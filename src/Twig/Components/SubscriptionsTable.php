<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\ExpenseCategory;
use App\Entity\User;
use App\Service\ExpenseCategoryService;
use App\Service\SubscriptionListState;
use App\Service\SubscriptionService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class SubscriptionsTable
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly Security $security,
        private readonly SubscriptionListState $listState,
        private readonly ExpenseCategoryService $categoryService,
    ) {
    }

    public function getSubscriptions(): array
    {
        return $this->subscriptionService->get(
            $this->security->getUser(),
            $this->listState->sort(),
            $this->listState->order(),
            $this->listState->categoryId(),
            $this->getMainCurrency(),
        );
    }

    public function getTotal(): array
    {
        return $this->subscriptionService->getTotals($this->getSubscriptions(), $this->getMainCurrency());
    }

    public function getMainCurrency(): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof \App\Entity\User ? $user->getMainCurrency() : null;
    }

    public function getSort(): string
    {
        return $this->listState->sort();
    }

    public function getOrder(): string
    {
        return $this->listState->order();
    }

    /**
     * @return list<ExpenseCategory> The signed-in User's own categories, so a
     *                              foreign category can never be offered.
     */
    public function getCategories(): array
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->categoryService->getForOwner($user) : [];
    }

    /**
     * The selected category id, but only when it belongs to the signed-in
     * User: a forged foreign id filters to an empty list while the dropdown
     * keeps showing "All categories".
     */
    public function getSelectedCategoryId(): ?string
    {
        $selected = $this->listState->categoryId();

        if ($selected === null) {
            return null;
        }

        foreach ($this->getCategories() as $category) {
            if ((string) $category->getId() === $selected) {
                return $selected;
            }
        }

        return null;
    }

    public function isCategorySelected(?string $selected, ExpenseCategory $category): bool
    {
        return $selected !== null && (string) $category->getId() === $selected;
    }
}
