<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use ReCaptcha\ReCaptcha;
use ReCaptcha\RequestMethod;

/**
 * Fail-closed reCAPTCHA verification that never throws.
 *
 * The raw ReCaptcha client throws when no secret is configured (every visitor
 * of the contact page in dev/test, where the secret is empty) and fatals when
 * the configured HTTP transport needs a missing PHP extension. Both used to
 * surface as HTTP 500s. This wrapper builds the client lazily per verification
 * and converts every failure — missing secret, transport error, network
 * outage — into a logged `false`, so the caller can answer with a normal
 * failed-verification response instead of a 500.
 */
final class RecaptchaVerifier implements RecaptchaVerifierInterface
{
    public function __construct(
        private readonly string $recaptchaSecret,
        private readonly RequestMethod $requestMethod,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->recaptchaSecret);
    }

    public function verify(?string $response, ?string $remoteIp = null): bool
    {
        if (!$this->isConfigured()) {
            $this->logger->warning('reCAPTCHA verification failed: no secret configured.');

            return false;
        }

        try {
            $recaptcha = new ReCaptcha($this->recaptchaSecret, $this->requestMethod);

            return $recaptcha->verify($response, $remoteIp)->isSuccess();
        } catch (\Throwable $exception) {
            $this->logger->error('reCAPTCHA verification failed.', ['exception' => $exception]);

            return false;
        }
    }
}
