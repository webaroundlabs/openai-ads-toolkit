<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebaroundLabs\OpenAIAds\ActionSource;
use WebaroundLabs\OpenAIAds\Content;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\EventId;
use WebaroundLabs\OpenAIAds\EventName;
use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\Money;
use WebaroundLabs\OpenAIAds\UserData;

#[CoversClass(Event::class)]
final class EventTest extends TestCase
{
    /**
     * The Phase 2 milestone: a minimal lead_created serializes to exactly the
     * payload pinned in the shared specification.
     */
    #[Test]
    public function minimal_lead_created_matches_the_golden_fixture(): void
    {
        $fixture = Spec::load('fixtures/lead_created.minimal.capi.json');
        $input = $fixture['input'];
        $clock = new FrozenClock($input['timestamp_ms']);

        $event = Event::create(
            name: EventName::LeadCreated,
            id: EventId::fromBusinessId($input['id']),
            timestampMs: $clock->nowMs(),
            actionSource: ActionSource::Web,
            sourceUrl: $input['source_url'],
            user: UserData::create(email: $input['user']['email']),
        );

        // assertSame on arrays compares key order too, so this pins the
        // emission order and keeps payloads byte-reproducible.
        self::assertSame($fixture['expected_event'], $event->toCapiArray());
    }

    #[Test]
    public function absent_optional_fields_are_omitted_rather_than_sent_as_null(): void
    {
        $event = self::lead();

        $payload = $event->toCapiArray();

        foreach (['amount', 'currency', 'oppref', 'opt_out', 'custom_event_name', 'user'] as $key) {
            self::assertArrayNotHasKey($key, $payload);
        }
        self::assertSame(['type' => 'customer_action'], $payload['data']);
    }

    #[Test]
    public function an_amount_carries_its_currency_into_the_data_object(): void
    {
        $event = self::lead(value: Money::minor(12_99, 'eur'));

        self::assertSame(
            ['type' => 'customer_action', 'amount' => 1299, 'currency' => 'EUR'],
            $event->toCapiArray()['data'],
        );
    }

    #[Test]
    public function opt_out_false_is_transmitted_because_false_is_not_absent(): void
    {
        $payload = self::lead(optOut: false)->toCapiArray();

        self::assertArrayHasKey('opt_out', $payload);
        self::assertFalse($payload['opt_out']);
    }

    #[Test]
    public function oppref_is_passed_through_byte_for_byte(): void
    {
        $oppref = 'oPPref_AbC-123.xyz==';

        $payload = self::lead(oppref: $oppref)->toCapiArray();

        self::assertSame($oppref, $payload['oppref']);
    }

    #[Test]
    public function a_capi_only_event_is_rejected_on_the_web(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('only supports action_source mobile_app');

        Event::create(
            name: EventName::AppInstalled,
            id: EventId::fromBusinessId('install_1'),
            timestampMs: 1789041600000,
            actionSource: ActionSource::Web,
            sourceUrl: 'https://example.com/',
        );
    }

    #[Test]
    public function a_capi_only_event_is_accepted_on_mobile_without_a_source_url(): void
    {
        $event = Event::create(
            name: EventName::AppInstalled,
            id: EventId::fromBusinessId('install_1'),
            timestampMs: 1789041600000,
            actionSource: ActionSource::MobileApp,
        );

        $payload = $event->toCapiArray();

        self::assertSame('app_installed', $payload['type']);
        self::assertSame('mobile_app', $payload['action_source']);
        self::assertArrayNotHasKey('source_url', $payload);
    }

    #[Test]
    public function a_web_event_requires_a_source_url(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('source_url is required when action_source is "web"');

        Event::create(
            name: EventName::LeadCreated,
            id: EventId::fromBusinessId('lead_1'),
            timestampMs: 1789041600000,
            actionSource: ActionSource::Web,
        );
    }

    /**
     * @param string $url
     */
    #[Test]
    #[DataProvider('unusableSourceUrls')]
    public function a_source_url_without_a_scheme_and_host_is_rejected(string $url): void
    {
        $this->expectException(InvalidArgument::class);

        self::lead(sourceUrl: $url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableSourceUrls(): iterable
    {
        yield 'no scheme' => ['example.com/thank-you'];
        yield 'path only' => ['/thank-you'];
        yield 'scheme only' => ['https://'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'data uri' => ['data:text/html,<script>alert(1)</script>'];
        yield 'file' => ['file:///etc/passwd'];
    }

    #[Test]
    public function a_query_string_is_preserved_because_stripping_it_is_an_adapter_policy(): void
    {
        $url = 'https://example.com/thank-you?utm_source=chatgpt#top';

        self::assertSame($url, self::lead(sourceUrl: $url)->toCapiArray()['source_url']);
    }

    #[Test]
    public function a_custom_event_requires_a_name(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('custom_event_name is required');

        Event::create(
            name: EventName::Custom,
            id: EventId::fromBusinessId('c_1'),
            timestampMs: 1789041600000,
            actionSource: ActionSource::Web,
            sourceUrl: 'https://example.com/',
        );
    }

    #[Test]
    public function a_standard_event_rejects_a_custom_name(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('only valid for the "custom" event');

        self::lead(customEventName: 'whatsapp_lead');
    }

    #[Test]
    public function a_custom_name_may_not_reuse_a_standard_event_name(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('must not reuse the standard event name');

        self::custom('order_created');
    }

    #[Test]
    #[DataProvider('invalidCustomEventNames')]
    public function an_invalid_custom_name_is_rejected(string $name): void
    {
        $this->expectException(InvalidArgument::class);

        self::custom($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCustomEventNames(): iterable
    {
        yield 'leading underscore' => ['_lead'];
        yield 'trailing underscore' => ['lead_'];
        yield 'trailing dash' => ['lead-'];
        yield 'space' => ['whatsapp lead'];
        yield 'punctuation' => ['lead!'];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('validCustomEventNames')]
    public function a_valid_custom_name_is_accepted(string $name): void
    {
        $payload = self::custom($name)->toCapiArray();

        self::assertSame('custom', $payload['type']);
        self::assertSame($name, $payload['custom_event_name']);
        self::assertSame(['type' => 'custom'], $payload['data']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validCustomEventNames(): iterable
    {
        yield 'single character' => ['a'];
        yield 'underscores' => ['whatsapp_lead'];
        yield 'dashes' => ['whatsapp-lead-2'];
        yield 'maximum length' => [str_repeat('a', 64)];
    }

    #[Test]
    public function a_non_positive_timestamp_is_rejected(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('timestamp_ms must be a positive');

        self::lead(timestampMs: 0);
    }

    /**
     * Freshness is NOT checked here. The 7-day window is relative to send time,
     * so an event that is valid when built can go stale in a queue; checking it
     * at construction would reject nothing useful and would make this fixture
     * expire. The check belongs in the client, immediately before transmission.
     */
    #[Test]
    public function an_old_timestamp_is_accepted_at_construction_time(): void
    {
        $event = self::lead(timestampMs: 1_000_000_000_000);

        self::assertSame(1_000_000_000_000, $event->toCapiArray()['timestamp_ms']);
    }

    #[Test]
    public function an_empty_user_object_is_omitted_entirely(): void
    {
        $payload = self::lead(user: UserData::create())->toCapiArray();

        self::assertArrayNotHasKey('user', $payload);
    }

    /**
     * The browser channels get the data object without the two content fields
     * the documentation marks server-side only. The Pixel would accept and
     * discard them, which is how a payload looks correct and is not.
     */
    #[Test]
    public function the_pixel_data_object_omits_the_server_only_content_fields(): void
    {
        $event = Event::create(
            name: EventName::OrderCreated,
            id: EventId::fromBusinessId('order-1'),
            timestampMs: 1789041600000,
            actionSource: ActionSource::Web,
            sourceUrl: 'https://example.com/thank-you',
            value: Money::minor(2599, 'EUR'),
            contents: [Content::create(id: 'SKU-1', groupId: 'PARENT-1', variantDict: ['size' => 'M'])],
        );

        $capiItem = $event->toCapiArray()['data']['contents'][0];
        $pixelItem = $event->pixelData()['contents'][0];

        self::assertArrayHasKey('group_id', $capiItem);
        self::assertArrayHasKey('variant_dict', $capiItem);
        self::assertArrayNotHasKey('group_id', $pixelItem);
        self::assertArrayNotHasKey('variant_dict', $pixelItem);
        self::assertSame('SKU-1', $pixelItem['id']);
    }

    /**
     * The event id is what ties the browser event to its server-side twin, so it
     * is always present in the Pixel options rather than being optional.
     */
    #[Test]
    public function the_pixel_options_always_carry_the_event_id(): void
    {
        self::assertSame(['event_id' => 'lead_88213'], self::lead()->pixelOptions());

        self::assertSame(
            ['event_id' => 'lead_88213', 'opt_out' => true],
            self::lead(optOut: true)->pixelOptions(),
        );

        self::assertSame(
            ['event_id' => 'c_1', 'custom_event_name' => 'quote_requested'],
            self::custom('quote_requested')->pixelOptions(),
        );
    }

    #[Test]
    public function without_identity_keeps_everything_except_the_user(): void
    {
        $event = self::lead(user: UserData::create(email: 'ada@example.com'), oppref: 'opp-1');

        $stripped = $event->withoutIdentity();

        self::assertNull($stripped->user);
        self::assertArrayNotHasKey('user', $stripped->toCapiArray());
        // oppref is an EVENT field, not identity, and must survive.
        self::assertSame('opp-1', $stripped->oppref);
        self::assertSame($event->id->value, $stripped->id->value);
        self::assertSame($event->timestampMs, $stripped->timestampMs);
    }

    #[Test]
    public function without_identity_leaves_the_original_untouched(): void
    {
        $event = self::lead(user: UserData::create(email: 'ada@example.com'));

        $event->withoutIdentity();

        self::assertNotNull($event->user);
    }

    private static function lead(
        int $timestampMs = 1789041600000,
        ?string $sourceUrl = 'https://example.com/contact/thank-you',
        ?Money $value = null,
        ?UserData $user = null,
        ?string $oppref = null,
        ?bool $optOut = null,
        ?string $customEventName = null,
    ): Event {
        return Event::create(
            name: EventName::LeadCreated,
            id: EventId::fromBusinessId('lead_88213'),
            timestampMs: $timestampMs,
            actionSource: ActionSource::Web,
            sourceUrl: $sourceUrl,
            value: $value,
            user: $user,
            oppref: $oppref,
            optOut: $optOut,
            customEventName: $customEventName,
        );
    }

    private static function custom(string $name): Event
    {
        return Event::create(
            name: EventName::Custom,
            id: EventId::fromBusinessId('c_1'),
            timestampMs: 1789041600000,
            actionSource: ActionSource::Web,
            sourceUrl: 'https://example.com/',
            customEventName: $name,
        );
    }
}
