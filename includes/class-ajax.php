<?php
defined( 'ABSPATH' ) || exit;

/**
 * تمام درخواست‌های AJAX سیستم
 *
 * اصلاحات نسخه 1.1.1:
 * - [FIX-01] admin_assign_with_custom_link: امضای تابع assign_standard_with_capacity کامل شد (product_id + student_gender)
 * - [FIX-02] get_prev_course_info: احراز هویت اضافه شد (admin: ta_admin_nonce | frontend: ta_product_nonce)
 * - [FIX-03] ثبت تکراری action حذف شد
 * - [FIX-04] update_assignment_group: nonce اصلاح شد (ta_ajax_nonce → ta_admin_nonce)
 * - [FIX-05] admin_get_available_tas: پیام‌های DEBUG از production حذف شدند
 * - [FIX-06] grade_student: بررسی status=active قبل از ثبت نمره اضافه شد
 * - [FIX-07] save_capacity: whitelist برای course_type اضافه شد
 */
class TA_Ajax {

    /** انواع دوره معتبر */
    private const VALID_COURSE_TYPES = [ 'creative', 'intro', 'advanced', 'professional', 'critique', 'basic_writing', 'ladder' ];

    // انواع دوره‌هایی که مجاز به داشتن گروه هستند
    private const GROUP_ALLOWED_TYPES = [ 'creative', 'basic_writing', 'critique', 'professional' ];

    public function __construct() {
        $actions = [
            'ta_get_available_tas'             => 'get_available_tas',
            'ta_assign_creative'               => 'assign_creative',
            'ta_assign_with_group'             => 'assign_with_group',
            'ta_update_prereq_group_info'      => 'update_prereq_group_info',
            'ta_assign_allowed'                => 'assign_allowed',
            'ta_grade_student'                 => 'grade_student',
            'ta_save_capacity'                 => 'save_capacity',
            'ta_save_all_capacities'           => 'save_all_capacities',
            'ta_add_group_slot'                => 'add_group_slot',
            'ta_update_capacity'               => 'update_capacity',
            'ta_delete_capacity'               => 'delete_capacity',
            'ta_update_group_slot'             => 'update_group_slot',
            'ta_delete_group_slot'             => 'delete_group_slot',
            'ta_blacklist_student'             => 'blacklist_student',
            'ta_remove_from_blacklist'         => 'remove_from_blacklist',
            'ta_assign_critique'               => 'assign_critique',
            'ta_assign_professional'           => 'assign_professional',
            'ta_assign_ladder'                 => 'assign_ladder',
            'ta_bulk_update_assignment_status' => 'bulk_update_assignment_status',
            'ta_admin_get_available_tas'       => 'admin_get_available_tas',
            'ta_admin_assign_by_capacity'      => 'admin_assign_by_capacity',
            'ta_get_capacity_groups'           => 'get_capacity_groups',
            'ta_get_capacity_assignments'      => 'get_capacity_assignments',
            'ta_get_term_stats'                => 'get_term_stats',
        ];

        foreach ( $actions as $action => $method ) {
            add_action( "wp_ajax_{$action}", [ $this, $method ] );
        }

        // نقد آثار (سابقاً «نقد داستان» — نام‌های داخلی/جدول برای سازگاری تغییر نکرده‌اند)
        add_action( 'wp_ajax_ta_story_critique_submit', [ $this, 'story_critique_submit' ] );
        add_action( 'wp_ajax_ta_story_critique_get',    [ $this, 'story_critique_get' ] );

        // نقد آثار — ظرفیت/قیمت‌پایه (استادیار خودش، با ثبت تاریخچه)
        add_action( 'wp_ajax_ta_sc_ta_field_update',  [ $this, 'sc_ta_field_update' ] );
        add_action( 'wp_ajax_ta_sc_ta_field_history', [ $this, 'sc_ta_field_history' ] );

        // نقد آثار — ظرفیت/قیمت‌پایه (ادمین، از صفحه گزارش، با ثبت تاریخچه)
        add_action( 'wp_ajax_ta_sc_admin_field_update',  [ $this, 'sc_admin_field_update' ] );
        add_action( 'wp_ajax_ta_sc_admin_field_history', [ $this, 'sc_admin_field_history' ] );

        // نقد آثار — آپلود/تغییر تصویر پروفایل استادیار توسط ادمین (از صفحه گزارش)
        add_action( 'wp_ajax_ta_sc_admin_avatar_upload', [ $this, 'sc_admin_avatar_upload' ] );
        add_action( 'wp_ajax_ta_sc_admin_specialty_pricing_get',  [ $this, 'sc_admin_specialty_pricing_get' ] );
        add_action( 'wp_ajax_ta_sc_admin_specialty_pricing_save', [ $this, 'sc_admin_specialty_pricing_save' ] );
        add_action( 'wp_ajax_ta_sc_ta_specialty_pricing_get',     [ $this, 'sc_ta_specialty_pricing_get' ] );
        add_action( 'wp_ajax_ta_sc_ta_specialty_pricing_save',    [ $this, 'sc_ta_specialty_pricing_save' ] );
        add_action( 'wp_ajax_ta_sc_admin_order_status_update',    [ $this, 'sc_admin_order_status_update' ] );
        add_action( 'wp_ajax_ta_sc_admin_order_delete',           [ $this, 'sc_admin_order_delete' ] );

        // نقد آثار — گردش‌کار سفارش‌ها توسط استادیار (پیشخوان)
        add_action( 'wp_ajax_ta_co_ta_status',     [ $this, 'co_ta_status' ] );
        add_action( 'wp_ajax_ta_co_ta_save_notes', [ $this, 'co_ta_save_notes' ] );
        add_action( 'wp_ajax_ta_co_ta_deliver',    [ $this, 'co_ta_deliver' ] );
        add_action( 'wp_ajax_ta_co_ta_complete',   [ $this, 'co_ta_complete' ] );

        // [FIX-03] ثبت بدون تکرار — قبلاً دوبار ثبت می‌شد
        add_action( 'wp_ajax_ta_update_assignment_group',       [ $this, 'update_assignment_group' ] );
        add_action( 'wp_ajax_ta_admin_assign_with_custom_link', [ $this, 'admin_assign_with_custom_link' ] );

        // [FIX-02] دو handler جدا با nonce متفاوت
        add_action( 'wp_ajax_ta_admin_get_prev_course_info', [ $this, 'admin_get_prev_course_info' ] );
        add_action( 'wp_ajax_ta_get_prev_course_info',       [ $this, 'frontend_get_prev_course_info' ] );

        // [FIX-MSG-02] صفحه پیام‌ها — mark_read و delete
        add_action( 'wp_ajax_ta_admin_msg_mark_read', [ $this, 'admin_msg_mark_read' ] );
        add_action( 'wp_ajax_ta_admin_msg_delete',    [ $this, 'admin_msg_delete' ] );
        add_action( 'wp_ajax_ta_get_available_tas',   [ $this, 'admin_get_available_tas' ] );
        add_action( 'wp_ajax_ta_admin_quick_assign',    [ $this, 'admin_quick_assign' ] );
        add_action( 'wp_ajax_ta_get_join_logs',         [ $this, 'get_join_logs' ] );

        // ویرایش نمره از پیشخوان استادیار
        add_action( 'wp_ajax_ta_ta_update_grade', [ $this, 'ta_update_grade' ] );

        // [GROUP-JOIN-LOG] لینک واسط ورود به گروه — لاگ می‌زند سپس ریدایرکت می‌کند
        // هم برای کاربر لاگین و هم مهمان ثبت می‌شود تا اگر لینک بدون لاگین باز شد،
        // کاربر به صفحه ورود هدایت شود (نه یک خطای خام ۰).
        add_action( 'wp_ajax_ta_join_group',        [ $this, 'join_group' ] );
        add_action( 'wp_ajax_nopriv_ta_join_group', [ $this, 'join_group' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [FIX-02] اطلاعات دوره قبلی — نسخه ادمین (با ta_admin_nonce)
    // ─────────────────────────────────────────────────────────────────────────

    public function admin_get_prev_course_info(): void {
        check_ajax_referer( 'ta_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز.' ] );
        }

        $this->process_prev_course_info();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [FIX-02] اطلاعات دوره قبلی — نسخه فرانت (با ta_product_nonce)
    // ─────────────────────────────────────────────────────────────────────────

    public function frontend_get_prev_course_info(): void {
        $this->verify( 'ta_product_nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'برای این عملیات باید وارد شوید.' ] );
        }

        // هنرجو فقط اطلاعات خودش را می‌تواند ببیند
        $student_id = get_current_user_id();
        $product_id = absint( $_POST['product_id'] ?? 0 );

        $prereqs        = TA_Product_Meta::get_prerequisites( $product_id );
        $prev_messenger = 'نامشخص';
        $prev_link      = 'نامشخص';

        foreach ( $prereqs as $prereq_id ) {
            $prev_assignment = TA_Assignment::get_assignment( $student_id, (int) $prereq_id );
            if ( $prev_assignment && ! empty( $prev_assignment->group_link ) ) {
                $prev_messenger = $prev_assignment->messenger;
                $prev_link      = $prev_assignment->group_link;
                break;
            }
        }

        wp_send_json_success( [ 'messenger' => $prev_messenger, 'group_link' => $prev_link ] );
    }

    /**
     * منطق مشترک دریافت اطلاعات دوره قبلی (برای admin handler)
     */
    private function process_prev_course_info(): void {
        $student_id = absint( $_POST['student_id'] ?? 0 );
        $product_id = absint( $_POST['product_id'] ?? 0 );

        $prereqs        = TA_Product_Meta::get_prerequisites( $product_id );
        $prev_messenger = 'نامشخص';
        $prev_link      = 'نامشخص';

        foreach ( $prereqs as $prereq_id ) {
            $prev_assignment = TA_Assignment::get_assignment( $student_id, (int) $prereq_id );
            if ( $prev_assignment && ! empty( $prev_assignment->group_link ) ) {
                $prev_messenger = $prev_assignment->messenger;
                $prev_link      = $prev_assignment->group_link;
                break;
            }
        }

        wp_send_json_success( [ 'messenger' => $prev_messenger, 'group_link' => $prev_link ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ویرایش پیام‌رسان/لینک گروه دوره پیش‌نیاز توسط خود هنرجو
    // (مقدماتی/پیشرفته و نردبان حرفه‌ای: وقتی اطلاعات استخراج‌شده از دوره پیش‌نیاز
    //  اشتباه باشد، هنرجو می‌تواند آن را اصلاح کند؛ تغییر مستقیماً روی رکورد
    //  تخصیص دوره پیش‌نیاز خودِ هنرجو ذخیره می‌شود)
    // ─────────────────────────────────────────────────────────────────────────

    public function update_prereq_group_info(): void {
        $this->verify( 'ta_product_nonce' );
        $uid = $this->require_login();

        $product_id = absint( $_POST['product_id'] ?? 0 );
        $messenger  = sanitize_text_field( $_POST['messenger']  ?? '' );
        $group_link = esc_url_raw( TA_URL_Helper::normalize( wp_unslash( $_POST['group_link'] ?? '' ) ) );

        if ( ! $product_id ) {
            wp_send_json_error( [ 'message' => 'اطلاعات ارسالی نامعتبر است.' ] );
        }

        $supported = (array) TA_Settings_Helper::get( 'supported_messengers', [ 'telegram', 'bale', 'eitaa' ] );
        if ( ! in_array( $messenger, $supported, true ) ) {
            wp_send_json_error( [ 'message' => 'پیام‌رسان انتخابی در این سیستم پشتیبانی نمی‌شود.' ] );
        }
        if ( empty( $group_link ) || ! filter_var( $group_link, FILTER_VALIDATE_URL ) ) {
            wp_send_json_error( [ 'message' => 'لینک گروه نامعتبر است. لطفاً یک لینک کامل وارد کنید.' ] );
        }
        if ( ! TA_URL_Helper::is_valid_for_messenger( $group_link, $messenger ) ) {
            wp_send_json_error( [ 'message' => 'لینک گروه باید با نشانی معتبر پیام‌رسان انتخاب‌شده شروع شود.' ] );
        }

        $settings    = TA_Eligibility::get_product_settings( $product_id );
        $course_type = $settings->course_type ?? '';

        // همان تخصیص پیش‌نیازی که سیستم برای استخراج پیام‌رسان/لینک استفاده می‌کند
        $prereq_assignment = TA_Eligibility::get_repeat_assignment( $uid, $product_id, $course_type );
        if ( ! $prereq_assignment ) {
            $prereq_assignment = TA_Assignment::find_prereq_assignment( $uid, $product_id, $course_type );
        }

        if ( ! $prereq_assignment || empty( $prereq_assignment->id ) ) {
            wp_send_json_error( [ 'message' => 'تخصیص دوره پیش‌نیاز یافت نشد. لطفاً با پشتیبانی تماس بگیرید.' ] );
        }

        global $wpdb;
        $table   = TA_Database::table( 'assignments' );
        $updated = $wpdb->update(
            $table,
            [ 'messenger' => $messenger, 'group_link' => $group_link ],
            // student_id هم در شرط هست تا هنرجو فقط بتواند تخصیص خودش را ویرایش کند
            [ 'id' => (int) $prereq_assignment->id, 'student_id' => $uid ],
            [ '%s', '%s' ],
            [ '%d', '%d' ]
        );

        if ( $updated === false ) {
            wp_send_json_error( [ 'message' => 'خطا در ذخیره اطلاعات. لطفاً دوباره تلاش کنید.' ] );
        }

        wp_send_json_success( [
            'message'    => 'اطلاعات پیام‌رسان و لینک گروه با موفقیت اصلاح شد.',
            'messenger'  => $messenger,
            'group_link' => $group_link,
        ] );
    }


    public function bulk_update_assignment_status(): void {
        $this->verify( 'ta_dashboard_nonce' );

        $ids        = array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) );
        $new_status = sanitize_text_field( $_POST['new_status'] ?? '' );

        if ( empty( $ids ) || ! in_array( $new_status, [ 'active', 'completed' ], true ) ) {
            wp_send_json_error( [ 'message' => 'داده‌های نامعتبر.' ] );
        }

        [, $ta] = $this->require_ta();

        global $wpdb;
        $table        = TA_Database::table( 'assignments' );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = %s
                 WHERE id IN ({$placeholders}) AND ta_id = %d",
                array_merge( [ $new_status ], $ids, [ $ta->id ] )
            )
        );

        wp_send_json_success( [
            'message' => $updated . ' تخصیص به‌روز شد.',
            'updated' => $updated,
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [FIX-04] آپدیت گروه تخصیص — nonce اصلاح شد به ta_admin_nonce
    // ─────────────────────────────────────────────────────────────────────────

    public function update_assignment_group(): void {
        // [FIX-04] قبلاً ta_ajax_nonce بود که با taSystem.nonce سازگار نبود
        check_ajax_referer( 'ta_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز.' ] );
        }

        $assignment_id = absint( $_POST['assignment_id'] ?? 0 );
        $messenger     = sanitize_text_field( $_POST['messenger'] ?? '' );
        $group_link    = esc_url_raw( TA_URL_Helper::normalize( $_POST['group_link'] ?? '' ) );

        if ( ! $assignment_id ) {
            wp_send_json_error( [ 'message' => 'شناسه نامعتبر.' ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ta_assignments';

        $updated = $wpdb->update(
            $table,
            [ 'messenger' => $messenger, 'group_link' => $group_link ],
            [ 'id'        => $assignment_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            wp_send_json_error( [ 'message' => 'خطا در به‌روزرسانی.' ] );
        }

        wp_send_json_success( [ 'message' => 'اطلاعات گروه به‌روز شد.' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تخصیص نقد اثر
    // ─────────────────────────────────────────────────────────────────────────

    public function assign_critique(): void {
        try {
            $this->verify( 'ta_product_nonce' );

            if ( ! is_user_logged_in() ) {
                wp_send_json_error( [ 'message' => 'لطفاً ابتدا وارد سایت شوید.' ] );
            }

            $product_id = absint( $_POST['product_id'] ?? 0 );
            $student_id = get_current_user_id();

            if ( ! $product_id ) {
                wp_send_json_error( [ 'message' => 'اطلاعات ارسالی نامعتبر است.' ] );
            }

            $settings = TA_Product_Meta::get_settings( $product_id );

            if ( ! $settings ) {
                wp_send_json_error( [ 'message' => 'تنظیمات این دوره یافت نشد.' ] );
            }

            if ( $settings->course_type !== 'critique' ) {
                wp_send_json_error( [ 'message' => 'این دوره برای نقد اثر تنظیم نشده است.' ] );
            }

            $ta_user_id = get_post_meta( $product_id, '_ta_critique_ta_id', true );

            if ( ! $ta_user_id ) {
                wp_send_json_error( [ 'message' => 'استادیار این دوره در تنظیمات محصول مشخص نشده است.' ] );
            }

            global $wpdb;
            $assistant_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM " . TA_Database::table( 'assistants' ) . " WHERE user_id = %d",
                $ta_user_id
            ) );

            if ( ! $assistant_row ) {
                wp_send_json_error( [ 'message' => 'کاربر انتخاب شده در لیست استادیاران فعال یافت نشد.' ] );
            }

            $real_ta_id     = (int) $assistant_row->id;
            $course_type    = $settings->course_type;
            $messenger      = $settings->messenger ?? 'telegram';
            $student_gender = get_user_meta( $student_id, 'gender', true ) ?: 'unknown';
            $group_link     = $settings->pro_group_link ?? '';


            $assigned = TA_Assignment::assign_with_student_group(
                $student_id, $real_ta_id, $product_id,
                $settings->term_id, $course_type,
                $messenger, $student_gender, $group_link
            );

            if ( is_array( $assigned ) && ( $assigned['success'] ?? false ) ) {
                wp_send_json_success( [
                    'message'  => 'ثبت‌نام شما با موفقیت انجام شد.',
                    'group_link' => $group_link,
                ] );
            } else {
                wp_send_json_error( [ 'message' => $assigned['message'] ?? 'خطا در تخصیص استادیار.' ] );
            }

        } catch ( Throwable $e ) {
            wp_send_json_error( [ 'message' => 'خطای سیستم. لطفاً دوباره تلاش کنید.' ] );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ویرایش ظرفیت
    // ─────────────────────────────────────────────────────────────────────────

    public function update_capacity(): void {
        $this->verify( 'ta_dashboard_nonce' );
        [, $ta] = $this->require_ta();

        $capacity_id    = absint( $_POST['capacity_id'] ?? 0 );
        $term_id        = absint( $_POST['term_id']     ?? 0 );
        $course_type    = sanitize_text_field( $_POST['course_type']    ?? '' );
        $student_gender = sanitize_text_field( $_POST['student_gender'] ?? 'both' );
        $total_capacity = absint( $_POST['total_capacity'] ?? 0 );
        $messenger      = sanitize_text_field( $_POST['messenger']      ?? 'both' );

        // [FIX-07] اعتبارسنجی course_type
        if ( ! in_array( $course_type, self::VALID_COURSE_TYPES, true ) ) {
            wp_send_json_error( [ 'message' => 'نوع دوره نامعتبر است.' ] );
        }

        if ( ! in_array( $messenger, [ 'telegram', 'bale', 'eitaa', 'both' ], true ) ) {
            $messenger = 'both';
        }

        if ( ! in_array( $student_gender, [ 'male', 'female', 'both' ], true ) ) {
            wp_send_json_error( [ 'message' => 'جنسیت نامعتبر است.' ] );
        }

        $cap_table = TA_Database::table( 'capacities' );
        $cap       = TA_Database::get_row(
            "SELECT * FROM {$cap_table} WHERE id = %d AND ta_id = %d",
            [ $capacity_id, $ta->id ]
        );

        if ( ! $cap ) {
            wp_send_json_error( [ 'message' => 'ظرفیت یافت نشد.' ] );
        }

        if ( $total_capacity < $cap->used_capacity ) {
            wp_send_json_error( [ 'message' => "ظرفیت کل نمی‌تواند کمتر از {$cap->used_capacity} (استفاده‌شده) باشد." ] );
        }

        $updated = TA_Database::update( 'ta_capacities', [
            'term_id'        => $term_id,
            'course_type'    => $course_type,
            'student_gender' => $student_gender,
            'total_capacity' => $total_capacity,
            'messenger'      => $messenger,
        ], [ 'id' => $capacity_id ] );

        // [BUGFIX-SILENT-FAIL] قبلاً نتیجه چک نمی‌شد؛ اگر ترکیب جدید (ترم/نوع‌دوره/جنسیت/پیام‌رسان)
        // با یک ظرفیت دیگر تداخل کلید یکتا داشت، آپدیت بی‌صدا شکست می‌خورد ولی پیام موفقیت نشان داده می‌شد
        if ( $updated === false ) {
            wp_send_json_error( [ 'message' => 'خطا در ذخیره‌سازی. احتمالاً ظرفیتی با همین ترم/نوع‌دوره/جنسیت/پیام‌رسان از قبل ثبت شده است.' ] );
        }

        wp_send_json_success( [ 'message' => 'ظرفیت با موفقیت ویرایش شد.' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // حذف ظرفیت
    // ─────────────────────────────────────────────────────────────────────────

    private function cooperation_allows_new_assignment( int $ta_id, int $term_id ): array {
        // ترم‌هایی که ادمین «الزام تعیین نوع همکاری» را برایشان خاموش کرده، بررسی نمی‌شوند
        if ( ! (bool) get_option( 'ta_term_require_cooperation_' . $term_id, 1 ) ) return [ true, '' ];
        $setting = get_option( 'ta_cooperation_' . $ta_id . '_' . $term_id, [] );
        $mode = is_array( $setting ) ? (string) ( $setting['mode'] ?? '' ) : '';
        if ( in_array( $mode, [ 'professional', 'critique', 'professional_critique' ], true ) ) return [ true, '' ];
        if ( ! in_array( $mode, [ 'educational', 'development', 'capacity' ], true ) ) return [ false, 'نوع همکاری این ترم ثبت نشده است.' ];
        if ( ( $setting['status'] ?? 'complete' ) === 'incomplete' ) return [ false, 'با حذف ظرفیت، شرایط حداقلی نوع همکاری کمتر شده است؛ لطفاً ظرفیت‌ها را اصلاح کنید تا همکاری دوباره ثبت شود.' ];
        global $wpdb;
        $table = TA_Database::table( 'capacities' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT course_type,total_capacity FROM {$table} WHERE ta_id=%d AND term_id=%d", $ta_id, $term_id ) );
        $weights = [ 'creative'=>1, 'basic_writing'=>1.5, 'intro'=>2, 'advanced'=>3, 'ladder'=>0.5 ];
        $total=0; $score=0; $intro=0; $advanced=0;
        foreach ( $rows as $row ) { $n=max(0,(int)$row->total_capacity); if(isset($weights[$row->course_type])){$total+=$n;$score+=$n*$weights[$row->course_type];} if($row->course_type==='intro')$intro+=$n; if($row->course_type==='advanced')$advanced+=$n; }
        $ok = $mode === 'educational' ? ($total>=22 && $score>=35) : ( $mode === 'development' ? ($total>=15 && $score>=20 && !empty($setting['commitment'])) : ($advanced>=20 || ($total>=32 && $score>=57 && ($intro>=12 || $advanced>=12))) );
        return $ok ? [ true, '' ] : [ false, 'با حذف ظرفیت، شرایط حداقلی نوع همکاری کمتر شده است؛ لطفاً ظرفیت‌ها را اصلاح کنید تا همکاری دوباره ثبت شود.' ];
    }

    public function delete_capacity(): void {
        $this->verify( 'ta_dashboard_nonce' );
        [, $ta] = $this->require_ta();

        $capacity_id = absint( $_POST['capacity_id'] ?? 0 );
        $cap_table   = TA_Database::table( 'capacities' );

        $cap = TA_Database::get_row(
            "SELECT * FROM {$cap_table} WHERE id = %d AND ta_id = %d",
            [ $capacity_id, $ta->id ]
        );

        if ( ! $cap ) {
            wp_send_json_error( [ 'message' => 'ظرفیت یافت نشد.' ] );
        }

        if ( $cap->used_capacity > 0 ) {
            wp_send_json_error( [ 'message' => 'این ظرفیت در حال استفاده است و قابل حذف نیست.' ] );
        }

        TA_Database::delete( 'ta_groups',    [ 'capacity_id' => $capacity_id ] );
        TA_Database::delete( 'ta_capacities', [ 'id'         => $capacity_id ] );
        $cooperation_key = 'ta_cooperation_' . (int) $ta->id . '_' . (int) $cap->term_id;
        $cooperation = get_option( $cooperation_key, [] );
        if ( is_array( $cooperation ) && in_array( $cooperation['mode'] ?? '', [ 'educational', 'development', 'capacity' ], true ) ) {
            $cooperation['status'] = 'incomplete';
            $cooperation['status_message'] = 'با حذف ظرفیت، حداقل شرایط این همکاری دیگر برقرار نیست؛ لطفاً ظرفیت‌ها را اصلاح کنید.';
            update_option( $cooperation_key, $cooperation, false );
        }

        wp_send_json_success( [ 'message' => 'ظرفیت با موفقیت حذف شد.' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ویرایش گروه
    // ─────────────────────────────────────────────────────────────────────────

    public function update_group_slot(): void {
        $this->verify( 'ta_dashboard_nonce' );

        $group_id       = absint( $_POST['group_id']       ?? 0 );
        $messenger_type = sanitize_text_field( $_POST['messenger_type'] ?? '' );
        $group_link     = esc_url_raw( TA_URL_Helper::normalize( $_POST['group_link'] ?? '' ) );

        $gr_table = TA_Database::table( 'groups' );
        $is_admin = current_user_can( 'manage_options' );

        if ( $is_admin ) {
            $group = TA_Database::get_row( "SELECT * FROM {$gr_table} WHERE id = %d", [ $group_id ] );
        } else {
            [, $ta] = $this->require_ta();
            $group  = TA_Database::get_row(
                "SELECT * FROM {$gr_table} WHERE id = %d AND ta_id = %d",
                [ $group_id, $ta->id ]
            );
        }

        if ( ! $group ) {
            wp_send_json_error( [ 'message' => 'گروه یافت نشد یا دسترسی ندارید.' ] );
        }

        if ( $group->is_used ) {
            wp_send_json_error( [ 'message' => 'این گروه در حال استفاده است و قابل ویرایش نیست.' ] );
        }

        TA_Database::update( 'ta_groups', [
            'messenger_type' => $messenger_type,
            'group_link'     => $group_link,
        ], [ 'id' => $group_id ] );

        wp_send_json_success( [ 'message' => 'گروه با موفقیت ویرایش شد.' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // حذف گروه
    // ─────────────────────────────────────────────────────────────────────────

    public function delete_group_slot(): void {
        $this->verify( 'ta_dashboard_nonce' );

        $group_id = absint( $_POST['group_id'] ?? 0 );
        $gr_table = TA_Database::table( 'groups' );
        $is_admin = current_user_can( 'manage_options' );

        if ( $is_admin ) {
            $group = TA_Database::get_row( "SELECT * FROM {$gr_table} WHERE id = %d", [ $group_id ] );
        } else {
            [, $ta] = $this->require_ta();
            $group  = TA_Database::get_row(
                "SELECT * FROM {$gr_table} WHERE id = %d AND ta_id = %d",
                [ $group_id, $ta->id ]
            );
        }

        if ( ! $group ) {
            wp_send_json_error( [ 'message' => 'گروه یافت نشد یا دسترسی ندارید.' ] );
        }

        if ( $group->is_used ) {
            wp_send_json_error( [ 'message' => 'این گروه در حال استفاده است و قابل حذف نیست.' ] );
        }

        TA_Database::delete( 'ta_groups', [ 'id' => $group_id ] );

        wp_send_json_success( [ 'message' => 'گروه با موفقیت حذف شد.' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ابزار مشترک
    // ─────────────────────────────────────────────────────────────────────────

    private function verify( string $nonce_action ): void {
        if ( ! check_ajax_referer( $nonce_action, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'توکن امنیتی نامعتبر است.' ] );
        }
    }

    private function require_login(): int {
        $uid = get_current_user_id();
        if ( ! $uid ) {
            wp_send_json_error( [ 'message' => 'برای این عملیات باید وارد شوید.' ] );
        }
        return $uid;
    }

    private function require_ta(): array {
        $uid = $this->require_login();
        if ( ! TA_Roles::is_ta( $uid ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز.' ] );
        }
        $ta_table = TA_Database::table( 'assistants' );
        $ta       = TA_Database::get_row( "SELECT * FROM {$ta_table} WHERE user_id = %d", [ $uid ] );
        if ( ! $ta ) {
            wp_send_json_error( [ 'message' => 'استادیار یافت نشد.' ] );
        }
        return [ $uid, $ta ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // دریافت استادیارهای موجود
    // ─────────────────────────────────────────────────────────────────────────

    public function get_available_tas(): void {
        $this->verify( 'ta_product_nonce' );
        $uid = $this->require_login();

        $product_id     = absint( $_POST['product_id']     ?? 0 );
        $messenger      = sanitize_text_field( $_POST['messenger']      ?? '' );
        $student_gender = sanitize_text_field( $_POST['student_gender'] ?? '' );

        $settings = TA_Eligibility::get_product_settings( $product_id );
        if ( ! $settings ) {
            wp_send_json_error( [ 'message' => 'تنظیمات محصول یافت نشد.' ] );
        }

        // ── بررسی مجوز انتخاب مستقیم (تعیین سطح شده) ──────────────────────
        $is_ta_allowed = (
            $settings->course_type !== 'professional' &&
            TA_Order_Allowed::course_type_eligible( $settings->course_type ) &&
            TA_Order_Allowed::is_allowed_for_student( $uid, $product_id )
        );

        $simple_types = ['critique', 'professional'];
        $is_simple    = in_array( $settings->course_type, $simple_types, true );

        if ( $is_ta_allowed ) {
            // تعیین سطح شده: پیامرسان از POST هنرجو (مثل خلاق)
            $supported = (array) TA_Settings_Helper::get( 'supported_messengers', [ 'telegram', 'bale', 'eitaa' ] );
            if ( ! in_array( $messenger, $supported, true ) ) {
                wp_send_json_error( [ 'message' => 'پیامرسان انتخابی در این سیستم پشتیبانی نمی‌شود.' ] );
            }
        } elseif ( $is_simple ) {
            // نقد اثر و حرفه‌ای: پیامرسان و جنسیت نادیده گرفته می‌شوند
            $messenger      = 'any';
            $student_gender = 'any';
        } elseif ( in_array( $settings->course_type, ['creative', 'basic_writing'], true ) ) {
            // خلاق و پایه: پیامرسان از POST هنرجو
            $supported = (array) TA_Settings_Helper::get( 'supported_messengers', [ 'telegram', 'bale', 'eitaa' ] );
            if ( ! in_array( $messenger, $supported, true ) ) {
                wp_send_json_error( [ 'message' => 'پیامرسان انتخابی در این سیستم پشتیبانی نمی‌شود.' ] );
            }
        } else {
            // سایر دوره‌ها: ابتدا تخصیص محصول قبلیِ هم‌نوع در حالت تکرار،
            // سپس در حالت عادی تخصیص پیش‌نیاز.
            // [BUGFIX-MESSENGER-MISMATCH] قبلاً اینجا یک حلقه‌ی دستیِ جداگانه بود که اولین
            // پیش‌نیازِ دارای پیام‌رسان را بدون هیچ استثنایی انتخاب می‌کرد. این باعث می‌شد
            // برای دوره‌ی «پیشرفته»، اگر پیش‌نیازِ «نقد اثر» (با پیام‌رسان متفاوت) در دیتابیس
            // زودتر از پیش‌نیازِ واقعی (مثلاً مقدماتی) ثبت شده باشد، پیام‌رسانِ اشتباه انتخاب
            // شود؛ در حالی‌که برچسبِ نمایشی (find_prereq_assignment) و تخصیص نهایی
            // (assign_with_group) هر دو این پیش‌نیاز نقد اثر را برای دوره پیشرفته نادیده
            // می‌گرفتند. اکنون همان تابع مرجع استفاده می‌شود تا هر سه بخش هماهنگ بمانند.
            $prereq_assignment = TA_Eligibility::get_repeat_assignment( $uid, $product_id, $settings->course_type );
            if ( ! $prereq_assignment ) {
                $prereq_assignment = TA_Assignment::find_prereq_assignment( $uid, $product_id, $settings->course_type );
            }
            $found_messenger   = $prereq_assignment->messenger ?? '';
            if ( empty( $found_messenger ) && $messenger !== 'auto' ) {
                $found_messenger = $messenger;
            }

            if ( empty( $found_messenger ) ) {
                wp_send_json_error( [ 'message' => 'اطلاعات پیام‌رسان از دوره‌های پیش‌نیاز یافت نشد. لطفاً با پشتیبانی تماس بگیرید.' ] );
            }
            $messenger = $found_messenger;
        }

        $tas  = TA_Assignment::get_available_tas(
            $settings->course_type,
            (int) $settings->term_id,
            $messenger,
            $student_gender,
            $uid
        );

        // اگر show_ta_remaining غیرفعال باشد، ظرفیت باقیمانده را پنهان کن
        $show_remaining = TA_Settings_Helper::is_enabled( 'show_ta_remaining' );

        $list = array_map( fn( $ta ) => [
            'id'        => $ta->id,
            'name'      => $ta->ta_name,
            'remaining' => $show_remaining ? $ta->remaining : null,
        ], $tas );

        wp_send_json_success( [ 'tas' => $list ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تخصیص استادیار — خلاق
    // ─────────────────────────────────────────────────────────────────────────

    public function assign_creative(): void {
        $this->verify( 'ta_product_nonce' );
        $uid = $this->require_login();

        $product_id        = absint( $_POST['product_id']  ?? 0 );
        $ta_id             = absint( $_POST['ta_id']        ?? 0 );
        $messenger         = sanitize_text_field( $_POST['messenger']         ?? '' );
        $student_gender    = sanitize_text_field( $_POST['student_gender']    ?? '' );
        $custom_group_link = esc_url_raw( TA_URL_Helper::normalize( wp_unslash( $_POST['custom_group_link'] ?? '' ) ) );

        if ( ! $product_id || ! $ta_id || ! $messenger || ! $student_gender ) {
            wp_send_json_error( [ 'message' => 'اطلاعات ارسالی ناقص است.' ] );
        }

        // بررسی پیامرسان در برابر لیست فعال
        $supported = (array) TA_Settings_Helper::get( 'supported_messengers', [ 'telegram', 'bale', 'eitaa' ] );
        if ( ! in_array( $messenger, $supported, true ) ) {
            wp_send_json_error( [ 'message' => 'پیامرسان انتخابی در این سیستم پشتیبانی نمی‌شود.' ] );
        }
        if ( ! empty( $custom_group_link ) && ! TA_URL_Helper::is_valid_for_messenger( $custom_group_link, $messenger ) ) {
            wp_send_json_error( [ 'message' => 'لینک گروه با پیام‌رسان انتخاب‌شده مطابقت ندارد.' ] );
        }

        if ( in_array( $student_gender, [ 'male', 'female' ], true ) ) {
            update_user_meta( $uid, 'student_gender', $student_gender );
            update_user_meta( $uid, 'gender', $student_gender );
        }

        $settings = TA_Eligibility::get_product_settings( $product_id );
        if ( ! $settings ) {
            wp_send_json_error( [ 'message' => 'تنظیمات محصول یافت نشد.' ] );
        }

        $result = TA_Assignment::assign_creative(
            $uid, $ta_id, $product_id,
            (int) $settings->term_id,
            $messenger, $student_gender,
            $custom_group_link,
            $settings->course_type
        );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تخصیص استادیار — مقدماتی / پیشرفته (گروه از هنرجو)
    // ─────────────────────────────────────────────────────────────────────────

    public function assign_with_group(): void {
        $this->verify( 'ta_product_nonce' );
        $uid = $this->require_login();

        $product_id     = absint( $_POST['product_id'] ?? 0 );
        $ta_id          = absint( $_POST['ta_id']       ?? 0 );
        $student_gender = sanitize_text_field( $_POST['student_gender'] ?? '' );

        $check = TA_Eligibility::check( $uid, $product_id );

        // ── اگر هنرجو مجوز انتخاب مستقیم دارد، به مسیر ta_allowed هدایت شود ──
        if ( $check['eligible'] && $check['path'] === 'ta_allowed' ) {
            $_POST['group_link'] = sanitize_text_field( $_POST['custom_group_link'] ?? $_POST['group_link'] ?? '' );
            $this->assign_allowed();
            return;
        }

        if ( ! $check['eligible'] || $check['path'] !== 'normal' ) {
            wp_send_json_error( [ 'message' => $check['message'] ?: 'مجوز انتخاب استادیار ندارید.' ] );
        }

        if ( in_array( $student_gender, [ 'male', 'female' ], true ) ) {
            update_user_meta( $uid, 'student_gender', $student_gender );
            update_user_meta( $uid, 'gender', $student_gender );
        }

        $settings      = TA_Eligibility::get_product_settings( $product_id );
        if ( ! $settings ) {
            wp_send_json_error( [ 'message' => 'تنظیمات محصول یافت نشد.' ] );
        }

        $is_simple = in_array( $settings->course_type, [ 'critique', 'professional' ], true );

        // ── نقد اثر و حرفه‌ای: بدون پیامرسان/جنسیت — گروه آزاد از ظرفیت گرفته می‌شود ──
        // ── خلاق و پایه: پیامرسان از هنرجو ──
        if ( in_array( $settings->course_type, [ 'creative', 'basic_writing', 'critique', 'professional' ], true ) ) {

            if ( $is_simple ) {
                // نقد اثر / حرفه‌ای: پیامرسان و جنسیت اهمیت ندارد
                $messenger         = 'any';
                $custom_group_link = '';
            } else {
                $messenger         = sanitize_text_field( $_POST['messenger']         ?? '' );
                $custom_group_link = esc_url_raw( TA_URL_Helper::normalize( wp_unslash( $_POST['custom_group_link'] ?? '' ) ) );

                $supported = (array) TA_Settings_Helper::get( 'supported_messengers', [ 'telegram', 'bale', 'eitaa' ] );
                if ( ! in_array( $messenger, $supported, true ) ) {
                    wp_send_json_error( [ 'message' => 'پیامرسان انتخابی در این سیستم پشتیبانی نمی‌شود.' ] );
                }
                if ( ! empty( $custom_group_link ) && ! TA_URL_Helper::is_valid_for_messenger( $custom_group_link, $messenger ) ) {
                    wp_send_json_error( [ 'message' => 'لینک گروه با پیام‌رسان انتخاب‌شده مطابقت ندارد.' ] );
                }
            }

            $result = TA_Assignment::assign_creative(
                $uid, $ta_id, $product_id,
                (int) $settings->term_id,
                $messenger, $student_gender,
                $custom_group_link,
                $settings->course_type
            );

            if ( $result['success'] ) {
                wp_send_json_success( $result );
            } else {
                wp_send_json_error( $result );
            }
            return;
        }
        // ──────────────────────────────────────────────────────────────────────

        $prerequisites = TA_Product_Meta::get_prerequisites( $product_id );
        $messenger     = sanitize_text_field( $_POST['messenger'] ?? '' );
        if ( $messenger === 'auto' ) {
            $messenger = '';
        }
        $group_link    = esc_url_raw( TA_URL_Helper::normalize( wp_unslash( $_POST['custom_group_link'] ?? '' ) ) );

        $repeat_assignment = TA_Eligibility::get_repeat_assignment( $uid, $product_id, $settings->course_type );
        if ( $repeat_assignment ) {
            $messenger  = (string) ( $repeat_assignment->messenger ?? '' );
            $group_link = (string) ( $repeat_assignment->group_link ?? '' );
        }

        foreach ( $prerequisites as $prereq_id ) {
            if ( ! empty( $messenger ) && ! empty( $group_link ) ) break;
            $assignment = TA_Assignment::get_assignment( $uid, (int) $prereq_id );
            if ( ! $assignment ) continue;

            if ( isset( $settings->course_type ) && $settings->course_type === 'advanced'
                 && isset( $assignment->course_type ) && $assignment->course_type === 'critique' ) {
                continue;
            }

            if ( ! empty( $assignment->messenger ) && empty( $messenger ) ) {
                $messenger = $assignment->messenger;
            }
            if ( ! empty( $assignment->group_link ) && empty( $group_link ) ) {
                $group_link = $assignment->group_link;
            }

            if ( ! empty( $messenger ) && ! empty( $group_link ) ) break;
        }

        if ( empty( $group_link ) ) {
            wp_send_json_error( [ 'message' => 'لینک گروه از دوره پیش‌نیاز یافت نشد. لطفاً با پشتیبانی تماس بگیرید.' ] );
        }
        if ( empty( $messenger ) ) {
            wp_send_json_error( [ 'message' => 'اطلاعات پیام‌رسان از دوره پیش‌نیاز یافت نشد.' ] );
        }

        $result = TA_Assignment::assign_with_student_group(
            $uid, $ta_id, $product_id,
            (int) $settings->term_id, $settings->course_type,
            $messenger, $student_gender, $group_link
        );

        if ( $result['success'] ) {
            $result['group_link'] = $group_link;
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تخصیص استادیار در مسیر «تعیین سطح شده» (ta_allowed)
    // پیام‌رسان و لینک گروه مستقیم از هنرجو دریافت می‌شود — نه از پیش‌نیاز
    // ─────────────────────────────────────────────────────────────────────────

    public function assign_allowed(): void {
        $this->verify( 'ta_product_nonce' );
        $uid = $this->require_login();

        $product_id     = absint( $_POST['product_id']      ?? 0 );
        $ta_id          = absint( $_POST['ta_id']           ?? 0 );
        $messenger      = sanitize_text_field( $_POST['messenger']      ?? '' );
        $group_link     = esc_url_raw( TA_URL_Helper::normalize( wp_unslash( $_POST['group_link'] ?? '' ) ) );
        $student_gender = sanitize_text_field( $_POST['student_gender'] ?? '' );

        // اعتبارسنجی اولیه
        if ( ! $product_id || ! $ta_id ) {
            wp_send_json_error( [ 'message' => 'اطلاعات ارسالی ناقص است.' ] );
        }

        $supported = (array) TA_Settings_Helper::get( 'supported_messengers', [ 'telegram', 'bale', 'eitaa' ] );
        if ( ! in_array( $messenger, $supported, true ) ) {
            wp_send_json_error( [ 'message' => 'پیام‌رسان انتخابی پشتیبانی نمی‌شود.' ] );
        }
        if ( empty( $group_link ) || ! filter_var( $group_link, FILTER_VALIDATE_URL ) ) {
            wp_send_json_error( [ 'message' => 'لینک گروه نامعتبر است.' ] );
        }
        if ( ! TA_URL_Helper::is_valid_for_messenger( $group_link, $messenger ) ) {
            wp_send_json_error( [ 'message' => 'برای پیام‌رسان انتخاب‌شده، لینک باید با نشانی معتبر همان پیام‌رسان شروع شود.' ] );
        }
        if ( ! in_array( $student_gender, [ 'male', 'female' ], true ) ) {
            wp_send_json_error( [ 'message' => 'جنسیت نامعتبر است.' ] );
        }

        // بررسی eligibility — باید مسیر ta_allowed باشد
        $check = TA_Eligibility::check( $uid, $product_id );
        if ( ! $check['eligible'] || $check['path'] !== 'ta_allowed' ) {
            wp_send_json_error( [ 'message' => 'مجوز انتخاب مستقیم استادیار ندارید.' ] );
        }

        // ذخیره جنسیت
        update_user_meta( $uid, 'student_gender', $student_gender );
        update_user_meta( $uid, 'gender',         $student_gender );

        $settings = TA_Eligibility::get_product_settings( $product_id );
        if ( ! $settings ) {
            wp_send_json_error( [ 'message' => 'تنظیمات محصول یافت نشد.' ] );
        }

        // تخصیص — از همان مسیر assign_with_student_group (که group_link از هنرجو می‌گیرد)
        $result = TA_Assignment::assign_with_student_group(
            $uid, $ta_id, $product_id,
            (int) $settings->term_id,
            $settings->course_type,
            $messenger,
            $student_gender,
            $group_link
        );

        if ( $result['success'] ) {
            $result['group_link'] = $group_link;
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [FIX-06] ثبت نمره — بررسی status=active اضافه شد
    // ─────────────────────────────────────────────────────────────────────────

    public function grade_student(): void {
        $this->verify( 'ta_dashboard_nonce' );
        [, $ta] = $this->require_ta();

        // ── بررسی تنظیم ta_grading_enabled ───────────────────────────────────
        if ( ! TA_Settings_Helper::is_enabled( 'ta_grading_enabled' ) ) {
            wp_send_json_error( [ 'message' => 'نمره‌دهی توسط استادیار در حال حاضر غیرفعال است.' ] );
        }
        // ─────────────────────────────────────────────────────────────────────

        $student_id = absint( $_POST['student_id'] ?? 0 );
        $product_id = absint( $_POST['product_id'] ?? 0 );
        $grade      = sanitize_text_field( $_POST['grade'] ?? '' );
        $notes      = sanitize_textarea_field( $_POST['notes'] ?? '' );

        if ( ! array_key_exists( $grade, TA_Grade::all_grades() ) ) {
            wp_send_json_error( [ 'message' => 'نمره نامعتبر.' ] );
        }

        $assign_table = TA_Database::table( 'assignments' );
        $assignment   = TA_Database::get_row(
            "SELECT * FROM {$assign_table} WHERE student_id = %d AND product_id = %d AND ta_id = %d",
            [ $student_id, $product_id, $ta->id ]
        );

        if ( ! $assignment ) {
            wp_send_json_error( [ 'message' => 'این هنرجو متعلق به شما نیست.' ] );
        }

        // تخصیص‌های فعال، در انتظار و تکمیل‌شده همچنان امکان ثبت یا اصلاح نمره دارند.
        if ( ! in_array( $assignment->status, [ 'active', 'pending', 'completed' ], true ) ) {
            wp_send_json_error( [ 'message' => 'این تخصیص فعال نیست و امکان ثبت نمره وجود ندارد.' ] );
        }

        // ── بررسی تنظیم allow_grade_edit ─────────────────────────────────────
        $grade_table   = TA_Database::table( 'grades' );
        $existing_grade = TA_Database::get_row(
            "SELECT * FROM {$grade_table} WHERE student_id = %d AND product_id = %d",
            [ $student_id, $product_id ]
        );

        if ( $existing_grade ) {
            // نمره قبلاً ثبت شده — بررسی مجوز ویرایش
            if ( ! TA_Settings_Helper::is_enabled( 'allow_grade_edit' ) ) {
                wp_send_json_error( [ 'message' => 'ویرایش نمره غیرفعال است. نمره قبلاً ثبت شده و قابل تغییر نیست.' ] );
            }

            // بررسی مهلت ویرایش
            $limit_days = (int) TA_Settings_Helper::get( 'grade_edit_limit_days', 0 );
            if ( $limit_days > 0 && ! empty( $existing_grade->created_at ) ) {
                $created_ts  = strtotime( $existing_grade->created_at );
                $elapsed_days = ( time() - $created_ts ) / DAY_IN_SECONDS;
                if ( $elapsed_days > $limit_days ) {
                    wp_send_json_error( [ 'message' => sprintf(
                        'مهلت ویرایش نمره (%d روز) به پایان رسیده است.',
                        $limit_days
                    ) ] );
                }
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        $settings = TA_Eligibility::get_product_settings( $product_id );

        // [BUGFIX-NULL] قبلاً $settings بدون بررسی null بودن استفاده می‌شد و باعث
        // Warning و ثبت نمره با term_id=0 / course_type نامعتبر می‌شد
        if ( ! $settings ) {
            wp_send_json_error( [ 'message' => 'تنظیمات این محصول یافت نشد.' ] );
        }

        $allowed_grades = TA_Grade::get_grades_for_course( (string) $settings->course_type );
        if ( ! array_key_exists( $grade, $allowed_grades ) || $grade === 'pending' ) {
            wp_send_json_error( [ 'message' => 'این نمره برای نوع دوره انتخاب‌شده مجاز نیست.' ] );
        }

        $ok = TA_Grade::set_grade(
            $student_id, $product_id,
            (int) $settings->term_id, $settings->course_type,
            $grade, $ta->id, $notes
        );

        if ( $ok ) {
            // ارسال اعلان ایمیل به هنرجو پس از ثبت نمره
            TA_Settings_Helper::send_notification( 'student_graded', [
                'student_email' => get_userdata( $student_id )->user_email ?? '',
                'product_name'  => get_the_title( $product_id ),
                'grade_label'   => TA_Grade::grade_label( $grade ),
                'notes'         => $notes,
            ] );

            if ( ! empty( $_POST['mark_complete'] ) && (int) $_POST['mark_complete'] === 1 ) {
                $assignment_id = absint( $_POST['assignment_id'] ?? 0 );
                if ( $assignment_id ) {
                    global $wpdb;
                    $wpdb->update(
                        TA_Database::table( 'assignments' ),
                        [ 'status' => 'completed' ],
                        [ 'id'     => $assignment_id ],
                        [ '%s' ], [ '%d' ]
                    );
                }
            }
            wp_send_json_success( [ 'message' => 'نمره با موفقیت ثبت شد.' ] );
        } else {
            wp_send_json_error( [ 'message' => 'خطا در ثبت نمره.' ] );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [FIX-07] ذخیره ظرفیت — whitelist برای course_type اضافه شد
    // ─────────────────────────────────────────────────────────────────────────

    public function save_capacity(): void {
        $this->verify( 'ta_dashboard_nonce' );
        [, $ta] = $this->require_ta();

        $course_type    = sanitize_text_field( $_POST['course_type']    ?? '' );
        $term_id        = absint( $_POST['term_id']        ?? 0 );
        $student_gender = sanitize_text_field( $_POST['student_gender'] ?? 'both' );
        $total_capacity = absint( $_POST['total_capacity'] ?? 0 );
        $messenger      = sanitize_text_field( $_POST['messenger']      ?? 'both' );

        // [FIX-07] اعتبارسنجی course_type
        if ( ! in_array( $course_type, self::VALID_COURSE_TYPES, true ) ) {
            wp_send_json_error( [ 'message' => 'نوع دوره نامعتبر است.' ] );
        }

        if ( ! in_array( $messenger, [ 'telegram', 'bale', 'eitaa', 'both' ], true ) ) {
            $messenger = 'both';
        }

        if ( ! in_array( $student_gender, [ 'male', 'female', 'both' ], true ) ) {
            $student_gender = 'both';
        }

        $cap_table = TA_Database::table( 'capacities' );
        // بررسی existing با تمام فیلدهای UNIQUE KEY (شامل messenger)
        $existing  = TA_Database::get_row(
            "SELECT id FROM {$cap_table}
             WHERE ta_id = %d AND course_type = %s AND term_id = %d
               AND student_gender = %s AND messenger = %s",
            [ $ta->id, $course_type, $term_id, $student_gender, $messenger ]
        );

        if ( $existing ) {
            $result = TA_Database::update( 'ta_capacities',
                [ 'total_capacity' => $total_capacity ],
                [ 'id' => $existing->id ]
            );
        } else {
            $result = TA_Database::insert( 'ta_capacities', [
                'ta_id'          => $ta->id,
                'course_type'    => $course_type,
                'term_id'        => $term_id,
                'student_gender' => $student_gender,
                'total_capacity' => $total_capacity,
                'used_capacity'  => 0,
                'messenger'      => $messenger,
                'created_at'     => current_time( 'mysql' ),
            ] );
        }

        // [BUGFIX-SILENT-FAIL] بررسی نتیجه عملیات دیتابیس
        if ( $result === false ) {
            wp_send_json_error( [ 'message' => 'خطا در ذخیره‌سازی ظرفیت.' ] );
        }

        wp_send_json_success( [ 'message' => 'ظرفیت ذخیره شد.' ] );
    }

    /** ذخیره اتمیک ظرفیت‌های چندردیفی و مسیر همکاری برای یک ترم */
    public function save_all_capacities(): void {
        $this->verify( 'ta_dashboard_nonce' );
        [, $ta] = $this->require_ta();
        $term_id = absint( $_POST['term_id'] ?? 0 );
        if ( ! current_user_can( 'manage_options' ) && ! (bool) get_option( 'ta_term_allow_capacity_edit_' . $term_id, 1 ) ) wp_send_json_error( [ 'message' => 'ویرایش ظرفیت برای این ترم توسط ادمین غیرفعال شده است.' ] );
        $mode = sanitize_key( $_POST['cooperation_mode'] ?? '' );
        if ( ! $term_id ) wp_send_json_error( [ 'message' => 'ترم نامعتبر است.' ] );
        // jQuery مقدار false را به رشته «false» ارسال می‌کند؛ بنابراین empty() کافی نیست.
        $commitment_raw = strtolower( trim( (string) ( $_POST['commitment'] ?? '' ) ) );
        $commitment = in_array( $commitment_raw, [ '1', 'true', 'yes', 'on' ], true ) ? 1 : 0;
        if ( $mode === 'development' && ! $commitment && (bool) get_option( 'ta_term_require_cooperation_' . $term_id, 1 ) ) wp_send_json_error( [ 'message' => 'پذیرش تعهد توسعه‌محور الزامی است.' ] );
        $rows = json_decode( wp_unslash( $_POST['capacity_rows'] ?? '[]' ), true );
        if ( ! is_array( $rows ) ) wp_send_json_error( [ 'message' => 'ساختار ظرفیت‌ها نامعتبر است.' ] );
        $has_professional = false; $has_critique = false; $has_other = false;
        foreach ( $rows as $auto_row ) { $auto_course = sanitize_key( $auto_row['course_type'] ?? '' ); $auto_value = max( 0, absint( $auto_row['total_capacity'] ?? 0 ) ); if ( $auto_value <= 0 ) continue; if ( $auto_course === 'professional' ) $has_professional = true; elseif ( $auto_course === 'critique' ) $has_critique = true; else $has_other = true; }
        $automatic_mode = ( ! $has_other && ( $has_professional || $has_critique ) ) ? ( $has_professional && $has_critique ? 'professional_critique' : ( $has_professional ? 'professional' : 'critique' ) ) : '';
        $coop_required = (bool) get_option( 'ta_term_require_cooperation_' . $term_id, 1 );
        if ( $automatic_mode ) { $mode = $automatic_mode; } elseif ( ! $coop_required ) { $mode = ''; } elseif ( ! in_array( $mode, [ 'educational', 'development', 'capacity' ], true ) ) wp_send_json_error( [ 'message' => 'برای ترکیب دوره‌های انتخاب‌شده، انتخاب یکی از سه مسیر همکاری الزامی است.' ] );
        $weights = [ 'creative'=>1, 'basic_writing'=>1.5, 'intro'=>2, 'advanced'=>3, 'ladder'=>0.5 ]; $student_count=0; $load_score=0; $intro=0; $advanced=0;
        foreach ( $rows as $row ) { $course=sanitize_key($row['course_type']??''); $value=max(0,absint($row['total_capacity']??0)); if(isset($weights[$course])){$student_count+=$value;$load_score+=$value*$weights[$course];if($course==='intro')$intro+=$value;if($course==='advanced')$advanced+=$value;} }
        $rules=[ 'educational'=>[22,35], 'development'=>[15,20], 'capacity'=>[32,57] ];
        if ( $mode === 'capacity' ) { $capacity_ok = $advanced >= 20 || ( $student_count >= 32 && $load_score >= 57 && ( $intro >= 12 || $advanced >= 12 ) ); if ( ! $capacity_ok ) wp_send_json_error(['message'=>'در همکاری ظرفیت‌محور باید حداقل ۳۲ هنرجوی کل، امتیاز بار آموزشی ۵۷ و حداقل ۱۲ هنرجوی مقدماتی یا ۱۲ هنرجوی پیشرفته داشته باشید؛ استثنا: با ۲۰ هنرجوی پیشرفته، این شروط اصلی نادیده گرفته می‌شود.']); } elseif ( isset( $rules[ $mode ] ) && ( $student_count < $rules[ $mode ][0] || $load_score < $rules[ $mode ][1] ) ) wp_send_json_error(['message'=>'حداقل تعداد هنرجو و مرز بار آموزشی این مسیر هنوز هم‌زمان محقق نشده است.']);
        
        $valid_courses=[ 'creative','intro','basic_writing','advanced','professional','ladder','critique' ]; $valid_gender=['male','female','both']; $valid_messenger=['telegram','bale','eitaa','both']; $table=TA_Database::table('capacities'); $seen=[];
        foreach($rows as $row){$id=absint($row['id']??0);$course=sanitize_key($row['course_type']??'');$gender=sanitize_key($row['student_gender']??'both');$messenger=sanitize_key($row['messenger']??'both');$value=max(0,absint($row['total_capacity']??0));if(!in_array($course,$valid_courses,true)||!in_array($gender,$valid_gender,true)||!in_array($messenger,$valid_messenger,true))continue;$key=$course.'|'.$gender.'|'.$messenger;if(isset($seen[$key]))wp_send_json_error(['message'=>'ترکیب دوره، جنسیت و پیام‌رسان تکراری است.']);$seen[$key]=1;$existing=$id?TA_Database::get_row("SELECT * FROM {$table} WHERE id=%d AND ta_id=%d AND term_id=%d",[$id,$ta->id,$term_id]):TA_Database::get_row("SELECT * FROM {$table} WHERE ta_id=%d AND term_id=%d AND course_type=%s AND student_gender=%s AND messenger=%s",[$ta->id,$term_id,$course,$gender,$messenger]);if($existing&&$value<(int)$existing->used_capacity)wp_send_json_error(['message'=>'ظرفیت کل نمی‌تواند کمتر از ظرفیت استفاده‌شده باشد.']);$data=['course_type'=>$course,'student_gender'=>$gender,'messenger'=>$messenger,'total_capacity'=>$value];if($existing)TA_Database::update('ta_capacities',$data,['id'=>$existing->id]);else TA_Database::insert('ta_capacities',['ta_id'=>$ta->id,'term_id'=>$term_id,'student_gender'=>$gender,'messenger'=>$messenger,'total_capacity'=>$value,'used_capacity'=>0,'course_type'=>$course,'created_at'=>current_time('mysql')]);}
        update_option('ta_cooperation_'.(int)$ta->id.'_'.$term_id,['mode'=>$mode,'commitment'=>$commitment,'status'=>'complete'],false);wp_send_json_success(['message'=>'همه ظرفیت‌ها و مسیر همکاری با موفقیت ذخیره شد.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // افزودن گروه برای ظرفیت خلاق
    // ─────────────────────────────────────────────────────────────────────────

    public function add_group_slot(): void {
        $this->verify( 'ta_dashboard_nonce' );

        // بررسی محدودیت ساخت گروه توسط استادیار
        if ( ! current_user_can( 'manage_options' ) && ! TA_Settings_Helper::is_enabled( 'allow_ta_create_group' ) ) {
            wp_send_json_error( [
                'message' => TA_Settings_Helper::get( 'ta_group_creation_message', 'ساخت گروه جدید در حال حاضر توسط مسئول اجرایی مبنا مدیریت می‌شود.' ),
            ] );
        }

        $capacity_id    = absint( $_POST['capacity_id']    ?? 0 );
        $messenger_type = sanitize_text_field( $_POST['messenger_type'] ?? '' );
        $group_link     = esc_url_raw( TA_URL_Helper::normalize( $_POST['group_link'] ?? '' ) );

        $cap_table = TA_Database::table( 'capacities' );
        $is_admin  = current_user_can( 'manage_options' );

        // انواع دوره‌هایی که مجاز به داشتن گروه هستند
        $group_allowed = self::GROUP_ALLOWED_TYPES;
        $placeholders  = implode( ',', array_fill( 0, count( $group_allowed ), '%s' ) );

        if ( $is_admin ) {
            global $wpdb;
            $cap   = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$cap_table} WHERE id = %d AND course_type IN ({$placeholders})",
                    array_merge( [ $capacity_id ], $group_allowed )
                )
            );
            $ta_id = $cap ? $cap->ta_id : 0;
        } else {
            [, $ta] = $this->require_ta();
            $ta_id  = $ta->id;
            global $wpdb;
            $cap    = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$cap_table} WHERE id = %d AND ta_id = %d AND course_type IN ({$placeholders})",
                    array_merge( [ $capacity_id, $ta_id ], $group_allowed )
                )
            );
        }

        if ( ! $cap ) {
            wp_send_json_error( [ 'message' => 'ظرفیت یافت نشد یا دسترسی ندارید.' ] );
        }

        TA_Database::insert( 'ta_groups', [
            'capacity_id'    => $capacity_id,
            'ta_id'          => $ta_id,
            'messenger_type' => $messenger_type,
            'group_link'     => $group_link,
            'is_used'        => 0,
        ] );

        wp_send_json_success( [ 'message' => 'گروه با موفقیت ثبت شد.' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // لیست سیاه
    // ─────────────────────────────────────────────────────────────────────────

    public function blacklist_student(): void {
        $this->verify( 'ta_dashboard_nonce' );
        [, $ta] = $this->require_ta();

        $student_id = absint( $_POST['student_id'] ?? 0 );
        $reason     = sanitize_textarea_field( $_POST['reason'] ?? '' );

        $bl_table   = TA_Database::table( 'blacklist' );
        $assign_tbl = TA_Database::table( 'assignments' );
        global $wpdb;

        $exists = TA_Database::get_row(
            "SELECT id FROM {$bl_table} WHERE ta_id = %d AND student_id = %d",
            [ $ta->id, $student_id ]
        );
        if ( $exists ) {
            wp_send_json_error( [ 'message' => 'این هنرجو قبلاً در لیست سیاه است.' ] );
        }

        $term_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT term_id FROM {$assign_tbl} WHERE ta_id = %d AND student_id = %d ORDER BY id DESC LIMIT 1",
            $ta->id, $student_id
        ) );

        if ( $term_id ) {
            $total_students    = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT student_id) FROM {$assign_tbl} WHERE ta_id = %d AND term_id = %d",
                $ta->id, $term_id
            ) );
            $blacklisted_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT a.student_id)
                 FROM {$assign_tbl} a
                 INNER JOIN {$bl_table} b ON a.ta_id = b.ta_id AND a.student_id = b.student_id
                 WHERE a.ta_id = %d AND a.term_id = %d",
                $ta->id, $term_id
            ) );
            $max_pct     = TA_Settings_Helper::blacklist_max_pct(); // از تنظیمات می‌خواند
            $max_allowed = (int) ceil( $total_students * $max_pct / 100 );

            if ( $blacklisted_count >= $max_allowed ) {
                wp_send_json_error( [ 'message' => sprintf(
                    'شما به سقف مجاز لیست سیاه در این ترم (%d نفر از کل %d هنرجو) رسیده‌اید.',
                    $max_allowed, $total_students
                ) ] );
            }
        }

        TA_Database::insert( 'ta_blacklist', [
            'ta_id'      => $ta->id,
            'student_id' => $student_id,
            'reason'     => $reason,
            'created_at' => current_time( 'mysql' ),
        ] );

        wp_send_json_success( [ 'message' => 'هنرجو با موفقیت به لیست سیاه اضافه شد.' ] );
    }

    public function remove_from_blacklist(): void {
        $this->verify( 'ta_dashboard_nonce' );
        [, $ta] = $this->require_ta();

        $student_id = absint( $_POST['student_id'] ?? 0 );
        TA_Database::delete( 'ta_blacklist', [ 'ta_id' => $ta->id, 'student_id' => $student_id ] );
        wp_send_json_success( [ 'message' => 'حذف شد.' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تخصیص حرفه‌ای
    // ─────────────────────────────────────────────────────────────────────────

    public function assign_professional(): void {
        try {
            $this->verify( 'ta_product_nonce' );

            if ( ! is_user_logged_in() ) {
                wp_send_json_error( [ 'message' => 'لطفاً ابتدا وارد سایت شوید.' ] );
            }

            $product_id = absint( $_POST['product_id'] ?? 0 );
            $student_id = get_current_user_id();
            $messenger  = sanitize_text_field( $_POST['messenger']    ?? 'telegram' );

            if ( ! $product_id ) {
                wp_send_json_error( [ 'message' => 'اطلاعات ارسالی نامعتبر است.' ] );
            }

            $settings = TA_Product_Meta::get_settings( $product_id );

            if ( ! $settings ) {
                wp_send_json_error( [ 'message' => 'تنظیمات این دوره یافت نشد.' ] );
            }

            if ( $settings->course_type !== 'professional' ) {
                wp_send_json_error( [ 'message' => 'این دوره برای تخصیص حرفه‌ای تنظیم نشده است.' ] );
            }

            $eligibility = TA_Eligibility::check( $student_id, $product_id );
            if ( $eligibility['path'] !== 'assign_professional' ) {
                wp_send_json_error( [ 'message' => 'شما مجوز ثبت‌نام در این دوره را ندارید.' ] );
            }

            global $wpdb;
            $assistant_row = $wpdb->get_row( $wpdb->prepare(
                'SELECT ta.id, ta.user_id
                 FROM ' . TA_Database::table( 'capacities' ) . ' c
                 INNER JOIN ' . TA_Database::table( 'groups' ) . ' g ON g.capacity_id = c.id
                 INNER JOIN ' . TA_Database::table( 'assistants' ) . ' ta ON ta.id = c.ta_id AND ta.is_active = 1
                 WHERE c.term_id = %d AND c.course_type = %s
                   AND c.used_capacity < c.total_capacity AND g.group_link <> %s
                 ORDER BY c.id ASC, g.id ASC LIMIT 1',
                (int) $settings->term_id, 'professional', ''
            ) );

            if ( ! $assistant_row ) {
                wp_send_json_error( [ 'message' => 'برای این دوره استادیار فعال با ظرفیت حرفه‌ای و گروه ساخته‌شده پیدا نشد.' ] );
            }

            $real_ta_id     = (int) $assistant_row->id;
            $student_gender = get_user_meta( $student_id, 'gender', true ) ?: 'unknown';
            $group_link     = '';
            $group_link_row = $wpdb->get_row( $wpdb->prepare(
                'SELECT g.group_link
                 FROM ' . TA_Database::table( 'capacities' ) . ' c
                 INNER JOIN ' . TA_Database::table( 'groups' ) . ' g ON g.capacity_id = c.id
                 WHERE c.ta_id = %d AND c.term_id = %d AND c.course_type = %s
                   AND c.used_capacity < c.total_capacity AND g.group_link <> %s
                 ORDER BY c.id ASC, g.id ASC LIMIT 1',
                $real_ta_id, (int) $settings->term_id, 'professional', ''
            ) );
            if ( $group_link_row ) {
                $group_link = (string) $group_link_row->group_link;
            }
            if ( $group_link === '' ) {
                wp_send_json_error( [ 'message' => 'برای این استادیار ظرفیت حرفه‌ایِ دارای گروه پیدا نشد.' ] );
            }


            $assigned = TA_Assignment::assign_with_student_group(
                $student_id, $real_ta_id, $product_id,
                $settings->term_id, 'professional',
                $messenger, $student_gender, $group_link
            );

            if ( is_array( $assigned ) && ( $assigned['success'] ?? false ) ) {
                wp_send_json_success( [
                    'message'  => 'ثبت‌نام شما با موفقیت انجام شد.',
                    'group_link' => $group_link,
                ] );
            } else {
                wp_send_json_error( [ 'message' => $assigned['message'] ?? 'خطا در تخصیص استادیار.' ] );
            }

        } catch ( Throwable $e ) {
            wp_send_json_error( [ 'message' => 'خطای سیستم. لطفاً دوباره تلاش کنید.' ] );
        }
    }

    // ── تخصیص استادیار نردبان حرفه‌ای ──────────────────────────────────────
    public function assign_ladder(): void {
        try {
            $this->verify( 'ta_product_nonce' );

            if ( ! is_user_logged_in() ) {
                wp_send_json_error( [ 'message' => 'لطفاً ابتدا وارد سایت شوید.' ] );
            }

            $product_id    = absint( $_POST['product_id']    ?? 0 );
            $student_id    = get_current_user_id();
            $use_prev_ta   = (bool) ( $_POST['use_prev_ta'] ?? true );
            $decline_prev  = (bool) ( $_POST['decline_prev'] ?? false );

            // [FIX-LADDER-01] global $wpdb باید خارج از foreach باشد
            // اگر prereq_ids_for_msg خالی باشد، foreach اجرا نمی‌شود و $wpdb در scope function نیست
            global $wpdb;

            // پیام‌رسان از assignment دوره پیش‌نیاز لود می‌شود
            $messenger = 'telegram'; // پیش‌فرض
            $prereq_ids_for_msg = TA_Product_Meta::get_prerequisites( $product_id );
            foreach ( (array) $prereq_ids_for_msg as $prereq_id_msg ) {
                $prev_msg = $wpdb->get_var( $wpdb->prepare(
                    "SELECT messenger FROM " . TA_Database::table('assignments') .
                    " WHERE student_id = %d AND product_id = %d AND ta_id > 0 ORDER BY id DESC LIMIT 1",
                    $student_id, $prereq_id_msg
                ) );
                if ( $prev_msg ) {
                    $messenger = $prev_msg;
                    break;
                }
            }

            if ( ! $product_id ) {
                wp_send_json_error( [ 'message' => 'اطلاعات ارسالی نامعتبر است.' ] );
            }

            $settings = TA_Product_Meta::get_settings( $product_id );
            if ( ! $settings || $settings->course_type !== 'ladder' ) {
                wp_send_json_error( [ 'message' => 'این دوره برای نردبان حرفه‌ای تنظیم نشده است.' ] );
            }

            // بررسی صلاحیت
            $eligibility = TA_Eligibility::check( $student_id, $product_id );

            // اگر هنرجو قصد ادامه با استادیار قبلی ندارد → ثبت با ta_id=36 و اطلاعات پیش‌نیاز
            // این بلاک قبل از eligibility check است تا حتی اگر already_assigned باشد (pending_admin) کار کند
            if ( $decline_prev ) {
                // جلوگیری از تکرار روی assignment های active/completed
                $existing = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, status FROM " . TA_Database::table('assignments') .
                    " WHERE student_id = %d AND product_id = %d ORDER BY id DESC LIMIT 1",
                    $student_id, $product_id
                ) );
                if ( $existing && in_array( $existing->status, [ 'active', 'completed' ], true ) ) {
                    wp_send_json_error( [ 'message' => 'شما قبلاً استادیار فعال دارید.' ] );
                    return;
                }

                // واکشی اطلاعات تخصیص پیش‌نیاز (گروه و ظرفیت)
                // [FIX-DECLINE-COURSE-TYPE] نوع دوره همیشه از دوره جاری (ladder) می‌آید، نه از پیشنیاز
                $prereq_course_type  = $settings->course_type; // = 'ladder'
                $prereq_group_id     = null;
                $prereq_group_link   = null;
                $prereq_capacity_id  = 0;
                foreach ( (array) $prereq_ids_for_msg as $prereq_id_check ) {
                    $pa = $wpdb->get_row( $wpdb->prepare(
                        "SELECT group_id, group_link, capacity_id
                         FROM " . TA_Database::table('assignments') .
                        " WHERE student_id = %d AND product_id = %d AND ta_id > 0
                         ORDER BY id DESC LIMIT 1",
                        $student_id, $prereq_id_check
                    ) );
                    if ( $pa ) {
                        $prereq_group_id    = $pa->group_id    ?: null;
                        $prereq_group_link  = $pa->group_link  ?: null;
                        $prereq_capacity_id = (int) ( $pa->capacity_id ?? 0 );
                        break;
                    }
                }

                $student_gender_d = get_user_meta( $student_id, 'gender', true ) ?: 'unknown';

                // [FIX-DECLINE-01] اگر pending_admin وجود دارد → update، وگرنه → insert
                if ( $existing && in_array( $existing->status, [ 'pending_admin', 'pending_admin_reviewed' ], true ) ) {
                    $assignment_id = (int) $existing->id;
                    TA_Database::update( 'ta_assignments', [
                        'ta_id'          => 0,
                        'status'         => 'pending_admin',
                        'course_type'    => $prereq_course_type,
                        'capacity_id'    => $prereq_capacity_id,
                        'group_id'       => $prereq_group_id,
                        'group_link'     => $prereq_group_link,
                        'messenger'      => $messenger,
                        'student_gender' => $student_gender_d,
                    ], [ 'id' => $assignment_id ] );
                } else {
                    TA_Database::insert( 'ta_assignments', [
                        'student_id'     => $student_id,
                        'product_id'     => $product_id,
                        'ta_id'          => 0,
                        'term_id'        => $settings->term_id,
                        'course_type'    => $prereq_course_type,
                        'capacity_id'    => $prereq_capacity_id,
                        'group_id'       => $prereq_group_id,
                        'group_link'     => $prereq_group_link,
                        'status'         => 'pending_admin',
                        'messenger'      => $messenger,
                        'student_gender' => $student_gender_d,
                        'created_at'     => current_time( 'mysql' ),
                    ] );
                    // [FIX-DECLINE-02] ID ردیف تازه ساخته‌شده را بگیر
                    $assignment_id = (int) $wpdb->insert_id;
                }

                // [FIX-DECLINE-03] ثبت پیام در ta_admin_messages با assignment_id صحیح
                // (قبلاً وقتی insert جدید بود، $existing->id ?? 0 مقدار 0 می‌داد)
                $wpdb->insert(
                    $wpdb->prefix . 'ta_admin_messages',
                    [
                        'student_id'    => $student_id,
                        'product_id'    => $product_id,
                        'assignment_id' => $assignment_id,
                        'message'       => 'درخواست تخصیص استادیار — ادامه بدون استادیار قبلی',
                        'created_at'    => current_time( 'mysql' ),
                    ]
                );

                TA_Settings_Helper::send_notification( 'admin_pending_assignment', [
                    'student_name'  => get_userdata( $student_id )->display_name ?? '',
                    'student_email' => get_userdata( $student_id )->user_email ?? '',
                    'product_name'  => get_the_title( $product_id ),
                    'messenger'     => $messenger,
                    'gender'        => $student_gender_d,
                    'notes'         => 'هنرجو دکمه «ادامه بدون استادیار قبلی» را انتخاب کرده است.',
                ] );
                wp_send_json_success( [
                    'message' => 'درخواست شما ثبت شد. مسئول اجرایی مبنا به زودی استادیار را برای شما تخصیص خواهد داد.',
                    'status'  => 'pending_admin',
                ] );
                return;
            }

            if ( $eligibility['path'] !== 'assign_ladder' ) {
                wp_send_json_error( [ 'message' => $eligibility['message'] ?: 'شما مجوز ثبت‌نام در این دوره را ندارید.' ] );
            }

            // یافتن استادیار پیشرفته قبلی هنرجو
            global $wpdb;
            $assign_table   = TA_Database::table( 'assignments' );
            $prereq_ids     = TA_Product_Meta::get_prerequisites( $product_id );
            $prev_ta_id     = null;
            $prev_ta_name   = '';

            foreach ( (array) $prereq_ids as $prereq_id ) {
                $prev = $wpdb->get_row( $wpdb->prepare(
                    "SELECT a.ta_id, u.display_name AS ta_name
                     FROM {$assign_table} a
                     JOIN {$wpdb->users} u ON u.ID = (SELECT user_id FROM " . TA_Database::table('assistants') . " WHERE id = a.ta_id)
                     WHERE a.student_id = %d AND a.product_id = %d AND a.ta_id > 0
                     ORDER BY a.id DESC LIMIT 1",
                    $student_id, $prereq_id
                ) );
                if ( $prev && $prev->ta_id ) {
                    $prev_ta_id   = (int) $prev->ta_id;
                    $prev_ta_name = $prev->ta_name;
                    break;
                }
            }

            if ( ! $prev_ta_id ) {
                // استادیار قبلی یافت نشد → ادمین تخصیص دهد
                TA_Database::insert( 'ta_assignments', [
                    'student_id'     => $student_id,
                    'product_id'     => $product_id,
                    'ta_id'          => 0,
                    'term_id'        => $settings->term_id,
                    'course_type'    => 'ladder',
                    'status'         => 'pending_admin',
                    'messenger'      => $messenger,
                    'student_gender' => get_user_meta( $student_id, 'gender', true ) ?: 'unknown',
                    'created_at'     => current_time( 'mysql' ),
                ] );
                // [FIX-NOPREV-01] ثبت پیام برای ادمین
                $wpdb->insert( $wpdb->prefix . 'ta_admin_messages', [
                    'student_id'    => $student_id,
                    'product_id'    => $product_id,
                    'assignment_id' => (int) $wpdb->insert_id,
                    'message'       => 'استادیار پیشرفته قبلی هنرجو یافت نشد — نیاز به تخصیص دستی',
                    'created_at'    => current_time( 'mysql' ),
                ] );
                wp_send_json_success( [
                    'message' => 'استادیار قبلی شما یافت نشد. مسئول اجرایی مبنا به زودی استادیار را تخصیص خواهد داد.',
                    'status'  => 'pending_admin',
                ] );
                return;
            }

            // بررسی ظرفیت استادیار قبلی برای دوره نردبان
            $cap_table     = TA_Database::table( 'capacities' );
            $student_gender = get_user_meta( $student_id, 'gender', true ) ?: 'unknown';

            $cap = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, total_capacity, used_capacity
                 FROM {$cap_table}
                 WHERE ta_id = %d AND course_type = 'ladder' AND term_id = %d
                   AND student_gender IN (%s, 'both') AND used_capacity < total_capacity
                 LIMIT 1",
                $prev_ta_id, $settings->term_id, $student_gender
            ) );

            if ( ! $cap ) {
                // استادیار قبلی ظرفیت ندارد → ادمین تخصیص دهد
                TA_Database::insert( 'ta_assignments', [
                    'student_id'     => $student_id,
                    'product_id'     => $product_id,
                    'ta_id'          => 0,
                    'term_id'        => $settings->term_id,
                    'course_type'    => 'ladder',
                    'status'         => 'pending_admin',
                    'messenger'      => $messenger,
                    'student_gender' => $student_gender,
                    'created_at'     => current_time( 'mysql' ),
                ] );
                // [FIX-NOCAP-01] ثبت پیام برای ادمین
                $wpdb->insert( $wpdb->prefix . 'ta_admin_messages', [
                    'student_id'    => $student_id,
                    'product_id'    => $product_id,
                    'assignment_id' => (int) $wpdb->insert_id,
                    'message'       => sprintf( 'استادیار قبلی (%s) ظرفیت نردبان ندارد — نیاز به تخصیص دستی', $prev_ta_name ),
                    'created_at'    => current_time( 'mysql' ),
                ] );
                wp_send_json_success( [
                    'message'       => sprintf(
                        'متأسفانه استادیار قبلی شما (%s) در این ترم ظرفیت نردبان حرفه‌ای ندارد. مسئول اجرایی مبنا به زودی استادیار مناسبی برای شما تخصیص خواهد داد.',
                        $prev_ta_name
                    ),
                    'status'        => 'pending_admin',
                ] );
                return;
            }

            // تخصیص به استادیار قبلی — لینک گروه از تخصیص پیش‌نیاز
            $group_link = '';
            foreach ( (array) $prereq_ids as $prereq_id_gl ) {
                $prev_asgn = $wpdb->get_row( $wpdb->prepare(
                    "SELECT group_link FROM " . TA_Database::table('assignments') .
                    " WHERE student_id = %d AND product_id = %d AND ta_id > 0 ORDER BY id DESC LIMIT 1",
                    $student_id, $prereq_id_gl
                ) );
                if ( $prev_asgn && ! empty( $prev_asgn->group_link ) ) {
                    $group_link = $prev_asgn->group_link;
                    break;
                }
            }

            $assigned   = TA_Assignment::assign_with_student_group(
                $student_id, $prev_ta_id, $product_id,
                $settings->term_id, 'ladder',
                $messenger, $student_gender, $group_link
            );

            if ( is_array( $assigned ) && ( $assigned['success'] ?? false ) ) {
                wp_send_json_success( [
                    'message'  => sprintf( 'تخصیص با موفقیت انجام شد. استادیار شما %s می‌باشد.', $prev_ta_name ),
                    'group_link' => $group_link,
                ] );
            } else {
                wp_send_json_error( [ 'message' => $assigned['message'] ?? 'خطا در تخصیص استادیار.' ] );
            }

        } catch ( Throwable $e ) {
            wp_send_json_error( [ 'message' => 'خطای سیستم. لطفاً دوباره تلاش کنید.' ] );
        }
    }

        // ─────────────────────────────────────────────────────────────────────────
    // [FIX-05] دریافت استادیارهای موجود (ادمین) — پیام‌های DEBUG حذف شدند
    // ─────────────────────────────────────────────────────────────────────────

    public function admin_get_available_tas(): void {
        check_ajax_referer( 'ta_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز.' ] );
        }

        global $wpdb;

        $product_id     = absint( $_POST['product_id']     ?? 0 );
        $messenger      = sanitize_text_field( $_POST['messenger']      ?? '' );
        $student_gender = sanitize_text_field( $_POST['student_gender'] ?? '' );

        if ( ! $product_id ) {
            // [FIX-05] پیام ساده بدون اطلاعات داخلی
            wp_send_json_error( [ 'message' => 'شناسه محصول ارسال نشده است.' ] );
        }

        $settings = $wpdb->get_row( $wpdb->prepare(
            "SELECT term_id, course_type FROM {$wpdb->prefix}ta_product_settings WHERE product_id = %d",
            $product_id
        ) );

        if ( ! $settings ) {
            wp_send_json_error( [ 'message' => 'تنظیمات این محصول یافت نشد. لطفاً از بخش تنظیمات محصول، ترم و نوع دوره را تنظیم کنید.' ] );
        }

        $term_id     = (int) $settings->term_id;
        $course_type = $settings->course_type ?? '';

        if ( ! $term_id ) {
            wp_send_json_error( [ 'message' => 'ترم این محصول تنظیم نشده است.' ] );
        }

        $ta_table    = TA_Database::table( 'assistants' );
        $cap_table   = TA_Database::table( 'capacities' );
        $group_table = TA_Database::table( 'groups' );

        // ساخت پویای شرط‌های WHERE بر اساس پارامترهای موجود
        $where_parts = [
            'cap.ta_id       = ta.id',
            'cap.term_id     = %d',
            'cap.course_type = %s',
        ];
        $params_list = [ $term_id, $course_type ];

        if ( $student_gender ) {
            $where_parts[] = "cap.student_gender IN (%s, 'both')";
            $params_list[] = $student_gender;
        }

        if ( $messenger ) {
            $where_parts[] = "cap.messenger IN (%s, 'both')";
            $params_list[] = $messenger;
        }

        $where_sql = implode( ' AND ', $where_parts );

        // [FIX-CAP-01] خلاق/پایه: اگر لینک سفارشی غیرفعال است، فقط استادیارهایی نشان داده شوند
        // که واقعاً گروه آزاد (is_used=0) دارند — دقیقاً مطابق منطق فرانت‌اند در
        // TA_Assignment::get_available_tas، تا لیست ادمین استادیاری را نشان ندهد که
        // در مرحله نهایی به علت نبود گروه آزاد، تخصیص با خطا متوقف می‌شود.
        $needs_group_check = in_array( $course_type, [ 'creative', 'basic_writing' ], true )
            && ! TA_Settings_Helper::is_enabled( 'allow_custom_group_link' )
            && $messenger;

        $group_exists_sql = '';
        if ( $needs_group_check ) {
            $group_exists_sql = "
               AND EXISTS (
                    SELECT 1 FROM {$group_table} g
                    WHERE g.capacity_id    = cap.id
                      AND g.messenger_type = %s
                      AND g.is_used        = 0
               )";
            $params_list[] = $messenger;
        }

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                ta.id              AS ta_id,
                cap.id             AS capacity_id,
                cap.total_capacity,
                cap.used_capacity,
                cap.messenger,
                cap.student_gender,
                (cap.total_capacity - cap.used_capacity) AS remaining,
                u.display_name     AS ta_name
             FROM {$ta_table} ta
             JOIN {$cap_table} cap ON {$where_sql}
             JOIN {$wpdb->users} u ON u.ID = ta.user_id
             WHERE ta.is_active      = 1
               AND cap.used_capacity < cap.total_capacity
               {$group_exists_sql}
             ORDER BY remaining DESC, ta.rank DESC",
            ...$params_list
        ) );

        if ( empty( $results ) ) {
            $detail = [];
            if ( $course_type )      $detail[] = TA_Product_Meta::course_type_label( $course_type );
            if ( $student_gender )   $detail[] = ( $student_gender === 'male' ? 'آقایان' : 'خانم‌ها' );
            if ( $messenger )        $detail[] = $messenger;
            $detail_str = $detail ? ' (' . implode( ' — ', $detail ) . ')' : '';

            if ( $needs_group_check ) {
                wp_send_json_error( [ 'message' => 'استادیاری با ظرفیت خالی و گروه آزاد برای این شرایط یافت نشد' . $detail_str . '. (ممکن است ظرفیت عددی خالی باشد اما گروه آماده‌ای در پیام‌رسان انتخابی برای آن ساخته نشده باشد.)' ] );
            }

            wp_send_json_error( [ 'message' => 'استادیاری با ظرفیت خالی برای این شرایط یافت نشد' . $detail_str . '.' ] );
        }

        $data = array_map( function ( $row ) {
            return [
                'capacity_id' => (int) $row->capacity_id,
                'ta_id'       => (int) $row->ta_id,
                'name'        => $row->ta_name,
                'remaining'   => (int) $row->remaining,
                'group_link'  => '',
            ];
        }, $results );

        wp_send_json_success( $data );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [FIX-01] تخصیص با لینک سفارشی (ادمین) — امضای تابع کامل شد
    // ─────────────────────────────────────────────────────────────────────────

    public function admin_assign_with_custom_link(): void {
        check_ajax_referer( 'ta_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز.' ] );
        }

        $order_id       = absint( $_POST['order_id']         ?? 0 );
        $ta_user_id     = absint( $_POST['ta_user_id']       ?? 0 );
        $capacity_id    = absint( $_POST['capacity_id']      ?? 0 );
        // [FIX-01] این دو پارامتر قبلاً پاس نمی‌شدند
        $product_id     = absint( $_POST['product_id']       ?? 0 );
        $student_gender = sanitize_text_field( $_POST['student_gender']  ?? '' );
        $messenger_type = sanitize_text_field( $_POST['messenger_type']  ?? '' );
        $custom_link    = esc_url_raw( TA_URL_Helper::normalize( $_POST['custom_group_link'] ?? '' ) );

        if ( ! $order_id || ! $ta_user_id || ! $capacity_id || ! $product_id || ! $messenger_type || empty( $custom_link ) ) {
            wp_send_json_error( [ 'message' => 'پارامترهای ناقص است.' ] );
        }

        // [FIX-01] هفت آرگومان صحیح به تابع پاس می‌شوند
        $result = TA_Assignment::assign_standard_with_capacity(
            $order_id,       // student_id
            $ta_user_id,     // ta_id
            $product_id,     // product_id ← اضافه شد
            $capacity_id,    // capacity_id
            $messenger_type, // messenger
            $student_gender, // student_gender ← اضافه شد
            $custom_link     // custom_group_link
        );

        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تخصیص بر اساس ظرفیت (ادمین)
    // ─────────────────────────────────────────────────────────────────────────

    public function admin_assign_by_capacity(): void {
        $this->verify( 'ta_admin_nonce' );

        // [FIX-CAP-02] بررسی دسترسی ادمین که در نسخه قبلی این تابع وجود نداشت
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز.' ] );
        }

        $student_id     = absint( $_POST['student_id']     ?? 0 );
        $ta_id          = absint( $_POST['ta_id']           ?? 0 );
        $product_id     = absint( $_POST['product_id']      ?? 0 );
        $capacity_id    = absint( $_POST['capacity_id']     ?? 0 );
        $messenger      = sanitize_text_field( $_POST['messenger']      ?? '' );
        $student_gender = sanitize_text_field( $_POST['student_gender'] ?? '' );
        $custom_link    = esc_url_raw( TA_URL_Helper::normalize( $_POST['custom_group_link'] ?? '' ) );

        if ( ! $student_id || ! $ta_id || ! $product_id || ! $capacity_id ) {
            wp_send_json_error( [ 'message' => 'پارامترهای ورودی ناقص است.' ] );
        }

        if ( empty( $custom_link ) ) {
            $cap_table    = TA_Database::table( 'capacities' );
            global $wpdb;
            $capacity_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$cap_table} WHERE id = %d", $capacity_id
            ) );

            if ( ! $capacity_row ) {
                wp_send_json_error( [ 'message' => 'ظرفیت یافت نشد.' ] );
            }

            $course_type = $capacity_row->course_type;

            if ( in_array( $course_type, [ 'intro', 'advanced' ], true ) ) {
                $prerequisites = TA_Product_Meta::get_prerequisites( $product_id );
                $settings      = TA_Eligibility::get_product_settings( $product_id );

                foreach ( $prerequisites as $prereq_id ) {
                    $assignment = TA_Assignment::get_assignment( $student_id, (int) $prereq_id );
                    if ( ! $assignment ) continue;

                    if ( $course_type === 'advanced'
                         && isset( $assignment->course_type )
                         && $assignment->course_type === 'critique' ) {
                        continue;
                    }

                    if ( ! empty( $assignment->group_link ) ) {
                        $custom_link = $assignment->group_link;
                        if ( empty( $messenger ) && ! empty( $assignment->messenger ) ) {
                            $messenger = $assignment->messenger;
                        }
                        break;
                    }
                }

                if ( empty( $custom_link ) ) {
                    wp_send_json_error( [ 'message' => 'لینک گروه از دوره پیش‌نیاز هنرجو یافت نشد.' ] );
                }

            } elseif ( in_array( $course_type, [ 'critique', 'professional' ], true ) ) {
                $messenger = $capacity_row->messenger;
                $group_row = $wpdb->get_row( $wpdb->prepare(
                    'SELECT group_link FROM ' . TA_Database::table( 'groups' ) .
                    ' WHERE capacity_id = %d AND group_link <> %s ORDER BY id ASC LIMIT 1',
                    $capacity_id, ''
                ) );
                if ( $group_row ) {
                    $custom_link = (string) $group_row->group_link;
                } else {
                    wp_send_json_error( [ 'message' => 'برای این ظرفیت گروه ساخته‌شده پیدا نشد.' ] );
                }
            }
        }

        $result = TA_Assignment::assign_standard_with_capacity(
            $student_id, $ta_id, $product_id, $capacity_id,
            $messenger, $student_gender, $custom_link
        );

        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // دریافت گروه‌های یک ظرفیت خلاق (برای Modal مدیریت ظرفیت)
    // ─────────────────────────────────────────────────────────────────────────

    public function get_capacity_groups(): void {
        $this->verify( 'ta_dashboard_nonce' );

        $capacity_id = absint( $_POST['capacity_id'] ?? 0 );
        if ( ! $capacity_id ) {
            wp_send_json_error( [ 'message' => 'شناسه نامعتبر.' ] );
        }

        // [SECURITY-FIX-IDOR] بررسی مالکیت/دسترسی — قبلاً هر کاربر لاگین‌شده‌ای
        // با داشتن nonce می‌توانست گروه‌های هر ظرفیتی (حتی متعلق به استادیار دیگر) را ببیند
        $cap_table = TA_Database::table( 'capacities' );
        $is_admin  = current_user_can( 'manage_options' );

        if ( $is_admin ) {
            $cap = TA_Database::get_row(
                "SELECT id FROM {$cap_table} WHERE id = %d",
                [ $capacity_id ]
            );
        } else {
            [, $ta] = $this->require_ta();
            $cap = TA_Database::get_row(
                "SELECT id FROM {$cap_table} WHERE id = %d AND ta_id = %d",
                [ $capacity_id, $ta->id ]
            );
        }

        if ( ! $cap ) {
            wp_send_json_error( [ 'message' => 'ظرفیت یافت نشد یا دسترسی ندارید.' ] );
        }

        $gr_table = TA_Database::table( 'groups' );
        $rows     = TA_Database::get_results(
            "SELECT id, messenger_type, group_link, is_used, assigned_to_user_id
             FROM {$gr_table} WHERE capacity_id = %d ORDER BY id ASC",
            [ $capacity_id ]
        );

        wp_send_json_success( $rows );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // دریافت هنرجویان یک ظرفیت (برای Modal مدیریت ظرفیت)
    // ─────────────────────────────────────────────────────────────────────────

    public function get_capacity_assignments(): void {
        check_ajax_referer( 'ta_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز.' ] );
        }

        $capacity_id = absint( $_POST['capacity_id'] ?? 0 );
        if ( ! $capacity_id ) {
            wp_send_json_error( [ 'message' => 'شناسه نامعتبر.' ] );
        }

        global $wpdb;
        $assign_tbl = TA_Database::table( 'assignments' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, u.display_name AS student_name, a.messenger, a.group_link, a.status
             FROM {$assign_tbl} a
             JOIN {$wpdb->users} u ON u.ID = a.student_id
             WHERE a.capacity_id = %d AND a.status = 'active'
             ORDER BY a.id ASC",
            $capacity_id
        ) );

        wp_send_json_success( $rows );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // آمار تفصیلی بر اساس ترم — برای تب خلاصه استادیار
    // ─────────────────────────────────────────────────────────────────────────
    public function get_term_stats(): void {
        $this->verify( 'ta_dashboard_nonce' );
        [ , $ta ] = $this->require_ta();

        global $wpdb;
        $cap_table    = TA_Database::table( 'capacities' );
        $assign_table = TA_Database::table( 'assignments' );
        $grade_table  = TA_Database::table( 'grades' );
        $term_table   = TA_Database::table( 'terms' );

        // ─── آمار کلی همه ترم‌ها ───
        // باگ قبلی: JOIN مستقیم capacities با assignments روی ta_id=ta_id
        // باعث ضرب‌شدن رکوردها می‌شد (هر assignment × تعداد capacity رکوردها).
        // راه‌حل: دو کوئری مجزا و ترکیب در PHP.
        $global_cap = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(total_capacity) AS total_cap,
                SUM(used_capacity)  AS used_cap
             FROM {$cap_table}
             WHERE ta_id = %d",
            $ta->id
        ) );
        $global_asgn = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*)                                              AS total_students,
                SUM(CASE WHEN status='active'    THEN 1 ELSE 0 END)  AS active_count,
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END)  AS completed_count,
                SUM(CASE WHEN status='canceled'  THEN 1 ELSE 0 END)  AS canceled_count
             FROM {$assign_table}
             WHERE ta_id = %d",
            $ta->id
        ) );
        $global = (object) [
            'total_cap'       => $global_cap->total_cap        ?? 0,
            'used_cap'        => $global_cap->used_cap         ?? 0,
            'total_students'  => $global_asgn->total_students  ?? 0,
            'active_count'    => $global_asgn->active_count    ?? 0,
            'completed_count' => $global_asgn->completed_count ?? 0,
            'canceled_count'  => $global_asgn->canceled_count  ?? 0,
        ];

        // ─── آمار نمرات کلی ───
        $grades_global = $wpdb->get_results( $wpdb->prepare(
            "SELECT g.grade, COUNT(*) AS cnt
             FROM {$assign_table} a
             JOIN {$grade_table} g ON g.student_id = a.student_id AND g.product_id = a.product_id
             WHERE a.ta_id = %d
             GROUP BY g.grade",
            $ta->id
        ) );

        // ─── آمار بر اساس ترم ───
        // مرجع ترم‌ها = UNION ترم‌هایی که TA در آن‌ها assignment یا capacity دارد.
        // سپس هر بخش (capacity / assignment) جداگانه GROUP BY می‌شود و در PHP ترکیب می‌شود
        // تا از ضرب‌شدن رکوردها جلوگیری شود.

        // الف) لیست همه ترم‌هایی که این TA در آن‌ها حضور دارد
        $active_term_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT term_id FROM {$assign_table} WHERE ta_id = %d
             UNION
             SELECT DISTINCT term_id FROM {$cap_table}   WHERE ta_id = %d",
            $ta->id, $ta->id
        ) );

        $terms_raw = [];
        if ( ! empty( $active_term_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $active_term_ids ), '%d' ) );

            // ب) اطلاعات پایه ترم‌ها
            $terms_info = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name, order_index
                 FROM {$term_table}
                 WHERE id IN ($placeholders)
                 ORDER BY order_index DESC",
                ...$active_term_ids
            ) );

            // ج) ظرفیت هر ترم
            $cap_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT term_id,
                        SUM(total_capacity) AS total_cap,
                        SUM(used_capacity)  AS used_cap
                 FROM {$cap_table}
                 WHERE ta_id = %d AND term_id IN ($placeholders)
                 GROUP BY term_id",
                $ta->id, ...$active_term_ids
            ) );
            $cap_by_term = [];
            foreach ( $cap_rows as $r ) {
                $cap_by_term[ (int) $r->term_id ] = $r;
            }

            // د) آمار هنرجویان هر ترم
            $asgn_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT term_id,
                        COUNT(*)                                              AS total_students,
                        SUM(CASE WHEN status='active'    THEN 1 ELSE 0 END)  AS active_count,
                        SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END)  AS completed_count,
                        SUM(CASE WHEN status='canceled'  THEN 1 ELSE 0 END)  AS canceled_count
                 FROM {$assign_table}
                 WHERE ta_id = %d AND term_id IN ($placeholders)
                 GROUP BY term_id",
                $ta->id, ...$active_term_ids
            ) );
            $asgn_by_term = [];
            foreach ( $asgn_rows as $r ) {
                $asgn_by_term[ (int) $r->term_id ] = $r;
            }

            // ه) ترکیب
            foreach ( $terms_info as $t ) {
                $tid = (int) $t->id;
                $cr  = $cap_by_term[ $tid ]  ?? null;
                $ar  = $asgn_by_term[ $tid ] ?? null;
                $t->total_cap       = $cr ? (int) $cr->total_cap      : 0;
                $t->used_cap        = $cr ? (int) $cr->used_cap       : 0;
                $t->total_students  = $ar ? (int) $ar->total_students  : 0;
                $t->active_count    = $ar ? (int) $ar->active_count    : 0;
                $t->completed_count = $ar ? (int) $ar->completed_count : 0;
                $t->canceled_count  = $ar ? (int) $ar->canceled_count  : 0;
                $terms_raw[] = $t;
            }
        }

        // ─── آمار نوع دوره هر ترم ───
        $course_stats = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                a.term_id,
                a.course_type,
                COUNT(*) AS cnt,
                SUM(CASE WHEN a.status='completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN a.status='canceled'  THEN 1 ELSE 0 END) AS canceled
             FROM {$assign_table} a
             WHERE a.ta_id = %d
             GROUP BY a.term_id, a.course_type",
            $ta->id
        ) );

        // ─── آمار نمرات هر ترم ───
        $grade_stats = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.term_id, g.grade, COUNT(*) AS cnt
             FROM {$assign_table} a
             JOIN {$grade_table} g ON g.student_id = a.student_id AND g.product_id = a.product_id
             WHERE a.ta_id = %d
             GROUP BY a.term_id, g.grade",
            $ta->id
        ) );

        // ─── ساختاردهی ───
        $course_map = [];
        foreach ( $course_stats as $row ) {
            $course_map[ $row->term_id ][ $row->course_type ] = $row;
        }
        $grade_map = [];
        foreach ( $grade_stats as $row ) {
            $grade_map[ $row->term_id ][ $row->grade ] = (int) $row->cnt;
        }
        $grades_global_map = [];
        foreach ( $grades_global as $row ) {
            $grades_global_map[ $row->grade ] = (int) $row->cnt;
        }

        $terms = [];
        foreach ( $terms_raw as $t ) {
            $terms[] = [
                'id'              => (int) $t->id,
                'name'            => $t->name,
                'total_cap'       => (int) $t->total_cap,
                'used_cap'        => (int) $t->used_cap,
                'remaining_cap'   => max( 0, (int)$t->total_cap - (int)$t->used_cap ),
                'total_students'  => (int) $t->total_students,
                'active_count'    => (int) $t->active_count,
                'completed_count' => (int) $t->completed_count,
                'canceled_count'  => (int) $t->canceled_count,
                'fill_pct'        => $t->total_cap > 0 ? round( ($t->used_cap / $t->total_cap) * 100 ) : 0,
                'courses'         => $course_map[ $t->id ] ?? [],
                'grades'          => $grade_map[ $t->id ] ?? [],
            ];
        }

        wp_send_json_success( [
            'global' => [
                'total_cap'       => (int)( $global->total_cap ?? 0 ),
                'used_cap'        => (int)( $global->used_cap ?? 0 ),
                'remaining_cap'   => max( 0, (int)($global->total_cap ?? 0) - (int)($global->used_cap ?? 0) ),
                'total_students'  => (int)( $global->total_students ?? 0 ),
                'active_count'    => (int)( $global->active_count ?? 0 ),
                'completed_count' => (int)( $global->completed_count ?? 0 ),
                'canceled_count'  => (int)( $global->canceled_count ?? 0 ),
                'fill_pct'        => ($global->total_cap ?? 0) > 0
                                     ? round( ($global->used_cap / $global->total_cap) * 100 ) : 0,
                'grades'          => $grades_global_map,
            ],
            'terms' => $terms,
        ] );
    }

    // ─── نقد داستان ──────────────────────────────────────────────────────────

    public function story_critique_submit(): void {
        $this->verify( 'ta_dashboard_nonce' );
        $uid = get_current_user_id();
        if ( ! TA_Roles::is_ta( $uid ) ) {
            wp_send_json_error( 'دسترسی غیرمجاز' );
        }

        $ta_table = TA_Database::table( 'assistants' );
        $ta       = TA_Database::get_row( "SELECT id FROM {$ta_table} WHERE user_id = %d", [ $uid ] );
        if ( ! $ta ) {
            wp_send_json_error( 'استادیار یافت نشد' );
        }

        $full_name       = sanitize_text_field( $_POST['full_name']       ?? '' );
        $phone           = sanitize_text_field( $_POST['phone']           ?? '' );
        $specialties_raw = $_POST['specialties'] ?? [];
        $other           = sanitize_textarea_field( $_POST['other_specialty'] ?? '' );
        $hours           = sanitize_text_field( $_POST['hours_per_day']   ?? '' );
        $resume          = sanitize_textarea_field( $_POST['resume']      ?? '' );

        if ( ! $full_name || ! $phone || ! $hours ) {
            wp_send_json_error( 'لطفاً تمام فیلدهای ضروری را پر کنید.' );
        }

        $specialties = is_array( $specialties_raw )
            ? array_map( 'sanitize_text_field', $specialties_raw )
            : [];

        $sc_table = TA_Database::table( 'story_critique_forms' );
        global $wpdb;

        // بررسی ثبت قبلی
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$sc_table} WHERE ta_id = %d", $ta->id
        ) );

        $data = [
            'full_name'       => $full_name,
            'phone'           => $phone,
            'specialties'     => wp_json_encode( $specialties, JSON_UNESCAPED_UNICODE ),
            'other_specialty' => $other,
            'hours_per_day'   => $hours,
            'resume'          => $resume,
        ];

        if ( $existing ) {
            $wpdb->update( $sc_table, $data, [ 'ta_id' => $ta->id ] );
        } else {
            $data['ta_id']   = $ta->id;
            $data['user_id'] = $uid;
            $wpdb->insert( $sc_table, $data );
        }

        wp_send_json_success( [ 'saved' => true ] );
    }

    public function story_critique_get(): void {
        $this->verify( 'ta_dashboard_nonce' );
        $uid = get_current_user_id();
        if ( ! TA_Roles::is_ta( $uid ) ) {
            wp_send_json_error( 'دسترسی غیرمجاز' );
        }

        $ta_table = TA_Database::table( 'assistants' );
        $ta       = TA_Database::get_row( "SELECT id FROM {$ta_table} WHERE user_id = %d", [ $uid ] );
        if ( ! $ta ) {
            wp_send_json_success( null );
        }

        $sc_table = TA_Database::table( 'story_critique_forms' );
        $row      = TA_Database::get_row( "SELECT * FROM {$sc_table} WHERE ta_id = %d", [ $ta->id ] );

        if ( $row ) {
            $row->specialties = json_decode( $row->specialties, true ) ?: [];
            $row->is_saved    = true;
        } else {
            // فرم هنوز پر نشده — نام و تلفن را از حساب کاربری پیش‌پر می‌کنیم
            $user         = get_userdata( $uid );
            $display_name = $user ? $user->display_name : '';
            // ترجیح: first_name + last_name، در صورت خالی بودن: display_name
            $first = $user ? get_user_meta( $uid, 'first_name', true ) : '';
            $last  = $user ? get_user_meta( $uid, 'last_name',  true ) : '';
            if ( $first || $last ) {
                $display_name = trim( $first . ' ' . $last );
            }
            // شماره تلفن از billing WooCommerce
            $phone = get_user_meta( $uid, 'billing_phone', true ) ?: '';
            $row  = (object) [
                'full_name'       => $display_name,
                'phone'           => $phone,
                'specialties'     => [],
                'other_specialty' => '',
                'hours_per_day'   => '',
                'resume'          => '',
                'capacity'        => null,
                'base_price'      => null,
                'is_saved'        => false,
            ];
        }
        wp_send_json_success( $row );
    }

    // ─── نقد آثار — ظرفیت/قیمت‌پایه با تاریخچه ─────────────────────────────────

    /** یافتن ردیف فرم نقد آثار متعلق به استادیار جاری، یا خطا */
    private function sc_get_own_form(): object {
        $uid = get_current_user_id();
        if ( ! TA_Roles::is_ta( $uid ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );
        }
        $ta_table = TA_Database::table( 'assistants' );
        $ta       = TA_Database::get_row( "SELECT id FROM {$ta_table} WHERE user_id = %d", [ $uid ] );
        if ( ! $ta ) {
            wp_send_json_error( [ 'message' => 'استادیار یافت نشد' ] );
        }
        $sc_table = TA_Database::table( 'story_critique_forms' );
        $form     = TA_Database::get_row( "SELECT * FROM {$sc_table} WHERE ta_id = %d", [ $ta->id ] );
        if ( ! $form ) {
            wp_send_json_error( [ 'message' => 'ابتدا باید فرم نقد آثار را ثبت کنید.' ] );
        }
        return $form;
    }

    private function sc_valid_field( string $field ): bool {
        return in_array( $field, [ 'capacity', 'base_price', 'base_word_count', 'increment_words', 'increment_percent' ], true );
    }

    private function sc_cast_field_value( string $field, string $raw ) {
        if ( $raw === '' ) return null;
        if ( in_array( $field, [ 'capacity', 'base_word_count', 'increment_words' ], true ) ) {
            return absint( $raw );
        }
        return round( (float) $raw, 2 ); // base_price, increment_percent
    }

    private function sc_log_field_change( int $form_id, int $ta_id, string $field, $old_value, $new_value, string $actor_type, int $actor_id ): void {
        TA_Database::insert( 'ta_story_critique_field_logs', [
            'form_id'    => $form_id,
            'ta_id'      => $ta_id,
            'field'      => $field,
            'old_value'  => $old_value === null ? null : (string) $old_value,
            'new_value'  => $new_value === null ? null : (string) $new_value,
            'actor_type' => $actor_type,
            'actor_id'   => $actor_id,
            'created_at' => current_time( 'mysql' ),
        ] );
    }

    /** استادیار: ویرایش ظرفیت/قیمت‌پایه خودش */
    public function sc_ta_field_update(): void {
        $this->verify( 'ta_dashboard_nonce' );
        $form  = $this->sc_get_own_form();
        $field = sanitize_key( $_POST['field'] ?? '' );
        if ( ! $this->sc_valid_field( $field ) ) {
            wp_send_json_error( [ 'message' => 'فیلد نامعتبر' ] );
        }

        // [FIX-PRICING-LIMITS] دسترسی تغییر و سقفِ هر ستون را ادمین از تب قیمت‌گذاری
        // تعیین می‌کند (سراسری، برای همهٔ استادیارها)
        if ( class_exists( 'TA_Story_Critique_Manager' ) ) {
            if ( ! TA_Story_Critique_Manager::pricing_field_editable( $field ) ) {
                wp_send_json_error( [ 'message' => 'ویرایش این فیلد توسط استادیار غیرفعال است. با ادمین هماهنگ کنید.' ] );
            }
        }

        $raw = wp_unslash( $_POST['value'] ?? '' );
        $new = $this->sc_cast_field_value( $field, $raw );

        if ( class_exists( 'TA_Story_Critique_Manager' ) ) {
            $cap = TA_Story_Critique_Manager::pricing_field_cap( $field );
            if ( $cap !== null && $new !== '' && $new !== null && (float) $new > $cap ) {
                wp_send_json_error( [ 'message' => 'حداکثر مقدار مجاز برای این فیلد ' . $cap . ' است.' ] );
            }
        }

        $old = $form->$field;

        $sc_table = TA_Database::table( 'story_critique_forms' );
        global $wpdb;
        $wpdb->update( $sc_table, [ $field => $new ], [ 'id' => $form->id ] );

        $this->sc_log_field_change( (int) $form->id, (int) $form->ta_id, $field, $old, $new, 'ta', get_current_user_id() );

        wp_send_json_success( [ 'value' => $new ] );
    }

    /** استادیار: مشاهده تاریخچه ظرفیت/قیمت‌پایه خودش */
    public function sc_ta_field_history(): void {
        $this->verify( 'ta_dashboard_nonce' );
        $form  = $this->sc_get_own_form();
        $field = sanitize_key( $_POST['field'] ?? '' );
        if ( ! $this->sc_valid_field( $field ) ) {
            wp_send_json_error( [ 'message' => 'فیلد نامعتبر' ] );
        }
        $log_table = TA_Database::table( 'story_critique_field_logs' );
        $logs = TA_Database::get_results(
            "SELECT old_value, new_value, actor_type, created_at FROM {$log_table}
             WHERE form_id = %d AND field = %s ORDER BY created_at DESC, id DESC LIMIT 100",
            [ $form->id, $field ]
        );
        wp_send_json_success( $logs );
    }

    /** ادمین: ویرایش ظرفیت/قیمت‌پایه هر رکورد از صفحه گزارش */
    public function sc_admin_field_update(): void {
        $this->verify( 'ta_admin_nonce' );
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );
        }
        $form_id = absint( $_POST['form_id'] ?? 0 );
        $field   = sanitize_key( $_POST['field'] ?? '' );
        if ( ! $form_id || ! $this->sc_valid_field( $field ) ) {
            wp_send_json_error( [ 'message' => 'داده نامعتبر' ] );
        }
        $sc_table = TA_Database::table( 'story_critique_forms' );
        $form     = TA_Database::get_row( "SELECT * FROM {$sc_table} WHERE id = %d", [ $form_id ] );
        if ( ! $form ) {
            wp_send_json_error( [ 'message' => 'رکورد یافت نشد' ] );
        }
        $raw = wp_unslash( $_POST['value'] ?? '' );
        $new = $this->sc_cast_field_value( $field, $raw );
        $old = $form->$field;

        global $wpdb;
        $wpdb->update( $sc_table, [ $field => $new ], [ 'id' => $form_id ] );

        $this->sc_log_field_change( $form_id, (int) $form->ta_id, $field, $old, $new, 'admin', get_current_user_id() );

        wp_send_json_success( [ 'value' => $new ] );
    }

    /** ادمین: مشاهده تاریخچه ظرفیت/قیمت‌پایه هر رکورد */
    public function sc_admin_field_history(): void {
        $this->verify( 'ta_admin_nonce' );
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );
        }
        $form_id = absint( $_POST['form_id'] ?? 0 );
        $field   = sanitize_key( $_POST['field'] ?? '' );
        if ( ! $form_id || ! $this->sc_valid_field( $field ) ) {
            wp_send_json_error( [ 'message' => 'داده نامعتبر' ] );
        }
        $log_table = TA_Database::table( 'story_critique_field_logs' );
        $logs = TA_Database::get_results(
            "SELECT old_value, new_value, actor_type, actor_id, created_at FROM {$log_table}
             WHERE form_id = %d AND field = %s ORDER BY created_at DESC, id DESC LIMIT 100",
            [ $form_id, $field ]
        );
        foreach ( $logs as $l ) {
            $l->actor_name = $l->actor_id ? TA_User_Helper::display( (int) $l->actor_id ) : '—';
        }
        wp_send_json_success( $logs );
    }

    /** ادمین: آپلود/تغییر عکس پروفایل یک استادیار از صفحه گزارش نقد آثار */
    public function sc_admin_avatar_upload(): void {
        $this->verify( 'ta_admin_nonce' );
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );
        }
        $user_id = absint( $_POST['user_id'] ?? 0 );
        if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
            wp_send_json_error( [ 'message' => 'کاربر نامعتبر' ] );
        }
        if ( empty( $_FILES['file'] ) || ! empty( $_FILES['file']['error'] ) ) {
            wp_send_json_error( [ 'message' => 'فایلی ارسال نشد' ] );
        }

        $allowed = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ];
        $type    = $_FILES['file']['type'] ?? '';
        if ( ! in_array( $type, $allowed, true ) ) {
            wp_send_json_error( [ 'message' => 'فقط فایل تصویری مجاز است.' ] );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ] );
        }

        update_user_meta( $user_id, 'image', $attachment_id );

        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'url'           => wp_get_attachment_url( $attachment_id ),
        ] );
    }

    /**
     * قیمت‌گذاری مستقلِ هر تخصص (per-form) — ابزار مشترک بین مسیر ادمین و
     * مسیر خودِ استادیار. هر فیلد اختیاری است؛ اگر رکوردی برای یک تخصص
     * نباشد یا فیلدی خالی باشد، calc_price() به قیمت پایهٔ خودِ استادیار
     * fallback می‌کند (پیاده‌سازی در class-critique-order.php).
     */
    private function sc_specialty_pricing_upsert( int $form_id, array $items ): void {
        $tbl = TA_Database::table( 'specialty_pricing' );
        global $wpdb;
        foreach ( $items as $item ) {
            $specialty = sanitize_text_field( wp_unslash( $item['specialty'] ?? '' ) );
            if ( ! $specialty ) continue;

            $to_num = function ( $v ) {
                $v = trim( (string) ( $v ?? '' ) );
                return $v === '' ? null : (float) $v;
            };
            $base_price  = $to_num( $item['base_price'] ?? '' );
            $base_words  = $to_num( $item['base_word_count'] ?? '' );
            $inc_words   = $to_num( $item['increment_words'] ?? '' );
            $inc_percent = $to_num( $item['increment_percent'] ?? '' );

            // اگر همه‌ی فیلدها خالی باشند، یعنی TA/ادمین دارد قیمت اختصاصی این
            // تخصص را پاک می‌کند — رکورد را حذف می‌کنیم تا fallback به قیمت پایه اعمال شود
            if ( $base_price === null && $base_words === null && $inc_words === null && $inc_percent === null ) {
                $wpdb->delete( $tbl, [ 'form_id' => $form_id, 'specialty' => $specialty ] );
                continue;
            }

            $existing = TA_Database::get_row( "SELECT id FROM {$tbl} WHERE form_id = %d AND specialty = %s", [ $form_id, $specialty ] );
            $data = [
                'base_price'        => $base_price,
                'base_word_count'   => $base_words,
                'increment_words'   => $inc_words,
                'increment_percent' => $inc_percent,
            ];
            if ( $existing ) {
                $wpdb->update( $tbl, $data, [ 'id' => $existing->id ] );
            } else {
                $data['form_id']    = $form_id;
                $data['specialty']  = $specialty;
                $data['created_at'] = current_time( 'mysql' );
                $wpdb->insert( $tbl, $data );
            }
        }
    }

    /** ادمین: دریافت قیمت‌گذاری تخصص‌های یک استادیار خاص */
    public function sc_admin_specialty_pricing_get(): void {
        $this->verify( 'ta_admin_nonce' );
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );
        }
        $form_id = absint( $_POST['form_id'] ?? 0 );
        if ( ! $form_id ) wp_send_json_error( [ 'message' => 'داده نامعتبر' ] );

        $tbl  = TA_Database::table( 'specialty_pricing' );
        $rows = TA_Database::get_results( "SELECT * FROM {$tbl} WHERE form_id = %d", [ $form_id ] );
        $out  = [];
        foreach ( $rows as $r ) {
            $out[ $r->specialty ] = [
                'base_price'        => $r->base_price,
                'base_word_count'   => $r->base_word_count,
                'increment_words'   => $r->increment_words,
                'increment_percent' => $r->increment_percent,
            ];
        }
        wp_send_json_success( $out );
    }

    /** ادمین: ذخیرهٔ قیمت‌گذاری تخصص‌های یک استادیار */
    public function sc_admin_specialty_pricing_save(): void {
        $this->verify( 'ta_admin_nonce' );
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );
        }
        $form_id = absint( $_POST['form_id'] ?? 0 );
        $items   = json_decode( wp_unslash( $_POST['items'] ?? '[]' ), true );
        if ( ! $form_id || ! is_array( $items ) ) {
            wp_send_json_error( [ 'message' => 'داده نامعتبر' ] );
        }
        $this->sc_specialty_pricing_upsert( $form_id, $items );
        wp_send_json_success();
    }

    /** استادیار: دریافت قیمت‌گذاری تخصص‌های خودش */
    public function sc_ta_specialty_pricing_get(): void {
        $this->verify( 'ta_dashboard_nonce' );
        $form = $this->sc_get_own_form();

        $tbl  = TA_Database::table( 'specialty_pricing' );
        $rows = TA_Database::get_results( "SELECT * FROM {$tbl} WHERE form_id = %d", [ $form->id ] );
        $out  = [];
        foreach ( $rows as $r ) {
            $out[ $r->specialty ] = [
                'base_price'        => $r->base_price,
                'base_word_count'   => $r->base_word_count,
                'increment_words'   => $r->increment_words,
                'increment_percent' => $r->increment_percent,
            ];
        }
        wp_send_json_success( $out );
    }

    /** استادیار: ذخیرهٔ قیمت‌گذاری تخصص‌های خودش (با رعایت سقف/دسترسی سراسری) */
    public function sc_ta_specialty_pricing_save(): void {
        $this->verify( 'ta_dashboard_nonce' );
        $form  = $this->sc_get_own_form();
        $items = json_decode( wp_unslash( $_POST['items'] ?? '[]' ), true );
        if ( ! is_array( $items ) ) {
            wp_send_json_error( [ 'message' => 'داده نامعتبر' ] );
        }

        if ( class_exists( 'TA_Story_Critique_Manager' ) ) {
            if ( ! TA_Story_Critique_Manager::pricing_field_editable( 'base_price' ) ) {
                wp_send_json_error( [ 'message' => 'ویرایش قیمت‌گذاری تخصص‌ها توسط استادیار غیرفعال است. با ادمین هماهنگ کنید.' ] );
            }
            $cap_map = [
                'base_price'        => TA_Story_Critique_Manager::pricing_field_cap( 'base_price' ),
                'base_word_count'   => TA_Story_Critique_Manager::pricing_field_cap( 'base_word_count' ),
                'increment_words'   => TA_Story_Critique_Manager::pricing_field_cap( 'increment_words' ),
                'increment_percent' => TA_Story_Critique_Manager::pricing_field_cap( 'increment_percent' ),
            ];
            foreach ( $items as $item ) {
                foreach ( $cap_map as $f => $cap ) {
                    $v = trim( (string) ( $item[ $f ] ?? '' ) );
                    if ( $cap !== null && $v !== '' && (float) $v > $cap ) {
                        wp_send_json_error( [ 'message' => 'حداکثر مقدار مجاز برای «' . $f . '» عدد ' . $cap . ' است.' ] );
                    }
                }
            }
        }

        $this->sc_specialty_pricing_upsert( (int) $form->id, $items );
        wp_send_json_success();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ادمین: تغییر وضعیت / حذف سفارش نقد اثر (صفحهٔ «سفارش‌های نقد آثار»)
    // ─────────────────────────────────────────────────────────────────────────

    /** لیست وضعیت‌های معتبر برای سفارش نقد اثر (برای اعتبارسنجی ورودی ادمین) */
    const CO_STATUSES = [ 'pending', 'in_progress', 'delivered', 'ta_completed', 'completed', 'cancelled' ];

    public function sc_admin_order_status_update(): void {
        $this->verify( 'ta_admin_nonce' );
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );
        }

        $corder_id = absint( $_POST['corder_id'] ?? 0 );
        $status    = sanitize_key( $_POST['status'] ?? '' );
        if ( ! $corder_id || ! in_array( $status, self::CO_STATUSES, true ) ) {
            wp_send_json_error( [ 'message' => 'داده نامعتبر' ] );
        }

        $co_table = TA_Database::table( 'critique_orders' );
        $order = TA_Database::get_row( "SELECT * FROM {$co_table} WHERE id = %d", [ $corder_id ] );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'سفارش یافت نشد' ] );
        }

        $update = [ 'status' => $status ];
        if ( $status === 'in_progress' && $order->status !== 'in_progress' ) {
            $update['in_progress_at'] = current_time( 'mysql' );
        }
        if ( $status !== 'pending' && ! empty( $order->sla_pending_eligible_at ) ) {
            $update['sla_pending_eligible_at'] = null;
        }
        // اگر ادمین مستقیماً وضعیت را جلو ببرد، برای هماهنگی با گزارش‌ها زمانِ متناظر هم ثبت شود
        // (فقط اگر قبلاً ثبت نشده — تاریخچهٔ واقعی تغییر نمی‌کند)
        if ( $status === 'delivered' && empty( $order->delivered_at ) ) {
            $update['delivered_at'] = current_time( 'mysql' );
        }
        if ( $status === 'ta_completed' && empty( $order->ta_completed_at ) ) {
            $update['ta_completed_at'] = current_time( 'mysql' );
        }
        if ( $status === 'completed' && empty( $order->completed_at ) ) {
            $update['completed_at'] = current_time( 'mysql' );
        }

        global $wpdb;

        // [FEATURE-CAPACITY-SYNC] اثرِ تغییر دستیِ وضعیت روی ظرفیت استادیار.
        // فیلد sms_sent روی هر ردیف همان چیزی است که در on_payment_complete/
        // on_order_cancelled_or_refunded استفاده می‌شود و یعنی «آیا ۱ واحد از
        // ظرفیت این استادیار همین الان بابت این سفارش کسر شده و هنوز برنگشته؟».
        // پس همان پرچم را اینجا هم مرجع می‌گیریم تا با بقیه‌ی سیستم هماهنگ بماند
        // و کسر/برگشتِ تکراری رخ ندهد.
        $was_cancelled = ( $order->status === 'cancelled' );
        $becomes_cancelled = ( $status === 'cancelled' );
        $capacity_note = '';

        if ( ! $was_cancelled && $becomes_cancelled && (int) $order->sms_sent === 1 ) {
            // لغو دستی: ۱ واحد ظرفیت به استادیار برگردد
            $ta = $this->co_get_ta_form_by_id( (int) $order->ta_id );
            if ( $ta && $ta->capacity !== null ) {
                $wpdb->update( TA_Database::table( 'story_critique_forms' ), [ 'capacity' => (int) $ta->capacity + 1 ], [ 'id' => $ta->id ] );
                $capacity_note = 'یک واحد به ظرفیت استادیار «' . $ta->full_name . '» برگشت.';
            }
            $update['sms_sent'] = 0;
        } elseif ( $was_cancelled && ! $becomes_cancelled && (int) $order->sms_sent === 0 ) {
            // بازگرداندن از لغو به وضعیت فعال: دوباره ۱ واحد از ظرفیت کسر شود
            $ta = $this->co_get_ta_form_by_id( (int) $order->ta_id );
            if ( $ta && $ta->capacity !== null ) {
                $new_cap = max( 0, (int) $ta->capacity - 1 );
                $wpdb->update( TA_Database::table( 'story_critique_forms' ), [ 'capacity' => $new_cap ], [ 'id' => $ta->id ] );
                $capacity_note = 'یک واحد از ظرفیت استادیار «' . $ta->full_name . '» کسر شد.';
            }
            $update['sms_sent'] = 1;
        }

        $wpdb->update( $co_table, $update, [ 'id' => $corder_id ] );

        wp_send_json_success( [ 'status' => $status, 'capacity_note' => $capacity_note ] );
    }

    /** کمکی: گرفتن ردیف story_critique_forms بر اساس id (همان چیزی که critique_orders.ta_id به آن اشاره دارد) */
    private function co_get_ta_form_by_id( int $form_id ): ?object {
        if ( ! $form_id ) return null;
        return TA_Database::get_row( "SELECT * FROM " . TA_Database::table( 'story_critique_forms' ) . " WHERE id = %d", [ $form_id ] );
    }

    public function sc_admin_order_delete(): void {
        $this->verify( 'ta_admin_nonce' );
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );
        }

        $corder_id = absint( $_POST['corder_id'] ?? 0 );
        if ( ! $corder_id ) {
            wp_send_json_error( [ 'message' => 'داده نامعتبر' ] );
        }

        $co_table = TA_Database::table( 'critique_orders' );
        $order = TA_Database::get_row( "SELECT * FROM {$co_table} WHERE id = %d", [ $corder_id ] );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'سفارش یافت نشد' ] );
        }

        global $wpdb;

        // [FEATURE-CAPACITY-SYNC] حذفِ سفارشی که هنوز ۱ واحد ظرفیت رویش کسر شده
        // (لغو نشده)، مثل لغو دستی رفتار می‌کند: ظرفیت استادیار برمی‌گردد
        if ( $order->status !== 'cancelled' && (int) $order->sms_sent === 1 ) {
            $ta = $this->co_get_ta_form_by_id( (int) $order->ta_id );
            if ( $ta && $ta->capacity !== null ) {
                $wpdb->update( TA_Database::table( 'story_critique_forms' ), [ 'capacity' => (int) $ta->capacity + 1 ], [ 'id' => $ta->id ] );
            }
        }

        // پاک‌سازی داده‌های وابسته تا رکورد یتیم در جدول‌های دیگر نماند
        $wpdb->delete( TA_Database::table( 'critique_messages' ),        [ 'order_id' => $corder_id ] );
        $wpdb->delete( TA_Database::table( 'eval_responses' ),           [ 'critique_order_id' => $corder_id ] );
        $wpdb->delete( TA_Database::table( 'student_eval_responses' ),   [ 'critique_order_id' => $corder_id ] );
        $wpdb->delete( $co_table, [ 'id' => $corder_id ] );

        wp_send_json_success();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // نقد آثار — گردش‌کار سفارش‌ها توسط استادیار
    // ─────────────────────────────────────────────────────────────────────────

    /** یافتن سفارش نقد آثار متعلق به استادیار جاری، یا خطا */
    private function co_get_own_order(): object {
        $uid = get_current_user_id();
        if ( ! TA_Roles::is_ta( $uid ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );
        }
        $ta_table = TA_Database::table( 'assistants' );
        $ta = TA_Database::get_row( "SELECT id FROM {$ta_table} WHERE user_id = %d", [ $uid ] );
        if ( ! $ta ) {
            wp_send_json_error( [ 'message' => 'استادیار یافت نشد' ] );
        }
        // [BUGFIX] critique_orders.ta_id مقدار story_critique_forms.id را ذخیره می‌کند،
        // نه ta_assistants.id (که $ta->id همان است). قبلاً مقایسه مستقیم با $ta->id باعث
        // می‌شد سفارش هیچ‌وقت پیدا نشود (مگر اتفاقی دو شناسه یکسان بودند) و دکمه‌های
        // «شروع کار / تحویل / تکمیل» برای استادیار کار نکنند.
        $sc_table = TA_Database::table( 'story_critique_forms' );
        $form     = TA_Database::get_row( "SELECT id FROM {$sc_table} WHERE ta_id = %d", [ $ta->id ] );
        if ( ! $form ) {
            wp_send_json_error( [ 'message' => 'فرم قیمت‌گذاری نقد آثار برای شما یافت نشد' ] );
        }
        $corder_id = absint( $_POST['corder_id'] ?? 0 );
        $co_table  = TA_Database::table( 'critique_orders' );
        $order = TA_Database::get_row( "SELECT * FROM {$co_table} WHERE id = %d AND ta_id = %d", [ $corder_id, $form->id ] );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'سفارش یافت نشد' ] );
        }
        return $order;
    }

    public function co_ta_status(): void {
        $this->verify( 'ta_dashboard_nonce' );
        $order  = $this->co_get_own_order();
        $status = sanitize_key( $_POST['status'] ?? '' );

        // [FIX-COMPLETION-FLOW] گذار delivered→completed مستقیم توسط استادیار حذف شد.
        // حالا تکمیل‌شدن نقد دو مرحله‌ای است: استاد با ثبت «ارزیابی هنرجو» سفارش را به
        // ta_completed می‌برد (co_ta_complete)، سپس خودِ هنرجو با تیک «دریافت کردم»
        // آن را نهایی/completed می‌کند (ajax_student_complete در TA_Critique_Order).
        $allowed_transitions = [ 'pending' => 'in_progress' ];
        if ( ! isset( $allowed_transitions[ $order->status ] ) || $allowed_transitions[ $order->status ] !== $status ) {
            wp_send_json_error( [ 'message' => 'تغییر وضعیت نامعتبر است.' ] );
        }

        $co_table = TA_Database::table( 'critique_orders' );
        global $wpdb;
        $wpdb->update( $co_table, [
            'status'         => $status,
            'in_progress_at' => current_time( 'mysql' ),
        ], [ 'id' => $order->id ] );

        // پیامک شروع کار به هنرجو
        if ( $status === 'in_progress' ) {
            $this->co_notify_student_started( $order );
        }

        wp_send_json_success();
    }

    /**
     * استادیار: «تکمیل نقد» — همراه با ثبت اجباریِ فرم «ارزیابی هنرجو» (سؤالاتی که
     * ادمین در تب ارزیابی تعریف کرده). فایل تحویل اجباری نیست (در co_ta_deliver
     * برداشته شده)، اما این مرحله (تکمیل) بدون پرکردن حداقل یک سؤال ارزیابی ممکن نیست.
     * سفارش به وضعیت میانیِ ta_completed می‌رود؛ تکمیل نهایی (completed) با تیک
     * «دریافت کردم» خودِ هنرجو انجام می‌شود (ajax_student_complete).
     */
    public function co_ta_complete(): void {
        $this->verify( 'ta_dashboard_nonce' );
        $order = $this->co_get_own_order();

        if ( ! in_array( $order->status, [ 'delivered' ], true ) ) {
            wp_send_json_error( [ 'message' => 'این سفارش هنوز به مرحلهٔ تکمیل نرسیده (ابتدا باید تحویل شود).' ] );
        }

        $answers = (array) ( $_POST['answers'] ?? [] );
        if ( empty( $answers ) ) {
            wp_send_json_error( [ 'message' => 'لطفاً فرم ارزیابی هنرجو را پر کنید.' ] );
        }

        $q_table = TA_Database::table( 'student_eval_questions' );
        $active_qids = wp_list_pluck( TA_Database::get_results( "SELECT id FROM {$q_table} WHERE is_active = 1" ), 'id' );

        $resp_table = TA_Database::table( 'student_eval_responses' );
        global $wpdb;
        $inserted = 0;
        foreach ( $answers as $qid => $val ) {
            $qid = absint( $qid );
            if ( ! in_array( $qid, array_map( 'intval', $active_qids ), true ) ) continue;
            $val = sanitize_textarea_field( wp_unslash( $val ) );
            if ( $val === '' ) continue;
            $wpdb->insert( $resp_table, [
                'critique_order_id' => $order->id,
                'question_id'       => $qid,
                'ta_id'             => (int) $order->ta_id,
                'answer'            => $val,
                'created_at'        => current_time( 'mysql' ),
            ] );
            $inserted++;
        }

        if ( ! $inserted ) {
            wp_send_json_error( [ 'message' => 'لطفاً حداقل به یک سؤال ارزیابی پاسخ دهید.' ] );
        }

        $co_table = TA_Database::table( 'critique_orders' );
        $wpdb->update( $co_table, [
            'status'          => 'ta_completed',
            'ta_completed_at' => current_time( 'mysql' ),
        ], [ 'id' => $order->id ] );

        // پیامک یادآوری به هنرجو: نقد تکمیل شد، برای تأیید دریافت و ثبت ارزیابی استاد مراجعه کند
        $this->co_notify_student_evaluate( $order );

        wp_send_json_success();
    }

    /** پیامک به هنرجو: استادیار کار روی سفارش را شروع کرد */
    private function co_notify_student_started( object $order ): void {
        $student = get_userdata( (int) $order->student_id );
        $phone   = TA_User_Helper::get_phone( (int) $order->student_id );
        if ( ! $student || ! $phone ) return;

        $sc_table = TA_Database::table( 'story_critique_forms' );
        $ta       = TA_Database::get_row( "SELECT full_name FROM {$sc_table} WHERE id = %d", [ $order->ta_id ] );
        $wc_order = wc_get_order( (int) $order->wc_order_id );

        TA_MeliPayamak::notify_student_started(
            $phone,
            $student->display_name,
            $ta->full_name ?? '',
            $wc_order ? (string) $wc_order->get_order_number() : (string) $order->wc_order_id
        );
    }

    /** پیامک به هنرجو: سفارش توسط استادیار تکمیل شد، برو ارزیابی استاد را ثبت کن */
    private function co_notify_student_evaluate( object $order ): void {
        $student = get_userdata( (int) $order->student_id );
        $phone   = TA_User_Helper::get_phone( (int) $order->student_id );
        if ( ! $student || ! $phone ) return;

        $sc_table = TA_Database::table( 'story_critique_forms' );
        $ta       = TA_Database::get_row( "SELECT full_name FROM {$sc_table} WHERE id = %d", [ $order->ta_id ] );
        $wc_order = wc_get_order( (int) $order->wc_order_id );

        TA_MeliPayamak::notify_student_evaluate(
            $phone,
            $student->display_name,
            $ta->full_name ?? '',
            $wc_order ? (string) $wc_order->get_order_number() : (string) $order->wc_order_id
        );
    }

    public function co_ta_save_notes(): void {
        $this->verify( 'ta_dashboard_nonce' );
        $order = $this->co_get_own_order();

        $co_table = TA_Database::table( 'critique_orders' );
        global $wpdb;
        $wpdb->update( $co_table, [
            'note_to_admin'   => sanitize_textarea_field( $_POST['note_to_admin'] ?? '' ),
            'note_to_student' => sanitize_textarea_field( $_POST['note_to_student'] ?? '' ),
        ], [ 'id' => $order->id ] );

        wp_send_json_success();
    }

    public function co_ta_deliver(): void {
        $this->verify( 'ta_dashboard_nonce' );
        $order = $this->co_get_own_order();

        if ( ! in_array( $order->status, [ 'in_progress' ], true ) ) {
            wp_send_json_error( [ 'message' => 'این سفارش در وضعیت قابل‌تحویل نیست.' ] );
        }

        $co_table = TA_Database::table( 'critique_orders' );
        global $wpdb;
        $update = [
            'status'          => 'delivered',
            'delivered_at'    => current_time( 'mysql' ),
            'note_to_admin'   => sanitize_textarea_field( $_POST['note_to_admin'] ?? '' ),
            'note_to_student' => sanitize_textarea_field( $_POST['note_to_student'] ?? '' ),
        ];

        // [FIX-FILE-OPTIONAL] ارسال فایل دیگر اجباری نیست — استاد می‌تواند بدون
        // پیوست‌کردن فایل هم سفارش را «تحویل» بزند (مثلاً وقتی نقد به‌صورت متنی/چت
        // ارائه شده). اگر فایلی ارسال شود، ذخیره می‌شود؛ در غیر این صورت هیچ خطایی
        // رخ نمی‌دهد و result_file_id (اگر قبلاً چیزی نبوده) خالی می‌ماند.
        if ( ! empty( $_FILES['file'] ) && empty( $_FILES['file']['error'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attachment_id = media_handle_upload( 'file', 0 );
            if ( ! is_wp_error( $attachment_id ) ) {
                $update['result_file_id'] = $attachment_id;
            }
        }

        $wpdb->update( $co_table, $update, [ 'id' => $order->id ] );

        // پیامک تحویل نقد به هنرجو
        $this->co_notify_student_delivered( $order );

        wp_send_json_success();
    }

    /** پیامک به هنرجو: نقد اثر تحویل داده شد */
    private function co_notify_student_delivered( object $order ): void {
        $student = get_userdata( (int) $order->student_id );
        $phone   = TA_User_Helper::get_phone( (int) $order->student_id );
        if ( ! $student || ! $phone ) return;

        $sc_table = TA_Database::table( 'story_critique_forms' );
        $ta       = TA_Database::get_row( "SELECT full_name FROM {$sc_table} WHERE id = %d", [ $order->ta_id ] );
        $wc_order = wc_get_order( (int) $order->wc_order_id );

        TA_MeliPayamak::notify_student_delivered(
            $phone,
            $student->display_name,
            $ta->full_name ?? '',
            $wc_order ? (string) $wc_order->get_order_number() : (string) $order->wc_order_id
        );
    }

    /**
     * [FIX-MSG-01] [FIX-MSG-02] علامت‌گذاری پیام به‌عنوان خوانده‌شده
     * status را از 'pending_admin' به 'pending_admin_reviewed' تغییر می‌دهد
     */
    public function admin_msg_mark_read(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'دسترسی غیرمجاز' );
        }

        $assign_id = absint( $_POST['assignment_id'] ?? 0 );
        if ( ! $assign_id ) {
            wp_send_json_error( 'شناسه نامعتبر' );
        }

        if ( ! check_ajax_referer( 'ta_msg_mark_read_' . $assign_id, 'nonce', false ) ) {
            wp_send_json_error( 'کد امنیتی نامعتبر' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ta_assignments';

        // [FIX-MSG-01] تغییر status به reviewed + افزودن یادداشت
        $result = $wpdb->update(
            $table,
            [
                'status' => 'pending_admin_reviewed',
                'notes'  => $wpdb->get_var( $wpdb->prepare(
                    "SELECT notes FROM {$table} WHERE id=%d", $assign_id
                ) ) . ' [ادمین: مشاهده شد ' . current_time( 'Y-m-d H:i' ) . ']',
            ],
            [ 'id' => $assign_id ]
        );

        if ( $result === false ) {
            wp_send_json_error( 'خطا در پایگاه داده' );
        }

        $pending_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'pending_admin'"
        );

        wp_send_json_success( [
            'pending_count' => $pending_count,
            'delete_nonce'  => wp_create_nonce( 'ta_msg_delete_' . $assign_id ),
        ] );
    }

    /**
     * [FIX-MSG-02] حذف یک ردیف پیام (فقط reviewed)
     */
    public function admin_msg_delete(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'دسترسی غیرمجاز' );
        }

        $assign_id = absint( $_POST['assignment_id'] ?? 0 );
        if ( ! $assign_id ) {
            wp_send_json_error( 'شناسه نامعتبر' );
        }

        if ( ! check_ajax_referer( 'ta_msg_delete_' . $assign_id, 'nonce', false ) ) {
            wp_send_json_error( 'کد امنیتی نامعتبر' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ta_assignments';

        // فقط ردیف‌های reviewed قابل حذف هستند
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT status FROM {$table} WHERE id=%d", $assign_id
        ) );

        if ( ! $row ) {
            wp_send_json_error( 'ردیف یافت نشد' );
        }

        if ( $row->status !== 'pending_admin_reviewed' ) {
            wp_send_json_error( 'فقط ردیف‌های مشاهده‌شده قابل حذف هستند' );
        }

        $result = $wpdb->delete( $table, [ 'id' => $assign_id ] );

        if ( $result === false ) {
            wp_send_json_error( 'خطا در پایگاه داده' );
        }

        wp_send_json_success( [ 'deleted' => true ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // [FIX-MSG-MODAL] تخصیص سریع از modal صفحه پیام‌ها
    // ─────────────────────────────────────────────────────────────────────────
    public function admin_quick_assign(): void {
        check_ajax_referer( 'ta_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );
        }

        $assignment_id  = absint( $_POST['assignment_id']  ?? 0 );
        $student_id     = absint( $_POST['student_id']     ?? 0 );
        $product_id     = absint( $_POST['product_id']     ?? 0 );
        $ta_id          = absint( $_POST['ta_id']          ?? 0 );
        $capacity_id    = absint( $_POST['capacity_id']    ?? 0 );
        $messenger      = sanitize_text_field( $_POST['messenger']      ?? 'telegram' );
        $student_gender = sanitize_text_field( $_POST['student_gender'] ?? 'unknown' );
        $group_link     = esc_url_raw( TA_URL_Helper::normalize( $_POST['group_link'] ?? '' ) );

        if ( ! $student_id || ! $product_id || ! $ta_id ) {
            wp_send_json_error( [ 'message' => 'پارامترهای ورودی ناقص است.' ] );
        }

        $settings = TA_Product_Meta::get_settings( $product_id );
        if ( ! $settings ) {
            wp_send_json_error( [ 'message' => 'تنظیمات محصول یافت نشد.' ] );
        }

        global $wpdb;
        $assign_table = TA_Database::table( 'assignments' );
        $cap_table    = TA_Database::table( 'capacities' );
        [ $cooperation_ok, $cooperation_message ] = $this->cooperation_allows_new_assignment( $ta_id, (int) $settings->term_id );
        if ( ! $cooperation_ok ) wp_send_json_error( [ 'message' => '⚠️ تخصیص هنرجوی جدید مسدود است. ' . $cooperation_message ] );

        // اگر assignment_id معتبر داریم، تخصیص pending را به‌روز کن
        if ( $assignment_id ) {
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, status FROM {$assign_table} WHERE id = %d AND student_id = %d",
                $assignment_id, $student_id
            ) );

            if ( $existing && in_array( $existing->status, [ 'pending_admin', 'pending_admin_reviewed' ], true ) ) {
                // capacity_id: ترجیحاً از پارامتر ارسالی، وگرنه جستجو
                if ( $capacity_id ) {
                    $cap = $wpdb->get_row( $wpdb->prepare(
                        "SELECT id FROM {$cap_table} WHERE id = %d AND ta_id = %d AND used_capacity < total_capacity",
                        $capacity_id, $ta_id
                    ) );
                } else {
                    $cap = $wpdb->get_row( $wpdb->prepare(
                        "SELECT id FROM {$cap_table}
                         WHERE ta_id = %d AND term_id = %d AND course_type = %s
                           AND used_capacity < total_capacity
                         LIMIT 1",
                        $ta_id, $settings->term_id, $settings->course_type
                    ) );
                }

                $update_data = [
                    'ta_id'          => $ta_id,
                    'status'         => 'active',
                    'messenger'      => $messenger,
                    'student_gender' => $student_gender,
                ];
                if ( $group_link ) {
                    $update_data['group_link'] = $group_link;
                }
                if ( $cap ) {
                    $update_data['capacity_id'] = $cap->id;
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$cap_table} SET used_capacity = used_capacity + 1 WHERE id = %d",
                        $cap->id
                    ) );
                }

                $result = $wpdb->update( $assign_table, $update_data, [ 'id' => $assignment_id ] );
                if ( $result === false ) {
                    wp_send_json_error( [ 'message' => 'خطا در پایگاه داده.' ] );
                }

                $pending_count = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$assign_table} WHERE status = 'pending_admin'"
                );
                wp_send_json_success( [
                    'message'       => 'استادیار با موفقیت تخصیص یافت.',
                    'pending_count' => $pending_count,
                ] );
                return;
            }
        }

        // تخصیص جدید
        $already = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$assign_table} WHERE student_id=%d AND product_id=%d",
            $student_id, $product_id
        ) );
        if ( $already ) {
            wp_send_json_error( [ 'message' => 'این هنرجو قبلاً برای این محصول تخصیص دارد.' ] );
        }

        TA_Database::insert( 'ta_assignments', [
            'student_id'     => $student_id,
            'ta_id'          => $ta_id,
            'product_id'     => $product_id,
            'term_id'        => $settings->term_id,
            'course_type'    => $settings->course_type,
            'messenger'      => $messenger,
            'student_gender' => $student_gender,
            'group_link'     => $group_link ?: null,
            'status'         => 'active',
            'created_at'     => current_time( 'mysql' ),
        ] );

        $pending_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$assign_table} WHERE status = 'pending_admin'"
        );
        wp_send_json_success( [
            'message'       => 'تخصیص جدید با موفقیت ثبت شد.',
            'pending_count' => $pending_count,
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ویرایش نمره از پیشخوان استادیار (تب نمره‌ها)
    // ─────────────────────────────────────────────────────────────────────────

    public function ta_update_grade(): void {
        // بررسی nonce — فرم با key «ta_grade_edit_nonce» ارسال می‌کند
        if ( ! check_ajax_referer( 'ta_grade_edit', 'ta_grade_edit_nonce', false ) ) {
            wp_send_json_error( 'توکن امنیتی نامعتبر است.' );
        }

        // فقط استادیار مجاز است
        [, $ta] = $this->require_ta();

        // بررسی تنظیم نمره‌دهی
        if ( ! TA_Settings_Helper::is_enabled( 'ta_grading_enabled' ) ) {
            wp_send_json_error( 'نمره‌دهی توسط استادیار در حال حاضر غیرفعال است.' );
        }

        // بررسی مجوز ویرایش
        if ( ! TA_Settings_Helper::is_enabled( 'allow_grade_edit' ) ) {
            wp_send_json_error( 'ویرایش نمره غیرفعال است.' );
        }

        $grade_id = absint( $_POST['grade_id'] ?? 0 );
        $grade    = sanitize_text_field( $_POST['grade'] ?? '' );
        $notes    = sanitize_textarea_field( $_POST['notes'] ?? '' );

        if ( ! $grade_id ) {
            wp_send_json_error( 'شناسه نمره نامعتبر است.' );
        }

        if ( ! array_key_exists( $grade, TA_Grade::all_grades() ) ) {
            wp_send_json_error( 'نمره انتخاب‌شده معتبر نیست.' );
        }

        // بررسی اینکه این نمره متعلق به همین استادیار باشد
        $grade_tbl    = TA_Database::table( 'grades' );
        $existing     = TA_Database::get_row(
            "SELECT * FROM {$grade_tbl} WHERE id = %d",
            [ $grade_id ]
        );

        if ( ! $existing ) {
            wp_send_json_error( 'نمره یافت نشد.' );
        }

        if ( (int) $existing->ta_id !== (int) $ta->id ) {
            wp_send_json_error( 'شما مجاز به ویرایش این نمره نیستید.' );
        }

        $allowed_grades = TA_Grade::get_grades_for_course( (string) $existing->course_type );
        if ( ! array_key_exists( $grade, $allowed_grades ) || $grade === 'pending' ) {
            wp_send_json_error( 'این نمره برای نوع دوره ثبت‌شده مجاز نیست.' );
        }

        // بررسی مهلت ویرایش
        $limit_days = (int) TA_Settings_Helper::get( 'grade_edit_limit_days', 0 );
        if ( $limit_days > 0 && ! empty( $existing->created_at ) ) {
            $elapsed_days = ( time() - strtotime( $existing->created_at ) ) / DAY_IN_SECONDS;
            if ( $elapsed_days > $limit_days ) {
                wp_send_json_error( sprintf(
                    'مهلت ویرایش نمره (%d روز) به پایان رسیده است.',
                    $limit_days
                ) );
            }
        }

        // ذخیره‌سازی
        $updated = TA_Database::update(
            'ta_grades',
            [ 'grade' => $grade, 'notes' => $notes, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $grade_id ]
        );

        if ( $updated !== false ) {
            wp_send_json_success( [ 'message' => 'نمره با موفقیت ویرایش شد.' ] );
        } else {
            wp_send_json_error( 'خطا در ذخیره‌سازی. لطفاً دوباره تلاش کنید.' );
        }
    }



    // ═══════════════════════════════════════════════════════════════════
    // [GROUP-JOIN-LOG] لاگ ورود واقعی به گروه (هنرجو / استادیار / ادمین)
    // ملاک ثبت لاگ، لاگین‌شدن در سایت نیست؛ بلکه کلیک/تاچ روی لینک «ورود به
    // گروه» است. لینک‌های گروه در همه‌ی صفحات به‌جای اشاره‌ی مستقیم به
    // group_link، از طریق TA_URL_Helper::build_join_url() به این اندپوینت
    // اشاره می‌کنند؛ این اندپوینت لاگ می‌زند و سپس کاربر را به لینک واقعی
    // گروه ریدایرکت می‌کند. چون این یک لینک عادی <a href> است (نه رویداد JS)،
    // کلیک چپ، کلیک وسط، Ctrl+Click، تاچ، و راست‌کلیک → Open in new tab/window
    // همگی از همین مسیر عبور می‌کنند و لاگ می‌خورند.
    // ═══════════════════════════════════════════════════════════════════

    public function join_group(): void {
        $assignment_id = absint( $_GET['assignment_id'] ?? 0 );
        $nonce         = sanitize_text_field( $_GET['nonce'] ?? '' );

        if ( ! $assignment_id || ! wp_verify_nonce( $nonce, 'ta_join_group_' . $assignment_id ) ) {
            wp_die( 'لینک نامعتبر یا منقضی‌شده است.', 'خطا', [ 'response' => 403 ] );
        }

        // مهمان (لاگین‌نشده) → به صفحه ورود هدایت شود و بعد از لاگین به همین لینک برگردد
        if ( ! is_user_logged_in() ) {
            wp_redirect( wp_login_url( $_SERVER['REQUEST_URI'] ?? home_url() ) );
            exit;
        }

        global $wpdb;
        $assign_table = TA_Database::table( 'assignments' );
        $ta_table     = TA_Database::table( 'assistants' );

        $assignment = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.id, a.student_id, a.product_id, a.messenger, a.group_link, ta.user_id AS ta_user_id
             FROM {$assign_table} a
             LEFT JOIN {$ta_table} ta ON ta.id = a.ta_id
             WHERE a.id = %d",
            $assignment_id
        ) );

        if ( ! $assignment || empty( $assignment->group_link ) ) {
            wp_die( 'لینک گروه برای این تخصیص یافت نشد.', 'خطا', [ 'response' => 404 ] );
        }

        $current_user = wp_get_current_user();
        $is_admin     = user_can( $current_user, 'manage_options' );
        $is_this_ta   = (int) $assignment->ta_user_id === (int) $current_user->ID;
        $is_this_stu  = (int) $assignment->student_id === (int) $current_user->ID;

        // فقط خودِ هنرجو، استادیارِ همان تخصیص، یا ادمین اجازه‌ی ورود از این لینک را دارند
        if ( ! $is_admin && ! $is_this_ta && ! $is_this_stu ) {
            wp_die( 'دسترسی غیرمجاز.', 'خطا', [ 'response' => 403 ] );
        }

        $actor_type = $is_admin ? 'admin' : ( $is_this_ta ? 'ta' : 'student' );

        TA_Database::insert( 'ta_group_join_logs', [
            'assignment_id' => $assignment_id,
            'student_id'    => (int) $assignment->student_id,
            'product_id'    => (int) $assignment->product_id,
            'actor_type'    => $actor_type,
            'actor_id'      => (int) $current_user->ID,
            'messenger'     => $assignment->messenger,
            'group_link'    => $assignment->group_link,
            'logged_at'     => current_time( 'mysql' ),
        ], [ '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s' ] );

        wp_redirect( esc_url_raw( $assignment->group_link ) );
        exit;
    }

    /**
     * دریافت لاگ‌های ورود به گروه مرتبط با یک تخصیص مشخص
     * (منبع: جدول ta_group_join_logs که هر رکورد آن یک کلیکِ واقعی روی
     * لینک «ورود به گروه» توسط هنرجو، استادیار یا ادمین است.)
     */
    public function get_join_logs(): void {
        check_ajax_referer( 'ta_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز.' ] );
        }

        $assignment_id = absint( $_POST['assignment_id'] ?? 0 );
        if ( ! $assignment_id ) {
            wp_send_json_error( [ 'message' => 'شناسه تخصیص الزامی است.' ] );
        }

        global $wpdb;
        $log_table = TA_Database::table( 'group_join_logs' );

        $logs = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.*, l.actor_id AS actor_id, u.display_name AS actor_name, u.user_email AS actor_email
             FROM {$log_table} l
             LEFT JOIN {$wpdb->users} u ON u.ID = l.actor_id
             WHERE l.assignment_id = %d
             ORDER BY l.logged_at DESC
             LIMIT 200",
            $assignment_id
        ) );

        wp_send_json_success( [ 'logs' => $logs ] );
    }

}
