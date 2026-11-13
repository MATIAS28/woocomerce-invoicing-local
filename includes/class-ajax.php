<?php
/**
 * AJAX endpoints used by the sale screen: product search and saving the sale.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KOP_Ajax {

	public static function init() {
		add_action( 'wp_ajax_kop_search', array( __CLASS__, 'search' ) );
		add_action( 'wp_ajax_kop_save', array( __CLASS__, 'save' ) );
	}

	private static function verify() {
		check_ajax_referer( 'kop_inv', 'nonce' );
		if ( ! current_user_can( KOP_INV_CAP ) || ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error( 'Sin permisos', 403 );
		}
	}

	public static function search() {
		self::verify();

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		if ( mb_strlen( $term ) < 2 ) {
			wp_send_json_success( array() );
		}

		$ids = array();
		$sku = wc_get_product_id_by_sku( $term ); // Exact SKU match (barcode scanner).
		if ( $sku ) {
			$ids[] = $sku;
		}

		$found = wc_get_products( array(
			'status' => 'publish',
			'limit'  => 12,
			's'      => $term,
			'type'   => array_merge( array_keys( wc_get_product_types() ), array( 'variation' ) ),
			'return' => 'ids',
		) );
		
		$ids = array_values( array_unique( array_merge( $ids, $found ) ) );

		$out = array();
		foreach ( array_slice( $ids, 0, 12 ) as $id ) {
			$product = wc_get_product( $id );

			if ( ! $product || $product->is_type( 'variable' ) ) {
				continue; // The variation is sold, not the parent product.
			}

			
			$out[] = array(
				'img'    => wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ),
				'id'     => $product->get_id(),
				'name'   => wp_strip_all_tags( $product->get_name() ),
				'sku'    => $product->get_sku(),
				'price'  => (float) $product->get_price(),
				'manage' => (bool) $product->managing_stock(),
				'stock'  => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
			);
		}
		
		wp_send_json_success( $out );
	}

	public static function save() {
		self::verify();

		$data   = json_decode( wp_unslash( isset( $_POST['data'] ) ? $_POST['data'] : '' ), true );
		
		try {
			$result = KOP_Sales::create( $data );
		} catch ( Throwable $e ) {
			error_log( 'KOP: ' . $e->getMessage() );
			wp_send_json_error( 'Error inesperado. Revisá el Historial antes de reintentar.' );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( $result );
	}
}
