# Security policy

## Reporting a vulnerability

**Please do not open a public issue.**

Use GitHub's private vulnerability reporting:
[**Report a vulnerability**](https://github.com/webaroundlabs/openai-ads-toolkit/security/advisories/new).
It is private between you and the maintainers, and it lets us prepare a fix
before anything is disclosed.

You should get an acknowledgement within a few days. If a report is confirmed we
will agree a disclosure timeline with you and credit you in the advisory unless
you would rather we did not.

## What this project treats as a vulnerability

This is a measurement toolkit, so the interesting failures are not the usual
ones. All of these are in scope:

- **Anything that could expose the Conversions API key** to a browser, a page, a
  compiled bundle, a log, an exception message, or a public Tag Manager
  container. This is the one mistake that cannot be walked back after a release.
- **Anything that leaks raw personal data.** Email addresses, phone numbers and
  names are hashed at the boundary; a path that sends, logs or renders a raw
  value is a vulnerability, not a bug.
- **Anything that lets an attacker inject data into someone's measurement** — a
  spoofed origin reaching `source_url`, an attacker-controlled value reaching an
  event, or a way to fire conversions on another site's Pixel ID.
- Cross-site scripting in the WordPress admin screens or in rendered Pixel output.
- Missing capability or nonce checks in the WordPress plugin.
- Anything that makes a measurement failure break a checkout, a form submission
  or a registration. A conversion that fails to report is acceptable; an order
  that fails to complete is not.

Out of scope: the fact that the Pixel ID is public — it is meant to be, it
appears in every page by design.

## Supported versions

While the project is `0.x`, only the latest release receives fixes. Once `1.0`
ships, this section will state a support window.

## For implementers

A few properties this toolkit maintains, which are worth preserving in anything
built on it:

- The Conversions API key is server-side only. There is no code path that gives
  it to a browser, and CI fails if one appears — see
  [`scripts/check-secrets.py`](scripts/check-secrets.py).
- Raw identifiers never appear in exception messages or logs. A rejected phone
  number reports the digit count and the rule, never the number.
- Query strings are stripped from `source_url` before sending, because they
  routinely carry email addresses, reset tokens and order keys.
- `opt_out` is a personalization flag, not a consent gate. Wiring a consent
  banner to it still transmits identity hashes.

You remain responsible for complying with applicable privacy law and your own
consent policy. This toolkit deliberately ships no privacy model of its own.
