<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\SystemClock;
use WebaroundLabs\OpenAIAds\WordPress\EventBuilder;
use WebaroundLabs\OpenAIAds\WordPress\RequestContext;
use WebaroundLabs\OpenAIAds\WordPress\Settings;
use WpStubs;

#[CoversClass(EventBuilder::class)]
#[CoversClass(RequestContext::class)]
final class EventBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubs::reset();
        WpStubs::$options[Settings::OPTION] = ['pixel_id' => 'px-1', 'capi_key' => 'secret'];
    }

    #[Test]
    public function it_builds_a_valid_event_from_a_loose_array(): void
    {
        $payload = $this->builder()->build('lead_created', [], ['event_id' => 'lead_9'])->toCapiArray();

        self::assertSame('lead_9', $payload['id']);
        self::assertSame('lead_created', $payload['type']);
        self::assertSame('web', $payload['action_source']);
        self::assertSame(['type' => 'customer_action'], $payload['data']);
    }

    #[Test]
    public function an_unknown_event_name_is_rejected_with_a_useful_message(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('is not a supported OpenAI Ads event');

        $this->builder()->build('purchase');
    }

    #[Test]
    public function a_major_unit_amount_is_rejected_with_the_minor_unit_explained(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('A price of 12.99 is 1299');

        $this->builder()->build('order_created', ['amount' => 12.99, 'currency' => 'EUR']);
    }

    #[Test]
    public function an_amount_without_a_currency_is_rejected(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('currency is required');

        $this->builder()->build('order_created', ['amount' => 1299]);
    }

    #[Test]
    public function an_amount_carries_its_currency(): void
    {
        $payload = $this->builder()->build('order_created', ['amount' => 1299, 'currency' => 'eur'])->toCapiArray();

        self::assertSame(['type' => 'contents', 'amount' => 1299, 'currency' => 'EUR'], $payload['data']);
    }

    /**
     * The two attribution values come from different cookies and land on
     * different parts of the payload. Conflating them silently breaks matching.
     */
    #[Test]
    public function oppref_lands_on_the_event_and_obref_inside_the_user(): void
    {
        $_COOKIE[RequestContext::OPPREF_COOKIE] = 'oppref-abc';
        $_COOKIE[RequestContext::OBREF_COOKIE] = 'obref-xyz';

        $payload = $this->builder()->build('lead_created')->toCapiArray();

        self::assertSame('oppref-abc', $payload['oppref']);
        self::assertSame('obref-xyz', $payload['user']['obref']);
        self::assertArrayNotHasKey('obref', $payload);
    }

    #[Test]
    public function raw_identity_is_hashed_before_it_leaves(): void
    {
        $payload = $this->builder()->build('lead_created', [], [
            'user' => ['email' => ' Ada@Example.COM '],
        ])->toCapiArray();

        self::assertSame(
            ['b5fc85e55755f9e0d030a10ab4429b6b2944855f9a0d60077fe832becbc41d72'],
            $payload['user']['emails_sha256'],
        );
        self::assertStringNotContainsString('ada@example.com', json_encode($payload) ?: '');
    }

    #[Test]
    public function the_request_supplies_the_network_context(): void
    {
        $payload = $this->builder()->build('lead_created')->toCapiArray();

        self::assertSame('203.0.113.7', $payload['user']['ip_address']);
        self::assertSame('Mozilla/5.0', $payload['user']['user_agent']);
    }

    #[Test]
    public function the_query_string_is_stripped_from_the_source_url(): void
    {
        $_SERVER['REQUEST_URI'] = '/checkout/done?key=wc_order_abc&email=ada@example.com';

        $payload = $this->builder()->build('lead_created')->toCapiArray();

        self::assertSame('https://shop.example.com/checkout/done', $payload['source_url']);
    }

    #[Test]
    public function the_query_string_can_be_kept_deliberately(): void
    {
        WpStubs::$options[Settings::OPTION]['strip_query_string'] = false;
        $_SERVER['REQUEST_URI'] = '/landing?utm_source=chatgpt';

        $payload = $this->builder()->build('lead_created')->toCapiArray();

        self::assertSame('https://shop.example.com/landing?utm_source=chatgpt', $payload['source_url']);
    }

    /**
     * The site's own origin is used regardless of what the request claims, so a
     * spoofed Host header cannot put another domain into the advertiser's data.
     */
    #[Test]
    public function the_source_url_always_uses_the_sites_own_origin(): void
    {
        $_SERVER['HTTP_HOST'] = 'evil.example.net';
        $_SERVER['REQUEST_URI'] = '/checkout/done';

        $payload = $this->builder()->build('lead_created')->toCapiArray();

        self::assertStringStartsWith('https://shop.example.com/', $payload['source_url']);
    }

    #[Test]
    public function a_forwarded_ip_is_ignored_unless_a_filter_supplies_it(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        $payload = $this->builder()->build('lead_created')->toCapiArray();
        self::assertSame('203.0.113.7', $payload['user']['ip_address']);

        \add_filter('openai_ads_client_ip', static fn (): string => '1.2.3.4');
        $filtered = $this->builder()->build('lead_created')->toCapiArray();
        self::assertSame('1.2.3.4', $filtered['user']['ip_address']);
    }

    #[Test]
    public function an_event_id_is_minted_when_the_caller_supplies_none(): void
    {
        $payload = $this->builder()->build('lead_created')->toCapiArray();

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $payload['id']);
    }

    #[Test]
    public function a_custom_event_needs_its_name(): void
    {
        $payload = $this->builder()->build('custom', [], [
            'event_id' => 'w_1',
            'custom_event_name' => 'whatsapp_lead',
        ])->toCapiArray();

        self::assertSame('whatsapp_lead', $payload['custom_event_name']);
        self::assertSame(['type' => 'custom'], $payload['data']);
    }

    private function builder(): EventBuilder
    {
        return new EventBuilder(new RequestContext(new Settings()), new SystemClock());
    }
}
