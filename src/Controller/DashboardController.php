<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use App\Service\ChartService;
use App\Service\SubscriptionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[isGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(
        SubscriptionRepository $subscriptionRepository,
        SubscriptionService $subscriptionService,
        ChartService $chartService,
        #[MapQueryParameter('chartType')] string $chartType = 'bar',
        #[MapQueryParameter('withCalculated')] bool $withCalculated = false,
    ): Response {
        $subscriptions = $subscriptionRepository->findBy(['owner' => $this->getUser()]);

        $chart = match($chartType) {
            'monthly' => $chartService->createMonthlyChart($subscriptions, $withCalculated),
            'yearly' => $chartService->createYearlyChart($subscriptions, $withCalculated),
            default => $chartService->createBarChart($subscriptions),
        };

        return $this->render('dashboard/index.html.twig', [
            'subscriptions' => $subscriptions,
            'chart' => $chart,
            'total' => $this->calculateTotals($subscriptions),
            'fullWidth' => $chartType === 'bar',
            'active' => ['type' => $chartType, 'withCalculated' => $withCalculated],
            'addSubscriptionDisabled' => ! $subscriptionService->isAbleToAddSubscription($this->getUser()),
        ]);
    }

    private function calculateTotals(array $subscriptions): array
    {
        $totals = [
            'monthly' => 0.0,
            'yearly' => 0.0,
            'monthlyCalculated' => 0.0,
            'yearlyCalculated' => 0.0,
        ];

        return array_reduce(
            $subscriptions,
            function (array $totals, Subscription $subscription) {
                $totals['monthly'] += (float)$subscription->getMonthly();
                $totals['yearly'] += (float)$subscription->getYearly();
                $totals['monthlyCalculated'] += $subscription->monthlyCalculated();
                $totals['yearlyCalculated'] += $subscription->yearlyCalculated();

                return $totals;
            },
            $totals,
        );
    }

}
