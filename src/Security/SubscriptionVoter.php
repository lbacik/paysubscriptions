<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Subscription;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Only the owning User may open or mutate a Subscription.
 *
 * Denials surface as 403 through denyAccessUnlessGranted(), checked before
 * any form handling or mutation, so a cross-user request never renders the
 * record's details and never changes it.
 */
final class SubscriptionVoter extends Voter
{
    public const EDIT = 'SUBSCRIPTION_EDIT';
    public const DELETE = 'SUBSCRIPTION_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::DELETE], true)
            && $subject instanceof Subscription;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof UserInterface || !$subject instanceof Subscription) {
            return false;
        }

        $owner = $subject->getOwner();

        if (null === $owner) {
            return false;
        }

        // Compare identifiers rather than object identity: the authenticated
        // User and the entity owner may be different object instances.
        return $user->getUserIdentifier() === $owner->getUserIdentifier();
    }
}
