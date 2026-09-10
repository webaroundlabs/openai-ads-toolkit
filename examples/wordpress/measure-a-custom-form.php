<?php

/**
 * Measuring a form the toolkit does not ship an integration for.
 *
 * Drop this in a site-specific plugin. It shows the two rules that matter
 * whatever the form plugin is: fire only on confirmed success, and use one
 * event id on both sides.
 */

declare(strict_types=1);

/**
 * Record the lead when the submission has actually been accepted.
 *
 * Whatever hook you use, make sure it is the one that fires AFTER the
 * submission succeeded - not on a button click, and not before validation.
 */
add_action('my_form_submission_succeeded', function (array $submission): void {
    if (!function_exists('openai_ads_track')) {
        return;
    }

    // No business record exists for a form submission, so mint an id once and
    // remember it for the rest of this request. Never regenerate it: a second
    // id is a second conversion.
    $event_id = openai_ads_event_id();
    set_transient('openai_ads_last_form_event', $event_id, 5 * MINUTE_IN_SECONDS);

    openai_ads_track('lead_created', [], [
        'event_id' => $event_id,
        'user' => [
            // Raw values. The toolkit normalizes and hashes them before they
            // leave the server; never hash by hand, or the digests will not
            // match the ones the Pixel and other adapters produce.
            'email' => $submission['email'] ?? null,
            'phone' => $submission['phone'] ?? null,
        ],
    ]);
});

/**
 * Emit the browser half on the confirmation page, with the same id.
 */
add_action('wp_footer', function (): void {
    $event_id = get_transient('openai_ads_last_form_event');

    if (!$event_id || !function_exists('openai_ads_pixel_event')) {
        return;
    }

    delete_transient('openai_ads_last_form_event');

    openai_ads_pixel_event('lead_created', (string) $event_id);
});

/**
 * Gate everything behind the site's own consent mechanism.
 *
 * Returning false means nothing is sent and no Pixel is rendered - silently,
 * because a refused consent is a normal outcome and not an error.
 */
add_filter('openai_ads_consent', static function (): bool {
    return function_exists('my_consent_manager_allows')
        ? my_consent_manager_allows('marketing')
        : true;
});

/**
 * Map one particular form to different event semantics.
 */
add_filter('openai_ads_form_event', static function (string $event, string $form_id): string {
    return $form_id === '12' ? 'appointment_scheduled' : $event;
}, 10, 2);
