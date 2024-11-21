<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Service\SubscriptionService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class SubscriptionsTable
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly Security $security,
        private readonly SortableColumn $sortableColumn,
    ) {
    }

    public function getSubscriptions(): array
    {
        return $this->subscriptionService->get(
            $this->security->getUser(),
            $this->sortableColumn->sort(),
            $this->sortableColumn->order(),
        );
    }

    public function getTotal(): array
    {
        $subscriptions = $this->getSubscriptions();
        $totals = [
            'monthly' => 0.0,
            'yearly' => 0.0,
            'monthlyCalculated' => 0.0,
            'yearlyCalculated' => 0.0,
        ];

        foreach ($subscriptions as $subscription) {
            $totals['monthly'] += $subscription->getMonthly();
            $totals['yearly'] += $subscription->getYearly();
            $totals['monthlyCalculated'] += $subscription->getMonthlyCalculated();
            $totals['yearlyCalculated'] += $subscription->getYearlyCalculated();
        }

        return $totals;
    }
}
