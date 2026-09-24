<?php

declare(strict_types=1);

namespace App\Tests;

use App\Factory\SubscriptionFactory;
use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Guards the single source of truth for the WCAG 2.1 AA scan (issue #52):
 * tests/accessibility/scenarios.json.
 *
 * The Node/axe scan itself runs in CI against a booted server; this test runs
 * in the regular PHPUnit job with no browser and proves the registry has not
 * rotted: every covered scenario resolves to a real route, and no pending
 * scenario silently became available without being promoted to covered.
 */
final class AccessibilityScenariosTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    private const REGISTRY_PATH = __DIR__.'/accessibility/scenarios.json';

    /** @return array{scenarios: list<array<string, mixed>>} */
    private function registry(): array
    {
        self::assertFileExists(
            self::REGISTRY_PATH,
            'The axe scan scenario registry is missing; see docs/accessibility.md.'
        );

        $decoded = json_decode((string) file_get_contents(self::REGISTRY_PATH), true);
        self::assertIsArray($decoded, 'Scenario registry is not valid JSON.');
        self::assertArrayHasKey('scenarios', $decoded);
        self::assertNotEmpty($decoded['scenarios']);

        return $decoded;
    }

    public function testRegistryEntriesAreWellFormed(): void
    {
        $ids = [];

        foreach ($this->registry()['scenarios'] as $scenario) {
            self::assertArrayHasKey('id', $scenario);
            self::assertArrayHasKey('auth', $scenario);
            self::assertIsString($scenario['id']);
            self::assertNotContains($scenario['id'], $ids, sprintf('Duplicate scenario id "%s".', $scenario['id']));
            $ids[] = $scenario['id'];

            self::assertIsBool($scenario['auth'], sprintf('Scenario "%s": auth must be boolean.', $scenario['id']));
            self::assertNotEmpty($scenario['viewports'] ?? [], sprintf('Scenario "%s" needs at least one viewport.', $scenario['id']));

            foreach ($scenario['viewports'] ?? [] as $viewport) {
                self::assertContains($viewport, ['desktop', 'narrow'], sprintf('Scenario "%s": unknown viewport "%s".', $scenario['id'], $viewport));
            }

            if (($scenario['pending'] ?? false) === true) {
                self::assertArrayHasKey('issue', $scenario, sprintf('Pending scenario "%s" must reference its tracking issue.', $scenario['id']));
            } else {
                self::assertNotEmpty($scenario['path'] ?? null, sprintf('Covered scenario "%s" must define a path.', $scenario['id']));
                self::assertStringStartsWith('/', $scenario['path'], sprintf('Covered scenario "%s" path must be absolute.', $scenario['id']));
            }
        }
    }

    public function testCoveredScenariosResolveToRealRoutes(): void
    {
        $client = static::createClient();
        $subscriptionId = $this->createSubscriptionForScanUser();

        foreach ($this->registry()['scenarios'] as $scenario) {
            if (($scenario['pending'] ?? false) === true && empty($scenario['path'])) {
                continue;
            }

            $path = str_replace('{id}', $subscriptionId, (string) $scenario['path']);
            $client->request('GET', $path);
            $status = $client->getResponse()->getStatusCode();

            if (($scenario['pending'] ?? false) === true) {
                self::assertNotContains(
                    $status,
                    [200, 302, 303],
                    sprintf('Pending scenario "%s" now resolves (%d): promote it to covered in scenarios.json.', $scenario['id'], $status)
                );
                continue;
            }

            if ($scenario['auth'] === true) {
                // Anonymous request to a protected page must redirect to login:
                // that proves the route exists and is still behind auth.
                self::assertContains(
                    $status,
                    [301, 302, 303, 307, 308],
                    sprintf('Scenario "%s" (%s) expected an auth redirect, got %d.', $scenario['id'], $path, $status)
                );
                self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
            } else {
                self::assertSame(
                    200,
                    $status,
                    sprintf('Scenario "%s" (%s) expected 200, got %d.', $scenario['id'], $path, $status)
                );
            }
        }
    }

    public function testDynamicScenariosDeclareTheirPlaceholder(): void
    {
        $dynamic = array_filter(
            $this->registry()['scenarios'],
            static fn (array $s): bool => str_contains((string) ($s['path'] ?? ''), '{id}')
        );

        self::assertNotEmpty($dynamic, 'Expected at least one dynamic scenario (subscription edit/delete).');

        foreach ($dynamic as $scenario) {
            self::assertTrue($scenario['auth'], sprintf('Dynamic scenario "%s" must be authenticated.', $scenario['id']));
            self::assertSame(1, substr_count((string) $scenario['path'], '{id}'), sprintf('Scenario "%s": exactly one {id} placeholder.', $scenario['id']));
        }
    }

    private function createSubscriptionForScanUser(): string
    {
        $user = UserFactory::createOne(['email' => 'a11y-scan@example.com', 'isVerified' => true]);
        $subscription = SubscriptionFactory::createOne(['owner' => $user]);

        self::assertNotNull($subscription->getId());

        return (string) $subscription->getId();
    }
}
