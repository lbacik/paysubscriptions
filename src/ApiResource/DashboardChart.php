<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * Numeric chart data plus visibility metadata of the dashboard snapshot report.
 *
 * The bar mode carries per-month labels and one dataset per reportable
 * Subscription; the monthly/yearly modes carry labels parallel to values.
 * The basis is the domain snake_case value (charges, monthly_direct,
 * monthly_equivalent, yearly_direct, yearly_equivalent): bar always reports
 * per-month charges. No Chart.js presentation (colors, radii, legend/tooltip
 * settings) ever crosses into API v1.
 */
final class DashboardChart
{
    /**
     * @param list<string>            $labels   month names (bar) or Subscription names (monthly/yearly)
     * @param list<string>|null       $data     decimal strings parallel to labels (monthly/yearly); null for bar
     * @param list<DashboardDataset>|null $datasets per-Subscription series (bar); null for monthly/yearly
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $basis,
        public readonly ?string $currency,
        public readonly array $labels,
        public readonly ?array $data,
        public readonly ?array $datasets,
        public readonly bool $empty,
        public readonly int $hiddenCount,
        public readonly ?string $hiddenCycle,
    ) {
    }
}
