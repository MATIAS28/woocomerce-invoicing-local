<?php
/**
 * Printable receipt for a sale.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KOP_Receipt {

	public static function init() {
		add_action( 'admin_post_kop_receipt', array( __CLASS__, 'show' ) );
	}

	public static function show() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'kop_receipt_' . $id );
		if ( ! current_user_can( KOP_INV_CAP ) ) {
			wp_die( 'Sin permisos' );
		}

		$sale = KOP_Sales::get( $id );
		if ( ! $sale ) {
			wp_die( 'Venta no encontrada' );
		}
		$items  = KOP_Sales::items( $id );
		$payments = KOP_Sales::payments( $id );
		$seller = get_userdata( $sale->seller_id );

		include KOP_INV_DIR . 'admin/views/receipt.php';
		exit;
	}
}
