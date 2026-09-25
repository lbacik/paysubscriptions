<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Subscription;
use App\Entity\User;

/**
 * Decides whether a forecast renewal is due its email reminder on the User's
 * local calendar day.
 *
 * Lead time is measured in calendar days in the User's account time zone: the
 * reference "today" is the current date in that zone, not UTC. Time-zone
 * conversion (including DST transitions) is delegated to PHP's DateTimeZone,
 * so day boundaries follow the account zone.
 */
final class RenewalReminderPlanner
{
    public function __construct(
        private readonly RenewalCalculator $calculator,
        private readonly TimezoneService $timezones,
    ) {
    }

    /**
     * Returns the forecast renewal date a reminder is due for, or null when no
     * reminder should be scheduled (opted out, missing data, or the renewal
     * is further out than the configured lead time).
     */
    public function dueRenewalDate(
        Subscription $subscription,
        User $user,
        \DateTimeImmutable $now,
    ): ?\DateTimeImmutable {
        if (!$user->isEmailRemindersEnabled()) {
            return null;
        }

        $anchor = $subscription->getNextPayment();
        $cycle = $subscription->getBillingCycle();

        if (null === $anchor || null === $cycle) {
            return null;
        }

        $localToday = $now
            ->setTimezone(new \DateTimeZone($this->timezones->normalize($user->getTimezone())))
            ->setTime(0, 0, 0);

        $renewal = $this->calculator->nextRenewal($anchor, $cycle, $localToday);

        // nextRenewal() rolls forward to the first occurrence on or after the
        // reference day, so the diff is never negative; the guard stays as
        // defense against future calculator changes.
        $daysUntil = (int) $localToday->diff($renewal)->format('%r%a');

        if ($daysUntil < 0 || $daysUntil > $user->getReminderLeadDays()) {
            return null;
        }

        return $renewal;
    }
}
