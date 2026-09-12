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

    /**
     * WordPress runs add_magic_quotes() over $_COOKIE and $_SERVER before any
     * plugin sees them, so a value containing a quote arrives with a backslash
     * in front of it. Sending that on means OpenAI receives a user agent, a page
     * address and an attribution reference that nobody ever had.
     */
    #[Test]
    public function slashes_wordpress_added_are_removed_before_sending(): void
    {
        $_COOKIE[RequestContext::OPPREF_COOKIE] = 'opp\\"quoted\\"';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; \\"Bot\\"/1.0)';

        $payload = $this->builder()->build('lead_created')->toCapiArray();

        self::assertSame('opp"quoted"', $payload['oppref']);
        self::assertSame('Mozilla/5.0 (compatible; "Bot"/1.0)', $payload['user']['user_agent']);
    }

    #[Test]
    public function a_slashed_request_uri_does_not_reach_the_source_url(): void
    {
        $_SERVER['REQUEST_URI'] = '/thanks/\\"odd\\"';

        $payload = $this->builder()->build('lead_created')->toCapiArray();

        self::assertStringEndsWith('/thanks/"odd"', (string) $payload['source_url']);
        self::assertStringNotContainsString('\\', (string) $payload['source_url']);
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

    /**
     * A visitor typing an extension after their phone number must not cost the
     * site the conversion. The core refuses that phone - correctly, because
     * stripping the extension would hash a number belonging to nobody - and the
     * adapter drops just that field.
     */
    #[Test]
    public function one_unusable_identity_field_does_not_take_the_conversion_with_it(): void
    {
        $payload = $this->builder()->build('lead_created', [], [
            'user' => [
                'email' => 'ada@example.com',
                'phone' => '+1 (555) 123-4567 ext. 89',
                'country' => 'Romania',
            ],
        ])->toCapiArray();

        self::assertArrayHasKey('emails_sha256', $payload['user']);
        self::assertArrayNotHasKey('phone_numbers_sha256', $payload['user']);
        self::assertArrayNotHasKey('countries', $payload['user']);
    }

    #[Test]
    public function a_dropped_identity_field_is_announced_without_its_value(): void
    {
        $this->builder()->build('lead_created', [], [
            'user' => ['phone' => '+1 (555) 123-4567 ext. 89'],
        ]);

        $dropped = array_values(array_filter(
            WpStubs::$actions,
            static fn (array $fired): bool => $fired[0] === 'openai_ads_identity_field_dropped',
        ));

        self::assertCount(1, $dropped);
        self::assertSame('phone', $dropped[0][1][0]);
        self::assertStringNotContainsString('555', $dropped[0][1][1]);
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
     * Every AJAX and REST integration here runs on a request the visitor never
     * navigated to. REQUEST_URI is the endpoint on those, so reporting it files
     * every lead on the site under one admin URL and loses the landing page that
     * earned it.
     */
    #[Test]
    public function an_ajax_submission_reports_the_page_it_was_made_from(): void
    {
        WpStubs::$doingAjax = true;
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
        WpStubs::$referer = 'https://shop.example.com/landing/black-friday/';

        $payload = $this->builder()->build('lead_created')->toCapiArray();

        self::assertSame('https://shop.example.com/landing/black-friday/', $payload['source_url']);
    }

    #[Test]
    public function a_rest_submission_reports_the_page_it_was_made_from(): void
    {
        WpStubs::$servingRest = true;
        $_SERVER['REQUEST_URI'] = '/wp-json/contact-form-7/v1/contact-forms/14/feedback';
        WpStubs::$referer = 'https://shop.example.com/contact/';

        $payload = $this->builder()->build('lead_created')->toCapiArray();

        self::assertSame('https://shop.example.com/contact/', $payload['source_url']);
    }

    /**
     * The referer is host data. wp_get_referer() has already refused an off-site
     * one, and the origin is rebuilt from the canonical origin regardless, so
     * nothing a header claims can put another domain into the payload.
     */
    #[Test]
    public function a_referer_cannot_change_the_origin_that_is_reported(): void
    {
        WpStubs::$doingAjax = true;
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
        WpStubs::$referer = 'https://evil.example.net/attacker/page/';

        $payload = $this->builder()->build('lead_created')->toCapiArray();

        self::assertSame('https://shop.example.com/attacker/page/', $payload['source_url']);
    }

    /** A background request with no usable referer still reports something. */
    #[Test]
    public function an_ajax_submission_without_a_referer_falls_back_to_the_request(): void
    {
        WpStubs::$doingAjax = true;
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
        WpStubs::$referer = false;

        $payload = $this->builder()->build('lead_created')->toCapiArray();

        self::assertSame('https://shop.example.com/wp-admin/admin-ajax.php', $payload['source_url']);
    }

    /**
     * On an ordinary page view REQUEST_URI is the page. A referer there is the
     * page BEFORE this one, which is not where the conversion happened.
     */
    #[Test]
    public function a_page_view_reports_itself_and_not_where_the_visitor_came_from(): void
    {
        $_SERVER['REQUEST_URI'] = '/checkout/done';
        WpStubs::$referer = 'https://shop.example.com/cart/';

        $payload = $this->builder()->build('lead_created')->toCapiArray();

        self::assertSame('https://shop.example.com/checkout/done', $payload['source_url']);
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
