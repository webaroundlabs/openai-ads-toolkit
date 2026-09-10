# PHP public API — design proposal

**Status:** implemented in `packages/php`, except the Conversions API client (§5), which is next.
**Scope:** the event model and the Conversions API serializer.

This document exists because the public surface of a library is expensive to change once
people depend on it. It records not just what was built but why, and what was deliberately
left out; after `0.2.0` most of these decisions cost a major version to revisit.

Three of the decisions below deviate from the original brief. Each says so and gives the
reasoning.

---

## 1. `toArray()` becomes `toCapiArray()`

**Deviation from the brief.** The brief's milestone is `$event->toArray()`.

An `Event` has *two* wire formats, and they are not cosmetically different: the Conversions API
takes `emails_sha256: ["…"]` while the Pixel takes `email_sha256: "…"`, and the Pixel must not
receive `source_url`, `timestamp_ms` or `oppref` at all because the SDK supplies them itself.

A method called `toArray()` on an object with two serializations is a loaded gun pointed at
Phase 2. Whoever writes the WordPress pixel snippet will call it, receive a CAPI-shaped payload,
and ship broken identity matching. Nothing will report the error — the Pixel does not tell you
it ignored a field — so it surfaces months later as unexplained match-rate loss.

The name must be right in the first commit. Renaming a public method later is a major version
bump. This is *not* a request for a separate serializer class: there is one destination in
Phase 1 and direct serialization is correct. It is a request that the name not lie about which
destination.

Phase 2 adds `toPixelPayload()` alongside it.

## 2. `Event::leadCreated()` becomes `Event::create()` with named arguments

**Deviation from the brief.** The brief proposes a static named constructor per event.

Three problems:

- **It does not scale.** Thirteen events across four data shapes means thirteen methods
  differing by two literals.
- **It cannot hold an injected clock without a global.** If `leadCreated()` defaults
  `timestamp_ms` from `time()`, it breaks the charter's "time and randomness must be injectable"
  rule on day one — and that rule exists precisely because timestamps and event IDs *are* the
  deduplication mechanism. If it does not default the timestamp, the sugar saves nothing.
- **The risk is asymmetric.** Adding a named constructor later is a minor version bump.
  Shipping thirteen and regretting the signature is a major one.

```php
$event = Event::create(
    name:         EventName::LeadCreated,
    id:           EventId::fromBusinessId('lead_88213'),
    timestampMs:  $clock->nowMs(),
    actionSource: ActionSource::Web,
    sourceUrl:    'https://example.com/contact/thank-you',
    user:         UserData::create(email: $submission->email),
);
```

`$clock->nowMs()` at the call site is one extra token, no hidden global, and trivially testable.
`timestamp_ms` must be stamped at *collection* time, not send time, so that a retried queue job
carries the original value — making the clock explicit is what enforces that.

Ten parameters is not a defect here. Five are required by the wire contract, five are optional
and defaulted, and the parameter names mirror the documented request body one-for-one — which is
what the charter's naming rule asks for. A builder would add a mutable intermediate object and
several methods to buy nothing.

`create()` validates once, at this boundary, each failure naming the field and the event id:

- the action source is permitted for the event (catches `app_installed` on `web`);
- `sourceUrl` is present and has a scheme and host when the action source is `web`;
- `customEventName` is present if and only if the event is `custom`, matches the pattern, and
  does not collide with a standard event name;
- `timestampMs` is positive.

The **freshness window is deliberately not checked here** — see §5.

Note what `create()` does not accept: `contents`, `planId`. `lead_created` uses the
`customer_action` shape, which permits neither. In Phase 1 that rule is enforced by *the
parameter not existing*, which is a better error than any runtime check. They arrive in Phase 2
as additional optional parameters — an additive, minor change.

## 3. `TransportInterface` and `HttpTransport` are not built

**Deviation from the brief.** The brief specifies a `TransportInterface` with a `send(Batch): Response`.

PSR-18's `ClientInterface` already *is* that interface, and it already has dozens of
implementations and test doubles. Defining our own means an interface with one implementation
that wraps another interface — the charter's named anti-pattern — and the charter separately
says to depend on PSR-18 so the core does not force a transport on integrators.

The "we will need it later" argument also fails on inspection: the WordPress adapter's
`wp_remote_post` shim will implement **PSR-18**, not a bespoke interface, because that is what
lets it also serve any other PSR-18 consumer.

So the core's entire runtime dependency set is `psr/http-client`, `psr/http-factory`,
`ext-json`, `ext-mbstring`. Ergonomics are the adapters' job: Laravel binds the implementations
in the container, WordPress ships the shim.

---

## 4. Types for this slice

```php
enum EventName: string { /* 13 cases; dataShape() and allowedActionSources() mirror events.json */ }
enum DataShape: string { case Contents; case CustomerAction; case PlanEnrollment; case Custom; }
enum ActionSource: string { /* the 7 documented values */ }

final class Event    { public static function create(...): self; public function toCapiArray(): array; }
final class EventId  { public static function fromBusinessId(string $id): self; }
final class Money    { public static function minor(int $minorUnits, string $currency): self; }
final class UserData { public static function create(...): self; public function toCapiArray(): array; }

interface Clock      { public function nowMs(): int; }
final class SystemClock implements Clock {}

namespace Capi;
final class Client   { public function send(array $events, bool $validateOnly = false): Response; }
final class Response { public readonly int $statusCode; public readonly array $headers;
                       public readonly string $body; public function isSuccessful(): bool; }
```

Plus three exception types: a marker `interface Exception`, `InvalidArgument`, and
`Capi\TransportException`.

Namespace `WebaroundLabs\OpenAIAds\`. Composer package `webaroundlabs/openai-ads`.

### `Money` — one class, one use, deliberately

This breaks the "extract on the second use" rule, on the charter's own grounds that a check
which can only fail in a structurally impossible state should not exist. `Money` makes
"amount without currency" **unconstructible**, deleting the spec's most bug-prone conditional
from PHP entirely.

It earns its keep a second way: under `strict_types=1`, `Money::minor(19.99, 'USD')` is a
`TypeError`. That is exactly right — `19.99` is not minor units, and passing major units is the
single most common mistake made against every conversions API in existence.

### `UserData` — raw in, hashed at the boundary

```php
UserData::create(
    email: ' Ada@Example.COM ',      // normalized and hashed here, once
    obref: $_COOKIE['__obref'] ?? null,  // opaque, never hashed
);
```

Normalization and hashing live in private static methods. **No `Hasher` or `Normalizer` class
in Phase 1** — there is exactly one consumer. The second consumer is scheduled rather than
speculative: it arrives in Phase 2 with the Pixel serializer, and that is when to extract.

Tests assert the observable output —
`UserData::create(email: ' Ada@Example.COM ')->toCapiArray()['emails_sha256'][0]` — against
`packages/spec/fixtures/normalization.cases.json`, which is a stronger assertion than one on an
internal helper.

Lowercasing uses `mb_strtolower($v, 'UTF-8')`. `strtolower()` is byte-wise and leaves
`Ștefănescu` untouched where JavaScript's `toLowerCase()` does not — two different hashes for
the same person, silently unmatched. The fixture pins both the correct digest and the wrong one.

Raw identifiers never appear in exception messages: a bad phone throws
`"phone must contain 8-15 digits after normalization"`, never the number.

### `EventId` — `fromBusinessId()` only

No generator method, in Phase 1 or Phase 2. Where the event ID is minted is the biggest open
architectural question in the project: if PHP mints it, the browser can only learn it by the
server rendering it into the page; if the browser mints it, the server can only learn it via a
form field or beacon. That decision belongs to the Pixel phase, made deliberately.

Shipping only `fromBusinessId()` is the hedge — it matches the documented recommendation to
reuse a stable order/lead/payment id, and it freezes nothing.

---

## 5. Consequences of an undocumented response contract

The success body, status codes, error shape, rate limits and idempotency semantics are
documented nowhere. The charter says both "validate the response shape at the boundary" and
"implement only what the API documents", and here those collide: you cannot validate a shape
nobody has specified. **The only contract we hold is HTTP itself.**

`Response` therefore exposes `statusCode`, `headers`, raw `body`, and `isSuccessful()` (2xx —
an RFC-level guarantee, not an OpenAI-level one). It must **not** grow `errors()`,
`failedEvents()`, `requestId()`, `isRateLimited()` or `retryAfter()`. Per-event results are not
merely undocumented but impossible to model: the docs say the whole batch fails together, so
there is no per-event outcome to expose.

`decodedBody(): ?array` is also omitted, tempting as it is. The moment it exists someone writes
`$response->decodedBody()['id']` and we have implied a contract we cannot back. Hand back a
string; if OpenAI documents the body later, adding an accessor is a minor bump.

**Non-2xx does not throw.** Turning status codes into exception types requires inventing the
taxonomy we are forbidden from inventing — is 202 success? is 207 partial? So `send()` returns a
`Response` for any completed round trip and throws `TransportException` only when there was no
round trip. This runs against SDK convention and belongs in the first paragraph of the README's
error-handling section.

### The freshness window is checked in `send()`, not `create()`

The window is relative to *now at send time*. An event constructed validly can go stale in a
queue. Because one bad event fails the entire batch, a single stale event would discard up to
999 good ones and return an error we cannot interpret — so checking locally converts that into a
clear failure naming the offending event id. This is not re-validation past a boundary: staleness
is new information, not a re-check.

### `RetryPolicyInterface` is not built

**Deviation from the brief**, and the one with the highest stakes.

1. It would be an interface with one implementation and no test double.
2. A retry policy needs three inputs we do not have: which statuses are retryable, whether a
   `Retry-After` header exists and under what name, and whether repeating a request is safe.
3. **Idempotency is the killer.** Retrying a request that actually succeeded but whose response
   was lost double-counts conversions — unless CAPI deduplicates on `events[].id`. The documented
   dedup key (pixel id + event name + event id) strongly implies it but never states it. Shipping
   retries on that inference risks silently inflated conversion data: invisible, corrupting to the
   advertiser's ad optimization, and unrecoverable after the fact.
4. Retry belongs to the layer that owns durability anyway. A Laravel queued job has `$tries` and
   `backoff()`; Action Scheduler reschedules. A retry loop inside `send()` would give those
   integrators *multiplicative* retries.

What Phase 1 does instead, at zero cost: `EventId` is caller-supplied and never regenerated, so a
retry is provably the same event; `send()` is free of side effects, so calling it twice with the
same `Event` objects is meaningful; and the README states that a retry must reuse the same
`Event` instances and never re-stamp the timestamp. Integrators configure retries in the PSR-18
client they already injected.

---

## 6. The first milestone

```php
$event->toCapiArray() === [
    'id'            => 'lead_88213',
    'type'          => 'lead_created',
    'timestamp_ms'  => 1789041600000,
    'action_source' => 'web',
    'source_url'    => 'https://example.com/contact/thank-you',
    'data'          => ['type' => 'customer_action'],
    'user'          => [
        'emails_sha256' => ['b5fc85e55755f9e0d030a10ab4429b6b2944855f9a0d60077fe832becbc41d72'],
    ],
];
```

Pinned in `packages/spec/fixtures/lead_created.minimal.capi.json`. The digest is the real
SHA-256 of `ada@example.com`, generated independently in Python and PHP.

**Absent fields are omitted, never emitted as `null`.** There is no `amount`, `currency`,
`oppref`, `opt_out` or `custom_event_name` key above. Omitted and null are a real wire
distinction and we cannot know how the API treats an explicit null. This deserves its own test.

---

## 7. Not built yet, and what would justify each

| Class | Trigger |
|---|---|
| `Configuration` | Phase 2, when the Laravel config array *and* the WordPress options screen both map onto it — that is the second use. |
| `EventFactory` | Its only real job is holding the clock, which the explicit call site does better. |
| `Content` | The `contents` data-shape slice. |
| `Attribution` | Never as designed: `oppref` and `obref` live on two different wire objects and a class holding both must be torn apart at serialization. Two `?string` properties. |
| `Hasher`, `Normalizer` | Phase 2, when the Pixel serializer becomes the second consumer. |
| `Validator` | Never. Validation belongs in the constructors of objects that would otherwise be invalid; a separate validator re-reads raw input after validation. |
| `Batch` | A batch is `list<Event>` plus state the client already holds. The min/max rules are three lines in `send()`. |
| `TransportInterface`, `HttpTransport` | Never — PSR-18 is the interface. See §3. |
| `RetryPolicyInterface` | The adapter layer, which owns durability. See §5. |
| Richer exceptions | We cannot know which status codes mean rate-limited or unauthenticated. |

## 8. Versioning

First tag is **`0.1.0`**, with an "unstable until 1.0" banner in the README. A one-event slice
cannot honour semantic versioning: every Phase 2 addition would otherwise be a breaking change
or an awkward workaround. 1.0 waits until all 13 events and both serializers exist. That is what
buys the freedom to defer everything in §7.

## 9. Questions resolved in implementation

1. **`Money` breaking the second-use rule — kept.** It makes "amount without
   currency" unconstructible and turns `Money::minor(12.99, 'EUR')` into a
   `TypeError` at the call site under `strict_types`. Both are pinned by tests.
2. **`UserData` multi-value input — deferred, with the escape hatch chosen.** When
   a caller needs several emails (the API reads the first three unique values per
   list field), it arrives as a *second named constructor*, not by widening
   `?string` to `string|list<string>`, which would be a signature change. The CAPI
   serializer already emits lists, so that change is additive.
3. **Geographic values are lenient, identity values are strict.** A blank city is
   treated as absent; a blank email throws. Hashing an empty string yields a
   valid-looking digest that matches nobody, so silently sending one would degrade
   matching with no signal — whereas throwing on a half-filled checkout address
   would break conversions for no benefit.
4. **`source_url` is validated, not rewritten.** The core requires a scheme and a
   host and restricts the scheme to http/https (stated toolkit policy, not an API
   rule). It does **not** strip query strings or fragments: that is privacy policy
   belonging to the adapters, which know the site's canonical origin, and doing it
   silently in the core would surprise callers.

## Still open, and blocking nothing today

These concern `Capi\Client`, which does not exist yet:

- **Non-2xx returning a `Response` rather than throwing.** Unconventional; needs a
  decision before the client ships, and belongs in the README's first
  error-handling paragraph either way.
- **What adapters do with an event that has gone stale in a queue** — drop it with
  a log, or fail the job. The freshness check itself is settled: it belongs in
  `send()`, immediately before transmission, because the window is relative to
  send time and one stale event fails an entire 1,000-event batch.
