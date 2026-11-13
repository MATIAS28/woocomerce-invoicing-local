<?php
/**
 * Fired when the plugin is uninstalled.
 * Deletes all custom database tables and options created by Kings of PC - Invoicing.
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. Eliminar las tablas personalizadas del plugin
$tables = array(
	"{$wpdb->prefix}kop_sales",
	"{$wpdb->prefix}kop_sale_items",
	"{$wpdb->prefix}kop_sale_payments",
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

// 2. Eliminar las opciones de configuración de la tabla wp_options
delete_option( 'kop_inv_opts' );
delete_option( 'kop_inv_db_version' );

// 3. Opcional: Limpiar metadatos vinculados a las órdenes de WooCommerce
// Borra las referencias de metadatos '_kop_sale_id' creadas en los pedidos
$wpdb->query( "DELETE FROM {$wpdb->prefix}postmeta WHERE meta_key IN ('_kop_sale_id', '_kop_sale_number', '_kop_customer_tax_id')" );

// Si tu sitio usa la estructura HPOS de WooCommerce (Tablas de órdenes personalizadas)
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wc_orders_meta'" ) ) {
	$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key IN ('_kop_sale_id', '_kop_sale_number', '_kop_customer_tax_id')" );
}