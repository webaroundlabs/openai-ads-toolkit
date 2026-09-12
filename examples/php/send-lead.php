<?php

/**
 * Send one lead through the Conversions API, with no framework.
 *
 *   composer require webaround/openai-ads guzzlehttp/guzzle
 *   OPENAI_ADS_PIXEL_ID=... OPENAI_ADS_CAPI_KEY=... php send-lead.php
 *
 * Nothing here is WordPress- or Laravel-specific. Any PSR-18 client works;
 * Guzzle is used because it is the one most projects already have.
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use WebaroundLabs\OpenAIAds\ActionSource;
use WebaroundLabs\OpenAIAds\Capi\Client;
use WebaroundLabs\OpenAIAds\Capi\TransportException;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\EventId;
use WebaroundLabs\OpenAIAds\EventName;
use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\SystemClock;
use WebaroundLabs\OpenAIAds\UserData;

$pixelId = getenv('OPENAI_ADS_PIXEL_ID') ?: '';
$apiKey = getenv('OPENAI_ADS_CAPI_KEY') ?: '';

if ($pixelId === '' || $apiKey === '') {
    fwrite(STDERR, "Set OPENAI_ADS_PIXEL_ID and OPENAI_ADS_CAPI_KEY first.\n");
    exit(1);
}

$factory = new HttpFactory();
$clock = new SystemClock();

$client = new Client(
    pixelId: $pixelId,
    // Server-side only. Never let this reach browser code, a public
    // environment variable, or a log.
    apiKey: $apiKey,
    http: new Guzzle(['timeout' => 5, 'connect_timeout' => 2, 'http_errors' => false]),
    requests: $factory,
    streams: $factory,
    clock: $clock,
);

/*
 * The event id ties this to the browser event for the same conversion. Prefer
 * an id the application already owns - here, the lead's own primary key - over
 * minting one, and give the same value to the browser.
 */
$leadId = 'lead_88213';

try {
    $event = Event::create(
        name: EventName::LeadCreated,
        id: EventId::fromBusinessId($leadId),
        // Stamped when the conversion happened, not when it is sent, so a
        // retry from a queue carries the original moment.
        timestampMs: $clock->nowMs(),
        actionSource: ActionSource::Web,
        sourceUrl: 'https://example.com/contact/thank-you',
        user: UserData::create(
            email: 'ada@example.com',      // raw; normalized and hashed here
            country: 'RO',
        ),
    );
} catch (InvalidArgument $e) {
    // A programming error rather than an outage: the payload was never valid.
    fwrite(STDERR, 'Rejected before sending: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Sending:\n", json_encode($event->toCapiArray(), JSON_PRETTY_PRINT), "\n\n";

try {
    /*
     * validate() uses the API's documented validation mode: the event is
     * checked but never recorded, so running this example cannot create a
     * conversion that did not happen. Swap it for send() when you mean it.
     */
    $response = $client->validate([$event]);
} catch (TransportException $e) {
    // No round trip completed, so the event definitely did not arrive.
    fwrite(STDERR, 'Could not reach the API: ' . $e->getMessage() . "\n");
    exit(1);
}

/*
 * A completed request returns whatever the status was. Only a failed round trip
 * throws, because turning status codes into exception types would mean
 * inventing a taxonomy OpenAI has not published.
 */
printf("HTTP %d%s\n", $response->statusCode, $response->isSuccessful() ? ' - accepted' : '');
echo $response->body, "\n";

exit($response->isSuccessful() ? 0 : 1);
