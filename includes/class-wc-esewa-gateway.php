<?php

defined( 'ABSPATH' ) || exit;

class WC_Esewa_Gateway extends WC_Payment_Gateway {
    const LIVE_URL = "https://epay.esewa.com.np/api/epay/main/v2/form";
    const TEST_URL = "https://rc-epay.esewa.com.np/api/epay/main/v2/form";
    public static $log_enabled = false;

    public static $log = false;

    public function __construct() {
        $this->id = 'esewa';
            
        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            array($this, 'process_admin_options')
        );
        add_action('woocommerce_receipt_' . $this->id, array( $this, 'esewa_woocommerce_confirmation_form'));
        add_action('admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

        $this->icon = apply_filters( 'woocommerce_esewa_icon', plugins_url('/assets/images/eSewa_logo.png', WC_ESEWA_PLUGIN_FILE ) );
        $this->has_fields = false;
        $this->order_button_text = __( "Procced to eSewa", 'esewa-woocommerce');
        $this->method_title = __('eSewa', 'esewa-woocommerce');
        $this->method_description = __('Payment via eSewa - sends customers to eSewa protal', 'esewa-woocommerce');

        // load the setting
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->testmode = 'yes' === $this->get_option('testmode', 'no');
        $this->debug = 'yes' === $this->get_option('debug', 'no');
        $this->merchant_secret = $this->get_option('merchant_secret');
        $this->product_code = $this->get_option('product_code');

        // API endpoints
        $this->payment_request_api_endpoint = self::LIVE_URL;

        // Enable logging for events.
        self::$log_enabled = $this->debug;

        // Test mode
        if ( $this->testmode ) {
            $this->description .= ' ' . __( 'SANDBOX ENABLED. You can use testing accounts only.', 'esewa-woocommerce');
            $this->description = trim( $this->description );
            $this->merchant_secret = $this->get_option('sandbox_merchant_secret');
            $this->product_code = $this->get_option('sandbox_product_code');
            $this->payment_request_api_endpoint = self::TEST_URL;
        }

        if ( ! $this->is_valid_for_use() ) {
            $this->enabled = 'no';
        } elseif ( $this->product_code && $this->merchant_secret ) {
            include_once dirname( __FILE__ ) . '/gateways/class-wc-esewa-gateway-ipn-handler.php';
            new WC_Esewa_Gateway_IPN_Handler( $this, $this->testmode );
        }        
    }

    // save log
    public static function log( $message, $level = 'info' ) {
        if ( self::$log_enabled ) {
            if ( empty( self::$log) ) {
                self::$log = wc_get_logger();
            }
            self::$log->log( $level, $message, array( 'source' => 'esewa' ) );
        }
    }    

    public function process_admin_options()
    {
        $saved = parent::process_admin_options();

        if ('yes' !== $this->get_option('debug', 'no')) {
            if (empty(self::$log)) {
                self::$log = wc_get_logger();
            }

            self::$log->clear('esewa');
        }
        
        return $saved;
    }

    // check currency
    public function is_valid_for_use() {
        return in_array( get_woocommerce_currency(), 
        apply_filters( 'woocommerce_esewa_supported_currencies', 
        array( 'NPR' ) ), 
        true );
    }

    // initialization of admin form
    public function init_form_fields() {
        $this->form_fields = include 'gateways/esewa-settings.php';
    }

    // redirect to payment process
    public function process_payment($order_id)
    {
        $order = wc_get_order( $order_id );

        $redirect = $order->get_checkout_payment_url(true);
        return array(
            'result' => 'success',
            'redirect' => $redirect
        );
    }

    // Generate confirmation formation form
    public function esewa_woocommerce_confirmation_form($order_id)
	{
        include_once dirname( __FILE__ ) . '/gateways/class-wc-esewa-gateway-request.php';

		$order = new WC_Order($order_id);
        $esewa_request = new WC_Esewa_Gateway_Request($this);
        $esewa_args = $esewa_request->get_esewa_args( $order );

		$paymentForm = "";
		$paymentForm .= '<form method="POST" action="'.esc_url($this->payment_request_api_endpoint).'" id="esewa_payment_form" name="esewa_load">';

        $paymentForm .= '<input type="hidden" id="amount" name="amount" value="'.$esewa_args['amount'].'">';
        
        $paymentForm .= '<input type="hidden" id="tax_amount" name="tax_amount" value="'.$esewa_args['tax_amount'].'">';
        
        $paymentForm .= '<input type="hidden" id="total_amount" name="total_amount" value="'.$esewa_args['total_amount'].'">';
        
        $paymentForm .= '<input type="hidden" id="transaction_uuid" name="transaction_uuid" value="'.$esewa_args['transaction_uuid'].'">';
        
        $paymentForm .= '<input type="hidden" id="product_code" name="product_code" value="'.$esewa_args['product_code'].'">';
        
        $paymentForm .= '<input type="hidden" id="product_service_charge" name="product_service_charge" value="'.$esewa_args['product_service_charge'].'">';
        
        $paymentForm .= '<input type="hidden" id="product_delivery_charge" name="product_delivery_charge" value="'.$esewa_args['product_delivery_charge'].'">';
        
        $paymentForm .= '<input type="hidden" id="success_url" name="success_url" value="'.$esewa_args['success_url'].'">';
        
        $paymentForm .= '<input type="hidden" id="failure_url" name="failure_url" value="'.$esewa_args['failure_url'].'">';
        
        $paymentForm .= '<input type="hidden" id="signed_field_names" name="signed_field_names" value="'.$esewa_args['signed_field_names'].'">';
        
        $paymentForm .= '<input type="hidden" id="signature" name="signature" value="'.$esewa_args['signature'].'">';
        
        $paymentForm .= '<input type="submit" value="Proceed to Esewa">';
        
        $paymentForm .= '</form>';

        $paymentForm .= '<script type="text/javascript">document.getElementById("esewa_payment_form").submit();</script>';
        
        echo $paymentForm;
	}

    // load javascript
    public function admin_scripts() {
        $screen = get_current_screen();
        $screen_id = $screen ? $screen->id : '';

        if ( 'woocommerce_page_wc-settings' != $screen_id ) {
            return;
        }

        $suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

        wp_enqueue_script( 'woocommerce_esewa_admin', plugins_url( '/assets/js/esewa-admin' . $suffix . '.js', WC_ESEWA_PLUGIN_FILE), array(), WC_VERSION, true);
    }
}