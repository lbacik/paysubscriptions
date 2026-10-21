<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ExpenseCategory;
use App\Entity\User;
use App\Service\ExpenseCategoryService;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Writes API v1 ExpenseCategories through ExpenseCategoryService, so the API
 * keeps the web app's rules: per-User name uniqueness under the owner lock
 * and no delete while Subscriptions use the category.
 *
 * API Platform's object mapper has already mapped the validated DTO onto the
 * entity (a new one for POST, the scoped, managed one for PATCH) and maps the
 * returned entity back to the DTO. Domain exceptions propagate unchanged;
 * App\Api\DomainExceptionTranslator turns them into problems.
 *
 * @implements ProcessorInterface<ExpenseCategory, ExpenseCategory|null>
 */
final class ExpenseCategoryProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ExpenseCategoryService $categories,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ExpenseCategory
    {
        if ($operation instanceof DeleteOperationInterface) {
            // The entity the scoped provider read, kept by API Platform.
            $category = $context['request']?->attributes->get('mapped_data');
            \assert($category instanceof ExpenseCategory);

            $this->categories->delete($category);

            return null;
        }

        \assert($data instanceof ExpenseCategory);

        if ($operation instanceof Post) {
            $owner = $this->security->getUser();
            \assert($owner instanceof User);

            return $this->categories->create($owner, (string) $data->getName(), (string) $data->getColor());
        }

        $this->categories->update($data, (string) $data->getName(), (string) $data->getColor());

        return $data;
    }
}
