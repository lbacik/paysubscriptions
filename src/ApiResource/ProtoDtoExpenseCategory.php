<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\ExpenseCategory;
use App\Prototype\DtoCategoryProcessor;
use App\Prototype\DtoCategoryProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PROTOTYPE (#246) variant B: a plain DTO resource. A hand-written provider
 * reads through the repository/service, a hand-written processor writes
 * through ExpenseCategoryService. No Doctrine provider, so no Doctrine
 * extensions, filters or paginator.
 */
#[ApiResource(
    shortName: 'ProtoDtoExpenseCategory',
    operations: [
        new GetCollection(uriTemplate: '/v1/proto-dto/expense-categories', paginationEnabled: false),
        new Get(uriTemplate: '/v1/proto-dto/expense-categories/{id}'),
        new Post(uriTemplate: '/v1/proto-dto/expense-categories'),
        new Patch(uriTemplate: '/v1/proto-dto/expense-categories/{id}'),
        new Delete(uriTemplate: '/v1/proto-dto/expense-categories/{id}'),
    ],
    provider: DtoCategoryProvider::class,
    processor: DtoCategoryProcessor::class,
)]
final class ProtoDtoExpenseCategory
{
    #[ApiProperty(identifier: true, writable: false)]
    public ?Uuid $id = null;

    // Constraints duplicated from the entity: the DTO is what gets validated.
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'Please provide a color tag in hex format, e.g. #577399.')]
    public ?string $color = null;

    #[ApiProperty(writable: false)]
    public ?\DateTimeInterface $createdAt = null;

    #[ApiProperty(writable: false)]
    public ?\DateTimeInterface $updatedAt = null;

    public static function fromEntity(ExpenseCategory $category): self
    {
        $dto = new self();
        $dto->id = $category->getId();
        $dto->name = $category->getName();
        $dto->color = $category->getColor();
        $dto->createdAt = $category->getCreatedAt();
        $dto->updatedAt = $category->getUpdatedAt();

        return $dto;
    }
}
