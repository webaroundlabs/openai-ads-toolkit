<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use WebaroundLabs\OpenAIAds\ActionSource;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\EventId;
use WebaroundLabs\OpenAIAds\EventName;
use WebaroundLabs\OpenAIAds\Laravel\OpenAIAdsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected const PIXEL_ID = 'px-test';

    protected const CAPI_KEY = 'secret-key-must-never-be-rendered';

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [OpenAIAdsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('openai-ads.pixel_id', self::PIXEL_ID);
        $app['config']->set('openai-ads.capi_key', self::CAPI_KEY);
        $app['config']->set('openai-ads.canonical_origin', 'https://shop.example.com');
        $app['config']->set('openai-ads.queue.enabled', false);
    }

    protected function lead(?int $timestampMs = null): Event
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
