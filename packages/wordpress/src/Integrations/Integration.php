<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Integrations;

/**
 * One host plugin this toolkit knows how to measure.
 *
 * An integration is only ever loaded when its host plugin is actually active, so
 * Contact Form 7, Elementor and WooCommerce never become dependencies of this
 * plugin - a site running none of them pays nothing.
 */
interface Integration
{
    /** Stable identifier, used as the settings key. */
    public function id(): string;

    /** Human-readable name for the settings screen. */
    public function label(): string;

    /** Whether the host plugin is present and active on this request. */
    public function isAvailable(): bool;

    /**
     * Attach to the host's hooks.
     *
     * Called only when the integration is both available and enabled. Register
     * cheaply: this runs on every request.
     */
    public function register(): void;
}
