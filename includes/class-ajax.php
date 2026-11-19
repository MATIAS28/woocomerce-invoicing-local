<?php
/**
 * AJAX endpoints used by the sale screen: product catalog, product search and saving the sale.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KOP_Ajax {

	public static function init() {
		add_action( 'wp_ajax_kop_catalog', array( __CLASS__, 'catalog' ) );
		add_action( 'wp_ajax_kop_search', array( __CLASS__, 'search' ) );
		add_action( 'wp_ajax_kop_save', array( __CLASS__, 'save' ) );
	}

	private static function verify() {
		check_ajax_referer( 'kop_inv', 'nonce' );
		if ( ! current_user_can( KOP_INV_CAP ) || ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error( 'Sin permisos', 403 );
		}
	}

	/** Fila de producto que entiende el POS (la misma forma para el catálogo y para la búsqueda). */
	private static function row( $id, $name, $sku, $web, $manage, $stock, $img, $cats ) {
		return array(
			'id'     => (int) $id,
			'name'   => wp_strip_all_tags( (string) $name ),
			'sku'    => (string) $sku,
			'web'    => round( (float) $web, 2 ),
			'price'  => kop_inv_local_price( $web ), // precio del local (web - descuento, en pesos enteros)
			'manage' => (bool) $manage,
			'stock'  => $manage ? (int) $stock : null,
			'img'    => $img ? (string) $img : '',
			'cats'   => (string) $cats,
		);
	}

	/**
	 * Catálogo completo de productos vendibles, para buscar en el navegador sin esperar al servidor.
	 * Se arma con pocas consultas directas (sin cargar cada producto con wc_get_product), así es rápido
	 * aunque haya miles de productos. Los precios salen de la misma fuente que usa el guardado (_price).
	 */
	public static function catalog() {
		self::verify();
		global $wpdb;

		// Productos publicados y variaciones cuyo producto padre está publicado.
		$posts = $wpdb->get_results(
			"SELECT p.ID, p.post_parent, p.post_type, p.post_title
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->posts} pp ON pp.ID = p.post_parent
			 WHERE p.post_status = 'publish'
			   AND ( p.post_type = 'product'
			      OR ( p.post_type = 'product_variation' AND pp.post_type = 'product' AND pp.post_status = 'publish' ) )"
		);
		if ( ! $posts ) {
			wp_send_json_success( array( 'products' => array(), 'discount' => kop_inv_local_discount() ) );
		}

		// Metadatos necesarios, en una sola consulta.
		$meta = array();
		$rows = $wpdb->get_results(
			"SELECT m.post_id, m.meta_key, m.meta_value
			 FROM {$wpdb->postmeta} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE p.post_type IN ( 'product', 'product_variation' ) AND p.post_status = 'publish'
			   AND m.meta_key IN ( '_sku', '_price', '_manage_stock', '_stock', '_thumbnail_id' )"
		);
		foreach ( $rows as $r ) {
			$meta[ (int) $r->post_id ][ $r->meta_key ] = $r->meta_value;
		}
		unset( $rows );

		// Tipos que no se venden directamente: el variable (se vende la variación) y el agrupado.
		$skip = array_flip( array_map( 'intval', $wpdb->get_col(
			"SELECT tr.object_id
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
			 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			 WHERE t.slug IN ( 'variable', 'grouped' )"
		) ) );

		// Categorías (se usan solo para buscar). Las variaciones usan las de su producto padre.
		$cats = array();
		$rows = $wpdb->get_results(
			"SELECT tr.object_id, t.name
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
			 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id"
		);
		foreach ( $rows as $r ) {
			$cats[ (int) $r->object_id ][] = $r->name;
		}
		unset( $rows );

		$get = function ( $id, $key ) use ( $meta ) {
			return isset( $meta[ $id ][ $key ] ) ? $meta[ $id ][ $key ] : '';
		};

		// Imágenes: se precargan todas juntas para no hacer una consulta por producto.
		$thumbs = array();
		foreach ( $posts as $p ) {
			$t = (int) $get( (int) $p->ID, '_thumbnail_id' );
			if ( ! $t && $p->post_parent ) {
				$t = (int) $get( (int) $p->post_parent, '_thumbnail_id' );
			}
			if ( $t ) {
				$thumbs[ $t ] = true;
			}
		}
		$thumbs = array_keys( $thumbs );
		if ( $thumbs && function_exists( '_prime_post_caches' ) ) {
			foreach ( array_chunk( $thumbs, 1000 ) as $chunk ) {
				_prime_post_caches( $chunk, false, true );
			}
		}
		$img_cache = array();
		$img       = function ( $thumb ) use ( &$img_cache ) {
			if ( ! $thumb ) {
				return '';
			}
			if ( ! isset( $img_cache[ $thumb ] ) ) {
				$img_cache[ $thumb ] = (string) wp_get_attachment_image_url( $thumb, 'woocommerce_thumbnail' );
			}
			return $img_cache[ $thumb ];
		};

		$out = array();
		foreach ( $posts as $p ) {
			$id     = (int) $p->ID;
			$parent = (int) $p->post_parent;
			if ( isset( $skip[ $id ] ) ) {
				continue;
			}
			$is_var = ( 'product_variation' === $p->post_type );

			// Stock: una variación sin stock propio usa el del padre (igual que WooCommerce).
			$manage = ( 'yes' === $get( $id, '_manage_stock' ) );
			$stock  = $get( $id, '_stock' );
			if ( $is_var && ! $manage && 'yes' === $get( $parent, '_manage_stock' ) ) {
				$manage = true;
				$stock  = $get( $parent, '_stock' );
			}

			$thumb = (int) $get( $id, '_thumbnail_id' );
			if ( ! $thumb && $is_var ) {
				$thumb = (int) $get( $parent, '_thumbnail_id' );
			}

			$cat_names = isset( $cats[ $is_var ? $parent : $id ] ) ? $cats[ $is_var ? $parent : $id ] : array();

			$out[] = self::row(
				$id,
				$p->post_title,
				$get( $id, '_sku' ),
				(float) $get( $id, '_price' ),
				$manage,
				(int) round( (float) $stock ),
				$img( $thumb ),
				implode( ' ', $cat_names )
			);
		}

		wp_send_json_success( array(
			'products' => $out,
			'discount' => kop_inv_local_discount(),
		) );
	}

	/**
	 * Búsqueda en el servidor. El POS busca en su catálogo local; esto queda como respaldo
	 * (catálogo todavía cargando, o producto creado después de abrir la pantalla).
	 */
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

		// El producto tiene TODAS las palabras (en el nombre o en el SKU).
		$where = array();
		$args  = array();
		foreach ( $tokens as $t ) {
			$like    = '%' . $wpdb->esc_like( $t ) . '%';
			$where[] = "( p.post_title LIKE %s OR COALESCE( m.meta_value, '' ) LIKE %s )";
			$args[]  = $like;
			$args[]  = $like;
		}
		$args[] = $wpdb->esc_like( $tokens[0] ) . '%';

		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
			 WHERE p.post_type IN ( 'product', 'product_variation' )
			   AND p.post_status = 'publish'
			   AND " . implode( ' AND ', $where ) . "
			 ORDER BY ( p.post_title LIKE %s ) DESC, CHAR_LENGTH( p.post_title ) ASC
			 LIMIT 30",
			$args
		) );
		$ids = array_values( array_unique( array_merge( $ids, array_map( 'intval', $rows ) ) ) );

		if ( $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, true );
		}

		$out = array();
		foreach ( $ids as $id ) {
			if ( count( $out ) >= 12 ) {
				break;
			}
			$product = wc_get_product( $id );

			// Solo se vende lo publicado; de las variaciones se vende la variación, no el producto padre.
			if ( ! $product || $product->is_type( array( 'variable', 'grouped' ) ) || 'publish' !== $product->get_status() ) {
				continue;
			}
			$cat_id = $product->get_id();
			if ( $product->is_type( 'variation' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( ! $parent || 'publish' !== $parent->get_status() ) {
					continue;
				}
				$cat_id = $parent->get_id();
			}

			$out[] = self::row(
				$product->get_id(),
				$product->get_name(),
				$product->get_sku(),
				kop_inv_web_price( $product ),
				$product->managing_stock(),
				$product->get_stock_quantity(),
				wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ),
				implode( ' ', wp_get_post_terms( $cat_id, 'product_cat', array( 'fields' => 'names' ) ) )
			);
		}

		wp_send_json_success( $out );
	}

	public static function save() {
		self::verify();

		$data = json_decode( wp_unslash( isset( $_POST['data'] ) ? $_POST['data'] : '' ), true );

		try {
			$result = KOP_Sales::create( $data );
		} catch ( Throwable $e ) {
			error_log( 'KOP: ' . $e->getMessage() );
			wp_send_json_error( 'Error inesperado. Revisá el Historial antes de reintentar.' );
		}

		if ( is_wp_error( $result ) ) {
			// Cambió el precio de algún producto: se devuelven los precios nuevos para actualizar el carrito.
			if ( 'price_changed' === $result->get_error_code() ) {
				$extra = $result->get_error_data();
				wp_send_json_error( array(
					'message' => $result->get_error_message(),
					'changes' => isset( $extra['changes'] ) ? $extra['changes'] : array(),
				) );
			}
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( $result );
	}
}
