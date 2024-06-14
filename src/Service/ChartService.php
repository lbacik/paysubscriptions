<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Subscription;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

class ChartService
{
    private const MONTHS = [
        'January',
        'February',
        'March',
        'April',
        'May',
        'June',
        'July',
        'August',
        'September',
        'October',
        'November',
        'December'
    ];

    public function __construct(
        private ChartBuilderInterface $chartBuilder,
    ) {
    }

    public function createBarChart(array $subscriptions): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $dataSets = $this->createDataSets($subscriptions);

        $chart->setData(
            [
                'labels' => self::MONTHS,
                'datasets' => $dataSets,
            ]
        );

        $chart->setOptions(
            [
                'scales' => [
                    'x' => [
                        'stacked' => true,
                    ],
                    'y' => [
                        'stacked' => true,
                    ],
                ],
            ]
        );

        return $chart;
    }

    public function createMonthlyChart(array $subscriptions, bool $withYearly = false): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_DOUGHNUT);

        $withYearly ?
            $data = array_map(
                fn(Subscription $subscription) => [
                    'm' => $subscription->getMonthly() ?? ((float)$subscription->getYearly() / 12),
                    'l' => $subscription->getName()
                ],
                $subscriptions
            )
            : $data = array_map(
            fn(Subscription $subscription) => ['m' => $subscription->getMonthly(), 'l' => $subscription->getName()],
            array_filter($subscriptions, fn(Subscription $subscription) => $subscription->getMonthly() !== null)
        );

        $data = array_values($data);

        $chart->setData(
            [
                'datasets' => [
                    [
                        'data' => array_map(
                            fn(array $item) => $item['m'],
                            $data,
                        ),
                        'backgroundColor' => array_map(
                            fn() => $this->randomColor(),
                            $data,
                        ),
                    ]
                ],
                'labels' => array_map(
                    fn(array $item) => $item['l'],
                    $data,
                ),
            ]
        );

        return $chart;
    }

    public function createYearlyChart(array $subscriptions, bool $withMonthly = false): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_DOUGHNUT);

        $withMonthly ?
            $data = array_map(
                fn(Subscription $subscription) => [
                    'm' => $subscription->getYearly() ?? ((float)$subscription->getMonthly() * 12),
                    'l' => $subscription->getName()
                ],
                $subscriptions
            )
            : $data = array_map(
            fn(Subscription $subscription) => ['m' => $subscription->getYearly(), 'l' => $subscription->getName()],
            array_filter($subscriptions, fn(Subscription $subscription) => $subscription->getYearly() !== null)
        );

        $data = array_values($data);

        $chart->setData(
            [
                'datasets' => [
                    [
                        'data' => array_map(
                            fn(array $item) => $item['m'],
                            $data,
                        ),
                        'backgroundColor' => array_map(
                            fn() => $this->randomColor(),
                            $data,
                        ),
                    ]
                ],
                'labels' => array_map(
                    fn(array $item) => $item['l'],
                    $data,
                ),
            ]
        );

        return $chart;
    }

    private function createDataSets(array $subscriptions): array
    {
        $dataSets = [];
        foreach ($subscriptions as $subscription) {
            $dataSets[] = [
                'label' => $subscription->getName(),
                'data' => $this->createData($subscription),
                'backgroundColor' => $this->randomColor(),
            ];
        }

        return $dataSets;
    }

    private function createData(Subscription $subscription): array
    {
        $data = [];
        foreach (array_keys(self::MONTHS) as $month) {
            $data[] = $this->countByMonth([$subscription], $month);
        }

        return $data;
    }

    private function randomColor(): string
    {
        return 'rgb(' . random_int(0, 255) . ', ' . random_int(0, 255) . ', ' . random_int(0, 255) . ')';
    }

    private function countByMonth(array $subscriptions, int $month): float
    {
        $total = 0.0;

        foreach ($subscriptions as $subscription) {
            if ($subscription->getMonthly() !== null) {
                $total += $subscription->getMonthly();
            } elseif ((int)$subscription->getFirstPayment()->format('n') === ($month + 1)) {
                $total += $subscription->getYearly();
            }
        }

        return $total;
    }
}
