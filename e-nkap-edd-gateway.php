<?php

/**
 * Plugin Name: E-nkap for Easy Digital Downloads
 * Plugin URI: https://github.com/camoo/enkap-edd-gateway
 * Description: Receive Mobile Money payments on your store using E-nkap.
 * Version: 1.0.0
 * Tested up to: 5.8.1
 * WC requires at least: 3.2
 * WC tested up to: 4.8
 * Author: Camoo Sarl
 * Author URI: https://www.camoo.cm/
 * Developer: Camoo Sarl
 * Developer URI: http://www.camoo.cm/
 * Text Domain: edd-wp-enkap
 * Domain Path: /languages
 *
 * Copyright: © 2021 Camoo Sarl, CM.
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

namespace Camoo\Enkap\Easy_Digital_Downloads;

defined('ABSPATH') || exit;
require_once(__DIR__ . '/includes/Plugin.php');

(new Plugin(
    __FILE__,
    'EDD_Enkap_Gateway',
    'Gateway',
    sprintf('%s<br/><a href="%s" target="_blank">%s</a><br/><a href="%s" target="_blank">%s</a>',
        __('E-nkap payment gateway', 'edd_enkap'),
        'https://enkap.cm/#comptenkap',
        __('Do you have any questions or requests?', 'edd_enkap'),
        'https://support.enkap.cm',
        __('Do you like our plugin and can recommend to others?', 'edd_enkap')),
    '1.0.0'
)
)->register();
