<?php

declare(strict_types=1);

namespace App\RateLimiter;

use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Shared consume-and-reject helper for the public throttled endpoints
 * (issue #143).
 *
 * Each endpoint pairs its own windows (per-client, per-address, per-IP) with
 * its own rejection signaling (a 429 response vs. an exception), but the
 * consume-every-window-and-stop-at-the-first-rejection shape is identical.
 * Callers pass ordered [factory, key] pairs and get back the retry-after
 * delay of the first exhausted window, or null when every window accepts.
 */
final class RateLimitGuard
{
    /**
     * @param list<array{RateLimiterFactory, string}> $windows
     *
     * @return ?int retry-after seconds for the first rejected window, or null
     *             when every window accepts the request
     */
    public function retryAfterSeconds(array $windows): ?int
    {
        foreach ($windows as [$factory, $key]) {
            $limit = $factory->create($key)->consume();

            if (!$limit->isAccepted()) {
                return max(1, $limit->getRetryAfter()->getTimestamp() - time());
            }
        }

        return null;
    }
}
