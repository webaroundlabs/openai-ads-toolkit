<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds;

/**
 * Builds the URL for an Image Tag conversion.
 *
 * The third measurement channel OpenAI documents, after the Measurement Pixel
 * and the Conversions API: a GET request dressed as a 1x1 image, for the places
 * JavaScript cannot go - an email body, an AMP page, a `<noscript>` fallback, a
 * platform that will not let you add a script.
 *
 * It is deliberately weaker than the other two, and the weaknesses are the
 * reason this class refuses rather than degrades:
 *
 * - **No identity.** The documentation is explicit: "The image tag doesn't
 *   support a `user` object. Don't put personal data, secrets, session IDs,
 *   customer identifiers, or order identifiers in any query parameter." An event
 *   carrying identity is therefore rejected, not quietly stripped - losing
 *   matching without being told is exactly the failure this toolkit exists to
 *   prevent. Acknowledge it at the call site with `Event::withoutIdentity()`.
 * - **No opt-out.** `opt_out` is not among the documented parameters, so an
 *   event carrying one is rejected too. Dropping a privacy flag silently is
 *   worse than refusing to send the event.
 * - **One event per request.** There is no batching, and URL length bounds the
 *   payload.
 *
 * What it keeps is the part that matters: `event_id`, so an image tag and a
 * Conversions API event describing the same conversion are deduplicated exactly
 * as a Pixel event would be.
 */
final class ImageTag
{
    public const ENDPOINT = 'https://bzr.openai.com/v1/sdk/events';

    /**
     * @param string $pixelId the public Pixel ID; never the Conversions API key
     *
     * @throws InvalidArgument when the event carries something a URL cannot
     */
    public static function url(string $pixelId, Event $event): string
    {
        if (trim($pixelId) === '') {
            throw new InvalidArgument('Pixel id must not be empty.');
        }

        if ($event->user !== null && !$event->user->isEmpty()) {
            throw new InvalidArgument(sprintf(
                'The image tag cannot carry identity - OpenAI documents no user object for it '
                . 'and forbids personal data in a query parameter - so event "%s" would lose its '
                . 'matching data silently. Call Event::withoutIdentity() to say so explicitly, '
                . 'or send this conversion through the Conversions API instead.',
                $event->id->value,
            ));
        }

        if ($event->optOut !== null) {
            throw new InvalidArgument(sprintf(
                'opt_out is not a documented image tag parameter, so event "%s" cannot honour '
                . 'it. Send this conversion through the Conversions API or the Pixel instead.',
                $event->id->value,
            ));
        }

        $query = [
            'pid' => $pixelId,
            'event' => $event->name->value,
            'event_id' => $event->id->value,
        ];

        if ($event->customEventName !== null) {
            $query['custom_event_name'] = $event->customEventName;
        }

        if ($event->oppref !== null) {
            $query['oppref'] = $event->oppref;
        }

        // The data object travels as data[<field>] parameters, with the contents
        // array serialized to JSON first, exactly as documented.
        foreach ($event->pixelData() as $field => $value) {
            $query['data[' . $field . ']'] = is_scalar($value)
                ? (string) $value
                : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return self::ENDPOINT . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
