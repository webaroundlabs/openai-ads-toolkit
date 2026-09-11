<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel\Tests;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use WebaroundLabs\OpenAIAds\Capi\Client;
use WebaroundLabs\OpenAIAds\Capi\TransportException;
use WebaroundLabs\OpenAIAds\Laravel\Jobs\SendConversionEvents;

#[CoversClass(SendConversionEvents::class)]
final class SendConversionEventsTest extends TestCase
{
    #[Test]
    public function it_delivers_the_batch(): void
    {
        $http = $this->fakeHttp();

        $this->dispatchNow(new SendConversionEvents([$this->lead()]));

        self::assertSame(1, $http->callCount);
    }

    /**
     * The retry policy turns on the distinction the core draws between a failed
     * round trip and an unsuccessful response.
     *
     * Nothing was delivered here, so replaying the same events is safe - and
     * because the job carries the constructed Event objects, the replay reuses
     * the original ids and timestamps rather than minting new ones.
     */
    #[Test]
    public function a_failed_round_trip_is_rethrown_so_the_queue_retries_it(): void
    {
        $refused = new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {};
        $this->app->instance(ClientInterface::class, new FakeHttpClient(throw: $refused));
        $this->app->forgetInstance(Client::class);

        $this->expectException(TransportException::class);

        $this->dispatchNow(new SendConversionEvents([$this->lead()]), attempts: 1);
    }

    #[Test]
    public function it_stops_retrying_a_failed_round_trip_once_the_attempts_are_exhausted(): void
    {
        $refused = new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {};
        $this->app->instance(ClientInterface::class, new FakeHttpClient(throw: $refused));
        $this->app->forgetInstance(Client::class);
        config(['openai-ads.queue.tries' => 3]);
        Log::shouldReceive('error')->once()->withArgs(
            static fn (string $message): bool => str_contains($message, 'failed permanently'),
        );

        $job = new SendConversionEvents([$this->lead()]);

        // No exception: the job gives up rather than looping forever.
        $this->dispatchNow($job, attempts: 3);
    }

    /**
     * The request arrived and the API refused it. Repeating it cannot fix the
     * payload, and might duplicate the conversion, so the batch is dropped.
     */
    #[Test]
    public function an_unsuccessful_response_is_logged_and_never_retried(): void
    {
        $this->app->instance(ClientInterface::class, new FakeHttpClient(status: 400, body: '{"error":"bad"}'));
        $this->app->forgetInstance(Client::class);
        Log::shouldReceive('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => str_contains($message, 'rejected the batch')
                && $context['status'] === 400,
        );

        $this->dispatchNow(new SendConversionEvents([$this->lead()]));
    }

    #[Test]
    public function an_event_that_aged_out_while_queued_is_dropped_rather_than_retried_forever(): void
    {
        $http = $this->fakeHttp();
        $eightDaysAgo = (int) round(microtime(true) * 1000) - 8 * 86400000;
        Log::shouldReceive('error')->once()->withArgs(
            static fn (string $message): bool => str_contains($message, 'dropping'),
        );

        $this->dispatchNow(new SendConversionEvents([$this->lead($eightDaysAgo)]));

        self::assertSame(0, $http->callCount, 'A stale batch must not reach the API.');
    }

    #[Test]
    public function an_empty_batch_does_nothing(): void
    {
        $http = $this->fakeHttp();

        $this->dispatchNow(new SendConversionEvents([]));

        self::assertSame(0, $http->callCount);
    }

    #[Test]
    public function the_retry_schedule_comes_from_configuration(): void
    {
        config(['openai-ads.queue.tries' => 2, 'openai-ads.queue.backoff' => [5, 10]]);

        $job = new SendConversionEvents([$this->lead()]);

        self::assertSame(2, $job->tries());
        self::assertSame([5, 10], $job->backoff());
    }

    /**
     * The job payload carries constructed events, so a retry replays the same
     * ids and timestamps. A retry that re-minted either would be a second
     * conversion rather than a retry.
     */
    #[Test]
    public function serializing_the_job_preserves_the_event_id_and_timestamp(): void
    {
        $event = $this->lead(1789041600000);

        $revived = unserialize(serialize(new SendConversionEvents([$event])));
        $http = $this->fakeHttp();
        $this->dispatchNow($revived);

        $sent = $http->body()['events'][0];
        self::assertSame('lead_88213', $sent['id']);
        self::assertSame(1789041600000, $sent['timestamp_ms']);
    }

    private function dispatchNow(SendConversionEvents $job, int $attempts = 1): void
    {
        if ($attempts > 1) {
            // Stands in for the queue's own job record, which is what reports
            // how many times this payload has already been attempted.
            $job->setJob(new FakeQueueJob($attempts));
        }

        $job->handle(
            $this->app->make(Client::class),
            $this->app->make(\Psr\Log\LoggerInterface::class),
            $this->app->make(\Illuminate\Contracts\Config\Repository::class),
        );
    }

    private function fakeHttp(): FakeHttpClient
    {
        $fake = new FakeHttpClient();
        $this->app->instance(ClientInterface::class, $fake);
        $this->app->forgetInstance(Client::class);

        return $fake;
    }
}
