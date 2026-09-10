<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds;

/**
 * One conversion event.
 *
 * Immutable, and validated once at construction - which is this library's public
 * boundary and therefore the one place that must be strict. Everything
 * downstream can trust the object.
 *
 * There is no `Event::leadCreated()` and no other per-event named constructor.
 * Thirteen of them would differ by two literals, and a static factory cannot
 * take an injected clock without reaching for a global - which would break the
 * rule that time must be injectable, on the very first day, for the field that
 * deduplication depends on. `create()` with named arguments mirrors the
 * documented request body one for one instead.
 */
final class Event
{
    /**
     * Mirrors `patterns.custom_event_name` in packages/spec/events.json, without
     * delimiters. Exposed so SpecParityTest can assert the two are identical.
     *
     * @internal Not part of the public contract; may change with the spec.
     */
    public const CUSTOM_EVENT_NAME_PATTERN = '^[A-Za-z0-9]$|^[A-Za-z0-9][A-Za-z0-9_-]{0,62}[A-Za-z0-9]$';

    private function __construct(
        public readonly EventName $name,
        public readonly EventId $id,
        public readonly int $timestampMs,
        public readonly ActionSource $actionSource,
        public readonly ?string $sourceUrl,
        public readonly ?Money $value,
        public readonly ?UserData $user,
        public readonly ?string $oppref,
        public readonly ?bool $optOut,
        public readonly ?string $customEventName,
    ) {
    }

    /**
     * @param int         $timestampMs     When the conversion happened, in Unix
     *                                     milliseconds - pass `$clock->nowMs()`
     *                                     at collection time, not at send time,
     *                                     so a retry carries the original value.
     * @param string|null $sourceUrl       Required when $actionSource is Web.
     * @param string|null $oppref          The opaque `__oppref` cookie value.
     *                                     Event-level; not to be confused with
     *                                     the user-level `obref` on UserData.
     * @param bool|null   $optOut          Excludes the event from personalization.
     *                                     NOT a consent gate: if consent was
     *                                     refused, do not build the event at all.
     * @param string|null $customEventName Required when $name is Custom, forbidden otherwise.
     *
     * @throws InvalidArgument
     */
    public static function create(
        EventName $name,
        EventId $id,
        int $timestampMs,
        ActionSource $actionSource,
        ?string $sourceUrl = null,
        ?Money $value = null,
        ?UserData $user = null,
        ?string $oppref = null,
        ?bool $optOut = null,
        ?string $customEventName = null,
    ): self {
        if ($timestampMs <= 0) {
            throw new InvalidArgument(sprintf(
                'timestamp_ms must be a positive Unix millisecond timestamp (event "%s").',
                $id->value,
            ));
        }

        if (!$name->permits($actionSource)) {
            $allowed = array_map(
                static fn (ActionSource $a): string => $a->value,
                $name->allowedActionSources() ?? [],
            );

            throw new InvalidArgument(sprintf(
                'Event "%s" only supports action_source %s; got "%s" (event "%s").',
                $name->value,
                implode(', ', $allowed),
                $actionSource->value,
                $id->value,
            ));
        }

        $sourceUrl = self::validateSourceUrl($sourceUrl, $actionSource, $id);
        $customEventName = self::validateCustomEventName($customEventName, $name, $id);

        // No check that the data shape accepts an amount: all four documented
        // shapes do. What customer_action does NOT accept is a contents array or
        // a plan id, and in this slice that is enforced by those parameters not
        // existing yet - a better error than any runtime branch.

        if ($oppref !== null && trim($oppref) === '') {
            throw new InvalidArgument(sprintf(
                'oppref was provided but is empty; pass null instead (event "%s").',
                $id->value,
            ));
        }

        return new self(
            $name,
            $id,
            $timestampMs,
            $actionSource,
            $sourceUrl,
            $value,
            $user,
            $oppref,
            $optOut,
            $customEventName,
        );
    }

    /**
     * The Conversions API shape.
     *
     * Named for its destination on purpose. The Measurement Pixel wants a
     * different shape - singular identity keys, and none of source_url,
     * timestamp_ms or oppref, because the browser SDK supplies those itself - so
     * a method called `toArray()` would be an invitation to send this payload to
     * the Pixel and silently lose identity matching. The Pixel serializer
     * arrives alongside the JavaScript package.
     *
     * Absent optional fields are OMITTED rather than emitted as null: omitted
     * and null are a real distinction on the wire, and OpenAI documents no
     * behaviour for an explicit null.
     *
     * @return array<string, mixed>
     */
    public function toCapiArray(): array
    {
        $payload = [
            'id' => $this->id->value,
            'type' => $this->name->value,
        ];

        if ($this->customEventName !== null) {
            $payload['custom_event_name'] = $this->customEventName;
        }

        $payload['timestamp_ms'] = $this->timestampMs;
        $payload['action_source'] = $this->actionSource->value;

        if ($this->sourceUrl !== null) {
            $payload['source_url'] = $this->sourceUrl;
        }

        if ($this->oppref !== null) {
            $payload['oppref'] = $this->oppref;
        }

        if ($this->optOut !== null) {
            $payload['opt_out'] = $this->optOut;
        }

        $payload['data'] = $this->dataArray();

        if ($this->user !== null && !$this->user->isEmpty()) {
            $payload['user'] = $this->user->toCapiArray();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function dataArray(): array
    {
        $data = ['type' => $this->name->dataShape()->value];

        if ($this->value !== null) {
            $data['amount'] = $this->value->minorUnits;
            $data['currency'] = $this->value->currency;
        }

        return $data;
    }

    /**
     * @throws InvalidArgument
     */
    private static function validateSourceUrl(?string $sourceUrl, ActionSource $actionSource, EventId $id): ?string
    {
        if ($sourceUrl === null || trim($sourceUrl) === '') {
            if ($actionSource === ActionSource::Web) {
                throw new InvalidArgument(sprintf(
                    'source_url is required when action_source is "web" (event "%s").',
                    $id->value,
                ));
            }

            return null;
        }

        $sourceUrl = trim($sourceUrl);
        $parts = parse_url($sourceUrl);

        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new InvalidArgument(sprintf(
                'source_url must include a scheme and a host, such as '
                . '"https://example.com/thank-you" (event "%s").',
                $id->value,
            ));
        }

        // Restricting to http(s) is this toolkit's policy rather than a
        // documented API rule: no other scheme describes a web conversion, and
        // accepting one would let an attacker-supplied value into the
        // advertiser's measurement data.
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgument(sprintf(
                'source_url must use http or https; got "%s" (event "%s").',
                $parts['scheme'],
                $id->value,
            ));
        }

        return $sourceUrl;
    }

    /**
     * @throws InvalidArgument
     */
    private static function validateCustomEventName(?string $customEventName, EventName $name, EventId $id): ?string
    {
        if (!$name->requiresCustomEventName()) {
            if ($customEventName !== null) {
                throw new InvalidArgument(sprintf(
                    'custom_event_name is only valid for the "custom" event; '
                    . '"%s" is a standard event (event "%s").',
                    $name->value,
                    $id->value,
                ));
            }

            return null;
        }

        if ($customEventName === null || $customEventName === '') {
            throw new InvalidArgument(sprintf(
                'custom_event_name is required for the "custom" event (event "%s").',
                $id->value,
            ));
        }

        if (preg_match('/' . self::CUSTOM_EVENT_NAME_PATTERN . '/', $customEventName) !== 1) {
            throw new InvalidArgument(sprintf(
                'custom_event_name must be 1-64 characters of letters, digits, underscores or '
                . 'dashes, starting and ending with a letter or digit; got "%s" (event "%s").',
                $customEventName,
                $id->value,
            ));
        }

        if (EventName::tryFrom($customEventName) !== null) {
            throw new InvalidArgument(sprintf(
                'custom_event_name must not reuse the standard event name "%s" (event "%s").',
                $customEventName,
                $id->value,
            ));
        }

        return $customEventName;
    }
}
