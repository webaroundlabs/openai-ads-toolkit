<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Admin;

use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\WordPress\EventBuilder;
use WebaroundLabs\OpenAIAds\WordPress\Integrations\Registry;
use WebaroundLabs\OpenAIAds\WordPress\Measurement;
use WebaroundLabs\OpenAIAds\WordPress\Settings;

/**
 * The settings screen.
 *
 * Capability checks and nonce verification are separate from sanitization, and
 * both are mandatory: one answers "is this person allowed to do this", the other
 * "is this input well-formed". Every value printed is escaped at the point of
 * output.
 */
final class SettingsPage
{
    public const SLUG = 'openai-ads';

    public const CAPABILITY = 'manage_options';

    public const TEST_ACTION = 'openai_ads_test_connection';

    public function __construct(
        private readonly Settings $settings,
        private readonly Measurement $measurement,
        private readonly EventBuilder $builder,
        private readonly ?Registry $integrations = null,
    ) {
    }

    public function register(): void
    {
        \add_action('admin_menu', [$this, 'addPage']);
        \add_action('admin_init', [$this, 'registerSettings']);
        \add_action('wp_ajax_' . self::TEST_ACTION, [$this, 'testConnection']);
    }

    public function addPage(): void
    {
        \add_options_page(
            \__('Conversion Tracking for OpenAI Ads', 'openai-ads'),
            \__('OpenAI Ads', 'openai-ads'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'render'],
        );
    }

    public function registerSettings(): void
    {
        \register_setting(self::SLUG, Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => [],
            // Not autoloaded: these options are needed on a handful of requests,
            // not on every page load.
            'show_in_rest' => false,
        ]);
    }

    /**
     * @param mixed $input
     *
     * @return array<string, mixed>
     */
    public function sanitize($input): array
    {
        if (!\current_user_can(self::CAPABILITY)) {
            return $this->settings->all();
        }

        $sanitized = $this->settings->sanitize(is_array($input) ? $input : []);
        $this->settings->forget();

        return $sanitized;
    }

    public function render(): void
    {
        if (!\current_user_can(self::CAPABILITY)) {
            \wp_die(\esc_html__('You do not have permission to manage these settings.', 'openai-ads'));
        }

        $s = $this->settings;
        $keyIsConstant = $s->capiKeyIsConstant();
        $hasKey = $s->capiKey() !== null;

        ?>
        <div class="wrap">
            <h1><?php echo \esc_html__('Conversion Tracking for OpenAI Ads', 'openai-ads'); ?></h1>

            <p class="description">
                <?php echo \esc_html__(
                    'An independent community integration. Not created, certified, endorsed or supported by OpenAI.',
                    'openai-ads',
                ); ?>
            </p>

            <form method="post" action="options.php">
                <?php \settings_fields(self::SLUG); ?>

                <h2><?php echo \esc_html__('Measurement Pixel', 'openai-ads'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo \esc_html__('Enable the Pixel', 'openai-ads'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo \esc_attr(Settings::OPTION); ?>[pixel_enabled]"
                                       value="1" <?php \checked($s->pixelEnabled()); ?>>
                                <?php echo \esc_html__('Load the Pixel in the site head.', 'openai-ads'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="openai-ads-pixel-id"><?php echo \esc_html__('Pixel ID', 'openai-ads'); ?></label>
                        </th>
                        <td>
                            <input id="openai-ads-pixel-id" type="text" class="regular-text"
                                   name="<?php echo \esc_attr(Settings::OPTION); ?>[pixel_id]"
                                   value="<?php echo \esc_attr((string) $s->pixelId()); ?>">
                            <p class="description">
                                <?php echo \esc_html__('Public. It appears in your pages, which is expected.', 'openai-ads'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php echo \esc_html__('Conversions API', 'openai-ads'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo \esc_html__('Enable server-side events', 'openai-ads'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo \esc_attr(Settings::OPTION); ?>[capi_enabled]"
                                       value="1" <?php \checked($s->capiEnabled()); ?>>
                                <?php echo \esc_html__('Send conversions from the server as well as the browser.', 'openai-ads'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="openai-ads-key"><?php echo \esc_html__('API key', 'openai-ads'); ?></label>
                        </th>
                        <td>
                            <?php if ($keyIsConstant) { ?>
                                <p>
                                    <strong><?php echo \esc_html__('Set in wp-config.php.', 'openai-ads'); ?></strong>
                                    <?php echo \esc_html__('This is the safer place for it, and it cannot be edited here.', 'openai-ads'); ?>
                                </p>
                            <?php } else { ?>
                                <input id="openai-ads-key" type="password" class="regular-text" autocomplete="off"
                                       name="<?php echo \esc_attr(Settings::OPTION); ?>[capi_key]"
                                       value=""
                                       placeholder="<?php echo $hasKey
                                            ? \esc_attr__('Saved. Leave blank to keep it.', 'openai-ads')
                                            : \esc_attr__('Paste your key', 'openai-ads'); ?>">
                                <p class="description">
                                    <?php echo \esc_html__(
                                        'Server-side only. Never rendered into a page. Define OPENAI_ADS_CAPI_KEY in wp-config.php to keep it out of the database entirely.',
                                        'openai-ads',
                                    ); ?>
                                </p>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo \esc_html__('Validation mode', 'openai-ads'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo \esc_attr(Settings::OPTION); ?>[validate_only]"
                                       value="1" <?php \checked($s->validateOnly()); ?>>
                                <?php echo \esc_html__('Check events against the API without recording them. Use on staging.', 'openai-ads'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php $available = $this->integrations?->available() ?? []; ?>
                <?php if ($available !== []) { ?>
                    <h2><?php echo \esc_html__('Integrations', 'openai-ads'); ?></h2>
                    <p class="description">
                        <?php echo \esc_html__(
                            'Detected on this site. Each one measures its own confirmed success boundary - a lead is recorded when the submission is accepted, not when the button is clicked.',
                            'openai-ads',
                        ); ?>
                    </p>
                    <table class="form-table" role="presentation">
                        <?php foreach ($available as $integration) { ?>
                            <tr>
                                <th scope="row"><?php echo \esc_html($integration->label()); ?></th>
                                <td>
                                    <?php /* States which integrations this form covers, so an
                                             unchecked box is recorded as off rather than
                                             falling back to the default. */ ?>
                                    <input type="hidden"
                                           name="<?php echo \esc_attr(Settings::OPTION); ?>[integrations_present][]"
                                           value="<?php echo \esc_attr($integration->id()); ?>">
                                    <label>
                                        <input type="checkbox"
                                               name="<?php echo \esc_attr(Settings::OPTION); ?>[integrations][<?php echo \esc_attr($integration->id()); ?>]"
                                               value="1" <?php \checked($this->settings->integrationEnabled($integration->id())); ?>>
                                        <?php echo \esc_html__('Measure conversions from this plugin.', 'openai-ads'); ?>
                                    </label>
                                </td>
                            </tr>
                        <?php } ?>
                    </table>
                <?php } ?>

                <h2><?php echo \esc_html__('Privacy and diagnostics', 'openai-ads'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo \esc_html__('Strip query strings', 'openai-ads'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo \esc_attr(Settings::OPTION); ?>[strip_query_string]"
                                       value="1" <?php \checked($s->stripQueryString()); ?>>
                                <?php echo \esc_html__(
                                    'Remove query strings from the page URL before sending it. Recommended: they often carry search terms, order keys and reset tokens.',
                                    'openai-ads',
                                ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="openai-ads-origin"><?php echo \esc_html__('Canonical origin', 'openai-ads'); ?></label>
                        </th>
                        <td>
                            <input id="openai-ads-origin" type="url" class="regular-text"
                                   name="<?php echo \esc_attr(Settings::OPTION); ?>[canonical_origin]"
                                   value="<?php echo \esc_attr((string) ($s->all()['canonical_origin'] ?? '')); ?>"
                                   placeholder="<?php echo \esc_attr((string) $s->canonicalOrigin()); ?>">
                        </td>
                    </tr>
                    <?php if (function_exists('as_enqueue_async_action')) { ?>
                        <tr>
                            <th scope="row"><?php echo \esc_html__('Deferred delivery', 'openai-ads'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo \esc_attr(Settings::OPTION); ?>[use_scheduler]"
                                           value="1" <?php \checked($s->useScheduler()); ?>>
                                    <?php echo \esc_html__(
                                        'Hand conversions to Action Scheduler so they survive the request that created them. Recommended. Turn off if this site has no working cron.',
                                        'openai-ads',
                                    ); ?>
                                </label>
                            </td>
                        </tr>
                    <?php } ?>
                    <tr>
                        <th scope="row"><?php echo \esc_html__('Debug logging', 'openai-ads'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo \esc_attr(Settings::OPTION); ?>[debug]"
                                       value="1" <?php \checked($s->debug()); ?>>
                                <?php echo \esc_html__('Write failures to the PHP error log. Payloads are never logged.', 'openai-ads'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php \submit_button(); ?>
            </form>

            <h2><?php echo \esc_html__('Test the connection', 'openai-ads'); ?></h2>
            <p class="description">
                <?php echo \esc_html__(
                    'Sends one event in the API\'s validation mode. Nothing is recorded, so this cannot create a fake conversion.',
                    'openai-ads',
                ); ?>
            </p>
            <p>
                <button type="button" class="button" id="openai-ads-test"><?php
                    echo \esc_html__('Send a test event', 'openai-ads');
        ?></button>
                <span id="openai-ads-test-result"></span>
            </p>
            <script>
            document.getElementById('openai-ads-test')?.addEventListener('click', function () {
                var out = document.getElementById('openai-ads-test-result');
                out.textContent = <?php echo \wp_json_encode(\__('Testing…', 'openai-ads')); ?>;
                var body = new FormData();
                body.append('action', <?php echo \wp_json_encode(self::TEST_ACTION); ?>);
                body.append('_wpnonce', <?php echo \wp_json_encode(\wp_create_nonce(self::TEST_ACTION)); ?>);
                fetch(<?php echo \wp_json_encode(\admin_url('admin-ajax.php')); ?>, {
                    method: 'POST', body: body, credentials: 'same-origin'
                })
                    .then(function (r) { return r.json(); })
                    .then(function (r) { out.textContent = r.data && r.data.message ? r.data.message : ''; })
                    .catch(function () { out.textContent = <?php echo \wp_json_encode(\__('The request failed.', 'openai-ads')); ?>; });
            });
            </script>
        </div>
        <?php
    }

    /**
     * Validate the credentials against the real API without recording anything.
     *
     * Uses the documented validation mode, which is the only way to prove an
     * integration works without inventing a conversion.
     */
    public function testConnection(): void
    {
        // Authorization and request authenticity, checked separately from input.
        if (!\current_user_can(self::CAPABILITY)) {
            \wp_send_json_error(['message' => \__('You are not allowed to do this.', 'openai-ads')], 403);
        }

        \check_ajax_referer(self::TEST_ACTION);

        if ($this->settings->pixelId() === null || $this->settings->capiKey() === null) {
            \wp_send_json_error([
                'message' => \__('Add a Pixel ID and an API key first.', 'openai-ads'),
            ]);
        }

        try {
            $event = $this->builder->build('page_viewed', [], [
                'event_id' => 'openai-ads-connection-test',
                'source_url' => \home_url('/'),
            ]);
        } catch (InvalidArgument $e) {
            // wp_send_json_* ends the request; there is nothing after it. A
            // defensive `return` here would be unreachable code pretending to
            // guard a state WordPress does not produce.
            \wp_send_json_error(['message' => \esc_html($e->getMessage())]);
        }

        $response = $this->measurement->sendNow(true, $event);

        if ($response === null) {
            \wp_send_json_error([
                'message' => \__('The API could not be reached. Check the site can make outbound requests.', 'openai-ads'),
            ]);
        }

        if ($response->isSuccessful()) {
            \wp_send_json_success([
                'message' => \__('Success. The credentials work and the event validated.', 'openai-ads'),
            ]);
        }

        \wp_send_json_error([
            'message' => sprintf(
                /* translators: %d: HTTP status code */
                \__('The API returned HTTP %d. Check the Pixel ID and API key.', 'openai-ads'),
                $response->statusCode,
            ),
        ]);
    }
}
