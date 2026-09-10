<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use WebaroundLabs\OpenAIAds\Capi\Client;
use WebaroundLabs\OpenAIAds\Clock;
use WebaroundLabs\OpenAIAds\SystemClock;

/**
 * Wires the core into Laravel.
 *
 * Everything is registered as a binding and nothing is resolved here. That is
 * not a stylistic preference: bindings registered in `register()` are evaluated
 * for every request, including requests that never fire an event, so
 * constructing an HTTP client at registration time would make installing this
 * package slow down the whole application. The client is built the first time
 * something actually sends.
 */
final class OpenAIAdsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/openai-ads.php', 'openai-ads');

        // Only bound if the application has not provided its own. An app that
        // already has a configured PSR-18 client - with its own proxy, retry
        // middleware or instrumentation - keeps it.
        $this->app->bindIf(ClientInterface::class, function (Container $app): ClientInterface {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return new Guzzle([
                // Bounded, because a slow ad platform must never hold a
                // checkout open.
                'timeout' => (float) $config->get('openai-ads.timeout', 5),
                'connect_timeout' => (float) $config->get('openai-ads.connect_timeout', 2),
                'http_errors' => false,
            ]);
        });

        $this->app->bindIf(RequestFactoryInterface::class, HttpFactory::class);
        $this->app->bindIf(StreamFactoryInterface::class, HttpFactory::class);
        $this->app->bindIf(Clock::class, SystemClock::class);

        $this->app->singleton(Client::class, function (Container $app): Client {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return new Client(
                pixelId: (string) $config->get('openai-ads.pixel_id'),
                apiKey: (string) $config->get('openai-ads.capi_key'),
                http: $app->make(ClientInterface::class),
                requests: $app->make(RequestFactoryInterface::class),
                streams: $app->make(StreamFactoryInterface::class),
                clock: $app->make(Clock::class),
                integrationSource: (string) $config->get(
                    'openai-ads.integration_source',
                    Client::DEFAULT_INTEGRATION_SOURCE,
                ),
            );
        });

        $this->app->bind(RequestContext::class, function (Container $app): RequestContext {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return new RequestContext(
                request: $app->make(Request::class),
                canonicalOrigin: $config->get('openai-ads.canonical_origin'),
                stripQueryString: (bool) $config->get('openai-ads.strip_query_string', true),
            );
        });

        $this->app->singleton(Measurement::class, function (Container $app): Measurement {
            return new Measurement(
                container: $app,
                config: $app->make(Config::class),
                logger: $app->make(LoggerInterface::class),
            );
        });

        $this->app->alias(Measurement::class, 'openai-ads');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'openai-ads');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/openai-ads.php' => $this->app->configPath('openai-ads.php'),
            ], 'openai-ads-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => $this->app->resourcePath('views/vendor/openai-ads'),
            ], 'openai-ads-views');
        }

        // Renders the official loader and the init call. The Pixel ID is public;
        // the Conversions API key is never available to this view.
        Blade::directive('openaiAdsPixel', static function (string $expression): string {
            $arguments = trim($expression) === '' ? '[]' : $expression;

            return "<?php echo view('openai-ads::pixel', ['user' => {$arguments}])->render(); ?>";
        });
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [Client::class, Measurement::class, RequestContext::class, 'openai-ads'];
    }
}
