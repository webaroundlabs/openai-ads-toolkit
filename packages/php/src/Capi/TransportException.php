<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Capi;

use WebaroundLabs\OpenAIAds\Exception;

/**
 * No HTTP round trip completed: DNS failure, connection refused, TLS error,
 * timeout.
 *
 * This is the ONLY failure the client throws for. A completed round trip returns
 * a Response whatever its status code, because deciding that 4xx means one thing
 * and 5xx another would require inventing the status taxonomy that OpenAI has
 * not published.
 *
 * The distinction matters operationally: a TransportException means the events
 * definitely did not arrive, whereas a non-2xx Response means they arrived and
 * something about them was unacceptable.
 *
 * The message never contains the request body or the API key.
 */
final class TransportException extends \RuntimeException implements Exception
{
}
