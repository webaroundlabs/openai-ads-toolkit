# Conversion Tracking for OpenAI Ads — WordPress

The WordPress plugin, built on the shared toolkit. Measurement Pixel, Conversions
API, and the deduplication between them.

> **Pre-alpha, `0.1.0`.** Not on the WordPress plugin directory. The base plugin
> only — Contact Form 7, Elementor Forms and WooCommerce come next.

An independent community integration. Not created, certified, endorsed or
supported by OpenAI.

## The interesting part: PSR-18 over `wp_remote_post`

`src/Http/WpTransport.php` is the reason the core depends on PSR-18 rather than
defining a transport interface of its own.

WordPress sites sit behind proxies, firewalls and host-level HTTP filters that
only `wp_remote_*` knows about, and site owners expect `pre_http_request` and
`http_request_args` to work. So this plugin supplies a PSR-18 client backed by
the WordPress HTTP API, and the core uses it without knowing WordPress exists. A
bespoke interface would have needed this same adapter *and* would have served no
other consumer.

A `WP_Error` becomes a PSR-18 network exception, which the core turns into its
own `TransportException` — preserving the distinction that matters: the events
definitely did not arrive, as opposed to arriving and being refused.

## Public API

```php
openai_ads_track( 'lead_created', [], [
    'event_id' => $lead_id,               // share this with the browser
    'user'     => [ 'email' => $email ],  // raw; hashed before it leaves
] );
```

Fire at a **confirmed** boundary — after the order is paid, after the lead is
accepted — never on a button click.

Never throws. A measurement problem returns `false` and, with debug logging on,
explains itself in the error log. The order or form submission still completes.

| Function | Purpose |
|---|---|
| `openai_ads_track()` | Record a conversion |
| `openai_ads_event_id()` | Mint a deduplication id when the site has no stable one |
| `openai_ads_pixel_event()` | Print the browser half of a confirmed conversion |
| `openai_ads_is_configured()` | Whether server-side measurement is on and has credentials |

Amounts are integers in the currency's minor unit — `1299`, not `12.99`. Pass a
float and you get told what the right value would be.

### Deduplication

Same Pixel ID, same event name, same event id on both sides:

```php
$event_id = $order->get_order_number();

openai_ads_track( 'order_created', [ 'amount' => 1299, 'currency' => 'EUR' ], [
    'event_id' => $event_id,
] );

openai_ads_pixel_event( 'order_created', $event_id, [
    'type' => 'contents', 'amount' => 1299, 'currency' => 'EUR',
] );
```

Prefer an id the site already owns. `openai_ads_event_id()` is the fallback for
flows with no stable id — mint once, use on both sides, never regenerate.

### Hooks

| Filter | Purpose |
|---|---|
| `openai_ads_enabled` | Turn measurement off entirely |
| `openai_ads_consent` | Your consent mechanism. Returns false → nothing is sent, silently |
| `openai_ads_event` | Inspect or amend an `Event` before it is queued; return null to veto |
| `openai_ads_pixel_user` | Already-hashed identity for the Pixel |
| `openai_ads_client_ip` | Supply the real client IP behind a proxy |

| Action | Fires |
|---|---|
| `openai_ads_sent` | After a batch is delivered |
| `openai_ads_failed` | On a delivery or validation failure |
| `openai_ads_invalid_event` | When a caller passed something the API cannot accept |

## Delivery

Events collect in memory during the request and are sent on `shutdown`, after
`fastcgi_finish_request()` where the server supports it — so the visitor's
browser is never waiting on an ad platform, and batching falls out naturally.

**This is not durable.** A fatal error before shutdown loses the batch. Durable
retries need a real queue, which on WordPress means Action Scheduler, and that
arrives with the WooCommerce integration where it is actually installed. Nothing
retries today, matching the core's position that repeating a request whose
outcome is unknown risks double-counting conversions.

## Security and privacy

The API key is **server-side only**. It is never rendered into a page, and a test
asserts that — verified by deliberately leaking it and watching the test fail.

Prefer keeping it out of the database entirely:

```php
define( 'OPENAI_ADS_CAPI_KEY', 'your-key' );   // wp-config.php
```

The settings screen then shows it as fixed and refuses to edit it.

Other decisions worth knowing:

- **Query strings are stripped** from the page URL before sending. On WordPress
  they routinely carry search terms, `wc_order` keys and password-reset tokens.
- **The site's own origin is always used** for `source_url`, so a spoofed `Host`
  header cannot put another domain into your measurement data.
- **Only `REMOTE_ADDR` is trusted** for the client IP. WordPress has no notion of
  trusted proxies, so `X-Forwarded-For` is attacker-controlled unless a proxy
  overwrites it. Behind Cloudflare, supply the real address via
  `openai_ads_client_ip`.
- **`__oppref` and `__obref` are different cookies** feeding different fields —
  `oppref` is event-level, `obref` goes inside the user object. Both are opaque
  and pass through byte for byte.
- **Payloads are never logged.** They carry identity hashes and attribution.
- **`opt_out` is not a consent gate.** It excludes an event from personalization
  while still sending it.

## Development

```bash
composer install
composer test
vendor/bin/phpunit --filter never_contains_the_conversions_api_key
```

The suite runs without a WordPress install: `tests/bootstrap.php` stubs the small
set of WordPress functions the testable surface depends on. That covers the
transport, settings sanitization, request context, event building and the
rendered Pixel. Hook registration order and admin screen rendering are exercised
on a real site instead.

`vendor/` is gitignored but **must be present in the distributed plugin** — users
cannot run Composer. Build the zip with `composer install --no-dev` first.

## License

GPL-2.0-or-later, as the WordPress plugin directory requires. The rest of the
toolkit is [MIT](../../LICENSE), which is compatible — that compatibility is why
MIT was chosen for the core.
