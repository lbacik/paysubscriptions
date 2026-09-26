<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Resolves the account time zone for a User.
 *
 * The initial value comes from the browser (Intl.DateTimeFormat), which may be
 * missing or report a non-IANA zone. Such values must never be stored: they
 * fall back to UTC explicitly so the User can correct the zone in settings.
 */
final class TimezoneService
{
    public const FALLBACK_TIMEZONE = 'UTC';

    public function normalize(?string $candidate): string
    {
        if ($candidate !== null && $this->isValid($candidate)) {
            return $candidate;
        }

        return self::FALLBACK_TIMEZONE;
    }

    public function isValid(?string $candidate): bool
    {
        if ($candidate === null || $candidate === '') {
            return false;
        }

        return \in_array($candidate, \DateTimeZone::listIdentifiers(), true);
    }
}
