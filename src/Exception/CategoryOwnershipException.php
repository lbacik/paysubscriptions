<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The assigned expense category belongs to a different User.
 *
 * Extends \LogicException so existing callers catching the ownership gate
 * stay unchanged; web callers catch this class specifically so unrelated
 * logic errors are never mistaken for a category-ownership problem.
 */
final class CategoryOwnershipException extends \LogicException
{
}
