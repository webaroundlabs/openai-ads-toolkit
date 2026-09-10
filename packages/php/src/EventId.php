<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds;

/**
 * The identifier that ties a browser Pixel event to its server-side twin.
 *
 * Deduplication keys on Pixel ID + event name + event id, so this value must be
 * identical on both sides and must survive a retry unchanged.
 *
 * There is deliberately no generator. OpenAI recommends reusing an existing
 * stable business identifier - an order id, a payment intent id, a lead id -
 * and doing so also sidesteps the open question of where a minted id would have
 * to be created so that both the browser and the server can see it. A generator
 * would freeze that decision before the Pixel package exists to inform it.
 */
final class EventId implements \Stringable
{
    private function __construct(
        public readonly string $value,
    ) {
    }

    /**
     * @throws InvalidArgument when the identifier is empty or only whitespace
     */
    public static function fromBusinessId(string $id): self
    {
        $trimmed = trim($id);

        if ($trimmed === '') {
            throw new InvalidArgument('Event id must be a non-empty string.');
        }

        return new self($trimmed);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
