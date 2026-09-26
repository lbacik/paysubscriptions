<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Subscription;

/**
 * Collects upcoming renewal occurrences across Subscriptions, orders them by
 * date, and returns the soonest few for the dashboard widget.
 *
 * One Subscription can contribute several occurrences: with fewer than five
 * Subscriptions, later repeats fill the widget up to the limit.
 *
 * The widget is always available: it reads only the signed-in User's
 * Subscriptions and never depends on email reminder preferences.
 */
final class UpcomingRenewals
{
    public function __construct(
        private readonly RenewalCalculator $calculator,
    ) {
    }

    /**
     * @param iterable<Subscription> $subscriptions already scoped to the signed-in User
     *
     * @return list<UpcomingRenewal>
     */
    public function nextOccurrences(
        iterable $subscriptions,
        ?\DateTimeInterface $today = null,
        int $limit = 5,
    ): array {
        $today ??= new \DateTimeImmutable('today');

        $renewals = [];
        foreach ($subscriptions as $subscription) {
            $anchor = $subscription->getNextPayment();
            $cycle = $subscription->getBillingCycle();

            if (null === $anchor || null === $cycle) {
                continue;
            }

            foreach ($this->calculator->upcomingRenewals($anchor, $cycle, $today, max(0, $limit)) as $renewalDate) {
                $renewals[] = new UpcomingRenewal($subscription, $renewalDate);
            }
        }

        usort(
            $renewals,
            fn(UpcomingRenewal $a, UpcomingRenewal $b) =>
                $a->renewalDate->format('Y-m-d') <=> $b->renewalDate->format('Y-m-d')
                ?: (string) $a->subscription->getName() <=> (string) $b->subscription->getName(),
        );

        return \array_slice($renewals, 0, max(0, $limit));
    }
}
