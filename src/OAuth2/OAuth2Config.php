<?php

declare(strict_types=1);

namespace App\OAuth2;

/**
 * Shared OAuth2 constants for API v1 (decisions #82, #86).
 */
final class OAuth2Config
{
    /**
     * The single coarse API v1 scope: full read/write access to the approving
     * User's Subscriptions, ExpenseCategories, and reports. Every approved
     * client that receives this scope can read, create, change, and delete
     * the User's data; this is an intentional product choice.
     */
    public const SCOPE_FULL = 'api:full';

    /**
     * Scopes the authorization server may issue.
     *
     * @var list<string>
     */
    public const AVAILABLE_SCOPES = [self::SCOPE_FULL];

    /**
     * Audience claim carried by every access token, identifying the
     * PaySubscriptions API resource the token is restricted to. A token for
     * another audience must never be accepted for this API.
     */
    public const API_AUDIENCE = 'urn:paysubscriptions:api:v1';

    /**
     * Idle lifetime of a refresh-token family: 30 days without use expires
     * the family (issue #91). Mirrors the `refresh_token_ttl` authorization
     * server setting so bundle token expiry and family bookkeeping agree.
     */
    public const REFRESH_IDLE_DAYS = 30;

    /**
     * Absolute lifetime of a refresh-token family: no later than 90 days
     * after initial authorization, and refreshing never extends it (#91).
     */
    public const REFRESH_ABSOLUTE_DAYS = 90;

    /**
     * Lifetime of an access token, mirroring the `access_token_ttl`
     * authorization server setting (issue #94). Together with
     * CLOCK_SKEW_LEEWAY this bounds how long the previous verification key
     * must stay published during a routine signing-key rotation: no token
     * outlives its 15-minute expiry plus clock skew, so the overlap window
     * below is all a rotation ever needs.
     */
    public const ACCESS_TOKEN_TTL = 'PT15M';

    /**
     * Clock-skew tolerance for access-token verification, mirroring the
     * `jwt_leeway` resource server setting (issue #94). Kept as a constant
     * (rather than read back from the bundle) so the key-ring validator and
     * the bundle configuration cannot silently diverge: both must name this
     * value.
     */
    public const CLOCK_SKEW_LEEWAY = 'PT60S';

    /**
     * Rotation overlap: how long after a routine signing-key rotation the
     * previous public key stays accepted (issue #94). Covers the longest a
     * pre-rotation access token can remain valid (15-minute TTL plus 60
     * seconds of clock skew), rounded up. Operators should keep the previous
     * key published at least this long; 30 minutes is the documented
     * recommendation.
     */
    public const ROTATION_OVERLAP = 'PT16M';

    private function __construct()
    {
    }
}
