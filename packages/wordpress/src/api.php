<?php

declare(strict_types=1);

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

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
        ?string $custom_event_name = null,
    ): void {
        Plugin::instance()?->pixel()->renderEvent($event_name, $event_id, $data, $custom_event_name);
    }
}

if (!function_exists('openai_ads_hash_user')) {
    /**
     * Normalize and hash raw identity into the shape the browser Pixel wants.
     *
     * Use it when you render the Pixel yourself, or pass identity to a script.
     * The point is that the raw value never leaves the server:
     *
     *     $user = openai_ads_hash_user( [ 'email' => $customer->email ] );
     *     // [ 'email_sha256' => '...' ]
     *
     * Fields the API cannot use are dropped rather than throwing. Returns an
     * empty array when nothing usable was supplied.
     *
     * @param array<string, mixed> $user email, phone, external_id, first_name,
     *                                   last_name, country, city, region, postal_code
     *
     * @return array<string, string>
     */
    function openai_ads_hash_user(array $user): array
    {
        return \WebaroundLabs\OpenAIAds\WordPress\Identity::forPixel($user);
    }
}

if (!function_exists('openai_ads_image_tag')) {
    /**
     * Print an image-tag conversion: a 1x1 <img> that measures without JavaScript.
     *
     * For the places a script cannot go - an email body, an AMP page, a
     * <noscript> fallback. It carries the same event id as the server event, so
     * the two deduplicate exactly as a Pixel event would.
     *
     * It cannot carry identity: OpenAI documents no user object for this channel
     * and forbids personal data in a query parameter. Anything you pass under
     * `user` is used for the SERVER event only, never for this URL.
     *
     * Prints nothing when the Pixel is off, consent was refused, or the event
     * could not be built.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    function openai_ads_image_tag(string $event_name, array $data = [], array $options = []): void
    {
        $url = openai_ads_image_tag_url($event_name, $data, $options);

        if ($url === null) {
            return;
        }

        printf(
            '<img src="%s" width="1" height="1" alt="" style="display:none" />',
            esc_url($url),
        );
    }
}

if (!function_exists('openai_ads_image_tag_url')) {
    /**
     * The image tag's URL, for callers that render the element themselves.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    function openai_ads_image_tag_url(string $event_name, array $data = [], array $options = []): ?string
    {
        $plugin = Plugin::instance();

        return $plugin?->imageTagUrl($event_name, $data, $options);
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
