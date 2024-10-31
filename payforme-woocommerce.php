<?php

/**
 * Payforme for WooCommerce
 *
 * Plugin Name: Payforme for WooCommerce
 * Plugin URI:  https://wordpress.org/plugins/payforme/
 * Description: Payforme enables people to pay for the purchases of their friends and families via a secure payment link. Accept ApplePay, GooglePay and all major debit and credit cards from customers in every country. 
 * Version:     2.1.2
 * Author:      Payforme
 * Author URI:  https://payforme.io/
 * License:     GPL-2.0
 * Requires at least:    5.0
 * Tested up to:         6.4.3
 * WC requires at least: 4.7
 * WC tested up to:      8.5.2
 * Text Domain: payforme-for-woocommerce
 */

include_once ABSPATH . 'wp-admin/includes/plugin.php';

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// If Woocommerce is not active, abort.
if (!is_plugin_active('woocommerce/woocommerce.php')) {
    return;
}

define('PAYFORME_APP_NAME', 'Payforme');
define('PAYFORME_API_URL', 'https://us-central1-payforme-prod.cloudfunctions.net/apiV1');
define('PAYFORME_SANDBOX_API_URL', 'https://us-central1-payforme-68ce1.cloudfunctions.net/apiV1');
define('PAYFORME_WOO_CLIENT_ID', 'wc_client_sznSblzWTT4ONCI7drOM0KUPJQxUm5XdSDxUEQm8VaWQI6PxJOECeziURjt5HlEh');
define('PAYFORME_FRONTEND_URL', 'https://app.payforme.io');
define('PAYFORME_SANDBOX_FRONTEND_URL', 'https://sandbox.payforme.io');

add_action('before_woocommerce_init', 'before_woocommerce_hpos');
// Mark plugin as High Performance Order Storage compatible
function before_woocommerce_hpos (){ 
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) { 
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true ); 
    }
}


register_activation_hook(__FILE__, 'payforme_plugin_activated');
function payforme_plugin_activated() {
    payforme_plugin_activation_changed(true);
}

add_action('deactivated_plugin', 'payforme_plugin_deactivated');
function payforme_plugin_deactivated($plugin) {
    payforme_plugin_activation_changed(false);
}

function payforme_plugin_activation_changed($is_activated) {
    $store_url = get_bloginfo('url');
    $admin_email = get_option('admin_email');
    $requestUrl = PAYFORME_API_URL;

    $args = [
        'method'     => 'POST',
        'timeout'    => 30,
        'headers'    => payforme_get_request_headers(),
        'body'       => [
            'function'            => 'pluginActivation',
            'source'              => 'woocommerce',
            'store_url'              => $store_url,
            'store_email'              => $admin_email,
            'activated'              => $is_activated,
        ],
    ];

    wp_remote_post($requestUrl, $args);
}

function payforme_get_request_headers() {
    $headers = [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Authorization' => 'ClientId ' . PAYFORME_WOO_CLIENT_ID,
    ];
    return $headers;
}

/**
 * plugins_loaded: Callback after plugins are loaded.
 * payforme_gateway_init: initial function to call after callback.
 * 11: Some default priority value.
 */
add_action('plugins_loaded', 'payforme_gateway_init', 11);
function payforme_gateway_init() {
    /**
     * @property string $id
     * @property boolean $has_fields
     * @property string $method_title
     * @property string $method_description
     * @property string $title
     * @property string $description
     * @property array $form_fields
     */
    class PayForMe_Gateway extends WC_Payment_Gateway {
        const PAYFORME_GATEWAY_ID = 'payforme-gateway';
        const PAYFORME_DOMAIN = 'woocommerce';

        public function __construct() {
            $this->id = self::PAYFORME_GATEWAY_ID; // id of the Gateway
            $this->has_fields = false; // fields to see when you click setup. Leave empty for now.
            $this->method_title = __(PAYFORME_APP_NAME, self::PAYFORME_DOMAIN);
            $this->method_description = __(PAYFORME_APP_NAME . ' lets you accept payments from friends and families of shoppers. Accept ApplePay, GooglePay and all major debit and credit cards from customers in every country.', self::PAYFORME_DOMAIN);

            // Display info for the plugin in checkout UI.
            $this->title = PAYFORME_APP_NAME;
            $this->description = PAYFORME_APP_NAME . ' lets you send a payment link to anyone you’d like to pay for your purchase';

            $this->init_form_fields();
            $this->init_settings();

            // Allows updating form fields.
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields() {
            $this->form_fields = apply_filters('payforme_form_fields', array(
                'enabled' => array(
                    'title' => __('Enable/Disable', self::PAYFORME_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __(payforme_enable_description_html(), self::PAYFORME_DOMAIN),
                    'required' => true,
                    'default' => 'no',
                ),

                'sandbox_enabled' => array(
                    'title' => __('Enable Sandbox Mode', self::PAYFORME_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __('(For testing only) Enable or Disable Sandbox Mode. 
                    <br/>If enabled, <a href=' . get_connect_store_url(PAYFORME_SANDBOX_FRONTEND_URL) . ' 
                    target="_blank">Connect Your Store to ' . PAYFORME_APP_NAME . ' Sandbox</a> instead.', self::PAYFORME_DOMAIN),
                    'default' => 'no',
                ),

                'show_button_in_cart' => array(
                    'title' => __('Show the ' . PAYFORME_APP_NAME . ' button in the shopping cart', self::PAYFORME_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __('Show or hide the ' . PAYFORME_APP_NAME . ' button in the shopping cart', self::PAYFORME_DOMAIN),
                    'required' => true,
                    'default' => 'yes',
                ),

                'show_button_in_checkout' => array(
                    'title' => __('Show the ' . PAYFORME_APP_NAME . ' button in the checkout page', self::PAYFORME_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __('Show or hide the ' . PAYFORME_APP_NAME . ' button in the checkout page', self::PAYFORME_DOMAIN),
                    'required' => true,
                    'default' => 'yes',
                ),

            ));
        }

        /**
         * @return WC_Payment_Gateway instance for payforme plugin.
         */
        public static function getGateway() {
            return WC()->payment_gateways->payment_gateways()[PayForMe_Gateway::PAYFORME_GATEWAY_ID];
        }
    } // end of class PayForMe_Gateway

    function get_connect_store_url($frontend_base_url) {
        $site_url = get_site_url();
        $connect_store_url = $frontend_base_url . '/connect-woo-store-msg?store_url=' . $site_url;
        return $connect_store_url;
    }

    function payforme_enable_description_html() {
        $html = 'Enable or Disable ' . PAYFORME_APP_NAME . ' Payment Gateway. ';
        $html .= '<br/>Please ensure that your store is connected so everything works as expected.';
        $html .= '<br/><a class="button" href="' . get_connect_store_url(PAYFORME_FRONTEND_URL) . '" target="_blank"> CLICK HERE TO CONNECT YOUR STORE </a>';
        return $html;
    }

    function payforme_get_full_cart_data($cart, $shopper) {
        $customer = $cart->get_customer();
        $currency = get_woocommerce_currency();

        $cart_data = new stdClass();
        $cart_data->shopper = $shopper;
        $cart_data->currency = $currency;
        $cart_data->totals = $cart->get_totals();
        $cart_data->line_items = payforme_get_cart_items($currency);
        $cart_data->line_fees = payforme_get_cart_fees($currency);
        $cart_data->coupons = payforme_get_cart_coupons($cart);
        $cart_data->shipping_rates = payforme_get_chosen_shipping_rates();
        $cart_data->needs_shipping = $cart->needs_shipping_address();

        $cart_data->billing->firstName = $customer->get_billing_first_name();
        $cart_data->billing->lastName = $customer->get_billing_last_name();
        $cart_data->billing->line1 = $customer->get_billing_address();
        $cart_data->billing->line2 = $customer->get_billing_address_2();
        $cart_data->billing->company = $customer->get_billing_company();
        $cart_data->billing->city = $customer->get_billing_city();
        $cart_data->billing->state = $customer->get_billing_state();
        $cart_data->billing->postalCode = $customer->get_billing_postcode();
        $cart_data->billing->countryCode = $customer->get_billing_country();
        $cart_data->billing->email = $customer->get_billing_email();
        $cart_data->billing->phone = $customer->get_billing_phone();

        $cart_data->shipping->firstName = $customer->get_shipping_first_name();
        $cart_data->shipping->lastName = $customer->get_shipping_last_name();
        $cart_data->shipping->line1 = $customer->get_shipping_address();
        $cart_data->shipping->line2 = $customer->get_shipping_address_2();
        $cart_data->shipping->company = $customer->get_shipping_company();
        $cart_data->shipping->city = $customer->get_shipping_city();
        $cart_data->shipping->state = $customer->get_shipping_state();
        $cart_data->shipping->postalCode = $customer->get_shipping_postcode();
        $cart_data->shipping->countryCode = $customer->get_shipping_country();

        return $cart_data;
    }

    /**
     * Returns the shipping rate for the shipping method id
     * if available
     *  
     * @param string $shipping_method_id 
     *
     * @return WC_Shipping_Rate|null 
     */
    function get_shipping_rate_by_id($shipping_method_id) {
        $packages = WC()->cart->get_shipping_packages();

        foreach ($packages as $package) {
            // Calculate shipping for the current package
            $shipping_methods_for_package = WC()->shipping->calculate_shipping_for_package($package);

            // Check if the chosen shipping method is in the available shipping methods for the package
            $shipping_rate = $shipping_methods_for_package['rates'][$shipping_method_id];
            if (isset($shipping_rate)) {
                return $shipping_rate;
            }
        }
        return null;
    }

    /**
     * Returns the array of shipping rates for the chosen shipping methods
     *
     * @return WC_Shipping_Rate[] 
     */
    function payforme_get_chosen_shipping_rates() {
        $shipping_rates = [];

        $chosen_shipping_methods = WC()->session->get('chosen_shipping_methods');

        foreach ($chosen_shipping_methods as $shipping_method_id) {
            $shipping_rate = get_shipping_rate_by_id($shipping_method_id);
            if (isset($shipping_rate)) {
                $id = $shipping_rate->get_id(); // combination of the method_id and instance_id. e.g. "flatrate:2"
                $label  = $shipping_rate->get_label(); // The label name of the method
                $cost        = $shipping_rate->get_cost(); // The cost without tax
                $shipping_tax   = $shipping_rate->get_shipping_tax();
                $total = $cost;
                if (isset($shipping_tax) && !empty($shipping_tax)) {
                    $total = $cost + $shipping_tax;
                }

                array_push($shipping_rates, [
                    'id'     => $id,
                    'label'     => $label,
                    'cost'     => $cost, // excluding tax
                    'total'     => $total, // including tax
                ]);
            }
        }

        return $shipping_rates;
    }

    /**
     * Get all coupons or discounts
     * @return array of coupons 
     */
    function payforme_get_cart_coupons($cart) {
        $coupons = $cart->get_coupons();
        $arr = [];
        foreach ($coupons as $coupon_code => $data) {
            $coupon = new WC_Coupon($coupon_code);
            $discount_amount = $cart->get_coupon_discount_amount($coupon_code);
            $item = [
                'id' => $coupon->get_id(),
                'code' => $coupon->get_code(),
                'amount' => $coupon->get_amount(),
                'discount_amount' => $discount_amount,
                'type' => $coupon->get_discount_type(),
                'description' => $coupon->get_description(),
            ];
            array_push($arr, $item);
        }
        return $arr;
    }

    /**
     * @param WC_Tax_Rate[] woo tax rates
     * @returns taxrates format expected by payforme server
     */
    function wc_taxrates_to_payforme_taxrates($tax_rates) {
        $pfm_tax_rates = [];
        foreach ($tax_rates as $key => $rate) {
            $_rate_item = [
                'id' => $key,
                'label' => $rate['label'],
                'percent' => $rate['rate'],
            ];
            array_push($pfm_tax_rates, $_rate_item);
        }

        return $pfm_tax_rates;
    }

    /**
     * Gets the cart items
     * @return array of cart items 
     */
    function payforme_get_cart_items($currency) {
        $items = WC()->cart->get_cart();
        $arr = [];
        $wc_tax = new WC_Tax();

        foreach ($items as $key => $item) {
            $variation_id = $item['variation_id'];
            $product_id = $item['product_id'];
            $product = wc_get_product($variation_id  ? $variation_id : $product_id);
            $tax_rates = $wc_tax->get_rates($product->get_tax_class());

            $image_id = $product->get_image_id();
            $image_url_srcs = wp_get_attachment_image_src($image_id, 'thumbnail');
            $image_url = reset($image_url_srcs);
            $image_urls = ($image_url) ? [$image_url] : [];

            $item = [
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'name' => $product->get_name(),
                'unit_price' => $product->get_price(),
                'line_total' => $item['line_total'],
                'line_tax' => $item['line_tax'],
                'quantity' => $item['quantity'],
                'tax_rates' => wc_taxrates_to_payforme_taxrates($tax_rates),
                'currency' => $currency,
                'images' => $image_urls,
            ];
            array_push($arr, $item);
        }

        return $arr;
    }

    function payforme_get_cart_fees($currency) {
        $fees = WC()->cart->get_fees();
        $fees = array_values($fees);

        $line_fees = [];
        foreach ($fees as $fee) {
            $tax_rates = $fee->taxable ? WC_Tax::get_rates($fee->tax_class, WC()->customer) : [];
            $item = [
                'id' => $fee->id,
                'name' => $fee->name,
                'amount' => $fee->amount,
                'total' => $fee->total,
                'tax' => $fee->tax,
                'taxable' => $fee->taxable,
                'tax_class' => $fee->tax_class,
                'tax_status' => $fee->taxable ? 'taxable' : 'none',
                'currency' => $currency,
                'tax_rates' => wc_taxrates_to_payforme_taxrates($tax_rates),
            ];
            array_push($line_fees, $item);
        }

        return $line_fees;
    }

    add_filter('woocommerce_payment_gateways', 'payforme_add_payment_gateway');
    function payforme_add_payment_gateway($gateways) {
        // Append Payforme Gateway to Woocommerce payment gateways.
        $gateways[] = 'PayForMe_Gateway';
        return $gateways;
    }

    add_filter('woocommerce_available_payment_gateways', 'payforme_hide_gateway_if_necessary');
    function payforme_hide_gateway_if_necessary($available_gateways) {
        if (!is_checkout()) {
            return $available_gateways;
        }

        if (!isset($available_gateways[PayForMe_Gateway::PAYFORME_GATEWAY_ID])) {
            // Abort if we can't find our gateway.
            return $available_gateways;
        }

        if (PayForMe_Gateway::getGateway()->get_option('show_button_in_checkout') !== 'yes') {
            // showing payforme button in gateway is disabled
            // hide the payforme gateway in checkout
            unset($available_gateways[PayForMe_Gateway::PAYFORME_GATEWAY_ID]);
        }

        return $available_gateways;
    }

    add_action('wp_footer', 'add_hidden_modal_container', 5);
    function add_hidden_modal_container() {
        echo '<div id="payforme-root" style="pointer-events: none"></div>';
    }

    add_action('wp_enqueue_scripts', 'add_my_scripts');
    function add_my_scripts() {
        $cache_burst = date("Y/m/d/h:i:sa");

        $script_id = 'payforme-script';

        // Enqueue JS file
        wp_enqueue_script(
            $script_id,
            plugin_dir_url(__FILE__) . '/scripts/pfm-script.js?' . $cache_burst,
            array('jquery')
        );

        payforme_localize_script($script_id);

        // Enqueue css style
        wp_enqueue_style('payforme-style', plugin_dir_url(__FILE__) . '/scripts/pfm-style.css?' . $cache_burst);

        enqueue_react_app_assets();
    }

    /**
     * Enqueues all the JavaScript and CSS files in react build path.
     * This is useful to avoid updating the dynamic hash value of 
     * files generated by the react bundler.
     */
    function enqueue_react_app_assets() {
        $cache_burst = date("Y/m/d/h:i:sa");
        $react_build_dir = '/build';
        $manifest_path = plugin_dir_path(__FILE__) . $react_build_dir . '/asset-manifest.json';

        if (file_exists($manifest_path)) {
            $manifest = json_decode(file_get_contents($manifest_path), true);

            // Enqueue all the JavaScript and CSS files.
            foreach ($manifest['files'] as $file_key => $info) {
                $enqueue_key = 'payforme-' . sanitize_key($file_key);
                $file_url = plugin_dir_url(__FILE__) . $react_build_dir . $info;
                if (preg_match('/\.js$/', $file_key)) {
                    wp_enqueue_script(
                        $enqueue_key,
                        $file_url,
                        array(),
                        $cache_burst,
                        true
                    );

                    payforme_localize_script($enqueue_key);
                } elseif (preg_match('/\.css$/', $file_key)) {
                    wp_enqueue_style(
                        $enqueue_key,
                        $file_url,
                        array(),
                        $cache_burst
                    );
                }
            }
        } else {
            var_dump("****** did not find manifest files");
        }
    }

    /**
     * Pass data to the js script e.g. const value = payForMeLocalizedData.ajaxurl;
     * @param String the script id used to enqueue the script
     */
    function payforme_localize_script($script_id) {
        wp_localize_script(
            $script_id,
            'payForMeLocalizedData',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'payformeGatewayId' => PayForMe_Gateway::PAYFORME_GATEWAY_ID,
                'nonce' => wp_create_nonce('my_ajax_nonce'),
                'source' => 'woocommerce',
                'environment' => isSandboxEnabled() ? "sandbox" : "prod",
                'appName' => PAYFORME_APP_NAME,
            )
        );
    }


    add_action('wp_ajax_payforme_ajax_action', 'my_ajax_handler');
    add_action('wp_ajax_nopriv_payforme_ajax_action', 'my_ajax_handler');
    function my_ajax_handler() {
        if (!wp_verify_nonce($_POST['nonce'], 'my_ajax_nonce')) {
            die('Invalid ajax nonce');
        }

        $requestType = $_POST['type'];

        if ($requestType === "payforme-request-create-checkout") {
            $msgData = $_POST['msgData'];
            $displayData = createPaymentLinkWithCustomAddress($msgData);
            $jsonString = json_encode($displayData);
            wp_send_json($jsonString);
            die();
        }

        die();
    }

    // Add "Settings" link to Plugins screen.
    add_filter(
        'plugin_action_links_' . plugin_basename(__FILE__),
        function ($links) {
            array_unshift(
                $links,
                sprintf(
                    '<a href="%1$s">%2$s</a>',
                    admin_url('admin.php?page=wc-settings&tab=checkout&section=payforme-gateway'),
                    __('Settings', PAYFORME_APP_NAME . ' for Woocommerce')
                )
            );
            return $links;
        }
    );

    // add_action('woocommerce_review_order_before_submit', 'add_stuff', 10);
    add_action('woocommerce_review_order_after_submit', 'add_payforme_button_in_checkout', 10);
    function add_payforme_button_in_checkout() {
        $payforme_button_html = '
        <div id="pfm_paynow_container">
         <p style="margin-bottom: 10px"></p>
         <button id="payforme_button_in_checkout" class="button-payforme-paynow">
          <span id="button-text" style="pointer-events: none; font-size:18px">Let Someone Payforme</span>
         </button>
        </div>
        ';
        echo $payforme_button_html;
    }

    add_action('woocommerce_proceed_to_checkout', 'add_payforme_button_in_cart', 20);
    function add_payforme_button_in_cart() {
        if (PayForMe_Gateway::getGateway()->get_option("enabled") !== 'yes') {
            return; // Payforme Plugin is disabled
        }
        if (PayForMe_Gateway::getGateway()->get_option("show_button_in_cart") !== 'yes') {
            return; // Showing button in cart is disabled 
        }

        // https://stackoverflow.com/a/27812496
        $customButton = '
            <div>
             <button id="payforme_button_in_cart" class="button-payforme-paynow">
              <span id="button-text" style="pointer-events: none; font-size:18px">Let Someone ' . PAYFORME_APP_NAME . '</span>
             </button>
             <p style="margin-bottom:0.0cm;"></p>
            </div>
            ';
        echo $customButton;
    }

    function payforme_get_payment_gateway() {
        return WC()->payment_gateways->payment_gateways()[PayForMe_Gateway::PAYFORME_GATEWAY_ID];
    }

    function buildCartDataWithShippingAddress($msgData) {
        $pfmAddress = $msgData['shipping'];
        $shopper = $msgData['shopper'];

        // Get the customer object
        $customer = WC()->customer;

        // Clear the billing address since someone else pays
        $customer->set_billing_first_name('');
        $customer->set_billing_last_name('');
        $customer->set_billing_address_1('');
        $customer->set_billing_address_2('');
        $customer->set_billing_company('');
        $customer->set_billing_city('');
        $customer->set_billing_state('');
        $customer->set_billing_postcode('');
        $customer->set_billing_country('');
        $customer->set_billing_email('');
        $customer->set_billing_phone('');

        // Set the new shipping address
        $customer->set_shipping_first_name($pfmAddress['firstName']);
        $customer->set_shipping_last_name($pfmAddress['lastName']);
        $customer->set_shipping_address_1($pfmAddress['line1']);
        $customer->set_shipping_address_2($pfmAddress['line2']);
        $customer->set_shipping_company($pfmAddress['company']);
        $customer->set_shipping_city($pfmAddress['city']);
        $customer->set_shipping_state($pfmAddress['state']);
        $customer->set_shipping_postcode($pfmAddress['postalCode']);
        $customer->set_shipping_country($pfmAddress['countryCode']);

        // Recalculate shipping and taxes for the cart
        WC()->cart->calculate_totals();

        return payforme_get_full_cart_data(WC()->cart, $shopper);
    }

    /**
     * Creates a new pfm payment link and returns the payment link payload details.
     * @param {object} $data = {shipping, shopper}
     * @return {paymentLink, email, storeName, totals, lineItems, expiration}
     */
    function createPaymentLinkWithCustomAddress($msgData) {
        $cart_data = buildCartDataWithShippingAddress($msgData);
        $site_url = get_site_url();
        $site_name = get_blogInfo('name');
        $requestUrl = getServerRequestUrl();

        $args = [
            'method'     => 'POST',
            'timeout'    => 30, // seconds
            'headers'    => payforme_get_request_headers(),
            'body'       => [
                'function'            => 'createCheckout',
                'cart_data'           => $cart_data,
                'source'              => 'woocommerce',
                'woo_store_url'       => $site_url,
                'woo_store_name'      => $site_name,
            ],
        ];

        $response = wp_remote_post($requestUrl, $args);
        $httpcode = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if (is_wp_error($response)) {
            http_response_code($httpcode);
            echo json_encode($response->get_error_message());
            exit();
        }

        if ($httpcode !== 200) {
            http_response_code($httpcode);
            echo json_encode($response_body);
            exit();
        }

        $json = json_decode($response_body, true);
        $paymentLinkUrl = $json['paymentLinkUrl'];
        $displayData = $json['displayData'];

        $result = $displayData;
        $result['paymentLink'] = $paymentLinkUrl;

        return $result;
    }

    /**
     * Returns the server url endpoint based on the whether we're 
     * in prod or sandbox environment.
     */
    function getServerRequestUrl() {
        if (isSandboxEnabled()) {
            return PAYFORME_SANDBOX_API_URL;
        }
        return PAYFORME_API_URL;
    }

    function isSandboxEnabled() {
        $sandbox_mode = PayForMe_Gateway::getGateway()->get_option('sandbox_enabled');
        return $sandbox_mode === 'yes';
    }
} // end of function payforme_gateway_init()