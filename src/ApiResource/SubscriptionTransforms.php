<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\Entity\Subscription as SubscriptionEntity;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Pure value conversions between the API v1 Subscription DTO and its entity.
 *
 * Money travels as a decimal string on the wire but the entity accessors are
 * float-based, and the date travels as YYYY-MM-DD while the entity holds a
 * DateTimeInterface: neither direction can rely on the default property copy.
 * A single transform per property branches on the mapping target instead of
 * two direction-scoped #[Map] attributes.
 */
final class SubscriptionTransforms
{
    public static function mapAmount(mixed $value, object $source, ?object $target): float|string|null
    {
        if (null === $value) {
            return null;
        }

        if ($target instanceof SubscriptionEntity) {
            return (float) $value;
        }

        return sprintf('%.2F', (float) $value);
    }

    public static function mapNextPayment(mixed $value, object $source, ?object $target): \DateTimeImmutable|string|null
    {
        if (null === $value) {
            return null;
        }

        if ($target instanceof SubscriptionEntity) {
            if (!\is_string($value)) {
                return null;
            }

            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $date : null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        \assert(\is_string($value));

        return $value;
    }

    /**
     * Maps the category relation entity -> DTO. Input mapping never reaches
     * here (the DTO property skips DTO -> entity mapping, and the
     * denormalizer already converted the IRI-resolved entity), so a DTO
     * value is passed through and anything else maps to null.
     */
    public static function mapCategory(mixed $value, object $source, ?object $target): ?ExpenseCategory
    {
        if ($value instanceof ExpenseCategory) {
            return $value;
        }

        if ($value instanceof \App\Entity\ExpenseCategory) {
            return ExpenseCategory::fromEntity($value);
        }

        return null;
    }

    public static function validateRealDate(mixed $value, ExecutionContextInterface $context): void
    {
        if (null === $value || !\is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            // Missing values are reported by NotNull, malformed ones by the
            // Regex: this only rejects impossible calendar dates.
            return;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            $context->buildViolation('This value should be a date in YYYY-MM-DD format.')->addViolation();
        }
    }
}
