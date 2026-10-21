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
 * PROTOTYPE (#246) variant A: writes on the entity resource go through
 * ExpenseCategoryService instead of API Platform's Doctrine processors.
 *
 * @implements ProcessorInterface<ExpenseCategory, ExpenseCategory|null>
 */
final class EntityCategoryProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ExpenseCategoryService $service,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ExpenseCategory
    {
        \assert($data instanceof ExpenseCategory);

        if ($operation instanceof DeleteOperationInterface) {
            try {
                $this->service->delete($data);
            } catch (\LogicException $exception) {
                throw new CategoryInUseProblem($exception->getMessage(), $exception);
            }

            return null;
        }

        try {
            if ($operation instanceof Post) {
                $user = $this->security->getUser();
                \assert($user instanceof User);

                // $data is a detached, ownerless entity built by the
                // deserializer; the service builds the real one.
                return $this->service->create($user, (string) $data->getName(), (string) $data->getColor());
            }

            // PATCH: $data is the managed entity, already mutated by the
            // deserializer and validated.
            $this->service->update($data, (string) $data->getName(), (string) $data->getColor());

            return $data;
        } catch (DuplicateCategoryNameException $exception) {
            throw Violations::duplicateName($exception, $data, (string) $data->getName());
        }
    }
}
