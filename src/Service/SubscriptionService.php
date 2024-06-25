<?php

declare(strict_types=1);

namespace App\Service;

class SubscriptionService
{
    public function __construct(
        private SubscriptionRepository $subscriptionRepository,

    ) {
    }

    public function getAll(): array
    {
    }
}
