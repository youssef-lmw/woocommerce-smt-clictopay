<?php
/*
Plugin Name: WooCommerce SMT ClicToPay V2
Plugin URI: https://www.linkedin.com/in/gharbi-youssef-b38b451a2/
Description: Ce module vous permet d'accepter les paiements en ligne par SPS Clictopay SMT
Version: 2.0.1
Author: Youssef Gharbi
Author URI: https://www.linkedin.com/in/gharbi-youssef-b38b451a2/
*/
function wc_ctp_add_clictopay_check_payment_page()
{
    if(get_page_by_title( 'ClicToPay Check Payment' )==null){
        $my_post = array(
            'post_title' => wp_strip_all_tags('ClicToPay Check Payment'),
            'post_content' => '[clictopay_check_payment]',
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => 'page',
        );

        wp_insert_post($my_post);
    }
}

register_activation_hook(__FILE__, 'wc_ctp_add_clictopay_check_payment_page');


/*
 * Create failed payment page
 */
function wc_ctp_add_failed_payment_page()
{
    if(get_page_by_title( 'Failed Payment' )==null){
        $my_post = array(
            'post_title' => wp_strip_all_tags('Failed Payment'),
            'post_content' => 'Failed Payment',
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => 'page',
        );

        wp_insert_post($my_post);
    }
}

register_activation_hook(__FILE__, 'wc_ctp_add_failed_payment_page');

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter('woocommerce_payment_gateways', 'wc_ctp_add_credit_card_gateway_class');
function wc_ctp_add_credit_card_gateway_class($gateways)
{
    $gateways[] = 'WC_ClicToPay_Credit_Card_Gateway';
    return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action('plugins_loaded', 'wc_ctp_init_credit_card_gateway_class');
function wc_ctp_init_credit_card_gateway_class()
{

    class WC_ClicToPay_Credit_Card_Gateway extends WC_Payment_Gateway
    {

        /**
         * Class constructor
         */
        public function __construct()
        {

            $this->id = 'cc_ctp'; // payment gateway plugin ID
            $this->icon = ''; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = false; // in case you need a custom credit card form
            $this->method_title = 'Credit Card using ClicToPay';
            $this->method_description = 'Enable paying with Credit Card using ClicToPay'; // will be displayed on the options page

            // gateways can support subscriptions, refunds, saved payment methods,
            // but in this tutorial we begin with simple payments
            $this->supports = array(
                'products'
            );

            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->testmode = 'yes' === $this->get_option('testmode');
            $this->username = $this->get_option('username');
            $this->password = $this->get_option('password');

            // This action hook saves the settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // We need custom JavaScript to obtain a token
            add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

            // You can also register a webhook here
            // add_action('woocommerce_api_clictopay', array($this, 'webhook'));
        }

        public function get_instance()
        {
            return $this; // return the object
        }

        /**
         * Plugin options
         */
        public function init_form_fields()
        {
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
                    'description' => 'Place the payment gateway in test mode using test API keys.',
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
                )
            );
        }

        public function payment_scripts()
        {
        }

        /*
         * We're processing the payments here
         */
        public function process_payment($order_id)
        {

            global $woocommerce;

            $order = wc_get_order($order_id);

            $response = wp_remote_get(($this->testmode ? 'https://test.' : 'https://ipay.') . 'clictopay.com/payment/rest/register.do?currency=788&amount=' . ($order->get_total() * 1000) . '&orderNumber=' . $order_id . '&password=' . $this->password . '&returnUrl='.get_site_url().'/clictopay-check-payment&userName=' . $this->username);

            $body = json_decode($response['body'], true);

            if (isset($body['errorCode'])) {
                wc_add_notice($body['errorMessage'], 'error');
                return;
            }

            return array(
                'result' => 'success',
                'redirect' => $body['formUrl']
            );

        }

        public function webhook()
        {
        }

        /*
         * Check if the payment is successful or not and redirect to correct page
         */
        public function clictopay_check_payment()
        {

            $response = wp_remote_get(($this->testmode ? 'https://test.' : 'https://ipay.') . 'clictopay.com/payment/rest/getOrderStatus.do?orderId=' . $_GET['orderId'] . '&password=' . $this->password . '&userName=' . $this->username);

            $body = json_decode($response['body'], true);

            if ($body['ErrorMessage'] === 'Success') {
                global $woocommerce;

                $order = wc_get_order($body['OrderNumber']);
                // we received the payment
                $order->payment_complete();
                $order->reduce_order_stock();

                // Empty cart
                $woocommerce->cart->empty_cart();

                $redirect = $this->get_return_url($order);
            } else {
                $redirect = get_site_url().'/failed-payment/';
            }
            wp_redirect($redirect);
            return;
        }
    }

    add_shortcode('clictopay_check_payment', [new WC_ClicToPay_Credit_Card_Gateway, 'clictopay_check_payment']);
}


?>
