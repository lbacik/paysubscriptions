<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use App\Entity\User;
use App\Enum\BillingCycle;
use App\Report\DashboardReport as DomainReport;
use App\Report\DashboardReporter;
use App\Report\ReportQuery;
use App\Service\ExpenseCategoryService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

/**
 * Dashboard snapshot report on API Platform (issue #99, DTO resource).
 *
 * Seam: public HTTP interface GET /api/v1/reports/dashboard in the default
 * (HAL) format; content negotiation is covered by DashboardReportFormatsTest
 * and the token gate by TokenGateTest.
 *
 * Behavior is observed through status, content-type, and body only. Every
 * mode and basis is compared against DashboardReporter for the same
 * fixtures — totals, charts, doughnut modes, and pending review — never
 * against hard-coded numbers. Money is asserted as decimal strings.
 */
final class DashboardReportTest extends ApiV1TestCase
{
    private const URL = '/api/v1/reports/dashboard';

    private User $user;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('api-report-user@example.com');
        $this->other = $this->createUser('api-report-other@example.com');
    }

    public function testDefaultBarReportMatchesReporterParity(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();
        $this->createSubscription($this->user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-01-15'));
        $this->createSubscription($this->user, 'Amazon Prime', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-01'));
        $this->createSubscription($this->other, 'Foreign', BillingCycle::Monthly, 999.0, new \DateTime('2024-01-15'));

        $report = $this->requestAs($this->user, 'GET', self::URL)->toArray();

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/hal+json');

        $expected = $this->reporter()->report($this->owner('api-report-user@example.com'), new ReportQuery());

        // Summary mirrors the reporter; money travels as decimal strings.
        self::assertSame(self::money((float) $expected->summary['monthly']), $report['summary']['monthly']);
        self::assertSame(self::money((float) $expected->summary['yearly']), $report['summary']['yearly']);
        self::assertSame(self::money((float) $expected->summary['monthlyCalculated']), $report['summary']['monthlyEquivalent']);
        self::assertSame(self::money((float) $expected->summary['yearlyCalculated']), $report['summary']['yearlyEquivalent']);
        self::assertSame($expected->summary['count'], $report['summary']['count']);
        self::assertSame($expected->summary['pendingReview'], $report['summary']['pendingReview']);
        self::assertSame($expected->summary['currency'], $report['summary']['currency']);
        self::assertSame($this->user->getSubscriptionsLimit(), $report['summary']['limit']);

        // The other User's Subscription never contributes.
        self::assertSame(2, $report['summary']['count']);

        // Bar chart numeric data mirrors the reporter without presentation options.
        self::assertSame('bar', $report['chart']['mode']);
        self::assertSame($expected->basis, $report['chart']['basis']);
        self::assertSame('charges', $report['chart']['basis']);
        self::assertSame($expected->currency, $report['chart']['currency']);
        self::assertSame($expected->labels, $report['chart']['labels']);
        self::assertArrayNotHasKey('data', $report['chart']);
        self::assertCount(\count($expected->datasets), $report['chart']['datasets']);
        foreach ($expected->datasets as $i => $dataset) {
            self::assertSame($dataset['label'], $report['chart']['datasets'][$i]['label']);
            self::assertSame(
                array_map(static fn(mixed $value): string => self::money((float) $value), $dataset['data']),
                $report['chart']['datasets'][$i]['data'],
            );
        }
        self::assertSame($expected->empty, $report['chart']['empty']);
        self::assertFalse($report['chart']['empty']);
        self::assertSame(0, $report['chart']['hiddenCount']);
        self::assertArrayNotHasKey('hiddenCycle', $report['chart']);

        // Filters echo the applied (defaulted) selectors; nulls are omitted.
        self::assertSame(
            ['chartMode' => 'bar', 'basis' => 'direct'],
            $report['filters'],
        );

        // The negotiated format wraps the report in its item envelope.
        self::assertSame(self::URL, $report['_links']['self']['href']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function doughnutModes(): array
    {
        return [
            'monthly direct' => ['monthly', 'direct', 'monthly_direct'],
            'monthly equivalent' => ['monthly', 'equivalent', 'monthly_equivalent'],
            'yearly direct' => ['yearly', 'direct', 'yearly_direct'],
            'yearly equivalent' => ['yearly', 'equivalent', 'yearly_equivalent'],
        ];
    }

    #[DataProvider('doughnutModes')]
    public function testDoughnutModesMatchReporterChartsAndVisibility(
        string $chartMode,
        string $basis,
        string $expectedBasis,
    ): void {
        $this->user->setMainCurrency('USD');
        $this->em->flush();
        $this->createSubscription($this->user, 'Netflix', BillingCycle::Monthly, 12.0, new \DateTime('2024-01-15'));
        $this->createSubscription($this->user, 'Amazon Prime', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-01'));

        $query = http_build_query(['chartMode' => $chartMode, 'basis' => $basis]);
        $report = $this->requestAs($this->user, 'GET', self::URL.'?'.$query)->toArray();

        self::assertResponseIsSuccessful();

        $expected = $this->reporter()->report(
            $this->owner('api-report-user@example.com'),
            new ReportQuery(mode: $chartMode, basis: $basis),
        );

        self::assertSame($chartMode, $report['chart']['mode']);
        self::assertSame($expectedBasis, $report['chart']['basis']);
        self::assertSame($expected->basis, $report['chart']['basis']);
        self::assertSame('USD', $report['chart']['currency']);
        self::assertSame($expected->labels, $report['chart']['labels']);
        self::assertSame(
            array_map(static fn(mixed $value): string => self::money((float) $value), $expected->data),
            $report['chart']['data'],
        );
        self::assertArrayNotHasKey('datasets', $report['chart']);
        self::assertSame($expected->empty, $report['chart']['empty']);
        self::assertFalse($report['chart']['empty']);

        // Visibility metadata mirrors the dashboard describeChart rules.
        self::assertSame($expected->hiddenCount, $report['chart']['hiddenCount']);
        if (null === $expected->hiddenCycle) {
            self::assertArrayNotHasKey('hiddenCycle', $report['chart']);
        } else {
            self::assertSame($expected->hiddenCycle, $report['chart']['hiddenCycle']);
        }
        if ('monthly' === $chartMode && 'direct' === $basis) {
            self::assertSame(1, $report['chart']['hiddenCount']);
            self::assertSame('yearly', $report['chart']['hiddenCycle']);
        } elseif ('yearly' === $chartMode && 'direct' === $basis) {
            self::assertSame(1, $report['chart']['hiddenCount']);
            self::assertSame('monthly', $report['chart']['hiddenCycle']);
        } else {
            self::assertSame(0, $report['chart']['hiddenCount']);
            self::assertArrayNotHasKey('hiddenCycle', $report['chart']);
        }

        self::assertSame(
            ['chartMode' => $chartMode, 'basis' => $basis],
            $report['filters'],
        );
    }

    public function testCategoryNarrowsReportToOwnedCategory(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();
        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $food = $categories->create($this->user, 'Food', '#ff0000');
        $fun = $categories->create($this->user, 'Fun', '#00ff00');

        $monthly = $this->createSubscription($this->user, 'Groceries', BillingCycle::Monthly, 50.0, new \DateTime('2024-01-15'));
        $monthly->setCategory($food);
        $yearly = $this->createSubscription($this->user, 'Cinema', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-01'));
        $yearly->setCategory($fun);
        $this->em->flush();

        $query = http_build_query(['category' => (string) $food->getId()]);
        $report = $this->requestAs($this->user, 'GET', self::URL.'?'.$query)->toArray();

        self::assertResponseIsSuccessful();

        $expected = $this->reporter()->report(
            $this->owner('api-report-user@example.com'),
            new ReportQuery(categoryId: (string) $food->getId()),
        );

        self::assertSame($expected->summary['count'], $report['summary']['count']);
        self::assertSame(1, $report['summary']['count']);
        self::assertSame(self::money((float) $expected->summary['monthly']), $report['summary']['monthly']);
        self::assertSame(self::money((float) $expected->summary['monthlyCalculated']), $report['summary']['monthlyEquivalent']);
        self::assertSame(
            ['chartMode' => 'bar', 'basis' => 'direct', 'category' => (string) $food->getId()],
            $report['filters'],
        );
    }

    public function testForeignCategoryGivesAnEmptyReport(): void
    {
        $categories = static::getContainer()->get(ExpenseCategoryService::class);
        $foreign = $categories->create($this->other, 'Foreign', '#333333');
        $this->createSubscription($this->user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-01-15'));

        $query = http_build_query(['category' => (string) $foreign->getId()]);
        $report = $this->requestAs($this->user, 'GET', self::URL.'?'.$query)->toArray();

        // A foreign category is not a 404: the User-scoped query matches
        // nothing, so the report renders zero totals and an empty chart.
        self::assertResponseIsSuccessful();
        self::assertSame(0, $report['summary']['count']);
        self::assertSame('0.00', $report['summary']['monthly']);
        self::assertSame('0.00', $report['summary']['yearly']);
        self::assertSame('0.00', $report['summary']['monthlyEquivalent']);
        self::assertSame('0.00', $report['summary']['yearlyEquivalent']);
        self::assertTrue($report['chart']['empty']);
        // The bar view always labels the twelve months, even when empty.
        self::assertSame(DomainReport::MONTHS, $report['chart']['labels']);
        self::assertSame([], $report['chart']['datasets']);
        self::assertSame((string) $foreign->getId(), $report['filters']['category']);
    }

    public function testUnknownCategoryGivesAnEmptyReport(): void
    {
        $this->createSubscription($this->user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-01-15'));

        $query = http_build_query(['chartMode' => 'monthly', 'category' => (string) Uuid::v4()]);
        $report = $this->requestAs($this->user, 'GET', self::URL.'?'.$query)->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(0, $report['summary']['count']);
        self::assertTrue($report['chart']['empty']);
        self::assertSame([], $report['chart']['labels']);
        self::assertSame([], $report['chart']['data']);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function invalidSelectors(): array
    {
        return [
            'unknown chart mode' => ['chartMode=weekly', ['chartMode']],
            'unknown basis' => ['chartMode=monthly&basis=annual', ['basis']],
            'equivalent basis with bar' => ['chartMode=bar&basis=equivalent', ['basis']],
            'month zero' => ['chartMode=monthly&basis=direct&month=0', ['month']],
            'month thirteen' => ['chartMode=monthly&basis=direct&month=13', ['month']],
            'month not a number' => ['chartMode=monthly&basis=direct&month=may', ['month']],
            'month with bar' => ['chartMode=bar&month=5', ['month']],
            'month with yearly' => ['chartMode=yearly&month=5', ['month']],
            'month with monthly equivalent' => ['chartMode=monthly&basis=equivalent&month=5', ['month']],
            'malformed category' => ['category=not-a-uuid', ['category']],
            'several selectors at once' => ['chartMode=weekly&basis=annual', ['basis', 'chartMode']],
        ];
    }

    /**
     * @param list<string> $expectedPaths
     */
    #[DataProvider('invalidSelectors')]
    public function testInvalidSelectorsAreAValidation422WithViolations(string $query, array $expectedPaths): void
    {
        $response = $this->requestAs($this->user, 'GET', self::URL.'?'.$query);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');

        $problem = json_decode($response->getContent(false), true);
        self::assertSame(422, $problem['status']);
        self::assertSame($expectedPaths, self::violatedPaths($problem));
        self::assertNotSame('invalid_filter', $problem['code'] ?? null);
    }

    public function testMonthSelectorAppliesOnlyToMonthlyDirect(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();
        $this->createSubscription($this->user, 'Netflix', BillingCycle::Monthly, 10.0, new \DateTime('2024-01-15'));
        $this->createSubscription($this->user, 'May Yearly', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-15'));

        $may = $this->requestAs(
            $this->user,
            'GET',
            self::URL.'?'.http_build_query(['chartMode' => 'monthly', 'basis' => 'direct', 'month' => 5]),
        )->toArray();

        self::assertResponseIsSuccessful();

        $expectedMay = $this->reporter()->report(
            $this->owner('api-report-user@example.com'),
            new ReportQuery(mode: 'monthly', basis: 'direct', month: 5),
        );
        self::assertSame($expectedMay->labels, $may['chart']['labels']);
        self::assertSame($expectedMay->hiddenCount, $may['chart']['hiddenCount']);
        self::assertSame(0, $may['chart']['hiddenCount']);
        self::assertSame(
            ['chartMode' => 'monthly', 'basis' => 'direct', 'month' => 5],
            $may['filters'],
        );

        $june = $this->requestAs(
            $this->user,
            'GET',
            self::URL.'?'.http_build_query(['chartMode' => 'monthly', 'basis' => 'direct', 'month' => 6]),
        )->toArray();

        self::assertResponseIsSuccessful();

        $expectedJune = $this->reporter()->report(
            $this->owner('api-report-user@example.com'),
            new ReportQuery(mode: 'monthly', basis: 'direct', month: 6),
        );
        self::assertSame($expectedJune->labels, $june['chart']['labels']);
        self::assertSame(['Netflix'], $june['chart']['labels']);
        self::assertSame(1, $june['chart']['hiddenCount']);
        self::assertSame('yearly', $june['chart']['hiddenCycle']);

        // Summary always covers the whole filtered set, never the month slice.
        self::assertSame($may['summary']['count'], $june['summary']['count']);
        self::assertSame($may['summary']['monthlyEquivalent'], $june['summary']['monthlyEquivalent']);
    }

    public function testNoConfirmedMainCurrencyKeepsUnlabeledTotals(): void
    {
        // No main currency confirmed: legacy amounts pass through unchanged.
        $this->createSubscription($this->user, 'Netflix', BillingCycle::Monthly, 15.99, new \DateTime('2024-01-15'));
        $this->createSubscription($this->user, 'Amazon Prime', BillingCycle::Yearly, 120.0, new \DateTime('2024-05-01'));

        $report = $this->requestAs($this->user, 'GET', self::URL)->toArray();

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('currency', $report['summary']);
        self::assertArrayNotHasKey('currency', $report['chart']);
        self::assertSame(2, $report['summary']['count']);
        self::assertSame(0, $report['summary']['pendingReview']);

        $expected = $this->reporter()->report($this->owner('api-report-user@example.com'), new ReportQuery());
        self::assertSame(self::money((float) $expected->summary['monthlyCalculated']), $report['summary']['monthlyEquivalent']);
        self::assertSame(self::money((float) $expected->summary['yearlyCalculated']), $report['summary']['yearlyEquivalent']);
    }

    public function testPendingReviewExcludedFromMoneyButKeptInCount(): void
    {
        $this->user->setMainCurrency('USD');
        $this->em->flush();

        $fresh = $this->createSubscription($this->user, 'Local', BillingCycle::Monthly, 10.0, new \DateTime('2024-01-15'));
        $fresh->setCurrency('USD');
        $stale = $this->createSubscription($this->user, 'Stale', BillingCycle::Monthly, 20.0, new \DateTime('2024-01-15'));
        $stale->setCurrency('EUR');
        $stale->setConvertedAmount(22.0);
        $stale->setConvertedCurrency('PLN');
        $this->em->flush();

        $report = $this->requestAs($this->user, 'GET', self::URL)->toArray();

        self::assertResponseIsSuccessful();

        $expected = $this->reporter()->report($this->owner('api-report-user@example.com'), new ReportQuery());
        self::assertSame($expected->summary['pendingReview'], $report['summary']['pendingReview']);
        self::assertSame(1, $report['summary']['pendingReview']);
        self::assertSame(2, $report['summary']['count']);
        // Only the fresh subscription contributes money.
        self::assertSame(self::money((float) $expected->summary['monthly']), $report['summary']['monthly']);
        self::assertSame('10.00', $report['summary']['monthly']);
        self::assertSame('10.00', $report['summary']['monthlyEquivalent']);

        // Charts draw only the reportable subscription.
        self::assertCount(1, $report['chart']['datasets']);
        self::assertSame('Local', $report['chart']['datasets'][0]['label']);

        $monthly = $this->requestAs(
            $this->user,
            'GET',
            self::URL.'?'.http_build_query(['chartMode' => 'monthly', 'basis' => 'equivalent']),
        )->toArray();
        self::assertSame(['Local'], $monthly['chart']['labels']);
        self::assertSame(['10.00'], $monthly['chart']['data']);
    }

    public function testEmptyReportRendersZeroTotalsAndEmptyCharts(): void
    {
        $report = $this->requestAs($this->user, 'GET', self::URL)->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(0, $report['summary']['count']);
        self::assertSame(0, $report['summary']['pendingReview']);
        self::assertSame('0.00', $report['summary']['monthlyEquivalent']);
        self::assertTrue($report['chart']['empty']);
    }

    public static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $problem
     *
     * @return list<string> the violated property paths of the response, sorted
     */
    private static function violatedPaths(array $problem): array
    {
        $paths = array_column($problem['violations'] ?? [], 'propertyPath');
        sort($paths);

        return array_values(array_unique($paths));
    }

    private function reporter(): DashboardReporter
    {
        return static::getContainer()->get(DashboardReporter::class);
    }

    private function owner(string $email): User
    {
        $user = $this->freshUser($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
