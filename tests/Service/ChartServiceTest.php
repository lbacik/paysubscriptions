<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Subscription;
use App\Enum\BillingCycle;
use App\Service\ChartService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ChartService as a pure DashboardReport renderer (candidate 03).
 *
 * The array call shapes below are compatibility shims over the Dashboard
 * report module; they build the same report the controllers render, so the
 * worked chart examples here still pin the rendered output.
 */
final class ChartServiceTest extends KernelTestCase
{
    private ChartService $chartService;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->chartService = static::getContainer()->get(ChartService::class);
    }

    public function testCreateBarChartBuildsDatasetsWithAmountAndLabels(): void
    {
        $subscription1 = new Subscription();
        $subscription1->setName('Netflix');
        $subscription1->setBillingCycle(BillingCycle::Monthly);
        $subscription1->setAmount(15.99);
        $subscription1->setNextPayment(new \DateTime('2024-01-01'));

        $subscription2 = new Subscription();
        $subscription2->setName('Amazon Prime');
        $subscription2->setBillingCycle(BillingCycle::Yearly);
        $subscription2->setAmount(139.00);
        $subscription2->setNextPayment(new \DateTime('2024-05-01'));

        $chart = $this->chartService->createBarChart([$subscription1, $subscription2]);

        $data = $chart->getData();
        self::assertCount(2, $data['datasets']);

        self::assertSame('Netflix', $data['datasets'][0]['label']);
        self::assertSame('15.99 / mo', $data['datasets'][0]['amount']);
        self::assertArrayHasKey('backgroundColor', $data['datasets'][0]);

        self::assertSame('Amazon Prime', $data['datasets'][1]['label']);
        self::assertSame('139.00 / yr', $data['datasets'][1]['amount']);
        self::assertArrayHasKey('backgroundColor', $data['datasets'][1]);

        $options = $chart->getOptions();
        self::assertFalse($options['plugins']['legend']['display']);
    }

    public function testBarChartUsesConvertedAmountsInMainCurrency(): void
    {
        $chartService = $this->chartService;

        $subscription = new Subscription();
        $subscription->setName('Foreign Service');
        $subscription->setBillingCycle(BillingCycle::Monthly);
        $subscription->setAmount(10.00);
        $subscription->setCurrency('EUR');
        $subscription->setNextPayment(new \DateTime('2024-01-01'));
        $subscription->setConvertedAmount(11.00);
        $subscription->setConvertedCurrency('USD');

        $chart = $chartService->createBarChart([$subscription], 'USD');

        $data = $chart->getData();
        self::assertSame('11.00 / mo', $data['datasets'][0]['amount']);
        self::assertEqualsWithDelta(11.00, $data['datasets'][0]['data'][0], 0.001);
    }

    public function testChartsExcludeStaleConvertedAmountsPendingReview(): void
    {
        $chartService = $this->chartService;

        $stale = new Subscription();
        $stale->setName('Stale Service');
        $stale->setBillingCycle(BillingCycle::Monthly);
        $stale->setAmount(20.00);
        $stale->setCurrency('EUR');
        $stale->setNextPayment(new \DateTime('2024-01-01'));
        $stale->setConvertedAmount(22.00);
        $stale->setConvertedCurrency('PLN');

        $chart = $chartService->createBarChart([$stale], 'USD');

        self::assertCount(0, $chart->getData()['datasets']);
    }

    public function testMonthlyChartWithCalculatedNormalizesYearlyPlans(): void
    {
        $chartService = $this->chartService;

        $chart = $chartService->createMonthlyChart(
            [$this->subscription('Monthly', BillingCycle::Monthly, 12.00), $this->subscription('Yearly', BillingCycle::Yearly, 120.00)],
            true,
        );

        $data = $chart->getData();
        self::assertSame(['Monthly', 'Yearly'], $data['labels']);
        // Yearly 120/12 = 10.00 monthly equivalent.
        self::assertEqualsWithDelta(12.00, $data['datasets'][0]['data'][0], 0.001);
        self::assertEqualsWithDelta(10.00, $data['datasets'][0]['data'][1], 0.001);
    }

    public function testMonthlyChartWithoutCalculatedShowsOnlyMonthlyPlans(): void
    {
        $chartService = $this->chartService;

        $chart = $chartService->createMonthlyChart(
            [$this->subscription('Monthly', BillingCycle::Monthly, 12.00), $this->subscription('Yearly', BillingCycle::Yearly, 120.00)],
            false,
        );

        $data = $chart->getData();
        self::assertSame(['Monthly'], $data['labels']);
        self::assertEqualsWithDelta(12.00, $data['datasets'][0]['data'][0], 0.001);
    }

    public function testYearlyChartWithCalculatedNormalizesMonthlyPlans(): void
    {
        $chartService = $this->chartService;

        $chart = $chartService->createYearlyChart(
            [$this->subscription('Monthly', BillingCycle::Monthly, 10.00), $this->subscription('Yearly', BillingCycle::Yearly, 120.00)],
            true,
        );

        $data = $chart->getData();
        self::assertSame(['Monthly', 'Yearly'], $data['labels']);
        // Monthly 10*12 = 120.00 yearly equivalent.
        self::assertEqualsWithDelta(120.00, $data['datasets'][0]['data'][0], 0.001);
        self::assertEqualsWithDelta(120.00, $data['datasets'][0]['data'][1], 0.001);
    }

    public function testYearlyChartWithoutCalculatedShowsOnlyYearlyPlans(): void
    {
        $chartService = $this->chartService;

        $chart = $chartService->createYearlyChart(
            [$this->subscription('Monthly', BillingCycle::Monthly, 10.00), $this->subscription('Yearly', BillingCycle::Yearly, 120.00)],
            false,
        );

        $data = $chart->getData();
        self::assertSame(['Yearly'], $data['labels']);
        self::assertEqualsWithDelta(120.00, $data['datasets'][0]['data'][0], 0.001);
    }

    public function testMonthlyChartMonthFilterKeepsYearlyPlansInThatMonthOnly(): void
    {
        $chartService = $this->chartService;
        $mayYearly = $this->subscription('May Yearly', BillingCycle::Yearly, 120.00);
        $mayYearly->setNextPayment(new \DateTime('2024-05-15'));

        $subscriptions = [$this->subscription('Monthly', BillingCycle::Monthly, 10.00), $mayYearly];

        $may = $chartService->createMonthlyChart($subscriptions, false, 5);
        self::assertSame(['Monthly', 'May Yearly'], $may->getData()['labels']);

        $june = $chartService->createMonthlyChart($subscriptions, false, 6);
        self::assertSame(['Monthly'], $june->getData()['labels']);
    }

    public function testEquivalentChartsUseConvertedAmountsInMainCurrency(): void
    {
        $chartService = $this->chartService;
        $cross = $this->subscription('Cross', BillingCycle::Yearly, 120.00);
        $cross->setCurrency('EUR');
        $cross->setConvertedAmount(132.00);
        $cross->setConvertedCurrency('USD');

        $monthly = $chartService->createMonthlyChart([$cross], true, null, 'USD');
        // 132/12 = 11.00 from the converted amount, never the raw EUR 120.
        self::assertEqualsWithDelta(11.00, $monthly->getData()['datasets'][0]['data'][0], 0.001);

        $yearly = $chartService->createYearlyChart([$cross], true, 'USD');
        self::assertEqualsWithDelta(132.00, $yearly->getData()['datasets'][0]['data'][0], 0.001);
    }

    public function testEquivalentChartsRoundYearlyNormalizationToCents(): void
    {
        $chartService = $this->chartService;

        $chart = $chartService->createMonthlyChart(
            [$this->subscription('Yearly', BillingCycle::Yearly, 100.00)],
            true,
        );

        // 100/12 = 8.333… → 8.33.
        self::assertEqualsWithDelta(8.33, $chart->getData()['datasets'][0]['data'][0], 0.001);
    }

    public function testEmptySubscriptionsProduceEmptyChartsWithoutErrors(): void
    {
        $chartService = $this->chartService;

        $bar = $chartService->createBarChart([]);
        self::assertSame([], $bar->getData()['datasets']);

        $monthly = $chartService->createMonthlyChart([], true);
        self::assertSame([], $monthly->getData()['datasets'][0]['data']);
        self::assertSame([], $monthly->getData()['labels']);

        $yearly = $chartService->createYearlyChart([], true);
        self::assertSame([], $yearly->getData()['datasets'][0]['data']);
        self::assertSame([], $yearly->getData()['labels']);
    }

    private function subscription(string $name, BillingCycle $cycle, float $amount): Subscription
    {
        $subscription = new Subscription();
        $subscription->setName($name);
        $subscription->setBillingCycle($cycle);
        $subscription->setAmount($amount);
        $subscription->setNextPayment(new \DateTime('2024-01-01'));

        return $subscription;
    }
}
