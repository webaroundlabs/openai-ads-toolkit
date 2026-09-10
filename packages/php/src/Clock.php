<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds;

/**
 * A source of the current time, in Unix milliseconds.
 *
 * Time is injectable because timestamps are part of the deduplication
 * mechanism rather than an incidental detail: an event must be stamped when the
 * conversion is collected, and a retried job must carry that original value
 * rather than the time it happened to be retried.
 */
interface Clock
{
    public function nowMs(): int;
}
