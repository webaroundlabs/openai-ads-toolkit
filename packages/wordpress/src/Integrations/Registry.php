<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Integrations;

use WebaroundLabs\OpenAIAds\WordPress\Plugin;
use WebaroundLabs\OpenAIAds\WordPress\Settings;

/**
 * Decides which integrations run.
 *
 * An integration is registered only when its host plugin is active AND the site
 * owner has not switched it off. Nothing is loaded speculatively, so a site
 * running neither form plugin pays for neither.
 */
final class Registry
{
    /** @var list<Integration>|null */
    private ?array $integrations = null;

    public function __construct(
        private readonly Plugin $plugin,
        private readonly Settings $settings,
    ) {
    }

    /**
     * Every integration this plugin knows about, active or not.
     *
     * @return list<Integration>
     */
    public function all(): array
    {
        if ($this->integrations !== null) {
            return $this->integrations;
        }

        $recorder = new LeadRecorder($this->plugin);

        $integrations = [
            new ContactForm7($recorder),
            new ElementorForms($recorder),
            new WooCommerce($this->plugin),
        ];

        /**
         * Add an integration for a form or commerce plugin this toolkit does not
         * ship support for.
         *
         * @param list<Integration> $integrations
         */
        $filtered = \apply_filters('openai_ads_integrations', $integrations);

        $this->integrations = array_values(array_filter(
            is_array($filtered) ? $filtered : $integrations,
            static fn (mixed $i): bool => $i instanceof Integration,
        ));

        return $this->integrations;
    }

    /**
     * Those whose host plugin is actually present on this request.
     *
     * @return list<Integration>
     */
    public function available(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (Integration $i): bool => $i->isAvailable(),
        ));
    }

    public function isEnabled(Integration $integration): bool
    {
        return $this->settings->integrationEnabled($integration->id());
    }

    /**
     * Hook up everything available and enabled.
     *
     * Returns whether anything registered, which is what decides if the browser
     * bridge script is worth enqueuing.
     */
    public function register(): bool
    {
        $registered = false;

        foreach ($this->available() as $integration) {
            if (!$this->isEnabled($integration)) {
                continue;
            }

            $integration->register();
            $registered = true;
        }

        return $registered;
    }
}
