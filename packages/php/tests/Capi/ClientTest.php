<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Tests\Capi;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use WebaroundLabs\OpenAIAds\ActionSource;
use WebaroundLabs\OpenAIAds\Capi\Client;
use WebaroundLabs\OpenAIAds\Capi\Response;
use WebaroundLabs\OpenAIAds\Capi\TransportException;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\EventId;
use WebaroundLabs\OpenAIAds\EventName;
use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\Tests\FrozenClock;
use WebaroundLabs\OpenAIAds\Tests\RecordingHttpClient;
use WebaroundLabs\OpenAIAds\Tests\Spec;
use WebaroundLabs\OpenAIAds\UserData;

#[CoversClass(Client::class)]
#[CoversClass(Response::class)]
#[CoversClass(TransportException::class)]
final class ClientTest extends TestCase
{
    private const NOW = 1789041600000;

    private const API_KEY = 'test-key-do-not-log-me';

    #[Test]
    public function it_posts_to_the_documented_endpoint_with_the_pixel_id_in_the_query(): void
    {
        $http = new RecordingHttpClient();

        $this->client($http)->send([$this->lead()]);

        $request = $http->lastRequest;
        self::assertNotNull($request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://bzr.openai.com/v1/events?pid=px-123', (string) $request->getUri());
    }

    #[Test]
    public function a_pixel_id_needing_escaping_is_url_encoded(): void
    {
        $http = new RecordingHttpClient();

        $this->client($http, pixelId: 'px 1/2&3')->send([$this->lead()]);

        self::assertNotNull($http->lastRequest);
        self::assertStringEndsWith('?pid=px%201%2F2%263', (string) $http->lastRequest->getUri());
    }

    #[Test]
    public function it_authenticates_with_a_bearer_token(): void
    {
        $http = new RecordingHttpClient();

        $this->client($http)->send([$this->lead()]);

        self::assertNotNull($http->lastRequest);
        self::assertSame('Bearer ' . self::API_KEY, $http->lastRequest->getHeaderLine('Authorization'));
        self::assertSame('application/json', $http->lastRequest->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function the_request_body_matches_the_golden_fixture(): void
    {
        $fixture = Spec::load('fixtures/lead_created.minimal.capi.json');
        $input = $fixture['input'];
        $http = new RecordingHttpClient();

        $event = Event::create(
            name: EventName::LeadCreated,
            id: EventId::fromBusinessId($input['id']),
            timestampMs: $input['timestamp_ms'],
            actionSource: ActionSource::Web,
            sourceUrl: $input['source_url'],
            user: UserData::create(email: $input['user']['email']),
        );

        $this->client($http)->send([$event]);

        self::assertSame($fixture['expected_request'], $http->decodedRequestBody());
    }

    #[Test]
    public function validate_only_is_absent_unless_requested(): void
    {
        $http = new RecordingHttpClient();

        $this->client($http)->send([$this->lead()]);

        self::assertArrayNotHasKey('validate_only', $http->decodedRequestBody());
    }

    #[Test]
    public function validate_sends_validate_only_true(): void
    {
        $http = new RecordingHttpClient();

        $this->client($http)->validate([$this->lead()]);

        self::assertTrue($http->decodedRequestBody()['validate_only']);
    }

    #[Test]
    public function the_integration_source_is_sent_by_default(): void
    {
        $http = new RecordingHttpClient();

        $this->client($http)->send([$this->lead()]);

        self::assertSame(
            Client::DEFAULT_INTEGRATION_SOURCE,
            $http->decodedRequestBody()['integration_source'],
        );
    }

    #[Test]
    public function an_adapter_may_override_the_integration_source(): void
    {
        $http = new RecordingHttpClient();

        $this->client($http, integrationSource: 'webaroundlabs-wordpress')->send([$this->lead()]);

        self::assertSame('webaroundlabs-wordpress', $http->decodedRequestBody()['integration_source']);
    }

    #[Test]
    #[DataProvider('invalidIntegrationSources')]
    public function an_invalid_integration_source_is_rejected_at_construction(string $source): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('integration_source must be');

        $this->client(new RecordingHttpClient(), integrationSource: $source);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIntegrationSources(): iterable
    {
        yield 'empty' => [''];
        yield 'leading hyphen' => ['-webaround'];
        yield 'space' => ['web around'];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'non-ascii' => ['webaround-ș'];
    }

    #[Test]
    public function an_empty_batch_is_rejected_without_a_request(): void
    {
        $http = new RecordingHttpClient();

        try {
            $this->client($http)->send([]);
            self::fail('Expected an empty batch to be rejected.');
        } catch (InvalidArgument $e) {
            self::assertStringContainsString('at least one event', $e->getMessage());
        }

        self::assertSame(0, $http->callCount, 'No HTTP request should be attempted.');
    }

    #[Test]
    public function a_batch_of_exactly_the_maximum_size_is_accepted(): void
    {
        $http = new RecordingHttpClient();
        $events = array_fill(0, Client::MAX_BATCH_SIZE, $this->lead());

        $this->client($http)->send($events);

        self::assertCount(Client::MAX_BATCH_SIZE, $http->decodedRequestBody()['events']);
    }

    #[Test]
    public function an_oversized_batch_is_rejected_without_a_request(): void
    {
        $http = new RecordingHttpClient();
        $events = array_fill(0, Client::MAX_BATCH_SIZE + 1, $this->lead());

        try {
            $this->client($http)->send($events);
            self::fail('Expected an oversized batch to be rejected.');
        } catch (InvalidArgument $e) {
            self::assertStringContainsString('at most 1000 events; got 1001', $e->getMessage());
        }

        self::assertSame(0, $http->callCount);
    }

    /**
     * A batch fails as a whole, so a single stale event would discard up to 999
     * good ones and return an error this library cannot interpret. Better to
     * fail locally and name the offender.
     */
    #[Test]
    public function an_event_older_than_the_window_is_rejected_and_named(): void
    {
        $http = new RecordingHttpClient();
        $eightDays = 8 * 86400000;

        try {
            $this->client($http)->send([$this->lead(timestampMs: self::NOW - $eightDays)]);
            self::fail('Expected a stale event to be rejected.');
        } catch (InvalidArgument $e) {
            self::assertStringContainsString('lead_88213', $e->getMessage());
            self::assertStringContainsString('8 days old', $e->getMessage());
            self::assertStringContainsString('last 7 days', $e->getMessage());
        }

        self::assertSame(0, $http->callCount);
    }

    #[Test]
    public function an_event_at_the_edge_of_the_window_is_still_sent(): void
    {
        $http = new RecordingHttpClient();
        $sevenDays = 7 * 86400000;

        $this->client($http)->send([$this->lead(timestampMs: self::NOW - $sevenDays)]);

        self::assertSame(1, $http->callCount);
    }

    #[Test]
    public function an_event_too_far_in_the_future_is_rejected(): void
    {
        $http = new RecordingHttpClient();

        try {
            $this->client($http)->send([$this->lead(timestampMs: self::NOW + 11 * 60 * 1000)]);
            self::fail('Expected a future-dated event to be rejected.');
        } catch (InvalidArgument $e) {
            self::assertStringContainsString('in the future', $e->getMessage());
            self::assertStringContainsString('server clock', $e->getMessage());
        }

        self::assertSame(0, $http->callCount);
    }

    #[Test]
    public function a_small_clock_skew_into_the_future_is_tolerated(): void
    {
        $http = new RecordingHttpClient();

        $this->client($http)->send([$this->lead(timestampMs: self::NOW + 9 * 60 * 1000)]);

        self::assertSame(1, $http->callCount);
    }

    /**
     * The deliberate departure from SDK convention: a completed round trip
     * returns, whatever the status. Mapping codes onto exception types would
     * mean inventing a taxonomy OpenAI has not published.
     */
    #[Test]
    #[DataProvider('unsuccessfulStatuses')]
    public function a_non_2xx_response_is_returned_rather_than_thrown(int $status): void
    {
        $http = new RecordingHttpClient($status, '{"error":"nope"}');

        $response = $this->client($http)->send([$this->lead()]);

        self::assertFalse($response->isSuccessful());
        self::assertSame($status, $response->statusCode);
        self::assertSame('{"error":"nope"}', $response->body);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unsuccessfulStatuses(): iterable
    {
        yield '400' => [400];
        yield '401' => [401];
        yield '429' => [429];
        yield '500' => [500];
        yield '503' => [503];
    }

    #[Test]
    public function a_successful_response_exposes_status_headers_and_raw_body(): void
    {
        $http = new RecordingHttpClient(200, '{"ok":true}', ['X-Request-Id' => 'abc123']);

        $response = $this->client($http)->send([$this->lead()]);

        self::assertTrue($response->isSuccessful());
        self::assertSame(200, $response->statusCode);
        self::assertSame('{"ok":true}', $response->body);
        self::assertSame('abc123', $response->header('x-request-id'));
        self::assertNull($response->header('X-Absent'));
    }

    #[Test]
    public function a_failed_round_trip_throws_a_transport_exception(): void
    {
        $underlying = new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {};
        $http = new RecordingHttpClient(throw: $underlying);

        try {
            $this->client($http)->send([$this->lead()]);
            self::fail('Expected a TransportException.');
        } catch (TransportException $e) {
            self::assertStringContainsString('did not complete', $e->getMessage());
            self::assertSame($underlying, $e->getPrevious());
        }
    }

    #[Test]
    public function the_api_key_never_appears_in_a_transport_exception(): void
    {
        $underlying = new class ('boom') extends \RuntimeException implements ClientExceptionInterface {};
        $http = new RecordingHttpClient(throw: $underlying);

        try {
            $this->client($http)->send([$this->lead()]);
            self::fail('Expected a TransportException.');
        } catch (TransportException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, (string) $e);
        }
    }

    /**
     * Debug pages and dump helpers print objects wholesale, and a readonly
     * private property is not private to var_dump().
     */
    #[Test]
    public function dumping_the_client_redacts_the_api_key(): void
    {
        $client = $this->client(new RecordingHttpClient());

        $dump = print_r($client, true);

        self::assertStringNotContainsString(self::API_KEY, $dump);
        self::assertStringContainsString('[redacted]', $dump);
        self::assertStringContainsString('px-123', $dump);
    }

    #[Test]
    public function an_empty_pixel_id_or_api_key_is_rejected(): void
    {
        $factory = new Psr17Factory();

        try {
            new Client('', 'key', new RecordingHttpClient(), $factory, $factory, new FrozenClock(self::NOW));
            self::fail('Expected an empty pixel id to be rejected.');
        } catch (InvalidArgument $e) {
            self::assertStringContainsString('Pixel id', $e->getMessage());
        }

        try {
            new Client('px-123', '  ', new RecordingHttpClient(), $factory, $factory, new FrozenClock(self::NOW));
            self::fail('Expected an empty API key to be rejected.');
        } catch (InvalidArgument $e) {
            self::assertStringContainsString('API key must not be empty', $e->getMessage());
        }
    }

    #[Test]
    public function unicode_in_the_payload_is_not_escaped(): void
    {
        $http = new RecordingHttpClient();
        $event = $this->lead(user: UserData::create(city: 'București'));

        $this->client($http)->send([$event]);

        self::assertNotNull($http->lastRequest);
        self::assertStringContainsString('București', (string) $http->lastRequest->getBody());
    }

    private function client(
        RecordingHttpClient $http,
        string $pixelId = 'px-123',
        string $integrationSource = Client::DEFAULT_INTEGRATION_SOURCE,
    ): Client {
        $factory = new Psr17Factory();

        return new Client(
            pixelId: $pixelId,
            apiKey: self::API_KEY,
            http: $http,
            requests: $factory,
            streams: $factory,
            clock: new FrozenClock(self::NOW),
            integrationSource: $integrationSource,
        );
    }

    private function lead(int $timestampMs = self::NOW, ?UserData $user = null): Event
    {
        return Event::create(
            name: EventName::LeadCreated,
            id: EventId::fromBusinessId('lead_88213'),
            timestampMs: $timestampMs,
            actionSource: ActionSource::Web,
            sourceUrl: 'https://example.com/contact/thank-you',
            user: $user,
        );
    }
}
