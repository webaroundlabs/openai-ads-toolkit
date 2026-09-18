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
openai_ads_delete_pending_batches();

// Multisite: each site keeps its own settings.
if (is_multisite()) {
    $openai_ads_sites = get_sites(['fields' => 'ids', 'number' => 0]);

    foreach ($openai_ads_sites as $openai_ads_site_id) {
        switch_to_blog((int) $openai_ads_site_id);
        delete_option('openai_ads_settings');
        openai_ads_delete_pending_batches();
        restore_current_blog();
    }
}

/**
 * Remove any batches that were queued but never delivered.
 *
 * These hold serialized events, including hashed identity, so they should not be
 * left behind once the plugin is gone.
 */
function openai_ads_delete_pending_batches(): void
{
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- there is no API for "every option whose name starts with", the query is prepared, and caching a list read once while the plugin is being deleted would be worse than useless.
    $keys = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('openai_ads_batch_') . '%'
        )
    );

    foreach ((array) $keys as $key) {
        delete_option((string) $key);
    }
}
