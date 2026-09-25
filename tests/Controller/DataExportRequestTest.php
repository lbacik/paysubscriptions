<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\DatabaseTestCase;

/**
 * The manual email-request export path (issue #48) must be discoverable: the
 * account context points at the monitored contact process and names it as a
 * manual request, never as self-service.
 */
final class DataExportRequestTest extends DatabaseTestCase
{
    public function testAccountPagePointsAtTheContactProcessAsAManualRequest(): void
    {
        $user = $this->createUser('requester@example.com');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/account/delete');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('/contact', $content);
        self::assertStringContainsStringIgnoringCase('manual', $content);
        self::assertStringContainsStringIgnoringCase('export', $content);
        self::assertGreaterThan(0, $crawler->filter('a[href="/contact"]')->count());
    }

    public function testContactPageExplainsHowToRequestAnExport(): void
    {
        $this->client->request('GET', '/contact');

        self::assertResponseIsSuccessful();
        self::assertStringContainsStringIgnoringCase(
            'export',
            (string) $this->client->getResponse()->getContent()
        );
    }

    public function testAnonymousAccountPageStaysBehindLogin(): void
    {
        $this->client->request('GET', '/account/delete');

        self::assertResponseRedirects('/login');
    }
}
