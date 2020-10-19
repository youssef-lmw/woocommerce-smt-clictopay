<?php
/*
Plugin Name: WooCommerce SMT ClicToPay V1
Plugin URI: http://lightmyweb.fr/
Description: Ce module vous permet d'accepter les paiements en ligne par SPS Clictopay SMT (Light My Web).
Version: 1.1.0
Author: Light My Web (Youssef Gharbi)
Author URI: https://www.linkedin.com/in/gharbi-youssef-b38b451a2/
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}
add_action('plugins_loaded', 'woocommerce_yg_smt_init', 0);

function woocommerce_yg_smt_init() {

    if ( !class_exists( 'WC_Payment_Gateway' ) ) return;

    $plugin_dir = basename(dirname(__FILE__));
    load_plugin_textdomain( 'yg-smt-woocommerce-payment-gatway', false, $plugin_dir. '/languages/' );

    if(empty(get_woocommerce_currency_symbol('TND'))){
        add_filter( 'woocommerce_currencies', 'add_tnd_currency' );

        add_filter('woocommerce_currency_symbol', 'add_tnd_currency_symbol', 10, 2);
    }
    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_yg_smt_gateway($methods) {
        $methods[] = 'WC_Yg_SMT';
        return $methods;
    }

    function add_tnd_currency( $currencies ) {
        $currencies['TND'] = __( 'Tunisian Dinar', 'woocommerce' );
        return $currencies;
    }

    function add_tnd_currency_symbol( $currency_symbol, $currency ) {
        switch( $currency ) {
            case 'TND': $currency_symbol = 'TND'; break;
        }
        return $currency_symbol;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_yg_smt_gateway' );

    /**
     * Gateway class
     */
    class WC_Yg_SMT extends WC_Payment_Gateway {
        public function __construct(){

            $this->id = 'yg_smt';
            $this->method_title = __('SMT ClicToPay', 'yg-smt-woocommerce-payment-gatway');
            $this->method_description = 'Accepter les paiements par ClicToPay SMT';
            $this->icon = 'http://www.clictopay.com.tn/public_html/img/ClicToPay_logo.png';
            $this ->has_fields = false;
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->affilie = $this->get_option('affilie');

            $this->liveurl = ($this->get_option('sandbox') == 'yes') ? $this->get_option('sandbox_url') : $this->get_option('production_url');

            if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_smt_response' ) );
            } else {
                add_action('init', array(&$this, 'check_smt_response'));
            }

            if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
            } else {
                add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
            }
            add_action('woocommerce_receipt_yg_smt', array(&$this, 'receipt_page'));
        }
        function init_form_fields(){

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable:', 'yg-smt-woocommerce-payment-gatway'),
                    'type' => 'checkbox',
                    'label' => __('Enable SMT Payment Module.', 'yg-smt-woocommerce-payment-gatway'),
                    'default' => 'no'),
                'title' => array(
                    'title' => __('Title:', 'yg-smt-woocommerce-payment-gatway'),
                    'type'=> 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'yg-smt-woocommerce-payment-gatway'),
                    'default' => __('SMT', 'yg-smt-woocommerce-payment-gatway')),
                'description' => array(
                    'title' => __('Description:', 'yg-smt-woocommerce-payment-gatway'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'yg-smt-woocommerce-payment-gatway'),
                    'default' => __('', 'yg-smt-woocommerce-payment-gatway')),
                'affilie' => array(
                    'title' => __('Affiliate:', 'yg-smt-woocommerce-payment-gatway'),
                    'type' => 'text',
                    'description' => __('')),
                'production_url' => array(
                    'title' => __('Production Url:', 'yg-smt-woocommerce-payment-gatway'),
                    'type'=> 'text',
                    'description' => __('This controls the production gateway url.', 'yg-smt-woocommerce-payment-gatway'),
                    'default' => 'https://www.smt-sps.com.tn/paiement/'),
                'sandbox_url' => array(
                    'title' => __('Sandbox Url:', 'yg-smt-woocommerce-payment-gatway'),
                    'type'=> 'text',
                    'description' => __('This controls the sandbox gateway url.', 'yg-smt-woocommerce-payment-gatway'),
                    'default' => 'https://clictopay.monetiquetunisie.com/clicktopay/ '),
                'sandbox' => array(
                    'title' => __('Sandbox Mode:', 'yg-smt-woocommerce-payment-gatway'),
                    'type' => 'checkbox',
                    'label' => __('Enable Sandbox Mode.', 'yg-smt-woocommerce-payment-gatway'),
                    'default' => 'no')
            );

        }

        /**
         * Receipt Page
         **/
        function receipt_page($order){
            echo '<p>'.__('Thank you for your order, please click the button below to pay with SMT Payment Gateway.', 'yg-smt-woocommerce-payment-gatway').'</p>';
            echo $this->generate_smt_form($order);
        }

        /**
         * Generate SMT button link
         **/
        public function generate_smt_form($order_id){
            $order = new WC_Order($order_id);
            $smt_args = array(
                'affilie' => $this->affilie,
                'devise' => get_woocommerce_currency(),
                'reference' => $order_id,
                'montant' => number_format($order->order_total, 3, '.', ''),
                'sid' => $order_id,
                'sandbox' => $this->get_option('sandbox')
            );

            $smt_args_array = array();
            foreach($smt_args as $key => $value){
                $smt_args_array[] = "<input type='hidden' name='$key' value='$value'/>";
            }
            return '<form action="'.$this->liveurl.'" method="post" id="smt_payment_form">
        ' . implode('', $smt_args_array) . '
        <input type="submit" class="button-alt" id="submit_smt_payment_form" value="'.__('Pay via SMT', 'yg-smt-woocommerce-payment-gatway').'" /> <a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order &amp; restore cart', 'yg-smt-woocommerce-payment-gatway').'</a>
        </form>';


        }


        /**
         * Admin Panel Options
         **/
        public function admin_options(){
            $ipn_url = site_url('/wc-api/'.get_class( $this ).'/');

            echo '<h3>'.__('SMT Payment Gateway', 'yg-smt-woocommerce-payment-gatway').'</h3>';
            echo '<p>'.__('', 'yg-smt-woocommerce-payment-gatway').'</p>';
            echo '<p>'.__( 'Notification URL:', 'yg-smt-woocommerce-payment-gatway' ).' <b><i>'.$ipn_url.'</i></b></p>';
            echo '<p>'.__( 'Success URL:', 'yg-smt-woocommerce-payment-gatway' ).' <b><i>'.$ipn_url.'?Action=SUCCESS</i></b></p>';
            echo '<p>'.__( 'ERROR URL:', 'yg-smt-woocommerce-payment-gatway' ).' <b><i>'.$ipn_url.'?Action=ERROR</i></b></p>';

            echo '<table class="form-table">';
            $this->generate_settings_html();
            echo '</table>';

        }
        /**
         * Process the payment and return the result
         **/
        function process_payment($order_id){
            $order = new WC_Order($order_id);
            // return array('result' => 'success', 'redirect' => add_query_arg('order',
            //     $order->id, add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))))
            // );
            return array(
                'result' => 'success',
                'redirect'  => $order->get_checkout_payment_url( true )
            );
        }
        /**
         * Check for valid SMT server callback
         **/
        function check_smt_response(){
            global $woocommerce;

            $reference = (int)$_GET['Reference'];
            $action = $_GET['Action'];
            $param = $_GET['Param'];
            $order = new WC_Order($reference);

            switch($action) {
                case "DETAIL":
                    die("Reference=".$reference."&Action=".$action."&Reponse=".$order->order_total);
                    break;

                case "ERREUR":
                    die("Reference=".$reference. "&Action=".$action."&Reponse=OK");
                    break;

                case "ACCORD":
                    $order->payment_complete();
                    $order->add_order_note('SMT payment successful<br/>Transaction Id: '.$param);
                    $woocommerce->cart->empty_cart();

                    die("Reference=".$reference. "&Action=".$action."&Reponse=OK");
                    break;

                case "REFUS":
                    die("Reference=".$reference. "&Action=".$action."&Reponse=OK");
                    break;

                case "ANNULATION":
                    die("Reference=".$reference. "&Action=".$action."&Reponse=OK");
                    break;

                case "SUCCESS":
                    $customer_orders = get_posts( array(
                        'numberposts' => -1,
                        'meta_key'    => '_customer_user',
                        'meta_value'  => get_current_user_id(),
                        'post_type'   => wc_get_order_types(),
                        'post_status' => array_keys( wc_get_order_statuses() ),
                    ) );
                    $order = new WC_Order( $customer_orders[ 0 ]->ID );
                    wp_redirect( site_url('/checkout/'.get_option( 'woocommerce_checkout_order_received_endpoint' ).'/'.$order->id.'/?key='.$order->order_key));

                    die;
                    break;

                case "ERROR":
                    wc_add_notice( __('Payment error:', 'yg-smt-woocommerce-payment-gatway') . __(' An error occurred during the payment process', 'yg-smt-woocommerce-payment-gatway'), 'error' );
                    $customer_orders = get_posts( array(
                        'numberposts' => -1,
                        'meta_key'    => '_customer_user',
                        'meta_value'  => get_current_user_id(),
                        'post_type'   => wc_get_order_types(),
                        'post_status' => array_keys( wc_get_order_statuses() ),
                    ) );
                    $order = new WC_Order( $customer_orders[ 0 ]->ID );
                    wp_redirect( site_url('/checkout/?order='.$order->id.'&key='.$order->order_key));

                    die;
                    break;
            }
        }
    }
}

?>
