<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel\Tests;

use GuzzleHttp\Client as Guzzle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use WebaroundLabs\OpenAIAds\Capi\Client;
use WebaroundLabs\OpenAIAds\Clock;
use WebaroundLabs\OpenAIAds\Laravel\Measurement;
use WebaroundLabs\OpenAIAds\Laravel\OpenAIAdsServiceProvider;
use WebaroundLabs\OpenAIAds\Laravel\RequestContext;
use WebaroundLabs\OpenAIAds\SystemClock;

#[CoversClass(OpenAIAdsServiceProvider::class)]
final class ServiceProviderTest extends TestCase
{
    /**
     * The rule this test exists for: bindings registered in a service provider
     * are evaluated on EVERY request, including the overwhelming majority that
     * never send an event. Constructing an HTTP client at registration time
     * would make installing this package slow down the whole application.
     */
    #[Test]
    public function nothing_expensive_is_constructed_until_something_actually_sends(): void
    {
        self::assertFalse($this->app->resolved(Client::class));
        self::assertFalse($this->app->resolved(ClientInterface::class));

        $this->app->make(Measurement::class);

        // Resolving the facade target still must not open anything.
        self::assertFalse($this->app->resolved(Client::class));
        self::assertFalse($this->app->resolved(ClientInterface::class));
    }

    #[Test]
    public function the_core_client_is_built_from_configuration(): void
    {
        $client = $this->app->make(Client::class);

        self::assertInstanceOf(Client::class, $client);
    }

    #[Test]
    public function it_supplies_a_psr18_client_and_psr17_factories(): void
    {
        self::assertInstanceOf(Guzzle::class, $this->app->make(ClientInterface::class));
        self::assertInstanceOf(RequestFactoryInterface::class, $this->app->make(RequestFactoryInterface::class));
        self::assertInstanceOf(StreamFactoryInterface::class, $this->app->make(StreamFactoryInterface::class));
        self::assertInstanceOf(SystemClock::class, $this->app->make(Clock::class));
    }

    /**
     * An application that already has a configured PSR-18 client - with its own
     * proxy, instrumentation or retry middleware - keeps it. The package binds
     * with bindIf, never over the top.
     */
    #[Test]
    public function an_application_supplied_http_client_is_not_overridden(): void
    {
        $this->app->instance(ClientInterface::class, $mine = new FakeHttpClient());

        self::assertSame($mine, $this->app->make(ClientInterface::class));
    }

    #[Test]
    public function the_facade_resolves_to_the_measurement_service(): void
    {
        self::assertInstanceOf(Measurement::class, $this->app->make('openai-ads'));
        self::assertSame($this->app->make('openai-ads'), $this->app->make(Measurement::class));
    }

    #[Test]
    public function the_request_context_is_resolvable_and_request_scoped(): void
    {
        self::assertInstanceOf(RequestContext::class, $this->app->make(RequestContext::class));
    }

    #[Test]
    public function the_configuration_is_merged_with_working_defaults(): void
    {
        self::assertSame('webaroundlabs-laravel', config('openai-ads.integration_source'));
        self::assertTrue(config('openai-ads.strip_query_string'));
        self::assertSame(5, config('openai-ads.timeout'));
        self::assertNull(config('openai-ads.consent'));
    }

    #[Test]
    public function the_default_integration_source_is_a_valid_one(): void
    {
        // Must satisfy the API's documented pattern, or every request is refused.
        self::assertMatchesRegularExpression(
            '/' . Client::INTEGRATION_SOURCE_PATTERN . '/',
            (string) config('openai-ads.integration_source'),
        );
    }
}
