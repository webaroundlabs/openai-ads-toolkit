<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Integrations;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

use WebaroundLabs\OpenAIAds\WordPress\Plugin;

/**
 * Records a form conversion and produces the payload the browser needs to
 * report the same conversion once.
 *
 * Shared by every form integration, because the hard parts are identical
 * whichever plugin collected the submission: mint one event id, send the server
 * event with it, and hand that same id back so the browser can fire the Pixel
 * event that matches rather than duplicates.
 *
 * Only the field extraction differs per host, and that stays in each
 * integration where it belongs.
 */
final class LeadRecorder
{
    public function __construct(
        private readonly Plugin $plugin,
    ) {
    }

    /**
     * Record a successful form submission.
     *
     * @param array<string, string> $user       raw identity, hashed downstream
     * @param string                $formId     the host's identifier for the form
     * @param string                $formName   for filters and debugging
     * @param string                $source     integration id, for filters
     *
     * @return array<string, mixed>|null payload for the browser bridge, or null when
     *                                   nothing was recorded
     */
    public function record(array $user, string $formId, string $formName, string $source): ?array
    {
        $plugin = $this->plugin;

        /**
         * Let a site map a particular form to different event semantics - a
         * quote request to `lead_created`, a webinar sign-up to
         * `registration_completed`, a booking form to `appointment_scheduled`.
         *
         * @param string $eventName The event this submission will be reported as.
         * @param string $formId    The host plugin's identifier for the form.
         * @param string $source    Which integration is reporting - "cf7", "elementor".
         * @param string $formName  The form's title, where it has one.
         */
        $eventName = (string) \apply_filters(
            'openai_ads_form_event',
            'lead_created',
            $formId,
            $source,
            $formName,
        );

        /**
         * Full control over the identity sent for this submission, for forms
         * whose field names the automatic extraction cannot recognize.
         *
         * @param array<string, string> $user     Identity extracted from the submission.
         * @param string                $formId   The host plugin's identifier for the form.
         * @param string                $source   Which integration is reporting.
         * @param string                $formName The form's title, where it has one.
         */
        $user = \apply_filters('openai_ads_form_user_data', $user, $formId, $source, $formName);

        if (!is_array($user)) {
            $user = [];
        }

        // No business record exists at this point - a form submission is not an
        // order - so an id is minted here and used on BOTH sides. It must not be
        // regenerated: a second id is a second conversion.
        $eventId = \wp_generate_uuid4();

        $options = [
            'event_id' => $eventId,
            'user' => $user,
        ];

        $customEventName = null;

        if ($eventName === 'custom') {
            $customEventName = (string) \apply_filters(
                'openai_ads_form_custom_event_name',
                'form_submitted',
                $formId,
                $source,
            );
            $options['custom_event_name'] = $customEventName;
        }

        if (!$plugin->track($eventName, [], $options)) {
            return null;
        }

        $payload = [
            'event' => $eventName,
            'event_id' => $eventId,
            'data' => ['type' => $this->dataShapeFor($eventName)],
        ];

        if ($customEventName !== null) {
            $payload['custom_event_name'] = $customEventName;
        }

        return $payload;
    }

    /**
     * Pull identity out of a set of typed form fields.
     *
     * Email and phone are taken from the field TYPE, which the form builder
     * already knows and which cannot be misread. Names are only taken when a
     * field is explicitly named for one - guessing that a single "name" field
     * splits cleanly into first and last is wrong often enough that sending it
     * would degrade matching rather than improve it. Sites with such a form can
     * map it through `openai_ads_form_user_data`.
     *
     * @param list<array{type: string, name: string, value: string}> $fields
     *
     * @return array<string, string>
     */
    public static function identityFromFields(array $fields): array
    {
        $user = [];

        foreach ($fields as $field) {
            $value = trim($field['value']);

            if ($value === '') {
                continue;
            }

            $type = strtolower($field['type']);
            $name = strtolower($field['name']);

            if ($type === 'email' && !isset($user['email'])) {
                $user['email'] = $value;

                continue;
            }

            // Form builders spell the same field type differently - Contact
            // Form 7 and Elementor use the HTML input type, Gravity Forms and
            // WPForms use their own name for it.
            if (in_array($type, ['tel', 'phone'], true) && !isset($user['phone'])) {
                $user['phone'] = $value;

                continue;
            }

            if (!isset($user['first_name']) && preg_match('/first[\s_-]*name/', $name) === 1) {
                $user['first_name'] = $value;

                continue;
            }

            if (!isset($user['last_name']) && preg_match('/(last|sur)[\s_-]*name/', $name) === 1) {
                $user['last_name'] = $value;
            }
        }

        return $user;
    }

    private function dataShapeFor(string $eventName): string
    {
        return match ($eventName) {
            'page_viewed', 'contents_viewed', 'items_added', 'checkout_started', 'order_created' => 'contents',
            'subscription_created', 'trial_started' => 'plan_enrollment',
            'custom' => 'custom',
            default => 'customer_action',
        };
    }
}
