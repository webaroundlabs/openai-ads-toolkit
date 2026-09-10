# OpenAI Ads Conversion Toolkit

One open-source toolkit for OpenAI Ads measurement — Measurement Pixel, Conversions API, and
the deduplication between them — with adapters for PHP, JavaScript, Laravel, WordPress and
Google Tag Manager.

> **Pre-alpha, `0.1.x`.** Everything but the two Google Tag Manager templates is built and
> tested. Nothing is published to Packagist, npm or the WordPress plugin directory.
> The public API is unstable until `1.0`. See [Status](#status).

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Why

Every OpenAI Ads integration re-implements the same handful of rules: which events exist, which
data shape each one takes, how an email is normalized before it is hashed, how a browser event
and a server event are tied together so the conversion is counted once. Re-implementing those
per platform is how measurement quietly breaks — and it breaks silently, because neither the
Pixel nor the Conversions API tells you it ignored a field.

This toolkit defines them **once**:

```
shared specification  →  PHP / JavaScript cores  →  framework adapters  →  CMS integrations
```

## Planned surface

```js
// JavaScript — a typed wrapper over OpenAI's official oaiq browser SDK
OpenAIAds.init({ pixelId: 'YOUR-PIXEL-ID' });
OpenAIAds.track('lead_created', undefined, { eventId: leadId });   // same id the server sends
```

```php
// PHP — the server-side Conversions API
$client->send([$event]);          // returns a Response; only a failed round trip throws
$client->validate([$event]);      // validate_only: the documented way to test an integration
```

```php
// Laravel
OpenAIAds::queue($event);         // delivered off the request cycle
```

```blade
{{-- Laravel: the browser Pixel --}}
@openaiAdsPixel
```

```php
// WordPress
openai_ads_track( 'lead_created', [], [ 'event_id' => $lead_id ] );
```

Signatures are proposals under review — see [`docs/api-design.md`](docs/api-design.md), which
argues for some changes to them.

## Status

| Package | State |
|---|---|
| `packages/spec` | **Done** — 13 events, identity mapping, golden fixtures |
| `packages/php` | **Done** — 13 events, identity hashing, Conversions API client, 123 tests |
| `packages/js` | **Done** — typed wrapper over the official `oaiq` SDK, 75 tests |
| `packages/laravel` | **Done** — provider, facade, queued delivery, attribution, Blade Pixel, 54 tests |
| `packages/wordpress` | **Done** — base plugin, Contact Form 7, Elementor Forms, WooCommerce. 82 tests |
| `packages/gtm-web`, `packages/gtm-server` | Not started |

Built in that order on purpose: the shared foundation first, WordPress last. Nothing is
published to Packagist, npm or the WordPress plugin directory yet.

The PHP and JavaScript packages are held to the same specification by parity tests, and both
assert the identity digests in `packages/spec/fixtures/normalization.cases.json` — so a Pixel
event and its Conversions API twin provably describe the same person.

## What the specification captures

[`packages/spec`](packages/spec/README.md) is the source of truth for every other package —
the event catalogue, the data shapes, the identity mapping and the limits. A few things it
records that are easy to get wrong:

- **`oppref` and `obref` are different fields.** `events[].oppref` is event-level and comes
  from the `__oppref` cookie; `events[].user.obref` is user-level and comes from `__obref`.
  Both are opaque — never parsed, decoded or minted.
- **The Pixel and the Conversions API serialize identity differently.** CAPI takes plural array
  keys (`emails_sha256`), the Pixel takes singular scalars (`email_sha256`). Same digest,
  different shape.
- **The Pixel SDK supplies `source_url`, timestamps, batching and `oppref` itself.** Those must
  be passed explicitly to the Conversions API and never hand-passed to the Pixel.
- **Amounts are integers in the currency's minor unit.** `1299`, not `12.99`.
- **A batch is atomic** — up to 1,000 events, and if one fails the whole batch fails. Local
  validation before sending is therefore load-bearing, not a convenience.
- **Name lowercasing must be Unicode-aware.** PHP's byte-wise `strtolower()` leaves `Ștefănescu`
  unchanged where JavaScript's `toLowerCase()` does not, producing two different hashes for the
  same person. Pinned in the fixtures.

## Security and privacy

The Conversions API key is **server-side only**. It must never appear in JavaScript, a frontend
environment variable, HTML, a browser bundle, or a log. Raw identifiers are hashed at the
boundary and never logged, never included in exception messages, and never attached to error
reports.

Consent is the host application's to decide. A failed consent check means the event is never
constructed — `opt_out` is a personalization flag, not a consent gate, and using it as one
still transmits identity hashes. Implementers remain responsible for compliance with applicable
privacy law and their own consent policy.

Analytics must never break the application. A reporting failure cannot fail a checkout, a form
submission, or a signup.

## Contributing

The project is in its foundation phase; the specification and the public API design are the
useful things to argue with right now. [`CLAUDE.md`](CLAUDE.md) is the coding charter —
proportional engineering, no speculative abstraction, and no implementing behaviour that
OpenAI has not documented.

## License

[MIT](LICENSE) © 2026 Webaround Labs.

MIT rather than Apache-2.0 specifically so the WordPress plugin can bundle the PHP core:
Apache-2.0 is incompatible with GPLv2, and the WordPress plugin directory requires
GPLv2-or-later compatibility.

---

This is an independent, community-built integration. It is **not** created, certified,
endorsed or supported by OpenAI. "OpenAI" and "ChatGPT" are trademarks of OpenAI.
