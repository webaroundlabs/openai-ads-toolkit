# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

While the version is `0.x` the public API may change in any release. See
[Versioning](README.md#versioning).

## [Unreleased]

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
