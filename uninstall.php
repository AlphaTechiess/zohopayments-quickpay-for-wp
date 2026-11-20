<?php
/**
 * Zoho Payments Uninstall.
 * Fired when the plugin is deleted by an administrator.
 * Completely cleans up all stored options, transients, and logs,
 * leaving zero footprints in the WordPress database.
 *
 * @package Zoho_Payments
 */

// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Remove all plugin data for the current site.
 */
function zoho_payments_uninstall_site_data() {
    global $wpdb;

    // Delete saved options
    delete_option('zoho_payments_settings');
    delete_option('zoho_payments_webhook_logs');
    delete_option('zoho_payments_db_version');

    // Drop custom payments table
    $table_name = $wpdb->prefix . 'zoho_payments';
    $wpdb->query("DROP TABLE IF EXISTS {$table_name}");

    // Delete all cached access tokens and transient timeouts
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_zoho_pay_%' 
            OR option_name LIKE '_transient_timeout_zoho_pay_%'"
    );

    // Flush object cache if available
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }

    // Flush rewrite rules
    flush_rewrite_rules(false);
}

// Handle Multisite network installations or standard single site
if (is_multisite()) {
    $site_ids = get_sites(array('fields' => 'ids', 'number' => 0));
    foreach ($site_ids as $site_id) {
        switch_to_blog($site_id);
        zoho_payments_uninstall_site_data();
        restore_current_blog();
    }
} else {
    zoho_payments_uninstall_site_data();
}
