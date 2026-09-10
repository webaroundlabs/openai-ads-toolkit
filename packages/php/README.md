# webaroundlabs/openai-ads

OpenAI Ads measurement for PHP: typed events, identity normalization, and the
Conversions API. No framework dependency.

> **Pre-alpha, `0.1.x`.** The event model and the Conversions API serializer exist.
> The HTTP client does not yet — see [Status](#status). The public API is unstable
> until `1.0`.

## Requirements

PHP 8.2+, `ext-json`, `ext-mbstring`. A 64-bit build (amounts are integers in the
currency's minor unit and can exceed 2³¹ for zero-decimal currencies).

`ext-mbstring` is not optional: name normalization must lowercase in a
Unicode-aware way, and PHP's byte-wise `strtolower()` produces a different digest
from JavaScript's `toLowerCase()` for any name with diacritics.

## Usage

```php
use WebaroundLabs\OpenAIAds\{Event, EventId, EventName, ActionSource, UserData, SystemClock};

$clock = new SystemClock();

$event = Event::create(
    name:         EventName::LeadCreated,
    id:           EventId::fromBusinessId($lead->id),
    timestampMs:  $clock->nowMs(),
    actionSource: ActionSource::Web,
    sourceUrl:    'https://example.com/contact/thank-you',
    user:         UserData::create(email: $lead->email),
);

$payload = $event->toCapiArray();
```

```php
[
    'id'            => 'lead_88213',
    'type'          => 'lead_created',
    'timestamp_ms'  => 1789041600000,
    'action_source' => 'web',
    'source_url'    => 'https://example.com/contact/thank-you',
    'data'          => ['type' => 'customer_action'],
    'user'          => ['emails_sha256' => ['b5fc85e5…']],
]
```

With a value — note the minor unit:

```php
use WebaroundLabs\OpenAIAds\Money;

Event::create(
    name:         EventName::OrderCreated,
    id:           EventId::fromBusinessId($order->number),
    timestampMs:  $clock->nowMs(),
    actionSource: ActionSource::Web,
    sourceUrl:    $confirmationUrl,
    value:        Money::minor(12_99, 'EUR'),   // 1299, never 12.99
);
```

## Design notes

**`toCapiArray()`, not `toArray()`.** The Pixel wants a different shape — singular
identity keys, and none of `source_url`, `timestamp_ms` or `oppref`, because the
browser SDK supplies those itself. A method named for its destination cannot be
sent to the wrong one by accident.

**No `Event::leadCreated()`.** Thirteen static constructors would differ by two
literals, and a static factory cannot take an injected clock without a global.
Time must be injectable because timestamps are part of deduplication, not an
incidental detail.

**Pass `$clock->nowMs()` at collection time, not at send time.** A retried queue
job must carry the timestamp of the conversion, not of the retry.

**Absent fields are omitted, never sent as `null`.** Omitted and null are a real
distinction on the wire and OpenAI documents no behaviour for an explicit null.

**`oppref` is not `obref`.** `oppref` is event-level, from the `__oppref` cookie,
and lives on `Event`. `obref` is user-level, from `__obref`, and lives on
`UserData`. Both are opaque: never parsed, decoded or minted.

**Raw identifiers never appear in exception messages.** A rejected phone number
reports the digit count and the rule, never the number.

**`opt_out` is not a consent gate.** It excludes an event from personalization. If
consent was refused, do not construct the event at all.

## Testing

```bash
composer install
composer test
vendor/bin/phpunit --filter it_reproduces_every_pinned_digest   # a single test
```

The suite includes `SpecParityTest`, which asserts this package against
`packages/spec`: the event catalogue, the data shapes, the Pixel/CAPI support
matrix, and the identity key mapping. Normalization tests are driven directly from
`packages/spec/fixtures/normalization.cases.json`, so PHP and the future
TypeScript core cannot disagree about what a value hashes to.

Those tests read the spec by relative path, so they run from a monorepo checkout
only — never from an installed package. That is intentional: the spec is a
development-time asset, not a runtime dependency.

## Status

Built: the 13 events, the four data shapes, `EventId`, `Money`, `UserData` with
normalization and hashing, and the Conversions API serializer.

Not built yet, deliberately — see `docs/api-design.md` §7 for the trigger that
would justify each: `Capi\Client` and the HTTP transport, `Content` and the
contents/plan data shapes, multi-value identity input, `Configuration`, and any
retry policy. There is no `TransportInterface`: PSR-18's `ClientInterface` already
is one, so the client will depend on that.

## License

[MIT](../../LICENSE). Independent community project, not affiliated with OpenAI.
