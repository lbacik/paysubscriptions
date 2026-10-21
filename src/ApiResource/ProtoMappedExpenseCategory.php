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
use App\Entity\ExpenseCategory;
use App\Prototype\MappedCategoryProcessor;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\TargetClass;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PROTOTYPE (#246) variant C: a DTO resource backed by the entity through
 * stateOptions + symfony/object-mapper. Reads use API Platform's Doctrine
 * provider (extensions, filters, paginator); writes map DTO -> entity and go
 * through ExpenseCategoryService in a custom processor.
 */
#[ApiResource(
    shortName: 'ProtoMappedExpenseCategory',
    operations: [
        new GetCollection(uriTemplate: '/v1/proto-mapped/expense-categories', paginationEnabled: false, order: ['name' => 'ASC']),
        new Get(uriTemplate: '/v1/proto-mapped/expense-categories/{id}'),
        new Post(uriTemplate: '/v1/proto-mapped/expense-categories', processor: MappedCategoryProcessor::class),
        new Patch(uriTemplate: '/v1/proto-mapped/expense-categories/{id}', processor: MappedCategoryProcessor::class),
        new Delete(uriTemplate: '/v1/proto-mapped/expense-categories/{id}', processor: MappedCategoryProcessor::class),
    ],
    stateOptions: new Options(entityClass: ExpenseCategory::class),
)]
#[Map(source: ExpenseCategory::class, target: ExpenseCategory::class)]
final class ProtoMappedExpenseCategory
{
    #[ApiProperty(identifier: true, writable: false)]
    // Read-only: copied entity -> DTO only, never DTO -> entity.
    #[Map(if: new TargetClass(self::class))]
    public ?Uuid $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'Please provide a color tag in hex format, e.g. #577399.')]
    public ?string $color = null;

    #[ApiProperty(writable: false)]
    // Read-only: copied entity -> DTO only, never DTO -> entity.
    #[Map(if: new TargetClass(self::class))]
    public ?\DateTimeInterface $createdAt = null;

    #[ApiProperty(writable: false)]
    // Read-only: copied entity -> DTO only, never DTO -> entity.
    #[Map(if: new TargetClass(self::class))]
    public ?\DateTimeInterface $updatedAt = null;
}
