<?php

declare(strict_types=1);

namespace App\Mailer;

use Symfony\Component\Mailer\Exception\RuntimeException;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * A message that cannot leave through the SES tenant boundary as built.
 *
 * Retrying cannot fix it, so the async mailer sends it straight to the
 * failure transport instead of retrying.
 */
final class SesTenantBoundaryViolation extends RuntimeException implements UnrecoverableExceptionInterface
{
}
