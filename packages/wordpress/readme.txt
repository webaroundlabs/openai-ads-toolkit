=== Conversion Tracking for OpenAI Ads ===
Contributors: webaround
Tags: openai, conversion tracking, analytics, pixel, conversions api
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Measurement Pixel and Conversions API for OpenAI Ads, with browser/server deduplication.

== Description ==

Adds the OpenAI Ads Measurement Pixel to your site and sends the same conversions
from your server through the Conversions API, so a conversion measured in both
places is counted once rather than twice.

**This is an independent community integration.** It is not created, certified,
endorsed or supported by OpenAI. "OpenAI" and "ChatGPT" are trademarks of OpenAI.

= What it does =

* Loads the official OpenAI Ads Pixel, once, in your site head.
* Sends server-side conversions through the Conversions API.
* Deduplicates the two using a shared event id.
* Hashes email addresses, phone numbers and names before they leave your server,
  using the normalization OpenAI documents.
* Strips query strings from the page URL before sending it, so search terms,
  order keys and password-reset tokens do not reach an ad platform.
* Defers to your existing consent mechanism. It ships no cookie banner and makes
  no privacy decisions for you.
* Never makes the visitor wait. Conversions are collected during the request and
  sent after the page has gone out.
* Survives the request that produced them. Where the site has Action Scheduler -
  which every WooCommerce site does - the batch is handed to it, so a fatal error
  later in the request cannot lose the conversion.
* Retries nothing, deliberately. Repeating a request whose outcome is unknown
  risks reporting a purchase twice, and a silently inflated conversion count
  corrupts the bidding it feeds and cannot be undone afterwards.
* Works on any WordPress site. WooCommerce, a form plugin and a consent banner
  are each optional; the plugin finds what is there and uses it.

= Form integrations =

Contact Form 7, Elementor Forms, Gravity Forms, WPForms, Fluent Forms and Ninja
Forms are detected automatically. A lead is recorded when the submission is
accepted, never when the button is clicked.

Contact Form 7 and Elementor also fire the matching browser event, sharing one
event id with the server so the conversion is counted once. The other four
report server-side only, which needs no deduplication because nothing fires a
second report for the same conversion.

None of them is required. Each can be switched off individually under
OpenAI Ads -> Integrations.

= Tag manager endpoint =

Optional, and off by default. Lets Google Tag Manager hand a conversion to your
site, which forwards it to OpenAI - server-side measurement without paying for a
GTM server container. Your API key stays on your server and the request to
OpenAI is not something an ad blocker can see.

Protected by a generated secret, rate limited, and it refuses to run without
one. Only switch it on if you are using it.

= Other integrations =

* **Easy Digital Downloads** - a purchase once the payment completes, with line
  items and the buyer's details.
* **WooCommerce Subscriptions** - `trial_started` when a subscription begins in
  its trial, `subscription_created` when a paid one activates.
* **WordPress registration** - `registration_completed` on signup. Off by
  default, deliberately: `user_register` also fires for an administrator adding
  a colleague and for an importer restoring a backup, and neither is a
  conversion.

= WooCommerce =

Measures product views, add to cart, checkout start and paid orders. A purchase
is reported only once payment is confirmed - never for a pending, failed or
cancelled order - and never twice, however many times the gateway or a webhook
triggers the same order.

Amounts use the currency's own minor unit, so yen and dinars are correct as well
as euros, and line items reflect discounts rather than list prices.

= For developers =

Record a conversion at a confirmed boundary:

`openai_ads_track( 'lead_created', [], [ 'event_id' => $lead_id, 'user' => [ 'email' => $email ] ] );`

Supported events are the ones OpenAI documents: page_viewed, contents_viewed,
items_added, checkout_started, order_created, lead_created,
registration_completed, appointment_scheduled, subscription_created,
trial_started, and custom. Two more - app_installed and app_opened - exist in
the API but accept only action_source "mobile_app", so they are refused from a
web page rather than silently reshaped.

Other functions:

* `openai_ads_event_id()` - a deduplication id for a flow with no id of its own.
  Generate once, use for both the server event and the browser event.
* `openai_ads_pixel_event( $event, $event_id, $data )` - prints the browser half
  of a conversion the server has already recorded, so the two are matched.
* `openai_ads_hash_user( [ 'email' => $email ] )` - the documented normalization
  and SHA-256, if you need the hashes yourself.
* `openai_ads_image_tag( $event, $data )` - a 1x1 image conversion, for an email
  or anywhere JavaScript cannot run. Carries no identity at all: the channel has
  no user object, so an event carrying one is refused rather than quietly
  stripped.
* `openai_ads_is_configured()` - whether measurement is switched on and has
  credentials.

Filters: `openai_ads_enabled`, `openai_ads_consent`, `openai_ads_consent_providers`,
`openai_ads_event`, `openai_ads_pixel_identity`, `openai_ads_client_ip`,
`openai_ads_integrations`, `openai_ads_form_event`, `openai_ads_form_user_data`,
`openai_ads_should_report_registration`, `openai_ads_ingest_rate_limit`.

Actions: `openai_ads_sent`, `openai_ads_failed`, `openai_ads_invalid_event`,
`openai_ads_form_recorded`, `openai_ads_identity_field_dropped`.

== External services ==

This plugin connects to OpenAI in order to measure advertising conversions.
That is its entire purpose, and nothing here happens without you entering a
Pixel ID or an API key first.

= Which services, and only which =

Three addresses, all of them OpenAI's:

* https://bzrcdn.openai.com/sdk/oaiq.min.js - the browser SDK, when the Pixel is on
* https://bzr.openai.com/v1/events - the Conversions API, from your server
* https://bzr.openai.com/v1/sdk/events - the image tag, only if you call it

There is no fourth. The plugin sends nothing to its author, to webaround.ro, or
to any analytics, telemetry, licensing or update service. It counts no installs
and phones home to nobody. The Conversions API address is a constant in the
source, not something fetched at runtime, so it cannot be redirected elsewhere
by a future update without that change being visible in the code.

= 1. The OpenAI Ads Measurement Pixel =

When the Pixel is switched on, the plugin loads a script from
https://bzrcdn.openai.com/sdk/oaiq.min.js into your pages and initializes it
with your public Pixel ID.

That script is written and hosted by OpenAI. It sets first-party cookies named
__oppref and __obref to remember which advertisement a visitor arrived from, and
it reports the conversion events you configure.

WHAT IS SENT: the event name, a deduplication id, the page address, the amount
and currency where the event carries one, and - only when your site supplies it -
identity as irreversible SHA-256 hashes. It runs in the visitor's browser, so
their IP address and browser user agent reach OpenAI as part of any web request.

On a commerce event it also carries the basket: for each item its SKU (or the
numeric product id when no SKU is set), its name, the quantity, the price of
that line, and the variation attributes such as size or colour. Product names
are sent as they appear in your catalogue, and are not hashed: hashing is for
identifying a person, and a product name identifies a product.

WHEN: on any page view, once you have enabled the Pixel.

= 2. The OpenAI Ads Conversions API =

When server-side events are switched on, your server sends conversions to
https://bzr.openai.com/v1/events using the API key you provide.

WHAT IS SENT: the same event data as above, including the basket detail, plus
the visitor's IP address and user agent, plus the two attribution values read
from the __oppref and __obref cookies, plus the page address the conversion
happened on. Email addresses, phone numbers, names and customer ids are hashed
with SHA-256 on your server before they leave it - the raw values are never
transmitted.

WHEN: after a conversion your site confirms - a paid order, an accepted form
submission - and never on a page view.

= 3. The image tag =

Only if you call openai_ads_image_tag() yourself. It requests a 1x1 image from
https://bzr.openai.com/v1/sdk/events, carrying the event name, the deduplication
id and the amount. It carries no personal data of any kind, by design.

= Terms and privacy =

OpenAI's terms: https://openai.com/policies/
OpenAI's privacy policy: https://openai.com/policies/privacy-policy/
Advertising documentation: https://developers.openai.com/ads/

= Your responsibilities =

Sending a visitor's data to OpenAI is a disclosure to a third party. You are
responsible for saying so in your own privacy policy and for obtaining consent
where the law requires it.

The plugin helps rather than decides. It detects a consent plugin - the WP
Consent API and Complianz are read directly - and when consent for the
"marketing" category is refused, no Pixel is loaded and no event is constructed.
If it finds no consent mechanism at all, it says so on its settings screen
rather than assuming you meant to measure everybody.

= What is NOT sent =

* No raw email address, phone number or name. Ever. Only SHA-256 hashes.
* No query strings from your page addresses by default - they routinely carry
  email addresses, password reset tokens and order keys.
* No data at all until you enter credentials, and none for a visitor who has
  refused marketing consent.

This plugin is an independent community integration. It is not created,
certified, endorsed or supported by OpenAI. "OpenAI" and "ChatGPT" are
trademarks of OpenAI.

== Installation ==

1. Install and activate the plugin.
2. Go to OpenAI Ads in the admin menu.
3. Enter your Pixel ID and your Conversions API key.
4. Open Integrations and check what the Consent section says. If it reports
   that no consent mechanism was found, decide what you want before going
   further.
5. Use "Send a test event" on the General screen to confirm the credentials
   work. It uses the API's
   validation mode, so nothing is recorded and no fake conversion is created.

Everything is configured from that one screen. Nothing requires editing a file.

Developers who prefer to keep credentials out of the database entirely - so they
are absent from backups and staging copies - may define them as constants in
`wp-config.php` instead, and the settings screen will show them as fixed there.
That is an option, never a requirement.

== Frequently Asked Questions ==

= What does deduplication actually do? =

A purchase measured in the browser and again on the server is one purchase, but
two reports. Both halves of this plugin send the same event id, so OpenAI matches
them and counts one. You get the browser's reach and the server's reliability
without the conversion count drifting upwards.

= Do I need the Conversions API key, or is the Pixel enough? =

The Pixel alone works, and needs only a Pixel ID. It is also the half an ad
blocker, an iOS privacy setting or a failed script can remove. The API key adds
the server-side half, which nothing in the browser can block - and because the
two are deduplicated, adding it does not inflate anything.

= I have no cookie banner. What happens? =

Everything is measured, and the settings screen tells you so in as many words.
That is the uncomfortable default and it is deliberate: the alternative is a
plugin that silently measures nothing while you hunt for the fault. Install a
banner that supports the WP Consent API and it is used automatically.

= Can I use this with Google Tag Manager? =

Yes, and without paying for a server-side container. Switch on the collection
endpoint, point a tag at it, and your server forwards the conversion to OpenAI
with the API key never leaving it. It is off until you switch it on and it
refuses every request that does not carry the generated secret.

= Does this slow down my site? =

No. Conversions are never sent while the visitor is waiting. On a WooCommerce
site they are handed to Action Scheduler and delivered in a later request; on
other sites they are sent after the page has been delivered.

= Do I need WooCommerce for this? =

No. WooCommerce is optional. If it happens to be installed, the plugin reuses the
background queue it already ships so conversions survive a request that dies
early. Without it, nothing changes.

= Will a measurement failure break my checkout? =

No. If reporting fails the plugin logs it and moves on. The order, form
submission or registration still completes.

= Is my API key exposed? =

No. It is used only server-side and is never printed into a page. The plugin's
test suite asserts this.

== Screenshots ==

1. General - the Pixel, the Conversions API, what is trimmed before anything is
   sent, and a connection test that validates your credentials without recording
   a conversion.
2. Integrations and consent - every supported plugin found on the site, each one
   switchable, and a plain statement of which consent mechanism is being asked.
3. Tag manager endpoint - an address your tag manager can post conversions to,
   protected by a generated secret, with a log of what it has received lately.

== Changelog ==

= 0.1.2 =
* The settings moved out of Settings and into their own menu, split across
  General, Integrations and Tag manager.
* Fixed: saving one settings screen could blank the settings on another,
  including the Pixel ID and the API key. It could also overwrite a setting the
  screen had hidden - on a site without WooCommerce, every save turned deferred
  delivery off.
* The plugin's mark now appears in the admin menu.

= 0.1.1 =
* No change to this plugin. The release fixed attribution in the toolkit's
  Laravel adapter, which this plugin does not use.

= 0.1.0 =
* First release: Pixel, Conversions API, deduplication, settings screen and a
  connection test.
* Contact Form 7, Elementor Forms and WooCommerce integrations.
