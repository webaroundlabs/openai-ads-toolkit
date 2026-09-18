<?php
/**
 * Plugin Name:       Conversion Tracking for OpenAI Ads
 * Plugin URI:        https://github.com/webaroundlabs/openai-ads-toolkit
 * Description:       Measurement Pixel and Conversions API for OpenAI Ads, with browser/server deduplication. An independent community integration, not affiliated with OpenAI.
 * Version:           0.2.1
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Webaround
 * Author URI:        https://webaround.ro
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       conversion-tracking-for-openai-ads
 * Domain Path:       /languages
 *
 * @package WebaroundLabs\OpenAIAds\WordPress
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const OPENAI_ADS_VERSION = '0.2.1';

$openai_ads_autoloader = __DIR__ . '/vendor/autoload.php';

if (!is_readable($openai_ads_autoloader)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'Conversion Tracking for OpenAI Ads is missing its dependencies. Run "composer install" in the plugin directory.',
            'conversion-tracking-for-openai-ads',
        );
        echo '</p></div>';
    });

    return;
}

require_once $openai_ads_autoloader;
require_once __DIR__ . '/src/api.php';

/*
 * Booted on plugins_loaded rather than at file scope so nothing runs before
 * WordPress is ready, and so another plugin can unhook it. Registration is
 * cheap: no options are read and no HTTP client is built until something
 * actually measures.
 */
add_action('plugins_loaded', static function (): void {
    \WebaroundLabs\OpenAIAds\WordPress\Plugin::boot(__FILE__, OPENAI_ADS_VERSION);
});
