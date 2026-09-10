<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress;

use Nyholm\Psr7\Factory\Psr17Factory;
use WebaroundLabs\OpenAIAds\Capi\Client;
use WebaroundLabs\OpenAIAds\Capi\Response;
use WebaroundLabs\OpenAIAds\Capi\TransportException;
use WebaroundLabs\OpenAIAds\Clock;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\SystemClock;
use WebaroundLabs\OpenAIAds\WordPress\Delivery\ScheduledDelivery;
use WebaroundLabs\OpenAIAds\WordPress\Http\WpTransport;

/**
 * Collects events during a request and delivers them after the page has been
 * sent to the visitor.
 *
 * WordPress has no queue. Rather than invent one, events accumulate in memory
 * and flush on `shutdown`, after `fastcgi_finish_request()` where the server
 * supports it - so the visitor's browser is never waiting on an ad platform.
 * Batching falls out naturally, which matters because the API takes up to 1,000
 * events at once and fails a batch as a whole.
 *
 * Where the site has Action Scheduler - which every WooCommerce site does - the
 * batch is handed to it instead, so it survives this request ending rather than
 * being lost to a fatal error before shutdown. See ScheduledDelivery.
 *
 * Nothing retries in either mode, matching the core's position that repeating a
 * request whose outcome is unknown risks double-counting conversions.
 *
 * Nothing throws. A conversion that fails to report must never break the
 * checkout, form submission or registration that produced it.
 */
final class Measurement
{
    /** @var list<Event> */
    private array $pending = [];

    private ?Client $client = null;

    private bool $flushRegistered = false;

    public function __construct(
        private readonly Settings $settings,
        private readonly Clock $clock = new SystemClock(),
        private readonly ScheduledDelivery $scheduler = new ScheduledDelivery(),
    ) {
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    /**
     * Queue an event for delivery at the end of the request.
     *
     * The usual entry point. Returns false when nothing was queued - measurement
     * off, unconfigured, or consent refused.
     */
    public function record(Event $event): bool
    {
        if (!$this->allowed()) {
            return false;
        }

        /** @var Event|null $filtered */
        $filtered = \apply_filters('openai_ads_event', $event);

        if (!$filtered instanceof Event) {
            // A filter returned null or something else: treat it as a veto.
            return false;
        }

        $this->pending[] = $filtered;
        $this->registerFlush();

        return true;
    }

    /**
     * Deliver everything collected so far.
     *
     * Called on `shutdown`; safe to call directly when a caller needs the
     * response, for example the settings screen's connection test.
     */
    public function flush(): ?Response
    {
        if ($this->pending === []) {
            return null;
        }

        $events = $this->pending;
        $this->pending = [];
        $validateOnly = $this->settings->validateOnly();

        // Where the site has Action Scheduler - which every WooCommerce site
        // does - hand the batch over so it survives this request ending. There
        // is nothing to report back in that case.
        if ($this->settings->useScheduler() && $this->scheduler->enqueue($events, $validateOnly)) {
            return null;
        }

        return $this->deliver($events, $validateOnly);
    }

    /**
     * Deliver a batch that was queued in an earlier request.
     *
     * Called by Action Scheduler. The batch is claimed before the attempt, so a
     * reclaimed or repeated action finds nothing left to send twice.
     */
    public function deliverScheduledBatch(string $key): void
    {
        $batch = $this->scheduler->claim($key);

        if ($batch === null) {
            return;
        }

        $this->deliver($batch['events'], $batch['validate_only']);
    }

    /**
     * Send a batch immediately and hand back the response.
     *
     * Used by the connection test, which needs to report what happened.
     */
    public function sendNow(bool $validateOnly, Event ...$events): ?Response
    {
        return $events === [] ? null : $this->deliver($events, $validateOnly);
    }

    /**
     * Whether the visitor has consented to measurement.
     *
     * The plugin ships no consent banner and no privacy model of its own. It
     * asks, through a filter, and defaults to true so it does not silently
     * disable measurement on a site that gates consent elsewhere. Wire it up:
     *
     *     add_filter( 'openai_ads_consent', fn () => has_consent( 'marketing' ) );
     */
    public function consented(): bool
    {
        return (bool) \apply_filters('openai_ads_consent', true);
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    /**
     * @param list<Event> $events
     */
    private function deliver(array $events, bool $validateOnly): ?Response
    {
        try {
            $response = $this->client()->send($events, $validateOnly);
        } catch (InvalidArgument $e) {
            // Malformed, oversized, or aged past the API's 7-day window.
            $this->log('Events rejected before sending: ' . $e->getMessage());
            \do_action('openai_ads_failed', $e, $events);

            return null;
        } catch (TransportException $e) {
            $this->log('The Conversions API could not be reached: ' . $e->getMessage());
            \do_action('openai_ads_failed', $e, $events);

            return null;
        }

        if (!$response->isSuccessful()) {
            $this->log(sprintf('The API returned HTTP %d.', $response->statusCode));
        }

        \do_action('openai_ads_sent', $response, $events);

        return $response;
    }

    private function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $factory = new Psr17Factory();

        return $this->client = new Client(
            pixelId: (string) $this->settings->pixelId(),
            apiKey: (string) $this->settings->capiKey(),
            http: new WpTransport($factory, $this->settings->timeout()),
            requests: $factory,
            streams: $factory,
            clock: $this->clock,
            integrationSource: $this->settings->integrationSource(),
        );
    }

    private function allowed(): bool
    {
        if (!(bool) \apply_filters('openai_ads_enabled', true)) {
            return false;
        }

        if (!$this->settings->capiEnabled()) {
            return false;
        }

        // A refused consent is a normal outcome, so it is silent.
        return $this->consented();
    }

    /**
     * Flush after the response has gone out, not before.
     */
    private function registerFlush(): void
    {
        if ($this->flushRegistered) {
            return;
        }

        $this->flushRegistered = true;

        \add_action('shutdown', function (): void {
            // Close the connection first where the server allows it, so the
            // visitor is not waiting on an outbound HTTP call.
            if (function_exists('fastcgi_finish_request')) {
                @\fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                @\litespeed_finish_request();
            }

            $this->flush();
        }, PHP_INT_MAX);
    }

    private function log(string $message): void
    {
        if (!$this->settings->debug()) {
            return;
        }

        // Never logs a payload: it carries identity hashes and attribution.
        \error_log('[openai-ads] ' . $message);
    }
}
