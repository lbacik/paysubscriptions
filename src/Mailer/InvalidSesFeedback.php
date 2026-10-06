<?php

declare(strict_types=1);

namespace App\Mailer;

/**
 * An SQS message that is not a paysubs-app permanent bounce or complaint.
 *
 * The message is left on the queue so it reaches the dead-letter queue and
 * the operator alarm; it never restricts or releases a recipient. The message
 * names no recipient, so it is safe to log.
 */
final class InvalidSesFeedback extends \RuntimeException
{
}
