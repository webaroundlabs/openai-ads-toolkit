# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

While the version is `0.x` the public API may change in any release. See
[Versioning](README.md#versioning).

## [Unreleased]

### Changed

- **Deduplication behaviour: geographic values are now normalized.** Re-verified
  against the live Conversions API documentation on 2026-09-11, which documents a
  rule for each geographic field and drops a value that does not satisfy it
  without reporting anything. Cities and regions are trimmed, lowercased
  (Unicode-aware) and capped at 128 characters; postal codes are reduced to
  letters, digits, spaces and hyphens and capped at 32, with case preserved;
  country codes must be two letters and are emitted uppercase. Previously all
  four were only trimmed, so `country: "Romania"` looked delivered and matched
  nobody. Applied in PHP, JavaScript and the server-side GTM template, and pinned
  in `packages/spec/fixtures/normalization.cases.json`.
- **Deduplication behaviour: phone normalization removes only the four
  documented separators.** Upstream specifies "8-15 digits after removing a
  leading `+`, leading zeroes, whitespace, parentheses, periods, and hyphens".
  The toolkit previously removed every non-digit, which turned
  `+1 (555) 123-4567 ext. 89` into thirteen digits that passed the length check
  and hashed to a number belonging to nobody. Such a value is now refused. Every
  well-formed number normalizes exactly as before; the pinned digests are
  unchanged.
- The WordPress adapter now drops an individual identity field the core refuses
  instead of losing the whole conversion, and announces it through the new
  `openai_ads_identity_field_dropped` action. Form input is untrusted, and a
  customer typing an extension after their phone number should not cost the site
  a paid order.

### Fixed

- `postal_code` versus `zip_code` on the Pixel is resolved: the live Measurement
  Pixel documentation lists `postal_code`, which is what both runtimes already
  emitted. The `UNRESOLVED` note in `packages/spec/user.json` is closed.

### Added

- Shared specification: 13 events, four data shapes, the identity mapping, and
  golden fixtures asserted by every runtime.
- PHP core: typed events, identity normalization and hashing, and the
  Conversions API client.
- JavaScript package: a typed wrapper over the official `oaiq` browser SDK.
- Laravel adapter: service provider, facade, queued delivery, request
  attribution and a Blade Pixel helper.
- WordPress plugin: Pixel, Conversions API, settings screen, connection test,
  and integrations for Contact Form 7, Elementor Forms and WooCommerce.
- Google Tag Manager templates for web and server containers.
- Continuous integration across PHP 8.2-8.4, Laravel 11 and 12, and Node 22,
  plus a credential-exposure check.

[Unreleased]: https://github.com/webaroundlabs/openai-ads-toolkit/commits/main
