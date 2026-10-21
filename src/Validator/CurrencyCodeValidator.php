<?php

declare(strict_types=1);

namespace App\Validator;

use App\Service\CurrencyService;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class CurrencyCodeValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        \assert($constraint instanceof CurrencyCode);

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value) || !CurrencyService::isValidCode($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
