<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Tests;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that records what it was asked to send and returns a canned
 * reply.
 *
 * This is the test double that a bespoke TransportInterface would have been
 * invented to enable - and it works precisely because PSR-18 is the interface,
 * which is the argument for not defining one of our own.
 */
final class RecordingHttpClient implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    public int $callCount = 0;

    public function __construct(
        private readonly int $status = 200,
        private readonly string $body = '',
        /** @var array<string, string> */
        private readonly array $headers = [],
        private readonly ?ClientExceptionInterface $throw = null,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;
        ++$this->callCount;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return new Response($this->status, $this->headers, $this->body);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedRequestBody(): array
    {
        if ($this->lastRequest === null) {
            throw new \LogicException('No request was sent.');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->lastRequest->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
