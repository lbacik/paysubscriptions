<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Locks the published API v1 contract (issue #260, decision #250.8).
 *
 * The committed `resources/openapi/v1.json` is the generated API Platform
 * document: additive backward-compatible changes stay within v1, breaking
 * changes require a new version. The export runs in-process with no
 * database; regeneration is manual only:
 *
 *     bin/console api:openapi:export --output=resources/openapi/v1.json
 */
final class OpenApiSnapshotTest extends KernelTestCase
{
    private const REGENERATE = 'bin/console api:openapi:export --output=resources/openapi/v1.json';

    /**
     * @return array<string, mixed>
     */
    private static function committedContract(): array
    {
        $path = \dirname(__DIR__, 3).'/resources/openapi/v1.json';
        self::assertFileExists($path);

        $doc = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($doc, 'The v1 OpenAPI contract must be valid JSON.');

        return $doc;
    }

    private static function exportedContractContents(): string
    {
        self::bootKernel();
        $command = static::getContainer()->get('api_platform.openapi.command');
        $tester = new CommandTester($command);

        $tmp = tempnam(sys_get_temp_dir(), 'openapi-snapshot-');
        self::assertIsString($tmp);
        try {
            self::assertSame(0, $tester->execute(['--output' => $tmp]));

            $contents = file_get_contents($tmp);
            self::assertIsString($contents, 'The generated OpenAPI export must be readable.');
            json_decode($contents, true);
            self::assertSame(JSON_ERROR_NONE, json_last_error(), 'The generated OpenAPI document must be valid JSON.');

            return $contents;
        } finally {
            @unlink($tmp);
        }
    }

    public function testGeneratedDocumentMatchesCommittedSnapshot(): void
    {
        $path = \dirname(__DIR__, 3).'/resources/openapi/v1.json';
        self::assertFileExists($path);
        $committed = file_get_contents($path);
        self::assertIsString($committed, 'The committed v1 OpenAPI snapshot must be readable.');

        self::assertSame(
            $committed,
            self::exportedContractContents(),
            'The generated OpenAPI document drifted from resources/openapi/v1.json. Regenerate it with '.self::REGENERATE.' and review the diff as a contract change.'
        );
    }

    public function testContractIsVersionedV1(): void
    {
        $doc = self::committedContract();

        self::assertSame('3.2.0', $doc['openapi']);
        self::assertSame('1.0.0', $doc['info']['version']);
        self::assertStringContainsStringIgnoringCase('paysubscriptions', $doc['info']['title']);
        self::assertNotEmpty($doc['paths']);
    }

    public function testDocumentCarriesTheOAuth2SecurityScheme(): void
    {
        $doc = self::committedContract();

        // Every operation requires the single full-access scope (decision
        // #247): the global requirement pins it, the scheme defines it.
        self::assertSame([['oauth' => ['api:full']]], array_values($doc['security']));

        $scheme = $doc['components']['securitySchemes']['oauth'];
        self::assertSame('oauth2', $scheme['type']);
        $flows = $scheme['flows']['authorizationCode'];
        self::assertSame('/authorize', $flows['authorizationUrl']);
        self::assertSame('/token', $flows['tokenUrl']);
        self::assertSame(['api:full'], array_keys($flows['scopes']));
    }

    public function testSwaggerUiSignsInWithTheDocsClientAndPkce(): void
    {
        self::bootKernel();
        $params = static::getContainer()->getParameterBag();

        // The Client the Swagger UI sign-in uses (decision #258): a public
        // docs Client with the authorization-code + PKCE flow, relative
        // URLs so the committed snapshot stays host-agnostic, and no
        // persisted authorization on a public page.
        self::assertTrue($params->get('api_platform.oauth.enabled'));
        self::assertSame('oauth2', $params->get('api_platform.oauth.type'));
        self::assertSame('authorizationCode', $params->get('api_platform.oauth.flow'));
        self::assertSame('paysubscriptions-api-docs', $params->get('api_platform.oauth.clientId'));
        self::assertSame('', $params->get('api_platform.oauth.clientSecret'));
        self::assertSame('/authorize', $params->get('api_platform.oauth.authorizationUrl'));
        self::assertSame('/token', $params->get('api_platform.oauth.tokenUrl'));
        self::assertTrue($params->get('api_platform.oauth.pkce'));
        self::assertSame(['api:full'], array_keys($params->get('api_platform.oauth.scopes')));
        self::assertFalse($params->get('api_platform.swagger.persist_authorization'));
    }

    public function testSubscriptionListDeclaresSortAndCategoryParameters(): void
    {
        $doc = self::committedContract();

        $list = $doc['paths']['/api/v1/subscriptions']['get'] ?? null;
        self::assertIsArray($list, 'The Subscription list operation must exist in the published contract.');
        $byName = [];
        foreach ($list['parameters'] ?? [] as $parameter) {
            $byName[$parameter['name']] = $parameter;
        }

        foreach (['order[name]', 'order[price]', 'order[renewal]'] as $name) {
            self::assertArrayHasKey($name, $byName, sprintf('The Subscription list must declare "%s".', $name));
            self::assertSame('query', $byName[$name]['in']);
            self::assertSame('string', $byName[$name]['schema']['type'] ?? null);
            self::assertSame(['asc', 'desc'], $byName[$name]['schema']['enum'] ?? null);
            self::assertStringContainsString('Only one', $byName[$name]['description'] ?? '');
            self::assertStringContainsString('422', $byName[$name]['description'] ?? '');
            self::assertStringContainsString('name/asc', $byName[$name]['description'] ?? '');
        }

        self::assertArrayHasKey('category', $byName, 'The Subscription list must declare "category".');
        self::assertSame('query', $byName['category']['in']);
        self::assertSame('string', $byName['category']['schema']['type'] ?? null);
        self::assertSame('uuid', $byName['category']['schema']['format'] ?? null);
        self::assertStringContainsString('422', $byName['category']['description'] ?? '');
        self::assertStringContainsString('matches nothing', $byName['category']['description'] ?? '');

        foreach (['get', 'patch', 'delete'] as $method) {
            $item = $doc['paths']['/api/v1/subscriptions/{id}'][$method] ?? null;
            self::assertIsArray($item, sprintf('The Subscription item %s operation must exist.', $method));
            $names = array_column($item['parameters'] ?? [], 'name');
            foreach (['order[name]', 'order[price]', 'order[renewal]', 'category'] as $name) {
                self::assertNotContains($name, $names, sprintf('The Subscription item %s operation must not declare "%s".', $method, $name));
            }
        }
    }
}
