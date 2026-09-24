<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Service\SubscriptionListState;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class SortableColumn
{
    public function __construct(
        private readonly SubscriptionListState $listState,
    ) {
    }

    public function order(): string
    {
        return $this->listState->order();
    }

    public function nextOrder(string|null $order = null): string|null
    {
        return $this->listState->nextOrder($order);
    }

    public function sort(): string
    {
        return $this->listState->sort();
    }

    public function categoryId(): ?string
    {
        return $this->listState->categoryId();
    }
}
