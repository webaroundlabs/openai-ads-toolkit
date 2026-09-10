<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel;

use Illuminate\Http\Request;

/**
 * Pulls the measurement context out of an HTTP request.
 *
 * This is the adapter's real job: translating host conventions into the core's
 * types. Nothing here decides anything about OpenAI Ads - it reads cookies,
 * headers and the URL, applies the toolkit's stated privacy policy, and hands
 * over plain values.
 *
 * `oppref` and `obref` are different fields from different cookies, and both are
 * opaque. They are read and passed through byte for byte; never parsed, decoded
 * or minted.
 */
final class RequestContext
{
    public const OPPREF_COOKIE = '__oppref';

    public const OBREF_COOKIE = '__obref';

    public function __construct(
        private readonly Request $request,
        private readonly ?string $canonicalOrigin = null,
        private readonly bool $stripQueryString = true,
    ) {
    }

    /**
     * The event-level attribution identifier, from the Pixel's `__oppref` cookie.
     *
     * Read from the raw cookie jar rather than through Laravel's cookie
     * decryption: this value is written by OpenAI's browser SDK, not by this
     * application, so it is not encrypted and must not be run through the
     * decrypter. Add `__oppref` and `__obref` to EncryptCookies::$except if your
     * middleware would otherwise touch them.
     */
    public function oppref(): ?string
    {
        return $this->rawCookie(self::OPPREF_COOKIE);
    }

    /** The user-level browser reference, from the Pixel's `__obref` cookie. */
    public function obref(): ?string
    {
        return $this->rawCookie(self::OBREF_COOKIE);
    }

    /**
     * The client IP.
     *
     * Laravel resolves this through the trusted-proxy configuration, so behind a
     * load balancer this is only as trustworthy as `TrustProxies`. If that is not
     * configured, an attacker can set it - which is why it is worth checking
     * before relying on it for matching.
     */
    public function ipAddress(): ?string
    {
        $ip = $this->request->ip();

        return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    public function userAgent(): ?string
    {
        $agent = $this->request->userAgent();

        return $agent !== null && trim($agent) !== '' ? $agent : null;
    }

    /**
     * A source URL safe to send.
     *
     * The API only requires a scheme and a host. Everything else here is the
     * toolkit's policy: strip the query string and fragment, because they
     * routinely carry email addresses, password-reset tokens and order
     * references that have no business reaching an ad platform; and refuse an
     * origin that is not the configured canonical one, so a spoofed Host header
     * cannot inject a third-party domain into the advertiser's data.
     *
     * Returns null when the request cannot produce a trustworthy URL, which the
     * caller should treat as "do not send a web event" rather than as an error.
     */
    public function sourceUrl(): ?string
    {
        $canonical = $this->canonicalOrigin();
        // fullUrl(), not url(): url() has already dropped the query string, which
        // would make the strip_query_string setting a no-op in one direction.
        // Fragments never reach the server, so there is nothing to strip there.
        $candidate = $this->request->fullUrl();

        $parts = parse_url($candidate);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $canonical;
        }

        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);

        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        if ($canonical !== null && $origin !== $canonical) {
            // The request did not come from the site we are measuring. Fall back
            // to the canonical origin rather than reporting someone else's host.
            return $canonical;
        }

        $path = $parts['path'] ?? '/';
        $url = $origin . $path;

        if (!$this->stripQueryString && isset($parts['query'])) {
            $url .= '?' . $parts['query'];
        }

        return $url;
    }

    /**
     * The configured canonical origin, normalized to scheme://host[:port].
     */
    public function canonicalOrigin(): ?string
    {
        if ($this->canonicalOrigin === null || trim($this->canonicalOrigin) === '') {
            return null;
        }

        $parts = parse_url(trim($this->canonicalOrigin));

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);

        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    private function rawCookie(string $name): ?string
    {
        /** @var array<string, mixed> $cookies */
        $cookies = $this->request->cookies->all();
        $value = $cookies[$name] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
