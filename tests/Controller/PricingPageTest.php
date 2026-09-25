<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Limits;
use App\Entity\User;
use App\Factory\UserFactory;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Aligns the pricing page with the v1.0 product package (issue #55, part of
 * #34; sources #24, #26, #27, #28).
 *
 * Pricing must agree with the homepage, the About page, and the docs about
 * what is available, planned, free, and limited: no absolute privacy claims
 * (banned after the #28 correction), no stale "research and planning" status
 * for reminders, honest currency wording with no automatic conversion, and no
 * copy implying bank/inbox integration, provider cancellation, shared
 * household access, or self-service export.
 */
final class PricingPageTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    public function testPricingPageIsPublicWithOneHeadingAndMainLandmark(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pricing');

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();
        self::assertCount(1, $crawler->filter('h1'));
        self::assertCount(1, $crawler->filter('main'));
    }

    public function testAnonymousVisitorSeesRegistrationCtaAndSignedInUserSeesDashboard(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pricing');

        self::assertResponseIsSuccessful();
        self::assertSame('/register', $client->getCrawler()->filter('main a.btn-cta')->first()->attr('href'));

        UserFactory::createOne(['email' => 'pricing-user@example.com', 'isVerified' => true]);
        $this->loginAs($client, 'pricing-user@example.com');
        $client->request('GET', '/pricing');

        self::assertResponseIsSuccessful();
        self::assertSame('/dashboard', $client->getCrawler()->filter('main a.btn-cta')->first()->attr('href'));
    }

    public function testPricingLinksToContactAndAbout(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pricing');

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();
        self::assertGreaterThan(0, $crawler->filter('main a[href="/contact"]')->count(), 'Pricing must link to contact.');
        self::assertGreaterThan(0, $crawler->filter('main a[href="/about"]')->count(), 'Pricing must link to About for the available-vs-planned distinction.');
    }

    public function testPricingStatesTheAccountLimitDynamically(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pricing');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString((string) Limits::DEFAULT_SUBSCRIPTIONS_LIMIT, $content);
        self::assertStringContainsStringIgnoringCase('no paid tier', $content);
    }

    public function testPricingAvoidsAbsolutePrivacyClaims(): void
    {
        $content = $this->getPricingContent();

        // Absolute privacy language is banned after the #28 correction.
        self::assertStringNotContainsString('100% private', $content);
        self::assertStringNotContainsString('100% privacy', $content);
        self::assertStringNotContainsStringIgnoringCase('sole exception', $content);
        self::assertStringNotContainsStringIgnoringCase('only third party', $content);
        self::assertStringNotContainsStringIgnoringCase('we do not share', $content);
        self::assertStringNotContainsStringIgnoringCase('never share', $content);

        // The honest boundary stays: manual entry, no bank connection.
        self::assertStringContainsStringIgnoringCase('no bank credentials', $content);
        self::assertStringContainsStringIgnoringCase('nothing is imported or connected', $content);
    }

    public function testSharedFooterAvoidsAbsolutePrivacyClaims(): void
    {
        $content = $this->getPricingContent();

        self::assertStringContainsString('A focused subscription cost tracker', $content);
        self::assertStringNotContainsString('A private, focused', $content);
    }

    public function testPricingDescribesRemindersAsAPlannedV1Goal(): void
    {
        $content = $this->getPricingContent();

        self::assertStringContainsStringIgnoringCase('reminder', $content);
        // Reminders are v1.0 release goals, not unscoped research.
        self::assertStringNotContainsStringIgnoringCase('research and planning', $content);
        self::assertStringContainsString('Planned for v1.0', $content);
    }

    public function testPricingExplainsManualCurrencyHandlingWithoutAutomaticConversion(): void
    {
        $content = $this->getPricingContent();

        self::assertStringContainsStringIgnoringCase('currency', $content);
        self::assertStringContainsStringIgnoringCase('converted amount', $content);
        self::assertStringContainsStringIgnoringCase('no automatic conversion', $content);
        self::assertStringNotContainsStringIgnoringCase('exchange rate', $content);
        self::assertStringNotContainsStringIgnoringCase('live rates', $content);
    }

    public function testPricingImpliesNoUnshippedIntegrationsOrAccess(): void
    {
        $content = $this->getPricingContent();

        self::assertStringContainsStringIgnoringCase('do not connect to bank', $content);
        self::assertStringNotContainsStringIgnoringCase('inbox scan', $content);
        self::assertStringNotContainsStringIgnoringCase('cancel with your provider', $content);
        self::assertStringNotContainsStringIgnoringCase('cancel your subscriptions for you', $content);
        self::assertStringNotContainsStringIgnoringCase('shared household', $content);
        self::assertStringNotContainsStringIgnoringCase('shared account', $content);
        self::assertStringNotContainsStringIgnoringCase('family account', $content);
        self::assertStringNotContainsStringIgnoringCase('download your data', $content);
        self::assertStringNotContainsStringIgnoringCase('self-service export', $content);
    }

    private function getPricingContent(): string
    {
        $client = static::createClient();
        $client->request('GET', '/pricing');

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
