<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Opt-in rate-limit control for behavioral tests (issue #143).
 *
 * The test environment sizes every limiter burst generously (.env.test), so
 * the suite is never throttled unless a test explicitly pins small values
 * with pinRateLimitEnv() before the kernel boots — the same putenv +
 * $_SERVER + $_ENV pattern ContactFlowTest uses for reCAPTCHA secrets.
 * Limiter factories resolve `%env(int:…)%` when they are instantiated (lazily,
 * on first use), so pinning before createClient() is sufficient; nothing
 * needs rebuilding.
 */
trait RateLimitTestHelper
{
    /**
     * @var array<string, array{putenv: string|false, server: string|null, env: string|null}>
     */
    private array $rateLimitEnvBackup = [];

    /**
     * @param array<string, string> $vars
     */
    protected function pinRateLimitEnv(array $vars): void
    {
        foreach ($vars as $key => $value) {
            if (!\array_key_exists($key, $this->rateLimitEnvBackup)) {
                $this->rateLimitEnvBackup[$key] = [
                    'putenv' => getenv($key),
                    'server' => $_SERVER[$key] ?? null,
                    'env' => $_ENV[$key] ?? null,
                ];
            }

            putenv($key.'='.$value);
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
        }
    }

    protected function restoreRateLimitEnv(): void
    {
        foreach ($this->rateLimitEnvBackup as $key => $original) {
            if (false === $original['putenv']) {
                putenv($key);
            } else {
                putenv($key.'='.$original['putenv']);
            }

            if (null === $original['server']) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $original['server'];
            }

            if (null === $original['env']) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $original['env'];
            }
        }

        $this->rateLimitEnvBackup = [];
    }

    /**
     * Clears the stored hits for one limiter key, so the test starts from a
     * full burst even when a previous run consumed the same key inside the
     * current window. Prefer unique keys per run (uniqid emails, random
     * 10/8 IPs) where the key space allows it; reset where it does not.
     */
    protected function resetLimiter(string $limiterServiceId, string $key): void
    {
        $factory = static::getContainer()->get($limiterServiceId);
        \assert($factory instanceof RateLimiterFactory);

        $factory->create($key)->reset();
    }
}
