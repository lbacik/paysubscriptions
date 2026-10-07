<?php

declare(strict_types=1);

namespace App\OAuth2;

use DateInterval;
use DateTimeImmutable;

/**
 * OAuth2 token lifetimes, defined once as container parameters (issue #135,
 * problem 6) instead of being repeated in both the League bundle config and
 * code constants.
 *
 * - The League bundle config references the same parameters, so the issued
 *   tokens' `exp` follows `app.oauth2.access_token_ttl` /
 *   `app.oauth2.refresh_idle_ttl` / `app.oauth2.clock_skew_leeway`.
 * - The key-rotation overlap is derived here as access-token TTL plus
 *   clock-skew leeway: no pre-rotation token can outlive that window, so it
 *   is all a routine signing-key rotation ever needs the previous
 *   verification key published for.
 */
final readonly class OAuthTokenLifetimes
{
    public function __construct(
        public string $accessTokenTtl,
        public string $refreshIdleTtl,
        public string $clockSkewLeeway,
    ) {
    }

    public function getAccessTokenTtl(): DateInterval
    {
        return new DateInterval($this->accessTokenTtl);
    }

    public function getRefreshIdleTtl(): DateInterval
    {
        return new DateInterval($this->refreshIdleTtl);
    }

    public function getClockSkewLeeway(): DateInterval
    {
        return new DateInterval($this->clockSkewLeeway);
    }

    /**
     * Rotation overlap: how long after a routine signing-key rotation the
     * previous public key stays accepted. Covers the longest a pre-rotation
     * access token can remain valid (access-token TTL plus clock-skew
     * tolerance). With the default lifetimes this is 16 minutes; operators
     * keep the previous key published at least this long, with 30 minutes as
     * the documented recommendation.
     */
    public function getRotationOverlap(): DateInterval
    {
        $base = new DateTimeImmutable();

        return $base->diff(
            $base->add($this->getAccessTokenTtl())->add($this->getClockSkewLeeway())
        );
    }
}
