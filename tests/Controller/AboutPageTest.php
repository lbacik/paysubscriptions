<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers the story-led About page with the data-handling section and the
 * vertical Now / Next / Later roadmap (issue #54, part of #34;
 * sources #25, #27, #28; selected prototype variant A at 0eaf67a).
 *
 * Pre-launch framing: the release gates in #34 have not passed, so any
 * visible Now content must be labelled as a v1.0 goal, not current
 * behavior. Data-handling copy must match the final privacy policy
 * (issue #49 with the #28 correction): no unqualified no-sharing claim,
 * Umami named without being framed as the sole external service.
 */
final class AboutPageTest extends WebTestCase
{
    public function testAboutPageIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about');

        self::assertResponseIsSuccessful();
    }

    public function testAboutExplainsFreeManualFirstSingleOwnerHouseholdBoundary(): void
    {
        $content = $this->getAboutContent();

        self::assertStringContainsStringIgnoringCase('free', $content);
        self::assertStringContainsStringIgnoringCase('manual', $content);
        self::assertStringContainsStringIgnoringCase('household', $content);
        self::assertStringContainsStringIgnoringCase('one person', $content);
        self::assertStringContainsStringIgnoringCase('no bank connection', $content);
        self::assertStringContainsStringIgnoringCase('no shared account', $content);
    }

    public function testAboutDistinguishesShippedFromPlannedCapabilities(): void
    {
        $content = $this->getAboutContent();

        self::assertStringContainsString('Available now', $content);
        self::assertStringContainsString('Planned for v1.0', $content);
    }

    public function testDataHandlingMatchesTheFinalPrivacyPolicy(): void
    {
        $content = $this->getAboutContent();

        // External services: hosted Umami named, never as the sole exception.
        self::assertStringContainsString('Umami', $content);
        self::assertStringContainsString('/privacy', $content);
        self::assertStringContainsStringIgnoringCase('not sold', $content);
        self::assertStringNotContainsStringIgnoringCase('sole exception', $content);
        self::assertStringNotContainsStringIgnoringCase('only third party', $content);
        self::assertStringNotContainsStringIgnoringCase('only external service', $content);
        self::assertStringNotContainsStringIgnoringCase('we do not share', $content);
        self::assertStringNotContainsStringIgnoringCase('never share', $content);

        // Storage: plain disclosure, no inflated security claims.
        self::assertStringContainsStringIgnoringCase('encrypt', $content);
        self::assertStringNotContainsStringIgnoringCase('bank-grade', $content);
        self::assertStringNotContainsStringIgnoringCase('end-to-end encrypt', $content);

        // Control: deletion scope with the separate newsletter path, manual export.
        self::assertStringContainsStringIgnoringCase('delete', $content);
        self::assertStringContainsStringIgnoringCase('newsletter', $content);
        self::assertStringContainsString('gprodb.com', $content);
        self::assertStringContainsStringIgnoringCase('separate opt-in', $content);
        self::assertStringContainsStringIgnoringCase('manual', $content);
        self::assertStringContainsString('Data export request', $content);
        self::assertStringNotContainsStringIgnoringCase('download your data', $content);
    }

    public function testRoadmapIsVerticalNowNextLaterWithNowLabelledAsV1Goal(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about');

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();

        $roadmap = $crawler->filter('section[aria-labelledby="roadmap-title"]');
        self::assertCount(1, $roadmap, 'The roadmap must be a single labelled section.');

        $text = $roadmap->text();
        self::assertStringContainsString('Now', $text);
        self::assertStringContainsString('Next', $text);
        self::assertStringContainsString('Later', $text);

        // Pre-launch: Now is a v1.0 goal, never current behavior.
        self::assertStringContainsString('v1.0 goal', $text);
        self::assertStringContainsStringIgnoringCase('not all available today', $text);
        self::assertStringNotContainsStringIgnoringCase('already available', $text);

        // Next and Later are exploratory directions, not commitments.
        self::assertStringContainsStringIgnoringCase('explore', $text);
        self::assertStringContainsStringIgnoringCase('no dates', $text);
        self::assertStringContainsStringIgnoringCase('no guaranteed order', $text);
        self::assertStringNotContainsStringIgnoringCase('we promise', $text);
    }

    public function testAboutPageHasOneHeadingAndNamedSections(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about');

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();

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

    public function testAboutPageIsLinkedFromNavigation(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $client->getCrawler()->filter('header a[href="/about"], nav a[href="/about"]')->count(),
            'The header navigation must link to the About page.'
        );
        self::assertGreaterThan(
            0,
            $client->getCrawler()->filter('footer a[href="/about"]')->count(),
            'The footer must link to the About page.'
        );
    }

    private function getAboutContent(): string
    {
        $client = static::createClient();
        $client->request('GET', '/about');

        self::assertResponseIsSuccessful();

        return (string) $client->getResponse()->getContent();
    }
}
