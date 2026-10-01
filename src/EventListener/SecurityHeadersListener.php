<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Forbids framing the application, site-wide.
 *
 * Without `frame-ancestors 'none'` (or `X-Frame-Options: DENY`) any page —
 * notably the OAuth consent screen at /authorize — can be embedded in a
 * third-party iframe for clickjacking. Both headers are sent so older
 * browsers honor the equivalent; an explicitly set header is never
 * overwritten.
 */
final class SecurityHeadersListener
{
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;

        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        }

        if (!$headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'DENY');
        }
    }
}
