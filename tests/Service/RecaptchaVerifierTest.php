<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\RecaptchaVerifier;
use App\Tests\Double\FakeRecaptchaRequestMethod;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Verification goes through FakeRecaptchaRequestMethod, so no test contacts
 * Google; the logger is observed through a Monolog TestHandler.
 */
final class RecaptchaVerifierTest extends TestCase
{
    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logs = new TestHandler();
    }

    public function testAcceptedTokenLogsNothing(): void
    {
        self::assertTrue($this->verifier()->verify(FakeRecaptchaRequestMethod::VALID_TOKEN));
        self::assertSame([], $this->logs->getRecords());
    }

    public function testRejectedTokenLogsGoogleErrorCodes(): void
    {
        self::assertFalse($this->verifier()->verify('stale-token'));

        self::assertTrue($this->logs->hasRecordThatPasses(
            static fn ($record): bool => 'reCAPTCHA verification rejected.' === $record->message
                && ['invalid-input-response'] === $record->context['error_codes'],
            Level::Info,
        ));
    }

    private function verifier(): RecaptchaVerifier
    {
        return new RecaptchaVerifier('test-secret', new FakeRecaptchaRequestMethod(), new Logger('test', [$this->logs]));
    }
}
