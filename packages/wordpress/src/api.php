<?php

declare(strict_types=1);

/**
 * The plugin's public API.
 *
 * Deliberately plain functions: a WordPress integrator should not have to
 * resolve a container or know a namespace to record a conversion.
 *
 * @package WebaroundLabs\OpenAIAds\WordPress
 */

use WebaroundLabs\OpenAIAds\WordPress\Plugin;

if (!function_exists('openai_ads_track')) {
    /**
     * Record a conversion.
     *
     * Call at a CONFIRMED boundary - after the order is paid, after the lead is
     * accepted - never on a button click, unless the click itself is the
     * conversion.
     *
     * Never throws. A measurement problem must not break whatever produced the
     * conversion, so this returns false and, with debug logging on, explains why
     * in the PHP error log.
     *
     *     openai_ads_track( 'lead_created', [], [
     *         'event_id' => $lead_id,          // share this with the browser
     *         'user'     => [ 'email' => $email ],
     *     ] );
     *
     * @param string               $event_name One of the documented OpenAI Ads events.
     * @param array<string, mixed> $data       amount (minor units, integer), currency, ...
     * @param array<string, mixed> $options    event_id, custom_event_name, opt_out, user,
     *                                         action_source, source_url, timestamp_ms.
     */
    function openai_ads_track(string $event_name, array $data = [], array $options = []): bool
    {
        $plugin = Plugin::instance();

        return $plugin !== null && $plugin->track($event_name, $data, $options);
    }
}

if (!function_exists('openai_ads_event_id')) {
    /**
     * A deduplication id for one logical conversion.
     *
     * Prefer an id the site already owns - an order number, a lead id - and pass
     * that instead. This is the fallback for flows with no stable id: generate
     * once, use for BOTH the server event and the browser event, and never
     * regenerate it for a retry.
     */
    function openai_ads_event_id(): string
    {
        return wp_generate_uuid4();
    }
}

if (!function_exists('openai_ads_pixel_event')) {
    /**
     * Print the browser half of a conversion the server has just recorded.
     *
     * The deduplication bridge: same Pixel ID, same event name, same event id on
     * both sides, so OpenAI matches them instead of counting two conversions.
     *
     * @param array<string, mixed> $data
     */
    function openai_ads_pixel_event(
        string $event_name,
        string $event_id,
        array $data = [],
        ?string $custom_event_name = null
    ): void {
        Plugin::instance()?->pixel()->renderEvent($event_name, $event_id, $data, $custom_event_name);
    }
}

if (!function_exists('openai_ads_is_configured')) {
    /**
     * Whether server-side measurement is switched on and has credentials.
     */
    function openai_ads_is_configured(): bool
    {
        $plugin = Plugin::instance();

        return $plugin !== null && $plugin->settings()->capiEnabled();
    }
}
