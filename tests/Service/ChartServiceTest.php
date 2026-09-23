<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Subscription;
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
        $subscription1->setMonthly(15.99);
        $subscription1->setFirstPayment(new \DateTime('2024-01-01'));

        $subscription2 = new Subscription();
        $subscription2->setName('Amazon Prime');
        $subscription2->setYearly(139.00);
        $subscription2->setFirstPayment(new \DateTime('2024-05-01'));

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

    public function testBarChartAllocatesYearlyAmountToItsFirstPaymentMonthOnly(): void
    {
        $yearly = new Subscription();
        $yearly->setName('Amazon Prime');
        $yearly->setYearly(120.00);
        // May: index 4 in the zero-based monthly data array.
        $yearly->setFirstPayment(new \DateTime('2024-05-01'));

        $chart = $this->chartService->createBarChart([$yearly]);
        $data = $chart->getData()['datasets'][0]['data'];

        self::assertCount(12, $data);
        foreach ($data as $index => $value) {
            self::assertSame($index === 4 ? 120.0 : 0.0, $value, 'month index '.$index);
        }
    }

    public function testBarChartRepeatsMonthlyAmountAcrossAllMonths(): void
    {
        $monthly = new Subscription();
        $monthly->setName('Netflix');
        $monthly->setMonthly(15.99);
        $monthly->setFirstPayment(new \DateTime('2024-01-01'));

        $chart = $this->chartService->createBarChart([$monthly]);
        $data = $chart->getData()['datasets'][0]['data'];

        self::assertCount(12, $data);
        self::assertSame(array_fill(0, 12, 15.99), $data);
    }

    public function testMonthlyChartExcludesYearlySubscriptionsByDefault(): void
    {
        $chart = $this->chartService->createMonthlyChart($this->mixedSubscriptions());

        $data = $chart->getData();
        self::assertSame(['Netflix'], $data['labels']);
        self::assertSame([15.99], $data['datasets'][0]['data']);
    }

    public function testMonthlyChartWithYearlyNormalizesToMonthlyEquivalents(): void
    {
        $chart = $this->chartService->createMonthlyChart($this->mixedSubscriptions(), true);

        $data = $chart->getData();
        self::assertSame(['Netflix', 'Amazon Prime'], $data['labels']);
        self::assertSame(15.99, $data['datasets'][0]['data'][0]);
        // 139/12 normalized.
        self::assertEqualsWithDelta(11.583, $data['datasets'][0]['data'][1], 0.001);
    }

    public function testMonthlyChartMonthFilterIncludesMatchingYearlyFirstPayments(): void
    {
        // May (5): matches Amazon Prime's first payment.
        $chart = $this->chartService->createMonthlyChart($this->mixedSubscriptions(), false, 5);

        $data = $chart->getData();
        self::assertSame(['Netflix', 'Amazon Prime'], $data['labels']);

        // June (6): only the monthly subscription qualifies.
        $chart = $this->chartService->createMonthlyChart($this->mixedSubscriptions(), false, 6);

        $data = $chart->getData();
        self::assertSame(['Netflix'], $data['labels']);
    }

    public function testYearlyChartExcludesMonthlySubscriptionsByDefault(): void
    {
        $chart = $this->chartService->createYearlyChart($this->mixedSubscriptions());

        $data = $chart->getData();
        self::assertSame(['Amazon Prime'], $data['labels']);
        self::assertSame([139.0], $data['datasets'][0]['data']);
    }

    public function testYearlyChartWithMonthlyNormalizesToYearlyEquivalents(): void
    {
        $chart = $this->chartService->createYearlyChart($this->mixedSubscriptions(), true);

        $data = $chart->getData();
        self::assertSame(['Netflix', 'Amazon Prime'], $data['labels']);
        // 15.99*12 normalized.
        self::assertEqualsWithDelta(191.88, $data['datasets'][0]['data'][0], 0.001);
        self::assertSame(139.0, $data['datasets'][0]['data'][1]);
    }

    public function testChartsOfEmptyListsRenderWithoutDatasets(): void
    {
        $monthly = $this->chartService->createMonthlyChart([]);
        self::assertSame([], $monthly->getData()['labels']);

        $yearly = $this->chartService->createYearlyChart([]);
        self::assertSame([], $yearly->getData()['labels']);

        $bar = $this->chartService->createBarChart([]);
        self::assertSame([], $bar->getData()['datasets']);
    }

    /**
     * @return list<Subscription>
     */
    private function mixedSubscriptions(): array
    {
        $monthly = new Subscription();
        $monthly->setName('Netflix');
        $monthly->setMonthly(15.99);
        $monthly->setFirstPayment(new \DateTime('2024-01-01'));

        $yearly = new Subscription();
        $yearly->setName('Amazon Prime');
        $yearly->setYearly(139.00);
        $yearly->setFirstPayment(new \DateTime('2024-05-01'));

        return [$monthly, $yearly];
    }
}
