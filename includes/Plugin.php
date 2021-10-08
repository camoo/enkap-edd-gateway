<?php
/**
 * Description of Plugin
 *
 * @author Camoo Sarl
 */

namespace Camoo\Enkap\Easy_Digital_Downloads;

defined('ABSPATH') || exit;
if (!class_exists('\\Camoo\\Enkap\\Easy_Digital_Downloads\\Plugin')):

    class Plugin
    {
        protected $id;
        protected $mainMenuId;
        protected $adapterName;
        protected $title;
        protected $description;
        protected $optionKey;
        protected $settings;
        protected $adapterFile;
        protected $pluginPath;
        protected $version;
        protected $image_format = 'full';

        public function __construct($pluginPath, $adapterName, $adapterFile, $description = '', $version = null)
        {
            $this->id = basename($pluginPath, '.php');
            $this->pluginPath = $pluginPath;
            $this->adapterName = $adapterName;
            $this->adapterFile = $adapterFile;
            $this->description = $description;
            $this->version = $version;
            $this->optionKey = '';

            $this->mainMenuId = 'admin.php';
            $this->title = sprintf(__('%s Payment Gateway', $this->id), 'E-nkap');
        }

        private function test()
        {

        }

        public function register()
        {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
            require_once __DIR__ . '/InstallEnkap.php';
            // do not register when Easy_Digital_Downloads is not enabled
            if (!function_exists('edd_is_gateway_active')) {
                return;
            }
            
            register_activation_hook($this->pluginPath, [InstallEnkap::class, 'install']);
            register_activation_hook( $this->pluginPath, array( $this, 'flush_rules' ) );
            
            add_filter('plugin_action_links_' . plugin_basename($this->pluginPath), [$this, 'onPluginActionLinks'], 1, 1);
            add_action('plugins_loaded', [$this, 'onInit']);
            add_action('wp_enqueue_scripts', [ __CLASS__, 'enqueue_block_enkap_css_scripts' ]);
        }

        public static function enqueue_block_enkap_css_scripts(): void
        {
            wp_enqueue_style(
                'enkap_style',
                plugins_url('/assets/css/style.css', __FILE__)
            );
        }

        public function onInit()
        {
            $this->loadGatewayClass();
            if (class_exists('\\Camoo\\Enkap\\Easy_Digital_Downloads\\' . $this->adapterName)) {
                new EDD_Enkap_Gateway();
            }
            add_filter( 'init', array( $this, 'rewrite_rules' ) );
            add_filter( 'init', array( $this, 'flush_rules' ) );
        }
        public function flush_rules()
        {
            $this->rewrite_rules();

            flush_rewrite_rules();
        }

        public function rewrite_rules()
        {
            add_rewrite_rule( 'e-nkap/return/(.+?)/?$', 'index.php?edd-listener=return_e_nkap&merchantReferenceId==$matches[1]', 'top');
            add_rewrite_tag( '%merchantReferenceId%', '([^&]+)' );

            add_rewrite_rule( 'e-nkap/notification/(.+?)/?$', 'index.php?edd-listener=notification_e_nkap&merchantReferenceId==$matches[1]', 'top');
            add_rewrite_tag( '%merchantReferenceId%', '([^&]+)' );

            add_rewrite_rule( 'e-nkap/status/(.+?)/?$', 'index.php?edd-listener=status_e_nkap&merchantReferenceId==$matches[1]', 'top');
            add_rewrite_tag( '%merchantReferenceId%', '([^&]+)' );
        }

        public function onPluginActionLinks($links)
        {
            $settings_link = array(
                'settings' => '<a href="' . admin_url( 'edit.php?post_type=download&page=edd-settings&tab=gateways&section=edd_enkap' ) . '" title="'.__('Settings', $this->id).'">'.__('Settings', $this->id).'</a>',
            );
            return array_merge( $settings_link, $links );
        }

        public function loadGatewayClass()
        {
            if (class_exists('\\Camoo\\Enkap\\Easy_Digital_Downloads\\' . $this->adapterName)) {
                return;
            }
            include_once(dirname(__DIR__) . '/inc/Gateway.php');
            include_once(dirname(__DIR__) . '/vendor/autoload.php');
        }

        public static function get_webhook_url($endpoint)
        {
            return trailingslashit(get_home_url()). 'e-nkap/'.$endpoint;
        }

        public static function getEEDOrderIdByMerchantReferenceId($id_code)
        {

            global $wpdb;
            if (!wp_is_uuid($id_code)) {
                return null;
            }

            $db_prepare = $wpdb->prepare("SELECT * FROM `{$wpdb->prefix}edd_enkap_payments` WHERE `merchant_reference_id` = %s", $id_code);
            $payment = $wpdb->get_row($db_prepare);

            if (!$payment) {
                return null;
            }

            return (int)$payment->edd_order_id;
        }

        public static function getEnkapPaymentByMerchantOrderId($order_id)
        {
            global $wpdb;

            $db_prepare = $wpdb->prepare("SELECT * FROM `{$wpdb->prefix}edd_enkap_payments` WHERE `edd_order_id` = %s", $order_id);
            $payment = $wpdb->get_row($db_prepare);

            if (!$payment) {
                return null;
            }

            return $payment;
        }

        public static function displayNotFoundPage()
        {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            get_template_part(404);
        }
    }

endif;
