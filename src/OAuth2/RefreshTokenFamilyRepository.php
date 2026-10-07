<?php

declare(strict_types=1);

namespace App\OAuth2;

use App\Connection\ClientApprovalPolicy;
use App\Connection\ConnectionDecision;
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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;
use WeakMap;

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
 * This repository is League token storage only. Trigger-driven revocation
 * (disconnect, `/revoke`, password change, client lifecycle, account
 * deletion, emergency command) lives in App\Connection\ConnectionLifecycle
 * (ADR 0005); the only family revocation here is storage-internal —
 * replayed-token containment, expiry, and the fail-closed approval check.
 *
 * Concurrency note: League validates, revokes, and persists through three
 * separate repository calls, so two truly simultaneous uses of one token can
 * both pass validation before either is marked superseded. The window is one
 * statement wide and the loser is caught on its next use (its token is by
 * then superseded, which revokes the family); serializing the window would
 * need pessimistic locking inside League's grant.
 */
final class RefreshTokenFamilyRepository implements RefreshTokenRepositoryInterface, ResetInterface
{
    /**
     * Rotation handshake, scoped to the current HTTP request (issue #135).
     *
     * isRefreshTokenRevoked() validates the presented token and records which
     * family the subsequent persistNewRefreshToken() of the same token request
     * must rotate. League always calls validate → revoke → persist in that
     * order within one token request.
     *
     * The pending rotation is keyed by the current Request object (plus a
     * fallback slot when no request is on the stack, e.g. CLI), never by bare
     * instance state: a previous request that failed after validation (so its
     * persist never ran) leaves an entry behind its own Request object, which
     * the next request never reads. Entries are consumed by persist, garbage
     * collected with their Request via WeakMap, and dropped by reset() between
     * worker requests.
     *
     * @var WeakMap<Request, array{tokenId: string, familyId: ?string}>
     */
    private WeakMap $pendingRotations;

    /** @var array{tokenId: string, familyId: ?string}|null */
    private ?array $fallbackRotation = null;

    public function __construct(
        private readonly RefreshTokenRepositoryInterface $inner,
        private readonly EntityManagerInterface $em,
        private readonly ClientManagerInterface $clients,
        private readonly string $refreshIdleTtl,
        private readonly RequestStack $requestStack,
        private readonly ClientApprovalPolicy $approvals,
    ) {
        $this->pendingRotations = new WeakMap();
    }

    public function reset(): void
    {
        $this->pendingRotations = new WeakMap();
        $this->fallbackRotation = null;
    }

    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return $this->inner->getNewRefreshToken();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $now = new DateTimeImmutable();
        $pending = $this->consumePendingRotation();

        if (null !== $pending) {
            $this->persistRotation($refreshTokenEntity, $now, $pending['familyId'], $pending['tokenId']);

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
        $this->rememberPendingRotation($tokenId, null !== $id ? (string) $id : null);

        return $this->inner->isRefreshTokenRevoked($tokenId);
    }

    /**
     * Rejects revoked, absolutely expired, and idle-expired families,
     * revoking the family on expiry so no later use can succeed. Also rejects
     * families whose Client may no longer act for their User (issue #93):
     * lifecycle revocation normally marks such families first, and the shared
     * ClientApprovalPolicy closes the gap fail-closed — revoking the family
     * — when it did not.
     */
    private function assertFamilyUsable(OAuthRefreshFamily $family, DateTimeImmutable $now): void
    {
        if ($family->isRevoked()) {
            throw OAuthServerException::invalidRefreshToken('The refresh token family has been revoked.');
        }

        $this->assertConnectionApproved($family);

        $absoluteExpiresAt = $family->getAbsoluteExpiresAt();
        if (null === $absoluteExpiresAt || $now >= $absoluteExpiresAt) {
            $this->revokeFamily($family);
            throw OAuthServerException::invalidRefreshToken('The refresh token family has expired.');
        }

        $lastUsedAt = $family->getLastUsedAt();
        if (null !== $lastUsedAt) {
            $idleExpiresAt = $lastUsedAt->add(new DateInterval($this->refreshIdleTtl));
            if ($now >= $idleExpiresAt) {
                $this->revokeFamily($family);
                throw OAuthServerException::invalidRefreshToken('The refresh token family expired without use.');
            }
        }
    }

    /**
     * The family's Client must still be allowed to act for its User: an
     * existing, eligible (verified) User, and a registered, active Client
     * approved for api:full. Account deletion normally removes the family
     * with the User and disabling a client normally revokes its families
     * first; this check fails closed — revoking the family — when either did
     * not, and also covers client deletion and scope narrowing.
     */
    private function assertConnectionApproved(OAuthRefreshFamily $family): void
    {
        try {
            $user = $family->getUser();
        } catch (EntityNotFoundException) {
            $user = null;
        }

        $decision = $this->approvals->decide($user, $this->clients->find((string) $family->getClientId()));

        if (ConnectionDecision::Allowed === $decision) {
            return;
        }

        $this->revokeFamily($family);

        throw OAuthServerException::invalidRefreshToken(
            ConnectionDecision::UserNotEligible === $decision
                ? 'The User behind this authorization no longer exists.'
                : 'The client behind this authorization is no longer approved.'
        );
    }

    private function persistRotation(
        RefreshTokenEntityInterface $entity,
        DateTimeImmutable $now,
        ?string $validatedFamilyId,
        ?string $validatedTokenId,
    ): void {
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

        // Deliberately leaves the pending rotation for the current request
        // untouched: persistRotation() re-checks family usability and fails
        // closed when the family was revoked mid-flight, instead of falling
        // back to a fresh family for a refresh request.
    }

    /**
     * @return array{tokenId: string, familyId: ?string}|null
     */
    private function consumePendingRotation(): ?array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            $pending = $this->fallbackRotation;
            $this->fallbackRotation = null;

            return $pending;
        }

        if (!isset($this->pendingRotations[$request])) {
            return null;
        }

        $pending = $this->pendingRotations[$request];
        unset($this->pendingRotations[$request]);

        return $pending;
    }

    private function rememberPendingRotation(string $tokenId, ?string $familyId): void
    {
        $pending = ['tokenId' => $tokenId, 'familyId' => $familyId];
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            $this->fallbackRotation = $pending;

            return;
        }

        $this->pendingRotations[$request] = $pending;
    }
}
