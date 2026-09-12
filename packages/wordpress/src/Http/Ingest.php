<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Http;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

use WebaroundLabs\OpenAIAds\HostContext;
use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\WordPress\Plugin;
use WebaroundLabs\OpenAIAds\WordPress\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A REST endpoint that accepts a conversion and forwards it to OpenAI.
 *
 * The reason this exists: Google Tag Manager's server-side tagging needs a
 * server container, and Google hosts those on Google Cloud - real money, every
 * month, for a site that already has a server. The common answer is to point a
 * web container at an endpoint on your own site and let that forward the event.
 * This is that endpoint, so it does not have to be written by hand on every
 * site, with the authentication left for later.
 *
 * What it buys, and what it does not. The API key stays server-side, and the
 * request to OpenAI leaves from your server, so no ad blocker sees it. But the
 * first hop - browser to here - is still a browser request and can still be
 * blocked, and if your server is down the event is gone. A real server container
 * runs on a subdomain of yours and retries. Those are the trade-offs; they are
 * usually worth it.
 *
 * SECURITY. An endpoint that forwards conversions is an endpoint that writes to
 * the advertiser's measurement data. Left open, anyone who finds it can inject
 * conversions that never happened - which does not steal money directly, but
 * poisons the numbers the bidding algorithm optimizes against, invisibly. So:
 *
 * - It is OFF until a site switches it on.
 * - Every request must carry the shared secret, compared in constant time.
 * - Requests are rate limited, because a secret can leak and a loop can run away.
 * - The secret is never rendered into a page, never logged, and never returned.
 *
 * The payload is the same shape `openai_ads_track()` takes, so anything you can
 * measure in PHP you can measure from here.
 */
final class Ingest
{
    public const NAMESPACE = 'openai-ads/v1';

    public const ROUTE = '/collect';

    public const SECRET_HEADER = 'X-OpenAI-Ads-Key';

    /** Requests allowed per minute, per IP. */
    private const RATE_LIMIT = 120;

    /** How many recent events the diagnostics screen remembers. */
    public const LOG_SIZE = 20;

    public const LOG_OPTION = 'openai_ads_ingest_log';

    public function __construct(
        private readonly Plugin $plugin,
        private readonly Settings $settings,
    ) {
    }

    public function register(): void
    {
        \add_action('rest_api_init', [$this, 'registerRoute']);
    }

    public function registerRoute(): void
    {
        if (!$this->settings->ingestEnabled()) {
            // Not merely closed - absent. An endpoint that exists and refuses
            // still tells a scanner the plugin is here and worth probing.
            return;
        }

        \register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [$this, 'authorize'],
        ]);
    }

    /**
     * The shared secret, compared in constant time.
     *
     * `hash_equals` rather than `===`: a normal string comparison returns as
     * soon as two characters differ, and the time that takes leaks how much of
     * the secret was right. It is a slow attack and an unlikely one, but the fix
     * is one function name.
     *
     * @return true|WP_Error
     */
    public function authorize(WP_REST_Request $request)
    {
        $expected = $this->settings->ingestSecret();

        if ($expected === null) {
            return new WP_Error(
                'openai_ads_not_configured',
                'The collection endpoint has no secret configured.',
                ['status' => 503],
            );
        }

        $supplied = (string) $request->get_header(self::SECRET_HEADER);

        if ($supplied === '' || !hash_equals($expected, $supplied)) {
            // Deliberately says nothing about which part was wrong.
            return new WP_Error(
                'openai_ads_forbidden',
                'Invalid or missing credentials.',
                ['status' => 401],
            );
        }

        if (!$this->withinRateLimit()) {
            return new WP_Error(
                'openai_ads_rate_limited',
                'Too many events from this address.',
                ['status' => 429],
            );
        }

        return true;
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        /** @var array<string, mixed> $payload */
        $payload = (array) $request->get_json_params();

        $eventName = isset($payload['event']) && is_string($payload['event'])
            ? $payload['event']
            : '';

        if ($eventName === '') {
            return $this->reject('An "event" name is required.');
        }

        /** @var array<string, mixed> $data */
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

        try {
            $event = $this->plugin->builder()->build(
                $eventName,
                $data,
                $this->options($payload),
                $this->context($payload),
            );
        } catch (InvalidArgument $e) {
            // Safe to return: the core's messages name the field and the event
            // id, never an identifier. The caller is authenticated, and an
            // integrator debugging a tag needs to know what was wrong.
            $this->remember($eventName, 'rejected', $e->getMessage());

            return $this->reject($e->getMessage());
        }

        $accepted = $this->plugin->measurement()->record($event);

        $this->remember(
            $eventName,
            $accepted ? 'accepted' : 'skipped',
            $accepted ? '' : 'Measurement is off, unconfigured, or consent was refused.',
        );

        // 202, not 200: the event is queued and leaves after this response. What
        // OpenAI thinks of it is not known yet and will not be on this request.
        return new WP_REST_Response([
            'accepted' => $accepted,
            'event_id' => $event->id->value,
        ], $accepted ? 202 : 200);
    }

    /**
     * What this conversion's own request knew, as opposed to this one.
     *
     * The subtlety that makes this endpoint different from every other entry
     * point. A POST to /wp-json did NOT happen on the page where the conversion
     * did, so the request's own URL, address and user agent describe the wrong
     * thing entirely. Whatever the caller sends therefore wins.
     *
     * The request remains the fallback, and it is the RIGHT fallback in the
     * common case: a web container posting from the visitor's own browser,
     * same-origin, carrying their cookies and their address. A server-to-server
     * caller that omits these attributes the conversion to its own machine -
     * which is why the settings screen says so in as many words.
     *
     * `oppref` and `obref` are both here and are NOT the same field: one is
     * event-level, the other user-level. They part company at serialization.
     *
     * @param array<string, mixed> $payload
     */
    private function context(array $payload): HostContext
    {
        $request = $this->plugin->context();

        $given = static function (string $key) use ($payload): ?string {
            return isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== ''
                ? $payload[$key]
                : null;
        };

        return new HostContext(
            sourceUrl: $given('source_url') ?? $request->sourceUrl(),
            oppref: $given('oppref') ?? $request->oppref(),
            obref: $given('obref') ?? $request->obref(),
            ipAddress: $given('ip_address') ?? $request->ipAddress(),
            userAgent: $given('user_agent') ?? $request->userAgent(),
        );
    }

    /**
     * Translate the payload into the options `EventBuilder` takes.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function options(array $payload): array
    {
        $options = [];

        foreach (['event_id', 'custom_event_name', 'action_source', 'plan_id'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && $payload[$key] !== '') {
                $options[$key] = $payload[$key];
            }
        }

        if (array_key_exists('source_url', $payload)) {
            $options['source_url'] = is_string($payload['source_url']) ? $payload['source_url'] : null;
        }

        if (isset($payload['timestamp_ms']) && is_numeric($payload['timestamp_ms'])) {
            $options['timestamp_ms'] = (int) $payload['timestamp_ms'];
        }

        if (isset($payload['opt_out'])) {
            $options['opt_out'] = (bool) $payload['opt_out'];
        }

        if (isset($payload['contents']) && is_array($payload['contents'])) {
            $options['contents'] = $payload['contents'];
        }

        if (isset($payload['user']) && is_array($payload['user'])) {
            /** @var array<string, mixed> $user */
            $user = $payload['user'];
            $options['user'] = $user;
        }

        // `oppref` and `obref` are deliberately NOT read here. They are context,
        // not caller input, and they go through HostContext - which is also what
        // keeps them on their separate objects. See context() above.

        return $options;
    }

    /**
     * A small ring buffer of what arrived, for the diagnostics screen.
     *
     * Deliberately thin: the event name, the outcome, a reason, a timestamp.
     * NOT the payload - it carries raw email addresses and phone numbers, and an
     * options row is readable by anyone who can read options.
     */
    private function remember(string $eventName, string $outcome, string $reason): void
    {
        if (!$this->settings->debug()) {
            return;
        }

        /** @var mixed $stored */
        $stored = \get_option(self::LOG_OPTION, []);
        $log = is_array($stored) ? $stored : [];

        array_unshift($log, [
            'at' => \current_time('mysql', true),
            'event' => \sanitize_text_field($eventName),
            'outcome' => $outcome,
            'reason' => \sanitize_text_field($reason),
        ]);

        \update_option(self::LOG_OPTION, array_slice($log, 0, self::LOG_SIZE), false);
    }

    /**
     * A per-address budget, held in a transient.
     *
     * Not a defence against a determined attacker - they have the secret or they
     * are not getting in at all. It is a defence against the ordinary disasters:
     * a tag misconfigured into a loop, a retry storm, a leaked secret being used
     * before anybody notices. A site that legitimately exceeds two events a
     * second from one address can raise it through the filter.
     */
    private function withinRateLimit(): bool
    {
        /** @var int $limit */
        $limit = (int) \apply_filters('openai_ads_ingest_rate_limit', self::RATE_LIMIT);

        if ($limit <= 0) {
            return true;
        }

        $address = isset($_SERVER['REMOTE_ADDR'])
            ? (string) \wp_unslash($_SERVER['REMOTE_ADDR'])
            : 'unknown';
        $key = 'openai_ads_rate_' . md5($address);

        /** @var mixed $count */
        $count = \get_transient($key);
        $count = is_numeric($count) ? (int) $count : 0;

        if ($count >= $limit) {
            return false;
        }

        \set_transient($key, $count + 1, MINUTE_IN_SECONDS);

        return true;
    }

    private function reject(string $message): WP_REST_Response
    {
        return new WP_REST_Response(['accepted' => false, 'error' => $message], 400);
    }
}
