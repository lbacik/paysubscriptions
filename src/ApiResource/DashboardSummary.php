<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * Summary-card values of the dashboard snapshot report.
 *
 * Mirrors SubscriptionService::getTotals() plus the User limit: monetary
 * fields are decimal strings, pending-review cross-currency Subscriptions
 * are counted but excluded from the money, and the currency is null until a
 * main currency is confirmed.
 */
final class DashboardSummary
{
    public function __construct(
        public readonly string $monthly,
        public readonly string $yearly,
        public readonly string $monthlyEquivalent,
        public readonly string $yearlyEquivalent,
        public readonly int $count,
        public readonly int $limit,
        public readonly ?string $currency,
        public readonly int $pendingReview,
    ) {
    }
}
