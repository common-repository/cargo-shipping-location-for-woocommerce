<?php
/**
 * Admin adjustments.
 *
 */

use CSLFW\Includes\CargoAPI\Cargo;
use CSLFW\Includes\CargoAPI\CargoAPIV2;
use CSLFW\Includes\CargoAPI\CSLFW_Order;
use CSLFW\Includes\CSLFW_Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( class_exists( 'CSLFW_Admin', false ) ) {
    return new CSLFW_Admin();
}

if( !class_exists('CSLFW_Admin') ) {
    class CSLFW_Admin
    {
        public $helpers;
        public $cargo;

        function __construct()
        {
            $this->helpers = new CSLFW_Helpers();
            $api_key = get_option('cslfw_cargo_api_key');

            if ($api_key) {
                $this->cargo = new CargoAPIV2();
            } else {
                $this->cargo = new Cargo();
            }

            add_action('admin_enqueue_scripts', [$this, 'import_assets']);

            add_action('init', [$this, 'register_order_status_for_cargo']);
            add_filter('wc_order_statuses', [$this, 'custom_order_status']);
            add_action('add_meta_boxes', [$this, 'add_meta_box']);

            add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'show_shipping_info']);
            add_action('admin_notices', [$this, 'cargo_bulk_action_admin_notice']);
            add_action('woocommerce_shipping_init', [$this,'shipping_method_classes']);

            add_filter('handle_bulk_actions-edit-shop_order', [$this, 'bulk_order_cargo_shipment'], 10, 3);
            add_filter('bulk_actions-edit-shop_order', [$this, 'custom_dropdown_bulk_actions_shop_order'], 20, 1);
            add_filter('woocommerce_shipping_methods', [$this,'cargo_shipping_methods']);

            add_action('manage_shop_order_posts_custom_column', [$this,'add_custom_column_content']);
            add_filter('manage_edit-shop_order_columns', [$this, 'add_order_delivery_status_column_header'], 2000);

            add_action('wp_ajax_cslfw_change_carrier_id', [$this, 'change_carrier_id']);

            // WC for HPOS
            add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'add_custom_column_content'], 10, 2);
            add_filter('woocommerce_shop_order_list_table_columns', [$this, 'add_order_delivery_status_column_header']);

            add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'custom_dropdown_bulk_actions_shop_order'], 20, 1);
            add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'bulk_order_cargo_shipment'], 10, 2);

            add_action('wp_ajax_cslfw_get_points_by_city', [$this, 'get_points_by_city']);
            add_action('wp_ajax_nopriv_cslfw_get_points_by_city', [$this, 'get_points_by_city']);

            add_action('wp_ajax_cslfw_get_bulk_action_progress', [$this, 'get_bulk_action_progress']);
        }

        public function import_assets() {
            $screen       = get_current_screen();
            $screen_id    = $screen ? $screen->id : '';

            if( $screen_id === 'toplevel_page_loaction_api_settings' ||
                $screen_id === 'cargo-shipping-location_page_cargo_shipping_contact' ||
                $screen_id === 'cargo-shipping-location_page_cargo_shipping_webhook' ||
                $screen_id === 'cargo-shipping-location_page_cargo_shipments_table' ||
                $screen_id === 'cargo-shipping-location_page_cargo_orders_reindex' ) {
                wp_enqueue_script( 'cargo-libs', CSLFW_URL . 'assets/js/libs.js', ['jquery'], CSLFW_VERSION, true);
            }

            wp_enqueue_style( 'admin-baldarp-styles', CSLFW_URL . 'assets/css/admin-baldarp-styles.css' );
            wp_enqueue_script( 'cargo-global', CSLFW_URL . 'assets/js/global.js', ['jquery'], CSLFW_VERSION, true);

            wp_enqueue_script( 'cargo-admin-script', CSLFW_URL .'assets/js/admin/admin-baldarp-script.js', [], CSLFW_VERSION, true);
            wp_localize_script( 'cargo-admin-script', 'admin_cargo_obj',
                [
                    'ajaxurl' => admin_url( 'admin-ajax.php' ),
                    'ajax_nonce'    => wp_create_nonce( 'cslfw_shipping_nonce' ),
                    'path' => CSLFW_URL,
                ]
            );

            if ($screen_id === 'edit-shop_order' || $screen_id === 'woocommerce_page_wc-orders') {
                wp_enqueue_script( 'cargo-libs', CSLFW_URL . 'assets/js/admin/bulk-actions-script.js', ['jquery'], CSLFW_VERSION, true);
            }
        }

        public function change_carrier_id()
        {
            $orderId = sanitize_text_field($_POST['orderId']);

            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field($_POST['_wpnonce']), "cslfw_cargo_actions{$orderId}")) {
                echo wp_json_encode([
                    'error' => true,
                    'message' => 'Bad request, try again later.',
                ]);
                wp_die();
            }

            $order = wc_get_order($orderId);

            if ($order) {
                $cargoOrder = new CSLFW_Order($order);
                $shippingMethod  = $cargoOrder->getShippingMethod();


                $newMethod = $shippingMethod === 'woo-baldarp-pickup' ? 'cargo-express' : 'woo-baldarp-pickup';

                $order->update_meta_data('cslfw_shipping_method', $newMethod);
                $order->save_meta_data();
                $order->save();

                echo wp_json_encode([
                    'error' => false,
                    'test' => $newMethod,
                    'order' => $order->get_meta('cslfw_shipping_method'),
                    'message' => 'successfully updated'
                ]);
                wp_die();
            }

            echo wp_json_encode([
                'error' => true,
                'message' => 'Order not found. Contact support.'
            ]);
            wp_die();
        }

        /**
         * Single order cargo view.
         *
         * @param $post
         */
        public function render_meta_box_content( $post ) {
            $order_id = $post instanceof \WP_Post ? $post->ID : $post->get_id();
            $order = wc_get_order($order_id);
            $cargoOrder = new CSLFW_Order($order);
            $shipping_method = $cargoOrder->getShippingMethod();

            $cargo_debug_mode   = get_option('cslfw_debug_mode');
            $cargo_shipping     = new CSLFW_Cargo_Shipping($order->get_id());
            $allowForAllShippingMethods = get_option('cslfw_shipping_methods_all');
            $cslfw_shiping_methods = get_option('cslfw_shipping_methods') ? get_option('cslfw_shipping_methods') : [];

            $orderStatus = $order->get_status();

            if (!in_array($orderStatus, ['cancelled', 'refunded', 'pending']) && $shipping_method) {
                if ($cargo_debug_mode) {

                    echo maybe_serialize($cargo_shipping->deliveries);
                }
                if (
                    $shipping_method === 'cargo-express'
                    || $shipping_method === 'cargo-express-24'
                    || $shipping_method === 'woo-baldarp-pickup'
                    || in_array($shipping_method, $cslfw_shiping_methods)
                    || $allowForAllShippingMethods
                ) {

                    $shipmentData = $cargo_shipping->get_shipment_data();

                    $data = [
                        'shipmentIds' => $cargo_shipping->get_shipment_ids(),
                        'paymentMethodCheck' => get_option('cslfw_cod_check') ?  get_option('cslfw_cod_check') : 'cod',
                        'fulfillAllShipments' => get_option('cslfw_fulfill_all'),
                        'shippingMethod' => $shipping_method,
                        'order' => $order,
                        'shipmentData' => $shipmentData,
                    ];

                    $boxPointId = !empty($shipmentData) ? @end( $shipmentData)['box_id'] : false;
                    $boxPointId = $boxPointId ? $boxPointId : $order->get_meta('cargo_DistributionPointID', true);

                    if ($boxPointId) {
                        $selectedPoint = $this->cargo->findPointById($boxPointId);

                        if (!$selectedPoint->errors) {
                            $city = $selectedPoint->data->CityName;
                            $points = $this->cargo->getPointsByCity($city);

                            $data['selectedPoint'] = !$points->errors ? $selectedPoint->data : null;
                            $data['points'] = !$points->errors ? $points->data : null;
                        } else {
                            $data['selectedPoint'] = null;
                            $data['points'] = [];
                        }
                    } else {
                        $data['selectedPoint'] = null;
                        $data['points'] = [];
                    }

                    $data['cities'] = $this->cargo->getPointsCities();

                    $this->helpers->load_template('admin/shipment', $data);
                } else {
                    esc_html_e('Shipping type is not related to cargo', 'cargo-shipping-location-for-woocommerce');
                }
            }
        }

        /**
         * Add order status for Cargo
         */
        function register_order_status_for_cargo() {
            register_post_status( 'wc-send-cargo', [
                'label'                     => 'Send to CARGO',
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop( 'Send to CARGO <span class="count">(%s)</span>', 'Send to CARGO <span class="count">(%s)</span>' )
            ] );
        }

        /**
         * @param $order_statuses
         * @return mixed
         *
         * Add order status in Array
         */
        function custom_order_status( $order_statuses ) {
            $order_statuses['wc-send-cargo'] = esc_html__( 'Send to CARGO', 'Order status', 'cargo-shipping-location-for-woocommerce' );
            return $order_statuses;
        }
        /**
         * @param $post_type
         *
         * Add Meta box in admin order
         */
        public function add_meta_box( $post_type ) {
            global $post, $pagenow, $typenow;
            if (isset($_GET['id'])) {
                $orderId = sanitize_text_field($_GET['id']);
            } else {
                $orderId = $post->ID ?? null;
            }

            if(
                ($post_type === 'woocommerce_page_wc-orders' && $orderId)
                || ('edit.php' === $pagenow || 'post.php' === $pagenow) && 'shop_order' === $typenow
                && is_admin()
            ) {
                $order = wc_get_order($orderId);
                $cargoOrder = new CSLFW_Order($order);
                $shipping_method = $cargoOrder->getShippingMethod();
                $cslfw_shiping_methods = get_option('cslfw_shipping_methods') ? get_option('cslfw_shipping_methods') : [];
                $allowForAllShippingMethods = get_option('cslfw_shipping_methods_all');

                if (!in_array($order->get_status() , ['cancelled', 'refunded', 'pending']) && $shipping_method !== null) {
                    if ( $shipping_method === 'cargo-express'
                        || $shipping_method === 'cargo-express-24'
                        || $shipping_method === 'woo-baldarp-pickup'
                        || in_array($shipping_method, $cslfw_shiping_methods)
                        || $allowForAllShippingMethods
                    ) {
                        add_meta_box(
                            'cslfw_cargo_custom_box',
                            '<img src="'.CSLFW_URL."assets/image/howitworks.png".'" alt="Cargo" width="100" style="width:50px;">CARGO',
                            [$this, 'render_meta_box_content'],
                            null,
                            'side',
                            'core'
                        );
                    }
                }
            }
        }

        /**
         * @param $actions
         * @return array
         *
         * Add Order Status to Admin Order list
         */
        function custom_dropdown_bulk_actions_shop_order($actions ){
            $new_actions = [];

            foreach ($actions as $key => $action) {
                $new_actions[$key] = $action;

                if ('mark_processing' === $key) {
                    $new_actions['mark_send-cargo-shipping'] = esc_html__( 'Send to CARGO', 'cargo-shipping-location-for-woocommerce' );
                    $new_actions['mark_send-cargo-dd'] = esc_html__( 'Send to CARGO with double delivery', 'cargo-shipping-location-for-woocommerce' );
                    $new_actions['mark_send-cargo-pickup'] = esc_html__( 'Send Pickup to CARGO', 'cargo-shipping-location-for-woocommerce' );
                    $new_actions['mark_cargo-print-label'] = esc_html__( 'Print CARGO labels', 'cargo-shipping-location-for-woocommerce' );
                }
            }

            return $new_actions;
        }

        /**
         * @param $column
         *
         * Add Custom Column in Admin Order List
         */
        public function add_custom_column_content($column, $orderPost = null ) {
            global $post;
            $order = $orderPost ?? wc_get_order($post->ID);

            if (!$order) return;

            $cargoOrder = new CSLFW_Order($order);
            $shipping_method = $cargoOrder->getShippingMethod();
            $cslfw_shiping_methods = get_option('cslfw_shipping_methods') ? get_option('cslfw_shipping_methods') : [];
            $allowForAllShippingMethods = get_option('cslfw_shipping_methods_all');

            if (!in_array($order->get_status() , ['cancelled', 'refunded', 'pending'])) {

                if ($shipping_method === 'cargo-express'
                    || $shipping_method === 'cargo-express-24'
                    || $shipping_method === 'woo-baldarp-pickup'
                    || in_array($shipping_method, $cslfw_shiping_methods)
                    || $allowForAllShippingMethods
                ) {

                    $cargo_shipping = new CSLFW_Cargo_Shipping($order->get_id());
                    $deliveries = $cargo_shipping->get_shipment_data();
                    $webhook_installed = get_option('cslfw_webhooks_installed');
                    $box_point_id = $order->get_meta('cargo_DistributionPointID', true);
                    $nonce = wp_create_nonce('cslfw_cargo_actions'.$order->get_id());

                    if ( 'cslfw_delivery_status' === $column ) {
                        if ( $deliveries ) {
                            foreach ($deliveries as $key => $value) {
                                echo wp_kses_post('<div class=""><p class="cslfw-status status-' . $value['status']['number'] .'">'. $key .' - ' . $value['status']['text'] . '</p></div>');
                                if ($webhook_installed !== 'yes') {
                                    echo wp_kses_post("<a href='#' class='btn btn-success send-status' style='margin-bottom: 5px;' data-id=" . $order->get_id() .  " data-deliveryid='$key'>$key  בדוק מצב הזמנה</a>");
                                }
                            }
                        }
                    }

                    if ( 'send_to_cargo' === $column ) {
                        $nonceId = 'cslfw_cargo_actions_nonce_' . $order->get_id();
                        echo wp_kses_post('<div id="'. esc_attr($nonceId) .'" data-value="' . esc_attr($nonce) . '"></div>');

                        if ( $deliveries ) {
                            $cargoShippingIds =  implode(',', array_keys($deliveries));

                            echo wp_kses_post('<a  href="#" class="btn btn-success label-cargo-shipping" data-id="' . $cargoShippingIds . '" data-order-id="'.$order->get_id().'">הדפס תווית</a>');
                            $printedLabel = $order->get_meta('cslfw_printed_label');
                            if ($printedLabel) {
                                echo wp_kses_post("<p>Printed at:</p>");
                                echo wp_kses_post("<p>". $printedLabel . "</p>");

                            }
                        } else {
                            $autoCashOnDeliveryMethod = get_option('cslfw_cod_check') ?  get_option('cslfw_cod_check') : 'cod';

                            $order_total = $autoCashOnDeliveryMethod === $order->get_payment_method() ? $order->get_total() : 0;
                            if ( $box_point_id ) {
                                echo wp_kses_post("<a href='#' class='btn btn-success submit-cargo-shipping' data-cargo-cod='$order_total' data-box-point-id='$box_point_id' data-id=".$order->get_id()." >שלח  לCARGO</a>");
                            } else {
                                echo wp_kses_post("<a href='#' class='btn btn-success submit-cargo-shipping' data-cargo-cod='$order_total' data-id=".$order->get_id()." >שלח  לCARGO</a>");
                            }
                        }
                    }
                }
            }
        }

        /**
         * Show Shipping Info in Admin Edit Order
         *
         * @param $order
         */
        function show_shipping_info($order ) {
            $cargo_shipping = new CSLFW_Cargo_Shipping($order->get_id());
            $deliveries = $cargo_shipping->get_shipment_ids();

            if ( ! empty($deliveries) ) {
                $deliveries = is_array( $deliveries ) ? implode(',', $deliveries) : $deliveries;
                echo wp_kses_post('<p><strong>'.__('מזהה משלוח', 'cargo-shipping-location-for-woocommerce').':</strong> ' . $deliveries . '</p>');
            }
        }

        /**
         * @param $columns
         * @return array
         */
        public function add_order_delivery_status_column_header($columns) {
            $new_columns = [];

            foreach ( $columns as $column_name => $column_info ) {
                $new_columns[ $column_name ] = $column_info;

                if ( 'order_status' === $column_name ) {
                    $new_columns['send_to_cargo'] = __( 'שלח משלוח לCARGO', 'cargo-shipping-location-for-woocommerce' );
                    $new_columns['cslfw_delivery_status'] = __( 'סטטוס משלוח', 'cargo-shipping-location-for-woocommerce' );
                }
            }

            return $new_columns;
        }

        /**
         * Add Bulk Action in admin panel.
         */
        function cargo_bulk_action_admin_notice() {
            global $pagenow;

            if ( 'edit.php' === $pagenow ) {
                $args = [
                    'posts_per_page' => -1,
                    'meta_key'      => 'cargo_shipping_id', // Postmeta key field
                    'meta_value'    => ['', '0'],
                    'meta_compare'  => 'NOT IN', // Possible values are ‘=’, ‘!=’, ‘>’, ‘>=’, ‘<‘, ‘<=’, ‘LIKE’, ‘NOT LIKE’, ‘IN’, ‘NOT IN’, ‘BETWEEN’, ‘NOT BETWEEN’, ‘EXISTS’ (only in WP >= 3.5), and ‘NOT EXISTS’ (also only in WP >= 3.5). Values ‘REGEXP’, ‘NOT REGEXP’ and ‘RLIKE’ were added in WordPress 3.7. Default value is ‘=’.
                    'return'        => 'ids' // Accepts a string: 'ids' or 'objects'. Default: 'objects'.
                ];

                $orders = wc_get_orders( $args );
                if ( count($orders) > 0) {
                    echo wp_kses_post( printf( '<div class="notice notice-error fade is-dismissible"><p>' .
                        _n( '%s Order require reindex',
                            '%s Orders require reindex',
                            count($orders),
                            'cargo-shipping-location-for-woocommerce'
                        ) . '<a class="button button-primary" style="margin-left: 10px;" href="%s">REINDEX</a></p></div>', count($orders), admin_url('admin.php?page=cargo_orders_reindex')
                    ) );
                }
            }

            if ( ('edit.php' === $pagenow && isset($_GET['post_type']) && 'shop_order' === sanitize_text_field($_GET['post_type']) )
                || ($pagenow === 'admin.php' && isset($_GET['page']) && 'wc-orders' == sanitize_text_field($_GET['page'])) ) {

                $print_label = get_transient('cslfw_print_label');

                if ($print_label) {
                    $label_link = sanitize_text_field($print_label);

                    $noticeText = '<div class="notice notice-success fade is-dismissible"><div id="cslfw_shipments_label_process"><p>';
                    $noticeText .=  esc_html__( "All labels are process. If it didn't redirect you to the labels, click button below", 'cargo-shipping-location-for-woocommerce');
                    $noticeText .='</p>';
                    $noticeText .='<p><a class="button" href="#" onclick="window.open(\''.$label_link.'\', \'_blank\')">Pdf link</a></p>';
                    $noticeText .='</div></div>';
                    echo wp_kses_post( printf( $noticeText ) );
                    echo '<script>
                                window.open("' . $label_link . '", "_blank")
                                const url = new URL(window.location.href);
                                const params = new URLSearchParams(url.search);
                                params.delete("cslfw_print_label");
                                window.history.replaceState({}, "", `${url.pathname}?${params}`);
                            </script>';

                    delete_transient('cslfw_print_label');
                }

                if ($labelsProcess = get_transient('cslfw_bulk_shipment_print')) {
                    $labelsCompleted = get_transient('cslfw_bulk_shipment_print_process');

                    $noticeText = '<div class="notice notice-success fade is-dismissible"><div id="cslfw_shipments_label_process"><p>';
                    $noticeText .=  __( 'Printing labels is in process. [%1$s] out of %2$s are completed. Please be patient.', 'action-scheduler' );
                    $noticeText .='</p>';
                    $noticeText .='</div></div>';

                    echo wp_kses_post(
                        printf(
                            $noticeText,
                            count($labelsCompleted),
                            count($labelsProcess)
                        )
                    );
                }

                if ( isset($_REQUEST['processed_ids']) && isset( $_GET['cargo_send'])) {
                    $processed_orders = sanitize_text_field($_REQUEST['processed_ids']);
                    $processed_orders = explode(',', $processed_orders);
                    $processed_count = count($processed_orders);
                    $progress = get_transient( 'cslfw_bulk_shipment_process');
                    $processing = get_transient('bulk_shipment_create');

                    if ($processing) {
                        $noticeText = '<div class="notice notice-success fade is-dismissible"><div id="cslfw_bulk_shipment_progress" ><p>';
                        $noticeText .=  _n( '%s Order Sent for Shipment. They will start processing really soon. be patient.',
                                '%s Order Sent for Shipment. They will start processing really soon. be patient.',
                                $processed_count,
                                'cargo-shipping-location-for-woocommerce'
                            );
                        $noticeText .='</p>';
                        if ($progress) {
                            foreach ($progress as $value) {
                                $noticeText .= "<p>Order <b>#{$value['orderId']}</b> :: {$value['status']}</p>";
                            }
                        }

                        $noticeText .='</div></div>';
                        echo wp_kses_post( printf( $noticeText, $processed_count ) );
                    }
                }
            }
        }

        public function get_bulk_action_progress()
        {
            if ($progress = get_transient( 'cslfw_bulk_shipment_process')) {
                $completed = !get_transient('bulk_shipment_create');

                $countProgress = !$completed && is_array($progress) ? count($progress) : 0;
                $noticeText = '<p>';
                $noticeText .=  "$countProgress Order Sent for Shipment. They will start processing really soon. be patient.";
                $noticeText .='</p>';
                if ($progress) {
                    foreach ($progress as $value) {
                        $noticeText .= "<p>Order <b>#{$value['orderId']}</b> :: {$value['status']}</p>";
                    }
                }
                if ($completed) {
                    $noticeText .='<p>All shipments are completed.</p>';
                    $noticeText .='<p><a class="cslfw-remove-webhooks button" href="#" onclick="window.location.reload()">Reload page</a></p>';
                }
                $data = [
                    'completed' => $completed,
                    'progress_html' => $noticeText,
                    'progress' => $progress,
                    'action' => 'cslfw_bulk_shipment_progress'
                ];


            } else if ($labelsProcess = get_transient('cslfw_bulk_shipment_print') ) {
                $labelsCompleted = get_transient('cslfw_bulk_shipment_print_process');

                $completedCount = count($labelsCompleted);
                $processCount = count($labelsProcess);

                $noticeText = '<div><p>';
                $noticeText .=  __( "Printing labels is in process. [$completedCount] out of [$processCount] are completed. Please be patient.", 'action-scheduler' );
                $noticeText .='</p>';
                $noticeText .='</div>';

                $data = [
                    'completed' => false,
                    'progress_html' => $noticeText,
                    'progress' => [],
                    'action' => 'cslfw_shipments_label_process'
                ];
            } else if ($print_label = get_transient('cslfw_print_label')) {
                $label_link = sanitize_text_field($print_label);

                $noticeText = '<div><p>';
                $noticeText .=  esc_html__( "If it didn't redirect you to the labels, click button below", 'cargo-shipping-location-for-woocommerce');
                $noticeText .='</p>';
                $noticeText .='<p><a class="button" href="#" onclick="window.open(\''.$label_link.'\', \'_blank\')">Pdf link</a></p>';
                $noticeText .='</div>';

                $data = [
                    'completed' => true,
                    'progress_html' => $noticeText,
                    'progress' => [],
                    'label_link' => $label_link,
                    'action' => 'cslfw_shipments_label_process'
                ];
            } else {
                $data = [
                    'completed' => true,
                    'progress_html' => '',
                    'progress' => [],
                ];
            }

            echo wp_json_encode($data);
            wp_die();
        }

        /**
         * Init Cargo shipping methods.
         */
        public function shipping_method_classes() {
            require_once CSLFW_PATH . 'includes/woo-baldarp-shipping.php';
            require_once CSLFW_PATH . 'includes/woo-baldarp-express-shipping.php';
            require_once CSLFW_PATH . 'includes/woo-cargo-express-24.php';
        }

        /**
         * Add shipping methods.
         *
         * @param $methods
         * @return mixed
         */
        public function cargo_shipping_methods( $methods ) {
            $boxCode = get_option('shipping_cargo_box');
            $expressCode = get_option('shipping_cargo_express');
            $express24Code = get_option('shipping_cargo_express_24');

            if ($boxCode) {
                $methods['woo-baldarp-pickup'] = 'CSLFW_Shipping_Method';
            }

            if ($expressCode) {
                $methods['cargo-express'] = 'Cargo_Express_Shipping_Method';
            }

            if ($express24Code) {
                $methods['cargo-express-24'] = 'Cargo_Express_24_Method';
            }
            return $methods;
        }

        /**
         * Add bulk actions in order list
         *
         * @param $redirect_to
         * @param $action
         * @param $ids
         * @return mixed
         */
        public function bulk_order_cargo_shipment($redirect_to, $action, $ids = null)
        {
            $orderIds = $ids ?? array_map('sanitize_text_field', $_GET['id']) ?? [];

            if (strpos($action, 'mark_') !== false) {
                $actionName = substr( $action, 5 ); // Get the status name from action.

                if ($actionName === 'cargo-print-label') {
                    if ($queued = get_option('cslfw_queued_bulk_labels')) {

                        $currentProcess = get_transient('bulk_shipment_print');
                        $currentProcess = $currentProcess ? $currentProcess : [];

                        if (!count($currentProcess)) {

                            set_transient('cslfw_bulk_shipment_print', $orderIds, 300);
                            set_transient('cslfw_bulk_shipment_print_process', [], 300);

                            $lastOrderId = $orderIds && count($orderIds) > 0 ? $orderIds[count($orderIds) - 1] : null;
                            $delay = 0;
                            $logs = new CSLFW_Logs();

                            $time = time();
                            $fileName = "cargo_{$orderIds[0]}_{$lastOrderId}_{$time}";
                            $logs->add_log_message('BULK PROCESS PRINT LABELS:: add orders', ['file' => $fileName, 'orders' => $orderIds]);

                            foreach ($orderIds as $orderId) {
                                $handler = new CSLFW_Cargo_Process_Shipment_Label($orderId, $actionName, $lastOrderId, $fileName);
                                cslfw_handle_or_queue($handler, $delay);
                            }
                        }
                    } else {
                        $cargoShipping = new CSLFW_Cargo_Shipping();
                        $shipmentIds   = $cargoShipping->order_ids_to_shipment_ids($orderIds);
                        $pdfLabel      = $cargoShipping->getShipmentLabel( implode( ',', $shipmentIds ), $orderIds);

                        if (!$pdfLabel->errors) {
                            set_transient("cslfw_print_label", $pdfLabel->data, 60);

                            return $redirect_to;
                        }
                    }
                } else if (in_array($actionName, ['send-cargo-shipping', 'send-cargo-dd', 'send-cargo-pickup'])) {
                    $currentProcess = get_transient( 'bulk_shipment_create');
                    $currentProcess = $currentProcess ? $currentProcess : [];

                    if (array_intersect($currentProcess, $orderIds)) {
                        $orderIds = array_diff($orderIds, $currentProcess);
                    }

                    $currentProcess = array_unique([...$currentProcess, ...$orderIds]);
                    set_transient( 'bulk_shipment_create', $currentProcess, 300);
                    $progress = array_map(function($orderId) {
                        return [
                            'orderId' => $orderId,
                            'status' => 'Queued...'
                        ];
                    }, $currentProcess);
                    set_transient( 'cslfw_bulk_shipment_process', $progress, 300);

                    $lastOrderId = $currentProcess && count($currentProcess) > 0 ? $currentProcess[count($currentProcess) - 1] : null;
                    $delay = 0;
                    $logs = new CSLFW_Logs();
                    $logs->add_log_message('BULK PROCESS SHIPMENT CREATE:: add orders', ['orders' => $orderIds]);

                    foreach ($orderIds as $orderId) {
                        $handler = new CSLFW_Cargo_Process_Shipment_Create($orderId, $actionName, $lastOrderId);
                        cslfw_handle_or_queue($handler, $delay);
                    }
                }
            }

            return add_query_arg(
                [
                    'processed_ids'  => implode( ',', $orderIds ),
                    'cargo_send' => true
                ], $redirect_to );
        }

        /**
         * @return mixed
         */
        public function get_points_by_city()
        {
            $city = sanitize_text_field($_POST['city']);

            $pointsByCity = $this->cargo->getPointsByCity($city);

            echo wp_json_encode($pointsByCity,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            wp_die();
        }
    }
}

$cslfw_admin = new CSLFW_Admin();
