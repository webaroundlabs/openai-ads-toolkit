<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebaroundLabs\OpenAIAds\WordPress\Http\Ingest;
use WebaroundLabs\OpenAIAds\WordPress\Plugin;
use WebaroundLabs\OpenAIAds\WordPress\Settings;
use WP_REST_Request;
use WpStubs;

/**
 * The endpoint a tag manager posts conversions to.
 *
 * Half of this suite is about what it REFUSES, and that is the right
 * proportion. An endpoint that forwards conversions writes into the
 * advertiser's measurement data; left open, anyone who finds it can record
 * conversions that never happened. That does not steal money directly - it
 * poisons the numbers the bidding algorithm optimizes against, and nothing
 * downstream flags it.
 */
#[CoversClass(Ingest::class)]
final class IngestTest extends TestCase
{
    private const SECRET = 'a7c3f1e9b2d4a6c8e0f2a4b6c8d0e2f4a6b8c0d2e4f6a8b0c2d4e6f8a0b2c4d6';

    protected function setUp(): void
    {
        WpStubs::reset();
        Plugin::reset();
        WpStubs::$options[Settings::OPTION] = [
            'pixel_id' => 'px-1',
            'capi_key' => 'secret-key-must-never-be-rendered',
            'use_scheduler' => false,
            'ingest_enabled' => true,
            'ingest_secret' => self::SECRET,
        ];
    }

    protected function tearDown(): void
    {
        Plugin::reset();
    }

    // ------------------------------------------------------------- refusals

    #[Test]
    public function a_request_without_the_secret_is_refused(): void
    {
        $result = $this->ingest()->authorize($this->request([], null));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(401, $result->status());
    }

    #[Test]
    public function a_request_with_the_wrong_secret_is_refused(): void
    {
        $result = $this->ingest()->authorize($this->request([], 'not-the-secret'));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(401, $result->status());
    }

    /**
     * A near-miss must be refused exactly like a wild guess. The comparison uses
     * hash_equals rather than ===, because a normal string comparison returns as
     * soon as two characters differ and the time that takes leaks how much of
     * the secret was right.
     */
    #[Test]
    public function a_secret_that_is_almost_right_is_still_refused(): void
    {
        $almost = substr(self::SECRET, 0, -1) . 'X';

        $result = $this->ingest()->authorize($this->request([], $almost));

        self::assertInstanceOf(\WP_Error::class, $result);
    }

    /** Saying which half was wrong would help somebody guess the other half. */
    #[Test]
    public function a_refusal_says_nothing_useful_to_an_attacker(): void
    {
        $result = $this->ingest()->authorize($this->request([], 'wrong'));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertStringNotContainsString(self::SECRET, $result->get_error_message());
        self::assertStringNotContainsString('secret-key-must-never-be-rendered', $result->get_error_message());
    }

    #[Test]
    public function the_correct_secret_is_accepted(): void
    {
        self::assertTrue($this->ingest()->authorize($this->request([], self::SECRET)));
    }

    /**
     * Not merely closed - absent. An endpoint that exists and refuses still
     * tells a scanner the plugin is here and worth probing.
     */
    #[Test]
    public function no_route_exists_at_all_until_the_site_switches_it_on(): void
    {
        WpStubs::$options[Settings::OPTION]['ingest_enabled'] = false;

        $this->ingest()->registerRoute();

        self::assertSame([], WpStubs::$restRoutes);
    }

    #[Test]
    public function the_route_is_registered_once_it_is_on(): void
    {
        $this->ingest()->registerRoute();

        self::assertCount(1, WpStubs::$restRoutes);
        self::assertSame(Ingest::NAMESPACE, WpStubs::$restRoutes[0]['namespace']);
        self::assertSame(Ingest::ROUTE, WpStubs::$restRoutes[0]['route']);
        self::assertSame('POST', WpStubs::$restRoutes[0]['args']['methods']);
    }

    /**
     * Being switched on without a secret is not "open", it is broken. Serving
     * events in that state would be the worst possible reading of the setting.
     */
    #[Test]
    public function it_refuses_to_run_without_a_secret_rather_than_running_open(): void
    {
        WpStubs::$options[Settings::OPTION]['ingest_secret'] = '';

        $this->ingest()->registerRoute();

        self::assertSame([], WpStubs::$restRoutes);
    }

    /**
     * Not a defence against somebody holding the secret - they are already in.
     * It is a defence against the ordinary disasters: a tag misconfigured into a
     * loop, a retry storm, a leaked secret used before anybody notices.
     */
    #[Test]
    public function a_flood_from_one_address_is_cut_off(): void
    {
        \add_filter('openai_ads_ingest_rate_limit', static fn (): int => 3);

        $ingest = $this->ingest();

        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($ingest->authorize($this->request([], self::SECRET)), "request $i");
        }

        $result = $ingest->authorize($this->request([], self::SECRET));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(429, $result->status());
    }

    // -------------------------------------------------------------- the work

    #[Test]
    public function a_valid_event_is_accepted_and_forwarded(): void
    {
        $response = $this->ingest()->handle($this->request([
            'event' => 'lead_created',
            'event_id' => 'lead_123',
            'source_url' => 'https://shop.example.com/thank-you',
            'user' => ['email' => ' Ada@Example.COM '],
        ], self::SECRET));

        self::assertSame(202, $response->status);
        self::assertTrue($response->data['accepted']);
        self::assertSame('lead_123', $response->data['event_id']);

        $sent = $this->lastEvent();
        self::assertSame('lead_created', $sent['type']);
        self::assertSame('lead_123', $sent['id']);
        self::assertSame(
            ['b5fc85e55755f9e0d030a10ab4429b6b2944855f9a0d60077fe832becbc41d72'],
            $sent['user']['emails_sha256'],
        );
    }

    /**
     * The whole point of the endpoint: the browser and the server can now use
     * one id, so OpenAI counts one conversion.
     */
    #[Test]
    public function the_caller_supplied_id_survives_untouched(): void
    {
        $this->ingest()->handle($this->request([
            'event' => 'order_created',
            'event_id' => 'wc_1042',
            'source_url' => 'https://shop.example.com/thanks',
            'data' => ['amount' => 2599, 'currency' => 'EUR'],
        ], self::SECRET));

        $sent = $this->lastEvent();

        self::assertSame('wc_1042', $sent['id']);
        self::assertSame(2599, $sent['data']['amount']);
    }

    /**
     * This request arrived at /wp-json, not at the page where the conversion
     * happened. A caller posting server-to-server has to say where it was, or
     * the conversion is attributed to its own machine.
     */
    #[Test]
    public function the_payloads_source_url_wins_over_the_requests_own(): void
    {
        $this->ingest()->handle($this->request([
            'event' => 'lead_created',
            'event_id' => 'lead_1',
            'source_url' => 'https://shop.example.com/campaign-landing',
        ], self::SECRET));

        self::assertSame(
            'https://shop.example.com/campaign-landing',
            $this->lastEvent()['source_url'],
        );
    }

    /** Both attribution values can be forwarded, and they stay on their own objects. */
    #[Test]
    public function forwarded_attribution_lands_on_the_right_object(): void
    {
        $this->ingest()->handle($this->request([
            'event' => 'lead_created',
            'event_id' => 'lead_1',
            'source_url' => 'https://shop.example.com/thanks',
            'oppref' => 'opp-from-gtm',
            'obref' => 'obr-from-gtm',
        ], self::SECRET));

        $sent = $this->lastEvent();

        self::assertSame('opp-from-gtm', $sent['oppref']);
        self::assertSame('obr-from-gtm', $sent['user']['obref']);
        self::assertArrayNotHasKey('obref', $sent);
    }

    #[Test]
    public function custom_events_work_here_too(): void
    {
        $this->ingest()->handle($this->request([
            'event' => 'custom',
            'event_id' => 'quote_881',
            'custom_event_name' => 'quote_requested',
            'source_url' => 'https://shop.example.com/thanks',
        ], self::SECRET));

        $sent = $this->lastEvent();

        self::assertSame('custom', $sent['type']);
        self::assertSame('quote_requested', $sent['custom_event_name']);
    }

    #[Test]
    public function an_unknown_event_is_rejected_with_a_usable_explanation(): void
    {
        $response = $this->ingest()->handle($this->request([
            'event' => 'not_an_event',
            'event_id' => 'x',
        ], self::SECRET));

        self::assertSame(400, $response->status);
        self::assertFalse($response->data['accepted']);
        self::assertStringContainsString('not a supported OpenAI Ads event', $response->data['error']);
    }

    #[Test]
    public function a_payload_with_no_event_name_is_rejected(): void
    {
        $response = $this->ingest()->handle($this->request(['event_id' => 'x'], self::SECRET));

        self::assertSame(400, $response->status);
    }

    /** Consent is checked here exactly as everywhere else. */
    #[Test]
    public function nothing_is_forwarded_when_consent_is_refused(): void
    {
        \add_filter('openai_ads_consent', static fn (): bool => false);

        $response = $this->ingest()->handle($this->request([
            'event' => 'lead_created',
            'event_id' => 'lead_1',
            'source_url' => 'https://shop.example.com/thanks',
        ], self::SECRET));

        self::assertFalse($response->data['accepted']);
        self::assertSame([], WpStubs::$requests);
    }

    // ----------------------------------------------------------- diagnostics

    #[Test]
    public function the_diagnostic_log_never_records_the_payload(): void
    {
        WpStubs::$options[Settings::OPTION]['debug'] = true;

        $this->ingest()->handle($this->request([
            'event' => 'lead_created',
            'event_id' => 'lead_1',
            'source_url' => 'https://shop.example.com/thanks',
            'user' => ['email' => 'ada@example.com'],
        ], self::SECRET));

        $log = WpStubs::$options[Ingest::LOG_OPTION];

        self::assertCount(1, $log);
        self::assertSame('lead_created', $log[0]['event']);
        self::assertSame('accepted', $log[0]['outcome']);
        self::assertStringNotContainsString('ada@example.com', (string) json_encode($log));
    }

    #[Test]
    public function the_diagnostic_log_is_capped(): void
    {
        WpStubs::$options[Settings::OPTION]['debug'] = true;
        $ingest = $this->ingest();

        for ($i = 0; $i < Ingest::LOG_SIZE + 5; $i++) {
            $ingest->handle($this->request([
                'event' => 'lead_created',
                'event_id' => 'lead_' . $i,
                'source_url' => 'https://shop.example.com/thanks',
            ], self::SECRET));
        }

        self::assertCount(Ingest::LOG_SIZE, WpStubs::$options[Ingest::LOG_OPTION]);
    }

    #[Test]
    public function nothing_is_logged_without_debug_mode(): void
    {
        $this->ingest()->handle($this->request([
            'event' => 'lead_created',
            'event_id' => 'lead_1',
            'source_url' => 'https://shop.example.com/thanks',
        ], self::SECRET));

        self::assertArrayNotHasKey(Ingest::LOG_OPTION, WpStubs::$options);
    }

    private function ingest(): Ingest
    {
        return Plugin::boot(__DIR__ . '/../conversion-tracking-for-openai-ads.php', '0.1.0')->ingest();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(array $payload, ?string $secret): WP_REST_Request
    {
        $request = new WP_REST_Request($payload);

        if ($secret !== null) {
            $request->setHeader(Ingest::SECRET_HEADER, $secret);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function lastEvent(): array
    {
        Plugin::instance()?->measurement()->flush();

        self::assertNotSame([], WpStubs::$requests, 'Nothing was sent.');

        /** @var array<string, mixed> $body */
        $body = json_decode((string) WpStubs::$requests[0]['args']['body'], true);

        /** @var array<string, mixed> $event */
        $event = $body['events'][0];

        return $event;
    }
}
