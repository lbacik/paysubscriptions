<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Input to the Dashboard report module.
 *
 * Controllers translate their own state into a query and only render the
 * resulting DashboardReport: the web dashboard folds its session-backed
 * list state (sort, order, category) in, while API v1 folds its validated
 * query parameters in (always name/asc). Validation, category ownership,
 * and problem responses stay with the controllers.
 */
final class ReportQuery
{
    public const MODE_BAR = 'bar';
    public const MODE_MONTHLY = 'monthly';
    public const MODE_YEARLY = 'yearly';

    public const BASIS_DIRECT = 'direct';
    public const BASIS_EQUIVALENT = 'equivalent';

    /**
     * @param string $mode bar|monthly|yearly
     * @param string $basis direct|equivalent (bar only reports charges)
     * @param int|null $month 1-12, only meaningful for monthly direct
     * @param string|null $categoryId UUID string, or null for all categories
     */
    public function __construct(
        public readonly string $mode = self::MODE_BAR,
        public readonly string $basis = self::BASIS_DIRECT,
        public readonly ?int $month = null,
        public readonly ?string $categoryId = null,
        public readonly string $sort = 'name',
        public readonly string $order = 'asc',
    ) {
    }
}
