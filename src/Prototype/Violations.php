<?php

declare(strict_types=1);

namespace App\Prototype;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Exception\DuplicateCategoryNameException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * PROTOTYPE (#246): turns the service's duplicate-name outcome into the 422
 * `name` violation #245 asks for.
 */
final class Violations
{
    public static function duplicateName(DuplicateCategoryNameException $exception, mixed $root, string $name): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([
            new ConstraintViolation($exception->getMessage(), null, [], $root, 'name', $name),
        ]), null, $exception);
    }
}
