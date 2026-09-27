<?php

declare(strict_types=1);

namespace App\OAuth2;

use App\Entity\OAuthRefreshFamily;
use App\Entity\OAuthRefreshFamilyToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\RefreshTokenManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCode;
use League\Bundle\OAuth2ServerBundle\Model\Client;

/**
 * Revokes refresh-token families when account or client state changes
 * (issue #93).
 *
 * Families are marked revoked (never deleted here): already-issued access
 * tokens stay valid until their short expiry (decision #86), but no refresh
 * can extend them anymore. Revocation also supersedes every token link of the
 * family and revokes the bundle's own refresh-token and authorization-code
 * rows, so a pending code cannot mint a fresh family after the fact.
 *
 * Unrelated Users and clients are never touched: both entry points scope to
 * exactly one User or one client identifier.
 */
final class RefreshFamilyRevoker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RefreshTokenManagerInterface $refreshTokens,
    ) {
    }

    /**
     * Revokes every active family of the given User.
     *
     * @return int the number of families revoked
     */
    public function revokeForUser(User $user): int
    {
        $families = $this->em->getRepository(OAuthRefreshFamily::class)->findBy([
            'user' => $user,
            'revoked' => false,
        ]);

        $revoked = $this->revokeFamilies($families);
        $this->revokeAuthCodesForUser($user->getEmail());

        return $revoked;
    }

    /**
     * Revokes every active family granted to the given client identifier.
     *
     * @return int the number of families revoked
     */
    public function revokeForClientId(string $clientId): int
    {
        if ('' === $clientId) {
            return 0;
        }

        $families = $this->em->getRepository(OAuthRefreshFamily::class)->findBy([
            'clientId' => $clientId,
            'revoked' => false,
        ]);

        $revoked = $this->revokeFamilies($families);
        $this->revokeAuthCodesForClient($clientId);

        return $revoked;
    }

    /**
     * @param list<OAuthRefreshFamily> $families
     */
    private function revokeFamilies(array $families): int
    {
        if ([] === $families) {
            return 0;
        }

        $tokenIds = [];
        $linkRepository = $this->em->getRepository(OAuthRefreshFamilyToken::class);

        foreach ($families as $family) {
            $family->setRevoked(true);

            foreach ($linkRepository->findBy(['family' => $family]) as $link) {
                $link->setSuperseded(true);
                $tokenId = $link->getTokenId();
                if (\is_string($tokenId) && '' !== $tokenId) {
                    $tokenIds[$tokenId] = true;
                }
            }
        }

        $this->em->flush();

        foreach (array_keys($tokenIds) as $tokenId) {
            $bundleToken = $this->refreshTokens->find($tokenId);
            if (null !== $bundleToken && !$bundleToken->isRevoked()) {
                $bundleToken->revoke();
                $this->refreshTokens->save($bundleToken);
            }
        }

        return \count($families);
    }

    private function revokeAuthCodesForUser(?string $email): void
    {
        if (null === $email || '' === $email) {
            return;
        }

        // Mirrors the bundle's own credentials revoker: pending codes must not
        // mint a fresh family after the password change.
        $this->em->createQueryBuilder()
            ->update(AuthorizationCode::class, 'ac')
            ->set('ac.revoked', ':revoked')
            ->where('ac.userIdentifier = :userIdentifier')
            ->setParameter('revoked', true)
            ->setParameter('userIdentifier', $email)
            ->getQuery()
            ->execute();
    }

    private function revokeAuthCodesForClient(string $clientId): void
    {
        $client = $this->em->getRepository(Client::class)->find($clientId);
        if (null === $client) {
            // The client row is gone: its codes cascade with it, so there is
            // nothing left to revoke.
            return;
        }

        $this->em->createQueryBuilder()
            ->update(AuthorizationCode::class, 'ac')
            ->set('ac.revoked', ':revoked')
            ->where('ac.client = :client')
            ->setParameter('revoked', true)
            ->setParameter('client', $client)
            ->getQuery()
            ->execute();
    }
}
