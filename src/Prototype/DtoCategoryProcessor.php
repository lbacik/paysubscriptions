<?php

declare(strict_types=1);

namespace App\Prototype;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ProtoDtoExpenseCategory;
use App\Entity\User;
use App\Exception\DuplicateCategoryNameException;
use App\Service\ExpenseCategoryService;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * PROTOTYPE (#246) variant B: DTO in, service call, DTO out.
 *
 * @implements ProcessorInterface<ProtoDtoExpenseCategory, ProtoDtoExpenseCategory|null>
 */
final class DtoCategoryProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ExpenseCategoryService $service,
        private readonly DtoCategoryProvider $provider,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ProtoDtoExpenseCategory
    {
        \assert($data instanceof ProtoDtoExpenseCategory);
        $user = $this->security->getUser();
        \assert($user instanceof User);

        try {
            if ($operation instanceof Post) {
                return ProtoDtoExpenseCategory::fromEntity(
                    $this->service->create($user, (string) $data->name, (string) $data->color),
                );
            }

            // The provider already proved ownership; reload the entity the
            // DTO was built from.
            $category = $this->provider->findOwned($user, $uriVariables['id'] ?? null);
            \assert(null !== $category);

            if ($operation instanceof DeleteOperationInterface) {
                try {
                    $this->service->delete($category);
                } catch (\LogicException $exception) {
                    throw new CategoryInUseProblem($exception->getMessage(), $exception);
                }

                return null;
            }

            $this->service->update($category, (string) $data->name, (string) $data->color);

            return ProtoDtoExpenseCategory::fromEntity($category);
        } catch (DuplicateCategoryNameException $exception) {
            throw Violations::duplicateName($exception, $data, (string) $data->name);
        }
    }
}
