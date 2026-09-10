<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebaroundLabs\OpenAIAds\WordPress\Integrations\WooCommerce;
use WebaroundLabs\OpenAIAds\WordPress\Integrations\WooCommerce\Amount;
use WebaroundLabs\OpenAIAds\WordPress\Plugin;
use WebaroundLabs\OpenAIAds\WordPress\Settings;
use WooStubs;
use WpStubs;

#[CoversClass(WooCommerce::class)]
#[CoversClass(Amount::class)]
final class WooCommerceTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubs::reset();
        WooStubs::reset();
        WpStubs::$options[Settings::OPTION] = ['pixel_id' => 'px-1', 'capi_key' => 'secret'];
        Plugin::reset();
    }

    protected function tearDown(): void
    {
        Plugin::reset();
    }

    // ------------------------------------------------------------- amounts

    /**
     * How many minor units make a major one is a property of the CURRENCY, not
     * of the store's display settings. Using WooCommerce's decimal-places
     * setting would send 13 for a 12.99 euro order on a shop configured to show
     * whole euros, and 129900 for a 1299 yen order.
     */
    #[Test]
    #[DataProvider('currencyAmounts')]
    public function a_price_converts_to_the_currencys_own_minor_unit(
        string $amount,
        string $currency,
        int $expected,
    ): void {
        self::assertSame($expected, Amount::toMinorUnits($amount, $currency));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function currencyAmounts(): iterable
    {
        yield 'euros' => ['12.99', 'EUR', 1299];
        yield 'dollars' => ['1000.00', 'USD', 100000];
        yield 'yen have no minor unit' => ['1299', 'JPY', 1299];
        yield 'dinars have three places' => ['12.345', 'BHD', 12345];
        yield 'lowercase currency' => ['12.99', 'eur', 1299];
        yield 'free' => ['0', 'EUR', 0];
    }

    /**
     * Binary floating point makes (int) (0.29 * 100) equal 28, which would
     * under-report the cents of every order ending in one of the affected
     * values. Rounding first is what prevents that.
     */
    #[Test]
    public function cents_are_rounded_rather_than_truncated(): void
    {
        self::assertSame(29, Amount::toMinorUnits('0.29', 'EUR'));
        self::assertSame(1010, Amount::toMinorUnits('10.10', 'EUR'));
        self::assertSame(70, Amount::toMinorUnits('0.70', 'EUR'));
    }

    #[Test]
    public function an_unrecognizable_currency_yields_no_money_rather_than_a_wrong_one(): void
    {
        self::assertNull(Amount::money('12.99', 'EURO'));
    }

    // -------------------------------------------------------------- orders

    #[Test]
    public function a_paid_order_is_reported_with_its_total_and_line_items(): void
    {
        $woo = $this->woo();
        $order = $this->order(items: [
            new FakeWcOrderItem(new FakeWcProduct(10, 'Mug', '12.99', 'SKU-MUG'), 1, '12.99'),
            new FakeWcOrderItem(new FakeWcProduct(11, 'Tea', '6.50'), 2, '13.00'),
        ]);

        $woo->onOrderPaid($order->get_id());

        $sent = $this->lastEvent();
        self::assertSame('order_created', $sent['type']);
        self::assertSame(2598, $sent['data']['amount']);
        self::assertSame('EUR', $sent['data']['currency']);
        self::assertSame([
            ['id' => 'SKU-MUG', 'name' => 'Mug', 'content_type' => 'product', 'quantity' => 1, 'amount' => 1299, 'currency' => 'EUR'],
            ['id' => '11', 'name' => 'Tea', 'content_type' => 'product', 'quantity' => 2, 'amount' => 650, 'currency' => 'EUR'],
        ], $sent['data']['contents']);
    }

    #[Test]
    public function the_line_total_is_used_so_discounts_are_reflected(): void
    {
        $woo = $this->woo();
        // Listed at 12.99, sold at 10.00 after a coupon.
        $order = $this->order(total: '10.00', items: [
            new FakeWcOrderItem(new FakeWcProduct(10, 'Mug', '12.99'), 1, '10.00'),
        ]);

        $woo->onOrderPaid($order->get_id());

        self::assertSame(1000, $this->lastEvent()['data']['contents'][0]['amount']);
    }

    #[Test]
    public function a_variation_carries_its_parent_and_attributes(): void
    {
        $woo = $this->woo();
        $variation = new FakeWcProduct(
            id: 55,
            name: 'T-shirt - M / Blue',
            price: '20.00',
            sku: 'TSHIRT-M-BLUE',
            type: 'variation',
            parentId: 50,
            attributes: ['size' => 'M', 'colour' => 'blue'],
        );
        $order = $this->order(total: '20.00', items: [new FakeWcOrderItem($variation, 1, '20.00')]);

        $woo->onOrderPaid($order->get_id());

        $item = $this->lastEvent()['data']['contents'][0];
        self::assertSame('TSHIRT-M-BLUE', $item['id']);
        // Both are Conversions API only fields, which is why they belong here
        // and not in anything the Pixel is given.
        self::assertSame('50', $item['group_id']);
        self::assertSame(['size' => 'M', 'colour' => 'blue'], $item['variant_dict']);
    }

    #[Test]
    public function billing_details_become_hashed_identity(): void
    {
        $woo = $this->woo();
        $order = $this->order(billing: [
            'email' => ' Ada@Example.COM ',
            'phone' => '+40 746 123 456',
            'country' => 'RO',
        ], customerId: 42);

        $woo->onOrderPaid($order->get_id());

        $user = $this->lastEvent()['user'];
        self::assertSame(
            ['b5fc85e55755f9e0d030a10ab4429b6b2944855f9a0d60077fe832becbc41d72'],
            $user['emails_sha256'],
        );
        self::assertArrayHasKey('phone_numbers_sha256', $user);
        self::assertSame(['RO'], $user['countries']);
        self::assertArrayHasKey('external_ids_sha256', $user);
        self::assertStringNotContainsString('Ada@Example.COM', json_encode($user) ?: '');
    }

    // ------------------------------------------------------- the hard parts

    /**
     * The mistake most integrations make. woocommerce_thankyou fires for
     * pending, failed and cancelled orders too, so an unpaid order must produce
     * nothing at all - server-side or in the browser.
     */
    #[Test]
    public function an_unpaid_order_is_never_reported_as_a_purchase(): void
    {
        $woo = $this->woo();
        $order = $this->order(paid: false);

        $woo->onOrderPaid($order->get_id());

        self::assertSame([], WpStubs::$requests);
        self::assertNull(Plugin::instance()?->measurement()->flush());
        self::assertSame('', $order->get_meta(WooCommerce::TRACKED_META));
    }

    /**
     * Gateways differ: some call payment_complete(), others move the order
     * straight to a paid status, and a webhook may be redelivered. All of those
     * reach the same handler, so without the guard the same purchase would be
     * reported more than once.
     */
    #[Test]
    public function the_same_order_is_never_reported_twice(): void
    {
        $woo = $this->woo();
        $order = $this->order();

        $woo->onOrderPaid($order->get_id());   // woocommerce_payment_complete
        $woo->onOrderPaid($order->get_id());   // woocommerce_order_status_processing
        $woo->onOrderPaid($order->get_id());   // and later, completed

        $response = Plugin::instance()?->measurement()->flush();
        self::assertNotNull($response);

        $body = json_decode((string) WpStubs::$requests[0]['args']['body'], true);
        self::assertCount(1, $body['events'], 'The purchase must be reported exactly once.');
    }

    #[Test]
    public function the_order_number_becomes_the_deduplication_id_and_is_stored(): void
    {
        $woo = $this->woo();
        $order = $this->order();

        $woo->onOrderPaid($order->get_id());

        self::assertSame('wc_77', $this->lastEvent()['id']);
        self::assertSame('wc_77', $order->get_meta(WooCommerce::TRACKED_META));
        self::assertSame(1, $order->saveCount);
    }

    /**
     * The browser half must reuse the id the server already sent, or the
     * purchase is counted twice.
     */
    #[Test]
    public function the_thank_you_page_emits_the_same_id_the_server_used(): void
    {
        $woo = $this->woo();
        $order = $this->order();
        $woo->onOrderPaid($order->get_id());

        ob_start();
        $woo->onThankYou($order->get_id());
        $html = (string) ob_get_clean();

        self::assertStringContainsString('oaiq("measure"', $html);
        self::assertStringContainsString('order_created', $html);
        self::assertStringContainsString('wc_77', $html);
        self::assertStringContainsString('2598', $html);
    }

    #[Test]
    public function the_thank_you_page_emits_nothing_for_an_order_that_was_never_reported(): void
    {
        $woo = $this->woo();
        $order = $this->order(paid: false);

        ob_start();
        $woo->onThankYou($order->get_id());

        self::assertSame('', (string) ob_get_clean());
    }

    /**
     * Leaving the meta unset when nothing was recorded means a later, permitted
     * attempt can still report the order - rather than it being marked as done
     * when it never happened.
     */
    #[Test]
    public function an_order_is_not_marked_as_reported_when_consent_was_refused(): void
    {
        \add_filter('openai_ads_consent', static fn (): bool => false);
        $woo = $this->woo();
        $order = $this->order();

        $woo->onOrderPaid($order->get_id());

        self::assertSame('', $order->get_meta(WooCommerce::TRACKED_META));
        self::assertSame(0, $order->saveCount);
    }

    #[Test]
    public function an_unknown_order_id_does_nothing(): void
    {
        $this->woo()->onOrderPaid(999);

        self::assertNull(Plugin::instance()?->measurement()->flush());
    }

    #[Test]
    public function woocommerce_is_only_available_when_it_is_installed(): void
    {
        // The stub bootstrap does not define the WooCommerce class.
        self::assertFalse($this->woo()->isAvailable());
    }

    // ------------------------------------------------------------- helpers

    private function woo(): WooCommerce
    {
        return new WooCommerce(Plugin::boot(__FILE__, '0.1.0'));
    }

    /**
     * @param list<FakeWcOrderItem> $items
     * @param array<string, string> $billing
     */
    private function order(
        string $total = '25.98',
        bool $paid = true,
        array $items = [],
        array $billing = [],
        int $customerId = 0,
    ): FakeWcOrder {
        $order = new FakeWcOrder(77, $total, 'EUR', $paid, $items, $billing, $customerId);
        WooStubs::$orders[77] = $order;

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function lastEvent(): array
    {
        $response = Plugin::instance()?->measurement()->flush();
        self::assertNotNull($response, 'Nothing was queued.');

        $body = json_decode((string) WpStubs::$requests[0]['args']['body'], true);

        return $body['events'][0];
    }
}
