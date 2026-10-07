<?php

declare(strict_types=1);

namespace App\Report;

/**
 * One Dashboard report: spending summary plus exactly one chart series, as
 * plain domain data with no Chart.js or HTTP shape.
 *
 * The summary reuses SubscriptionService::getTotals() (shared with the
 * subscriptions table). The series carries labels plus values for the
 * monthly/yearly modes, or per-Subscription per-month charges for bar mode,
 * in the requested order. Visibility describes what the chart draws so both
 * renderers label currency/basis and empty/filtered states identically.
 */
final class DashboardReport
{
    /** @var list<string> */
    public const MONTHS = [
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
        'December',
    ];

    public const BASIS_CHARGES = 'charges';
    public const BASIS_MONTHLY_DIRECT = 'monthly_direct';
    public const BASIS_MONTHLY_EQUIVALENT = 'monthly_equivalent';
    public const BASIS_YEARLY_DIRECT = 'yearly_direct';
    public const BASIS_YEARLY_EQUIVALENT = 'yearly_equivalent';

    /**
     * @param array{monthly: float, yearly: float, monthlyCalculated: float, yearlyCalculated: float, count: int, currency: ?string, pendingReview: int} $summary
     * @param list<string> $labels Month names (bar) or Subscription names (monthly/yearly)
     * @param list<float> $data Values parallel to labels (monthly/yearly); empty for bar
     * @param list<array{label: ?string, cycle: string, amount: float, data: list<float>}> $datasets Per-Subscription per-month charges (bar); empty otherwise
     */
    public function __construct(
        public readonly array $summary,
        public readonly string $mode,
        public readonly string $basis,
        public readonly ?string $currency,
        public readonly array $labels,
        public readonly array $data,
        public readonly array $datasets,
        public readonly bool $empty,
        public readonly int $hiddenCount,
        public readonly ?string $hiddenCycle,
    ) {
    }

    /**
     * @return array{basis: string, currency: ?string, empty: bool, hiddenCount: int, hiddenCycle: ?string}
     */
    public function visibility(): array
    {
        return [
            'basis' => $this->basis,
            'currency' => $this->currency,
            'empty' => $this->empty,
            'hiddenCount' => $this->hiddenCount,
            'hiddenCycle' => $this->hiddenCycle,
        ];
    }
}
