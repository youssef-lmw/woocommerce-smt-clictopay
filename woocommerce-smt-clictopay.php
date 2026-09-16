<?php
/*
 * Plugin Name: WooCommerce SMT ClicToPay
 * Plugin URI: https://www.linkedin.com/in/gharbi-youssef/
 * Description: Ce module vous permet d'accepter les paiements en ligne par carte bancaire via SPS ClicToPay (Monétique Tunisie) dans WooCommerce.
 * Version: 3.0.1
 * Author: Youssef Gharbi
 * Author URI: https://www.linkedin.com/in/gharbi-youssef/
 * License: GPL2
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Text Domain: clictopay-for-woocommerce
 */

defined('ABSPATH') or die('No script kiddies please!');

define('CFW_CTP_VERSION', '3.0.1');
define('CFW_CTP_GATEWAY_ID', 'cc_ctp');
define('CFW_CTP_LOG_SOURCE', 'clictopay');

/**
 * ISO 4217 codes and minor-unit multipliers for the currencies ClicToPay supports.
 */
function cfw_ctp_supported_currencies() {
    return array(
        'TND' => array('code' => 788, 'multiplier' => 1000),
        'EUR' => array('code' => 978, 'multiplier' => 100),
        'USD' => array('code' => 840, 'multiplier' => 100),
    );
}

/**
 * The live gateway instance, or null when WooCommerce is not ready.
 */
function cfw_ctp_get_gateway() {
    if (!function_exists('WC') || !WC()->payment_gateways()) {
        return null;
    }
    $gateways = WC()->payment_gateways()->payment_gateways();
    return isset($gateways[CFW_CTP_GATEWAY_ID]) ? $gateways[CFW_CTP_GATEWAY_ID] : null;
}

add_action('before_woocommerce_init', 'cfw_ctp_declare_hpos_compatibility');
function cfw_ctp_declare_hpos_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
}

add_filter('woocommerce_payment_gateways', 'cfw_ctp_add_credit_card_gateway_class');
function cfw_ctp_add_credit_card_gateway_class($gateways) {
    $gateways[] = 'CFW_ClicToPay_Credit_Card_Gateway';
    return $gateways;
}

add_action('plugins_loaded', 'cfw_ctp_init_credit_card_gateway_class');
function cfw_ctp_init_credit_card_gateway_class() {

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class CFW_ClicToPay_Credit_Card_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id                 = CFW_CTP_GATEWAY_ID;
            $this->icon               = '';
            $this->has_fields         = false;
            $this->method_title       = __('Credit Card using ClicToPay', 'clictopay-for-woocommerce');
            $this->method_description = __('Enable paying with Credit Card using ClicToPay', 'clictopay-for-woocommerce');

            $this->supports = array('products');

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled     = $this->get_option('enabled');
            $this->testmode    = 'yes' === $this->get_option('testmode');
            $this->debug       = 'yes' === $this->get_option('debug');
            $this->username    = $this->get_option('username');
            $this->password    = $this->get_option('password');
            $this->language    = $this->get_option('language', 'fr');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'label' => 'Credit Card using ClicToPay',
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default' => 'Carte de crédit',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default' => 'Payer avec votre carte bancaire à travers le service ClicToPay.',
                ),
                'testmode' => array(
                    'title' => 'Test mode',
                    'label' => 'Enable Test Mode',
                    'type' => 'checkbox',
                    'description' => 'Use the ClicToPay sandbox (test.clictopay.com) instead of production (ipay.clictopay.com).',
                    'default' => 'yes',
                    'desc_tip' => true,
                ),
                'username' => array(
                    'title' => 'Api-User Login',
                    'type' => 'text',
                    'description' => 'Provided by ClicToPay'
                ),
                'password' => array(
                    'title' => 'Api-User Password',
                    'type' => 'password',
                    'description' => 'Provided by ClicToPay'
                ),
                'language' => array(
                    'title' => 'Payment page language',
                    'type' => 'select',
                    'default' => 'fr',
                    'options' => array(
                        'fr' => 'Français',
                        'en' => 'English',
                        'ar' => 'Arabic',
                    ),
                ),
                'debug' => array(
                    'title' => 'Debug log',
                    'label' => 'Log every ClicToPay API request and response',
                    'type' => 'checkbox',
                    'description' => 'Saved under WooCommerce > Status > Logs (source: clictopay). Keep it on while validating the integration.',
                    'default' => 'yes',
                ),
            );
        }

        /**
         * Base URL of the ClicToPay REST API for the current mode.
         */
        public function api_base_url() {
            return ($this->testmode ? 'https://test.clictopay.com' : 'https://ipay.clictopay.com') . '/payment/rest/';
        }

        public function log($message) {
            if (!$this->debug || !function_exists('wc_get_logger')) {
                return;
            }
            wc_get_logger()->info($message, array('source' => CFW_CTP_LOG_SOURCE));
        }

        /**
         * POST to a ClicToPay endpoint.
         *
         * Returns array( 'raw' => string, 'data' => array|null, 'error' => WP_Error|null ).
         * The raw body is what has to be pasted into the integration validation grid.
         */
        public function api_request($endpoint, $params, $label = '') {
            $url = $this->api_base_url() . $endpoint;

            $loggable = $params;
            if (isset($loggable['password'])) {
                $loggable['password'] = '***';
            }
            $this->log(sprintf('[%s] POST %s %s', $label ? $label : $endpoint, $url, wp_json_encode($loggable)));

            $response = wp_remote_post($url, array(
                'timeout' => 45,
                'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
                'body'    => $params,
            ));

            if (is_wp_error($response)) {
                $this->log(sprintf('[%s] TRANSPORT ERROR: %s', $label ? $label : $endpoint, $response->get_error_message()));
                return array('raw' => '', 'data' => null, 'error' => $response);
            }

            $raw  = wp_remote_retrieve_body($response);
            $code = wp_remote_retrieve_response_code($response);
            $this->log(sprintf('[%s] HTTP %d %s', $label ? $label : $endpoint, $code, $raw));

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return array(
                    'raw'   => $raw,
                    'data'  => null,
                    'error' => new WP_Error('cfw_ctp_bad_json', __('Invalid response from ClicToPay.', 'clictopay-for-woocommerce')),
                );
            }

            return array('raw' => $raw, 'data' => $data, 'error' => null);
        }

        /**
         * Order total converted to the currency minor unit (millimes for TND).
         */
        protected function get_minor_amount($order, $multiplier) {
            return (int) round((float) $order->get_total() * $multiplier);
        }

        /**
         * A per-attempt order number, so retrying a failed payment never hits
         * ClicToPay errorCode 1 (duplicate order number).
         */
        protected function build_order_number($order) {
            $attempt = (int) $order->get_meta('_cfw_ctp_attempt');
            $attempt++;
            $order->update_meta_data('_cfw_ctp_attempt', $attempt);

            $order_number = $order->get_order_number();
            if ($attempt > 1) {
                $order_number .= '-' . $attempt;
            }
            return $order_number;
        }

        public function return_url_for($order, $fail = false) {
            $args = array(
                'cfw_order' => $order->get_id(),
                'key'       => $order->get_order_key(),
            );
            if ($fail) {
                $args['cfw_fail'] = 1;
            }
            return add_query_arg($args, WC()->api_request_url('cfw_ctp_return'));
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                wc_add_notice(__('Order not found.', 'clictopay-for-woocommerce'), 'error');
                return;
            }

            $currencies = cfw_ctp_supported_currencies();
            $currency   = $order->get_currency();
            if (!isset($currencies[$currency])) {
                wc_add_notice(sprintf(__('Currency %s is not supported by ClicToPay.', 'clictopay-for-woocommerce'), $currency), 'error');
                return;
            }

            $order_number = $this->build_order_number($order);

            $params = array(
                'userName'    => $this->username,
                'password'    => $this->password,
                'orderNumber' => $order_number,
                'amount'      => $this->get_minor_amount($order, $currencies[$currency]['multiplier']),
                'currency'    => $currencies[$currency]['code'],
                'returnUrl'   => $this->return_url_for($order, false),
                'failUrl'     => $this->return_url_for($order, true),
                'description' => sprintf(__('Order %s', 'clictopay-for-woocommerce'), $order_number),
                'language'    => $this->language,
                // Always DESKTOP: that template is responsive, while the MOBILE
                // one described in the integration manual answers 404.
                'pageView'    => 'DESKTOP',
            );

            $result = $this->api_request('register.do', $params, 'register');

            if ($result['error']) {
                $order->save();
                wc_add_notice(__('Connection error.', 'clictopay-for-woocommerce'), 'error');
                return;
            }

            $body = $result['data'];

            if (isset($body['errorCode']) && (int) $body['errorCode'] !== 0) {
                $order->save();
                $message = isset($body['errorMessage']) ? $body['errorMessage'] : __('Payment registration failed.', 'clictopay-for-woocommerce');
                $order->add_order_note(sprintf(__('ClicToPay register.do failed (errorCode %1$s): %2$s', 'clictopay-for-woocommerce'), $body['errorCode'], $message));
                wc_add_notice($message, 'error');
                return;
            }

            if (empty($body['orderId']) || empty($body['formUrl'])) {
                $order->save();
                wc_add_notice(__('Invalid response from ClicToPay.', 'clictopay-for-woocommerce'), 'error');
                return;
            }

            // orderId is the key accepted by getOrderStatusExtended.do, so it must be stored.
            $order->update_meta_data('_cfw_ctp_order_id', sanitize_text_field($body['orderId']));
            $order->update_meta_data('_cfw_ctp_order_number', $order_number);
            $order->add_order_note(sprintf(__('ClicToPay order registered. orderId: %s', 'clictopay-for-woocommerce'), $body['orderId']));
            $order->save();

            return array(
                'result'   => 'success',
                'redirect' => $body['formUrl'],
            );
        }

        /**
         * Query the real status of an order. Never trust the redirection alone.
         */
        public function get_order_status_extended($clictopay_order_id) {
            return $this->api_request('getOrderStatusExtended.do', array(
                'userName' => $this->username,
                'password' => $this->password,
                'orderId'  => $clictopay_order_id,
                'language' => $this->language,
            ), 'getOrderStatusExtended');
        }

        /**
         * Handles both returnUrl and failUrl. The decision comes from
         * getOrderStatusExtended.do only, never from which URL was hit.
         */
        public function handle_return() {
            $order_id  = isset($_GET['cfw_order']) ? absint($_GET['cfw_order']) : 0;
            $order_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
            $order     = $order_id ? wc_get_order($order_id) : false;

            if (!$order || !hash_equals($order->get_order_key(), $order_key)) {
                $this->log(sprintf('[return] Rejected callback for order %d: invalid order key.', $order_id));
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            }

            if ($order->is_paid()) {
                wp_safe_redirect($this->get_return_url($order));
                exit;
            }

            $clictopay_order_id = $order->get_meta('_cfw_ctp_order_id');
            if (empty($clictopay_order_id)) {
                $this->fail_order($order, __('No ClicToPay orderId stored for this order.', 'clictopay-for-woocommerce'));
            }

            $result = $this->get_order_status_extended($clictopay_order_id);

            if ($result['error']) {
                $this->fail_order($order, __('Unable to reach ClicToPay to confirm the payment.', 'clictopay-for-woocommerce'));
            }

            $body        = $result['data'];
            $error_code  = isset($body['errorCode']) ? (int) $body['errorCode'] : 0;
            $status      = isset($body['orderStatus']) ? (int) $body['orderStatus'] : -1;
            $action_code = isset($body['actionCode']) ? $body['actionCode'] : '';

            // orderStatus = 2 is the only value that means the money was taken.
            if (0 !== $error_code || 2 !== $status) {
                $reason = !empty($body['actionCodeDescription'])
                    ? $body['actionCodeDescription']
                    : (isset($body['errorMessage']) ? $body['errorMessage'] : __('Payment not completed.', 'clictopay-for-woocommerce'));

                $order->add_order_note(sprintf(
                    __('ClicToPay payment not confirmed. orderStatus: %1$s, actionCode: %2$s, errorCode: %3$s. %4$s', 'clictopay-for-woocommerce'),
                    $status >= 0 ? $status : 'n/a',
                    '' !== $action_code ? $action_code : 'n/a',
                    $error_code,
                    $reason
                ));
                $this->fail_order($order, $reason);
            }

            $transaction_id = '';
            if (!empty($body['cardAuthInfo']['approvalCode'])) {
                $transaction_id = $body['cardAuthInfo']['approvalCode'];
            } elseif (!empty($body['authRefNum'])) {
                $transaction_id = $body['authRefNum'];
            }

            if (!empty($body['cardAuthInfo']['pan'])) {
                $order->add_order_note(sprintf(
                    __('ClicToPay payment accepted. Card: %1$s, approval code: %2$s.', 'clictopay-for-woocommerce'),
                    $body['cardAuthInfo']['pan'],
                    $transaction_id ? $transaction_id : 'n/a'
                ));
            }

            // payment_complete() already reduces stock through wc_maybe_reduce_stock_levels().
            $order->payment_complete($transaction_id);
            $order->save();

            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }

            wp_safe_redirect($this->get_return_url($order));
            exit;
        }

        /**
         * Marks the order failed and sends the customer back to checkout. Does not return.
         */
        protected function fail_order($order, $message) {
            if (!$order->has_status(array('failed', 'cancelled'))) {
                $order->update_status('failed', $message);
            }
            wc_add_notice(sprintf(__('Payment failed: %s', 'clictopay-for-woocommerce'), $message), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }
    }
}

add_action('woocommerce_api_cfw_ctp_return', 'cfw_ctp_handle_return');
function cfw_ctp_handle_return() {
    $gateway = cfw_ctp_get_gateway();
    if (!$gateway) {
        wp_safe_redirect(home_url('/'));
        exit;
    }
    $gateway->handle_return();
}

require_once plugin_dir_path(__FILE__) . 'includes/class-cfw-ctp-diagnostics.php';
