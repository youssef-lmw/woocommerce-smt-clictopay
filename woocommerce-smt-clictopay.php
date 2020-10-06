<?php
/*
Plugin Name: WooCommerce SMT ClicToPay
Plugin URI: http://lightmyweb.fr/
Description: Ce module vous permet d'accepter les paiements en ligne par SPS Clictopay SMT (Light My Web).
Version: 1.1.0
Author: Light My Web (Youssef Gharbi)
Author URI: https://www.linkedin.com/in/gharbi-youssef-b38b451a2/
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}
/**
 *
 * Provides a Custom Payment Gateway, mainly for testing purposes.
 */

    add_action('plugins_loaded', 'woocommerce_yg_smt_init', 0);

    function woocommerce_yg_smt_init() {
        if ( !class_exists( 'WC_Payment_Gateway' ) ) return;
        /**
         * Gateway class
         */
    class WC_Yg_SMT extends WC_Payment_Gateway{
    	protected $msg = array();
    	public function __construct(){

    		$this->id= 'lmw_yg_smt_clictopay';
    		$this->method_title = 'SMT ClicToPay';
            $this->method_description = 'Accepter les paiements par ClicToPay SMT';
            $this->icon = 'https://www.drupal.org/files/project-images/ClicToPay_logo.png';
    		$this->has_fields = false;
    		$this->init_form_fields();
    		$this->init_settings();
    		$this->title = $this->get_option('title');
    		$this->description = $this->get_option('description');
    		$this->affilie = $this->get_option('affilie');
    		$this->liveurl = $this->get_option('sandbox') ? 'https://www.smt-sps.com.tn/clicktopay/' : 'https://www.smt-sps.com.tn/clicktopay/';
    		$this->msg['message'] = "";
    		$this->msg['class'] = "";

    		add_action('init', array(&$this, 'check_smt_response'));

            //update for woocommerce >2.0
    		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_smt_response' ) );
    		add_action('valid-smt-request', array(&$this, 'successful_request'));
    		if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
    			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
    		} else {
    			add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
    		}
    		add_action('woocommerce_receipt_smt', array(&$this, 'receipt_page'));
    		add_action('woocommerce_thankyou_smt',array(&$this, 'thankyou_page'));
    	}

    	function init_form_fields(){
    		$this->form_fields = array(
    			'enabled' => array(
    				'title' => __('Enable/Disable', 'yg'),
    				'type' => 'checkbox',
    				'label' => __('Enable SMT Payment Module.', 'yg'),
    				'default' => 'no'),
    			'title' => array(
    				'title' => __('Title:', 'yg'),
    				'type'=> 'text',
    				'description' => __('This controls the title which the user sees during checkout.', 'yg'),
    				'default' => __('SMT ClicToPay', 'yg')),
    			'description' => array(
    				'title' => __('Description:', 'yg'),
    				'type' => 'textarea',
    				'description' => __('This controls the description which the user sees during checkout.', 'yg'),
    				'default' => __('Payment method description', 'yg')),
    			'affilie' => array(
    				'title' => __('Affilie', 'yg'),
    				'type' => 'text',
    				'description' => __('')),
    			'sandbox' => array(
    				'title' => __('Sandbox', 'yg'),
    				'type' => 'checkbox',
    				'label' => __('Enable Sandbox Mode.', 'yg'),
    				'default' => 'no')
    			);
		}

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         **/
        public function admin_options(){
     		$ipn_url = add_query_arg( 'wc-api', get_class( $this ), get_site_url() );

        	echo '<h3>'.__('Clictopay SMT', 'yg').'</h3>';
      		echo '<p>'.__( 'IPN URL: "<b><i>'.$ipn_url.'</i></b>".' ).'</p>';
            echo '<p>Entrez les informations de configuration pour Clictopay SMT.</p>';


        	echo '<table class="form-table">';
        	$this->generate_settings_html();
        	echo '</table>';
        }

        /**
         *  There are no payment fields for CCAvenue, but we want to show the description if set.
         **/
        function payment_fields(){
        	if($this->description) echo wpautop(wptexturize($this->description));
        }

        /**
         * Receipt Page
         **/
        function receipt_page($order){
        	echo '<p>'.__('Thank you for your order, please click the button below to pay with SMT Payment Gateway.', 'yg').'</p>';
        	echo $this->generate_smt_form($order);
        }

        /**
         * Process the payment and return the result
         **/
        function process_payment($order_id){
        	$order = new WC_Order($order_id);
        	return array('result' => 'success', 'redirect' => add_query_arg('order',
        		$order->id, add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))))
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
        	switch($action) {
        		case "DETAIL":
        		if ($order = new WC_Order($reference)) {
        			echo "Reference=".$reference."&Action=".$action."&Reponse=".$order->order_total;
        			die();
        		}
        		break;

        		case "ERREUR":
        		echo "Reference=".$reference. "&Action=".$action."&Reponse=OK";
        		break;

        		case "ACCORD":
        		$order->payment_complete();
        		$order->add_order_note('SMT payment successful<br/>Transaction Id: '.$param);
        		$woocommerce->cart->empty_cart();

        		echo "Reference=".$reference. "&Action=".$action."&Reponse=OK";
        		break;

        		case "REFUS":
        		echo "Reference=".$reference. "&Action=".$action."&Reponse=OK";
        		break;

        		case "ANNULATION":
        		echo "Reference=".$reference. "&Action=".$action."&Reponse=OK";
        		break;
        	}
        }

        /**
         * Generate SMT button link
         **/
        public function generate_smt_form($order_id){
        	global $woocommerce;
        	$order = new WC_Order($order_id);
        	/*
        	 <input type="hidden" name="affilie" value="{$affiliate}" />
			  <input type="hidden" name="Devise" value="{$currency}" />
			  <input type="hidden" name="Reference" value="{$reference}" />
			  <input type="hidden" name="Montant" value="{$total}" />
			  <input type="hidden" name="sid" value="{$session_id}" />
			*/
		   $smt_args = array(
              'affilie' => $this->affilie,
              'devise' => 'TND', // TND = Tunisian Dinar (which is the tunisian currency
              'reference' => $order_id,
              'montant' => number_format($order->order_total, 3, '.', ''),
              'sid' => $order_id); // this is the session ID 

    		$smt_args_array = array();
    		foreach($smt as $key => $value){
    			$smt_args_array[] = "<input type='hidden' name='$key' value='$value'/>";
    		}
    		return '<form action="'.$this->liveurl.'" method="post" id="smt_payment_form">
    		' . implode('', $smt_args_array) . '
    		<input type="submit" class="button-alt" id="submit_smt_payment_form" value="'.__('Pay via smt', 'yg').'" /> <a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Cancel order &amp; restore cart', 'yg').'</a>
    		
    		</form>';
        }
        // get all pages
        function get_pages($title = false, $indent = true) {
        	$wp_pages = get_pages('sort_column=menu_order');
        	$page_list = array();
        	if ($title) $page_list[] = $title;
        	foreach ($wp_pages as $page) {
        		$prefix = '';
                // show indented child pages?
        		if ($indent) {
        			$has_parent = $page->post_parent;
        			while($has_parent) {
        				$prefix .=  ' - ';
        				$next_page = get_page($has_parent);
        				$has_parent = $next_page->post_parent;
        			}
        		}
                // add to page list array array
        		$page_list[$page->ID] = $prefix . $page->post_title;
        	}
        	return $page_list;
        }
    }

    /*
    * This action hook registers our PHP class as a WooCommerce payment gateway
    */
    function woocommerce_add_yg_smt_gateway($methods) {
    	$methods[] = 'WC_Yg_SMT';
    	return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_yg_smt_gateway' );
}

?>
