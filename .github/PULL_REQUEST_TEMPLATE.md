## What and why

<!-- The code says what changed. Say why. -->

## Checks

- [ ] Tests pass for every package I touched
- [ ] `python packages/spec/verify.py` passes
- [ ] `python scripts/check-secrets.py` passes

## Does this change measurement behaviour?

<!--
Tick anything that applies and say what you did about it. All of these are
behavioural changes to deduplication even when no type signature moves, so each
needs a test pinning the exact output and a CHANGELOG entry.
-->

- [ ] Identity normalization or hashing
- [ ] Event id generation, or where an id comes from
- [ ] The serialized payload shape
- [ ] When an event fires (the conversion boundary)
- [ ] None of the above
