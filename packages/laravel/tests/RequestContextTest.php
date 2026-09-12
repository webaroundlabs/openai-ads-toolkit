<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel\Tests;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WebaroundLabs\OpenAIAds\Laravel\RequestContext;

#[CoversClass(RequestContext::class)]
final class RequestContextTest extends TestCase
{
    #[Test]
    public function it_reads_the_two_attribution_cookies_from_their_own_fields(): void
    {
        // They are different fields from different cookies on different wire
        // objects; conflating them silently breaks matching.
        $context = $this->context(cookies: [
            RequestContext::OPPREF_COOKIE => 'oppref-value',
            RequestContext::OBREF_COOKIE => 'obref-value',
        ]);

        self::assertSame('oppref-value', $context->oppref());
        self::assertSame('obref-value', $context->obref());
    }

    #[Test]
    public function attribution_values_are_passed_through_untouched(): void
    {
        $opaque = '  MiXeD-Case_Value==  ';

        self::assertSame($opaque, $this->context(cookies: [
            RequestContext::OPPREF_COOKIE => $opaque,
        ])->oppref());
    }

    #[Test]
    public function missing_or_blank_cookies_are_null(): void
    {
        $context = $this->context(cookies: [RequestContext::OPPREF_COOKIE => '   ']);

        self::assertNull($context->oppref());
        self::assertNull($context->obref());
    }

    /**
     * Stripping the query string is the toolkit's privacy policy, not an API
     * rule: query strings routinely carry email addresses, reset tokens and
     * order references that have no business reaching an ad platform.
     */
    #[Test]
    public function the_query_string_and_fragment_are_stripped_by_default(): void
    {
        $context = $this->context(url: 'https://shop.example.com/checkout/done?email=ada@example.com&order=42');

        self::assertSame('https://shop.example.com/checkout/done', $context->sourceUrl());
    }

    #[Test]
    public function the_query_string_can_be_kept_deliberately(): void
    {
        $context = $this->context(
            url: 'https://shop.example.com/c?utm_source=chatgpt',
            stripQueryString: false,
        );

        self::assertSame('https://shop.example.com/c?utm_source=chatgpt', $context->sourceUrl());
    }

    /**
     * A form posted with fetch(), an Inertia visit or a Livewire action reaches
     * a route the visitor never navigated to. Reporting that route files every
     * conversion under one URL and loses the page that produced it.
     */
    #[Test]
    public function a_background_request_reports_the_page_it_was_made_from(): void
    {
        $context = $this->context(
            url: 'https://shop.example.com/api/leads',
            server: [
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                'HTTP_REFERER' => 'https://shop.example.com/landing/black-friday',
            ],
        );

        self::assertSame('https://shop.example.com/landing/black-friday', $context->sourceUrl());
    }

    /** A JSON client that sends no referer is left exactly as it was. */
    #[Test]
    public function a_background_request_without_a_referer_reports_its_own_url(): void
    {
        $context = $this->context(
            url: 'https://shop.example.com/api/leads',
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertSame('https://shop.example.com/api/leads', $context->sourceUrl());
    }

    /**
     * On an ordinary page view the request URL is the page. The referer there is
     * the page BEFORE this one, which is not where the conversion happened.
     */
    #[Test]
    public function a_page_view_reports_itself_and_not_where_the_visitor_came_from(): void
    {
        $context = $this->context(
            url: 'https://shop.example.com/checkout/done',
            server: ['HTTP_REFERER' => 'https://shop.example.com/cart'],
        );

        self::assertSame('https://shop.example.com/checkout/done', $context->sourceUrl());
    }

    /** The referer is client-controlled, so it gets the same treatment as the Host. */
    #[Test]
    public function a_forged_referer_cannot_report_another_domain(): void
    {
        $context = $this->context(
            url: 'https://shop.example.com/api/leads',
            server: [
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                'HTTP_REFERER' => 'https://evil.example.net/attacker/page',
            ],
        );

        self::assertSame('https://shop.example.com', $context->sourceUrl());
    }

    /**
     * A spoofed Host header, or a misconfigured proxy, must not be able to put
     * a third party's domain into the advertiser's measurement data.
     */
    #[Test]
    public function an_origin_that_is_not_the_canonical_one_falls_back_rather_than_being_reported(): void
    {
        $context = $this->context(url: 'https://evil.example.net/checkout/done');

        self::assertSame('https://shop.example.com', $context->sourceUrl());
    }

    #[Test]
    #[DataProvider('canonicalOrigins')]
    public function the_canonical_origin_is_normalized(string $configured, ?string $expected): void
    {
        $context = $this->context(canonicalOrigin: $configured);

        self::assertSame($expected, $context->canonicalOrigin());
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function canonicalOrigins(): iterable
    {
        yield 'plain' => ['https://shop.example.com', 'https://shop.example.com'];
        yield 'with a path' => ['https://shop.example.com/app', 'https://shop.example.com'];
        yield 'uppercase host' => ['https://SHOP.Example.COM', 'https://shop.example.com'];
        yield 'with a port' => ['http://localhost:8000', 'http://localhost:8000'];
        yield 'empty' => ['', null];
        yield 'not a url' => ['shop.example.com', null];
    }

    #[Test]
    public function a_valid_client_ip_and_user_agent_are_exposed(): void
    {
        $context = $this->context(server: [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ]);

        self::assertSame('203.0.113.7', $context->ipAddress());
        self::assertSame('Mozilla/5.0', $context->userAgent());
    }

    #[Test]
    public function a_blank_user_agent_is_null_rather_than_an_empty_string(): void
    {
        self::assertNull($this->context(server: ['HTTP_USER_AGENT' => '  '])->userAgent());
    }

    #[Test]
    public function attribution_survives_a_middleware_that_nulls_the_cookie_bag(): void
    {
        // EncryptCookies rewrites $request->cookies in place and replaces every
        // value it cannot decrypt with null. __oppref and __obref are written by
        // OpenAI's browser SDK, so it never can, and an app that has not listed
        // them in $except - the default - would report no attribution at all
        // while looking entirely healthy. Found against a real Laravel 12 app;
        // building a Request directly, as every other test here does, runs no
        // middleware and so cannot see it.
        $context = $this->context(
            cookies: [
                RequestContext::OPPREF_COOKIE => null,
                RequestContext::OBREF_COOKIE => null,
            ],
            server: ['HTTP_COOKIE' => '__oppref=opp-abc; __obref=obr-xyz'],
        );

        self::assertSame('opp-abc', $context->oppref());
        self::assertSame('obr-xyz', $context->obref());
    }

    #[Test]
    public function the_decrypted_bag_wins_over_the_header(): void
    {
        // An app that HAS configured $except stays on the framework's own path.
        $context = $this->context(
            cookies: [RequestContext::OPPREF_COOKIE => 'from-the-bag'],
            server: ['HTTP_COOKIE' => '__oppref=from-the-header'],
        );

        self::assertSame('from-the-bag', $context->oppref());
    }

    #[Test]
    public function a_header_cookie_is_decoded_like_the_bag_would_be(): void
    {
        // PHP decodes $_COOKIE and Symfony decodes its bag; the header path has
        // to agree with them or the same visitor reports two different values.
        $context = $this->context(
            cookies: [],
            server: ['HTTP_COOKIE' => '__oppref=a%20b%2Bc'],
        );

        self::assertSame('a b+c', $context->oppref());
    }

    #[Test]
    public function a_blank_header_cookie_is_null_like_a_blank_one_in_the_bag(): void
    {
        $context = $this->context(
            cookies: [],
            server: ['HTTP_COOKIE' => '__oppref=   ; __obref=obr-1'],
        );

        self::assertNull($context->oppref());
        self::assertSame('obr-1', $context->obref());
    }

    /**
     * `string|null` on the cookies, not `string`: a middleware that cannot
     * decrypt a value leaves null in the bag, and that is the state worth
     * being able to describe here.
     *
     * @param array<string, string|null> $cookies
     * @param array<string, string>      $server
     */
    private function context(
        string $url = 'https://shop.example.com/contact/thank-you',
        array $cookies = [],
        array $server = [],
        ?string $canonicalOrigin = 'https://shop.example.com',
        bool $stripQueryString = true,
    ): RequestContext {
        $request = Request::create($url, 'GET', [], $cookies, [], $server);

        return new RequestContext($request, $canonicalOrigin, $stripQueryString);
    }
}
