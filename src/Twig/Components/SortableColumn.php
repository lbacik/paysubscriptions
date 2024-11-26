<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class SortableColumn
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function order(): string
    {
        $order = $this->requestStack->getCurrentRequest()->query->get('order');

        if ($order === null) {
            $order = $this->requestStack->getSession()->get('order', 'asc');
        }

        $this->requestStack->getSession()->set('order', $order);

        return $order;
    }

    public function nextOrder(string|null $order = null): string|null
    {
        return match($order) {
            'asc' => 'desc',
            'desc' => 'asc',
            default => 'asc',
        };
    }

    public function sort(): string
    {
        $sort = $this->requestStack->getCurrentRequest()->query->get('sort');

        if ($sort === null) {
            $sort = $this->requestStack->getSession()->get('sort', 'name');
        }

        $this->requestStack->getSession()->set('sort', $sort);

        return $sort;
    }
}
