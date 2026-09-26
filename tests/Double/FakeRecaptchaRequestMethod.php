<?php

declare(strict_types=1);

namespace App\Tests\Double;

use ReCaptcha\RequestMethod;
use ReCaptcha\RequestParameters;

/**
 * Test-only reCAPTCHA transport (wired in config/packages/test/recaptcha.yaml).
 *
 * Answers verification without touching Google: the token `valid-test-token`
 * succeeds, every other token fails. Behavioral tests drive the contact
 * journey through the real RecaptchaVerifier deterministically and offline.
 */
final class FakeRecaptchaRequestMethod implements RequestMethod
{
    public const VALID_TOKEN = 'valid-test-token';

    public function submit(RequestParameters $params)
    {
        $response = $params->toArray()['response'] ?? null;

        if (self::VALID_TOKEN === $response) {
            return json_encode(['success' => true]);
        }

        return json_encode(['success' => false, 'error-codes' => ['invalid-input-response']]);
    }
}
