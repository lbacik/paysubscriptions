<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\State\DashboardReportProvider;

/**
 * Dashboard snapshot report for API v1: the current User's summary-card
 * values and the numeric data behind one dashboard chart mode in a single
 * response (issue #99).
 *
 * A DTO resource with a custom provider over DashboardReporter: there is no
 * entity behind it (ADR 0007). Calculations reuse the Dashboard report
 * module, so figures match the dashboard exactly: the summary comes from
 * SubscriptionService::getTotals() through DashboardReporter, and the chart
 * series and visibility metadata are the same DashboardReport the web
 * dashboard renders.
 *
 * The request is stateless: the optional category filter and the month-of-year
 * selector travel as explicit query parameters rather than web-session list
 * state. No year, date-range, payment-history, or forecast semantics are
 * offered. Pending-review cross-currency Subscriptions stay in the count but
 * are excluded from monetary totals and charts, exactly as on the dashboard.
 *
 * An unknown or foreign category is not an error: the User-scoped query
 * matches nothing, so the report renders zero totals and an empty chart.
 */
#[ApiResource(
    operations: [
        new Get(
            name: 'api_v1_reports_dashboard',
            uriTemplate: '/reports/dashboard',
            provider: DashboardReportProvider::class,
            description: 'Current dashboard snapshot: summary cards plus one chart mode. Stateless snapshot of the authenticated User\'s Subscriptions reusing the web dashboard calculations. Summary mirrors SubscriptionService::getTotals() plus the User limit; chart series mirror ChartService numeric data with basis/visibility metadata instead of Chart.js options. Pending-review cross-currency Subscriptions stay in counts but are excluded from monetary totals and charts. The month selector applies only to the monthly direct view (monthly Subscriptions plus yearly ones whose next-payment month matches). No year, date-range, payment-history, or forecast semantics.',
        ),
    ],
)]
#[QueryParameter(
    key: 'chartMode',
    description: 'Chart view: twelve-month charge distribution (bar), monthly doughnut (monthly), or yearly doughnut (yearly). Defaults to bar.',
    schema: ['type' => 'string', 'enum' => ['bar', 'monthly', 'yearly'], 'default' => 'bar'],
)]
#[QueryParameter(
    key: 'basis',
    description: 'Direct-cycle charges (direct) or normalized equivalents (equivalent). The bar view supports direct only; monthly equivalent uses per-Subscription monthly calculations over all reportable Subscriptions; yearly equivalent uses yearly calculations. Defaults to direct.',
    schema: ['type' => 'string', 'enum' => ['direct', 'equivalent'], 'default' => 'direct'],
)]
#[QueryParameter(
    key: 'category',
    description: 'Optional User-owned ExpenseCategory UUID filter; omit for all categories. An unknown or foreign UUID matches nothing and renders an empty report.',
    schema: ['type' => 'string', 'format' => 'uuid'],
)]
#[QueryParameter(
    key: 'month',
    description: 'Month of the year (1-12) used only by the monthly direct view. Any other use, or a value outside 1-12, is a validation error.',
    schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
)]
final class DashboardReport
{
    public function __construct(
        public readonly DashboardSummary $summary,
        public readonly DashboardChart $chart,
        public readonly DashboardFilters $filters,
    ) {
    }
}
