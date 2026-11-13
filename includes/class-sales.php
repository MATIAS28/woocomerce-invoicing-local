<?php
/**
 * Sales logic: create (decreasing stock), cancel (restoring stock) and queries.
 */
 
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
 
class KOP_Sales {
 
    public static function init() {
        add_action( 'admin_post_kop_cancel', array( __CLASS__, 'handle_cancel' ) );
        add_action( 'admin_post_kop_export', array( __CLASS__, 'handle_export' ) );
    }
 
    /* ------------------------------------------------------------------
     * Queries
     * ---------------------------------------------------------------- */
    public static function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kop_sales WHERE id = %d", $id ) );
    }
 
    public static function items( $sale_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kop_sale_items WHERE sale_id = %d", $sale_id ) );
    }
 
    public static function payments( $sale_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kop_sale_payments WHERE sale_id = %d", $sale_id ) );
    }
 
    /* ------------------------------------------------------------------
     * History: filters, search, totals
     * ---------------------------------------------------------------- */
 
    /** Reads the history filters from the request (GET by default). Everything is validated. */
    public static function filters_from_request( $src = null ) {
        $src  = ( null === $src ) ? $_GET : $src; // phpcs:ignore
        $get  = function ( $key ) use ( $src ) {
            return isset( $src[ $key ] ) && is_scalar( $src[ $key ] ) ? trim( (string) wp_unslash( $src[ $key ] ) ) : '';
        };
        $date = function ( $value ) {
            return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
        };
        $type  = strtoupper( $get( 'type' ) );
        $state = $get( 'state' );
 
        return array(
            's'      => sanitize_text_field( $get( 's' ) ),
            'type'   => in_array( $type, array( 'C', 'X' ), true ) ? $type : '',
            'state'  => in_array( $state, array( 'issued', 'cancelled', 'error', 'emitting', 'invoiced' ), true ) ? $state : '',
            'from'   => $date( $get( 'from' ) ),
            'to'     => $date( $get( 'to' ) ),
            'pay'    => sanitize_text_field( $get( 'pay' ) ),
            'seller' => absint( $get( 'seller' ) ),
            'min'    => '' === $get( 'min' ) ? '' : kop_inv_parse_amount( $get( 'min' ) ),
            'max'    => '' === $get( 'max' ) ? '' : kop_inv_parse_amount( $get( 'max' ) ),
        );
    }
 
    /** Builds the WHERE of the history. Returns array( sql, args ). Table alias: s. */
    private static function where( array $f ) {
        global $wpdb;
        $w = array( '1=1' );
        $a = array();
 
        if ( ! empty( $f['s'] ) ) {
            $like = '%' . $wpdb->esc_like( $f['s'] ) . '%';
            $w[]  = "( s.number LIKE %s OR s.customer_name LIKE %s OR s.customer_tax_id LIKE %s OR s.customer_phone LIKE %s
                OR s.invoice_cae LIKE %s OR s.invoice_number LIKE %s
                OR EXISTS ( SELECT 1 FROM {$wpdb->prefix}kop_sale_items i WHERE i.sale_id = s.id AND ( i.sku LIKE %s OR i.description LIKE %s ) ) )";
            for ( $n = 0; $n < 8; $n++ ) {
                $a[] = $like;
            }
        }
        if ( ! empty( $f['type'] ) ) {
            $w[] = 's.type = %s';
            $a[] = $f['type'];
        }
        switch ( isset( $f['state'] ) ? $f['state'] : '' ) {
            case 'issued':
            case 'cancelled':
                $w[] = 's.status = %s';
                $a[] = $f['state'];
                break;
            case 'error':
            case 'emitting':
            case 'invoiced':
                $w[] = "s.type = 'C' AND s.status = 'issued' AND s.invoice_status = %s";
                $a[] = $f['state'];
                break;
        }
        if ( ! empty( $f['from'] ) ) {
            $w[] = 's.created_at >= %s';
            $a[] = $f['from'] . ' 00:00:00';
        }
        if ( ! empty( $f['to'] ) ) {
            $w[] = 's.created_at <= %s';
            $a[] = $f['to'] . ' 23:59:59';
        }
        if ( ! empty( $f['pay'] ) ) {
            $w[] = 's.payment_method LIKE %s';
            $a[] = '%' . $wpdb->esc_like( $f['pay'] ) . '%';
        }
        if ( ! empty( $f['seller'] ) ) {
            $w[] = 's.seller_id = %d';
            $a[] = (int) $f['seller'];
        }
        if ( isset( $f['min'] ) && '' !== $f['min'] ) {
            $w[] = 's.total >= %f';
            $a[] = (float) $f['min'];
        }
        if ( isset( $f['max'] ) && '' !== $f['max'] ) {
            $w[] = 's.total <= %f';
            $a[] = (float) $f['max'];
        }
        return array( 'WHERE ' . implode( ' AND ', $w ), $a );
    }
 
    /** prepare() only when there are placeholders (WordPress complains otherwise). */
    private static function prep( $sql, array $args ) {
        global $wpdb;
        return $args ? $wpdb->prepare( $sql, $args ) : $sql;
    }
 
    public static function count( array $f = array() ) {
        global $wpdb;
        list( $where, $args ) = self::where( $f );
        return (int) $wpdb->get_var( self::prep( "SELECT COUNT(*) FROM {$wpdb->prefix}kop_sales s $where", $args ) );
    }
 
    public static function all( $paged, $per_page, array $f = array() ) {
        global $wpdb;
        list( $where, $args ) = self::where( $f );
        $args[] = (int) $per_page;
        $args[] = ( max( 1, (int) $paged ) - 1 ) * (int) $per_page;
        return $wpdb->get_results( self::prep(
            "SELECT s.* FROM {$wpdb->prefix}kop_sales s $where ORDER BY s.id DESC LIMIT %d OFFSET %d",
            $args
        ) );
    }
 
    /** Totals of the filtered sales: count, amount (cancelled ones are not added) and cancelled count. */
    public static function totals( array $f = array() ) {
        global $wpdb;
        list( $where, $args ) = self::where( $f );
        $row = $wpdb->get_row( self::prep(
            "SELECT COUNT(*) AS n,
                    COALESCE( SUM( CASE WHEN s.status = 'issued' THEN s.total ELSE 0 END ), 0 ) AS amount,
                    COALESCE( SUM( CASE WHEN s.status = 'cancelled' THEN 1 ELSE 0 END ), 0 ) AS cancelled
             FROM {$wpdb->prefix}kop_sales s $where",
            $args
        ) );
        return array(
            'count'     => (int) $row->n,
            'amount'    => (float) $row->amount,
            'cancelled' => (int) $row->cancelled,
        );
    }
 
    /** Items / payments of several sales in one query each: array( sale_id => rows ). */
    public static function grouped( $table, array $ids ) {
        global $wpdb;
        $out = array();
        $ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
        if ( ! $ids ) {
            return $out;
        }
        $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}{$table} WHERE sale_id IN (" . implode( ',', $ids ) . ') ORDER BY id ASC' );
        foreach ( $rows as $row ) {
            $out[ (int) $row->sale_id ][] = $row;
        }
        return $out;
    }
 
    /** Sellers that have sales: array( user_id => name ). */
    public static function sellers() {
        global $wpdb;
        $out = array();
        foreach ( $wpdb->get_col( "SELECT DISTINCT seller_id FROM {$wpdb->prefix}kop_sales WHERE seller_id > 0" ) as $uid ) {
            $user = get_userdata( (int) $uid );
            $out[ (int) $uid ] = $user ? $user->display_name : '#' . (int) $uid;
        }
        asort( $out );
        return $out;
    }
 
    /** Invoices (C) with problems: in error, or "emitting" for more than 5 minutes (the request died). */
    public static function count_problems() {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 300 );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kop_sales
             WHERE type = 'C' AND status = 'issued'
               AND ( invoice_status = 'error' OR ( invoice_status = 'emitting' AND ( invoice_attempt_at IS NULL OR invoice_attempt_at < %s ) ) )",
            $cutoff
        ) );
    }
 
    /* ------------------------------------------------------------------
     * CSV export of the filtered history (Excel Argentina: ";" and decimal comma)
     * ---------------------------------------------------------------- */
    private static function csv_cell( $value ) {
        $value = (string) $value;
        // Avoid formulas when the CSV is opened in Excel (= + - @ at the start).
        return ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) ? "'" . $value : $value;
    }
 
    private static function csv_num( $n ) {
        return number_format( (float) $n, 2, ',', '' );
    }
 
    public static function csv_header() {
        return array( 'Número', 'Tipo', 'Fecha', 'Cliente', 'DNI/CUIT', 'Teléfono', 'Vendedor', 'Formas de pago', 'Subtotal', 'Descuento', 'Ajuste de pago', 'Total', 'Estado venta', 'Estado factura', 'CAE', 'Vencimiento CAE', 'N° de factura', 'Pedido WooCommerce', 'Anulada el', 'Motivo de anulación' );
    }
 
    public static function csv_row( $sale, $seller_name = '' ) {
        $labels = array( 'none' => '', 'emitting' => 'Emitiendo', 'invoiced' => 'Facturada', 'error' => 'Error', 'unverified' => 'Sin verificar' );
        return array_map( array( __CLASS__, 'csv_cell' ), array(
            $sale->number,
            $sale->type,
            mysql2date( 'd/m/Y H:i', $sale->created_at ),
            $sale->customer_name,
            $sale->customer_tax_id,
            $sale->customer_phone,
            $seller_name,
            $sale->payment_method,
            self::csv_num( $sale->subtotal ),
            self::csv_num( $sale->discount ),
            self::csv_num( $sale->payment_adjustment ),
            self::csv_num( $sale->total ),
            'cancelled' === $sale->status ? 'Anulada' : 'Emitida',
            isset( $labels[ $sale->invoice_status ] ) ? $labels[ $sale->invoice_status ] : $sale->invoice_status,
            $sale->invoice_cae,
            $sale->invoice_cae_due ? mysql2date( 'd/m/Y', $sale->invoice_cae_due ) : '',
            $sale->invoice_number,
            $sale->wc_order_id ? $sale->wc_order_id : '',
            $sale->cancelled_at ? mysql2date( 'd/m/Y H:i', $sale->cancelled_at ) : '',
            $sale->cancel_reason,
        ) );
    }
 
    public static function handle_export() {
        check_admin_referer( 'kop_export' );
        if ( ! current_user_can( KOP_INV_CAP ) ) {
            wp_die( 'Sin permisos' );
        }
        $filters = self::filters_from_request();
        $sellers = self::sellers();
 
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="ventas-' . gmdate( 'Ymd-His' ) . '.csv"' );
 
        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel shows the accents.
        fputcsv( $out, self::csv_header(), ';' );
        $page = 1;
        do {
            $rows = self::all( $page, 500, $filters );
            foreach ( $rows as $sale ) {
                fputcsv( $out, self::csv_row( $sale, isset( $sellers[ (int) $sale->seller_id ] ) ? $sellers[ (int) $sale->seller_id ] : '' ), ';' );
            }
            $page++;
        } while ( 500 === count( $rows ) );
        fclose( $out );
        exit;
    }
 
    /* ------------------------------------------------------------------
     * Create a sale
     * $data = [customer, tax_id, discount, payment, notes,
     *          items: [ {id, qty, price, discount_pct} ]]
     * Returns an array (id, number, total, url) or a WP_Error.
     * ---------------------------------------------------------------- */
 
    public static function create( $data ) {
        global $wpdb;
 
        if ( ! is_array( $data ) || empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
            return new WP_Error( 'no_items', 'La venta no tiene productos.' );
        }
 
        $token = isset( $data['token'] ) ? preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $data['token'] ) : '';
        if ( strlen( $token ) < 16 || strlen( $token ) > 64 ) {
            $token = '';
        }
 
        if ( '' !== $token ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}kop_sales WHERE token = %s", $token ) );
            if ( $existing ) {
                return self::result_for( $existing );
            }
        }
 
        // Invoice (type C): the customer name and a valid DNI/CUIT are required. The server decides, not the browser.
        $tax_id = isset( $data['tax_id'] ) ? sanitize_text_field( $data['tax_id'] ) : '';
        
        if ( isset( $data['type'] ) && 'C' === $data['type'] ) {
            $checked = kop_inv_check_invoice_customer( isset( $data['customer'] ) ? $data['customer'] : '', $tax_id );
 
            if ( is_wp_error( $checked ) ) {
                return $checked;
            }
 
            $tax_id = $checked; // digits only
 
            if ( ! kop_inv_crplugins_active() ) {
                return new WP_Error( 'crp', 'CRPlugins (ARCA) no está activo: no se puede emitir factura C.' );
            }
        }
 
        // Validate lines and stock.
        $lines    = array();
        $subtotal = 0.0;
        $qty_by_product = array();
        foreach ( $data['items'] as $item ) {
            $product_id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
            $qty        = isset( $item['qty'] ) ? (int) $item['qty'] : 0;
            $price      = isset( $item['price'] ) ? max( 0, (float) $item['price'] ) : 0;
            $line_pct   = isset( $item['discount_pct'] ) ? min( 100, max( 0, (float) $item['discount_pct'] ) ) : 0;
            $product    = $product_id ? wc_get_product( $product_id ) : false;
            if ( ! $product || $qty < 1 ) {
                return new WP_Error( 'line', 'Hay una línea inválida en la venta.' );
            }
            $qty_by_product[ $product_id ] = ( isset( $qty_by_product[ $product_id ] ) ? $qty_by_product[ $product_id ] : 0 ) + $qty;
            if ( $product->managing_stock() && ! $product->backorders_allowed() && $product->get_stock_quantity() < $qty_by_product[ $product_id ] ) {
                return new WP_Error( 'stock', sprintf( 'Stock insuficiente: %s (disponible %d).', $product->get_name(), $product->get_stock_quantity() ) );
            }
            $line_total = round( $qty * $price * ( 1 - $line_pct / 100 ), 2 );
            $subtotal  += $line_total;
            $lines[]    = array(
                'product_id'   => $product_id,
                'sku'          => $product->get_sku(),
                'description'  => mb_substr( wp_strip_all_tags( $product->get_name() ), 0, 250 ),
                'quantity'     => $qty,
                'price'        => $price,
                'discount_pct' => $line_pct,
                'total'        => $line_total,
            );
        }
 
        $discount = isset( $data['discount'] ) ? max( 0, (float) $data['discount'] ) : 0;
        $discount = min( $discount, $subtotal );
        $base     = round( $subtotal - $discount, 2 );
 
        // Payments: [ {name, amount} ]. Validated against the settings (the browser is not trusted)
        // and they must add up to the sale. Each method's % applies only to the part it pays.
        $configured = array();
        foreach ( kop_inv_payment_methods() as $method ) {
            $configured[ $method['name'] ] = $method['pct'];
        }
        $payments   = array();
        $paid       = 0.0;
        $adjustment = 0.0;
        $raw        = isset( $data['payments'] ) && is_array( $data['payments'] ) ? $data['payments'] : array();
        foreach ( $raw as $p ) {
            $name   = isset( $p['name'] ) ? sanitize_text_field( $p['name'] ) : '';
            $amount = isset( $p['amount'] ) ? round( (float) $p['amount'], 2 ) : 0;
            if ( ! isset( $configured[ $name ] ) || $amount <= 0 ) {
                return new WP_Error( 'payment', 'Hay una forma de pago inválida.' );
            }
            $adj        = round( $amount * $configured[ $name ] / 100, 2 );
            $payments[] = array( 'method' => $name, 'amount' => $amount, 'adjustment' => $adj );
            $paid      += $amount;
            $adjustment += $adj;
        }
 
        
        // Los pagos deben sumar exactamente la venta: el vuelto ya viene descontado del efectivo.
        if ( ! $payments || abs( round( $paid, 2 ) - round( $base, 2 ) ) > 0.01 ) {
            return new WP_Error( 'payment', 'Lo pagado no coincide con el total de la venta.' );
        }
 
        // Efectivo recibido y vuelto (dato informativo para el recibo).
        $cash_received = isset( $data['cash_received'] ) ? round( max( 0, (float) $data['cash_received'] ), 2 ) : 0.0;
        $change_amount = 0.0;
        if ( $cash_received > 0 ) {
            $cash_due = 0.0;
            foreach ( $payments as $pay ) {
                if ( preg_match( '/efectivo/i', $pay['method'] ) ) {
                    $cash_due += $pay['amount'] + $pay['adjustment'];
                }
            }
            $cash_due = round( $cash_due, 2 );
            if ( $cash_received < $cash_due ) {
                return new WP_Error( 'payment', 'El efectivo recibido es menor al efectivo a cobrar.' );
            }
            $change_amount = round( $cash_received - $cash_due, 2 );
        }
 
        $adjustment = round( $adjustment, 2 );
        $total      = round( $base + $adjustment, 2 );
 
                // X = comprobante interno. C = además crea el pedido de WooCommerce que se factura.
        $type     = ( isset( $data['type'] ) && 'C' === $data['type'] ) ? 'C' : 'X';
        $customer = isset( $data['customer'] ) ? sanitize_text_field( $data['customer'] ) : '';
 
        $wpdb->query( 'START TRANSACTION' );
 
        $inserted = $wpdb->insert( "{$wpdb->prefix}kop_sales", array(
            'type'               => $type,
            'created_at'         => current_time( 'mysql' ),
            'customer_name'      => '' !== $customer ? $customer : 'Consumidor Final',
            'customer_tax_id'    => $tax_id,
            'customer_phone'     => isset( $data['phone'] ) ? preg_replace( '/[^0-9+ ]/', '', (string) $data['phone'] ) : '',
            'seller_id'          => get_current_user_id(),
            'subtotal'           => round( $subtotal, 2 ),
            'discount'           => round( $discount, 2 ),
            'payment_adjustment' => $adjustment,
            'total'              => $total,
            'payment_method'     => implode( ' + ', array_unique( wp_list_pluck( $payments, 'method' ) ) ),
            'notes'              => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : '',
            'token'              => '' !== $token ? $token : null,
            'cash_received'      => $cash_received,
            'change_amount'      => $change_amount,
            // Las C se emiten en el momento: nacen "emitiendo" y el intento 1 ya cuenta.
            'invoice_status'     => ( 'C' === $type ) ? 'emitting' : 'none',
            'invoice_attempts'   => ( 'C' === $type ) ? 1 : 0,
            'invoice_attempt_at' => ( 'C' === $type ) ? current_time( 'mysql' ) : null,
        ) );
 
        if ( ! $inserted ) {
            $last = $wpdb->last_error;
            $wpdb->query( 'ROLLBACK' );
            // Carrera: otra petición con el mismo token llegó primero.
            if ( '' !== $token ) {
                $dup = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}kop_sales WHERE token = %s", $token ) );
                if ( $dup ) {
                    return self::result_for( $dup );
                }
            }
            error_log( 'KOP: could not insert the sale: ' . $last );
            $detail = ( $last && current_user_can( 'manage_options' ) ) ? ' Detalle técnico: ' . esc_html( $last ) : '';
            return new WP_Error( 'db', 'No se pudo guardar la venta.' . $detail );
        }
 
        $sale_id = (int) $wpdb->insert_id;
 
        // Número interno. Las ventas C llevan "OP-" para no confundirse con el número real de la factura.
        $number = sprintf( '%s-%04d-%08d', 'C' === $type ? 'OP' : 'X', (int) kop_inv_opt( 'pos_number' ), $sale_id );
        if ( false === $wpdb->update( "{$wpdb->prefix}kop_sales", array( 'number' => $number ), array( 'id' => $sale_id ) ) ) {
            return self::abort( 'number update' );
        }
 
        foreach ( $lines as $line ) {
            $line['sale_id'] = $sale_id;
            if ( ! $wpdb->insert( "{$wpdb->prefix}kop_sale_items", $line ) ) {
                return self::abort( 'item insert' );
            }
        }
 
        foreach ( $payments as $p ) {
            if ( ! $wpdb->insert( "{$wpdb->prefix}kop_sale_payments", array(
                'sale_id'    => $sale_id,
                'method'     => $p['method'],
                'amount'     => $p['amount'],
                'adjustment' => $p['adjustment'],
            ) ) ) {
                return self::abort( 'payment insert' );
            }
        }
 
        $wpdb->query( 'COMMIT' );
 
        // El stock se descuenta DESPUÉS del commit: si algo falló arriba, no se tocó el stock.
        foreach ( $qty_by_product as $product_id => $qty ) {
            $product = wc_get_product( $product_id );
            if ( $product && $product->managing_stock() ) {
                wc_update_product_stock( $product, $qty, 'decrease' );
            }
        }
 
        return self::result_for( $sale_id, true );
    }
 
    /** Deshace la transacción y devuelve el error (captura el error SQL antes del ROLLBACK). */
    private static function abort( $context ) {
        global $wpdb;
        $last = $wpdb->last_error;
        $wpdb->query( 'ROLLBACK' );
        error_log( 'KOP: ' . $context . ': ' . $last );
        $detail = ( $last && current_user_can( 'manage_options' ) ) ? ' Detalle técnico: ' . esc_html( $last ) : '';
        return new WP_Error( 'db', 'No se pudo guardar la venta.' . $detail );
    }
 
    /**
     * Arma la respuesta de una venta ya guardada.
     * $issue = true solo para la venta recién creada: si es tipo C, crea el pedido y lo factura ahora.
     * Para un reintento del mismo token ($issue = false) solo informa el estado actual.
     */
    private static function result_for( $sale_id, $issue = false ) {
        $sale = self::get( $sale_id );
        if ( ! $sale ) {
            return new WP_Error( 'db', 'No se encontró la venta.' );
        }
 
        if ( $issue && 'C' === $sale->type && 'issued' === $sale->status && ( empty( $sale->wc_order_id ) || ! wc_get_order( $sale->wc_order_id ) ) ) {
            try {
                $created = KOP_Orders::create_from_sale( $sale->id );
            } catch ( Throwable $e ) {
                $created = new WP_Error( 'exception', $e->getMessage() );
            }
            if ( is_wp_error( $created ) ) {
                KOP_Orders::set_error( $sale->id, $created->get_error_message() );
            }
            $sale = self::get( $sale_id ); // reload: it now has the invoice state.
        }
 
        $result = array(
            'id'     => (int) $sale->id,
            'number' => $sale->number,
            'total'  => kop_inv_money( $sale->total ),
            'url'    => kop_inv_receipt_url( $sale->id ),
            'type'   => $sale->type,
            'phone'  => $sale->customer_phone,
        );
 
        if ( 'C' === $sale->type && 'issued' === $sale->status ) {
            $result['invoice_status'] = $sale->invoice_status;
            $result['cae']            = $sale->invoice_cae;
            if ( ! empty( $sale->wc_order_id ) ) {
                $result['order_id']  = (int) $sale->wc_order_id;
                $result['order_url'] = kop_inv_order_edit_url( $sale->wc_order_id );
            }
            if ( 'error' === $sale->invoice_status ) {
                $result['warning'] = esc_html( 'La venta se guardó, pero ARCA no confirmó la factura: ' . $sale->invoice_error . ' No se reintenta sola: antes de volver a facturar usá "Verificar factura" en el Historial y buscala en ARCA.' );
            }
        }
 
        $wa = kop_inv_whatsapp_url( $sale->customer_phone, kop_inv_whatsapp_text( $sale ) );
        if ( $wa ) {
            $result['whatsapp'] = $wa;
        }
        return $result;
    }
 
    /* ------------------------------------------------------------------
     * Cancel a sale (restores stock). Records when, who and why.
     * An invoiced C sale cannot be cancelled here: the invoice exists and needs a credit note.
     * Returns true or a WP_Error.
     * ---------------------------------------------------------------- */
    public static function cancel( $id, $reason = '' ) {
        global $wpdb;
        $id   = (int) $id;
        $sale = self::get( $id );
        if ( ! $sale ) {
            return new WP_Error( 'no_sale', 'Venta no encontrada.' );
        }
        if ( 'issued' !== $sale->status ) {
            return new WP_Error( 'status', 'La venta ya estaba anulada.' );
        }
        if ( ! empty( $sale->wc_order_id ) ) {
            return new WP_Error( 'invoiced', 'Esta factura C ya tiene pedido (y puede tener factura emitida): para anularla hace falta una nota de crédito.' );
        }
 
        // Atomic: only one request can win this UPDATE, so the stock is returned exactly once.
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}kop_sales
             SET status = 'cancelled', cancelled_at = %s, cancelled_by = %d, cancel_reason = %s
             WHERE id = %d AND status = 'issued' AND wc_order_id = 0",
            current_time( 'mysql' ),
            get_current_user_id(),
            mb_substr( sanitize_text_field( $reason ), 0, 250 ),
            $id
        ) );
        if ( 1 !== (int) $claimed ) {
            return new WP_Error( 'race', 'No se pudo anular: la venta cambió de estado. Actualizá el Historial.' );
        }
 
        foreach ( self::items( $id ) as $item ) {
            $product = wc_get_product( $item->product_id );
            if ( $product && $product->managing_stock() ) {
                wc_update_product_stock( $product, (int) $item->quantity, 'increase' );
            }
        }
        return true;
    }
 
    public static function handle_cancel() {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'kop_cancel_' . $id );
        if ( ! current_user_can( KOP_INV_CAP ) ) {
            wp_die( 'Sin permisos' );
        }
        $reason = isset( $_GET['reason'] ) ? wp_unslash( $_GET['reason'] ) : '';
        $result = self::cancel( $id, $reason );
 
        $args = array( 'page' => 'kop-history' );
        if ( is_wp_error( $result ) ) {
            $args['cancel_error'] = rawurlencode( $result->get_error_message() );
        } else {
            $args['cancelled'] = 1;
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }
 
    public static function history_page() {
        $per_page = 30;
        $paged    = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
        $filters  = self::filters_from_request();
        $totals   = self::totals( $filters );
        $total    = $totals['count'];
        $rows     = self::all( $paged, $per_page, $filters );
        $ids      = wp_list_pluck( $rows, 'id' );
        $items_by_sale    = self::grouped( 'kop_sale_items', $ids );
        $payments_by_sale = self::grouped( 'kop_sale_payments', $ids );
        $sellers  = self::sellers();
        $problems = self::count_problems();
        include KOP_INV_DIR . 'admin/views/history.php';
    }
}
