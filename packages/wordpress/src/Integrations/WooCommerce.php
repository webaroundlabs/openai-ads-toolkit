<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Integrations;

use WebaroundLabs\OpenAIAds\Content;
use WebaroundLabs\OpenAIAds\WordPress\Plugin;

/**
 * WooCommerce.
 *
 * Four events, each fired at a boundary WooCommerce genuinely confirms:
 *
 * | Event            | Boundary                                             |
 * |------------------|------------------------------------------------------|
 * | contents_viewed  | a single product page is rendered                    |
 * | items_added      | the cart mutation succeeded                          |
 * | checkout_started | the checkout form is reached                          |
 * | order_created    | payment completed, or the order reached a paid status |
 *
 * The last one is the one that matters and the one most integrations get wrong.
 * `woocommerce_thankyou` fires for pending, failed and cancelled orders too, so
 * hooking it blindly reports conversions that were never paid for. This
 * integration hooks payment completion and paid status transitions instead, and
 * records the order id on the order itself so a second transition - or a
 * refresh, or a webhook retry - cannot report the same purchase twice.
 */
final class WooCommerce implements Integration
{
    /** Marks an order as already reported, and stores the id used. */
    public const TRACKED_META = '_openai_ads_event_id';

    public function __construct(
        private readonly Plugin $plugin,
    ) {
    }

    public function id(): string
    {
        return 'woocommerce';
    }

    public function label(): string
    {
        return 'WooCommerce';
    }

    public function isAvailable(): bool
    {
        return class_exists('WooCommerce');
    }

    public function register(): void
    {
        \add_action('woocommerce_after_single_product_summary', [$this, 'onProductViewed'], 5);
        \add_action('woocommerce_add_to_cart', [$this, 'onAddToCart'], 10, 6);
        \add_action('woocommerce_before_checkout_form', [$this, 'onCheckoutStarted'], 5);

        // Payment confirmed. Both hooks are registered because gateways differ:
        // some call payment_complete(), others move the order straight to a paid
        // status. The order meta guard makes the overlap harmless.
        \add_action('woocommerce_payment_complete', [$this, 'onOrderPaid'], 10, 1);
        \add_action('woocommerce_order_status_processing', [$this, 'onOrderPaid'], 10, 1);
        \add_action('woocommerce_order_status_completed', [$this, 'onOrderPaid'], 10, 1);

        // The browser half, on the order-received page.
        \add_action('woocommerce_thankyou', [$this, 'onThankYou'], 20, 1);
    }

    public function onProductViewed(): void
    {
        $product = $this->currentProduct();

        if ($product === null) {
            return;
        }

        $currency = $this->currency();
        $content = $this->contentFor($product, 1, $currency);

        $this->plugin->track('contents_viewed', $this->dataFor(
            $content?->value?->minorUnits,
            $currency,
        ), [
            'contents' => $content !== null ? [$content] : [],
        ]);
    }

    /**
     * Fires after the cart mutation has succeeded, which covers every add path -
     * a product card button, the product page, and a quantity increase that
     * creates or extends a line.
     */
    public function onAddToCart(
        string $cartItemKey = '',
        int $productId = 0,
        int $quantity = 0,
        int $variationId = 0,
        mixed $variation = null,
        mixed $cartItemData = null,
    ): void {
        $product = $this->product($variationId > 0 ? $variationId : $productId);

        if ($product === null) {
            return;
        }

        $currency = $this->currency();
        $content = $this->contentFor($product, max(1, $quantity), $currency);

        if ($content === null) {
            return;
        }

        $lineTotal = $content->value !== null ? $content->value->minorUnits * max(1, $quantity) : null;

        $this->plugin->track('items_added', $this->dataFor($lineTotal, $currency), [
            'contents' => [$content],
        ]);
    }

    public function onCheckoutStarted(): void
    {
        $cart = $this->cart();

        if ($cart === null || $cart->is_empty()) {
            return;
        }

        $currency = $this->currency();
        $contents = [];

        /** @var array<string, array<string, mixed>> $items */
        $items = $cart->get_cart();

        foreach ($items as $item) {
            $product = $item['data'] ?? null;

            if (!is_object($product)) {
                continue;
            }

            $content = $this->contentFor($product, (int) ($item['quantity'] ?? 1), $currency);

            if ($content !== null) {
                $contents[] = $content;
            }
        }

        $total = Amount::toMinorUnits((string) $cart->get_total('edit'), $currency);

        $this->plugin->track('checkout_started', $this->dataFor($total, $currency), [
            'contents' => $contents,
        ]);
    }

    /**
     * The purchase, reported once.
     *
     * @param int|string $orderId
     */
    public function onOrderPaid($orderId): void
    {
        $order = $this->order((int) $orderId);

        if ($order === null) {
            return;
        }

        // Idempotency. Several gateways trigger more than one of the hooks
        // above, a webhook may be redelivered, and an order can move from
        // processing to completed later. Any of those would otherwise report the
        // same purchase again.
        if ((string) $order->get_meta(self::TRACKED_META) !== '') {
            return;
        }

        if (!$this->isPaid($order)) {
            return;
        }

        // The order number is a stable business identifier the browser can be
        // told, which is exactly what the documentation recommends over a minted
        // id - and it makes the browser half trivially matchable.
        $eventId = 'wc_' . (string) $order->get_order_number();
        $currency = (string) $order->get_currency();

        $recorded = $this->plugin->track(
            'order_created',
            $this->dataFor(Amount::toMinorUnits((string) $order->get_total(), $currency), $currency),
            [
                'event_id' => $eventId,
                'contents' => $this->orderContents($order, $currency),
                'user' => $this->customer($order),
            ],
        );

        if (!$recorded) {
            // Not recorded means measurement is off, unconfigured, or consent
            // was refused. Leaving the meta unset lets a later, permitted
            // attempt still report it.
            return;
        }

        $order->update_meta_data(self::TRACKED_META, $eventId);
        $order->save();
    }

    /**
     * The browser half, on the order-received page.
     *
     * Only for an order that was actually reported server-side, using the very
     * same id - so the two are matched instead of counted twice. An unpaid order
     * has no stored id and therefore emits nothing.
     *
     * @param int|string $orderId
     */
    public function onThankYou($orderId): void
    {
        $order = $this->order((int) $orderId);

        if ($order === null) {
            return;
        }

        $eventId = (string) $order->get_meta(self::TRACKED_META);

        if ($eventId === '') {
            return;
        }

        $currency = (string) $order->get_currency();

        $this->plugin->pixel()->renderEvent('order_created', $eventId, [
            'type' => 'contents',
            'amount' => Amount::toMinorUnits((string) $order->get_total(), $currency),
            'currency' => strtoupper($currency),
        ]);
    }

    /**
     * Whether the order has actually been paid for.
     *
     * A refunded or cancelled order that happens to pass through a paid status
     * still counted as a sale at the moment it was paid, so `is_paid()` is the
     * right question - not the current status.
     */
    /** @param \WC_Order $order */
    private function isPaid(object $order): bool
    {
        if (method_exists($order, 'is_paid')) {
            return (bool) $order->is_paid();
        }

        return method_exists($order, 'has_status')
            && (bool) $order->has_status(['processing', 'completed']);
    }

    /**
     * @param \WC_Order $order
     *
     * @return list<Content>
     */
    private function orderContents(object $order, string $currency): array
    {
        if (!method_exists($order, 'get_items')) {
            return [];
        }

        $contents = [];

        /** @var iterable<object> $items */
        $items = $order->get_items();

        foreach ($items as $item) {
            if (!method_exists($item, 'get_product')) {
                continue;
            }

            $product = $item->get_product();

            if (!is_object($product)) {
                continue;
            }

            $quantity = method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 1;
            $unit = null;

            if (method_exists($item, 'get_total') && $quantity > 0) {
                // The line total already reflects discounts, which the product's
                // list price does not.
                $unit = ((float) $item->get_total()) / $quantity;
            }

            $content = $this->contentFor($product, $quantity, $currency, $unit);

            if ($content !== null) {
                $contents[] = $content;
            }
        }

        return $contents;
    }

    /**
     * Build a line item, using the Conversions API's variation fields where the
     * product actually is one.
     */
    private function contentFor(
        object $product,
        int $quantity,
        string $currency,
        string|float|int|null $unitPrice = null,
    ): ?Content {
        if (!method_exists($product, 'get_id')) {
            return null;
        }

        $price = $unitPrice ?? (method_exists($product, 'get_price') ? $product->get_price() : null);
        $value = $price === null || $price === '' ? null : Amount::money($price, $currency);

        $groupId = null;
        $variantDict = [];

        if (method_exists($product, 'is_type') && $product->is_type('variation')) {
            if (method_exists($product, 'get_parent_id')) {
                $groupId = (string) $product->get_parent_id();
            }

            if (method_exists($product, 'get_attributes')) {
                /** @var array<string, mixed> $attributes */
                $attributes = $product->get_attributes();

                foreach ($attributes as $key => $attributeValue) {
                    if (is_scalar($attributeValue) && (string) $attributeValue !== '') {
                        $variantDict[(string) $key] = (string) $attributeValue;
                    }
                }
            }
        }

        // The SKU is what a merchant recognizes in a feed; the numeric id is the
        // fallback when no SKU is set.
        $sku = method_exists($product, 'get_sku') ? (string) $product->get_sku() : '';
        $id = $sku !== '' ? $sku : (string) $product->get_id();

        return Content::create(
            id: $id,
            groupId: $groupId,
            name: method_exists($product, 'get_name') ? (string) $product->get_name() : null,
            contentType: 'product',
            quantity: $quantity,
            value: $value,
            variantDict: $variantDict,
        );
    }

    /**
     * Identity from the order's billing details, hashed downstream.
     *
     * @param \WC_Order $order
     *
     * @return array<string, string>
     */
    private function customer(object $order): array
    {
        $user = [];

        $map = [
            'email' => 'get_billing_email',
            'phone' => 'get_billing_phone',
            'first_name' => 'get_billing_first_name',
            'last_name' => 'get_billing_last_name',
            'city' => 'get_billing_city',
            'region' => 'get_billing_state',
            'postal_code' => 'get_billing_postcode',
            'country' => 'get_billing_country',
        ];

        foreach ($map as $field => $method) {
            if (!method_exists($order, $method)) {
                continue;
            }

            $value = trim((string) $order->{$method}());

            if ($value !== '') {
                $user[$field] = $value;
            }
        }

        if (method_exists($order, 'get_customer_id')) {
            $customerId = (int) $order->get_customer_id();

            if ($customerId > 0) {
                $user['external_id'] = (string) $customerId;
            }
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function dataFor(?int $amount, string $currency): array
    {
        if ($amount === null || preg_match('/^[A-Za-z]{3}$/', $currency) !== 1) {
            return [];
        }

        return ['amount' => $amount, 'currency' => strtoupper($currency)];
    }

    private function currency(): string
    {
        return function_exists('get_woocommerce_currency')
            ? (string) \get_woocommerce_currency()
            : 'USD';
    }

    /** @return \WC_Product|null */
    private function currentProduct(): ?object
    {
        if (!function_exists('is_product') || !\is_product()) {
            return null;
        }

        return $this->product(0);
    }

    /** @return \WC_Product|null */
    private function product(int $id): ?object
    {
        if (!function_exists('wc_get_product')) {
            return null;
        }

        // false, not null: WC_Product_Factory::get_product_id() compares with
        // `false ===` when deciding whether the caller means "the product this
        // page is about". Anything else - null included - falls through every
        // branch and comes back as false, so a null here would silently stop
        // contents_viewed from ever firing on a product page.
        $product = \wc_get_product($id > 0 ? $id : false);

        return $product instanceof \WC_Product ? $product : null;
    }

    /** @return \WC_Cart|null */
    private function cart(): ?object
    {
        if (!function_exists('WC')) {
            return null;
        }

        $cart = \WC()->cart;

        return $cart instanceof \WC_Cart ? $cart : null;
    }

    /** @return \WC_Order|null */
    private function order(int $id): ?object
    {
        if ($id <= 0 || !function_exists('wc_get_order')) {
            return null;
        }

        $order = \wc_get_order($id);

        // instanceof rather than method_exists: wc_get_order() also returns a
        // WC_Order_Refund, which carries get_total() and would have been
        // reported as a purchase.
        return $order instanceof \WC_Order ? $order : null;
    }
}
