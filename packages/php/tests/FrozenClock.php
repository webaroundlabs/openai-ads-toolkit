<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Tests;

use WebaroundLabs\OpenAIAds\Clock;

/**
 * The reason Clock is an interface: without an injectable time source, no test
 * could assert a serialized payload, because every payload would contain the
 * moment it was built.
 */
final class FrozenClock implements Clock
{
    public function __construct(
        private int $ms,
    ) {
    }

    public function nowMs(): int
    {
        return $this->ms;
    }

    public function advanceMs(int $delta): void
    {
        $this->ms += $delta;
    }
}
