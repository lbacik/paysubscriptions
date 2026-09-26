<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\BillingCycle;
use App\Factory\UserFactory;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\ExpenseCategoryService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Dashboard cost equivalents in the User's main currency (#41).
 *
 * Monthly/yearly totals and charts normalize billing cycles and are labelled
 * with the main currency; cross-currency Subscriptions contribute only their
 * converted amount. Aggregates stay distinct from the dated renewal
 * forecasts of the upcoming-renewals widget.
 */
final class DashboardTotalsTest extends WebTestCase
{
    use ResetDatabase;
    use Factories;

    public function testDashboardShowsMonthlyAndYearlyEquivalentsLabelledWithMainCurrency(): void
    {
        $client = static::createClient();
        $owner = $this->createUser($client, 'owner@example.com', 'USD');
        $this->createSubscription($client, $owner, 'Monthly', BillingCycle::Monthly, new \DateTime('2024-01-01'), 10.0, 'USD');
        $this->createSubscription($client, $owner, 'Yearly', BillingCycle::Yearly, new \DateTime('2024-05-01'), 120.0, 'USD');

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        // Normalized equivalents: 10 + 120/12 and 120 + 10*12.
        self::assertStringContainsString('20.00', $content);
        self::assertStringContainsString('240.00', $content);
        self::assertStringContainsString('USD', $content);
        self::assertStringContainsString('/ mo', $content);
        self::assertStringContainsString('/ yr', $content);
        // The figures must name both the currency and their equivalent nature.
        self::assertStringContainsString('Monthly equivalent in USD', $content);
        self::assertStringContainsString('Yearly equivalent in USD', $content);
    }

    public function testCrossCurrencySubscriptionContributesOnlyItsConvertedAmount(): void
    {
        $client = static::createClient();
        $owner = $this->createUser($client, 'owner@example.com', 'USD');
        $this->createSubscription($client, $owner, 'Local', BillingCycle::Monthly, new \DateTime('2024-01-01'), 10.0, 'USD');
        // Renews within days so its forecast stays among the widget's soonest occurrences.
        $this->createSubscription($client, $owner, 'Foreign', BillingCycle::Yearly, new \DateTime('today +3 days'), 100.0, 'EUR', 110.0, 'USD');

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        // 10 + 110/12 = 19.17 monthly equivalent; 120 + 110 = 230 yearly.
        self::assertStringContainsString('19.17', $content);
        self::assertStringContainsString('230.00', $content);
        self::assertStringContainsString('110.00', $content);
        // The cross-currency row reports the converted figure, flagged as such…
        self::assertStringContainsString('≈ 110.00 USD', $content);
        // …while the dated renewal forecast keeps the billed charge distinct.
        self::assertStringContainsString('100.00 EUR', $content);
    }

    public function testStaleConvertedAmountsAreExcludedAndFlaggedOnTheDashboard(): void
    {
        $client = static::createClient();
        $owner = $this->createUser($client, 'owner@example.com', 'USD');
        $this->createSubscription($client, $owner, 'Stale Service', BillingCycle::Monthly, new \DateTime('2024-01-01'), 10.0, 'EUR', 11.0, 'PLN');

        $this->loginAs($client, 'owner@example.com');
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Stale Service', $content);
        self::assertStringContainsString('need review', $content);
        self::assertStringContainsString('excluded', $content);
    }

    public function testEmptyDashboardRendersZeroTotalsAndEmptyStates(): void
    {
        $client = static::createClient();
        $this->createUser($client, 'fresh@example.com', 'USD');

        $this->loginAs($client, 'fresh@example.com');
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('0.00', $content);
        self::assertStringContainsString('Monthly equivalent in USD', $content);
        // No chart data to show: the chart card stays hidden…
        self::assertStringNotContainsString('chart-container', $content);
        // …while the renewals widget explains how to get started.
        self::assertStringContainsString('No upcoming renewals yet', $content);
        self::assertStringContainsString('/subscription/new', $content);
    }

    public function testChartViewsLabelTheirCurrencyAndBasis(): void
    {
        $client = static::createClient();
        $owner = $this->createUser($client, 'owner@example.com', 'USD');
        $this->createSubscription($client, $owner, 'Monthly', BillingCycle::Monthly, new \DateTime('2024-01-01'), 10.0, 'USD');
        $this->createSubscription($client, $owner, 'Yearly', BillingCycle::Yearly, new \DateTime('2024-05-01'), 120.0, 'USD');
        $this->loginAs($client, 'owner@example.com');

        $client->request('GET', '/dashboard');
        self::assertStringContainsString('Expected charges per month in USD', (string) $client->getResponse()->getContent());

        $client->request('GET', '/dashboard?chartType=monthly&withCalculated=1');
        self::assertStringContainsString('Monthly equivalents in USD', (string) $client->getResponse()->getContent());

        $client->request('GET', '/dashboard?chartType=monthly');
        self::assertStringContainsString('Direct monthly charges in USD', (string) $client->getResponse()->getContent());

        $client->request('GET', '/dashboard?chartType=yearly&withCalculated=1');
        self::assertStringContainsString('Yearly equivalents in USD', (string) $client->getResponse()->getContent());

        $client->request('GET', '/dashboard?chartType=yearly');
        self::assertStringContainsString('Direct yearly charges in USD', (string) $client->getResponse()->getContent());
    }

    public function testDirectMonthlyViewNotesHiddenYearlyPlans(): void
    {
        $client = static::createClient();
        $owner = $this->createUser($client, 'owner@example.com', 'USD');
        $this->createSubscription($client, $owner, 'Monthly', BillingCycle::Monthly, new \DateTime('2024-01-01'), 10.0, 'USD');
        $this->createSubscription($client, $owner, 'Yearly', BillingCycle::Yearly, new \DateTime('2024-05-01'), 120.0, 'USD');
        $this->loginAs($client, 'owner@example.com');

        $client->request('GET', '/dashboard?chartType=monthly');
        self::assertStringContainsString('1 yearly plan(s) hidden', (string) $client->getResponse()->getContent());

        // Folding yearly plans in removes the note.
        $client->request('GET', '/dashboard?chartType=monthly&withCalculated=1');
        self::assertStringNotContainsString('yearly plan(s) hidden', (string) $client->getResponse()->getContent());
    }

    public function testChartExplainsItselfWhenFiltersHideEverySubscription(): void
    {
        $client = static::createClient();
        $owner = $this->createUser($client, 'owner@example.com', 'USD');
        $this->createSubscription($client, $owner, 'Yearly only', BillingCycle::Yearly, new \DateTime('2024-05-01'), 120.0, 'USD');
        $this->loginAs($client, 'owner@example.com');

        $client->request('GET', '/dashboard?chartType=monthly');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Nothing to chart yet', $content);
        self::assertStringContainsString('Include yearly plans', $content);
    }

    public function testEditingSubscriptionAmountAndCycleUpdatesDashboardTotals(): void
    {
        $client = static::createClient();
        $owner = $this->createUser($client, 'owner@example.com', 'USD');
        $subscription = $this->createSubscription($client, $owner, 'Flexible', BillingCycle::Monthly, new \DateTime('2024-01-01'), 10.0, 'USD');
        $this->loginAs($client, 'owner@example.com');

        $client->request('GET', '/dashboard');
        self::assertStringContainsString('10.00', (string) $client->getResponse()->getContent());

        $this->updateSubscription($client, $subscription, static function (Subscription $managed): void {
            $managed->setAmount(25.0);
        });

        $client->request('GET', '/dashboard');
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('25.00', $content);
        self::assertStringContainsString('300.00', $content);

        $this->updateSubscription($client, $subscription, static function (Subscription $managed): void {
            $managed->setBillingCycle(BillingCycle::Yearly);
        });

        $client->request('GET', '/dashboard');
        $content = (string) $client->getResponse()->getContent();
        // Now a direct yearly charge: the monthly equivalent is 25/12 = 2.08.
        self::assertStringContainsString('2.08', $content);
        self::assertStringContainsString('25.00', $content);
    }

    public function testChangingMainCurrencyExcludesStaleAmountsUntilReviewed(): void
    {
        $client = static::createClient();
        $owner = $this->createUser($client, 'owner@example.com', 'USD');
        $this->createSubscription($client, $owner, 'Foreign', BillingCycle::Monthly, new \DateTime('2024-01-01'), 10.0, 'EUR', 11.0, 'USD');
        $this->loginAs($client, 'owner@example.com');

        $client->request('GET', '/dashboard');
        self::assertStringContainsString('11.00', (string) $client->getResponse()->getContent());

        $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        $form = $client->getCrawler()->selectButton('Save')->form();
        $form['profile[mainCurrency]'] = 'PLN';
        $client->submit($form);
        self::assertResponseRedirects('/dashboard');
        $client->followRedirect();

        $content = (string) $client->getResponse()->getContent();
        // The USD-stamped figure is stale for PLN: excluded until reviewed.
        self::assertStringContainsString('need review', $content);
        self::assertStringContainsString('excluded', $content);
        self::assertStringNotContainsString('11.00', $content);
    }

    private function createUser(KernelBrowser $client, string $email, ?string $mainCurrency): User
    {
        UserFactory::createOne(['email' => $email, 'isVerified' => true, 'mainCurrency' => $mainCurrency]);

        return $this->managedUser($client, $email);
    }

    private function createSubscription(
        KernelBrowser $client,
        User $owner,
        string $name,
        BillingCycle $cycle,
        \DateTimeInterface $nextPayment,
        float $amount,
        ?string $currency = null,
        ?float $convertedAmount = null,
        ?string $convertedCurrency = null,
    ): Subscription {
        $managedOwner = $this->managedUser($client, $owner);
        $categoryService = $client->getContainer()->get(ExpenseCategoryService::class);

        $subscription = (new Subscription())
            ->setName($name)
            ->setBillingCycle($cycle)
            ->setNextPayment($nextPayment)
            ->setAmount($amount)
            ->setCurrency($currency)
            ->setOwner($managedOwner)
            ->setCategory($categoryService->ensureDefaultCategory($managedOwner));

        if ($convertedAmount !== null) {
            $subscription->setConvertedAmount($convertedAmount);
        }

        if ($convertedCurrency !== null) {
            $subscription->setConvertedCurrency($convertedCurrency);
        }

        $client->getContainer()->get(SubscriptionRepository::class)->save($subscription);

        return $subscription;
    }

    private function updateSubscription(KernelBrowser $client, Subscription $subscription, callable $update): void
    {
        $repository = $client->getContainer()->get(SubscriptionRepository::class);
        $managed = $repository->find($subscription->getId());
        self::assertInstanceOf(Subscription::class, $managed);
        $update($managed);
        $repository->save($managed);
    }

    private function loginAs(KernelBrowser $client, string $email): void
    {
        $client->loginUser($this->managedUser($client, $email));
    }

    private function managedUser(KernelBrowser $client, User|string $user): User
    {
        $repository = $client->getContainer()->get(UserRepository::class);
        $managed = $user instanceof User
            ? $repository->find($user->getId())
            : $repository->findOneBy(['email' => $user]);

        self::assertInstanceOf(User::class, $managed);

        return $managed;
    }
}
