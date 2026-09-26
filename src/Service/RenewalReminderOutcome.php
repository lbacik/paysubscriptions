<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Counters for one reminder run. Logged and printed by the console command;
 * counts only, never Subscription details.
 */
final class RenewalReminderOutcome
{
    public int $sent = 0;

    public int $skipped = 0;

    public int $needsReview = 0;
}
