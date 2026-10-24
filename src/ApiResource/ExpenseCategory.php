<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\State\Options;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\ExpenseCategory as ExpenseCategoryEntity;
use App\State\ExpenseCategoryProcessor;
use App\Validator\CategoryColor;
use App\Validator\CategoryName;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\TargetClass;
use Symfony\Component\Uid\Uuid;

/**
 * API v1 ExpenseCategory: a DTO backed by the entity through stateOptions
 * and the object mapper (ADR 0007). Reads use API Platform's Doctrine
 * provider, scoped to the token User by UserOwnedExtension; writes are mapped
 * onto the entity and go through ExpenseCategoryService in
 * ExpenseCategoryProcessor.
 *
 * The collection is not paginated: it is a small per-User picker a client
 * needs whole.
 */
#[ApiResource(
    description: "",
    operations: [
        new GetCollection(paginationEnabled: false, order: ['name' => 'ASC']),
        new Get(),
        new Post(processor: ExpenseCategoryProcessor::class),
        new Patch(processor: ExpenseCategoryProcessor::class),
        new Delete(processor: ExpenseCategoryProcessor::class),
    ],
    stateOptions: new Options(entityClass: ExpenseCategoryEntity::class),
)]
#[Map(source: ExpenseCategoryEntity::class, target: ExpenseCategoryEntity::class)]
final class ExpenseCategory
{
    // Read-only members are copied entity -> DTO only, never DTO -> entity
    // (a plain `if: false` would block both directions).
    #[ApiProperty(identifier: true, writable: false)]
    #[Map(if: new TargetClass(self::class))]
    public ?Uuid $id = null;

    #[CategoryName]
    public ?string $name = null;

    #[CategoryColor]
    public ?string $color = null;

    #[ApiProperty(writable: false)]
    #[Map(if: new TargetClass(self::class))]
    public ?\DateTimeInterface $createdAt = null;

    #[ApiProperty(writable: false)]
    #[Map(if: new TargetClass(self::class))]
    public ?\DateTimeInterface $updatedAt = null;

    /**
     * Builds the DTO for a managed entity, shared by the Subscription
     * relation mapping and its IRI denormalizer: both need the same
     * entity -> DTO conversion the object mapper cannot express for a
     * relation the serializer resolved to the managed entity.
     */
    public static function fromEntity(ExpenseCategoryEntity $entity): self
    {
        $dto = new self();
        $dto->id = $entity->getId();
        $dto->name = $entity->getName();
        $dto->color = $entity->getColor();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();

        return $dto;
    }
}
