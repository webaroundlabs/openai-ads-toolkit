<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel\Tests;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use WebaroundLabs\OpenAIAds\Capi\Client;
use WebaroundLabs\OpenAIAds\Laravel\Facades\OpenAIAds;
use WebaroundLabs\OpenAIAds\Laravel\Jobs\SendConversionEvents;
use WebaroundLabs\OpenAIAds\Laravel\Measurement;

#[CoversClass(Measurement::class)]
final class MeasurementTest extends TestCase
{
    #[Test]
    public function it_sends_an_event_through_the_core_client(): void
    {
        $http = $this->fakeHttp();

        $response = OpenAIAds::send($this->lead());

        self::assertNotNull($response);
        self::assertTrue($response->isSuccessful());
        self::assertSame(1, $http->callCount);
        self::assertSame('webaroundlabs-laravel', $http->body()['integration_source']);
        self::assertCount(1, $http->body()['events']);
    }

    #[Test]
    public function the_pixel_id_reaches_the_query_string_and_the_key_reaches_the_header(): void
    {
        $http = $this->fakeHttp();

        OpenAIAds::send($this->lead());

        self::assertNotNull($http->lastRequest);
        self::assertStringContainsString('pid=px-test', (string) $http->lastRequest->getUri());
        self::assertSame(
            'Bearer ' . self::CAPI_KEY,
            $http->lastRequest->getHeaderLine('Authorization'),
        );
    }

    #[Test]
    public function nothing_is_sent_when_measurement_is_disabled(): void
    {
        config(['openai-ads.enabled' => false]);
        $http = $this->fakeHttp();

        self::assertNull(OpenAIAds::send($this->lead()));
        self::assertSame(0, $http->callCount);
    }

    #[Test]
    public function nothing_is_sent_and_a_warning_is_logged_when_credentials_are_missing(): void
    {
        config(['openai-ads.capi_key' => null]);
        $http = $this->fakeHttp();
        Log::shouldReceive('warning')->once()->withArgs(
            static fn (string $message): bool => str_contains($message, 'OPENAI_ADS_CAPI_KEY'),
        );

        self::assertNull(OpenAIAds::send($this->lead()));
        self::assertSame(0, $http->callCount);
    }

    /**
     * A refused consent is a normal outcome, so it produces no error and no log
     * noise - it simply does not happen.
     */
    #[Test]
    public function a_refused_consent_is_a_silent_no_op(): void
    {
        config(['openai-ads.consent' => static fn (): bool => false]);
        $http = $this->fakeHttp();
        Log::shouldReceive('warning')->never();
        Log::shouldReceive('error')->never();

        self::assertNull(OpenAIAds::send($this->lead()));
        self::assertSame(0, $http->callCount);
    }

    #[Test]
    public function consent_defaults_to_the_host_applications_own_gating(): void
    {
        // No callback configured: the toolkit does not invent a privacy model.
        self::assertTrue(OpenAIAds::consented());
    }

    /**
     * The rule that matters most in an adapter: reporting a conversion must
     * never be able to fail the thing that produced it.
     */
    #[Test]
    public function a_transport_failure_is_logged_and_swallowed(): void
    {
        $broken = new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {};
        $this->app->instance(ClientInterface::class, new FakeHttpClient(throw: $broken));
        $this->app->forgetInstance(Client::class);
        Log::shouldReceive('warning')->once();

        self::assertNull(OpenAIAds::send($this->lead()));
    }

    #[Test]
    public function a_stale_event_is_logged_as_an_error_and_not_sent(): void
    {
        $http = $this->fakeHttp();
        $eightDaysAgo = (int) round(microtime(true) * 1000) - 8 * 86400000;
        Log::shouldReceive('error')->once()->withArgs(
            static fn (string $message): bool => str_contains($message, 'rejected before sending'),
        );

        self::assertNull(OpenAIAds::send($this->lead($eightDaysAgo)));
        self::assertSame(0, $http->callCount);
    }

    #[Test]
    public function an_unsuccessful_response_is_returned_rather_than_thrown(): void
    {
        $this->app->instance(ClientInterface::class, new FakeHttpClient(status: 400, body: '{"error":1}'));
        $this->app->forgetInstance(Client::class);

        $response = OpenAIAds::send($this->lead());

        self::assertNotNull($response);
        self::assertFalse($response->isSuccessful());
        self::assertSame(400, $response->statusCode);
    }

    #[Test]
    public function validate_uses_the_documented_validation_mode(): void
    {
        $http = $this->fakeHttp();

        OpenAIAds::validate($this->lead());

        self::assertTrue($http->body()['validate_only']);
    }

    #[Test]
    public function validate_still_works_when_measurement_is_switched_off(): void
    {
        // So an integration can be proven on staging with sending disabled.
        config(['openai-ads.enabled' => false]);
        $http = $this->fakeHttp();

        self::assertNotNull(OpenAIAds::validate($this->lead()));
        self::assertSame(1, $http->callCount);
    }

    #[Test]
    public function validate_only_config_makes_every_send_a_dry_run(): void
    {
        config(['openai-ads.validate_only' => true]);
        $http = $this->fakeHttp();

        OpenAIAds::send($this->lead());

        self::assertTrue($http->body()['validate_only']);
    }

    #[Test]
    public function queueing_dispatches_the_delivery_job(): void
    {
        config(['openai-ads.queue.enabled' => true, 'openai-ads.queue.queue' => 'measurement']);
        Queue::fake();

        OpenAIAds::queue($this->lead());

        Queue::assertPushedOn('measurement', SendConversionEvents::class);
    }

    #[Test]
    public function queueing_falls_back_to_an_inline_send_when_the_queue_is_off(): void
    {
        config(['openai-ads.queue.enabled' => false]);
        $http = $this->fakeHttp();
        Queue::fake();

        OpenAIAds::queue($this->lead());

        Queue::assertNothingPushed();
        self::assertSame(1, $http->callCount);
    }

    #[Test]
    public function queueing_is_skipped_entirely_when_consent_is_refused(): void
    {
        config(['openai-ads.queue.enabled' => true, 'openai-ads.consent' => static fn (): bool => false]);
        Queue::fake();

        OpenAIAds::queue($this->lead());

        Queue::assertNothingPushed();
    }

    #[Test]
    public function sending_no_events_does_nothing(): void
    {
        $http = $this->fakeHttp();

        self::assertNull(OpenAIAds::send());
        self::assertSame(0, $http->callCount);
    }

    private function fakeHttp(int $status = 200): FakeHttpClient
    {
        $fake = new FakeHttpClient(status: $status);
        $this->app->instance(ClientInterface::class, $fake);
        $this->app->forgetInstance(Client::class);

        return $fake;
    }
}
