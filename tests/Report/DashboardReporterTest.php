<?php

declare(strict_types=1);

namespace App\Tests\Report;

use App\Enum\BillingCycle;
use App\Report\DashboardReporter;
use App\Report\ReportQuery;
use App\Service\ExpenseCategoryService;
use App\Tests\DatabaseTestCase;

/**
 * The Dashboard report module (candidate 03): one seam answering
 * report(User, ReportQuery): DashboardReport, tested directly with no HTTP.
 *
 * Seams under test (pre-agreed in the ticket brief): the reporter is the
 * only new seam; the web dashboard and API v1 render its DashboardReport
 * and keep their existing HTTP coverage unchanged.
 *
 * Glossary: Dashboard report, Pending review, Renewal (CONTEXT.md).
 */
final class DashboardReporterTest extends DatabaseTestCase
{
    public function testBarReportChargesMonthlyEveryMonthAndYearlyInItsRenewalMonth(): void
    {
        $user = $this->createUser('reporter-bar@example.com');
        $user->setMainCurrency('USD');
        $this->em->flush();
        $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-01-15'));
        $this->createSubscription($user, 'Amazon Prime', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-01'));

        $owner = parent::freshUser('reporter-bar@example.com');
        self::assertInstanceOf(\App\Entity\User::class, $owner);

        $report = $this->reporter()->report(
            $owner,
            new ReportQuery(mode: 'bar', basis: 'direct'),
        );

        self::assertSame('bar', $report->mode);
        self::assertSame('charges', $report->basis);
        self::assertSame('USD', $report->currency);
        self::assertFalse($report->empty);
        self::assertSame(0, $report->hiddenCount);
        self::assertNull($report->hiddenCycle);

        // Summary reuses SubscriptionService::getTotals().
        self::assertEqualsWithDelta(15.99, $report->summary['monthly'], 0.001);
        self::assertEqualsWithDelta(120.0, $report->summary['yearly'], 0.001);
        self::assertEqualsWithDelta(25.99, $report->summary['monthlyCalculated'], 0.001);
        self::assertEqualsWithDelta(311.88, $report->summary['yearlyCalculated'], 0.001);
        self::assertSame(2, $report->summary['count']);
        self::assertSame(0, $report->summary['pendingReview']);

        // Bar labels are the calendar months; datasets follow the name sort.
        self::assertSame(
            ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            $report->labels,
        );
        self::assertSame([], $report->data);
        self::assertSame(['Amazon Prime', 'Netflix'], array_column($report->datasets, 'label'));

        // The yearly plan charges only in May (index 4); monthly charges every month.
        $expectedYearly = array_fill(0, 12, 0.0);
        $expectedYearly[4] = 120.0;
        self::assertSame($expectedYearly, $report->datasets[0]['data']);
        foreach ($report->datasets[1]['data'] as $charge) {
            self::assertEqualsWithDelta(15.99, $charge, 0.001);
        }
    }

    public function testMonthlyDirectWithoutMonthShowsOnlyMonthlyAndHidesYearly(): void
    {
        $user = $this->createUser('reporter-monthly@example.com');
        $user->setMainCurrency('USD');
        $this->em->flush();
        $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 12.0, new \DateTime('2024-01-15'));
        $this->createSubscription($user, 'Amazon Prime', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-01'));

        $owner = parent::freshUser('reporter-monthly@example.com');
        self::assertInstanceOf(\App\Entity\User::class, $owner);
        $report = $this->reporter()->report($owner, new ReportQuery(mode: 'monthly', basis: 'direct'));

        self::assertSame('monthly_direct', $report->basis);
        self::assertSame(['Netflix'], $report->labels);
        self::assertEqualsWithDelta([12.0], $report->data, 0.001);
        self::assertFalse($report->empty);
        self::assertSame(1, $report->hiddenCount);
        self::assertSame('yearly', $report->hiddenCycle);
        // The summary still covers the whole filtered set.
        self::assertSame(2, $report->summary['count']);
    }

    public function testMonthlyDirectWithMonthIncludesYearlyChargedThatMonth(): void
    {
        $user = $this->createUser('reporter-month@example.com');
        $user->setMainCurrency('USD');
        $this->em->flush();
        $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 10.0, new \DateTime('2024-01-15'));
        $this->createSubscription($user, 'May Yearly', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-15'));

        $owner = parent::freshUser('reporter-month@example.com');
        self::assertInstanceOf(\App\Entity\User::class, $owner);

        $may = $this->reporter()->report($owner, new ReportQuery(mode: 'monthly', basis: 'direct', month: 5));
        self::assertSame(['May Yearly', 'Netflix'], $this->sorted($may->labels));
        self::assertSame(0, $may->hiddenCount);
        self::assertNull($may->hiddenCycle);
        self::assertFalse($may->empty);

        $june = $this->reporter()->report($owner, new ReportQuery(mode: 'monthly', basis: 'direct', month: 6));
        self::assertSame(['Netflix'], $june->labels);
        self::assertSame(1, $june->hiddenCount);
        self::assertSame('yearly', $june->hiddenCycle);

        // The summary never follows the month slice.
        self::assertSame($may->summary['count'], $june->summary['count']);
    }

    public function testYearlyChargedInMonthFollowsRenewalsForPastAnchors(): void
    {
        $user = $this->createUser('reporter-past@example.com');
        $user->setMainCurrency('USD');
        $this->em->flush();
        // Anchor years in the past: the Renewal rule rolls forward to the
        // next occurrence, still in May, instead of reading the raw date.
        $this->createSubscription($user, 'Old Yearly', BillingCycle::Yearly, 120.0, new \DateTime('2020-05-15'));

        $owner = parent::freshUser('reporter-past@example.com');
        self::assertInstanceOf(\App\Entity\User::class, $owner);

        $may = $this->reporter()->report($owner, new ReportQuery(mode: 'monthly', basis: 'direct', month: 5));
        self::assertSame(['Old Yearly'], $may->labels);
        self::assertFalse($may->empty);

        $bar = $this->reporter()->report($owner, new ReportQuery(mode: 'bar', basis: 'direct'));
        $expected = array_fill(0, 12, 0.0);
        $expected[4] = 120.0;
        self::assertSame($expected, $bar->datasets[0]['data']);
    }

    /**
     * @dataProvider equivalentModes
     *
     * @param list<float> $expectedData
     */
    public function testEquivalentModesNormalizeEveryReportableSubscription(
        string $mode,
        string $expectedBasis,
        array $expectedData,
    ): void {
        $user = $this->createUser('reporter-equiv-'.$mode.'@example.com');
        $user->setMainCurrency('USD');
        $this->em->flush();
        $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 12.0, new \DateTime('2024-01-15'));
        $this->createSubscription($user, 'Amazon Prime', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-01'));

        $owner = parent::freshUser('reporter-equiv-'.$mode.'@example.com');
        self::assertInstanceOf(\App\Entity\User::class, $owner);
        $report = $this->reporter()->report($owner, new ReportQuery(mode: $mode, basis: 'equivalent'));

        self::assertSame($expectedBasis, $report->basis);
        // No cycle is ever hidden from an equivalent view.
        self::assertSame(['Amazon Prime', 'Netflix'], $report->labels);
        self::assertSame(0, $report->hiddenCount);
        self::assertNull($report->hiddenCycle);
        self::assertFalse($report->empty);
        self::assertEqualsWithDelta($expectedData, $report->data, 0.001);
    }

    public static function equivalentModes(): array
    {
        return [
            // Amazon Prime first (name sort): 120/12 monthly, 120 yearly; Netflix: 12 monthly, 12*12 yearly.
            'monthly equivalent' => ['monthly', 'monthly_equivalent', [10.0, 12.0]],
            'yearly equivalent' => ['yearly', 'yearly_equivalent', [120.0, 144.0]],
        ];
    }

    public function testYearlyDirectShowsOnlyYearlyAndHidesMonthly(): void
    {
        $user = $this->createUser('reporter-yearly@example.com');
        $user->setMainCurrency('USD');
        $this->em->flush();
        $this->createSubscription($user, 'Netflix', BillingCycle::Monthly, 10.0, new \DateTime('2024-01-15'));
        $this->createSubscription($user, 'Amazon Prime', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-01'));

        $owner = parent::freshUser('reporter-yearly@example.com');
        self::assertInstanceOf(\App\Entity\User::class, $owner);
        $report = $this->reporter()->report($owner, new ReportQuery(mode: 'yearly', basis: 'direct'));

        self::assertSame('yearly_direct', $report->basis);
        self::assertSame(['Amazon Prime'], $report->labels);
        self::assertEqualsWithDelta([120.0], $report->data, 0.001);
        self::assertFalse($report->empty);
        self::assertSame(1, $report->hiddenCount);
        self::assertSame('monthly', $report->hiddenCycle);
    }

    public function testCategoryFilterNarrowsSummaryAndSeriesToOwnedCategory(): void
    {
        $user = $this->createUser('reporter-category@example.com');
        $user->setMainCurrency('USD');
        $this->em->flush();
        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $food = $categories->create($user, 'Food', '#ff0000');
        $fun = $categories->create($user, 'Fun', '#00ff00');

        $monthly = $this->createSubscription($user, 'Groceries', BillingCycle::Monthly, 50.0, new \DateTime('2024-01-15'));
        $monthly->setCategory($food);
        $yearly = $this->createSubscription($user, 'Cinema', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-01'));
        $yearly->setCategory($fun);
        $this->em->flush();

        $owner = parent::freshUser('reporter-category@example.com');
        self::assertInstanceOf(\App\Entity\User::class, $owner);
        $report = $this->reporter()->report(
            $owner,
            new ReportQuery(mode: 'bar', basis: 'direct', categoryId: (string) $food->getId()),
        );

        self::assertSame(1, $report->summary['count']);
        self::assertEqualsWithDelta(50.0, $report->summary['monthly'], 0.001);
        self::assertEqualsWithDelta(0.0, $report->summary['yearly'], 0.001);
        self::assertSame(['Groceries'], array_column($report->datasets, 'label'));

        $monthlyDirect = $this->reporter()->report(
            $owner,
            new ReportQuery(mode: 'monthly', basis: 'direct', categoryId: (string) $food->getId()),
        );
        self::assertSame(['Groceries'], $monthlyDirect->labels);
        self::assertSame(0, $monthlyDirect->hiddenCount);
    }

    public function testSeriesFollowTheRequestedSortOrder(): void
    {
        $user = $this->createUser('reporter-sort@example.com');
        $user->setMainCurrency('USD');
        $this->em->flush();
        $this->createSubscription($user, 'B Second', BillingCycle::Monthly, 10.0, new \DateTime('2024-01-15'));
        $this->createSubscription($user, 'A First', BillingCycle::Monthly, 20.0, new \DateTime('2024-01-15'));

        $owner = parent::freshUser('reporter-sort@example.com');
        self::assertInstanceOf(\App\Entity\User::class, $owner);

        $ascending = $this->reporter()->report($owner, new ReportQuery(mode: 'bar', basis: 'direct'));
        self::assertSame(['A First', 'B Second'], array_column($ascending->datasets, 'label'));

        $descending = $this->reporter()->report(
            $owner,
            new ReportQuery(mode: 'bar', basis: 'direct', sort: 'name', order: 'desc'),
        );
        self::assertSame(['B Second', 'A First'], array_column($descending->datasets, 'label'));

        $doughnut = $this->reporter()->report(
            $owner,
            new ReportQuery(mode: 'monthly', basis: 'equivalent', sort: 'name', order: 'desc'),
        );
        self::assertSame(['B Second', 'A First'], $doughnut->labels);
        self::assertEqualsWithDelta([10.0, 20.0], $doughnut->data, 0.001);
    }

    public function testPendingReviewCountsWithoutContributingMoney(): void
    {
        $user = $this->createUser('reporter-review@example.com');
        $user->setMainCurrency('USD');
        $this->em->flush();

        $fresh = $this->createSubscription($user, 'Local', BillingCycle::Monthly, 10.0, new \DateTime('2024-01-15'));
        $fresh->setCurrency('USD');
        $stale = $this->createSubscription($user, 'Stale', BillingCycle::Monthly, 20.0, new \DateTime('2024-01-15'));
        $stale->setCurrency('EUR');
        $stale->setConvertedAmount(22.0);
        $stale->setConvertedCurrency('PLN');
        $this->em->flush();

        $owner = parent::freshUser('reporter-review@example.com');
        self::assertInstanceOf(\App\Entity\User::class, $owner);

        $bar = $this->reporter()->report($owner, new ReportQuery(mode: 'bar', basis: 'direct'));
        self::assertSame(2, $bar->summary['count']);
        self::assertSame(1, $bar->summary['pendingReview']);
        self::assertEqualsWithDelta(10.0, $bar->summary['monthly'], 0.001);
        self::assertEqualsWithDelta(10.0, $bar->summary['monthlyCalculated'], 0.001);
        self::assertSame(['Local'], array_column($bar->datasets, 'label'));
        self::assertFalse($bar->empty);

        $monthly = $this->reporter()->report($owner, new ReportQuery(mode: 'monthly', basis: 'equivalent'));
        self::assertSame(['Local'], $monthly->labels);
        self::assertEqualsWithDelta([10.0], $monthly->data, 0.001);
    }

    public function testEmptyReportHasZeroTotalsAndEmptySeries(): void
    {
        $user = $this->createUser('reporter-empty@example.com');
        $user->setMainCurrency('USD');
        $this->em->flush();

        $owner = parent::freshUser('reporter-empty@example.com');
        self::assertInstanceOf(\App\Entity\User::class, $owner);

        $bar = $this->reporter()->report($owner, new ReportQuery(mode: 'bar', basis: 'direct'));
        self::assertTrue($bar->empty);
        self::assertSame([], $bar->datasets);
        self::assertSame(0, $bar->summary['count']);
        self::assertEqualsWithDelta(0.0, $bar->summary['monthlyCalculated'], 0.001);

        $monthly = $this->reporter()->report($owner, new ReportQuery(mode: 'monthly', basis: 'direct'));
        self::assertTrue($monthly->empty);
        self::assertSame([], $monthly->labels);
        self::assertSame([], $monthly->data);
        self::assertSame(0, $monthly->hiddenCount);
        self::assertNull($monthly->hiddenCycle);
    }

    /**
     * @param list<string> $labels
     *
     * @return list<string>
     */
    private function sorted(array $labels): array
    {
        sort($labels);

        return $labels;
    }

    private function reporter(): DashboardReporter
    {
        return static::getContainer()->get(DashboardReporter::class);
    }
}
