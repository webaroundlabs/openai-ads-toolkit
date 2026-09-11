<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebaroundLabs\OpenAIAds\ActionSource;
use WebaroundLabs\OpenAIAds\Content;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\EventId;
use WebaroundLabs\OpenAIAds\EventName;
use WebaroundLabs\OpenAIAds\ImageTag;
use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\Money;
use WebaroundLabs\OpenAIAds\UserData;

#[CoversClass(ImageTag::class)]
final class ImageTagTest extends TestCase
{
    #[Test]
    public function it_builds_the_documented_url(): void
    {
        $url = ImageTag::url('px-123', $this->order());

        self::assertStringStartsWith('https://bzr.openai.com/v1/sdk/events?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('px-123', $query['pid']);
        self::assertSame('order_created', $query['event']);
        self::assertSame('order-9', $query['event_id']);
        self::assertSame('contents', $query['data']['type']);
        self::assertSame('2599', $query['data']['amount']);
        self::assertSame('EUR', $query['data']['currency']);
    }

    /**
     * The upstream instruction is exact: "serialize the contents array as JSON,
     * then URL-encode the complete JSON value".
     */
    #[Test]
    public function the_contents_array_travels_as_url_encoded_json(): void
    {
        $url = ImageTag::url('px-123', $this->order(contents: [
            Content::create(id: 'SKU-1', name: 'Chair', quantity: 2),
        ]));

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame(
            [['id' => 'SKU-1', 'name' => 'Chair', 'quantity' => 2]],
            json_decode($query['data']['contents'], true),
        );
    }

    /**
     * `group_id` and `variant_dict` are documented as server-side only. Sending
     * them here would be accepted and discarded.
     */
    #[Test]
    public function it_strips_the_content_fields_the_browser_cannot_carry(): void
    {
        $url = ImageTag::url('px-123', $this->order(contents: [
            Content::create(id: 'SKU-1', groupId: 'PARENT-1', variantDict: ['size' => 'M']),
        ]));

        self::assertStringNotContainsString('PARENT-1', urldecode($url));
        self::assertStringNotContainsString('variant_dict', urldecode($url));
    }

    /**
     * The reason this channel is worth having at all: an image tag and a
     * Conversions API event describing the same conversion deduplicate.
     */
    #[Test]
    public function the_event_id_is_the_one_the_conversions_api_sends(): void
    {
        $event = $this->order();

        $url = ImageTag::url('px-123', $event);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame($event->toCapiArray()['id'], $query['event_id']);
    }

    #[Test]
    public function a_custom_event_carries_its_name(): void
    {
        $event = Event::create(
            name: EventName::Custom,
            id: EventId::fromBusinessId('q-1'),
            timestampMs: 1789041600000,
            actionSource: ActionSource::Email,
            customEventName: 'quote_requested',
        );

        parse_str((string) parse_url(ImageTag::url('px-123', $event), PHP_URL_QUERY), $query);

        self::assertSame('custom', $query['event']);
        self::assertSame('quote_requested', $query['custom_event_name']);
    }

    #[Test]
    public function an_oppref_is_passed_through(): void
    {
        parse_str(
            (string) parse_url(ImageTag::url('px-123', $this->order(oppref: 'opp-abc')), PHP_URL_QUERY),
            $query,
        );

        self::assertSame('opp-abc', $query['oppref']);
    }

    /**
     * The documentation is explicit that no user object exists and that personal
     * data must not go in a query parameter. Stripping identity silently would
     * lose matching with no signal, so it is refused instead.
     */
    #[Test]
    public function an_event_carrying_identity_is_refused_rather_than_stripped(): void
    {
        $event = $this->order(user: UserData::create(email: 'ada@example.com'));

        try {
            ImageTag::url('px-123', $event);
            self::fail('Expected identity to be refused.');
        } catch (InvalidArgument $e) {
            self::assertStringContainsString('cannot carry identity', $e->getMessage());
            self::assertStringContainsString('withoutIdentity', $e->getMessage());
        }
    }

    #[Test]
    public function without_identity_makes_the_loss_explicit_and_then_allowed(): void
    {
        $event = $this->order(user: UserData::create(email: 'ada@example.com'));

        $url = ImageTag::url('px-123', $event->withoutIdentity());

        self::assertStringNotContainsString('email', $url);
        self::assertStringContainsString('order-9', $url);
    }

    /** A privacy flag that cannot be honoured must not be dropped quietly. */
    #[Test]
    public function an_event_that_opts_out_is_refused(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('opt_out is not a documented image tag parameter');

        ImageTag::url('px-123', $this->order(optOut: true));
    }

    #[Test]
    public function an_empty_pixel_id_is_refused(): void
    {
        $this->expectException(InvalidArgument::class);

        ImageTag::url('  ', $this->order());
    }

    /**
     * @param list<Content> $contents
     */
    private function order(
        array $contents = [],
        ?UserData $user = null,
        ?string $oppref = null,
        ?bool $optOut = null,
    ): Event {
        return Event::create(
            name: EventName::OrderCreated,
            id: EventId::fromBusinessId('order-9'),
            timestampMs: 1789041600000,
            actionSource: ActionSource::Web,
            sourceUrl: 'https://example.com/thank-you',
            value: Money::minor(2599, 'EUR'),
            user: $user,
            oppref: $oppref,
            optOut: $optOut,
            contents: $contents,
        );
    }
}
