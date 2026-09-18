# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

While the version is `0.x` the public API may change in any release. See
[Versioning](README.md#versioning).

## [Unreleased]

## [0.2.1] - 2026-09-18

### Fixed

- **Three settings-screen labels were never translatable.** `Consent::modes()`
  returned them as plain strings and `SettingsPage` translated the *variable* —
  `__($label, ...)` — which the extractor cannot follow, so none of the three
  reached the `.pot` and all three stayed English in all ten catalogues while
  looking perfectly translatable in the source. They are now literals inside
  `modes()`. Found by the official Plugin Check tool, which this release is a
  clean pass of; a spot the parity tests and `make-pot.py` were both structurally
  blind to, because the string simply was not there to compare.

### Changed

- **The plugin passes WordPress's own Plugin Check with no errors and no
  warnings**, run against the built zip rather than the working tree - the zip is
  what a reviewer receives, and the tree carries tests and dev dependencies that
  never ship. What was genuinely wrong is fixed: the untranslatable labels above,
  unprefixed file-scope variables in `uninstall.php` and the bootstrap, and a
  `$advice` string now passed through `wp_kses_post()` rather than printed raw.
  What is not wrong carries a `phpcs:ignore` naming the reason - `wp_json_encode()`
  output in a `<script>`, an exception message that is never printed, superglobals
  that must reach the API byte for byte, and the plugin's own gated debug log.
- **`load_plugin_textdomain()` stays, against Plugin Check's advice, and now says
  why in the code.** The advice is right for translations served by GlotPress and
  wrong for the ones this plugin bundles: on WordPress 7.1, clearing the domain
  from `WP_Textdomain_Registry` and asking for `ro_RO` returns the English string
  and a path of `false`. Without the call every bundled language is dead.
- **The plugin zip carries `composer.json`.** Nobody installing it runs Composer,
  but a `vendor/` directory arriving without the manifest that explains it is
  something Plugin Check flags and a reviewer is entitled to ask about.

## [0.2.0] - 2026-09-18

### Added

- **Both GTM templates are gallery-ready.** Each has been round-tripped through
  Tag Manager's template editor: the `___TERMS_OF_SERVICE___` section is present,
  a human having ticked the box that writes it, and `___INFO___` now carries the
  brand thumbnail. The sandboxed JavaScript came back byte-identical in both, and
  the parameter and permission blocks are semantically unchanged — the editor
  reformatted their JSON and dropped the blank lines between test cases, nothing
  more. The names carry a `by Webaround` suffix because `OpenAI Ads Measurement
  Pixel` was already taken in the gallery; the server template is
  `OpenAI Ads Conversions API by Webaround`, since it posts to the Conversions
  API and loads no Pixel, and a name contradicting its own description is a name
  that sends people to the wrong tag.

- **The WordPress plugin suggests privacy policy text**, through
  `wp_add_privacy_policy_content()`, so the wording appears under Tools → Privacy
  alongside every other plugin's. WordPress asks this of any plugin that
  discloses data to a third party, and measuring a conversion is nothing else.
  The draft says which OpenAI service receives what, that identifiers are hashed
  before they leave the server, that commerce events carry product names
  unhashed, and that query strings are removed - the same facts as the
  `External services` section of `readme.txt`, addressed to a visitor rather
  than to a site owner. It is a draft of facts, never a claim of compliance: the
  site owner is the data controller.
- **The WordPress plugin is translated into ten languages** — Bulgarian, Dutch,
  French, German, Greek, Hungarian, Polish, Portuguese, Romanian and Spanish —
  all 83 strings each, compiled to `.mo` and bundled. Product names are left
  alone: `Measurement Pixel`, `Conversions API` and `Pixel ID` are what OpenAI's
  documentation calls them, and a developer comparing the screen against those
  docs has to recognize them. The privacy policy draft addresses a site's
  visitors rather than its owner, so it takes each language's formal register
  where the two differ.
- **`scripts/make-mo.py` compiles and checks the catalogues**, and CI runs
  `--check` on every pull request. WordPress reads `.mo`, so a `.po` edited
  without a rebuild is a screen that silently stays English; the check refuses
  that, a msgid that drifted from the `.pot`, an empty translation, and a
  `printf` placeholder lost in translation — a dropped `%s` renders the sentence
  without the value it exists to carry, and a `%d` turned into `%s` throws on a
  settings screen. It packs the `.mo` itself rather than shelling out to
  `msgfmt`: needing a toolchain to rebuild a translation is how translations
  stop being rebuilt.
- **`tests/bootstrap.php` stubs the gettext functions**, recording the text
  domain each string was translated against rather than translating it. A string
  passed the wrong domain simply never picks up a translation, and nothing about
  the page looks broken when it happens — so a test now sees it.
- **Install instructions where the registries send people.** `packages/js` said
  "Not published to npm" while `@webaround/openai-ads` was on npm, and
  `packages/php` documented no `composer require` at all although
  `webaround/openai-ads` is on Packagist. Both now open with the command that
  installs them, and the root README carries all three near the top.

- **The two GTM templates are mirrored into single-template repositories** —
  `openai-ads-gtm-web` and `openai-ads-gtm-server` — which is the shape the
  Community Template Gallery indexes: one template per repository, with
  `template.tpl`, `metadata.yaml`, `LICENSE` and `README.md` in the root.
  `scripts/mirror-gtm.py` builds them and the `Mirrors` workflow keeps them
  current. Unlike the Packagist mirrors these are append-only and never
  force-pushed: the gallery serves each published version from the commit sha
  `metadata.yaml` names, and a sha that stops being reachable is a template that
  stops loading. A release there is two commits, because a version entry names
  the commit holding the template it publishes and no commit can contain its own
  sha. `packages/gtm-collect` has no mirror — it needs this project's WordPress
  plugin to do anything, so in the gallery it would be a tag that does nothing
  for nearly everyone who found it.
- `python scripts/mirror-gtm.py --into build/gtm-mirrors` shows what the gallery
  would receive, with no network and no credentials. CI runs it on every pull
  request, so a stray file in either package, a `LICENSE` that is no longer the
  Apache 2.0 text, or a README link that would 404 outside the monorepo fails
  there rather than in a review round at Google.

### Changed

- **The WordPress `readme.txt` said the opposite of what the plugin does about
  consent.** Under `Your responsibilities` it claimed that finding no consent
  mechanism made the plugin say so "rather than assuming you meant to measure
  everybody", while the FAQ six sections later said "Everything is measured" -
  which is the behaviour. The settings screen warns; it does not stop. The
  recorded decision is that measuring everybody is the deliberate, uncomfortable
  default, because a plugin that silently measures nothing sends its owner
  hunting for a fault for days. The section now states it, once.
- **`readme.txt` is 9.8 KB, down from 14.4 KB.** The plugin handbook's File Size
  note is explicit: "having a file larger than 10k may result in errors". Every
  disclosure survives intact - the three addresses, what each sends, when, and
  what is not sent - and the prose around them is shorter.
- **A package's own `LICENSE` is linked from its own README.** All four read
  `[MIT](../../LICENSE)`, which points above the package root: correct in a
  monorepo checkout, a 404 on Packagist and npm, where the package root is the
  repository root - and pointless either way, because each package already ships
  the same MIT text beside its README. The WordPress plugin is the exception and
  keeps both links: its own `LICENSE` is GPLv2-or-later, and the sentence about
  the rest of the toolkit being MIT now names the root file by absolute URL,
  because that README ships inside the plugin zip.
- **`packages/gtm-web` and `packages/gtm-server` are Apache-2.0**, where the rest
  of the toolkit is MIT. The gallery requires a repository whose `LICENSE` is the
  Apache 2.0 text and nothing else, so a template published there cannot carry
  another licence. The reason the rest of the repository is MIT — Apache-2.0 is
  incompatible with GPLv2, and the WordPress plugin bundles the PHP core — does
  not reach these two, because nothing bundles them into the plugin.
  `packages/gtm-collect` is unaffected and stays MIT.
- `.github/workflows/split.yml` is now `mirrors.yml`. It no longer only splits:
  half of it does, for Packagist, and half of it does not, for the gallery.
- **The WordPress plugin's `External services` section names every address and
  the commerce data.** It described a commerce event as carrying "the amount and
  currency", but both the Pixel and the Conversions API send the whole basket:
  each item's SKU or product id, its name, the quantity, that line's price and
  the variation attributes. Product names leave the site unhashed, which a site
  owner is entitled to know before switching this on. The section now also lists
  the three OpenAI addresses in one place and states what the plugin does *not*
  contact - no author endpoint, no telemetry, no licensing or update service -
  because a reviewer reading a third-party plugin that talks to an ad platform
  will ask, and the answer should not require reading the source. Verified both
  ways: every address in the section appears in the code, and every address in
  the code appears in the section.
- **The copyright holder is `Webaround`, everywhere.** It read `Webaround Labs`
  in every licence file, both GTM READMEs, the root README and the translation
  catalogue's header, while the WordPress plugin header, the npm scope, the
  Composer vendor and the domain all already said `Webaround`. The two GTM
  packages were the sharpest case: their `LICENSE` and their `README.md` ship to
  the gallery together and disagreed with each other. The PHP namespace
  `WebaroundLabs\` is deliberately untouched - it is a code identifier, and
  moving it breaks every integrator's `use` statement - and so are the
  `github.com/webaroundlabs` URLs, which name the account that actually holds
  the repository, and the `webaroundlabs-*` integration_source values, which are
  on the wire. Regenerating the catalogue changed source-line references and its
  header only; no `msgid` moved, so no existing translation is invalidated.

### Fixed

- **A Laravel test had a date in it, and that date arrived.**
  `serializing_the_job_preserves_the_event_id_and_timestamp` pinned
  `1789041600000` — 2026-09-10 — and went red on 2026-09-17, exactly seven days
  later. Nothing was wrong with the code: the job correctly refuses an event
  that has aged out of the API's seven-day window, and that window is checked at
  send time. The other suites pin the same constant safely because they only
  serialize an event; this one delivers it. It now takes its timestamp relative
  to now, and still asserts the exact value survives the round trip, which was
  always the point.
- **WordPress: values read from `$_COOKIE` and `$_SERVER` are unslashed before
  they are sent.** WordPress runs `add_magic_quotes()` over both on every
  request, so a user agent, a page address or an `__oppref` value containing a
  quote arrived with a backslash in front of it and was reported to OpenAI that
  way - a user agent nobody has, and an attribution reference that matches
  nothing. The comment on the cookie reader said the value "must reach the API
  byte for byte", which was the intent and not what happened: WordPress had
  already changed the bytes. Every read now goes through `wp_unslash()`.

### Security

- **Every file in the WordPress plugin refuses to run without `ABSPATH`.** The
  source files are loaded through Composer's autoloader and none of them does
  anything at include time, but twelve of them extend or implement something,
  so a direct request for one produced a fatal error naming the installation
  path. The bundled `vendor/` is deliberately left alone: the PHP core is
  published on Packagist for use outside WordPress and must not learn a
  WordPress constant.

## [0.1.2] - 2026-09-12

### Changed

- **The settings live in the admin menu rather than under Settings**, split
  across three screens: General (Pixel, Conversions API, privacy and the
  connection test), Integrations (the host plugins found here, and how consent
  is decided) and Tag manager (the collection endpoint and what it has
  received). The handbook recommends Settings for a plugin with a single option
  page; this one has three, plus a live log people come back to. The warning
  that a site is measuring everybody without asking appears on General too, so
  splitting the screens cannot hide it. It sits just under Comments, at a
  fractional menu position - WordPress keys the menu by position, so two plugins
  picking the same integer means one silently replaces the other.

### Fixed

- **Saving one settings screen no longer blanks another.** The settings live in
  a single option row, and `sanitize()` rebuilt the whole row from whatever the
  form posted - so a field on another screen was indistinguishable from an
  unchecked checkbox. It also hit fields the one screen deliberately hid:
  Deferred delivery is only rendered where Action Scheduler exists, and `timeout`
  and `integration_source` have no field at all, so every save quietly wrote a
  default over them. Forms now declare which settings they rendered, the way the
  integrations section already declared its checkboxes, and nothing else is
  touched.

### Added

- The brand assets the WordPress plugin directory asks for: the icon as PNG and
  SVG, both banner sizes, three screenshots and a Playground blueprint for the
  directory's Live Preview. The mark is drawn from geometry and type in
  `scripts/assets/frame.html`, rendered by a headless browser rather than
  plotted by a script, so it is provably the project's own work and carries no
  third party's logo.
- The plugin's own mark in the admin menu, in colour.

### Security

- `build-plugin.sh` checks `vendor/` against Composer's own manifest instead of
  looking for one dev package by name. An interrupted extraction leaves a
  directory behind WITHOUT recording the package, so `composer install --no-dev`
  cannot remove it - php-cs-fixer reached a built zip that way, 4.6MB of a
  development tool bound for every installed site, and only the size check
  noticed. A smaller one would have shipped in silence.

## [0.1.1] - 2026-09-12

### Fixed

- **Laravel: attribution is no longer lost to Laravel's own cookie
  encryption.** `EncryptCookies` rewrites `$request->cookies` in place and
  replaces every value it cannot decrypt with `null`. `__oppref` and `__obref`
  are written by OpenAI's browser SDK and are never encrypted by the
  application, so in any app that has not listed them in `$except` - the default
  - both came back null and every conversion was reported with no attribution at
  all, while the integration looked entirely healthy. `RequestContext` now falls
  back to the request's `Cookie` header, which no middleware rewrites, and still
  prefers the bag when an app has configured `$except`. Found against a real
  Laravel 12 application; the unit tests build a `Request` directly, which runs
  no middleware and so could not see it. The WordPress adapter reads `$_COOKIE`
  and was never affected.

## [0.1.0] - 2026-09-12

The first release. This section describes the whole toolkit rather than a delta.
The **Changed** and **Fixed** entries below are still worth reading: they record
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
  and ten integrations - Contact Form 7, Elementor Forms, Gravity Forms,
  WPForms, Fluent Forms, Ninja Forms, WooCommerce, WooCommerce Subscriptions,
  Easy Digital Downloads, and WordPress account registration.
  `trial_started` and `subscription_created` are reported by the Subscriptions
  integration; nothing else in the plugin has a boundary for them.
  Registration is **off by default**: `user_register` also fires for an
  administrator adding a colleague and for an importer restoring a backup, and
  counting those inflates the number the advertiser optimizes against.
- **A collection endpoint for WordPress**, at
  `/wp-json/openai-ads/v1/collect`, so a tag manager can hand a conversion to
  the site and have it forwarded from there. Server-side measurement without
  paying for a GTM server container: the API key stays on the server, and the
  request to OpenAI is not something an ad blocker sees. Off until switched on,
  refuses to run without a secret, constant-time comparison, rate limited, with
  a diagnostics table that never records the payload. Plus a third GTM template,
  `packages/gtm-collect`, that posts to it from a web container.
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
- Laravel: `OpenAIAds::event()` and `OpenAIAds::track()`, which fill in the five
  things only the request knows - the source URL under the configured privacy
  policy, the `__oppref` and `__obref` cookies, the client IP and the user agent
  - so a controller does not have to. `Event::create()` stays available as the
  typed alternative. Plus `OpenAIAds::pixelUser()` and
  `OpenAIAds::imageTagUrl()`.
- `EventFactory` and `HostContext` in the core: the translation from a host's
  loose array to a validated `Event`, shared by both adapters rather than
  written once per host.
- Continuous integration across PHP 8.2–8.4, Laravel 11 and 12, and Node 22,
  plus a credential-exposure check that scans the built JavaScript bundle.
- A WordPress translation catalogue, `packages/wordpress/languages/*.pot`,
  regenerated by `scripts/make-pot.py` from a bare checkout - no WP-CLI needed.
- An **External services** section in `readme.txt` naming every endpoint the
  plugin contacts, what is sent to each, when, and what is never sent. The plugin
  directory requires the disclosure; a site owner deserves it regardless.
- A release pipeline: a pushed tag re-verifies everything, publishes to npm with
  provenance, builds the WordPress plugin zip from an allowlist, and opens a
  draft GitHub release. `scripts/check-version.py` refuses a tag that disagrees
  with any manifest. See `docs/releasing.md`.
- Static analysis and code style across every package, in CI: PHPStan at level 9
  over the core and 8 over the adapters, with WordPress and WooCommerce stubs
  loaded; PHP-CS-Fixer; and type-aware ESLint for the browser package. Each
  config records why its level is what it is.

### Changed

- **The WordPress plugin's slug is `conversion-tracking-for-openai-ads`.** The
  plugin directory refuses a slug beginning with somebody else's trademark, and
  a slug is permanent once published, so this is a rename made while it is still
  free. The text domain matches it, because translations from
  translate.wordpress.org are keyed on the slug. The `openai_ads_*` function and
  hook prefixes are unrelated to the slug and are unchanged.
- **WordPress consent is detected rather than demanded.** The plugin reads the WP
  Consent API - the shared standard Complianz, CookieYes, Borlabs and Real Cookie
  Banner all publish into - and Complianz's own function directly. Other banners
  are detected by class, named on the settings screen, and never read by
  guesswork: a consent check that answers confidently and wrongly is worse than
  one that says it does not know. A site with no mechanism at all is warned on
  the settings screen instead of quietly measuring everybody. The
  `openai_ads_consent` filter still runs last and still wins, and
  `openai_ads_consent_providers` adds a banner the plugin does not know.
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
- **The Composer packages are `webaround/openai-ads` and
  `webaround/openai-ads-laravel`,** matching the npm scope. The PHP namespace is
  unchanged, and so is `integration_source` - that one is a value on the wire
  with a pinned fixture behind it, not a name.
- **Packagist is fed from generated mirror repositories.** Packagist reads the
  `composer.json` at a repository root and has no concept of a package in a
  subdirectory, so `packages/php` and `packages/laravel` are mirrored into
  repositories whose root is the package. The path repository in
  `packages/laravel/composer.json` now points at `../php*`: Composer errors on a
  non-glob path whose directory is missing, which is exactly the state inside a
  mirror, and without the glob the published Laravel package could not be
  installed at all.
- **The npm package is `@webaround/openai-ads`.** The scope is the brand rather
  than the GitHub account it is developed under, and it is settled now because
  an npm name cannot be reclaimed after the first publish. Publishing itself no
  longer follows from tagging: the release workflow's `npm` environment requires
  a maintainer's approval, so a tag builds and verifies but waits before it
  reaches a registry nobody can take it back from.
- **Laravel 12 is the version CI proves.** The adapter still requires
  `^11.0|^12.0` and installs on Laravel 11 for anyone already there, but
  every `laravel/framework` 11.x now carries an unfixed Packagist security
  advisory, so Composer refuses to resolve one and the 11.x CI legs cannot be
  made green. They are removed rather than silenced: turning the advisory
  check off to earn a tick would report a guarantee nobody has.

### Fixed
- **The JavaScript test runner no longer carries known vulnerabilities.** vitest
  2 pulled in vite and esbuild versions with seven advisories against them, one
  critical. vitest 5 resolves all of them, and needed no change to a test.
  Nothing reached the published package, which has no runtime dependencies and
  ships only `dist/` - but a dev-server advisory is still a real one for anyone
  who clones this repository.


- **A conversion submitted in the background reports the page it came from.**
  Contact Form 7 posts to a REST route; Elementor, WPForms and Ninja Forms post
  to `admin-ajax.php`; a Laravel form posted with fetch(), Inertia or Livewire
  reaches an API route. `source_url` was taken from the request being served, so
  every lead on a site arrived labelled `/wp-admin/admin-ajax.php` and the
  landing page that earned it was lost. Both adapters now take the referring page
  on a background request, and only there - on an ordinary page view the request
  URL is still the page, because a referer there is the page *before* this one.
  The referer is client-controlled and treated as such: the origin is rebuilt
  from the canonical origin, so a forged one cannot put another domain into the
  payload, and a client that sends none falls back to the old behaviour.
- **WordPress: a queued batch is delivered again.** `ScheduledDelivery` stores
  the batch and queues `openai_ads_deliver_batch`, but nothing was ever hooked to
  that action, so Action Scheduler ran it, found no callback, marked it complete
  and the conversion was gone. Every WooCommerce site took this path by default —
  Action Scheduler ships inside WooCommerce and `use_scheduler` defaults to on —
  which meant the plugin's largest audience lost every event, silently and with
  debug logging switched on. The stored `openai_ads_batch_*` option was orphaned
  in `wp_options` on top of it. Found on a real site; the unit suite exercised
  `deliverScheduledBatch()` directly and never asked whether WordPress would ever
  call it.
- **WooCommerce: `contents_viewed` fires on a product page again.** The current
  product was fetched with `wc_get_product(null)`, and
  `WC_Product_Factory::get_product_id()` recognizes "the product this page is
  about" as `false ===` — strictly. Null matched no branch and came back as
  `false`, so the integration saw no product and returned early on every product
  page. Purchases still arrived, which is what made it hard to notice: only the
  top of the funnel was missing.
- **WooCommerce: a refund is no longer reported as a purchase.** `wc_get_order()`
  returns a `WC_Order_Refund` as readily as a `WC_Order`; both carry
  `get_total()` and `get_order_number()`, and the integration was checking for
  the method rather than the class. The result was a conversion reported for
  money going the other way — an over-count, in the direction that corrupts
  optimization and that nothing downstream flags.
- `postal_code` versus `zip_code` on the Pixel is resolved: the live Measurement
  Pixel documentation lists `postal_code`, which is what both runtimes already
  emitted. The `UNRESOLVED` note in `packages/spec/user.json` is closed.

[Unreleased]: https://github.com/webaroundlabs/openai-ads-toolkit/compare/v0.2.1...main
[0.2.1]: https://github.com/webaroundlabs/openai-ads-toolkit/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/webaroundlabs/openai-ads-toolkit/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/webaroundlabs/openai-ads-toolkit/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/webaroundlabs/openai-ads-toolkit/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/webaroundlabs/openai-ads-toolkit/releases/tag/v0.1.0
