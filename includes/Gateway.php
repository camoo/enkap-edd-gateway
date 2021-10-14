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
use WP_error;
use WP_REST_Response;
use WP_REST_Server;

defined('ABSPATH') || exit;


class EDD_Enkap_Gateway
{
    public const GATEWAY_ID = 'edd_enkap';
    private $title;
    private $_key;
    private $_secret;
    private $testMode;

    /**
     * @var string
     */
    private $id = self::GATEWAY_ID;

    private const ADMIN_OVERVIEW = 'edit.php?post_type=download&page=edd-payment-history';

    public function __construct()
    {
        $this->title = esc_html__('SmobilPay for e-commerce', Plugin::DOMAIN_TEXT);
        $this->testMode = '1' === sanitize_text_field($this->get_option($this->id . '_test_mode'));

        $this->_key = sanitize_text_field($this->get_option($this->id . '_key'));
        $this->_secret = sanitize_text_field($this->get_option($this->id . '_secret'));

        if (is_admin()) {
            add_filter('edd_settings_gateways', [$this, 'init_settings'], 1, 1);
            add_filter('edd_view_order_details_payment_meta_after', [$this, 'onAdminDetailAction']);
            add_filter('edd_purchase_history_header_after', [__CLASS__, 'extendPaymentHeaderView'], 10);
            add_action('edd_purchase_history_row_end', [__CLASS__, 'extendPaymentRowView'], 10, 2);
            add_action('admin_init', [$this, 'process_admin_return']);
            add_action('admin_post_edd_enkap_mark_order_status', [$this, 'checkRemotePaymentStatus']);
        }

        add_action('edd_' . $this->id . '_cc_form', [__CLASS__, 'remove_cc_form']);
        add_action('edd_gateway_' . $this->id, array($this, 'process_payment'));
        add_filter('edd_accepted_payment_icons', array($this, 'payment_icon'));
        add_filter('edd_payment_gateways', [$this, 'onAddGateway']);
        add_filter('edd_settings_sections_gateways', array($this, 'onEddENkapSettingsSection'), 10, 1);

        add_action('rest_api_init', [$this, 'notification_route']);
        add_action('rest_api_init', [$this, 'return_route']);
    }

    /**
     * we only register the action so that the default CC form is not shown
     */
    public static function remove_cc_form()
    {
    }

    public static function extendPaymentHeaderView()
    {
        $columns = [
            '<th class="edd_enkap_purchase_merchant_ref">' . esc_html__(
                'e-nkap Merchant Reference ID',
                Plugin::DOMAIN_TEXT
            ) . '</th>',
            '<th class="edd_enkap_purchase_transaction_id">' . esc_html__(
                'e-nkap Transaction ID',
                Plugin::DOMAIN_TEXT
            ) . '</th>'
        ];

        echo implode("\n", $columns);
    }

    public static function extendPaymentRowView($paymentId, $paymentMeta)
    {
        $paymentData = Plugin::getEnkapPaymentByOrderId($paymentId);
        $columns = [
            '					<td class="edd_enkap_purchase_merchant_ref">
						<span class="edd_enkap_purchase_merchant_ref">' .
            esc_html($paymentData->merchant_reference_id ?? '') . '</span>
					</td>',
            '					<td class="edd_enkap_purchase_transaction_id">
						<span class="edd_enkap_purchase_transaction_id">' .
            esc_html($paymentData->order_transaction_id ?? '') . '</span>
					</td>',

        ];

        echo implode("\n", $columns);
    }

    protected function get_option($key): string
    {
        return trim(edd_get_option($key));
    }

    public function onAddGateway($gateways)
    {
        $gateways[$this->id] = [
            'admin_label' => esc_html__('SmobilPay for e-commerce', Plugin::DOMAIN_TEXT),
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
        $icons[plugin_dir_url(dirname(__FILE__)) . 'assets/images/e-nkap.png'] = esc_attr__(
            'SmobilPay for e-commerce',
            Plugin::DOMAIN_TEXT
        );
        return $icons;
    }

    public function init_settings($settings): array
    {
        $edd_enkap_settings = [
            [
                'id' => 'header_' . $this->id,
                'name' => esc_html__('SmobilPay for e-commerce Gateway Settings', Plugin::DOMAIN_TEXT),
                'desc' => esc_html__('Configure the SmobilPay for e-commerce gateway settings', Plugin::DOMAIN_TEXT),
                'type' => 'header'
            ],
            [
                'id' => $this->id . '_test_mode',
                'name' => esc_html__('Test mode', Plugin::DOMAIN_TEXT),
                'tooltip_title' => esc_html__('Enable Test Mode', Plugin::DOMAIN_TEXT),
                'type' => 'checkbox',
                'tooltip_desc' => esc_html__(
                    'Place the payment gateway in test mode using test API keys.',
                    Plugin::DOMAIN_TEXT
                ),
                'std' => 0,
            ],
            [
                'id' => $this->id . '_currency',
                'name' => esc_html__('Currency', 'easy-digital-downloads'),
                'tooltip_title' => esc_html__('Enkap Currency', Plugin::DOMAIN_TEXT),
                'type' => 'select',
                'tooltip_desc' => esc_html__('Define the currency to place your payments', Plugin::DOMAIN_TEXT),
                'default' => 'XAF',
                'options' => ['XAF' => esc_html__('CFA-Franc BEAC', Plugin::DOMAIN_TEXT)],
                'desc_tip' => true,
                'size' => 'regular'
            ],
            [
                'id' => 'config_' . $this->id,
                'name' => __('API Settings', 'easy-digital-downloads'),
                'desc' =>
                    wp_kses(
                        sprintf(
                            __('Enter your SmobilPay for e-commerce API credentials to process Payments via SmobilPay for e-commerce. Learn how to access your <a href="%s" target="_blank" rel="noopener noreferrer">SmobilPay for e-commerce API Credentials</a>.',
                                Plugin::DOMAIN_TEXT)
                            , 'https://enkap.cm/faq/'),
                        [
                            'a' => [
                                'href' => true,
                                'target' => true,
                                'rel' => true,
                            ],
                        ]
                    ),
                'type' => 'descriptive_text'
            ],
            [
                'id' => $this->id . '_key',
                'name' => esc_html__('Consumer Key', Plugin::DOMAIN_TEXT),
                'tooltip_title' => __('Consumer Key', Plugin::DOMAIN_TEXT),
                'type' => 'text',
                'tooltip_desc' => esc_html__('Get your API Consumer Key from SmobilPay for e-commerce.', Plugin::DOMAIN_TEXT),
                'size' => 'regular'
            ],
            [
                'id' => $this->id . '_secret',
                'name' => esc_html__('Consumer Secret', Plugin::DOMAIN_TEXT),
                'type' => 'password',
                'default' => '',
                'size' => 'regular',
                'tooltip_title' => __('Consumer Secret', Plugin::DOMAIN_TEXT),
                'tooltip_desc' => esc_html__('Get your API Consumer Secret from SmobilPay for e-commerce.', Plugin::DOMAIN_TEXT),
            ],
            [
                'id' => $this->id . '_description',
                'name' => esc_html__('Description', Plugin::DOMAIN_TEXT),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', Plugin::DOMAIN_TEXT),
                'default' => esc_html__('Pay with your mobile phone via SmobilPay for e-commerce.', Plugin::DOMAIN_TEXT),
                'desc_tip' => true,
                'size' => 'regular',
                'class' => 'edd-hidden'
            ],
            [
                'id' => $this->id . '_instructions',
                'name' => esc_html__('Instructions', Plugin::DOMAIN_TEXT),
                'type' => 'textarea',
                'description' => esc_html__('Instructions that will be added to the thank you page.', Plugin::DOMAIN_TEXT),
                'default' => esc_html__('Secured Payment with Enkap. Smobilpay for e-commerce', Plugin::DOMAIN_TEXT),
                'desc_tip' => true,
                'size' => 'regular',
                'class' => 'edd-hidden'
            ],
        ];

        $settings[$this->id] = apply_filters('edd_enkap_settings', $edd_enkap_settings);

        return $settings;
    }

    public function process_admin_return()
    {
        $option_page = filter_input(INPUT_POST, 'option_page');
        $action = filter_input(INPUT_POST, 'action');

        if ($option_page !== 'edd_settings' || $action !== 'update') {
            return;
        }

        $edd_settings = $_POST['edd_settings'];

        $consumerKey = $edd_settings[self::GATEWAY_ID . '_key'] ?? null;
        $consumerSecret = $edd_settings[self::GATEWAY_ID . '_secret'] ?? null;
        $testModeValue = $edd_settings[self::GATEWAY_ID . '_test_mode'] ?? false;

        if (empty($consumerKey) || empty($consumerSecret)) {
            return;
        }
        $this->_key = sanitize_text_field($consumerKey);
        $this->_secret = sanitize_text_field($consumerSecret);
        $isTest = !empty(sanitize_text_field($testModeValue)) && $testModeValue !== '-1';

        $setup = new CallbackUrlService($this->_key, $this->_secret, [], $isTest);
        /** @var CallbackUrl $callBack */
        try {
            $callBack = $setup->loadModel(CallbackUrl::class);
            $callBack->return_url = Plugin::get_webhook_url('return');
            $callBack->notification_url = Plugin::get_webhook_url('notification');
            $result = $setup->set($callBack);
        } catch (Throwable $exception) {
            edd_record_gateway_error($this->id . '_callback_error', sanitize_text_field($exception->getMessage()));
        }

        if (empty($result)) {
            $message = __('Keys could not be setup properly. Please make sure that your Consumers keys pairs are valid.',
                Plugin::DOMAIN_TEXT);
            $return = __('Go back', Plugin::DOMAIN_TEXT);
            edd_record_gateway_error($this->id . '_callback_error', sanitize_text_field($message));
            wp_die(
                new WP_error('rest_invalid_param', sprintf(
                    '<div><p>%s | <a href="%s">%s</a></p></div>',
                    esc_html($message), esc_url(wp_get_referer() ? wp_get_referer() : admin_url()),
                    $return
                ),
                    Plugin::DOMAIN_TEXT),
                array('status' => 400)
            );
        }
    }

    public function process_payment($purchase_data)
    {

        $payment_data = [
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
        ];

        $payment = edd_insert_payment($payment_data);

        if (!$payment) {
            edd_record_gateway_error(
                'Payment Error',
                sprintf(
                    'Payment creation failed before sending buyer to SmobilPay for e-commerce. Payment data: %s',
                    json_encode($payment_data)
                ),
                $payment
            );
            edd_send_back_to_checkout(['payment-mode' => $this->id]);
        } else {
            $orderService = new OrderService($this->_key, $this->_secret, [], $this->testMode);
            $order = $orderService->loadModel(Order::class);
            $merchantReferenceId = wp_generate_uuid4();
            $orderData = [
                'merchantReference' => $merchantReferenceId,
                'email' => $purchase_data['user_email'],
                'customerName' => $purchase_data['user_info']['first_name'] . ' ' .
                    $purchase_data['user_info']['last_name'],
                'totalAmount' => (float)$purchase_data['price'],
                'description' => 'Payment from ' . get_bloginfo('name'),
                'currency' => sanitize_text_field($this->get_option($this->id . '_currency')),
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
                edd_insert_payment_note($payment, __(
                    'SmobilPay for e-commerce payment accepted awaiting partner confirmation',
                    Plugin::DOMAIN_TEXT
                ));
                $this->logEnkapPayment($payment, $merchantReferenceId, $response->getOrderTransactionId());
                Helper::redirect($response->getRedirectUrl(), true);
            } catch (Throwable $exception) {
                edd_record_gateway_error('Payment Error', sanitize_text_field($exception->getMessage()));
                edd_set_error($this->id . '_error',
                    esc_html__('Can\'t connect to the SmobilPay for e-commerce gateway, Please try again.', Plugin::DOMAIN_TEXT));
                edd_send_back_to_checkout(['payment-mode' => $this->id]);
            }
        }
        Helper::exitOrDie();
    }

    public function onReturn()
    {
        $merchantReferenceId = Helper::getOderMerchantIdFromUrl();
        $orderId = Plugin::getEEDOrderIdByMerchantReferenceId($merchantReferenceId);

        if (empty($orderId)) {
            wp_redirect(get_home_url());
            Helper::exitOrDie();
        }
        $status = filter_input(INPUT_GET, 'status');

        $payment = new EDD_Payment($orderId);

        if ($status && !empty($payment->ID)) {
            Plugin::processWebhookStatus($payment, $status);
        }
        if (in_array($status, [Status::CANCELED_STATUS, Status::FAILED_STATUS])) {
            edd_set_error($this->id . '_failed_payment',
                esc_html__('Payment failed. Please try again.', Plugin::DOMAIN_TEXT));
            edd_send_back_to_checkout(['payment-mode' => $this->id]);
        } else {
            edd_empty_cart();
            edd_send_to_success_page();
        }

        Helper::exitOrDie();
    }

    public function onNotification(): WP_REST_Response
    {
        $merchantReferenceId = sanitize_text_field(Helper::getOderMerchantIdFromUrl());

        $orderId = Plugin::getEEDOrderIdByMerchantReferenceId($merchantReferenceId);

        if (empty($orderId)) {
            return new WP_REST_Response([
                'status' => 'KO',
                'message' => 'Bad Request'
            ], 400);
        }

        $requestBody = WP_REST_Server::get_raw_data();
        $bodyData = json_decode($requestBody, true);

        $status = $bodyData['status'];

        if (empty($status) || !in_array(sanitize_text_field($status), Status::getAllowedStatus())) {
            return new WP_REST_Response([
                'status' => 'KO',
                'message' => 'Bad Request'
            ], 400);
        }

        $order = new EDD_Payment($orderId);
        $oldStatus = '';
        if ($order->ID > 0) {
            $oldStatus = $order->status;
            Plugin::processWebhookStatus($order, sanitize_text_field($status));
        }

        return new WP_REST_Response([
            'status' => 'OK',
            'message' => sprintf('Status Updated From %s To %s', $oldStatus, $order->status)
        ], 200);
    }

    public function onAdminDetailAction($payment_id)
    {
        $payment = Plugin::getEnkapPaymentByOrderId($payment_id);

        if (empty($payment)) {
            return;
        }

        echo '<div class="edd-enkap-track edd-admin-box-inside">';
        echo '<h3>SmobilPay for e-commerce details</h3>';
        echo '<p> ' . esc_html__('e-nkap Merchant Reference ID', Plugin::DOMAIN_TEXT) . ': <strong>' .
            esc_html($payment->merchant_reference_id) . '</strong></p>';
        echo '<p> ' . esc_html__('e-nkap Transaction ID', Plugin::DOMAIN_TEXT) . ': <strong>' .
            esc_html($payment->order_transaction_id) . '</strong></p>';

        if (empty($payment->status) ||
            in_array(strtolower($payment->status), ['in_progress', 'created', 'initialised'], true)) {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=edd_enkap_mark_order_status&status=check&order_id=' .
                    absint(wp_unslash($payment_id))),
                'edd_enkap_check_status'
            );
            echo wp_kses('<a href="' . esc_url($url) .
                '" class="button check-status">' . __('Check Payment status', Plugin::DOMAIN_TEXT) . '</a>',
                [
                    'a' => [
                        'href' => true,
                        'class' => true,
                    ]
                ]
            );
        }
        echo '</div>';
    }

    protected function logEnkapPayment(int $orderId, string $merchantReferenceId, string $orderTransactionId)
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . "edd_enkap_payments",
            [
                'edd_order_id' => absint(wp_unslash($orderId)),
                'order_transaction_id' => sanitize_text_field($orderTransactionId),
                'merchant_reference_id' => sanitize_text_field($merchantReferenceId),
            ]
        );
    }

    public function checkRemotePaymentStatus()
    {
        if (current_user_can('edit_shop_orders') &&
            check_admin_referer('edd_enkap_check_status') &&
            isset($_GET['status'], $_GET['order_id'])) {
            $status = sanitize_text_field(wp_unslash($_GET['status']));

            $orderId = absint(wp_unslash($_GET['order_id']));
            $order = new EDD_Payment($orderId);
            if ($status === 'check' && !empty($order) && in_array($order->status, ['pending', 'processing'])) {
                $consumerKey = $this->_key;
                $consumerSecret = $this->_secret;
                $statusService = new StatusService($consumerKey, $consumerSecret, [], $this->testMode);
                $paymentData = Plugin::getEnkapPaymentByOrderId($orderId);
                if ($paymentData) {
                    $status = $statusService->getByTransactionId($paymentData->order_transaction_id);
                    Plugin::processWebhookStatus($order, $status->getCurrent());
                }
            }
        }

        wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url(self::ADMIN_OVERVIEW));
        Helper::exitOrDie();
    }

    public function return_route()
    {
        register_rest_route(
            'edd-e-nkap/return',
            '/(.*?)',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'onReturn'],
                'permission_callback' => '__return_true',
                'args' => [
                    'status' => [
                        'required' => true,
                        'validate_callback' => function ($param) {
                            return in_array($param, Status::getAllowedStatus());
                        }
                    ],
                ],
            ]
        );
        flush_rewrite_rules();
    }

    public function notification_route()
    {
        register_rest_route(
            'edd-e-nkap/notification',
            '/(.*?)',
            [
                'methods' => 'PUT',
                'callback' => [$this, 'onNotification'],
                'permission_callback' => '__return_true',
            ]
        );
        flush_rewrite_rules();
    }

}
