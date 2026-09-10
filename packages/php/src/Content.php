<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds;

/**
 * One item inside an event's `contents` array.
 *
 * A product in an order, a plan in a subscription, an article that was viewed.
 *
 * Two fields are Conversions API only and are silently absent from the Pixel
 * serialization: `group_id`, which ties a variation back to its parent product,
 * and `variant_dict`, which carries the chosen attributes. The Pixel would
 * accept and discard them.
 */
final class Content
{
    /**
     * @param array<string, string> $variantDict
     */
    private function __construct(
        public readonly ?string $id,
        public readonly ?string $groupId,
        public readonly ?string $name,
        public readonly ?string $contentType,
        public readonly ?int $quantity,
        public readonly ?Money $value,
        public readonly array $variantDict,
    ) {
    }

    /**
     * @param string|null           $groupId     Conversions API only. For a variation,
     *                                           the parent product's identifier.
     * @param string|null           $contentType "product", "plan", "page", or your own.
     * @param Money|null            $value       The item's own value, in minor units.
     * @param array<string, string> $variantDict Conversions API only. Chosen attributes,
     *                                           such as ['size' => 'M', 'colour' => 'blue'].
     *
     * @throws InvalidArgument
     */
    public static function create(
        ?string $id = null,
        ?string $groupId = null,
        ?string $name = null,
        ?string $contentType = null,
        ?int $quantity = null,
        ?Money $value = null,
        array $variantDict = [],
    ): self {
        // Every field is optional individually, but an item with none of them
        // describes nothing and would only add noise to the payload.
        if (
            $id === null && $groupId === null && $name === null && $contentType === null
            && $quantity === null && $value === null && $variantDict === []
        ) {
            throw new InvalidArgument('A content item must carry at least one field.');
        }

        if ($quantity !== null && $quantity < 0) {
            throw new InvalidArgument(sprintf(
                'Content quantity must not be negative; got %d.',
                $quantity,
            ));
        }

        foreach ($variantDict as $key => $variantValue) {
            if (!is_string($key) || !is_string($variantValue)) {
                throw new InvalidArgument(
                    'variant_dict must be a map of strings to strings, such as '
                    . '["size" => "M"].',
                );
            }
        }

        return new self($id, $groupId, $name, $contentType, $quantity, $value, $variantDict);
    }

    /**
     * @return array<string, mixed>
     */
    public function toCapiArray(): array
    {
        $payload = [];

        if ($this->id !== null) {
            $payload['id'] = $this->id;
        }

        if ($this->groupId !== null) {
            $payload['group_id'] = $this->groupId;
        }

        if ($this->name !== null) {
            $payload['name'] = $this->name;
        }

        if ($this->contentType !== null) {
            $payload['content_type'] = $this->contentType;
        }

        if ($this->quantity !== null) {
            $payload['quantity'] = $this->quantity;
        }

        if ($this->value !== null) {
            $payload['amount'] = $this->value->minorUnits;
            $payload['currency'] = $this->value->currency;
        }

        if ($this->variantDict !== []) {
            $payload['variant_dict'] = $this->variantDict;
        }

        return $payload;
    }
}
