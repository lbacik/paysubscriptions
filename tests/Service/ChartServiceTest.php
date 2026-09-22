<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Subscription;
use App\Service\ChartService;
use PHPUnit\Framework\TestCase;
use Symfony\UX\Chartjs\Builder\ChartBuilder;

final class ChartServiceTest extends TestCase
{
    public function testCreateBarChartBuildsDatasetsWithAmountAndLabels(): void
    {
        $chartBuilder = new ChartBuilder();
        $chartService = new ChartService($chartBuilder);

        $subscription1 = new Subscription();
        $subscription1->setName('Netflix');
        $subscription1->setMonthly(15.99);
        $subscription1->setFirstPayment(new \DateTime('2024-01-01'));

        $subscription2 = new Subscription();
        $subscription2->setName('Amazon Prime');
        $subscription2->setYearly(139.00);
        $subscription2->setFirstPayment(new \DateTime('2024-05-01'));

        $chart = $chartService->createBarChart([$subscription1, $subscription2]);

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
}
