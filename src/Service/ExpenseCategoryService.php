<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ExpenseCategory;
use App\Entity\Subscription;
use App\Entity\User;
use App\Exception\DuplicateCategoryNameException;
use App\Repository\ExpenseCategoryRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
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
     * Read-only counterpart of ensureDefaultCategory(): the owner's
     * `Subscriptions` category if it still exists under that name, otherwise
     * their alphabetically-first category, or null when the owner has none
     * yet. Never persists anything, so it is safe to call while rendering a
     * GET response (link prefetchers and crawlers must not create rows).
     */
    public function findDefaultCategory(User $owner): ?ExpenseCategory
    {
        return $this->categoryRepository->findOneBy(['owner' => $owner, 'name' => ExpenseCategory::DEFAULT_NAME])
            ?? $this->categoryRepository->findOneBy(['owner' => $owner], ['name' => 'ASC']);
    }

    /**
     * Returns a category to pre-select for a new Subscription: the owner's
     * `Subscriptions` category if it still exists under that name, otherwise
     * their alphabetically-first category, or a freshly created
     * `Subscriptions` default when the owner has none yet. Once an owner has
     * at least one category, this never creates another one, so renaming or
     * deleting a category (including the original default) never causes it
     * to reappear — it only recreates the default once the owner is back
     * down to zero categories.
     *
     * Concurrent first-category creations for the same owner are serialized
     * on the owner row: the loser blocks on the lock until the winner
     * commits, then sees the winner's row on the in-transaction re-check
     * instead of violating the unique constraint. Recovering *after* a
     * violation is not an option — a failed flush closes the whole
     * EntityManager, which would break every later write in the request.
     *
     * This persists, so call it only on a write path (form submission,
     * registration, API write) — never while rendering a GET response. Use
     * findDefaultCategory() for pre-selecting without side effects.
     */
    public function ensureDefaultCategory(User $owner): ExpenseCategory
    {
        $default = $this->findDefaultCategory($owner);

        if (null !== $default) {
            return $default;
        }

        if (null === $owner->getId()) {
            // The owner itself is not persisted yet, so no concurrent request
            // can reference the same (owner, name): insert directly.
            return $this->insertCategory($owner, ExpenseCategory::DEFAULT_NAME, ExpenseCategory::DEFAULT_COLOR);
        }

        return $this->entityManager->wrapInTransaction(function () use ($owner): ExpenseCategory {
            $this->lockOwner($owner);

            // Re-check under the lock: a concurrent creator may have
            // committed while this request waited for it.
            return $this->findDefaultCategory($owner)
                ?? $this->insertCategory($owner, ExpenseCategory::DEFAULT_NAME, ExpenseCategory::DEFAULT_COLOR);
        });
    }

    /**
     * @throws DuplicateCategoryNameException when the name is already taken —
     *                                        either by an earlier row or by a
     *                                        concurrent request that committed
     *                                        while waiting for the owner lock
     *                                        (UniqueEntity is check-then-
     *                                        insert, so callers must handle
     *                                        this, not 500).
     */
    public function create(User $owner, string $name, string $color): ExpenseCategory
    {
        if (null === $owner->getId()) {
            return $this->insertCategory($owner, $name, $color);
        }

        try {
            $category = $this->entityManager->wrapInTransaction(function () use ($owner, $name, $color): ?ExpenseCategory {
                $this->lockOwner($owner);

                if (null !== $this->categoryRepository->findOneBy(['owner' => $owner, 'name' => $name])) {
                    // Taken (either before this request or committed by a
                    // concurrent one while waiting for the lock): report
                    // without inserting. Returning a sentinel instead of
                    // throwing here matters: wrapInTransaction() closes the
                    // EntityManager whenever the closure throws, even for an
                    // expected business outcome.
                    return null;
                }

                return $this->insertCategory($owner, $name, $color);
            });

            if (null === $category) {
                throw $this->duplicateName($name);
            }

            return $category;
        } catch (UniqueConstraintViolationException $exception) {
            // Last resort for a violation the lock could not prevent (e.g. a
            // caller-managed transaction with an already-stale snapshot): a
            // failed flush closes the EntityManager, so translate without
            // touching managed state and let the caller answer 422/409.
            throw $this->duplicateName($name, $exception);
        }
    }

    /**
     * Single construction site for the taken-name error so the wording stays
     * identical across create/rename/update (web form error and API conflict
     * payload both derive from this message).
     */
    private function duplicateName(string $name, ?\Throwable $previous = null): DuplicateCategoryNameException
    {
        return new DuplicateCategoryNameException(
            sprintf('You already have a category with name "%s".', $name),
            0,
            $previous,
        );
    }

    /**
     * Serializes concurrent category writers for one owner on the owner row,
     * mirroring the subscription-limit gate in SubscriptionService::add().
     * Uses lock() rather than a locking find() so the managed instance — and
     * any of its pending in-memory changes — stays in place instead of being
     * re-read from the row. Nest-safe: re-locking an already-held row inside
     * the same transaction is a no-op.
     */
    private function lockOwner(User $owner): void
    {
        if ($this->entityManager->contains($owner)) {
            $this->entityManager->lock($owner, LockMode::PESSIMISTIC_WRITE);
        } else {
            $this->entityManager->find(User::class, $owner->getId(), LockMode::PESSIMISTIC_WRITE);
        }
    }

    /**
     * Builds, links, and flushes one new category. Only call after holding
     * the owner lock (or for an unpersisted owner no concurrent request can
     * race with).
     */
    private function insertCategory(User $owner, string $name, string $color): ExpenseCategory
    {
        $category = (new ExpenseCategory())
            ->setName($name)
            ->setColor($color);
        $owner->addExpenseCategory($category);
        $this->categoryRepository->save($category);

        return $category;
    }

    /**
     * @throws DuplicateCategoryNameException when a concurrent rename takes
     *                                        the same name before this flush.
     */
    public function rename(ExpenseCategory $category, string $name): void
    {
        $category->setName($name);

        try {
            $this->categoryRepository->save($category);
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->duplicateName($name, $exception);
        }
    }

    public function recolor(ExpenseCategory $category, string $color): void
    {
        $category->setColor($color);
        $this->categoryRepository->save($category);
    }

    /**
     * Applies a full name/color update in one flush. The web edit form saves
     * name and color through rename()/recolor(); the API PATCH path uses this
     * so both stay on the same service rule.
     *
     * @throws DuplicateCategoryNameException when a concurrent change takes
     *                                        the same name before this flush.
     */
    public function update(ExpenseCategory $category, string $name, string $color): void
    {
        $category->setName($name);
        $category->setColor($color);

        try {
            $this->categoryRepository->save($category);
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->duplicateName($name, $exception);
        }
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
