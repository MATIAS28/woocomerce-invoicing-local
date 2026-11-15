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
		global $wpdb;

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		if ( mb_strlen( $term ) < 2 ) {
			wp_send_json_success( array() );
		}

		// "usb tipo c-c" => usb, tipo, c: guiones, puntos y espacios dobles no importan (máx. 6 palabras).
		$tokens = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $term ), -1, PREG_SPLIT_NO_EMPTY );
		$tokens = array_slice( array_values( array_unique( (array) $tokens ) ), 0, 6 );
		if ( ! $tokens ) {
			wp_send_json_success( array() );
		}

		$ids = array();

		// Código de barras / SKU exacto (lector): siempre primero.
		$exact = wc_get_product_id_by_sku( $term );
		if ( $exact ) {
			$ids[] = (int) $exact;
		}

		$first = $wpdb->esc_like( $tokens[0] ) . '%';

		// 1º intento: el producto tiene TODAS las palabras.
		// 2º (solo si no hubo nada): al menos una, ordenado por cuántas coincide.
		foreach ( array( 'AND', 'OR' ) as $mode ) {
			$sel_sql    = array();
			$sel_args   = array();
			$where_sql  = array();
			$where_args = array();

			foreach ( $tokens as $t ) {
				$like = '%' . $wpdb->esc_like( $t ) . '%';

				$sel_sql[]  = "(p.post_title LIKE %s OR COALESCE(m.meta_value, '') LIKE %s)";
				$sel_args[] = $like;
				$sel_args[] = $like;

				$where_sql[] = "(p.post_title LIKE %s
					OR COALESCE(m.meta_value, '') LIKE %s
					OR EXISTS (
						SELECT 1 FROM {$wpdb->term_relationships} tr
						INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
						INNER JOIN {$wpdb->terms} tm ON tm.term_id = tt.term_id
						WHERE tr.object_id = p.ID AND tm.name LIKE %s
					))";
				$where_args[] = $like;
				$where_args[] = $like;
				$where_args[] = $like;
			}

			$sql = "SELECT p.ID, ( " . implode( ' + ', $sel_sql ) . " ) AS matched
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
				WHERE p.post_type IN ( 'product', 'product_variation' )
				AND p.post_status = 'publish'
				AND ( " . implode( ' ' . $mode . ' ', $where_sql ) . " )
				ORDER BY ( COALESCE(m.meta_value, '') = %s ) DESC,
				         ( p.post_title LIKE %s OR COALESCE(m.meta_value, '') LIKE %s ) DESC,
				         matched DESC,
				         CHAR_LENGTH( p.post_title ) ASC,
				         p.post_title ASC
				LIMIT 40";

			$args = array_merge( $sel_args, $where_args, array( $term, $first, $first ) );
			$rows = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );

			if ( $rows ) {
				$ids = array_merge( $ids, array_map( 'intval', $rows ) );
				break;
			}
		}

		$ids = array_values( array_unique( $ids ) );

		$out = array();
		foreach ( $ids as $id ) {
			if ( count( $out ) >= 12 ) {
				break;
			}

			$product = wc_get_product( $id );

			// Solo se vende lo publicado; de las variaciones se vende la variación, no el producto padre.
			if ( ! $product || $product->is_type( 'variable' ) || 'publish' !== $product->get_status() ) {
				continue;
			}
			if ( $product->is_type( 'variation' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( ! $parent || 'publish' !== $parent->get_status() ) {
					continue;
				}
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
