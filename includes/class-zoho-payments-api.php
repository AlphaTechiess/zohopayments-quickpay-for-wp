<?php
/**
 * Zoho Payments API Client.
 * Handles server-to-server communication with Zoho Payments API.
 *
 * @package Zoho_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zoho_Payments_API {

    /**
     * OAuth handler.
     *
     * @var Zoho_Payments_OAuth
     */
    private $oauth;

    /**
     * Constructor.
     *
     * @param Zoho_Payments_OAuth $oauth
     */
    public function __construct(Zoho_Payments_OAuth $oauth) {
        $this->oauth = $oauth;
    }

    /**
     * Get OAuth instance.
     *
     * @return Zoho_Payments_OAuth
     */
    public function get_oauth() {
        return $this->oauth;
    }

    /**
     * Get API Base URL based on current mode and edition.
     *
     * @return string
     */
    public function get_base_url() {
        $domain    = $this->oauth->get_oauth_domain();
        $subdomain = $this->oauth->is_live_mode() ? 'payments' : 'paymentssandbox';
        return 'https://' . $subdomain . '.' . $domain . '/api/v1';
    }

    /**
     * Create an online payment session.
     * Each session is valid for 15 minutes as per Zoho Payments documentation.
     *
     * Live Endpoint:    https://payments.{domain}/api/v1/paymentsessions?account_id={account_id}
     * Sandbox Endpoint: https://paymentssandbox.{domain}/api/v1/paymentsessions?account_id={account_id}
     *
     * Payload:
     * {
     *   "amount": 100,
     *   "currency": "INR",
     *   "description": "Sample Description"
     * }
     *
     * @param float|int|string $amount
     * @param string           $currency
     * @param string           $description
     * @return array|WP_Error Array containing payments_session_id on success, WP_Error on failure.
     */
    public function create_payment_session($amount, $currency = 'INR', $description = '') {
        $account_id = $this->oauth->get_setting('account_id', '');
        if (empty($account_id)) {
            return new WP_Error('missing_account_id', __('Zoho Payments Account ID is not configured.', 'zoho-payments'));
        }

        $access_token = $this->oauth->get_access_token();
        if (is_wp_error($access_token)) {
            return $access_token;
        }

        $clean_amount = floatval($amount);
        if ($clean_amount <= 0) {
            return new WP_Error('invalid_amount', __('Payment amount must be greater than zero.', 'zoho-payments'));
        }

        $clean_description = trim(sanitize_text_field($description));
        if (empty($clean_description)) {
            return new WP_Error('missing_description', __('Payment description is required.', 'zoho-payments'));
        }

        $endpoint = add_query_arg(
            array('account_id' => sanitize_text_field($account_id)),
            $this->get_base_url() . '/paymentsessions'
        );

        $payload = array(
            'amount'      => (floatval($clean_amount) == intval($clean_amount)) ? intval($clean_amount) : round($clean_amount, 2),
            'currency'    => strtoupper(sanitize_text_field($currency ? $currency : 'INR')),
            'description' => $clean_description,
            'meta_data'   => array(
                array(
                    'key'   => 'Method',
                    'value' => 'Quick Pay WP',
                ),
            ),
        );

        $response = wp_remote_post($endpoint, array(
            'timeout' => 25,
            'headers' => array(
                'Authorization' => 'Zoho-oauthtoken ' . $access_token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body'    => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);
        $data        = json_decode($raw_body, true);

        if ($status_code < 200 || $status_code >= 300 || !is_array($data)) {
            $error_message = '';
            if (is_array($data)) {
                if (!empty($data['message'])) {
                    $error_message = $data['message'];
                } elseif (!empty($data['error_description'])) {
                    $error_message = $data['error_description'];
                } elseif (!empty($data['error'])) {
                    $error_message = is_string($data['error']) ? $data['error'] : wp_json_encode($data['error']);
                } elseif (!empty($data['description'])) {
                    $error_message = $data['description'];
                }
            }
            if (empty($error_message)) {
                $error_message = sprintf(__('Zoho Payments API returned HTTP %d: %s', 'zoho-payments'), $status_code, wp_strip_all_tags(substr($raw_body, 0, 150)));
            }
            return new WP_Error('session_creation_failed', $error_message, array(
                'status_code' => $status_code,
                'response'    => $data,
            ));
        }

        // Support various response structures returned by Zoho Payments
        $session_id = null;
        if (!empty($data['payments_session_id'])) {
            $session_id = $data['payments_session_id'];
        } elseif (!empty($data['payment_session_id'])) {
            $session_id = $data['payment_session_id'];
        } elseif (!empty($data['payment_session']['payments_session_id'])) {
            $session_id = $data['payment_session']['payments_session_id'];
        } elseif (!empty($data['payment_session']['payment_session_id'])) {
            $session_id = $data['payment_session']['payment_session_id'];
        } elseif (!empty($data['payments_session']['payments_session_id'])) {
            $session_id = $data['payments_session']['payments_session_id'];
        }

        if (empty($session_id)) {
            return new WP_Error('missing_session_id', __('Payment session was created, but no session ID was returned.', 'zoho-payments'), $data);
        }

        return array(
            'payments_session_id' => $session_id,
            'amount'              => $payload['amount'],
            'currency'            => $payload['currency'],
            'raw_response'        => $data,
        );
    }

    /**
     * Test the API & OAuth credentials by attempting to get an access token.
     *
     * @return array Array with success boolean and message.
     */
    public function test_connection() {
        $token = $this->oauth->get_access_token();
        if (is_wp_error($token)) {
            return array(
                'success' => false,
                'message' => $token->get_error_message(),
            );
        }

        return array(
            'success' => true,
            'message' => __('Successfully authenticated with Zoho Payments!', 'zoho-payments'),
        );
    }
}
