<?php
/**
 * Description of Plugin
 *
 * @author Camoo Sarl
 */

namespace Camoo\Enkap\Easy_Digital_Downloads;

use EDD_Payment;
use Enkap\OAuth\Model\Status;
use WC_Geolocation;

defined('ABSPATH') || exit;
if (!class_exists(Plugin::class)):

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
        public const DOMAIN_TEXT = 'edd-wp-enkap';

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
            $this->title = __('E-nkap Payment Gateway', self::DOMAIN_TEXT);
        }


        public function register()
        {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
            require_once __DIR__ . '/Install.php';
            if (!is_plugin_active('easy-digital-downloads/easy-digital-downloads.php')) {
                return;
            }

            register_activation_hook($this->pluginPath, [Install::class, 'install']);
            register_activation_hook($this->pluginPath, array($this, 'flush_rules'));

            add_filter('plugin_action_links_' . plugin_basename($this->pluginPath), [$this, 'onPluginActionLinks'], 1, 1);
            add_action('plugins_loaded', [$this, 'onInit']);
            add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_block_enkap_css_scripts']);
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
                (new EDD_Enkap_Gateway());
            }
            add_action('init', [__CLASS__, 'loadTextDomain']);
            add_filter('init', array($this, 'rewrite_rules'));
            add_filter('init', array($this, 'flush_rules'));
        }

        public function flush_rules()
        {
            $this->rewrite_rules();

            flush_rewrite_rules();
        }

        public function rewrite_rules()
        {
            add_rewrite_rule('edd-e-nkap/return/(.+?)/?$', 'index.php?edd-listener=return_e_nkap&merchantReferenceId=$matches[1]', 'top');
            add_rewrite_tag('%merchantReferenceId%', '([^&]+)');

            add_rewrite_rule('edd-e-nkap/notification/(.+?)/?$', 'index.php?edd-listener=notification_e_nkap&merchantReferenceId=$matches[1]', 'top');
            add_rewrite_tag('%merchantReferenceId%', '([^&]+)');

            add_rewrite_rule('edd-e-nkap/status/(.+?)/?$', 'index.php?edd-listener=status_e_nkap&merchantReferenceId=$matches[1]', 'top');
            add_rewrite_tag('%merchantReferenceId%', '([^&]+)');
        }

        public function onPluginActionLinks($links): array
        {
            $settings_link = [
                'settings' => '<a href="' .
                    admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways&section=edd_enkap') .
                    '" title="' . __('Settings', self::DOMAIN_TEXT) . '">' . __('Settings', self::DOMAIN_TEXT) . '</a>',
            ];
            return array_merge($settings_link, $links);
        }

        public function loadGatewayClass()
        {
            if (class_exists('\\Camoo\\Enkap\\Easy_Digital_Downloads\\' . $this->adapterName)) {
                return;
            }
            include_once(dirname(__DIR__) . '/includes/Gateway.php');
            include_once(dirname(__DIR__) . '/vendor/autoload.php');
        }

        public static function get_webhook_url($endpoint): string
        {
            return trailingslashit(get_home_url()) . 'edd-e-nkap/' . sanitize_text_field($endpoint);
        }

        public static function getEEDOrderIdByMerchantReferenceId($id_code): ?int
        {

            global $wpdb;
            if (!wp_is_uuid(sanitize_text_field($id_code))) {
                return null;
            }

            $db_prepare = $wpdb->prepare("SELECT * FROM `{$wpdb->prefix}edd_enkap_payments` WHERE `merchant_reference_id` = %s", $id_code);
            $payment = $wpdb->get_row($db_prepare);

            if (!$payment) {
                return null;
            }

            return (int)$payment->edd_order_id;
        }

        public static function getEnkapPaymentByMerchantOrderId($orderId)
        {
            global $wpdb;

            $orderId = absint(wp_unslash($orderId));

            $db_prepare = $wpdb->prepare("SELECT * FROM `{$wpdb->prefix}edd_enkap_payments` WHERE `edd_order_id` = %d", $orderId);
            $payment = $wpdb->get_row($db_prepare);

            if (!$payment) {
                return null;
            }

            return $payment;
        }

        public static function getLanguageKey(): string
        {
            $local = sanitize_text_field(get_locale());
            if (empty($local)) {
                return 'fr';
            }

            $localExploded = explode('_', $local);

            $lang = $localExploded[0];

            return in_array($lang, ['fr', 'en']) ? $lang : 'en';
        }

        public static function loadTextDomain(): void
        {
            load_plugin_textdomain(
                self::DOMAIN_TEXT,
                false,
                dirname(plugin_basename(__FILE__)) . '/languages'
            );
        }

        public static function processWebhookStatus(EDD_Payment $order, string $status): bool
        {
            $result = false;
            switch (sanitize_text_field($status)) {
                case Status::IN_PROGRESS_STATUS:
                case Status::CREATED_STATUS:
                case Status::INITIALISED_STATUS:
                    $result = self::processWebhookProgress($order, $status);
                    break;
                case Status::CONFIRMED_STATUS:
                    $result = self::processWebhookConfirmed($order);
                    break;
                case Status::CANCELED_STATUS:
                    $result = self::processWebhookCanceled($order);
                    break;
                case Status::FAILED_STATUS:
                    $result = self::processWebhookFailed($order);
                    break;
                default:
                    break;
            }
            return $result;
        }

        /**
         * @param EDD_Payment $order
         * @return bool
         */
        private static function processWebhookConfirmed(EDD_Payment $order): bool
        {
            self::applyStatusChange(Status::CONFIRMED_STATUS, $order->transaction_id);
            $order->add_note(sprintf(__('E-nkap payment completed! Transaction ID: %s', self::DOMAIN_TEXT),
                $order->transaction_id));
            return $order->update_status('completed');
        }


        private static function processWebhookProgress(EDD_Payment $order, string $realStatus): bool
        {
            self::applyStatusChange($realStatus, $order->transaction_id);
            return $order->update_status('processing');
        }


        private static function processWebhookCanceled(EDD_Payment $order): bool
        {
            self::applyStatusChange(Status::CANCELED_STATUS, $order->transaction_id);
            $order->add_note(sprintf(__('E-nkap payment cancelled! Transaction ID: %s', self::DOMAIN_TEXT),
                $order->transaction_id));
            return $order->update_status('revoked');
        }

        private static function processWebhookFailed(EDD_Payment $order): bool
        {
            self::applyStatusChange(Status::FAILED_STATUS, $order->transaction_id);
            return $order->update_status('failed');
        }

        private static function applyStatusChange(string $status, string $transactionId)
        {
            global $wpdb;
            $remoteIp = WC_Geolocation::get_ip_address();
            $setData = [
                'status_date' => current_time('mysql'),
                'status' => sanitize_title($status)
            ];
            if ($remoteIp) {
                $setData['remote_ip'] = sanitize_text_field($remoteIp);
            }
            $wpdb->update(
                $wpdb->prefix . "edd_enkap_payments",
                $setData,
                [
                    'order_transaction_id' => sanitize_text_field($transactionId)
                ]
            );

        }

    }

endif;
