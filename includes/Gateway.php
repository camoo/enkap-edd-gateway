<?php

namespace Camoo\Enkap\Easy_Digital_Downloads;

use EDD_Payment;
use Enkap\OAuth\Lib\Helper;
use Enkap\OAuth\Model\Order;
use Enkap\OAuth\Model\Status;
use Enkap\OAuth\Services\OrderService;
use Enkap\OAuth\Services\StatusService;
use Enkap\OAuth\Services\CallbackUrlService;
use Enkap\OAuth\Model\CallbackUrl;
use Throwable;

defined('ABSPATH') || exit;


class EDD_Enkap_Gateway
{
    private $title;
    private $_key;
    private $_secret;

    private $testMode;

    /**
     * @var string
     */
    private $id;

    private const ADMIN_OVERVIEW = 'edit.php?post_type=download&page=edd-payment-history';
    public function __construct()
    {
        $this->id = "edd_enkap";
        //$this->icon = null;
        //$this->has_fields = true;

        $this->title = __('E-nkap payment', Plugin::DOMAIN_TEXT);
        $this->testMode = 'yes' === sanitize_text_field($this->get_option('test_mode'));
        //$this->description = esc_html($this->get_option('description'));

        $this->_key = sanitize_text_field($this->get_option('enkap_key'));
        $this->_secret = sanitize_text_field($this->get_option('enkap_secret'));

        if ( is_admin() ) {
            add_filter('edd_settings_gateways', [$this, 'init_settings']);
            add_filter('edd_view_order_details_payment_meta_after', [$this, 'onAdminDetailAction']);
        }
        add_action( 'admin_init', array( $this, 'process_admin_return' ) );
        
        add_action('edd_'.$this->id.'_cc_form', '__return_false');
        add_action('edd_gateway_'.$this->id, array($this, 'process_payment'));
        add_action( 'init', array( $this, 'edd_enkap_redirect'), 20);
        add_action( $this->id.'_process_webhook', array( $this, 'process_webhook' ) );
        
        add_filter('edd_accepted_payment_icons', array($this, 'payment_icon'));
        add_filter('edd_payment_gateways', [$this, 'onAddGateway']);
        add_filter('edd_settings_sections_gateways', array($this, 'onEddENkapSettingsSection'), 10, 1);
    }
    
    protected function get_option($key): string
    {
        return trim(edd_get_option($key));
    }

    public function onAddGateway($gateways)
    {
        $gateways[$this->id] = [
            'admin_label' => __('E-nkap Payment Gateway', Plugin::DOMAIN_TEXT),
            'checkout_label' => $this->title
        ];
        return $gateways;
    }
    
    public function onEddENkapSettingsSection($sections)
    {
        $sections[$this->id] = $this->title;

        return $sections;
    }
    
    public function payment_icon($icons)
    {
        $icons[plugin_dir_url(dirname(__FILE__)). 'assets/images/e-nkap.png'] = __('E-nkap Payment Gateway',
            Plugin::DOMAIN_TEXT);
        return $icons;
    }

    public function init_settings($settings): array
    {
	$edd_enkap_settings = array(
            array(
                'id' => 'header_'.$this->id,
                'name' => __('E-Nkap Gateway Settings', Plugin::DOMAIN_TEXT),
                'desc' => __('Configure the E-Nkap gateway settings', Plugin::DOMAIN_TEXT),
                'type' => 'header'
            ),
            array(
                'id' => 'test_mode',
                'name' => 'Test mode',
                'label' => __('Enable Test Mode', Plugin::DOMAIN_TEXT),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in test mode using test API keys.', Plugin::DOMAIN_TEXT),
                'default' => 'yes',
                'std' => 0,
            ),
            array(
                'id' => 'enkap_currency',
                'name' => __('Currency', Plugin::DOMAIN_TEXT),
                'label' => __('Enkap Currency', Plugin::DOMAIN_TEXT),
                'type' => 'select',
                'description' => __('Define the currency to place your payments', Plugin::DOMAIN_TEXT),
                'default' => 'XAF',
                'options' => ['XAF' => __('CFA-Franc BEAC', Plugin::DOMAIN_TEXT)],
                'desc_tip' => true,
                'size' => 'regular'
            ),
            array(
                'id' => 'enkap_key',
                'name' => __('Consumer Key', Plugin::DOMAIN_TEXT),
                'type' => 'text',
                'description' => __('Get your API Consumer Key from E-nkap.', Plugin::DOMAIN_TEXT),
                'size' => 'regular'
            ),
            array(
                'id' => 'enkap_secret',
                'name' => __('Consumer Secret', Plugin::DOMAIN_TEXT),
                'type' => 'password',
                'description' => __('Get your API Consumer Secret from E-nkap.', Plugin::DOMAIN_TEXT),
                'default' => '',
                'size' => 'regular'
            ),
            array(
                'id' => 'description',
                'name' => __('Description', Plugin::DOMAIN_TEXT),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', Plugin::DOMAIN_TEXT),
                'default' => __('Pay with your mobile phone via E-nkap payment gateway.', Plugin::DOMAIN_TEXT),
                'desc_tip' => true,
                'size' => 'regular'
            ),
            array(
                'id' => 'instructions',
                'name' => __('Instructions', Plugin::DOMAIN_TEXT),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to the thank you page.', Plugin::DOMAIN_TEXT),
                'default' => __('Secured Payment with Enkap. Smobilpay for e-commerce', Plugin::DOMAIN_TEXT),
                'desc_tip' => true,
                'size' => 'regular'
            ),
	);
        
        if (version_compare(EDD_VERSION, 2.5, '>=')) {
            $edd_enkap_settings = [$this->id => $edd_enkap_settings];
        }
 
	return array_merge($settings, $edd_enkap_settings);
    }

    public function process_admin_return()
    {
        $option_page = filter_input(INPUT_POST, 'option_page');
        $action = filter_input(INPUT_POST, 'action');
        
        if ($option_page != 'edd_settings' || $action != 'update' || !isset($_POST['edd_settings']) || !is_array($_POST['edd_settings']) ) {
            return;
        }
        $edd_settings = $_POST['edd_settings'];
        if ( !isset($edd_settings['enkap_key']) || !isset($edd_settings['enkap_secret'])) {
            return;
        }
        $this->_key = $edd_settings['enkap_key'];
        $this->_secret = $edd_settings['enkap_secret'];
        if (!$this->_key || !$this->_secret) {
            return;
        }
        $setup = new CallbackUrlService($this->_key, $this->_secret);
        /** @var CallbackUrl $callBack */
        try {
            $callBack = $setup->loadModel(CallbackUrl::class);
            $callBack->return_url = Plugin::get_webhook_url('return');
            $callBack->notification_url = Plugin::get_webhook_url('notification');
            $setup->set($callBack);
        } catch (Throwable $exc) {
            echo $exc->getMessage();
        }
    }

    public function process_payment($purchase_data)
    {
        $payment_data = array(
            'price' => $purchase_data['price'],
            'date' => $purchase_data['date'],
            'user_email' => $purchase_data['user_email'],
            'purchase_key' => $purchase_data['purchase_key'],
            'currency' => edd_get_currency(),
            'downloads' => $purchase_data['downloads'],
            'cart_details' => $purchase_data['cart_details'],
            'user_info' => $purchase_data['user_info'],
            'status' => 'pending',
            'gateway' => $this->id,
        );

        $payment = edd_insert_payment($payment_data);

        if (!$payment) {
            edd_record_gateway_error('Payment Error',
                sprintf('Payment creation failed before sending buyer to E-Nkap. Payment data: %s',
                    json_encode($payment_data)), $payment);
            edd_send_back_to_checkout(['payment-mode' => $this->id]);
        } else {
            $orderService = new OrderService($this->_key, $this->_secret, [], $this->testMode);
            $order = $orderService->loadModel(Order::class);
            $merchantReferenceId = wp_generate_uuid4();
            $orderData = [
                'merchantReference' => $merchantReferenceId,
                'email' => $purchase_data['user_email'],
                'customerName' => $purchase_data['user_info']['first_name'] . ' ' . $purchase_data['user_info']['last_name'],
                'totalAmount' => (float)$purchase_data['price'],
                'description' => 'Payment from ' . get_bloginfo('name'),
                'currency' => sanitize_text_field($this->get_option('enkap_currency')),
                'langKey' => Plugin::getLanguageKey(),
                'items' => []
            ];
            foreach ($purchase_data['cart_details'] as $item) {
                $orderData['items'][] = [
                    'itemId' => (int)$item['id'],
                    'particulars' => $item['name'],
                    'unitCost' => (float)$item['price'],
                    'subTotal' => (float)$item['price'],
                    'quantity' => $item['quantity']
                ];
            }
            try {
                $order->fromStringArray($orderData);
                $response = $orderService->place($order);            
                edd_set_payment_transaction_id($payment, $response->getOrderTransactionId());
                edd_insert_payment_note($payment,  __('E-Nkap payment accepted awaiting partner confirmation',
                    Plugin::DOMAIN_TEXT));
                $this->logEnkapPayment($payment, $merchantReferenceId, $response->getOrderTransactionId());
                wp_redirect($response->getRedirectUrl());
            } catch (Throwable $e) {
                edd_record_gateway_error( 'Payment Error', sanitize_text_field($e->getMessage()) );
                edd_set_error( $this->id . '_error', 'Can\'t connect to the E-Nkap gateway, Please try again.' );
                edd_send_back_to_checkout( ['payment-mode' => $this->id]);
            }
        }
        return null;
    }

    public function onReturn()
    {
        $merchantReferenceId = Helper::getOderMerchantIdFromUrl();
        $paymentReferenceId = Plugin::getEEDOrderIdByMerchantReferenceId($merchantReferenceId);

        if (empty($paymentReferenceId))  {
            wp_redirect(get_permalink(get_the_ID()));
            exit();
        }
        $status = filter_input(INPUT_GET, 'status');
        
        $payment = new EDD_Payment($paymentReferenceId);

        if ($status && !empty($payment->ID)) {
            Plugin::processWebhookStatus($payment, $status);
        }
        if (in_array($status, [Status::CANCELED_STATUS, Status::FAILED_STATUS])) {
            edd_set_error('failed_payment', 'Payment failed. Please try again.');
            edd_send_back_to_checkout(['payment-mode' => $this->id]);
        }else{
            edd_empty_cart();
            edd_send_to_success_page();
        }

        exit;
    }

    public function onNotification()
    {
        $merchantReferenceId = Helper::getOderMerchantIdFromUrl();
        $payment_id = (int)Plugin::getEEDOrderIdByMerchantReferenceId($merchantReferenceId);

        if (empty($payment_id))  {
            wp_redirect(get_permalink(get_the_ID()));
            exit();
        }
        $status = filter_input(INPUT_GET, 'status');
        
        $payment = new EDD_Payment($payment_id);
        if ($status && !empty($payment->ID)) {
            Plugin::processWebhookStatus($payment, $status);
        }
        die;
    }

    public function onCheckStatus()
    {
        $merchantReferenceId = Helper::getOderMerchantIdFromUrl();
        $payment_id = Plugin::getEEDOrderIdByMerchantReferenceId($merchantReferenceId);

        $order = new EDD_Payment($payment_id);

        if ($order->ID === 0) {
            wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url(self::ADMIN_OVERVIEW));
            exit;
        }
        $statusService = new StatusService($this->_key, $this->_secret, [], $this->testMode);
        try {
            $status = $statusService->getByTransactionId($order->transaction_id);
        } catch (Throwable $exception) {
            echo esc_html($exception->getMessage());
            wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url(self::ADMIN_OVERVIEW));
            exit;
        }
        Plugin::processWebhookStatus($order, $status->getCurrent());

        wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url(self::ADMIN_OVERVIEW));
        exit;
    }

    public function onAdminDetailButtonAction( $actions, $order )
    {
        if ( $order->has_status( array( 'processing' ) ) ) {
            $order_id = method_exists( $order, 'get_id' ) ? $order->get_id() : $order->id;
            $actions['parcial'] = array(
                'url'       => wp_nonce_url( admin_url( 'admin-ajax.php?action=woocommerce_mark_order_status&status=parcial&order_id=' . $order_id ), 'woocommerce-mark-order-status' ),
                'name'      => __( 'Envio parcial', 'woocommerce' ),
                'action'    => "view parcial",
            );
        }
    }

    public function onAdminDetailAction( $payment_id )
    {
        $payment = Plugin::getEnkapPaymentByMerchantOrderId($payment_id);
        if ($payment && is_object($payment)) {
            echo '<div class="edd-enkap-track edd-admin-box-inside">';
            echo '<h3>E-Nkap details</h3>';
            echo '<p> UUID: <strong>' . esc_html($payment->merchant_reference_id) . '</strong></p>';
            echo '<a href="'.Plugin::get_webhook_url('status').'/'.esc_html($payment->merchant_reference_id).'" target="_blank" class="button check-status">' . __('Check Payment status') . '</a>';
            echo '</div>';
        }
    }

    protected function logEnkapPayment(int $orderId, string $merchantReferenceId, string $orderTransactionId)
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . "edd_enkap_payments",
            [
                'edd_order_id' => $orderId,
                'order_transaction_id' => $orderTransactionId,
                'merchant_reference_id' => $merchantReferenceId,
            ]
        );
    }
    
    public function edd_enkap_redirect() {
        $action = $this->get_action_from_url();
        if (empty($action)) {
            wp_redirect(get_permalink(get_the_ID()));
            exit();
        }
        if ($action === 'return') {
            $this->onReturn();
            exit();
        } elseif ($action === 'notification') {
            $this->onNotification();
            exit();
        } elseif ($action == 'status') {
             $this->onCheckStatus();
            exit();
        }
    }
    
    protected function get_action_from_url(): string
    {
        if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
            $protocol = 'http';
        } else {
            $protocol = 'https';
        }
        $url = $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $urlPath = rtrim(parse_url($url, PHP_URL_PATH), '/');
        $urlExploded = explode('/', $urlPath);
        array_pop($urlExploded);
        return sanitize_text_field(array_pop($urlExploded));
    }
}
