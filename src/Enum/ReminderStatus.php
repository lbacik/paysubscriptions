<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle of one scheduled renewal reminder.
 *
 * - Pending: claimed by a worker that has not delivered yet. A fresh pending
 *   row blocks concurrent workers from sending the same renewal twice; a
 *   stale one (worker died mid-claim) may be reclaimed after a timeout.
 * - Sent: the mailer accepted the message exactly once for this identity.
 * - NeedsReview: sending through the mail provider ended ambiguously (the
 *   message may or may not have been delivered). The row is kept so the
 *   outcome stays visible for manual review and is never blindly resent.
 */
enum ReminderStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case NeedsReview = 'needs_review';
}
