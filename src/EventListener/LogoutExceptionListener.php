<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\LogoutException;

/**
 * Turns a failed logout (missing or invalid CSRF token) into a plain 403.
 *
 * The firewall throws LogoutException, which is not an HTTP exception, so
 * without this mapping a forged or token-less logout would surface as an
 * HTTP 500. The session is untouched: the User stays logged in.
 */
final class LogoutExceptionListener
{
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof LogoutException) {
            return;
        }

        $event->setResponse(new Response('Invalid logout request.', Response::HTTP_FORBIDDEN));
    }
}
