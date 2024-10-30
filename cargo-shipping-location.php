<?php
 /**
 * Plugin Name: Cargo Shipping Location for WooCommerce
 * Plugin URI: https://cargo.co.il/
 * Description: Location Selection for Shipping Method for WooCommerce
 * Version: 5.3
 * Author: Astraverdes
 * Author URI: https://astraverdes.com/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cargo-shipping-location-for-woocommerce
  *
  * WC requires at least: 6.0.0
  * WC tested up to: 9.2.3
 */

use CSLFW\Includes\CargoAPI\Cargo;
use CSLFW\Includes\CargoAPI\CargoAPIV2;
use CSLFW\Includes\CargoAPI\CSLFW_Order;
use CSLFW\Includes\CargoAPI\Webhook;
use CSLFW\Includes\CSLFW_Helpers;
use CSLFW\Includes\CSLFW_ShipmentsPage;

if ( !defined( 'ABSPATH' ) ) {
    exit;
}

if ( !defined( 'CSLFW_URL' ) ) {
    define( 'CSLFW_URL', plugins_url( '/', __FILE__ ) );
}

if ( !defined( 'CSLFW_PATH' ) ) {
    define( 'CSLFW_PATH', plugin_dir_path( __FILE__ ) );
}

if ( !defined( 'CSLFW_VERSION' ) ) {
    define( 'CSLFW_VERSION', '5.3' );
}

if (!isset($cslfw_cargo_autoloader) || $cslfw_cargo_autoloader === false) {
    // require Action Scheduler
    if( file_exists( __DIR__ . "/includes/vendor/action-scheduler/action-scheduler.php" ) ){
        include_once __DIR__ . "/includes/vendor/action-scheduler/action-scheduler.php";
    }

    include_once __DIR__ . "/vendor/autoload.php";
    include_once __DIR__ . "/bootstrap.php";
}

if( !class_exists('CSLFW_Cargo') ) {
    class CSLFW_Cargo {
        /**
         * @var CSLFW_Helpers
         */
        private $helpers;
        /**
         * @var CSLFW_Logs
         */
        private $logs;
        /**
         * @var Cargo|CargoAPIV2
         */
        private $cargo;
        /**
         * @var Webhook
         */
        private $webhook;

        function __construct() {
            $this->helpers = new CSLFW_Helpers();
            $this->logs = new CSLFW_Logs();
            $api_key = get_option('cslfw_cargo_api_key');

            if ($api_key) {
                $this->cargo = new CargoAPIV2();
            } else {
                $this->cargo = new Cargo();
            }
            $this->webhook = new Webhook();
            new CSLFW_ShipmentsPage();

            add_action('before_woocommerce_init', [$this, 'hpos_compability']);

            add_action('woocommerce_checkout_update_order_meta', [$this, 'custom_checkout_field_update_order_meta']);
            add_action('woocommerce_checkout_order_processed', [$this, 'transfer_order_data_for_shipment'], 10, 1);

            add_action('wp_ajax_getOrderStatus', [$this, 'getOrderStatusFromCargo']);
            add_action('wp_ajax_nopriv_getOrderStatus', [$this, 'getOrderStatusFromCargo']);

            add_action('wp_ajax_cancelShipment', [$this, 'cancelShipment']);
            add_action('wp_ajax_nopriv_cancelShipment', [$this, 'cancelShipment']);

            add_action('wp_ajax_get_delivery_location', [$this, 'cslfw_ajax_delivery_location']);
            add_action('wp_ajax_nopriv_get_delivery_location', [$this, 'cslfw_ajax_delivery_location']);

            add_action('wp_ajax_sendOrderCARGO', [$this, 'send_order_to_cargo']);
            add_action('wp_ajax_get_shipment_label', [$this, 'get_shipment_label']);
            add_action('admin_menu', [$this->logs, 'add_menu_link'], 100);

            add_filter('woocommerce_order_get_formatted_shipping_address', [$this, 'additional_shipping_details'], 10, 3 );

            add_action('woocommerce_order_status_processing', [$this, 'auto_create_shipment'], 200, 1);

            add_action('CSLFW_Cargo_Process_Shipment_Create', [$this, 'cslfw_process_single_shipment_create_job'], 10, 3);
            add_action('CSLFW_Cargo_Process_Shipment_Label', [$this, 'cslfw_process_single_shipment_label_job'], 10, 4);
        }

        function cslfw_process_single_shipment_create_job($obj_id = null, $action_name = '', $last_order_id = null)
        {
            $logs = new CSLFW_Logs();
            $message = "********************************************* \n";
            $message .= "********************************************* \n";
            $message .= 'cslfw_process_single_shipment_create_job fired';
            $logs->add_debug_message($message, ['obj' => $obj_id, 'action_name' => $action_name, 'last_order_id' => $last_order_id]);
            $job = new CSLFW_Cargo_Process_Shipment_Create($obj_id, $action_name, $last_order_id);
            $job->handle();

            return true;
        }

        function cslfw_process_single_shipment_label_job($obj_id = null, $action_name = '', $last_order_id = null, $file_name = '')
        {
            $logs = new CSLFW_Logs();
            $message = "********************************************* \n";
            $message .= "********************************************* \n";
            $message .= 'cslfw_process_single_shipment_label_job fired';
            $logs->add_debug_message($message, ['obj' => $obj_id, 'action_name' => $action_name, 'last_order_id' => $last_order_id]);
            $job = new CSLFW_Cargo_Process_Shipment_Label($obj_id, $action_name, $last_order_id, $file_name);
            $job->handle();

            return true;
        }


        public function hpos_compability()
        {
            if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
            }
        }

        /**
         * Add shipment details to an email and frontend
         *
         * @param $address
         * @param $raw_address
         * @param $order
         * @return mixed|string
         */
        function additional_shipping_details( $address, $raw_address, $order ) {
            $cargoOrder = new CSLFW_Order($order);
            $shipping_method    = $cargoOrder->getShippingMethod();
            $cslfw_box_info     = get_option('cslfw_box_info_email');

            if (!$cslfw_box_info && $shipping_method !== null) {
                ob_start();
                $cargo_shipping = new CSLFW_Cargo_Shipping( $order->get_id() );
                $shipmentsData = $cargo_shipping->get_shipment_data();

                if ( $shipping_method === 'woo-baldarp-pickup' && $shipmentsData ) {
                    $box_shipment_type = $order->get_meta('cslfw_box_shipment_type', true);

                    foreach ($shipmentsData as $shipping_id => $data) {

                        if (isset($data['box_id'])) {
                            $point = $this->cargo->findPointById($data['box_id']);
                            if (!$point->errors) {
                                $chosen_point = $point->data;
                                echo esc_html_e("Cargo Point Details", 'cargo-shipping-location-for-woocommerce') . PHP_EOL;
                                if ( $box_shipment_type === 'cargo_automatic' && !$chosen_point ) {
                                    echo esc_html_e('Details will appear after sending to cargo.', 'cargo-shipping-location-for-woocommerce'). PHP_EOL;
                                } else {
                                    echo wp_kses_post( $chosen_point->DistributionPointName ) ?> : <?php echo wp_kses_post($chosen_point->DistributionPointID ) . PHP_EOL;
                                    echo wp_kses_post( $chosen_point->StreetNum.' '.$chosen_point->StreetName.' '. $chosen_point->CityName ) . PHP_EOL;
                                    echo wp_kses_post( $chosen_point->Comment ) . PHP_EOL;
                                }
                            }
                        }
                    }
                 }
                $cargo_details = ob_get_clean();
                $address .= $cargo_details;
            }
            return $address;
        }

	    /**
		* Send Order to CARGO (Baldar) For Shipping Process.
		*
		* @param string $_POST data
		*
		* @return Return Success Msg and store Cargo Shipping ID in Meta.
		*/
	    function send_order_to_cargo()
        {
            $order_id = sanitize_text_field($_POST['orderId']);

            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field($_POST['_wpnonce']), "cslfw_cargo_actions{$order_id}")) {
                echo wp_json_encode([
                    'error' => true,
                    'message' => 'Bad request, try again later.',
                ]);
                wp_die();
            }

			if( trim( get_option('from_street') ) == ''
                || trim( get_option('from_street_name') ) == ''
                || trim( get_option('from_city') ) == ''
                || trim( get_option('phonenumber_from') ) == '' ) {
				echo wp_json_encode(
				    [
				        "shipmentId" => "",
                        "error_msg" => esc_html__('Please enter all details from plugin setting', 'cargo-shipping-location-for-woocommerce')
                    ]
                );
				exit;
			}

            $order = wc_get_order($order_id);
            $cargoOrder = new CSLFW_Order($order);
            $shipping_method  = $cargoOrder->getShippingMethod();

            if ($shipping_method === null) {
                echo wp_json_encode(
                    [
                        "shipmentId" => "",
                        "error_msg" => esc_html__('No shipping methods found. Contact support please.', 'cargo-shipping-location-for-woocommerce')
                    ]
                );
                exit;
            }
            if ( ($shipping_method === 'cargo-express') && trim( get_option('shipping_cargo_express') ) === '' ) {
                echo wp_json_encode(
                    [
                        "shipmentId" => "",
                        "error_msg" => esc_html__('Cargo Express ID is missing from plugin settings.', 'cargo-shipping-location-for-woocommerce')
                    ]
                );
                exit;
            }

            if (in_array($order->get_status(), ['cancelled', 'refunded', 'pending'])) {
                echo wp_json_encode(
                    [
                        "shipmentId" => "",
                        "error_msg" => esc_html__('Cancelled, pending, or refunded order can\'t be processed.', 'cargo-shipping-location-for-woocommerce')
                    ]
                );
                exit;
            }

            if ( ($shipping_method === 'woo-baldarp-pickup') && trim( get_option('shipping_cargo_box') ) === '' ) {
                echo wp_json_encode(
                    [
                        "shipmentId" => "",
                        "error_msg" => esc_html__('Cargo BOX ID is missing from plugin settings.', 'cargo-shipping-location-for-woocommerce')
                    ]
                );
                exit;
            }

			$args = [
                'double_delivery'   => (int) sanitize_text_field($_POST['double_delivery']),
                'shipping_type'     => (int) sanitize_text_field($_POST['shipment_type']),
                'no_of_parcel'      => (int) sanitize_text_field($_POST['no_of_parcel']),
                'cargo_cod'         => (int) sanitize_text_field($_POST['cargo_cod']),
                'cargo_cod_type'    => (int) sanitize_text_field($_POST['cargo_cod_type']),
                'fulfillment'       => (int) sanitize_text_field($_POST['fulfillment'])
            ];

            if (isset($_POST['box_point_id'])) {
                $point = $this->cargo->findPointById(sanitize_text_field($_POST['box_point_id']));

                if (!$point->errors && $point->data) {
                    $args['box_point'] = $point->data;

                    $order->update_meta_data('cargo_DistributionPointID', $point->data->DistributionPointID);
                }
                $order->update_meta_data('cslfw_shipping_method', 'woo-baldarp-pickup');
            }

            $cargo_shipping = new CSLFW_Cargo_Shipping($order_id);
            $response = $cargo_shipping->createShipment($args);

            $order->save();
            echo wp_json_encode($response);
			exit();
	    }

        /**
         * Get label from cargo.
         *
         * @param false $shipmentId
         * @return mixed
         */
		function get_shipment_label() {
            $orderId = sanitize_text_field($_POST['orderId']);
            $shipmentId = sanitize_text_field($_POST['shipmentId']);

            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field($_POST['_wpnonce']), "cslfw_cargo_actions{$orderId}")) {
                echo wp_json_encode([
                    'error' => true,
                    'message' => 'Bad request, try again later.',
                ]);
                wp_die();
            }

		    $cargo_shipping = new CSLFW_Cargo_Shipping($orderId);
            $response = $cargo_shipping->getShipmentLabel($shipmentId);

            echo wp_json_encode($response);
            exit;
		}

		/**
		* Check the Shipping Setting from cargo
		*
		* @param $_POST DATA
		* @return int shipping Status
		*/
		function getOrderStatusFromCargo() {
            $order_id = (int) sanitize_text_field($_POST['orderId']);

            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field($_POST['_wpnonce']), "cslfw_cargo_actions{$order_id}")) {
                echo wp_json_encode([
                    'error' => true,
                    'message' => 'Bad request, try again later.',
                ]);
                wp_die();
            }
		    $shipping_id    = (int) sanitize_text_field($_POST['deliveryId']);
            $cargo_shipping = new CSLFW_Cargo_Shipping($order_id);

			echo wp_json_encode( $cargo_shipping->getOrderStatusFromCargo($shipping_id) );
			exit;
		}

		function cancelShipment() {
            $order_id = (int) sanitize_text_field($_POST['orderId']);
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field($_POST['_wpnonce']), "cslfw_cargo_actions{$order_id}")) {
                echo wp_json_encode([
                    'error' => true,
                    'message' => 'Bad request, try again later.',
                ]);
                wp_die();
            }
            $shipping_id    = (int) sanitize_text_field($_POST['deliveryId']);
            $cargo_shipping = new CSLFW_Cargo_Shipping($order_id);
            $response = $cargo_shipping->updateShipmentStatus($shipping_id, 8);
            $logs = new CSLFW_Logs();
            $logs->add_log_message('CARGO CANCEL SHIPMENT::', ['shipment_id' => $shipping_id, 'data' => $response]);

            echo wp_json_encode( $response );
            exit;
        }

        /**
         * Update order on status change.
         *
         * @param $order_id
         */
        public function transfer_order_data_for_shipment($order_id) {
        	if ( ! $order_id ) return;

        	$order = wc_get_order( $order_id );
            $cargo_order = new CSLFW_Order($order);
            $shipping_method = $cargo_order->getShippingMethod();

            if ($shipping_method !== null) {
                if ($shipping_method === 'woo-baldarp-pickup') {
                    $order->update_meta_data('cslfw_shipping_method', $shipping_method);

                    if (isset($_POST['DistributionPointID'])) {
                        $order->update_meta_data('cargo_DistributionPointID', sanitize_text_field($_POST['DistributionPointID']));
                    }
                }
            } else if ($shipping_method === 'cargo-express') {
                $order->update_meta_data('cslfw_shipping_method', $shipping_method);
            }

            $order->save();
        }

        function auto_create_shipment( $order_id )
        {
            $order = wc_get_order($order_id);
            $cargo_shipping = new CSLFW_Cargo_Shipping($order_id);
            $cargo_order = new CSLFW_Order($order);
            $shipping_method = $cargo_order->getShippingMethod();

            $autoBoxChose = get_option('cargo_box_style');

            if ($shipping_method === 'woo-baldarp-pickup' && $autoBoxChose === 'cargo_automatic') {
                $logs = new \CSLFW_Logs();

                $data = $cargo_shipping->createCargoObject();
                $address = $data['Params']['to_address']['street1'] . ' ' . $data['Params']['to_address']['street2'] . ',' . $data['Params']['to_address']['city'];
                $geocoding = $this->cargo->cargoGeocoding($address);

                if ($geocoding->errors === false) {
                    if ( !empty($geocoding->data->results) ) {

                        $coordinates = $geocoding->data->results[0]->geometry->location;

                        $closest_point = $this->cargo->findClosestPoints($coordinates->lat, $coordinates->lng, 30);

                        if ( !$closest_point->errors ) {
                            // THE SUCCESS FOR DETERMINE CARGO POINT ID IN AUTOMATIC MODE.
                            $chosen_point = $closest_point->data[0];
                            $order->update_meta_data('cargo_DistributionPointID', $chosen_point->DistributionPointID);

                            $order->save();
                        } else {
                            $logs->add_debug_message("ERROR.FAIL: 'No closest points found by the radius." . PHP_EOL );
                        }
                    } else {
                        $logs->add_debug_message("ERROR.FAIL: Empty geocoding data." . PHP_EOL );
                    }

                } else {
                    $logs->add_debug_message("ERROR.FAIL: Address geocoding fail for address $address" . PHP_EOL );
                }
            }

            $autoShipmentCreate = get_option('cslfw_auto_shipment_create');
            if ($autoShipmentCreate === 'on' && !$cargo_shipping->get_shipment_data()) {
                $cargo_shipping->createShipment();
            }

            $order->save();
        }

        /**
         * Update Order meta
         *
         * @param $order_id
         */
        public function custom_checkout_field_update_order_meta($order_id){
            $order          = wc_get_order($order_id);
            $shippingMethod = explode(':', sanitize_text_field($_POST['shipping_method'][0]) );
            if(reset($shippingMethod) === 'woo-baldarp-pickup') {
                if ( isset($_POST['DistributionPointID']) ) {
                    $order->update_meta_data('cargo_DistributionPointID', sanitize_text_field($_POST['DistributionPointID']));
                }
                if ( isset($_POST['DistributionPointID']) ) {
                    $order->update_meta_data('cargo_CityName', sanitize_text_field($_POST['CityName']) );
                }
                if ( get_option('cargo_box_style') ) {
                    $order->update_meta_data('cslfw_box_shipment_type', sanitize_text_field(get_option('cargo_box_style')));
                }

                $order->update_meta_data('cslfw_shipping_method', 'woo-baldarp-pickup');
            }

            if(reset($shippingMethod) === 'cargo-express') {
                $order->update_meta_data('cslfw_shipping_method', 'cargo-express');
            }

            $order->save();
        }

        /**
         * Function for map
         */
        public function cslfw_ajax_delivery_location() {
            if ( WC()->session->get('chosen_shipping_methods') !== null) {
                $results = $this->cargo->getPickupPoints();
                if (!$results->errors && count($results->data) > 0) {
                    $response = [
                        "info"             => "Everything is fine.",
                        "data"             => 1,
                        "dataval"          => wp_json_encode($results->data),
                        'shippingMethod'   => WC()->session->get('chosen_shipping_methods')[0],
                    ];
                } else {
                    $response = [
                        "info"             => "Error",
                        "data"             => 0,
                        "dataval"          => '',
                        'shippingMethod'   => ''
                    ];
                }

            } else {
                $response = [
                    "info"             => "Error",
                    "data"             => 0,
                    "dataval"          => '',
                    'shippingMethod'   => ''
                ];
            }
            echo wp_json_encode($response);
            wp_die();
        }

        public function init_plugin() {
            add_action('admin_menu', [$this, 'plugin_menu']);
        }

        public function plugin_menu() {
            add_menu_page('Cargo Shipping Location',
                'Cargo Shipping Location',
                'manage_options',
                'loaction_api_settings',
                [new CSLFW_Settings(), 'settings'
                ], plugin_dir_url( __FILE__ ) . 'assets/image/cargo-icon-with-white-bg-svg.svg');
        }
    }
}

$cslfw_shipping = new CSLFW_Cargo();
$cslfw_shipping->init_plugin();
