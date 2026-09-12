=== Conversion Tracking for OpenAI Ads ===
Contributors: webaround
Tags: openai, conversion tracking, analytics, pixel, conversions api
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 0.1.0
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

= Form integrations =

Contact Form 7, Elementor Forms, Gravity Forms, WPForms, Fluent Forms and Ninja
Forms are detected automatically. A lead is recorded when the submission is
accepted, never when the button is clicked.

Contact Form 7 and Elementor also fire the matching browser event, sharing one
event id with the server so the conversion is counted once. The other four
report server-side only, which needs no deduplication because nothing fires a
second report for the same conversion.

None of them is required. Each can be switched off individually under
Settings -> OpenAI Ads.

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
trial_started, and custom.

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

WHEN: on any page view, once you have enabled the Pixel.

= 2. The OpenAI Ads Conversions API =

When server-side events are switched on, your server sends conversions to
https://bzr.openai.com/v1/events using the API key you provide.

WHAT IS SENT: the same event data as above, plus the visitor's IP address and
user agent, plus the attribution values from the two cookies. Email addresses,
phone numbers, names and customer ids are hashed with SHA-256 on your server
before they leave it - the raw values are never transmitted.

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
2. Go to Settings → OpenAI Ads.
3. Enter your Pixel ID and your Conversions API key.
4. Check what the Consent section says. If it reports that no consent mechanism
   was found, decide what you want before going further.
5. Use "Send a test event" to confirm the credentials work. It uses the API's
   validation mode, so nothing is recorded and no fake conversion is created.

Everything is configured from that one screen. Nothing requires editing a file.

Developers who prefer to keep credentials out of the database entirely - so they
are absent from backups and staging copies - may define them as constants in
`wp-config.php` instead, and the settings screen will show them as fixed there.
That is an option, never a requirement.

== Frequently Asked Questions ==

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

== Changelog ==

= 0.1.0 =
* First release: Pixel, Conversions API, deduplication, settings screen and a
  connection test.
* Contact Form 7, Elementor Forms and WooCommerce integrations.
