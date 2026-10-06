<?php

declare(strict_types=1);

namespace App\Exception;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * A per-User expense-category name lost a concurrent-creation race.
 *
 * Thrown by ExpenseCategoryService instead of the raw DBAL
 * {@see UniqueConstraintViolationException} so web callers can re-render the
 * form with a field error (422) and API callers can answer 409, rather than
 * either layer importing DBAL exceptions or 500ing on the TOCTOU gap between
 * UniqueEntity validation and the INSERT.
 */
final class DuplicateCategoryNameException extends \RuntimeException
{
}
