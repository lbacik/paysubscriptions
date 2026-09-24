<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ChartService;
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
        private readonly ChartService $chartService,
        private readonly UpcomingRenewals $upcomingRenewals,
    ) {
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(
        #[MapQueryParameter('chartType')] string $chartType = 'bar',
        #[MapQueryParameter('withCalculated')] bool $withCalculated = false,
        #[MapQueryParameter('month')] int|null $month = null,
        #[MapQueryParameter('sort')] string $sort = 'name',
        #[MapQueryParameter('order')] string $order = 'asc',
    ): Response {
        $user = $this->getUser();
        $mainCurrency = $user instanceof \App\Entity\User ? $user->getMainCurrency() : null;

        $subscriptions = $this->subscriptionService->get($this->getUser(), $sort, $order, $mainCurrency);

        $chart = match($chartType) {
            'monthly' => $this->chartService->createMonthlyChart($subscriptions, $withCalculated, $month, $mainCurrency),
            'yearly' => $this->chartService->createYearlyChart($subscriptions, $withCalculated, $mainCurrency),
            default => $this->chartService->createBarChart($subscriptions, $mainCurrency),
        };

        $totals = $this->subscriptionService->getTotals($subscriptions, $mainCurrency);
        $pendingReviewSubscriptions = $this->subscriptionService->getPendingReviewSubscriptions($subscriptions, $mainCurrency);
        $chartMeta = $this->describeChart($chartType, $withCalculated, $month, $subscriptions, $mainCurrency);
        $subscriptionLimit = $user instanceof \App\Entity\User
            ? $user->getSubscriptionsLimit()
            : \App\Entity\Limits::DEFAULT_SUBSCRIPTIONS_LIMIT;
        $count = count($subscriptions);
        $limitPercentage = $subscriptionLimit > 0
            ? min(100, (int) round(($count / $subscriptionLimit) * 100))
            : 0;

        return $this->render('dashboard/index.html.twig', [
            'subscriptions' => $subscriptions,
            'chart' => $chart,
            'fullWidth' => $chartType === 'bar',
            'active' => ['type' => $chartType, 'withCalculated' => $withCalculated, 'month' => $month],
            'totals' => $totals,
            'mainCurrency' => $mainCurrency,
            'pendingReviewSubscriptions' => $pendingReviewSubscriptions,
            'subscriptionLimit' => $subscriptionLimit,
            'limitPercentage' => $limitPercentage,
            'addSubscriptionDisabled' => ! $this->subscriptionService->isAbleToAddSubscription($this->getUser()),
            'upcomingRenewals' => $this->upcomingRenewals->nextOccurrences($subscriptions),
            'chartMeta' => $chartMeta,
        ]);
    }

    /**
     * Describes what the active chart draws so the template can label its
     * currency and basis (normalized equivalents vs direct-cycle charges vs
     * the bar view's per-month charge distribution) and render clear
     * empty/filtered states. The inclusion rules mirror ChartService: only
     * reportable Subscriptions (nothing pending converted-amount review)
     * ever reach a chart.
     *
     * @param array<\App\Entity\Subscription> $subscriptions
     * @return array{basis: string, currency: ?string, empty: bool, hiddenCount: int, hiddenCycle: ?string}
     */
    private function describeChart(
        string $chartType,
        bool $withCalculated,
        int|null $month,
        array $subscriptions,
        ?string $mainCurrency,
    ): array {
        $reportable = $this->chartService->filterReportable($subscriptions, $mainCurrency);
        $meta = [
            'basis' => 'charges',
            'currency' => \App\Service\CurrencyService::normalizeCode($mainCurrency),
            'empty' => $reportable === [],
            'hiddenCount' => 0,
            'hiddenCycle' => null,
        ];

        if ($chartType === 'monthly') {
            if ($withCalculated) {
                $meta['basis'] = 'monthly_equivalent';

                return $meta;
            }

            $meta['basis'] = 'monthly_direct';
            $included = array_filter(
                $reportable,
                static fn(\App\Entity\Subscription $s) => $s->isMonthly()
                    || ($month !== null && $s->getNextPayment()?->format('n') === (string) $month),
            );
            $meta['empty'] = $included === [];
            $meta['hiddenCount'] = \count($reportable) - \count($included);
            $meta['hiddenCycle'] = $meta['hiddenCount'] > 0 ? 'yearly' : null;

            return $meta;
        }

        if ($chartType === 'yearly') {
            if ($withCalculated) {
                $meta['basis'] = 'yearly_equivalent';

                return $meta;
            }

            $meta['basis'] = 'yearly_direct';
            $included = array_filter($reportable, static fn(\App\Entity\Subscription $s) => $s->isYearly());
            $meta['empty'] = $included === [];
            $meta['hiddenCount'] = \count($reportable) - \count($included);
            $meta['hiddenCycle'] = $meta['hiddenCount'] > 0 ? 'monthly' : null;

            return $meta;
        }

        return $meta;
    }
}
