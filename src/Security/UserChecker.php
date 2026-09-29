<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class UserChecker implements UserCheckerInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isVerified()) {
            // The resend endpoint only accepts POST with a CSRF token (issue
            // #143), so the recovery action renders as an inline form — not a
            // link — posting the address back with a fresh token. The login
            // template prints this message raw, like it did the old link.
            $resendUrl = htmlspecialchars(
                $this->urlGenerator->generate('resend_activation'),
                ENT_QUOTES
            );
            $email = htmlspecialchars($user->getEmail() ?? '', ENT_QUOTES);
            $token = htmlspecialchars(
                $this->csrfTokenManager->getToken('resend_activation')->getValue(),
                ENT_QUOTES
            );

            throw new CustomUserMessageAccountStatusException(
                'User account is not active. '
                .'<form class="inline" method="post" action="'.$resendUrl.'">'
                .'<input type="hidden" name="email" value="'.$email.'">'
                .'<input type="hidden" name="_token" value="'.$token.'">'
                .'<button type="submit" class="underline">Send activation email again</button>'
                .'</form>'
            );
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
