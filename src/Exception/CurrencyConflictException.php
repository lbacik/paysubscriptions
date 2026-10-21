<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A converted amount was confirmed against a different main currency than the
 * User's current one. The write is refused atomically (409) so a figure
 * computed for another currency is never stamped as reviewed.
 *
 * Extends \RuntimeException (not \LogicException) so callers catching the
 * ownership gate specifically never mistake it for a category problem.
 */
final class CurrencyConflictException extends \RuntimeException
{
}
