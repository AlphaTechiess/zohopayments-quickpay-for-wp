<?php
/**
 * Zoho Payments OAuth Handler.
 * Manages OAuth credentials, authorization code exchange,
 * and on-demand token generation with intelligent caching.
 *
 * @package Zoho_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zoho_Payments_OAuth {

    /**
     * Settings option key in wp_options.
     */
    const OPTION_KEY = 'zoho_payments_settings';

    /**
     * In-memory cache for parsed settings.
     *
     * @var array|null
     */
    private $cached_settings = null;

    /**
     * In-memory cache for access token during current request.
     *
     * @var string|null
     */
    private $cached_access_token = null;

    /**
     * Get plugin settings array.
     *
     * @return array
     */
    public function get_settings() {
        if (null !== $this->cached_settings) {
            return $this->cached_settings;
        }

        $defaults = array(
            'edition'             => 'IN', // 'IN' or 'US'
            'mode'                => 'sandbox', // 'sandbox' or 'live'
            'account_id'          => '',
            'client_id'           => '',
            'client_secret'       => '',
            'checkout_api_key'    => '',
            'webhook_signing_key' => '',
            'refresh_token'       => '',
            'is_connected'        => false,
            'connected_at'        => 0,
            'connected_mode'      => '',
        );

        $saved = get_option(self::OPTION_KEY, array());
        $this->cached_settings = wp_parse_args($saved, $defaults);
        return $this->cached_settings;
    }

    /**
     * Get current active edition ('IN' or 'US').
     *
     * @return string
     */
    public function get_edition() {
        return 'US' === $this->get_setting('edition', 'IN') ? 'US' : 'IN';
    }

    /**
     * Get OAuth domain ('zoho.in' or 'zoho.com').
     *
     * @return string
     */
    public function get_oauth_domain() {
        return ('US' === $this->get_edition()) ? 'zoho.com' : 'zoho.in';
    }

    /**
     * Get OAuth authorization URL base.
     *
     * @return string
     */
    public function get_auth_url() {
        return 'https://accounts.' . $this->get_oauth_domain() . '/oauth/v2/org/auth';
    }

    /**
     * Get OAuth token endpoint URL.
     *
     * @return string
     */
    public function get_token_url() {
        return 'https://accounts.' . $this->get_oauth_domain() . '/oauth/v2/token';
    }

    /**
     * Get Zoho API Console add client URL.
     *
     * @return string
     */
    public function get_api_console_url() {
        return 'https://api-console.' . $this->get_oauth_domain() . '/add?client_type=ORG';
    }

    /**
     * Update plugin settings array.
     *
     * @param array $settings
     * @return bool
     */
    public function update_settings($settings) {
        $this->cached_settings = $settings;
        return update_option(self::OPTION_KEY, $settings);
    }

    /**
     * Get specific setting value.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get_setting($key, $default = '') {
        $settings = $this->get_settings();
        return isset($settings[$key]) ? $settings[$key] : $default;
    }

    /**
     * Check if currently in live mode.
     *
     * @return bool
     */
    public function is_live_mode() {
        return 'live' === $this->get_setting('mode', 'sandbox');
    }

    /**
     * Get the dedicated redirect URI required by Zoho API console.
     * Dedicated fixed page providing a clean URL with no query parameters.
     *
     * @return string
     */
    public function get_redirect_uri() {
        return home_url('/zoho-payments-callback/');
    }

    /**
     * Generate the Zoho OAuth consent URL.
     *
     * @return string|WP_Error
     */
    public function get_authorization_url() {
        $settings = $this->get_settings();

        if (empty($settings['client_id'])) {
            return new WP_Error('missing_client_id', __('Client ID is required to initiate OAuth.', 'zoho-payments'));
        }

        if (empty($settings['account_id'])) {
            return new WP_Error('missing_account_id', __('Zoho Payments Account ID is required to initiate OAuth.', 'zoho-payments'));
        }

        $is_live = $this->is_live_mode();
        $scope = $is_live
            ? 'ZohoPay.payments.CREATE,ZohoPay.payments.READ'
            : 'ZohoPaySandbox.payments.CREATE,ZohoPaySandbox.payments.READ';

        // Sandbox requires 'zohopaysandbox.{account_id}', Live requires 'zohopay.{account_id}'
        $soid_prefix = $is_live ? 'zohopay.' : 'zohopaysandbox.';
        $soid        = $soid_prefix . sanitize_text_field($settings['account_id']);

        // Build exact query params as required by Zoho Payments
        $params = array(
            'scope'         => $scope,
            'client_id'     => sanitize_text_field($settings['client_id']),
            'soid'          => $soid,
            'state'         => 'To generate payments access token',
            'response_type' => 'code',
            'redirect_uri'  => $this->get_redirect_uri(),
            'access_type'   => 'offline',
        );

        // Keep literal commas in scope parameter
        $query_string = str_replace('%2C', ',', http_build_query($params, '', '&', PHP_QUERY_RFC3986));

        return $this->get_auth_url() . '?' . $query_string;
    }

    /**
     * Exchange authorization code for refresh & access tokens.
     *
     * @param string $code
     * @return true|WP_Error
     */
    public function handle_authorization_code($code) {
        $settings = $this->get_settings();

        if (empty($settings['client_id']) || empty($settings['client_secret'])) {
            return new WP_Error('missing_credentials', __('Client ID and Client Secret are required.', 'zoho-payments'));
        }

        $body = array(
            'code'          => sanitize_text_field($code),
            'client_id'     => sanitize_text_field($settings['client_id']),
            'client_secret' => sanitize_text_field($settings['client_secret']),
            'redirect_uri'  => $this->get_redirect_uri(),
            'grant_type'    => 'authorization_code',
        );

        $response = wp_remote_post($this->get_token_url(), array(
            'timeout'   => 25,
            'body'      => $body,
            'headers'   => array(
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);
        $data        = json_decode($raw_body, true);

        if ($status_code !== 200 || !is_array($data) || isset($data['error'])) {
            $error_msg = isset($data['error']) ? $data['error'] : __('Failed to exchange authorization code.', 'zoho-payments');
            return new WP_Error('oauth_exchange_failed', $error_msg, $data);
        }

        if (empty($data['refresh_token'])) {
            // Note: If refresh_token is not in the response, offline access might not have been granted.
            return new WP_Error('no_refresh_token', __('No refresh token received from Zoho. Please revoke previous grants or re-authorize with offline access.', 'zoho-payments'));
        }

        // Save refresh token & update connection status
        $settings['refresh_token']  = sanitize_text_field($data['refresh_token']);
        $settings['is_connected']   = true;
        $settings['connected_at']   = time();
        $settings['connected_mode'] = $settings['mode'];
        $this->update_settings($settings);

        // Cache initial access token if present
        if (!empty($data['access_token'])) {
            $expires_in = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
            $this->cache_access_token($data['access_token'], $expires_in);
        }

        return true;
    }

    /**
     * Get a valid access token on-demand.
     * Reuses cached token if valid; generates a new one only when needed.
     * Never runs hourly cron jobs.
     *
     * @return string|WP_Error
     */
    public function get_access_token() {
        if (null !== $this->cached_access_token) {
            return $this->cached_access_token;
        }

        $settings = $this->get_settings();

        if (empty($settings['refresh_token'])) {
            return new WP_Error('not_connected', __('Zoho Payments is not connected. Please authorize under Zoho Payments Settings.', 'zoho-payments'));
        }

        $mode = $this->get_setting('mode', 'sandbox');
        $transient_key = 'zoho_pay_token_' . md5($settings['account_id'] . '_' . $mode . '_' . $this->get_edition());
        $cached_token  = get_transient($transient_key);

        if (!empty($cached_token)) {
            $this->cached_access_token = $cached_token;
            return $cached_token;
        }

        // Generate new access token using stored refresh token
        $body = array(
            'refresh_token' => $settings['refresh_token'],
            'client_id'     => $settings['client_id'],
            'client_secret' => $settings['client_secret'],
            'grant_type'    => 'refresh_token',
        );

        $response = wp_remote_post($this->get_token_url(), array(
            'timeout' => 20,
            'body'    => $body,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);
        $data        = json_decode($raw_body, true);

        if ($status_code !== 200 || !is_array($data) || empty($data['access_token'])) {
            $error_msg = isset($data['error']) ? $data['error'] : __('Unable to refresh Zoho Payments access token.', 'zoho-payments');
            return new WP_Error('token_refresh_failed', $error_msg, $data);
        }

        $access_token = sanitize_text_field($data['access_token']);
        $expires_in   = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;

        $this->cache_access_token($access_token, $expires_in);

        return $access_token;
    }

    /**
     * Cache access token with safety buffer (180 seconds before expiry).
     *
     * @param string $access_token
     * @param int    $expires_in
     */
    private function cache_access_token($access_token, $expires_in) {
        $this->cached_access_token = $access_token;
        $mode = $this->get_setting('mode', 'sandbox');
        $account_id = $this->get_setting('account_id', '');
        $transient_key = 'zoho_pay_token_' . md5($account_id . '_' . $mode . '_' . $this->get_edition());

        // Subtract 180 seconds to ensure token never expires mid-flight
        $ttl = max(300, $expires_in - 180);
        set_transient($transient_key, $access_token, $ttl);
    }

    /**
     * Disconnect Zoho Payments account.
     * Clears tokens and resets connection status.
     *
     * @return void
     */
    public function disconnect() {
        $this->cached_access_token = null;
        $settings = $this->get_settings();
        $mode = $settings['mode'];
        $account_id = $settings['account_id'];

        $settings['refresh_token']  = '';
        $settings['is_connected']   = false;
        $settings['connected_at']   = 0;
        $settings['connected_mode'] = '';
        $this->update_settings($settings);

        delete_transient('zoho_pay_token_' . md5($account_id . '_' . $mode . '_' . $this->get_edition()));
    }
}
