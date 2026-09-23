<?php

declare(strict_types=1);

namespace App\Tests\Double;

use ReCaptcha\ReCaptcha;
use ReCaptcha\Response;

/**
 * Deterministic stand-in for the Google reCAPTCHA client.
 *
 * The real client performs an HTTP call to google.com, which behavioral tests
 * must never depend on. Install per-test through the test container before
 * the request is made:
 *
 *     static::getContainer()->set(ReCaptcha::class, new FakeReCaptcha(true));
 *
 * It has to be set before the first request of the test, while the
 * ContactController service is still uninstantiated, so constructor injection
 * picks the fake up.
 */
final class FakeReCaptcha extends ReCaptcha
{
    public function __construct(private readonly bool $success = true)
    {
        parent::__construct('fake-secret-for-tests');
    }

    public function verify($response, $remoteIp = null): Response
    {
        return new Response($this->success, $this->success ? [] : ['fake-recaptcha-failure']);
    }
}
