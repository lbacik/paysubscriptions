<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Subscription;
use App\Enum\BillingCycle;
use App\Service\ChartService;
use PHPUnit\Framework\TestCase;
use Symfony\UX\Chartjs\Builder\ChartBuilder;

final class ChartServiceTest extends TestCase
{
    private ChartService $chartService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chartService = new ChartService(new ChartBuilder());
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
        $chartBuilder = new ChartBuilder();
        $chartService = new ChartService($chartBuilder);

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
        $chartBuilder = new ChartBuilder();
        $chartService = new ChartService($chartBuilder);

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
}
