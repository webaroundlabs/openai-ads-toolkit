<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

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
        $remote = isset($_SERVER['REMOTE_ADDR'])
            ? (string) \wp_unslash($_SERVER['REMOTE_ADDR'])
            : '';

        /** @var string $ip */
        $ip = \apply_filters('openai_ads_client_ip', $remote);

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    public function userAgent(): ?string
    {
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return null;
        }

        $agent = trim((string) \wp_unslash($_SERVER['HTTP_USER_AGENT']));

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
        $path = $this->referringPage()
            ?? (isset($_SERVER['REQUEST_URI'])
                ? (string) \wp_unslash($_SERVER['REQUEST_URI'])
                : '/');

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

    /**
     * The page a background request was made from, or null on a page view.
     *
     * Every AJAX and REST integration here runs on a request the visitor never
     * navigated to: Contact Form 7 posts to a REST route, Elementor, WPForms and
     * Ninja Forms to admin-ajax.php. REQUEST_URI is the endpoint on those, so
     * taking it would file every lead on the site under
     * `/wp-admin/admin-ajax.php` and lose the landing page that earned it.
     *
     * Only asked on those requests. On an ordinary page view REQUEST_URI IS the
     * page, and a referer there is the page BEFORE this one.
     *
     * The referer is host data and therefore untrusted. wp_get_referer() drops
     * one pointing off this site, and sourceUrl() rebuilds the origin from the
     * canonical one either way - so what comes back from here is a path this
     * site served, never another domain.
     */
    private function referringPage(): ?string
    {
        if (!$this->isBackgroundRequest()) {
            return null;
        }

        $referer = \wp_get_referer();

        return is_string($referer) && $referer !== '' ? $referer : null;
    }

    /**
     * Whether the browser asked for this in the background rather than
     * navigating to it.
     *
     * `wp_is_serving_rest_request()` is WordPress 6.5 and later; the plugin
     * supports 6.4, where the REST_REQUEST constant is the only marker there is.
     */
    private function isBackgroundRequest(): bool
    {
        if (\function_exists('wp_doing_ajax') && \wp_doing_ajax()) {
            return true;
        }

        if (\function_exists('wp_is_serving_rest_request')) {
            return \wp_is_serving_rest_request();
        }

        return \defined('REST_REQUEST') && \REST_REQUEST === true;
    }

    private function cookie(string $name): ?string
    {
        if (!isset($_COOKIE[$name]) || !is_string($_COOKIE[$name])) {
            return null;
        }

        // Unslashed but not sanitized. WordPress runs add_magic_quotes() over
        // $_COOKIE on every request, so the bytes here are already not the bytes
        // the browser sent; wp_unslash() puts them back. Sanitizing beyond that
        // would be wrong: the value is opaque and has to reach the API exactly as
        // OpenAI's own SDK wrote it. It is never rendered, never interpolated
        // into a query, and never logged next to identity data.
        $value = (string) \wp_unslash($_COOKIE[$name]);

        return trim($value) !== '' ? $value : null;
    }
}
