<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel\Tests;

use PHPUnit\Framework\Attributes\Test;
use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\Laravel\Measurement;

/**
 * The ergonomic path: documented field names in, a validated event out, with the
 * five things only the request knows filled in.
 *
 * `Event::create()` remains the typed alternative. This exists because making
 * every controller read the `__oppref` cookie, the `__obref` cookie, the client
 * IP, the user agent and a privacy-filtered source URL by hand is how three of
 * the five end up missing.
 */
final class EventBuildingTest extends TestCase
{
    #[Test]
    public function it_fills_in_what_only_the_request_knows(): void
    {
        $this->withRequest();

        $payload = $this->measurement()->event('lead_created', [], ['event_id' => 'lead-1'])
            ->toCapiArray();

        self::assertSame('lead-1', $payload['id']);
        self::assertSame('https://shop.example.com/contact/thank-you', $payload['source_url']);
        self::assertSame('opp-abc', $payload['oppref']);
        self::assertSame('obr-xyz', $payload['user']['obref']);
        self::assertSame('203.0.113.7', $payload['user']['ip_address']);
        self::assertSame('Mozilla/5.0', $payload['user']['user_agent']);
    }

    /**
     * The two are different fields on different objects, and conflating them is
     * the easiest mistake in this codebase.
     */
    #[Test]
    public function oppref_stays_on_the_event_and_obref_inside_the_user(): void
    {
        $this->withRequest();

        $payload = $this->measurement()->event('lead_created')->toCapiArray();

        self::assertArrayNotHasKey('obref', $payload);
        self::assertArrayNotHasKey('oppref', $payload['user']);
    }

    #[Test]
    public function raw_identity_is_hashed_before_it_leaves(): void
    {
        $this->withRequest();

        $payload = $this->measurement()->event('lead_created', [], [
            'event_id' => 'lead-1',
            'user' => ['email' => ' Ada@Example.COM '],
        ])->toCapiArray();

        self::assertSame(
            ['b5fc85e55755f9e0d030a10ab4429b6b2944855f9a0d60077fe832becbc41d72'],
            $payload['user']['emails_sha256'],
        );
        self::assertStringNotContainsString('ada@example.com', strtolower(json_encode($payload) ?: ''));
    }

    #[Test]
    public function the_query_string_is_stripped_from_the_source_url(): void
    {
        $this->withRequest('/thanks?email=ada%40example.com&order=9');

        $payload = $this->measurement()->event('lead_created')->toCapiArray();

        self::assertSame('https://shop.example.com/thanks', $payload['source_url']);
    }

    #[Test]
    public function a_major_unit_amount_is_refused_with_the_conversion_spelled_out(): void
    {
        $this->withRequest();

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('A price of 12.99 is 1299');

        $this->measurement()->event('order_created', ['amount' => '12.99', 'currency' => 'EUR']);
    }

    /**
     * `track()` is the form for a controller. A measurement mistake must not take
     * the request that produced the conversion with it.
     */
    #[Test]
    public function track_never_throws_and_reports_whether_it_worked(): void
    {
        $this->withRequest();

        self::assertFalse($this->measurement()->track('not_an_event'));
        self::assertTrue($this->measurement()->track('lead_created', [], ['event_id' => 'lead-1']));
    }

    #[Test]
    public function an_unusable_identity_field_does_not_cost_the_conversion(): void
    {
        $this->withRequest();

        $payload = $this->measurement()->event('lead_created', [], [
            'event_id' => 'lead-1',
            'user' => ['email' => 'ada@example.com', 'phone' => '+1 (555) 123-4567 ext. 89'],
        ])->toCapiArray();

        self::assertArrayHasKey('emails_sha256', $payload['user']);
        self::assertArrayNotHasKey('phone_numbers_sha256', $payload['user']);
    }

    private function withRequest(string $path = '/contact/thank-you'): void
    {
        $request = \Illuminate\Http\Request::create('https://shop.example.com' . $path, 'GET', [], [
            '__oppref' => 'opp-abc',
            '__obref' => 'obr-xyz',
        ], [], [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ]);

        $this->app->instance('request', $request);
    }

    private function measurement(): Measurement
    {
        return $this->app->make(Measurement::class);
    }
}
