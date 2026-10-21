<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Locks the stable v1 contract (issue #100, decision #87).
 *
 * The committed resources/openapi/v1.json is the reviewed contract: additive
 * backward-compatible changes stay within v1, breaking changes require a new
 * version. These tests fail on drift in either direction — an implemented
 * route, schema, filter, or error that the document does not describe, or a
 * documented operation the implementation does not serve.
 *
 * Until the docs switch, the document covers only the hand-written v1
 * resources: routes served by API Platform (ExpenseCategory, Subscription)
 * are described by API Platform's generated document and skipped here.
 *
 * Needs no database: it boots the kernel for the route collection and reads
 * the committed file. Per-resource behavior (isolation, validation statuses,
 * parity) stays in the sibling Api tests.
 */
final class OpenApiContractTest extends KernelTestCase
{
    private const CONTRACT_VERSION = '1.0.0';

    /**
     * @return array<string, mixed>
     */
    private static function contract(): array
    {
        $path = \dirname(__DIR__, 4).'/resources/openapi/v1.json';
        self::assertFileExists($path);

        $doc = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($doc, 'The v1 OpenAPI contract must be valid JSON.');

        return $doc;
    }

    public function testContractIsVersionedV1(): void
    {
        $doc = self::contract();

        self::assertSame('3.0.3', $doc['openapi']);
        self::assertSame(self::CONTRACT_VERSION, $doc['info']['version']);
        self::assertStringContainsStringIgnoringCase('paysubscriptions', $doc['info']['title']);
    }

    public function testEveryV1RouteIsDocumented(): void
    {
        self::bootKernel();
        /** @var RouterInterface $router */
        $router = static::getContainer()->get(RouterInterface::class);
        $doc = self::contract();

        $missing = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/api/v1/') || null !== $route->getDefault('_api_resource_class')) {
                continue;
            }
            $docPath = substr($path, \strlen('/api/v1'));
            $docPath = preg_replace('#\{[^}]+}#', '{id}', $docPath);
            foreach ($route->getMethods() as $method) {
                $operation = $doc['paths'][$docPath][strtolower($method)] ?? null;
                if (null === $operation) {
                    $missing[] = sprintf('%s %s (route %s)', $method, $docPath, $name);
                }
            }
        }

        self::assertSame([], $missing, 'Implemented v1 routes missing from the OpenAPI contract.');
    }

    public function testEveryDocumentedOperationHasARoute(): void
    {
        self::bootKernel();
        /** @var RouterInterface $router */
        $router = static::getContainer()->get(RouterInterface::class);
        $doc = self::contract();

        $routes = [];
        foreach ($router->getRouteCollection()->all() as $route) {
            $path = preg_replace('#\{[^}]+}#', '{id}', $route->getPath());
            foreach ($route->getMethods() as $method) {
                $routes[strtoupper($method).' '.$path] = true;
            }
        }

        $undocumented = [];
        foreach ($doc['paths'] as $docPath => $operations) {
            foreach ($operations as $method => $operation) {
                if (!isset($routes[strtoupper($method).' /api/v1'.$docPath])) {
                    $undocumented[] = sprintf('%s /api/v1%s', strtoupper($method), $docPath);
                }
            }
        }

        self::assertSame([], $undocumented, 'Documented v1 operations with no implemented route.');
    }

    public function testResponseSchemasExposeOwnedDataOnly(): void
    {
        $schemas = self::contract()['components']['schemas'];

        self::assertArrayHasKey('DashboardReport', $schemas, 'Schema DashboardReport is missing from the contract.');

        // Ownership is bearer-implied: no response schema may leak an owner reference.
        $properties = array_keys($schemas['DashboardReport']['properties'] ?? []);
        self::assertNotContains('owner', $properties, 'DashboardReport must not expose owner.');
        self::assertNotContains('owner_id', $properties, 'DashboardReport must not expose owner_id.');
        self::assertNotContains('ownerId', $properties, 'DashboardReport must not expose ownerId.');
    }

    public function testSecurityUsesSingleFullAccessScope(): void
    {
        $doc = self::contract();

        self::assertSame([['paySubsOAuth2' => ['api:full']]], array_values($doc['security']));

        $scheme = $doc['components']['securitySchemes']['paySubsOAuth2'];
        self::assertSame('oauth2', $scheme['type']);
        $flows = $scheme['flows']['authorizationCode'];
        self::assertSame('/authorize', $flows['authorizationUrl']);
        self::assertSame('/token', $flows['tokenUrl']);
        self::assertSame(['api:full'], array_keys($flows['scopes']));

        foreach ($doc['paths'] as $docPath => $operations) {
            foreach ($operations as $method => $operation) {
                self::assertSame(
                    [['paySubsOAuth2' => ['api:full']]],
                    array_values($operation['security'] ?? []),
                    sprintf('%s %s must require the api:full scope.', strtoupper($method), $docPath)
                );
            }
        }
    }

    public function testProblemErrorsUseProblemJson(): void
    {
        $doc = self::contract();

        $problem = $doc['components']['schemas']['Problem'];
        foreach (['type', 'title', 'status', 'detail', 'code'] as $field) {
            self::assertContains($field, $problem['required']);
        }

        foreach ($doc['components']['responses'] as $name => $response) {
            self::assertArrayHasKey(
                'application/problem+json',
                $response['content'] ?? [],
                sprintf('Error response %s must use application/problem+json.', $name)
            );
        }

        $expectations = [
            '/reports/dashboard' => ['get' => ['200', '401', '403', '404', '422']],
            '/openapi.json' => ['get' => ['200', '401', '403']],
        ];

        foreach ($expectations as $docPath => $methods) {
            foreach ($methods as $method => $statuses) {
                // PHP casts numeric JSON object keys to int; normalize for comparison.
                $responses = array_map('strval', array_keys($doc['paths'][$docPath][$method]['responses'] ?? []));
                foreach ($statuses as $status) {
                    self::assertContains($status, $responses, sprintf('%s %s must document %s.', strtoupper($method), $docPath, $status));
                }
            }
        }
    }

    public function testReportSelectorsAreDocumented(): void
    {
        $doc = self::contract();

        $reportParams = array_column($doc['paths']['/reports/dashboard']['get']['parameters'] ?? [], 'name');
        foreach (['chartMode', 'basis', 'categoryId', 'month'] as $selector) {
            self::assertContains($selector, $reportParams);
        }
        self::assertNotContains('year', $reportParams, 'v1 has no year selector (decision #84).');
        self::assertNotContains('dateRange', $reportParams, 'v1 has no date-range selector (decision #84).');
    }
}
