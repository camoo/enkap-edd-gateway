<?php

namespace Camoo\Enkap\Easy_Digital_Downloads;

use Enkap\OAuth\Lib\Helper;
use Enkap\OAuth\Model\Order;
use Enkap\OAuth\Model\Status;
use Enkap\OAuth\Services\OrderService;
use Enkap\OAuth\Services\StatusService;
use Enkap\OAuth\Services\PaymentService;
use Enkap\OAuth\Services\CallbackUrlService;
use Enkap\OAuth\Model\CallbackUrl;
use Throwable;

defined('ABSPATH') || exit;


class EDD_Enkap_Gateway
{
    private $title;
    private $_key;
    private $_secret;
    private $instructions;
    private $testmode;

    function __construct()
    {
        $this->id = "edd_enkap";
        $this->icon = null;
        $this->has_fields = true;

        $this->title = __('E-Nkap payment', $this->id);
        $this->testmode = 'yes' === $this->get_option('testmode');
        $this->description = $this->get_option('description');
        $this->instructions = $this->get_option('instructions');

        $this->_key = $this->get_option('enkap_key');
        $this->_secret = $this->get_option('enkap_secret');

        if ( is_admin() ) {
            add_filter('edd_settings_gateways', [$this, 'init_settings']);
            add_filter('edd_view_order_details_payment_meta_after', [$this, 'onAdminDetailAction']);
        }
        add_action( 'admin_init', array( $this, 'process_admin_return' ) );
        
        add_action('edd_'.$this->id.'_cc_form', '__return_false');
        add_action('edd_gateway_'.$this->id, array($this, 'process_payment'));
        add_action( 'init', array( $this, 'edd_enkap_redirect'), 20);
        add_action( $this->id.'_redirect_verify', array( $this, 'redirect_verify' ));
        add_action( $this->id.'_process_webhook', array( $this, 'process_webhook' ) );
        
        add_filter('edd_accepted_payment_icons', array($this, 'payment_icon'));
        add_filter('edd_payment_gateways', [$this, 'onAddGateway']);
        add_filter('edd_settings_sections_gateways', array($this, 'onEddENkapSettingsSection'), 10, 1);
    }
    
    protected function get_option($key)
    {
        return trim(edd_get_option($key));
    }

    public function onAddGateway($gateways)
    {
        $gateways['edd_enkap'] = array('admin_label' => sprintf(__('%s Payment Gateway', $this->id), 'E-nkap'), 'checkout_label' => $this->title);
        return $gateways;
    }
    
    public function onEddENkapSettingsSection($sections)
    {
        $sections['edd_enkap'] = $this->title;

        return $sections;
    }
    
    public function payment_icon($icons)
    {
        $icons[plugin_dir_url(__FILE__). 'assets/img/e-nkap.png'] = 'E-Nkap';
        return $icons;
    }

    public function init_settings($settings) {
	$edd_enkap_settings = array(
            array(
                'id' => 'header_'.$this->id,
                'name' => __('E-Nkap Gateway Settings', $this->id),
                'desc' => __('Configure the E-Nkap gateway settings', $this->id),
                'type' => 'header'
            ),
            array(
                'id' => 'testmode',
                'name' => 'Test mode',
                'label' => 'Enable Test Mode',
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in test mode using test API keys.', 'wp_enkap'),
                'default' => 'yes',
                'std' => 0,
            ),
            array(
                'id' => 'enkap_currency',
                'name' => __('Currency', 'wp_enkap'),
                'label' => 'Enkap Currency',
                'type' => 'select',
                'description' => __('Define the currency to place your payments', 'wp_enkap'),
                'default' => 'XAF',
                'options' => ['XAF' => __('CFA-Franc BEAC', 'wp_enkap')],
                'desc_tip' => true,
                'size' => 'regular'
            ),
            array(
                'id' => 'enkap_key',
                'name' => __('Consumer Key', $this->id),
                'type' => 'text',
                'description' => __('Get your API Consumer Key from E-nkap.', $this->id),
                'size' => 'regular'
            ),
            array(
                'id' => 'enkap_secret',
                'name' => __('Consumer Secret', $this->id),
                'type' => 'password',
                'description' => __('Get your API Consumer Secret from E-nkap.', $this->id),
                'default' => '',
                'size' => 'regular'
            ),
            array(
                'id' => 'description',
                'name' => __('Description', $this->id),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'wp_enkap'),
                'default' => __('Pay with your mobile phone via E-nkap payment gateway.', 'wp_enkap'),
                'desc_tip' => true,
                'size' => 'regular'
            ),
            array(
                'id' => 'instructions',
                'name' => __('Instructions', $this->id),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to the thank you page.', 'woocommerce'),
                'default' => __('Secured Payment with Enkap. Smobilpay for e-commerce', 'wp_enkap'),
                'desc_tip' => true,
                'size' => 'regular'
            ),
	);
        
        if (version_compare(EDD_VERSION, 2.5, '>=')) {
            $edd_enkap_settings = array('edd_enkap' => $edd_enkap_settings);
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
        if (!$this->_key || !$this->_key) {
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
            edd_record_gateway_error('Payment Error', sprintf('Payment creation failed before sending buyer to E-Nkap. Payment data: %s', json_encode($payment_data)), $payment);
            edd_send_back_to_checkout('?payment-mode='.$this->id);
        } else {
            $orderService = new OrderService($this->_key, $this->_secret);
            $order = $orderService->loadModel(Order::class);
            $merchantReferenceId = wp_generate_uuid4();
            $dataData = [
                'merchantReference' => $merchantReferenceId,
                'email' => $purchase_data['user_email'],
                'customerName' => $purchase_data['user_info']['first_name'] . ' ' . $purchase_data['user_info']['last_name'],
                'totalAmount' => (float)$purchase_data['price'],
                'description' => 'Payment from ' . get_bloginfo('name'),
                'currency' => $this->get_option('enkap_currency'),
                'items' => []
            ];
            foreach ($purchase_data['cart_details'] as $item) {
                $dataData['items'][] = [
                    'itemId' => (int)$item['id'],
                    'particulars' => $item['name'],
                    'unitCost' => (float)$item['price'],
                    'quantity' => $item['quantity']
                ];
            }
            try {
                $order->fromStringArray($dataData);
                $response = $orderService->place($order);            
                edd_set_payment_transaction_id($payment, $response->getOrderTransactionId());
                edd_insert_payment_note($payment,  __('E-Nkap payment accepted awaiting partner confirmation', $this->id));
                $this->logEnkapPayment($payment, $merchantReferenceId, $response->getOrderTransactionId());
                wp_redirect($response->getRedirectUrl());
            } catch (Throwable $e) {
                edd_record_gateway_error( 'Payment Error', $e->getMessage() );
                edd_set_error( $this->id . '_error', 'Can\'t connect to the E-Nkap gateway, Please try again.' );
                edd_send_back_to_checkout( '?payment-mode=' .$this->id );
            }
        }
        return null;
    }

    public function onReturn()
    {
        $merchantReferenceId = Helper::getOderMerchantIdFromUrl();
        $payment_id = (int)Plugin::getEEDOrderIdByMerchantReferenceId($merchantReferenceId);

        if (empty($payment_id))  {
            Plugin::displayNotFoundPage();
            exit();
        }
        $status = filter_input(INPUT_GET, 'status');
        
        $payment = new \EDD_Payment($payment_id);

        if ($status && $payment) {
            $this->processWebhook($payment_id, $status);
        }
        edd_empty_cart();
        edd_send_to_success_page();
        exit;
    }

    public function onNotification()
    {
        $merchantReferenceId = Helper::getOderMerchantIdFromUrl();
        $payment_id = (int)Plugin::getEEDOrderIdByMerchantReferenceId($merchantReferenceId);

        if (empty($payment_id))  {
            Plugin::displayNotFoundPage();
            exit();
        }
        $status = filter_input(INPUT_GET, 'status');
        
        $payment = new \EDD_Payment($payment_id);
        if ($status && $payment) {
            $this->processWebhook($payment_id, $status);
        }
        die;
    }

    public function onCheckStatus()
    {
        $merchantReferenceId = Helper::getOderMerchantIdFromUrl();
        $payment_id = (int)Plugin::getEEDOrderIdByMerchantReferenceId($merchantReferenceId);
        
        $payment = new \EDD_Payment($payment_id);
        $status = null;
        if ($payment && is_object($payment)) {
            $statusService = new StatusService($this->_key, $this->_secret);
            $status = $statusService->getByTransactionId($payment->transaction_id);
        }
        if ($status && is_object($status)) {
            $current_status = $payment->status;
            if ($status->confirmed() && $current_status != 'complete') {
                $this->processWebhookConfirmed($payment_id);
            } elseif ( ($status->initialized() || $status->created())  && $current_status != 'pending') {
                $this->processWebhookProgress($payment_id);
            } elseif ( $status->isInProgress()  && $current_status != 'pending') {
                $this->processWebhookProgress($payment_id);
            } elseif ($status->canceled() && $current_status != 'cancelled') {
                $this->processWebhookCanceled($payment_id);
            } elseif ($status->failed() && $current_status != 'failed') {
                $this->processWebhookFailed($payment_id);
            }
            die($status->getCurrent());
        }
        die('Done');
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
            echo '<p> UUID: <strong>' . $payment->merchant_reference_id . '</strong></p>';
            echo '<a href="'.Plugin::get_webhook_url('status').'/'.$payment->merchant_reference_id.'" target="_blank" class="button check-status">' . __('Check Payment status') . '</a>';
            echo '</div>';
        }
    }

    public function processWebhook($order_id, $status)
    {
        switch ($status) {
            case Status::IN_PROGRESS_STATUS :
            case Status::CREATED_STATUS :
                $this->processWebhookProgress($order_id);
                break;
            case Status::CONFIRMED_STATUS :
                $this->processWebhookConfirmed($order_id);
                break;
            case Status::CANCELED_STATUS :
                $this->processWebhookCanceled($order_id);
                edd_set_error('failed_payment', 'Payment canceled. Please try again.');
                edd_send_back_to_checkout('?payment-mode='.$this->id);
            case Status::FAILED_STATUS :
                $this->processWebhookFailed($order_id);
                edd_set_error('failed_payment', 'Payment failed. Please try again.');
                edd_send_back_to_checkout('?payment-mode='.$this->id);
                break;
            default :
        }

    }

    private function processWebhookConfirmed($payment_id)
    {
        $payment = new \EDD_Payment($payment_id);
        if ($payment->status !== 'complete') {
            $payment->status = 'complete';
            $payment->add_note( sprintf( __( '%s payment approved! Trnsaction ID: %s', $this->id ), $this->title, $payment->order_transaction_id ) );
            $payment->save();
        }
    }

    private function processWebhookProgress($payment_id)
    {
        $payment = new \EDD_Payment($payment_id);
        if ($payment->status !== 'pending') {
            $payment->status = 'pending';
            $payment->add_note( __('Awaiting E-Nkap payment confirmation', $this->id));
            $payment->save();
        }
    }

    private function processWebhookCanceled($payment_id)
    {
        $payment = new \EDD_Payment($payment_id);
        if ($payment->status !== 'revoked') {
            $payment->status = 'revoked';
            $payment->add_note( sprintf( __( '%s payment cancelled! Transaction ID: %d', $this->id ), $this->title, '' ) );
            $payment->save();
        }
    }

    private function processWebhookFailed($payment_id)
    {
        $payment = new \EDD_Payment($payment_id);
        if ($payment->status !== 'revoked') {
            $payment->status = 'revoked';
            $payment->add_note( sprintf( __( '%s payment failed! Transaction ID: %d', $this->id ), $this->title, '' ) );
            $payment->save();
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
        if (!$action) {
            return;
        }
        if ($action == 'return') {
            return $this->onReturn();
        } elseif ($action == 'notification') {
            return $this->onNotification();
        } elseif ($action == 'status') {
            return $this->onCheckStatus();
        }
    }
    
    public function redirect_verify() {
        
    }
    
    public function verify_transaction($payment_reference) {
        
    }
    
    protected function get_action_from_url()
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
        return array_pop($urlExploded);
    }
}
