<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Seam for reCAPTCHA verification, so the contact journey stays testable
 * without Google credentials or network access.
 */
interface RecaptchaVerifierInterface
{
    public function isConfigured(): bool;

    public function verify(?string $response, ?string $remoteIp = null): bool;
}
