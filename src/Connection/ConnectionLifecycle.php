<?php

declare(strict_types=1);

namespace App\Connection;

use App\Entity\OAuthConsent;
use App\Entity\OAuthRefreshFamily;
use App\Entity\OAuthRefreshFamilyToken;
use App\Entity\User;
use App\OAuth2\OpaqueTokenDecryptor;
use App\Repository\OAuthConsentRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCode;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;

/**
 * The single owner of Connection lifecycle revocation (ADR 0005,
 * CONTEXT.md **Connection** / **Client session**).
 *
 * A Connection is a User's standing approval for one Client, stored as the
 * consent row; Client sessions (refresh-token families) and pending
 * authorization codes live under it and may never outlive it. Every
 * revocation trigger in the app routes through exactly two operations:
 *
 * - **End a Connection**: remove the consent and revoke every Client session
 *   and pending authorization code under it. Scope: one (User, Client) pair,
 *   all of a User's Connections, or all of a Client's Connections. The next
 *   authorization shows the consent screen again.
 * - **Revoke credentials**: revoke Client sessions and pending authorization
 *   codes and keep the Connection. Scope: one Client session, one User, one
 *   Client, or everything. The next authorization auto-approves.
 *
 * Trigger map (ADR 0005): User disconnect ends that Connection; `/revoke`
 * revokes one Client session; password change revokes the User's
 * credentials; Client deactivation revokes the Client's credentials; Client
 * removal ends all of the Client's Connections; account deletion ends all of
 * the User's Connections (via hard delete in AccountDeletionService, which is
 * strictly stronger and stays as the mechanism); the emergency command
 * revokes all credentials, including pending authorization codes.
 *
 * Already-issued self-contained access tokens stay valid until their short
 * expiry after any trigger (decision #86).
 *
 * League appears here only as persisted rows: the bundle's
 * `AuthorizationCode` / `RefreshToken` entities and the opaque-token payload
 * shape. There is no grant, client-type, or server dependency.
 */
final class ConnectionLifecycle
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OAuthConsentRepository $consents,
        private readonly OpaqueTokenDecryptor $decryptor,
    ) {
    }

    /**
     * @return list<OAuthConsent> the User's Connections, newest first
     */
    public function listConnections(User $user): array
    {
        return $this->consents->findBy(['user' => $user], ['updatedAt' => 'DESC']);
    }

    /**
     * Ends one Connection: revokes every Client session and pending
     * authorization code of the (User, Client) pair, then removes the
     * consent, so the next authorization asks for consent again.
     *
     * Codes and families are revoked first: if their revocation fails, the
     * consent stays and the next authorization still covers what is live.
     * Idempotent: ending a Connection with nothing left to revoke succeeds
     * without effect (like RFC 7009 for unknown tokens).
     *
     * @return bool whether anything was revoked: a consent, a usable Client
     *              session, or a pending authorization code
     */
    public function endConnection(User $user, string $clientId): bool
    {
        $codes = $this->revokePendingCodesForPair($user, $clientId);
        $sessions = $this->revokeSessions($this->sessionsForPair($user, $clientId));
        $hadConnection = null !== $this->consents->findForClient($user, $clientId);
        $this->removeConsents($this->consentsForPair($user, $clientId));

        return $hadConnection || $sessions > 0 || $codes > 0;
    }

    /**
     * Ends every Connection of a User: revokes all of their Client sessions
     * and pending authorization codes and removes all of their consents.
     *
     * @return int the number of Client sessions revoked
     */
    public function endConnectionsForUser(User $user): int
    {
        $this->revokePendingCodesForUser($user->getEmail());
        $sessions = $this->revokeSessions($this->sessionsForUser($user));
        $this->removeConsents($this->em->getRepository(OAuthConsent::class)->findBy(['user' => $user]));

        return $sessions;
    }

    /**
     * Ends every Connection granted to a Client: revokes all of its Client
     * sessions and pending authorization codes and removes all of its
     * consents, so a Client re-registered under the same identifier starts
     * with no Connections.
     *
     * Safe to call after the Client row itself is gone: session and consent
     * rows key off the plain identifier string, and pending codes cascade with
     * the Client row at the database level.
     *
     * @return int the number of Client sessions revoked
     */
    public function endConnectionsForClient(string $clientId): int
    {
        if ('' === $clientId) {
            return 0;
        }

        $this->revokePendingCodesForClientId($clientId);
        $sessions = $this->revokeSessions($this->em->getRepository(OAuthRefreshFamily::class)->findBy(['clientId' => $clientId]));
        $this->removeConsents($this->em->getRepository(OAuthConsent::class)->findBy(['clientId' => $clientId]));

        return $sessions;
    }

    /**
     * Revokes the one usable Client session behind a client-facing (opaque,
     * encrypted) refresh-token value. The Connection stays: the next
     * authorization auto-approves.
     *
     * Returns the revoked session, or null for unknown values
     * (self-contained access tokens, malformed input) and for tokens owned
     * by another client, which are left untouched. Callers answer success
     * either way, so the endpoint never discloses token ownership.
     */
    public function revokeSessionByOpaqueToken(string $opaqueToken, ?string $expectedClientId = null): ?OAuthRefreshFamily
    {
        $payload = $this->decryptor->decryptToArray($opaqueToken);
        $tokenId = $payload['refresh_token_id'] ?? null;
        if (!\is_string($tokenId) || '' === $tokenId) {
            return null;
        }

        $link = $this->em->find(OAuthRefreshFamilyToken::class, $tokenId);
        $session = $link?->getFamily();
        if (null === $session) {
            return null;
        }

        if (null !== $expectedClientId && $session->getClientId() !== $expectedClientId) {
            return null;
        }

        $this->revokeSessions([$session]);

        return $session;
    }

    /**
     * Revokes every Client session and pending authorization code of a User.
     * The Connections stay: the next authorization auto-approves.
     *
     * @return int the number of Client sessions revoked
     */
    public function revokeCredentialsForUser(User $user): int
    {
        $this->revokePendingCodesForUser($user->getEmail());
        $sessions = $this->revokeSessions($this->sessionsForUser($user));

        return $sessions;
    }

    /**
     * Revokes every Client session and pending authorization code granted to
     * a Client. The Connections stay: re-enabling the Client needs no
     * re-consent, only fresh authorization.
     *
     * @return int the number of Client sessions revoked
     */
    public function revokeCredentialsForClient(string $clientId): int
    {
        if ('' === $clientId) {
            return 0;
        }

        $this->revokePendingCodesForClientId($clientId);
        $sessions = $this->revokeSessions($this->em->getRepository(OAuthRefreshFamily::class)->findBy(['clientId' => $clientId]));

        return $sessions;
    }

    /**
     * Revokes every Client session and every pending authorization code.
     * Connections stay: clients authorize again without re-consenting.
     *
     * This is the emergency response to a suspected signing-key compromise:
     * with the old verification key unpublished, forged access tokens already
     * fail validation, and revoking all sessions additionally kills every
     * legitimate session. Sessions are marked revoked (never deleted) and
     * every token link is superseded with its bundle row revoked, mirroring
     * the single-session path. Pending authorization codes are revoked too,
     * so a code issued before the emergency cannot mint a fresh session after
     * it — in-flight authorizations restart instead.
     *
     * @return int the number of Client sessions revoked
     */
    public function revokeAllCredentials(): int
    {
        $this->em->createQueryBuilder()
            ->update(AuthorizationCode::class, 'ac')
            ->set('ac.revoked', ':revoked')
            ->setParameter('revoked', true)
            ->getQuery()
            ->execute();

        $sessions = $this->em->getRepository(OAuthRefreshFamily::class)->findBy(['revoked' => false]);

        return $this->revokeSessions($sessions);
    }

    /**
     * @return list<OAuthRefreshFamily>
     */
    private function sessionsForPair(User $user, string $clientId): array
    {
        /** @var list<OAuthRefreshFamily> */
        return $this->em->getRepository(OAuthRefreshFamily::class)->findBy([
            'user' => $user,
            'clientId' => $clientId,
        ]);
    }

    /**
     * @return list<OAuthRefreshFamily>
     */
    private function sessionsForUser(User $user): array
    {
        /** @var list<OAuthRefreshFamily> */
        return $this->em->getRepository(OAuthRefreshFamily::class)->findBy([
            'user' => $user,
            'revoked' => false,
        ]);
    }

    /**
     * @return list<OAuthConsent>
     */
    private function consentsForPair(User $user, string $clientId): array
    {
        $consent = $this->consents->findForClient($user, $clientId);

        return null === $consent ? [] : [$consent];
    }

    /**
     * @param list<OAuthConsent> $consents
     */
    private function removeConsents(array $consents): void
    {
        if ([] === $consents) {
            return;
        }

        foreach ($consents as $consent) {
            $this->em->remove($consent);
        }
        $this->em->flush();
    }

    /**
     * Marks Client sessions revoked (never deleted): already-issued access
     * tokens stay valid until their short expiry, but no refresh can extend
     * them anymore. Every token link of the session is superseded and the
     * bundle's own refresh-token row revoked, so a pending code cannot mint
     * a fresh session after the fact either.
     *
     * @param list<OAuthRefreshFamily> $sessions
     *
     * @return int the number of sessions revoked
     */
    private function revokeSessions(array $sessions): int
    {
        $usable = array_values(array_filter(
            $sessions,
            static fn (OAuthRefreshFamily $session): bool => !$session->isRevoked(),
        ));

        if ([] === $usable) {
            return 0;
        }

        $tokenIds = [];
        $linkRepository = $this->em->getRepository(OAuthRefreshFamilyToken::class);

        foreach ($usable as $session) {
            $session->setRevoked(true);

            foreach ($linkRepository->findBy(['family' => $session]) as $link) {
                $link->setSuperseded(true);
                $tokenId = $link->getTokenId();
                if (\is_string($tokenId) && '' !== $tokenId) {
                    $tokenIds[$tokenId] = true;
                }
            }
        }

        $this->em->flush();

        $bundleTokens = $this->em->getRepository(RefreshToken::class);
        foreach (array_keys($tokenIds) as $tokenId) {
            $bundleToken = $bundleTokens->find($tokenId);
            if (null !== $bundleToken && !$bundleToken->isRevoked()) {
                $bundleToken->revoke();
            }
        }
        $this->em->flush();

        return \count($usable);
    }

    private function revokePendingCodesForPair(User $user, string $clientId): int
    {
        $email = $user->getEmail();
        $client = $this->em->getRepository(Client::class)->find($clientId);
        if (null === $email || '' === $email || null === $client) {
            return 0;
        }

        return (int) $this->em->createQueryBuilder()
            ->update(AuthorizationCode::class, 'ac')
            ->set('ac.revoked', ':revoked')
            ->where('ac.userIdentifier = :userIdentifier')
            ->andWhere('ac.client = :client')
            ->andWhere('ac.revoked = :pending')
            ->setParameter('revoked', true)
            ->setParameter('pending', false)
            ->setParameter('userIdentifier', $email)
            ->setParameter('client', $client)
            ->getQuery()
            ->execute();
    }

    private function revokePendingCodesForUser(?string $email): int
    {
        if (null === $email || '' === $email) {
            return 0;
        }

        return (int) $this->em->createQueryBuilder()
            ->update(AuthorizationCode::class, 'ac')
            ->set('ac.revoked', ':revoked')
            ->where('ac.userIdentifier = :userIdentifier')
            ->setParameter('revoked', true)
            ->setParameter('userIdentifier', $email)
            ->getQuery()
            ->execute();
    }

    private function revokePendingCodesForClientId(string $clientId): int
    {
        $client = $this->em->getRepository(Client::class)->find($clientId);
        if (null === $client) {
            // The client row is gone: its codes cascade with it, so there is
            // nothing left to revoke.
            return 0;
        }

        return (int) $this->em->createQueryBuilder()
            ->update(AuthorizationCode::class, 'ac')
            ->set('ac.revoked', ':revoked')
            ->where('ac.client = :client')
            ->setParameter('revoked', true)
            ->setParameter('client', $client)
            ->getQuery()
            ->execute();
    }
}
