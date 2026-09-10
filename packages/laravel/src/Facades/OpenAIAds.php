<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use WebaroundLabs\OpenAIAds\Laravel\Measurement;

/**
 * @method static \WebaroundLabs\OpenAIAds\Capi\Response|null send(\WebaroundLabs\OpenAIAds\Event ...$events)
 * @method static void queue(\WebaroundLabs\OpenAIAds\Event ...$events)
 * @method static \WebaroundLabs\OpenAIAds\Capi\Response|null validate(\WebaroundLabs\OpenAIAds\Event ...$events)
 * @method static \WebaroundLabs\OpenAIAds\Laravel\RequestContext context()
 * @method static string|null pixelId()
 * @method static bool pixelEnabled()
 * @method static bool consented()
 *
 * @see Measurement
 */
final class OpenAIAds extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Measurement::class;
    }
}
