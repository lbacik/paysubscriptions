<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Yaml\Yaml;

final class FirewallTest extends WebTestCase
{
    /**
     * Patterns a firewall with `security: false` bypasses authentication for
     * entirely, so a route matching one runs with no security context at all
     * - keep this list exact rather than "contains" so a broadened pattern
     * fails the test.
     */
    private const EXEMPT_PATTERNS = ['^/(_profiler|_wdt)/', '^/assets/'];

    public function testUnauthenticatedFirewallPatternsAreLimitedToDevToolsAndAssets(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/security.yaml');
        $firewalls = $config['security']['firewalls'];

        $unauthenticatedPatterns = [];
        foreach ($firewalls as $name => $firewall) {
            if (($firewall['security'] ?? true) === false) {
                self::assertArrayHasKey('pattern', $firewall, \sprintf('Firewall "%s" disables security but has no pattern, so it would apply to every request.', $name));
                $unauthenticatedPatterns[$name] = $firewall['pattern'];
            }
        }

        self::assertSame(
            ['dev' => self::EXEMPT_PATTERNS[0], 'assets' => self::EXEMPT_PATTERNS[1]],
            $unauthenticatedPatterns,
        );
    }

    public function testNoApplicationRouteIsReachableUnderTheExemptPrefixes(): void
    {
        self::bootKernel();
        $router = static::getContainer()->get('router');

        foreach ($router->getRouteCollection() as $name => $route) {
            foreach (self::EXEMPT_PATTERNS as $pattern) {
                self::assertDoesNotMatchRegularExpression(
                    '#'.$pattern.'#',
                    $route->getPath(),
                    \sprintf('Route "%s" (%s) is reachable under a firewall pattern that disables security.', $name, $route->getPath()),
                );
            }
        }
    }

    public function testProtectedApplicationRouteStillRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard');

        self::assertResponseRedirects('/login');
    }
}
