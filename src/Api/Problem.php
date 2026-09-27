<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Stable RFC 9457 problem document for API v1 resource errors (decision #87).
 *
 * Resource and report errors use `application/problem+json` with a stable
 * machine-readable `type`/`code`, HTTP `status`, safe human-readable
 * `title`/`detail`, and field-level `errors` for 422. OAuth2 protocol
 * endpoints keep their protocol-defined errors and never use this shape.
 */
final class Problem
{
    public const BASE_TYPE = 'https://api.paysubscriptions.local/v1/problems/';

    public const UNAUTHORIZED = 'unauthorized';
    public const FORBIDDEN = 'forbidden';
    public const INSUFFICIENT_SCOPE = 'insufficient_scope';
    public const ACCOUNT_INACTIVE = 'account_inactive';
    public const CATEGORY_NOT_FOUND = 'category_not_found';
    public const CATEGORY_NAME_CONFLICT = 'category_name_conflict';
    public const CATEGORY_IN_USE = 'category_in_use';
    public const VALIDATION_FAILED = 'validation_failed';
    public const SUBSCRIPTION_NOT_FOUND = 'subscription_not_found';
    public const INVALID_FILTER = 'invalid_filter';
    public const SUBSCRIPTION_LIMIT_REACHED = 'subscription_limit_reached';
    public const CURRENCY_CONFLICT = 'currency_conflict';
    public const INVALID_TOKEN = 'invalid_token';
    public const NOT_FOUND = 'not_found';

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public static function body(
        string $code,
        string $title,
        int $status,
        string $detail,
        array $extra = [],
    ): array {
        return array_merge([
            'type' => self::BASE_TYPE.$code,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
        ], $extra);
    }
}
