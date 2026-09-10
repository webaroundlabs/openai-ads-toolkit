# webaroundlabs/openai-ads-laravel

Laravel adapter for the OpenAI Ads toolkit. Config, a facade, queued delivery,
request attribution, and a Blade Pixel helper.

> **Pre-alpha, `0.1.x`.** Not published to Packagist. The public API is unstable until `1.0`.

Deliberately thin. It decides three things — whether measurement is on, whether
consent allows it, and whether to send now or on the queue — and hands everything
else to [`webaroundlabs/openai-ads`](../php). It contains no knowledge of the
OpenAI Ads wire format.

## Install

```bash
composer require webaroundlabs/openai-ads-laravel
php artisan vendor:publish --tag=openai-ads-config
```

```dotenv
OPENAI_ADS_PIXEL_ID=your-pixel-id
OPENAI_ADS_CAPI_KEY=your-server-side-key
```

The Pixel ID is public. **The Conversions API key is not** — it must never reach a
Blade template, a compiled asset, or a `VITE_`-prefixed variable.

## Sending

```php
use WebaroundLabs\OpenAIAds\Laravel\Facades\OpenAIAds;
use WebaroundLabs\OpenAIAds\{ActionSource, Event, EventId, EventName, SystemClock, UserData};

$context = OpenAIAds::context();

$event = Event::create(
    name:         EventName::LeadCreated,
    id:           EventId::fromBusinessId($lead->id),
    timestampMs:  app(\WebaroundLabs\OpenAIAds\Clock::class)->nowMs(),
    actionSource: ActionSource::Web,
    sourceUrl:    $context->sourceUrl(),
    user:         UserData::create(
        email:     $lead->email,
        obref:     $context->obref(),
        ipAddress: $context->ipAddress(),
        userAgent: $context->userAgent(),
    ),
    oppref: $context->oppref(),
);

OpenAIAds::queue($event);   // off the request cycle - the usual choice
OpenAIAds::send($event);    // inline; blocks until OpenAI answers
```

Fire at a **confirmed** boundary — after the lead is accepted, after payment is
captured — never on a button click.

`send()` and `queue()` never throw. A conversion that fails to report must not
fail the thing that produced it, so problems are logged and swallowed. `send()`
returns `null` when nothing was sent (disabled, unconfigured, consent refused, or
a delivery failure) and a `Response` otherwise.

### Proving it works

```php
OpenAIAds::validate($event);   // the API's documented validation mode
```

Checks the batch against the real API without recording it, and keeps working
even with `OPENAI_ADS_ENABLED=false`, so it is useful on staging. Set
`OPENAI_ADS_VALIDATE_ONLY=true` to make every send a dry run.

## The Pixel

```blade
<head>
    @openaiAdsPixel
</head>
```

Once the visitor is known, pass **already-hashed** identity:

```blade
@openaiAdsPixel(['email_sha256' => $digest])
```

Renders nothing when the Pixel is disabled, unconfigured, or consent is refused.
Values are JSON-encoded for the script context rather than concatenated.

## Attribution

`OpenAIAds::context()` reads the request:

| Method | Source |
|---|---|
| `oppref()` | the `__oppref` cookie — **event-level** |
| `obref()` | the `__obref` cookie — **user-level**, goes inside `UserData` |
| `ipAddress()`, `userAgent()` | the request |
| `sourceUrl()` | the canonical origin plus path |

`oppref` and `obref` are different fields on different parts of the payload, and
both are opaque — read and passed through byte for byte, never parsed or minted.

These cookies are written by OpenAI's browser SDK, not by your application, so
they are not encrypted. Add them to `EncryptCookies::$except` if your middleware
would otherwise try to decrypt them:

```php
protected $except = ['__oppref', '__obref'];
```

`sourceUrl()` strips the query string and refuses an origin that is not
`canonical_origin` (defaults to `APP_URL`). Both are **this toolkit's policy**,
not API rules: query strings routinely carry email addresses and reset tokens,
and a spoofed `Host` header should not be able to put a third party's domain into
your measurement data. Set `OPENAI_ADS_STRIP_QUERY=false` to keep the query.

## Deduplication

The browser event and the server event must share the Pixel ID, the event name
and the event id. Prefer an id the server already owns and hand it to the browser
rather than minting one there:

```blade
<script>
  oaiq("measure", "lead_created", { type: "customer_action" }, { event_id: @json($lead->id) });
</script>
```

## Queue behaviour

Retries are narrow on purpose, and this is the point of the core distinguishing a
failed round trip from an unsuccessful response:

| Outcome | Action |
|---|---|
| No round trip (DNS, refused, TLS) | Retried — the events did not arrive |
| Completed, non-2xx | Logged and dropped — repeating risks a duplicate |
| Rejected locally (malformed, or aged past 7 days while queued) | Logged and dropped |

The job carries constructed `Event` objects, so a retry replays the original ids
and timestamps rather than minting new ones — that is what makes a retry a retry
rather than a second conversion.

A timeout is the honest grey area: it is reported as a transport failure, but the
API may have processed the batch before the connection gave up. Retrying it bets
that OpenAI deduplicates on the event id, which the documentation implies without
stating. Set `OPENAI_ADS_QUEUE_TRIES=1` to opt out of that bet.

## Consent

```php
config(['openai-ads.consent' => fn () => Cookiebot::hasMarketing()]);
```

Returns false and nothing is sent and no Pixel is rendered — silently, because a
refused consent is a normal outcome, not an error. With no callback configured
the toolkit defers entirely to your own gating; it does not invent a privacy model.

`opt_out` is a different, weaker thing: it excludes an event from personalization
while still sending it. Do not wire a consent banner to it.

## Container

Everything is bound lazily. Nothing constructs an HTTP client until something
actually sends — a binding evaluated in `register()` runs on *every* request,
including the vast majority that measure nothing.

Bind your own `Psr\Http\Client\ClientInterface` before this package's provider and
it will be used as-is, so an application with its own proxy, instrumentation or
retry middleware keeps it.

## Development

```bash
composer install
composer test
vendor/bin/phpunit --filter never_contains_the_conversions_api_key
```

The suite runs against Testbench and covers the container wiring, the queue
policy, request attribution and the rendered Pixel — including an assertion that
the Conversions API key never appears in the rendered output.

## License

[MIT](../../LICENSE). Independent community project, not affiliated with OpenAI.
