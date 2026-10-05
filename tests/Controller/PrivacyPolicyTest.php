<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\DatabaseTestCase;

/**
 * Covers the v1.0 privacy and data-handling policy page (issue #49, part of
 * #34; sources #30 with the overriding correction in #28).
 *
 * The page must describe implemented behavior: actual storage, the verified
 * external-service inventory (docs/third-party-services.md), the no-sale
 * commitment without an unqualified no-sharing promise, plain disclosure of
 * missing application-level field encryption (ADR 0001), account-deletion
 * scope with the separate newsletter path, and manual-request export.
 */
final class PrivacyPolicyTest extends DatabaseTestCase
{
    public function testPrivacyPageIsPublic(): void
    {
        $this->client->request('GET', '/privacy');

        self::assertResponseIsSuccessful();
    }

    public function testPrivacyPageIsReachableWhenSignedIn(): void
    {
        $user = $this->createUser('privacy-reader@example.com');
        $this->client->loginUser($user);
        $this->client->request('GET', '/privacy');

        self::assertResponseIsSuccessful();
    }

    public function testFooterLinksToThePrivacyPage(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $this->client->getCrawler()->filter('footer a[href="/privacy"]')->count(),
            'The public footer must link to the privacy page.'
        );
    }

    public function testAccountMenuLinksToThePrivacyPage(): void
    {
        $user = $this->createUser('privacy-menu@example.com');
        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $this->client->getCrawler()->filter('a[href="/privacy"]')->count(),
            'Signed-in account contexts (user menu) must link to the privacy page.'
        );
    }

    public function testPolicyStatesDataIsNotSoldWithoutAbsoluteSharingClaims(): void
    {
        $content = $this->getPrivacyContent();

        self::assertStringContainsStringIgnoringCase('not sold', $content);
        self::assertStringContainsString('Account and Subscription data is not sold', $content);

        // Banned by the #28 correction: Umami must not be framed as the sole
        // exception, and no unqualified no-third-party-sharing promise.
        self::assertStringNotContainsStringIgnoringCase('sole exception', $content);
        self::assertStringNotContainsStringIgnoringCase('only third party', $content);
        self::assertStringNotContainsStringIgnoringCase('only external service', $content);
        self::assertStringNotContainsStringIgnoringCase('we do not share', $content);
        self::assertStringNotContainsStringIgnoringCase('never share', $content);
        self::assertStringNotContainsStringIgnoringCase('100% private', $content);
    }

    public function testPolicyNamesEveryInventoriedExternalService(): void
    {
        $content = $this->getPrivacyContent();

        // Automatic requests (docs/third-party-services.md).
        self::assertStringContainsString('Umami', $content);
        self::assertStringContainsString('umami.rum.luka.sh', $content);
        self::assertStringContainsString('our own server', $content);
        self::assertStringContainsString('not Umami Cloud', $content);
        self::assertStringContainsString('reCAPTCHA', $content);
        self::assertStringContainsString('Google Fonts', $content);
        self::assertStringContainsString('Font Awesome', $content);
        self::assertStringContainsString('cdnjs.cloudflare.com', $content);

        // On-action disclosures.
        self::assertStringContainsString('gprodb.com', $content);
        self::assertStringContainsString('BuyMeACoffee', $content);

        // The request-metadata baseline every automatic request discloses.
        self::assertStringContainsStringIgnoringCase('IP address', $content);
    }

    public function testPolicyDisclosesStorageWithoutFieldLevelEncryption(): void
    {
        $content = $this->getPrivacyContent();

        self::assertStringContainsStringIgnoringCase('no application-level field encryption', $content);
        self::assertStringContainsStringIgnoringCase('password', $content);
        self::assertStringContainsStringIgnoringCase('hash', $content);

        // No unsupported security or legal claims.
        self::assertStringNotContainsStringIgnoringCase('bank-grade', $content);
        self::assertStringNotContainsStringIgnoringCase('military-grade', $content);
        self::assertStringNotContainsStringIgnoringCase('end-to-end encrypt', $content);
        self::assertStringNotContainsStringIgnoringCase('GDPR-compliant', $content);
        self::assertStringNotContainsStringIgnoringCase('fully encrypted', $content);
    }

    public function testPolicyExplainsDeletionNewsletterAndManualExport(): void
    {
        $content = $this->getPrivacyContent();

        // Account deletion scope.
        self::assertStringContainsString('/account/delete', $content);
        self::assertStringContainsStringIgnoringCase('cannot be undone', $content);

        // The newsletter is a separate opt-in with its own unsubscribe path.
        self::assertStringContainsStringIgnoringCase('separate opt-in', $content);
        self::assertStringContainsStringIgnoringCase('unsubscribe', $content);
        self::assertStringContainsStringIgnoringCase(
            'deleting your account does not unsubscribe',
            $content
        );

        // Export is a manual email request, never a self-service download.
        self::assertStringContainsStringIgnoringCase('manual email request', $content);
        self::assertStringContainsString('Data export request', $content);
        self::assertStringContainsString('/contact', $content);
        self::assertStringNotContainsStringIgnoringCase('download your data', $content);
    }

    public function testPolicyHasOneHeadingAndNamedSections(): void
    {
        $this->client->request('GET', '/privacy');

        self::assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();

        self::assertCount(1, $crawler->filter('h1'));
        self::assertCount(1, $crawler->filter('main'));

        $sections = $crawler->filter('main section[aria-labelledby]');
        self::assertGreaterThanOrEqual(3, $sections->count(), 'Content sections must name their headings.');
        foreach ($sections as $section) {
            $labelledBy = $section->getAttribute('aria-labelledby');
            self::assertNotSame('', $labelledBy);
            self::assertGreaterThan(0, $crawler->filter('#'.$labelledBy)->count());
        }
    }

    private function getPrivacyContent(): string
    {
        $this->client->request('GET', '/privacy');

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }
}
