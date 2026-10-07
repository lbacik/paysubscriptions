<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Subscription;
use App\Report\DashboardReporter;
use App\Report\DashboardReport;
use App\Report\ReportQuery;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * Pure renderer from DashboardReport to a Chart.js Chart.
 *
 * Every domain rule (which Subscriptions are reportable, which yearly plans
 * charge in a month, per-month charges) lives in DashboardReporter; this
 * service only owns presentation: colours (keyed by name and position, so
 * they follow the report order), options, labels, and the formatted tooltip
 * amount the bar legend reads.
 */
class ChartService
{
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
        private readonly DashboardReporter $reporter,
    ) {
    }

    public function createChart(DashboardReport $report): Chart
    {
        return ReportQuery::MODE_BAR === $report->mode
            ? $this->renderBar($report)
            : $this->renderDoughnut($report);
    }

    /**
     * Compatibility shims for the previous "subscriptions → Chart" call
     * shapes. Each builds the same DashboardReport the module path uses and
     * renders it, so the domain rules still exist in exactly one place.
     *
     * @param array<Subscription> $subscriptions
     */
    public function createBarChart(array $subscriptions, ?string $mainCurrency = null): Chart
    {
        return $this->createChart($this->reporter->build(
            $subscriptions,
            new ReportQuery(mode: ReportQuery::MODE_BAR),
            $mainCurrency,
        ));
    }

    /**
     * @param array<Subscription> $subscriptions
     */
    public function createMonthlyChart(array $subscriptions, bool $withYearly = false, int|null $month = null, ?string $mainCurrency = null): Chart
    {
        return $this->createChart($this->reporter->build(
            $subscriptions,
            new ReportQuery(
                mode: ReportQuery::MODE_MONTHLY,
                basis: $withYearly ? ReportQuery::BASIS_EQUIVALENT : ReportQuery::BASIS_DIRECT,
                month: $month,
            ),
            $mainCurrency,
        ));
    }

    /**
     * @param array<Subscription> $subscriptions
     */
    public function createYearlyChart(array $subscriptions, bool $withMonthly = false, ?string $mainCurrency = null): Chart
    {
        return $this->createChart($this->reporter->build(
            $subscriptions,
            new ReportQuery(
                mode: ReportQuery::MODE_YEARLY,
                basis: $withMonthly ? ReportQuery::BASIS_EQUIVALENT : ReportQuery::BASIS_DIRECT,
            ),
            $mainCurrency,
        ));
    }

    private function renderBar(DashboardReport $report): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $dataSets = [];

        foreach ($report->datasets as $index => $entry) {
            $amountFormatted = number_format((float) $entry['amount'], 2, '.', ' ')
                . ('monthly' === $entry['cycle'] ? ' / mo' : ' / yr');

            $dataSets[] = [
                'label' => $entry['label'],
                'data' => $entry['data'],
                'backgroundColor' => $this->getColorForSubscription((string) $entry['label'], $index),
                'borderRadius' => 4,
                'amount' => $amountFormatted,
            ];
        }

        $chart->setData(
            [
                'labels' => $report->labels,
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

    private function renderDoughnut(DashboardReport $report): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_DOUGHNUT);

        $chart->setData(
            [
                'datasets' => [
                    [
                        'data' => $report->data,
                        'backgroundColor' => array_map(
                            fn(?string $label, int $index): string => $this->getColorForSubscription((string) $label, $index),
                            $report->labels,
                            array_keys($report->labels),
                        ),
                        'borderWidth' => 2,
                        'borderColor' => '#ffffff',
                    ],
                ],
                'labels' => $report->labels,
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

    private function getColorForSubscription(string $name, int $index): string
    {
        $paletteSize = count(self::COLOR_PALETTE);
        $colorIndex = (abs(crc32($name)) + $index) % $paletteSize;

        return self::COLOR_PALETTE[$colorIndex];
    }
}
