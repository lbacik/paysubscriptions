<?php

declare(strict_types=1);

namespace App\Prototype;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ExpenseCategory;
use App\Entity\User;
use App\Exception\DuplicateCategoryNameException;
use App\Service\ExpenseCategoryService;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * PROTOTYPE (#246) variant C: receives the entity already mapped from the
 * DTO by ObjectMapperInputProcessor; the output processor maps the returned
 * entity back to the DTO.
 *
 * @implements ProcessorInterface<ExpenseCategory, ExpenseCategory|null>
 */
final class MappedCategoryProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ExpenseCategoryService $service,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ExpenseCategory
    {
        if ($operation instanceof DeleteOperationInterface) {
            // Delete skips the input mapper: $data is the DTO, the entity
            // the provider read is kept by the ObjectMapperProvider.
            $category = $context['request']?->attributes->get('mapped_data');
            \assert($category instanceof ExpenseCategory);

            try {
                $this->service->delete($category);
            } catch (\LogicException $exception) {
                throw new CategoryInUseProblem($exception->getMessage(), $exception);
            }

            return null;
        }

        \assert($data instanceof ExpenseCategory);

        try {
            if ($operation instanceof Post) {
                $user = $this->security->getUser();
                \assert($user instanceof User);

                return $this->service->create($user, (string) $data->getName(), (string) $data->getColor());
            }

            $this->service->update($data, (string) $data->getName(), (string) $data->getColor());

            return $data;
        } catch (DuplicateCategoryNameException $exception) {
            throw Violations::duplicateName($exception, $data, (string) $data->getName());
        }
    }
}
