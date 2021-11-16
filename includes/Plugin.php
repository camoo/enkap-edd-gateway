<?php
/**
 * Description of Plugin
 *
 * @author Camoo Sarl
 */

namespace Camoo\Enkap\Easy_Digital_Downloads;

use EDD_Payment;
use Enkap\OAuth\Model\Status;

defined('ABSPATH') || exit;
if (!class_exists(Plugin::class)):

    class Plugin
    {
        public const WP_EDD_ENKAP_DB_VERSION = '1.0.2';
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
            $this->title = __('SmobilPay for e-commerce Payment Gateway', self::DOMAIN_TEXT);
        }

        public function register()
        {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
            require_once __DIR__ . '/Install.php';
            if (!is_plugin_active('easy-digital-downloads/easy-digital-downloads.php')) {
                return;
            }

            register_activation_hook($this->pluginPath, [Install::class, 'install']);

            add_filter('plugin_action_links_' . plugin_basename($this->pluginPath),
                [$this, 'onPluginActionLinks'], 1, 1);
            add_action('plugins_loaded', [$this, 'onInit']);
        }

        public function onInit()
        {
            $this->loadGatewayClass();
            if (class_exists('\\Camoo\\Enkap\\Easy_Digital_Downloads\\' . $this->adapterName)) {
                (new EDD_Enkap_Gateway());
            }

            add_action('init', [__CLASS__, 'loadTextDomain']);
            register_deactivation_hook($this->pluginPath, array($this, 'flush_rules'));
        }

        public function flush_rules()
        {
            flush_rewrite_rules();
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
            if (get_option('permalink_structure')) {
                return trailingslashit(get_home_url()) . 'wp-json/edd-e-nkap/' . sanitize_text_field($endpoint);
            }

            return add_query_arg('rest_route', '/edd-e-nkap/' . sanitize_text_field($endpoint),
                trailingslashit(get_home_url()));
        }

        public static function getEEDOrderIdByMerchantReferenceId(string $referenceId): ?int
        {
            global $wpdb;
            if (!wp_is_uuid(sanitize_text_field($referenceId))) {
                return null;
            }

            $db_prepare = $wpdb->prepare("SELECT * FROM `{$wpdb->prefix}edd_enkap_payments` WHERE `merchant_reference_id` = %s", $referenceId);
            $payment = $wpdb->get_row($db_prepare);

            if (!$payment) {
                return null;
            }

            return (int)$payment->edd_order_id;
        }

        public static function getEnkapPaymentByOrderId(int $orderId)
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
            $order->add_note(sprintf(
                __('SmobilPay for e-commerce payment completed! Transaction ID: %s', self::DOMAIN_TEXT),
                $order->transaction_id
            ));
            return $order->update_status('completed');
        }

        private static function processWebhookProgress(EDD_Payment $order, string $realStatus): bool
        {
            if (in_array($order->status, ['complete', 'publish', 'completed'], true)) {
                return true;
            }
            self::applyStatusChange($realStatus, $order->transaction_id);

            return $order->update_status('processing');
        }

        private static function processWebhookCanceled(EDD_Payment $order): bool
        {
            self::applyStatusChange(Status::CANCELED_STATUS, $order->transaction_id);
            $order->add_note(sprintf(
                __('SmobilPay for e-commerce payment cancelled! Transaction ID: %s', self::DOMAIN_TEXT),
                $order->transaction_id
            ));
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
            $remoteIp = edd_get_ip();
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

            /**
             * Executes the hook smobilpay_after_status_change where ever it's defined.
             *
             * Example usage:
             *
             *     // The action callback function.
             *     function example_callback( $id, $shopType ) {
             *         // (maybe) do something with the args.
             *     }
             *
             *     add_action( 'smobilpay_after_status_change', 'example_callback', 10, 2 );
             *
             *     /*
             *      * Trigger the actions by calling the 'example_callback()' function
             *      * that's hooked onto `smobilpay_after_status_change`.
             *
             *      * - $id is either the transaction ID or the merchant reference ID
             *      * - $shopType is the shop invoked actually the hook
             *
             * @since 1.0.3
             */
            do_action('smobilpay_after_status_change', sanitize_text_field($transactionId), 'edd');

        }
    }

endif;
