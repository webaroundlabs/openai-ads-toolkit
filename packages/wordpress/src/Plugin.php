<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress;

use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\ImageTag;
use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\SystemClock;
use WebaroundLabs\OpenAIAds\WordPress\Admin\SettingsPage;
use WebaroundLabs\OpenAIAds\WordPress\Delivery\ScheduledDelivery;
use WebaroundLabs\OpenAIAds\WordPress\Http\Ingest;
use WebaroundLabs\OpenAIAds\WordPress\Integrations\Registry;

/**
 * The plugin's composition root.
 *
 * Registration is cheap and lazy on purpose. WordPress loads every active plugin
 * on every request - including admin-ajax, REST and cron - so anything expensive
 * at load time is a tax on the entire site. Nothing here opens a connection,
 * reads an option, or builds an HTTP client; that happens inside the hooks that
 * actually need it.
 */
final class Plugin
{
    private static ?self $instance = null;

    private ?Settings $settings = null;

    private ?Measurement $measurement = null;

    private ?RequestContext $context = null;

    private ?Pixel $pixel = null;

    private ?Registry $integrations = null;

    private ?Ingest $ingest = null;

    private function __construct(
        public readonly string $file,
        public readonly string $version,
    ) {
    }

    public static function boot(string $file, string $version): self
    {
        if (self::$instance === null) {
            self::$instance = new self($file, $version);
            self::$instance->registerHooks();
        }

        return self::$instance;
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    /** Test seam. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings();
    }

    public function measurement(): Measurement
    {
        return $this->measurement ??= new Measurement($this->settings(), new SystemClock());
    }

    public function context(): RequestContext
    {
        return $this->context ??= new RequestContext($this->settings());
    }

    public function pixel(): Pixel
    {
        return $this->pixel ??= new Pixel($this->settings(), $this->measurement());
    }

    public function integrations(): Registry
    {
        return $this->integrations ??= new Registry($this, $this->settings());
    }

    /** The REST endpoint that accepts conversions from a tag manager. */
    public function ingest(): Ingest
    {
        return $this->ingest ??= new Ingest($this, $this->settings());
    }

    public function builder(): EventBuilder
    {
        return new EventBuilder($this->context(), $this->measurement()->clock());
    }

    /**
     * The plugin's public API.
     *
     * Never throws: a measurement mistake must not break the checkout, form
     * submission or registration that triggered it. Returns false and, in debug
     * mode, logs why.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function track(string $eventName, array $data = [], array $options = []): bool
    {
        try {
            $event = $this->builder()->build($eventName, $data, $options);
        } catch (InvalidArgument $e) {
            if ($this->settings()->debug()) {
                \error_log('[openai-ads] ' . $e->getMessage());
            }

            \do_action('openai_ads_invalid_event', $e, $eventName, $data, $options);

            return false;
        }

        return $this->measurement()->record($event);
    }

    /**
     * Build an event without sending it, for callers that need to inspect or
     * amend it first.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     *
     * @throws InvalidArgument
     */
    public function event(string $eventName, array $data = [], array $options = []): Event
    {
        return $this->builder()->build($eventName, $data, $options);
    }

    /**
     * The URL for an image-tag conversion, or null when one cannot be measured.
     *
     * Never throws, for the same reason `track()` does not: a measurement
     * problem must not break the page that produced the conversion.
     *
     * Identity is deliberately dropped here rather than in the core - OpenAI
     * documents no user object for this channel and forbids personal data in a
     * query parameter, so `withoutIdentity()` is what says that out loud.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function imageTagUrl(string $eventName, array $data = [], array $options = []): ?string
    {
        $pixelId = $this->settings()->pixelId();

        if ($pixelId === null || !$this->settings()->pixelEnabled() || !$this->measurement()->consented()) {
            return null;
        }

        try {
            $event = $this->builder()->build($eventName, $data, $options);

            return ImageTag::url($pixelId, $event->withoutIdentity());
        } catch (InvalidArgument $e) {
            if ($this->settings()->debug()) {
                \error_log('[openai-ads] ' . $e->getMessage());
            }

            \do_action('openai_ads_invalid_event', $e, $eventName, $data, $options);

            return null;
        }
    }

    /**
     * The browser half of the deduplication bridge.
     *
     * Only enqueued when an integration that needs it actually registered, and
     * only when the Pixel is running - there is nothing for it to talk to
     * otherwise.
     */
    public function enqueueBridge(): void
    {
        if (!$this->settings()->pixelEnabled() || !$this->measurement()->consented()) {
            return;
        }

        \wp_enqueue_script(
            'openai-ads-forms',
            \plugins_url('assets/js/forms.js', $this->file),
            [],
            $this->version,
            true,
        );
    }

    private function registerHooks(): void
    {
        // On `init`, not earlier. WordPress 6.7 started warning about translations
        // loaded before then, and a plugin that trips that notice looks broken to
        // every developer with WP_DEBUG on. A site installing from the plugin
        // directory gets its translations without this; it is here for the copies
        // installed by hand, with the .mo files bundled.
        \add_action('init', function (): void {
            \load_plugin_textdomain(
                'conversion-tracking-for-openai-ads',
                false,
                dirname(\plugin_basename($this->file)) . '/languages',
            );
        }, 1);

        \add_action('wp_head', [$this->pixel(), 'render'], 1);

        // The other half of the deferred delivery in ScheduledDelivery. Without
        // this, a site with Action Scheduler - which is every WooCommerce site -
        // stores each batch, queues an action nothing listens to, and loses the
        // conversion silently.
        //
        // The key arrives from Action Scheduler's own table, so it is host data
        // rather than something this plugin still holds: hence mixed, checked.
        \add_action(ScheduledDelivery::HOOK, function (mixed $key): void {
            if (is_string($key)) {
                $this->measurement()->deliverScheduledBatch($key);
            }
        }, 10, 1);

        // Registers one hook, nothing more. The route itself is only declared if
        // the site switched the endpoint on, and that is checked inside
        // rest_api_init rather than here: reading an option on every request is
        // the tax this class exists to avoid.
        $this->ingest()->register();

        // Integrations attach to their host plugin's hooks, which are declared
        // after plugins_loaded. `init` is late enough that every host has
        // registered its classes, and early enough for all of their own hooks.
        \add_action('init', function (): void {
            if ($this->integrations()->register()) {
                \add_action('wp_enqueue_scripts', [$this, 'enqueueBridge']);
            }
        });

        if (\is_admin()) {
            $page = new SettingsPage(
                $this->settings(),
                $this->measurement(),
                $this->builder(),
                $this->integrations(),
            );
            $page->register();

            \add_filter(
                'plugin_action_links_' . \plugin_basename($this->file),
                static function (array $links): array {
                    $url = \admin_url('options-general.php?page=' . SettingsPage::SLUG);
                    $settings = '<a href="' . \esc_url($url) . '">'
                        . \esc_html__('Settings', 'conversion-tracking-for-openai-ads') . '</a>';

                    return array_merge([$settings], $links);
                },
            );
        }
    }
}
