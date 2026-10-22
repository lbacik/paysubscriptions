<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

/**
 * No API v1 operation ships unprotected (decision #250.3).
 *
 * Every operation in the generated contract (`resources/openapi/v1.json`)
 * answers 401 without a token and 403 with a token lacking `api:full`.
 * The full gate matrix lives once in `TokenGateTest`; this suite only pins
 * that every operation is behind the same gate, so adding an operation
 * without protection fails here.
 */
final class ApiOperationsGateTest extends ApiV1TestCase
{
    private const EMAIL = 'operations-gate-user@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        // The 403 probe carries a token minted for a real, eligible User:
        // the listener checks the User before the scope, so an unknown
        // User would answer 401 instead of the 403 this suite pins.
        $this->createUser(self::EMAIL);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function operations(): iterable
    {
        $path = \dirname(__DIR__, 3).'/resources/openapi/v1.json';
        self::assertFileExists($path);

        $doc = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($doc);

        foreach ($doc['paths'] as $docPath => $operations) {
            foreach ($operations as $method => $operation) {
                if (!\is_array($operation)) {
                    continue;
                }
                // Item paths carry an `{id}` template: no id may exist, but
                // the gate refuses before lookup, so any UUID behaves the same.
                $url = str_replace('{id}', (string) Uuid::v4(), $docPath);
                yield strtoupper($method).' '.$docPath => [strtoupper($method), $url];
            }
        }
    }

    #[DataProvider('operations')]
    public function testOperationWithoutTokenIsUnauthorized(string $method, string $url): void
    {
        $this->client->request($method, $url);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertJsonContains(['type' => '/errors/401', 'status' => 401]);
    }

    #[DataProvider('operations')]
    public function testOperationWithoutFullScopeIsForbidden(string $method, string $url): void
    {
        $token = $this->craftToken(self::EMAIL, scope: 'api:read');
        $this->client->request($method, $url, ['auth_bearer' => $token]);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertJsonContains(['type' => '/problems/insufficient-scope', 'status' => 403]);
    }
}
