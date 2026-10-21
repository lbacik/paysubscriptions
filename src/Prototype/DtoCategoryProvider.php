<?php

declare(strict_types=1);

namespace App\Prototype;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ProtoDtoExpenseCategory;
use App\Entity\ExpenseCategory;
use App\Entity\User;
use App\Repository\ExpenseCategoryRepository;
use App\Service\ExpenseCategoryService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * PROTOTYPE (#246) variant B: scoping lives in the provider (service query
 * for the list, isOwnedBy() for an item).
 *
 * @implements ProviderInterface<ProtoDtoExpenseCategory>
 */
final class DtoCategoryProvider implements ProviderInterface
{
    public function __construct(
        private readonly ExpenseCategoryService $service,
        private readonly ExpenseCategoryRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        if ($operation instanceof CollectionOperationInterface) {
            return array_map(ProtoDtoExpenseCategory::fromEntity(...), $this->service->getForOwner($user));
        }

        $category = $this->findOwned($user, $uriVariables['id'] ?? null);

        return null === $category ? null : ProtoDtoExpenseCategory::fromEntity($category);
    }

    public function findOwned(User $user, mixed $id): ?ExpenseCategory
    {
        if (\is_string($id)) {
            if (!Uuid::isValid($id)) {
                return null;
            }
            $id = Uuid::fromString($id);
        }

        $category = $id instanceof Uuid ? $this->repository->find($id) : null;

        return null !== $category && $category->isOwnedBy($user) ? $category : null;
    }
}
