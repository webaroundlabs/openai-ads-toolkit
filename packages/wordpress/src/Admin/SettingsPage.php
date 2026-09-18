<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Admin;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\WordPress\Consent;
use WebaroundLabs\OpenAIAds\WordPress\EventBuilder;
use WebaroundLabs\OpenAIAds\WordPress\Http\Ingest;
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
    public const SLUG = 'conversion-tracking-for-openai-ads';

    public const INTEGRATIONS_SLUG = self::SLUG . '-integrations';

    public const TAG_MANAGER_SLUG = self::SLUG . '-tag-manager';

    public const CAPABILITY = 'manage_options';

    public const TEST_ACTION = 'openai_ads_test_connection';

    private ?Consent $consentService = null;

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
        /*
         * A top-level menu rather than a child of Settings. The handbook
         * recommends Settings for a plugin with a single option page, and this
         * one has three screens plus a live log of what the endpoint received -
         * things a site owner comes back to when the figures look wrong, not
         * settings they fill in once. Measurement plugins are also looked for in
         * the sidebar, and one filed under Settings is one nobody finds.
         *
         * The position is a float on purpose. WordPress keys the menu by
         * position, so two plugins picking the same integer means one of them
         * silently replaces the other; a fractional one has no such neighbour.
         * 26.7 puts this just under Comments, in the group people actually look
         * at, rather than below the fold with the rest of the plugins.
         */
        \add_menu_page(
            \__('Conversion Tracking for OpenAI Ads', 'conversion-tracking-for-openai-ads'),
            \__('OpenAI Ads', 'conversion-tracking-for-openai-ads'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderGeneral'],
            self::menuIcon(),
            26.7,
        );

        /*
         * Registering the parent again as its own first child is what stops
         * WordPress repeating the plugin's full name as the first submenu item.
         */
        \add_submenu_page(
            self::SLUG,
            \__('Conversion Tracking for OpenAI Ads', 'conversion-tracking-for-openai-ads'),
            \__('General', 'conversion-tracking-for-openai-ads'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderGeneral'],
        );

        \add_submenu_page(
            self::SLUG,
            \__('Integrations and consent', 'conversion-tracking-for-openai-ads'),
            \__('Integrations', 'conversion-tracking-for-openai-ads'),
            self::CAPABILITY,
            self::INTEGRATIONS_SLUG,
            [$this, 'renderIntegrations'],
        );

        \add_submenu_page(
            self::SLUG,
            \__('Tag manager endpoint', 'conversion-tracking-for-openai-ads'),
            \__('Tag manager', 'conversion-tracking-for-openai-ads'),
            self::CAPABILITY,
            self::TAG_MANAGER_SLUG,
            [$this, 'renderTagManager'],
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

    /** Credentials, what is measured, and what is trimmed before it is sent. */
    public function renderGeneral(): void
    {
        $this->guard();

        $s = $this->settings;
        $keyIsConstant = $s->capiKeyIsConstant();
        $hasKey = $s->capiKey() !== null;

        // Declared so sanitize() knows this form covers them - see
        // Settings::FIELDS_PRESENT. The two conditional ones are declared only
        // where they are actually rendered.
        $fields = [
            'pixel_enabled', 'pixel_id', 'capi_enabled', 'validate_only',
            'strip_query_string', 'canonical_origin', 'debug',
        ];

        if (!$keyIsConstant) {
            $fields[] = 'capi_key';
        }

        if (function_exists('as_enqueue_async_action')) {
            $fields[] = 'use_scheduler';
        }

        ?>
        <div class="wrap">
            <?php
            $this->pageIntro(\__('Conversion Tracking for OpenAI Ads', 'conversion-tracking-for-openai-ads'));

        // The one thing a site owner must not have to go looking for.
        $this->renderUngatedNotice();
        ?>

            <form method="post" action="options.php">
                <?php
                \settings_fields(self::SLUG);
        $this->declareFields($fields);
        ?>

                <h2><?php echo \esc_html__('Measurement Pixel', 'conversion-tracking-for-openai-ads'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo \esc_html__('Enable the Pixel', 'conversion-tracking-for-openai-ads'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo \esc_attr(Settings::OPTION); ?>[pixel_enabled]"
                                       value="1" <?php \checked($s->pixelEnabled()); ?>>
                                <?php echo \esc_html__('Load the Pixel in the site head.', 'conversion-tracking-for-openai-ads'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="openai-ads-pixel-id"><?php echo \esc_html__('Pixel ID', 'conversion-tracking-for-openai-ads'); ?></label>
                        </th>
                        <td>
                            <input id="openai-ads-pixel-id" type="text" class="regular-text"
                                   name="<?php echo \esc_attr(Settings::OPTION); ?>[pixel_id]"
                                   value="<?php echo \esc_attr((string) $s->pixelId()); ?>">
                            <p class="description">
                                <?php echo \esc_html__('Public. It appears in your pages, which is expected.', 'conversion-tracking-for-openai-ads'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php echo \esc_html__('Conversions API', 'conversion-tracking-for-openai-ads'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo \esc_html__('Enable server-side events', 'conversion-tracking-for-openai-ads'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo \esc_attr(Settings::OPTION); ?>[capi_enabled]"
                                       value="1" <?php \checked($s->capiEnabled()); ?>>
                                <?php echo \esc_html__('Send conversions from the server as well as the browser.', 'conversion-tracking-for-openai-ads'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="openai-ads-key"><?php echo \esc_html__('API key', 'conversion-tracking-for-openai-ads'); ?></label>
                        </th>
                        <td>
                            <?php if ($keyIsConstant) { ?>
                                <p>
                                    <strong><?php echo \esc_html__('Set in wp-config.php.', 'conversion-tracking-for-openai-ads'); ?></strong>
                                    <?php echo \esc_html__('This is the safer place for it, and it cannot be edited here.', 'conversion-tracking-for-openai-ads'); ?>
                                </p>
                            <?php } else { ?>
                                <input id="openai-ads-key" type="password" class="regular-text" autocomplete="off"
                                       name="<?php echo \esc_attr(Settings::OPTION); ?>[capi_key]"
                                       value=""
                                       placeholder="<?php echo $hasKey
                                            ? \esc_attr__('Saved. Leave blank to keep it.', 'conversion-tracking-for-openai-ads')
                                            : \esc_attr__('Paste your key', 'conversion-tracking-for-openai-ads'); ?>">
                                <p class="description">
                                    <?php echo \esc_html__(
                                        'Server-side only. Never rendered into a page, never written to a log.',
                                        'conversion-tracking-for-openai-ads',
                                    ); ?>
                                </p>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo \esc_html__('Validation mode', 'conversion-tracking-for-openai-ads'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo \esc_attr(Settings::OPTION); ?>[validate_only]"
                                       value="1" <?php \checked($s->validateOnly()); ?>>
                                <?php echo \esc_html__('Check events against the API without recording them. Use on staging.', 'conversion-tracking-for-openai-ads'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <h2><?php echo \esc_html__('Privacy and diagnostics', 'conversion-tracking-for-openai-ads'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo \esc_html__('Strip query strings', 'conversion-tracking-for-openai-ads'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo \esc_attr(Settings::OPTION); ?>[strip_query_string]"
                                       value="1" <?php \checked($s->stripQueryString()); ?>>
                                <?php echo \esc_html__(
                                    'Remove query strings from the page URL before sending it. Recommended: they often carry search terms, order keys and reset tokens.',
                                    'conversion-tracking-for-openai-ads',
                                ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="openai-ads-origin"><?php echo \esc_html__('Canonical origin', 'conversion-tracking-for-openai-ads'); ?></label>
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
                            <th scope="row"><?php echo \esc_html__('Deferred delivery', 'conversion-tracking-for-openai-ads'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo \esc_attr(Settings::OPTION); ?>[use_scheduler]"
                                           value="1" <?php \checked($s->useScheduler()); ?>>
                                    <?php echo \esc_html__(
                                        'Hand conversions to Action Scheduler so they survive the request that created them. Recommended. Turn off if this site has no working cron.',
                                        'conversion-tracking-for-openai-ads',
                                    ); ?>
                                </label>
                            </td>
                        </tr>
                    <?php } ?>
                    <tr>
                        <th scope="row"><?php echo \esc_html__('Debug logging', 'conversion-tracking-for-openai-ads'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo \esc_attr(Settings::OPTION); ?>[debug]"
                                       value="1" <?php \checked($s->debug()); ?>>
                                <?php echo \esc_html__('Write failures to the PHP error log. Payloads are never logged.', 'conversion-tracking-for-openai-ads'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php \submit_button(); ?>
            </form>

            <h2><?php echo \esc_html__('Test the connection', 'conversion-tracking-for-openai-ads'); ?></h2>
            <p class="description">
                <?php echo \esc_html__(
                    'Sends one event in the API\'s validation mode. Nothing is recorded, so this cannot create a fake conversion.',
                    'conversion-tracking-for-openai-ads',
                ); ?>
            </p>
            <p>
                <button type="button" class="button" id="openai-ads-test"><?php
                    echo \esc_html__('Send a test event', 'conversion-tracking-for-openai-ads');
        ?></button>
                <span id="openai-ads-test-result"></span>
            </p>
            <script>
            document.getElementById('openai-ads-test')?.addEventListener('click', function () {
                var out = document.getElementById('openai-ads-test-result');
                out.textContent = <?php echo \wp_json_encode(\__('Testing…', 'conversion-tracking-for-openai-ads')); ?>;
                var body = new FormData();
                body.append('action', <?php echo \wp_json_encode(self::TEST_ACTION); ?>);
                body.append('_wpnonce', <?php echo \wp_json_encode(\wp_create_nonce(self::TEST_ACTION)); ?>);
                fetch(<?php echo \wp_json_encode(\admin_url('admin-ajax.php')); ?>, {
                    method: 'POST', body: body, credentials: 'same-origin'
                })
                    .then(function (r) { return r.json(); })
                    .then(function (r) { out.textContent = r.data && r.data.message ? r.data.message : ''; })
                    .catch(function () { out.textContent = <?php echo \wp_json_encode(\__('The request failed.', 'conversion-tracking-for-openai-ads')); ?>; });
            });
            </script>
        </div>
        <?php
    }

    /** Which host plugins are measured, and who decides whether to measure at all. */
    public function renderIntegrations(): void
    {
        $this->guard();

        $s = $this->settings;
        $available = $this->integrations?->available() ?? [];

        ?>
        <div class="wrap">
            <?php $this->pageIntro(\__('Integrations and consent', 'conversion-tracking-for-openai-ads')); ?>

            <form method="post" action="options.php">
                <?php
                \settings_fields(self::SLUG);
        $this->declareFields(['consent_mode']);
        ?>

                <h2><?php echo \esc_html__('Integrations', 'conversion-tracking-for-openai-ads'); ?></h2>
                <?php if ($available === []) { ?>
                    <p class="description">
                        <?php echo \esc_html__(
                            'None of the plugins this one integrates with are active here. Contact Form 7, Elementor Pro, Gravity Forms, WPForms, Fluent Forms, Ninja Forms, WooCommerce and Easy Digital Downloads are picked up automatically when they are.',
                            'conversion-tracking-for-openai-ads',
                        ); ?>
                    </p>
                <?php } else { ?>
                    <p class="description">
                        <?php echo \esc_html__(
                            'Detected on this site. Each one measures its own confirmed success boundary - a lead is recorded when the submission is accepted, not when the button is clicked.',
                            'conversion-tracking-for-openai-ads',
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
                                        <?php echo \esc_html__('Measure conversions from this plugin.', 'conversion-tracking-for-openai-ads'); ?>
                                    </label>
                                </td>
                            </tr>
                        <?php } ?>
                    </table>
                <?php } ?>

                <h2><?php echo \esc_html__('Consent', 'conversion-tracking-for-openai-ads'); ?></h2>
                <?php $this->renderConsentStatus(); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="openai-ads-consent"><?php
                                echo \esc_html__('How consent is decided', 'conversion-tracking-for-openai-ads');
        ?></label>
                        </th>
                        <td>
                            <select id="openai-ads-consent" name="<?php echo \esc_attr(Settings::OPTION); ?>[consent_mode]">
                                <?php foreach ($this->consentChoices() as $value => $label) { ?>
                                    <option value="<?php echo \esc_attr($value); ?>"
                                        <?php \selected($s->consentMode(), $value); ?>>
                                        <?php echo \esc_html($label); ?>
                                    </option>
                                <?php } ?>
                            </select>
                            <p class="description">
                                <?php echo \esc_html__(
                                    'This plugin ships no cookie banner and makes no privacy decision for you. It asks yours. Nothing is collected and no Pixel is loaded when the answer is no.',
                                    'conversion-tracking-for-openai-ads',
                                ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php \submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /** The endpoint a tag manager posts to, and what it has received lately. */
    public function renderTagManager(): void
    {
        $this->guard();

        $s = $this->settings;

        ?>
        <div class="wrap">
            <?php $this->pageIntro(\__('Tag manager endpoint', 'conversion-tracking-for-openai-ads')); ?>

            <form method="post" action="options.php">
                <?php
                \settings_fields(self::SLUG);
        $this->declareFields(['ingest_enabled']);
        ?>

                <p class="description">
                    <?php echo \esc_html__(
                        'Lets Google Tag Manager, or anything else, hand a conversion to this site and have it forwarded to OpenAI from your server. Useful when you want server-side tagging without paying for a server container: the API key stays here, and no ad blocker sees the request to OpenAI.',
                        'conversion-tracking-for-openai-ads',
                    ); ?>
                </p>
                <p class="description">
                    <strong><?php echo \esc_html__('Leave this off unless you are using it.', 'conversion-tracking-for-openai-ads'); ?></strong>
                    <?php echo \esc_html__(
                        'Anyone who can reach the endpoint and knows the secret can record conversions in your account. That does not cost you money directly, but it corrupts the figures your campaigns are optimized against.',
                        'conversion-tracking-for-openai-ads',
                    ); ?>
                </p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo \esc_html__('Accept events', 'conversion-tracking-for-openai-ads'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo \esc_attr(Settings::OPTION); ?>[ingest_enabled]"
                                       value="1" <?php \checked($s->ingestEnabled()); ?>>
                                <?php echo \esc_html__('Open the collection endpoint.', 'conversion-tracking-for-openai-ads'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo \esc_html__('Endpoint URL', 'conversion-tracking-for-openai-ads'); ?></th>
                        <td>
                            <input type="text" class="large-text code" readonly
                                   onfocus="this.select()"
                                   value="<?php echo \esc_attr($s->ingestUrl()); ?>">
                            <p class="description">
                                <?php echo \esc_html__('POST JSON here. Public information; the secret below is not.', 'conversion-tracking-for-openai-ads'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php echo \esc_html__('Shared secret', 'conversion-tracking-for-openai-ads'); ?>
                        </th>
                        <td>
                            <?php if ($s->ingestSecretIsConstant()) { ?>
                                <p>
                                    <strong><?php echo \esc_html__('Set in wp-config.php.', 'conversion-tracking-for-openai-ads'); ?></strong>
                                    <?php echo \esc_html__('That is the safer place for it.', 'conversion-tracking-for-openai-ads'); ?>
                                </p>
                            <?php } elseif ($s->ingestSecret() !== null) { ?>
                                <input type="text" class="large-text code" readonly
                                       onfocus="this.select()"
                                       value="<?php echo \esc_attr((string) $s->ingestSecret()); ?>">
                                <p class="description">
                                    <?php echo \esc_html__(
                                        'Send it as the X-OpenAI-Ads-Key header on every request. Treat it like a password.',
                                        'conversion-tracking-for-openai-ads',
                                    ); ?>
                                </p>
                                <p>
                                    <label>
                                        <input type="checkbox" name="<?php echo \esc_attr(Settings::OPTION); ?>[ingest_rotate]" value="1">
                                        <?php echo \esc_html__(
                                            'Replace it when I save. Anything still using the old one stops working.',
                                            'conversion-tracking-for-openai-ads',
                                        ); ?>
                                    </label>
                                </p>
                            <?php } else { ?>
                                <p class="description">
                                    <?php echo \esc_html__('One is generated when you switch the endpoint on and save.', 'conversion-tracking-for-openai-ads'); ?>
                                </p>
                            <?php } ?>
                        </td>
                    </tr>
                </table>

                <?php if ($s->ingestEnabled()) { ?>
                    <p class="description">
                        <?php echo \esc_html__(
                            'Sending from a server rather than a browser? Include source_url, oppref, obref, ip_address and user_agent in the payload. Without them the conversion is attributed to the machine that called this endpoint, not to the visitor.',
                            'conversion-tracking-for-openai-ads',
                        ); ?>
                    </p>
                    <pre class="code" style="overflow:auto;padding:1em;background:#f6f7f7;"><?php
                        echo \esc_html(sprintf(
                            "curl -X POST %s \\\n  -H 'Content-Type: application/json' \\\n"
                            . "  -H '%s: YOUR-SECRET' \\\n"
                            . "  -d '%s'",
                            $s->ingestUrl(),
                            Ingest::SECRET_HEADER,
                            (string) \wp_json_encode([
                                'event' => 'lead_created',
                                'event_id' => 'lead_123',
                                'source_url' => \home_url('/thank-you'),
                                'user' => ['email' => 'visitor@example.com'],
                            ]),
                        ));
                    ?></pre>
                <?php } ?>

                <?php \submit_button(); ?>
            </form>

            <?php $this->renderIngestLog(); ?>
        </div>
        <?php
    }

    /**
     * The plugin's mark, in the menu, in colour.
     *
     * The same drawing as `.wordpress-org/icon.svg` - the same curves, the same
     * two channel gradients, the same glow under the strokes - with two changes
     * the slot requires. The dark tile is dropped, because a 20px square of
     * near-black in the sidebar reads as a broken image rather than an icon,
     * and the glow is far better for landing on the sidebar's own ground. And
     * the viewBox is tightened to the drawing, because the full one leaves the
     * margins a 256px tile wants and a 20px slot cannot spare.
     *
     * Inline rather than read from a file: this runs on `admin_menu`, which is
     * every admin page load. WordPress does not recolour a data-URI icon the
     * way it does a Dashicon, so what is drawn here is what appears.
     */
    private static function menuIcon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="14 13 230 230">'
            . '<defs>'
            . '<linearGradient id="c" x1="34" y1="48" x2="150" y2="128" gradientUnits="userSpaceOnUse">'
            . '<stop stop-color="#3bf0d8"/><stop offset="1" stop-color="#12a8ff"/></linearGradient>'
            . '<linearGradient id="s" x1="34" y1="208" x2="150" y2="128" gradientUnits="userSpaceOnUse">'
            . '<stop stop-color="#ffc94a"/><stop offset="1" stop-color="#ff7a45"/></linearGradient>'
            . '<linearGradient id="m" x1="150" y1="128" x2="226" y2="128" gradientUnits="userSpaceOnUse">'
            . '<stop stop-color="#ffffff"/><stop offset="1" stop-color="#b7e6ff"/></linearGradient>'
            . '<radialGradient id="k" cx=".42" cy=".36" r=".85">'
            . '<stop stop-color="#ffffff"/><stop offset=".7" stop-color="#f4fbff"/>'
            . '<stop offset="1" stop-color="#d3ecfa"/></radialGradient>'
            . '<filter id="g" x="-60%" y="-60%" width="220%" height="220%">'
            . '<feGaussianBlur stdDeviation="7" result="b"/>'
            . '<feMerge><feMergeNode in="b"/><feMergeNode in="b"/></feMerge></filter>'
            . '</defs>'
            . '<g filter="url(#g)" opacity=".55" fill="none" stroke-linecap="round">'
            . '<path d="M34 48C80 48 114 86 150 128" stroke="url(#c)" stroke-width="15"/>'
            . '<path d="M34 208C80 208 114 170 150 128" stroke="url(#s)" stroke-width="15"/>'
            . '<path d="M150 128h76" stroke="url(#m)" stroke-width="17"/>'
            . '</g>'
            . '<g fill="none" stroke-linecap="round">'
            . '<path d="M34 48C80 48 114 86 150 128" stroke="url(#c)" stroke-width="17"/>'
            . '<path d="M34 208C80 208 114 170 150 128" stroke="url(#s)" stroke-width="17"/>'
            . '<path d="M150 128h76" stroke="url(#m)" stroke-width="21"/>'
            . '</g>'
            . '<circle cx="34" cy="48" r="11.5" fill="#4ff0e0"/>'
            . '<circle cx="34" cy="208" r="11.5" fill="#ffc247"/>'
            . '<circle cx="150" cy="128" r="23" fill="url(#k)"/>'
            . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Authorization, checked before anything is rendered. Separate from
     * sanitization, which answers a different question.
     */
    private function guard(): void
    {
        if (!\current_user_can(self::CAPABILITY)) {
            \wp_die(\esc_html__('You do not have permission to manage these settings.', 'conversion-tracking-for-openai-ads'));
        }
    }

    /** The heading, the disclaimer, and whatever the last save had to say. */
    private function pageIntro(string $title): void
    {
        ?>
        <h1><?php echo \esc_html($title); ?></h1>
        <p class="description">
            <?php echo \esc_html__(
                'An independent community integration. Not created, certified, endorsed or supported by OpenAI.',
                'conversion-tracking-for-openai-ads',
            ); ?>
        </p>
        <?php
        /*
         * WordPress prints "Settings saved." by itself only for screens under
         * options-general.php. These are their own pages, so they ask.
         */
        \settings_errors();
    }

    /**
     * State which settings this form rendered. See Settings::FIELDS_PRESENT.
     *
     * @param list<string> $fields
     */
    private function declareFields(array $fields): void
    {
        foreach ($fields as $field) {
            printf(
                '<input type="hidden" name="%s[%s][]" value="%s">',
                \esc_attr(Settings::OPTION),
                \esc_attr(Settings::FIELDS_PRESENT),
                \esc_attr($field),
            );
        }
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
            \wp_send_json_error(['message' => \__('You are not allowed to do this.', 'conversion-tracking-for-openai-ads')], 403);
        }

        \check_ajax_referer(self::TEST_ACTION);

        if ($this->settings->pixelId() === null || $this->settings->capiKey() === null) {
            \wp_send_json_error([
                'message' => \__('Add a Pixel ID and an API key first.', 'conversion-tracking-for-openai-ads'),
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
                'message' => \__('The API could not be reached. Check the site can make outbound requests.', 'conversion-tracking-for-openai-ads'),
            ]);
        }

        if ($response->isSuccessful()) {
            \wp_send_json_success([
                'message' => \__('Success. The credentials work and the event validated.', 'conversion-tracking-for-openai-ads'),
            ]);
        }

        \wp_send_json_error([
            'message' => sprintf(
                /* translators: %d: HTTP status code */
                \__('The API returned HTTP %d. Check the Pixel ID and API key.', 'conversion-tracking-for-openai-ads'),
                $response->statusCode,
            ),
        ]);
    }

    /**
     * What the collection endpoint has seen lately.
     *
     * Only shown with debug logging on, and only ever four columns: when, which
     * event, what happened, and why. Never the payload - it carries raw email
     * addresses and phone numbers, and an options row is readable by anyone who
     * can read options.
     */
    private function renderIngestLog(): void
    {
        if (!$this->settings->ingestEnabled() || !$this->settings->debug()) {
            return;
        }

        /** @var mixed $stored */
        $stored = \get_option(Ingest::LOG_OPTION, []);
        $log = is_array($stored) ? $stored : [];

        echo '<h2>' . \esc_html__('Recent events received', 'conversion-tracking-for-openai-ads') . '</h2>';

        if ($log === []) {
            echo '<p class="description">'
                . \esc_html__('Nothing yet. Send one and reload this page.', 'conversion-tracking-for-openai-ads')
                . '</p>';

            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . \esc_html__('When (UTC)', 'conversion-tracking-for-openai-ads') . '</th>';
        echo '<th>' . \esc_html__('Event', 'conversion-tracking-for-openai-ads') . '</th>';
        echo '<th>' . \esc_html__('Outcome', 'conversion-tracking-for-openai-ads') . '</th>';
        echo '<th>' . \esc_html__('Reason', 'conversion-tracking-for-openai-ads') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($log as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            echo '<tr>';

            foreach (['at', 'event', 'outcome', 'reason'] as $column) {
                echo '<td>' . \esc_html((string) ($entry[$column] ?? '')) . '</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * The dropdown's options: the three modes, plus any provider found.
     *
     * A detected provider is offered by name so a site with two of them can pin
     * the one it means, rather than depending on which happens to be first.
     *
     * @return array<string, string>
     */
    private function consentChoices(): array
    {
        $choices = [];

        foreach (Consent::modes() as $value => $label) {
            $choices[$value] = $label;
        }

        foreach ($this->consent()->availableProviders() as $id => $label) {
            /* translators: %s: the name of a consent plugin, such as Complianz */
            $choices[$id] = sprintf(\__('Always ask %s', 'conversion-tracking-for-openai-ads'), $label);
        }

        return $choices;
    }

    /**
     * What is actually answering, right now.
     *
     * The most useful thing on this screen, and the reason auto-detection is
     * worth having at all: a site owner should learn they are measuring everyone
     * ungated by looking at the page, not from a complaint months later.
     */
    private function renderConsentStatus(): void
    {
        $consent = $this->consent();
        $active = $consent->activeProvider();

        if ($active !== null) {
            printf(
                '<div class="notice notice-success inline"><p>%s</p></div>',
                \esc_html(sprintf(
                    /* translators: %s: the name of a consent plugin */
                    \__('Asking %s for marketing consent. Nothing is measured without it.', 'conversion-tracking-for-openai-ads'),
                    $consent->label($active),
                )),
            );
        }

        $this->renderUngatedNotice(true);

        $unreadable = $consent->unreadableBanners();

        if ($unreadable !== [] && $active === null) {
            printf(
                '<div class="notice notice-info inline"><p>%s</p></div>',
                \esc_html(sprintf(
                    /* translators: %s: a comma-separated list of consent plugin names */
                    \__(
                        'Found %s, but this plugin cannot read it directly. Enable its WP Consent API support and it will be used automatically. Guessing at its internals instead would produce a consent check that answers confidently and wrongly.',
                        'conversion-tracking-for-openai-ads',
                    ),
                    implode(', ', $unreadable),
                )),
            );
        }
    }

    /**
     * The warning that this site measures everybody without asking anyone.
     *
     * Shown on the General screen as well as the consent one. It is the most
     * consequential thing on either, and splitting the settings across screens
     * must not mean a site owner has to find the right page before the plugin
     * will tell them. See Consent::isUngated() for why measuring is still the
     * default when nothing is found.
     *
     * @param bool $onConsentScreen whether the control that fixes it is on this
     *                              same page, or a link away
     */
    private function renderUngatedNotice(bool $onConsentScreen = false): void
    {
        if (!$this->consent()->isUngated()) {
            return;
        }

        if ($onConsentScreen) {
            $advice = \esc_html__(
                'Every visitor is measured. That may be exactly what you want. If you have visitors in the EU or the UK, it probably is not - install a consent plugin that supports the WP Consent API, or choose one of the other options below.',
                'conversion-tracking-for-openai-ads',
            );
        } else {
            $advice = sprintf(
                /* translators: %s: a link reading "Integrations and consent" */
                \esc_html__(
                    'Every visitor is measured. That may be exactly what you want. If you have visitors in the EU or the UK, it probably is not - install a consent plugin that supports the WP Consent API, or decide it yourself under %s.',
                    'conversion-tracking-for-openai-ads',
                ),
                sprintf(
                    '<a href="%s">%s</a>',
                    \esc_url(\admin_url('admin.php?page=' . self::INTEGRATIONS_SLUG)),
                    \esc_html__('Integrations and consent', 'conversion-tracking-for-openai-ads'),
                ),
            );
        }

        printf(
            '<div class="notice notice-warning inline"><p><strong>%s</strong> %s</p></div>',
            \esc_html__('No consent mechanism was found.', 'conversion-tracking-for-openai-ads'),
            // Every part of $advice is escaped above, field by field, and the
            // only markup in it is the link built here. wp_kses_post() keeps
            // that link and would strip anything else, which is what a static
            // analyser cannot see by following the variable.
            \wp_kses_post($advice),
        );
    }

    private function consent(): Consent
    {
        return $this->consentService ??= new Consent($this->settings);
    }
}
