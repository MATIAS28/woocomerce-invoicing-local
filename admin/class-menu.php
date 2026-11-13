<?php
/**
 * Admin menu, script/style loading and notices.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KOP_Admin_Menu {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'woocommerce_notice' ) );
	}

	public static function menu() {
		add_menu_page( 'Ventas del local', 'Ventas local', KOP_INV_CAP, 'kop-sale', array( __CLASS__, 'sale_page' ), 'dashicons-cart', 56 );
		add_submenu_page( 'kop-sale', 'Nueva venta', 'Nueva venta', KOP_INV_CAP, 'kop-sale', array( __CLASS__, 'sale_page' ) );
		add_submenu_page( 'kop-sale', 'Historial de ventas', 'Historial', KOP_INV_CAP, 'kop-history', array( 'KOP_Sales', 'history_page' ) );
		add_submenu_page( 'kop-sale', 'Configuración', 'Configuración', 'manage_options', 'kop-settings', array( 'KOP_Settings', 'page' ) );
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_kop-sale' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'kop-inv', KOP_INV_URL . 'assets/pos.css', array(), KOP_INV_VERSION );
		wp_enqueue_script( 'kop-inv', KOP_INV_URL . 'assets/pos.js', array( 'jquery' ), KOP_INV_VERSION, true );
		wp_localize_script( 'kop-inv', 'kopInv', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'kop_inv' ),
		) );
	}

	public static function woocommerce_notice() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-error"><p><strong>Kings of PC – Invoicing</strong> necesita WooCommerce activo.</p></div>';
			return;
		}

		// Aviso solo en las pantallas del plugin.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
 
        // Facturas C que ARCA no confirmó: aviso visible en las pantallas del POS.
        if ( in_array( $page, array( 'kop-sale', 'kop-history' ), true ) && current_user_can( KOP_INV_CAP ) ) {
            $problems = KOP_Sales::count_problems();
            if ( $problems ) {
                echo '<div class="notice notice-error"><p><strong>Hay ' . (int) $problems . ' factura(s) C sin confirmar por ARCA.</strong> ' .
                    '<a href="' . esc_url( admin_url( 'admin.php?page=kop-history&state=error' ) ) . '">Ver y verificar</a></p></div>';
            }
        }
 
        if ( 0 === strpos( $page, 'kop-' ) && current_user_can( 'manage_options' ) && '' === trim( (string) kop_inv_opt( 'crp_tax_id_key' ) ) ) {
			echo '<div class="notice notice-warning"><p><strong>Kings of PC – Invoicing:</strong> falta configurar el campo de DNI/CUIT para CRPlugins (Ventas local → Configuración). Sin eso, las facturas C pueden salir a Consumidor Final.</p></div>';
		}
	}

	public static function sale_page() {
		$user     = wp_get_current_user();
		$payments = kop_inv_payment_methods();
		include KOP_INV_DIR . 'admin/views/sale.php';
	}
}
