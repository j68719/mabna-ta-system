<?php
defined( 'ABSPATH' ) || exit;

/**
 * صفحه عمومی سفارش نقد آثار
 * شورت‌کد: [ta_critique_order]
 *
 * ۳ گام: انتخاب تخصص/استاد تسهیلگر (آکاردئون) → آپلود اثر و محاسبه قیمت → تسویه‌حساب مختصر
 * + نمای «سفارش‌های من» و فرم «ثبت ارزیابی» بعد از تکمیل نقد.
 */
class TA_Critique_Order {

    public function __construct() {
        add_shortcode( 'ta_critique_order', [ $this, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );

        add_action( 'wp_ajax_ta_co_price',       [ $this, 'ajax_price' ] );
        add_action( 'wp_ajax_ta_co_place_order', [ $this, 'ajax_place_order' ] );
        add_action( 'wp_ajax_ta_co_submit_eval', [ $this, 'ajax_submit_eval' ] );
        add_action( 'wp_ajax_ta_co_student_complete', [ $this, 'ajax_student_complete' ] );

        add_action( 'woocommerce_payment_complete', [ $this, 'on_payment_complete' ] );
        // برخی درگاه‌ها به‌جای payment_complete مستقیم وضعیت را processing/completed می‌کنند
        add_action( 'woocommerce_order_status_processing', [ $this, 'on_payment_complete' ] );
        add_action( 'woocommerce_order_status_completed',  [ $this, 'on_payment_complete' ] );
        // وضعیت اختصاصیِ افزونهٔ پرداخت قسطی (wc-partial-payment) — بیعانه پرداخت شده
        add_action( 'woocommerce_order_status_partial-payment', [ $this, 'on_payment_complete' ] );

        // ── لغو/مرجوع‌شدن سفارش بعد از پردازش: برگرداندن ظرفیت استاد تسهیلگر ─────────
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'on_order_cancelled_or_refunded' ] );
        add_action( 'woocommerce_order_status_refunded',  [ $this, 'on_order_cancelled_or_refunded' ] );
        add_action( 'woocommerce_order_status_failed',    [ $this, 'on_order_cancelled_or_refunded' ] );

        // ── مسیر عادیِ سبد خرید/تسویه‌حساب برای آیتم‌های نقد آثار ──────────────
        add_filter( 'woocommerce_before_calculate_totals', [ $this, 'apply_cart_item_price' ] );
        add_filter( 'woocommerce_get_item_data',           [ $this, 'show_cart_item_data' ], 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_order_line_item_meta' ], 10, 4 );
        // [FIX-PENDING-ORDER] قبلاً create_critique_order_rows() به هوک
        // woocommerce_checkout_order_processed وصل بود و بلافاصله بعد از ثبت
        // سفارش (حتی قبل از پرداخت / در وضعیت pending) ردیف سفارش نقد اثر
        // ساخته می‌شد. حالا این کار فقط داخل on_payment_complete() و فقط برای
        // سفارش‌های واقعاً پرداخت‌شده (processing/completed/partial-payment)
        // انجام می‌شود؛ سفارش‌های در انتظار پرداخت هرگز سفارش نقد نمی‌سازند.
        add_action( 'woocommerce_check_cart_items',         [ $this, 'validate_cart_capacity' ] );
        add_filter( 'woocommerce_checkout_get_value',       [ $this, 'prefill_checkout_field' ], 10, 2 );

        // پیامک خودکار افزونهٔ Persian WooCommerce SMS Pro برای سفارش‌های نقد آثار
        // ارسال نشود؛ پیامک اختصاصی نقد آثار در مسیر خودش مدیریت می‌شود.
        add_filter( 'pwoosms_buyer_can_get_order_sms', [ $this, 'disable_external_sms_for_critique_order' ], 10, 3 );

        // ── یادآوری SLA به استاد تسهیلگر برای سفارش‌های راکد ─────────────────────────
        add_action( 'ta_co_sla_reminder_cron', [ $this, 'run_sla_reminder_check' ] );
        if ( ! wp_next_scheduled( 'ta_co_sla_reminder_cron' ) ) {
            wp_schedule_event( time() + 300, 'daily', 'ta_co_sla_reminder_cron' );
        }
    }

    /** چک روزانهٔ چهارگانهٔ SLA؛ یادآوری در انتظار می‌تواند یک‌باره یا دوره‌ای باشد. */
    public function run_sla_reminder_check(): void {
        $types = [ 'sla_pending', 'sla_in_progress', 'sla_delivered', 'sla_completed' ];
        if ( ! array_filter( $types, [ TA_MeliPayamak::class, 'enabled' ] ) ) return;

        $co_table = TA_Database::table( 'critique_orders' );
        $sc_table = TA_Database::table( 'story_critique_forms' );
        global $wpdb;
        $rows = TA_Database::get_results( "SELECT * FROM {$co_table} WHERE status IN ('pending','in_progress','delivered','completed')" );
        foreach ( $rows as $row ) {
            $ta      = TA_Database::get_row( "SELECT full_name, phone FROM {$sc_table} WHERE id = %d", [ $row->ta_id ] );
            $student = get_userdata( (int) $row->student_id );
            if ( ! $ta || ! $ta->phone || ! $student ) continue;
            $wc_order    = wc_get_order( (int) $row->wc_order_id );
            $order_number = $wc_order ? (string) $wc_order->get_order_number() : (string) $row->wc_order_id;

            if ( $row->status === 'pending' && TA_MeliPayamak::enabled( 'sla_pending' ) ) {
                $pending_once = get_option( 'ta_sc_sla_pending_once', '0' ) === '1';
                if ( $pending_once && (int) $row->sla_pending_reminder_sent ) {
                    continue;
                }
                $active = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$co_table} WHERE ta_id = %d AND status = 'in_progress'",
                    (int) $row->ta_id
                ) );
                if ( $active > 0 ) {
                    if ( $row->sla_pending_eligible_at || $row->sla_pending_last_sent_at ) {
                        $wpdb->update( $co_table, [
                            'sla_pending_eligible_at' => null,
                            'sla_pending_last_sent_at' => null,
                        ], [ 'id' => $row->id ] );
                    }
                    continue;
                }
                if ( ! $row->sla_pending_eligible_at ) {
                    $wpdb->update( $co_table, [ 'sla_pending_eligible_at' => current_time( 'mysql' ) ], [ 'id' => $row->id ] );
                    continue;
                }
                $days = max( 1, (int) get_option( 'ta_sc_sla_pending_days', get_option( 'ta_sc_sla_reminder_days', 3 ) ) );
                $anchor = ( ! $pending_once && ! empty( $row->sla_pending_last_sent_at ) )
                    ? $row->sla_pending_last_sent_at
                    : $row->sla_pending_eligible_at;
                if ( $this->sla_elapsed_days( $anchor ) >= $days && $this->send_sla_ta( 'sla_pending', $row, $ta, $student, $order_number, $days ) ) {
                    $wpdb->update( $co_table, [
                        'sla_pending_reminder_sent' => $pending_once ? 1 : 0,
                        'sla_reminder_sent'         => $pending_once ? 1 : 0,
                        'sla_pending_last_sent_at'  => current_time( 'mysql' ),
                    ], [ 'id' => $row->id ] );
                }
                continue;
            }

            if ( $row->status === 'in_progress' && ! (int) $row->sla_in_progress_reminder_sent && TA_MeliPayamak::enabled( 'sla_in_progress' ) ) {
                $days = max( 1, (int) get_option( 'ta_sc_sla_in_progress_days', 3 ) );
                $started_at = $row->in_progress_at ?: $row->created_at;
                if ( $this->sla_elapsed_days( $started_at ) >= $days && $this->send_sla_ta( 'sla_in_progress', $row, $ta, $student, $order_number, $days ) ) {
                    $wpdb->update( $co_table, [ 'sla_in_progress_reminder_sent' => 1 ], [ 'id' => $row->id ] );
                }
                continue;
            }

            if ( $row->status === 'delivered' && ! (int) $row->sla_delivered_reminder_sent && TA_MeliPayamak::enabled( 'sla_delivered' ) ) {
                $student_eval_table = TA_Database::table( 'student_eval_responses' );
                $has_ta_evaluation = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$student_eval_table} WHERE critique_order_id = %d", (int) $row->id ) );
                $days = max( 1, (int) get_option( 'ta_sc_sla_delivered_days', 3 ) );
                if ( ! $has_ta_evaluation && $this->sla_elapsed_days( $row->delivered_at ?: $row->created_at ) >= $days && $this->send_sla_ta( 'sla_delivered', $row, $ta, $student, $order_number, $days ) ) {
                    $wpdb->update( $co_table, [ 'sla_delivered_reminder_sent' => 1 ], [ 'id' => $row->id ] );
                }
                continue;
            }

            if ( $row->status === 'completed' && ! (int) $row->sla_completed_reminder_sent && TA_MeliPayamak::enabled( 'sla_completed' ) ) {
                $student_eval_table = TA_Database::table( 'eval_responses' );
                $has_student_evaluation = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$student_eval_table} WHERE critique_order_id = %d", (int) $row->id ) );
                $days = max( 1, (int) get_option( 'ta_sc_sla_completed_days', 3 ) );
                if ( ! $has_student_evaluation && $this->sla_elapsed_days( $row->completed_at ?: $row->created_at ) >= $days && $this->send_sla_student( 'sla_completed', $row, $ta, $student, $order_number, $days ) ) {
                    $wpdb->update( $co_table, [ 'sla_completed_reminder_sent' => 1 ], [ 'id' => $row->id ] );
                }
            }
        }
    }

    private function sla_elapsed_days( string $started_at ): int {
        $started = strtotime( $started_at );
        return $started ? max( 0, (int) floor( ( current_time( 'timestamp' ) - $started ) / DAY_IN_SECONDS ) ) : 0;
    }

    private function send_sla_ta( string $type, object $row, object $ta, WP_User $student, string $order_number, int $days ): bool {
        TA_MeliPayamak::set_log_context( [
            'critique_order_id' => (int) $row->id,
            'wc_order_id'       => (int) $row->wc_order_id,
            'recipient_role'    => 'ta',
            'recipient_name'    => (string) $ta->full_name,
        ] );
        $result = TA_MeliPayamak::notify_sla( $type, (string) $ta->phone, (string) $ta->full_name, (string) $student->display_name, $order_number, (string) $days );
        TA_MeliPayamak::clear_log_context();
        return ! empty( $result['ok'] );
    }

    private function send_sla_student( string $type, object $row, object $ta, WP_User $student, string $order_number, int $days ): bool {
        $phone = TA_User_Helper::get_phone( (int) $student->ID );
        if ( ! $phone ) return false;
        TA_MeliPayamak::set_log_context( [
            'critique_order_id' => (int) $row->id,
            'wc_order_id'       => (int) $row->wc_order_id,
            'recipient_role'    => 'student',
            'recipient_name'    => (string) $student->display_name,
        ] );
        $result = TA_MeliPayamak::notify_sla( $type, $phone, (string) $student->display_name, (string) $ta->full_name, $order_number, (string) $days );
        TA_MeliPayamak::clear_log_context();
        return ! empty( $result['ok'] );
    }

    /** پرکردن خودکار نام/تلفن صورتحساب در صفحهٔ تسویه‌حساب از روی فرم گام ۳ */
    public function prefill_checkout_field( $value, string $input ) {
        if ( $value || ! in_array( $input, [ 'billing_first_name', 'billing_last_name', 'billing_phone' ], true ) ) {
            return $value;
        }
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return $value;
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( $input === 'billing_phone' && ! empty( $cart_item['ta_co_phone'] ) ) {
                return $cart_item['ta_co_phone'];
            }
            if ( in_array( $input, [ 'billing_first_name', 'billing_last_name' ], true ) && ! empty( $cart_item['ta_co_full_name'] ) ) {
                $parts = explode( ' ', $cart_item['ta_co_full_name'], 2 );
                return $input === 'billing_first_name' ? $parts[0] : ( $parts[1] ?? '' );
            }
        }
        return $value;
    }

    /**
     * جلوگیری از پیامک مشتریِ افزونهٔ Persian WooCommerce SMS Pro برای سفارش نقد آثار.
     * هم شناسهٔ محصول تنظیم‌شده و هم متای آیتم نقد آثار بررسی می‌شود تا در سفارش‌های
     * چندمحصولی یا محصولی که شناسه‌اش تغییر کرده، تشخیص همچنان مطمئن باشد.
     */
    public function disable_external_sms_for_critique_order( $allowed, $order, $new_status ): bool {
        if ( ! $allowed || ! is_object( $order ) || ! is_a( $order, 'WC_Order' ) ) {
            return (bool) $allowed;
        }

        $critique_product_id = absint( get_option( 'ta_sc_product_id', 0 ) );
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( $critique_product_id && (int) $item->get_product_id() === $critique_product_id ) {
                return false;
            }
            if ( $item->get_meta( '_ta_co_ta_id', true ) ) {
                return false;
            }
        }

        return (bool) $allowed;
    }

    public function enqueue(): void {
        global $post;
        if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'ta_critique_order' ) ) {
            wp_enqueue_style( 'ta-system', TA_SYSTEM_URL . 'assets/css/ta-system.css', [], ta_system_asset_ver( 'assets/css/ta-system.css' ) );
            wp_enqueue_script( 'jquery' );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // رندر
    // ─────────────────────────────────────────────────────────────────────────

    public function render_shortcode(): string {
        if ( ! TA_Database::tables_exist() ) {
            return '<div dir="rtl"><p>سیستم در حال راه‌اندازی است.</p></div>';
        }
        if ( ! is_user_logged_in() ) {
            return '<div class="ta-co-wrap" dir="rtl"><p>برای ثبت سفارش نقد آثار ابتدا <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">وارد سایت شوید</a>.</p></div>';
        }

        $view = sanitize_key( $_GET['ta_view'] ?? 'order' );

        ob_start();
        if ( $view === 'my_orders' ) {
            $this->render_my_orders();
        } elseif ( $view === 'evaluate' ) {
            $this->render_evaluate();
        } else {
            $this->render_order_flow();
        }
        return ob_get_clean();
    }

    private function page_url( array $args = [] ): string {
        return esc_url( add_query_arg( $args, get_permalink() ) );
    }

    /**
     * پیدا کردن آدرس صفحه‌ای که شورت‌کد [ta_critique_order] در آن قرار دارد.
     * برای لینک‌دهی از جاهای دیگر سایت (مثلاً از داخل تب «نقد آثار» پیشخوان هنرجو).
     */
    public static function get_page_url( array $args = [] ): string {
        global $wpdb;
        static $page_id = null;
        if ( $page_id === null ) {
            $page_id = (int) $wpdb->get_var(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE '%[ta_critique_order%' LIMIT 1"
            );
        }
        if ( ! $page_id ) {
            return '';
        }
        $url = get_permalink( $page_id );
        // این مقدار گاهی در پاسخ JSON/AJAX استفاده می‌شود؛ esc_url() آمپرسند را
        // به &#038; تبدیل می‌کند و مرورگر در location.href آن را به‌اشتباه #038;
        // تفسیر می‌کند. برای داده خام از esc_url_raw استفاده می‌کنیم و محل HTML
        // خودش با esc_url() آن را escape خواهد کرد.
        return $args ? esc_url_raw( add_query_arg( $args, $url ) ) : esc_url_raw( $url );
    }

    /**
     * آیا سفارش ووکامرس مربوطه پرداخت شده (کامل یا بیعانه/اقساطی) محسوب می‌شود؟
     * وضعیت‌های پذیرفته‌شده دقیقاً همان‌هایی‌اند که در بقیهٔ افزونه هم به‌عنوان
     * «پرداخت‌شده» در نظر گرفته می‌شوند (class-eligibility.php, class-student-portal.php):
     * wc-completed، wc-processing و وضعیت اختصاصیِ افزونهٔ اقساطی wc-partial-payment.
     * سفارش‌هایی که هنوز پرداخت نشده‌اند (pending, failed, cancelled, on-hold,
     * pending-deposit, scheduled-payment و ...) «پرداخت‌شده» محسوب نمی‌شوند.
     */
    public static function order_is_paid( int $wc_order_id ): bool {
        static $cache = [];
        if ( ! $wc_order_id ) return false;
        if ( isset( $cache[ $wc_order_id ] ) ) return $cache[ $wc_order_id ];
        $order = wc_get_order( $wc_order_id );
        $paid  = (bool) ( $order && $order->has_status( [ 'processing', 'completed', 'partial-payment' ] ) );
        return $cache[ $wc_order_id ] = $paid;
    }

    /**
     * آمار صفِ سفارش‌های یک استاد تسهیلگر (به تفکیک وضعیت، به‌جز تکمیل‌شده‌ها و پرداخت‌نشده‌ها).
     * برای نمایش «چقدر طول می‌کشد نوبتش بشود» هم در لحظه خرید و هم در سفارش‌های هنرجو.
     */
    public static function get_ta_queue_stats( int $ta_id ): array {
        $table = TA_Database::table( 'critique_orders' );
        $rows  = TA_Database::get_results(
            "SELECT wc_order_id, status FROM {$table} WHERE ta_id = %d AND status NOT IN ('completed','cancelled')",
            [ $ta_id ]
        );
        $out = [ 'pending' => 0, 'in_progress' => 0, 'delivered' => 0 ];
        foreach ( $rows as $r ) {
            if ( ! self::order_is_paid( (int) $r->wc_order_id ) ) continue; // سفارش پرداخت‌نشده در صف حساب نمی‌شود
            if ( isset( $out[ $r->status ] ) ) {
                $out[ $r->status ]++;
            }
        }
        $out['active_total'] = $out['pending'] + $out['in_progress'] + $out['delivered'];
        return $out;
    }

    /** خروجی HTML یک نشان کوچک برای نمایش وضعیت صف استاد تسهیلگر (نمای کلی — قبل از خرید) */
    public static function queue_badge_html( array $stats ): string {
        if ( empty( $stats['active_total'] ) ) {
            return '<span class="ta-co-queue-badge ta-co-queue-empty">🟢 صف خالی — شروع سریع</span>';
        }
        $parts = [];
        if ( $stats['pending'] )     $parts[] = $stats['pending'] . ' در انتظار';
        if ( $stats['in_progress'] ) $parts[] = $stats['in_progress'] . ' در حال انجام';
        if ( $stats['delivered'] )   $parts[] = $stats['delivered'] . ' تحویل‌شده';
        return '<span class="ta-co-queue-badge">📊 در صف این استاد تسهیلگر: ' . esc_html( implode( ' · ', $parts ) ) . '</span>';
    }

    /**
     * موقعیتِ واقعیِ یک سفارشِ مشخص در صفِ استاد تسهیلگر — یعنی چند سفارشِ فعال دیگر
     * «جلوتر» از این سفارش قرار دارند (بر اساس ترتیب ثبت سفارش، از قدیم به جدید).
     * فقط سفارش‌های پرداخت‌شده و تکمیل‌نشده در این محاسبه شرکت می‌کنند.
     */
    public static function get_queue_position( object $order ): array {
        $table = TA_Database::table( 'critique_orders' );
        $rows  = TA_Database::get_results(
            "SELECT id, wc_order_id, status FROM {$table}
             WHERE ta_id = %d AND status NOT IN ('completed','cancelled')
             ORDER BY created_at ASC, id ASC",
            [ (int) $order->ta_id ]
        );

        $ahead = 0;
        $found = false;
        foreach ( $rows as $r ) {
            if ( ! self::order_is_paid( (int) $r->wc_order_id ) ) continue; // پرداخت‌نشده‌ها اصلاً در صف حساب نمی‌شوند
            if ( (int) $r->id === (int) $order->id ) {
                $found = true;
                continue;
            }
            if ( ! $found ) $ahead++;
        }

        return [ 'ahead' => $ahead, 'position' => $ahead + 1 ];
    }

    /** خروجی HTML نوبتِ یک سفارش مشخص در صف استاد تسهیلگر (برای نمایش در «سفارش‌های من») */
    public static function queue_position_badge_html( array $pos ): string {
        if ( $pos['ahead'] <= 0 ) {
            return '<span class="ta-co-queue-badge ta-co-queue-empty">🟢 نوبت شماست — اولین سفارش در صف</span>';
        }
        return '<span class="ta-co-queue-badge">📊 نوبت شما: نفر ' . (int) $pos['position']
            . ' در صف (' . (int) $pos['ahead'] . ' نفر جلوتر از شما)</span>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // گام‌های سفارش
    // ─────────────────────────────────────────────────────────────────────────

    private function render_order_flow(): void {
        $spec_table = TA_Database::table( 'specialties' );
        $sc_table   = TA_Database::table( 'story_critique_forms' );

        $reorder_parent_id = absint( $_GET['ta_reorder'] ?? 0 );
        $reorder_parent = $reorder_parent_id ? $this->get_valid_reorder_parent( $reorder_parent_id ) : null;
        if ( $reorder_parent_id && ! $reorder_parent ) {
            echo '<div class="ta-co-wrap" dir="rtl"><div class="ta-sc-msg error" style="display:block">این سفارش برای سفارش مجدد در دسترس نیست.</div></div>';
            return;
        }

        $specialties = TA_Database::get_results( "SELECT * FROM {$spec_table} WHERE is_active = 1 ORDER BY sort_order ASC, id ASC" );

        // فقط استاد تسهیلگرانی که پروفایل کامل (قیمت‌گذاری) دارند و ظرفیت باقی‌مانده دارند
        // نکته: «تعداد کلمه پایه» و پله‌های افزایشی اختیاری‌اند — اگر ثبت نشوند
        // محاسبه قیمت (calc_price) به‌سادگی از قیمت پایه استفاده می‌کند، پس
        // نبود آن‌ها نباید باعث حذف استاد تسهیلگر از لیست شود (فقط قیمت‌گذاری پلکانی
        // برایش فعال نمی‌شود).
        $tas = TA_Database::get_results(
            "SELECT * FROM {$sc_table}
             WHERE capacity IS NOT NULL AND capacity > 0
               AND base_price IS NOT NULL
             ORDER BY full_name ASC"
        );

        $tas_by_spec = [];
        foreach ( $tas as $t ) {
            $specs = $t->specialties ? json_decode( $t->specialties, true ) : [];
            $specs = is_array( $specs ) ? $specs : [];
            $t->_user_id  = (int) $t->user_id;
            $t->_avatar   = $this->ta_avatar_url( (int) $t->user_id );
            $t->_queue    = self::get_ta_queue_stats( (int) $t->id );
            foreach ( $specs as $s ) {
                $tas_by_spec[ $s ][] = $t;
            }
        }

        $nonce = wp_create_nonce( 'ta_co_nonce' );
        ?>
        <div class="ta-co-wrap" dir="rtl">
            <?php if ( $reorder_parent ) : ?><div class="ta-sc-msg info" style="display:block;margin-bottom:16px">🔁 سفارش مجدد نسخه بازنویسی‌شده — استاد و تخصص سفارش اصلی انتخاب شده‌اند. تخفیف ۴۰٪ پس از محاسبه قیمت اعمال می‌شود.</div><?php endif; ?>
            <p style="text-align:left"><a href="<?= $this->page_url( [ 'ta_view' => 'my_orders' ] ) ?>" class="ta-btn ta-btn-outline ta-btn-sm">📋 سفارش‌های من</a></p>

            <!-- گام ۱: انتخاب تخصص و استاد تسهیلگر -->
            <div id="ta-co-step-1" class="ta-co-step"<?= $reorder_parent ? ' style="display:none"' : '' ?>>
                <div class="ta-co-eyebrow-row">
                    <p class="ta-co-eyebrow">نقد آثار</p>
                    <span class="ta-co-eyebrow-underline"></span>
                </div>
                <h3 class="ta-co-title ta-co-intro-title">هر جایی از مسیر نویسندگی ایستاده‌ای، می‌توانی یک قدم جلوتر بروی.</h3>
                <p class="ta-co-intro-copy">یک نقد دقیق و اختصاصی می‌تواند به تو نشان بدهد چه چیزی در نوشته‌هایت می‌درخشد، کجاها متوقف شده‌ای و برای قدم بعدی باید چه چیزی را تغییر بدهی.<br>اینجا نوشته‌هایت فقط خوانده نمی‌شوند؛ دقیق دیده می‌شوند.</p>
                <p class="ta-co-intro-copy">مهم نیست ابتدای راهی، در میانه‌ی مسیری یا سال‌هاست که می‌نویسی؛ هر نوشته، جا برای بهتر شدن دارد. اینجا قرار است نوشته‌هایت را دقیق بخوانیم، جدی بگیریم و با نقدی تخصصی، روشن و کاربردی، در مسیر رشد، اثرگذاری و حرفه‌ای‌تر شدن همراهت باشیم.</p>
                <h2 class="ta-co-title">۱. برای درخواست نقد داستان، ژانر و منتقد خود را انتخاب کنید:</h2>

                <?php if ( empty( $specialties ) ) : ?>
                    <div class="ta-co-empty">در حال حاضر تخصصی برای نقد آثار تعریف نشده است.</div>
                <?php endif; ?>

                <div class="ta-co-accordion-list">
                <?php foreach ( $specialties as $s ) :
                    // در سفارش مجدد فقط همان تخصص سفارش اصلی نمایش داده می‌شود.
                    if ( $reorder_parent && (string) $reorder_parent->specialty !== (string) $s->name ) continue;
                    $list = $tas_by_spec[ $s->name ] ?? [];
                    $img  = $s->image_id ? wp_get_attachment_image_url( $s->image_id, 'medium' ) : '';
                ?>
                <div class="ta-co-accordion">
                    <?php if ( $img ) : ?>
                        <span class="ta-co-spec-cover"><img src="<?= esc_url( $img ) ?>" alt=""></span>
                    <?php else : ?>
                        <span class="ta-co-spec-cover ta-co-spec-cover-empty">📖</span>
                    <?php endif; ?>
                    <div class="ta-co-accordion-head" role="button" tabindex="0">
                        <span class="ta-co-accordion-arrow" aria-hidden="true">
                            <svg width="30" height="30" viewBox="0 0 30 30" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="15" cy="15" r="15" transform="rotate(90 15 15)" fill="#594D49"/><path d="M23.6211 19.0003L17.7416 13.4645C16.4425 12.2414 15.7929 11.6298 14.9995 11.6298C14.2062 11.6298 13.5566 12.2414 12.2575 13.4645L6.37799 19.0003" stroke="white" stroke-width="2" stroke-linejoin="round"/></svg>
                        </span>
                        <span class="ta-co-spec-text">
                            <span class="ta-co-spec-name"><?= esc_html( $s->name ) ?></span>
                            <?php if ( $s->description ) : ?><?php endif; ?>
                        </span>
                        <span class="ta-co-spec-desc-inline"><?= esc_html( $s->description ) ?></span>
                    </div>
                    <div class="ta-co-accordion-body" style="display:none">
                        <?php if ( empty( $list ) ) : ?>
                            <p class="ta-co-empty">فعلاً استاد تسهیلگری با ظرفیت باز در این تخصص وجود ندارد.</p>
                        <?php endif; ?>
                        <div class="ta-co-ta-grid">
                            <?php foreach ( $list as $t ) :
                                if ( $reorder_parent && (int) $reorder_parent->ta_id !== (int) $t->id ) continue;
                            ?>
                            <label class="ta-co-ta-card">
                                <input type="checkbox" class="ta-co-ta-check" value="<?= (int) $t->id ?>" data-specialty="<?= esc_attr( $s->name ) ?>" data-name="<?= esc_attr( $t->full_name ) ?>" <?= $reorder_parent && (int) $reorder_parent->ta_id === (int) $t->id && (string) $reorder_parent->specialty === (string) $s->name ? 'checked disabled' : '' ?> >
                                <span class="ta-co-ta-check-mark">✓</span>
                                <div class="ta-co-ta-main">
                                    <span class="ta-co-avatar-wrap">
                                        <img src="<?= esc_url( $t->_avatar ) ?>" class="ta-co-ta-avatar" alt="<?= esc_attr( $t->full_name ) ?>" loading="lazy" decoding="async" fetchpriority="low">
                                        <button type="button" class="ta-co-avatar-expand" aria-label="بزرگ‌نمایی تصویر" title="بزرگ‌نمایی تصویر"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 8.5A2.5 2.5 0 0 1 6.5 6h2l1.2-1.5h4.6L15.5 6h2A2.5 2.5 0 0 1 20 8.5v8A2.5 2.5 0 0 1 17.5 19h-11A2.5 2.5 0 0 1 4 16.5v-8Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12.5" r="3.2" stroke="currentColor" stroke-width="1.8"/></svg></button>
                                    </span>
                                    <div class="ta-co-ta-info">
                                        <strong><?= esc_html( $t->full_name ) ?></strong>
                                        <div class="ta-co-ta-tags">
                                            <span class="ta-co-ta-tag ta-co-ta-role"><?= esc_html( $t->role_title ?: 'نویسنده و تسهیلگر' ) ?></span>
                                            <?= self::queue_badge_html( $t->_queue ) ?>
                                        </div>
                                        <span class="ta-co-ta-price">از <?= number_format( (float) $t->base_price ) ?> تومان<?php if ( ! empty( $t->base_word_count ) ) : ?> (برای <?= number_format( (int) $t->base_word_count ) ?> کلمه)<?php endif; ?></span>
                                        <?php if ( $t->resume ) : ?><p class="ta-co-ta-resume" style="display:none"><?= esc_html( $t->resume ) ?></p><?php endif; ?>
                                    </div>
                                </div>
                                <div class="ta-co-ta-actions">
                                    <?php if ( $t->resume ) : ?>
                                    <button type="button" class="ta-co-ta-more-btn">
                                        اطلاعات بیشتر
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 11v5M12 8v.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                                    </button>
                                    <?php endif; ?>
                                    <span class="ta-co-ta-select-btn">
                                        
                                        انتخاب منتقد
                                        <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16 0C24.8366 0 32 7.16344 32 16C32 24.8366 24.8366 32 16 32C7.16344 32 0 24.8366 0 16C0 7.16344 7.16344 0 16 0ZM6.66797 15.3145C6.30563 15.6995 6.30566 16.3005 6.66797 16.6855L15.3271 25.8818L16.0557 25.1963L16.7832 24.5107L9.71191 17H24.6025V15H9.71191L16.7832 7.48926L16.0547 6.80371L15.3271 6.11816L6.66797 15.3145Z" fill="white"/></svg>
                                    </span>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>

                <div class="ta-co-selected-bar" id="ta-co-selected-bar" style="display:none">
                    <span id="ta-co-selected-count">۰ استاد تسهیلگر انتخاب شد</span>
                    <button type="button" class="ta-btn ta-btn-primary" id="ta-co-goto-step2">مرحله بعد ←</button>
                </div>
            </div>



            <!-- گام ۲: آپلود اثر و محاسبه قیمت -->
            <div id="ta-co-step-2" class="ta-co-step"<?= $reorder_parent ? ' style="display:block"' : ' style="display:none"' ?>>
                <div class="ta-co-eyebrow-row">
                    <p class="ta-co-eyebrow">نقد اثر</p>
                    <span class="ta-co-eyebrow-underline"></span>
                </div>
                <h2 class="ta-co-title">۲. آپلود فایل اثر</h2>

                <div class="ta-co-card">
                    <div class="ta-co-card-section">
                        <p class="ta-co-card-heading">بارگذاری اثر</p>
                        <div class="ta-co-upload-box">
                            <label class="ta-co-upload-pill" for="ta-co-file">
                                <svg width="16" height="15" viewBox="0 0 16 15" fill="none"><path d="M8 14V1M8 1L2 7M8 1l6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <span id="ta-co-file-label">بارگذاری اثر</span>
                            </label>
                            <input type="file" id="ta-co-file" accept=".docx" style="display:none">
                            <button type="button" class="ta-btn ta-btn-primary" id="ta-co-calc-btn">محاسبه قیمت</button>
                        </div>
                        <p class="ta-co-hint">حتماً فایل را با فرمت docx. (نرم‌افزار Word) بارگذاری کنید.</p>
                    </div>
                </div>

                <div id="ta-co-price-msg" class="ta-sc-msg" style="display:none"></div>

                <div id="ta-co-price-result" class="ta-co-card" style="display:none">
                    <div class="ta-co-card-section">
                        <p class="ta-co-card-heading">نتیجهٔ محاسبهٔ قیمت</p>
                        <p class="ta-co-word-count-line">تعداد کلمات اثر: <strong id="ta-co-word-count">۰</strong></p>
                        <table class="ta-co-price-table">
                            <thead><tr><th>استاد تسهیلگر</th><th>قیمت</th></tr></thead>
                            <tbody id="ta-co-price-rows"></tbody>
                            <tfoot><tr><td>جمع کل</td><td id="ta-co-price-total">۰ تومان</td></tr></tfoot>
                        </table>
                        <p id="ta-co-reorder-discount" style="display:none;color:#15803d;font-weight:700;margin:10px 0"></p>
                    </div>

                    <div class="ta-co-card-section">
                        <p class="ta-co-card-heading">تکمیل سفارش</p>
                        <div class="ta-co-checkout-form">
                            <div class="ta-sc-field">
                                <label>نام و نام‌خانوادگی <span class="ta-sc-req">*</span></label>
                                <input type="text" id="ta-co-name" value="<?= esc_attr( wp_get_current_user()->display_name ) ?>">
                            </div>
                            <div class="ta-sc-field">
                                <label>شماره موبایل <span class="ta-sc-req">*</span></label>
                                <input type="tel" id="ta-co-phone" dir="ltr" value="<?= esc_attr( get_user_meta( get_current_user_id(), 'billing_phone', true ) ) ?>">
                            </div>
                        </div>
                        <div id="ta-co-order-msg" class="ta-sc-msg" style="display:none"></div>
                    </div>

                    <div class="ta-co-card-actions">
                        <button type="button" class="ta-btn ta-btn-outline" id="ta-co-back-1">← بازگشت</button>
                        <button type="button" class="ta-btn ta-btn-primary ta-btn-lg" id="ta-co-pay-btn">پرداخت و ثبت سفارش</button>
                    </div>
                </div>
            </div>

            <!-- مودال اطلاعات بیشتر استاد تسهیلگر -->
            <div class="ta-co-modal-overlay" id="ta-co-ta-modal" style="display:none">
                <div class="ta-co-modal-box" role="dialog" aria-modal="true">
                    <div class="ta-co-modal-head">
                        <img src="" class="ta-co-ta-avatar ta-co-modal-avatar" id="ta-co-modal-avatar">
                        <div class="ta-co-modal-name-wrap">
                            <strong id="ta-co-modal-name"></strong>
                            <span class="ta-co-ta-tag ta-co-ta-role" id="ta-co-modal-role"></span>
                        </div>
                    </div>
                    <p class="ta-co-modal-resume" id="ta-co-modal-resume"></p>
                    <div class="ta-co-modal-actions">
                        <button type="button" class="ta-co-modal-btn ta-co-modal-btn-close" id="ta-co-modal-close">
                            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="11" fill="#e11d48"/><path d="M7.5 7.5l7 7M14.5 7.5l-7 7" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/></svg>
                            بستن
                        </button>
                        <button type="button" class="ta-co-modal-btn ta-co-modal-btn-select" id="ta-co-modal-select">
                            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="11" fill="#16a34a"/><path d="M6.5 11.3l3 3 6-6.2" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            انتخاب منتقد
                        </button>
                    </div>
                </div>
            </div>
            <div class="ta-co-image-lightbox" id="ta-co-image-lightbox" style="display:none" role="dialog" aria-modal="true" aria-label="تصویر بزرگ نویسنده">
                <button type="button" class="ta-co-image-lightbox-close" aria-label="بستن">×</button>
                <img src="" id="ta-co-lightbox-image" alt="">
            </div>

        </div>

        <?php $this->print_styles(); ?>
        <script>
        (function($){
            var ajaxUrl = '<?= esc_js( admin_url( 'admin-ajax.php' ) ) ?>';
            var nonce   = '<?= esc_js( $nonce ) ?>';
            var priceToken = null;
            var reorderParentId = <?= (int) ( $reorder_parent->id ?? 0 ) ?>;

            $(document).on('click keydown', '.ta-co-accordion-head', function(e){
                if ( e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ' ) return;
                if ( e.type === 'keydown' ) e.preventDefault();
                $(this).next('.ta-co-accordion-body').slideToggle(150);
                $(this).find('.ta-co-accordion-arrow').toggleClass('ta-co-arrow-open');
                $(this).closest('.ta-co-accordion').toggleClass('ta-co-open');
            });

            // «اطلاعات بیشتر»: باز کردن مودال پروفایل استاد تسهیلگر — بدون تأثیر روی انتخاب استاد تسهیلگر (چک‌باکس)
            var $tsModalCard = null;
            $(document).on('click', '.ta-co-ta-more-btn', function(e){
                e.preventDefault();
                e.stopPropagation();
                var $card = $(this).closest('.ta-co-ta-card');
                $tsModalCard = $card;
                $('#ta-co-modal-avatar').attr('src', $card.find('.ta-co-ta-avatar').attr('src'));
                $('#ta-co-modal-name').text($card.find('.ta-co-ta-info strong').text());
                $('#ta-co-modal-role').text($card.find('.ta-co-ta-tag.ta-co-ta-role').first().text());
                $('#ta-co-modal-resume').text($card.find('.ta-co-ta-resume').text());
                $('#ta-co-ta-modal').css('display', 'flex');
            });

            function tsCloseModal(){ $('#ta-co-ta-modal').css('display', 'none'); $tsModalCard = null; }

            $(document).on('click', '#ta-co-modal-close', function(e){
                e.preventDefault();
                tsCloseModal();
            });

            // «انتخاب منتقد» داخل مودال: همان استاد تسهیلگرِ مودال را انتخاب می‌کند
            $(document).on('click', '#ta-co-modal-select', function(e){
                e.preventDefault();
                if ($tsModalCard) {
                    var $chk = $tsModalCard.find('.ta-co-ta-check');
                    if (!$chk.prop('checked')) $chk.prop('checked', true).trigger('change');
                }
                tsCloseModal();
            });

            // بستن مودال با کلیک روی پس‌زمینه یا کلید Esc
            $(document).on('click', '#ta-co-ta-modal', function(e){
                if (e.target === this) tsCloseModal();
            });
            $(document).on('click', '.ta-co-avatar-expand', function(e){
                e.preventDefault();
                e.stopPropagation();
                $('#ta-co-lightbox-image').attr('src', $(this).siblings('.ta-co-ta-avatar').attr('src'));
                $('#ta-co-image-lightbox').css('display', 'flex');
            });
            function tsCloseImageLightbox(){ $('#ta-co-image-lightbox').css('display', 'none'); $('#ta-co-lightbox-image').attr('src', ''); }
            $(document).on('click', '.ta-co-image-lightbox-close', function(e){ e.preventDefault(); tsCloseImageLightbox(); });
            $(document).on('click', '#ta-co-image-lightbox', function(e){ if (e.target === this) tsCloseImageLightbox(); });
            $(document).on('keydown', function(e){
                if (e.key === 'Escape') { tsCloseModal(); tsCloseImageLightbox(); }
            });

            // فقط نمایشی: نام فایل انتخاب‌شده را روی دکمهٔ بارگذاری نشان می‌دهد (منطق محاسبه/AJAX دست‌نخورده است)
            $(document).on('change', '#ta-co-file', function(){
                var f = this.files && this.files[0];
                $('#ta-co-file-label').text(f ? f.name : 'بارگذاری اثر');
            });

            function updateSelectedBar(){
                var n = $('.ta-co-ta-check:checked').length;
                $('#ta-co-selected-count').text(n + ' استاد تسهیلگر انتخاب شد');
                $('#ta-co-selected-bar').toggle(n > 0);
                $('.ta-co-ta-card').removeClass('active');
                $('.ta-co-ta-check:checked').closest('.ta-co-ta-card').addClass('active');
            }
            $(document).on('change', '.ta-co-ta-check', updateSelectedBar);
            updateSelectedBar();

            $('#ta-co-goto-step2').on('click', function(){
                if ($('.ta-co-ta-check:checked').length === 0) return;
                $('#ta-co-step-1').hide(); $('#ta-co-step-2').show();
            });
            $('#ta-co-back-1').on('click', function(){ $('#ta-co-step-2').hide(); $('#ta-co-step-1').show(); });

            $('#ta-co-calc-btn').on('click', function(){
                var file = document.getElementById('ta-co-file').files[0];
                if (!file){ showMsg('#ta-co-price-msg','لطفاً فایل را انتخاب کنید.','error'); return; }
                if (!/\.docx$/i.test(file.name)){ showMsg('#ta-co-price-msg','فقط فایل Word با فرمت docx مجاز است.','error'); return; }

                var fd = new FormData();
                fd.append('action','ta_co_price');
                fd.append('nonce', nonce);
                fd.append('file', file);
                if (reorderParentId) fd.append('reorder_parent_id', reorderParentId);
                $('.ta-co-ta-check:checked').each(function(){
                    fd.append('ta_ids[]', $(this).val());
                    fd.append('specialties[]', $(this).data('specialty'));
                });

                var $btn = $(this).prop('disabled', true).text('در حال محاسبه…');
                $.ajax({ url: ajaxUrl, type:'POST', data: fd, processData:false, contentType:false })
                .done(function(res){
                    $btn.prop('disabled', false).text('محاسبه قیمت');
                    if (!res.success){ showMsg('#ta-co-price-msg', res.data && res.data.message ? res.data.message : 'خطا','error'); return; }
                    priceToken = res.data.token;
                    $('#ta-co-word-count').text(res.data.word_count);
                    var $rows = $('#ta-co-price-rows').empty();
                    res.data.items.forEach(function(it){
                        $rows.append('<tr><td>'+it.name+'</td><td>'+Number(it.price).toLocaleString()+' تومان</td></tr>');
                    });
                    $('#ta-co-price-total').text(Number(res.data.total).toLocaleString()+' تومان');
                    if (res.data.discount_percent) {
                        $('#ta-co-reorder-discount').text('تخفیف سفارش مجدد: '+res.data.discount_percent+'٪ روی قیمت نهایی اعمال شد').show();
                    } else {
                        $('#ta-co-reorder-discount').hide();
                    }
                    $('#ta-co-price-result').show();
                })
                .fail(function(){ $btn.prop('disabled', false).text('محاسبه قیمت'); showMsg('#ta-co-price-msg','خطا در ارتباط با سرور','error'); });
            });

            $('#ta-co-pay-btn').on('click', function(){
                var name  = $('#ta-co-name').val().trim();
                var phone = $('#ta-co-phone').val().trim();
                if (!name || !phone){ showMsg('#ta-co-order-msg','لطفاً همه فیلدها را پر کنید.','error'); return; }
                if (!priceToken){ showMsg('#ta-co-order-msg','ابتدا مرحله محاسبه قیمت را انجام دهید.','error'); return; }

                var $btn = $(this).prop('disabled', true).text('در حال ثبت سفارش…');
                $.post(ajaxUrl, {
                    action:'ta_co_place_order', nonce:nonce, token:priceToken,
                    full_name:name, phone:phone, reorder_parent_id:reorderParentId
                }).done(function(res){
                    if (res.success && res.data.redirect){
                        window.location.href = res.data.redirect;
                    } else {
                        $btn.prop('disabled', false).text('پرداخت و ثبت سفارش');
                        showMsg('#ta-co-order-msg', res.data && res.data.message ? res.data.message : 'خطا در ثبت سفارش','error');
                    }
                }).fail(function(){
                    $btn.prop('disabled', false).text('پرداخت و ثبت سفارش');
                    showMsg('#ta-co-order-msg','خطا در ارتباط با سرور','error');
                });
            });

            function showMsg(sel, msg, type){
                $(sel).text(msg).removeClass('error info').addClass(type).show();
            }
        })(jQuery);
        </script>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // سفارش‌های من
    // ─────────────────────────────────────────────────────────────────────────

    private function render_my_orders(): void {
        ?>
        <div class="ta-co-wrap" dir="rtl">
            <p><a href="<?= $this->page_url() ?>" class="ta-btn ta-btn-outline ta-btn-sm">← ثبت سفارش جدید</a></p>
            <h2 class="ta-co-title">سفارش‌های نقد آثار من</h2>
            <?php self::render_orders_panel( get_current_user_id(), false ); ?>
        </div>
        <?php
    }

    /**
     * نمای «سفارش‌های نقد آثار من» به‌صورت قابل‌استفاده مجدد — هم در صفحهٔ اختصاصی
     * سفارش نقد آثار و هم داخل تب «نقد آثار» پیشخوان هنرجو (class-student-portal.php).
     *
     * برای هر سفارش نشان می‌دهد: استاد تسهیلگر، تخصص، تعداد کلمات، وضعیت مرحلهٔ سفارش،
     * وضعیت صفِ کاری استاد تسهیلگر (چند نقد در چه مرحله‌ای دارد)، و لینک گروه بلهٔ استاد
     * (فقط وقتی سفارش «در حال انجام»/«تحویل‌شده» است — بعد از «تکمیل‌شدن» حذف می‌شود).
     */
    public static function render_orders_panel( int $uid, bool $with_new_order_link = true ): void {
        $table    = TA_Database::table( 'critique_orders' );
        $sc_table = TA_Database::table( 'story_critique_forms' );
        $orders = TA_Database::get_results(
            "SELECT co.*, scf.full_name AS ta_name, scf.group_link AS ta_group_link
             FROM {$table} co
             LEFT JOIN {$sc_table} scf ON scf.id = co.ta_id
             WHERE co.student_id = %d ORDER BY co.created_at DESC",
            [ $uid ]
        );

        $status_labels = [
            'pending'      => '⏳ در انتظار استاد تسهیلگر',
            'in_progress'  => '✍️ در حال انجام',
            'delivered'    => '📬 فایل تحویل شد',
            'ta_completed' => '🧾 نقد تکمیل شد — منتظر تأیید شماست',
            'completed'    => '✅ تکمیل‌شده',
            'cancelled'    => '❌ لغو شده',
        ];

        $order_page_url = self::get_page_url();
        ?>
        <div class="ta-co-embedded" dir="rtl">
            <?php if ( $with_new_order_link && $order_page_url ) : ?>
                <p><a href="<?= esc_url( $order_page_url ) ?>" class="ta-btn ta-btn-primary ta-btn-sm">➕ ثبت سفارش نقد اثر جدید</a></p>
            <?php endif; ?>

            <?php if ( empty( $orders ) ) : ?>
                <div class="ta-co-empty">هنوز سفارش نقد اثری ثبت نکرده‌اید.</div>
            <?php else : ?>
            <div class="ta-co-orders-list">
                <?php foreach ( $orders as $o ) :
                    $has_eval  = self::student_has_evaluated( (int) $o->id );
                    $paid      = self::order_is_paid( (int) $o->wc_order_id );
                    $queue_pos = self::get_queue_position( $o );
                    $show_link = in_array( $o->status, [ 'in_progress', 'delivered' ], true ) && ! empty( $o->ta_group_link );
                    $reorder_parent_id = (int) get_post_meta( (int) $o->wc_order_id, '_ta_co_reorder_parent_id', true );
                    global $wpdb;
                    $reorder_child_id = 0;
                    if ( ! $reorder_parent_id ) {
                        $child_wc_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_ta_co_reorder_parent_id' AND pm.meta_value=%d AND p.post_type='shop_order' ORDER BY pm.meta_id ASC LIMIT 1", (int) $o->id ) );
                        if ( $child_wc_id ) $reorder_child_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE wc_order_id=%d ORDER BY id ASC LIMIT 1", $child_wc_id ) );
                    }
                ?>
                <div class="ta-co-order-card" id="ta-co-order-<?= (int) $o->id ?>">
                    <div><strong>استاد تسهیلگر:</strong> <?= esc_html( $o->ta_name ?: '—' ) ?></div>
                    <div><strong>تخصص:</strong> <?= esc_html( $o->specialty ?: '—' ) ?></div>
                    <div><strong>تعداد کلمات:</strong> <?= esc_html( $o->word_count ?: '—' ) ?></div>
                    <div><strong>وضعیت سفارش:</strong> <?= $paid ? esc_html( $status_labels[ $o->status ] ?? $o->status ) : '💳 در انتظار پرداخت' ?></div>
                    <?php if ( $reorder_parent_id ) : ?><div style="color:#15803d;font-size:12px">🔁 سفارش مجدد مرتبط با سفارش اصلی <a href="#ta-co-order-<?= (int) $reorder_parent_id ?>">مشاهده سفارش اصلی</a></div><?php elseif ( $reorder_child_id ) : ?><div style="color:#15803d;font-size:12px">🔁 سفارش اصلی دارای سفارش مجدد <a href="#ta-co-order-<?= (int) $reorder_child_id ?>">مشاهده سفارش مجدد</a></div><?php endif; ?>

                    <?php if ( $paid && ! in_array( $o->status, [ 'completed', 'cancelled' ], true ) ) : ?>
                        <div><?= self::queue_position_badge_html( $queue_pos ) ?></div>
                    <?php endif; ?>

                    <?php if ( $show_link ) : ?>
                        <div>
                            <a href="<?= esc_url( TA_URL_Helper::normalize( $o->ta_group_link ) ) ?>" target="_blank" rel="noopener"
                               class="ta-btn ta-btn-sm ta-btn-primary">💬 ورود به گروه بله استاد</a>
                        </div>
                    <?php endif; ?>

                    <?php if ( in_array( $o->status, [ 'delivered', 'ta_completed', 'completed' ], true ) ) : ?>
                        <?php if ( $o->result_file_id ) : ?>
                        <div><a href="<?= esc_url( wp_get_attachment_url( $o->result_file_id ) ) ?>" target="_blank" class="ta-btn ta-btn-sm ta-btn-outline">📥 دانلود فایل نقد</a></div>
                        <?php endif; ?>
                        <?php if ( $o->note_to_student ) : ?><div class="ta-co-ta-note"><?= esc_html( $o->note_to_student ) ?></div><?php endif; ?>
                    <?php endif; ?>

                    <?php // [FIX-COMPLETION-FLOW] هنرجو فقط وقتی می‌تواند «دریافت کردم» را بزند که استاد
                    // نقد را تکمیل کرده باشد (ta_completed — یعنی ارزیابی هنرجو را هم پر کرده) ?>
                    <?php if ( $o->status === 'ta_completed' ) : ?>
                        <div style="margin-top:6px">
                            <button type="button" class="ta-btn ta-btn-success ta-btn-sm ta-co-student-complete-btn" data-id="<?= (int) $o->id ?>">
                                ✅ نقد اثر را دریافت کردم و ارزیابی استاد را انجام می‌دهم
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if ( $o->status === 'completed' && ! $has_eval ) : ?>
                        <div>
                            <a href="<?= $order_page_url ? esc_url( add_query_arg( [ 'ta_view' => 'evaluate', 'corder' => $o->id ], $order_page_url ) ) : '#' ?>"
                               class="ta-btn ta-btn-primary ta-btn-sm">🗳️ ثبت ارزیابی استاد</a>
                        </div>
                    <?php elseif ( $has_eval ) : ?>
                        <div style="color:#16a34a;font-size:12px">✅ ارزیابی استاد ثبت شد</div>
                        <?php if ( $o->status === 'completed' && $order_page_url && ! $reorder_child_id ) : ?>
                            <div style="margin-top:8px">
                                <a href="<?= esc_url( add_query_arg( [ 'ta_reorder' => (int) $o->id ], $order_page_url ) ) ?>" class="ta-btn ta-btn-primary ta-btn-sm ta-co-reorder-cta">🔁 سفارش مجدد نقد نسخه بازنویسی‌شده</a>
                                <small style="display:block;color:#64748b;margin-top:4px">با ۴۰٪ تخفیف روی قیمت نهایی محاسبه‌شده</small>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ( $paid && ! in_array( $o->status, [ 'cancelled' ], true ) ) : ?>
                        <?php TA_Critique_Chat::render_box( (int) $o->id, 'ta_student', 'گفتگو با استاد تسهیلگر' ); ?>
                        <?php TA_Critique_Chat::render_box( (int) $o->id, 'admin_student', 'گفتگو با ادمین' ); ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <script>
        (function($){
            $(document).on('click', '.ta-co-student-complete-btn', function(){
                var $btn = $(this).prop('disabled', true).text('در حال ثبت…');
                $.post(ajaxurlTaCo, {
                    action: 'ta_co_student_complete',
                    nonce: '<?= esc_js( wp_create_nonce( 'ta_co_nonce' ) ) ?>',
                    corder_id: $btn.data('id')
                }, function(res){
                    if (res.success) {
                        if (res.data && res.data.redirect) {
                            window.location.href = res.data.redirect;
                        } else {
                            window.location.reload();
                        }
                    } else {
                        $btn.prop('disabled', false).text('✅ نقد اثر را دریافت کردم و ارزیابی استاد را انجام می‌دهم');
                        alert(res.data && res.data.message ? res.data.message : 'خطا در ثبت');
                    }
                });
            });
        })(jQuery);
        var ajaxurlTaCo = '<?= esc_js( admin_url( 'admin-ajax.php' ) ) ?>';
        </script>
        <?php self::print_styles();
    }

    private static function student_has_evaluated( int $critique_order_id ): bool {
        $table = TA_Database::table( 'eval_responses' );
        $count = TA_Database::get_row( "SELECT COUNT(*) AS c FROM {$table} WHERE critique_order_id = %d", [ $critique_order_id ] );
        return $count && (int) $count->c > 0;
    }

    /** سفارش اصلیِ مجاز برای سفارش مجدد: متعلق به همین هنرجو، تکمیل‌شده و ارزیابی‌شده. */
    private function get_valid_reorder_parent( int $corder_id ): ?object {
        if ( ! $corder_id || ! is_user_logged_in() ) return null;
        $table = TA_Database::table( 'critique_orders' );
        $row = TA_Database::get_row( "SELECT * FROM {$table} WHERE id = %d AND student_id = %d", [ $corder_id, get_current_user_id() ] );
        if ( ! $row || $row->status !== 'completed' || ! self::student_has_evaluated( $corder_id ) ) return null;
        if ( $this->get_reorder_child_id( $corder_id ) ) return null;
        return $row;
    }

    /** شناسه سفارش نقدِ مجددی که از سفارش اصلی ساخته شده است؛ سقف مجاز: یک مورد. */
    private function get_reorder_child_id( int $parent_corder_id ): int {
        global $wpdb;
        $co_table = TA_Database::table( 'critique_orders' );
        $wc_order_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_ta_co_reorder_parent_id' AND pm.meta_value = %d
               AND p.post_type = 'shop_order' ORDER BY pm.meta_id ASC LIMIT 1",
            $parent_corder_id
        ) );
        return $wc_order_id ? (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$co_table} WHERE wc_order_id = %d ORDER BY id ASC LIMIT 1", $wc_order_id
        ) ) : 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // فرم ارزیابی
    // ─────────────────────────────────────────────────────────────────────────

    private function render_evaluate(): void {
        $uid       = get_current_user_id();
        $corder_id = absint( $_GET['corder'] ?? 0 );
        $co_table  = TA_Database::table( 'critique_orders' );
        $order     = TA_Database::get_row( "SELECT * FROM {$co_table} WHERE id = %d AND student_id = %d", [ $corder_id, $uid ] );

        if ( ! $order || $order->status !== 'completed' ) {
            echo '<div class="ta-co-wrap" dir="rtl"><p>این سفارش برای ارزیابی در دسترس نیست.</p></div>';
            return;
        }
        if ( $this->student_has_evaluated( $corder_id ) ) {
            echo '<div class="ta-co-wrap" dir="rtl"><p>✅ ارزیابی این سفارش قبلاً ثبت شده است.</p></div>';
            return;
        }

        $q_table   = TA_Database::table( 'eval_questions' );
        $questions = TA_Database::get_results( "SELECT * FROM {$q_table} WHERE is_active = 1 ORDER BY sort_order ASC, id ASC" );
        $nonce     = wp_create_nonce( 'ta_co_nonce' );
        ?>
        <div class="ta-co-wrap ta-eval-public" dir="rtl">
            <div class="ta-eval-public-hero"><span class="ta-eval-public-kicker">🗳️ سؤال‌های ارزیابی استاد (توسط هنرجو)</span><h2 class="ta-co-title">تجربه شما از پایگاه نقد مبنا</h2><p>نظر شما به ما کمک می‌کند کیفیت پایگاه نقد و عملکرد منتقدان را دقیق‌تر ارزیابی کنیم.<br>لطفاً هر مورد را بر اساس تجربهٔ واقعی خودتان از این نقد ارزیابی کنید.</p></div>
            <div class="ta-eval-scale-note"><strong>مقیاس پاسخ:</strong><span>کاملاً</span><span>تا حد زیادی</span><span>تا حدی</span><span>کم</span><span>اصلاً</span><span>امکان ارزیابی این مورد را نداشتم</span></div>
            <div id="ta-co-eval-msg" class="ta-sc-msg" style="display:none"></div>
            <form id="ta-co-eval-form">
                <?php foreach ( $questions as $q_index => $q ) :
                    $options = $q->options ? json_decode( $q->options, true ) : [];
                    $options = is_array( $options ) ? $options : [];
                ?>
                <div class="ta-sc-field ta-sc-field-full">
                    <label><span class="ta-eval-number"><?= (int) $q_index + 1 ?></span><?= esc_html( $q->question ) ?><?= $q->type !== 'text' ? '<b class="ta-eval-required">*</b>' : '' ?></label>
                    <?php if ( $q->type === 'rating' ) : ?>
                        <div class="ta-co-rating" data-qid="<?= (int) $q->id ?>">
                            <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                            <span class="ta-co-star" data-value="<?= $i ?>">☆</span>
                            <?php endfor; ?>
                            <input type="hidden" class="ta-co-eval-input" data-qid="<?= (int) $q->id ?>" value="">
                        </div>
                    <?php elseif ( $q->type === 'choice' ) : ?>
                        <?php foreach ( $options as $opt ) : ?>
                        <label class="ta-eval-option">
                            <input type="radio" class="ta-co-eval-input" name="q_<?= (int) $q->id ?>" data-qid="<?= (int) $q->id ?>" value="<?= esc_attr( $opt ) ?>"> <?= esc_html( $opt ) ?>
                        </label>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <textarea class="ta-co-eval-input" data-qid="<?= (int) $q->id ?>" rows="3"></textarea>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <button type="button" class="ta-btn ta-btn-primary" id="ta-co-eval-submit">ثبت ارزیابی تجربه</button>
            </form>
        </div>
        <?php $this->print_styles(); ?>
        <style>
        .ta-eval-public-hero{background:linear-gradient(135deg,#24134f,#6d28d9);color:#fff;border-radius:20px;padding:24px;margin-bottom:20px;box-shadow:0 14px 35px rgba(76,29,149,.2)}
        .ta-eval-public-hero .ta-co-title{color:#fff;margin:7px 0}.ta-eval-public-hero p{margin:0;line-height:1.9;opacity:.88;font-size:13px}.ta-eval-public-kicker{font-size:12px;color:#ddd6fe;font-weight:700}
        .ta-eval-public #ta-co-eval-form{display:grid;gap:12px}.ta-eval-public .ta-sc-field{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px;box-shadow:0 3px 12px rgba(15,23,42,.04)}
        .ta-eval-public .ta-sc-field>label{display:block;font-weight:800;color:#1e293b;margin-bottom:12px}.ta-eval-public .ta-sc-field label input{accent-color:#6d28d9}
        .ta-eval-scale-note{display:flex;flex-wrap:wrap;gap:7px;align-items:center;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:14px;padding:12px 14px;color:#4c1d95;font-size:12px;margin-bottom:14px}.ta-eval-scale-note span{background:#fff;border-radius:99px;padding:5px 9px;border:1px solid #e9d5ff}.ta-eval-public .ta-co-star{font-size:28px;color:#f59e0b;cursor:pointer;margin-left:4px}.ta-eval-public textarea{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:10px;min-height:80px}.ta-eval-public .ta-btn{margin-top:4px}.ta-eval-number{display:inline-flex;align-items:center;justify-content:center;width:25px;height:25px;background:#ede9fe;color:#6d28d9;border-radius:8px;margin-left:8px;font-size:12px}.ta-eval-required{color:#e11d48;margin-right:4px}.ta-eval-option{display:inline-flex!important;align-items:center;gap:5px;background:#fafafa;border:1px solid #e2e8f0;border-radius:9px;padding:8px 10px;margin:0 0 7px 7px!important;color:#334155;font-size:12px}.ta-eval-option:has(input:checked){background:#ede9fe;border-color:#8b5cf6;color:#4c1d95}
        </style>
        <script>
        (function($){
            $(document).on('click','.ta-co-star', function(){
                var $wrap = $(this).closest('.ta-co-rating');
                var v = $(this).data('value');
                $wrap.find('.ta-co-star').each(function(){ $(this).text($(this).data('value') <= v ? '★' : '☆'); });
                $wrap.find('.ta-co-eval-input').val(v);
            });

            $('#ta-co-eval-submit').on('click', function(){
                var answers = {};
                $('.ta-co-eval-input').each(function(){
                    var $el = $(this);
                    if ($el.attr('type') === 'radio') { if (!$el.is(':checked')) return; }
                    var qid = $el.data('qid');
                    var val = $el.val();
                    if (val) answers[qid] = val;
                });
                $.post('<?= esc_js( admin_url( 'admin-ajax.php' ) ) ?>', {
                    action:'ta_co_submit_eval', nonce:'<?= esc_js( $nonce ) ?>',
                    corder_id: <?= (int) $corder_id ?>, answers: answers
                }).done(function(res){
                    if (res.success){
                        $('#ta-co-eval-form').html('<p style="color:#16a34a">✅ ممنون از ارزیابی شما! در حال بازگشت به پرتال…</p>');
                        window.setTimeout(function(){ window.location.href = res.data && res.data.redirect ? res.data.redirect : '<?= esc_js( add_query_arg( [ 'ta_portal_tab' => 'critique' ], home_url( '/portal/' ) ) ) ?>'; }, 450);
                    } else {
                        $('#ta-co-eval-msg').text(res.data && res.data.message ? res.data.message : 'خطا').addClass('error').show();
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX: محاسبه قیمت
    // ─────────────────────────────────────────────────────────────────────────

    public function ajax_price(): void {
        check_ajax_referer( 'ta_co_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'ابتدا وارد شوید.' ] );

        if ( empty( $_FILES['file'] ) || ! empty( $_FILES['file']['error'] ) ) {
            wp_send_json_error( [ 'message' => 'فایلی ارسال نشد.' ] );
        }
        if ( ! TA_Docx_Reader::is_docx( $_FILES['file']['name'] ) ) {
            wp_send_json_error( [ 'message' => 'فقط فایل Word با فرمت docx مجاز است.' ] );
        }

        $ta_ids       = array_map( 'absint', (array) ( $_POST['ta_ids'] ?? [] ) );
        $specialties  = array_map( 'sanitize_text_field', (array) ( $_POST['specialties'] ?? [] ) );
        $reorder_parent_id = absint( $_POST['reorder_parent_id'] ?? 0 );
        $reorder_parent = $reorder_parent_id ? $this->get_valid_reorder_parent( $reorder_parent_id ) : null;
        if ( $reorder_parent_id && ! $reorder_parent ) {
            wp_send_json_error( [ 'message' => 'سفارش اصلی برای سفارش مجدد معتبر نیست.' ] );
        }
        if ( $reorder_parent && ( count( $ta_ids ) !== 1 || (int) $ta_ids[0] !== (int) $reorder_parent->ta_id || (string) ( $specialties[0] ?? '' ) !== (string) $reorder_parent->specialty ) ) {
            wp_send_json_error( [ 'message' => 'در سفارش مجدد باید همان استاد و تخصص سفارش اصلی انتخاب شود.' ] );
        }
        if ( empty( $ta_ids ) ) {
            wp_send_json_error( [ 'message' => 'حداقل یک استاد تسهیلگر انتخاب کنید.' ] );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ] );
        }

        $file_path  = get_attached_file( $attachment_id );
        $word_count = TA_Docx_Reader::count_words( $file_path );
        if ( $word_count === false ) {
            wp_send_json_error( [ 'message' => 'خواندن فایل با خطا مواجه شد. لطفاً فایل docx معتبر آپلود کنید.' ] );
        }

        $sc_table = TA_Database::table( 'story_critique_forms' );
        $items = [];
        $total = 0;
        foreach ( $ta_ids as $i => $ta_id ) {
            $ta = TA_Database::get_row( "SELECT * FROM {$sc_table} WHERE id = %d", [ $ta_id ] );
            if ( ! $ta || $ta->capacity === null || (int) $ta->capacity <= 0 ) continue;
            $original_price = $this->calc_price( $ta, (int) $word_count, $specialties[ $i ] ?? '' );
            $price = $reorder_parent ? round( $original_price * 0.60, 2 ) : $original_price;
            $items[] = [
                'ta_id'     => (int) $ta->id,
                'name'      => $ta->full_name,
                'specialty' => $specialties[ $i ] ?? '',
                'price'     => $price,
                'original_price' => $original_price,
            ];
            $total += $price;
        }

        if ( empty( $items ) ) {
            wp_send_json_error( [ 'message' => 'استاد تسهیلگر انتخاب‌شده دیگر ظرفیت ندارد. لطفاً دوباره انتخاب کنید.' ] );
        }

        $token = wp_generate_password( 20, false );
        set_transient( 'ta_co_cart_' . $token, [
            'user_id'       => get_current_user_id(),
            'attachment_id' => $attachment_id,
            'word_count'    => $word_count,
            'items'         => $items,
            'reorder_parent_id' => $reorder_parent ? (int) $reorder_parent->id : 0,
            'reorder_discount_percent' => $reorder_parent ? 40 : 0,
        ], HOUR_IN_SECONDS );

        wp_send_json_success( [
            'token'      => $token,
            'word_count' => $word_count,
            'items'      => $items,
            'total'      => $total,
            'discount_percent' => $reorder_parent ? 40 : 0,
            'original_total' => $reorder_parent ? round( $total / 0.60, 2 ) : $total,
        ] );
    }

    /**
     * محاسبه قیمت بر اساس تعداد کلمه: قیمت‌پایه + پله‌های افزایشی.
     * [FEATURE-SPECIALTY-PRICING] اگر برای این استاد تسهیلگر روی همین تخصص قیمت‌گذاری
     * مستقلی ثبت شده باشد از همان استفاده می‌شود؛ هر پارامتر که در آن رکورد خالی
     * باشد از قیمت‌پایه‌ی خودِ استاد تسهیلگر می‌آید (fallback فیلد به فیلد، نه کل رکورد).
     */
    private function calc_price( object $ta, int $word_count, string $specialty = '' ): float {
        $base_price   = (float) $ta->base_price;
        $base_words   = (int) $ta->base_word_count;
        $step_words   = (int) ( $ta->increment_words ?: 0 );
        $step_percent = (float) ( $ta->increment_percent ?: 0 );

        if ( $specialty !== '' ) {
            $sp_table = TA_Database::table( 'specialty_pricing' );
            $sp = TA_Database::get_row( "SELECT * FROM {$sp_table} WHERE form_id = %d AND specialty = %s", [ $ta->id, $specialty ] );
            if ( $sp ) {
                if ( $sp->base_price !== null )        $base_price   = (float) $sp->base_price;
                if ( $sp->base_word_count !== null )    $base_words   = (int) $sp->base_word_count;
                if ( $sp->increment_words !== null )    $step_words   = (int) $sp->increment_words;
                if ( $sp->increment_percent !== null )  $step_percent = (float) $sp->increment_percent;
            }
        }

        $extra = max( 0, $word_count - $base_words );
        if ( $extra <= 0 || $step_words <= 0 || $step_percent <= 0 ) {
            return round( $base_price, 2 );
        }
        $steps = (int) ceil( $extra / $step_words );
        $price = $base_price * ( 1 + ( $steps * $step_percent / 100 ) );
        return round( $price, 2 );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX: ثبت سفارش
    // ─────────────────────────────────────────────────────────────────────────

    public function ajax_place_order(): void {
        check_ajax_referer( 'ta_co_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'ابتدا وارد شوید.' ] );

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            wp_send_json_error( [ 'message' => 'ووکامرس فعال نیست.' ] );
        }

        $token = sanitize_text_field( $_POST['token'] ?? '' );
        $cart  = get_transient( 'ta_co_cart_' . $token );
        if ( ! $cart || (int) $cart['user_id'] !== $uid ) {
            wp_send_json_error( [ 'message' => 'اطلاعات سفارش منقضی شده؛ لطفاً دوباره فایل را آپلود کنید.' ] );
        }
        if ( absint( $_POST['reorder_parent_id'] ?? 0 ) !== absint( $cart['reorder_parent_id'] ?? 0 ) ) {
            wp_send_json_error( [ 'message' => 'اطلاعات سفارش مجدد معتبر نیست؛ لطفاً فرایند را دوباره آغاز کنید.' ] );
        }

        $full_name = sanitize_text_field( $_POST['full_name'] ?? '' );
        $phone     = sanitize_text_field( $_POST['phone'] ?? '' );
        if ( ! $full_name || ! $phone ) {
            wp_send_json_error( [ 'message' => 'لطفاً همه فیلدها را پر کنید.' ] );
        }

        $product_id = absint( get_option( 'ta_sc_product_id', 0 ) );
        $product    = $product_id ? wc_get_product( $product_id ) : null;
        if ( ! $product ) {
            wp_send_json_error( [ 'message' => 'محصول نمونه در تنظیمات مشخص نشده است.' ] );
        }

        $sc_table = TA_Database::table( 'story_critique_forms' );

        // شروع تازه: سبد خرید را خالی می‌کنیم تا فقط آیتم‌های همین سفارش در آن باشد
        WC()->cart->empty_cart();

        $added = 0;
        foreach ( $cart['items'] as $it ) {
            $ta = TA_Database::get_row( "SELECT * FROM {$sc_table} WHERE id = %d", [ $it['ta_id'] ] );
            if ( ! $ta || $ta->capacity === null || (int) $ta->capacity <= 0 ) continue; // بازبینی ظرفیت در لحظه ثبت سفارش

            $cart_item_data = [
                'ta_co_ta_id'      => (int) $ta->id,
                'ta_co_ta_name'    => $ta->full_name,
                'ta_co_specialty'  => $it['specialty'],
                'ta_co_price'      => (float) $it['price'],
                'ta_co_full_name'  => $full_name,
                'ta_co_phone'      => $phone,
                'ta_co_word_count' => (int) $cart['word_count'],
                'ta_co_file_id'    => (int) $cart['attachment_id'],
                'ta_co_reorder_parent_id' => (int) ( $cart['reorder_parent_id'] ?? 0 ),
                'ta_co_unique'     => wp_generate_password( 8, false ), // جلوگیری از ادغام آیتم‌های مشابه در سبد
            ];

            $added_key = WC()->cart->add_to_cart( $product_id, 1, 0, [], $cart_item_data );
            if ( $added_key ) $added++;
        }

        if ( ! $added ) {
            wp_send_json_error( [ 'message' => 'متأسفانه ظرفیت استاد تسهیلگر(ها)ی انتخابی تکمیل شده است.' ] );
        }

        delete_transient( 'ta_co_cart_' . $token );

        wp_send_json_success( [ 'redirect' => wc_get_checkout_url() ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // یکپارچه‌سازی با سبد خرید/تسویه‌حساب عادی ووکامرس
    // ─────────────────────────────────────────────────────────────────────────

    /** قیمت سفارشی هر آیتم نقد اثر را روی سبد خرید اعمال می‌کند */
    public function apply_cart_item_price( $wc_cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        foreach ( $wc_cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['ta_co_price'] ) ) {
                $cart_item['data']->set_price( (float) $cart_item['ta_co_price'] );
            }
        }
    }

    /** نمایش نام استاد تسهیلگر/تخصص زیر آیتم در سبد خرید و تسویه‌حساب */
    public function show_cart_item_data( array $item_data, array $cart_item ): array {
        if ( isset( $cart_item['ta_co_ta_name'] ) ) {
            $item_data[] = [ 'name' => 'استاد تسهیلگر', 'value' => esc_html( $cart_item['ta_co_ta_name'] ) ];
        }
        if ( ! empty( $cart_item['ta_co_specialty'] ) ) {
            $item_data[] = [ 'name' => 'تخصص', 'value' => esc_html( $cart_item['ta_co_specialty'] ) ];
        }
        return $item_data;
    }

    /** انتقال اطلاعات هر آیتم سبد به متادیتای آیتمِ سفارش نهایی */
    public function save_order_line_item_meta( $item, string $cart_item_key, array $values, $order ): void {
        if ( ! isset( $values['ta_co_ta_id'] ) ) return;
        $item->add_meta_data( '_ta_co_ta_id',      $values['ta_co_ta_id'] );
        $item->add_meta_data( '_ta_co_specialty',  $values['ta_co_specialty'] ?? '' );
        $item->add_meta_data( '_ta_co_price',      $values['ta_co_price'] ?? 0 );
        $item->add_meta_data( '_ta_co_word_count', $values['ta_co_word_count'] ?? 0 );
        $item->add_meta_data( '_ta_co_file_id',    $values['ta_co_file_id'] ?? 0 );
        if ( ! empty( $values['ta_co_reorder_parent_id'] ) ) {
            $item->add_meta_data( '_ta_co_reorder_parent_id', (int) $values['ta_co_reorder_parent_id'] );
            $order->update_meta_data( '_ta_co_reorder_parent_id', (int) $values['ta_co_reorder_parent_id'] );
            $order->update_meta_data( '_ta_co_reorder_discount_percent', 40 );
            $order->save();
        }
    }

    /** بعد از ساخته‌شدن سفارش (قبل از پرداخت): ردیف‌های جدول critique_orders را می‌سازد */
    public function create_critique_order_rows( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $co_table = TA_Database::table( 'critique_orders' );
        global $wpdb;

        foreach ( $order->get_items() as $item_id => $item ) {
            $ta_id = (int) $item->get_meta( '_ta_co_ta_id' );
            if ( ! $ta_id ) continue;

            // جلوگیری از درج تکراری (اگر هوک بیش از یک‌بار اجرا شود)
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$co_table} WHERE wc_order_item_id = %d", $item_id
            ) );
            if ( $exists ) continue;

            $wpdb->insert( $co_table, [
                'wc_order_id'      => $order_id,
                'wc_order_item_id' => $item_id,
                'ta_id'            => $ta_id,
                'student_id'       => $order->get_customer_id(),
                'specialty'        => $item->get_meta( '_ta_co_specialty' ),
                'file_id'          => (int) $item->get_meta( '_ta_co_file_id' ),
                'word_count'       => (int) $item->get_meta( '_ta_co_word_count' ),
                'price'            => (float) $item->get_meta( '_ta_co_price' ),
                'status'           => 'pending',
                'created_at'       => current_time( 'mysql' ),
            ] );
        }
    }

    /** جلوگیری از تسویه‌حساب اگر ظرفیت استاد تسهیلگر در فاصله‌ی افزودن به سبد تا پرداخت تمام شده باشد */
    public function validate_cart_capacity(): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        $sc_table = TA_Database::table( 'story_critique_forms' );
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( empty( $cart_item['ta_co_ta_id'] ) ) continue;
            $ta = TA_Database::get_row( "SELECT * FROM {$sc_table} WHERE id = %d", [ (int) $cart_item['ta_co_ta_id'] ] );
            if ( ! $ta || $ta->capacity === null || (int) $ta->capacity <= 0 ) {
                wc_add_notice(
                    sprintf( 'متأسفانه ظرفیت استاد تسهیلگر «%s» تکمیل شده است. لطفاً این آیتم را از سبد خرید حذف کرده و دوباره تلاش کنید.', esc_html( $cart_item['ta_co_ta_name'] ?? '' ) ),
                    'error'
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // بعد از پرداخت موفق: کسر ظرفیت + پیامک
    // ─────────────────────────────────────────────────────────────────────────

    public function on_payment_complete( $order_id ): void {
        if ( ! $order_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // فقط اگر سفارش واقعاً پرداخت‌شده محسوب شود (نه pending/failed/...) ردیف‌های
        // سفارش نقد اثر ساخته می‌شوند. این تابع از چند هوک مختلف صدا زده می‌شود
        // (payment_complete, status_processing, status_completed, status_partial-payment)
        // پس در عمل همیشه اینجا یعنی پرداخت انجام شده؛ این بررسی صرفاً یک لایه ایمنی اضافه است.
        if ( ! self::order_is_paid( $order_id ) ) return;

        // ساخت ردیف‌های سفارش نقد اثر — فقط حالا که پرداخت قطعی شده (idempotent: خودش
        // جلوی درج تکراری را می‌گیرد)
        $this->create_critique_order_rows( $order_id );

        $co_table = TA_Database::table( 'critique_orders' );
        $rows = TA_Database::get_results( "SELECT * FROM {$co_table} WHERE wc_order_id = %d AND sms_sent = 0", [ $order_id ] );
        if ( empty( $rows ) ) return;

        $sc_table    = TA_Database::table( 'story_critique_forms' );
        $order_number = $order->get_order_number();
        $student_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $student_phone = $order->get_billing_phone();

        global $wpdb;
        foreach ( $rows as $row ) {
            $ta = TA_Database::get_row( "SELECT * FROM {$sc_table} WHERE id = %d", [ $row->ta_id ] );
            if ( ! $ta ) continue;

            // کسر ظرفیت
            $new_capacity = null;
            if ( $ta->capacity !== null && (int) $ta->capacity > 0 ) {
                $new_capacity = max( 0, (int) $ta->capacity - 1 );
                $wpdb->update( $sc_table, [ 'capacity' => $new_capacity ], [ 'id' => $ta->id ] );
            }

            // پیامک به هنرجو
            if ( $student_phone ) {
                TA_MeliPayamak::notify_student( $student_phone, $student_name, $ta->full_name, (string) $order_number );
            }
            // پیامک به استاد تسهیلگر
            if ( $ta->phone ) {
                TA_MeliPayamak::notify_ta( $ta->phone, $ta->full_name, $student_name, (string) $order_number );
            }

            // هشدار به ادمین + خودِ استاد تسهیلگر: ظرفیت این استاد تسهیلگر به صفر رسید
            if ( $new_capacity === 0 ) {
                $this->notify_admin_capacity_zero( $ta->full_name );
                if ( $ta->phone ) {
                    TA_MeliPayamak::notify_ta_capacity_alert( $ta->phone, $ta->full_name );
                }
            }

            $wpdb->update( $co_table, [ 'sms_sent' => 1 ], [ 'id' => $row->id ] );
        }
    }

    /** هشدار پیامکی به ادمین وقتی ظرفیت یک استاد تسهیلگر به صفر می‌رسد */
    private function notify_admin_capacity_zero( string $ta_name ): void {
        $admin_phone = get_option( 'ta_sc_admin_alert_phone', '' );
        if ( ! $admin_phone ) return;
        TA_MeliPayamak::notify_admin_capacity_alert( $admin_phone, $ta_name );
    }

    /**
     * لغو/مرجوع/ناموفق‌شدن سفارش ووکامرس *بعد از* اینکه قبلاً پردازش شده بود
     * (یعنی ظرفیت استاد تسهیلگر کسر و پیامک ارسال شده بود — sms_sent = 1).
     * ظرفیت استاد تسهیلگر را برمی‌گرداند، ردیف سفارش نقد اثر را «لغو‌شده» علامت می‌زند
     * تا هم در صف حساب نشود و هم دوباره پردازش نشود، و به هنرجو/استاد تسهیلگر پیامک لغو می‌فرستد.
     */
    public function on_order_cancelled_or_refunded( $order_id ): void {
        if ( ! $order_id ) return;
        $co_table = TA_Database::table( 'critique_orders' );
        $rows = TA_Database::get_results(
            "SELECT * FROM {$co_table} WHERE wc_order_id = %d AND sms_sent = 1 AND status != 'cancelled'",
            [ $order_id ]
        );
        if ( empty( $rows ) ) return; // یا هنوز پردازش نشده بود (ظرفیت کسر نشده)، یا قبلاً برگردانده شده

        $sc_table = TA_Database::table( 'story_critique_forms' );
        global $wpdb;

        $wc_order      = wc_get_order( $order_id );
        $order_number  = $wc_order ? (string) $wc_order->get_order_number() : (string) $order_id;
        $student_name  = $wc_order ? trim( $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() ) : '';
        $student_phone = $wc_order ? $wc_order->get_billing_phone() : '';

        foreach ( $rows as $row ) {
            $ta = TA_Database::get_row( "SELECT * FROM {$sc_table} WHERE id = %d", [ $row->ta_id ] );
            if ( $ta && $ta->capacity !== null ) {
                $wpdb->update( $sc_table, [ 'capacity' => (int) $ta->capacity + 1 ], [ 'id' => $ta->id ] );
            }
            $wpdb->update( $co_table, [ 'status' => 'cancelled' ], [ 'id' => $row->id ] );

            // پیامک لغو سفارش
            if ( $student_phone ) {
                TA_MeliPayamak::notify_student_cancelled( $student_phone, $student_name, $ta->full_name ?? '', $order_number );
            }
            if ( $ta && $ta->phone ) {
                TA_MeliPayamak::notify_ta_cancelled( $ta->phone, $ta->full_name, $student_name, $order_number );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX: ثبت ارزیابی
    // ─────────────────────────────────────────────────────────────────────────

    public function ajax_submit_eval(): void {
        check_ajax_referer( 'ta_co_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'ابتدا وارد شوید.' ] );

        $corder_id = absint( $_POST['corder_id'] ?? 0 );
        $co_table  = TA_Database::table( 'critique_orders' );
        $order     = TA_Database::get_row( "SELECT * FROM {$co_table} WHERE id = %d AND student_id = %d", [ $corder_id, $uid ] );
        if ( ! $order || $order->status !== 'completed' ) {
            wp_send_json_error( [ 'message' => 'این سفارش برای ارزیابی در دسترس نیست.' ] );
        }
        if ( $this->student_has_evaluated( $corder_id ) ) {
            wp_send_json_error( [ 'message' => 'قبلاً ثبت شده است.' ] );
        }

        $answers = (array) ( $_POST['answers'] ?? [] );
        if ( empty( $answers ) ) {
            wp_send_json_error( [ 'message' => 'لطفاً حداقل به یک سؤال پاسخ دهید.' ] );
        }

        $resp_table = TA_Database::table( 'eval_responses' );
        global $wpdb;
        foreach ( $answers as $qid => $val ) {
            $wpdb->insert( $resp_table, [
                'critique_order_id' => $corder_id,
                'question_id'       => absint( $qid ),
                'student_id'        => $uid,
                'answer'            => sanitize_textarea_field( wp_unslash( $val ) ),
                'created_at'        => current_time( 'mysql' ),
            ] );
        }

        wp_send_json_success( [
            'redirect' => add_query_arg( [ 'ta_portal_tab' => 'critique' ], home_url( '/portal/' ) ),
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX: تکمیل سفارش توسط خودِ هنرجو («رضایت از نقد و تکمیل»)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * بعد از اینکه استاد تسهیلگر فایل نقد را تحویل داد (status = delivered)، هنرجو با
     * کلیک این دکمه رضایت خودش را اعلام و سفارش را نهایی/تکمیل می‌کند.
     * (جدا از فرم ارزیابی اختیاری که در ajax_submit_eval ثبت می‌شود.)
     */
    public function ajax_student_complete(): void {
        check_ajax_referer( 'ta_co_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'ابتدا وارد شوید.' ] );

        $corder_id = absint( $_POST['corder_id'] ?? 0 );
        $co_table  = TA_Database::table( 'critique_orders' );
        $order = TA_Database::get_row( "SELECT * FROM {$co_table} WHERE id = %d AND student_id = %d", [ $corder_id, $uid ] );
        // [FIX-COMPLETION-FLOW] هنرجو فقط بعد از اینکه استاد نقد را «تکمیل» کرد
        // (ta_completed — یعنی فرم ارزیابی هنرجو را هم پر کرده) می‌تواند تیک
        // «نقد اثر را دریافت کردم» را بزند.
        if ( ! $order || $order->status !== 'ta_completed' ) {
            wp_send_json_error( [ 'message' => 'این سفارش در وضعیت قابل‌تکمیل نیست.' ] );
        }

        global $wpdb;
        $wpdb->update( $co_table, [
            'status'       => 'completed',
            'completed_at' => current_time( 'mysql' ),
        ], [ 'id' => $order->id ] );

        // پیامک تکمیل سفارش به استاد تسهیلگر
        $sc_table = TA_Database::table( 'story_critique_forms' );
        $ta       = TA_Database::get_row( "SELECT full_name, phone FROM {$sc_table} WHERE id = %d", [ $order->ta_id ] );
        if ( $ta && $ta->phone ) {
            $student  = get_userdata( $uid );
            $wc_order = wc_get_order( (int) $order->wc_order_id );
            TA_MeliPayamak::notify_ta_completed(
                $ta->phone,
                $ta->full_name,
                $student->display_name ?? '',
                $wc_order ? (string) $wc_order->get_order_number() : (string) $order->wc_order_id
            );
        }

        wp_send_json_success( [
            'redirect' => self::get_page_url( [ 'ta_view' => 'evaluate', 'corder' => $corder_id ] ),
        ] );
    }

    private function ta_avatar_url( int $user_id ): string {
        $default = 'https://mabnaschool.ir/wp-content/uploads/2025/11/Frame-1666952.svg';
        if ( ! $user_id ) return $default;
        $att_id = get_user_meta( $user_id, 'image', true );
        return $att_id ? wp_get_attachment_url( $att_id ) : $default;
    }

    private static function print_styles(): void {
        static $printed = false;
        if ( $printed ) return;
        $printed = true;
        ?>
        <style>
        .ta-co-wrap{
            --ta-black-01:#594d49; --ta-black-02:#a7a1a0; --ta-black-00:#797363;
            --ta-green-01:#38ab98; --ta-green-02:#2aad9e;
            --ta-warm-gray-02:#f0eee8; --ta-warm-gray-03:#f9f7f2;
            --ta-border:#e4e1d7; --ta-orange:#ee6940; --ta-white:#fff;
            max-width:820px;margin:0 auto;padding:30px;font-family:inherit;color:var(--ta-black-01)
        }
        .ta-co-eyebrow-row{display:flex;flex-direction:column;gap:8px;margin:6px 0 14px}
        .ta-co-eyebrow{font-size:20px;font-weight:800;margin:0}
        .ta-co-eyebrow-underline{width:50px;height:5px;background:var(--ta-green-01)}
        .ta-co-title{font-size:15px;font-weight:600;margin:0 0 16px;color:var(--ta-black-01)}
        .ta-co-intro-title{padding-top:0}
        .ta-co-hint{color:var(--ta-black-01);font-size:12px;margin:8px 0 0;text-align:justify}
        .ta-co-intro-copy{color:var(--ta-black-01);font-size:15px;line-height:1.9;margin:0 0 18px;text-align:justify}
        .ta-co-empty{color:var(--ta-black-02);padding:14px;text-align:center}

        /* ── آکاردئون تخصص/ژانر ── */
        .ta-co-accordion-list{display:flex;flex-direction:column;gap:16px}
        .ta-co-accordion{position:relative;margin-top:180px;padding-top:70px;background:var(--ta-white) !important;border:2px solid var(--ta-warm-gray-03) !important;border-radius:25px;transition:background .15s,border-radius .15s,border-width .15s;box-shadow:none !important;overflow:visible}
        .ta-co-accordion.ta-co-open{background:var(--ta-warm-gray-03) !important;border-width:1px !important;border-radius:20px}
        .ta-co-spec-cover{position:absolute;top:-150px;left:22px;width:250px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:26px;z-index:2}
        .ta-co-spec-cover img{object-fit: contain; display: block; width: 250px; height: 250px;}
        .ta-co-spec-cover-empty{background:linear-gradient(135deg,var(--ta-warm-gray-02),var(--ta-border))}
        .ta-co-accordion-head{width:100%;display:flex;flex-wrap: wrap;align-items:center;gap:10px;padding:16px 20px 16px 100px;background:transparent !important;background-image:none !important;border:none !important;box-shadow:none !important;cursor:pointer;text-align:right;color:inherit !important;font-family:inherit !important;font-weight:inherit !important;text-transform:none !important;text-decoration:none !important}
        .ta-co-spec-text{display:flex;flex-direction:column;gap:4px;text-align:right;min-width:0}
        .ta-co-spec-name{font-size:16px;font-family: var(--e-global-typography-0e8f2b7-font-family), IRANSansX;color:var(--ta-black-01) !important}
        .ta-co-spec-desc-inline{font-size:18px;color:var(--ta-black-00) !important;width: 100%;line-height:1.6;padding-top:60px;display:block}
        .ta-co-accordion-arrow{transition:transform .15s;flex-shrink:0;display:flex}
        .ta-co-accordion-arrow svg{transform:rotate(180deg);transition:transform .15s}
        .ta-co-accordion-arrow.ta-co-arrow-open svg{transform:rotate(0deg)}
        .ta-co-accordion-body{padding:0 20px 20px}

        /* ── کارت‌های استاد تسهیلگر ── */
        .ta-co-ta-grid{display:flex;flex-direction:column;gap:8px}
        .ta-co-ta-card{position:relative;display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--ta-white);border:none;border-radius:20px;padding:12px;cursor:pointer;transition:.15s;flex-wrap:wrap}
        .ta-co-ta-card.active{box-shadow:0 0 0 2px var(--ta-green-02) inset}
        .ta-co-ta-card input{position:absolute;opacity:0;pointer-events:none}
        .ta-co-ta-main{display:flex;gap:10px;align-items:center}
        .ta-co-avatar-wrap{position:relative;display:inline-flex;flex-shrink:0}
        .ta-co-ta-avatar{width:112px;height:112px;border-radius:15px !important;aspect-ratio:1;object-fit:cover;flex-shrink:0}
        .ta-co-avatar-expand{position:absolute;right:5px;bottom:5px;width:28px;height:24px;padding:0 !important;border:0;border-radius:6px;background:transparent !important;color:#38ab98 !important;font-size:12px;line-height:20px;cursor:pointer;z-index:2;display:inline-flex;align-items:center;justify-content:center}
        .ta-co-avatar-expand:hover{background:transparent !important;color:#38ab98 !important}
        .ta-co-ta-info{text-align:right}
        .ta-co-ta-info strong{display:block;font-size:15px;font-weight:600;color:var(--ta-black-01);margin-bottom:4px}
        .ta-co-ta-resume{font-size:11.5px;color:var(--ta-black-00);margin:6px 0 0;max-width:230px;text-align:right}
        .ta-co-ta-tags{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:4px}
        .ta-co-ta-tag{font-size:12px;font-weight:400;color:var(--ta-black-01);background:var(--ta-warm-gray-03);padding:4px 8px;border-radius:10px;display:inline-flex}
        .ta-co-ta-price{font-size:11px;font-weight:700;color:var(--ta-green-02)}
        .ta-co-ta-check-mark{position:absolute;top:8px;right:8px;display:none;width:20px;height:20px;border-radius:99px;background:var(--ta-green-02);color:#fff;font-size:12px;line-height:20px;text-align:center;z-index:1}
        .ta-co-ta-card.active .ta-co-ta-check-mark{display:block}
        .ta-co-ta-actions{display:flex;align-items:center;gap:8px;flex-shrink:0}
        .ta-co-ta-select-btn,.ta-co-ta-more-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:42px;border-radius:15px;font-size:12.5px;font-weight:700;cursor:pointer;border:none;padding:0 12px;white-space:nowrap}
        .ta-co-ta-select-btn{background:var(--ta-orange);color:#fff;}
        .ta-co-ta-card.active .ta-co-ta-select-btn{background:var(--ta-green-02)}
        .ta-co-ta-more-btn{background:var(--ta-white)!important;color:var(--ta-green-01)!important;border:1px solid var(--ta-warm-gray-02)}

        .ta-co-selected-bar{position:sticky;z-index:200;bottom:0;background:var(--ta-white);border-top:2px solid var(--ta-warm-gray-02);padding:14px;display:flex;justify-content:space-between;align-items:center;margin-top:12px;border-radius:0 0 14px 14px}

        /* ── کارت‌های عمومی گام ۲ (بارگذاری/نتیجهٔ قیمت) ── */
        .ta-co-card{background:var(--ta-warm-gray-03);border:2px solid var(--ta-white);border-radius:20px;margin-bottom:14px;overflow:hidden}
        .ta-co-card-section{padding:20px}
        .ta-co-card-heading{font-size:15px;font-weight:600;text-align:center;margin:0 0 14px;color:var(--ta-black-01)}
        .ta-co-card-actions{display:flex;gap:10px;justify-content:flex-end;padding:0 20px 20px}
        .ta-co-upload-box{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
        .ta-co-upload-pill{display:inline-flex;align-items:center;gap:8px;background:var(--ta-white);border-radius:10px;padding:9px 16px;font-size:13px;font-weight:700;color:var(--ta-green-02);cursor:pointer;flex:1;min-width:180px;justify-content:center}
        .ta-co-word-count-line{font-size:13px;margin:0 0 10px}
        .ta-co-price-table{width:100%;border-collapse:collapse;margin:0 0 4px;font-size:13px}
        .ta-co-price-table th,.ta-co-price-table td{padding:8px 10px;border-bottom:1px solid var(--ta-border);text-align:right}
        .ta-co-price-table tfoot td{font-weight:800;border-top:2px solid var(--ta-border)}

        /* ── دکمه‌ها ── */
        .ta-btn{border-radius:10px;padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;border:2px solid transparent;transition:.15s}
        .ta-btn-primary{background:var(--ta-orange);color:#fff}
        .ta-btn-primary:hover{opacity:.92}
        .ta-co-reorder-cta{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#7c3aed,#db2777);border-color:#6d28d9;color:#fff !important;box-shadow:0 8px 18px rgba(124,58,237,.28);font-weight:800;padding:11px 16px}
        .ta-co-reorder-cta:hover,.ta-co-reorder-cta:focus{opacity:1;color:#fff !important;transform:translateY(-1px);box-shadow:0 11px 22px rgba(124,58,237,.36)}
        .ta-co-ta-check:disabled{cursor:not-allowed}
        .ta-co-ta-card:has(.ta-co-ta-check:disabled){outline:3px solid rgba(124,58,237,.24);background:#faf5ff}
        .ta-btn-outline{background:var(--ta-white);color:var(--ta-black-01);border-color:var(--ta-border)}
        .ta-btn-lg{padding:13px 26px;font-size:14px}

        .ta-co-checkout-form{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
        .ta-co-orders-list{display:flex;flex-direction:column;gap:10px}
        .ta-co-order-card{border:2px solid var(--ta-border);border-radius:14px;padding:14px;font-size:13px;display:flex;flex-direction:column;gap:6px;background:var(--ta-white)}
        .ta-co-ta-note{background:var(--ta-warm-gray-03);border-radius:10px;padding:8px;font-size:12px;color:var(--ta-black-00)}
        .ta-co-rating{font-size:22px;color:#f59e0b;cursor:pointer}
        .ta-co-star{margin-left:4px}
        .ta-co-queue-badge{display:inline-block;font-size:11px;font-weight:700;color:#92400e;background:#fef3c7;padding:3px 9px;border-radius:99px}
        .ta-co-ta-tags .ta-co-queue-badge{font-size:12px;font-weight:400;color:var(--ta-black-01);background:var(--ta-warm-gray-03);padding:4px 8px;border-radius:10px}
        .ta-co-ta-tags .ta-co-queue-empty{color:#166534;background:#dcfce7}
        .ta-co-queue-empty{color:#166534;background:#dcfce7}
        .ta-co-embedded{max-width:100%}

        /* ── مودال اطلاعات بیشتر استاد تسهیلگر ── */
        .ta-co-modal-overlay{position:fixed;inset:0;background:rgba(89,77,73,.55);z-index:100000;align-items:center;justify-content:center;padding:16px}
        .ta-co-modal-box{background:var(--ta-white);border-radius:20px;padding:22px;width:420px;max-width:100%;height:min(720px,88vh);max-height:88vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,.25)}
        .ta-co-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:14px}
        .ta-co-modal-name-wrap{display:flex;flex-direction:column;align-items:flex-start;gap:6px;text-align:right}
        .ta-co-modal-name-wrap strong{font-size:17px;font-weight:700;color:var(--ta-black-01)}
        .ta-co-modal-avatar{width:64px;height:64px;border-radius:14px;flex-shrink:0}
        .ta-co-modal-resume{font-size:13px;line-height:1.9;color:var(--ta-black-00);text-align:justify;margin:0 0 20px;white-space:pre-line;min-height:180px;flex:1;overflow-y:auto;overflow-x:hidden;padding:4px 8px 8px 2px;scrollbar-width:thin}
        .ta-co-modal-actions{display:flex;gap:10px}
        .ta-co-modal-btn{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:8px;height:44px;border-radius:14px;font-size:13px;font-weight:700;cursor:pointer;border:none;transition:.15s}
        .ta-co-modal-btn-close{background:var(--ta-warm-gray-03);color:var(--ta-black-01)}
        .ta-co-modal-btn-close:hover{background:var(--ta-warm-gray-02)}
        .ta-co-modal-btn-select{background:var(--ta-green-02);color:#fff}
        .ta-co-modal-btn-select:hover{opacity:.92}
        .ta-co-image-lightbox{position:fixed;inset:0;background:rgba(0,0,0,.78);z-index:100001;align-items:center;justify-content:center;padding:24px;cursor:zoom-out}
        .ta-co-image-lightbox img{display:block;max-width:min(92vw,900px);max-height:88vh;width:auto;height:auto;border-radius:16px;object-fit:contain;box-shadow:0 20px 70px rgba(0,0,0,.45);cursor:default}
        .ta-co-image-lightbox-close{position:absolute;top:18px;right:22px;width:42px;height:42px;border:0;border-radius:50%;background:#fff;color:#594d49;font-size:30px;line-height:36px;cursor:pointer}
        @media (max-width: 600px){
            .ta-co-accordion-head{padding:20px}
            .ta-co-spec-desc-inline{padding-top:0;text-align:justify}
            .ta-co-ta-select-btn{width:100%;flex:1}
            .ta-co-ta-tag.ta-co-ta-role,.ta-co-queue-badge.ta-co-queue-empty{text-align:right;justify-content:flex-start}
        }
        </style>
        <?php
    }
}
