<?php
/**
 * Plugin settings (business data, point of sale number, payment methods).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KOP_Settings {

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'register' ) );
    }

    public static function register() {
        register_setting( 'kop_inv', 'kop_inv_opts', array(
            'sanitize_callback' => array( __CLASS__, 'sanitize' ),
        ) );
    }

    public static function sanitize( $in ) {
        $in = is_array( $in ) ? $in : array();
        return array(
            'business'        => sanitize_text_field( isset( $in['business'] ) ? $in['business'] : '' ),
            'tax_id'          => sanitize_text_field( isset( $in['tax_id'] ) ? $in['tax_id'] : '' ),
            'gross_income_id' => sanitize_text_field( isset( $in['gross_income_id'] ) ? $in['gross_income_id'] : '' ),
            'tax_category'    => sanitize_text_field( isset( $in['tax_category'] ) ? $in['tax_category'] : '' ),
            'start_date'      => sanitize_text_field( isset( $in['start_date'] ) ? $in['start_date'] : '' ),
            'address'         => sanitize_text_field( isset( $in['address'] ) ? $in['address'] : '' ),
            'phone'           => sanitize_text_field( isset( $in['phone'] ) ? $in['phone'] : '' ),
            'pos_number'      => max( 1, absint( isset( $in['pos_number'] ) ? $in['pos_number'] : 1 ) ),
            'payment_methods' => sanitize_textarea_field( isset( $in['payment_methods'] ) ? $in['payment_methods'] : '' ),
            'footer'          => sanitize_text_field( isset( $in['footer'] ) ? $in['footer'] : '' ),
            'logo_url'        => esc_url_raw( isset( $in['logo_url'] ) ? $in['logo_url'] : '' ),
            'invoice_status'  => ( isset( $in['invoice_status'] ) && in_array( $in['invoice_status'], array( 'processing', 'completed' ), true ) ) ? $in['invoice_status'] : 'completed',
            'crp_tax_id_key'  => sanitize_text_field( isset( $in['crp_tax_id_key'] ) ? $in['crp_tax_id_key'] : '' ),
        );
    }

    public static function page() {
        include KOP_INV_DIR . 'admin/views/settings.php';
    }
}