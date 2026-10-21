<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * Field rules for an expense category name, shared by the entity (web forms)
 * and the API v1 resource so both enforce the same constraints. Per-User
 * uniqueness is not a field rule: the web form checks it with UniqueEntity on
 * the entity, the API through ExpenseCategoryService.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class CategoryName extends Compound
{
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(),
            new Assert\Length(min: 2, max: 100),
        ];
    }
}
