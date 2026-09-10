<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress;

use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\SystemClock;
use WebaroundLabs\OpenAIAds\WordPress\Admin\SettingsPage;
use WebaroundLabs\OpenAIAds\WordPress\Delivery\ScheduledDelivery;
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
        \add_action('wp_head', [$this->pixel(), 'render'], 1);

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
                        . \esc_html__('Settings', 'openai-ads') . '</a>';

                    return array_merge([$settings], $links);
                },
            );
        }
    }
}
