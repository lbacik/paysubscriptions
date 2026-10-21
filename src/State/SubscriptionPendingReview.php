<?php

declare(strict_types=1);

namespace App\State;

use App\Entity\Subscription as SubscriptionEntity;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\ObjectMapper\TransformCallableInterface;

/**
 * Computes the read-only pendingReview flag while mapping a Subscription
 * entity to its API v1 DTO. The flag depends on the current User's main
 * currency, which the entity does not know, so it cannot be a plain copy.
 *
 * @implements TransformCallableInterface<SubscriptionEntity, SubscriptionEntity>
 */
final class SubscriptionPendingReview implements TransformCallableInterface
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function __invoke(mixed $value, object $source, ?object $target): mixed
    {
        \assert($source instanceof SubscriptionEntity);

        $user = $this->security->getUser();
        $mainCurrency = $user instanceof User ? $user->getMainCurrency() : null;

        return $source->isPendingReview($mainCurrency);
    }
}
