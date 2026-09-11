# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

While the version is `0.x` the public API may change in any release. See
[Versioning](README.md#versioning).

## [Unreleased]

Nothing has been released yet, so this section describes the whole toolkit. The
**Changed** and **Fixed** entries below are still worth reading: they record
behavioural decisions about deduplication that a future change must not undo by
accident.

### Added

- Shared specification: 13 events, four data shapes, the identity mapping, the
  image tag's parameters, and golden fixtures asserted by every runtime.
- PHP core: typed events, identity normalization and hashing, the Conversions API
  client, and the image tag.
- JavaScript package: a typed wrapper over the official `oaiq` browser SDK.
- Laravel adapter: service provider, facade, queued delivery, request
  attribution and a Blade Pixel helper.
- WordPress plugin: Pixel, Conversions API, settings screen, connection test,
  and integrations for Contact Form 7, Elementor Forms and WooCommerce.
- Google Tag Manager templates for web and server containers. The server
  template's hand-written identity normalization - the sandbox has no regular
  expressions, so that code could not be shared - is asserted against the same
  pinned fixtures as PHP and TypeScript, and against the browser package's output
  directly, by `packages/js/tests/gtmParity.test.ts`.
- **Image Tag support**, the third measurement channel OpenAI documents: a 1x1
  `<img>` that reports a conversion without JavaScript, for an email body, an AMP
  page or a `<noscript>` fallback. `ImageTag::url()` in the core,
  `openai_ads_image_tag()` in WordPress, `OpenAIAds::imageTagUrl()` in Laravel.
  It carries the same `event_id` as the server event, so the two deduplicate.
  An event carrying identity or `opt_out` is **refused** rather than silently
  stripped — the channel supports neither, and losing matching or a privacy flag
  without being told is the failure this toolkit exists to prevent. Say it out
  loud with `Event::withoutIdentity()`.
- **`UserData::toPixelArray()`**: the Measurement Pixel's identity shape in PHP,
  with the same digests as `toCapiArray()` under singular scalar keys. A
  server-rendered page can now carry identity without shipping a raw email
  address to the browser for `hashUser()` to hash it there.
- `Event::pixelData()`, `Event::pixelOptions()` and `Content::toPixelArray()`,
  which drops the two content fields documented as server-side only.
- `UserData::fromUntrusted()`, for host boundaries where identity arrives from a
  form rather than from application code.
- WordPress: `openai_ads_hash_user()`, `openai_ads_image_tag()`, and the
  `openai_ads_pixel_identity` filter, which takes RAW values and hashes them
  server-side.
- Laravel: `OpenAIAds::pixelUser()` and `OpenAIAds::imageTagUrl()`.
- Continuous integration across PHP 8.2–8.4, Laravel 11 and 12, and Node 22,
  plus a credential-exposure check that scans the built JavaScript bundle.
- Static analysis and code style across every package, in CI: PHPStan at level 9
  over the core and 8 over the adapters, with WordPress and WooCommerce stubs
  loaded; PHP-CS-Fixer; and type-aware ESLint for the browser package. Each
  config records why its level is what it is.

### Changed

- **Deduplication behaviour: geographic values are normalized, not merely
  trimmed.** Re-verified against the live Conversions API documentation on
  2026-09-11, which documents a rule for each geographic field and drops a value
  that does not satisfy it without reporting anything. Cities and regions are
  trimmed, lowercased (Unicode-aware) and capped at 128 characters; postal codes
  are reduced to letters, digits, spaces and hyphens and capped at 32, with case
  preserved; country codes must be two letters and are emitted uppercase.
  Previously all four were only trimmed, so `country: "Romania"` looked delivered
  and matched nobody. Applied in PHP, JavaScript and the server-side GTM
  template, and pinned in `packages/spec/fixtures/normalization.cases.json`.
- **Deduplication behaviour: phone normalization removes only the four
  documented separators.** Upstream specifies "8–15 digits after removing a
  leading `+`, leading zeroes, whitespace, parentheses, periods, and hyphens".
  The toolkit previously removed every non-digit, which turned
  `+1 (555) 123-4567 ext. 89` into thirteen digits that passed the length check
  and hashed to a number belonging to nobody. Such a value is now refused. Every
  well-formed number normalizes exactly as before; no pinned digest moved.
- **Laravel: `@openaiAdsPixel` takes raw identity, not digests.**
  `@openaiAdsPixel(['email' => $user->email])` hashes on the server before the
  view is reached. Asking applications to hash by hand is how a raw address ends
  up in page source.
- **WordPress: the `openai_ads_pixel_user` filter is replaced by
  `openai_ads_pixel_identity`**, which takes raw values under the documented
  field names rather than pre-computed digests. The rename is deliberate: the two
  take incompatible input, and a silently reinterpreted filter would ship
  unhashed values.
- The WordPress adapter drops an individual identity field the core refuses
  instead of losing the whole conversion, and announces it through the new
  `openai_ads_identity_field_dropped` action. Form input is untrusted, and a
  customer typing an extension after their phone number should not cost the site
  a paid order.

### Fixed

- **WooCommerce: a refund is no longer reported as a purchase.** `wc_get_order()`
  returns a `WC_Order_Refund` as readily as a `WC_Order`; both carry
  `get_total()` and `get_order_number()`, and the integration was checking for
  the method rather than the class. The result was a conversion reported for
  money going the other way — an over-count, in the direction that corrupts
  optimization and that nothing downstream flags.
- `postal_code` versus `zip_code` on the Pixel is resolved: the live Measurement
  Pixel documentation lists `postal_code`, which is what both runtimes already
  emitted. The `UNRESOLVED` note in `packages/spec/user.json` is closed.

[Unreleased]: https://github.com/webaroundlabs/openai-ads-toolkit/commits/main
