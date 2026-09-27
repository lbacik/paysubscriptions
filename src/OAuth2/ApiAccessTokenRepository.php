<?php

declare(strict_types=1);

namespace App\OAuth2;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

/**
 * Issues ApiAccessTokenEntity tokens (issuer + API audience + client claims)
 * while delegating persistence and revocation bookkeeping to the bundle's
 * repository. Access-token persistence itself is disabled (stateless
 * short-lived tokens, decision #86), so the inner repository is a no-op for
 * access tokens and already-issued tokens stay valid until expiry.
 */
final class ApiAccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(
        private readonly AccessTokenRepositoryInterface $inner,
        private readonly string $issuer,
    ) {
    }

    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        ?string $userIdentifier = null,
    ): AccessTokenEntityInterface {
        $accessToken = new ApiAccessTokenEntity($this->issuer, OAuth2Config::API_AUDIENCE);
        $accessToken->setClient($clientEntity);
        if (null !== $userIdentifier && '' !== $userIdentifier) {
            $accessToken->setUserIdentifier($userIdentifier);
        }

        foreach ($scopes as $scope) {
            $accessToken->addScope($scope);
        }

        return $accessToken;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $this->inner->persistNewAccessToken($accessTokenEntity);
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $this->inner->revokeAccessToken($tokenId);
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        return $this->inner->isAccessTokenRevoked($tokenId);
    }
}
