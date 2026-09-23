<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Service\SubscriptionService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class SubscriptionsTable
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly Security $security,
        private readonly SortableColumn $sortableColumn,
    ) {
    }

    public function getSubscriptions(): array
    {
        return $this->subscriptionService->get(
            $this->security->getUser(),
            $this->sortableColumn->sort(),
            $this->sortableColumn->order(),
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
}
