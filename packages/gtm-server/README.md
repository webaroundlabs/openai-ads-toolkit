# OpenAI Ads Conversions API — GTM Server template

A Google Tag Manager **server** container template that sends conversions to the
OpenAI Ads Conversions API.

> **Pre-alpha.** Not submitted to the Community Template Gallery. Written against
> the current documentation but **not yet run in a live GTM container** — see
> [Status](#status).

Independent community integration. Not created, certified, endorsed or supported
by OpenAI.

## Installing

1. In your **server** container: **Templates → Tag Templates → New**.
2. From the ⋮ menu, choose **Import**, and select `template.tpl`.
3. Save, then add the tag and point it at a client-forwarded event.

Store the API key in a container variable rather than typing it into the tag.

## Identity is hashed here, and matches the rest of the toolkit

Raw values go in; digests go out. The normalization is the same one pinned in
[`packages/spec/user.json`](../spec/user.json) and asserted by the PHP and
JavaScript test suites:

| Field | Rule |
|---|---|
| email | trim, lowercase |
| phone | digits only, then leading zeroes removed; 8–15 digits or it is skipped |
| external id | trim, **case preserved** |
| first / last name | lowercase, then whitespace and ASCII punctuation removed, non-ASCII preserved |

That matters more than it looks. A conversion reported through this container and
the same conversion reported by the WordPress plugin must produce **identical**
digests, or OpenAI sees two different people and the deduplication never happens.
The GTM sandbox has no regular expressions, so the phone and name rules are
written as explicit character loops — which is also the clearest statement of the
rule.

A phone that does not normalize to 8–15 digits is skipped with a log line that
deliberately does not contain the number.

## `oppref` and `obref` are different fields

| Field | Where it goes | Cookie |
|---|---|---|
| `oppref` | on the event | `__oppref` |
| `obref` | inside the `user` object | `__obref` |

Both are opaque: passed through byte for byte, never parsed, decoded or invented.
Leave either blank and the template reads the cookie from the incoming request.

## Deduplication

The Event ID is required here, and it must be the same value the browser Pixel
sends for the same conversion, with the same Pixel ID and event name.

## Shape rules the template enforces

- `contents` is dropped, with a log line, on `lead_created`,
  `registration_completed` and `appointment_scheduled` — those use the
  `customer_action` shape, which has no contents array. Sending one fails the
  request for a reason the response cannot explain.
- `plan_id` is dropped on any shape that does not carry one.
- `source_url` is required when `action_source` is `web`.
- `app_installed` and `app_opened` require `action_source` `mobile_app`.
- An amount without a currency is refused. Amounts are integers in the minor
  unit — 1299, not 12.99.

## Testing a container honestly

Tick **Validation mode**. It sends `validate_only: true`, so the event is checked
against the real API and never recorded — no invented conversion.

## No retries

One event per fire, no retry. A completed request with a non-2xx status marks the
tag as failed and logs the status, but does not interpret it: OpenAI documents no
status taxonomy, and repeating a request whose outcome is unknown risks counting
a conversion twice. Silently inflated conversion data is worse than a missing
event, because it corrupts optimization invisibly and cannot be undone.

## Status

The `___TESTS___` section covers the posted payload, a missing source URL, an app
event on the wrong action source, a missing currency, hashing rather than sending
a raw email, validation mode, and dropping contents on the wrong shape. **Those
run inside GTM's template editor, not here** — press *Run Tests* after importing.

What *is* verified in this repository, by `python packages/spec/verify.py`: that
the event dropdown matches `packages/spec/events.json` exactly — all thirteen
events, including the two the browser cannot send — and that the container
context is `SERVER`.

The email hashing test pins the same digest the PHP and JavaScript suites assert
for `ada@example.com`, so if the sandbox's `sha256Sync` ever disagreed with them,
that test would say so.

## License

[Apache 2.0](LICENSE) © 2026 Webaround Labs — not the MIT the rest of the toolkit
uses. The Community Template Gallery requires a repository whose `LICENSE` is the
Apache 2.0 text and nothing else, and this package is published there.
