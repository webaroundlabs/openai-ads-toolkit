# @webaround/openai-ads

The OpenAI Ads Measurement Pixel for the browser: a typed, consent-aware wrapper
over the official `oaiq` SDK, built to deduplicate cleanly against the
Conversions API.

> **Pre-alpha, `0.1.x`.** The public API is unstable until `1.0`.

## Install

```bash
npm install @webaround/openai-ads
```

## What this is, and is not

It **wraps** OpenAI's official browser SDK. It does not reimplement it. OpenAI
publishes no npm package to depend on — their SDK is a script on OpenAI's CDN
plus a global command queue — so this package injects the documented loader and
speaks to that queue.

Everything the SDK owns stays with the SDK: batching, event timestamps,
`source_url`, and capturing `oppref` into the `__oppref` cookie. Passing any of
those by hand would corrupt them.

Zero runtime dependencies. ESM, tree-shakeable, `sideEffects: false`.

## Usage

```ts
import { OpenAIAds } from '@webaround/openai-ads';

OpenAIAds.init({ pixelId: 'YOUR-PIXEL-ID' });

OpenAIAds.track('lead_created', undefined, { eventId: leadId });
```

Amounts are integers in the currency's minor unit:

```ts
OpenAIAds.track('order_created', { amount: 2599, currency: 'EUR' }, { eventId: order.id });
```

Pass `25.99` and you get a developer-friendly rejection — *"amount must be an
integer in the currency's minor unit; got 25.99. Did you mean 2599?"* — rather
than a silently wrong conversion value.

The data shape is supplied for you and is type-checked per event. `lead_created`
is a `customer_action`, which accepts no `contents` array, and TypeScript will say so.

### Identity

Hashing happens in the browser via Web Crypto, which is async and requires a
secure context. Raw values are never sent; if Web Crypto is unavailable, hashing
fails loudly instead of degrading.

```ts
import { OpenAIAds, hashUser } from '@webaround/openai-ads';

OpenAIAds.init({ pixelId: 'YOUR-PIXEL-ID' });

// Later, once the visitor is known — after login, checkout or a lead submission:
OpenAIAds.init({ user: await hashUser({ email: user.email, country: 'RO' }) });
```

Identity goes on `init`, not on each `track` call. `hashUser` produces the
Pixel's singular-key shape (`email_sha256`), which differs from the Conversions
API's plural arrays (`emails_sha256`) — the digests are identical, the shapes are
not.

### Consent

```ts
OpenAIAds.consent(false);   // before init
```

Denied consent means the SDK is never loaded and `track()` becomes a **silent
no-op** — a refused consent is a normal outcome, not an error.

`optOut` is a different thing. It excludes an event from personalization while
still sending it. Do not wire a consent banner to `optOut`: that still transmits
identity hashes.

### Deduplication

The key is Pixel ID + event name + event id. The browser event and its
server-side twin must share all three.

Prefer an id the server already owns, and prefer to learn it *from* the server:

1. the server completes the business action and knows the record's id;
2. it returns that id (AJAX) or renders it into the confirmation page;
3. it sends the Conversions API event with that id;
4. the browser fires the Pixel event with the same id.

`createEventId()` is the fallback for flows where no such id exists when the
browser must act — mint it once, send it with the request, and never regenerate
it. A retry that re-mints is a second conversion. It uses Web Crypto and throws
rather than falling back to `Math.random()`, because a collision silently merges
two people's conversions.

### Multiple pixels

`measure` broadcasts to **every** pixel initialized at the time of the call —
that is the SDK's documented behaviour, preserved here. On a multi-pixel page
that usually double-counts, so target one explicitly:

```ts
OpenAIAds.init({ pixelId: 'px-a' });
OpenAIAds.init({ pixelId: 'px-b' });

OpenAIAds.track('order_created', data, { pixelId: 'px-b', eventId: order.id });
```

### Errors never reach your code

`track()` and `init()` do not throw. A measurement mistake on a checkout page
must not take the checkout with it, so failures are routed to a handler:

```ts
OpenAIAds.configure({ onError: (error) => Sentry.captureException(error) });
```

This is the opposite of the PHP core, which throws — there, the caller is server
code that decides what to do, and swallowing a developer error would hide it.

## Never put the CAPI key here

The Conversions API key is server-side only. Nothing in this package accepts one.
It must never appear in JavaScript, a public environment variable, HTML, or a
browser bundle.

## Development

```bash
npm install
npm test
npm run typecheck
npm run build
npx vitest run tests/userData.test.ts    # a single file
```

`tests/spec-parity.test.ts` asserts this package against `packages/spec` — the
event catalogue, the data shapes, Pixel support, and the Pixel identity key set.
The normalization tests read
`packages/spec/fixtures/normalization.cases.json` directly, which is the same
file the PHP suite asserts against: that is what guarantees a browser event and a
server event describe the same person.

Those tests read the spec by relative path, so they run from a monorepo checkout
only.

## License

[MIT](LICENSE). Independent community project, not affiliated with OpenAI.
