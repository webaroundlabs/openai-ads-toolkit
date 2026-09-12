<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel;

use Illuminate\Http\Request;
use WebaroundLabs\OpenAIAds\HostContext;

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
     * Everything the core needs to know about this request, in one value.
     *
     * The core's `EventFactory` takes this rather than reaching into Laravel,
     * which is what lets the same translation serve WordPress too.
     */
    public function forMeasurement(): HostContext
    {
        return new HostContext(
            sourceUrl: $this->sourceUrl(),
            oppref: $this->oppref(),
            obref: $this->obref(),
            ipAddress: $this->ipAddress(),
            userAgent: $this->userAgent(),
        );
    }

    /**
     * The event-level attribution identifier, from the Pixel's `__oppref` cookie.
     *
     * Read raw: this value is written by OpenAI's browser SDK, not by this
     * application, so it is not encrypted and must not be run through the
     * decrypter. Listing `__oppref` and `__obref` in EncryptCookies::$except is
     * the tidy thing to do, but it is not required - see rawCookie().
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
        $candidate = $this->referringPage() ?? $this->request->fullUrl();

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
     * The page a background request was made from, or null when this request IS
     * the page.
     *
     * A form posted with fetch(), an Inertia visit or a Livewire action reaches
     * a route the visitor never navigated to, so the request's own URL is the
     * endpoint rather than the page that produced the conversion. Reporting the
     * endpoint files every lead under one route and loses the landing page that
     * earned it.
     *
     * Only consulted on those requests: on an ordinary page view the request URL
     * IS the page, and the referer there is the page BEFORE this one.
     *
     * The header is client-controlled, which costs nothing here: sourceUrl()
     * refuses any origin that is not the canonical one, so the worst a forged
     * referer achieves is a wrong path on a domain that is already ours. An API
     * called by a mobile app or another server sends none, and the request URL
     * is used as before.
     */
    private function referringPage(): ?string
    {
        if (!$this->request->ajax() && !$this->request->expectsJson()) {
            return null;
        }

        $referer = $this->request->headers->get('referer');

        return is_string($referer) && trim($referer) !== '' ? trim($referer) : null;
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

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return $this->cookieFromHeader($name);
    }

    /**
     * The same cookie, read from the request's `Cookie` header.
     *
     * `EncryptCookies` rewrites `$request->cookies` in place and replaces with
     * null every value it cannot decrypt. These two are written by OpenAI's
     * browser SDK and are never encrypted by this application, so in an app that
     * has not listed them in `$except` - which is every app by default - the bag
     * above holds null and attribution disappears without a word. The header is
     * the one copy no middleware rewrites.
     *
     * Preferring the bag keeps an app that HAS configured `$except` on the
     * framework's own path, and reading the header rather than `$_COOKIE` keeps
     * this working under Octane, where the superglobal is not per-request.
     */
    private function cookieFromHeader(string $name): ?string
    {
        $header = $this->request->headers->get('cookie');

        if (!is_string($header) || trim($header) === '') {
            return null;
        }

        foreach (explode(';', $header) as $pair) {
            $parts = explode('=', trim($pair), 2);

            if (count($parts) !== 2 || $parts[0] !== $name) {
                continue;
            }

            // PHP decodes $_COOKIE and Symfony decodes its bag, so a value taken
            // from the header has to be decoded the same way or the two paths
            // would disagree on any cookie carrying a reserved character.
            $value = trim(urldecode(trim($parts[1], '"')));

            return $value === '' ? null : $value;
        }

        return null;
    }
}
