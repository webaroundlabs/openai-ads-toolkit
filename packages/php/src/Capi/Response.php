<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Capi;

/**
 * What came back from the Conversions API.
 *
 * Deliberately thin. OpenAI documents no success body, no status codes, no error
 * shape, no rate limits and no idempotency semantics - so the only contract this
 * library actually holds is HTTP itself, and anything richer would be an
 * invented taxonomy dressed up as an API contract.
 *
 * That is why there is no `errors()`, no `failedEvents()`, no `requestId()`, no
 * `isRateLimited()` and no `retryAfter()`. Per-event results in particular are
 * not merely undocumented but impossible: the documentation states that if one
 * event in a batch fails, the whole batch fails, so no per-event outcome exists
 * to report.
 *
 * There is also no `decodedBody()`. It would be one line and would technically
 * claim nothing, but the moment it exists somebody writes
 * `$response->decodedBody()['id']` and depends on a shape this library cannot
 * stand behind. Callers who want to parse the body may do so and own the risk.
 *
 * If OpenAI documents the response later, adding accessors is a minor version
 * bump.
 */
final class Response
{
    /**
     * @param array<string, list<string>> $headers
     * @param string                      $body    Raw and undecoded.
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /**
     * Whether the request succeeded at the HTTP level.
     *
     * A 2xx guarantee from RFC 9110, not a statement about OpenAI Ads accepting
     * the events - that distinction is not observable with the documentation
     * available.
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}
