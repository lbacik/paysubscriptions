<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\ExpenseCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\UserInterface;

class ExpenseCategoryService
{
    public function __construct(
        private readonly ExpenseCategoryRepository $categoryRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<ExpenseCategory>
     */
    public function getForOwner(UserInterface $owner): array
    {
        return $this->categoryRepository->findBy(['owner' => $owner], ['name' => 'ASC']);
    }

    /**
     * Returns a category to pre-select for a new Subscription: the owner's
     * first existing category, or a freshly created `Subscriptions` default
     * when the owner has none yet. Once an owner has at least one category,
     * this never creates another one, so renaming or deleting a category
     * (including the original default) never causes it to reappear.
     *
     * Also assigns the returned category to any of the owner's Subscriptions
     * that have none, so callers never leave a Subscription uncategorized.
     */
    public function ensureDefaultCategory(User $owner): ExpenseCategory
    {
        $default = $this->categoryRepository->findOneBy(['owner' => $owner], ['name' => 'ASC']);

        if (null === $default) {
            $default = (new ExpenseCategory())
                ->setName(ExpenseCategory::DEFAULT_NAME)
                ->setColor(ExpenseCategory::DEFAULT_COLOR);
            $owner->addExpenseCategory($default);
            $this->categoryRepository->save($default);
        }

        foreach ($owner->getSubscriptions() as $subscription) {
            if (null === $subscription->getCategory()) {
                $subscription->setCategory($default);
            }
        }

        return $default;
    }

    public function create(User $owner, string $name, string $color): ExpenseCategory
    {
        $category = (new ExpenseCategory())
            ->setName($name)
            ->setColor($color);
        $owner->addExpenseCategory($category);
        $this->categoryRepository->save($category);

        return $category;
    }

    public function rename(ExpenseCategory $category, string $name): void
    {
        $category->setName($name);
        $this->categoryRepository->save($category);
    }

    public function recolor(ExpenseCategory $category, string $color): void
    {
        $category->setColor($color);
        $this->categoryRepository->save($category);
    }

    /**
     * Deletes a category that is not in use. A category with Subscriptions
     * assigned is never reassigned silently — the caller must reassign those
     * Subscriptions first.
     *
     * Usage is read from the database (plus any unflushed in-memory
     * assignments), never from the inverse-side collection alone, which may
     * be stale when Subscriptions were linked via their owning side.
     *
     * @throws \LogicException when the category still has Subscriptions assigned
     */
    public function delete(ExpenseCategory $category): void
    {
        // NOTE: the category id is bound explicitly with its UUID type.
        // Binding the entity itself lets Doctrine infer a string binding,
        // which never matches the BINARY(16) column on MySQL.
        $persistedUsage = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Subscription::class, 's')
            ->where('s.category = :categoryId')
            ->setParameter('categoryId', $category->getId(), UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();

        $usageCount = max($persistedUsage, $category->getSubscriptions()->count());

        if ($usageCount > 0) {
            throw new \LogicException(sprintf(
                'Category "%s" cannot be deleted because it still has %d subscription(s) assigned. Reassign them first.',
                $category->getName(),
                $usageCount,
            ));
        }

        $this->categoryRepository->remove($category);
    }
}
