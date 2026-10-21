<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * An ISO 4217 currency code, case-insensitive like the web experience and the
 * entity setters (both normalize through CurrencyService). Null and blank
 * values are valid; combine with NotNull/NotBlank when the field requires one.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class CurrencyCode extends Constraint
{
    public string $message = 'This value is not a valid ISO 4217 currency code.';

    public function validatedBy(): string
    {
        return CurrencyCodeValidator::class;
    }
}
