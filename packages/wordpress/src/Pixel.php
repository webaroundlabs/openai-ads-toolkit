<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress;

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
         * Already-hashed identity for the Pixel, in its singular-key shape:
         * email_sha256, phone_number_sha256, external_id_sha256,
         * first_name_sha256, last_name_sha256, country, city, region,
         * postal_code.
         *
         * Raw values must never be placed in browser code. Hash server-side, or
         * use the JavaScript package's hashUser().
         *
         * @param array<string, string> $user
         */
        $user = \apply_filters('openai_ads_pixel_user', []);

        if (is_array($user) && $user !== []) {
            $config['user'] = array_map('strval', $user);
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
