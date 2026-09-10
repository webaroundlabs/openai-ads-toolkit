<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Tests;

/**
 * Stand-ins for the WooCommerce objects the integration touches.
 *
 * Only the methods it actually calls. WooCommerce's real classes are enormous
 * and change between releases; depending on more of them here would make the
 * suite fragile without testing anything further.
 */
final class FakeWcProduct
{
    /**
     * @param array<string, string> $attributes
     */
    public function __construct(
        private readonly int $id,
        private readonly string $name = 'Product',
        private readonly string $price = '12.99',
        private readonly string $sku = '',
        private readonly string $type = 'simple',
        private readonly int $parentId = 0,
        private readonly array $attributes = [],
    ) {
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_name(): string
    {
        return $this->name;
    }

    public function get_price(): string
    {
        return $this->price;
    }

    public function get_sku(): string
    {
        return $this->sku;
    }

    public function is_type(string $type): bool
    {
        return $this->type === $type;
    }

    public function get_parent_id(): int
    {
        return $this->parentId;
    }

    /**
     * @return array<string, string>
     */
    public function get_attributes(): array
    {
        return $this->attributes;
    }
}

final class FakeWcOrderItem
{
    public function __construct(
        private readonly FakeWcProduct $product,
        private readonly int $quantity = 1,
        private readonly string $total = '12.99',
    ) {
    }

    public function get_product(): FakeWcProduct
    {
        return $this->product;
    }

    public function get_quantity(): int
    {
        return $this->quantity;
    }

    /** The line total after discounts, which the product's price does not reflect. */
    public function get_total(): string
    {
        return $this->total;
    }
}

final class FakeWcOrder
{
    /** @var array<string, mixed> */
    public array $meta = [];

    public int $saveCount = 0;

    /**
     * @param list<FakeWcOrderItem> $items
     * @param array<string, string> $billing
     */
    public function __construct(
        private readonly int $id,
        private readonly string $total = '25.98',
        private readonly string $currency = 'EUR',
        private readonly bool $paid = true,
        private readonly array $items = [],
        private readonly array $billing = [],
        private readonly int $customerId = 0,
    ) {
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_order_number(): string
    {
        return (string) $this->id;
    }

    public function get_total(): string
    {
        return $this->total;
    }

    public function get_currency(): string
    {
        return $this->currency;
    }

    public function is_paid(): bool
    {
        return $this->paid;
    }

    /**
     * @return list<FakeWcOrderItem>
     */
    public function get_items(): array
    {
        return $this->items;
    }

    public function get_meta(string $key): mixed
    {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    public function save(): int
    {
        ++$this->saveCount;

        return $this->id;
    }

    public function get_customer_id(): int
    {
        return $this->customerId;
    }

    public function get_billing_email(): string
    {
        return $this->billing['email'] ?? '';
    }

    public function get_billing_phone(): string
    {
        return $this->billing['phone'] ?? '';
    }

    public function get_billing_first_name(): string
    {
        return $this->billing['first_name'] ?? '';
    }

    public function get_billing_last_name(): string
    {
        return $this->billing['last_name'] ?? '';
    }

    public function get_billing_city(): string
    {
        return $this->billing['city'] ?? '';
    }

    public function get_billing_state(): string
    {
        return $this->billing['region'] ?? '';
    }

    public function get_billing_postcode(): string
    {
        return $this->billing['postal_code'] ?? '';
    }

    public function get_billing_country(): string
    {
        return $this->billing['country'] ?? '';
    }
}
