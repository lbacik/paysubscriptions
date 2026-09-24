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
        private readonly RenewalCalculator $renewalCalculator,
    ) {
    }

    /**
     * The signed-in owner's Subscriptions, optionally narrowed to one of
     * their categories and ordered by name, comparable price, or next renewal.
     *
     * The category id is matched against the owner's own loaded records only,
     * so a forged foreign id matches nothing instead of leaking another
     * User's records. Price compares the monthly equivalent in the given main
     * currency (hand-entered converted amounts included); renewals compare the
     * projected next occurrence from the renewal model. Ties always break by
     * ascending name, then id, independent of the direction, so ties never
     * flip when the direction toggles.
     *
     * @param string|null $categoryId UUID string, or null for all categories
     */
    public function get(
        UserInterface $owner,
        string $sortBy,
        string $order,
        ?string $categoryId = null,
        ?string $mainCurrency = null,
        ?\DateTimeInterface $today = null,
    ): array {
        $subscriptions = $this->subscriptionRepository->findBy(['owner' => $owner]);
        $main = CurrencyService::normalizeCode($mainCurrency);

        if ($categoryId !== null && $categoryId !== '') {
            $subscriptions = array_values(array_filter(
                $subscriptions,
                static fn(Subscription $s) => $s->getCategory()?->getId() !== null
                    && (string) $s->getCategory()->getId() === $categoryId,
            ));
        }

        // Legacy keys predate the comparable-price sort; both monetary columns
        // follow the same price ordering (shared normalizer with the request state).
        $sortBy = SubscriptionListState::normalizeSort($sortBy);

        $main = CurrencyService::normalizeCode($mainCurrency);
        $today ??= new \DateTimeImmutable('today');
        $direction = $order === 'desc' ? -1 : 1;

        // Precompute the expensive keys once: renewal projection walks whole
        // billing cycles and must not run inside the comparator.
        $prices = [];
        $renewals = [];
        foreach ($subscriptions as $subscription) {
            $key = spl_object_id($subscription);
            $prices[$key] = $subscription->getReportingMonthlyCalculated($main);
            $renewals[$key] = $this->renewalCalculator->nextRenewal(
                $subscription->getNextPayment(),
                $subscription->getBillingCycle(),
                $today,
            );
        }

        uasort(
            $subscriptions,
            static function (Subscription $a, Subscription $b) use ($sortBy, $direction, $prices, $renewals): int {
                $primary = match ($sortBy) {
                    'name' => $a->getName() <=> $b->getName(),
                    'price' => $prices[spl_object_id($a)] <=> $prices[spl_object_id($b)],
                    'renewal' => $renewals[spl_object_id($a)]->format('Y-m-d') <=> $renewals[spl_object_id($b)]->format('Y-m-d'),
                    default => 0,
                };

                if ($primary !== 0) {
                    return $primary * $direction;
                }

                $tie = ($a->getName() ?? '') <=> ($b->getName() ?? '');

                return $tie !== 0 ? $tie : (string) $a->getId() <=> (string) $b->getId();
            }
        );

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

        // Per-Subscription equivalents are already rounded to cents; rounding
        // the sums keeps binary floating-point dust (10.1 + 20.2) out of the
        // figures the dashboard labels as currency amounts.
        $totals['monthly'] = round($totals['monthly'], 2);
        $totals['yearly'] = round($totals['yearly'], 2);
        $totals['monthlyCalculated'] = round($totals['monthlyCalculated'], 2);
        $totals['yearlyCalculated'] = round($totals['yearlyCalculated'], 2);

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

        // Non-form creation paths get the same default as the form: a new
        // Subscription starts in the User's main currency. Legacy data (no
        // confirmed main currency) stays untouched.
        if ($subscription->getCurrency() === null && CurrencyService::normalizeCode($main) !== null) {
            $subscription->setCurrency($main);
        }

        $subscription->syncConvertedCurrency($main);

        $violations = $subscription->validateConverted($main, true);

        if ($violations !== []) {
            throw new \InvalidArgumentException(implode(' ', $violations));
        }
    }
}
