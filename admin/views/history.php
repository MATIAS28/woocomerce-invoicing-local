<?php
/**
 * View: sales history with search, filters, totals, detail per sale and CSV export.
 *
 * Variables: $rows, $paged, $per_page, $total, $filters, $totals, $items_by_sale,
 *            $payments_by_sale, $sellers, $problems
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
 
$base_url = admin_url( 'admin.php?page=kop-history' );
 
$invoice_labels = array(
    'emitting'   => 'Emitiendo…',
    'invoiced'   => 'Facturada',
    'error'      => 'Error',
    'unverified' => 'Sin verificar',
);
 
// Quick filters (they keep the rest of the filters).
$pill_url = function ( $key, $value ) use ( $base_url, $filters ) {
    $args = array_filter( $filters, function ( $v ) { return '' !== $v && 0 !== $v; } );
    unset( $args['type'], $args['state'] );
    if ( '' !== $key ) {
        $args[ $key ] = $value;
    }
    return add_query_arg( $args, $base_url );
};
$pills = array(
    array( 'Todas',                '',      '',          '' === $filters['type'] && '' === $filters['state'] ),
    array( 'Facturas C',           'type',  'C',         'C' === $filters['type'] && '' === $filters['state'] ),
    array( 'Comprobantes X',       'type',  'X',         'X' === $filters['type'] && '' === $filters['state'] ),
    array( 'Con error',            'state', 'error',     'error' === $filters['state'] ),
    array( 'Anuladas',             'state', 'cancelled', 'cancelled' === $filters['state'] ),
);
 
$is_admin = current_user_can( 'manage_options' );
$notice   = function ( $key, $class ) {
    if ( ! empty( $_GET[ $key ] ) && '1' !== $_GET[ $key ] ) {
        echo '<div class="notice notice-' . esc_attr( $class ) . '"><p>' . esc_html( rawurldecode( wp_unslash( $_GET[ $key ] ) ) ) . '</p></div>';
    }
};
?>
<style>
    .kop-filters { display:flex; flex-wrap:wrap; gap:8px; align-items:end; margin:12px 0; }
    .kop-filters label { display:block; font-size:12px; color:#50575e; margin-bottom:2px; }
    .kop-filters input[type=text], .kop-filters input[type=date], .kop-filters select { min-width:130px; }
    .kop-pills a { display:inline-block; padding:4px 12px; border:1px solid #c3c4c7; border-radius:14px; background:#fff; text-decoration:none; margin-right:6px; }
    .kop-pills a.on { background:#2271b1; border-color:#2271b1; color:#fff; }
    .kop-totals { margin:10px 0; padding:8px 12px; background:#f0f6fc; border-left:4px solid #72aee6; }
    .kop-detail > td { background:#fafafa !important; }
    .kop-detail table { width:100%; margin:6px 0; }
    .kop-detail .cols { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:14px; }
    .kop-tag { display:inline-block; padding:1px 8px; border-radius:10px; font-size:12px; background:#f0f0f1; }
    .kop-tag.ok { background:#edfaef; color:#00700d; } .kop-tag.err { background:#fcf0f1; color:#b32d2e; } .kop-tag.wait { background:#fcf9e8; color:#8a6d00; } .kop-tag.off { background:#f0f0f1; color:#50575e; }
</style>
 
<div class="wrap">
    <h1 class="wp-heading-inline">Historial de ventas</h1>
    <a class="page-title-action" href="<?php echo esc_url( kop_inv_export_url( $filters ) ); ?>">Exportar a CSV</a>
    <hr class="wp-header-end">
 
    <?php if ( isset( $_GET['cancelled'] ) ) : ?>
        <div class="notice notice-success"><p>Venta anulada y stock devuelto.</p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['invoiced'] ) ) : ?>
        <div class="notice notice-success"><p><?php echo esc_html( '1' !== $_GET['invoiced'] ? rawurldecode( wp_unslash( $_GET['invoiced'] ) ) : 'Factura emitida: ARCA la confirmó.' ); ?></p></div>
    <?php endif; ?>
    <?php $notice( 'cancel_error', 'error' ); $notice( 'invoice_error', 'error' ); $notice( 'invoice_warning', 'warning' ); ?>
 
    <div class="kop-pills">
        <?php foreach ( $pills as $pill ) : ?>
            <a class="<?php echo $pill[3] ? 'on' : ''; ?>" href="<?php echo esc_url( $pill_url( $pill[1], $pill[2] ) ); ?>"><?php echo esc_html( $pill[0] ); ?></a>
        <?php endforeach; ?>
        <?php if ( $problems ) : ?>
            <span class="kop-tag err"><?php echo (int) $problems; ?> factura(s) C con problemas</span>
        <?php endif; ?>
    </div>
 
    <form method="get" class="kop-filters">
        <input type="hidden" name="page" value="kop-history">
        <?php if ( $filters['type'] ) : ?><input type="hidden" name="type" value="<?php echo esc_attr( $filters['type'] ); ?>"><?php endif; ?>
        <?php if ( $filters['state'] ) : ?><input type="hidden" name="state" value="<?php echo esc_attr( $filters['state'] ); ?>"><?php endif; ?>
        <div><label for="kop-f-s">Buscar</label>
            <input type="text" id="kop-f-s" name="s" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="Número, cliente, DNI/CUIT, teléfono, producto, SKU, CAE" style="min-width:330px"></div>
        <div><label for="kop-f-from">Desde</label><input type="date" id="kop-f-from" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>"></div>
        <div><label for="kop-f-to">Hasta</label><input type="date" id="kop-f-to" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>"></div>
        <div><label for="kop-f-pay">Forma de pago</label>
            <select id="kop-f-pay" name="pay"><option value="">Todas</option>
                <?php foreach ( kop_inv_payment_methods() as $method ) : ?>
                    <option value="<?php echo esc_attr( $method['name'] ); ?>" <?php selected( $filters['pay'], $method['name'] ); ?>><?php echo esc_html( $method['name'] ); ?></option>
                <?php endforeach; ?>
            </select></div>
        <div><label for="kop-f-seller">Vendedor</label>
            <select id="kop-f-seller" name="seller"><option value="">Todos</option>
                <?php foreach ( $sellers as $uid => $name ) : ?>
                    <option value="<?php echo (int) $uid; ?>" <?php selected( (int) $filters['seller'], (int) $uid ); ?>><?php echo esc_html( $name ); ?></option>
                <?php endforeach; ?>
            </select></div>
        <div><label for="kop-f-min">Monto desde</label><input type="text" inputmode="decimal" id="kop-f-min" name="min" value="<?php echo esc_attr( '' === $filters['min'] ? '' : $filters['min'] ); ?>" style="min-width:90px;width:90px"></div>
        <div><label for="kop-f-max">hasta</label><input type="text" inputmode="decimal" id="kop-f-max" name="max" value="<?php echo esc_attr( '' === $filters['max'] ? '' : $filters['max'] ); ?>" style="min-width:90px;width:90px"></div>
        <div>
            <button type="submit" class="button button-primary">Filtrar</button>
            <a class="button" href="<?php echo esc_url( $base_url ); ?>">Limpiar</a>
        </div>
    </form>
 
    <div class="kop-totals">
        <strong><?php echo (int) $totals['count']; ?></strong> venta(s) · Total vendido: <strong><?php echo esc_html( kop_inv_money( $totals['amount'] ) ); ?></strong>
        <?php if ( $totals['cancelled'] ) : ?> · <?php echo (int) $totals['cancelled']; ?> anulada(s) (no suman al total)<?php endif; ?>
    </div>
 
    <table class="widefat striped">
        <thead><tr><th>Número</th><th>Fecha</th><th>Cliente</th><th>Pago</th><th>Total</th><th>Factura</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php if ( ! $rows ) : ?>
            <tr><td colspan="8">No hay ventas con esos filtros.</td></tr>
        <?php endif; ?>
        <?php foreach ( $rows as $sale ) : ?>
            <?php
            $cancelled = ( 'cancelled' === $sale->status );
            $inv       = $sale->invoice_status;
            $is_c      = ( 'C' === $sale->type );
            $sid       = (int) $sale->id;
            $seller    = isset( $sellers[ (int) $sale->seller_id ] ) ? $sellers[ (int) $sale->seller_id ] : '—';
            $wa        = kop_inv_whatsapp_url( $sale->customer_phone, kop_inv_whatsapp_text( $sale ) );
            $can_check = $is_c && ! $cancelled && 'invoiced' !== $inv;
            ?>
            <tr>
                <td><strong><?php echo esc_html( $sale->number ); ?></strong><br><small><?php echo $is_c ? 'Factura C' : 'Comprobante X'; ?></small></td>
                <td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $sale->created_at ) ); ?></td>
                <td><?php echo esc_html( $sale->customer_name ); ?><?php if ( $sale->customer_tax_id ) : ?><br><small><?php echo esc_html( $sale->customer_tax_id ); ?></small><?php endif; ?></td>
                <td><?php echo esc_html( $sale->payment_method ); ?></td>
                <td><?php echo esc_html( kop_inv_money( $sale->total ) ); ?></td>
                <td>
                    <?php if ( ! $is_c || $cancelled ) : ?>
                        <span class="kop-tag off">—</span>
                    <?php else : ?>
                        <?php $cls = 'invoiced' === $inv ? 'ok' : ( 'error' === $inv ? 'err' : 'wait' ); ?>
                        <span class="kop-tag <?php echo esc_attr( $cls ); ?>"><?php echo esc_html( isset( $invoice_labels[ $inv ] ) ? $invoice_labels[ $inv ] : $inv ); ?></span>
                        <?php if ( 'invoiced' === $inv && $sale->invoice_cae ) : ?>
                            <br><small>CAE <?php echo esc_html( $sale->invoice_cae ); ?></small>
                        <?php endif; ?>
                        <?php if ( 'error' === $inv && $sale->invoice_error ) : ?>
                            <br><small style="color:#b32d2e"><?php echo esc_html( $sale->invoice_error ); ?></small>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="kop-tag <?php echo $cancelled ? 'err' : 'ok'; ?>"><?php echo $cancelled ? 'Anulada' : 'Emitida'; ?></span>
                    <?php if ( $cancelled && $sale->cancelled_at ) : ?>
                        <br><small><?php echo esc_html( mysql2date( 'd/m/Y H:i', $sale->cancelled_at ) ); ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="#" class="kop-toggle" data-id="<?php echo $sid; ?>">Detalle</a>
                    | <a href="<?php echo esc_url( kop_inv_receipt_url( $sale->id ) ); ?>" target="_blank">Ver / imprimir</a>
                    | <a href="<?php echo esc_url( add_query_arg( 'pdf', 1, kop_inv_receipt_url( $sale->id ) ) ); ?>" target="_blank">PDF</a>
                    <?php if ( $wa ) : ?>| <a href="<?php echo esc_url( $wa ); ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
                    <?php if ( $can_check ) : ?>
                        | <a href="<?php echo esc_url( kop_inv_check_url( $sale->id ) ); ?>">Verificar factura</a>
                    <?php endif; ?>
                    <?php if ( $sale->wc_order_id && kop_inv_order_edit_url( $sale->wc_order_id ) ) : ?>
                        | <a href="<?php echo esc_url( kop_inv_order_edit_url( $sale->wc_order_id ) ); ?>" target="_blank">Ver pedido</a>
                    <?php endif; ?>
                    <?php if ( ! $cancelled ) : ?>
                        <?php if ( empty( $sale->wc_order_id ) ) : ?>
                            | <a href="<?php echo esc_url( kop_inv_cancel_url( $sale->id ) ); ?>"
                                onclick="var r = prompt('Motivo de la anulación (opcional). Se devuelve el stock.'); if (r === null) { return false; } this.href += '&amp;reason=' + encodeURIComponent(r);">Anular</a>
                        <?php else : ?>
                            | <small title="La factura ya existe: hace falta una nota de crédito">No anulable (nota de crédito)</small>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
 
            <tr class="kop-detail" id="kop-detail-<?php echo $sid; ?>" style="display:none">
                <td colspan="8">
                    <div class="cols">
                        <div>
                            <strong>Productos</strong>
                            <table class="widefat">
                                <thead><tr><th>SKU</th><th>Descripción</th><th>Cant.</th><th>Precio</th><th>Desc.</th><th>Total</th></tr></thead>
                                <tbody>
                                <?php foreach ( isset( $items_by_sale[ $sid ] ) ? $items_by_sale[ $sid ] : array() as $item ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $item->sku ); ?></td>
                                        <td><?php echo esc_html( $item->description ); ?></td>
                                        <td><?php echo (int) $item->quantity; ?></td>
                                        <td><?php echo esc_html( kop_inv_money( $item->price ) ); ?></td>
                                        <td><?php echo $item->discount_pct > 0 ? esc_html( rtrim( rtrim( number_format( (float) $item->discount_pct, 2, ',', '' ), '0' ), ',' ) ) . '%' : '—'; ?></td>
                                        <td><?php echo esc_html( kop_inv_money( $item->total ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div>
                            <strong>Pagos</strong>
                            <table class="widefat">
                                <thead><tr><th>Forma</th><th>Monto</th><th>Recargo / desc.</th></tr></thead>
                                <tbody>
                                <?php foreach ( isset( $payments_by_sale[ $sid ] ) ? $payments_by_sale[ $sid ] : array() as $pay ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $pay->method ); ?></td>
                                        <td><?php echo esc_html( kop_inv_money( $pay->amount ) ); ?></td>
                                        <td><?php echo esc_html( kop_inv_money( $pay->adjustment ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if ( $sale->cash_received > 0 ) : ?>
                                <small>Efectivo recibido <?php echo esc_html( kop_inv_money( $sale->cash_received ) ); ?> · Vuelto <?php echo esc_html( kop_inv_money( $sale->change_amount ) ); ?></small><br>
                            <?php endif; ?>
                            <small>Subtotal <?php echo esc_html( kop_inv_money( $sale->subtotal ) ); ?>
                                · Descuento <?php echo esc_html( kop_inv_money( $sale->discount ) ); ?>
                                · Ajuste de pago <?php echo esc_html( kop_inv_money( $sale->payment_adjustment ) ); ?></small>
                        </div>
                        <div>
                            <strong>Datos</strong><br>
                            Vendedor: <?php echo esc_html( $seller ); ?><br>
                            Teléfono: <?php echo esc_html( $sale->customer_phone ? $sale->customer_phone : '—' ); ?><br>
                            <?php if ( $sale->notes ) : ?>Notas: <?php echo esc_html( $sale->notes ); ?><br><?php endif; ?>
                            <?php if ( $is_c ) : ?>
                                <br><strong>Factura</strong><br>
                                Estado: <?php echo esc_html( isset( $invoice_labels[ $inv ] ) ? $invoice_labels[ $inv ] : $inv ); ?>
                                · Intentos: <?php echo (int) $sale->invoice_attempts; ?><br>
                                <?php if ( $sale->invoice_cae ) : ?>CAE: <?php echo esc_html( $sale->invoice_cae ); ?><?php echo $sale->invoice_cae_due ? ' · vence ' . esc_html( mysql2date( 'd/m/Y', $sale->invoice_cae_due ) ) : ''; ?><br><?php endif; ?>
                                <?php if ( $sale->invoice_number ) : ?>N° de factura: <?php echo esc_html( $sale->invoice_number ); ?><br><?php endif; ?>
                                <?php if ( 'invoiced' === $inv && $sale->invoiced_at ) : ?>Facturada: <?php echo esc_html( mysql2date( 'd/m/Y H:i', $sale->invoiced_at ) ); ?><br><?php endif; ?>
                                <?php if ( 'invoiced' === $inv && ! $sale->invoice_cae ) : ?><small>No se pudo leer el CAE de la respuesta de ARCA: está en el pedido.</small><br><?php endif; ?>
                                <?php if ( $sale->invoice_error ) : ?><span style="color:#b32d2e"><?php echo esc_html( $sale->invoice_error ); ?></span><br><?php endif; ?>
                            <?php endif; ?>
                            <?php if ( $cancelled ) : ?>
                                <br><strong>Anulación</strong><br>
                                <?php $by = $sale->cancelled_by ? get_userdata( (int) $sale->cancelled_by ) : false; ?>
                                <?php echo esc_html( $sale->cancelled_at ? mysql2date( 'd/m/Y H:i', $sale->cancelled_at ) : '' ); ?><?php echo $by ? ' · ' . esc_html( $by->display_name ) : ''; ?><br>
                                Motivo: <?php echo esc_html( $sale->cancel_reason ? $sale->cancel_reason : '—' ); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ( $is_admin && $is_c && $sale->invoice_response ) : ?>
                        <details><summary>Diagnóstico: respuesta que devolvió ARCA / CRPlugins</summary>
                            <pre style="white-space:pre-wrap;max-height:200px;overflow:auto"><?php echo esc_html( $sale->invoice_response ); ?></pre>
                        </details>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    echo wp_kses_post( paginate_links( array(
        'base'    => add_query_arg( 'paged', '%#%' ),
        'format'  => '',
        'current' => $paged,
        'total'   => max( 1, (int) ceil( $total / $per_page ) ),
    ) ) );
    ?>
</div>
<script>
document.addEventListener('click', function (e) {
    var link = e.target.closest('.kop-toggle');
    if (!link) { return; }
    e.preventDefault();
    var row = document.getElementById('kop-detail-' + link.getAttribute('data-id'));
    if (row) { row.style.display = (row.style.display === 'none') ? '' : 'none'; }
});
</script>
