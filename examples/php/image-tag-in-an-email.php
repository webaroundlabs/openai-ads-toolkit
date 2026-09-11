<?php

/**
 * Measuring a conversion where JavaScript cannot run.
 *
 * An email body, an AMP page, a `<noscript>` fallback - anywhere a script is
 * stripped or never executes. The image tag is a GET request dressed as a 1x1
 * image, and it carries the same `event_id` as the server event, so the two are
 * deduplicated exactly as a Pixel event would be.
 *
 * The channel is deliberately weaker than the other two, and this example is
 * mostly about the weaknesses, because they are what make it easy to misuse.
 *
 * Nothing here sends anything: it prints the markup you would embed.
 *
 *     php examples/php/image-tag-in-an-email.php
 */

declare(strict_types=1);

require __DIR__ . '/../../packages/php/vendor/autoload.php';

use WebaroundLabs\OpenAIAds\ActionSource;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\EventId;
use WebaroundLabs\OpenAIAds\EventName;
use WebaroundLabs\OpenAIAds\ImageTag;
use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\SystemClock;
use WebaroundLabs\OpenAIAds\UserData;

$pixelId = getenv('OPENAI_ADS_PIXEL_ID') ?: 'YOUR-PIXEL-ID';
$clock = new SystemClock();

/*
 * A booking confirmed by a member of staff over the phone. There is no browser
 * involved at all, which is why action_source is `phone_call` and there is no
 * source_url - both are only required for web events.
 */
$booking = Event::create(
    name: EventName::AppointmentScheduled,
    id: EventId::fromBusinessId('booking_5511'),
    timestampMs: $clock->nowMs(),
    actionSource: ActionSource::PhoneCall,
);

echo "A confirmation email's tracking pixel:\n\n";
echo sprintf(
    '<img src="%s" width="1" height="1" alt="" style="display:none">',
    htmlspecialchars(ImageTag::url($pixelId, $booking), ENT_QUOTES, 'UTF-8'),
);
echo "\n\n";

/*
 * The trap this channel sets.
 *
 * OpenAI documents no `user` object for the image tag and states plainly that
 * personal data must not go in a query parameter. An event carrying identity is
 * therefore REFUSED rather than quietly stripped - because losing your matching
 * data without being told is the failure this toolkit exists to prevent.
 */
$withCustomer = Event::create(
    name: EventName::AppointmentScheduled,
    id: EventId::fromBusinessId('booking_5511'),
    timestampMs: $clock->nowMs(),
    actionSource: ActionSource::PhoneCall,
    user: UserData::create(email: 'ada@example.com'),
);

try {
    ImageTag::url($pixelId, $withCustomer);
} catch (InvalidArgument $e) {
    echo "Refused, as it should be:\n  ", $e->getMessage(), "\n\n";
}

/*
 * Say the loss is intended, and it is allowed. `withoutIdentity()` exists so a
 * reader of this line can see what is being given up.
 *
 * Better still: send this conversion through the Conversions API instead, which
 * carries identity properly. Reach for the image tag only where nothing else
 * can run.
 */
echo "Acknowledged explicitly:\n  ";
echo ImageTag::url($pixelId, $withCustomer->withoutIdentity()), "\n\n";

/*
 * Whatever you do, use the SAME id on both sides. This booking reported through
 * the Conversions API as well would carry `booking_5511` there too, and OpenAI
 * counts one conversion rather than two.
 */
echo "The id both channels share: ", $booking->toCapiArray()['id'], "\n";
