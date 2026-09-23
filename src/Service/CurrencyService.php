<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Intl\Currencies;

/**
 * Closed ISO 4217 currency list for User main currencies and
 * Subscription currencies. Automatic FX conversion is out of scope
 * (see #31): cross-currency amounts are converted manually by the User.
 */
final class CurrencyService
{
    public static function isValidCode(?string $code): bool
    {
        $normalized = self::normalizeCode($code);

        return $normalized !== null && Currencies::exists($normalized);
    }

    public static function normalizeCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $normalized = strtoupper(trim($code));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, string> Labels keyed by code, for choice fields.
     */
    public function getChoices(): array
    {
        $choices = [];

        foreach (Currencies::getCurrencyCodes() as $code) {
            $name = Currencies::getName($code, 'en');
            $choices[$code] = $name !== null ? sprintf('%s — %s', $code, $name) : $code;
        }

        ksort($choices);

        // ChoiceType expects [label => value].
        $flipped = [];
        foreach ($choices as $code => $label) {
            $flipped[$label] = $code;
        }

        return $flipped;
    }
}
