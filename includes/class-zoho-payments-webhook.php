<?php
/**
 * Zoho Payments Webhook Handler.
 * Listens for payment notifications, verifies incoming signatures using HMAC-SHA256,
 * filters only "Quick Pay WP" transactions via metadata, and stores successful payments.
 *
 * @package Zoho_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zoho_Payments_Webhook {

    /**
     * REST namespace and route.
     */
    const REST_NAMESPACE = 'zoho-payments/v1';
    const REST_ROUTE     = '/webhook';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register REST API route for Zoho Payments webhooks.
     */
    public function register_routes() {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true', // Authenticated via HMAC signature
        ));
    }

    /**
     * Get the public webhook endpoint URL.
     *
     * @return string
     */
    public static function get_webhook_url() {
        return rest_url(self::REST_NAMESPACE . self::REST_ROUTE);
    }

    /**
     * Handle incoming webhook requests.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_webhook(WP_REST_Request $request) {
        $raw_body = $request->get_body();
        $signature_header = $request->get_header('x-zoho-webhook-signature');

        if (empty($signature_header)) {
            $signature_header = $request->get_header('X-Zoho-Webhook-Signature');
        }

        $settings = get_option('zoho_payments_settings', array());
        $signing_key = isset($settings['webhook_signing_key']) ? trim($settings['webhook_signing_key']) : '';

        // If merchant hasn't configured the signing key, reject
        if (empty($signing_key)) {
            return new WP_REST_Response(array(
                'status'  => 'error',
                'message' => 'Webhook signing key is not configured on this site.',
            ), 500);
        }

        // Validate HMAC signature
        $is_valid = $this->verify_signature($raw_body, $signature_header, $signing_key);
        if (!$is_valid) {
            return new WP_REST_Response(array(
                'status'  => 'error',
                'message' => 'Invalid webhook signature.',
            ), 401);
        }

        $payload = json_decode($raw_body, true);
        if (!is_array($payload)) {
            return new WP_REST_Response(array(
                'status'  => 'error',
                'message' => 'Invalid JSON payload.',
            ), 400);
        }

        // 1. Metadata check: Only process transactions initiated by Quick Pay WP
        if (!$this->is_quick_pay_transaction($payload)) {
            return new WP_REST_Response(array(
                'status'  => 'ok',
                'message' => 'Ignored: Transaction not initiated by Quick Pay WP.',
            ), 200);
        }

        // 2. Status check: Only record successful payments
        if (!$this->is_payment_success($payload)) {
            return new WP_REST_Response(array(
                'status'  => 'ok',
                'message' => 'Ignored: Only successful payments are recorded.',
            ), 200);
        }

        // 3. Extract amount, description, customer phone number, and payment ID
        $payment_data = $this->extract_payment_details($payload);
        if (empty($payment_data['payment_id']) || $payment_data['amount'] <= 0) {
            return new WP_REST_Response(array(
                'status'  => 'ok',
                'message' => 'Ignored: Incomplete payment data.',
            ), 200);
        }

        // 4. Record to database (with idempotency)
        $this->record_successful_payment($payment_data);

        return new WP_REST_Response(array(
            'status'  => 'success',
            'message' => 'Payment recorded successfully.',
        ), 200);
    }

    /**
     * Check if the incoming payload has Method: "Quick Pay WP" in meta_data.
     *
     * @param array $payload
     * @return bool
     */
    private function is_quick_pay_transaction($payload) {
        if (!is_array($payload)) {
            return false;
        }

        $candidates = array();

        if (isset($payload['event_object']['payment']['meta_data'])) {
            $candidates[] = $payload['event_object']['payment']['meta_data'];
        }
        if (isset($payload['event_object']['payments_session']['meta_data'])) {
            $candidates[] = $payload['event_object']['payments_session']['meta_data'];
        }
        if (isset($payload['event_object']['meta_data'])) {
            $candidates[] = $payload['event_object']['meta_data'];
        }
        if (isset($payload['data']['payment']['meta_data'])) {
            $candidates[] = $payload['data']['payment']['meta_data'];
        }
        if (isset($payload['data']['payments_session']['meta_data'])) {
            $candidates[] = $payload['data']['payments_session']['meta_data'];
        }
        if (isset($payload['data']['meta_data'])) {
            $candidates[] = $payload['data']['meta_data'];
        }
        if (isset($payload['payment']['meta_data'])) {
            $candidates[] = $payload['payment']['meta_data'];
        }
        if (isset($payload['payments_session']['meta_data'])) {
            $candidates[] = $payload['payments_session']['meta_data'];
        }
        if (isset($payload['meta_data'])) {
            $candidates[] = $payload['meta_data'];
        }

        foreach ($candidates as $meta) {
            if (is_array($meta)) {
                foreach ($meta as $k => $v) {
                    if (is_array($v) && isset($v['key'], $v['value'])) {
                        if (strcasecmp($v['key'], 'Method') === 0 && strcasecmp($v['value'], 'Quick Pay WP') === 0) {
                            return true;
                        }
                    } elseif (strcasecmp((string) $k, 'Method') === 0 && strcasecmp((string) $v, 'Quick Pay WP') === 0) {
                        return true;
                    }
                }
            }
        }

        // Recursive safety check across any nested property in the payload
        $found = false;
        array_walk_recursive($payload, function ($val, $key) use (&$found) {
            if ($found) {
                return;
            }
            if (is_string($val) && strcasecmp(trim($val), 'Quick Pay WP') === 0) {
                $found = true;
            }
        });

        return $found;
    }

    /**
     * Check if the event or status represents a successful payment.
     *
     * @param array $payload
     * @return bool
     */
    private function is_payment_success($payload) {
        $event_type = isset($payload['event_type']) ? strtolower($payload['event_type']) : (
            isset($payload['event']) ? strtolower($payload['event']) : ''
        );

        if (strpos($event_type, 'succeed') !== false || strpos($event_type, 'success') !== false || strpos($event_type, 'paid') !== false || strpos($event_type, 'completed') !== false) {
            return true;
        }

        $payment = !empty($payload['event_object']['payment']) && is_array($payload['event_object']['payment'])
            ? $payload['event_object']['payment']
            : (!empty($payload['data']['payment']) && is_array($payload['data']['payment'])
                ? $payload['data']['payment']
                : (!empty($payload['payment']) && is_array($payload['payment']) ? $payload['payment'] : array()));

        $status_candidates = array(
            isset($payment['status']) ? $payment['status'] : '',
            isset($payment['payment_status']) ? $payment['payment_status'] : '',
            isset($payload['status']) ? $payload['status'] : '',
            isset($payload['payment_status']) ? $payload['payment_status'] : '',
            isset($payload['data']['status']) ? $payload['data']['status'] : '',
        );

        foreach ($status_candidates as $st) {
            $clean_st = strtolower(trim((string) $st));
            if (in_array($clean_st, array('success', 'succeeded', 'paid', 'completed'), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract payment details from Zoho webhook payload.
     *
     * @param array $payload
     * @return array
     */
    private function extract_payment_details($payload) {
        $payment = !empty($payload['event_object']['payment']) && is_array($payload['event_object']['payment'])
            ? $payload['event_object']['payment']
            : (!empty($payload['data']['payment']) && is_array($payload['data']['payment'])
                ? $payload['data']['payment']
                : (!empty($payload['payment']) && is_array($payload['payment']) ? $payload['payment'] : array()));

        // Payment ID
        $payment_id = '';
        if (!empty($payment['payment_id'])) {
            $payment_id = $payment['payment_id'];
        } elseif (!empty($payment['id'])) {
            $payment_id = $payment['id'];
        } elseif (!empty($payload['data']['payment_id'])) {
            $payment_id = $payload['data']['payment_id'];
        } elseif (!empty($payload['payment_id'])) {
            $payment_id = $payload['payment_id'];
        } elseif (!empty($payload['payments_session_id'])) {
            $payment_id = $payload['payments_session_id'];
        }

        // Amount
        $amount = 0.0;
        if (isset($payment['amount'])) {
            $amount = floatval($payment['amount']);
        } elseif (isset($payload['data']['amount'])) {
            $amount = floatval($payload['data']['amount']);
        } elseif (isset($payload['amount'])) {
            $amount = floatval($payload['amount']);
        }

        // Description
        $description = '';
        if (!empty($payment['description'])) {
            $description = $payment['description'];
        } elseif (!empty($payload['data']['description'])) {
            $description = $payload['data']['description'];
        } elseif (!empty($payload['description'])) {
            $description = $payload['description'];
        } elseif (!empty($payload['payments_session']['description'])) {
            $description = $payload['payments_session']['description'];
        }

        // Customer Phone Number
        $phone = '';
        $raw_phone = '';
        if (!empty($payment['phone'])) {
            $raw_phone = trim($payment['phone']);
        } elseif (!empty($payment['customer']['phone'])) {
            $raw_phone = trim($payment['customer']['phone']);
        } elseif (!empty($payment['customer']['phone_number'])) {
            $raw_phone = trim($payment['customer']['phone_number']);
        } elseif (!empty($payload['customer']['phone'])) {
            $raw_phone = trim($payload['customer']['phone']);
        } elseif (!empty($payload['phone'])) {
            $raw_phone = trim($payload['phone']);
        }

        if (!empty($raw_phone)) {
            $dialing = (!empty($payment['dialing_code']) && strpos($raw_phone, '+') === false) ? trim($payment['dialing_code']) . ' ' : '';
            $phone = sanitize_text_field($dialing . $raw_phone);
        }

        return array(
            'payment_id'     => sanitize_text_field($payment_id),
            'amount'         => $amount,
            'description'    => sanitize_text_field($description),
            'customer_phone' => $phone,
        );
    }

    /**
     * Record the successful payment into the database table.
     * Uses atomic INSERT IGNORE on primary key (payment_id) for instant idempotency.
     *
     * @param array $data
     */
    private function record_successful_payment($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zoho_payments';

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table_name} (payment_id, amount, description, customer_phone, created_at) VALUES (%s, %f, %s, %s, %s)",
            $data['payment_id'],
            $data['amount'],
            $data['description'],
            $data['customer_phone'],
            current_time('mysql')
        ));

        // Fallback only if the table was dropped manually outside WordPress
        if (false === $inserted && function_exists('zoho_payments_create_db_tables')) {
            zoho_payments_create_db_tables();
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table_name} (payment_id, amount, description, customer_phone, created_at) VALUES (%s, %f, %s, %s, %s)",
                $data['payment_id'],
                $data['amount'],
                $data['description'],
                $data['customer_phone'],
                current_time('mysql')
            ));
        }
    }

    /**
     * Verify Zoho Payments HMAC-SHA256 signature.
     * Header format: t={timestamp},v={signature}
     * Signed string: {timestamp}.{raw_body}
     *
     * @param string $raw_body
     * @param string $signature_header
     * @param string $signing_key
     * @return bool
     */
    public function verify_signature($raw_body, $signature_header, $signing_key) {
        if (empty($signature_header) || empty($signing_key)) {
            return false;
        }

        $parts = explode(',', $signature_header);
        $timestamp = null;
        $signature = null;

        foreach ($parts as $part) {
            $part = trim($part);
            if (strpos($part, 't=') === 0) {
                $timestamp = substr($part, 2);
            } elseif (strpos($part, 'v=') === 0) {
                $signature = substr($part, 2);
            }
        }

        if (empty($timestamp) || empty($signature)) {
            return false;
        }

        $current_time = time();
        $event_time = is_numeric($timestamp) ? (float) $timestamp : 0;

        if ($event_time > 10000000000) {
            $event_time = $event_time / 1000;
        }

        $tolerance = 15 * MINUTE_IN_SECONDS;
        if (abs($current_time - $event_time) > $tolerance) {
            return false;
        }

        $data_to_sign = $timestamp . '.' . $raw_body;
        $calculated_signature = hash_hmac('sha256', $data_to_sign, $signing_key);

        return hash_equals($calculated_signature, $signature);
    }
}
