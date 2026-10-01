<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        // Intentionally empty: everything that distinguishes account states
        // (e.g. "not verified") must run only after the password is proven
        // correct in checkPostAuth. Otherwise a wrong password would answer
        // differently for unverified accounts than for verified or missing
        // ones, letting anyone probe which emails are registered.
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isVerified()) {
            // A stable key plus the address as data: the login template
            // renders the (escaped) message and points at its own POST
            // resend form, so no HTML travels inside the exception.
            throw new CustomUserMessageAccountStatusException(
                'user.account_not_verified',
                ['email' => $user->getEmail()]
            );
        }
    }
}
