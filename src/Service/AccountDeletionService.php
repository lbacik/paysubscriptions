<?php

declare(strict_types=1);

namespace App\Service;

use App\Connection\ConnectionLifecycle;
use App\Entity\OAuthConsent;
use App\Entity\OAuthRefreshFamily;
use App\Entity\OAuthRefreshFamilyToken;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCode;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;

/**
 * Permanently removes a User and every application record owned by them.
 *
 * What disappears with the User:
 * - Subscriptions (orphanRemoval on User::$subscriptions) and Limits
 *   (cascade remove on User::$limits) via the ORM remove cascade.
 * - Reset-password requests, which point at the User with a non-nullable
 *   foreign key and no cascade, so they are deleted explicitly first.
 * - OAuth2 records (ADR 0005): remembered per-client Connections (consent
 *   rows), Client sessions with their token links, the bundle's own
 *   refresh-token rows (attributed only through those links), and pending
 *   authorization codes. Session rows would cascade with the User, but they
 *   are removed explicitly so the bundle rows keyed by their links can be
 *   collected first. Already-issued access tokens are stateless and stay
 *   valid until their short expiry (decision #86); with the families gone,
 *   no refresh can extend them.
 * - Reminder preferences/state: no dedicated columns or tables exist for
 *   these yet, so there is nothing extra to delete today beyond the User
 *   row itself. When the reminder slices land, their account-owned state
 *   must hook into deleteOwnedRecordsIfSupported() below.
 * - Queued account mail (verification, password-reset, reminders) sitting in
 *   the Doctrine messenger transport: rows mentioning the deleted address are
 *   purged so a late worker cannot send mail for an account that no longer
 *   exists, nor trigger code paths that re-persist the User.
 *
 * What is deliberately left alone:
 * - The gprodb.com newsletter list. It is a separate opt-in managed by an
 *   external provider (see MailingSubscriptionService); deletion never
 *   unsubscribes it, silently or otherwise.
 *
 * Forward compatibility: sibling v1.0 slices add account-owned entities
 * (expense categories in #39, durable reminder-send state in #45). Where such
 * an entity class exists in the codebase it is cleaned up explicitly below;
 * reminder state that does not exist yet must hook into this service when it
 * lands rather than being left behind.
 */
class AccountDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConnectionLifecycle $connections,
    ) {
    }

    public function delete(User $user): void
    {
        $email = (string) $user->getEmail();

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $this->removeOwned($user, ResetPasswordRequest::class, 'user');
            $this->deleteOwnedRecordsIfSupported($user);
            $this->removeOAuthRecords($user, $email);
            $this->purgeQueuedAccountMessages($email);

            $this->entityManager->remove($user);
            $this->entityManager->flush();

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    /**
     * Removes rows from optional account-owned tables that only exist once
     * their own v1.0 slice has merged. Guarded by class_exists so deletion
     * keeps working on codebases before (and after) those slices land.
     */
    private function deleteOwnedRecordsIfSupported(User $user): void
    {
        foreach (['App\Entity\ExpenseCategory' => 'owner'] as $class => $field) {
            if (!class_exists($class)) {
                continue;
            }

            $this->removeOwned($user, $class, $field);
        }
    }

    /**
     * Removes the deleted account's OAuth2 Connections and token records: the
     * remembered per-client consents, every Client session with its
     * token links, the bundle's own refresh-token rows (which carry no User
     * attribution of their own and are keyed here through the links), and
     * pending authorization codes. Links are removed before their families so
     * the cleanup never depends on database cascade ordering within the
     * flush.
     *
     * The deletion routes through the Connection lifecycle module first
     * (ADR 0005): ending all of the User's Connections revokes the sessions
     * and codes and removes the consents, and the hard delete below stays as
     * the mechanism that removes the rows themselves.
     */
    private function removeOAuthRecords(User $user, string $email): void
    {
        $this->connections->endConnectionsForUser($user);

        $this->removeOwned($user, OAuthConsent::class, 'user');

        $families = $this->entityManager->getRepository(OAuthRefreshFamily::class)->findBy(['user' => $user]);
        $linkRepository = $this->entityManager->getRepository(OAuthRefreshFamilyToken::class);

        $tokenIds = [];
        $links = [];
        foreach ($families as $family) {
            foreach ($linkRepository->findBy(['family' => $family]) as $link) {
                $links[] = $link;
                $tokenId = $link->getTokenId();
                if (\is_string($tokenId) && '' !== $tokenId) {
                    $tokenIds[$tokenId] = true;
                }
            }
        }

        foreach ($links as $link) {
            $this->entityManager->remove($link);
        }

        foreach ($families as $family) {
            $this->entityManager->remove($family);
        }

        $bundleRefreshTokens = $this->entityManager->getRepository(RefreshToken::class);
        foreach (array_keys($tokenIds) as $tokenId) {
            $bundleToken = $bundleRefreshTokens->find($tokenId);
            if (null !== $bundleToken) {
                $this->entityManager->remove($bundleToken);
            }
        }

        if ('' !== $email) {
            $codes = $this->entityManager->getRepository(AuthorizationCode::class)->findBy(['userIdentifier' => $email]);
            foreach ($codes as $code) {
                $this->entityManager->remove($code);
            }
        }
    }

    /**
     * Load-then-remove rather than DQL DELETE: the UUID primary keys are
     * binary(16) columns, and DQL binds an entity parameter in its 36-char
     * string form, matching nothing. The repository path converts the
     * identifier correctly.
     */
    private function removeOwned(User $user, string $class, string $field): void
    {
        $owned = $this->entityManager->getRepository($class)->findBy([$field => $user]);

        foreach ($owned as $entity) {
            $this->entityManager->remove($entity);
        }
    }

    /**
     * Best-effort purge of queued messenger rows mentioning the deleted
     * address. Only the Doctrine `async` transport stores rows here; the
     * newsletter transport is intentionally never purged (the gprodb.com list
     * is a separate opt-in and must survive deletion). A missing table
     * (transports with auto_setup=0 in a fresh test schema) is not an error:
     * there is simply nothing queued to purge.
     */
    private function purgeQueuedAccountMessages(string $email): void
    {
        if ('' === $email) {
            return;
        }

        $connection = $this->entityManager->getConnection();

        try {
            $tables = $connection->createSchemaManager()->listTableNames();
        } catch (\Throwable) {
            return;
        }

        if (!\in_array('messenger_messages', $tables, true)) {
            return;
        }

        try {
            // Match the address in its quoted (JSON/serialized) form inside
            // the body, so queued@example.com cannot over-match a longer
            // address that merely contains it (e.g. notqueued@example.com).
            // Headers are matched bare: stamps there are not uniformly
            // quoted. Two distinct placeholders: reusing one named
            // parameter twice is rejected by native MySQL prepares.
            $connection->executeStatement(
                'DELETE FROM messenger_messages WHERE POSITION(:email_quoted IN body) > 0 OR POSITION(:email_headers IN headers) > 0',
                ['email_quoted' => '"' . $email . '"', 'email_headers' => $email]
            );
        } catch (\Throwable) {
            // A purge failure must not block the deletion itself; the
            // verification and reset-password handlers already tolerate
            // unknown users without recreating them (covered by tests).
        }
    }
}
