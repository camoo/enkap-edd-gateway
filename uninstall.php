<?php
/**
 * Uninstalling SmobilPay for e-commerce for Easy Digital Downloads, deletes tables, and options.
 *
 * @package SmobilPay for e-commerce for Easy Digital Downloads
 * @version 1.0.2
 *
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

delete_option('wp_edd_enkap_db_version');

$wpdb->query("DELETE FROM $wpdb->options WHERE `option_name` LIKE 'edd_enkap%';");

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}edd_enkap_payments");
