<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel\Tests;

use Illuminate\Contracts\Queue\Job;
use WebaroundLabs\OpenAIAds\Laravel\Jobs\SendConversionEvents;

/**
 * Stands in for the queue's own job record.
 *
 * Only `attempts()` matters here - it is what the delivery job reads to decide
 * whether a failed round trip is worth replaying - but the interface is wide, so
 * the rest is filled in honestly rather than mocked. A hand-written double keeps
 * the assertion readable and, unlike a mock, is something the analyser can type.
 */
final class FakeQueueJob implements Job
{
    public bool $deleted = false;

    public bool $released = false;

    public function __construct(private readonly int $attempts = 1)
    {
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function delete(): void
    {
        $this->deleted = true;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function release($delay = 0): void
    {
        $this->released = true;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function isDeletedOrReleased(): bool
    {
        return $this->deleted || $this->released;
    }

    public function uuid(): string
    {
        return '00000000-0000-4000-8000-000000000000';
    }

    public function getJobId(): string
    {
        return 'fake-job';
    }

    public function getRawBody(): string
    {
        return '';
    }

    public function fire(): void
    {
    }

    public function hasFailed(): bool
    {
        return false;
    }

    public function markAsFailed(): void
    {
    }

    public function fail($e = null): void
    {
    }

    public function maxTries(): ?int
    {
        return null;
    }

    public function maxExceptions(): ?int
    {
        return null;
    }

    public function backoff(): null
    {
        return null;
    }

    public function timeout(): ?int
    {
        return null;
    }

    public function retryUntil(): ?int
    {
        return null;
    }

    public function getName(): string
    {
        return 'fake';
    }

    public function resolveName(): string
    {
        return 'fake';
    }

    public function getConnectionName(): string
    {
        return 'sync';
    }

    public function getQueue(): string
    {
        return 'default';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [];
    }

    public function resolveQueuedJobClass(): string
    {
        return SendConversionEvents::class;
    }
}
