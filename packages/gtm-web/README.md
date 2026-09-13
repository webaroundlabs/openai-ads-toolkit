# OpenAI Ads Measurement Pixel — GTM Web template

A Google Tag Manager **web** container template that loads the official OpenAI
Ads Pixel and reports conversions.

> **Pre-alpha.** Not submitted to the Community Template Gallery. Written against
> the current documentation but **not yet run in a live GTM container** — see
> [Status](#status).

Independent community integration. Not created, certified, endorsed or supported
by OpenAI.

## Installing

1. In your web container: **Templates → Tag Templates → New**.
2. From the ⋮ menu, choose **Import**, and select `template.tpl`.
3. Save. The tag then appears under **Tag Configuration → Custom**.

## Two tags, not one

**Initialize Pixel** — fires early on every page, once per Pixel ID. This injects
OpenAI's SDK and calls `init`. Identity, if you have any, goes here — the SDK
takes user data on `init`, not on each event.

**Track event** — one tag per conversion, on whatever trigger marks it as
*confirmed*. After payment, after the lead is accepted; not on a button click.

## The Event ID is the whole point

Set it. Without it the browser event cannot be matched to the server-side one and
the conversion may be counted twice. It must be the **same value** the Conversions
API sends, alongside the same Pixel ID and event name.

Prefer an id the site already owns — an order number, a lead id — pushed into the
data layer by the server.

## Two things this template does deliberately

**It routes with `measureSingle`, not `measure`.** The SDK's `measure` command
broadcasts to *every* Pixel ID initialized at the time of the call. In a container
running two pixels that double-counts, so setting a Pixel ID on the event tag
sends it to exactly that one.

**It refuses `app_installed` and `app_opened`.** They are Conversions API only.
The browser SDK would accept and silently discard them, so they are absent from
the dropdown rather than offered and lost.

## What the SDK supplies itself

Do not try to set these. The SDK adds `source_url`, timestamps each event,
batches closely grouped calls, and captures `oppref` into the `__oppref` cookie.
Passing them by hand corrupts them.

## Identity must already be hashed

The user fields take SHA‑256 digests, lowercase hex. **Never put a raw email
address or phone number in a web container** — it is readable by anyone who can
view the page. Hash server-side, or use the toolkit's
[JavaScript package](../js), whose `hashUser()` produces exactly this shape with
the normalization the rest of the toolkit uses.

## Never put the API key here

The Conversions API key belongs in a **server** container only. This template has
no field for one, and `packages/spec/verify.py` fails the build if the file ever
starts referencing a credential.

## Consent

The template has no consent logic, on purpose. Gate the tag with your consent
mechanism — GTM's built-in consent settings, or a trigger condition. `opt_out` is
a different, weaker thing: it excludes an event from personalization while still
sending it, so do not wire a consent banner to it.

## Status

The `___TESTS___` section covers initialization, single-pixel routing, the
major-unit amount rejection, missing currency, an unnamed custom event, and the
refusal of a Conversions API only event. **Those tests run inside GTM's template
editor, not here** — press *Run Tests* after importing.

What *is* verified in this repository, by `python packages/spec/verify.py`: that
the event dropdown matches `packages/spec/events.json` exactly, that the two
Conversions API only events are absent, that the container context is `WEB`, and
that the file references no credential. Mutation-tested — adding `app_installed`
to the dropdown fails the check.

## License

[Apache 2.0](LICENSE) © 2026 Webaround — not the MIT the rest of the toolkit
uses. The Community Template Gallery requires a repository whose `LICENSE` is the
Apache 2.0 text and nothing else, and this package is published there.
