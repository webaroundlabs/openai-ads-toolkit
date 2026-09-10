<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Tests;

use WebaroundLabs\OpenAIAds\ActionSource;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\EventId;
use WebaroundLabs\OpenAIAds\EventName;

final class EventFactory
{
    public static function lead(?int $timestampMs = null): Event
    {
        return Event::create(
            name: EventName::LeadCreated,
            id: EventId::fromBusinessId('lead_88213'),
            timestampMs: $timestampMs ?? (int) round(microtime(true) * 1000),
            actionSource: ActionSource::Web,
            sourceUrl: 'https://shop.example.com/contact/thank-you',
        );
    }
}
