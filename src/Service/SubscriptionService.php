<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\User\UserInterface;

class SubscriptionService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly UserRepository $userRepository,
        private readonly ExpenseCategoryService $categoryService,
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
     * Totals in the given main currency. Cross-currency Subscriptions
     * contribute their user-entered converted amount; Subscriptions pending
     * review (stale or missing converted amount) are excluded until
     * reviewed. Without a confirmed main currency, stored amounts are summed
     * unchanged and totals carry no currency label.
     *
     * @param array<Subscription> $subscriptions
     * @return array{monthly: float, yearly: float, monthlyCalculated: float, yearlyCalculated: float, count: int, currency: ?string, pendingReview: int}
     */
    public function getTotals(array $subscriptions, ?string $mainCurrency = null): array
    {
        $main = CurrencyService::normalizeCode($mainCurrency);
        $totals = [
            'monthly' => 0.0,
            'yearly' => 0.0,
            'monthlyCalculated' => 0.0,
            'yearlyCalculated' => 0.0,
            'count' => count($subscriptions),
            'currency' => $main,
            'pendingReview' => 0,
        ];

        foreach ($subscriptions as $subscription) {
            if ($subscription->isPendingReview($main)) {
                ++$totals['pendingReview'];

                continue;
            }

            $totals['monthly'] += (float) ($subscription->isMonthly() ? $subscription->getReportingAmount($main) : 0.0);
            $totals['yearly'] += (float) ($subscription->isYearly() ? $subscription->getReportingAmount($main) : 0.0);
            $totals['monthlyCalculated'] += (float) ($subscription->getReportingMonthlyCalculated($main) ?? 0.0);
            $totals['yearlyCalculated'] += (float) ($subscription->getReportingYearlyCalculated($main) ?? 0.0);
        }

        return $totals;
    }

    /**
     * @param array<Subscription> $subscriptions
     * @return array<Subscription> Cross-currency Subscriptions pending review
     *                             before aggregates use them: stale converted
     *                             amounts or missing converted amounts.
     */
    public function getPendingReviewSubscriptions(array $subscriptions, ?string $mainCurrency): array
    {
        $main = CurrencyService::normalizeCode($mainCurrency);

        if ($main === null) {
            return [];
        }

        return array_values(array_filter(
            $subscriptions,
            static fn(Subscription $s) => $s->isPendingReview($main),
        ));
    }

    public function add(Subscription $subscription): void
    {
        $this->canAddNewSubscription($subscription->getOwner());
        $this->assertValidCurrency($subscription);

        $owner = $subscription->getOwner();
        if ($owner instanceof User && null === $subscription->getCategory()) {
            $subscription->setCategory($this->categoryService->ensureDefaultCategory($owner));
        }
        $this->assertCategoryOwnership($subscription);

        $this->subscriptionRepository->save($subscription);
    }

    public function update(Subscription $subscription): void
    {
        $this->assertCategoryOwnership($subscription);
        $this->assertValidCurrency($subscription);

        $this->subscriptionRepository->save($subscription);
    }

    /**
     * @throws \LogicException when the assigned category belongs to another User
     */
    public function assertCategoryOwnership(Subscription $subscription): void
    {
        $owner = $subscription->getOwner();
        $category = $subscription->getCategory();

        if (null === $owner || null === $category) {
            return;
        }

        if ($owner instanceof User && !$category->isOwnedBy($owner)) {
            throw new \LogicException('The selected category does not belong to this account.');
        }
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

    private function assertValidCurrency(Subscription $subscription): void
    {
        $owner = $subscription->getOwner();
        $main = $owner instanceof \App\Entity\User ? $owner->getMainCurrency() : null;

        $subscription->syncConvertedCurrency($main);

        $violations = $subscription->validateConverted($main, true);

        if ($violations !== []) {
            throw new \InvalidArgumentException(implode(' ', $violations));
        }
    }
}
