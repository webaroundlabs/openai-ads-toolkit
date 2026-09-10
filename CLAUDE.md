# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`openai-ads-toolkit` — an open-source toolkit for OpenAI Ads measurement: **Pixel, Conversions API, deduplication**, plus adapters for **PHP, JavaScript, Laravel, WordPress, and GTM**.

Two properties follow from this and shape every decision below:

1. **It is a library, not an application.** Its public API is a contract other people depend on. A signature change is a breaking change; an internal refactor is free. Keep that line sharp and deliberate.
2. **It is multi-target.** The same measurement semantics — event shape, identity normalization, deduplication key — must behave identically whether they run in a browser, in PHP on a server, inside WordPress, or in a GTM template. That shared semantics belongs in **one** place per language runtime; adapters translate host conventions into it and add nothing of their own.

### Repository status

Phase 1 only. `packages/spec` holds the canonical event specification, the identity mapping and the golden fixtures; `docs/api-design.md` proposes the PHP public API. **No PHP or JavaScript package exists yet**, so there is no toolchain and no build, lint or test command to document.

**When the toolchain lands, record the real commands here** — per package: install, build, lint, full test run, and how to run a *single* test — rather than leaving future sessions to infer them.

Verifying the spec today:

```bash
python -c "import json,glob; [json.load(open(f,encoding='utf-8')) for f in glob.glob('packages/spec/**/*.json',recursive=True)]"
```

Next step is the `lead_created` vertical slice in `packages/php` — see `docs/api-design.md`, whose §7 lists what must *not* be built yet and what would justify each.

## Code Quality Guidelines

The goal is **proportional engineering**: code that is as simple as the problem allows, no simpler. Most violations come from premature abstraction, defensive paranoia, or pattern-matching from unrelated contexts.

When a guideline conflicts with an explicit request, ask before deviating.

### 1. Avoid premature abstraction

Extract into a function, class, or module only when at least one is true:
- It is reused in two or more places (tolerate one duplication; extract on the second).
- It encapsulates a non-trivial concept that benefits from a name.
- It removes nesting or branching that hurts the caller's readability.

Do not extract code that is used once, is short, and is already clear inline. A two-line private method called from one place is almost always worse than the inline version.

**Signals you went too far:**
- A `send()` method that is a sequence of one-line calls to private helpers, each used once.
- A class with a single public method wrapping a single HTTP call.
- An interface with exactly one implementation and no test double.

**Exception:** single-use extraction is justified when it isolates a side effect — HTTP, filesystem, **clock, randomness/UUID generation** — for testability. That exception matters more than usual here: event IDs and timestamps are the substance of deduplication, so time and randomness must be injectable in every runtime.

### 2. Keep abstractions proportional to complexity

A function that builds a payload, hashes identifiers, and POSTs it does not need a Builder + Transport + Serializer + Mapper + Repository layer. Add layers only when they reduce total complexity, not when they redistribute it across more files.

**Signals you violated this:**
- Tracing one event from `track()` to the wire requires opening more than 3 files for trivial logic.
- A class delegates everything to another class without adding behavior.

### 3. Do not over-defend against impossible states

Defensive checks belong on **boundaries**. For a library the boundaries are specific and non-negotiable:

- **The public API** — anything an integrator passes in is untrusted. Validate it here, once, with a clear error naming the offending field. This is the one place where a library must be *stricter* than an application.
- **HTTP responses** from the Conversions API.
- **Host-provided data**: WordPress hooks and options, Laravel config, superglobals/request objects, GTM template inputs, `dataLayer` contents.
- **Configuration** without a default.

Past those boundaries, trust the contract. Do not re-check a value already guaranteed by a type declaration, by a validated constructor, or by a value object that cannot be constructed in an invalid state. If removing a check would only fail in a structurally impossible state, remove it.

### 4. The event schema is the single source of truth for accepted input

The set of fields an event accepts lives in **one** definition per runtime — the value object / schema, not scattered "allowed keys" arrays.

Do not maintain parallel lists of permitted fields in the WordPress adapter, the Laravel adapter, and the JS package. Adapters map host input onto the shared schema and let it reject what is invalid. When a field is added, exactly one file should need to change before every adapter can carry it.

After validation, work with the validated structure — do not mix it with re-reading the raw input. Raw access is acceptable only where the distinction between *absent* and *null* carries meaning the schema cannot express (which, for CAPI payloads, it sometimes does — an omitted field and an explicitly null one are not the same on the wire). When that matters, make it explicit in the type, not implicit in the access pattern.

### 5. Serializers and payload shaping

Introduce a dedicated serializer/transformer only when at least one applies:
- The shape is reused across multiple destinations (Pixel vs CAPI vs GTM).
- It standardizes a public contract integrators depend on.
- The transformation is non-trivial: computed fields, conditional inclusion, nested structures, hashing.

For a value object that already mirrors the wire format, serializing it directly is fine. `return $event->toArray();` is not a code smell.

### 6. Lazy construction of transports and clients

Construct HTTP clients, SDKs, and anything that opens connections or reads remote config **lazily, at the point of use, after the code path is determined** — never as a side effect of loading the library.

This is a hard requirement here, not a preference:
- **WordPress:** plugin load runs on *every* request, including admin-ajax, cron, and REST. Do work inside hooks; register cheaply at load. Never issue HTTP during `plugins_loaded`.
- **Laravel:** bind into the container; do not resolve or configure clients in a service provider's `register()`. Resolve inside the branch that actually sends.
- **JS:** the browser entry point must not perform network or heavy init on import.

Inject the dependencies a code path uses *unconditionally*; resolve lazily what only some branches need.

**Signals you violated this:** installing the package slows down every page load; a request that sends no event still opened a connection; a class takes 4+ collaborators, several used on one branch only.

### 7. Explicit data structures over loose arrays/objects

Data crossing a boundary between layers — adapter → core, core → transport, queue → handler — uses a typed structure: a value object such as `Event`, `UserData`, `CustomData`, or the language's equivalent (PHP classes with typed properties and named constructors; TypeScript types with real narrowing, not `Record<string, any>`).

Inline associative arrays / plain objects are fine for short-lived data inside one function, or for genuinely free-form data (options bags, custom properties an integrator defines).

**Signal:** if you must read two files to know what keys a structure contains, it should be a typed structure.

### 8. The Conversions API integration

**One** dedicated client owns: base URL, headers, authentication, timeout, retry/backoff policy, request construction, response validation, and the mapping of transport and HTTP errors onto this library's own exception types. Application and adapter code calls methods on that client; it never constructs HTTP requests directly.

**Implement only what the API documents.** No code paths for response shapes, status codes, or fields outside the documented contract — speculative handling is dead code that hides real bugs.

**Validate the response shape at the boundary.** Past that point the rest of the code trusts it.

In PHP, depend on PSR-18 / PSR-17 rather than a concrete HTTP client, so the core package does not force a transport on integrators; the Laravel and WordPress adapters supply the host's own client (`Http::`, `wp_remote_post`). In JS, use `fetch` with an injectable override.

### 9. Naming

Names are specific enough to be unambiguous in context, no more. Do not repeat what the namespace, package, or directory already says.

- Good: `OpenAIAds\Capi\Client`
- Redundant: `OpenAIAds\Capi\CapiApiHttpClientService`

Use role suffixes (`Client`, `Transport`, `Adapter`, `Job`, `Listener`) when the role is not obvious from context; do not stack suffixes that mean the same thing.

Public, integrator-facing names follow **the vocabulary of OpenAI Ads' own documentation**, not internal shorthand. A developer reading the API docs must recognize our parameter names on sight.

### 10. Templates and host UI are presentation-only

Anything that renders — the Pixel snippet, a WordPress settings screen, a Blade view in the Laravel adapter — receives finished data and displays it. It may iterate, apply display conditionals, and format for output. Escaping is mandatory: `esc_html` / `esc_attr` / `wp_json_encode` in WordPress, `{{ }}` in Blade, never string-concatenated JSON inside a `<script>`.

Rendering code must **not** query a database, transform business data, make HTTP calls, or read files. Prepare the data in the adapter and hand the template a finished structure.

### 11. Host-platform data discipline

There is no ORM in the core; discipline is per adapter.

- **WordPress:** use the platform APIs — options/transients for settings, `$wpdb->prepare()` for any direct SQL, `wp_remote_*` for HTTP. Prefix every option, hook, table, and global. Never write to `$_SESSION` or assume one exists. Respect `wp_cache_*`, and do not add a large autoloaded option.
- **Laravel:** if a model or query appears, avoid N+1 with `with()`, put reusable filters in scopes instead of duplicating `where` clauses, and keep mass-assignment protection deliberate.
- **Browser:** storing any identifier is consent-gated and explicit. No silent cookie writes, no fingerprinting.

### 12. Authorization and consent are separate from validation

Validation answers *is this input well-formed?* Authorization answers *is this caller allowed to do this?* Consent answers *are we permitted to collect this at all?* Keep the three separated.

- **WordPress:** capability checks (`current_user_can`) plus nonce verification on every admin form and AJAX endpoint, distinct from sanitizing the submitted values.
- **Laravel:** policies and gates, not inline ownership `if`s.
- **Everywhere:** a consent check gates whether an event is *collected*; it is not a validation rule. Failing consent is a normal, silent no-op — not an error.

### 13. Idempotency and deduplication are the core of this library

Everything that runs in response to an external trigger — a webhook, a retried queued job, a scheduled task, a page that may fire twice — must be safe to execute more than once with the same input.

Beyond that general rule, deduplication is this project's headline feature and deserves stated invariants:

- A browser Pixel event and its server-side CAPI counterpart carry **the same event ID**, generated once and propagated, never regenerated per transport.
- Event ID generation is deterministic where the caller supplies an identity, and injectable (see §1) so tests do not depend on a real clock or RNG.
- Retries reuse the original event ID. A retry is never a new event.
- Never assume "the queue delivers this only once".

Every change to ID generation, normalization, or hashing is a **behavioral** change to deduplication, even when type signatures do not move. Treat it as such: it needs tests pinning the exact output, and a changelog entry.

### 14. Identity handling

User identifiers are hashed at the boundary using the normalization the OpenAI Ads documentation specifies — trim, lowercase, strip formatting, then SHA-256 — implemented **once** per runtime and shared by every adapter. Two adapters must never normalize the same email differently.

Raw identifiers must not be logged, must not appear in exception messages, and must not be attached to error reports. When debugging output is needed, emit the hash or a redacted form.

### 15. Public API stability

The published surface follows semantic versioning. Adding an optional parameter is a minor; changing a default, a normalization rule, or an error type is a major. Internal classes outside the contract are marked as such (`@internal`, non-exported, `_`-prefixed as the language dictates) so refactoring stays cheap.

Every user-visible change gets a changelog entry. README examples are copy-pasteable and must keep working — treat a broken README example as a broken build.

## Working approach

When **refactoring existing code**:

1. **Understand before changing.** Read the surrounding code and its tests. Do not refactor what you do not understand.
2. **Preserve behavior.** Refactoring is not the time to fix bugs or change semantics. If you find a bug, surface it separately.
3. **Smallest viable change.** A sequence of small, reviewable commits beats one sweeping rewrite.
4. **Run the tests after each change.** If the area has no tests, add a characterization test before refactoring — especially for hashing, ID generation, and payload shape, where "the output changed slightly" is a silent data-quality incident downstream.
5. **When in doubt about scope, ask.** A guideline is a default; an explicit instruction wins.

When **generating new code**, default to the simplest structure that satisfies the requirement. Add layers only when a guideline above explicitly calls for one.

When a change touches shared measurement semantics, **check every adapter before declaring it done** — a core change that landed only in the PHP path is an incomplete change, not a finished one.

## Recorded decisions

These were settled during Phase 1 against the current OpenAI Ads documentation. They resolve
tensions in the guidelines above that this project's constraints expose. Do not re-litigate
them per session; revisit only if the upstream documentation changes.

**The specification exists in three places on purpose.** §4 forbids *scattering* the
accepted-field set within a runtime. It does not require one physical definition across
languages. `packages/spec/events.json`, the PHP validators and the TypeScript validators are
three deliberate copies, and the parity tests are what make the duplication safe. Code
generation was evaluated and rejected — 13 events across 2 runtimes does not repay a build
step, generated stack traces and generated error strings. Reconsider when a runtime appears
that cannot share code with the others (a GTM sandboxed-JavaScript template is the likely
trigger).

**§8's "validate the response shape at the boundary" does not apply to the Conversions API
response.** OpenAI documents no success body, no status codes, no error shape and no rate
limits. You cannot validate a shape nobody has specified, and inventing one is exactly what
§8's "implement only what the API documents" forbids. The boundary contract is HTTP status
only. `Response` exposes the status, headers, the raw body and `isSuccessful()` — never
`errors()`, `retryAfter()` or a decoded body.

**§8's "the client owns retry/backoff" is satisfied by deliberately delegating it.** Retry
lives in the injected PSR-18 client and in the adapters' queues (Laravel `$tries`, Action
Scheduler), not inside `send()`. The reason is idempotency: the documented deduplication key
implies but never states that the API deduplicates CAPI-to-CAPI on `events[].id`. Retrying a
request that succeeded with a lost response would double-count conversions — invisible,
corrupting to the advertiser's optimization, and unrecoverable. Do not add a retry loop to the
core on that inference.

**PSR-18 is the transport interface.** Do not define a `TransportInterface`. The WordPress
adapter's `wp_remote_post` shim implements PSR-18, so a bespoke interface would have one
implementation wrapping another interface — the anti-pattern §1 names.

**Unicode-aware lowercasing is mandatory.** Use `mb_strtolower($v, 'UTF-8')`; `strtolower()` is
byte-wise and diverges from JavaScript's `toLowerCase()` on non-ASCII names, producing two
different hashes for the same person. Per §13 this is a behavioural change to deduplication,
pinned in `packages/spec/fixtures/normalization.cases.json`.

**`oppref` and `obref` are different fields.** `events[].oppref` (event level, `__oppref`
cookie) and `events[].user.obref` (user level, `__obref` cookie). There is no coherent
`Attribution` object spanning both.
