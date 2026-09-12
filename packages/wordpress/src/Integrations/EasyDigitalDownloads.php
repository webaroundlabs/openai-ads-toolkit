<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Integrations;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

use WebaroundLabs\OpenAIAds\Content;
use WebaroundLabs\OpenAIAds\WordPress\Plugin;

/**
 * Easy Digital Downloads.
 *
 * The boundary is `edd_complete_purchase`, which fires when a payment reaches a
 * complete status - after the gateway has settled, not when checkout begins and
 * not for a pending or failed order.
 *
 * The payment id becomes the event id, and a meta flag records that it was
 * reported. Both matter: EDD can complete a payment more than once - a gateway
 * IPN arriving twice, a status changed by hand and changed back - and each of
 * those reaches this hook.
 */
final class EasyDigitalDownloads implements Integration
{
    private const TRACKED_META = '_openai_ads_reported';

    public function __construct(
        private readonly Plugin $plugin,
    ) {
    }

    public function id(): string
    {
        return 'edd';
    }

    public function label(): string
    {
        return 'Easy Digital Downloads';
    }

    public function isAvailable(): bool
    {
        return class_exists('Easy_Digital_Downloads') || function_exists('edd_get_payment_amount');
    }

    public function register(): void
    {
        \add_action('edd_complete_purchase', [$this, 'onPurchaseComplete'], 10, 1);
    }

    /**
     * @param int|string $paymentId
     */
    public function onPurchaseComplete($paymentId): void
    {
        $paymentId = (int) $paymentId;

        if ($paymentId <= 0 || !function_exists('edd_get_payment_amount')) {
            return;
        }

        // Idempotency. An IPN can arrive twice and a status can be toggled by
        // hand; without this, each one is another purchase.
        if ((string) \get_post_meta($paymentId, self::TRACKED_META, true) !== '') {
            return;
        }

        $currency = function_exists('edd_get_payment_currency_code')
            ? (string) \edd_get_payment_currency_code($paymentId)
            : (function_exists('edd_get_currency') ? (string) \edd_get_currency() : 'USD');

        $total = Amount::money((string) \edd_get_payment_amount($paymentId), $currency);

        if ($total === null) {
            // An unrecognizable currency. Reporting an amount without one is
            // rejected by the API anyway, and inventing one would be worse.
            return;
        }

        $eventId = 'edd_' . $paymentId;

        $recorded = $this->plugin->track(
            'order_created',
            ['amount' => $total->minorUnits, 'currency' => $total->currency],
            [
                'event_id' => $eventId,
                'contents' => $this->contents($paymentId, $currency),
                'user' => $this->customer($paymentId),
            ],
        );

        if ($recorded) {
            \update_post_meta($paymentId, self::TRACKED_META, $eventId);
        }
    }

    /**
     * @return list<Content>
     */
    private function contents(int $paymentId, string $currency): array
    {
        if (!function_exists('edd_get_payment_meta_cart_details')) {
            return [];
        }

        /** @var mixed $cart */
        $cart = \edd_get_payment_meta_cart_details($paymentId, true);

        if (!is_array($cart)) {
            return [];
        }

        $contents = [];

        foreach ($cart as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }

            $contents[] = Content::create(
                id: (string) $item['id'],
                name: isset($item['name']) ? (string) $item['name'] : null,
                contentType: 'product',
                quantity: isset($item['quantity']) ? (int) $item['quantity'] : null,
                value: isset($item['price']) ? Amount::money((string) $item['price'], $currency) : null,
            );
        }

        return $contents;
    }

    /**
     * @return array<string, string>
     */
    private function customer(int $paymentId): array
    {
        if (!function_exists('edd_get_payment_meta_user_info')) {
            return [];
        }

        /** @var mixed $info */
        $info = \edd_get_payment_meta_user_info($paymentId);

        if (!is_array($info)) {
            return [];
        }

        $user = [];

        foreach (['email' => 'email', 'first_name' => 'first_name', 'last_name' => 'last_name'] as $key => $field) {
            $value = $info[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $user[$field] = $value;
            }
        }

        if (isset($info['id']) && (int) $info['id'] > 0) {
            $user['external_id'] = (string) $info['id'];
        }

        /** @var mixed $address */
        $address = $info['address'] ?? null;

        if (is_array($address)) {
            foreach (['country' => 'country', 'city' => 'city', 'state' => 'region', 'zip' => 'postal_code'] as $key => $field) {
                $value = $address[$key] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    $user[$field] = $value;
                }
            }
        }

        return $user;
    }
}
