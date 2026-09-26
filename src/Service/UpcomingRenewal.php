<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Subscription;

/**
 * A single forecast renewal occurrence: the Subscription it belongs to and
 * the computed date of its next charge.
 *
 * Forecast charges are derived from user-entered data, not recorded
 * transactions, and are shown independently of any email reminder opt-in.
 */
final readonly class UpcomingRenewal
{
    public function __construct(
        public Subscription $subscription,
        public \DateTimeImmutable $renewalDate,
    ) {
    }
}
