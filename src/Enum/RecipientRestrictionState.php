<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Current deliverability restriction of one normalized email address.
 *
 * - Undeliverable: SES reported a permanent bounce.
 * - DoNotSend: the recipient complained. Stronger than Undeliverable.
 *
 * Restrictions only ever tighten from SES feedback: no later or duplicate
 * event moves an address back to sendable or from DoNotSend to Undeliverable.
 */
enum RecipientRestrictionState: string
{
    case Undeliverable = 'undeliverable';
    case DoNotSend = 'do_not_send';
}
