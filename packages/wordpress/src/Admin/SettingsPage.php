<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Admin;

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
        \add_options_page(
            \__('Conversion Tracking for OpenAI Ads', 'conversion-tracking-for-openai-ads'),
            \__('OpenAI Ads', 'conversion-tracking-for-openai-ads'),
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
            \wp_die(\esc_html__('You do not have permission to manage these settings.', 'conversion-tracking-for-openai-ads'));
        }

        $s = $this->settings;
        $keyIsConstant = $s->capiKeyIsConstant();
        $hasKey = $s->capiKey() !== null;

        ?>
        <div class="wrap">
            <h1><?php echo \esc_html__('Conversion Tracking for OpenAI Ads', 'conversion-tracking-for-openai-ads'); ?></h1>

            <p class="description">
                <?php echo \esc_html__(
                    'An independent community integration. Not created, certified, endorsed or supported by OpenAI.',
                    'conversion-tracking-for-openai-ads',
                ); ?>
            </p>

            <form method="post" action="options.php">
                <?php \settings_fields(self::SLUG); ?>

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

                <?php $available = $this->integrations?->available() ?? []; ?>
                <?php if ($available !== []) { ?>
                    <h2><?php echo \esc_html__('Integrations', 'conversion-tracking-for-openai-ads'); ?></h2>
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

                <h2><?php echo \esc_html__('Tag manager endpoint', 'conversion-tracking-for-openai-ads'); ?></h2>
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
            $choices[$value] = \__($label, 'conversion-tracking-for-openai-ads');
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

        if ($consent->isUngated()) {
            printf(
                '<div class="notice notice-warning inline"><p><strong>%s</strong> %s</p></div>',
                \esc_html__('No consent mechanism was found.', 'conversion-tracking-for-openai-ads'),
                \esc_html__(
                    'Every visitor is measured. That may be exactly what you want. If you have visitors in the EU or the UK, it probably is not - install a consent plugin that supports the WP Consent API, or choose one of the other options below.',
                    'conversion-tracking-for-openai-ads',
                ),
            );
        }

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

    private function consent(): Consent
    {
        return $this->consentService ??= new Consent($this->settings);
    }
}
