<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * Field rules for an expense category color tag (`#RRGGBB`), shared by the
 * entity (web forms) and the API v1 resource.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class CategoryColor extends Compound
{
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(),
            new Assert\Regex(
                pattern: '/^#[0-9a-fA-F]{6}$/',
                message: 'Please provide a color tag in hex format, e.g. #577399.',
            ),
        ];
    }
}
