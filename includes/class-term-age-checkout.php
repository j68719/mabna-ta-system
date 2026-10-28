<?php
defined( 'ABSPATH' ) || exit;

/** الزام تأیید بالای ۱۷ سال برای خرید محصولات ترم‌دار */
class TA_Term_Age_Checkout {
    public function __construct() {
        add_filter( 'woocommerce_checkout_fields', [ $this, 'add_field' ], 30 );
        add_filter( 'woocommerce_checkout_required_field_notice', [ $this, 'required_field_notice' ], 10, 3 );
        add_action( 'woocommerce_checkout_process', [ $this, 'validate' ] );
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_order_meta' ], 20, 2 );
    }

    public function has_term_product(): bool {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return false;
        foreach ( WC()->cart->get_cart() as $item ) {
            $product_id = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) $item['product_id'];
            if ( $product_id && class_exists( 'TA_Product_Meta' ) && TA_Product_Meta::get_settings( $product_id ) ) return true;
            if ( ! empty( $item['product_id'] ) && class_exists( 'TA_Product_Meta' ) && TA_Product_Meta::get_settings( (int) $item['product_id'] ) ) return true;
        }
        return false;
    }

    public function add_field( array $fields ): array {
        if ( ! $this->has_term_product() ) return $fields;
        $fields['billing']['ta_age_confirmation'] = [
            'type'     => 'checkbox',
            'label'    => 'من بیشتر از ۱۷ سال سن دارم.',
            'description' => 'یک گزینه الزامی است.',
            'required' => true,
            'priority' => 115,
            'class'    => [ 'form-row-wide', 'ta-age-confirmation-field' ],
            'clear'    => true,
        ];
        return $fields;
    }

    public function required_field_notice( string $notice, string $field_label, string $field_key ): string {
        if ( in_array( $field_key, [ 'ta_age_confirmation', 'billing_ta_age_confirmation' ], true ) ) {
            $notice = str_replace( [ 'صورتحساب', 'صورت‌حساب' ], 'گزینه‌ی', $notice );
        }
        return $notice;
    }

    public function validate(): void {
        if ( $this->has_term_product() && empty( $_POST['ta_age_confirmation'] ) ) {
            wc_add_notice( 'برای ادامه ثبت نام، تأیید کنید که بیشتر از ۱۷ سال دارید.', 'error' );
        }
    }

    public function save_order_meta( WC_Order $order, array $data ): void {
        if ( $this->has_term_product() && ! empty( $data['ta_age_confirmation'] ) ) {
            $order->update_meta_data( '_ta_age_confirmation', 'yes' );
            $order->update_meta_data( 'تأیید سن خریدار', 'خریدار اعلام کرده است که بیشتر از ۱۷ سال سن دارد.' );
        }
    }
}
