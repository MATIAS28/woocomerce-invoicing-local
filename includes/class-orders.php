<?php
/**
 * WooCommerce orders for invoiced sales.
 *
 * A "C" sale creates a WooCommerce order with the same products, prices and customer.
 * The invoicing plugin (CRPlugins) issues the invoice from that order, depending on the
 * order status configured in the settings ("invoice_status").
 */
 
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
 
class KOP_Orders {
 
    const META_SALE_ID  = '_kop_sale_id';
 
    /**
     * Invoice states (kop_sales.invoice_status):
     *   none        X sale (not invoiced)
     *   emitting    order created / being sent to ARCA, no confirmation yet
     *   invoiced    CRPlugins confirmed the invoice (hook wc_afip_after_order_process)
     *   error       ARCA did not confirm. Nothing is re-sent automatically: use "Verificar factura"
     *               (read-only check) and look the invoice up in ARCA before invoicing again.
     *   unverified  C sale from before this version: it has an order but nobody confirmed the invoice
     */
 
    public static function init() {
        // "Verificar factura" button in the history (read-only: it never issues an invoice).
        add_action( 'admin_post_kop_check_invoice', array( __CLASS__, 'handle_check' ) );
 
        // CRPlugins: the ARCA response (CAE) and the confirmation that the invoice was really created.
        add_filter( 'wc_afip_response_after_order_process', array( __CLASS__, 'capture_response' ), 99, 2 );
        add_action( 'wc_afip_after_order_process', array( __CLASS__, 'on_invoiced' ), 99, 1 );
 
 
        // The POS already decreased the stock: WooCommerce must not do it again for these orders.
        add_filter( 'woocommerce_can_reduce_order_stock', array( __CLASS__, 'can_reduce_stock' ), 10, 2 );
 
        // In-store sales must not send emails to the customer or "new order" emails.
        foreach ( array( 'new_order', 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order' ) as $email_id ) {
            add_filter( "woocommerce_email_enabled_{$email_id}", array( __CLASS__, 'email_enabled' ), 10, 2 );
        }
    }
 
    /* ------------------------------------------------------------------
     * Hooks
     * ---------------------------------------------------------------- */
    private static function is_pos_order( $order ) {
        return $order instanceof WC_Order && (bool) $order->get_meta( self::META_SALE_ID );
    }
 
    public static function can_reduce_stock( $can_reduce, $order ) {
        return self::is_pos_order( $order ) ? false : $can_reduce;
    }
 
    public static function email_enabled( $enabled, $order ) {
        return self::is_pos_order( $order ) ? false : $enabled;
    }
 
    /* ------------------------------------------------------------------
     * Spread the sale total over the product lines.
     *
     * The invoice must show the amount really charged. Instead of sending the manual discount and
     * the payment-method adjustment as separate lines, they are spread proportionally over the
     * products, so the order total always equals the sale total (the last line absorbs rounding).
     *
     * $totals: list of line totals (after the line discount). Returns the adjusted list.
     * ---------------------------------------------------------------- */
    public static function allocate( array $totals, $target ) {
        $target = round( (float) $target, 2 );
        $sum    = round( array_sum( $totals ), 2 );
        if ( ! $totals || $sum <= 0 || abs( $sum - $target ) < 0.005 ) {
            return $totals;
        }
        $factor = $target / $sum;
        $out    = array();
        $used   = 0.0;
        $last   = count( $totals ) - 1;
        foreach ( array_values( $totals ) as $i => $line_total ) {
            $value  = ( $i === $last ) ? round( $target - $used, 2 ) : round( $line_total * $factor, 2 );
            $used  += $value;
            $out[]  = $value;
        }
        return $out;
    }
 
    /* ------------------------------------------------------------------
     * Create the WooCommerce order of a "C" sale.
     * Returns the order id, or a WP_Error. The sale stays saved if this fails.
     * ---------------------------------------------------------------- */
    public static function create_from_sale( $sale_id ) {
        global $wpdb;
 
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_Error( 'wc', 'WooCommerce no está activo.' );
        }
        $sale = KOP_Sales::get( $sale_id );
        if ( ! $sale ) {
            return new WP_Error( 'no_sale', 'Venta no encontrada.' );
        }
        if ( 'C' !== $sale->type ) {
            return new WP_Error( 'type', 'Solo las ventas tipo C generan un pedido para facturar.' );
        }
        if ( 'issued' !== $sale->status ) {
            return new WP_Error( 'status', 'La venta está anulada.' );
        }
        if ( ! empty( $sale->wc_order_id ) && wc_get_order( $sale->wc_order_id ) ) {
            return new WP_Error( 'exists', 'Esta venta ya tiene un pedido.' );
        }
 
        $items = KOP_Sales::items( $sale_id );
        if ( ! $items ) {
            return new WP_Error( 'no_items', 'La venta no tiene productos.' );
        }
 
        $line_totals = array();
        foreach ( $items as $item ) {
            $line_totals[] = (float) $item->total;
        }
        $line_totals = self::allocate( $line_totals, (float) $sale->total );
 
        // Creamos la orden simulando origen web para compatibilidad total con pasarelas y plugins
        $order = wc_create_order( array( 'status' => 'pending', 'created_via' => 'checkout' ) );
        if ( is_wp_error( $order ) ) {
            return $order;
        }
 
        foreach ( $items as $i => $item ) {
            $product = wc_get_product( $item->product_id );
            if ( ! $product ) {
                $order->delete( true );
                return new WP_Error( 'product', sprintf( 'El producto "%s" ya no existe.', $item->description ) );
            }
            $order->add_product( $product, (int) $item->quantity, array(
                'subtotal'     => $line_totals[ $i ],
                'total'        => $line_totals[ $i ],
                'subtotal_tax' => 0,
                'total_tax'    => 0,
                'taxes'        => array( 'subtotal' => array(), 'total' => array() ),
            ) );
        }
 
        // Datos del cliente y limpieza de documento
        $parts  = preg_split( '/\s+/', trim( $sale->customer_name ), 2 );
        $digits = preg_replace( '/\D+/', '', (string) $sale->customer_tax_id );
        $doc_type = 11 === strlen( $digits ) ? 'CUIT' : 'DNI';
 
        $order->set_billing_first_name( isset( $parts[0] ) ? $parts[0] : '' );
        $order->set_billing_last_name( isset( $parts[1] ) ? $parts[1] : '' );
        $order->set_billing_country( 'AR' );
        $order->set_billing_phone( (string) $sale->customer_phone );
        $order->set_payment_method_title( $sale->payment_method );
        $order->set_customer_note( (string) $sale->notes );
 
        // Metadatos internos de control del POS
        $order->update_meta_data( self::META_SALE_ID, (int) $sale_id );
        $order->update_meta_data( '_kop_sale_number', $sale->number );
        $order->update_meta_data( '_kop_customer_tax_id', $digits );
 
        // Metadatos estándar de WooCommerce / CRPlugins para que reconozca DNI/CUIT y Dirección automáticamente
        $order->update_meta_data( '_billing_dni', $digits );
        $order->update_meta_data( '_billing_cuit', $digits );
        $order->update_meta_data( '_billing_persontype', 11 === strlen( $digits ) ? 'Legal' : 'Physical' );
 
        // Claves personalizadas configuradas en los Ajustes del plugin para CRPlugins (ARCA)
        $crp = array(
            'crp_tax_id_key'       => $digits,
            'crp_doc_type_key'     => $doc_type,
            'crp_condition_key'    => 'Consumidor Final',
            'crp_invoice_type_key' => 'C',
        );
        
        foreach ( $crp as $option => $value ) {
            $key = trim( (string) kop_inv_opt( $option ) );
            if ( '' !== $key && '' !== (string) $value ) {
                // Guardamos tanto en la clave personalizada que el usuario configuró como en el meta directo
                $order->update_meta_data( $key, $value );
                $order->update_meta_data( '_' . $key, $value );
            }
        }
 
        $order->calculate_totals( false );
 
        if ( abs( (float) $order->get_total() - (float) $sale->total ) > 0.01 ) {
            $order->delete( true );
            return new WP_Error( 'total', 'El total del pedido no coincide con el de la venta.' );
        }
 
        $order->add_order_note( sprintf( 'Pedido creado desde el POS (ARCA Sync). Venta %s.', $sale->number ) );
        $order->save();
 
        $wpdb->update( "{$wpdb->prefix}kop_sales", array( 'wc_order_id' => $order->get_id() ), array( 'id' => (int) $sale_id ) );
 
        // Changing the status makes CRPlugins issue the invoice (synchronously). Then we check if ARCA confirmed it.
        $status = kop_inv_opt( 'invoice_status' );
        try {
            $order->update_status( in_array( $status, array( 'processing', 'completed' ), true ) ? $status : 'completed', 'Facturación automática POS.' );
        } catch ( Throwable $e ) {
            error_log( 'KOP invoice: ' . $e->getMessage() );
            self::set_error( $sale_id, $e->getMessage() );
            return $order->get_id();
        }
        self::finish_attempt( $sale_id, $order );
 
        return $order->get_id();
    }
 
    /* ------------------------------------------------------------------
     * Invoice state: confirmation, errors and retries
     * ---------------------------------------------------------------- */
    private static function sale_id_of( $order ) {
        return $order instanceof WC_Order ? (int) $order->get_meta( self::META_SALE_ID ) : 0;
    }
 
    /**
     * Looks for the CAE, its due date and the invoice number inside any structure (ARCA response
     * or order meta). The exact names CRPlugins uses are not documented, so keys are compared
     * loosely and every value is validated (CAE = 14 digits).
     * Returns array( 'cae' => , 'cae_due' => , 'number' => ) with whatever was found.
     */
    public static function extract_fields( $data, array $found = array() ) {
        $data = json_decode( wp_json_encode( $data ), true );
        if ( ! is_array( $data ) ) {
            return $found;
        }
        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                $found = self::extract_fields( $value, $found );
                continue;
            }
            if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
                continue;
            }
            $k = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $key ) ); // "CAE_Vto" => "caevto"
            $v = trim( (string) $value );
 
            if ( false !== strpos( $k, 'cae' ) && preg_match( '/(vto|venc|exp|due|fch)/', $k ) ) {
                $time = strtotime( $v ); // accepts 20261231 and 2026-12-31
                if ( $time && empty( $found['cae_due'] ) ) {
                    $found['cae_due'] = gmdate( 'Y-m-d', $time );
                }
            } elseif ( preg_match( '/cae$/', $k ) ) {
                if ( empty( $found['cae'] ) && preg_match( '/^\d{14}$/', $v ) ) {
                    $found['cae'] = $v;
                }
            } elseif ( preg_match( '/(invoicenumber|invoiceno|numerocomprobante|nrocomprobante|comprobantenumero|nrocbte|cbtedesde|cbtenro|receiptnumber)$/', $k ) || in_array( $k, array( 'numero', 'number', 'nro' ), true ) ) {
                if ( empty( $found['number'] ) && preg_match( '/^[0-9\-]{1,20}$/', $v ) ) {
                    $found['number'] = $v;
                }
            }
        }
        return $found;
    }
 
    /** Filter wc_afip_response_after_order_process: keeps the ARCA response of POS orders. */
    public static function capture_response( $response, $order ) {
        global $wpdb;
        $sale_id = self::sale_id_of( $order );
        if ( $sale_id ) {
            $fields = self::extract_fields( $response );
            $data   = array( 'invoice_response' => mb_substr( (string) wp_json_encode( $response ), 0, 60000 ) );
            if ( ! empty( $fields['cae'] ) )     { $data['invoice_cae']     = $fields['cae']; }
            if ( ! empty( $fields['cae_due'] ) ) { $data['invoice_cae_due'] = $fields['cae_due']; }
            if ( ! empty( $fields['number'] ) )  { $data['invoice_number']  = $fields['number']; }
            $wpdb->update( "{$wpdb->prefix}kop_sales", $data, array( 'id' => $sale_id ) );
        }
        return $response; // unchanged.
    }
 
    /** Action wc_afip_after_order_process: CRPlugins created the invoice => the sale is invoiced. */
    public static function on_invoiced( $order ) {
        global $wpdb;
        $sale_id = self::sale_id_of( $order );
        $sale    = $sale_id ? KOP_Sales::get( $sale_id ) : null;
        if ( ! $sale ) {
            return;
        }
 
        $data = array(
            'invoice_status' => 'invoiced',
            'invoiced_at'    => current_time( 'mysql' ),
            'invoice_error'  => '',
        );
 
        // If the response filter did not give us the CAE, look for it in the order meta.
        if ( '' === (string) $sale->invoice_cae ) {
            $fields = array();
            foreach ( $order->get_meta_data() as $meta ) {
                $item   = $meta->get_data();
                $fields = self::extract_fields( array( $item['key'] => $item['value'] ), $fields );
            }
            if ( ! empty( $fields['cae'] ) )     { $data['invoice_cae']     = $fields['cae']; }
            if ( ! empty( $fields['cae_due'] ) ) { $data['invoice_cae_due'] = $fields['cae_due']; }
            if ( ! empty( $fields['number'] ) )  { $data['invoice_number']  = $fields['number']; }
        }
 
        $wpdb->update( "{$wpdb->prefix}kop_sales", $data, array( 'id' => $sale_id ) );
    }
 
    /** Last order note that CRPlugins wrote (usually the ARCA error). */
    private static function last_order_note( $order ) {
        if ( ! $order instanceof WC_Order || ! function_exists( 'wc_get_order_notes' ) ) {
            return '';
        }
        foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id(), 'limit' => 5 ) ) as $note ) {
            $text = trim( wp_strip_all_tags( $note->content ) );
            if ( '' !== $text && ! preg_match( '/^(Pedido creado desde el POS|Facturación automática POS|Estado del pedido cambiado|Order status changed)/iu', $text ) ) {
                return mb_substr( $text, 0, 400 );
            }
        }
        return '';
    }
 
    /** Marks the sale as "error". There is no automatic retry: a second request could issue a duplicate invoice. */
    public static function set_error( $sale_id, $message ) {
        global $wpdb;
        $sale_id = (int) $sale_id;
        $wpdb->update( "{$wpdb->prefix}kop_sales", array(
            'invoice_status' => 'error',
            'invoice_error'  => mb_substr( (string) $message, 0, 500 ),
        ), array( 'id' => $sale_id ) );
     }
 
    /** After asking CRPlugins to invoice: if nobody confirmed it (hook), it is an error. */
    public static function finish_attempt( $sale_id, $order = null ) {
        $sale = KOP_Sales::get( $sale_id );
        if ( ! $sale || 'emitting' !== $sale->invoice_status ) {
            return; // already confirmed by the hook, or changed by someone else.
        }
        if ( ! $order && $sale->wc_order_id ) {
            $order = wc_get_order( $sale->wc_order_id );
        }
        $note = self::last_order_note( $order );
        self::set_error( $sale_id, '' !== $note ? $note : 'ARCA no confirmó la factura. Revisá las notas del pedido.' );
    }
 
    /**
     * READ-ONLY check: tells whether the invoice of a "C" sale was issued. It NEVER sends anything to ARCA
     * and never creates or changes an order, so it cannot produce a duplicate invoice.
     *
     * Evidence looked for (in this order): the CAE already saved in the sale, the CAE in the saved ARCA
     * response, the CAE in the WooCommerce order meta, and the "invoiced" state confirmed by CRPlugins.
     *
     * Returns array( 'state' => 'invoiced'|'unconfirmed', 'cae' => string, 'message' => string ) or a WP_Error.
     * "unconfirmed" means: there is NO proof the invoice exists. It does not prove it was not issued
     * (the response may have been lost), so look it up in ARCA before invoicing again.
     */
    public static function check_invoice( $sale_id ) {
        global $wpdb;
        $sale_id = (int) $sale_id;
        $sale    = KOP_Sales::get( $sale_id );
        if ( ! $sale || 'C' !== $sale->type || 'issued' !== $sale->status ) {
            return new WP_Error( 'state', 'Esa venta no es una factura C vigente.' );
        }

        $order  = $sale->wc_order_id ? wc_get_order( $sale->wc_order_id ) : false;
        $fields = array();

        if ( preg_match( '/^\d{14}$/', (string) $sale->invoice_cae ) ) {
            $fields['cae'] = $sale->invoice_cae;
        }
        if ( empty( $fields['cae'] ) && '' !== (string) $sale->invoice_response ) {
            $fields = self::extract_fields( json_decode( (string) $sale->invoice_response, true ), $fields );
        }
        if ( empty( $fields['cae'] ) && $order instanceof WC_Order ) {
            foreach ( $order->get_meta_data() as $meta ) {
                $item   = $meta->get_data();
                $fields = self::extract_fields( array( $item['key'] => $item['value'] ), $fields );
            }
        }

        // Proof found: the invoice exists. Save it (only completes data, never emits).
        if ( ! empty( $fields['cae'] ) ) {
            $data = array( 'invoice_status' => 'invoiced', 'invoice_error' => '', 'invoice_cae' => $fields['cae'] );
            if ( empty( $sale->invoiced_at ) )                         { $data['invoiced_at']      = current_time( 'mysql' ); }
            if ( ! empty( $fields['cae_due'] ) && '' === (string) $sale->invoice_cae_due ) { $data['invoice_cae_due'] = $fields['cae_due']; }
            if ( ! empty( $fields['number'] ) && '' === (string) $sale->invoice_number )   { $data['invoice_number']  = $fields['number']; }
            $wpdb->update( "{$wpdb->prefix}kop_sales", $data, array( 'id' => $sale_id ) );
            return array(
                'state'   => 'invoiced',
                'cae'     => $fields['cae'],
                'message' => 'Factura emitida. CAE ' . $fields['cae'] . '.',
            );
        }

        // CRPlugins confirmed it (hook) but no CAE could be read: it is still an issued invoice.
        if ( 'invoiced' === $sale->invoice_status ) {
            return array(
                'state'   => 'invoiced',
                'cae'     => '',
                'message' => 'Factura emitida (confirmada por CRPlugins). No se pudo leer el CAE: buscalo en el pedido o en ARCA.',
            );
        }

        // No proof. A request stuck in "emitting" for more than 5 minutes is shown as a problem.
        if ( 'emitting' === $sale->invoice_status
            && ( empty( $sale->invoice_attempt_at ) || strtotime( $sale->invoice_attempt_at ) < strtotime( current_time( 'mysql' ) ) - 300 ) ) {
            self::set_error( $sale_id, 'ARCA no confirmó la factura (sin respuesta).' );
        }

        $where = $order ? ' o en el pedido #' . (int) $order->get_id() : '';
        return array(
            'state'   => 'unconfirmed',
            'cae'     => '',
            'message' => 'No hay constancia de que la factura se haya emitido. NO la vuelvas a facturar todavía: buscala en ARCA (Mis Comprobantes)' . $where . '. Esta verificación no emite nada.',
        );
    }

    /* ------------------------------------------------------------------
     * "Verificar factura" button in the history
     * ---------------------------------------------------------------- */
    public static function handle_check() {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'kop_check_' . $id );
        if ( ! current_user_can( KOP_INV_CAP ) ) {
            wp_die( 'Sin permisos' );
        }

        $result = self::check_invoice( $id );
        $args   = array( 'page' => 'kop-history' );
        if ( is_wp_error( $result ) ) {
            $args['invoice_error'] = rawurlencode( $result->get_error_message() );
        } elseif ( 'invoiced' === $result['state'] ) {
            $args['invoiced'] = rawurlencode( $result['message'] );
        } else {
            $args['invoice_warning'] = rawurlencode( $result['message'] );
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }
}
