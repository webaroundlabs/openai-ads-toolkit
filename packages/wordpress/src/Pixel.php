<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

/**
 * Renders the Measurement Pixel into the page head.
 *
 * Presentation only: it receives a Pixel ID and already-hashed identity and
 * prints them. It queries nothing, decides no business rules, and never has
 * access to the Conversions API key.
 */
final class Pixel
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Measurement $measurement,
    ) {
    }

    public function render(): void
    {
        if (!$this->settings->pixelEnabled() || !$this->measurement->consented()) {
            return;
        }

        $pixelId = $this->settings->pixelId();

        if ($pixelId === null) {
            return;
        }

        $config = ['pixelId' => $pixelId];

        /**
         * RAW identity for the visitor, if the site knows who they are.
         *
         * Documented field names - email, phone, external_id, first_name,
         * last_name, country, city, region, postal_code - exactly as they appear
         * in the plugin's other entry points:
         *
         *     add_filter( 'openai_ads_pixel_identity', function () {
         *         $user = wp_get_current_user();
         *
         *         return $user->exists() ? [ 'email' => $user->user_email ] : [];
         *     } );
         *
         * Values are normalized and hashed HERE, on the server, before anything
         * is printed. A raw email address must never appear in browser code, and
         * this filter is the reason a site does not have to hash by hand to
         * avoid that.
         *
         * @param array<string, string> $identity
         */
        $identity = \apply_filters('openai_ads_pixel_identity', []);

        if (is_array($identity) && $identity !== []) {
            $user = Identity::forPixel($identity);

            if ($user !== []) {
                $config['user'] = $user;
            }
        }

        if ($this->settings->debug()) {
            $config['debug'] = true;
        }

        // wp_json_encode escapes for a <script> context. Never build this by
        // concatenating strings.
        $encoded = \wp_json_encode($config);

        if (!is_string($encoded)) {
            return;
        }

        echo "<script>\n";
        echo "(function (w, d, s, u) {\n";
        echo "  if (w.oaiq) return;\n";
        echo "  var q = function () { q.q.push(arguments); };\n";
        echo "  q.q = [];\n";
        echo "  w.oaiq = q;\n";
        echo "  var js = d.createElement(s); js.async = true; js.src = u;\n";
        echo "  var f = d.getElementsByTagName(s)[0];\n";
        echo "  f.parentNode.insertBefore(js, f);\n";
        echo "})(window, document, \"script\", \"https://bzrcdn.openai.com/sdk/oaiq.min.js\");\n";
        echo 'oaiq("init", ' . $encoded . ");\n";
        echo "</script>\n";
    }

    /**
     * Emit a browser event for a conversion the server has just confirmed.
     *
     * The bridge that makes deduplication work: the server has already sent, or
     * is about to send, the same event id through the Conversions API, so the
     * two are matched rather than counted twice.
     *
     * @param array<string, mixed> $data
     */
    public function renderEvent(string $eventName, string $eventId, array $data = [], ?string $customEventName = null): void
    {
        if (!$this->settings->pixelEnabled() || !$this->measurement->consented()) {
            return;
        }

        $options = ['event_id' => $eventId];

        if ($customEventName !== null) {
            $options['custom_event_name'] = $customEventName;
        }

        $encodedName = \wp_json_encode($eventName);
        $encodedData = \wp_json_encode($data);
        $encodedOptions = \wp_json_encode($options);

        if (!is_string($encodedName) || !is_string($encodedData) || !is_string($encodedOptions)) {
            return;
        }

        echo "<script>\n";
        echo 'window.oaiq && oaiq("measure", ' . $encodedName . ', ' . $encodedData . ', ' . $encodedOptions . ");\n";
        echo "</script>\n";
    }
}
