<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\BillingCycle;

/**
 * Computes renewal dates from a Subscription's next-known-payment anchor.
 *
 * The anchor's calendar day is preserved across occurrences: when a target
 * month has no such day, that occurrence falls back to the month's last day
 * and later occurrences return to the anchor day when possible.
 */
final class RenewalCalculator
{
    public function nextRenewal(
        \DateTimeInterface $anchor,
        BillingCycle $cycle,
        ?\DateTimeInterface $from = null,
    ): \DateTimeImmutable {
        $from = $from ?? new \DateTimeImmutable('today');

        return $this->occurrence($anchor, $cycle, $this->firstPeriodOnOrAfter($anchor, $cycle, $from));
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    public function upcomingRenewals(
        \DateTimeInterface $anchor,
        BillingCycle $cycle,
        ?\DateTimeInterface $from,
        int $count,
    ): array {
        $from = $from ?? new \DateTimeImmutable('today');
        $firstPeriod = $this->firstPeriodOnOrAfter($anchor, $cycle, $from);

        $renewals = [];
        for ($period = $firstPeriod; $period < $firstPeriod + $count; ++$period) {
            $renewals[] = $this->occurrence($anchor, $cycle, $period);
        }

        return $renewals;
    }

    private function firstPeriodOnOrAfter(
        \DateTimeInterface $anchor,
        BillingCycle $cycle,
        \DateTimeInterface $from,
    ): int {
        $fromDay = $from->format('Y-m-d');

        $period = 0;
        while ($this->occurrence($anchor, $cycle, $period)->format('Y-m-d') < $fromDay) {
            ++$period;
        }

        return $period;
    }

    private function occurrence(
        \DateTimeInterface $anchor,
        BillingCycle $cycle,
        int $period,
    ): \DateTimeImmutable {
        $anchorDay = (int) $anchor->format('j');

        $month = (int) $anchor->format('n') + ($cycle === BillingCycle::Monthly ? $period : 0);
        $year = (int) $anchor->format('Y') + ($cycle === BillingCycle::Yearly ? $period : 0)
            + intdiv($month - 1, 12);
        $month = ($month - 1) % 12 + 1;

        $day = min($anchorDay, (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t'));

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
