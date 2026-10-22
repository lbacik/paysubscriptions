<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\DashboardChart;
use App\ApiResource\DashboardDataset;
use App\ApiResource\DashboardFilters;
use App\ApiResource\DashboardReport;
use App\ApiResource\DashboardSummary;
use App\Entity\User;
use App\Report\DashboardReporter;
use App\Report\ReportQuery;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Serves the dashboard snapshot report through DashboardReporter (issue #99).
 *
 * The provider reads the chartMode/basis/category/month selectors from the
 * query, validates their values and combination rules, and scopes every
 * query to the token User: DashboardReporter loads through the existing
 * User-scoped SubscriptionService query, so another User's Subscriptions
 * never count and an unknown or foreign category matches nothing (zero
 * totals, empty chart) instead of leaking a 404.
 *
 * Invalid selectors throw the default parameter-validation 422 with
 * violations (one per offending parameter), never a bespoke problem shape.
 * Money leaves the provider as decimal strings.
 *
 * @implements ProviderInterface<DashboardReport>
 */
final class DashboardReportProvider implements ProviderInterface
{
    private const CHART_MODES = ['bar', 'monthly', 'yearly'];
    private const BASES = ['direct', 'equivalent'];

    public function __construct(
        private readonly DashboardReporter $reporter,
        private readonly Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $context['request'] ?? null;
        if (!$request instanceof Request) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication is required to access this resource.');
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            // Fail closed: without a token User nothing is visible. The
            // firewall and ApiAccessListener reject such requests first, so
            // this is unreachable through HTTP; it only guards direct use.
            throw new UnauthorizedHttpException('Bearer', 'Authentication is required to access this resource.');
        }

        [$chartMode, $basis, $month, $categoryId] = $this->selectors($request);

        $domain = $this->reporter->report(
            $user,
            new ReportQuery(mode: $chartMode, basis: $basis, month: $month, categoryId: $categoryId),
        );

        $money = static fn(float $value): string => number_format($value, 2, '.', '');

        if (ReportQuery::MODE_BAR === $domain->mode) {
            $chart = new DashboardChart(
                mode: $domain->mode,
                basis: $domain->basis,
                currency: $domain->currency,
                labels: $domain->labels,
                data: null,
                datasets: array_map(
                    static fn(array $dataset): DashboardDataset => new DashboardDataset(
                        label: (string) $dataset['label'],
                        data: array_map($money, array_map(static fn(mixed $value): float => (float) $value, $dataset['data'])),
                    ),
                    $domain->datasets,
                ),
                empty: $domain->empty,
                hiddenCount: $domain->hiddenCount,
                hiddenCycle: $domain->hiddenCycle,
            );
        } else {
            $chart = new DashboardChart(
                mode: $domain->mode,
                basis: $domain->basis,
                currency: $domain->currency,
                labels: $domain->labels,
                data: array_map($money, array_map(static fn(mixed $value): float => (float) $value, $domain->data)),
                datasets: null,
                empty: $domain->empty,
                hiddenCount: $domain->hiddenCount,
                hiddenCycle: $domain->hiddenCycle,
            );
        }

        return new DashboardReport(
            summary: new DashboardSummary(
                monthly: $money((float) $domain->summary['monthly']),
                yearly: $money((float) $domain->summary['yearly']),
                monthlyEquivalent: $money((float) $domain->summary['monthlyCalculated']),
                yearlyEquivalent: $money((float) $domain->summary['yearlyCalculated']),
                count: $domain->summary['count'],
                limit: $user->getSubscriptionsLimit(),
                currency: $domain->summary['currency'],
                pendingReview: $domain->summary['pendingReview'],
            ),
            chart: $chart,
            filters: new DashboardFilters(
                chartMode: $chartMode,
                basis: $basis,
                category: $categoryId,
                month: $month,
            ),
        );
    }

    /**
     * Validates the selectors and returns them parsed, or throws the default
     * 422 carrying one violation per offending parameter.
     *
     * @return array{string, string, int|null, string|null}
     */
    private function selectors(Request $request): array
    {
        $query = $request->query->all();

        $rawMode = $query['chartMode'] ?? 'bar';
        $rawBasis = $query['basis'] ?? 'direct';
        $monthPresent = \array_key_exists('month', $query);
        $rawMonth = $query['month'] ?? null;
        $rawCategory = $query['category'] ?? null;

        $violations = new ConstraintViolationList();

        $chartMode = null;
        if (!\is_string($rawMode) || !\in_array($rawMode, self::CHART_MODES, true)) {
            $violations->add(new ConstraintViolation(
                'Unsupported chart mode. Expected one of: bar, monthly, yearly.',
                'Unsupported chart mode. Expected one of: bar, monthly, yearly.',
                [],
                $rawMode,
                'chartMode',
                $rawMode,
            ));
        } else {
            $chartMode = $rawMode;
        }

        $basis = null;
        if (!\is_string($rawBasis) || !\in_array($rawBasis, self::BASES, true)) {
            $violations->add(new ConstraintViolation(
                'Unsupported basis. Expected one of: direct, equivalent.',
                'Unsupported basis. Expected one of: direct, equivalent.',
                [],
                $rawBasis,
                'basis',
                $rawBasis,
            ));
        } else {
            $basis = $rawBasis;
        }

        if ('bar' === $chartMode && 'equivalent' === $basis) {
            $violations->add(new ConstraintViolation(
                'The bar view reports per-month charges and has no normalized-equivalent basis.',
                'The bar view reports per-month charges and has no normalized-equivalent basis.',
                [],
                $rawBasis,
                'basis',
                $rawBasis,
            ));
        }

        $month = null;
        if ($monthPresent) {
            if ('monthly' !== $chartMode || 'direct' !== $basis) {
                $violations->add(new ConstraintViolation(
                    'The month selector applies only to the monthly direct view.',
                    'The month selector applies only to the monthly direct view.',
                    [],
                    $rawMonth,
                    'month',
                    $rawMonth,
                ));
            } else {
                $parsed = \is_int($rawMonth)
                    ? $rawMonth
                    : (\is_string($rawMonth) && preg_match('/^\d+$/', $rawMonth) ? (int) $rawMonth : null);
                if (null === $parsed || $parsed < 1 || $parsed > 12) {
                    $violations->add(new ConstraintViolation(
                        'Expected an integer month of the year between 1 and 12.',
                        'Expected an integer month of the year between 1 and 12.',
                        [],
                        $rawMonth,
                        'month',
                        $rawMonth,
                    ));
                } else {
                    $month = $parsed;
                }
            }
        }

        $categoryId = null;
        if (null !== $rawCategory && '' !== $rawCategory) {
            if (!\is_string($rawCategory) || !Uuid::isValid($rawCategory)) {
                $violations->add(new ConstraintViolation(
                    'Invalid category. Expected a UUID.',
                    'Invalid category. Expected a UUID.',
                    [],
                    $rawCategory,
                    'category',
                    $rawCategory,
                ));
            } else {
                $categoryId = $rawCategory;
            }
        }

        if (0 !== \count($violations)) {
            throw new ValidationException($violations);
        }

        \assert(null !== $chartMode && null !== $basis);

        return [$chartMode, $basis, $month, $categoryId];
    }
}
