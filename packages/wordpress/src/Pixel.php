<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

/**
 * Puts the Measurement Pixel on WordPress's script queue.
 *
 * Presentation only: it receives a Pixel ID and an already-hashed identity and
 * hands them to WordPress. It queries nothing, decides no business rules, and
 * never has access to the Conversions API key.
 */
final class Pixel
{
    /** The handle the SDK is registered under, so a site can dequeue it. */
    public const HANDLE = 'openai-ads-pixel';

    /** The footer handle every confirmed conversion's browser half rides. */
    public const EVENTS_HANDLE = 'openai-ads-pixel-events';

    private const SDK = 'https://bzrcdn.openai.com/sdk/oaiq.min.js';

    /**
     * OpenAI's own queue stub, minus the DOM injection WordPress now performs.
     *
     * It is what lets init and measure be called before the SDK has finished
     * loading: the calls queue up, and the SDK replays them once it arrives.
     */
    private const LOADER = <<<'JS'
        (function (w) {
            if (w.oaiq) { return; }
            var q = function () { q.q.push(arguments); };
            q.q = [];
            w.oaiq = q;
        })(window);
        JS;

    /**
     * The page view itself.
     *
     * init sends only the SDK's own "Pixel Initialization" record: OpenAI's
     * Pixel does not measure page views by itself, so without this call a site
     * with the Pixel switched on reports no page_viewed at all.
     *
     * No event_id, on purpose. A page view has no server-side counterpart to be
     * deduplicated against, and an id minted here would be baked into the HTML
     * a page cache serves to every visitor - collapsing all of their page views
     * into one.
     */
    private const PAGE_VIEW = 'oaiq("measure", "page_viewed", {"type":"contents"});';

    public function __construct(
        private readonly Settings $settings,
        private readonly Measurement $measurement,
    ) {
    }

    public function enqueue(): void
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

        // wp_json_encode escapes for a script context, and is also what keeps
        // the payload clear of the literal closing script tag that
        // wp_add_inline_script() refuses to carry. Never build this by
        // concatenating strings.
        $encoded = \wp_json_encode($config);

        if (!is_string($encoded)) {
            return;
        }

        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- a null version on purpose: the URL is OpenAI's CDN, not ours, and appending our plugin version to it would rewrite a third party's cache key every time this plugin is released.
        \wp_enqueue_script(self::HANDLE, self::SDK, [], null, ['strategy' => 'async', 'in_footer' => false]);

        /*
         * Every block goes 'before', which is not a formatting preference. A
         * handle carrying an 'after' inline script is ineligible for any delayed
         * strategy (WP_Scripts::filter_eligible_strategies()), and WordPress
         * then moves the whole script to the footer (WP_Scripts::do_item()) - so
         * 'after' would cost the async attribute and the head position at once.
         * It is also what the hand-written snippet did: the stub, init and the
         * first measure all ran before the SDK tag existed, and the SDK replays
         * the queue in order once it arrives.
         */
        \wp_add_inline_script(self::HANDLE, self::LOADER, 'before');
        \wp_add_inline_script(self::HANDLE, 'oaiq("init", ' . $encoded . ');', 'before');
        \wp_add_inline_script(self::HANDLE, self::PAGE_VIEW, 'before');
    }

    /**
     * Queue a browser event for a conversion the server has just confirmed.
     *
     * The bridge that makes deduplication work: the server has already sent, or
     * is about to send, the same event id through the Conversions API, so the
     * two are matched rather than counted twice.
     *
     * @param array<string, mixed> $data
     */
    public function enqueueEvent(string $eventName, string $eventId, array $data = [], ?string $customEventName = null): void
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

        $js = 'window.oaiq && oaiq("measure", ' . $encodedName . ', ' . $encodedData . ', ' . $encodedOptions . ');';

        /*
         * Callers fire from the body - woocommerce_thankyou, a theme template -
         * which is always after WordPress has printed the head queue, and an
         * inline script added to a handle already printed is discarded in
         * silence (WP_Dependencies::do_items() keeps a done list). So the event
         * rides a footer handle of its own.
         *
         * That handle deliberately does not depend on self::HANDLE: eligibility
         * for a delayed strategy recurses over a handle's dependents, so a
         * blocking dependent would take the async attribute off the SDK. Order
         * is guaranteed by the document instead - init in the head, this in the
         * footer - and by the window.oaiq guard for the cases where it is not.
         * Re-registering a registered handle is a no-op, so a second event on
         * the same page needs no bookkeeping here.
         */
        if (\did_action('wp_print_footer_scripts') === 0) {
            // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the handle carries inline code and has no src, so there is no URL for a version to appear in.
            \wp_register_script(self::EVENTS_HANDLE, false, [], null, ['in_footer' => true]);
            \wp_enqueue_script(self::EVENTS_HANDLE);
            \wp_add_inline_script(self::EVENTS_HANDLE, $js);

            return;
        }

        // The footer queue has already been flushed - a late wp_footer callback,
        // or a template printing past wp_footer(). Nothing added to the queue
        // now would ever be printed, and losing a confirmed conversion in
        // silence is the worse failure, so this one tag goes out through core's
        // own inline-script API.
        \wp_print_inline_script_tag($js);
    }
}
