<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress;

use WebaroundLabs\OpenAIAds\HostContext as HostContextValue;

/**
 * Measurement context for the current request.
 *
 * Reads superglobals, which is the one place in this plugin where genuinely
 * untrusted input enters, and applies the toolkit's stated privacy policy before
 * anything reaches the core.
 *
 * `oppref` and `obref` are different fields from different cookies belonging to
 * different parts of the payload. Both are opaque: read byte for byte, never
 * parsed, decoded or minted.
 */
final class RequestContext
{
    public const OPPREF_COOKIE = '__oppref';

    public const OBREF_COOKIE = '__obref';

    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    /**
     * Everything the core needs to know about this request, in one value.
     *
     * The core's EventFactory takes this rather than reaching into WordPress,
     * which is what lets the same translation serve Laravel and a GTM server
     * container too.
     */
    public function forMeasurement(): HostContextValue
    {
        return new HostContextValue(
            sourceUrl: $this->sourceUrl(),
            oppref: $this->oppref(),
            obref: $this->obref(),
            ipAddress: $this->ipAddress(),
            userAgent: $this->userAgent(),
        );
    }

    /** Event-level attribution, from the Pixel's `__oppref` cookie. */
    public function oppref(): ?string
    {
        return $this->cookie(self::OPPREF_COOKIE);
    }

    /** User-level browser reference, from the Pixel's `__obref` cookie. */
    public function obref(): ?string
    {
        return $this->cookie(self::OBREF_COOKIE);
    }

    /**
     * The client IP.
     *
     * Only REMOTE_ADDR is trusted. Forwarded headers are attacker-controlled
     * unless a proxy is known to overwrite them, and WordPress has no notion of
     * trusted proxies - so a site behind Cloudflare should supply the real
     * address through the `openai_ads_client_ip` filter rather than have this
     * plugin guess from X-Forwarded-For.
     */
    public function ipAddress(): ?string
    {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        /** @var string $ip */
        $ip = \apply_filters('openai_ads_client_ip', $remote);

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    public function userAgent(): ?string
    {
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return null;
        }

        $agent = trim((string) $_SERVER['HTTP_USER_AGENT']);

        return $agent !== '' ? $agent : null;
    }

    /**
     * A source URL safe to send.
     *
     * The API only requires a scheme and a host. The rest is toolkit policy:
     * strip the query string, because on WordPress it routinely carries search
     * terms, order keys and password-reset tokens; and refuse an origin that is
     * not this site's, so a spoofed Host header cannot inject someone else's
     * domain into the advertiser's measurement data.
     */
    public function sourceUrl(): ?string
    {
        $canonical = $this->settings->canonicalOrigin();
        $path = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';

        $parts = \wp_parse_url($path);
        $pathOnly = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : '/';
        $query = is_array($parts) && isset($parts['query']) ? (string) $parts['query'] : null;

        if ($canonical === null) {
            return null;
        }

        $url = $canonical . $pathOnly;

        if (!$this->settings->stripQueryString() && $query !== null && $query !== '') {
            $url .= '?' . $query;
        }

        return $url;
    }

    private function cookie(string $name): ?string
    {
        if (!isset($_COOKIE[$name]) || !is_string($_COOKIE[$name])) {
            return null;
        }

        // Deliberately not sanitized: the value is opaque and must reach the API
        // byte for byte. It is never rendered, never interpolated into a query,
        // and never logged next to identity data.
        $value = $_COOKIE[$name];

        return trim($value) !== '' ? $value : null;
    }
}
