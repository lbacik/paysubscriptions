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

    private const COLOR_PALETTE = [
        '#3b82f6', // blue-500
        '#6366f1', // indigo-500
        '#8b5cf6', // violet-500
        '#ec4899', // pink-500
        '#06b6d4', // cyan-500
        '#10b981', // emerald-500
        '#f59e0b', // amber-500
        '#f43f5e', // rose-500
        '#0ea5e9', // sky-500
        '#14b8a6', // teal-500
        '#a855f7', // purple-500
        '#f97316', // orange-500
        '#84cc16', // lime-500
        '#577399', // color-ter
        '#fe5f55', // color-qui
        '#0284c7', // sky-600
        '#4f46e5', // indigo-600
        '#059669', // emerald-600
        '#d946ef', // fuchsia-500
        '#64748b', // slate-500
    ];

    public function __construct(
        private readonly ChartBuilderInterface $chartBuilder,
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
                'responsive' => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => [
                        'display' => false,
                    ],
                    'tooltip' => [
                        'padding' => 12,
                        'boxPadding' => 6,
                        'cornerRadius' => 8,
                    ],
                ],
                'scales' => [
                    'x' => [
                        'stacked' => true,
                        'grid' => [
                            'display' => false,
                        ],
                        'border' => [
                            'display' => false,
                        ],
                        'ticks' => [
                            'font' => [
                                'family' => "'Plus Jakarta Sans', system-ui, sans-serif",
                                'size' => 12,
                                'weight' => '500',
                            ],
                            'color' => '#64748b',
                        ],
                    ],
                    'y' => [
                        'stacked' => true,
                        'grid' => [
                            'color' => '#f1f5f9',
                        ],
                        'border' => [
                            'display' => false,
                        ],
                        'ticks' => [
                            'font' => [
                                'family' => "'Plus Jakarta Sans', system-ui, sans-serif",
                                'size' => 12,
                            ],
                            'color' => '#64748b',
                        ],
                    ],
                ],
            ]
        );

        return $chart;
    }

    public function createMonthlyChart(array $subscriptions, bool $withYearly = false, int|null $month = null): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_DOUGHNUT);

        $withYearly ?
            $data = array_map(
                fn(Subscription $subscription) => [
                    'm' => $subscription->getMonthlyCalculated(),
                    'l' => $subscription->getName()
                ],
                $subscriptions
            )
            : $data = array_map(
                fn(Subscription $subscription) => [
                    'm' => $subscription->getAmount(),
                    'l' => $subscription->getName()
                ],
                array_filter(
                    $subscriptions,
                    fn(Subscription $subscription) => $month === null
                        ? $subscription->isMonthly()
                        : $subscription->isMonthly() || $subscription->getNextPayment()->format('n') === (string) $month
                )
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
                            fn(array $item, int $index) => $this->getColorForSubscription($item['l'], $index),
                            $data,
                            array_keys($data)
                        ),
                        'borderWidth' => 2,
                        'borderColor' => '#ffffff',
                    ]
                ],
                'labels' => array_map(
                    fn(array $item) => $item['l'],
                    $data,
                ),
            ]
        );

        $chart->setOptions(
            [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'cutout' => '68%',
                'plugins' => [
                    'legend' => [
                        'position' => 'bottom',
                        'labels' => [
                            'boxWidth' => 12,
                            'boxHeight' => 12,
                            'padding' => 14,
                            'font' => [
                                'family' => "'Plus Jakarta Sans', system-ui, sans-serif",
                                'size' => 12,
                            ],
                            'color' => '#475569',
                        ],
                    ],
                    'tooltip' => [
                        'padding' => 12,
                        'boxPadding' => 6,
                        'cornerRadius' => 8,
                    ],
                ],
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
                    'm' => $subscription->getYearlyCalculated(),
                    'l' => $subscription->getName()
                ],
                $subscriptions
            )
            : $data = array_map(
            fn(Subscription $subscription) => ['m' => $subscription->getAmount(), 'l' => $subscription->getName()],
            array_filter($subscriptions, fn(Subscription $subscription) => $subscription->isYearly())
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
                            fn(array $item, int $index) => $this->getColorForSubscription($item['l'], $index),
                            $data,
                            array_keys($data)
                        ),
                        'borderWidth' => 2,
                        'borderColor' => '#ffffff',
                    ]
                ],
                'labels' => array_map(
                    fn(array $item) => $item['l'],
                    $data,
                ),
            ]
        );

        $chart->setOptions(
            [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'cutout' => '68%',
                'plugins' => [
                    'legend' => [
                        'position' => 'bottom',
                        'labels' => [
                            'boxWidth' => 12,
                            'boxHeight' => 12,
                            'padding' => 14,
                            'font' => [
                                'family' => "'Plus Jakarta Sans', system-ui, sans-serif",
                                'size' => 12,
                            ],
                            'color' => '#475569',
                        ],
                    ],
                    'tooltip' => [
                        'padding' => 12,
                        'boxPadding' => 6,
                        'cornerRadius' => 8,
                    ],
                ],
            ]
        );

        return $chart;
    }

    private function createDataSets(array $subscriptions): array
    {
        $dataSets = [];
        foreach ($subscriptions as $index => $subscription) {
            $amountFormatted = $subscription->isMonthly()
                ? number_format((float)$subscription->getAmount(), 2, '.', ' ') . ' / mo'
                : number_format((float)$subscription->getAmount(), 2, '.', ' ') . ' / yr';

            $dataSets[] = [
                'label' => $subscription->getName(),
                'data' => $this->createData($subscription),
                'backgroundColor' => $this->getColorForSubscription($subscription->getName(), $index),
                'borderRadius' => 4,
                'amount' => $amountFormatted,
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

    private function getColorForSubscription(string $name, int $index): string
    {
        $paletteSize = count(self::COLOR_PALETTE);
        $colorIndex = (abs(crc32($name)) + $index) % $paletteSize;

        return self::COLOR_PALETTE[$colorIndex];
    }

    private function countByMonth(array $subscriptions, int $month): float
    {
        $total = 0.0;

        foreach ($subscriptions as $subscription) {
            if ($subscription->isMonthly()) {
                $total += $subscription->getAmount();
            } elseif ((int)$subscription->getNextPayment()->format('n') === ($month + 1)) {
                $total += $subscription->getAmount();
            }
        }

        return $total;
    }
}
