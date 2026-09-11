<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Integrations;

use WebaroundLabs\OpenAIAds\WordPress\Plugin;

/**
 * WordPress account registration.
 *
 * `user_register` fires after the account row exists, which is the confirmed
 * boundary: a registration that failed validation never reaches it.
 *
 * The one integration whose host plugin is WordPress itself, so it is always
 * available - and therefore the one most worth being careful about. It is
 * **off by default**, because `user_register` fires for every account created by
 * any means: an administrator adding a colleague, a WooCommerce guest checkout
 * creating an account, an importer restoring a thousand users. None of those is
 * an ad conversion, and reporting them would quietly inflate the number the
 * advertiser optimizes against.
 *
 * What it does report, when switched on, is filtered to registrations that came
 * from a front-end request - not wp-admin, not WP-CLI, not a REST call from an
 * importer. A site with a more specific rule can refine it further through
 * `openai_ads_should_report_registration`.
 *
 * The user's id becomes the event id, so the browser half can use the same one
 * and a re-run of an importer cannot mint a second conversion for the same
 * person.
 */
final class UserRegistration implements Integration
{
    public function __construct(
        private readonly Plugin $plugin,
    ) {
    }

    public function id(): string
    {
        return 'user_registration';
    }

    public function label(): string
    {
        return 'WordPress registration';
    }

    /** WordPress is always present. Whether it should report is a separate question. */
    public function isAvailable(): bool
    {
        return true;
    }

    public function register(): void
    {
        \add_action('user_register', [$this, 'onUserRegistered'], 10, 1);
    }

    /**
     * @param int|string $userId
     */
    public function onUserRegistered($userId): void
    {
        $userId = (int) $userId;

        if ($userId <= 0 || !$this->isVisitorRegistration()) {
            return;
        }

        $user = \get_userdata($userId);
        $identity = [];

        if (is_object($user)) {
            foreach (['user_email' => 'email', 'first_name' => 'first_name', 'last_name' => 'last_name'] as $property => $field) {
                $value = $user->{$property} ?? null;

                if (is_string($value) && trim($value) !== '') {
                    $identity[$field] = $value;
                }
            }

            // The user id doubles as a stable pseudonymous identifier, which is
            // exactly what external_id is for. It is hashed like any other.
            $identity['external_id'] = (string) $userId;
        }

        $this->plugin->track('registration_completed', [], [
            // Stable and business-owned: re-running whatever created this
            // account reports the same conversion, not a second one.
            'event_id' => 'wp_user_' . $userId,
            'user' => $identity,
        ]);
    }

    /**
     * Whether this registration looks like a visitor signing up.
     *
     * Deliberately conservative. An administrator creating an account, WP-CLI
     * seeding users, or an importer restoring a backup are all `user_register`
     * too, and counting them as conversions corrupts the advertiser's data in
     * the direction nothing downstream flags.
     */
    private function isVisitorRegistration(): bool
    {
        $isVisitor = !\is_admin()
            && !(defined('WP_CLI') && \constant('WP_CLI'))
            && !(defined('REST_REQUEST') && \constant('REST_REQUEST'))
            && !(defined('DOING_CRON') && \constant('DOING_CRON'));

        /**
         * Refine which registrations count as conversions.
         *
         * @param bool $isVisitor Whether this looks like a front-end signup.
         */
        return (bool) \apply_filters('openai_ads_should_report_registration', $isVisitor);
    }
}
