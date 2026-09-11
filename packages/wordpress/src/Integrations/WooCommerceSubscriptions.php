<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Integrations;

use WebaroundLabs\OpenAIAds\WordPress\Plugin;

/**
 * WooCommerce Subscriptions.
 *
 * Two of the thirteen documented events exist only for this shape of business
 * and nothing else in the plugin reports them: `trial_started` when a
 * subscription begins in its free-trial period, and `subscription_created` when
 * a paid one activates.
 *
 * The boundary is `woocommerce_subscription_status_updated`, taken at the moment
 * a subscription becomes `active`. That is later than the order being paid, on
 * purpose - a subscription can be created pending and activate only once the
 * first payment settles, and a trial that never converts should not be reported
 * as a paid plan.
 *
 * Which of the two events is reported depends on whether the subscription is
 * inside a trial at that moment. A trial that later converts to a paid plan
 * produces a second, different event with its own id - that is correct: they are
 * two conversions, a trial start and a subscription start, and OpenAI documents
 * them separately.
 *
 * Both are idempotent on the subscription's own meta, because a status can
 * bounce - on hold, then active again - and each bounce reaches this hook.
 */
final class WooCommerceSubscriptions implements Integration
{
    private const TRIAL_META = '_openai_ads_trial_reported';

    private const SUBSCRIPTION_META = '_openai_ads_subscription_reported';

    public function __construct(
        private readonly Plugin $plugin,
    ) {
    }

    public function id(): string
    {
        return 'woocommerce_subscriptions';
    }

    public function label(): string
    {
        return 'WooCommerce Subscriptions';
    }

    public function isAvailable(): bool
    {
        return class_exists('WC_Subscriptions');
    }

    public function register(): void
    {
        \add_action('woocommerce_subscription_status_updated', [$this, 'onStatusUpdated'], 10, 3);
    }

    /**
     * @param object $subscription WC_Subscription
     * @param string $newStatus
     * @param string $oldStatus
     */
    public function onStatusUpdated(object $subscription, $newStatus = '', $oldStatus = ''): void
    {
        if ((string) $newStatus !== 'active') {
            return;
        }

        if (!method_exists($subscription, 'get_id') || !method_exists($subscription, 'get_meta')) {
            return;
        }

        $inTrial = $this->isInTrial($subscription);
        $meta = $inTrial ? self::TRIAL_META : self::SUBSCRIPTION_META;

        if ((string) $subscription->get_meta($meta) !== '') {
            return;
        }

        $id = (int) $subscription->get_id();
        $eventId = ($inTrial ? 'wcs_trial_' : 'wcs_') . $id;
        $currency = method_exists($subscription, 'get_currency')
            ? (string) $subscription->get_currency()
            : '';

        $data = [];

        // A trial is worth nothing yet, and reporting the eventual plan price as
        // its value would overstate every trial that never converts.
        if (!$inTrial && method_exists($subscription, 'get_total')) {
            $total = Amount::money((string) $subscription->get_total(), $currency);

            if ($total !== null) {
                $data = ['amount' => $total->minorUnits, 'currency' => $total->currency];
            }
        }

        $recorded = $this->plugin->track(
            $inTrial ? 'trial_started' : 'subscription_created',
            $data,
            [
                'event_id' => $eventId,
                'plan_id' => $this->planId($subscription),
                'user' => $this->customer($subscription),
            ],
        );

        if ($recorded && method_exists($subscription, 'update_meta_data') && method_exists($subscription, 'save')) {
            $subscription->update_meta_data($meta, $eventId);
            $subscription->save();
        }
    }

    /**
     * Whether the subscription is still inside its free-trial period.
     *
     * `get_date('trial_end')` returns an empty string when there is no trial,
     * which is the case for most subscriptions.
     */
    private function isInTrial(object $subscription): bool
    {
        if (!method_exists($subscription, 'get_date')) {
            return false;
        }

        $trialEnd = (string) $subscription->get_date('trial_end');

        return $trialEnd !== '' && strtotime($trialEnd) > time();
    }

    /**
     * The subscribed product, which is what `plan_id` means here.
     *
     * A subscription can contain several products. Only the first is reported,
     * because `plan_id` is a single value and picking one deterministically is
     * better than picking one at random.
     */
    private function planId(object $subscription): ?string
    {
        if (!method_exists($subscription, 'get_items')) {
            return null;
        }

        /** @var mixed $items */
        $items = $subscription->get_items();

        if (!is_iterable($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (is_object($item) && method_exists($item, 'get_product_id')) {
                $productId = (int) $item->get_product_id();

                if ($productId > 0) {
                    return (string) $productId;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function customer(object $subscription): array
    {
        $user = [];

        $map = [
            'get_billing_email' => 'email',
            'get_billing_phone' => 'phone',
            'get_billing_first_name' => 'first_name',
            'get_billing_last_name' => 'last_name',
            'get_billing_country' => 'country',
            'get_billing_city' => 'city',
            'get_billing_state' => 'region',
            'get_billing_postcode' => 'postal_code',
        ];

        foreach ($map as $method => $field) {
            if (!method_exists($subscription, $method)) {
                continue;
            }

            $value = $subscription->{$method}();

            if (is_string($value) && trim($value) !== '') {
                $user[$field] = $value;
            }
        }

        if (method_exists($subscription, 'get_customer_id')) {
            $customerId = (int) $subscription->get_customer_id();

            if ($customerId > 0) {
                $user['external_id'] = (string) $customerId;
            }
        }

        return $user;
    }
}
