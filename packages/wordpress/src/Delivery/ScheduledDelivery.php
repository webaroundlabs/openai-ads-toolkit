<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Delivery;

use WebaroundLabs\OpenAIAds\Event;

/**
 * Hands a batch to Action Scheduler so it is delivered in a later request.
 *
 * Action Scheduler is a background job queue that ships inside WooCommerce, so
 * on a WooCommerce site it is already present and already running. This class
 * never bundles or registers a copy of it - two copies in one site conflict -
 * and every entry point is guarded by a function_exists check, so a site without
 * WooCommerce simply keeps the in-request shutdown flush.
 *
 * What this buys: the batch survives the request that created it. A fatal error
 * after the conversion but before shutdown no longer loses it.
 *
 * What this deliberately does NOT buy: retries. The batch is claimed - removed
 * from storage - before the send is attempted, so a failure loses it rather than
 * repeating it. That is the same position the core takes: repeating a request
 * whose outcome is unknown risks reporting a conversion twice, and silently
 * inflated conversion data is worse than a missing event, because it corrupts
 * the advertiser's optimization invisibly and cannot be undone afterwards.
 *
 * Action Scheduler stores its arguments as JSON, so the events themselves cannot
 * travel in them. They are stored in a dedicated option and only the key is
 * passed along.
 */
class ScheduledDelivery
{
    public const HOOK = 'openai_ads_deliver_batch';

    public const OPTION_PREFIX = 'openai_ads_batch_';

    /**
     * Batches older than this are dropped unsent.
     *
     * The API refuses events older than seven days, and a batch fails as a
     * whole, so a stale one would take every event with it. This mostly matters
     * on a site with DISABLE_WP_CRON and no real cron, where queued actions can
     * sit unprocessed indefinitely.
     */
    private const MAX_AGE_SECONDS = 6 * 86400;

    /** Whether this site can defer delivery at all. */
    public function isAvailable(): bool
    {
        return function_exists('as_enqueue_async_action');
    }

    /**
     * Store the batch and queue it for a later request.
     *
     * @param list<Event> $events
     *
     * @return bool false when the batch could not be handed off, in which case
     *              the caller should send it inline instead
     */
    public function enqueue(array $events, bool $validateOnly): bool
    {
        if (!$this->isAvailable() || $events === []) {
            return false;
        }

        $key = self::OPTION_PREFIX . \wp_generate_uuid4();

        $stored = \add_option($key, [
            'events' => $events,
            'validate_only' => $validateOnly,
            'created' => time(),
        ], '', false);

        if ($stored === false) {
            return false;
        }

        // Async rather than scheduled: run at the next opportunity, which on a
        // site with a working cron is seconds away.
        \as_enqueue_async_action(self::HOOK, [$key], 'openai-ads');

        return true;
    }

    /**
     * Claim a stored batch.
     *
     * Deletes before returning, deliberately. If Action Scheduler reclaims a
     * timed-out action and runs it again, there is nothing left to send a second
     * time - at-most-once, which is the safe direction for conversion data.
     *
     * @return array{events: list<Event>, validate_only: bool}|null
     */
    public function claim(string $key): ?array
    {
        if (!str_starts_with($key, self::OPTION_PREFIX)) {
            return null;
        }

        $stored = \get_option($key);
        \delete_option($key);

        if (!is_array($stored) || !isset($stored['events']) || !is_array($stored['events'])) {
            return null;
        }

        $created = isset($stored['created']) ? (int) $stored['created'] : 0;

        if ($created > 0 && (time() - $created) > self::MAX_AGE_SECONDS) {
            return null;
        }

        $events = [];

        foreach ($stored['events'] as $event) {
            // A plugin update that renamed or removed a class leaves
            // __PHP_Incomplete_Class here. Dropping those is the only safe
            // response; sending them is impossible and throwing would fail the
            // scheduled action for no benefit.
            if ($event instanceof Event) {
                $events[] = $event;
            }
        }

        if ($events === []) {
            return null;
        }

        return ['events' => $events, 'validate_only' => (bool) ($stored['validate_only'] ?? false)];
    }
}
