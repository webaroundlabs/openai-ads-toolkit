<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Capi;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use WebaroundLabs\OpenAIAds\Clock;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\InvalidArgument;

/**
 * Sends events to the OpenAI Ads Conversions API.
 *
 * The API key is server-side only. It must never reach browser code, a frontend
 * environment variable, HTML or a log - which is why this class redacts it from
 * `var_dump()` output (see __debugInfo) and never places it in an exception
 * message.
 *
 * There is no TransportInterface here. PSR-18's ClientInterface already is one,
 * with many implementations and test doubles, so the library takes that and lets
 * each adapter supply its host's client - Laravel from the container, WordPress
 * with a shim over wp_remote_post.
 *
 * There is also no retry. The documented deduplication key implies, but never
 * states, that the API deduplicates on `events[].id`; retrying a request that
 * actually succeeded but whose response was lost would then double-count
 * conversions - invisible, corrupting to the advertiser's optimization, and
 * unrecoverable afterwards. Retry belongs to whichever layer owns durability:
 * configure it on the injected PSR-18 client, or on the queue that wraps this
 * call. Reuse the same Event objects when you do, so the event id and timestamp
 * stay identical.
 */
final class Client
{
    public const ENDPOINT = 'https://bzr.openai.com/v1/events';

    public const MAX_BATCH_SIZE = 1000;

    public const DEFAULT_INTEGRATION_SOURCE = 'webaroundlabs-openai-ads-toolkit';

    /** Events older than this are refused. Mirrors limits.timestamp_max_age_ms in the spec. */
    public const MAX_AGE_MS = 604800000;

    /** Clock-skew tolerance. Mirrors limits.timestamp_max_future_ms in the spec. */
    public const MAX_FUTURE_MS = 600000;

    /** @internal Mirrors patterns.integration_source in the spec; not part of the contract. */
    public const INTEGRATION_SOURCE_PATTERN = '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$';

    /**
     * @param string $integrationSource Identifies this integration to OpenAI. Defaulted rather
     *                                  than nullable so it is actually sent; adapters may pass
     *                                  their own.
     *
     * @throws InvalidArgument
     */
    public function __construct(
        private readonly string $pixelId,
        private readonly string $apiKey,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly Clock $clock,
        private readonly string $integrationSource = self::DEFAULT_INTEGRATION_SOURCE,
    ) {
        if (trim($pixelId) === '') {
            throw new InvalidArgument('Pixel id must not be empty.');
        }

        if (trim($apiKey) === '') {
            // Deliberately says nothing about the value.
            throw new InvalidArgument('Conversions API key must not be empty.');
        }

        if (preg_match('/' . self::INTEGRATION_SOURCE_PATTERN . '/', $integrationSource) !== 1) {
            throw new InvalidArgument(sprintf(
                'integration_source must be 1-64 ASCII characters starting with a letter or '
                . 'digit, followed by letters, digits, periods, underscores or hyphens; got "%s".',
                $integrationSource,
            ));
        }
    }

    /**
     * Send a batch.
     *
     * A completed HTTP round trip returns a Response whatever its status code;
     * only a failed round trip throws. Check `$response->isSuccessful()`.
     *
     * This is unlike most SDKs, and it is deliberate: mapping status codes onto
     * exception types would mean inventing the taxonomy OpenAI has not published
     * - is 202 success, is 207 partial, does 429 exist and with which header. If
     * that changes, an opt-in throwing mode can be added without breaking
     * callers.
     *
     * @param list<Event> $events 1 to 1000 events
     *
     * @throws InvalidArgument   the batch is empty, oversized, or contains an event whose
     *                           timestamp has fallen outside the accepted window
     * @throws TransportException no HTTP round trip completed
     */
    public function send(array $events, bool $validateOnly = false): Response
    {
        $this->guardBatchSize($events);
        $this->guardFreshness($events);

        $request = $this->requests
            ->createRequest('POST', self::ENDPOINT . '?pid=' . rawurlencode($this->pixelId))
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream($this->encode($events, $validateOnly)));

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // The original is attached as $previous; the message names no
            // credential and carries no payload.
            throw new TransportException(
                'The Conversions API request did not complete; the events were not delivered.',
                0,
                $e,
            );
        }

        /** @var array<string, list<string>> $headers */
        $headers = $response->getHeaders();

        return new Response($response->getStatusCode(), $headers, (string) $response->getBody());
    }

    /**
     * Validate a batch against the API without persisting it.
     *
     * `validate_only` is the one documented feedback channel, which makes this
     * the honest way to test an integration end to end.
     *
     * @param list<Event> $events
     *
     * @throws InvalidArgument
     * @throws TransportException
     */
    public function validate(array $events): Response
    {
        return $this->send($events, true);
    }

    /**
     * Keeps the API key out of stack traces, debug pages and dump output.
     *
     * Laravel's error page and most debug helpers dump objects wholesale, and a
     * readonly private property is not private to var_dump().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'pixelId' => $this->pixelId,
            'apiKey' => '[redacted]',
            'integrationSource' => $this->integrationSource,
            'http' => $this->http::class,
        ];
    }

    /**
     * @param list<Event> $events
     *
     * @throws InvalidArgument
     */
    private function guardBatchSize(array $events): void
    {
        if ($events === []) {
            throw new InvalidArgument('A batch must contain at least one event.');
        }

        $count = count($events);

        if ($count > self::MAX_BATCH_SIZE) {
            throw new InvalidArgument(sprintf(
                'A batch may contain at most %d events; got %d. Split the batch.',
                self::MAX_BATCH_SIZE,
                $count,
            ));
        }
    }

    /**
     * Reject events whose timestamp has fallen outside the accepted window.
     *
     * This is checked here rather than in Event::create() because the window is
     * relative to *send* time: an event that was valid when it was built can go
     * stale sitting in a queue. Since one rejected event fails the entire batch,
     * catching it locally turns the loss of up to 999 other events into a clear
     * error naming the offender.
     *
     * @param list<Event> $events
     *
     * @throws InvalidArgument
     */
    private function guardFreshness(array $events): void
    {
        $now = $this->clock->nowMs();

        foreach ($events as $event) {
            $age = $now - $event->timestampMs;

            if ($age > self::MAX_AGE_MS) {
                throw new InvalidArgument(sprintf(
                    'Event "%s" is %d days old; the Conversions API accepts events from the '
                    . 'last 7 days only. Because a batch fails as a whole, it was not sent.',
                    $event->id->value,
                    intdiv($age, 86400000),
                ));
            }

            if (-$age > self::MAX_FUTURE_MS) {
                throw new InvalidArgument(sprintf(
                    'Event "%s" is timestamped %d seconds in the future; the Conversions API '
                    . 'allows at most 10 minutes. Check the server clock.',
                    $event->id->value,
                    intdiv(-$age, 1000),
                ));
            }
        }
    }

    /**
     * @param list<Event> $events
     */
    private function encode(array $events, bool $validateOnly): string
    {
        $body = ['integration_source' => $this->integrationSource];

        // Only sent when true: absent and false are not knowably equivalent, and
        // the documented default is not to validate.
        if ($validateOnly) {
            $body['validate_only'] = true;
        }

        $body['events'] = array_map(
            static fn (Event $event): array => $event->toCapiArray(),
            $events,
        );

        return json_encode(
            $body,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
