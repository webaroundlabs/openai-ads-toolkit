<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Psr\Log\LoggerInterface;
use WebaroundLabs\OpenAIAds\Capi\Client;
use WebaroundLabs\OpenAIAds\Capi\TransportException;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\InvalidArgument;

/**
 * Delivers a batch off the request cycle.
 *
 * The events are carried as constructed objects, so the queue payload holds the
 * original event ids and timestamps. A retry therefore replays the *same*
 * events - it never re-mints an id or re-stamps a clock, which is what keeps a
 * retry from becoming a second conversion.
 *
 * Retry policy is deliberately narrow, and this is the whole reason the core
 * distinguishes a failed round trip from an unsuccessful response:
 *
 * - No round trip (DNS, refused connection, TLS): the events did not arrive, so
 *   retrying is safe. Retried.
 * - A completed request with a non-2xx status: the events arrived and something
 *   about them was unacceptable. Repeating them cannot help and might duplicate,
 *   so it is logged and dropped.
 * - Rejected locally (malformed, or aged out of the 7-day window while queued):
 *   permanent. Logged and dropped.
 *
 * A timeout is the honest grey area - it is reported as a transport failure, but
 * the API may have processed the batch before the connection gave up. Retrying
 * it is a bet that OpenAI deduplicates on the event id, which the documentation
 * implies without stating. Set `openai-ads.queue.tries` to 1 to opt out of that
 * bet entirely.
 */
final class SendConversionEvents implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param list<Event> $events
     */
    public function __construct(
        private readonly array $events,
        private readonly bool $validateOnly = false,
    ) {
    }

    public function handle(Client $client, LoggerInterface $logger, Config $config): void
    {
        if ($this->events === []) {
            return;
        }

        try {
            $response = $client->send($this->events, $this->validateOnly);
        } catch (InvalidArgument $e) {
            // Permanent: a stale or malformed event will not become valid later.
            $logger->error('OpenAI Ads: batch rejected before sending; dropping.', [
                'reason' => $e->getMessage(),
                'events' => count($this->events),
            ]);

            $this->delete();

            return;
        } catch (TransportException $e) {
            $tries = (int) $config->get('openai-ads.queue.tries', 3);

            if ($this->attempts() >= $tries) {
                $logger->error('OpenAI Ads: delivery failed permanently.', [
                    'reason' => $e->getMessage(),
                    'attempts' => $this->attempts(),
                    'events' => count($this->events),
                ]);

                $this->delete();

                return;
            }

            // Nothing was delivered, so replaying the same events is safe.
            throw $e;
        }

        if ($response->isSuccessful()) {
            return;
        }

        // The request arrived. Repeating it cannot fix the payload and risks a
        // duplicate, so this is the end of the road for this batch.
        $logger->warning('OpenAI Ads: the API rejected the batch.', [
            'status' => $response->statusCode,
            'events' => count($this->events),
            // The body is included because it is the only diagnostic the API
            // gives. It is untrusted text: never render it without escaping.
            'body' => mb_substr($response->body, 0, 500),
        ]);

        $this->delete();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        /** @var list<int> $backoff */
        $backoff = config('openai-ads.queue.backoff', [10, 60, 300]);

        return $backoff;
    }

    public function tries(): int
    {
        return (int) config('openai-ads.queue.tries', 3);
    }

    public function failed(\Throwable $e): void
    {
        logger()->error('OpenAI Ads: the delivery job failed.', ['reason' => $e->getMessage()]);
    }
}
