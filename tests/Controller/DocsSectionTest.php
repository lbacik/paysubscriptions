<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression tests for the public documentation page (/about).
 *
 * The `section` query parameter must never be concatenated into a filesystem
 * path: only section keys known to the documentation index may render,
 * everything else (traversal sequences, absolute paths, encoded separators,
 * null bytes, unknown names, odd casing) returns 404 without reading a file.
 */
final class DocsSectionTest extends WebTestCase
{
    public function testValidSectionRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about', ['section' => 'Api']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsStringIgnoringCase('API', (string) $client->getResponse()->getContent());
    }

    public function testDefaultSectionRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about');

        self::assertResponseIsSuccessful();
    }

    public function testUnknownSectionReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about', ['section' => 'NoSuchSection']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTraversalAttemptReturns404AndReadsNoFile(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about', ['section' => '../../AGENTS']);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(
            self::agentsMdMarker(),
            (string) $client->getResponse()->getContent()
        );
    }

    public function testAbsolutePathReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about', ['section' => '/etc/passwd']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testEncodedSeparatorReturns404(): void
    {
        $client = static::createClient();
        // Raw percent-encoded traversal on the wire; the framework decodes
        // it to ../../AGENTS before the controller sees it.
        $client->request('GET', '/about?section=..%2F..%2FAGENTS');

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(
            self::agentsMdMarker(),
            (string) $client->getResponse()->getContent()
        );
    }

    public function testNullByteReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about', ['section' => "Api\x00.md"]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testOddCasingReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about', ['section' => 'API']);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Marker unique to AGENTS.md at the repo root. Built by concatenation so
     * the contiguous string never appears in this file's source: debug 404
     * pages excerpt test sources, and a literal marker would match itself.
     */
    private static function agentsMdMarker(): string
    {
        return 'Per-repo' . ' configuration';
    }
}
