<?php

declare(strict_types=1);

namespace App\OAuth2;

use App\Entity\OAuthConsent;
use App\Entity\User;
use App\Repository\OAuthConsentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * User disconnect for approved OAuth2 clients (issue #92).
 *
 * Revoking a grant deletes the remembered consent — so the next
 * authorization asks for consent again — and revokes every usable
 * refresh-token family for the (User, client) pair, so the disconnected
 * client cannot refresh or obtain new access without renewed consent.
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
     * Disconnects one client. Returns whether a remembered grant existed;
     * revoking an unknown grant is a no-op that still succeeds (revocation
     * is idempotent, like RFC 7009 for unknown tokens).
     */
    public function revoke(User $user, string $clientId): bool
    {
        $consent = $this->consents->findForClient($user, $clientId);
        if (null === $consent) {
            return false;
        }

        // Families first: if their revocation fails, the remembered consent
        // stays and the next authorization still covers the live family.
        $this->families->revokeFamiliesForUserClient($user, $clientId);
        $this->forget($user, $clientId);

        return true;
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
}
