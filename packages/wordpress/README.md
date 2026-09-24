# Webaround Pixel and Conversions API for OpenAI Ads — WordPress

The WordPress plugin, built on the shared toolkit. Measurement Pixel, Conversions
API, and the deduplication between them.

> **Pre-alpha, `0.1.0`.** Not on the WordPress plugin directory. Base plugin plus
> Contact Form 7, Elementor Forms, Gravity Forms, WPForms, Fluent Forms, Ninja
> Forms, WooCommerce, WooCommerce Subscriptions, Easy Digital Downloads and
> WordPress account registration.

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
| `openai_ads_consent` | The last word on consent. Returns false → nothing is sent, silently. Overrides whatever the plugin detected |
| `openai_ads_consent_providers` | Add a consent plugin the toolkit does not know |
| `openai_ads_event` | Inspect or amend an `Event` before it is queued; return null to veto |
| `openai_ads_pixel_user` | Already-hashed identity for the Pixel |
| `openai_ads_client_ip` | Supply the real client IP behind a proxy |

| Action | Fires |
|---|---|
| `openai_ads_sent` | After a batch is delivered |
| `openai_ads_failed` | On a delivery or validation failure |
| `openai_ads_invalid_event` | When a caller passed something the API cannot accept |

### Script handles

Everything the plugin puts in a page goes through WordPress's queue, so a site
can see it, reorder it or remove it:

| Handle | What it is |
|---|---|
| `openai-ads-pixel` | OpenAI's SDK, `async`, in the head. The queue stub and `oaiq("init", …)` ride it as `before` inline scripts. |
| `openai-ads-pixel-events` | A `src`-less footer handle carrying the browser half of each confirmed conversion. |
| `openai-ads-forms` | The AJAX bridge above, enqueued only when an integration registered. |
| `openai-ads-admin` | The settings screen's test-connection button, on that screen only. |

`wp_dequeue_script( 'openai-ads-pixel' )` is the supported way to stop the Pixel
loading on a particular page.

Two details are load-bearing rather than stylistic, and both are pinned by tests.
The init call is attached `before` and never `after`: a handle carrying an
`after` inline script is ineligible for a delayed strategy, and WordPress answers
that by moving the script to the footer — so `after` would cost the `async`
attribute and the head position at once. And `openai-ads-pixel-events` declares
no dependency on `openai-ads-pixel`, because eligibility recurses over a handle's
dependents and a blocking dependent would take `async` off the SDK. Ordering is
guaranteed by the document instead, and by the `window.oaiq &&` guard.

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
| Gravity Forms | `gform_after_submission` | Runs once the entry is saved, after any payment add-on has settled. |
| WPForms | `wpforms_process_complete` | Runs after validation, spam checks and storage. |
| Fluent Forms | `fluentform/submission_inserted` | Runs once the entry exists. |
| Ninja Forms | `ninja_forms_after_submission` | Runs once the submission is processed. |
| Easy Digital Downloads | `edd_complete_purchase` | Fires when the payment reaches a complete status, not when checkout begins. |
| WooCommerce Subscriptions | `woocommerce_subscription_status_updated` → `active` | Later than the order being paid, on purpose: a subscription can be created pending and activate only once the first payment settles. |
| WordPress registration | `user_register` | After the account row exists. **Off by default** — see below. |

The last four form plugins report **server-side only**. That is correct rather
than a shortcut: nothing fires a browser Pixel event for those conversions, so
there is no second report to deduplicate against. A site that wants the browser
half too can listen for `openai_ads_form_recorded` and print it with
`openai_ads_pixel_event()`, using the event id handed over.

WordPress registration is off until you switch it on, because `user_register`
also fires for an administrator adding a colleague, for a WooCommerce guest
checkout that creates an account, and for an importer restoring a thousand
users. None of those is an ad conversion, and reporting them would quietly
inflate the number the advertiser optimizes against. Refine the rule with the
`openai_ads_should_report_registration` filter.

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

### Receiving events from a tag manager

Server-side tagging in GTM normally needs a server container hosted on Google
Cloud. If you would rather not pay for one, the plugin can accept the event
itself and forward it:

```
Browser → GTM web container → your site (/wp-json/openai-ads/v1/collect) → OpenAI
```

The API key stays on your server, and the request to OpenAI is not something an
ad blocker can see. Switch it on under **OpenAI Ads → Tag manager
endpoint**, which also shows you the URL and generates a shared secret.

```bash
curl -X POST https://yoursite.com/wp-json/openai-ads/v1/collect   -H 'Content-Type: application/json'   -H 'X-OpenAI-Ads-Key: YOUR-SECRET'   -d '{"event":"lead_created","event_id":"lead_123",
       "source_url":"https://yoursite.com/thank-you",
       "user":{"email":"visitor@example.com"}}'
```

The payload takes the same fields `openai_ads_track()` does, so anything you can
measure in PHP you can measure from here — custom events included.

**Treat the secret like a password.** An endpoint that forwards conversions
writes into your measurement data: anyone who can reach it and knows the secret
can record conversions that never happened. That does not cost you money
directly, but it corrupts the figures your campaigns are optimized against, and
nothing downstream will flag it. The endpoint is off until you switch it on, it
refuses to run without a secret, comparisons are constant-time, and requests are
rate limited to 120 per minute per address (`openai_ads_ingest_rate_limit`).

Prefer to keep the secret out of the database entirely:

```php
define( 'OPENAI_ADS_INGEST_SECRET', '…' );   // in wp-config.php
```

**Posting from a server rather than a browser?** Send `source_url`, `oppref`,
`obref`, `ip_address` and `user_agent` explicitly. A browser posting same-origin
supplies all five and the plugin reads them itself; a server does not, and
without them the conversion is attributed to the machine that called the
endpoint rather than to the visitor.

There is a ready-made GTM tag template for this in
[`packages/gtm-collect`](../gtm-collect/README.md).

## Adding your own

```php
add_filter( 'openai_ads_integrations', function ( array $integrations ) {
    $integrations[] = new My_Booking_Plugin_Integration();
    return $integrations;
} );
```

Implement `Integrations\Integration`: `id()`, `label()`, `isAvailable()`,
`register()`.

## WooCommerce

Four events, each at a boundary WooCommerce genuinely confirms:

| Event | Boundary |
|---|---|
| `contents_viewed` | a single product page renders |
| `items_added` | after the cart mutation succeeded |
| `checkout_started` | the checkout form is reached |
| `order_created` | payment completed, or the order reached a paid status |

### The purchase, reported exactly once

This is the one most integrations get wrong. `woocommerce_thankyou` fires for
pending, failed and cancelled orders too, so hooking it blindly reports
conversions that were never paid for. Instead the integration hooks
`woocommerce_payment_complete` and the paid status transitions, and checks
`is_paid()` before reporting anything.

It then writes the event id to the order as `_openai_ads_event_id`. Gateways
differ — some call `payment_complete()`, others move the order straight to a paid
status, a webhook may be redelivered, and an order can go from processing to
completed later. All of those reach the same handler, and without the guard the
same purchase is reported three times. Both behaviours are mutation-tested.

If nothing was recorded — measurement off, or consent refused — the meta is
deliberately **not** written, so a later permitted attempt can still report the
order rather than finding it marked as done.

The order number becomes the deduplication id (`wc_1234`), and the order-received
page emits the browser half with that same id. An order with no stored id emits
nothing.

### Amounts

Minor units are a property of the **currency**, not of the store's display
settings. A shop configured to show whole euros still deals in cents, and yen have
no minor unit at all — so using WooCommerce's decimal-places setting would send
`13` for a €12.99 order and `129900` for a ¥1299 one. `Amount` uses the ISO 4217
exponent instead, and rounds before casting, because `(int) (0.29 * 100)` is 28 in
binary floating point.

### Line items

Product SKU where set, otherwise the id; name; quantity; and the **line total**
rather than the list price, so discounts are reflected. A variation additionally
carries `group_id` (its parent product) and `variant_dict` (the chosen
attributes) — both Conversions API only fields, which is exactly why they belong
on the server event and not on anything the Pixel receives.

Billing details become hashed identity: email, phone, name, city, region, postal
code, country, plus the customer id as an external id.

## Delivery

Events collect in memory during the request. What happens next depends on whether
the site has **Action Scheduler** — the background job queue that ships inside
WooCommerce.

| Site | Behaviour |
|---|---|
| Has Action Scheduler (any WooCommerce site) | The batch is stored and queued, and delivered in a later request. It survives this request ending. |
| No Action Scheduler | The batch is sent on `shutdown`, after `fastcgi_finish_request()` where the server supports it. |

Either way the visitor's browser never waits on an ad platform, and batching
falls out naturally — which matters because the API takes up to 1,000 events at
once and fails a batch as a whole.

The plugin **never bundles Action Scheduler**. Two copies in one site conflict,
so it only uses one that is already there, detected with `function_exists()`. A
site without WooCommerce is entirely unaffected.

### What deferred delivery does and does not give you

**Does:** a fatal error after the conversion but before shutdown no longer loses
the batch.

**Does not:** retries. The batch is *claimed* — removed from storage — before the
send is attempted, so a failure loses it rather than repeating it. That is
deliberate and matches the core's position: repeating a request whose outcome is
unknown risks reporting a conversion twice, and silently inflated conversion data
is worse than a missing event, because it corrupts the advertiser's optimization
invisibly and cannot be undone. Mutation-tested — without the claim, a reclaimed
action sends the batch a second time.

### One caveat worth knowing

Action Scheduler runs jobs through WP‑Cron or loopback requests. On a site with
`DISABLE_WP_CRON` and no real system cron, queued actions can sit unprocessed.
Batches older than six days are therefore dropped unsent, because the API refuses
events older than seven and would fail the whole batch.

If that describes the site, untick **Deferred delivery** under OpenAI
Ads → General and events are sent in-request as before.

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

## Translations

The plugin ships no catalogues. Translations reach a site from
translate.wordpress.org, which the plugin directory asks every hosted plugin to
use and which WordPress has loaded on demand since 4.6 — so there is no
`load_plugin_textdomain()` call either.

What the repository keeps is the English `.pot` in [`languages/`](languages),
which does ship, and hand-made catalogues for Bulgarian, Dutch, French, German,
Greek, Hungarian, Polish, Portuguese, Romanian and Spanish in
[`translations-source/`](translations-source), which do not. They are there to be
imported into GlotPress rather than retyped.

```bash
python ../../scripts/make-pot.py           # after any string changes
python ../../scripts/check-translations.py # what CI runs
```

The check refuses a msgid that has drifted from the `.pot`, an empty
translation, and a `printf` placeholder lost in translation — the last one being
the one that breaks a page rather than merely reading oddly.

Product names stay untranslated on purpose: `Measurement Pixel`, `Conversions
API` and `Pixel ID` are what OpenAI's own documentation calls them, and a
developer comparing this screen with those docs has to recognize them.

## License

GPL-2.0-or-later, as the WordPress plugin directory requires — see
[`LICENSE`](LICENSE). The rest of the toolkit is
[MIT](https://github.com/webaroundlabs/openai-ads-toolkit/blob/main/LICENSE),
which is compatible — that compatibility is why MIT was chosen for the core.
