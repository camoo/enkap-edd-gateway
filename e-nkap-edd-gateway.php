<?php

/**
 * Plugin Name: E-nkap for Easy Digital Downloads
 * Plugin URI: https://enkap.cm/
 * Description: Receive Mobile Money payments on your store using E-nkap.
 * Version: 1.0.0
 * Tested up to: 5.8.1
 * EDD requires at least: 2.0
 * EDD tested up to: 2.11.2
 * Author: Camoo Sarl
 * Author URI: https://www.camoo.cm/
 * Developer: Camoo Sarl
 * Developer URI: http://www.camoo.cm/
 * Text Domain: edd-wp-enkap
 * Domain Path: /languages
 * Requires at least: 4.7
 * Requires PHP: 7.3
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */
namespace Camoo\Enkap\Easy_Digital_Downloads;

defined('ABSPATH') || exit;
require_once(__DIR__ . '/includes/Plugin.php');

(new Plugin(
    __FILE__,
    'EDD_Enkap_Gateway',
    'Gateway',
    sprintf('%s<br/><a href="%s" target="_blank">%s</a><br/><a href="%s" target="_blank">%s</a>',
        __('E-nkap payment gateway', Plugin::DOMAIN_TEXT),
        'https://enkap.cm/#comptenkap',
        __('Do you have any questions or requests?', Plugin::DOMAIN_TEXT),
        'https://support.enkap.cm',
        __('Do you like our plugin and can recommend to others?', Plugin::DOMAIN_TEXT)),
    '1.0.0'
)
)->register();
