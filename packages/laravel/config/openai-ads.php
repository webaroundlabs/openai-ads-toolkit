<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | The Pixel ID is public and appears in browser code. The Conversions API
    | key is NOT: it is server-side only and must never reach a Blade template,
    | a Vite-compiled bundle, a VITE_ or NEXT_PUBLIC_ variable, or a log.
    |
    */

    'pixel_id' => env('OPENAI_ADS_PIXEL_ID'),

    'capi_key' => env('OPENAI_ADS_CAPI_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Switches
    |--------------------------------------------------------------------------
    |
    | Turn measurement off entirely, or send events to the API's documented
    | validation mode so they are checked but never recorded. Validation mode is
    | the honest way to prove an integration works on staging.
    |
    */

    'enabled' => env('OPENAI_ADS_ENABLED', true),

    'validate_only' => env('OPENAI_ADS_VALIDATE_ONLY', false),

    'pixel_enabled' => env('OPENAI_ADS_PIXEL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Integration source
    |--------------------------------------------------------------------------
    |
    | Identifies this integration to OpenAI. 1-64 ASCII characters starting with
    | a letter or digit, then letters, digits, periods, underscores or hyphens.
    |
    */

    'integration_source' => env('OPENAI_ADS_INTEGRATION_SOURCE', 'webaroundlabs-laravel'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Bounded timeouts, because a slow measurement call must never hold up a
    | checkout. Bind your own PSR-18 client in the container to override the
    | transport entirely; this package will use it if it finds one.
    |
    */

    'timeout' => env('OPENAI_ADS_TIMEOUT', 5),

    'connect_timeout' => env('OPENAI_ADS_CONNECT_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Sending on the queue keeps the API call off the request that completed the
    | conversion. Retries are deliberately narrow: a request that completed but
    | returned a non-2xx status is NOT retried, because the events arrived and
    | repeating them risks double-counting. Only a failed round trip is retried.
    |
    */

    'queue' => [
        'enabled' => env('OPENAI_ADS_QUEUE', true),
        'connection' => env('OPENAI_ADS_QUEUE_CONNECTION'),
        'queue' => env('OPENAI_ADS_QUEUE_NAME'),
        'tries' => env('OPENAI_ADS_QUEUE_TRIES', 3),
        'backoff' => [10, 60, 300],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source URL
    |--------------------------------------------------------------------------
    |
    | The API requires a source_url with a scheme and host for web events. The
    | rest is this toolkit's policy, not an API rule:
    |
    | - Query strings and fragments are stripped, because they routinely carry
    |   email addresses, tokens and order references that have no business being
    |   sent to an ad platform.
    | - The origin is checked against `canonical_origin`, so a spoofed Host or
    |   forwarded header cannot put someone else's domain into your measurement
    |   data. Defaults to APP_URL.
    |
    */

    'canonical_origin' => env('OPENAI_ADS_CANONICAL_ORIGIN', env('APP_URL')),

    'strip_query_string' => env('OPENAI_ADS_STRIP_QUERY', true),

    /*
    |--------------------------------------------------------------------------
    | Consent
    |--------------------------------------------------------------------------
    |
    | Return false from this callback and no event is constructed or sent - a
    | refused consent is a silent no-op, not an error. Set it in a service
    | provider to your own consent mechanism:
    |
    |     config(['openai-ads.consent' => fn () => Cookiebot::hasMarketing()]);
    |
    | Leave it null to defer entirely to the host application's own gating.
    |
    */

    'consent' => null,

];
