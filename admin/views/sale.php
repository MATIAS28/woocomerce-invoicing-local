<?php
/**
 * View: new sale screen.
 * Variables: $user (WP_User), $payments (array)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap kop-wrap">
	<h1><span class="dashicons dashicons-cart"></span> Venta</h1>
	<div id="kop-notice" class="notice" style="display:none"></div>

	<div class="kop-grid">
		<div class="kop-main">
			<div class="kop-card kop-header">
				
				<label>Cliente
					<input type="text" id="kop-customer" value="Consumidor Final">
				</label>

				<label>CUIT / DNI
					<input type="text" id="kop-tax-id" placeholder="Opcional">
				</label>

				<label>WhatsApp
					<input type="text" id="kop-phone" placeholder="Ej: 2642642642" inputmode="tel">
				</label>

				<label>Factura tipo
					<select id="kop-type">
						<option value="X">X</option>
						<option value="C">C</option>
					</select>
				</label>
				<label>Vendedor
					<input type="text" value="<?php echo esc_attr( $user->display_name ); ?>" readonly>
				</label>
			</div>

			<div class="kop-card">
				<div class="kop-search">
					<div class="kop-q-wrap">
						<input type="text" id="kop-q" placeholder="Escanear código (SKU) o buscar producto…" autocomplete="off" autofocus>
						<ul id="kop-results" class="kop-results" style="display:none"></ul>
					</div>
				</div>

				<table class="widefat kop-items" id="kop-items">
					<thead>
						<tr>
							<th>Código</th><th>Descripción</th><th style="width:80px">Cant.</th>
							<th style="width:130px">Precio</th><th style="width:90px">Bonif. %</th>
							<th style="width:130px">Total</th><th style="width:40px"></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
				<p class="kop-empty" id="kop-empty">Todavía no agregaste productos.</p>

				<div class="kop-footer">
					<span>Productos: <b id="kop-count-products">0</b></span>
					<span>Unidades: <b id="kop-count-units">0</b></span>
					<button type="button" class="button" id="kop-clear">Vaciar carrito</button>
				</div>
			</div>
		</div>

		<aside class="kop-side">
			<div class="kop-card">
				<h2>Resumen de venta</h2>
				<div class="kop-row"><span>Subtotal</span><b id="kop-subtotal">$ 0,00</b></div>
				<div class="kop-row"><span>Descuento $</span>
					<input type="number" id="kop-discount" value="0" min="0" step="0.01">
				</div>
				<div class="kop-row"><span>Ajuste forma de pago</span><b id="kop-adjustment">$ 0,00</b></div>
				<div class="kop-total-box"><span>TOTAL</span><b id="kop-total">$ 0,00</b></div>
			</div>

			<div class="kop-card">
				<h2>Formas de pago</h2>
				<?php foreach ( $payments as $method ) : ?>
					<div class="kop-pay-row">
						<label>
							<?php echo esc_html( $method['name'] ); ?>
						</label>
						<input type="number" class="kop-pay-amount" min="0" step="0.01" value="0"
							data-name="<?php echo esc_attr( $method['name'] ); ?>"
							data-pct="<?php echo esc_attr( $method['pct'] ); ?>"/>
						<span class="kop-pay-due"></span>
					</div>
				<?php endforeach; ?>
				<div class="kop-row"><span>Pagado</span><b id="kop-paid">$ 0,00</b></div>
				<div class="kop-row"><span id="kop-missing-label">Falta</span><b id="kop-missing">$ 0,00</b></div>
				<label class="kop-notes-field">Notas
					<textarea id="kop-notes" rows="2"></textarea>
				</label>
				<button type="button" class="button button-primary button-hero kop-save-btn" id="kop-save">Guardar venta (F6)</button>
			</div>
		</aside>
	</div>
</div>
