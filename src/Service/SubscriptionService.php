<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use Symfony\Component\Security\Core\User\UserInterface;

class SubscriptionService
{
    public function __construct(
        private SubscriptionRepository $subscriptionRepository,
    ) {
    }

    public function add(Subscription $subscription): void
    {
        $this->canAddNewSubscription($subscription->getOwner());

        $this->subscriptionRepository->save($subscription);
    }

    public function canAddNewSubscription(UserInterface $user): void
    {
        $currentSubscriptionCount = $this->subscriptionRepository->count(['owner' => $user]);

        if ($currentSubscriptionCount >= 30) {
            throw new \LogicException('You have reached the maximum number of subscriptions.');
        }
    }

    public function isAbleToAddSubscription(UserInterface $user): bool
    {
        try {
            $this->canAddNewSubscription($user);

            return true;
        } catch (\LogicException) {
            return false;
        }
    }
}
