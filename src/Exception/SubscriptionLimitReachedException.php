<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The owner already reached their maximum number of Subscriptions.
 *
 * Extends \LogicException so existing web callers catching the gate stay
 * unchanged; API callers catch this class specifically to return 409 without
 * swallowing unrelated logic errors.
 */
final class SubscriptionLimitReachedException extends \LogicException
{
}
