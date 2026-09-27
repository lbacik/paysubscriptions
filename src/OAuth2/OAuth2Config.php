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

    private function __construct()
    {
    }
}
