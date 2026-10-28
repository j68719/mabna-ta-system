<?php
/**
 * مدیریت «اجازه انتخاب استادیار» روی آیتم‌های سفارش WooCommerce
 *
 * ذخیره‌سازی: order item meta با کلید _ta_allowed (مقدار 1 یا 0)
 * دوره‌های واجد شرایط: intro, advanced, professional, ladder
 * ذخیره: AJAX مستقیم روی تغییر چک‌باکس (بدون نیاز به save سفارش)
 */

defined( 'ABSPATH' ) || exit;

class TA_Order_Allowed {

    public const ALLOWED_COURSE_TYPES = [ 'intro', 'advanced', 'professional', 'ladder' ];

    public function __construct() {
        add_action( 'woocommerce_after_order_itemmeta', [ $this, 'render_order_item_checkbox' ], 10, 3 );
        add_action( 'wp_ajax_ta_toggle_order_allowed',  [ $this, 'ajax_toggle' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // API عمومی
    // ─────────────────────────────────────────────────────────────────────────

    public static function is_allowed( int $order_id, int $product_id ): bool {
        $item_id = self::get_item_id( $order_id, $product_id );
        if ( ! $item_id ) return false;
        return (bool) wc_get_order_item_meta( $item_id, '_ta_allowed', true );
    }

    public static function get_item_id( int $order_id, int $product_id ): int {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return 0;
        foreach ( $order->get_items() as $item_id => $item ) {
            if ( (int) $item->get_product_id() === $product_id ) {
                return (int) $item_id;
            }
        }
        return 0;
    }

    public static function course_type_eligible( string $course_type ): bool {
        return in_array( $course_type, self::ALLOWED_COURSE_TYPES, true );
    }

    /**
     * بررسی مستقیم از دیتابیس — بدون نیاز به find_order_id جداگانه
     * آیا هنرجو برای این محصول مجوز انتخاب مستقیم دارد؟
     */
    public static function is_allowed_for_student( int $student_id, int $product_id ): bool {
        global $wpdb;

        $count = $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*)
            FROM   {$wpdb->prefix}woocommerce_order_items        AS oi
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim_product
                   ON  oim_product.order_item_id = oi.order_item_id
                   AND oim_product.meta_key      = '_product_id'
                   AND oim_product.meta_value    = %d
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim_allowed
                   ON  oim_allowed.order_item_id = oi.order_item_id
                   AND oim_allowed.meta_key      = '_ta_allowed'
                   AND oim_allowed.meta_value    = '1'
            INNER JOIN {$wpdb->postmeta} AS pm
                   ON  pm.post_id    = oi.order_id
                   AND pm.meta_key   = '_customer_user'
                   AND pm.meta_value = %d
            INNER JOIN {$wpdb->posts} AS p
                   ON  p.ID          = oi.order_id
                   AND p.post_type   = 'shop_order'
                   AND p.post_status IN ('wc-completed','wc-processing','wc-partial-payment')
            LIMIT 1
        ", $product_id, $student_id ) );

        return (int) $count > 0;
    }

    public static function find_order_id( int $student_id, int $product_id ): int {
        global $wpdb;

        $order_id = $wpdb->get_var( $wpdb->prepare( "
            SELECT oi.order_id
            FROM   {$wpdb->prefix}woocommerce_order_items        AS oi
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim_product
                   ON  oim_product.order_item_id = oi.order_item_id
                   AND oim_product.meta_key      = '_product_id'
                   AND oim_product.meta_value    = %d
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim_allowed
                   ON  oim_allowed.order_item_id = oi.order_item_id
                   AND oim_allowed.meta_key      = '_ta_allowed'
                   AND oim_allowed.meta_value    = '1'
            INNER JOIN {$wpdb->postmeta} AS pm
                   ON  pm.post_id    = oi.order_id
                   AND pm.meta_key   = '_customer_user'
                   AND pm.meta_value = %d
            INNER JOIN {$wpdb->posts} AS p
                   ON  p.ID          = oi.order_id
                   AND p.post_type   = 'shop_order'
                   AND p.post_status IN ('wc-completed','wc-processing','wc-partial-payment')
            ORDER BY oi.order_id DESC
            LIMIT 1
        ", $product_id, $student_id ) );

        return (int) $order_id;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Hook: نمایش چک‌باکس با ذخیره AJAX مستقیم
    // ─────────────────────────────────────────────────────────────────────────

    public function render_order_item_checkbox( int $item_id, $item, $product ): void {
        if ( ! $product instanceof WC_Product ) return;

        $product_id = (int) $product->get_id();
        $settings   = TA_Eligibility::get_product_settings( $product_id );
        if ( ! $settings || ! self::course_type_eligible( $settings->course_type ) ) return;

        $is_allowed = (bool) wc_get_order_item_meta( $item_id, '_ta_allowed', true );
        $label      = self::get_label( $settings->course_type );
        $nonce      = wp_create_nonce( 'ta_toggle_order_allowed_' . $item_id );
        ?>
        <div class="ta-order-item-allowed" style="margin-top:8px;padding:6px 10px;background:#f0f6ff;border:1px solid #b3d4fb;border-radius:4px;font-size:13px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500;margin:0;">
                <input type="checkbox"
                       class="ta-allowed-checkbox"
                       data-item-id="<?= $item_id ?>"
                       data-nonce="<?= esc_attr( $nonce ) ?>"
                       <?php checked( $is_allowed ); ?>
                       style="width:15px;height:15px;margin:0;">
                🎓 <?= esc_html( $label ) ?>
                <span class="ta-allowed-status" style="font-size:11px;font-weight:normal;color:#666;"></span>
            </label>
        </div>
        <script>
        (function(){
            var allInits = window._taOrderAllowedInited = window._taOrderAllowedInited || false;
            if (!allInits) {
                window._taOrderAllowedInited = true;
                document.addEventListener('change', function(e) {
                    var cb = e.target.closest('.ta-allowed-checkbox');
                    if (!cb) return;

                    var itemId  = cb.dataset.itemId;
                    var nonce   = cb.dataset.nonce;
                    var value   = cb.checked ? 1 : 0;
                    var statusEl = cb.closest('.ta-order-item-allowed').querySelector('.ta-allowed-status');

                    statusEl.textContent = '⏳ در حال ذخیره...';
                    statusEl.style.color = '#999';

                    var fd = new FormData();
                    fd.append('action',  'ta_toggle_order_allowed');
                    fd.append('item_id', itemId);
                    fd.append('value',   value);
                    fd.append('nonce',   nonce);

                    fetch(ajaxurl, { method: 'POST', body: fd })
                        .then(function(r){ return r.json(); })
                        .then(function(res) {
                            if (res.success) {
                                statusEl.textContent = value ? '✅ ذخیره شد' : '✅ برداشته شد';
                                statusEl.style.color = '#1a7f37';
                            } else {
                                statusEl.textContent = '❌ ' + (res.data?.message || 'خطا در ذخیره');
                                statusEl.style.color = '#d63638';
                                cb.checked = !cb.checked; // برگشت به حالت قبل
                            }
                            setTimeout(function(){ statusEl.textContent = ''; }, 3000);
                        })
                        .catch(function() {
                            statusEl.textContent = '❌ خطا در ارتباط با سرور';
                            statusEl.style.color = '#d63638';
                            cb.checked = !cb.checked;
                        });
                });
            }
        })();
        </script>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX: ذخیره مستقیم مقدار چک‌باکس
    // ─────────────────────────────────────────────────────────────────────────

    public function ajax_toggle(): void {
        $item_id = absint( $_POST['item_id'] ?? 0 );
        $value   = absint( $_POST['value']   ?? 0 );

        if ( ! $item_id ) {
            wp_send_json_error( [ 'message' => 'item_id نامعتبر.' ] );
        }

        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'ta_toggle_order_allowed_' . $item_id ) ) {
            wp_send_json_error( [ 'message' => 'خطای امنیتی.' ] );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی کافی ندارید.' ] );
        }

        wc_update_order_item_meta( $item_id, '_ta_allowed', $value ? 1 : 0 );
        wp_send_json_success( [ 'value' => $value ] );
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function get_label( string $course_type ): string {
        return [
            'intro'        => 'تعیین سطح شده — اجازه انتخاب مستقیم استادیار (مقدماتی)',
            'advanced'     => 'تعیین سطح شده — اجازه انتخاب مستقیم استادیار (پیشرفته)',
            'professional' => 'تعیین سطح شده — اجازه انتخاب مستقیم استادیار (حرفه‌ای)',
            'ladder'       => 'تعیین سطح شده — اجازه انتخاب مستقیم استادیار (نردبان)',
        ][ $course_type ] ?? 'اجازه انتخاب مستقیم استادیار';
    }
}
