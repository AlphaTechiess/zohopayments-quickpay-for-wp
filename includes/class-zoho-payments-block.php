<?php
/**
 * Zoho Payments Gutenberg Block Extension & Payment Session Controller.
 * Extends WordPress core/button block with Zoho Payments Quick Pay controls,
 * guarantees 100% theme style inheritance, provides tamper-proof HMAC verification,
 * and handles high-reliability dual REST + AJAX payment session creation.
 *
 * @package Zoho_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zoho_Payments_Block {

    /**
     * API client handler.
     *
     * @var Zoho_Payments_API
     */
    private $api;

    /**
     * Prevent redundant frontend asset enqueuing.
     *
     * @var bool
     */
    private static $assets_enqueued = false;

    /**
     * Constructor.
     *
     * @param Zoho_Payments_API $api
     */
    public function __construct(Zoho_Payments_API $api) {
        $this->api = $api;

        add_action('init', array($this, 'register_assets'));
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_block_editor_assets'));
        add_filter('render_block', array($this, 'filter_core_button_render'), 10, 2);
        add_action('rest_api_init', array($this, 'register_session_routes'));
        add_action('wp_ajax_zoho_payments_create_session', array($this, 'handle_ajax_create_session'));
        add_action('wp_ajax_nopriv_zoho_payments_create_session', array($this, 'handle_ajax_create_session'));
    }

    /**
     * Register scripts and styles for Gutenberg editor and frontend checkout.
     */
    public function register_assets() {
        // Block editor script extending core/button
        wp_register_script(
            'zoho-payments-block-editor',
            ZOHO_PAYMENTS_PLUGIN_URL . 'assets/js/block.min.js',
            array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-hooks', 'wp-compose'),
            ZOHO_PAYMENTS_VERSION,
            true
        );

        // Editor styling for Advanced settings panel
        wp_register_style(
            'zoho-payments-block-editor',
            ZOHO_PAYMENTS_PLUGIN_URL . 'assets/css/block.min.css',
            array(),
            ZOHO_PAYMENTS_VERSION
        );

        // Frontend checkout button styles & spinner animation
        wp_register_style(
            'zoho-payments-block-style',
            ZOHO_PAYMENTS_PLUGIN_URL . 'assets/css/block.min.css',
            array(),
            ZOHO_PAYMENTS_VERSION
        );

        // Frontend checkout handler
        wp_register_script(
            'zoho-payments-frontend',
            ZOHO_PAYMENTS_PLUGIN_URL . 'assets/js/frontend.min.js',
            array('zoho-payments-widget-sdk'),
            ZOHO_PAYMENTS_VERSION,
            true
        );
    }

    /**
     * Enqueue block editor assets in Gutenberg editor.
     */
    public function enqueue_block_editor_assets() {
        wp_enqueue_script('zoho-payments-block-editor');
        wp_enqueue_style('zoho-payments-block-editor');
    }

    /**
     * Enqueue Zoho Payments Widget SDK and checkout script on-demand.
     */
    public function enqueue_frontend_assets() {
        if (self::$assets_enqueued) {
            return;
        }

        $oauth    = $this->api->get_oauth();
        $settings = $oauth->get_settings();

        // Official Zoho Payments Checkout Widget SDK
        wp_enqueue_script(
            'zoho-payments-widget-sdk',
            'https://static.zohocdn.com/zpay/zpay-js/v1/zpayments.js',
            array(),
            null,
            true
        );

        wp_enqueue_style('zoho-payments-block-style');
        wp_enqueue_script('zoho-payments-frontend');

        $edition = $oauth->get_edition();

        // Localize configuration for client-side checkout
        wp_localize_script('zoho-payments-frontend', 'zohoPaymentsConfig', array(
            'restUrl'        => esc_url_raw(rest_url('zoho-payments/v1/create-session')),
            'ajaxUrl'        => esc_url_raw(admin_url('admin-ajax.php')),
            'accountId'      => isset($settings['account_id']) ? sanitize_text_field($settings['account_id']) : '',
            'checkoutApiKey' => isset($settings['checkout_api_key']) ? sanitize_text_field($settings['checkout_api_key']) : '',
            'domain'         => $edition,
            'isTestMode'     => (!isset($settings['mode']) || 'sandbox' === $settings['mode']),
            'i18n'           => array(
                'loading_sdk'    => __('Zoho Payments SDK is loading. Please wait a moment...', 'zoho-payments'),
                'not_configured' => __('Zoho Payments is not configured. Please configure Account ID and API Key in Admin Settings.', 'zoho-payments'),
                'network_error'  => __('Unable to connect to payment server. Please try again.', 'zoho-payments'),
            ),
        ));

        self::$assets_enqueued = true;
    }

    /**
     * Filter core/button block rendering on frontend.
     * Injects Zoho Payments data attributes and classes while preserving 100% of native theme styles.
     * Also maintains full backward compatibility for legacy custom block markup.
     *
     * @param string $block_content Rendered HTML.
     * @param array  $block         Parsed block array.
     * @return string
     */
    public function filter_core_button_render($block_content, $block) {
        if (empty($block['blockName'])) {
            return $block_content;
        }

        $is_core_button   = ('core/button' === $block['blockName']);
        $is_legacy_block  = ('zoho-payments/pay-button' === $block['blockName']);

        if (!$is_core_button && !$is_legacy_block) {
            return $block_content;
        }

        $attrs = !empty($block['attrs']) ? $block['attrs'] : array();

        // For standard core/button, only intervene when Zoho Payments is enabled
        if ($is_core_button && empty($attrs['zohoPayEnabled'])) {
            return $block_content;
        }

        // Enqueue frontend scripts on-demand only when a Zoho button is on the page
        $this->enqueue_frontend_assets();

        // Extract and normalize payment parameters
        $default_curr = ('US' === $this->api->get_oauth()->get_edition()) ? 'USD' : 'INR';

        $raw_amount   = isset($attrs['zohoAmount']) ? $attrs['zohoAmount'] : (isset($attrs['amount']) ? $attrs['amount'] : 100);
        $raw_curr     = !empty($attrs['zohoCurrency']) ? $attrs['zohoCurrency'] : (!empty($attrs['currency']) ? $attrs['currency'] : $default_curr);
        $raw_desc     = isset($attrs['zohoDescription']) ? $attrs['zohoDescription'] : (isset($attrs['description']) ? $attrs['description'] : '');
        $raw_url      = isset($attrs['zohoRedirectUrl']) ? $attrs['zohoRedirectUrl'] : (isset($attrs['redirectUrl']) ? $attrs['redirectUrl'] : '');

        $clean_amount   = number_format(floatval($raw_amount), 2, '.', '');
        $clean_currency = strtoupper(trim(sanitize_text_field($raw_curr ? $raw_curr : $default_curr)));
        $clean_desc     = trim(sanitize_text_field($raw_desc));
        $clean_url      = trim(esc_url_raw($raw_url));

        // Cryptographic HMAC signature to ensure parameters cannot be tampered with on client side
        $signature = wp_hash($clean_amount . '|' . $clean_currency . '|' . $clean_desc . '|' . $clean_url);

        // Fallback for legacy block if content was empty
        if ($is_legacy_block && empty($block_content)) {
            $text = !empty($attrs['text']) ? $attrs['text'] : __('Pay Now', 'zoho-payments');
            return sprintf(
                '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button zoho-pay-button" role="button" href="javascript:void(0);" data-amount="%s" data-currency="%s" data-description="%s" data-redirect="%s" data-sig="%s">%s</a></div>',
                esc_attr($clean_amount),
                esc_attr($clean_currency),
                esc_attr($clean_desc),
                esc_url($clean_url),
                esc_attr($signature),
                esc_html($text)
            );
        }

        // Modern WordPress 6.2+ HTML Tag Processor
        if (class_exists('WP_HTML_Tag_Processor')) {
            $tags = new WP_HTML_Tag_Processor($block_content);
            if ($tags->next_tag(array('tag_name' => 'a')) || $tags->next_tag(array('tag_name' => 'button'))) {
                $tags->add_class('zoho-pay-button');
                $tags->set_attribute('data-amount', $clean_amount);
                $tags->set_attribute('data-currency', $clean_currency);
                $tags->set_attribute('data-description', $clean_desc);
                $tags->set_attribute('data-redirect', $clean_url);
                $tags->set_attribute('data-sig', $signature);
                $tags->set_attribute('role', 'button');
                $tags->set_attribute('href', 'javascript:void(0);');
                return $tags->get_updated_html();
            }
        }

        // High-reliability regex fallback for WordPress 5.8 to 6.1
        return preg_replace_callback(
            '/<(a|button)\b([^>]*)>/i',
            function ($m) use ($clean_amount, $clean_currency, $clean_desc, $clean_url, $signature) {
                $tag       = $m[1];
                $attrs_str = $m[2];

                if (preg_match('/class=["\']([^"\']*)["\']/i', $attrs_str, $cm)) {
                    if (strpos($cm[1], 'zoho-pay-button') === false) {
                        $attrs_str = preg_replace('/class=["\']([^"\']*)["\']/i', 'class="$1 zoho-pay-button"', $attrs_str);
                    }
                } else {
                    $attrs_str .= ' class="zoho-pay-button"';
                }

                if (preg_match('/href=["\']([^"\']*)["\']/i', $attrs_str)) {
                    $attrs_str = preg_replace('/href=["\']([^"\']*)["\']/i', 'href="javascript:void(0);"', $attrs_str);
                } elseif ('a' === strtolower($tag)) {
                    $attrs_str .= ' href="javascript:void(0);"';
                }

                $attrs_str .= sprintf(
                    ' data-amount="%s" data-currency="%s" data-description="%s" data-redirect="%s" data-sig="%s" role="button"',
                    esc_attr($clean_amount),
                    esc_attr($clean_currency),
                    esc_attr($clean_desc),
                    esc_url($clean_url),
                    esc_attr($signature)
                );

                return '<' . $tag . $attrs_str . '>';
            },
            $block_content,
            1
        );
    }

    /**
     * Unified payment session processor for REST API and WordPress AJAX.
     *
     * @param mixed  $amount
     * @param string $currency
     * @param string $description
     * @param string $redirect_url
     * @param string $signature
     * @return array|WP_Error
     */
    private function process_session_request($amount, $currency, $description, $redirect_url, $signature) {
        $default_curr   = ('US' === $this->api->get_oauth()->get_edition()) ? 'USD' : 'INR';
        $clean_amount   = number_format(floatval($amount), 2, '.', '');
        $clean_currency = strtoupper(trim(sanitize_text_field($currency ? $currency : $default_curr)));
        $clean_desc     = trim(sanitize_text_field($description));
        $clean_url      = trim(esc_url_raw($redirect_url));

        // 1. Verify cryptographic signature against tampering
        $expected_sig = wp_hash($clean_amount . '|' . $clean_currency . '|' . $clean_desc . '|' . $clean_url);
        if (!hash_equals($expected_sig, (string) $signature)) {
            return new WP_Error('invalid_signature', __('Invalid payment session signature.', 'zoho-payments'));
        }

        // 2. Validate amount
        if (floatval($clean_amount) <= 0) {
            return new WP_Error('invalid_amount', __('Payment amount must be greater than zero.', 'zoho-payments'));
        }

        // 3. Validate description (strictly required by Zoho Payments)
        if (empty($clean_desc)) {
            return new WP_Error('missing_description', __('Payment description is required.', 'zoho-payments'));
        }

        // 4. Request payment session from Zoho Payments API
        $session = $this->api->create_payment_session(floatval($clean_amount), $clean_currency, $clean_desc);
        if (is_wp_error($session)) {
            return $session;
        }

        return array(
            'success'             => true,
            'payments_session_id' => $session['payments_session_id'],
            'amount'              => $session['amount'],
            'currency'            => $session['currency'],
            'description'         => $clean_desc,
            'redirect_url'        => $clean_url,
        );
    }

    /**
     * Register REST API endpoint.
     */
    public function register_session_routes() {
        register_rest_route('zoho-payments/v1', '/create-session', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'handle_create_session'),
            'permission_callback' => '__return_true', // Protected by cryptographic signature verification
        ));
    }

    /**
     * Handle payment session creation via REST request.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_create_session(WP_REST_Request $request) {
        $result = $this->process_session_request(
            $request->get_param('amount'),
            $request->get_param('currency'),
            $request->get_param('description'),
            $request->get_param('redirect_url'),
            $request->get_param('sig')
        );

        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $result->get_error_message(),
            ), 400);
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * Handle payment session creation via admin-ajax.php fallback.
     */
    public function handle_ajax_create_session() {
        $result = $this->process_session_request(
            isset($_POST['amount']) ? wp_unslash($_POST['amount']) : '',
            isset($_POST['currency']) ? wp_unslash($_POST['currency']) : 'INR',
            isset($_POST['description']) ? wp_unslash($_POST['description']) : '',
            isset($_POST['redirect_url']) ? wp_unslash($_POST['redirect_url']) : '',
            isset($_POST['sig']) ? wp_unslash($_POST['sig']) : ''
        );

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        } else {
            wp_send_json_success($result, 200);
        }
    }
}
