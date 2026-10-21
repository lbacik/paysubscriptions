<?php

declare(strict_types=1);

namespace App\Serializer;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\ExpenseCategory as ExpenseCategoryDto;
use App\Entity\ExpenseCategory as ExpenseCategoryEntity;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Resolves a Subscription's `category` IRI to its DTO on input.
 *
 * IRI resolution hands back the managed entity, which cannot be assigned to
 * the DTO-typed relation property (the object mapper would then map the DTO
 * back to the entity on output, and HAL would lose `_links.category`). The
 * denormalizer converts the resolved entity up front, so the property only
 * ever holds the DTO. Unresolvable IRIs — foreign, unknown, malformed, or a
 * wrong resource — are 422 on `category` here, the same wording the domain
 * translator uses for CategoryOwnershipException, instead of API Platform's
 * default 400/404.
 */
final class ExpenseCategoryDenormalizer implements DenormalizerInterface
{
    public function __construct(
        private readonly IriConverterInterface $iriConverter,
    ) {
    }

    public function getSupportedTypes(?string $format): array
    {
        // The decision depends on the runtime data (IRI strings only), so the
        // result is not cacheable.
        return ['*' => false];
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return ExpenseCategoryDto::class === $type && \is_string($data);
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): ?ExpenseCategoryDto
    {
        \assert(\is_string($data));

        try {
            $item = $this->iriConverter->getResourceFromIri($data, $context + ['fetch_data' => true]);
        } catch (\Throwable $exception) {
            throw $this->invalidCategory($exception);
        }

        // The IRI converter runs the resource read path, so a found category
        // comes back as its DTO; accept it directly (the processor re-checks
        // ownership against the managed entity before writing).
        if ($item instanceof ExpenseCategoryDto) {
            return $item;
        }

        if (!$item instanceof ExpenseCategoryEntity) {
            throw $this->invalidCategory();
        }

        return ExpenseCategoryDto::fromEntity($item);
    }

    private function invalidCategory(?\Throwable $previous = null): ValidationException
    {
        return new ValidationException(
            new ConstraintViolationList([
                new ConstraintViolation(
                    'The selected category is invalid.',
                    null,
                    [],
                    null,
                    'category',
                    null,
                ),
            ]),
            null,
            $previous,
        );
    }
}
