<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\User\UserInterface;

class SubscriptionService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function get(UserInterface $owner, string $sortBy, string $order): array
    {
        $subscriptions = $this->subscriptionRepository->findBy(['owner' => $owner]);

        uasort($subscriptions, fn(Subscription $a, Subscription $b) => match($sortBy) {
                'name' => $a->getName() <=> $b->getName(),
                'monthly' => $a->getMonthlyCalculated() <=> $b->getMonthlyCalculated(),
                'yearly' => $a->getYearlyCalculated() <=> $b->getYearlyCalculated(),
                default => 0,
            } * ($order === 'asc' ? 1 : -1));

        return $subscriptions;
    }

    /**
     * @param array<Subscription> $subscriptions
     * @return array{monthly: float, yearly: float, monthlyCalculated: float, yearlyCalculated: float, count: int}
     */
    public function getTotals(array $subscriptions): array
    {
        $totals = [
            'monthly' => 0.0,
            'yearly' => 0.0,
            'monthlyCalculated' => 0.0,
            'yearlyCalculated' => 0.0,
            'count' => count($subscriptions),
        ];

        foreach ($subscriptions as $subscription) {
            $totals['monthly'] += (float) ($subscription->isMonthly() ? $subscription->getAmount() : 0.0);
            $totals['yearly'] += (float) ($subscription->isYearly() ? $subscription->getAmount() : 0.0);
            $totals['monthlyCalculated'] += (float) ($subscription->getMonthlyCalculated() ?? 0.0);
            $totals['yearlyCalculated'] += (float) ($subscription->getYearlyCalculated() ?? 0.0);
        }

        return $totals;
    }

    public function add(Subscription $subscription): void
    {
        $this->canAddNewSubscription($subscription->getOwner());

        $this->subscriptionRepository->save($subscription);
    }

    public function update(Subscription $subscription): void
    {
        $this->subscriptionRepository->save($subscription);
    }

    public function canAddNewSubscription(UserInterface $user): void
    {
        $user = $this->userRepository->find($user->getId());

        if (count($user->getSubscriptions()) >= $user->getSubscriptionsLimit()) {
            throw new \LogicException('You have reached the maximum number of subscriptions.');
        }
    }

    public function isAbleToAddSubscription(UserInterface $user): bool
    {
        try {
            $this->canAddNewSubscription($user);

            return true;
        } catch (\LogicException) {
            return false;
        }
    }
}
