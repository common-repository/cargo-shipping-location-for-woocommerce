<?php
/**
 * Logs class
 *
 */

use CSLFW\Includes\CSLFW_Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( class_exists( 'CSLFW_Logs', false ) ) {
    return new CSLFW_Logs();
}

if( !class_exists('CSLFW_Logs') ) {
    class CSLFW_Logs
    {

        /**
         * @var CSLFW_Helpers
         */
        private $helpers;
        private $upload;
        /**
         * @var mixed
         */
        private $upload_dir;
        /**
         * @var string
         */
        private $logs_dir;
        /**
         * @var array|false
         */
        private $files;
        /**
         * @var array
         */
        private $result;
        /**
         * @var array
         */
        private $handles;
        /**
         * @var string
         */
        private $viewed_log;

        function __construct()
        {
            $this->helpers = new CSLFW_Helpers();
            $this->upload     = wp_upload_dir();
            $this->upload_dir = $this->upload['basedir'];
            $this->logs_dir   = $this->upload_dir . '/cargo-shipping-location';
            $this->files      = @scandir( $this->logs_dir ); // @codingStandardsIgnoreLine.

            $this->result      = [];
            $this->handles     = [];
            $this->viewed_log  = '';
        }

        public function add_menu_link() {
            add_submenu_page('loaction_api_settings', 'Log Files', 'Log Files', 'manage_options', 'cargo_shipping_log', [$this, 'logs']);
        }

        public function logs(){
            $this->helpers->check_woo();
            $this->helpers->load_template('logs');
        }

        /**
         * @param $msg
         *
         * Add Log for Order
         */
        function add_log_message($msg, $data = []) {
            $upload = wp_upload_dir();
            $upload_dir = $upload['basedir'];
            $upload_dir = $upload_dir . '/cargo-shipping-location';

            if (! is_dir($upload_dir)) {
                mkdir( $upload_dir, 0700 );
            }
            $path = $upload_dir.'/order_log_' . date('Ymd') . '.txt';
            if (!file_exists($path)) {
                $file = fopen($path, 'w') or die("Can't create file");
            }

            $msg = '['. date('Y-m-d H:i:d') . '] ' . $msg;
            $message = empty($data) ? $msg . PHP_EOL  : "$msg " . wc_print_r($data, true) . PHP_EOL;
            file_put_contents($path, $message, FILE_APPEND) or die('failed to put');
        }

        /**
         * @param $msg
         */
        function add_debug_message($msg, $data = []) {
            if (get_option('cslfw_debug_mode')) {
                $this->add_log_message($msg, $data);
            }
        }

        public function get_logs() {
            if ( ! empty( $this->files ) ) {
                foreach ( $this->files as $key => $value ) {
                    if ( ! in_array( $value, ['.', '..'], true ) ) {
                        if ( ! is_dir( $value ) && strstr( $value, '.txt' ) ) {
                            $this->result[ sanitize_title( $value ) ] = $value;
                        }
                    }
                }
            }
            $this->result = array_reverse($this->result);

            if ( ! empty( $_REQUEST['log_file'] ) && isset( $this->result[ sanitize_title( wp_unslash( $_REQUEST['log_file'] ) ) ] ) ){
                $this->viewed_log = $this->result[ sanitize_title( wp_unslash( $_REQUEST['log_file'] ) ) ];
            } elseif ( ! empty( $this->result ) ) {
                $this->viewed_log = current( $this->result );
            }

            $handle = ! empty( $this->viewed_log ) ? $this->get_log_file_handle_op( $this->viewed_log ) : '';

            if ( ! empty( $_REQUEST['handle'] ) ) { // WPCS: input var ok, CSRF ok.
                $this->remove_log_op();
            }


            $logs = [
                'current_view'  => $this->viewed_log,
                'files'         => $this->result,
                'logs_dir'      => $this->logs_dir,
            ];
            return (object) $logs;
        }

        function get_log_file_handle_op( $filename ) {
            return substr( $filename, 0, strlen( $filename ) > 48 ? strlen( $filename ) - 48 : strlen( $filename ) - 4 );
        }

        function remove_log_op() {
            if ( empty( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field(wp_unslash( $_REQUEST['_wpnonce'] ) ), 'remove_log' ) ) { // WPCS: input var ok, sanitization ok.
                wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'cargo-shipping-location-for-woocommerce' ) );
            }

            if ( ! empty( $_REQUEST['handle'] ) ) {
                $this->remove_op( sanitize_title(wp_unslash( $_REQUEST['handle'] ) ) );
            }

            wp_safe_redirect( esc_url_raw( admin_url( 'admin.php?page=cargo_shipping_log' ) ) );
            exit();
        }

        function remove_op($handle) {
            $removed    = false;
            $result_new = [];

            if ( ! empty( $this->files ) ) {
                foreach ( $this->files as $kye => $value ) {
                    if ( ! in_array( $value, ['.', '..'], true ) ) {
                        if ( ! is_dir( $value ) && strstr( $value, '.txt' ) ) {
                            $result_new[ sanitize_title( $value ) ] = $value;
                        }
                    }
                }
            }

            $handle  = sanitize_title( $handle );

            if ( isset( $result_new[ $handle ] ) && $result_new[ $handle ] ) {
                $file = realpath( trailingslashit( $this->logs_dir ) .'/'. $result_new[ $handle ] );
                if ( 0 === stripos( $file, realpath( trailingslashit( $this->logs_dir ) ) ) && is_file( $file ) && is_writable( $file ) ) { // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_is_writable
                    $this->close_op( $file ); // Close first to be certain no processes keep it alive after it is unlinked.
                    $removed = unlink( $file ); // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_unlink
                }
            }
            return $removed;
        }

        public function close_op( $handle ) {
            $this->result = false;

            if ( $this->is_open_op( $handle ) ) {
                $this->result = fclose( $this->handles[ $handle ] ); // @codingStandardsIgnoreLine.
                unset( $this->handles[ $handle ] );
            }
            return $this->result;
        }

        function is_open_op($handle) {
            return array_key_exists( $handle, $this->handles ) && is_resource( $this->handles[ $handle ] );
        }
    }
}
