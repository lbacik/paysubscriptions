<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CurrencyService;
use PHPUnit\Framework\TestCase;

final class CurrencyServiceTest extends TestCase
{
    public function testWellKnownIsoCodesAreValid(): void
    {
        foreach (['USD', 'EUR', 'PLN', 'GBP', 'JPY', 'CHF'] as $code) {
            self::assertTrue(CurrencyService::isValidCode($code), $code.' should be a valid ISO 4217 code');
        }
    }

    public function testFreeTextCodesAreRejected(): void
    {
        foreach (['XX1', 'FOO', 'US', 'USDD', 'EURO', '12', '', '   '] as $code) {
            self::assertFalse(CurrencyService::isValidCode($code), var_export($code, true).' should be rejected');
        }

        self::assertFalse(CurrencyService::isValidCode(null));
    }

    public function testCodesAreNormalizedToUppercase(): void
    {
        self::assertTrue(CurrencyService::isValidCode('usd'));
        self::assertSame('USD', CurrencyService::normalizeCode('usd'));
        self::assertSame('EUR', CurrencyService::normalizeCode(' eur '));
        self::assertNull(CurrencyService::normalizeCode(null));
        self::assertNull(CurrencyService::normalizeCode(''));
    }

    public function testChoicesCoverTheClosedIsoList(): void
    {
        $service = new CurrencyService();
        $choices = $service->getChoices();

        self::assertGreaterThan(100, \count($choices));
        self::assertArrayHasKey('USD', array_flip($choices));
        self::assertArrayHasKey('EUR', array_flip($choices));
        self::assertArrayHasKey('PLN', array_flip($choices));

        foreach ($choices as $code) {
            self::assertTrue(CurrencyService::isValidCode($code), $code.' from choices should be valid');
        }
    }
}
