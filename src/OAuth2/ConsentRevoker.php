<?php

declare(strict_types=1);

namespace App\OAuth2;

use App\Entity\OAuthConsent;
use App\Entity\User;
use App\Repository\OAuthConsentRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCode;
use League\Bundle\OAuth2ServerBundle\Model\Client;

/**
 * User disconnect for approved OAuth2 clients (issue #92).
 *
 * Revoking a grant deletes the remembered consent — so the next
 * authorization asks for consent again — and revokes every usable
 * refresh-token family and every pending authorization code for the
 * (User, client) pair, so the disconnected client cannot refresh or obtain
 * new access without renewed consent. A code issued before the disconnect
 * must not mint a fresh family after it (issue #182).
 * Already-issued self-contained access tokens stay valid until their
 * 15-minute expiry.
 */
final class ConsentRevoker
{
    public function __construct(
        private readonly OAuthConsentRepository $consents,
        private readonly RefreshTokenFamilyRepository $families,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<OAuthConsent> the User's approved grants, newest first
     */
    public function listFor(User $user): array
    {
        return $this->consents->findBy(['user' => $user], ['updatedAt' => 'DESC']);
    }

    /**
     * Disconnects one client. Returns whether anything was revoked: a
     * remembered grant, a usable refresh family, or a pending authorization
     * code. Families and codes are revoked even without a consent row, so a
     * family orphaned earlier can still be cleaned up. Disconnecting a client
     * with nothing to revoke is a no-op that still succeeds (revocation is
     * idempotent, like RFC 7009 for unknown tokens).
     */
    public function revoke(User $user, string $clientId): bool
    {
        // Codes and families first: if their revocation fails, the remembered
        // consent stays and the next authorization still covers what is live.
        $codes = $this->revokePendingCodes($user, $clientId);
        $families = $this->families->revokeFamiliesForUserClient($user, $clientId);
        $hadConsent = null !== $this->consents->findForClient($user, $clientId);
        $this->forget($user, $clientId);

        return $hadConsent || $families > 0 || $codes > 0;
    }

    /**
     * Forgets the remembered consent without touching refresh families.
     * Used after client-side RFC 7009 revocation, which already retired the
     * family: the client asks for consent again next time.
     */
    public function forget(User $user, string $clientId): void
    {
        $consent = $this->consents->findForClient($user, $clientId);
        if (null === $consent) {
            return;
        }

        $this->em->remove($consent);
        $this->em->flush();
    }

    private function revokePendingCodes(User $user, string $clientId): int
    {
        $email = $user->getEmail();
        $client = $this->em->getRepository(Client::class)->find($clientId);
        if (null === $email || '' === $email || null === $client) {
            return 0;
        }

        // Same bulk update as RefreshFamilyRevoker, scoped to the pair.
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
}
