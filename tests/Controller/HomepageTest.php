<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Factory\UserFactory;
use App\Repository\UserRepository;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Covers the current homepage design.
 */
final class HomepageTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    public function testAnonymousVisitorSeesOutcomeHeadlineAndRegistrationCta(): void
    {
        $client = static::createClient();
        $content = $this->getHomepageContent($client);

        self::assertStringContainsString('Know what you pay. See what renews next.', $content);

        $registerUrl = '/register';
        $crawler = $client->getCrawler();
        $ctaLinks = $crawler->filter('main a.btn-cta');
        self::assertGreaterThanOrEqual(1, $ctaLinks->count(), 'Homepage must render a primary CTA inside <main>.');
        self::assertSame($registerUrl, $ctaLinks->first()->attr('href'), 'Anonymous primary CTA must go to registration.');
    }

    public function testSignedInUserSeesDashboardCtaInsteadOfRegistration(): void
    {
        $client = static::createClient();
        UserFactory::createOne(['email' => 'homepage-user@example.com', 'isVerified' => true]);
        $this->loginAs($client, 'homepage-user@example.com');

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();
        $ctaLinks = $crawler->filter('main a.btn-cta');
        self::assertGreaterThanOrEqual(1, $ctaLinks->count(), 'Homepage must render a primary CTA inside <main>.');
        self::assertSame('/dashboard', $ctaLinks->first()->attr('href'), 'Signed-in primary CTA must go to the dashboard.');
        self::assertStringContainsString('Go to your dashboard', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('/register', $ctaLinks->first()->attr('href'));
    }

    public function testPreviewIsClearlyIllustrativeAndNeverARealRecord(): void
    {
        $client = static::createClient();
        $content = $this->getHomepageContent($client);

        self::assertStringContainsString('Illustrative example', $content);
        self::assertStringContainsString('not your data', $content);
        self::assertStringContainsString('Planned for v1.0', $content);
        // The preview must never borrow the language of real records.
        self::assertStringNotContainsString('Your upcoming renewals', $content);
        self::assertStringNotContainsString('Dashboard Demo', $content);
    }

    public function testHowItWorksStepsAreExplained(): void
    {
        $client = static::createClient();
        $content = $this->getHomepageContent($client);

        self::assertStringContainsString('Planned for v1.0', $content);
        // Three-step explanation section is present in the hero.
        self::assertStringContainsString('id="how-it-works"', $content);
        self::assertStringContainsString('Add what you pay for', $content);
        self::assertStringContainsString('See the dates and totals', $content);
        self::assertStringContainsString('Decide in time', $content);
    }

    public function testAbsolutePrivacyClaimsAreBanned(): void
    {
        $client = static::createClient();
        $content = $this->getHomepageContent($client);

        // Absolute privacy claims are banned after the #28 correction.
        self::assertStringNotContainsString('100% private', $content);
        self::assertStringNotContainsString('100% Private', $content);
        self::assertStringNotContainsString('sole exception', $content);
        self::assertStringNotContainsString('only third party', $content);
    }

    public function testMarkupIsAccessible(): void
    {
        $client = static::createClient();
        $this->getHomepageContent($client);
        $crawler = $client->getCrawler();

        // Exactly one top-level heading and one main landmark.
        self::assertCount(1, $crawler->filter('h1'));
        self::assertCount(1, $crawler->filter('main'));

        // Every content section names its heading.
        $sections = $crawler->filter('main section[aria-labelledby]');
        self::assertGreaterThanOrEqual(1, $sections->count(), 'Content sections must name their headings.');
        foreach ($sections as $section) {
            $labelledBy = $section->getAttribute('aria-labelledby');
            self::assertNotSame('', $labelledBy);
            self::assertGreaterThan(0, $crawler->filter('#'.$labelledBy)->count(), \sprintf('Section references missing heading #%s.', $labelledBy));
        }
    }

    private function getHomepageContent(KernelBrowser $client): string
    {
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        return (string) $client->getResponse()->getContent();
    }

    private function loginAs(KernelBrowser $client, string $email): void
    {
        $user = $client->getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        $client->loginUser($user);
    }
}
