<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Http;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client backed by WordPress's own HTTP API.
 *
 * This is the payoff for the core depending on PSR-18 instead of defining a
 * transport interface of its own: WordPress sites are frequently behind proxies,
 * firewalls and hosting-level HTTP filters that only `wp_remote_*` knows about,
 * and site owners expect `pre_http_request` and `http_request_args` to work. A
 * bespoke interface would have needed its own implementation here AND would
 * still not have served any other PSR-18 consumer.
 *
 * Requests are blocking with a bounded timeout. Measurement runs after the
 * response has been flushed to the visitor, so a slow ad platform delays a
 * background shutdown task rather than a page.
 */
final class WpTransport implements ClientInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly int $timeout = 5,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            // WordPress wants a flat map. Host is set by the HTTP layer itself.
            if (strcasecmp($name, 'Host') === 0) {
                continue;
            }

            $headers[$name] = implode(', ', $values);
        }

        $result = \wp_remote_request((string) $request->getUri(), [
            'method' => $request->getMethod(),
            'headers' => $headers,
            'body' => (string) $request->getBody(),
            'timeout' => $this->timeout,
            'redirection' => 0,
            'blocking' => true,
            // A non-2xx status is a valid outcome to report, not a failure to
            // reach the API. Only a WP_Error means no round trip happened.
            'reject_unsafe_urls' => true,
        ]);

        if (\is_wp_error($result)) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an exception is thrown to the caller, never printed; escaping it would put HTML entities into a log line.
            throw new NetworkFailure(
                $request,
                // The WP_Error message can name the host but never carries the
                // request body or the API key.
                sprintf('The Conversions API could not be reached: %s', $result->get_error_message()),
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        /** @var array{response: array{code: int}, headers: mixed, body: string} $result */
        $response = $this->responses->createResponse((int) $result['response']['code']);

        foreach ($this->normalizeHeaders($result['headers']) as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $response->getBody()->write((string) $result['body']);
        $response->getBody()->rewind();

        return $response;
    }

    /**
     * WordPress hands back either a plain array or a Requests_Utility case-
     * insensitive dictionary, depending on version and transport.
     *
     * @return array<string, string|list<string>>
     */
    private function normalizeHeaders(mixed $headers): array
    {
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            /** @var array<string, string|list<string>> $all */
            $all = $headers->getAll();

            return $all;
        }

        if (is_array($headers)) {
            /** @var array<string, string|list<string>> $headers */
            return $headers;
        }

        return [];
    }
}
