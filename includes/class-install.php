<?php
/**
 * Installation: creates the database tables on plugin activation.
 */
 
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
 
class KOP_Install {
 
    // Raise this number every time a table changes (new column or table).
    const DB_VERSION = '6';
 
    /**
     * Runs on every load but only does work when the tables are older than this code.
     * Replacing the plugin files is enough: no need to deactivate and reactivate.
     */
    public static function maybe_upgrade() {
        if ( get_option( 'kop_inv_db_version' ) !== self::DB_VERSION ) {
            self::activate();
        }
    }
 
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset  = $wpdb->get_charset_collate();
        $previous = get_option( 'kop_inv_db_version' ); // false on a fresh install.
 
        dbDelta( "CREATE TABLE {$wpdb->prefix}kop_sales (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        number varchar(40) NOT NULL DEFAULT '',
        type varchar(5) NOT NULL DEFAULT 'X',
        created_at datetime NOT NULL,
        customer_name varchar(200) NOT NULL DEFAULT 'Consumidor Final',
        customer_tax_id varchar(40) NOT NULL DEFAULT '',
        customer_phone varchar(50) NOT NULL DEFAULT '',
        seller_id bigint(20) unsigned NOT NULL DEFAULT 0,
        subtotal decimal(14,2) NOT NULL DEFAULT 0,
        discount decimal(14,2) NOT NULL DEFAULT 0,
        payment_adjustment decimal(14,2) NOT NULL DEFAULT 0,
        total decimal(14,2) NOT NULL DEFAULT 0,
        payment_method varchar(80) NOT NULL DEFAULT '',
        status varchar(15) NOT NULL DEFAULT 'issued',
        wc_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
        notes text NULL,
        token varchar(64) NULL DEFAULT NULL,
        cash_received decimal(14,2) NOT NULL DEFAULT 0,
        change_amount decimal(14,2) NOT NULL DEFAULT 0,
        invoice_status varchar(15) NOT NULL DEFAULT 'none',
        invoice_attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
        invoice_attempt_at datetime NULL,
        invoice_error text NULL,
        invoice_cae varchar(20) NOT NULL DEFAULT '',
        invoice_cae_due varchar(10) NOT NULL DEFAULT '',
        invoice_number varchar(30) NOT NULL DEFAULT '',
        invoice_response longtext NULL,
        invoiced_at datetime NULL,
        cancelled_at datetime NULL,
        cancelled_by bigint(20) unsigned NOT NULL DEFAULT 0,
        cancel_reason varchar(255) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        UNIQUE KEY token (token),
        KEY created_at (created_at),
        KEY invoice_status (invoice_status),
        KEY type_status (type,status)
    ) $charset;" );
 
        dbDelta( "CREATE TABLE {$wpdb->prefix}kop_sale_items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sale_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  sku varchar(100) NOT NULL DEFAULT '',
  description varchar(255) NOT NULL DEFAULT '',
  quantity int(11) NOT NULL DEFAULT 1,
  price decimal(14,2) NOT NULL DEFAULT 0,
  discount_pct decimal(5,2) NOT NULL DEFAULT 0,
  total decimal(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY sale_id (sale_id)
) $charset;" );
 
        dbDelta( "CREATE TABLE {$wpdb->prefix}kop_sale_payments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sale_id bigint(20) unsigned NOT NULL,
  method varchar(80) NOT NULL DEFAULT '',
  amount decimal(14,2) NOT NULL DEFAULT 0,
  adjustment decimal(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY sale_id (sale_id)
) $charset;" );
 
        // Ventas C anteriores a esta versión (tablas < 5): no sabemos si ARCA las confirmó.
        if ( $previous && version_compare( (string) $previous, '5', '<' ) ) {
            $wpdb->query( "UPDATE {$wpdb->prefix}kop_sales SET invoice_status = 'unverified' WHERE type = 'C' AND status = 'issued' AND wc_order_id > 0" );
            $wpdb->query( "UPDATE {$wpdb->prefix}kop_sales SET invoice_status = 'error', invoice_error = 'Venta anterior a esta versión: no se creó el pedido.' WHERE type = 'C' AND status = 'issued' AND wc_order_id = 0" );
        }
 
        // Automatic invoice retries were removed (they could issue duplicate invoices): drop events already scheduled.
        wp_unschedule_hook( 'kop_inv_retry_invoice' );

        update_option( 'kop_inv_db_version', self::DB_VERSION );
    }
}
