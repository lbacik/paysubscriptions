<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Permanently removes a User and every application record owned by them.
 *
 * What disappears with the User:
 * - Subscriptions (orphanRemoval on User::$subscriptions) and Limits
 *   (cascade remove on User::$limits) via the ORM remove cascade.
 * - Reset-password requests, which point at the User with a non-nullable
 *   foreign key and no cascade, so they are deleted explicitly first.
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
