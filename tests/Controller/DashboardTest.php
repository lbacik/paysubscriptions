<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Limits;
use App\Enum\BillingCycle;
use App\Tests\DatabaseTestCase;

/**
 * The dashboard aggregates the signed-in user's subscriptions into
 * monthly/yearly equivalents, renders the limit gauge, and switches chart
 * views - always scoped to the current user.
 */
final class DashboardTest extends DatabaseTestCase
{
    private const PASSWORD = 'Fixture-Password-1';

    public function testAnonymousUserIsBouncedToLogin(): void
    {
        $this->client->request('GET', '/dashboard');

        self::assertResponseRedirects('/login');
    }

    public function testDashboardShowsNormalizedTotals(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->createSubscription($alice, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-01-15'));
        $this->createSubscription($alice, 'Amazon Prime', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-01'));
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $text = $crawler->text(null, true);

        // Monthly: 15.99 direct + 120/12 normalized = 25.99.
        self::assertStringContainsString('25.99', $text);
        // Yearly: 120 direct + 15.99*12 normalized = 311.88.
        self::assertStringContainsString('311.88', $text);
        // Both subscription names are listed.
        self::assertStringContainsString('Netflix', $text);
        self::assertStringContainsString('Amazon Prime', $text);
    }

    public function testDashboardShowsTheSubscriptionLimitGauge(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->createSubscription($alice, 'Netflix', BillingCycle::Monthly, 15.99);
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $text = $crawler->text(null, true);
        self::assertStringContainsString('/ '.Limits::DEFAULT_SUBSCRIPTIONS_LIMIT, $text);
    }

    public function testDashboardRespectsACustomLimit(): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $alice->setSubscriptionsLimit(5);
        $this->em->flush();
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/ 5', $crawler->text(null, true));
    }

    public function testEmptyDashboardRendersWithoutTotals(): void
    {
        $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('0.00', $crawler->text(null, true));
    }

    /**
     * @dataProvider chartTypes
     */
    public function testChartViewsRender(string $query): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->createSubscription($alice, 'Netflix', BillingCycle::Monthly, 15.99);
        $this->createSubscription($alice, 'Amazon Prime', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-01'));
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $this->client->request('GET', '/dashboard?'.$query);

        self::assertResponseIsSuccessful();
    }

    public static function chartTypes(): array
    {
        return [
            'bar totals' => ['chartType=bar'],
            'monthly breakdown' => ['chartType=monthly'],
            'monthly with normalized yearly' => ['chartType=monthly&withCalculated=1'],
            'yearly breakdown' => ['chartType=yearly'],
            'yearly with normalized monthly' => ['chartType=yearly&withCalculated=1'],
        ];
    }

    /**
     * @dataProvider sortingOptions
     */
    public function testSortingOptionsRender(string $query): void
    {
        $alice = $this->createUser('alice@example.com', self::PASSWORD, true);
        $this->createSubscription($alice, 'Beta Service', BillingCycle::Monthly, 20.0);
        $this->createSubscription($alice, 'Alpha Service', BillingCycle::Monthly, 10.0);
        $this->login('alice@example.com', self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/dashboard?'.$query);

        self::assertResponseIsSuccessful();
        $text = $crawler->text(null, true);
        self::assertStringContainsString('Alpha Service', $text);
        self::assertStringContainsString('Beta Service', $text);
    }

    public static function sortingOptions(): array
    {
        return [
            'by name asc' => ['sort=name&order=asc'],
            'by name desc' => ['sort=name&order=desc'],
            'by monthly desc' => ['sort=monthly&order=desc'],
            'by yearly asc' => ['sort=yearly&order=asc'],
        ];
    }
}
