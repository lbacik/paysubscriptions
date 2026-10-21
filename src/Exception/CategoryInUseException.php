<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * An expense category cannot be deleted while Subscriptions are assigned to
 * it; the caller must reassign them first.
 *
 * Extends \LogicException so existing callers catching the delete gate stay
 * unchanged; the web app and the API catch this class specifically so
 * unrelated logic errors are never mistaken for a category in use.
 */
final class CategoryInUseException extends \LogicException
{
}
