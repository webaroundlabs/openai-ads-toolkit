<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel\Tests;

use PHPUnit\Framework\Attributes\Test;
use WebaroundLabs\OpenAIAds\ActionSource;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\EventId;
use WebaroundLabs\OpenAIAds\EventName;
use WebaroundLabs\OpenAIAds\Laravel\Measurement;
use WebaroundLabs\OpenAIAds\UserData;

/**
 * The no-JavaScript channel, as the adapter exposes it: an email body, an AMP
 * page, a <noscript> fallback. It earns its place by carrying the same event id
 * as the server event, so the two deduplicate.
 */
final class ImageTagTest extends TestCase
{
    #[Test]
    public function it_builds_a_url_carrying_the_event_id(): void
    {
        $url = $this->measurement()->imageTagUrl($this->lead());

        self::assertNotNull($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame(self::PIXEL_ID, $query['pid']);
        self::assertSame('lead_created', $query['event']);
        self::assertSame('lead_88213', $query['event_id']);
    }

    #[Test]
    public function the_url_never_contains_the_conversions_api_key(): void
    {
        $url = (string) $this->measurement()->imageTagUrl($this->lead());

        self::assertStringNotContainsString(self::CAPI_KEY, $url);
    }

    /**
     * OpenAI documents no user object for this channel and forbids personal data
     * in a query parameter, so an event carrying identity produces no URL at all
     * until the caller strips it deliberately.
     */
    #[Test]
    public function an_event_carrying_identity_produces_nothing_until_it_is_stripped(): void
    {
        $event = Event::create(
            name: EventName::LeadCreated,
            id: EventId::fromBusinessId('lead_1'),
            timestampMs: (int) round(microtime(true) * 1000),
            actionSource: ActionSource::Web,
            sourceUrl: 'https://shop.example.com/thank-you',
            user: UserData::create(email: 'ada@example.com'),
        );

        self::assertNull($this->measurement()->imageTagUrl($event));

        $url = (string) $this->measurement()->imageTagUrl($event->withoutIdentity());

        self::assertStringContainsString('lead_1', $url);
        self::assertStringNotContainsString('ada@example.com', urldecode($url));
    }

    #[Test]
    public function nothing_is_measured_when_the_pixel_is_switched_off(): void
    {
        config(['openai-ads.pixel_enabled' => false]);

        self::assertNull($this->measurement()->imageTagUrl($this->lead()));
    }

    private function measurement(): Measurement
    {
        return $this->app->make(Measurement::class);
    }
}
