<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Integrations;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

use WebaroundLabs\OpenAIAds\Money;

/**
 * Converts a store's price into the minor unit the API expects.
 *
 * WooCommerce and Easy Digital Downloads both store totals as decimal strings
 * in the store currency - "12.99".
 * The API wants an integer in the currency's minor unit - 1299. How many minor
 * units make one major unit is a property of the CURRENCY, not of the store's
 * display settings: a shop showing zero decimals for euros still deals in cents,
 * and yen have no minor unit at all.
 *
 * Using the store's decimal-places setting would therefore be wrong in both
 * directions - it would send 13 for a 12.99 euro order on a shop configured to
 * display whole euros, and 129900 for a 1299 yen order.
 */
final class Amount
{
    /**
     * ISO 4217 currencies with no minor unit.
     *
     * @var list<string>
     */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * ISO 4217 currencies with three decimal places.
     *
     * @var list<string>
     */
    private const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    public static function exponentFor(string $currency): int
    {
        $currency = strtoupper(trim($currency));

        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return 0;
        }

        if (in_array($currency, self::THREE_DECIMAL, true)) {
            return 3;
        }

        return 2;
    }

    /**
     * @param string|float|int $amount a store total, in major units
     */
    public static function toMinorUnits(string|float|int $amount, string $currency): int
    {
        $factor = 10 ** self::exponentFor($currency);

        // round() before casting: (int) (0.29 * 100) is 28 in binary floating
        // point, which would under-report every order ending in those cents.
        return (int) round(((float) $amount) * $factor);
    }

    /**
     * Null rather than a zero Money for a free item, so the field is omitted
     * instead of reporting a conversion worth nothing.
     */
    public static function money(string|float|int $amount, string $currency): ?Money
    {
        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return null;
        }

        return Money::minor(self::toMinorUnits($amount, $currency), $currency);
    }
}
