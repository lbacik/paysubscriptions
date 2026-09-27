<?php

declare(strict_types=1);

namespace App\OAuth2;

use App\Entity\OAuthRefreshFamily;
use App\Entity\OAuthRefreshFamilyToken;
use App\Entity\User;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Refresh-token repository with single-use rotating families (issue #91).
 *
 * Decorates the bundle's Doctrine repository: bundle rows keep carrying
 * expiry/revocation while App entities (`oauth_refresh_family` plus its
 * token links) carry family linkage. Behavior:
 *
 * - the first refresh token of an authorization starts a family bound to
 *   client, User, api:full scope, and the API resource;
 * - every successful refresh rotates within the family: the prior token is
 *   marked superseded and can never be used again;
 * - presenting a superseded (replayed) token revokes the whole family, so
 *   the active token dies with it and only fresh authorization helps;
 * - a family expires after 30 days without use and no later than 90 days
 *   after initial authorization; refreshing caps the new token at the
 *   absolute deadline and can never extend it.
 *
 * All rejections use protocol-defined errors (`invalid_grant`,
 * `invalid_scope`); a mismatched `resource` indicator is rejected with
 * `invalid_target` by RefreshResourceListener.
 *
 * Concurrency note: League validates, revokes, and persists through three
 * separate repository calls, so two truly simultaneous uses of one token can
 * both pass validation before either is marked superseded. The window is one
 * statement wide and the loser is caught on its next use (its token is by
 * then superseded, which revokes the family); serializing the window would
 * need pessimistic locking inside League's grant.
 */
final class RefreshTokenFamilyRepository implements RefreshTokenRepositoryInterface
{
    /**
     * Rotation handshake: isRefreshTokenRevoked() validates the presented
     * token and records which family the subsequent persistNewRefreshToken()
     * of the same token request must rotate. Same-request only; League always
     * calls validate → revoke → persist in that order.
     */
    private ?string $validatedTokenId = null;

    private ?string $validatedFamilyId = null;

    public function __construct(
        private readonly RefreshTokenRepositoryInterface $inner,
        private readonly EntityManagerInterface $em,
        private readonly OpaqueTokenDecryptor $decryptor,
        private readonly ClientManagerInterface $clients,
    ) {
    }

    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return $this->inner->getNewRefreshToken();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $now = new DateTimeImmutable();

        if (null !== $this->validatedFamilyId && null !== $this->validatedTokenId) {
            $this->persistRotation($refreshTokenEntity, $now);

            return;
        }

        $this->persistInitial($refreshTokenEntity, $now);
    }

    public function revokeRefreshToken(string $tokenId): void
    {
        $link = $this->em->find(OAuthRefreshFamilyToken::class, $tokenId);
        if (null !== $link && !$link->isSuperseded()) {
            $link->setSuperseded(true);
            $this->em->flush();
        }

        $this->inner->revokeRefreshToken($tokenId);
    }

    /**
     * Revokes the whole usable family behind one client-facing (opaque,
     * encrypted) refresh-token value (issue #92): the presented token and
     * every other usable token of its family become unusable, so only fresh
     * authorization helps.
     *
     * Returns the revoked family, or null for unknown values
     * (self-contained access tokens, malformed input) and for tokens owned
     * by another client, which are left untouched. Callers answer success
     * either way, so the endpoint never discloses token ownership.
     */
    public function revokeByOpaqueToken(string $opaqueToken, ?string $expectedClientId = null): ?OAuthRefreshFamily
    {
        $family = $this->findFamilyByOpaqueToken($opaqueToken);
        if (null === $family) {
            return null;
        }

        if (null !== $expectedClientId && $family->getClientId() !== $expectedClientId) {
            return null;
        }

        $this->revokeFamily($family);

        return $family;
    }

    /**
     * Finds the usable family behind one client-facing (opaque, encrypted)
     * refresh-token value, or null when the value is unknown or not a
     * refresh token this server issued (issue #92).
     */
    public function findFamilyByOpaqueToken(string $opaqueToken): ?OAuthRefreshFamily
    {
        $payload = $this->decryptor->decryptToArray($opaqueToken);
        $tokenId = $payload['refresh_token_id'] ?? null;
        if (!\is_string($tokenId) || '' === $tokenId) {
            return null;
        }

        $link = $this->em->find(OAuthRefreshFamilyToken::class, $tokenId);

        return $link?->getFamily();
    }

    /**
     * Revokes every usable refresh-token family for one (User, client) pair
     * (issue #92): User disconnect takes effect on refresh immediately.
     * Already-issued self-contained access tokens stay valid until their
     * short expiry. Returns the number of families revoked.
     */
    public function revokeFamiliesForUserClient(User $user, string $clientId): int
    {
        $families = $this->em->getRepository(OAuthRefreshFamily::class)->findBy([
            'user' => $user,
            'clientId' => $clientId,
        ]);

        $revoked = 0;
        foreach ($families as $family) {
            if ($family->isRevoked()) {
                continue;
            }
            $this->revokeFamily($family);
            ++$revoked;
        }

        return $revoked;
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $link = $this->em->find(OAuthRefreshFamilyToken::class, $tokenId);
        if (null === $link) {
            return $this->inner->isRefreshTokenRevoked($tokenId);
        }

        $family = $link->getFamily();
        if (null === $family) {
            return true;
        }

        $this->assertFamilyUsable($family, new DateTimeImmutable());

        if ($link->isSuperseded()) {
            // Replayed token: revoke the active family so the leak cannot
            // extend access, then fail the request.
            $this->revokeFamily($family);
            throw OAuthServerException::invalidRefreshToken('The refresh token has already been used.');
        }

        $id = $family->getId();
        $this->validatedTokenId = $tokenId;
        $this->validatedFamilyId = null !== $id ? (string) $id : null;

        return $this->inner->isRefreshTokenRevoked($tokenId);
    }

    /**
     * Rejects revoked, absolutely expired, and idle-expired families,
     * revoking the family on expiry so no later use can succeed. Also rejects
     * families whose User is gone or ineligible and whose client is gone,
     * disabled, or no longer approved for api:full (issue #93): lifecycle
     * revocation normally marks such families first, and this check closes
     * the gap fail-closed — revoking the family — when it did not.
     */
    private function assertFamilyUsable(OAuthRefreshFamily $family, DateTimeImmutable $now): void
    {
        if ($family->isRevoked()) {
            throw OAuthServerException::invalidRefreshToken('The refresh token family has been revoked.');
        }

        $this->assertUserEligible($family);
        $this->assertClientApproved($family);

        $absoluteExpiresAt = $family->getAbsoluteExpiresAt();
        if (null === $absoluteExpiresAt || $now >= $absoluteExpiresAt) {
            $this->revokeFamily($family);
            throw OAuthServerException::invalidRefreshToken('The refresh token family has expired.');
        }

        $lastUsedAt = $family->getLastUsedAt();
        if (null !== $lastUsedAt) {
            $idleExpiresAt = $lastUsedAt->add(new DateInterval('P'.OAuth2Config::REFRESH_IDLE_DAYS.'D'));
            if ($now >= $idleExpiresAt) {
                $this->revokeFamily($family);
                throw OAuthServerException::invalidRefreshToken('The refresh token family expired without use.');
            }
        }
    }

    /**
     * The family must still belong to an existing, eligible (verified) User.
     * Account deletion normally removes the family with the User; this check
     * fails closed when it did not.
     */
    private function assertUserEligible(OAuthRefreshFamily $family): void
    {
        try {
            $user = $family->getUser();
            $eligible = null !== $user && $user->isVerified();
        } catch (EntityNotFoundException) {
            $eligible = false;
        }

        if (!$eligible) {
            $this->revokeFamily($family);
            throw OAuthServerException::invalidRefreshToken('The User behind this authorization no longer exists.');
        }
    }

    /**
     * The family client must still be registered, active, and approved for
     * api:full. Disabling a client normally revokes its families first; this
     * check fails closed — revoking the family — when it did not, and also
     * covers client deletion and scope narrowing.
     */
    private function assertClientApproved(OAuthRefreshFamily $family): void
    {
        $client = $this->clients->find((string) $family->getClientId());

        $approved = null !== $client && $client->isActive();
        if ($approved) {
            $approved = false;
            foreach ($client->getScopes() as $scope) {
                if (OAuth2Config::SCOPE_FULL === (string) $scope) {
                    $approved = true;
                    break;
                }
            }
        }

        if (!$approved) {
            $this->revokeFamily($family);
            throw OAuthServerException::invalidRefreshToken('The client behind this authorization is no longer approved.');
        }
    }

    private function persistRotation(RefreshTokenEntityInterface $entity, DateTimeImmutable $now): void
    {
        $validatedFamilyId = $this->validatedFamilyId;
        $validatedTokenId = $this->validatedTokenId;
        $this->validatedTokenId = null;
        $this->validatedFamilyId = null;

        if (null === $validatedFamilyId) {
            $this->inner->persistNewRefreshToken($entity);

            return;
        }

        $family = $this->em->find(OAuthRefreshFamily::class, Uuid::fromString($validatedFamilyId));
        if (null === $family) {
            throw OAuthServerException::invalidRefreshToken('The refresh token family has been revoked.');
        }

        // Re-check: state may have changed since validation (revocation,
        // concurrent reuse, or clock crossing a deadline mid-request).
        $this->assertFamilyUsable($family, $now);

        // Refreshing never extends the absolute deadline: cap the rotated
        // token instead of issuing a fresh idle window past it.
        $absoluteExpiresAt = $family->getAbsoluteExpiresAt();
        \assert(null !== $absoluteExpiresAt);
        if ($entity->getExpiryDateTime() > $absoluteExpiresAt) {
            $entity->setExpiryDateTime($absoluteExpiresAt);
        }

        $this->inner->persistNewRefreshToken($entity);

        // The old token must end superseded even if League ever persists
        // without revoking first: rotation is what retires it.
        if (null !== $validatedTokenId) {
            $oldLink = $this->em->find(OAuthRefreshFamilyToken::class, $validatedTokenId);
            if (null !== $oldLink && !$oldLink->isSuperseded()) {
                $oldLink->setSuperseded(true);
            }
        }

        $this->em->persist(new OAuthRefreshFamilyToken($entity->getIdentifier(), $family));
        $family->setLastUsedAt($now);
        $this->em->flush();
    }

    private function persistInitial(RefreshTokenEntityInterface $entity, DateTimeImmutable $now): void
    {
        try {
            $accessToken = $entity->getAccessToken();
        } catch (Throwable) {
            $this->inner->persistNewRefreshToken($entity);

            return;
        }

        $scopes = array_map(static fn ($scope): string => $scope->getIdentifier(), $accessToken->getScopes());
        sort($scopes);
        if ([OAuth2Config::SCOPE_FULL] !== $scopes) {
            throw OAuthServerException::invalidScope('' === implode('', $scopes) ? '(empty)' : implode(' ', $scopes));
        }

        $clientId = $accessToken->getClient()->getIdentifier();
        $userIdentifier = $accessToken->getUserIdentifier();
        if (!\is_string($userIdentifier) || '' === $userIdentifier) {
            throw OAuthServerException::invalidGrant('Refresh tokens require a User-delegated authorization.');
        }

        // Resolve the User before persisting: no orphan bundle token when the
        // account is gone.
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $userIdentifier]);
        if (null === $user) {
            throw OAuthServerException::invalidGrant('The User behind this authorization no longer exists.');
        }

        $this->inner->persistNewRefreshToken($entity);

        $family = (new OAuthRefreshFamily())
            ->setUser($user)
            ->setClientId($clientId)
            ->setScopes($scopes)
            ->setAudience(OAuth2Config::API_AUDIENCE)
            ->setIssuedAt($now)
            ->setLastUsedAt($now)
            ->setAbsoluteExpiresAt($now->add(new DateInterval('P'.OAuth2Config::REFRESH_ABSOLUTE_DAYS.'D')));

        $this->em->persist($family);
        $this->em->persist(new OAuthRefreshFamilyToken($entity->getIdentifier(), $family));
        $this->em->flush();
    }

    private function revokeFamily(OAuthRefreshFamily $family): void
    {
        $family->setRevoked(true);

        $links = $this->em->getRepository(OAuthRefreshFamilyToken::class)->findBy(['family' => $family]);
        foreach ($links as $link) {
            if (!$link->isSuperseded()) {
                $link->setSuperseded(true);
            }
            $tokenId = $link->getTokenId();
            if (\is_string($tokenId)) {
                $this->inner->revokeRefreshToken($tokenId);
            }
        }

        $this->em->flush();

        $this->validatedTokenId = null;
        $this->validatedFamilyId = null;
    }
}
