# Contributing

Thanks for looking. This is a small project with strong opinions, and most of
them are written down — which should make it easier to contribute, not harder.

## The short version

- **[`CLAUDE.md`](CLAUDE.md) is the coding charter.** Proportional engineering,
  no speculative abstraction, and no implementing behaviour OpenAI has not
  documented. Read it before a first pull request; it explains most review
  comments in advance.
- **Never invent API behaviour.** If the documentation does not describe a
  response shape, a status code or a retry rule, this toolkit does not model
  one. Speculative handling is dead code that hides real bugs.
- **A conversion must never be counted twice.** When a change could affect that,
  say so in the pull request.
- **Measurement must never break the host application.** A failed report is
  acceptable; a failed checkout is not.

## Running everything

```bash
python packages/spec/verify.py        # the specification and the GTM templates
python scripts/check-secrets.py       # credential exposure

cd packages/php       && composer install && composer test
cd packages/js        && npm install      && npm test && npm run typecheck
cd packages/laravel   && composer install && composer test
cd packages/wordpress && composer install && composer test
```

The Laravel and WordPress packages reach the core through a Composer path
repository, so a full checkout is all the wiring they need. Their test suites
also read `packages/spec` by relative path, which means they run from a monorepo
checkout only — never from an installed package. That is intended.

## When OpenAI changes something

The specification is the source of truth, and it lives in three places on
purpose — the JSON, the PHP, the TypeScript. The parity tests are what make that
duplication safe.

1. Re-read the upstream documentation and update `retrieved` and `sources`.
2. Change `packages/spec/events.json` or `user.json`.
3. Run both test suites. The parity tests go red in each runtime.
4. Follow them, add a fixture, and add a changelog entry.

The failure mode is a red build, never silent divergence.

## Changes that need extra care

A change to identity normalization, hashing or event id generation is a
**behavioural change to deduplication**, even when no type signature moves. It
needs a test pinning the exact output and a changelog entry. `user.json` and
`packages/spec/fixtures/normalization.cases.json` are deliberately separate files
so that a diff touching them is visible as dangerous from the filename alone.

The same goes for anything that could put a credential where a browser can read
it. CI checks for that, but a reviewer will look too.

## Tests

A test that cannot fail proves nothing. Several of the security-critical
assertions in this repository were verified by deliberately breaking the code and
watching the test go red — the API key rendered into a page, `strtolower`
substituted for `mb_strtolower`, the WooCommerce idempotency guard removed. If
you add an assertion that matters, consider doing the same and mentioning it in
the pull request.

Prefer testing observable output over internals. The normalization tests assert
what `toCapiArray()` produces, not what a private helper returns, because the
former is the thing that has to be right.

## Adding an integration

Implement `Integrations\Integration` in the WordPress plugin, or register your
own through the `openai_ads_integrations` filter. Two rules:

- Fire on a boundary the host **confirms** — after the submission is accepted,
  after payment completes. Never a button click.
- Use one event id for the server event and the browser event.

## Pull requests

Small and reviewable beats comprehensive. Explain *why* in the description; the
code already says what. If you disagree with something in the charter, say so —
it has been wrong before and the reasoning is more useful than the rule.

By contributing you agree your work is licensed under the [MIT License](LICENSE),
except the WordPress plugin, which is GPL-2.0-or-later as the plugin directory
requires.
