<?php
/**
 * Zoho Payments Admin Settings & OAuth Controller.
 *
 * @package Zoho_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zoho_Payments_Admin {

    /**
     * OAuth handler.
     *
     * @var Zoho_Payments_OAuth
     */
    private $oauth;

    /**
     * API handler.
     *
     * @var Zoho_Payments_API
     */
    private $api;

    /**
     * Constructor.
     *
     * @param Zoho_Payments_OAuth $oauth
     * @param Zoho_Payments_API   $api
     */
    public function __construct(Zoho_Payments_OAuth $oauth, Zoho_Payments_API $api) {
        $this->oauth = $oauth;
        $this->api   = $api;

        add_action('init', array($this, 'handle_oauth_callback_page'));
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_head', array($this, 'render_menu_icon_styles'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Render inline CSS to size the custom Zoho SVG icon in admin menu.
     */
    public function render_menu_icon_styles() {
        echo '<style>#adminmenu .toplevel_page_zoho-payments-settings .wp-menu-image img{width:18px;height:auto;padding-top:7px}</style>';
    }

    /**
     * Register admin menu page.
     */
    public function register_admin_menu() {
        $icon_file = ZOHO_PAYMENTS_PLUGIN_DIR . 'assets/icon.svg';
        if (file_exists($icon_file)) {
            $icon = 'data:image/svg+xml;base64,' . base64_encode(file_get_contents($icon_file));
        } else {
            $icon = ZOHO_PAYMENTS_PLUGIN_URL . 'assets/icon.svg';
        }

        add_menu_page(
            __('Zoho Payments', 'zoho-payments'),
            __('Zoho Payments', 'zoho-payments'),
            'manage_options',
            'zoho-payments-settings',
            array($this, 'render_settings_page'),
            $icon,
            56
        );

        add_submenu_page(
            'zoho-payments-settings',
            __('Settings', 'zoho-payments'),
            __('Settings', 'zoho-payments'),
            'manage_options',
            'zoho-payments-settings',
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            'zoho-payments-settings',
            __('Payments', 'zoho-payments'),
            __('Payments', 'zoho-payments'),
            'manage_options',
            'zoho-payments-list',
            array($this, 'render_payments_page')
        );
    }

    /**
     * Enqueue admin CSS and JS.
     *
     * @param string $hook
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'zoho-payments') === false) {
            return;
        }

        wp_enqueue_style(
            'zoho-payments-admin-css',
            ZOHO_PAYMENTS_PLUGIN_URL . 'assets/css/admin.min.css',
            array(),
            ZOHO_PAYMENTS_VERSION
        );

        wp_enqueue_script(
            'zoho-payments-admin-js',
            ZOHO_PAYMENTS_PLUGIN_URL . 'assets/js/admin.min.js',
            array(), // Pure Vanilla JS - zero dependencies
            ZOHO_PAYMENTS_VERSION,
            true
        );
    }

    /**
     * Process admin actions: Save settings, OAuth callback, Connect, Disconnect, Test.
     */
    public function handle_admin_actions() {
        if (!isset($_GET['page']) || 'zoho-payments-settings' !== $_GET['page']) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';

        // 1. Handle OAuth Redirect Callback from Zoho
        if ('oauth_callback' === $action || isset($_GET['code'])) {
            $this->handle_oauth_callback_page();
            return;
        }

        // 2. Handle Connect action (redirect to Zoho consent screen)
        if ('connect' === $action) {
            check_admin_referer('zoho_payments_connect');
            $auth_url = $this->oauth->get_authorization_url();
            if (is_wp_error($auth_url)) {
                wp_die(esc_html($auth_url->get_error_message()));
            }
            wp_redirect($auth_url);
            exit;
        }

        // 3. Handle Disconnect action
        if ('disconnect' === $action) {
            check_admin_referer('zoho_payments_disconnect');
            $this->oauth->disconnect();
            wp_safe_redirect(add_query_arg(
                array(
                    'page'    => 'zoho-payments-settings',
                    'message' => 'disconnected',
                ),
                admin_url('admin.php')
            ));
            exit;
        }

        // 4. Handle Test Connection action
        if ('test_connection' === $action) {
            check_admin_referer('zoho_payments_test');
            $test_result = $this->api->test_connection();
            $param = $test_result['success'] ? 'test_success' : 'test_failed';
            $error_text = $test_result['success'] ? '' : rawurlencode($test_result['message']);

            wp_safe_redirect(add_query_arg(
                array(
                    'page'       => 'zoho-payments-settings',
                    'message'    => $param,
                    'error_text' => $error_text,
                ),
                admin_url('admin.php')
            ));
            exit;
        }

        // 5. Handle Save Settings Form submission
        if (isset($_POST['zoho_payments_save'])) {
            check_admin_referer('zoho_payments_settings_action', 'zoho_payments_settings_nonce');
            $this->save_settings();
            wp_safe_redirect(add_query_arg(
                array(
                    'page'    => 'zoho-payments-settings',
                    'message' => 'saved',
                ),
                admin_url('admin.php')
            ));
            exit;
        }
    }

    /**
     * Handle incoming OAuth callback on the dedicated redirect URI page (/zoho-payments-callback/).
     * Shows a dedicated status screen with success or failure details.
     */
    public function handle_oauth_callback_page() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $is_callback = (strpos($request_uri, '/zoho-payments-callback') !== false) 
                    || isset($_GET['zoho_payments_callback']) 
                    || (isset($_GET['action']) && 'oauth_callback' === $_GET['action']);

        if (!$is_callback) {
            return;
        }

        // Handle error from Zoho (e.g. ?error=access_denied)
        if (isset($_GET['error'])) {
            $error = sanitize_text_field(wp_unslash($_GET['error']));
            $this->render_callback_screen(false, sprintf(__('Zoho authorization returned an error: %s', 'zoho-payments'), $error));
            exit;
        }

        // Handle authorization code from Zoho
        if (isset($_GET['code'])) {
            $code   = sanitize_text_field(wp_unslash($_GET['code']));
            $result = $this->oauth->handle_authorization_code($code);

            if (is_wp_error($result)) {
                $this->render_callback_screen(false, $result->get_error_message());
            } else {
                $this->render_callback_screen(true, __('Your Zoho Payments account has been successfully authorized and connected! Tokens are configured.', 'zoho-payments'));
            }
            exit;
        }

        // Accessed directly without parameters
        $this->render_callback_screen(false, __('No authorization code or parameters were received from Zoho.', 'zoho-payments'));
        exit;
    }

    /**
     * Render a dedicated, standalone confirmation page for the OAuth redirect.
     *
     * @param bool   $is_success
     * @param string $message
     */
    private function render_callback_screen($is_success, $message) {
        $settings_url = admin_url('admin.php?page=zoho-payments-settings' . ($is_success ? '&message=connected' : ''));
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <?php if ($is_success) : ?>
                <meta http-equiv="refresh" content="3;url=<?php echo esc_url($settings_url); ?>">
            <?php endif; ?>
            <title><?php echo $is_success ? esc_html__('Zoho Payments Authorized', 'zoho-payments') : esc_html__('Authorization Failed', 'zoho-payments'); ?></title>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    background: #f0f2f5;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                    color: #1d2327;
                }
                .zoho-callback-card {
                    background: #ffffff;
                    border-radius: 12px;
                    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
                    max-width: 480px;
                    width: 100%;
                    padding: 40px 32px;
                    text-align: center;
                }
                .zoho-icon-badge {
                    width: 64px;
                    height: 64px;
                    border-radius: 50%;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    margin-bottom: 24px;
                }
                .zoho-icon-badge.success {
                    background-color: #d1e7dd;
                    color: #0f5132;
                }
                .zoho-icon-badge.error {
                    background-color: #f8d7da;
                    color: #842029;
                }
                .zoho-icon-badge svg {
                    width: 32px;
                    height: 32px;
                    fill: currentColor;
                }
                h1 {
                    font-size: 22px;
                    font-weight: 700;
                    margin-bottom: 12px;
                    color: #1d2327;
                }
                p.desc {
                    font-size: 15px;
                    color: #50575e;
                    line-height: 1.5;
                    margin-bottom: 24px;
                }
                .zoho-error-detail {
                    background: #fdf2f2;
                    border: 1px solid #f8b4b4;
                    color: #9b1c1c;
                    padding: 12px;
                    border-radius: 6px;
                    font-family: monospace;
                    font-size: 13px;
                    text-align: left;
                    margin-bottom: 24px;
                    word-break: break-all;
                }
                .zoho-btn-primary {
                    display: inline-block;
                    background: #0d6efd;
                    color: #ffffff;
                    text-decoration: none;
                    font-weight: 600;
                    font-size: 15px;
                    padding: 12px 28px;
                    border-radius: 6px;
                    transition: background-color 0.2s ease;
                }
                .zoho-btn-primary:hover {
                    background: #0b5ed7;
                }
                .zoho-auto-redirect {
                    font-size: 13px;
                    color: #6c757d;
                    margin-top: 16px;
                }
            </style>
        </head>
        <body>
            <div class="zoho-callback-card">
                <?php if ($is_success) : ?>
                    <div class="zoho-icon-badge success">
                        <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                    </div>
                    <h1><?php esc_html_e('Zoho Payments Authorized!', 'zoho-payments'); ?></h1>
                    <p class="desc"><?php echo esc_html($message); ?></p>
                    <a href="<?php echo esc_url($settings_url); ?>" class="zoho-btn-primary">
                        <?php esc_html_e('Return to Zoho Payments Settings', 'zoho-payments'); ?>
                    </a>
                    <p class="zoho-auto-redirect"><?php esc_html_e('Automatically redirecting in 3 seconds...', 'zoho-payments'); ?></p>
                <?php else : ?>
                    <div class="zoho-icon-badge error">
                        <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z"/></svg>
                    </div>
                    <h1><?php esc_html_e('Authorization Failed', 'zoho-payments'); ?></h1>
                    <p class="desc"><?php esc_html_e('We could not complete the connection to Zoho Payments. Details:', 'zoho-payments'); ?></p>
                    <div class="zoho-error-detail"><?php echo esc_html($message); ?></div>
                    <a href="<?php echo esc_url($settings_url); ?>" class="zoho-btn-primary">
                        <?php esc_html_e('Back to Zoho Payments Settings', 'zoho-payments'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
    }

    /**
     * Save general credentials and mode settings.
     */
    private function save_settings() {
        $current = $this->oauth->get_settings();

        $edition             = isset($_POST['edition']) && 'US' === $_POST['edition'] ? 'US' : 'IN';
        $mode                = isset($_POST['mode']) && 'live' === $_POST['mode'] ? 'live' : 'sandbox';
        $account_id          = isset($_POST['account_id']) ? sanitize_text_field(wp_unslash($_POST['account_id'])) : '';
        $client_id           = isset($_POST['client_id']) ? sanitize_text_field(wp_unslash($_POST['client_id'])) : '';
        $client_secret       = isset($_POST['client_secret']) ? sanitize_text_field(wp_unslash($_POST['client_secret'])) : '';
        $checkout_api_key    = isset($_POST['checkout_api_key']) ? sanitize_text_field(wp_unslash($_POST['checkout_api_key'])) : '';
        $webhook_signing_key = isset($_POST['webhook_signing_key']) ? sanitize_text_field(wp_unslash($_POST['webhook_signing_key'])) : '';

        // If credentials, mode, or edition changed, invalidate existing connection token cache
        if ($current['mode'] !== $mode || $current['account_id'] !== $account_id || $current['client_id'] !== $client_id || $current['edition'] !== $edition) {
            delete_transient('zoho_pay_token_' . md5($current['account_id'] . '_' . $current['mode'] . '_' . $current['edition']));
        }

        $current['edition']             = $edition;
        $current['mode']                = $mode;
        $current['account_id']          = $account_id;
        $current['client_id']           = $client_id;
        $current['client_secret']       = $client_secret;
        $current['checkout_api_key']    = $checkout_api_key;
        $current['webhook_signing_key'] = $webhook_signing_key;

        $this->oauth->update_settings($current);
    }

    /**
     * Render the admin settings page.
     */
    public function render_settings_page() {
        $settings     = $this->oauth->get_settings();
        $is_connected = !empty($settings['is_connected']) && !empty($settings['refresh_token']);
        $redirect_uri = $this->oauth->get_redirect_uri();
        $webhook_url  = Zoho_Payments_Webhook::get_webhook_url();

        $message      = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
        $error_text   = isset($_GET['error_text']) ? sanitize_text_field(wp_unslash($_GET['error_text'])) : '';
        ?>
        <div class="wrap zoho-pay-admin-wrap">
            <div class="zoho-pay-header">
                <div class="zoho-pay-header-left">
                    <h1><?php esc_html_e('Zoho Payments - Settings', 'zoho-payments'); ?></h1>
                    <p class="description">
                        <?php esc_html_e('Configure your API credentials, OAuth connection, and Webhook settings.', 'zoho-payments'); ?>
                    </p>
                </div>
                <div class="zoho-pay-header-badge">
                    <span class="zoho-mode-badge <?php echo esc_attr($settings['mode']); ?>">
                        <?php echo 'live' === $settings['mode'] ? esc_html__('● Live Mode', 'zoho-payments') : esc_html__('○ Sandbox Mode', 'zoho-payments'); ?>
                    </span>
                </div>
            </div>

            <?php if (!empty($message)) : ?>
                <?php if ('saved' === $message) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved successfully.', 'zoho-payments'); ?></p></div>
                <?php elseif ('connected' === $message) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Zoho Payments account connected successfully! On-demand tokens are ready.', 'zoho-payments'); ?></p></div>
                <?php elseif ('disconnected' === $message) : ?>
                    <div class="notice notice-info is-dismissible"><p><?php esc_html_e('Zoho Payments account disconnected.', 'zoho-payments'); ?></p></div>
                <?php elseif ('test_success' === $message) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Connection test succeeded! Ready to accept transactions.', 'zoho-payments'); ?></p></div>
                <?php elseif ('test_failed' === $message || 'oauth_failed' === $message) : ?>
                    <div class="notice notice-error is-dismissible"><p><?php echo esc_html(!empty($error_text) ? $error_text : __('Operation failed. Please verify your credentials.', 'zoho-payments')); ?></p></div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="zoho-pay-grid">
                <!-- Main Form Column -->
                <div class="zoho-pay-column-main">
                    <div class="zoho-card">
                        <h2><?php esc_html_e('API & Integration Settings', 'zoho-payments'); ?></h2>
                        <form method="post" action="">
                            <?php wp_nonce_field('zoho_payments_settings_action', 'zoho_payments_settings_nonce'); ?>

                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('Edition', 'zoho-payments'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <div class="zoho-edition-selector">
                                            <button type="button" class="button zoho-edition-btn <?php echo ('IN' === $settings['edition']) ? 'is-active' : ''; ?>" data-edition="IN">
                                                <span class="zoho-flag">🇮🇳</span> <?php esc_html_e('India', 'zoho-payments'); ?>
                                            </button>
                                            <button type="button" class="button zoho-edition-btn <?php echo ('US' === $settings['edition']) ? 'is-active' : ''; ?>" data-edition="US">
                                                <span class="zoho-flag">🇺🇸</span> <?php esc_html_e('United States', 'zoho-payments'); ?>
                                            </button>
                                        </div>
                                        <input type="hidden" name="edition" id="zoho_edition_input" value="<?php echo esc_attr($settings['edition']); ?>" />
                                        <p class="description">
                                            <?php esc_html_e('Choose India if your Zoho account is on zoho.in, or United States if it is on zoho.com.', 'zoho-payments'); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="mode"><?php esc_html_e('Environment Mode', 'zoho-payments'); ?></label>
                                    </th>
                                    <td>
                                        <select name="mode" id="mode" class="regular-text">
                                            <option value="sandbox" <?php selected($settings['mode'], 'sandbox'); ?>>
                                                <?php esc_html_e('Sandbox / Test Mode', 'zoho-payments'); ?>
                                            </option>
                                            <option value="live" <?php selected($settings['mode'], 'live'); ?>>
                                                <?php esc_html_e('Live / Production Mode', 'zoho-payments'); ?>
                                            </option>
                                        </select>
                                        <p class="description">
                                            <?php esc_html_e('Use Sandbox mode for test transactions and switch to Live mode when ready to process actual customer payments.', 'zoho-payments'); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="account_id"><?php esc_html_e('Zoho Payments Account ID', 'zoho-payments'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="text" name="account_id" id="account_id" class="regular-text" value="<?php echo esc_attr($settings['account_id']); ?>" placeholder="e.g. 100000000000" required />
                                        <p class="description">
                                            <?php esc_html_e('Found in Zoho Payments Dashboard under Settings / Organization Profile.', 'zoho-payments'); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="client_id"><?php esc_html_e('Zoho Client ID', 'zoho-payments'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="text" name="client_id" id="client_id" class="regular-text" value="<?php echo esc_attr($settings['client_id']); ?>" placeholder="1000.XXXXXXXXXXXXXXXXXXXXXXXX" required />
                                        <p class="description">
                                            <?php printf(
                                                esc_html__('Generated from Zoho API Console (%s). Enter your website name and paste the Authorized Redirect URI shown on the right.', 'zoho-payments'),
                                                '<a href="' . esc_url($this->oauth->get_api_console_url()) . '" target="_blank" rel="noopener noreferrer" id="zoho_api_console_link_desc"><strong>' . esc_html__('Create Zoho ORG Client', 'zoho-payments') . ' ↗</strong></a>'
                                            ); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="client_secret"><?php esc_html_e('Zoho Client Secret', 'zoho-payments'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="password" name="client_secret" id="client_secret" class="regular-text" value="<?php echo esc_attr($settings['client_secret']); ?>" placeholder="••••••••••••••••••••••••" required />
                                        <p class="description">
                                            <?php esc_html_e('The secret corresponding to your Client ID from Zoho API Console.', 'zoho-payments'); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="checkout_api_key"><?php esc_html_e('API Key', 'zoho-payments'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="text" name="checkout_api_key" id="checkout_api_key" class="regular-text" value="<?php echo esc_attr($settings['checkout_api_key']); ?>" placeholder="xxxxxxxxxxxxxxxxxxxxxxxx" required />
                                        <p class="description">
                                            <?php esc_html_e('Go to Zoho Payments > Settings > Developer Space > API Keys.', 'zoho-payments'); ?>
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="webhook_signing_key"><?php esc_html_e('Webhook Signing Key', 'zoho-payments'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="password" name="webhook_signing_key" id="webhook_signing_key" class="regular-text" value="<?php echo esc_attr($settings['webhook_signing_key']); ?>" placeholder="xxxxxxxxxxxxxxxxxxxxxxxx" required />
                                        <p class="description">
                                            <?php esc_html_e('Go to Zoho Payments > Settings > Developer Space > Webhooks > View Signing Key.', 'zoho-payments'); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p class="submit">
                                <button type="submit" name="zoho_payments_save" class="button button-primary button-large">
                                    <?php esc_html_e('Save Changes', 'zoho-payments'); ?>
                                </button>
                            </p>
                        </form>
                    </div>
                </div>

                <!-- Sidebar Status & Setup Info Column -->
                <div class="zoho-pay-column-sidebar">
                    <!-- OAuth Connection Status Card -->
                    <div class="zoho-card">
                        <h3><?php esc_html_e('OAuth Connection Status', 'zoho-payments'); ?></h3>
                        <?php if ($is_connected) : ?>
                            <div class="zoho-status-box zoho-status-connected">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <div>
                                    <strong><?php esc_html_e('Connected to Zoho Payments', 'zoho-payments'); ?></strong>
                                    <p class="small">
                                        <?php printf(esc_html__('Mode: %s', 'zoho-payments'), esc_html(ucfirst($settings['connected_mode'] ? $settings['connected_mode'] : $settings['mode']))); ?>
                                        <?php if (!empty($settings['connected_at'])) : ?>
                                            <br><?php printf(esc_html__('Authorized: %s', 'zoho-payments'), esc_html(wp_date('M j, Y H:i', $settings['connected_at']))); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>

                            <div class="zoho-btn-group">
                                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'test_connection'), 'zoho_payments_test')); ?>" class="button button-secondary">
                                    <?php esc_html_e('Test Connection', 'zoho-payments'); ?>
                                </a>
                                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'disconnect'), 'zoho_payments_disconnect')); ?>" class="button button-link-delete" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to disconnect Zoho Payments?', 'zoho-payments')); ?>');">
                                    <?php esc_html_e('Disconnect', 'zoho-payments'); ?>
                                </a>
                            </div>
                        <?php else : ?>
                            <div class="zoho-status-box zoho-status-disconnected">
                                <span class="dashicons dashicons-warning"></span>
                                <div>
                                    <strong><?php esc_html_e('Not Connected', 'zoho-payments'); ?></strong>
                                    <p class="small"><?php esc_html_e('Save your Client ID, Secret, and Account ID first, then click below to authorize.', 'zoho-payments'); ?></p>
                                </div>
                            </div>

                            <?php if (!empty($settings['client_id']) && !empty($settings['account_id'])) : ?>
                                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'connect'), 'zoho_payments_connect')); ?>" class="button button-primary button-large zoho-connect-btn">
                                    <?php esc_html_e('Connect with Zoho Payments', 'zoho-payments'); ?>
                                </a>
                            <?php else : ?>
                                <button type="button" class="button button-primary button-large" disabled="disabled">
                                    <?php esc_html_e('Connect with Zoho Payments', 'zoho-payments'); ?>
                                </button>
                                <p class="description small"><?php esc_html_e('Save credentials above to enable authorization.', 'zoho-payments'); ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Setup URLs to Copy Card -->
                    <div class="zoho-card">
                        <h3><?php esc_html_e('Configuration Details for Zoho', 'zoho-payments'); ?></h3>
                        <div style="margin-bottom: 16px;">
                            <a href="<?php echo esc_url($this->oauth->get_api_console_url()); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary" id="zoho_api_console_link" style="width:100%;text-align:center;">
                                <?php esc_html_e('Create Zoho ORG Client ↗', 'zoho-payments'); ?>
                            </a>
                        </div>
                        <p class="description small">
                            <?php esc_html_e('Copy these configuration details into your Zoho developer dashboards:', 'zoho-payments'); ?>
                        </p>

                        <div class="zoho-copy-field">
                            <label><?php esc_html_e('1. Authorized Redirect URI', 'zoho-payments'); ?></label>
                            <div class="zoho-copy-input-group">
                                <input type="text" readonly="readonly" value="<?php echo esc_url($redirect_uri); ?>" class="regular-text" id="zoho_redirect_uri" />
                                <button type="button" class="button zoho-copy-btn" data-target="zoho_redirect_uri" title="<?php esc_attr_e('Copy', 'zoho-payments'); ?>" aria-label="<?php esc_attr_e('Copy', 'zoho-payments'); ?>"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg></button>
                            </div>
                            <span class="description small">
                                <?php esc_html_e('Dedicated redirect page provided by this plugin. Paste this exact URL into Zoho API Console under Authorized Redirect URIs.', 'zoho-payments'); ?>
                            </span>
                        </div>

                        <div class="zoho-copy-field" style="margin-top:15px;">
                            <label><?php esc_html_e('2. Webhook Notification URL', 'zoho-payments'); ?></label>
                            <div class="zoho-copy-input-group">
                                <input type="text" readonly="readonly" value="<?php echo esc_url($webhook_url); ?>" class="regular-text" id="zoho_webhook_url" />
                                <button type="button" class="button zoho-copy-btn" data-target="zoho_webhook_url" title="<?php esc_attr_e('Copy', 'zoho-payments'); ?>" aria-label="<?php esc_attr_e('Copy', 'zoho-payments'); ?>"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg></button>
                            </div>
                            <span class="description small">
                                <?php esc_html_e('Paste this in Zoho Payments > Settings > Developer Space > Webhooks.', 'zoho-payments'); ?>
                            </span>
                        </div>

                        <div class="zoho-copy-field" style="margin-top:15px;">
                            <label><?php esc_html_e('3. Webhook Event', 'zoho-payments'); ?></label>
                            <div class="zoho-copy-input-group">
                                <input type="text" readonly="readonly" value="payment.succeeded" class="regular-text" id="zoho_webhook_event" />
                                <button type="button" class="button zoho-copy-btn" data-target="zoho_webhook_event" title="<?php esc_attr_e('Copy', 'zoho-payments'); ?>" aria-label="<?php esc_attr_e('Copy', 'zoho-payments'); ?>"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg></button>
                            </div>
                            <span class="description small">
                                <?php esc_html_e('Select the payment.succeeded event when configuring your webhook in Zoho Payments.', 'zoho-payments'); ?>
                            </span>
                        </div>
                    </div>
                </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the admin Payments page displaying all successful payments.
     * Shows strictly Amount, Description, and Customer Phone Number.
     */
    public function render_payments_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zoho_payments';

        $payments = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT 200");
        if (!is_array($payments)) {
            $payments = array();
        }

        $settings = $this->oauth->get_settings();
        ?>
        <div class="wrap zoho-pay-admin-wrap">
            <div class="zoho-pay-header">
                <div class="zoho-pay-header-left">
                    <h1><?php esc_html_e('Zoho Payments - Successful Payments', 'zoho-payments'); ?></h1>
                    <p class="description">
                        <?php esc_html_e('Real-time record of all payments successfully completed through your Zoho Payments Quick Pay buttons.', 'zoho-payments'); ?>
                    </p>
                </div>
                <div class="zoho-pay-header-badge">
                    <span class="zoho-mode-badge <?php echo esc_attr($settings['mode']); ?>">
                        <?php echo 'live' === $settings['mode'] ? esc_html__('● Live Mode', 'zoho-payments') : esc_html__('○ Sandbox Mode', 'zoho-payments'); ?>
                    </span>
                </div>
            </div>

            <div class="zoho-card zoho-payments-table-card">
                <div class="zoho-payments-card-header">
                    <h2><?php esc_html_e('Transactions', 'zoho-payments'); ?></h2>
                    <span class="zoho-payments-count">
                        <?php printf(esc_html__('%d payments recorded', 'zoho-payments'), count($payments)); ?>
                    </span>
                </div>

                <?php if (!empty($payments)) : ?>
                    <table class="wp-list-table widefat fixed striped table-view-list zoho-payments-list-table">
                        <thead>
                            <tr>
                                <th scope="col" style="width: 200px;"><?php esc_html_e('Date & Time', 'zoho-payments'); ?></th>
                                <th scope="col" style="width: 150px;"><?php esc_html_e('Amount', 'zoho-payments'); ?></th>
                                <th scope="col"><?php esc_html_e('Description', 'zoho-payments'); ?></th>
                                <th scope="col" style="width: 200px;"><?php esc_html_e('Customer Phone Number', 'zoho-payments'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $curr_sym = ('US' === $this->oauth->get_edition()) ? '$' : '₹';
                            foreach ($payments as $item) :
                            ?>
                                <tr>
                                    <td class="zoho-col-date">
                                        <strong><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item->created_at))); ?></strong>
                                    </td>
                                    <td class="zoho-col-amount">
                                        <span class="zoho-amount-val"><?php echo esc_html($curr_sym . number_format_i18n($item->amount, 2)); ?></span>
                                    </td>
                                    <td class="zoho-col-desc">
                                        <?php echo esc_html($item->description); ?>
                                    </td>
                                    <td class="zoho-col-phone">
                                        <?php echo !empty($item->customer_phone) ? esc_html($item->customer_phone) : '<span class="description">—</span>'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <div class="zoho-empty-payments-state">
                        <div class="zoho-empty-icon">💳</div>
                        <h3><?php esc_html_e('No payments recorded yet', 'zoho-payments'); ?></h3>
                        <p class="description">
                            <?php esc_html_e('When customers make successful payments via your Quick Pay buttons, their transactions will automatically appear here.', 'zoho-payments'); ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
