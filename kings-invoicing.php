<?php
/**
 * Plugin Name: Kings of PC – Invoicing
 * Description: Simple in-store point of sale: builds the sale, prints a receipt and decreases WooCommerce stock.
 * Version: 0.5.6
 * Author: Kings of PC
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: kings-invoicing
 * GitHub Plugin URI: https://github.com/MATIAS28/woocommerce-invoicing-local
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KOP_INV_VERSION', '0.5.7' );
define( 'KOP_INV_DIR', plugin_dir_path( __FILE__ ) );
define( 'KOP_INV_URL', plugin_dir_url( __FILE__ ) );
define( 'KOP_INV_CAP', 'manage_woocommerce' );

// Core logic.
require_once KOP_INV_DIR . 'includes/helpers.php';
require_once KOP_INV_DIR . 'includes/class-install.php';
require_once KOP_INV_DIR . 'includes/class-settings.php';
require_once KOP_INV_DIR . 'includes/class-sales.php';
require_once KOP_INV_DIR . 'includes/class-orders.php';
require_once KOP_INV_DIR . 'includes/class-ajax.php';
require_once KOP_INV_DIR . 'includes/class-receipt.php';

// Admin.
require_once KOP_INV_DIR . 'admin/class-menu.php';

// The activation hook must be registered here (it needs __FILE__ of the main plugin file).
register_activation_hook( __FILE__, array( 'KOP_Install', 'activate' ) );

// Tables are also updated when the plugin files are replaced without deactivating it (see KOP_Install).
add_action( 'plugins_loaded', array( 'KOP_Install', 'maybe_upgrade' ) );

// Boot each module. To add a new feature: create its class and add its init() call here.
KOP_Settings::init();
KOP_Sales::init();
KOP_Orders::init();
KOP_Ajax::init();
KOP_Receipt::init();
KOP_Admin_Menu::init();
