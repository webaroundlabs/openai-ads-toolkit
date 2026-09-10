<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds;

final class SystemClock implements Clock
{
    public function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
