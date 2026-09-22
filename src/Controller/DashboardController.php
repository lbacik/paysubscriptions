<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ChartService;
use App\Service\SubscriptionService;
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
        $subscriptions = $this->subscriptionService->get($this->getUser(), $sort, $order);

        $chart = match($chartType) {
            'monthly' => $this->chartService->createMonthlyChart($subscriptions, $withCalculated, $month),
            'yearly' => $this->chartService->createYearlyChart($subscriptions, $withCalculated),
            default => $this->chartService->createBarChart($subscriptions),
        };

        $user = $this->getUser();
        $totals = $this->subscriptionService->getTotals($subscriptions);
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
            'subscriptionLimit' => $subscriptionLimit,
            'limitPercentage' => $limitPercentage,
            'addSubscriptionDisabled' => ! $this->subscriptionService->isAbleToAddSubscription($this->getUser()),
        ]);
    }
}
