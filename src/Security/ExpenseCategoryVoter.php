<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ExpenseCategory;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants access to an expense category only to its owning User.
 *
 * @extends Voter<string, ExpenseCategory>
 */
class ExpenseCategoryVoter extends Voter
{
    public const VIEW = 'CATEGORY_VIEW';
    public const EDIT = 'CATEGORY_EDIT';
    public const DELETE = 'CATEGORY_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof ExpenseCategory
            && \in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        \assert($subject instanceof ExpenseCategory);

        return $subject->isOwnedBy($user);
    }
}
