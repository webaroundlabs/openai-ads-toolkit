<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress;

use WebaroundLabs\OpenAIAds\ActionSource;
use WebaroundLabs\OpenAIAds\Clock;
use WebaroundLabs\OpenAIAds\Content;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\EventId;
use WebaroundLabs\OpenAIAds\EventName;
use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\Money;
use WebaroundLabs\OpenAIAds\UserData;

/**
 * Turns the loose array a WordPress caller passes into a validated Event.
 *
 * This is the adapter's translation layer, and the only place in the plugin
 * where an untyped array becomes a typed object. Everything past it is the
 * core's contract.
 *
 * The array shape is deliberately close to the documented API field names, so a
 * developer reading OpenAI's docs recognizes the keys.
 */
final class EventBuilder
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $data    Event data: amount, currency.
     * @param array<string, mixed> $options event_id, custom_event_name, opt_out, user,
     *                                      action_source, source_url, timestamp_ms,
     *                                      contents, plan_id.
     *
     * @throws InvalidArgument when the caller supplied something the API cannot accept
     */
    public function build(string $eventName, array $data = [], array $options = []): Event
    {
        $name = EventName::tryFrom($eventName);

        if ($name === null) {
            throw new InvalidArgument(sprintf(
                '"%s" is not a supported OpenAI Ads event.',
                $eventName,
            ));
        }

        $actionSource = isset($options['action_source'])
            ? (ActionSource::tryFrom((string) $options['action_source']) ?? ActionSource::Web)
            : ActionSource::Web;

        $sourceUrl = array_key_exists('source_url', $options)
            ? (is_string($options['source_url']) ? $options['source_url'] : null)
            : $this->context->sourceUrl();

        return Event::create(
            name: $name,
            id: $this->eventId($options),
            timestampMs: isset($options['timestamp_ms'])
                ? (int) $options['timestamp_ms']
                : $this->clock->nowMs(),
            actionSource: $actionSource,
            sourceUrl: $sourceUrl,
            value: $this->money($data),
            user: $this->user($options),
            oppref: $this->context->oppref(),
            optOut: isset($options['opt_out']) ? (bool) $options['opt_out'] : null,
            customEventName: isset($options['custom_event_name'])
                ? (string) $options['custom_event_name']
                : null,
            contents: $this->contents($options),
            planId: isset($options['plan_id']) ? (string) $options['plan_id'] : null,
        );
    }

    /**
     * Line items, either already built or as plain arrays.
     *
     * The array form exists because the plugin's public API is a WordPress
     * function taking arrays. Integrations inside the plugin build Content
     * objects directly and skip the conversion entirely.
     *
     * @param array<string, mixed> $options
     *
     * @return list<Content>
     *
     * @throws InvalidArgument
     */
    private function contents(array $options): array
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

            if (!is_array($item)) {
                continue;
            }

            $contents[] = $this->contentFromArray($item);
        }

        return $contents;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @throws InvalidArgument
     */
    private function contentFromArray(array $item): Content
    {
        $string = static fn (string $key): ?string => isset($item[$key])
            && is_scalar($item[$key])
            && (string) $item[$key] !== ''
                ? (string) $item[$key]
                : null;

        $currency = $string('currency');
        $amount = $item['amount'] ?? null;

        if ($amount !== null && !is_int($amount) && !(is_string($amount) && ctype_digit(ltrim($amount, '-')))) {
            throw new InvalidArgument(sprintf(
                'A content item amount must be an integer in the minor unit of its currency; got %s.',
                is_scalar($amount) ? var_export($amount, true) : gettype($amount),
            ));
        }

        if ($amount !== null && $currency === null) {
            throw new InvalidArgument('A content item with an amount must also carry a currency.');
        }

        /** @var array<string, string> $variantDict */
        $variantDict = isset($item['variant_dict']) && is_array($item['variant_dict'])
            ? array_map('strval', $item['variant_dict'])
            : [];

        return Content::create(
            id: $string('id'),
            groupId: $string('group_id'),
            name: $string('name'),
            contentType: $string('content_type'),
            quantity: isset($item['quantity']) ? (int) $item['quantity'] : null,
            value: $amount !== null && $currency !== null
                ? Money::minor((int) $amount, $currency)
                : null,
            variantDict: $variantDict,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function eventId(array $options): EventId
    {
        $id = isset($options['event_id']) ? (string) $options['event_id'] : '';

        if (trim($id) !== '') {
            return EventId::fromBusinessId($id);
        }

        // No caller-supplied id means no deduplication is possible: the browser
        // cannot know what to match against. Mint one so the event is still
        // usable server-side.
        return EventId::fromBusinessId(\wp_generate_uuid4());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgument
     */
    private function money(array $data): ?Money
    {
        if (!isset($data['amount'])) {
            return null;
        }

        $amount = $data['amount'];

        if (!is_int($amount) && !(is_string($amount) && ctype_digit(ltrim($amount, '-')))) {
            throw new InvalidArgument(sprintf(
                'amount must be an integer in the minor unit of its currency; got %s. '
                . 'A price of 12.99 is 1299.',
                is_scalar($amount) ? var_export($amount, true) : gettype($amount),
            ));
        }

        if (!isset($data['currency'])) {
            throw new InvalidArgument('currency is required when amount is present.');
        }

        return Money::minor((int) $amount, (string) $data['currency']);
    }

    /**
     * Build identity from raw values, plus the request's own context.
     *
     * Raw values are normalized and hashed by the core. The `obref` cookie and
     * the network fields are added here because only the request knows them.
     *
     * @param array<string, mixed> $options
     */
    private function user(array $options): UserData
    {
        /** @var array<string, mixed> $raw */
        $raw = isset($options['user']) && is_array($options['user']) ? $options['user'] : [];

        $string = static fn (string $key): ?string => isset($raw[$key])
            && is_string($raw[$key])
            && trim($raw[$key]) !== ''
                ? $raw[$key]
                : null;

        return UserData::create(
            email: $string('email'),
            phone: $string('phone'),
            externalId: $string('external_id'),
            firstName: $string('first_name'),
            lastName: $string('last_name'),
            country: $string('country'),
            city: $string('city'),
            region: $string('region'),
            postalCode: $string('postal_code'),
            obref: $this->context->obref(),
            ipAddress: $this->context->ipAddress(),
            userAgent: $this->context->userAgent(),
        );
    }
}
