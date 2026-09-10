<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FakeHttpClient implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    public int $callCount = 0;

    public function __construct(
        private readonly int $status = 200,
        private readonly string $body = '{}',
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

        return new Response($this->status, [], $this->body);
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->lastRequest?->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
