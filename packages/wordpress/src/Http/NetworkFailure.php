<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Http;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * No HTTP round trip completed.
 *
 * Implements the PSR-18 network exception so the core's client recognizes it and
 * converts it into its own TransportException - which is what tells the caller
 * the events definitely did not arrive, as opposed to arriving and being
 * refused.
 */
final class NetworkFailure extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
