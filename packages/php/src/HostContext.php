<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds;

/**
 * What only the incoming request knows.
 *
 * Five values that no caller can supply and no core rule can derive: where the
 * page was, the two opaque attribution cookies, and the network identity of the
 * visitor. Each adapter reads them from its own host - Laravel's `Request`,
 * WordPress's superglobals, a GTM server container's event data - and hands them
 * over in this shape.
 *
 * It exists so `EventFactory` can be shared. Without it, every adapter would
 * need its own copy of the translation from a loose array to an Event, which is
 * exactly the parallel-definitions problem the charter's §4 forbids.
 *
 * `oppref` and `obref` are BOTH here and are NOT the same field: `oppref` is
 * event-level and comes from the `__oppref` cookie, `obref` is user-level and
 * comes from `__obref`. They are carried side by side because a request knows
 * both; they part company at serialization, and conflating them silently breaks
 * matching.
 *
 * Every value is optional. An absent one means the host could not vouch for it,
 * which is different from it being empty.
 */
final class HostContext
{
    public function __construct(
        /**
         * The page the conversion happened on, already reduced to whatever the
         * host's privacy policy allows. Required by the API for web events.
         */
        public readonly ?string $sourceUrl = null,
        /** Event-level attribution, from the `__oppref` cookie. Opaque. */
        public readonly ?string $oppref = null,
        /** User-level browser reference, from the `__obref` cookie. Opaque. */
        public readonly ?string $obref = null,
        /** The trusted client address. Behind a proxy, only as trustworthy as the proxy config. */
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
    ) {
    }

    /** A context that knows nothing - for an offline or back-office conversion. */
    public static function none(): self
    {
        return new self();
    }
}
