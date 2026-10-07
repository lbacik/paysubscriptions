<?php

declare(strict_types=1);

namespace App\Controller;

use App\Report\DashboardReporter;
use App\Report\ReportQuery;
use App\Service\ChartService;
use App\Service\SubscriptionListState;
use App\Service\SubscriptionService;
use App\Service\UpcomingRenewals;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly DashboardReporter $reporter,
        private readonly ChartService $chartService,
        private readonly UpcomingRenewals $upcomingRenewals,
        private readonly SubscriptionListState $listState,
    ) {
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(
        #[MapQueryParameter('chartType')] string $chartType = 'bar',
        #[MapQueryParameter('withCalculated')] bool $withCalculated = false,
        #[MapQueryParameter('month')] int|null $month = null,
    ): Response {
        $user = $this->getUser();
        $mainCurrency = $user instanceof \App\Entity\User ? $user->getMainCurrency() : null;

        // The same session-backed filter/sort state the table component reads,
        // so charts, totals, and renewals describe the filtered list.
        $sort = $this->listState->sort();
        $order = $this->listState->order();
        $categoryId = $this->listState->categoryId();
        $subscriptions = $this->subscriptionService->get(
            $this->getUser(),
            $sort,
            $order,
            $categoryId,
            $mainCurrency,
        );

        $mode = match ($chartType) {
            'monthly' => ReportQuery::MODE_MONTHLY,
            'yearly' => ReportQuery::MODE_YEARLY,
            default => ReportQuery::MODE_BAR,
        };
        $report = $this->reporter->report(
            $this->getUser(),
            new ReportQuery(
                mode: $mode,
                basis: $withCalculated ? ReportQuery::BASIS_EQUIVALENT : ReportQuery::BASIS_DIRECT,
                month: $month,
                categoryId: $categoryId,
                sort: $sort,
                order: $order,
            ),
        );
        $chart = $this->chartService->createChart($report);

        $pendingReviewSubscriptions = $this->subscriptionService->getPendingReviewSubscriptions($subscriptions, $mainCurrency);
        $subscriptionLimit = $user instanceof \App\Entity\User
            ? $user->getSubscriptionsLimit()
            : \App\Entity\Limits::DEFAULT_SUBSCRIPTIONS_LIMIT;
        // The limit card describes the whole account, not the filtered list.
        $totalCount = $this->subscriptionService->countAllSubscriptions($user);
        $limitPercentage = $subscriptionLimit > 0
            ? min(100, (int) round(($totalCount / $subscriptionLimit) * 100))
            : 0;

        return $this->render('dashboard/index.html.twig', [
            'subscriptions' => $subscriptions,
            'chart' => $chart,
            'fullWidth' => $chartType === 'bar',
            'active' => ['type' => $chartType, 'withCalculated' => $withCalculated, 'month' => $month],
            'totals' => $report->summary,
            'mainCurrency' => $mainCurrency,
            'pendingReviewSubscriptions' => $pendingReviewSubscriptions,
            'subscriptionLimit' => $subscriptionLimit,
            'totalSubscriptionCount' => $totalCount,
            'limitPercentage' => $limitPercentage,
            'addSubscriptionDisabled' => ! $this->subscriptionService->isAbleToAddSubscription($this->getUser()),
            'upcomingRenewals' => $this->upcomingRenewals->nextOccurrences($subscriptions),
            'chartMeta' => $report->visibility(),
        ]);
    }
}
