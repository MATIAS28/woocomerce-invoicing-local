<?php
/**
 * View: printable receipt (standalone HTML document).
 * Variables: $sale (row), $items (array), $seller (WP_User|false)
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Comprobante Nº <?php echo esc_html( $sale->number ); ?></title>
    <link rel="stylesheet" href="<?php echo esc_url( plugins_url( 'assets/css/receipt.css', dirname( __FILE__, 2 ) ) ); ?>">
</head>
<body>

<div class="receipt-print-btn">
    <button type="button" onclick="window.print()">Imprimir Comprobante</button>
    <button type="button" id="kop-pdf-btn">Descargar PDF</button>
</div>

<div class="receipt-container">

    <!-- Encabezado de 3 columnas (Logo | Tipo de Factura | Datos Emisor) -->
    <div class="receipt-header">
        <div class="receipt-logo-col">
            <?php $logo = kop_inv_opt( 'logo_url' );
                if ( ! $logo && file_exists( KOP_INV_DIR . 'assets/imgs/logo.png' ) ) {
                    $logo = KOP_INV_URL . 'assets/imgs/logo.png';
                }
            ?>
            <?php if ( $logo ) : ?>
                <img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( kop_inv_opt( 'business' ) ); ?>" class="receipt-brand-logo">
            <?php else : ?>
                <strong><?php echo esc_html( kop_inv_opt( 'business' ) ); ?></strong>
            <?php endif; ?>
        </div>

        <div class="receipt-type-col">
            <div class="receipt-doc-box">
                <!-- Tipo de factura dinámico (X o C) -->
                <span class="receipt-doc-letter">X</span>
                <span class="receipt-doc-code">Comprobante interno</span>
            </div>
        </div>

        <div class="receipt-issuer-col">
            <div class="receipt-issuer-main">
                <!-- Datos del negocio dinámicos -->
                <strong><?php echo esc_html( kop_inv_opt( 'business', 'KINGS OF PC' ) ); ?></strong><br>
                Operación interna N° <?php echo esc_html( $sale->number ); ?><br>
                Fecha de emisión: <?php echo esc_html( mysql2date( 'd-m-Y - H:i:s', $sale->created_at ) ); ?><br>
                Vendedor: <?php echo esc_html( $seller ? $seller->display_name : 'Administrador' ); ?><br>
                <?php echo esc_html( kop_inv_opt( 'address', '' ) ); ?><br>
                Teléfono: <?php echo esc_html( kop_inv_opt( 'phone', '' ) ); ?>
            </div>
            
            <div class="receipt-issuer-tax">
                <!-- Datos fiscales dinámicos configurados en Ajustes -->
                CUIT: <?php echo esc_html( kop_inv_opt( 'tax_id', '' ) ); ?> - Ing. Brutos: <?php echo esc_html( kop_inv_opt( 'gross_income_id', '' ) ); ?><br>
                Condición Fiscal: <?php echo esc_html( kop_inv_opt( 'tax_category', '' ) ); ?><br>
                Inicio de actividades: <?php echo esc_html( kop_inv_opt( 'start_date', '' ) ); ?>
            </div>
        </div>
    </div>

    <?php if ( 'cancelled' === $sale->status ) : ?>
        <div class="receipt-cancelled-stamp">ANULADA</div>
    <?php endif; ?>

    <!-- Datos del Cliente (Dinámicos de la venta) -->
    <div class="receipt-customer-box">
        Cliente: <b><?php echo esc_html( $sale->customer_name ); ?></b> | DNI/CUIT: <b><?php echo esc_html( $sale->customer_tax_id ? $sale->customer_tax_id : '00000000' ); ?></b> | Cond. Fiscal: <b><?php echo esc_html( isset($sale->customer_vat) ? $sale->customer_vat : 'Consumidor Final' ); ?></b><br>
        Teléfono: <?php echo esc_html( isset($sale->customer_phone) ? $sale->customer_phone : '' ); ?> | Dirección: <?php echo esc_html( isset($sale->customer_address) ? $sale->customer_address : '' ); ?><br>
        Vendedor: <?php echo esc_html( $seller ? $seller->display_name : 'Administrador' ); ?>
    </div>

    <!-- Tabla de Ítems -->
    <table class="receipt-table">
        <thead>
            <tr>
                <th>Código</th>
                <th>Descripción</th>
                <th class="receipt-num">Cantidad</th>
                <th class="receipt-num">Precio</th>
                <th class="receipt-num">Desc. %</th>
                <th class="receipt-num">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $items as $item ) : ?>
            <tr>
                <td><?php echo esc_html( $item->sku ); ?></td>
                <td><?php echo esc_html( $item->description ); ?></td>
                <td class="receipt-num"><?php echo number_format( (float) $item->quantity, 2, '.', '' ); ?></td>
                <td class="receipt-num"><?php echo esc_html( kop_inv_money( $item->price ) ); ?></td>
                <td class="receipt-num"><?php echo $item->discount_pct > 0 ? number_format( $item->discount_pct, 2, '.', '' ) . '%' : '0.00%'; ?></td>
                <td class="receipt-num"><?php echo esc_html( kop_inv_money( $item->total ) ); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Bloque inferior: Pagos, Totales y Observaciones -->
    <div class="receipt-bottom-section">
        <div class="receipt-payments-box">
            <b>Formas de Pago:</b><br>
            <div class="receipt-payment-row">
                <span><?php echo mb_strtoupper( esc_html( $sale->payment_method ) ); ?>:</span>
                <span><b><?php echo esc_html( kop_inv_money( $sale->total ) ); ?></b></span>
            </div>
        </div>

        <div class="receipt-totals-box">
            <div class="receipt-subtotals-col">
                Subtotal: <?php echo esc_html( kop_inv_money( $sale->subtotal ) ); ?><br>
                Descuento: <?php echo esc_html( kop_inv_money( $sale->discount ) ); ?><br>
                Recargo: <?php echo esc_html( isset($sale->payment_adjustment) ? kop_inv_money( $sale->payment_adjustment ) : '$0.00' ); ?>
            </div>
            <div class="receipt-grandtotal-col">
                Total: <?php echo esc_html( kop_inv_money( $sale->total ) ); ?>
            </div>
        </div>

        <div class="receipt-observations-box">
            <b>Observación:</b><br>
            <?php echo nl2br( esc_html( $sale->notes ) ); ?>
        </div>
    </div>

</div>

<script src="<?php echo esc_url( KOP_INV_URL . 'assets/vendor/html2pdf.bundle.min.js' ); ?>"></script>
<script>
(function () {
    var filename     = 'Comprobante-<?php echo esc_js( $sale->number ); ?>.pdf';
    var autoDownload = <?php echo ! empty( $_GET['pdf'] ) ? 'true' : 'false'; ?>;
    var opts = {
        margin:      0,
        filename:    filename,
        image:       { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff', scrollX: 0, scrollY: 0 },
        jsPDF:       { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    function build() {
        document.body.classList.add('pdf-mode');
        return html2pdf().set(opts).from(document.querySelector('.receipt-container'));
    }
    function restore() {
        document.body.classList.remove('pdf-mode');
    }

    // Descarga directa (botón "Descargar PDF" y link del Historial).
    function saveHere() {
        return build().save().then(restore).catch(restore);
    }

    // Dentro del iframe del POS: arma el PDF y se lo manda a la página principal.
    function sendToParent() {
        build().outputPdf('blob').then(function (blob) {
            restore();
            window.parent.postMessage({ type: 'kop-pdf', filename: filename, blob: blob }, window.location.origin);
        }).catch(function (err) {
            restore();
            window.parent.postMessage({ type: 'kop-pdf-error', message: String(err) }, window.location.origin);
        });
    }

    document.getElementById('kop-pdf-btn').addEventListener('click', saveHere);

    if (autoDownload) {
        window.addEventListener('load', function () {
            if (window.parent !== window) { sendToParent(); } else { saveHere(); }
        });
    }
})();
</script>

</body>
</html>