<?php

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class SortableColumn
{
    public function order(string|null $order): string|null
    {
        return match($order) {
            'asc' => 'desc',
            'desc' => null,
            default => 'asc',
        };
    }
}
