<?php
/**
 * Removes the plugin's stored settings.
 *
 * Only runs when the site owner deletes the plugin, never on deactivation.
 * The API key lives in this option, so leaving it behind after an explicit
 * delete would leave a credential in the database with nothing to use it.
 *
 * @package WebaroundLabs\OpenAIAds\WordPress
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('openai_ads_settings');

// Multisite: each site keeps its own settings.
if (is_multisite()) {
    $sites = get_sites(['fields' => 'ids', 'number' => 0]);

    foreach ($sites as $site_id) {
        switch_to_blog((int) $site_id);
        delete_option('openai_ads_settings');
        restore_current_blog();
    }
}
