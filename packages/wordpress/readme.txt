=== Conversion Tracking for OpenAI Ads ===
Contributors: webaround
Tags: openai, conversion tracking, analytics, pixel, conversions api
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.2.0
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
* Sends server-side conversions through the Conversions API, deduplicated against
  the browser half by a shared event id.
* Hashes email addresses, phone numbers and names with SHA-256 before they leave
  your server, using the normalization OpenAI documents.
* Strips query strings from the page address, so search terms and password-reset
  tokens do not reach an ad platform.
* Defers to your existing consent mechanism. It ships no cookie banner.
* Never makes the visitor wait: conversions go out after the page does, handed to
  Action Scheduler where the site has it.

= Integrations =

Contact Form 7, Elementor Forms, Gravity Forms, WPForms, Fluent Forms and Ninja
Forms are detected automatically. A lead is recorded when the submission is
accepted, never when the button is clicked. Contact Form 7 and Elementor also fire
the matching browser event, sharing one event id.

**WooCommerce** measures product views, add to cart, checkout start and paid
orders. A purchase is reported only once payment is confirmed - never for a
pending, failed or cancelled order - and never twice, however many times the
gateway or a webhook triggers it. Amounts use the currency's own minor unit.

**Easy Digital Downloads** reports a purchase once payment completes.
**WooCommerce Subscriptions** reports trial_started or subscription_created.
**WordPress registration** reports registration_completed, off by default because
user_register also fires for an administrator adding a colleague.

Each can be switched off under OpenAI Ads -> Integrations.

= Tag manager endpoint =

Optional, off by default. Lets Google Tag Manager hand a conversion to your site,
which forwards it to OpenAI - server-side measurement without a paid GTM server
container. Protected by a generated secret.

= For developers =

Record a conversion at a confirmed boundary:

`openai_ads_track( 'lead_created', [], [ 'event_id' => $lead_id, 'user' => [ 'email' => $email ] ] );`

Also `openai_ads_event_id()`, `openai_ads_pixel_event()`, `openai_ads_hash_user()`,
`openai_ads_image_tag()` and `openai_ads_is_configured()`, plus filters and actions
for consent, event data, identity and integrations. Every event OpenAI documents
for the web is supported, from page_viewed to order_created, plus custom.

Full documentation: https://github.com/webaroundlabs/openai-ads-toolkit

== External services ==

This plugin connects to OpenAI in order to measure advertising conversions. That
is its entire purpose, and nothing happens until you enter a Pixel ID or an API
key.

= Which services, and only which =

Three addresses, all of them OpenAI's:

* https://bzrcdn.openai.com/sdk/oaiq.min.js - the browser SDK, when the Pixel is on
* https://bzr.openai.com/v1/events - the Conversions API, from your server
* https://bzr.openai.com/v1/sdk/events - the image tag, only if you call it

There is no fourth. The plugin sends nothing to its author or to any analytics,
telemetry or licensing service.

= 1. The OpenAI Ads Measurement Pixel =

When the Pixel is on, the plugin loads https://bzrcdn.openai.com/sdk/oaiq.min.js
into your pages and initializes it with your public Pixel ID. That script is
written and hosted by OpenAI. It sets first-party cookies named __oppref and
__obref to remember which advertisement a visitor arrived from.

WHAT IS SENT: the event name, a deduplication id, the page address, the amount and
currency where the event carries one, and - only when your site supplies it -
identity as irreversible SHA-256 hashes. It runs in the visitor's browser, so their
IP address and user agent reach OpenAI as part of the request. A commerce event
also carries the basket: each item's SKU or product id, name, quantity, line price
and variation attributes. Product names are not hashed: hashing identifies a person.

WHEN: on any page view, once you have enabled the Pixel.

= 2. The OpenAI Ads Conversions API =

When server-side events are on, your server sends conversions to
https://bzr.openai.com/v1/events using the API key you provide.

WHAT IS SENT: the same event data as above, including the basket detail, plus the
visitor's IP address and user agent, the two attribution values read from the
__oppref and __obref cookies, and the page address the conversion happened on.
Email addresses, phone numbers, names and customer ids are hashed with SHA-256 on
your server before they leave it - the raw values are never transmitted.

WHEN: after a conversion your site confirms - a paid order, an accepted form
submission - and never on a page view.

= 3. The image tag =

Only if you call openai_ads_image_tag() yourself. It requests a 1x1 image from
https://bzr.openai.com/v1/sdk/events, carrying the event name, the deduplication
id and the amount - and no personal data of any kind, by design.

= Terms and privacy =

OpenAI's terms: https://openai.com/policies/
OpenAI's privacy policy: https://openai.com/policies/privacy-policy/
Advertising documentation: https://developers.openai.com/ads/

= Your responsibilities =

Sending a visitor's data to OpenAI is a disclosure to a third party. You are
responsible for saying so in your own privacy policy and for obtaining consent
where the law requires it. The plugin adds suggested wording under Tools ->
Privacy for you to adapt.

The plugin helps rather than decides. It detects a consent plugin - the WP Consent
API and Complianz are read directly - and when consent for the "marketing"
category is refused, no Pixel is loaded and no event is constructed. If it finds
no consent mechanism at all it measures everybody, and says so on its settings
screen. That default is deliberate: a plugin that silently measured nothing would
leave you hunting for the fault.

= What is NOT sent =

* No raw email address, phone number or name. Ever. Only SHA-256 hashes.
* No query strings from your page addresses by default.
* No data at all until you enter credentials, and none for a visitor who has
  refused marketing consent.

== Installation ==

1. Install and activate the plugin.
2. Go to OpenAI Ads in the admin menu and enter your Pixel ID and Conversions API
   key.
3. Open Integrations and read what the Consent section reports. If it found none,
   decide what you want before going further.
4. Use "Send a test event" on the General screen. It uses the API's validation
   mode, so no fake conversion is recorded.

Nothing requires editing a file. To keep credentials out of the database, define
them as constants in wp-config.php instead.

== Frequently Asked Questions ==

= What does deduplication actually do? =

A purchase measured in the browser and again on the server is one purchase, but
two reports. Both halves send one event id, so OpenAI counts one conversion.

= Do I need the Conversions API key, or is the Pixel enough? =

The Pixel alone works, and needs only a Pixel ID. It is also the half an ad
blocker or a failed script can remove. The API key adds the server-side half,
which nothing in the browser can block - and because the two are deduplicated,
adding it inflates nothing.

= I have no cookie banner. What happens? =

Everything is measured, and the settings screen tells you so in as many words.
That default is deliberate: the alternative is a plugin that silently measures
nothing while you hunt for the fault. Install a banner supporting the WP Consent
API and it is used automatically.

= Can I use this with Google Tag Manager? =

Yes, and without a paid server-side container. Switch on the collection endpoint
and point a tag at it; the API key never leaves your server.

= Does this slow down my site? Do I need WooCommerce? =

No, and no. Conversions are never sent while the visitor is waiting, and
WooCommerce is optional - where it is installed, the plugin reuses its queue.

= Will a measurement failure break my checkout? =

No. If reporting fails the plugin logs it and moves on. The order, form submission
or registration still completes.

= Is my API key exposed? =

No. It is used only server-side and is never printed into a page. The plugin's test
suite asserts this.

== Screenshots ==

1. General - the Pixel, the Conversions API, and a connection test.
2. Integrations and consent - what was found, and what is in use.
3. Tag manager endpoint - an address your tag manager can post to.

== Changelog ==

= 0.2.0 =
* Translated into Bulgarian, Dutch, French, German, Greek, Hungarian, Polish,
  Portuguese, Romanian and Spanish.
* Suggested privacy policy wording now appears under Tools -> Privacy.
* Corrected this readme: with no consent mechanism installed the plugin measures
  everybody and says so on its settings screen. A section claimed otherwise.

= 0.1.2 =
* The settings moved into their own menu: General, Integrations, Tag manager.
* Fixed: saving one settings screen could blank the settings on another, including
  the Pixel ID and the API key.
* The plugin's mark now appears in the admin menu.

= 0.1.1 =
* No change to this plugin.

= 0.1.0 =
* First release: Pixel, Conversions API, deduplication, settings screen, a
  connection test, and the first three integrations.
