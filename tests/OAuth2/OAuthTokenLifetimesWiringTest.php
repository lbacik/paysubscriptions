<?php

declare(strict_types=1);

namespace App\Tests\OAuth2;

use App\OAuth2\OAuthTokenLifetimes;
use DateInterval;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Container-level contract (issue #135, problem 6): the bundle config and the
 * lifetimes service read the same parameters, so changing the access-token
 * lifetime parameter moves both the issued token's `exp` and the rotation
 * overlap together.
 */
final class OAuthTokenLifetimesWiringTest extends KernelTestCase
{
    public function testBundleConfigAndServiceShareOneDefinition(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $accessTtl = $container->getParameter('app.oauth2.access_token_ttl');
        $idleTtl = $container->getParameter('app.oauth2.refresh_idle_ttl');
        $leeway = $container->getParameter('app.oauth2.clock_skew_leeway');
        self::assertIsString($accessTtl);
        self::assertIsString($idleTtl);
        self::assertIsString($leeway);

        // The service is built from those same parameters.
        $lifetimes = $container->get(OAuthTokenLifetimes::class);
        self::assertSame($accessTtl, $lifetimes->accessTokenTtl);
        self::assertSame($idleTtl, $lifetimes->refreshIdleTtl);
        self::assertSame($leeway, $lifetimes->clockSkewLeeway);

        // The bundle issues `exp` from the access-token parameter: the
        // configured lifetime is the 15-minute contract the OAuth suite pins
        // (exp - iat == 900).
        $base = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        self::assertSame(900, $base->add(new DateInterval($accessTtl))->getTimestamp() - $base->getTimestamp());

        // …and the rotation overlap derives from it, so both move together.
        self::assertSame(960, self::totalSeconds($lifetimes->getRotationOverlap()));

        // The bundle config references the parameters instead of repeating
        // the literals: changing the parameter changes the issued token.
        $config = Yaml::parseFile(
            (string) $container->getParameter('kernel.project_dir').'/config/packages/league_oauth2_server.yaml'
        );
        self::assertSame('%app.oauth2.access_token_ttl%', $config['league_oauth2_server']['authorization_server']['access_token_ttl']);
        self::assertSame('%app.oauth2.refresh_idle_ttl%', $config['league_oauth2_server']['authorization_server']['refresh_token_ttl']);
        self::assertSame('%app.oauth2.clock_skew_leeway%', $config['league_oauth2_server']['resource_server']['jwt_leeway']);
    }

    private static function totalSeconds(DateInterval $interval): int
    {
        $base = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return $base->add($interval)->getTimestamp() - $base->getTimestamp();
    }
}
