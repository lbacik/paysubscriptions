<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Api\Problem;
use App\Entity\ExpenseCategory;
use App\Entity\User;
use App\Repository\ExpenseCategoryRepository;
use App\Report\DashboardReport;
use App\Report\DashboardReporter;
use App\Report\ReportQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Dashboard snapshot report for API v1 (issue #99, decisions #84 and #87).
 *
 * Returns the current User's summary-card values and the numeric data behind
 * one dashboard chart mode in a single JSON response. Calculations reuse the
 * Dashboard report module, so figures match the dashboard exactly: the
 * summary comes from SubscriptionService::getTotals() through
 * DashboardReporter, and the chart series and visibility metadata are the
 * same DashboardReport the web dashboard renders.
 *
 * The request is stateless: the optional category filter and the month-of-year
 * selector travel as explicit query parameters rather than web-session list
 * state. No year, date-range, payment-history, or forecast semantics are
 * offered. Pending-review cross-currency Subscriptions stay in the count but
 * are excluded from monetary totals and charts, exactly as on the dashboard.
 */
#[Route('/api/v1/reports/dashboard')]
class DashboardReportController extends AbstractController
{
    private const CHART_MODES = ['bar', 'monthly', 'yearly'];
    private const BASES = ['direct', 'equivalent'];

    public function __construct(
        private readonly DashboardReporter $reporter,
        private readonly ExpenseCategoryRepository $categories,
    ) {
    }

    #[Route('', name: 'api_v1_reports_dashboard', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->problem(
                Problem::body(
                    Problem::UNAUTHORIZED,
                    'Authentication required',
                    Response::HTTP_UNAUTHORIZED,
                    'Authentication is required to access this resource.',
                ),
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $chartMode = $request->query->get('chartMode', 'bar');
        if (!\is_string($chartMode) || !\in_array($chartMode, self::CHART_MODES, true)) {
            return $this->invalidFilter('chartMode', 'Unsupported chart mode. Expected one of: bar, monthly, yearly.');
        }

        $basis = $request->query->get('basis', 'direct');
        if (!\is_string($basis) || !\in_array($basis, self::BASES, true)) {
            return $this->invalidFilter('basis', 'Unsupported basis. Expected one of: direct, equivalent.');
        }

        if ('bar' === $chartMode && 'equivalent' === $basis) {
            return $this->invalidFilter('basis', 'The bar view reports per-month charges and has no normalized-equivalent basis.');
        }

        $month = $this->parseMonth($request->query->get('month'), $chartMode, $basis);
        if ($month instanceof JsonResponse) {
            return $month;
        }

        $categoryId = $request->query->get('categoryId');
        if ('' === $categoryId) {
            $categoryId = null;
        }
        if (null !== $categoryId && !$this->isOwnedCategory($user, $categoryId)) {
            return $this->problem(
                Problem::body(
                    Problem::CATEGORY_NOT_FOUND,
                    'Expense category not found',
                    Response::HTTP_NOT_FOUND,
                    'No expense category with this identifier.',
                ),
                Response::HTTP_NOT_FOUND,
            );
        }
        /** @var string|null $categoryId */
        $categoryId = \is_string($categoryId) ? $categoryId : null;

        $report = $this->reporter->report(
            $user,
            new ReportQuery(mode: $chartMode, basis: $basis, month: $month, categoryId: $categoryId),
        );

        return new JsonResponse([
            'summary' => [
                'monthly' => $report->summary['monthly'],
                'yearly' => $report->summary['yearly'],
                'monthlyEquivalent' => $report->summary['monthlyCalculated'],
                'yearlyEquivalent' => $report->summary['yearlyCalculated'],
                'count' => $report->summary['count'],
                'limit' => $user->getSubscriptionsLimit(),
                'currency' => $report->summary['currency'],
                'pendingReview' => $report->summary['pendingReview'],
            ],
            'chart' => $this->chart($report),
            'filters' => [
                'chartMode' => $chartMode,
                'basis' => $basis,
                'categoryId' => $categoryId,
                'month' => $month,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Validates the month-of-year selector. Only the monthly direct view uses
     * it (monthly Subscriptions plus yearly ones charged in that month);
     * any other use, or a value outside 1-12, is an invalid filter.
     *
     * @return int|null|JsonResponse The selector, or a 422 problem response
     */
    private function parseMonth(mixed $raw, string $chartMode, string $basis): int|null|JsonResponse
    {
        if (null === $raw) {
            return null;
        }

        $outOfScope = 'monthly' !== $chartMode || 'direct' !== $basis;
        if ($outOfScope) {
            return $this->invalidFilter('month', 'The month selector applies only to the monthly direct view.');
        }

        $month = \is_int($raw) ? $raw : (\is_string($raw) && preg_match('/^\d+$/', $raw) ? (int) $raw : null);
        if (null === $month || $month < 1 || $month > 12) {
            return $this->invalidFilter('month', 'Expected an integer month of the year between 1 and 12.');
        }

        return $month;
    }

    private function isOwnedCategory(User $user, mixed $categoryId): bool
    {
        if (!\is_string($categoryId) || !Uuid::isValid($categoryId)) {
            return false;
        }

        $category = $this->categories->find(Uuid::fromString($categoryId));
        if (!$category instanceof ExpenseCategory) {
            return false;
        }

        return $category->isOwnedBy($user);
    }

    /**
     * Numeric chart data plus visibility metadata, built straight from the
     * DashboardReport: no Chart.js presentation (colors, radii,
     * legend/tooltip settings) ever crosses into API v1.
     *
     * @return array<string, mixed>
     */
    private function chart(DashboardReport $report): array
    {
        if (ReportQuery::MODE_BAR === $report->mode) {
            return [
                'mode' => 'bar',
                'basis' => $report->basis,
                'currency' => $report->currency,
                'labels' => $report->labels,
                'datasets' => array_map(
                    static fn(array $dataset): array => [
                        'label' => $dataset['label'],
                        'data' => array_map(static fn(mixed $value): float => (float) $value, $dataset['data']),
                    ],
                    $report->datasets,
                ),
                'empty' => $report->empty,
                'hiddenCount' => 0,
                'hiddenCycle' => null,
            ];
        }

        return [
            'mode' => $report->mode,
            'basis' => $report->basis,
            'currency' => $report->currency,
            'labels' => $report->labels,
            'data' => array_map(static fn(mixed $value): float => (float) $value, $report->data),
            'empty' => $report->empty,
            'hiddenCount' => $report->hiddenCount,
            'hiddenCycle' => $report->hiddenCycle,
        ];
    }

    private function invalidFilter(string $field, string $message): JsonResponse
    {
        return $this->problem(
            Problem::body(
                Problem::INVALID_FILTER,
                'Invalid report filter',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'The report request contains an invalid filter.',
                ['errors' => [['field' => $field, 'message' => $message]]],
            ),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function problem(array $body, int $status): JsonResponse
    {
        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json']);
    }
}
