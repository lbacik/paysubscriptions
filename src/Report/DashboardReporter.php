<?php

declare(strict_types=1);

namespace App\Report;

use App\Entity\Subscription;
use App\Entity\User;
use App\Service\CurrencyService;
use App\Service\RenewalCalculator;
use App\Service\SubscriptionService;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The Dashboard report module: one seam answering
 * report(User, ReportQuery): DashboardReport.
 *
 * It loads the User's Subscriptions itself (honouring the query's
 * category/sort/order), counts pending-review Subscriptions without ever
 * giving them money, and charges a Subscription in a month only when the
 * Renewal rule says so. Both the web dashboard and API v1 render the
 * returned value and add nothing of their own.
 */
final class DashboardReporter
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly RenewalCalculator $renewals,
    ) {
    }

    public function report(UserInterface $user, ReportQuery $query): DashboardReport
    {
        $mainCurrency = $user instanceof User ? $user->getMainCurrency() : null;
        $subscriptions = $this->subscriptions->get(
            $user,
            $query->sort,
            $query->order,
            $query->categoryId,
            $mainCurrency,
        );

        return $this->build($subscriptions, $query, $mainCurrency);
    }

    /**
     * The shared domain build from an already-filtered, already-ordered
     * list. report() loads that list; callers holding their own list
     * (ChartService's compatibility shims) build from it directly, so the
     * rules below exist in exactly one place either way.
     *
     * @param array<Subscription> $subscriptions
     */
    public function build(array $subscriptions, ReportQuery $query, ?string $mainCurrency): DashboardReport
    {
        $currency = CurrencyService::normalizeCode($mainCurrency);
        $summary = $this->subscriptions->getTotals($subscriptions, $mainCurrency);
        $reportable = array_values(array_filter(
            $subscriptions,
            static fn(Subscription $s) => !$s->isPendingReview($currency),
        ));

        if (ReportQuery::MODE_BAR === $query->mode) {
            return new DashboardReport(
                summary: $summary,
                mode: ReportQuery::MODE_BAR,
                basis: DashboardReport::BASIS_CHARGES,
                currency: $currency,
                labels: DashboardReport::MONTHS,
                data: [],
                datasets: array_map(
                    fn(Subscription $s): array => [
                        'label' => $s->getName(),
                        'cycle' => $s->isMonthly() ? 'monthly' : 'yearly',
                        'amount' => (float) $s->getReportingAmount($currency),
                        'data' => $this->chargesPerMonth($s, $currency),
                    ],
                    $reportable,
                ),
                empty: [] === $reportable,
                hiddenCount: 0,
                hiddenCycle: null,
            );
        }

        if (ReportQuery::MODE_MONTHLY === $query->mode) {
            if (ReportQuery::BASIS_EQUIVALENT === $query->basis) {
                return $this->doughnut(
                    $summary,
                    $currency,
                    ReportQuery::MODE_MONTHLY,
                    DashboardReport::BASIS_MONTHLY_EQUIVALENT,
                    $reportable,
                    static fn(Subscription $s): float => (float) $s->getReportingMonthlyCalculated($currency),
                );
            }

            $included = array_values(array_filter(
                $reportable,
                fn(Subscription $s): bool => $s->isMonthly()
                    || (null !== $query->month && $this->isChargedInMonth($s, $query->month)),
            ));

            return $this->doughnut(
                $summary,
                $currency,
                ReportQuery::MODE_MONTHLY,
                DashboardReport::BASIS_MONTHLY_DIRECT,
                $included,
                static fn(Subscription $s): float => (float) $s->getReportingAmount($currency),
                \count($reportable) - \count($included),
            );
        }

        if (ReportQuery::MODE_YEARLY !== $query->mode) {
            throw new \InvalidArgumentException(sprintf('Unsupported report mode "%s".', $query->mode));
        }

        if (ReportQuery::BASIS_EQUIVALENT === $query->basis) {
            return $this->doughnut(
                $summary,
                $currency,
                ReportQuery::MODE_YEARLY,
                DashboardReport::BASIS_YEARLY_EQUIVALENT,
                $reportable,
                static fn(Subscription $s): float => (float) $s->getReportingYearlyCalculated($currency),
            );
        }

        $included = array_values(array_filter(
            $reportable,
            static fn(Subscription $s): bool => $s->isYearly(),
        ));

        return $this->doughnut(
            $summary,
            $currency,
            ReportQuery::MODE_YEARLY,
            DashboardReport::BASIS_YEARLY_DIRECT,
            $included,
            static fn(Subscription $s): float => (float) $s->getReportingAmount($currency),
            \count($reportable) - \count($included),
        );
    }

    /**
     * @param array{monthly: float, yearly: float, monthlyCalculated: float, yearlyCalculated: float, count: int, currency: ?string, pendingReview: int} $summary
     * @param array<Subscription> $included
     */
    private function doughnut(
        array $summary,
        ?string $currency,
        string $mode,
        string $basis,
        array $included,
        callable $value,
        int $hiddenCount = 0,
    ): DashboardReport {
        return new DashboardReport(
            summary: $summary,
            mode: $mode,
            basis: $basis,
            currency: $currency,
            labels: array_map(static fn(Subscription $s) => $s->getName(), $included),
            data: array_map(static fn(Subscription $s): float => (float) $value($s), $included),
            datasets: [],
            empty: [] === $included,
            hiddenCount: $hiddenCount,
            hiddenCycle: $hiddenCount > 0
                ? (ReportQuery::MODE_MONTHLY === $mode ? 'yearly' : 'monthly')
                : null,
        );
    }

    /**
     * @return list<float>
     */
    private function chargesPerMonth(Subscription $subscription, ?string $currency): array
    {
        $amount = (float) $subscription->getReportingAmount($currency);
        $charges = [];

        for ($month = 1; $month <= 12; ++$month) {
            $charges[] = $this->isChargedInMonth($subscription, $month) ? $amount : 0.0;
        }

        return $charges;
    }

    /**
     * The single month-inclusion rule ("monthly, or charged in month M"): a
     * monthly Subscription is charged every month; any other cycle only in
     * its Renewal month, resolved through RenewalCalculator so a past anchor
     * rolls forward to its next occurrence instead of being read raw off the
     * next payment date.
     */
    private function isChargedInMonth(Subscription $subscription, int $month): bool
    {
        if ($subscription->isMonthly()) {
            return true;
        }

        $anchor = $subscription->getNextPayment();
        $cycle = $subscription->getBillingCycle();

        if (null === $anchor || null === $cycle) {
            return false;
        }

        $renewal = $this->renewals->nextRenewal($anchor, $cycle, new \DateTimeImmutable('today'));

        return (int) $renewal->format('n') === $month;
    }
}
