# GTM: send to your own server

A Google Tag Manager **web** container tag that posts a conversion to the
collection endpoint on your own site. Your site forwards it to the OpenAI Ads
Conversions API.

## Why you would want this

Server-side tagging in GTM normally needs a **server container**, and Google
hosts those on Google Cloud — a real monthly bill for a site that already has a
perfectly good server. This tag skips it. The browser posts to your site; your
site talks to OpenAI.

What you get:

- **The Conversions API key stays on your server.** It is never in a container,
  never in page source, never recoverable by a visitor.
- **The request to OpenAI leaves from your server**, so no ad blocker sees it.
- **No extra hosting.**

What you do not get, so the trade is an honest one:

- The first hop is still a browser request, and an aggressive blocker can stop it
  exactly as it stops the Pixel. You have moved the fragile hop, not removed it.
- If your site is down, the event is gone. A real server container retries.
- A server container runs on a subdomain of yours, which survives more
  third-party-cookie restrictions than this does.

For most sites the trade is worth it. For a site spending enough that a few
percent of lost conversions costs more than the hosting, it is not.

## Setup

1. **WordPress → Settings → OpenAI Ads → Tag manager endpoint.** Switch it on and
   save. Copy the URL and the secret that appear.
2. In GTM, create a **Constant** variable holding the secret, and reference that
   from the tag. Rotating the secret is then one edit rather than several.
3. Import this template as a **custom tag template** in your **web** container.
4. Set **Event ID** to something the site already owns — an order number, a lead
   id — and use the **same value** in the Pixel tag for that conversion. That is
   what makes OpenAI count one conversion instead of two.

## The secret is not the API key

It authorizes posting to your site and nothing else. Someone who steals it can
record conversions that never happened — which corrupts the figures your
campaigns are optimized against, so treat it seriously — but they cannot read
anything and they cannot spend your budget directly.

Rotate it from the same settings screen. Anything still using the old one stops
working immediately, which is the point.

## What the tag deliberately does not send

`source_url` **is** sent, because only the page knows its own path.

The client IP, the user agent and the `__oppref` / `__obref` cookies are **not**.
The request goes to your own origin from the visitor's own browser, so your
server observes all four correctly for itself. A value the server observed beats
a value assembled in a page.

Identity is sent **raw**, over HTTPS, to your own server, which normalizes and
hashes it there. This tag hashes nothing. Those rules already live in four places
that must agree byte for byte, and a fifth copy written in sandboxed JavaScript
is how they stop agreeing.

## Verifying it works

Turn on **Debug logging** in the plugin settings. A table of the last 20 events
received appears under the endpoint settings: when, which event, accepted or
rejected, and why. It never shows the payload — that carries raw email addresses,
and an options row is readable by anyone who can read options.

Common responses:

| Status | Meaning |
|---|---|
| `202` | Accepted and queued. It leaves after the response. |
| `400` | The event was malformed. The body says which field. |
| `401` | Wrong or missing secret. |
| `429` | Rate limited — 120 events per minute per address by default. |

---

Independent community integration. Not created, certified, endorsed or supported
by OpenAI.
