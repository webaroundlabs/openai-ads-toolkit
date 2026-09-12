<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Http;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

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
