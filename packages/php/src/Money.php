<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds;

/**
 * A monetary value in the currency's minor unit.
 *
 * This type exists to delete two recurring bugs rather than to model money:
 *
 * 1. The Conversions API requires `currency` whenever `amount` is present.
 *    Pairing them here makes "amount without currency" unconstructible, so the
 *    check does not need to exist anywhere else.
 * 2. `amount` is an integer in the minor unit - 1299, not 12.99. Under
 *    strict_types, `Money::minor(12.99, 'EUR')` is a TypeError at the call
 *    site, which is the earliest and clearest place to catch what is the single
 *    most common mistake made against any conversions API.
 *
 * Amounts are 64-bit on any supported platform, so large JPY or IDR orders are
 * safe. A 32-bit PHP build is not supported.
 */
final class Money
{
    private function __construct(
        public readonly int $minorUnits,
        public readonly string $currency,
    ) {
    }

    /**
     * @param int    $minorUnits Cents, bani, pence - never a major-unit decimal.
     * @param string $currency   ISO 4217 alpha-3. Accepted in any case, emitted uppercase.
     *
     * @throws InvalidArgument when the currency code is not three letters
     */
    public static function minor(int $minorUnits, string $currency): self
    {
        $normalized = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $normalized) !== 1) {
            throw new InvalidArgument(sprintf(
                'Currency must be an ISO 4217 alpha-3 code such as "EUR"; got "%s".',
                $currency,
            ));
        }

        return new self($minorUnits, $normalized);
    }

    /**
     * Negative amounts are permitted. OpenAI documents no rule about refunds or
     * negative values, and inventing one would reject a payload the API may
     * well accept.
     */
    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }
}
