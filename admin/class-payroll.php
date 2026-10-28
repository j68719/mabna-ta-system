<?php
defined( 'ABSPATH' ) || exit;

/**
 * TA_Payroll — حسابداری و حق‌الزحمه استادیارها
 * نسخه 1.2.0
 *
 * ویژگی‌های جدید:
 *  - خلاصه به‌تفکیک استادیار پیش‌فرض برای آخرین ترم فعال
 *  - درخواست تایید حق‌الزحمه به ازای هر استادیار × ترم
 *  - ستون وضعیت تایید در جدول حق‌الزحمه
 *  - فیلد توضیحات ادمین و استادیار
 *  - علامت‌گذاری پرداخت‌شده
 */
class TA_Payroll {

    private const PER_PAGE        = 30;
    private const OPTION_RATES    = 'ta_payroll_product_rates';
    private const OPTION_PRICES   = 'ta_payroll_product_prices';
    private const OPTION_EXCL_IDS = 'ta_payroll_excluded_ids';
    private const OPTION_DEF_RATE = 'ta_payroll_default_rate';
    private const NONCE_ACTION    = 'ta_payroll_nonce';

    private const COURSE_LABELS = [
        'intro'         => 'مقدماتی',
        'advanced'      => 'پیشرفته',
        'creative'      => 'خلاق',
        'professional'  => 'حرفه‌ای',
        'critique'      => 'نقد اثر',
        'basic_writing' => 'نویسندگی پایه',
        'ladder'        => 'نردبان حرفه‌ای',
    ];

    private const STATUSES = [
        'active'    => [ 'label' => 'فعال',       'color' => '#10b981' ],
        'completed' => [ 'label' => 'تکمیل‌شده',  'color' => '#3b82f6' ],
        'canceled'  => [ 'label' => 'انصراف',      'color' => '#ef4444' ],
        'pending'   => [ 'label' => 'در انتظار',   'color' => '#f59e0b' ],
    ];

    private const REQUEST_STATUSES = [
        'pending'     => [ 'label' => 'در انتظار پاسخ استادیار', 'color' => '#f59e0b', 'icon' => '⏳' ],
        'ta_approved' => [ 'label' => 'تایید استادیار',           'color' => '#8b5cf6', 'icon' => '✅' ],
        'ta_rejected' => [ 'label' => 'رد استادیار',              'color' => '#f97316', 'icon' => '↩️' ],
        'approved'    => [ 'label' => 'تایید نهایی ادمین',        'color' => '#10b981', 'icon' => '✅' ],
        'rejected'    => [ 'label' => 'رد شده توسط ادمین',        'color' => '#ef4444', 'icon' => '❌' ],
        'paid'        => [ 'label' => 'پرداخت شده',               'color' => '#3b82f6', 'icon' => '💳' ],
    ];

    // ─── constructor ──────────────────────────────────────────────────────────
    public function __construct() {
        add_action( 'wp_ajax_ta_payroll_toggle_exclude',       [ $this, 'ajax_toggle_exclude'       ] );
        add_action( 'wp_ajax_ta_payroll_save_def_rate',        [ $this, 'ajax_save_def_rate'        ] );
        add_action( 'wp_ajax_ta_payroll_save_product_cfg',     [ $this, 'ajax_save_product_cfg'     ] );
        add_action( 'wp_ajax_ta_payroll_reset_product_cfg',    [ $this, 'ajax_reset_product_cfg'    ] );
        add_action( 'wp_ajax_ta_payroll_export_csv',           [ $this, 'ajax_export_csv'           ] );
        add_action( 'wp_ajax_ta_payroll_export_summary',      [ $this, 'ajax_export_summary'       ] );
        // handlers جدید
        add_action( 'wp_ajax_ta_payroll_send_request',         [ $this, 'ajax_send_request'         ] );
        add_action( 'wp_ajax_ta_payroll_review_request',       [ $this, 'ajax_review_request'       ] );
        add_action( 'wp_ajax_ta_payroll_mark_paid',            [ $this, 'ajax_mark_paid'            ] );
        add_action( 'wp_ajax_ta_payroll_get_requests',         [ $this, 'ajax_get_requests'         ] );
        // handler فرانت‌اند (استادیار)
        add_action( 'wp_ajax_ta_payroll_ta_respond',           [ $this, 'ajax_ta_respond'           ] );
        add_action( 'wp_ajax_ta_payroll_ta_get_items',         [ $this, 'ajax_ta_get_items'         ] );
        // تاریخچه لاگ
        add_action( 'wp_ajax_ta_payroll_get_logs',             [ $this, 'ajax_get_logs'             ] );
        add_action( 'wp_ajax_ta_payroll_ta_get_logs',          [ $this, 'ajax_ta_get_logs'          ] );
        // مسیر مستقل تفاوت
        add_action( 'wp_ajax_ta_payroll_diff_ta_respond',      [ $this, 'ajax_diff_ta_respond'      ] );
        add_action( 'wp_ajax_ta_payroll_diff_review',          [ $this, 'ajax_diff_review'          ] );
        add_action( 'wp_ajax_ta_payroll_diff_mark_paid',       [ $this, 'ajax_diff_mark_paid'       ] );
        // تعدیلات (مبلغ اضافی / کسورات)
        add_action( 'wp_ajax_ta_payroll_save_adjustment',      [ $this, 'ajax_save_adjustment'      ] );
        add_action( 'wp_ajax_ta_payroll_delete_adjustment',    [ $this, 'ajax_delete_adjustment'    ] );
        add_action( 'wp_ajax_ta_payroll_get_adjustments',      [ $this, 'ajax_get_adjustments'      ] );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // AJAX — موجود
    // ═════════════════════════════════════════════════════════════════════════

    public function ajax_toggle_exclude(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'دسترسی مجاز نیست.' );
        $id      = absint( $_POST['id']      ?? 0 );
        $exclude = (bool)( $_POST['exclude'] ?? false );
        $term_id = absint( $_POST['term_id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'شناسه نامعتبر.' );
        $excluded = $this->get_excluded_ids();
        if ( $exclude ) { $excluded[ $id ] = true; } else { unset( $excluded[ $id ] ); }
        update_option( self::OPTION_EXCL_IDS, wp_json_encode( array_keys( $excluded ) ), false );

        // محاسبه خلاصه به‌روز برای ارسال به JS
        $def_rate       = floatval( get_option( self::OPTION_DEF_RATE, 20 ) );
        $product_rates  = $this->get_product_rates();
        $product_prices = $this->get_product_prices();
        $filters        = [ 'term' => $term_id, 'ta' => 0, 'course_type' => '', 'calc' => '', 'search' => '' ];
        $all_rows       = $this->get_rows( $filters, true );
        $summary        = $this->build_summary( $all_rows, $def_rate, $product_rates, $product_prices, $excluded );

        // تبدیل آرایه by_ta به فرمت ساده برای JS
        $by_ta_simple = [];
        foreach ( $summary['by_ta'] as $ta_id => $d ) {
            $by_ta_simple[ $ta_id ] = [
                'included' => $d['included'],
                'excluded' => $d['excluded'],
                'fee'      => $d['fee'],
            ];
        }

        wp_send_json_success( [
            'excluded'       => $exclude,
            'id'             => $id,
            'total_fee'      => $summary['total_fee'],
            'included_count' => $summary['included_count'],
            'excluded_count' => $summary['excluded_count'],
            'by_ta'          => $by_ta_simple,
        ] );
    }

    public function ajax_save_def_rate(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
        $rate = floatval( $_POST['rate'] ?? 0 );
        if ( $rate < 0 || $rate > 100 ) wp_send_json_error( 'درصد باید بین ۰ تا ۱۰۰ باشد.' );
        update_option( self::OPTION_DEF_RATE, $rate, false );
        wp_send_json_success( [ 'rate' => $rate ] );
    }

    public function ajax_save_product_cfg(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
        $pid   = absint( $_POST['product_id'] ?? 0 );
        $field = sanitize_key( $_POST['field'] ?? '' );
        $value = floatval( $_POST['value'] ?? 0 );
        if ( ! $pid ) wp_send_json_error( 'شناسه محصول نامعتبر.' );
        if ( $field === 'rate' ) {
            if ( $value < 0 || $value > 100 ) wp_send_json_error( 'درصد باید بین ۰ تا ۱۰۰ باشد.' );
            $rates = $this->get_product_rates(); $rates[$pid] = $value;
            update_option( self::OPTION_RATES, wp_json_encode( $rates ), false );
        } elseif ( $field === 'price' ) {
            if ( $value < 0 ) wp_send_json_error( 'قیمت نمی‌تواند منفی باشد.' );
            $prices = $this->get_product_prices(); $prices[$pid] = $value;
            update_option( self::OPTION_PRICES, wp_json_encode( $prices ), false );
        } else { wp_send_json_error( 'فیلد نامعتبر.' ); }
        wp_send_json_success();
    }

    public function ajax_reset_product_cfg(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
        $pid   = absint( $_POST['product_id'] ?? 0 );
        $field = sanitize_key( $_POST['field'] ?? '' );
        if ( ! $pid ) wp_send_json_error();
        if ( $field === 'rate' ) {
            $rates = $this->get_product_rates(); unset( $rates[$pid] );
            update_option( self::OPTION_RATES, wp_json_encode( $rates ), false );
            wp_send_json_success( [ 'value' => floatval( get_option( self::OPTION_DEF_RATE, 20 ) ) ] );
        } elseif ( $field === 'price' ) {
            $prices = $this->get_product_prices(); unset( $prices[$pid] );
            update_option( self::OPTION_PRICES, wp_json_encode( $prices ), false );
            wp_send_json_success( [ 'value' => $this->get_wc_price( $pid ) ] );
        }
        wp_send_json_error();
    }

    public function ajax_export_csv(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی مجاز نیست.' );
        $rows     = $this->get_rows( $this->build_filters_from_request(), true );
        $excluded = $this->get_excluded_ids();
        $def_rate = floatval( get_option( self::OPTION_DEF_RATE, 20 ) );
        $rates    = $this->get_product_rates();
        $prices   = $this->get_product_prices();
        $requests = $this->get_all_requests_map();

        while ( ob_get_level() ) ob_end_clean();

        $writer = new TA_Xlsx_Writer();
        $writer->set_rtl( true );
        $writer->add_row( [
            'ردیف', 'نام هنرجو', 'ایمیل هنرجو', 'نام استادیار', 'ایمیل استادیار',
            'دوره', 'نوع دوره', 'ترم', 'وضعیت',
            'قیمت دوره (تومان)', 'قیمت محاسبه (تومان)', 'درصد حق‌الزحمه', 'مبلغ حق‌الزحمه (تومان)',
            'تاریخ تخصیص', 'شامل محاسبه', 'وضعیت تایید',
        ], true );

        $i = 1;
        foreach ( $rows as $r ) {
            $pid        = (int) $r->product_id;
            $wc_price   = $this->get_wc_price( $pid );
            $calc_price = $prices[$pid] ?? $wc_price;
            $rate       = $rates[$pid]  ?? $def_rate;
            $is_excl    = isset( $excluded[(int)$r->id] );
            $fee        = $is_excl ? 0 : round( $calc_price * $rate / 100 );
            $req_key    = $r->ta_id . '_' . $r->term_id;
            $req_status = $requests[$req_key]['status'] ?? '—';
            $req_label  = self::REQUEST_STATUSES[$req_status]['label'] ?? $req_status;
            $writer->add_row( [
                $i++,
                $r->student_name  ?? '', $r->student_email ?? '',
                $r->ta_name       ?? '', $r->ta_email      ?? '',
                $r->product_name  ?? '',
                self::COURSE_LABELS[$r->course_type] ?? $r->course_type,
                $r->term_name ?? '—',
                self::STATUSES[$r->status]['label'] ?? $r->status,
                number_format( $wc_price ),
                number_format( $calc_price ),
                $rate . '%',
                $is_excl ? '—' : number_format( $fee ),
                $r->created_at,
                $is_excl ? 'خیر' : 'بله',
                $req_label,
            ] );
        }
        $writer->output( 'ta-payroll-' . date('Y-m-d') . '.xlsx' );
        exit;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // AJAX — خروجی XLSX جدول خلاصه به تفکیک استادیار
    // ═════════════════════════════════════════════════════════════════════════

    public function ajax_export_summary(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی مجاز نیست.' );

        $filters          = $this->build_filters_from_request();
        $all_rows         = $this->get_rows( $filters, true );
        $excluded         = $this->get_excluded_ids();
        $def_rate         = floatval( get_option( self::OPTION_DEF_RATE, 20 ) );
        $product_rates    = $this->get_product_rates();
        $product_prices   = $this->get_product_prices();
        $summary          = $this->build_summary( $all_rows, $def_rate, $product_rates, $product_prices, $excluded );
        $requests_map     = $this->get_all_requests_map();
        $adjustments_map  = $this->get_all_adjustments_map();

        while ( ob_get_level() ) ob_end_clean();

        $writer = new TA_Xlsx_Writer();
        $writer->set_rtl( true );
        $writer->add_row( [
            'ردیف', 'نام استادیار', 'ایمیل',
            'تعداد شامل', 'تعداد خارج',
            'تنوع دوره', 'انواع دوره',
            'جمع قیمت محاسبه (تومان)', 'حق‌الزحمه پایه (تومان)',
            'اضافه تعدیل (تومان)', 'کسر تعدیل (تومان)',
            'مبلغ نهایی (تومان)',
            'مبلغ تایید/پرداخت (تومان)',
            'وضعیت تایید',
        ], true );

        $i = 1;
        foreach ( $summary['by_ta'] as $ta_id => $d ) {
            $req_key     = $ta_id . '_' . ( $filters['term'] ?? 0 );
            $req         = $requests_map[ $req_key ] ?? null;
            $req_st      = $req ? ( $req['status'] ?? null ) : null;
            $req_label   = $req_st ? ( self::REQUEST_STATUSES[$req_st]['label'] ?? $req_st ) : '—';
            $ta_adjs     = $adjustments_map[ $ta_id ] ?? [];
            $adj_extra   = array_sum( array_column( array_filter( $ta_adjs, fn($a) => $a['type']==='extra'     ), 'amount' ) );
            $adj_deduct  = array_sum( array_column( array_filter( $ta_adjs, fn($a) => $a['type']==='deduction' ), 'amount' ) );
            $adj_net     = $this->calc_adjustment_net( $ta_adjs );
            $final_fee   = $d['fee'] + $adj_net;
            $approved    = $req ? floatval( $req['approved_fee'] ?? $req['total_fee'] ?? 0 ) : 0;
            $course_list = implode( '، ', array_map(
                fn($ct) => ( self::COURSE_LABELS[$ct] ?? $ct ) . ' (' . ( $d['course_type_counts'][$ct] ?? 0 ) . ')',
                $d['course_types']
            ) );

            $writer->add_row( [
                $i++,
                $d['name']                        ?? '',
                $d['email']                       ?? '',
                $d['included'],
                $d['excluded'],
                $d['variety'],
                $course_list,
                number_format( $d['total_calc_price'] ),
                number_format( $d['fee'] ),
                $adj_extra  > 0 ? number_format( $adj_extra  ) : '—',
                $adj_deduct > 0 ? number_format( $adj_deduct ) : '—',
                number_format( $final_fee ),
                $approved   > 0 ? number_format( $approved   ) : '—',
                $req_label,
            ] );
        }

        $writer->output( 'summary-ta-' . date('Y-m-d') . '.xlsx' );
        exit;
    }

    /** آرایه تمام تعدیلات به صورت ta_id => [ ...adj ] */
    private function get_all_adjustments_map(): array {
        global $wpdb;
        $tbl = TA_Database::table('payroll_adjustments');
        if ( ! $wpdb->get_var("SHOW TABLES LIKE '{$tbl}'") ) return [];
        $rows = $wpdb->get_results("SELECT * FROM {$tbl}") ?: [];
        $map  = [];
        foreach ( $rows as $r ) {
            $map[ (int)$r->ta_id ][] = (array) $r;
        }
        return $map;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // AJAX — جدید: مدیریت درخواست‌های تایید (ادمین)
    // ═════════════════════════════════════════════════════════════════════════

    /** ارسال / به‌روزرسانی درخواست تایید برای یک استادیار + ترم */
    public function ajax_send_request(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'دسترسی مجاز نیست.' );

        global $wpdb;
        $ta_id   = absint( $_POST['ta_id']   ?? 0 );
        $term_id = absint( $_POST['term_id'] ?? 0 );
        $note    = sanitize_textarea_field( $_POST['admin_note'] ?? '' );
        if ( ! $ta_id || ! $term_id ) wp_send_json_error( 'اطلاعات ناقص.' );

        $base_total  = $this->calc_ta_term_fee( $ta_id, $term_id );
        $adj_map     = $this->get_adjustments_map( $term_id );
        $adj_net     = $this->calc_adjustment_net( $adj_map[$ta_id] ?? [] );
        $total       = $base_total + $adj_net;
        $tbl     = TA_Database::table('payroll_requests');
        $log_tbl = TA_Database::table('payroll_request_logs');
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$tbl} WHERE ta_id=%d AND term_id=%d", $ta_id, $term_id
        ) );

        if ( $existing ) {
            // اگر وضعیت paid است ولی مبلغ تغییر کرده، resend مجاز است
            if ( $existing->status === 'paid' ) {
                $ref = floatval( $existing->approved_fee ?: $existing->total_fee );
                if ( abs( $total - $ref ) <= 1 ) {
                    wp_send_json_error( 'این مورد قبلاً پرداخت شده و مبلغی تغییر نکرده است.' );
                }
            }
            // قبل از resend مبلغ تایید‌شده را در prev_approved_fee نگه می‌داریم
            // اولویت: approved_fee → prev_approved_fee قبلی (اگر resend مکرر)
            $prev_approved = floatval( $existing->approved_fee )
                          ?: floatval( $existing->prev_approved_fee )
                          ?: floatval( $existing->total_fee );
            // محاسبه تفاوت — مسیر مستقل
            $diff_amount = round( $total - $prev_approved, 2 );
            $has_diff    = abs( $diff_amount ) > 1;

            // wpdb->update از NULL پشتیبانی نمی‌کند — از raw query استفاده می‌کنیم
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$tbl}
                 SET total_fee         = %f,
                     approved_fee      = NULL,
                     prev_approved_fee = %f,
                     status            = 'pending',
                     admin_note        = %s,
                     ta_note           = NULL,
                     reviewed_at       = NULL,
                     paid_at           = NULL,
                     requested_at      = %s,
                     diff_amount       = %f,
                     diff_status       = %s,
                     diff_approved_fee = NULL,
                     diff_ta_note      = NULL,
                     diff_admin_note   = %s,
                     diff_reviewed_at  = NULL,
                     diff_paid_at      = NULL
                 WHERE id = %d",
                $total,
                $prev_approved,
                $note,
                current_time('mysql'),
                $has_diff ? $diff_amount : null,
                $has_diff ? 'pending' : null,
                $has_diff ? $note : null,
                $existing->id
            ) );
            $req_id = (int) $existing->id;
            $action = 'resent';
            // debug: اگر update شکست خورد، خطا برگردان
            if ( $wpdb->last_error ) {
                wp_send_json_error( 'DB Error: ' . $wpdb->last_error );
            }
            // پاک کردن object cache تا reload صفحه داده تازه بخواند
            wp_cache_flush();
        } else {
            $wpdb->insert( $tbl,
                [ 'ta_id'=>$ta_id, 'term_id'=>$term_id, 'total_fee'=>$total,
                  'status'=>'pending', 'admin_note'=>$note ],
                [ '%d','%d','%f','%s','%s' ]
            );
            $req_id = (int) $wpdb->insert_id;
            $action = 'sent';
        }

        // ثبت لاگ
        $wpdb->insert( $log_tbl, [
            'request_id' => $req_id,
            'ta_id'      => $ta_id,
            'term_id'    => $term_id,
            'action'     => $action,
            'actor_type' => 'admin',
            'actor_id'   => get_current_user_id(),
            'total_fee'  => $total,
            'note'       => $note,
        ], [ '%d','%d','%d','%s','%s','%d','%f','%s' ] );

        wp_send_json_success( [ 'id' => $req_id, 'total_fee' => $total ] );
    }

    /** بررسی درخواست توسط ادمین: تایید یا رد */
    public function ajax_review_request(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'دسترسی مجاز نیست.' );

        global $wpdb;
        $id     = absint( $_POST['req_id'] ?? 0 );
        $status = sanitize_key( $_POST['status'] ?? '' );
        $note   = sanitize_textarea_field( $_POST['admin_note'] ?? '' );
        if ( ! $id || ! in_array( $status, ['approved','rejected'], true ) ) wp_send_json_error( 'اطلاعات نامعتبر.' );

        $tbl     = TA_Database::table('payroll_requests');
        $log_tbl = TA_Database::table('payroll_request_logs');
        $req     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id=%d", $id ) );
        if ( ! $req ) wp_send_json_error( 'درخواست یافت نشد.' );
        if ( $req->status === 'paid' ) wp_send_json_error( 'این مورد پرداخت شده است.' );
        // ادمین می‌تواند از هر وضعیت غیر paid تایید/رد کند
        // (pending, ta_approved, ta_rejected, rejected)

        $update_data = [ 'status'=>$status, 'admin_note'=>$note, 'reviewed_at'=>current_time('mysql') ];
        if ( $status === 'approved' ) {
            $update_data['approved_fee'] = $req->total_fee;
        }
        // فرمت‌بندی دقیق برای هر فیلد
        $fmt_map = [
            'status'       => '%s',
            'ta_note'      => '%s',
            'reviewed_at'  => '%s',
            'approved_fee' => '%f',
            'total_fee'    => '%f',
        ];
        $formats = array_map( fn($k) => $fmt_map[$k] ?? '%s', array_keys($update_data) );
        $wpdb->update( $tbl, $update_data, [ 'id' => $id ], $formats, [ '%d' ] );

        // لاگ با پیشوند admin برای وضوح بیشتر
        $log_action = $status === 'approved' ? 'admin_approved' : 'admin_rejected';
        $wpdb->insert( $log_tbl, [
            'request_id' => $id,
            'ta_id'      => $req->ta_id,
            'term_id'    => $req->term_id,
            'action'     => $log_action,
            'actor_type' => 'admin',
            'actor_id'   => get_current_user_id(),
            'total_fee'  => $req->total_fee,
            'note'       => $note,
        ], [ '%d','%d','%d','%s','%s','%d','%f','%s' ] );

        wp_send_json_success( [ 'status' => $status ] );
    }

    /** علامت‌گذاری به‌عنوان پرداخت‌شده */
    public function ajax_mark_paid(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'دسترسی مجاز نیست.' );

        global $wpdb;
        $id   = absint( $_POST['req_id'] ?? 0 );
        $note = sanitize_textarea_field( $_POST['admin_note'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'شناسه نامعتبر.' );

        $tbl     = TA_Database::table('payroll_requests');
        $log_tbl = TA_Database::table('payroll_request_logs');
        $req     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id=%d", $id ) );
        if ( ! $req || ! in_array( $req->status, ['approved', 'ta_approved'], true ) ) wp_send_json_error( 'فقط موارد تایید‌شده قابل پرداخت هستند.' );

        $wpdb->update( $tbl,
            [ 'status'=>'paid', 'paid_at'=>current_time('mysql'), 'admin_note'=>$note ],
            [ 'id' => $id ], [ '%s','%s','%s' ], [ '%d' ]
        );

        // لاگ
        $wpdb->insert( $log_tbl, [
            'request_id' => $id,
            'ta_id'      => $req->ta_id,
            'term_id'    => $req->term_id,
            'action'     => 'paid',
            'actor_type' => 'admin',
            'actor_id'   => get_current_user_id(),
            'total_fee'  => $req->approved_fee ?? $req->total_fee,
            'note'       => $note,
        ], [ '%d','%d','%d','%s','%s','%d','%f','%s' ] );

        wp_send_json_success();
    }

    /** لیست درخواست‌ها برای یک ترم (AJAX) */
    public function ajax_get_requests(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $term_id = absint( $_POST['term_id'] ?? 0 );
        $rows = $this->get_requests_for_term( $term_id );
        wp_send_json_success( $rows );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // AJAX — فرانت‌اند استادیار
    // ═════════════════════════════════════════════════════════════════════════

    /** استادیار: پاسخ به درخواست (توضیحات + تایید/رد) */
    public function ajax_ta_respond(): void {
        check_ajax_referer( 'ta_dashboard_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( 'لطفاً وارد شوید.' );

        global $wpdb;
        $uid    = get_current_user_id();
        $id     = absint( $_POST['req_id'] ?? 0 );
        $action = sanitize_key( $_POST['ta_action'] ?? '' ); // 'approve' | 'reject'
        $note   = sanitize_textarea_field( $_POST['ta_note'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'شناسه نامعتبر.' );

        // تایید مالکیت
        $ta_tbl = TA_Database::table('assistants');
        $ta = $wpdb->get_row( $wpdb->prepare("SELECT id FROM {$ta_tbl} WHERE user_id=%d", $uid) );
        if ( ! $ta ) wp_send_json_error( 'دسترسی مجاز نیست.' );

        $tbl = TA_Database::table('payroll_requests');
        $req = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$tbl} WHERE id=%d AND ta_id=%d", $id, $ta->id) );
        if ( ! $req ) wp_send_json_error( 'درخواست یافت نشد.' );
        if ( $req->status !== 'pending' ) wp_send_json_error( 'این درخواست قبلاً پردازش شده.' );

        // ta_action: 'approve' | 'reject' | 'approve_prev'
        // approve_prev = استادیار مبلغ قبلی را تایید می‌کند (نه مبلغ جدید)
        $new_status  = $action === 'reject' ? 'ta_rejected' : 'ta_approved';
        $log_action  = $action === 'reject' ? 'ta_rejected' : 'ta_approved';
        $log_fee     = (float) $req->total_fee;

        $update_data = [ 'status' => $new_status, 'ta_note' => $note, 'reviewed_at' => current_time('mysql') ];

        if ( $action === 'approve' ) {
            // تایید مبلغ جدید
            $update_data['approved_fee'] = $req->total_fee;

        } elseif ( $action === 'approve_prev' ) {
            // اول از DB می‌خوانیم
            $prev_fee = floatval( $req->prev_approved_fee ?? 0 );
            if ( $prev_fee <= 0 ) $prev_fee = floatval( $_POST['prev_fee'] ?? 0 );
            if ( $prev_fee <= 0 ) wp_send_json_error( 'مبلغ قبلی یافت نشد.' );
            $update_data['approved_fee'] = $prev_fee;
            $update_data['total_fee']    = $prev_fee;
            $log_fee    = $prev_fee;
            $log_action = 'ta_approved_prev';
        }

        // فرمت‌بندی دقیق برای هر فیلد
        $fmt_map = [
            'status'       => '%s',
            'ta_note'      => '%s',
            'reviewed_at'  => '%s',
            'approved_fee' => '%f',
            'total_fee'    => '%f',
        ];
        $formats = array_map( fn($k) => $fmt_map[$k] ?? '%s', array_keys($update_data) );
        $wpdb->update( $tbl, $update_data, [ 'id' => $id ], $formats, [ '%d' ] );

        // لاگ
        $log_tbl = TA_Database::table('payroll_request_logs');
        $wpdb->insert( $log_tbl, [
            'request_id' => $id,
            'ta_id'      => $req->ta_id,
            'term_id'    => $req->term_id,
            'action'     => $log_action,
            'actor_type' => 'ta',
            'actor_id'   => $uid,
            'total_fee'  => $log_fee,
            'note'       => $note,
        ], [ '%d','%d','%d','%s','%s','%d','%f','%s' ] );

        wp_send_json_success( [ 'status' => $new_status, 'approved_fee' => $update_data['approved_fee'] ?? null ] );
    }

    /** استادیار: دریافت لیست درخواست‌های مربوط به خودش + جزئیات تخصیص‌ها */
    public function ajax_ta_get_items(): void {
        check_ajax_referer( 'ta_dashboard_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error();

        global $wpdb;
        $uid = get_current_user_id();
        $ta_tbl = TA_Database::table('assistants');
        $ta = $wpdb->get_row( $wpdb->prepare("SELECT id FROM {$ta_tbl} WHERE user_id=%d", $uid) );
        if ( ! $ta ) wp_send_json_error();

        $tbl  = TA_Database::table('payroll_requests');
        $t    = TA_Database::table('terms');
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, t.name AS term_name
             FROM {$tbl} r
             JOIN {$t} t ON t.id = r.term_id
             WHERE r.ta_id = %d AND r.status IN ('pending','ta_approved','ta_rejected','approved','rejected','paid')
             ORDER BY r.requested_at DESC",
            $ta->id
        ) ) ?: [];

        // برای هر درخواست، جزئیات تخصیص‌های آن ترم را اضافه می‌کنیم
        $a_tbl  = TA_Database::table('assignments');
        $excluded = $this->get_excluded_ids();
        $rates    = $this->get_product_rates();
        $prices   = $this->get_product_prices();
        $def_rate = floatval( get_option( self::OPTION_DEF_RATE, 20 ) );

        foreach ( $rows as &$row ) {
            $assignments = $wpdb->get_results( $wpdb->prepare(
                "SELECT a.id, a.product_id, a.course_type, a.status AS assign_status, a.created_at,
                        su.display_name AS student_name, p.post_title AS product_name
                 FROM {$a_tbl} a
                 JOIN {$wpdb->users} su ON su.ID = a.student_id
                 LEFT JOIN {$wpdb->posts} p ON p.ID = a.product_id
                 WHERE a.ta_id = %d AND a.term_id = %d
                 ORDER BY a.created_at DESC",
                $ta->id, $row->term_id
            ) ) ?: [];

            $breakdown = [];
            foreach ( $assignments as $asgn ) {
                $pid        = (int) $asgn->product_id;
                $calc_price = $prices[$pid] ?? $this->get_wc_price($pid);
                $rate       = $rates[$pid]  ?? $def_rate;
                $fee        = isset($excluded[$asgn->id]) ? 0 : round($calc_price * $rate / 100);
                $breakdown[] = [
                    'id'           => $asgn->id,
                    'student_name' => $asgn->student_name,
                    'product_name' => $asgn->product_name,
                    'course_type'  => $asgn->course_type,
                    'calc_price'   => $calc_price,
                    'rate'         => $rate,
                    'fee'          => $fee,
                    'excluded'     => isset($excluded[$asgn->id]),
                ];
            }
            $row->breakdown = $breakdown;
        }
        unset($row);

        wp_send_json_success( $rows );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // AJAX — تاریخچه لاگ‌ها
    // ═════════════════════════════════════════════════════════════════════════

    /** ادمین: دریافت لاگ یک درخواست */
    public function ajax_get_logs(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'access' );

        global $wpdb;
        $req_id = absint( $_POST['req_id'] ?? 0 );
        if ( ! $req_id ) wp_send_json_error( 'invalid_id' );

        // اطمینان از وجود جدول لاگ (migration ممکن است هنوز اجرا نشده باشد)
        TA_Database::maybe_install();

        $log_tbl = TA_Database::table('payroll_request_logs');
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.*, u.display_name AS actor_name
             FROM {$log_tbl} l
             LEFT JOIN {$wpdb->users} u ON u.ID = l.actor_id
             WHERE l.request_id = %d ORDER BY l.created_at ASC",
            $req_id
        ) );

        if ( $wpdb->last_error ) {
            wp_send_json_error( 'db_error: ' . $wpdb->last_error );
        }

        // اگر جدول خالی است ولی request وجود دارد، یک لاگ ترکیبی از خود request بسازیم
        $rows = $rows ?: [];
        if ( empty( $rows ) ) {
            $req_tbl = TA_Database::table('payroll_requests');
            $req = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$req_tbl} WHERE id=%d", $req_id ) );
            if ( $req ) {
                $actor = get_userdata( (int)( $req->reviewed_at ? get_current_user_id() : 0 ) );
                $rows = [ (object)[
                    'id'         => 0,
                    'request_id' => $req->id,
                    'ta_id'      => $req->ta_id,
                    'term_id'    => $req->term_id,
                    'action'     => 'sent',
                    'actor_type' => 'admin',
                    'actor_id'   => 0,
                    'total_fee'  => $req->total_fee,
                    'note'       => $req->admin_note,
                    'created_at' => $req->requested_at,
                    'actor_name' => 'ادمین',
                ] ];
                if ( $req->status !== 'pending' && $req->reviewed_at ) {
                    $status_action_map = [
                        'ta_approved' => 'ta_approved', 'ta_rejected' => 'ta_rejected',
                        'approved'    => 'admin_approved', 'rejected'    => 'admin_rejected',
                        'paid'        => 'paid',
                    ];
                    $act = $status_action_map[ $req->status ] ?? $req->status;
                    $actor_type = ( substr( $act, 0, 3 ) === 'ta_' ) ? 'ta' : 'admin';
                    $rows[] = (object)[
                        'id'         => 0,
                        'request_id' => $req->id,
                        'ta_id'      => $req->ta_id,
                        'term_id'    => $req->term_id,
                        'action'     => $act,
                        'actor_type' => $actor_type,
                        'actor_id'   => 0,
                        'total_fee'  => $req->approved_fee ?: $req->total_fee,
                        'note'       => $req->ta_note ?: $req->admin_note,
                        'created_at' => $req->reviewed_at,
                        'actor_name' => $actor_type === 'ta' ? 'استادیار' : 'ادمین',
                    ];
                }
                if ( $req->status === 'paid' && $req->paid_at ) {
                    $rows[] = (object)[
                        'id'         => 0,
                        'request_id' => $req->id,
                        'ta_id'      => $req->ta_id,
                        'term_id'    => $req->term_id,
                        'action'     => 'paid',
                        'actor_type' => 'admin',
                        'actor_id'   => 0,
                        'total_fee'  => $req->approved_fee ?: $req->total_fee,
                        'note'       => null,
                        'created_at' => $req->paid_at,
                        'actor_name' => 'ادمین',
                    ];
                }
            }
        }

        wp_send_json_success( $rows );
    }

    /** استادیار: دریافت لاگ یک درخواست خودش */
    public function ajax_ta_get_logs(): void {
        check_ajax_referer( 'ta_dashboard_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( 'not_logged_in' );

        global $wpdb;
        $uid    = get_current_user_id();
        $req_id = absint( $_POST['req_id'] ?? 0 );
        if ( ! $req_id ) wp_send_json_error( 'invalid_id' );

        $ta_tbl = TA_Database::table('assistants');
        $ta = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$ta_tbl} WHERE user_id=%d", $uid ) );
        if ( ! $ta ) wp_send_json_error( 'not_ta' );

        $req_tbl = TA_Database::table('payroll_requests');
        $req = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$req_tbl} WHERE id=%d AND ta_id=%d", $req_id, $ta->id ) );
        if ( ! $req ) wp_send_json_error( 'not_found' );

        // اطمینان از وجود جدول
        TA_Database::maybe_install();

        $log_tbl = TA_Database::table('payroll_request_logs');
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.*, u.display_name AS actor_name
             FROM {$log_tbl} l
             LEFT JOIN {$wpdb->users} u ON u.ID = l.actor_id
             WHERE l.request_id = %d ORDER BY l.created_at ASC",
            $req_id
        ) );

        $rows = $rows ?: [];

        // بازسازی تاریخچه از داده‌های request اگر جدول لاگ خالی است
        if ( empty( $rows ) ) {
            $rows[] = (object)[
                'id' => 0, 'request_id' => $req->id,
                'action' => 'sent', 'actor_type' => 'admin', 'actor_id' => 0,
                'total_fee' => $req->total_fee, 'note' => $req->admin_note,
                'created_at' => $req->requested_at, 'actor_name' => 'ادمین',
            ];
            if ( $req->status !== 'pending' && $req->reviewed_at ) {
                $map = [
                    'ta_approved'=>'ta_approved','ta_rejected'=>'ta_rejected',
                    'approved'=>'admin_approved','rejected'=>'admin_rejected','paid'=>'paid',
                ];
                $act = $map[$req->status] ?? $req->status;
                $at  = ( substr($act,0,3)==='ta_' ) ? 'ta' : 'admin';
                $rows[] = (object)[
                    'id'=>0,'request_id'=>$req->id,
                    'action'=>$act,'actor_type'=>$at,'actor_id'=>0,
                    'total_fee'=>$req->approved_fee??$req->total_fee,
                    'note'     => $at==='ta' ? $req->ta_note : $req->admin_note,
                    'created_at'=>$req->reviewed_at,
                    'actor_name'=> $at==='ta' ? 'شما' : 'ادمین',
                ];
            }
            if ( $req->status==='paid' && $req->paid_at ) {
                $rows[] = (object)[
                    'id'=>0,'request_id'=>$req->id,
                    'action'=>'paid','actor_type'=>'admin','actor_id'=>0,
                    'total_fee'=>$req->approved_fee??$req->total_fee,
                    'note'=>null,'created_at'=>$req->paid_at,'actor_name'=>'ادمین',
                ];
            }
        }

        wp_send_json_success( $rows );
    }


    // ═════════════════════════════════════════════════════════════════════════
    // رندر صفحه اصلی
    // ═════════════════════════════════════════════════════════════════════════
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی مجاز نیست.' );

        global $wpdb;

        $filters     = $this->build_filters_from_request();
        $paged       = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $offset      = ( $paged - 1 ) * self::PER_PAGE;

        // ── پیش‌فرض: آخرین ترم فعال ───────────────────────────────────────
        $terms = TA_Database::get_results(
            "SELECT id, name FROM " . TA_Database::table('terms') . " ORDER BY order_index DESC"
        );
        $latest_term = ! empty($terms) ? $terms[0] : null;
        if ( ! $filters['term'] && $latest_term ) {
            $filters['term'] = (int) $latest_term->id;
        }

        $total_count = $this->get_row_count( $filters );
        $rows        = $this->get_rows( $filters, false, self::PER_PAGE, $offset );
        $all_rows    = $this->get_rows( $filters, true );

        $def_rate       = floatval( get_option( self::OPTION_DEF_RATE, 20 ) );
        $product_rates  = $this->get_product_rates();
        $product_prices = $this->get_product_prices();
        $excluded_ids   = $this->get_excluded_ids();
        $nonce          = wp_create_nonce( self::NONCE_ACTION );
        $unique_products = $this->get_unique_products( $all_rows );
        $assistants = TA_Database::get_results(
            "SELECT ta.id, u.display_name AS name FROM " . TA_Database::table('assistants') . " ta
             JOIN {$wpdb->users} u ON u.ID = ta.user_id ORDER BY u.display_name ASC"
        );
        $summary = $this->build_summary( $all_rows, $def_rate, $product_rates, $product_prices, $excluded_ids );

        // نقشه درخواست‌ها: key = ta_id_term_id
        $requests_map = $this->get_all_requests_map();

        // نقشه تعدیلات: key = ta_id
        $adjustments_map = $filters['term'] ? $this->get_adjustments_map( $filters['term'] ) : [];

        $active_term_name = '';
        if ( $filters['term'] ) {
            foreach ( $terms as $t ) {
                if ( (int)$t->id === $filters['term'] ) { $active_term_name = $t->name; break; }
            }
        }

        ?>
        <div class="wrap ta-payroll-wrap" dir="rtl">

        <!-- ═══ هدر ═══ -->
        <div class="tap-header">
            <div>
                <h1 class="tap-title">💰 حسابداری حق‌الزحمه استادیارها</h1>
                <p class="tap-subtitle">محاسبه، مدیریت، تایید و پرداخت حق‌الزحمه</p>
            </div>
            <div class="tap-rate-box">
                <label class="tap-rate-label">درصد پیش‌فرض (همه دوره‌ها):</label>
                <div class="tap-rate-input-wrap">
                    <input type="number" id="tap-def-rate" value="<?= esc_attr($def_rate) ?>"
                           min="0" max="100" step="0.5" class="tap-rate-input">
                    <span class="tap-rate-symbol">٪</span>
                    <button id="tap-save-def-rate" class="tap-btn tap-btn-primary tap-btn-sm">ذخیره</button>
                </div>
                <span class="tap-rate-note">برای تغییر جداگانه هر دوره از بخش «تنظیم دوره‌ها» استفاده کنید</span>
            </div>
        </div>

        <!-- ═══ کارت‌های آماری ═══ -->
        <div class="tap-summary-grid">
            <div class="tap-stat-card tap-stat-blue">
                <div class="tap-stat-icon">📋</div>
                <div class="tap-stat-body">
                    <span class="tap-stat-value"><?= number_format($total_count) ?></span>
                    <span class="tap-stat-label">کل تخصیص‌ها (ترم انتخابی)</span>
                </div>
            </div>
            <div class="tap-stat-card tap-stat-green">
                <div class="tap-stat-icon">✅</div>
                <div class="tap-stat-body">
                    <span class="tap-stat-value tap-stat-included"><?= number_format($summary['included_count']) ?></span>
                    <span class="tap-stat-label">شامل محاسبه</span>
                </div>
            </div>
            <div class="tap-stat-card tap-stat-red">
                <div class="tap-stat-icon">🚫</div>
                <div class="tap-stat-body">
                    <span class="tap-stat-value tap-stat-excluded"><?= number_format($summary['excluded_count']) ?></span>
                    <span class="tap-stat-label">خارج از محاسبه</span>
                </div>
            </div>
            <div class="tap-stat-card tap-stat-gold">
                <div class="tap-stat-icon">💵</div>
                <div class="tap-stat-body">
                    <span class="tap-stat-value" id="tap-total-fee"><?= number_format($summary['total_fee']) ?></span>
                    <span class="tap-stat-label">جمع کل حق‌الزحمه (تومان)</span>
                </div>
            </div>
        </div>

        <!-- ═══ خلاصه به تفکیک استادیار — پیش‌فرض باز ═══ -->
        <?php if ( ! empty($summary['by_ta']) ) : ?>
        <div class="tap-card">
            <div class="tap-card-header">
                <div>
                    <h3>📊 خلاصه به تفکیک استادیار</h3>
                    <?php if ($active_term_name) : ?>
                    <span class="tap-term-title-badge">📅 ترم: <?= esc_html($active_term_name) ?></span>
                    <?php endif; ?>
                </div>
                <div class="tap-card-header-actions">
                    <button class="tap-btn tap-btn-sm tap-btn-success" id="tap-summary-export-btn">📥 خروجی اکسل</button>
                    <button class="tap-btn tap-btn-sm tap-toggle-breakdown" data-target="tap-breakdown-body">نمایش/پنهان</button>
                </div>
            </div>
            <div class="tap-card-body" id="tap-breakdown-body">
                <table class="tap-breakdown-table">
                    <thead>
                        <tr>
                            <th>نام استادیار</th>
                            <th>تعداد<br><span style="font-weight:400;color:#6b7280;font-size:11px">شامل / خارج</span></th>
                            <th>تنوع دوره</th>
                            <th>جمع قیمت محاسبه<br><span style="font-weight:400;color:#6b7280;font-size:11px">حق‌الزحمه پایه</span></th>
                            <th class="tap-th-final">تعدیلات<br><span style="font-weight:400;color:#6b7280;font-size:11px">💰 مبلغ نهایی</span></th>
                            <th>مبلغ محاسبه شده<br><span style="font-weight:400;color:#6b7280;font-size:11px">تفاوت با تایید شده</span></th>
                            <th>وضعیت تایید<br><span style="font-weight:400;color:#6b7280;font-size:11px">تغییرات</span></th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($summary['by_ta'] as $ta_id => $ta_data) :
                        $req_key      = $ta_id . '_' . $filters['term'];
                        $req          = $requests_map[$req_key] ?? null;
                        $req_st       = $req ? $req['status'] : null;
                        $req_info     = $req_st ? (self::REQUEST_STATUSES[$req_st] ?? null) : null;
                        $current_fee  = $ta_data['fee'];
                        // تعدیلات این استادیار
                        $ta_adjs      = $adjustments_map[$ta_id] ?? [];
                        $adj_net      = $this->calc_adjustment_net( $ta_adjs );
                        $adj_extra    = array_sum( array_column( array_filter($ta_adjs, fn($a) => $a['type']==='extra'), 'amount' ) );
                        $adj_deduct   = array_sum( array_column( array_filter($ta_adjs, fn($a) => $a['type']==='deduction'), 'amount' ) );
                        $final_fee    = $current_fee + $adj_net;
                        $approved_fee      = $req ? floatval($req['approved_fee']      ?? 0) : 0;
                        $prev_approved_fee = $req ? floatval($req['prev_approved_fee'] ?? 0) : 0;
                        $sent_fee          = $req  ? floatval($req['total_fee']         ?? 0) : 0;
                        $ref_fee      = ($approved_fee > 0) ? $approved_fee
                                      : (($prev_approved_fee > 0) ? $prev_approved_fee : $sent_fee);
                        $has_diff     = $req && in_array($req_st, ['ta_approved','approved','paid'], true)
                                        && $ref_fee > 0 && abs($current_fee - $ref_fee) > 1;
                        $pending_diff = $req && $req_st === 'pending'
                                        && abs($current_fee - $sent_fee) > 1;
                        $diff_val     = $has_diff     ? ($current_fee - $ref_fee)
                                      : ($pending_diff ? ($current_fee - $sent_fee) : 0);
                        $can_resend   = $req && in_array($req_st, ['ta_approved','approved','paid'], true) && $has_diff;
                        // ── مسیر مستقل تفاوت ──
                        $diff_status       = $req ? ($req['diff_status']       ?? null) : null;
                        $diff_amount       = $req ? floatval($req['diff_amount']       ?? 0) : 0;
                        $diff_approved_fee = $req ? floatval($req['diff_approved_fee'] ?? 0) : 0;
                        $diff_admin_note   = $req ? ($req['diff_admin_note']   ?? '') : '';
                        $diff_paid_at      = $req ? ($req['diff_paid_at']      ?? null) : null;
                        $diff_st_labels = [
                            'pending'     => ['color'=>'#f59e0b','icon'=>'⏳','label'=>'انتظار تایید استادیار'],
                            'ta_approved' => ['color'=>'#8b5cf6','icon'=>'✅','label'=>'تایید استادیار'],
                            'ta_rejected' => ['color'=>'#f97316','icon'=>'↩️','label'=>'رد استادیار'],
                            'approved'    => ['color'=>'#10b981','icon'=>'✅','label'=>'تایید نهایی ادمین'],
                            'rejected'    => ['color'=>'#ef4444','icon'=>'❌','label'=>'رد شده'],
                            'paid'        => ['color'=>'#3b82f6','icon'=>'💳','label'=>'پرداخت شده'],
                        ];
                        $diff_st_info = $diff_status ? ($diff_st_labels[$diff_status] ?? null) : null;
                    ?>
                    <tr class="tap-breakdown-row <?= $has_diff || $pending_diff ? 'tap-row-has-diff' : '' ?>" data-ta-id="<?= $ta_id ?>">
                        <td>
                            <strong><?= esc_html($ta_data['name']) ?></strong>
                            <span class="tap-email"><?= esc_html($ta_data['email']) ?></span>
                        </td>
                        <!-- تعداد شامل / خارج -->
                        <td style="white-space:nowrap;text-align:center">
                            <span class="tap-badge tap-badge-green" title="شامل محاسبه"><?= $ta_data['included'] ?></span>
                            <span style="color:#d1d5db;margin:0 2px">/</span>
                            <span class="tap-badge tap-badge-red" title="خارج از محاسبه"><?= $ta_data['excluded'] ?></span>
                        </td>
                        <td>
                            <span class="tap-badge tap-badge-purple" title="<?= esc_attr(implode('، ', array_map(fn($ct) => (self::COURSE_LABELS[$ct] ?? $ct) . ' (' . ($ta_data['course_type_counts'][$ct] ?? 0) . ')', $ta_data['course_types']))) ?>">
                                <?= $ta_data['variety'] ?>
                            </span>
                            <span class="tap-variety-types">
                                <?php foreach ($ta_data['course_types'] as $ct) : ?>
                                <span class="tap-course-chip tap-course-chip-sm tap-ct-<?= esc_attr($ct) ?>">
                                    <?= self::COURSE_LABELS[$ct] ?? $ct ?> (<?= (int) ($ta_data['course_type_counts'][$ct] ?? 0) ?>)
                                </span>
                                <?php endforeach; ?>
                            </span>
                        </td>
                        <!-- جمع قیمت محاسبه + حق‌الزحمه پایه -->
                        <td>
                            <span class="tap-stacked-top tap-mono tap-stacked-muted"><?= number_format($ta_data['total_calc_price']) ?> ت</span>
                            <span class="tap-stacked-sep"></span>
                            <span class="tap-stacked-bot tap-fee-cell tap-mono tap-base-fee-cell" data-ta-id="<?= $ta_id ?>">
                                <?= number_format($current_fee) ?> ت
                                <?php if ($pending_diff) : ?>
                                <span class="tap-diff-hint" title="مبلغ ارسال شده: <?= number_format($sent_fee) ?> ت">⚠️</span>
                                <?php endif; ?>
                            </span>
                        </td>
                        <!-- تعدیلات + مبلغ نهایی -->
                        <td class="tap-adj-cell" data-ta-id="<?= $ta_id ?>" data-term-id="<?= $filters['term'] ?>">
                            <div class="tap-adj-summary">
                                <?php if (!empty($ta_adjs)) : ?>
                                    <?php if ($adj_extra > 0) : ?>
                                    <span class="tap-adj-badge tap-adj-extra">+<?= number_format($adj_extra) ?> ت</span>
                                    <?php endif; ?>
                                    <?php if ($adj_deduct > 0) : ?>
                                    <span class="tap-adj-badge tap-adj-deduct">−<?= number_format($adj_deduct) ?> ت</span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="tap-adj-none">—</span>
                                <?php endif; ?>
                                <?php if ($filters['term']) : ?>
                                <button class="tap-btn tap-btn-xs tap-btn-outline tap-adj-edit-btn"
                                        data-ta-id="<?= $ta_id ?>"
                                        data-ta-name="<?= esc_attr($ta_data['name']) ?>"
                                        data-term-id="<?= $filters['term'] ?>"
                                        title="مدیریت تعدیلات">
                                    ✏️ تعدیل
                                </button>
                                <?php endif; ?>
                            </div>
                            <span class="tap-stacked-sep"></span>
                            <div class="tap-final-fee-cell tap-mono" data-ta-id="<?= $ta_id ?>">
                                <strong class="tap-final-amount <?= $adj_net > 0 ? 'tap-final-up' : ($adj_net < 0 ? 'tap-final-down' : '') ?>">
                                    💰 <?= number_format($final_fee) ?> ت
                                </strong>
                            </div>
                        </td>
                        <!-- مبلغ محاسبه شده + تفاوت -->
                        <td>
                            <div class="tap-stacked-top tap-mono tap-approved-fee-cell">
                                <?php if ($approved_fee > 0) : ?>
                                <span class="<?= $has_diff ? 'tap-fee-stale' : 'tap-fee-ok' ?>"><?= number_format($approved_fee) ?> ت</span>
                                <?php elseif ($req_st === 'ta_approved') : ?>
                                <span class="tap-fee-ta-ok"><?= number_format($sent_fee) ?> ت</span>
                                <span class="tap-pending-lbl" style="color:#8b5cf6">تایید استادیار</span>
                                <?php elseif ($sent_fee > 0 && $req_st === 'pending') : ?>
                                <span class="tap-fee-pending"><?= number_format($sent_fee) ?> ت</span>
                                <span class="tap-pending-lbl">در انتظار</span>
                                <?php elseif ($req_st === 'ta_rejected') : ?>
                                <span class="tap-fee-rejected"><?= number_format($sent_fee) ?> ت</span>
                                <span class="tap-pending-lbl" style="color:#f97316">رد استادیار</span>
                                <?php else : ?>
                                <span class="tap-req-none">—</span>
                                <?php endif; ?>
                            </div>
                            <span class="tap-stacked-sep"></span>
                            <?php
                            $final_approved = floatval($req['approved_fee'] ?? 0);
                            $final_ref      = $final_approved > 0 ? $final_approved : ($prev_approved_fee > 0 ? $prev_approved_fee : 0);
                            $real_diff      = $final_ref > 0 ? ($current_fee - $final_ref) : 0;
                            if ( $final_ref > 0 && abs($real_diff) > 1 ) :
                            ?>
                            <div class="tap-diff-wrap">
                                <span class="tap-diff-badge <?= $real_diff > 0 ? 'tap-diff-up' : 'tap-diff-down' ?>">
                                    <?= $real_diff > 0 ? '▲' : '▼' ?> <?= number_format(abs($real_diff)) ?> ت
                                </span>
                            </div>
                            <?php elseif ($final_ref > 0) : ?>
                            <span style="color:#10b981;font-size:11px">✔ بدون تفاوت</span>
                            <?php else : ?>
                            <span class="tap-req-none" style="font-size:11px">—</span>
                            <?php endif; ?>
                        </td>
                        <!-- وضعیت تایید + تغییرات -->
                        <td>
                            <div class="tap-stacked-top">
                                <?php if ($req_info) : ?>
                                <span class="tap-req-badge" style="background:<?= $req_info['color'] ?>22;color:<?= $req_info['color'] ?>;border:1px solid <?= $req_info['color'] ?>44">
                                    <?= $req_info['icon'] ?> <?= $req_info['label'] ?>
                                </span>
                                <?php if ($req && $req['ta_note']) : ?>
                                <span class="tap-note-hint" title="توضیح استادیار: <?= esc_attr($req['ta_note']) ?>">💬</span>
                                <?php endif; ?>
                                <?php else : ?>
                                <span class="tap-req-none">—</span>
                                <?php endif; ?>
                            </div>
                            <span class="tap-stacked-sep"></span>
                            <?php if ($req) : ?>
                            <button class="tap-btn tap-btn-outline tap-btn-xs tap-view-changes"
                                    data-req-id="<?= $req['id'] ?>"
                                    data-ta-name="<?= esc_attr($ta_data['name']) ?>">
                                📜 تغییرات
                            </button>
                            <?php else : ?>
                            <span class="tap-req-none">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="tap-bd-actions">
                            <?php if ($filters['term']) : ?>
                                <?php if ( ! $req_st || in_array($req_st, ['rejected','ta_rejected'], true) ) : ?>
                                <button class="tap-btn tap-btn-primary tap-btn-xs tap-send-request"
                                        data-ta-id="<?= $ta_id ?>"
                                        data-term-id="<?= $filters['term'] ?>"
                                        data-fee="<?= $final_fee ?>">
                                    📨 درخواست تایید
                                </button>
                                <?php elseif ($req_st === 'pending') : ?>
                                <span class="tap-waiting-ta">⏳ منتظر پاسخ</span>
                                <button class="tap-btn tap-btn-success tap-btn-xs tap-review-request"
                                        data-req-id="<?= $req['id'] ?>"
                                        data-action="approved"
                                        title="بدون نیاز به تایید استادیار تایید نهایی کن">✅ تایید مستقیم</button>
                                <button class="tap-btn tap-btn-danger tap-btn-xs tap-review-request"
                                        data-req-id="<?= $req['id'] ?>"
                                        data-action="rejected">❌ رد</button>
                                <?php elseif ($req_st === 'ta_approved') : ?>
                                <button class="tap-btn tap-btn-success tap-btn-xs tap-review-request"
                                        data-req-id="<?= $req['id'] ?>"
                                        data-action="approved">✅ تایید نهایی</button>
                                <button class="tap-btn tap-btn-danger tap-btn-xs tap-review-request"
                                        data-req-id="<?= $req['id'] ?>"
                                        data-action="rejected">❌ رد</button>
                                <?php elseif ($req_st === 'approved') : ?>
                                <button class="tap-btn tap-btn-blue tap-btn-xs tap-mark-paid"
                                        data-req-id="<?= $req['id'] ?>">💳 پرداخت شد</button>
                                <?php elseif ($req_st === 'paid') : ?>
                                <span class="tap-paid-label">💳 پرداخت‌شده</span>
                                <?php endif; ?>
                                <?php if ($can_resend) : ?>
                                <button class="tap-btn tap-btn-warning tap-btn-xs tap-send-request"
                                        data-ta-id="<?= $ta_id ?>"
                                        data-term-id="<?= $filters['term'] ?>"
                                        data-fee="<?= $final_fee ?>"
                                        data-resend="1"
                                        title="مبلغ فعلی با مبلغ تایید شده تفاوت دارد">
                                    🔄 درخواست مجدد
                                </button>
                                <?php endif; ?>
                                <?php if ($req) : ?>
                                <button class="tap-btn tap-btn-outline tap-btn-xs tap-view-history"
                                        data-req-id="<?= $req['id'] ?>"
                                        data-ta-name="<?= esc_attr($ta_data['name']) ?>">
                                    📋 تاریخچه
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══ تنظیم قیمت و درصد به ازای هر دوره ═══ -->
        <?php if ( ! empty($unique_products) ) : ?>
        <div class="tap-card">
            <div class="tap-card-header">
                <h3>⚙️ تنظیم قیمت محاسبه و درصد حق‌الزحمه به تفکیک دوره</h3>
                <button class="tap-btn tap-btn-sm tap-toggle-breakdown" data-target="tap-product-cfg-body">نمایش/پنهان</button>
            </div>
            <div class="tap-card-body" id="tap-product-cfg-body" style="display:none">
                <p class="tap-cfg-note">
                    قیمت محاسبه پیش‌فرض از WooCommerce خوانده می‌شود ولی می‌توانید آن را تغییر دهید.
                    درصد خالی = استفاده از درصد پیش‌فرض (<?= $def_rate ?>٪).
                </p>
                <table class="tap-cfg-table">
                    <thead>
                        <tr>
                            <th>نام دوره</th><th>نوع دوره</th><th>قیمت WooCommerce</th>
                            <th>قیمت محاسبه <small>(قابل تغییر)</small></th>
                            <th>درصد حق‌الزحمه <small>(قابل تغییر)</small></th>
                            <th>حق‌الزحمه هر تخصیص</th><th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($unique_products as $pid => $pdata) :
                        $wc_price    = $this->get_wc_price($pid);
                        $calc_price  = $product_prices[$pid] ?? $wc_price;
                        $rate        = $product_rates[$pid]  ?? $def_rate;
                        $fee_preview = round($calc_price * $rate / 100);
                        $price_custom = isset($product_prices[$pid]);
                        $rate_custom  = isset($product_rates[$pid]);
                    ?>
                    <tr class="tap-cfg-row" data-pid="<?= $pid ?>">
                        <td><strong><?= esc_html($pdata['name']) ?></strong><span class="tap-cfg-pid">#<?= $pid ?></span></td>
                        <td><span class="tap-course-chip tap-ct-<?= esc_attr($pdata['course_type']) ?>"><?= self::COURSE_LABELS[$pdata['course_type']] ?? $pdata['course_type'] ?></span></td>
                        <td class="tap-wc-price-cell"><?= number_format($wc_price) ?> <span class="tap-currency">ت</span></td>
                        <td>
                            <div class="tap-cfg-field-wrap">
                                <input type="number" class="tap-cfg-input tap-calc-price-input"
                                       value="<?= esc_attr($calc_price) ?>" min="0" step="1000"
                                       data-default="<?= esc_attr($wc_price) ?>" data-pid="<?= $pid ?>" data-field="price">
                                <?php if ($price_custom) : ?><span class="tap-custom-badge">سفارشی</span><?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="tap-cfg-field-wrap">
                                <input type="number" class="tap-cfg-input tap-rate-input-prod"
                                       value="<?= esc_attr($rate) ?>" min="0" max="100" step="0.5"
                                       data-default="<?= esc_attr($def_rate) ?>" data-pid="<?= $pid ?>" data-field="rate">
                                <span class="tap-rate-symbol-sm">٪</span>
                                <?php if ($rate_custom) : ?><span class="tap-custom-badge">سفارشی</span><?php endif; ?>
                            </div>
                        </td>
                        <td class="tap-fee-preview-cell tap-mono"><span class="tap-fee-preview"><?= number_format($fee_preview) ?></span> ت</td>
                        <td>
                            <div class="tap-cfg-actions">
                                <button class="tap-btn tap-btn-primary tap-btn-xs tap-save-product-cfg" data-pid="<?= $pid ?>">ذخیره</button>
                                <button class="tap-btn tap-btn-outline tap-btn-xs tap-reset-product-cfg" data-pid="<?= $pid ?>">بازنشانی</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══ فیلترها ═══ -->
        <div class="tap-card">
            <div class="tap-card-header"><h3>🔍 فیلترها</h3></div>
            <div class="tap-card-body">
                <form method="get" class="tap-filter-form">
                    <input type="hidden" name="page" value="ta-payroll">
                    <div class="tap-filter-row">
                        <div class="tap-filter-group">
                            <label>ترم</label>
                            <select name="filter_term">
                                <option value="">همه ترم‌ها</option>
                                <?php foreach (array_reverse($terms) as $t) : ?>
                                <option value="<?= $t->id ?>" <?= selected($filters['term'], $t->id, false) ?>><?= esc_html($t->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="tap-filter-group">
                            <label>استادیار</label>
                            <select name="filter_ta">
                                <option value="">همه استادیارها</option>
                                <?php foreach ($assistants as $ta) : ?>
                                <option value="<?= $ta->id ?>" <?= selected($filters['ta'], $ta->id, false) ?>><?= esc_html($ta->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="tap-filter-group">
                            <label>وضعیت</label>
                            <select name="filter_status">
                                <option value="">همه وضعیت‌ها</option>
                                <?php foreach (self::STATUSES as $k => $v) : ?>
                                <option value="<?= $k ?>" <?= selected($filters['status'], $k, false) ?>><?= $v['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="tap-filter-group">
                            <label>نوع دوره</label>
                            <select name="filter_course_type">
                                <option value="">همه انواع</option>
                                <?php foreach (self::COURSE_LABELS as $k => $v) : ?>
                                <option value="<?= $k ?>" <?= selected($filters['course_type'], $k, false) ?>><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="tap-filter-group">
                            <label>وضعیت محاسبه</label>
                            <select name="filter_calc">
                                <option value="">همه</option>
                                <option value="included" <?= selected($filters['calc'], 'included', false) ?>>شامل محاسبه</option>
                                <option value="excluded" <?= selected($filters['calc'], 'excluded', false) ?>>خارج از محاسبه</option>
                            </select>
                        </div>
                        <div class="tap-filter-group tap-filter-search">
                            <label>جستجو</label>
                            <input type="text" name="search" value="<?= esc_attr($filters['search']) ?>"
                                   placeholder="نام هنرجو / استادیار / دوره…">
                        </div>
                    </div>
                    <div class="tap-filter-actions">
                        <button type="submit" class="tap-btn tap-btn-primary">اعمال فیلتر</button>
                        <a href="<?= admin_url('admin.php?page=ta-payroll') ?>" class="tap-btn tap-btn-secondary">پاک‌کردن</a>
                        <button type="button" id="tap-export-btn" class="tap-btn tap-btn-success">📥 خروجی اکسل</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ═══ جدول اصلی ═══ -->
        <div class="tap-card">
            <div class="tap-card-header">
                <h3>جدول تخصیص‌ها</h3>
                <span class="tap-count-badge"><?= number_format($total_count) ?> ردیف</span>
            </div>
            <div class="tap-card-body tap-table-body">
            <?php if (empty($rows)) : ?>
                <div class="tap-empty">هیچ رکوردی یافت نشد.</div>
            <?php else : ?>
                <table class="tap-table" id="tap-main-table">
                    <thead>
                        <tr>
                            <th>#</th><th>هنرجو</th><th>استادیار</th><th>دوره</th>
                            <th>ترم</th><th>وضعیت</th><th>قیمت WC</th>
                            <th>قیمت محاسبه</th><th>درصد</th><th>حق‌الزحمه</th>
                            <th>وضعیت تایید</th><th>تاریخ</th><th>محاسبه</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $row_num = $offset + 1;
                    foreach ($rows as $r) :
                        $pid          = (int) $r->product_id;
                        $wc_price     = $this->get_wc_price($pid);
                        $calc_price   = $product_prices[$pid] ?? $wc_price;
                        $rate         = $product_rates[$pid]  ?? $def_rate;
                        $is_excl      = isset($excluded_ids[(int)$r->id]);
                        $fee          = $is_excl ? 0 : round($calc_price * $rate / 100);
                        $st_info      = self::STATUSES[$r->status] ?? ['label' => $r->status, 'color' => '#888'];
                        $price_custom = isset($product_prices[$pid]);
                        $rate_custom  = isset($product_rates[$pid]);
                        $req_key      = $r->ta_id . '_' . $r->term_id;
                        $req          = $requests_map[$req_key] ?? null;
                        $req_st       = $req ? $req['status'] : null;
                        $req_info     = $req_st ? (self::REQUEST_STATUSES[$req_st] ?? null) : null;
                    ?>
                    <tr class="tap-row <?= $is_excl ? 'tap-row-excluded' : '' ?>"
                        data-id="<?= $r->id ?>" data-pid="<?= $pid ?>" data-ta-id="<?= $r->ta_id ?>">
                        <td class="tap-td-num"><?= $row_num++ ?></td>
                        <td>
                            <div class="tap-person">
                                <span class="tap-person-name"><?= esc_html($r->student_name) ?></span>
                                <span class="tap-person-email"><?= esc_html($r->student_email) ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="tap-person">
                                <span class="tap-person-name"><?= esc_html($r->ta_name) ?></span>
                                <span class="tap-person-email"><?= esc_html($r->ta_email) ?></span>
                            </div>
                        </td>
                        <td>
                            <span class="tap-product-name"><?= esc_html($r->product_name) ?></span>
                            <span class="tap-course-chip tap-course-chip-sm tap-ct-<?= esc_attr($r->course_type) ?>">
                                <?= self::COURSE_LABELS[$r->course_type] ?? $r->course_type ?>
                            </span>
                        </td>
                        <td><?= esc_html($r->term_name ?? '—') ?></td>
                        <td>
                            <span class="tap-status-badge" style="background:<?= $st_info['color'] ?>22;color:<?= $st_info['color'] ?>">
                                <?= $st_info['label'] ?>
                            </span>
                        </td>
                        <td class="tap-td-price">
                            <?php if ($wc_price > 0) : ?>
                                <span><?= number_format($wc_price) ?></span> <span class="tap-currency">ت</span>
                            <?php else : ?>
                                <span class="tap-free-badge">رایگان</span>
                            <?php endif; ?>
                        </td>
                        <td class="tap-td-price">
                            <span class="tap-calc-price" data-pid="<?= $pid ?>"><?= number_format($calc_price) ?></span>
                            <span class="tap-currency">ت</span>
                            <?php if ($price_custom) : ?><span class="tap-dot-custom" title="سفارشی">●</span><?php endif; ?>
                        </td>
                        <td class="tap-td-rate">
                            <span class="tap-row-rate" data-pid="<?= $pid ?>"><?= $rate ?></span>٪
                            <?php if ($rate_custom) : ?><span class="tap-dot-custom" title="سفارشی">●</span><?php endif; ?>
                        </td>
                        <td class="tap-td-fee">
                            <?php if ($is_excl) : ?>
                                <span class="tap-excluded-label">— خارج —</span>
                            <?php else : ?>
                                <span class="tap-fee-amount" data-pid="<?= $pid ?>"
                                      data-calc-price="<?= $calc_price ?>"><?= number_format($fee) ?></span>
                                <span class="tap-currency">ت</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($req_info) : ?>
                            <span class="tap-req-badge-sm" style="background:<?= $req_info['color'] ?>22;color:<?= $req_info['color'] ?>">
                                <?= $req_info['icon'] ?> <?= $req_info['label'] ?>
                            </span>
                            <?php else : ?>
                            <span class="tap-req-none">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="tap-td-date"><?= esc_html(substr($r->created_at, 0, 10)) ?></td>
                        <td class="tap-td-toggle">
                            <label class="tap-toggle-switch">
                                <input type="checkbox" class="tap-excl-checkbox"
                                       data-id="<?= $r->id ?>" <?= $is_excl ? '' : 'checked' ?>>
                                <span class="tap-toggle-slider"></span>
                            </label>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php
                $total_pages = ceil($total_count / self::PER_PAGE);
                if ($total_pages > 1) :
                    $base = add_query_arg(array_filter([
                        'page'               => 'ta-payroll',
                        'filter_term'        => $filters['term']        ?: null,
                        'filter_ta'          => $filters['ta']          ?: null,
                        'filter_status'      => $filters['status']      ?: null,
                        'filter_course_type' => $filters['course_type'] ?: null,
                        'filter_calc'        => $filters['calc']        ?: null,
                        'search'             => $filters['search']      ?: null,
                    ]), admin_url('admin.php'));
                ?>
                <div class="tap-pagination">
                    <?php if ($paged > 1) : ?>
                        <a href="<?= esc_url(add_query_arg('paged', $paged - 1, $base)) ?>" class="tap-page-btn">← قبلی</a>
                    <?php endif; ?>
                    <span class="tap-page-info">صفحه <?= $paged ?> از <?= $total_pages ?></span>
                    <?php if ($paged < $total_pages) : ?>
                        <a href="<?= esc_url(add_query_arg('paged', $paged + 1, $base)) ?>" class="tap-page-btn">بعدی →</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            </div>
        </div>

        </div><!-- .ta-payroll-wrap -->

        <!-- ═══ مودال درخواست تایید ═══ -->
        <div id="tap-modal-overlay" class="tap-modal-overlay" style="display:none">
            <div class="tap-modal">
                <div class="tap-modal-header">
                    <h3 id="tap-modal-title">درخواست تایید حق‌الزحمه</h3>
                    <button class="tap-modal-close" id="tap-modal-close">✕</button>
                </div>
                <div class="tap-modal-body">
                    <div class="tap-modal-info" id="tap-modal-info"></div>
                    <div class="tap-modal-field">
                        <label>توضیحات ادمین (اختیاری)</label>
                        <textarea id="tap-modal-note" rows="3" placeholder="توضیحاتی برای استادیار…"></textarea>
                    </div>
                </div>
                <div class="tap-modal-footer">
                    <button class="tap-btn tap-btn-primary" id="tap-modal-confirm">ارسال درخواست</button>
                    <button class="tap-btn tap-btn-secondary" id="tap-modal-cancel">انصراف</button>
                </div>
            </div>
        </div>

        <!-- ═══ مودال تاریخچه درخواست ═══ -->
        <div id="tap-notes-modal-overlay" class="tap-modal-overlay" style="display:none">
            <div class="tap-modal" style="width:560px;max-width:96vw">
                <div class="tap-modal-header">
                    <h3>📋 تاریخچه درخواست</h3>
                    <button class="tap-modal-close" id="tap-notes-modal-close">✕</button>
                </div>
                <div class="tap-modal-body" style="max-height:60vh;overflow-y:auto">
                    <div id="tap-notes-admin"></div>
                    <div id="tap-notes-ta" style="display:none"></div>
                    <div class="tap-modal-field" id="tap-review-note-wrap" style="display:none">
                        <label>توضیح برای استادیار</label>
                        <textarea id="tap-review-note" rows="3" placeholder="توضیحات تایید/رد…"></textarea>
                    </div>
                </div>
                <div class="tap-modal-footer" id="tap-notes-modal-footer"></div>
            </div>
        </div>

        <!-- ═══ مودال تغییرات (Timeline کامل) ═══ -->
        <div id="tap-changes-modal-overlay" class="tap-modal-overlay" style="display:none">
            <div class="tap-modal" style="width:620px;max-width:96vw">
                <div class="tap-modal-header">
                    <h3>📜 تغییرات و سابقه کامل پرداخت‌ها</h3>
                    <button class="tap-modal-close" id="tap-changes-modal-close">✕</button>
                </div>
                <div class="tap-modal-body" style="max-height:65vh;overflow-y:auto">
                    <div id="tap-changes-content"></div>
                </div>
                <div class="tap-modal-footer">
                    <button class="tap-btn tap-btn-secondary" id="tap-changes-close-btn">بستن</button>
                </div>
            </div>
        </div>

        <!-- ═══ مودال پرداخت ═══ -->
        <div id="tap-pay-modal-overlay" class="tap-modal-overlay" style="display:none">
            <div class="tap-modal">
                <div class="tap-modal-header">
                    <h3>💳 ثبت پرداخت حق‌الزحمه</h3>
                    <button class="tap-modal-close" id="tap-pay-modal-close">✕</button>
                </div>
                <div class="tap-modal-body">
                    <p id="tap-pay-modal-info"></p>
                    <div class="tap-modal-field">
                        <label>یادداشت پرداخت (برای استادیار)</label>
                        <textarea id="tap-pay-note" rows="3" placeholder="جزئیات پرداخت، شماره حواله و…"></textarea>
                    </div>
                </div>
                <div class="tap-modal-footer">
                    <button class="tap-btn tap-btn-blue" id="tap-pay-modal-confirm">✅ تایید پرداخت</button>
                    <button class="tap-btn tap-btn-secondary" id="tap-pay-modal-cancel">انصراف</button>
                </div>
            </div>
        </div>

        <!-- ═══ مودال تعدیلات (مبلغ اضافی / کسورات) ═══ -->
        <div id="tap-adj-modal-overlay" class="tap-modal-overlay" style="display:none">
            <div class="tap-modal" style="width:620px;max-width:96vw">
                <div class="tap-modal-header">
                    <h3 id="tap-adj-modal-title">✏️ تعدیلات حق‌الزحمه</h3>
                    <button class="tap-modal-close" id="tap-adj-modal-close">✕</button>
                </div>
                <div class="tap-modal-body" style="padding:0">
                    <!-- خلاصه مبالغ -->
                    <div id="tap-adj-summary-bar" class="tap-adj-summary-bar"></div>
                    <!-- فرم افزودن -->
                    <div class="tap-adj-form-wrap">
                        <div class="tap-adj-form-title">➕ افزودن تعدیل جدید</div>
                        <div class="tap-adj-form-row">
                            <div class="tap-adj-field">
                                <label>نوع</label>
                                <div class="tap-adj-type-group">
                                    <label class="tap-adj-type-opt tap-adj-type-extra">
                                        <input type="radio" name="tap_adj_type" value="extra" checked>
                                        <span>➕ مبلغ اضافی</span>
                                    </label>
                                    <label class="tap-adj-type-opt tap-adj-type-deduct">
                                        <input type="radio" name="tap_adj_type" value="deduction">
                                        <span>➖ کسورات</span>
                                    </label>
                                </div>
                            </div>
                            <div class="tap-adj-field">
                                <label>مبلغ (تومان)</label>
                                <input type="number" id="tap-adj-amount" min="1" step="1000" placeholder="مثال: ۵۰۰۰۰۰">
                            </div>
                        </div>
                        <div class="tap-adj-field" style="margin-top:10px">
                            <label>توضیحات</label>
                            <input type="text" id="tap-adj-desc" placeholder="دلیل تعدیل، مانند: پاداش عملکرد، کسر غیبت و…" style="width:100%">
                        </div>
                        <div style="margin-top:12px;display:flex;gap:8px">
                            <button class="tap-btn tap-btn-primary tap-btn-sm" id="tap-adj-save-btn">💾 ذخیره تعدیل</button>
                            <button class="tap-btn tap-btn-secondary tap-btn-sm" id="tap-adj-cancel-edit" style="display:none">انصراف از ویرایش</button>
                        </div>
                    </div>
                    <!-- لیست تعدیلات موجود -->
                    <div class="tap-adj-list-wrap">
                        <div class="tap-adj-list-title">📋 تعدیلات ثبت‌شده</div>
                        <div id="tap-adj-list"></div>
                    </div>
                </div>
                <div class="tap-modal-footer">
                    <button class="tap-btn tap-btn-secondary" id="tap-adj-close-btn">بستن</button>
                </div>
            </div>
        </div>

        <!-- ═══ استایل ═══ -->
        <style>
        :root{
            --tap-blue:var(--ta-adm-blue);--tap-green:var(--ta-adm-green);--tap-red:var(--ta-adm-red);
            --tap-gold:var(--ta-adm-amber);--tap-purple:var(--ta-adm-purple);--tap-gray:var(--ta-adm-gray-500);
            --tap-border:var(--ta-adm-gray-200);--tap-radius:10px;--tap-shadow:0 1px 4px rgba(0,0,0,.08);
        }
        .ta-payroll-wrap{font-family:inherit;direction:rtl;max-width:1500px}
        /* هدر */
        .tap-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:24px}
        .tap-title{margin:0;font-size:22px;font-weight:800;color:var(--ta-adm-gray-900)}
        .tap-subtitle{margin:4px 0 0;color:var(--tap-gray);font-size:13px}
        .tap-rate-box{background:#fff;border:1.5px solid var(--tap-border);border-radius:var(--tap-radius);padding:14px 18px;min-width:260px}
        .tap-rate-label{font-size:12px;font-weight:700;color:var(--ta-adm-gray-700);display:block;margin-bottom:8px}
        .tap-rate-input-wrap{display:flex;align-items:center;gap:8px}
        .tap-rate-input{width:80px;padding:6px 10px;border:2px solid var(--tap-border);border-radius:8px;font-size:15px;text-align:center;font-weight:700;transition:border-color .2s}
        .tap-rate-input:focus{border-color:var(--tap-blue);outline:none}
        .tap-rate-symbol{font-size:18px;font-weight:700;color:var(--tap-gold)}
        .tap-rate-note{font-size:11px;color:var(--tap-gray)}
        /* آمار */
        .tap-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:20px}
        .tap-stat-card{background:#fff;border-radius:var(--tap-radius);padding:18px 20px;box-shadow:var(--tap-shadow);display:flex;align-items:center;gap:14px;border-right:4px solid transparent}
        .tap-stat-blue{border-right-color:var(--tap-blue)}.tap-stat-green{border-right-color:var(--tap-green)}
        .tap-stat-red{border-right-color:var(--tap-red)}.tap-stat-gold{border-right-color:var(--tap-gold)}
        .tap-stat-icon{font-size:28px;flex-shrink:0}
        .tap-stat-body{display:flex;flex-direction:column}
        .tap-stat-value{font-size:22px;font-weight:800;color:var(--ta-adm-gray-900);line-height:1.2}
        .tap-stat-label{font-size:12px;color:var(--tap-gray);margin-top:3px}
        /* کارت */
        .tap-card{background:#fff;border-radius:var(--tap-radius);box-shadow:var(--tap-shadow);margin-bottom:20px;overflow:hidden}
        .tap-card-header{padding:14px 20px;border-bottom:1px solid var(--tap-border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
        .tap-card-header h3{margin:0;font-size:15px;font-weight:700;color:var(--ta-adm-gray-900)}
        .tap-card-header-actions{display:flex;align-items:center;gap:8px}
        .tap-card-body{padding:18px 20px}
        .tap-table-body{padding:0}
        .tap-term-title-badge{background:#eff6ff;color:#1d4ed8;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:700;border:1px solid #bfdbfe;margin-top:4px;display:inline-block}
        /* جدول تنظیم محصول */
        .tap-cfg-note{font-size:12px;color:var(--tap-gray);margin:0 0 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:8px 12px}
        .tap-cfg-table{width:100%;border-collapse:collapse;font-size:13px}
        .tap-cfg-table th,.tap-cfg-table td{padding:10px 12px;border-bottom:1px solid var(--tap-border);text-align:right;vertical-align:middle}
        .tap-cfg-table th{background:var(--ta-adm-gray-50);font-weight:700;color:var(--ta-adm-gray-700);font-size:12px}
        .tap-cfg-table tr:last-child td{border-bottom:none}
        .tap-cfg-table tr:hover td{background:#fafbff}
        .tap-cfg-pid{display:block;font-size:10px;color:#aaa}
        .tap-cfg-input{width:110px;padding:5px 8px;border:1.5px solid var(--tap-border);border-radius:6px;font-size:13px;text-align:center;transition:border-color .2s}
        .tap-cfg-input:focus{border-color:var(--tap-blue);outline:none}
        .tap-cfg-field-wrap{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
        .tap-custom-badge{background:#dbeafe;color:#1d4ed8;font-size:10px;padding:2px 6px;border-radius:4px;font-weight:700}
        .tap-rate-symbol-sm{font-size:14px;font-weight:700;color:var(--tap-gold)}
        .tap-fee-preview-cell{font-weight:700;color:var(--tap-green);font-family:monospace}
        .tap-cfg-actions{display:flex;gap:6px;white-space:nowrap}
        .tap-wc-price-cell{color:var(--tap-gray);font-family:monospace}
        /* جدول breakdown */
        .tap-card-body{overflow-x:auto}
        .tap-breakdown-table{width:100%;min-width:900px;border-collapse:collapse;font-size:13px;table-layout:auto}
        .tap-breakdown-table th,.tap-breakdown-table td{padding:10px 12px;border-bottom:1px solid var(--tap-border);text-align:right;vertical-align:middle}
        .tap-breakdown-table th{background:var(--ta-adm-gray-50);font-weight:700;color:var(--ta-adm-gray-700);font-size:12px}
        .tap-breakdown-table tr:last-child td{border-bottom:none}
        .tap-breakdown-table tr:hover td{background:var(--ta-adm-gray-50)}
        .tap-breakdown-table th:first-child,.tap-breakdown-table td:first-child{min-width:160px}
        .tap-breakdown-table th:last-child,.tap-breakdown-table td:last-child{min-width:180px;white-space:normal}
        /* ستون‌های stacked */
        .tap-stacked-top{display:block;font-size:13px}
        .tap-stacked-bot{display:block;font-size:12px}
        .tap-stacked-muted{color:var(--ta-adm-gray-500)}
        .tap-stacked-sep{display:block;height:1px;background:#f0f0f0;margin:4px 0}
        .tap-fee-cell{font-weight:700;color:var(--tap-green)}
        .tap-mono{font-family:monospace}
        .tap-email{display:block;font-size:11px;color:var(--tap-gray)}
        .tap-variety-types{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px}
        .tap-bd-actions{display:flex;flex-wrap:wrap;gap:4px}
        /* وضعیت درخواست */
        .tap-req-badge{display:inline-block;padding:4px 10px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}
        .tap-req-badge-sm{display:inline-block;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;white-space:nowrap}
        .tap-req-none{color:#aaa;font-size:12px}
        .tap-paid-label{color:var(--tap-blue);font-size:12px;font-weight:700}
        .tap-waiting-ta{color:var(--ta-adm-amber);font-size:11px;font-weight:700;display:inline-block;margin-left:4px}
        .tap-note-hint{cursor:pointer;font-size:14px;margin-right:4px}
        /* فیلتر */
        .tap-filter-form{display:flex;flex-direction:column;gap:14px}
        .tap-filter-row{display:flex;flex-wrap:wrap;gap:12px}
        .tap-filter-group{display:flex;flex-direction:column;gap:5px;min-width:140px;flex:1}
        .tap-filter-search{min-width:220px;flex:2}
        .tap-filter-group label{font-size:12px;font-weight:600;color:var(--ta-adm-gray-700)}
        .tap-filter-group select,.tap-filter-group input[type=text]{padding:7px 10px;border:1.5px solid var(--tap-border);border-radius:8px;font-size:13px;font-family:inherit;outline:none;transition:border-color .2s}
        .tap-filter-group select:focus,.tap-filter-group input:focus{border-color:var(--tap-blue)}
        .tap-filter-actions{display:flex;gap:10px;flex-wrap:wrap}
        /* دکمه */
        .tap-btn{padding:8px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:opacity .15s,transform .1s;font-family:inherit;line-height:1;display:inline-flex;align-items:center;gap:4px}
        .tap-btn:hover{opacity:.88;transform:translateY(-1px)}
        .tap-btn:active{transform:translateY(0)}
        .tap-btn-primary{background:var(--tap-blue);color:#fff}
        .tap-btn-secondary{background:var(--ta-adm-gray-100);color:var(--ta-adm-gray-700);text-decoration:none}
        .tap-btn-success{background:var(--tap-green);color:#fff}
        .tap-btn-danger{background:var(--tap-red);color:#fff}
        .tap-btn-blue{background:var(--tap-blue);color:#fff}
        .tap-btn-outline{background:#fff;color:var(--tap-gray);border:1.5px solid var(--tap-border)}
        .tap-btn-sm{padding:5px 12px;font-size:12px}
        .tap-btn-xs{padding:4px 10px;font-size:11px}
        /* جدول اصلی */
        .tap-table{width:100%;border-collapse:collapse;font-size:12.5px}
        .tap-table th{background:var(--ta-adm-gray-50);color:var(--ta-adm-gray-700);font-weight:700;font-size:11.5px;padding:9px 10px;text-align:right;border-bottom:2px solid var(--tap-border);white-space:nowrap}
        .tap-table td{padding:9px 10px;border-bottom:1px solid var(--tap-border);vertical-align:middle}
        .tap-row:hover td{background:#f8faff}
        .tap-row-excluded td{opacity:.5}
        .tap-row-excluded .tap-person-name{text-decoration:line-through;color:var(--tap-gray)}
        .tap-td-num{color:var(--tap-gray);font-size:11px;text-align:center}
        .tap-person{display:flex;flex-direction:column}
        .tap-person-name{font-weight:600;color:var(--ta-adm-gray-900)}
        .tap-person-email{font-size:11px;color:var(--tap-gray)}
        .tap-product-name{display:block;font-weight:600;color:#1e293b;font-size:12px;margin-bottom:3px}
        .tap-status-badge{display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700}
        .tap-td-price,.tap-td-fee{text-align:left;font-family:monospace;white-space:nowrap}
        .tap-td-rate{text-align:center;white-space:nowrap}
        .tap-fee-amount{font-weight:700;color:#1e293b}
        .tap-currency{font-size:11px;color:var(--tap-gray)}
        .tap-free-badge{background:var(--ta-adm-gray-100);color:var(--tap-gray);padding:2px 8px;border-radius:4px;font-size:11px}
        .tap-excluded-label{color:var(--tap-red);font-size:12px;font-style:italic}
        .tap-td-date{font-size:11px;color:var(--tap-gray);white-space:nowrap}
        .tap-td-toggle{text-align:center}
        .tap-dot-custom{color:var(--tap-blue);font-size:10px;vertical-align:super}
        /* chip نوع دوره */
        .tap-course-chip{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700}
        .tap-course-chip-sm{padding:1px 6px;font-size:10px}
        .tap-ct-intro{background:#dbeafe;color:#1d4ed8}.tap-ct-advanced{background:#d1fae5;color:#065f46}
        .tap-ct-creative{background:#fce7f3;color:#9d174d}.tap-ct-professional{background:#ede9fe;color:#5b21b6}
        .tap-ct-critique{background:#fef3c7;color:#92400e}
        .tap-ct-basic_writing{background:#ccfbf1;color:#0f766e}.tap-ct-ladder{background:#f3e8ff;color:#7c3aed}
        /* toggle */
        .tap-toggle-switch{position:relative;display:inline-block;width:42px;height:22px;cursor:pointer}
        .tap-toggle-switch input{opacity:0;width:0;height:0;position:absolute}
        .tap-toggle-slider{position:absolute;inset:0;background:#d1d5db;border-radius:99px;transition:.25s}
        .tap-toggle-slider:before{content:'';position:absolute;width:16px;height:16px;bottom:3px;right:3px;background:#fff;border-radius:50%;transition:.25s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
        .tap-toggle-switch input:checked + .tap-toggle-slider{background:var(--tap-green)}
        .tap-toggle-switch input:checked + .tap-toggle-slider:before{transform:translateX(-20px)}
        /* بج */
        .tap-badge{display:inline-block;padding:2px 10px;border-radius:99px;font-size:12px;font-weight:700}
        .tap-badge-green{background:#d1fae5;color:#065f46}.tap-badge-red{background:#fee2e2;color:#991b1b}
        .tap-badge-purple{background:#ede9fe;color:#5b21b6}
        /* پیجینیشن */
        .tap-pagination{display:flex;align-items:center;justify-content:center;gap:12px;padding:16px 0 4px}
        .tap-page-btn{padding:7px 16px;background:var(--tap-blue);color:#fff;text-decoration:none;border-radius:8px;font-size:13px;font-weight:600;transition:opacity .15s}
        .tap-page-btn:hover{opacity:.85;color:#fff}
        .tap-page-info{font-size:13px;color:var(--tap-gray)}
        .tap-count-badge{background:var(--ta-adm-gray-100);color:var(--tap-gray);padding:3px 10px;border-radius:99px;font-size:12px}
        .tap-empty{padding:40px;text-align:center;color:var(--tap-gray);font-size:15px}
        /* مودال */
        .tap-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100000;display:flex;align-items:center;justify-content:center}
        .tap-modal{background:#fff;border-radius:14px;width:480px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.2)}
        .tap-modal-header{padding:18px 20px 14px;border-bottom:1px solid var(--tap-border);display:flex;align-items:center;justify-content:space-between}
        .tap-modal-header h3{margin:0;font-size:16px;font-weight:700}
        .tap-modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:#888;line-height:1;padding:0}
        .tap-modal-close:hover{color:#333}
        .tap-modal-body{padding:20px}
        .tap-modal-info{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px;font-size:13px;margin-bottom:14px}
        .tap-modal-field{display:flex;flex-direction:column;gap:6px}
        .tap-modal-field label{font-size:12px;font-weight:700;color:var(--ta-adm-gray-700)}
        .tap-modal-field textarea{padding:8px 10px;border:1.5px solid var(--tap-border);border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;min-height:80px;outline:none;transition:border-color .2s}
        .tap-modal-field textarea:focus{border-color:var(--tap-blue)}
        .tap-modal-footer{padding:14px 20px;border-top:1px solid var(--tap-border);display:flex;gap:10px;justify-content:flex-end}
        /* یادداشت */
        .tap-note-section{margin-bottom:14px}
        .tap-note-label{font-size:12px;font-weight:700;color:var(--ta-adm-gray-700);margin-bottom:6px}
        .tap-note-content{background:var(--ta-adm-gray-50);border:1px solid var(--tap-border);border-radius:8px;padding:10px;font-size:13px;min-height:36px;color:var(--ta-adm-gray-700)}
        /* اعلان */
        #tap-save-notice{position:fixed;top:60px;left:50%;transform:translateX(-50%);background:var(--ta-adm-gray-900);color:#fff;padding:10px 24px;border-radius:8px;font-size:13px;z-index:99999;opacity:0;transition:opacity .3s;pointer-events:none;white-space:nowrap}
        #tap-save-notice.show{opacity:1}
        /* diff & approved fee */
        .tap-row-has-diff td{background:#fffbeb!important}
        .tap-diff-badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}
        .tap-diff-up{background:#d1fae5;color:#065f46}
        .tap-diff-down{background:#fee2e2;color:#991b1b}
        .tap-diff-wrap{display:flex;flex-direction:column;gap:4px;align-items:flex-start}
        .tap-diff-status-pill{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;white-space:nowrap}
        .tap-diff-ref{font-size:10px;color:var(--ta-adm-gray-400);white-space:nowrap}
        .tap-diff-hint{cursor:help;font-size:13px;vertical-align:middle}
        .tap-fee-stale{color:var(--ta-adm-red);text-decoration:line-through;font-size:11px}
        .tap-fee-ok{color:#059669;font-weight:700}
        .tap-fee-ta-ok{color:var(--ta-adm-purple);font-weight:700}
        .tap-fee-rejected{color:#f97316;font-weight:700}
        .tap-fee-pending{color:var(--ta-adm-amber);font-weight:700}
        .tap-pending-lbl{display:block;font-size:10px;color:var(--ta-adm-amber)}
        .tap-approved-fee-cell{min-width:110px}
        .tap-diff-cell{min-width:100px}
        .tap-btn-warning{background:var(--ta-adm-amber);color:#fff}
        .tap-changes-cell{min-width:120px}
        .tap-changes-entry{border-bottom:1px solid var(--ta-adm-gray-100);padding:10px 0}
        .tap-changes-entry:last-child{border-bottom:none}
        .tap-changes-entry-amount{font-size:16px;font-weight:800;color:#1d4ed8;font-family:monospace}
        .tap-changes-entry-date{font-size:11px;color:var(--ta-adm-gray-400);margin-top:2px}
        .tap-changes-entry-status{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;margin-top:4px}
        .tap-changes-divider{text-align:center;color:#d1d5db;font-size:12px;padding:6px 0;letter-spacing:.05em}
        .tap-changes-entry-note{font-size:12px;color:var(--ta-adm-gray-500);background:var(--ta-adm-gray-50);border-radius:6px;padding:4px 8px;margin-top:5px}
        /* تاریخچه timeline */
        .tap-timeline{padding:0;margin:0;list-style:none;border-right:3px solid var(--ta-adm-gray-200);margin-right:12px}
        .tap-timeline-item{position:relative;padding:0 24px 16px 0;margin-right:-3px}
        .tap-timeline-item:last-child{padding-bottom:0}
        .tap-timeline-dot{position:absolute;right:-8px;top:4px;width:14px;height:14px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 2px var(--ta-adm-gray-200)}
        .tap-timeline-dot-sent{background:var(--ta-adm-blue)}.tap-timeline-dot-resent{background:var(--ta-adm-purple)}
        .tap-timeline-dot-approved,.tap-timeline-dot-ta_approved,.tap-timeline-dot-admin_approved{background:var(--ta-adm-green)}
        .tap-timeline-dot-rejected,.tap-timeline-dot-admin_rejected{background:var(--ta-adm-red)}
        .tap-timeline-dot-ta_rejected{background:#f97316}
        .tap-timeline-dot-paid{background:#1d4ed8}
        .tap-timeline-meta{font-size:11px;color:var(--ta-adm-gray-400);margin-bottom:3px}
        .tap-timeline-action{font-size:13px;font-weight:700;color:var(--ta-adm-gray-900)}
        .tap-timeline-fee{font-size:12px;color:#059669;font-family:monospace;font-weight:700}
        .tap-timeline-note{font-size:12px;color:var(--ta-adm-gray-500);margin-top:4px;background:var(--ta-adm-gray-50);border-radius:6px;padding:5px 8px;border:1px solid var(--ta-adm-gray-200)}
        .tap-timeline-empty{color:var(--ta-adm-gray-400);font-size:13px;text-align:center;padding:20px}
        /* تعدیلات (مبلغ اضافی / کسورات) */
        .tap-adj-cell{min-width:140px;vertical-align:middle}
        .tap-adj-summary{display:flex;flex-wrap:wrap;align-items:center;gap:4px}
        .tap-adj-badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}
        .tap-adj-extra{background:#d1fae5;color:#065f46}
        .tap-adj-deduct{background:#fee2e2;color:#991b1b}
        .tap-adj-none{color:#aaa;font-size:11px}
        .tap-final-fee-cell{min-width:110px;vertical-align:middle}
        .tap-final-amount{font-size:14px;color:var(--ta-adm-gray-900)}
        .tap-final-up{color:#059669}
        .tap-final-down{color:#dc2626}
        .tap-th-final{background:#f0f9ff!important;color:#1d4ed8!important;font-weight:800!important}
        /* مودال تعدیلات */
        .tap-adj-summary-bar{background:linear-gradient(135deg,#eff6ff,#f0fdf4);border-bottom:1px solid var(--ta-adm-gray-200);padding:14px 20px;display:flex;gap:20px;flex-wrap:wrap;align-items:center}
        .tap-adj-summary-item{display:flex;flex-direction:column;align-items:center;gap:2px}
        .tap-adj-summary-label{font-size:10px;color:var(--ta-adm-gray-500);text-transform:uppercase;letter-spacing:.05em}
        .tap-adj-summary-value{font-size:16px;font-weight:800;font-family:monospace}
        .tap-adj-summary-base{color:var(--ta-adm-gray-700)}
        .tap-adj-summary-extra{color:#059669}
        .tap-adj-summary-deduct{color:#dc2626}
        .tap-adj-summary-final{color:#1d4ed8;font-size:18px}
        .tap-adj-summary-divider{color:#d1d5db;font-size:20px;align-self:center}
        .tap-adj-form-wrap{padding:16px 20px;border-bottom:1px solid #f0f4f8}
        .tap-adj-form-title{font-size:12px;font-weight:700;color:var(--ta-adm-gray-700);margin-bottom:10px;text-transform:uppercase;letter-spacing:.04em}
        .tap-adj-form-row{display:flex;gap:14px;flex-wrap:wrap}
        .tap-adj-field{display:flex;flex-direction:column;gap:5px;flex:1;min-width:130px}
        .tap-adj-field label{font-size:11px;font-weight:700;color:var(--ta-adm-gray-500)}
        .tap-adj-field input{padding:7px 10px;border:1.5px solid var(--tap-border);border-radius:8px;font-size:13px;font-family:inherit;outline:none;transition:border-color .2s}
        .tap-adj-field input:focus{border-color:var(--tap-blue)}
        .tap-adj-type-group{display:flex;gap:6px}
        .tap-adj-type-opt{display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;cursor:pointer;border:1.5px solid var(--tap-border);font-size:12px;font-weight:600;transition:all .15s;user-select:none}
        .tap-adj-type-extra:has(input:checked){border-color:#059669;background:#f0fdf4;color:#065f46}
        .tap-adj-type-deduct:has(input:checked){border-color:#dc2626;background:#fef2f2;color:#991b1b}
        .tap-adj-type-opt input{width:14px;height:14px}
        .tap-adj-list-wrap{padding:16px 20px}
        .tap-adj-list-title{font-size:12px;font-weight:700;color:var(--ta-adm-gray-700);margin-bottom:10px;text-transform:uppercase;letter-spacing:.04em}
        .tap-adj-item{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:8px;background:var(--ta-adm-gray-50);border:1px solid var(--ta-adm-gray-200);margin-bottom:6px;transition:background .15s}
        .tap-adj-item:hover{background:var(--ta-adm-gray-100)}
        .tap-adj-item-type{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}
        .tap-adj-item-extra .tap-adj-item-type{background:#d1fae5}
        .tap-adj-item-deduct .tap-adj-item-type{background:#fee2e2}
        .tap-adj-item-body{flex:1;min-width:0}
        .tap-adj-item-amount{font-size:14px;font-weight:800;font-family:monospace}
        .tap-adj-item-extra .tap-adj-item-amount{color:#059669}
        .tap-adj-item-deduct .tap-adj-item-amount{color:#dc2626}
        .tap-adj-item-desc{font-size:11px;color:var(--ta-adm-gray-500);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .tap-adj-item-actions{display:flex;gap:4px;flex-shrink:0}
        .tap-adj-empty{color:#aaa;font-size:13px;text-align:center;padding:16px}
        </style>

        <div id="tap-save-notice"></div>

        <!-- ═══ اسکریپت ═══ -->
        <script>
        (function($){
            var nonce         = '<?= $nonce ?>';
            var defRate       = <?= $def_rate ?>;
            var ajaxUrl       = '<?= admin_url('admin-ajax.php') ?>';
            var currentTermId = <?= (int)($filters['term'] ?? 0) ?>;

            // ── ذخیره درصد پیش‌فرض ──────────────────────────────────────────
            $('#tap-save-def-rate').on('click', function(){
                var r = parseFloat($('#tap-def-rate').val());
                if(isNaN(r)||r<0||r>100){ alert('درصد باید بین ۰ تا ۱۰۰ باشد.'); return; }
                $(this).prop('disabled',true).text('…');
                $.post(ajaxUrl,{action:'ta_payroll_save_def_rate',nonce:nonce,rate:r},function(res){
                    $('#tap-save-def-rate').prop('disabled',false).text('ذخیره');
                    if(res.success){ defRate=r; showNotice('✓ درصد پیش‌فرض ذخیره شد'); }
                    else alert(res.data||'خطا');
                });
            });

            // ── preview جدول تنظیم محصول ────────────────────────────────────
            $(document).on('input','.tap-cfg-input',function(){
                var $row = $(this).closest('tr');
                var price = parseFloat($row.find('.tap-calc-price-input').val()) || 0;
                var rate  = parseFloat($row.find('.tap-rate-input-prod').val())  || 0;
                $row.find('.tap-fee-preview').text(nf(Math.round(price*rate/100)));
            });

            // ── ذخیره تنظیمات یک محصول ──────────────────────────────────────
            $(document).on('click','.tap-save-product-cfg',function(){
                var $btn = $(this); var pid=$btn.data('pid');
                var $row=$btn.closest('tr');
                var price=parseFloat($row.find('.tap-calc-price-input').val())||0;
                var rate=parseFloat($row.find('.tap-rate-input-prod').val())||0;
                $btn.prop('disabled',true).text('…');
                var done=0;
                function check(){ done++; if(done>=2){ $btn.prop('disabled',false).text('ذخیره'); refreshTableRows(pid); showNotice('✓ تنظیمات دوره ذخیره شد'); } }
                $.post(ajaxUrl,{action:'ta_payroll_save_product_cfg',nonce:nonce,product_id:pid,field:'price',value:price},check);
                $.post(ajaxUrl,{action:'ta_payroll_save_product_cfg',nonce:nonce,product_id:pid,field:'rate', value:rate}, check);
            });

            // ── بازنشانی تنظیمات محصول ──────────────────────────────────────
            $(document).on('click','.tap-reset-product-cfg',function(){
                var $btn=$(this); var pid=$btn.data('pid'); var $row=$btn.closest('tr');
                if(!confirm('تنظیمات این دوره به مقادیر پیش‌فرض بازنشانی شود؟')) return;
                $btn.prop('disabled',true);
                var done=0;
                function check(){ done++; if(done>=2){ $btn.prop('disabled',false); refreshTableRows(pid); showNotice('↺ بازنشانی شد'); } }
                $.post(ajaxUrl,{action:'ta_payroll_reset_product_cfg',nonce:nonce,product_id:pid,field:'price'},function(r){ if(r.success) $row.find('.tap-calc-price-input').val(r.data.value); check(); });
                $.post(ajaxUrl,{action:'ta_payroll_reset_product_cfg',nonce:nonce,product_id:pid,field:'rate'}, function(r){ if(r.success) $row.find('.tap-rate-input-prod').val(r.data.value);  check(); });
            });

            // ── به‌روزرسانی ردیف‌های جدول اصلی ─────────────────────────────
            function refreshTableRows(pid){
                var $cfgRow=$('.tap-cfg-row[data-pid="'+pid+'"]');
                var newPrice=parseFloat($cfgRow.find('.tap-calc-price-input').val())||0;
                var newRate=parseFloat($cfgRow.find('.tap-rate-input-prod').val())||0;
                $('#tap-main-table .tap-row[data-pid="'+pid+'"]').each(function(){
                    $(this).find('.tap-calc-price').text(nf(newPrice));
                    $(this).find('.tap-row-rate').text(newRate);
                    var $fee=$(this).find('.tap-fee-amount');
                    if($fee.length){ var fee=Math.round(newPrice*newRate/100); $fee.attr('data-calc-price',newPrice).text(nf(fee)); }
                });
                updateTotalFee();
            }

            // ── toggle شامل/خارج ────────────────────────────────────────────
            $(document).on('change','.tap-excl-checkbox',function(){
                var id=$(this).data('id'); var include=$(this).is(':checked');
                var $row=$(this).closest('tr'); var pid=$row.data('pid');
                $(this).prop('disabled',true);
                $.post(ajaxUrl,{action:'ta_payroll_toggle_exclude',nonce:nonce,id:id,exclude:include?0:1,term_id:currentTermId},function(res){
                    if(res.success){
                        // آپدیت ردیف جدول اصلی
                        if(!include){
                            $row.addClass('tap-row-excluded');
                            $row.find('.tap-td-fee').html('<span class="tap-excluded-label">— خارج —</span>');
                        } else {
                            $row.removeClass('tap-row-excluded');
                            var $cfgRow=$('.tap-cfg-row[data-pid="'+pid+'"]');
                            var calcP=parseFloat($cfgRow.length?$cfgRow.find('.tap-calc-price-input').val():0)||0;
                            var rateVal=parseFloat($cfgRow.length?$cfgRow.find('.tap-rate-input-prod').val():defRate)||defRate;
                            var fee=Math.round(calcP*rateVal/100);
                            $row.find('.tap-td-fee').html('<span class="tap-fee-amount" data-pid="'+pid+'" data-calc-price="'+calcP+'">'+nf(fee)+'</span> <span class="tap-currency">ت</span>');
                        }
                        // آپدیت خلاصه‌های بالای صفحه از پاسخ سرور
                        applyServerSummary(res.data);
                        showNotice(include?'✓ به محاسبه افزوده شد':'✗ از محاسبه حذف شد');
                    } else alert(res.data||'خطا');
                    $('[data-id="'+id+'"].tap-excl-checkbox').prop('disabled',false);
                });
            });

            /**
             * اعمال خلاصه دریافت‌شده از سرور روی DOM
             * data: { total_fee, included_count, excluded_count, by_ta: { ta_id: {included,excluded,fee} } }
             */
            function applyServerSummary(data){
                if(!data) return;
                // کارت‌های بالا
                if(typeof data.total_fee    !== 'undefined') $('#tap-total-fee').text(nf(data.total_fee));
                if(typeof data.included_count !== 'undefined') $('.tap-stat-included').text(nf(data.included_count));
                if(typeof data.excluded_count !== 'undefined') $('.tap-stat-excluded').text(nf(data.excluded_count));
                // ردیف‌های breakdown به ازای هر استادیار
                if(data.by_ta){
                    $.each(data.by_ta,function(taId,s){
                        var $brow=$('.tap-breakdown-row[data-ta-id="'+taId+'"]');
                        if(!$brow.length) return;
                        $brow.find('.tap-badge-green').text(s.included);
                        $brow.find('.tap-badge-red').text(s.excluded);
                        var $feeCell=$brow.find('.tap-fee-cell');
                        var $diffHint=$feeCell.find('.tap-diff-hint').clone();
                        $feeCell.text(nf(s.fee)+' ت');
                        if($diffHint.length) $feeCell.append(' ').append($diffHint);
                    });
                }
            }

            function updateTotalFee(){
                // این تابع فقط برای آپدیت محلی بعد از تغییر قیمت/درصد محصول استفاده می‌شود
                var total=0;
                $('#tap-main-table .tap-row').not('.tap-row-excluded').find('.tap-fee-amount').each(function(){
                    total+=parseFloat($(this).text().replace(/,/g,''))||0;
                });
                $('#tap-total-fee').text(nf(total));
            }

            // ── خروجی XLSX جدول خلاصه به تفکیک استادیار (سمت سرور) ────────
            $('#tap-summary-export-btn').on('click', function() {
                var p = new URLSearchParams(window.location.search);
                p.set('action', 'ta_payroll_export_summary');
                p.set('nonce', nonce);
                window.location.href = ajaxUrl + '?' + p.toString();
            });

            // ── خروجی CSV (جدول اصلی تخصیص‌ها) ────────────────────────────
            $('#tap-export-btn').on('click',function(){
                var p=new URLSearchParams(window.location.search);
                p.set('action','ta_payroll_export_csv'); p.set('nonce',nonce);
                window.location.href=ajaxUrl+'?'+p.toString();
            });

            // ── نمایش/پنهان ─────────────────────────────────────────────────
            $('.tap-toggle-breakdown').on('click',function(){
                $('#'+$(this).data('target')).slideToggle(200);
            });

            // ════════════════════════════════════════════════════════════════
            // مودال درخواست تایید
            // ════════════════════════════════════════════════════════════════
            var _reqTaId=0, _reqTermId=0, _reqFee=0, _isResend=false;

            $(document).on('click','.tap-send-request',function(){
                _reqTaId=$(this).data('ta-id');
                _reqTermId=$(this).data('term-id');
                _reqFee=$(this).data('fee');
                _isResend=$(this).data('resend')===1||$(this).data('resend')==='1';
                var title=_isResend?'🔄 درخواست مجدد تایید حق‌الزحمه':'📨 درخواست تایید حق‌الزحمه';
                $('#tap-modal-title').text(title);
                var infoHtml='مبلغ قابل تایید: <strong>'+nf(_reqFee)+'</strong> تومان';
                if(_isResend) infoHtml='<span style="color:#f59e0b;font-weight:700">⚠️ مبلغ تغییر کرده است.</span> '+infoHtml;
                $('#tap-modal-info').html(infoHtml);
                $('#tap-modal-note').val('');
                $('#tap-modal-confirm').text(_isResend?'🔄 ارسال مجدد':'📨 ارسال درخواست');
                $('#tap-modal-overlay').fadeIn(150);
            });

            $('#tap-modal-close,#tap-modal-cancel').on('click',function(){ $('#tap-modal-overlay').fadeOut(150); });
            $('#tap-modal-overlay').on('click',function(e){ if($(e.target).is(this)) $(this).fadeOut(150); });

            $('#tap-modal-confirm').on('click',function(){
                var $btn=$(this); $btn.prop('disabled',true).text('در حال ارسال…');
                $.post(ajaxUrl,{
                    action:'ta_payroll_send_request', nonce:nonce,
                    ta_id:_reqTaId, term_id:_reqTermId, admin_note:$('#tap-modal-note').val()
                },function(res){
                    $btn.prop('disabled',false).text(_isResend?'🔄 ارسال مجدد':'📨 ارسال درخواست');
                    if(res.success){ $('#tap-modal-overlay').fadeOut(150); showNotice(_isResend?'🔄 درخواست مجدد ارسال شد':'📨 درخواست ارسال شد'); setTimeout(()=>location.reload(),1000); }
                    else alert(res.data||'خطا');
                });
            });

            // ════════════════════════════════════════════════════════════════
            // مودال بررسی (تایید/رد)
            // ════════════════════════════════════════════════════════════════
            $(document).on('click','.tap-review-request',function(){
                var reqId=$(this).data('req-id');
                var action=$(this).data('action');
                var isFinal=$(this).text().indexOf('نهایی')>=0||$(this).text().indexOf('مستقیم')>=0;
                var label=action==='approved'?(isFinal?'تایید نهایی':'تایید مستقیم'):'رد';
                if(!confirm('آیا این درخواست '+label+' شود؟')) return;
                // Show inline note field instead of prompt
                var note=prompt('توضیح برای استادیار (اختیاری):','');
                if(note===null) return;
                $.post(ajaxUrl,{action:'ta_payroll_review_request',nonce:nonce,req_id:reqId,status:action,admin_note:note},function(res){
                    if(res.success){ showNotice(action==='approved'?'✅ تایید نهایی شد':'❌ رد شد'); setTimeout(()=>location.reload(),800); }
                    else alert(res.data||'خطا');
                });
            });

            // ════════════════════════════════════════════════════════════════
            // مودال پرداخت
            // ════════════════════════════════════════════════════════════════
            var _payReqId=0;

            $(document).on('click','.tap-mark-paid',function(){
                _payReqId=$(this).data('req-id');
                $('#tap-pay-note').val('');
                $('#tap-pay-modal-overlay').fadeIn(150);
            });

            $('#tap-pay-modal-close,#tap-pay-modal-cancel').on('click',function(){ $('#tap-pay-modal-overlay').fadeOut(150); });
            $('#tap-pay-modal-overlay').on('click',function(e){ if($(e.target).is(this)) $(this).fadeOut(150); });

            $('#tap-pay-modal-confirm').on('click',function(){
                var $btn=$(this); $btn.prop('disabled',true).text('در حال ثبت…');
                $.post(ajaxUrl,{action:'ta_payroll_mark_paid',nonce:nonce,req_id:_payReqId,admin_note:$('#tap-pay-note').val()},function(res){
                    $btn.prop('disabled',false).text('✅ تایید پرداخت');
                    if(res.success){ $('#tap-pay-modal-overlay').fadeOut(150); showNotice('💳 پرداخت ثبت شد'); setTimeout(()=>location.reload(),1000); }
                    else alert(res.data||'خطا');
                });
            });

            // ════════════════════════════════════════════════════════════════
            // مودال تغییرات (Timeline کامل پرداخت‌ها و تغییرات)
            // ════════════════════════════════════════════════════════════════
            $(document).on('click','.tap-view-changes',function(){
                var reqId=$(this).data('req-id');
                var taName=$(this).data('ta-name');
                $('#tap-changes-modal-overlay .tap-modal-header h3').text('📜 تغییرات کامل — '+taName);
                $('#tap-changes-content').html('<div style="padding:20px;text-align:center;color:#9ca3af">⏳ در حال بارگذاری…</div>');
                $('#tap-changes-modal-overlay').fadeIn(150);

                $.post(ajaxUrl,{action:'ta_payroll_get_logs',nonce:nonce,req_id:reqId})
                .done(function(res){
                    if(!res||!res.success){
                        $('#tap-changes-content').html('<div style="color:#ef4444;padding:20px">⚠️ خطا در بارگذاری</div>');
                        return;
                    }
                    var logs=res.data||[];
                    if(!logs.length){ $('#tap-changes-content').html('<div style="color:#9ca3af;padding:20px;text-align:center">هیچ سابقه‌ای ثبت نشده.</div>'); return; }

                    var CHANGE_LABELS={
                        sent:          {icon:'📨', label:'ارسال درخواست تایید',         color:'#3b82f6'},
                        resent:        {icon:'🔄', label:'ارسال مجدد درخواست',           color:'#8b5cf6'},
                        ta_approved:   {icon:'✅', label:'تایید استادیار',               color:'#8b5cf6'},
                        ta_rejected:   {icon:'↩️', label:'رد استادیار',                  color:'#f97316'},
                        ta_approved_prev:{icon:'✅',label:'تایید مبلغ قبلی توسط استادیار',color:'#8b5cf6'},
                        admin_approved:{icon:'✅', label:'تایید نهایی ادمین',             color:'#10b981'},
                        admin_rejected:{icon:'❌', label:'رد ادمین',                     color:'#ef4444'},
                        approved:      {icon:'✅', label:'تایید نهایی',                  color:'#10b981'},
                        rejected:      {icon:'❌', label:'رد شد',                        color:'#ef4444'},
                        paid:          {icon:'💳', label:'پرداخت انجام شد',              color:'#1d4ed8'},
                        diff_ta_approved:{icon:'✅',label:'تایید تفاوت توسط استادیار',  color:'#8b5cf6'},
                        diff_ta_rejected:{icon:'↩️',label:'رد تفاوت توسط استادیار',    color:'#f97316'},
                        diff_admin_approved:{icon:'✅',label:'تایید تفاوت توسط ادمین', color:'#10b981'},
                        diff_admin_rejected:{icon:'❌',label:'رد تفاوت توسط ادمین',    color:'#ef4444'},
                        diff_paid:     {icon:'💳', label:'پرداخت تفاوت',                color:'#1d4ed8'},
                    };

                    var html='<div style="padding:4px 0">';
                    var prevLogFee=0;
                    $.each(logs, function(i,l){
                        var a=CHANGE_LABELS[l.action]||{icon:'•',label:l.action,color:'#888'};
                        var isPay=(l.action==='paid'||l.action==='diff_paid');
                        var bg=isPay?'#eff6ff':((l.action||'').indexOf('approved')>=0&&(l.action||'').indexOf('rejected')<0?'#f0fdf4':'#fff');
                        var curFee=parseFloat(l.total_fee||0);
                        html+='<div class="tap-changes-entry" style="background:'+bg+';border-radius:8px;padding:10px 14px;margin-bottom:8px;border:1px solid '+(isPay?'#bfdbfe':'#f3f4f6')+'">';
                        // آیکون + عنوان
                        html+='<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">';
                        html+='<span style="font-size:16px">'+a.icon+'</span>';
                        html+='<span style="font-weight:700;font-size:13px;color:'+a.color+'">'+a.label+'</span>';
                        html+='</div>';
                        // مبلغ + تفاوت
                        if(curFee>0){
                            html+='<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">';
                            html+='<div class="tap-changes-entry-amount">'+nf(curFee)+' <span style="font-size:12px;font-weight:400;color:#6b7280">تومان</span></div>';
                            if(prevLogFee>0 && Math.abs(curFee-prevLogFee)>1){
                                var logDiff=curFee-prevLogFee;
                                var dClr=logDiff>0?'#10b981':'#ef4444';
                                var dIco=logDiff>0?'▲':'▼';
                                var dLbl=logDiff>0?'افزایش':'کاهش';
                                html+='<span style="display:inline-flex;align-items:center;gap:3px;background:'+(logDiff>0?'#d1fae5':'#fee2e2')+';color:'+dClr+';padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700">'+dIco+' '+nf(Math.abs(logDiff))+' ت '+dLbl+'</span>';
                            }
                            html+='</div>';
                            prevLogFee=curFee;
                        }
                        // تاریخ و عامل
                        var actor=l.actor_name||(l.actor_type==='ta'?'استادیار':'ادمین');
                        html+='<div class="tap-changes-entry-date">📅 '+esc(l.created_at)+' &nbsp;|&nbsp; 👤 '+esc(actor)+'</div>';
                        // یادداشت
                        if(l.note) html+='<div class="tap-changes-entry-note">💬 '+esc(l.note)+'</div>';
                        html+='</div>';
                    });
                    html+='</div>';
                    $('#tap-changes-content').html(html);
                })
                .fail(function(){
                    $('#tap-changes-content').html('<div style="color:#ef4444;padding:20px">⚠️ خطای شبکه</div>');
                });
            });

            $('#tap-changes-modal-close,#tap-changes-close-btn').on('click',function(){ $('#tap-changes-modal-overlay').fadeOut(150); });
            $('#tap-changes-modal-overlay').on('click',function(e){ if($(e.target).is(this)) $(this).fadeOut(150); });

            // ════════════════════════════════════════════════════════════════
            // مودال تاریخچه (timeline)
            // ════════════════════════════════════════════════════════════════
            var ACTION_LABELS={
                sent:          {icon:'📨', label:'درخواست ارسال شد',           cls:'tap-timeline-dot-sent'},
                resent:        {icon:'🔄', label:'درخواست مجدد ارسال شد',      cls:'tap-timeline-dot-resent'},
                ta_approved:   {icon:'✅', label:'تایید شد توسط استادیار',     cls:'tap-timeline-dot-ta_approved'},
                ta_rejected:   {icon:'↩️', label:'رد شد توسط استادیار',        cls:'tap-timeline-dot-ta_rejected'},
                ta_approved_prev:{icon:'✅', label:'تایید مبلغ قبلی توسط استادیار', cls:'tap-timeline-dot-ta_approved'},
                admin_approved:{icon:'✅', label:'تایید نهایی توسط ادمین',      cls:'tap-timeline-dot-approved'},
                admin_rejected:{icon:'❌', label:'رد شد توسط ادمین',            cls:'tap-timeline-dot-rejected'},
                approved:      {icon:'✅', label:'تایید نهایی (ادمین)',          cls:'tap-timeline-dot-approved'},
                rejected:      {icon:'❌', label:'رد شد (ادمین)',               cls:'tap-timeline-dot-rejected'},
                paid:          {icon:'💳', label:'پرداخت انجام شد',             cls:'tap-timeline-dot-paid'},
            };

            $(document).on('click','.tap-view-history',function(){
                var reqId=$(this).data('req-id');
                var taName=$(this).data('ta-name');
                $('#tap-notes-modal-overlay .tap-modal-header h3').text('📋 تاریخچه درخواست — '+taName);
                $('#tap-notes-modal-footer').html('<button class="tap-btn tap-btn-secondary" id="tap-notes-close-btn">بستن</button>');
                $('#tap-notes-admin').html('<div class="tapr-loading" style="padding:20px 0"><div class="ta-spinner" style="width:20px;height:20px;border-width:2px"></div> بارگذاری…</div>');
                $('#tap-notes-ta').html('');
                $('#tap-review-note-wrap').hide();
                $('#tap-notes-modal-overlay').fadeIn(150);

                $.post(ajaxUrl,{action:'ta_payroll_get_logs',nonce:nonce,req_id:reqId})
                .done(function(res){
                    if(!res||!res.success){
                        $('#tap-notes-admin').html('<div class="tap-timeline-empty" style="color:#ef4444">⚠️ خطا در بارگذاری تاریخچه'+(res&&res.data?' ('+res.data+')':'')+'</div>');
                        return;
                    }
                    var logs=res.data;
                    if(!logs||!logs.length){$('#tap-notes-admin').html('<div class="tap-timeline-empty">هیچ سابقه‌ای ثبت نشده.</div>');return;}
                    var html='<ul class="tap-timeline">';
                    $.each(logs,function(i,l){
                        var a=ACTION_LABELS[l.action]||{icon:'•',label:l.action,cls:''};
                        html+='<li class="tap-timeline-item">';
                        html+='<span class="tap-timeline-dot '+a.cls+'"></span>';
                        html+='<div class="tap-timeline-meta">'+esc(l.created_at)+' &nbsp;|&nbsp; '+(l.actor_name?esc(l.actor_name):l.actor_type==='ta'?'استادیار':'ادمین')+'</div>';
                        html+='<div><span class="tap-timeline-action">'+a.icon+' '+a.label+'</span>';
                        if(l.total_fee>0) html+=' &nbsp; <span class="tap-timeline-fee">'+nf(l.total_fee)+' ت</span>';
                        html+='</div>';
                        if(l.note) html+='<div class="tap-timeline-note">'+esc(l.note)+'</div>';
                        html+='</li>';
                    });
                    html+='</ul>';
                    $('#tap-notes-admin').html(html);
                    $('#tap-notes-ta').html('');
                })
                .fail(function(xhr,status,err){
                    $('#tap-notes-admin').html('<div class="tap-timeline-empty" style="color:#ef4444">⚠️ خطای شبکه: '+status+' — '+err+'</div>');
                });
            });

            $('#tap-notes-modal-close').on('click',function(){ $('#tap-notes-modal-overlay').fadeOut(150); });
            $(document).on('click','#tap-notes-close-btn',function(){ $('#tap-notes-modal-overlay').fadeOut(150); });
            $('#tap-notes-modal-overlay').on('click',function(e){ if($(e.target).is(this)) $(this).fadeOut(150); });
            function esc(s){ return $('<div>').text(s||'').html(); }

            // ────────────────────────────────────────────────────────────────
            function showNotice(msg){
                $('#tap-save-notice').text(msg).addClass('show');
                setTimeout(function(){ $('#tap-save-notice').removeClass('show'); },2400);
            }
            function nf(n){ return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,','); }

            // ── مسیر مستقل تفاوت ──────────────────────────────────────────
            $(document).on('click','.tap-diff-review', function(){
                var btn=$(this), reqId=btn.data('req-id'), action=btn.data('action');
                var diffAmount=btn.data('diff-amount')||0;
                var label=action==='approved'?'تایید تفاوت':'رد تفاوت';
                var note=prompt(label+' — یادداشت (اختیاری)\nمبلغ تفاوت: '+Math.round(diffAmount).toLocaleString()+' ت','');
                if(note===null) return;
                $.post(ajaxUrl,{action:'ta_payroll_diff_review',nonce:nonce,req_id:reqId,status:action,admin_note:note},function(res){
                    if(res.success){ showNotice(action==='approved'?'✅ تفاوت تایید شد':'❌ تفاوت رد شد'); setTimeout(()=>location.reload(),800); }
                    else alert('خطا: '+(res.data||'نامشخص'));
                });
            });

            $(document).on('click','.tap-diff-mark-paid', function(){
                var btn=$(this), reqId=btn.data('req-id'), diffAmount=btn.data('diff-amount')||0;
                if(!confirm('پرداخت تفاوت به مبلغ '+Math.round(diffAmount).toLocaleString()+' ت تایید می‌شود؟')) return;
                var note=prompt('یادداشت پرداخت تفاوت (اختیاری):','');
                if(note===null) return;
                $.post(ajaxUrl,{action:'ta_payroll_diff_mark_paid',nonce:nonce,req_id:reqId,admin_note:note},function(res){
                    if(res.success){ showNotice('💳 پرداخت تفاوت ثبت شد'); setTimeout(()=>location.reload(),800); }
                    else alert('خطا: '+(res.data||'نامشخص'));
                });
            });

            // ════════════════════════════════════════════════════════════════
            // مودال تعدیلات (مبلغ اضافی / کسورات)
            // ════════════════════════════════════════════════════════════════
            var _adjTaId=0, _adjTermId=0, _adjEditId=0;

            function nfAdj(n){ return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,','); }

            function renderAdjSummaryBar(data){
                var base=data.base_fee||0, net=data.net||0, final=data.final_fee||0;
                var extraSum=0, deductSum=0;
                (data.adjustments||[]).forEach(function(a){
                    if(a.type==='extra') extraSum+=parseFloat(a.amount);
                    else deductSum+=parseFloat(a.amount);
                });
                var html='';
                html+='<div class="tap-adj-summary-item"><span class="tap-adj-summary-label">حق‌الزحمه پایه</span><span class="tap-adj-summary-value tap-adj-summary-base">'+nfAdj(base)+' ت</span></div>';
                if(extraSum>0){
                    html+='<div class="tap-adj-summary-divider">+</div>';
                    html+='<div class="tap-adj-summary-item"><span class="tap-adj-summary-label">مبلغ اضافی</span><span class="tap-adj-summary-value tap-adj-summary-extra">'+nfAdj(extraSum)+' ت</span></div>';
                }
                if(deductSum>0){
                    html+='<div class="tap-adj-summary-divider">−</div>';
                    html+='<div class="tap-adj-summary-item"><span class="tap-adj-summary-label">کسورات</span><span class="tap-adj-summary-value tap-adj-summary-deduct">'+nfAdj(deductSum)+' ت</span></div>';
                }
                html+='<div class="tap-adj-summary-divider">=</div>';
                html+='<div class="tap-adj-summary-item"><span class="tap-adj-summary-label">💰 مبلغ نهایی</span><span class="tap-adj-summary-value tap-adj-summary-final">'+nfAdj(final)+' ت</span></div>';
                $('#tap-adj-summary-bar').html(html);
            }

            function renderAdjList(adjs){
                if(!adjs||!adjs.length){
                    $('#tap-adj-list').html('<div class="tap-adj-empty">هیچ تعدیلی ثبت نشده است.</div>');
                    return;
                }
                var html='';
                adjs.forEach(function(a){
                    var isExtra=a.type==='extra';
                    var icon=isExtra?'➕':'➖';
                    var amtStr=nfAdj(a.amount)+' ت';
                    html+='<div class="tap-adj-item tap-adj-item-'+(isExtra?'extra':'deduct')+'" data-adj-id="'+a.id+'">';
                    html+='<div class="tap-adj-item-type">'+icon+'</div>';
                    html+='<div class="tap-adj-item-body">';
                    html+='<div class="tap-adj-item-amount">'+(isExtra?'+ ':'− ')+amtStr+'</div>';
                    if(a.description) html+='<div class="tap-adj-item-desc">'+esc(a.description)+'</div>';
                    html+='</div>';
                    html+='<div class="tap-adj-item-actions">';
                    html+='<button class="tap-btn tap-btn-xs tap-btn-outline tap-adj-edit-item" data-adj-id="'+a.id+'" data-type="'+a.type+'" data-amount="'+a.amount+'" data-desc="'+esc(a.description||'')+'">✏️</button>';
                    html+='<button class="tap-btn tap-btn-xs tap-btn-danger tap-adj-delete-item" data-adj-id="'+a.id+'">🗑️</button>';
                    html+='</div>';
                    html+='</div>';
                });
                $('#tap-adj-list').html(html);
            }

            function updateTableRow(taId, data){
                var $row=$('.tap-breakdown-row[data-ta-id="'+taId+'"]');
                if(!$row.length) return;
                var net=data.net||0;
                var final=data.final_fee||0;
                var extraSum=0, deductSum=0;
                (data.adjustments||[]).forEach(function(a){
                    if(a.type==='extra') extraSum+=parseFloat(a.amount);
                    else deductSum+=parseFloat(a.amount);
                });
                // به‌روز ستون تعدیلات
                var adjHtml='';
                if(extraSum>0) adjHtml+='<span class="tap-adj-badge tap-adj-extra">+'+nfAdj(extraSum)+' ت</span> ';
                if(deductSum>0) adjHtml+='<span class="tap-adj-badge tap-adj-deduct">−'+nfAdj(deductSum)+' ت</span> ';
                if(!extraSum&&!deductSum) adjHtml='<span class="tap-adj-none">—</span>';
                adjHtml+=' <button class="tap-btn tap-btn-xs tap-btn-outline tap-adj-edit-btn" data-ta-id="'+taId+'" data-ta-name="'+$row.find('strong:first').text()+'" data-term-id="'+_adjTermId+'">✏️ تعدیل</button>';
                $row.find('.tap-adj-cell .tap-adj-summary').html(adjHtml);
                // به‌روز مبلغ نهایی
                var cls=net>0?'tap-final-up':(net<0?'tap-final-down':'');
                $row.find('.tap-final-fee-cell .tap-final-amount').removeClass('tap-final-up tap-final-down').addClass(cls).text(nfAdj(final)+' ت');
                // به‌روز data-fee دکمه ارسال
                $row.find('.tap-send-request').attr('data-fee', final);
            }

            function resetAdjForm(){
                _adjEditId=0;
                $('input[name="tap_adj_type"][value="extra"]').prop('checked',true);
                $('#tap-adj-amount').val('');
                $('#tap-adj-desc').val('');
                $('#tap-adj-save-btn').text('💾 ذخیره تعدیل');
                $('#tap-adj-cancel-edit').hide();
            }

            $(document).on('click','.tap-adj-edit-btn',function(){
                _adjTaId=$(this).data('ta-id');
                _adjTermId=$(this).data('term-id');
                var taName=$(this).data('ta-name');
                $('#tap-adj-modal-title').text('✏️ تعدیلات — '+taName);
                resetAdjForm();
                $('#tap-adj-list').html('<div class="tap-adj-empty">در حال بارگذاری…</div>');
                $('#tap-adj-summary-bar').html('');
                $('#tap-adj-modal-overlay').fadeIn(150);
                $.post(ajaxUrl,{action:'ta_payroll_get_adjustments',nonce:nonce,ta_id:_adjTaId,term_id:_adjTermId},function(res){
                    if(res.success){
                        renderAdjSummaryBar(res.data);
                        renderAdjList(res.data.adjustments||[]);
                    } else {
                        $('#tap-adj-list').html('<div style="color:#ef4444;padding:16px">خطا در بارگذاری</div>');
                    }
                });
            });

            $('#tap-adj-modal-close,#tap-adj-close-btn').on('click',function(){ $('#tap-adj-modal-overlay').fadeOut(150); });
            $('#tap-adj-modal-overlay').on('click',function(e){ if($(e.target).is(this)) $(this).fadeOut(150); });

            $('#tap-adj-cancel-edit').on('click',function(){ resetAdjForm(); });

            $(document).on('click','.tap-adj-edit-item',function(){
                _adjEditId=$(this).data('adj-id');
                $('input[name="tap_adj_type"][value="'+$(this).data('type')+'"]').prop('checked',true);
                $('#tap-adj-amount').val($(this).data('amount'));
                $('#tap-adj-desc').val($(this).data('desc'));
                $('#tap-adj-save-btn').text('💾 ذخیره ویرایش');
                $('#tap-adj-cancel-edit').show();
                $('#tap-adj-amount').focus();
            });

            $(document).on('click','.tap-adj-delete-item',function(){
                if(!confirm('آیا این تعدیل حذف شود؟')) return;
                var adjId=$(this).data('adj-id');
                var $btn=$(this).prop('disabled',true);
                $.post(ajaxUrl,{action:'ta_payroll_delete_adjustment',nonce:nonce,adj_id:adjId,ta_id:_adjTaId,term_id:_adjTermId},function(res){
                    if(res.success){
                        renderAdjSummaryBar(res.data);
                        renderAdjList(res.data.adjustments||[]);
                        updateTableRow(_adjTaId, res.data);
                        showNotice('🗑️ تعدیل حذف شد');
                    } else { alert(res.data||'خطا'); $btn.prop('disabled',false); }
                });
            });

            $('#tap-adj-save-btn').on('click',function(){
                var type=$('input[name="tap_adj_type"]:checked').val();
                var amount=parseFloat($('#tap-adj-amount').val());
                var desc=$('#tap-adj-desc').val().trim();
                if(!amount||amount<=0){ alert('مبلغ باید بزرگ‌تر از صفر باشد.'); return; }
                var $btn=$(this).prop('disabled',true).text('در حال ذخیره…');
                $.post(ajaxUrl,{
                    action:'ta_payroll_save_adjustment', nonce:nonce,
                    adj_id:_adjEditId, ta_id:_adjTaId, term_id:_adjTermId,
                    type:type, amount:amount, description:desc
                },function(res){
                    $btn.prop('disabled',false);
                    if(res.success){
                        renderAdjSummaryBar(res.data);
                        renderAdjList(res.data.adjustments||[]);
                        updateTableRow(_adjTaId, res.data);
                        resetAdjForm();
                        showNotice(_adjEditId?'✅ تعدیل ویرایش شد':'✅ تعدیل ذخیره شد');
                        $('#tap-adj-save-btn').text('💾 ذخیره تعدیل');
                    } else { alert(res.data||'خطا'); }
                });
            });

        })(jQuery);
        </script>
        <?php
    }


    // ═════════════════════════════════════════════════════════════════════════
    // AJAX — مسیر مستقل تفاوت
    // ═════════════════════════════════════════════════════════════════════════

    /** استادیار: پاسخ به تفاوت */
    public function ajax_diff_ta_respond(): void {
        check_ajax_referer( 'ta_dashboard_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error();

        global $wpdb;
        $uid    = get_current_user_id();
        $id     = absint( $_POST['req_id'] ?? 0 );
        $action = sanitize_key( $_POST['ta_action'] ?? '' ); // approve | reject
        $note   = sanitize_textarea_field( $_POST['ta_note'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'شناسه نامعتبر.' );

        $ta_tbl = TA_Database::table('assistants');
        $ta = $wpdb->get_row( $wpdb->prepare("SELECT id FROM {$ta_tbl} WHERE user_id=%d", $uid) );
        if ( ! $ta ) wp_send_json_error( 'دسترسی مجاز نیست.' );

        $tbl = TA_Database::table('payroll_requests');
        $req = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$tbl} WHERE id=%d AND ta_id=%d", $id, $ta->id) );
        if ( ! $req ) wp_send_json_error( 'درخواست یافت نشد.' );
        if ( $req->diff_status !== 'pending' ) wp_send_json_error( 'تفاوت در وضعیت قابل پاسخ نیست.' );

        $new_diff_status = $action === 'approve' ? 'ta_approved' : 'ta_rejected';
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$tbl} SET diff_status=%s, diff_ta_note=%s, diff_reviewed_at=%s WHERE id=%d",
            $new_diff_status, $note, current_time('mysql'), $id
        ) );

        // لاگ
        $log_tbl = TA_Database::table('payroll_request_logs');
        $wpdb->insert( $log_tbl, [
            'request_id' => $id, 'ta_id' => $req->ta_id, 'term_id' => $req->term_id,
            'action' => 'diff_' . $new_diff_status, 'actor_type' => 'ta',
            'actor_id' => $uid, 'total_fee' => floatval($req->diff_amount), 'note' => $note,
        ], [ '%d','%d','%d','%s','%s','%d','%f','%s' ] );

        wp_send_json_success( [ 'diff_status' => $new_diff_status ] );
    }

    /** ادمین: تایید یا رد تفاوت */
    public function ajax_diff_review(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'دسترسی مجاز نیست.' );

        global $wpdb;
        $id     = absint( $_POST['req_id'] ?? 0 );
        $status = sanitize_key( $_POST['status'] ?? '' ); // approved | rejected
        $note   = sanitize_textarea_field( $_POST['admin_note'] ?? '' );
        if ( ! $id || ! in_array( $status, ['approved','rejected'], true ) ) wp_send_json_error( 'اطلاعات نامعتبر.' );

        $tbl = TA_Database::table('payroll_requests');
        $req = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$tbl} WHERE id=%d", $id) );
        if ( ! $req ) wp_send_json_error( 'درخواست یافت نشد.' );
        if ( $req->diff_status === 'paid' ) wp_send_json_error( 'تفاوت قبلاً پرداخت شده.' );

        $diff_approved = $status === 'approved' ? floatval($req->diff_amount) : null;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$tbl} SET diff_status=%s, diff_approved_fee=%s, diff_admin_note=%s, diff_reviewed_at=%s WHERE id=%d",
            $status, $diff_approved, $note, current_time('mysql'), $id
        ) );

        $log_tbl = TA_Database::table('payroll_request_logs');
        $wpdb->insert( $log_tbl, [
            'request_id' => $id, 'ta_id' => $req->ta_id, 'term_id' => $req->term_id,
            'action' => 'diff_admin_' . $status, 'actor_type' => 'admin',
            'actor_id' => get_current_user_id(), 'total_fee' => floatval($req->diff_amount), 'note' => $note,
        ], [ '%d','%d','%d','%s','%s','%d','%f','%s' ] );

        wp_send_json_success( [ 'diff_status' => $status ] );
    }

    /** ادمین: ثبت پرداخت تفاوت */
    public function ajax_diff_mark_paid(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'دسترسی مجاز نیست.' );

        global $wpdb;
        $id   = absint( $_POST['req_id'] ?? 0 );
        $note = sanitize_textarea_field( $_POST['admin_note'] ?? '' );
        if ( ! $id ) wp_send_json_error( 'شناسه نامعتبر.' );

        $tbl = TA_Database::table('payroll_requests');
        $req = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$tbl} WHERE id=%d", $id) );
        if ( ! $req || $req->diff_status !== 'approved' ) wp_send_json_error( 'فقط تفاوت تایید‌شده قابل پرداخت است.' );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$tbl} SET diff_status='paid', diff_paid_at=%s, diff_admin_note=%s WHERE id=%d",
            current_time('mysql'), $note, $id
        ) );

        $log_tbl = TA_Database::table('payroll_request_logs');
        $wpdb->insert( $log_tbl, [
            'request_id' => $id, 'ta_id' => $req->ta_id, 'term_id' => $req->term_id,
            'action' => 'diff_paid', 'actor_type' => 'admin',
            'actor_id' => get_current_user_id(), 'total_fee' => floatval($req->diff_amount), 'note' => $note,
        ], [ '%d','%d','%d','%s','%s','%d','%f','%s' ] );

        wp_send_json_success( [ 'diff_status' => 'paid' ] );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // منطق داده
    // ═════════════════════════════════════════════════════════════════════════

    private function build_filters_from_request(): array {
        return [
            'term'        => absint( $_GET['filter_term']        ?? 0 ),
            'ta'          => absint( $_GET['filter_ta']          ?? 0 ),
            'status'      => sanitize_key( $_GET['filter_status']      ?? '' ),
            'course_type' => sanitize_key( $_GET['filter_course_type'] ?? '' ),
            'calc'        => sanitize_key( $_GET['filter_calc']        ?? '' ),
            'search'      => sanitize_text_field( $_GET['search']      ?? '' ),
        ];
    }

    private function build_where( array $f ): array {
        global $wpdb;
        $where = [ '1=1' ]; $params = [];
        if ( $f['term'] )        { $where[] = 'a.term_id = %d';    $params[] = $f['term']; }
        if ( $f['ta'] )          { $where[] = 'ta.id = %d';        $params[] = $f['ta']; }
        if ( $f['status'] )      { $where[] = 'a.status = %s';     $params[] = $f['status']; }
        if ( $f['course_type'] ) { $where[] = 'a.course_type = %s';$params[] = $f['course_type']; }
        if ( $f['search'] ) {
            $like = '%' . $wpdb->esc_like( $f['search'] ) . '%';
            $where[] = '(su.display_name LIKE %s OR su.user_email LIKE %s OR tu.display_name LIKE %s OR p.post_title LIKE %s)';
            $params = array_merge( $params, [ $like, $like, $like, $like ] );
        }
        return [ implode( ' AND ', $where ), $params ];
    }

    private function get_row_count( array $f ): int {
        global $wpdb;
        [ $w, $p ] = $this->build_where( $f );
        $sql = "SELECT a.id FROM " . $this->base_from() . " WHERE {$w}";
        if ( $p ) $sql = $wpdb->prepare( $sql, $p );
        $ids = $wpdb->get_col( $sql );
        if ( $f['calc'] === 'excluded' || $f['calc'] === 'included' ) {
            $ex = $this->get_excluded_ids();
            $ids = array_filter( $ids, fn($id) => $f['calc'] === 'excluded' ? isset($ex[(int)$id]) : ! isset($ex[(int)$id]) );
        }
        return count( $ids );
    }

    private function get_rows( array $f, bool $all=false, int $limit=0, int $offset=0 ): array {
        global $wpdb;
        [ $w, $p ] = $this->build_where( $f );
        $sql = "SELECT a.id, a.student_id, a.ta_id, a.product_id, a.course_type, a.status,
                       a.created_at, a.term_id,
                       su.display_name AS student_name, su.user_email AS student_email,
                       tu.display_name AS ta_name,     tu.user_email AS ta_email,
                       p.post_title    AS product_name,
                       t.name          AS term_name
                FROM " . $this->base_from() . " WHERE {$w} ORDER BY a.created_at DESC";
        if ( $p ) $sql = $wpdb->prepare( $sql, $p );
        $rows = $wpdb->get_results( $sql ) ?: [];
        if ( $f['calc'] === 'excluded' || $f['calc'] === 'included' ) {
            $ex = $this->get_excluded_ids();
            $rows = array_values( array_filter( $rows, fn($r) =>
                $f['calc'] === 'excluded' ? isset($ex[(int)$r->id]) : ! isset($ex[(int)$r->id])
            ) );
        }
        return ( ! $all && $limit > 0 ) ? array_slice( $rows, $offset, $limit ) : $rows;
    }

    private function base_from(): string {
        global $wpdb;
        $a  = TA_Database::table('assignments');
        $ta = TA_Database::table('assistants');
        $t  = TA_Database::table('terms');
        return "{$a} a
            JOIN {$ta}               ta ON ta.id  = a.ta_id
            JOIN {$wpdb->users}      su ON su.ID  = a.student_id
            JOIN {$wpdb->users}      tu ON tu.ID  = ta.user_id
            LEFT JOIN {$t}           t  ON t.id   = a.term_id
            LEFT JOIN {$wpdb->posts} p  ON p.ID   = a.product_id";
    }

    private function get_wc_price( int $pid ): float {
        static $cache = [];
        if ( isset($cache[$pid]) ) return $cache[$pid];
        $price = 0.0;
        if ( function_exists('wc_get_product') ) {
            $prod = wc_get_product( $pid );
            if ( $prod ) $price = floatval( $prod->get_price() );
        }
        return $cache[$pid] = $price;
    }

    private function get_excluded_ids(): array {
        $arr = json_decode( get_option( self::OPTION_EXCL_IDS, '[]' ), true );
        return array_flip( array_map( 'intval', is_array($arr) ? $arr : [] ) );
    }

    private function get_product_rates(): array {
        $v = json_decode( get_option( self::OPTION_RATES, '{}' ), true );
        return is_array($v) ? array_map('floatval', $v) : [];
    }

    private function get_product_prices(): array {
        $v = json_decode( get_option( self::OPTION_PRICES, '{}' ), true );
        return is_array($v) ? array_map('floatval', $v) : [];
    }

    private function get_unique_products( array $rows ): array {
        $products = [];
        foreach ( $rows as $r ) {
            $pid = (int) $r->product_id;
            if ( ! isset($products[$pid]) ) {
                $products[$pid] = [
                    'name'        => $r->product_name ?: "محصول #{$pid}",
                    'course_type' => $r->course_type,
                ];
            }
        }
        uasort( $products, fn($a,$b) => strcmp($a['name'], $b['name']) );
        return $products;
    }

    private function build_summary( array $rows, float $def_rate, array $p_rates, array $p_prices, array $excluded ): array {
        $total_fee = $included = $excl_count = 0;
        $by_ta = [];

        foreach ( $rows as $r ) {
            $pid        = (int) $r->product_id;
            $wc_price   = $this->get_wc_price( $pid );
            $calc_price = $p_prices[$pid] ?? $wc_price;
            $rate       = $p_rates[$pid]  ?? $def_rate;
            $is_excl    = isset( $excluded[(int)$r->id] );
            $fee        = $is_excl ? 0 : round( $calc_price * $rate / 100 );

            if ( $is_excl ) {
                $excl_count++;
            } else {
                $included++;
                $total_fee += $fee;
            }

            $tk = (int) $r->ta_id;
            if ( ! isset($by_ta[$tk]) ) {
                $by_ta[$tk] = [
                    'name'             => $r->ta_name,
                    'email'            => $r->ta_email,
                    'included'         => 0,
                    'excluded'         => 0,
                    'total_calc_price' => 0,
                    'fee'              => 0,
                    'course_types'     => [],
                ];
            }
            if ( $is_excl ) {
                $by_ta[$tk]['excluded']++;
            } else {
                $by_ta[$tk]['included']++;
                $by_ta[$tk]['total_calc_price'] += $calc_price;
                $by_ta[$tk]['fee']              += $fee;
            }
            if ( ! $is_excl ) {
                if ( ! isset( $by_ta[$tk]['course_types'][$r->course_type] ) ) {
                    $by_ta[$tk]['course_types'][$r->course_type] = 0;
                }
                $by_ta[$tk]['course_types'][$r->course_type]++;
            }
        }

        foreach ( $by_ta as &$d ) {
            $d['course_type_counts'] = $d['course_types'];
            $d['course_types']       = array_keys( $d['course_types'] );
            $d['variety']            = count( $d['course_types'] );
        }
        unset($d);

        uasort( $by_ta, fn($a,$b) => $b['fee'] <=> $a['fee'] );

        return [
            'total_fee'      => $total_fee,
            'included_count' => $included,
            'excluded_count' => $excl_count,
            'by_ta'          => $by_ta,
        ];
    }

    /** محاسبه مجموع حق‌الزحمه یک استادیار در یک ترم */
    private function calc_ta_term_fee( int $ta_id, int $term_id ): float {
        $f = [ 'term'=>$term_id, 'ta'=>$ta_id, 'status'=>'', 'course_type'=>'', 'calc'=>'', 'search'=>'' ];
        $rows     = $this->get_rows( $f, true );
        $excluded = $this->get_excluded_ids();
        $rates    = $this->get_product_rates();
        $prices   = $this->get_product_prices();
        $def_rate = floatval( get_option( self::OPTION_DEF_RATE, 20 ) );
        $total = 0.0;
        foreach ( $rows as $r ) {
            if ( isset($excluded[(int)$r->id]) ) continue;
            $pid        = (int) $r->product_id;
            $calc_price = $prices[$pid] ?? $this->get_wc_price($pid);
            $rate       = $rates[$pid]  ?? $def_rate;
            $total     += round( $calc_price * $rate / 100 );
        }
        return $total;
    }

    /** تمام درخواست‌ها به‌صورت نقشه key=ta_id_term_id */
    private function get_all_requests_map(): array {
        global $wpdb;
        $tbl = TA_Database::table('payroll_requests');
        $rows = $wpdb->get_results("SELECT * FROM {$tbl}") ?: [];
        $map  = [];
        foreach ( $rows as $r ) {
            $map[ $r->ta_id . '_' . $r->term_id ] = (array) $r;
        }
        return $map;
    }

    /** درخواست‌های یک ترم با نام استادیار */
    private function get_requests_for_term( int $term_id ): array {
        global $wpdb;
        $tbl    = TA_Database::table('payroll_requests');
        $ta_tbl = TA_Database::table('assistants');
        $sql = $term_id
            ? $wpdb->prepare(
                "SELECT r.*, u.display_name AS ta_name, u.user_email AS ta_email
                 FROM {$tbl} r
                 JOIN {$ta_tbl} ta ON ta.id=r.ta_id
                 JOIN {$wpdb->users} u ON u.ID=ta.user_id
                 WHERE r.term_id=%d ORDER BY r.requested_at DESC",
                $term_id
              )
            : "SELECT r.*, u.display_name AS ta_name, u.user_email AS ta_email
               FROM {$tbl} r
               JOIN {$ta_tbl} ta ON ta.id=r.ta_id
               JOIN {$wpdb->users} u ON u.ID=ta.user_id
               ORDER BY r.requested_at DESC";
        return $wpdb->get_results($sql) ?: [];
    }

    // ─── تعدیلات: helper ──────────────────────────────────────────────────────

    /** دریافت تمام تعدیلات یک ترم به‌صورت نقشه key=ta_id */
    private function get_adjustments_map( int $term_id ): array {
        global $wpdb;
        if ( ! $term_id ) return [];
        $tbl  = TA_Database::table('payroll_adjustments');
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$tbl} WHERE term_id=%d ORDER BY created_at ASC", $term_id
        ) ) ?: [];
        $map = [];
        foreach ( $rows as $r ) {
            $tid = (int) $r->ta_id;
            if ( ! isset($map[$tid]) ) $map[$tid] = [];
            $map[$tid][] = [
                'id'          => (int) $r->id,
                'type'        => $r->type,
                'amount'      => floatval( $r->amount ),
                'description' => $r->description,
                'created_at'  => $r->created_at,
            ];
        }
        return $map;
    }

    /** جمع خالص تعدیلات برای یک استادیار (مثبت=اضافی، منفی=کسورات) */
    private function calc_adjustment_net( array $adjustments ): float {
        $net = 0.0;
        foreach ( $adjustments as $a ) {
            $net += $a['type'] === 'extra' ? $a['amount'] : -$a['amount'];
        }
        return $net;
    }

    // ─── تعدیلات: AJAX ───────────────────────────────────────────────────────

    /** ذخیره یا ویرایش یک تعدیل */
    public function ajax_save_adjustment(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'دسترسی مجاز نیست.' );

        global $wpdb;
        $adj_id  = absint( $_POST['adj_id']  ?? 0 );
        $ta_id   = absint( $_POST['ta_id']   ?? 0 );
        $term_id = absint( $_POST['term_id'] ?? 0 );
        $type    = sanitize_key( $_POST['type'] ?? '' );
        $amount  = floatval( $_POST['amount'] ?? 0 );
        $desc    = sanitize_textarea_field( $_POST['description'] ?? '' );

        if ( ! $ta_id || ! $term_id ) wp_send_json_error( 'اطلاعات ناقص.' );
        if ( ! in_array( $type, ['extra','deduction'], true ) ) wp_send_json_error( 'نوع نامعتبر.' );
        if ( $amount <= 0 ) wp_send_json_error( 'مبلغ باید بزرگ‌تر از صفر باشد.' );

        $tbl = TA_Database::table('payroll_adjustments');

        if ( $adj_id ) {
            // ویرایش
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id=%d AND ta_id=%d AND term_id=%d", $adj_id, $ta_id, $term_id ) );
            if ( ! $existing ) wp_send_json_error( 'رکورد یافت نشد.' );
            $wpdb->update( $tbl,
                [ 'type'=>$type, 'amount'=>$amount, 'description'=>$desc ],
                [ 'id'=>$adj_id ],
                [ '%s','%f','%s' ], [ '%d' ]
            );
        } else {
            // افزودن
            $wpdb->insert( $tbl, [
                'ta_id'       => $ta_id,
                'term_id'     => $term_id,
                'type'        => $type,
                'amount'      => $amount,
                'description' => $desc,
                'created_by'  => get_current_user_id(),
            ], [ '%d','%d','%s','%f','%s','%d' ] );
            $adj_id = (int) $wpdb->insert_id;
        }

        // بازگشت لیست جدید
        $map = $this->get_adjustments_map( $term_id );
        $adjs = $map[$ta_id] ?? [];
        $net  = $this->calc_adjustment_net( $adjs );

        // محاسبه fee پایه
        $base_fee = $this->calc_ta_term_fee( $ta_id, $term_id );

        wp_send_json_success( [
            'adj_id'       => $adj_id,
            'adjustments'  => $adjs,
            'net'          => $net,
            'base_fee'     => $base_fee,
            'final_fee'    => $base_fee + $net,
        ] );
    }

    /** حذف یک تعدیل */
    public function ajax_delete_adjustment(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'دسترسی مجاز نیست.' );

        global $wpdb;
        $adj_id  = absint( $_POST['adj_id']  ?? 0 );
        $ta_id   = absint( $_POST['ta_id']   ?? 0 );
        $term_id = absint( $_POST['term_id'] ?? 0 );
        if ( ! $adj_id ) wp_send_json_error( 'شناسه نامعتبر.' );

        $tbl = TA_Database::table('payroll_adjustments');
        $wpdb->delete( $tbl, [ 'id'=>$adj_id ], [ '%d' ] );

        $map  = $this->get_adjustments_map( $term_id );
        $adjs = $map[$ta_id] ?? [];
        $net  = $this->calc_adjustment_net( $adjs );
        $base_fee = $this->calc_ta_term_fee( $ta_id, $term_id );

        wp_send_json_success( [
            'adjustments' => $adjs,
            'net'         => $net,
            'base_fee'    => $base_fee,
            'final_fee'   => $base_fee + $net,
        ] );
    }

    /** دریافت لیست تعدیلات یک استادیار */
    public function ajax_get_adjustments(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'دسترسی مجاز نیست.' );

        $ta_id   = absint( $_POST['ta_id']   ?? 0 );
        $term_id = absint( $_POST['term_id'] ?? 0 );
        if ( ! $ta_id || ! $term_id ) wp_send_json_error( 'اطلاعات ناقص.' );

        $map  = $this->get_adjustments_map( $term_id );
        $adjs = $map[$ta_id] ?? [];
        $net  = $this->calc_adjustment_net( $adjs );
        $base_fee = $this->calc_ta_term_fee( $ta_id, $term_id );

        wp_send_json_success( [
            'adjustments' => $adjs,
            'net'         => $net,
            'base_fee'    => $base_fee,
            'final_fee'   => $base_fee + $net,
        ] );
    }
}
