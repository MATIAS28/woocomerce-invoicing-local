<?php
/**
 * View: settings page.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1>Configuración – Facturación</h1>
    
    <form method="post" action="options.php">
        <?php settings_fields( 'kop_inv' ); ?>
        
        <table class="form-table">
            <tr>
                <th>Nombre del negocio</th>
                <td>
                    <input class="regular-text" name="kop_inv_opts[business]" value="<?php echo esc_attr( kop_inv_opt( 'business' ) ); ?>">
                </td>
            </tr>

            <tr>
                <th>Logo del recibo</th>
                <td>
                    <input class="large-text" name="kop_inv_opts[logo_url]" value="<?php echo esc_attr( kop_inv_opt( 'logo_url' ) ); ?>" placeholder="/wp-content/uploads/2026/10/logo.png">
                    <p class="description">Pegá la URL de la imagen (Medios → elegí la imagen → "Copiar URL").</p>
                    <?php if ( kop_inv_opt( 'logo_url' ) ) : ?>
                        <p><img src="<?php echo esc_url( kop_inv_opt( 'logo_url' ) ); ?>" alt="" style="max-height:80px;max-width:240px;"></p>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th>CUIT</th>
                <td>
                    <input class="regular-text" name="kop_inv_opts[tax_id]" value="<?php echo esc_attr( kop_inv_opt( 'tax_id' ) ); ?>">
                </td>
            </tr>
            <tr>
                <th>Ing. Brutos</th>
                <td>
                    <input class="regular-text" name="kop_inv_opts[gross_income_id]" value="<?php echo esc_attr( kop_inv_opt( 'gross_income_id' ) ); ?>">
                </td>
            </tr>
            <tr>
                <th>Condición Fiscal</th>
                <td>
                    <input class="regular-text" name="kop_inv_opts[tax_category]" value="<?php echo esc_attr( kop_inv_opt( 'tax_category' ) ); ?>">
                </td>
            </tr>
            <tr>
                <th>Inicio de actividades</th>
                <td>
                    <input class="regular-text" name="kop_inv_opts[start_date]" value="<?php echo esc_attr( kop_inv_opt( 'start_date' ) ); ?>">
                </td>
            </tr>
            <tr>
                <th>Dirección</th>
                <td>
                    <input class="regular-text" name="kop_inv_opts[address]" value="<?php echo esc_attr( kop_inv_opt( 'address' ) ); ?>">
                </td>
            </tr>
            <tr>
                <th>Teléfono</th>
                <td>
                    <input class="regular-text" name="kop_inv_opts[phone]" value="<?php echo esc_attr( kop_inv_opt( 'phone' ) ); ?>">
                </td>
            </tr>
            <tr>
                <th>Punto de venta</th>
                <td>
                    <input type="number" min="1" name="kop_inv_opts[pos_number]" value="<?php echo esc_attr( kop_inv_opt( 'pos_number' ) ); ?>">
                </td>
            </tr>
            <tr>
                <th>Formas de pago</th>
                <td>
                    <textarea name="kop_inv_opts[payment_methods]" rows="5" cols="40"><?php echo esc_textarea( kop_inv_opt( 'payment_methods' ) ); ?></textarea>
                    <p class="description">Una por línea: <code>Nombre|porcentaje</code>. Positivo = recargo, negativo = descuento. Ej: <code>Efectivo|-10</code></p>
                </td>
            </tr>
            
            <tr>
              <th>Estado que dispara la factura</th>
            <td>
                <select name="kop_inv_opts[invoice_status]">
                    <option value="completed" <?php selected( kop_inv_opt( 'invoice_status' ), 'completed' ); ?>>Completado</option>
                    <option value="processing" <?php selected( kop_inv_opt( 'invoice_status' ), 'processing' ); ?>>Procesando</option>
                </select>
                <p class="description">Tiene que coincidir con el estado en el que CRPlugins emite la factura.</p>
            </td>
            </tr>
            <tr>
                <th>Campo de DNI/CUIT (CRPlugins)</th>
                <td>
                    <input class="regular-text" name="kop_inv_opts[crp_tax_id_key]" value="<?php echo esc_attr( kop_inv_opt( 'crp_tax_id_key' ) ); ?>">
                    <p class="description">Nombre del campo del pedido donde CRPlugins lee el documento del cliente.</p>
                </td>
            </tr>

        </table>
        
        <?php submit_button(); ?>
    </form>
</div>