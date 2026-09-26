<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TimezoneService;
use PHPUnit\Framework\TestCase;

final class TimezoneServiceTest extends TestCase
{
    private TimezoneService $service;

    protected function setUp(): void
    {
        $this->service = new TimezoneService();
    }

    public function testValidIanaZoneIsKept(): void
    {
        self::assertSame('Europe/Warsaw', $this->service->normalize('Europe/Warsaw'));
    }

    public function testMissingBrowserZoneFallsBackToUtc(): void
    {
        self::assertSame('UTC', $this->service->normalize(null));
        self::assertSame('UTC', $this->service->normalize(''));
    }

    public function testInvalidBrowserZoneFallsBackToUtc(): void
    {
        self::assertSame('UTC', $this->service->normalize('Not/AZone'));
        self::assertSame('UTC', $this->service->normalize('GMT+2'));
    }

    public function testUtcIsValid(): void
    {
        self::assertTrue($this->service->isValid('UTC'));
        self::assertTrue($this->service->isValid('Europe/Warsaw'));
        self::assertFalse($this->service->isValid(null));
        self::assertFalse($this->service->isValid('Mars/Olympus'));
    }
}
