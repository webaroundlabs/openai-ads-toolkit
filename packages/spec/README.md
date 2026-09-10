# `@openai-ads-toolkit/spec`

The canonical description of the OpenAI Ads events this toolkit supports.

Every other package — PHP, JavaScript, Laravel, WordPress, GTM — validates against this
definition. When OpenAI adds an event, this is the file that changes; the parity tests then
tell each runtime what it is missing.

## What this is not

- **Not a runtime dependency.** No package loads these files in production. They are a
  development-time asset, referenced by repo-relative path from test suites and the docs
  build. That is what keeps the PHP core at zero runtime dependencies and keeps the event
  catalogue out of the browser bundle.
- **Not a JSON Schema.** JSON Schema cannot express the Pixel/CAPI dual serialization, the
  normalization pipelines, the `capi_only` matrix, the "first three unique values" rule, or a
  timestamp window measured against send time. Shipping one would mean a schema covering part
  of the truth plus hand-written code covering the rest — two competing sources of truth. It
  also produces errors like `#/events/3/data/currency: dependentRequired` where this project
  requires an error that names the field and the event.
- **Not a payload validator.** It describes what is valid; each runtime implements the check
  idiomatically, with its own error messages.

## Files

| File | Contents |
|---|---|
| `events.json` | Catalogue, envelope, data shapes, the `Content` object, limits and patterns |
| `user.json` | Identity: normalization rules and the Pixel/CAPI key mapping |
| `fixtures/` | Golden outputs asserted byte-for-byte by both runtimes |

Identity lives in its own file for three reasons: it is a mapping table rather than a
catalogue, it is identical across all 13 events, and it changes on a different cadence — a
diff touching `user.json` is a behavioural change to deduplication and should be visible as
such from the filename alone.

There is deliberately **no `attribution.json`**. Attribution is two fields that already have
owners: `oppref` is an event field, `obref` is a user field. A third file holding both would
have to be split apart again at serialization, and it would invite a class spanning two
different wire objects.

## Conventions

Both files use a small closed vocabulary. Conditional requirements take exactly two forms —
`{"field": …, "equals": …}` and `{"field": …, "present": true}`. A rule that needs a third
form belongs in code, not here.

| Marker | Meaning |
|---|---|
| `capi_only` | Accepted by the Conversions API, no Pixel equivalent. The Pixel serializer strips it. |
| `supplied_by_pixel_sdk` | The browser SDK derives it. Never hand-pass it on the Pixel side; required explicitly for CAPI. |
| `"pixel": null` | The browser supplies this itself. Different from `capi_only`, hence a different encoding. |
| `opaque` | Pass through byte-for-byte. Never parse, decode, transform or mint. |
| `notes` | An upstream ambiguity or a known hazard, recorded in-band so no runtime silently picks a side. |

## The two things most likely to be got wrong

**`oppref` is not `obref`.** They are different fields, from different cookies, on different
wire objects:

| Field | Where | Cookie |
|---|---|---|
| `oppref` | `events[].oppref` | `__oppref` |
| `obref` | `events[].user.obref` | `__obref` |

**The Pixel and the Conversions API serialize identity differently.** CAPI takes plural array
keys (`emails_sha256`), the Pixel takes singular scalars (`email_sha256`). Same digest, different
shape. `fixtures/user.full.capi.json` and `fixtures/user.full.pixel.json` are the same input
rendered both ways; read them side by side before writing a serializer.

## Parity tests

Each runtime asserts, against these files:

1. **Catalogue completeness** — the runtime's event enum equals the keys of `events.json → events`.
2. **Shape agreement** — every event's data shape and its `pixel` / `capi` / `action_sources`
   matrix match.
3. **Identity mapping** — a fully-populated user serialized for CAPI has exactly the key set of
   every `fields[*].capi.key`, with list-cardinality fields as arrays; and the Pixel serialization
   contains no field marked `"pixel": null`.
4. **Golden fixtures** — byte-equal after canonical JSON encoding.

The spec exists in three places (here, PHP, TypeScript) on purpose. `CLAUDE.md` §4 forbids
*scattering* the accepted-field set within a runtime; it does not require a single physical
definition across languages, and the parity tests are what make the duplication safe. Code
generation was considered and rejected: 13 events across 2 runtimes does not repay a build
step, generated stack traces and generated error strings. Revisit when a third runtime that
cannot share code with the others appears — a GTM sandboxed-JavaScript template is the likely
trigger.

## When OpenAI adds an event

1. Re-read the upstream docs and update `retrieved` and `sources`.
2. Add the event to `events.json` with its data shape, support matrix, `description` and `boundary`.
3. Run both test suites. Parity assertions 1 and 2 go red in each runtime.
4. Add the enum case and validation in PHP and TypeScript.
5. Add a fixture, and a changelog entry.

The failure mode is a red build, never silent divergence.

## Verifying a change

```bash
python packages/spec/verify.py
```

`verify.py` is the Phase 1 stand-in for the parity tests. It checks that every internal
cross-reference resolves, that the documented facts still hold (the 13 events, the 7 action
sources, `oppref` and `obref` on their correct objects, the plural/singular split, the batch and
timestamp limits, the `custom_event_name` pattern's accept/reject behaviour), and that the
fixtures agree with the spec files they derive from. Once the PHP and TypeScript packages exist,
each asserts the same properties against its own implementation and this script becomes a CI job.

Anything touching `user.json` normalization must also reproduce every digest in
`fixtures/normalization.cases.json`. Those were generated independently in Python 3.12 and
PHP 8.2 and cross-checked; the file also pins the exact wrong hash produced by a byte-wise
`strtolower`, so the regression cannot reappear unnoticed.

## Source

Derived from the official OpenAI Ads documentation, retrieved 2026-09-10. URLs are recorded in
the `sources` array of each file. This is an independent community project and is not
affiliated with, endorsed by, or supported by OpenAI.
