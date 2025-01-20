<?php
/**
 * Plugin Name: Custom Made Plugin
 * Plugin URI: https://github.com/ankit1626/code_sample_2
 * Description: This is a custom made plugin which extends the functionality of WooCommerce by integrating multiple shipping partners allowing admin team to generate labels and track the status of the same.
 * Version: 3.0.0
 * Author: Ankit Parekh
 * Author URI: https://github.com/ankit1626/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: code-sample
 * Domain Path: /languages/
 * Requires PHP: 8.0
 *
 * @package custom-plugin
 */

// Exit if accessed directly.
if ( ! defined( 'WPINC' ) ) {
	exit;
}
define( 'SS_PATH', plugin_dir_path( __FILE__ ) );
define( 'SS_URL', plugin_dir_url( __FILE__ ) );

$ss_wdm_version = '3.0';

require_once SS_PATH . 'helper-functions.php';
require_once SS_PATH . 'shipping-partners/trait-shippo.php';
require_once SS_PATH . 'shipping-partners/trait-easypost.php';
require_once SS_PATH . 'shipping-partners/trait-usps.php';
require_once SS_PATH . 'classes/class-ss-taxonomies.php';
require_once SS_PATH . 'classes/class-ss-wc.php';
require_once SS_PATH . 'classes/class-ss-settings.php';
require_once SS_PATH . 'classes/class-ss-main.php';
require_once SS_PATH . 'classes/class-ss-orders.php';
require_once SS_PATH . 'classes/class-ss-parcels.php';
require_once SS_PATH . 'classes/class-ss-schedule-refunds.php';
require_once SS_PATH . 'classes/class-ss-label-generator.php';
require_once SS_PATH . 'classes/class-ss-additional-fees.php';

SS_WC::get_instance();
SS_Taxonomies::get_instance();
SS_Settings::get_instance();
SS_Main::get_instance();
SS_Orders::get_instance();
SS_Additional_Fees::get_instance();
SS_Schedule_Refunds::get_instance();
SS_Label_Generator::get_instance();
