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

    /**
     * @return array<string, mixed>
     */
    private static function exportedContract(): array
    {
        self::bootKernel();
        $command = static::getContainer()->get('api_platform.openapi.command');
        $tester = new CommandTester($command);

        $tmp = tempnam(sys_get_temp_dir(), 'openapi-snapshot-');
        self::assertIsString($tmp);
        try {
            self::assertSame(0, $tester->execute(['--output' => $tmp]));

            $doc = json_decode((string) file_get_contents($tmp), true);
            self::assertIsArray($doc, 'The generated OpenAPI document must be valid JSON.');

            return $doc;
        } finally {
            @unlink($tmp);
        }
    }

    public function testGeneratedDocumentMatchesCommittedSnapshot(): void
    {
        self::assertSame(
            self::committedContract(),
            self::exportedContract(),
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
    }
}
