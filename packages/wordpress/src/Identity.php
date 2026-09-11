<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress;

use WebaroundLabs\OpenAIAds\UserData;

/**
 * Raw identity, hashed into the shape the browser Pixel wants.
 *
 * Singular keys and scalar values, unlike the Conversions API's plural arrays.
 * The field names are the documented ones, and the translation, normalization
 * and hashing all live in the core - this exists only so the Pixel renderer and
 * the plugin's public function do not each write the same three lines and then
 * drift.
 *
 * Never throws. A page must render even when a stored value turns out to be
 * unusable; the offending field is dropped and announced, and the rest still
 * matches.
 */
final class Identity
{
    /**
     * @param array<string, mixed> $raw email, phone, external_id, first_name,
     *                                  last_name, country, city, region, postal_code
     *
     * @return array<string, string>
     */
    public static function forPixel(array $raw): array
    {
        return UserData::fromUntrusted(
            $raw,
            static function (string $field, string $reason): void {
                // Neither argument contains the value; a listener must not log
                // one either.
                \do_action('openai_ads_identity_field_dropped', $field, $reason);
            },
        )->toPixelArray();
    }
}
