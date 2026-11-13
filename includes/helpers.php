<?php
/**
 * Helper functions shared across the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default plugin options.
 */
function kop_inv_defaults() {
	return array(
		'business'        => 'Kings of PC',
		'tax_id'          => '',
		'address'         => '',
		'phone'           => '',
		'pos_number'      => 1,
		'payment_methods' => "Efectivo|0\nTransferencia|0",
		'footer'          => 'Documento no válido como factura.',
		'logo_url'        => '',
		'invoice_status'  => 'completed', // order status that makes the invoicing plugin issue the invoice
		'crp_tax_id_key'  => '',          // order meta key where the invoicing plugin reads the customer tax ID
	);
}

/**
 * Get a single option value (falls back to the default).
 */
function kop_inv_opt( $key ) {
	$opts = wp_parse_args( get_option( 'kop_inv_opts', array() ), kop_inv_defaults() );
	return isset( $opts[ $key ] ) ? $opts[ $key ] : '';
}

/**
 * Format an amount as currency, e.g. "$ 1.234,50".
 */
function kop_inv_money( $n ) {
	return '$ ' . number_format( (float) $n, 2, ',', '.' );
}

/**
 * Configured payment methods: [ ['name' => ..., 'pct' => ...], ... ]
 * Positive pct = surcharge, negative pct = discount.
 */
function kop_inv_payment_methods() {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) kop_inv_opt( 'payment_methods' ) ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$parts = explode( '|', $line );
		$out[] = array(
			'name' => trim( $parts[0] ),
			'pct'  => isset( $parts[1] ) ? (float) str_replace( ',', '.', $parts[1] ) : 0.0,
		);
	}
	return $out ? $out : array( array( 'name' => 'Efectivo', 'pct' => 0.0 ) );
}

/**
 * Signed URL to view/print the receipt of a sale.
 * Built with add_query_arg() instead of wp_nonce_url(), because wp_nonce_url()
 * HTML-escapes the URL ("&amp;"), which breaks it when used from JavaScript.
 * Escape it with esc_url() when printing it in HTML.
 */
function kop_inv_receipt_url( $id ) {
	return add_query_arg(
		array(
			'action'   => 'kop_receipt',
			'id'       => (int) $id,
			'_wpnonce' => wp_create_nonce( 'kop_receipt_' . (int) $id ),
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * Signed URL to cancel a sale (raw, see kop_inv_receipt_url()).
 */
function kop_inv_cancel_url( $id ) {
	return add_query_arg(
		array(
			'action'   => 'kop_cancel',
			'id'       => (int) $id,
			'_wpnonce' => wp_create_nonce( 'kop_cancel_' . (int) $id ),
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * Signed URL to check (read-only) whether the invoice of a "C" sale was issued.
 */
function kop_inv_check_url( $id ) {
	return add_query_arg(
		array(
			'action'   => 'kop_check_invoice',
			'id'       => (int) $id,
			'_wpnonce' => wp_create_nonce( 'kop_check_' . (int) $id ),
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * Admin URL to edit a WooCommerce order (works with and without HPOS).
 */
function kop_inv_order_edit_url( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return '';
	}
	if ( method_exists( $order, 'get_edit_order_url' ) ) {
		return $order->get_edit_order_url();
	}
	return (string) get_edit_post_link( $order_id, 'raw' );
}

/**
 * WhatsApp link (wa.me) for a customer phone. Returns '' if the phone is not usable.
 * Phones are typed the Argentine way: area code + number, without the 0 and without the 15
 * (e.g. 1125606406). Numbers that already start with 54 are kept.
 * wa.me only opens the chat with a text: the invoice PDF is attached by hand.
 */
function kop_inv_whatsapp_url( $phone, $text = '' ) {
	$digits = preg_replace( '/\D+/', '', (string) $phone );
	$digits = preg_replace( '/^00/', '', $digits );
	if ( 0 === strpos( $digits, '54' ) ) {
		if ( 0 !== strpos( substr( $digits, 2 ), '9' ) ) {
			$digits = '549' . substr( $digits, 2 ); // mobile numbers need the 9 after 54.
		}
	} else {
		$digits = '549' . ltrim( $digits, '0' );
	}
	if ( strlen( $digits ) < 12 || strlen( $digits ) > 14 ) {
		return '';
	}
	return 'https://wa.me/' . $digits . ( '' !== $text ? '?text=' . rawurlencode( $text ) : '' );
}

/** Default WhatsApp message for a sale. */
function kop_inv_whatsapp_text( $sale ) {
    $business = kop_inv_opt( 'business', 'Kings of PC' );
    return sprintf(
        "¡Hola! Te compartimos el comprobante de tu compra en %s.\nOperación: %s\nTotal: %s\n¡Muchas gracias!",
        $business,
        $sale->number,
        kop_inv_money( $sale->total )
    );
}

/**
 * Normalizes and validates a tax ID: DNI (7-8 digits) or CUIT/CUIL (11 digits with check digit).
 * Returns the digits only, or false.
 */
function kop_inv_valid_tax_id( $raw ) {
	$digits = preg_replace( '/\D+/', '', (string) $raw );
	$len    = strlen( $digits );
	if ( 7 === $len || 8 === $len ) {
		return $digits;
	}
	if ( 11 !== $len ) {
		return false;
	}
	$weights = array( 5, 4, 3, 2, 7, 6, 5, 4, 3, 2 );
	$sum     = 0;
	foreach ( $weights as $i => $w ) {
		$sum += (int) $digits[ $i ] * $w;
	}
	$check = 11 - ( $sum % 11 );
	if ( 11 === $check ) {
		$check = 0;
	} elseif ( 10 === $check ) {
		$check = 9;
	}
	return ( (int) $digits[10] === $check ) ? $digits : false;
}

/**
 * Customer data required for an invoice (type C): a real name and a valid DNI/CUIT.
 * Returns the normalized tax ID or a WP_Error.
 */
function kop_inv_check_invoice_customer( $name, $tax_id ) {
	$name = trim( (string) $name );
	if ( '' === $name || 'consumidor final' === strtolower( $name ) ) {
		return new WP_Error( 'customer', 'Para una factura C cargá el nombre del cliente.' );
	}
	$digits = kop_inv_valid_tax_id( $tax_id );
	if ( false === $digits ) {
		return new WP_Error( 'tax_id', 'Para una factura C cargá un DNI (7 u 8 dígitos) o un CUIT/CUIL válido (11 dígitos).' );
	}
	return $digits;
}


function kop_inv_crplugins_active() {
	return false !== has_action( 'wc_afip_process_order' );
}

/**
 * Parses an amount typed by the user. Accepts "1500", "1500.50", "1.500,50" and "$ 1.500,50".
 */
function kop_inv_parse_amount( $raw ) {
    $raw = trim( str_replace( array( '$', ' ' ), '', (string) $raw ) );
    if ( '' === $raw ) {
        return 0.0;
    }
    if ( false !== strpos( $raw, ',' ) ) {
        $raw = str_replace( '.', '', $raw );   // "." = thousands separator
        $raw = str_replace( ',', '.', $raw );  // "," = decimals
    }
    return round( (float) preg_replace( '/[^0-9.\-]/', '', $raw ), 2 );
}
 
/**
 * Signed URL that downloads the history as CSV, keeping the filters currently applied.
 */
function kop_inv_export_url( array $filters ) {
    $args = array();
    foreach ( $filters as $key => $value ) {
        if ( '' !== $value && 0 !== $value && null !== $value ) {
            $args[ $key ] = $value;
        }
    }
    $args['action']   = 'kop_export';
    $args['_wpnonce'] = wp_create_nonce( 'kop_export' );
    return add_query_arg( $args, admin_url( 'admin-post.php' ) );
}
