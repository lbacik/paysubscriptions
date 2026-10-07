<?php

declare(strict_types=1);

namespace App\Tests\OAuth2;

use App\OAuth2\OAuthTokenLifetimes;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * OAuth token lifetimes are defined once as container parameters (issue #135,
 * problem 6): the League bundle config references the parameters for the
 * issued token's `exp`, and this service derives the key-rotation overlap
 * from those same parameters instead of hard-coding it.
 */
final class OAuthTokenLifetimesTest extends TestCase
{
    public function testRotationOverlapAddsAccessTtlAndLeeway(): void
    {
        $lifetimes = new OAuthTokenLifetimes('PT15M', 'P30D', 'PT60S');

        self::assertSame(960, self::totalSeconds($lifetimes->getRotationOverlap()));
    }

    public function testChangingAccessTtlChangesRotationOverlap(): void
    {
        $default = new OAuthTokenLifetimes('PT15M', 'P30D', 'PT60S');
        $longer = new OAuthTokenLifetimes('PT30M', 'P30D', 'PT60S');

        self::assertSame(960, self::totalSeconds($default->getRotationOverlap()));
        self::assertSame(1860, self::totalSeconds($longer->getRotationOverlap()));
    }

    public function testChangingLeewayChangesRotationOverlap(): void
    {
        $default = new OAuthTokenLifetimes('PT15M', 'P30D', 'PT60S');
        $generous = new OAuthTokenLifetimes('PT15M', 'P30D', 'PT120S');

        self::assertSame(960, self::totalSeconds($default->getRotationOverlap()));
        self::assertSame(1020, self::totalSeconds($generous->getRotationOverlap()));
    }

    public function testRefreshIdleTtlMatchesThirtyDays(): void
    {
        $lifetimes = new OAuthTokenLifetimes('PT15M', 'P30D', 'PT60S');

        self::assertSame(30 * 24 * 3600, self::totalSeconds($lifetimes->getRefreshIdleTtl()));
    }

    private static function totalSeconds(DateInterval $interval): int
    {
        $base = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return $base->add($interval)->getTimestamp() - $base->getTimestamp();
    }
}
