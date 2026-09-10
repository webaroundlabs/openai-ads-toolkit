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

Contact Form 7 and Elementor Forms are detected automatically. A lead is recorded
when the submission is accepted, never when the button is clicked, and the
browser and server halves share one event id so the conversion is counted once.

Neither plugin is required. Each can be switched off individually under
Settings -> OpenAI Ads.

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

Filters: `openai_ads_enabled`, `openai_ads_consent`, `openai_ads_event`,
`openai_ads_pixel_user`, `openai_ads_client_ip`.
Actions: `openai_ads_sent`, `openai_ads_failed`, `openai_ads_invalid_event`.

== Installation ==

1. Install and activate the plugin.
2. Go to Settings → OpenAI Ads.
3. Enter your Pixel ID and your Conversions API key.
4. Use "Send a test event" to confirm the credentials work. It uses the API's
   validation mode, so nothing is recorded and no fake conversion is created.

For a stronger setup, define the key in `wp-config.php` instead of the database:

`define( 'OPENAI_ADS_CAPI_KEY', 'your-key' );`

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
