<?php
/**
 * Plugin Name: Zoho Payments Quick Pay
 * Plugin URI:  https://wordpress.org/plugins/zoho-payments/
 * Description: Accept payments seamlessly on WordPress using Zoho Payments. Extends the native WordPress Button block with Quick Pay checkout, on-demand OAuth token management, and cryptographic webhook verification.
 * Version:     1.0.0
 * Author:      Zoho Payments
 * Author URI:  https://www.zoho.com/payments/
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: zoho-payments
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Plugin constants
define('ZOHO_PAYMENTS_VERSION', '1.0.0');
define('ZOHO_PAYMENTS_PLUGIN_FILE', __FILE__);
define('ZOHO_PAYMENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZOHO_PAYMENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZOHO_PAYMENTS_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Zoho Payments Plugin Class
 */
final class Zoho_Payments {

    /**
     * Singleton instance.
     *
     * @var Zoho_Payments|null
     */
    private static $instance = null;

    /**
     * OAuth handler instance.
     *
     * @var Zoho_Payments_OAuth
     */
    public $oauth;

    /**
     * API handler instance.
     *
     * @var Zoho_Payments_API
     */
    public $api;

    /**
     * Admin handler instance.
     *
     * @var Zoho_Payments_Admin
     */
    public $admin;

    /**
     * Webhook handler instance.
     *
     * @var Zoho_Payments_Webhook
     */
    public $webhook;

    /**
     * Block handler instance.
     *
     * @var Zoho_Payments_Block
     */
    public $block;

    /**
     * Get singleton instance.
     *
     * @return Zoho_Payments
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required class files.
     */
    private function load_dependencies() {
        require_once ZOHO_PAYMENTS_PLUGIN_DIR . 'includes/class-zoho-payments-oauth.php';
        require_once ZOHO_PAYMENTS_PLUGIN_DIR . 'includes/class-zoho-payments-api.php';
        require_once ZOHO_PAYMENTS_PLUGIN_DIR . 'includes/class-zoho-payments-admin.php';
        require_once ZOHO_PAYMENTS_PLUGIN_DIR . 'includes/class-zoho-payments-webhook.php';
        require_once ZOHO_PAYMENTS_PLUGIN_DIR . 'includes/class-zoho-payments-block.php';

        $this->oauth   = new Zoho_Payments_OAuth();
        $this->api     = new Zoho_Payments_API($this->oauth);
        $this->admin   = new Zoho_Payments_Admin($this->oauth, $this->api);
        $this->webhook = new Zoho_Payments_Webhook();
        $this->block   = new Zoho_Payments_Block($this->api);
    }

    /**
     * Initialize core hooks.
     */
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'on_plugins_loaded'));
        add_filter('plugin_action_links_' . ZOHO_PAYMENTS_PLUGIN_BASENAME, array($this, 'add_action_links'));
        add_action('init', array($this, 'register_rewrite_rules'));
    }

    /**
     * Register rewrite rule for dedicated OAuth callback page.
     */
    public function register_rewrite_rules() {
        add_rewrite_rule('^zoho-payments-callback/?$', 'index.php?zoho_payments_callback=1', 'top');
    }

    /**
     * Plugins loaded hook.
     */
    public function on_plugins_loaded() {
        load_plugin_textdomain('zoho-payments', false, dirname(ZOHO_PAYMENTS_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Add settings link to plugins list table.
     *
     * @param array $links
     * @return array
     */
    public function add_action_links($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=zoho-payments-settings')),
            esc_html__('Settings', 'zoho-payments')
        );
        array_unshift($links, $settings_link);
        return $links;
    }
}

/**
 * Helper function to access the plugin instance.
 *
 * @return Zoho_Payments
 */
function zoho_payments() {
    return Zoho_Payments::get_instance();
}

// Kick off the plugin.
zoho_payments();

// Flush rewrite rules & create database tables on plugin activation
register_activation_hook(__FILE__, 'zoho_payments_activate');
function zoho_payments_activate() {
    add_rewrite_rule('^zoho-payments-callback/?$', 'index.php?zoho_payments_callback=1', 'top');
    flush_rewrite_rules(false);
    zoho_payments_create_db_tables();
}

/**
 * Create or upgrade custom database tables.
 */
function zoho_payments_create_db_tables() {
    global $wpdb;
    $table_name      = $wpdb->prefix . 'zoho_payments';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        payment_id varchar(100) NOT NULL,
        amount decimal(10,2) NOT NULL,
        description text NOT NULL,
        customer_phone varchar(30) NOT NULL DEFAULT '',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (payment_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// Ensure database table exists on admin_init if files were updated directly
add_action('admin_init', 'zoho_payments_check_db');
function zoho_payments_check_db() {
    if (get_option('zoho_payments_db_version') !== ZOHO_PAYMENTS_VERSION) {
        zoho_payments_create_db_tables();
        update_option('zoho_payments_db_version', ZOHO_PAYMENTS_VERSION);
    }
}
