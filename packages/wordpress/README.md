# Conversion Tracking for OpenAI Ads — WordPress

The WordPress plugin, built on the shared toolkit. Measurement Pixel, Conversions
API, and the deduplication between them.

> **Pre-alpha, `0.1.0`.** Not on the WordPress plugin directory. Base plugin plus
> Contact Form 7 and Elementor Forms. WooCommerce comes next.

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

## Form integrations

Contact Form 7 and Elementor Forms are detected automatically and appear under
**Integrations** on the settings screen. Neither is a dependency: a site running
neither pays for neither, because an integration only registers when its host
plugin is actually active.

### The success boundary

A lead is recorded when the submission is *accepted*, never when the button is
clicked:

| Host | Hook | Why this one |
|---|---|---|
| Contact Form 7 | `wpcf7_mail_sent` | Fires only after validation, spam checks and delivery all succeeded. `wpcf7_before_send_mail` runs before the outcome is known. |
| Elementor Forms | `elementor_pro/forms/new_record` | Runs after validation and after the form's own actions were accepted. |

A test asserts that a response which is not a successful send carries no
conversion — verified by removing the check and watching the test fail.

### The deduplication bridge

Both plugins submit over AJAX, so the browser half cannot simply be printed into
the page. The server mints one event id, sends the Conversions API event with it,
and returns the same id in the form's own JSON response; the bundled
`assets/js/forms.js` reads it back and fires the Pixel event with that id.

Same Pixel ID, same event name, same event id — matched, not counted twice. The
script is enqueued only when an integration registered and the Pixel is running,
and it never throws into the host page.

### Identity extraction

Email and phone are read from the field **type**, which the form builder already
declared — not from field labels, which is how integrations end up hashing a
subject line as an email address.

Names are taken only from fields explicitly named for one (`first-name`,
`Last Name`). A single combined "name" field is deliberately **not** split:
"Mary Jane Watson" has no correct answer, and a wrong surname hashes to a digest
that matches nobody, which is worse than sending none.

For anything the automatic extraction cannot recognize:

```php
add_filter( 'openai_ads_form_user_data', function ( $user, $form_id, $source ) {
    $user['email'] = $_POST['my-custom-field'] ?? null;
    return $user;
}, 10, 3 );
```

### Mapping a form to a different event

```php
add_filter( 'openai_ads_form_event', function ( $event, $form_id, $source ) {
    return $form_id === '12' ? 'appointment_scheduled' : $event;
}, 10, 3 );
```

Any documented event works. For `custom`, supply the name via
`openai_ads_form_custom_event_name` — it is used on both sides, as it must be.

### Adding your own

```php
add_filter( 'openai_ads_integrations', function ( array $integrations ) {
    $integrations[] = new My_Gravity_Forms_Integration();
    return $integrations;
} );
```

Implement `Integrations\Integration`: `id()`, `label()`, `isAvailable()`,
`register()`.

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
