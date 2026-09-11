# OpenAI Ads Conversion Toolkit

One open-source toolkit for OpenAI Ads measurement — Measurement Pixel, Conversions API, image
tag, and the deduplication between them — with adapters for PHP, JavaScript, Laravel, WordPress
and Google Tag Manager.

> **Pre-alpha, `0.1.x`.** All seven packages are built. Nothing is published to Packagist,
> npm, the WordPress plugin directory or the GTM gallery. The public API is unstable
> until `1.0`. See [Status](#status).

[![CI](https://github.com/webaroundlabs/openai-ads-toolkit/actions/workflows/ci.yml/badge.svg)](https://github.com/webaroundlabs/openai-ads-toolkit/actions/workflows/ci.yml)
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

All three documented channels are covered: the browser Pixel, the server-side Conversions API,
and the image tag for the places JavaScript cannot go — an email body, an AMP page, a
`<noscript>` fallback. All three deduplicate on the same event id.

## The surface

```js
// JavaScript — a typed wrapper over OpenAI's official oaiq browser SDK
OpenAIAds.init({ pixelId: 'YOUR-PIXEL-ID' });
OpenAIAds.track('lead_created', undefined, { eventId: leadId });   // same id the server sends
```

```php
// PHP — the server-side Conversions API
$client->send([$event]);          // returns a Response; only a failed round trip throws
$client->validate([$event]);      // validate_only: the documented way to test an integration

// …and the no-JavaScript channel, for an email body or an AMP page
ImageTag::url($pixelId, $event->withoutIdentity());
```

```php
// Laravel
OpenAIAds::queue($event);         // delivered off the request cycle
```

```blade
{{-- Laravel: the browser Pixel. Raw identity in, hashed server-side, digests out. --}}
@openaiAdsPixel(['email' => $user->email])
```

```php
// WordPress
openai_ads_track( 'lead_created', [], [ 'event_id' => $lead_id ] );
```

[`docs/api-design.md`](docs/api-design.md) records why each of those signatures is the shape it
is, and what was deliberately left out.

## Status

| Package | State |
|---|---|
| `packages/spec` | **Done** — 13 events, identity mapping, golden fixtures |
| `packages/php` | **Done** — 13 events, identity hashing, Conversions API client, image tag, 171 tests |
| `packages/js` | **Done** — typed wrapper over the official `oaiq` SDK, 86 tests |
| `packages/laravel` | **Done** — provider, facade, queued delivery, attribution, Blade Pixel, 62 tests |
| `packages/wordpress` | **Done** — base plugin, Contact Form 7, Elementor Forms, WooCommerce. 102 tests |
| `packages/gtm-web` | **Template written** — catalogue verified here; tests run inside GTM |
| `packages/gtm-server` | **Template written** — catalogue verified here; tests run inside GTM |

Built in that order on purpose: the shared foundation first, the integrations last. Nothing is
published anywhere yet.

The GTM templates are the one part not covered by a runnable test suite here — their own tests
run inside GTM's template editor. What this repository does verify is that their event
dropdowns match the specification exactly, and that the web template contains no credential.

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
- **Geographic values are normalized, not merely passed through.** Cities and regions are
  lowercased and capped at 128 characters, postal codes reduced to letters, digits, spaces and
  hyphens, countries required to be two-letter codes. A country named `Romania` is dropped by
  the API without a word.
- **Phone normalization removes four separators, not every non-digit.** `+1 (555) 123-4567
  ext. 89` must be refused, not turned into thirteen digits belonging to nobody.
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

## Examples

[`examples/`](examples/) shows the two things that are easy to get wrong: firing at a
**confirmed** boundary, and using one event id on both sides. There is a framework-free PHP
script, a browser page that takes its event id from the server response, a Laravel controller,
and a WordPress snippet for a form plugin the toolkit does not ship support for.

## Development

```bash
python packages/spec/verify.py        # the specification and the GTM templates
python scripts/check-secrets.py       # credential exposure

cd packages/php       && composer install && composer check
cd packages/js        && npm install      && npm run check
cd packages/laravel   && composer install && composer check
cd packages/wordpress && composer install && composer check
```

`check` is style, then static analysis, then tests — the order that fails fastest.

CI runs all of that across PHP 8.2–8.4, Laravel 11 and 12, and Node 22, and scans the **built**
JavaScript bundle for anything credential-shaped — the artefact browsers actually receive.
PHPStan runs at level 9 over the core and level 8 over the adapters, with WordPress and
WooCommerce stubs loaded so it analyses the integration rather than reporting that WordPress
exists.

The adapters reach the core through a Composer path repository, and their tests read
`packages/spec` by relative path, so they run from a monorepo checkout only. That is intended:
the specification is a development-time asset, not a runtime dependency.

## Versioning

Semantic versioning, but the version is `0.x` and the public API may change in any release
until `1.0`. `1.0` waits until all 13 events and both serializers are stable in every package.

A change to identity normalization, hashing or event id generation is a **behavioural change to
deduplication** even when no type signature moves, and is treated as such: a pinned test and a
changelog entry.

## Contributing

[`CONTRIBUTING.md`](CONTRIBUTING.md) has the details. [`CLAUDE.md`](CLAUDE.md) is the coding
charter — proportional engineering, no speculative abstraction, and no implementing behaviour
that OpenAI has not documented; it explains most review comments in advance.

Security issues go through
[private vulnerability reporting](https://github.com/webaroundlabs/openai-ads-toolkit/security/advisories/new),
never a public issue — see [`SECURITY.md`](SECURITY.md).

## License

[MIT](LICENSE) © 2026 Webaround Labs.

MIT rather than Apache-2.0 specifically so the WordPress plugin can bundle the PHP core:
Apache-2.0 is incompatible with GPLv2, and the WordPress plugin directory requires
GPLv2-or-later compatibility.

---

This is an independent, community-built integration. It is **not** created, certified,
endorsed or supported by OpenAI. "OpenAI" and "ChatGPT" are trademarks of OpenAI.
