<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds;

/**
 * Builds a validated Event from the loose array a host framework hands over.
 *
 * The one place in the toolkit where an untyped array becomes a typed object.
 * It lives in the core rather than in each adapter because the alternative is
 * the same translation written once per host, drifting quietly - which is the
 * parallel-definitions problem §4 of the charter forbids by name.
 *
 * The array keys are the documented OpenAI Ads field names, in snake_case, so a
 * developer reading the API documentation recognizes them without learning a
 * second vocabulary.
 *
 * Identity is built with `UserData::fromUntrusted()`, not `create()`. That is
 * the whole point of this class being the host boundary: the values arrive from
 * a form a visitor filled in, and losing a paid order because somebody typed an
 * extension after their phone number is worse than sending one fewer matching
 * field. Pass `$onDroppedField` to hear about it.
 */
final class EventFactory
{
    /** @var (callable(string, string): void)|null */
    private $onDroppedField;

    /**
     * @param (callable(string, string): void)|null $onDroppedField field name and reason.
     *                                                             Neither carries the value,
     *                                                             and a listener must not log
     *                                                             one either.
     */
    public function __construct(
        private readonly Clock $clock,
        ?callable $onDroppedField = null,
    ) {
        $this->onDroppedField = $onDroppedField;
    }

    /**
     * @param array<string, mixed> $data    amount, currency
     * @param array<string, mixed> $options event_id, custom_event_name, opt_out, user,
     *                                      action_source, source_url, timestamp_ms,
     *                                      contents, plan_id
     *
     * @throws InvalidArgument when the caller supplied something the API cannot accept
     */
    public function build(
        string $eventName,
        array $data = [],
        array $options = [],
        ?HostContext $context = null,
    ): Event {
        $context ??= HostContext::none();
        $name = EventName::tryFrom($eventName);

        if ($name === null) {
            throw new InvalidArgument(sprintf('"%s" is not a supported OpenAI Ads event.', $eventName));
        }

        $actionSourceName = self::text($options['action_source'] ?? null);
        $actionSource = $actionSourceName === null
            ? ActionSource::Web
            : (ActionSource::tryFrom($actionSourceName) ?? ActionSource::Web);

        // array_key_exists, not isset: an explicit null source_url is a caller
        // saying "do not use the request's", which is not the same as omitting it.
        $sourceUrl = array_key_exists('source_url', $options)
            ? (is_string($options['source_url']) ? $options['source_url'] : null)
            : $context->sourceUrl;

        return Event::create(
            name: $name,
            id: $this->eventId($options),
            timestampMs: self::minorUnits($options['timestamp_ms'] ?? null) ?? $this->clock->nowMs(),
            actionSource: $actionSource,
            sourceUrl: $sourceUrl,
            value: self::money($data),
            user: $this->user($options, $context),
            oppref: $context->oppref,
            optOut: isset($options['opt_out']) ? (bool) $options['opt_out'] : null,
            customEventName: self::text($options['custom_event_name'] ?? null),
            contents: self::contents($options),
            planId: self::text($options['plan_id'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function user(array $options, HostContext $context): UserData
    {
        /** @var array<string, mixed> $raw */
        $raw = isset($options['user']) && is_array($options['user']) ? $options['user'] : [];

        // The caller's fields, plus the three only the request knows. Those are
        // vouched for by whoever read them; an adapter that cannot trust a
        // proxy-supplied IP passes null and it is simply absent.
        return UserData::fromUntrusted(
            [
                ...$raw,
                'obref' => $context->obref,
                'ip_address' => $context->ipAddress,
                'user_agent' => $context->userAgent,
            ],
            $this->onDroppedField,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function eventId(array $options): EventId
    {
        $id = self::text($options['event_id'] ?? null);

        if ($id !== null) {
            return EventId::fromBusinessId($id);
        }

        // No caller-supplied id means no deduplication is possible: the browser
        // cannot know what to match against. One is minted so the event is still
        // usable server-side, but a caller that wants both halves counted once
        // has to supply its own and share it.
        return EventId::fromBusinessId(self::uuid4());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgument
     */
    private static function money(array $data): ?Money
    {
        if (!isset($data['amount'])) {
            return null;
        }

        $raw = $data['amount'];
        $amount = self::minorUnits($raw);

        if ($amount === null) {
            throw new InvalidArgument(sprintf(
                'amount must be an integer in the minor unit of its currency; got %s. '
                . 'A price of 12.99 is 1299.',
                is_scalar($raw) ? var_export($raw, true) : gettype($raw),
            ));
        }

        $currency = self::text($data['currency'] ?? null);

        if ($currency === null) {
            throw new InvalidArgument('currency is required when amount is present.');
        }

        return Money::minor($amount, $currency);
    }

    /**
     * Line items, either already built or as plain arrays.
     *
     * The array form exists because a host's public API takes arrays. Code that
     * already has Content objects passes them straight through.
     *
     * @param array<string, mixed> $options
     *
     * @return list<Content>
     *
     * @throws InvalidArgument
     */
    private static function contents(array $options): array
    {
        if (!isset($options['contents']) || !is_array($options['contents'])) {
            return [];
        }

        $contents = [];

        foreach ($options['contents'] as $item) {
            if ($item instanceof Content) {
                $contents[] = $item;

                continue;
            }

            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $contents[] = self::contentFromArray($item);
            }
        }

        return $contents;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @throws InvalidArgument
     */
    private static function contentFromArray(array $item): Content
    {
        $string = static fn (string $key): ?string => self::text($item[$key] ?? null);

        $currency = $string('currency');
        $raw = $item['amount'] ?? null;
        $amount = $raw === null ? null : self::minorUnits($raw);

        if ($raw !== null && $amount === null) {
            throw new InvalidArgument(sprintf(
                'A content item amount must be an integer in the minor unit of its currency; got %s.',
                is_scalar($raw) ? var_export($raw, true) : gettype($raw),
            ));
        }

        if ($amount !== null && $currency === null) {
            throw new InvalidArgument('A content item with an amount must also carry a currency.');
        }

        $variantDict = [];

        if (isset($item['variant_dict']) && is_array($item['variant_dict'])) {
            foreach ($item['variant_dict'] as $key => $value) {
                $attribute = self::text($value);

                if ($attribute !== null) {
                    $variantDict[(string) $key] = $attribute;
                }
            }
        }

        return Content::create(
            id: $string('id'),
            groupId: $string('group_id'),
            name: $string('name'),
            contentType: $string('content_type'),
            quantity: self::minorUnits($item['quantity'] ?? null),
            value: $amount !== null && $currency !== null
                ? Money::minor($amount, $currency)
                : null,
            variantDict: $variantDict,
        );
    }

    /**
     * A non-empty string, or null.
     *
     * Host arrays are `mixed` by nature - a WordPress option, a request
     * parameter, a dataLayer entry - so every read out of one goes through here
     * rather than through a cast that would quietly stringify an array.
     */
    private static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $string = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        return trim($string) === '' ? null : $string;
    }

    /**
     * An integer, or null when the value is not one.
     *
     * A numeric string is accepted because a host's form data is all strings. A
     * float is NOT, and that is the point: `12.99` means the caller sent major
     * units, which is the single most common mistake made against any
     * conversions API and is worth an error rather than a silent truncation.
     */
    private static function minorUnits(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && ctype_digit(ltrim($value, '-'))) {
            return (int) $value;
        }

        return null;
    }

    /**
     * RFC 4122 version 4, from the platform CSPRNG.
     *
     * Only reached when the caller supplied no id, in which case the event
     * cannot deduplicate anyway - but a weak id could collide and merge two
     * people's conversions, so this is not the place to economize on entropy.
     */
    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return implode('-', [
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        ]);
    }
}
