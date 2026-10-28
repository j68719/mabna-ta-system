<?php
defined( 'ABSPATH' ) || exit;

/**
 * مدیریت کامل تخصیص‌ها توسط ادمین
 * — جستجوی هنرجو با نام / شماره همراه
 * — تخصیص تکی
 * — ویرایش و حذف تخصیص‌های موجود
 * — لیست با فیلتر
 */
class TA_Admin_Assignments {

    public function __construct() {
        add_action( 'admin_post_ta_admin_assign',        [ $this, 'handle_assign'  ] );
        add_action( 'admin_post_ta_admin_edit_assign',   [ $this, 'handle_edit'    ] );
        add_action( 'admin_post_ta_admin_delete_assign', [ $this, 'handle_delete'  ] );
        // ta_search_users در TA_Admin_TA_Manager ثبت شده — تکرار نمی‌کنیم
        add_action( 'wp_ajax_ta_get_assignment',         [ $this, 'ajax_get_assignment' ] );
        add_action( 'wp_ajax_ta_save_student_gender',[ $this, 'ajax_save_student_gender' ] );
        // [FIX-EDIT-CAP-01] پیش‌بررسی ظرفیت قبل از ارسال فرم ویرایش
        add_action( 'wp_ajax_ta_admin_check_edit_capacity', [ $this, 'ajax_check_edit_capacity' ] );
        // [FIX-EDIT-CAP-02] فهرست ردیف‌های ظرفیت یک استادیار برای فیلد جدید «ظرفیت» در فرم ویرایش
        add_action( 'wp_ajax_ta_admin_list_ta_capacities', [ $this, 'ajax_list_ta_capacities' ] );
        // [GROUP-REVIEW] تیک «بررسی شد» در صفحه‌ی تخصیص‌ها
        add_action( 'wp_ajax_ta_admin_toggle_reviewed', [ $this, 'ajax_toggle_reviewed' ] );
        // [GROUP-REVIEW] علامت‌زدن دسته‌جمعی «بررسی شد» برای همه‌ی نتایج فیلتر فعلی
        add_action( 'wp_ajax_ta_admin_bulk_mark_reviewed', [ $this, 'ajax_bulk_mark_reviewed' ] );
        add_action( 'wp_ajax_ta_admin_delete_assign', [ $this, 'ajax_delete_assign' ] );

    }

    /** حذف AJAX تخصیص؛ همان آزادسازی گروه/ظرفیت/نمره حذف فرم قدیمی را انجام می‌دهد. */
    public function ajax_delete_assign(): void {
        check_ajax_referer( 'ta_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز.' ], 403 );
        $id = absint( $_POST['assignment_id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'شناسه تخصیص نامعتبر است.' ] );
        $row = TA_Database::get_row( "SELECT * FROM " . TA_Database::table( 'assignments' ) . " WHERE id = %d", [ $id ] );
        if ( ! $row ) wp_send_json_error( [ 'message' => 'تخصیص پیدا نشد یا قبلاً حذف شده است.' ] );
        global $wpdb;
        if ( ! empty( $row->group_id ) ) {
            TA_Database::update( 'ta_groups', [ 'is_used'=>0, 'assigned_to_user_id'=>null, 'assigned_at'=>null ], [ 'id'=>(int)$row->group_id ] );
        }
        if ( ! empty( $row->capacity_id ) ) {
            $wpdb->query( $wpdb->prepare( "UPDATE " . TA_Database::table('capacities') . " SET used_capacity = GREATEST(0, used_capacity - 1) WHERE id = %d", [ (int)$row->capacity_id ] ) );
        } else {
            $wpdb->query( $wpdb->prepare( "UPDATE " . TA_Database::table('capacities') . " SET used_capacity = GREATEST(0, used_capacity - 1) WHERE ta_id = %d AND course_type = %s AND term_id = %d AND student_gender IN (%s, 'both') ORDER BY student_gender DESC LIMIT 1", [ (int)$row->ta_id, $row->course_type, (int)$row->term_id, $row->student_gender ] ) );
        }
        if ( class_exists( 'TA_Grade' ) && TA_Grade::get_grade( (int)$row->student_id, (int)$row->product_id ) ) {
            TA_Database::delete( 'ta_grades', [ 'student_id'=>(int)$row->student_id, 'product_id'=>(int)$row->product_id ] );
        }
        $deleted = TA_Database::delete( 'ta_assignments', [ 'id'=>$id ] );
        if ( false === $deleted ) wp_send_json_error( [ 'message' => 'حذف تخصیص انجام نشد.' ] );
        wp_send_json_success( [ 'id'=>$id, 'message'=>'تخصیص با موفقیت حذف شد.' ] );
    }

    // ════════════════════════════════════════════════════════════════
    // [GROUP-REVIEW] ساخت شرط WHERE مشترک لیست تخصیص‌ها — هم در render()
    // و هم در عملیات دسته‌جمعی «بررسی همه‌ی این فیلتر» استفاده می‌شود.
    // ════════════════════════════════════════════════════════════════
    private function build_assignments_where( array $input ): array {
        global $wpdb;

        $search        = sanitize_text_field( $input['s'] ?? '' );
        $f_term        = (int) ( $input['f_term'] ?? 0 );
        $f_course      = sanitize_text_field( $input['f_course'] ?? '' );
        $f_status      = sanitize_text_field( $input['f_status'] ?? '' );
        $filter_gender = sanitize_text_field( $input['filter_gender'] ?? '' );
        $filter_ta     = sanitize_text_field( $input['filter_ta'] ?? '' );
        $filter_ta_id  = (int) ( $input['filter_ta_id'] ?? 0 );

        $where   = [ '1=1' ];
        $params  = [];

        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            // جستجو در نام هنرجو + ایمیل + شماره همراه
            $phone_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$wpdb->usermeta}
                 WHERE meta_key IN ('kerasno-phone','phone','Mreeir_phone','billing_phone')
                 AND meta_value LIKE %s",
                [ $like ]
            ) );
            $name_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE display_name LIKE %s OR user_email LIKE %s",
                [ $like, $like ]
            ) );
            $all_ids = array_unique( array_merge( $phone_ids, $name_ids ) );

            if ( $all_ids ) {
                $ids_placeholder = implode( ',', array_map( 'intval', $all_ids ) );
                $where[] = "a.student_id IN ({$ids_placeholder})";
            } else {
                $where[] = '1=0'; // هیچ نتیجه‌ای
            }
        }
        if ( $f_term )   { $where[] = 'a.term_id = %d';      $params[] = $f_term; }
        if ( $f_course ) { $where[] = 'a.course_type = %s';   $params[] = $f_course; }
        if ( $f_status ) { $where[] = 'a.status = %s';        $params[] = $f_status; }
        if ( $filter_gender ) { $where[] = 'a.student_gender = %s'; $params[] = $filter_gender; }

        if ( $filter_ta === 'unassigned' ) {
            // اضافه شدن ta.id IS NULL برای پیدا کردن تکالیفی که استادشان حذف شده است
            $where[] = '(a.ta_id IS NULL OR a.ta_id = 0 OR ta.id IS NULL)';
        } elseif ( $filter_ta_id > 0 ) {
            $where[]  = 'ta.id = %d';
            $params[] = $filter_ta_id;
        }

        return [ $where, $params ];
    }

    // ════════════════════════════════════════════════════════════════
    // [GROUP-REVIEW] AJAX — علامت‌زدن «بررسی شد» برای همه‌ی تخصیص‌های
    // منطبق با فیلتر فعلیِ صفحه (روی همه‌ی صفحات، نه فقط صفحه‌ی جاری)
    // ════════════════════════════════════════════════════════════════
    public function ajax_bulk_mark_reviewed(): void {
        check_ajax_referer( 'ta_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );
        }

        global $wpdb;
        $assign_table = TA_Database::table( 'assignments' );
        $ta_table     = TA_Database::table( 'assistants' );

        [ $where, $params ] = $this->build_assignments_where( $_POST );
        $where_sql = implode( ' AND ', $where );

        $sql = "UPDATE {$assign_table} a
                LEFT JOIN {$ta_table} ta ON ta.id = a.ta_id
                SET a.admin_reviewed = 1
                WHERE {$where_sql}";

        $result = $params
            ? $wpdb->query( $wpdb->prepare( $sql, $params ) )
            : $wpdb->query( $sql );

        if ( $result === false ) {
            wp_send_json_error( [ 'message' => 'خطا در ذخیره‌سازی: ' . $wpdb->last_error ] );
        }

        wp_send_json_success( [ 'affected' => (int) $result ] );
    }

    // ════════════════════════════════════════════════════════════════
    // [GROUP-REVIEW] AJAX — ذخیره‌ی وضعیت چک‌باکس «بررسی شد» برای یک تخصیص
    // ════════════════════════════════════════════════════════════════
    public function ajax_toggle_reviewed(): void {
        check_ajax_referer( 'ta_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );
        }

        $assignment_id = (int) ( $_POST['assignment_id'] ?? 0 );
        $reviewed      = ! empty( $_POST['reviewed'] ) && $_POST['reviewed'] !== '0' ? 1 : 0;

        if ( ! $assignment_id ) {
            wp_send_json_error( [ 'message' => 'شناسه تخصیص نامعتبر است' ] );
        }

        $updated = TA_Database::update(
            'ta_assignments',
            [ 'admin_reviewed' => $reviewed ],
            [ 'id' => $assignment_id ],
            [ '%d' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            wp_send_json_error( [ 'message' => 'خطا در ذخیره‌سازی' ] );
        }

        wp_send_json_success( [ 'reviewed' => $reviewed ] );
    }
    
 public function ajax_save_student_gender () : void{
    check_ajax_referer( 'ta_admin_nonce', 'nonce' );

    $student_id = intval( $_POST['student_id'] ?? 0 );
    $gender     = sanitize_text_field( $_POST['gender'] ?? '' );

    if ( ! $student_id || ! in_array( $gender, [ 'male', 'female' ], true ) ) {
        wp_send_json_error( 'داده نامعتبر' );
    }

    update_user_meta( $student_id, 'student_gender', $gender );
    update_user_meta( $student_id, 'gender', $gender );

    wp_send_json_success( [
        'message' => 'جنسیت ذخیره شد',
        'gender'  => $gender,
    ] );
} 

    // ════════════════════════════════════════════════════════════════
    // [FIX-EDIT-CAP-01] کمکی: یافتن ظرفیت آزاد یک استادیار برای شرایط مشخص
    // ════════════════════════════════════════════════════════════════
    /**
     * یک ردیف ظرفیت آزاد (used_capacity < total_capacity) برای استادیار،
     * دوره، ترم و جنسیت/پیام‌رسان داده‌شده برمی‌گرداند، یا null اگر چیزی
     * پیدا نشود. اولویت با تطابق دقیق جنسیت/پیام‌رسان است، سپس 'both'.
     */
    private function find_capacity_for_ta( int $ta_id, string $course_type, int $term_id, string $gender, string $messenger ): ?object {
        return TA_Database::get_row(
            "SELECT * FROM " . TA_Database::table('capacities') . "
             WHERE ta_id = %d AND course_type = %s AND term_id = %d
               AND student_gender IN (%s, 'both')
               AND messenger IN (%s, 'both')
               AND used_capacity < total_capacity
             ORDER BY (student_gender = %s) DESC, (messenger = %s) DESC, id ASC
             LIMIT 1",
            [ $ta_id, $course_type, $term_id, $gender, $messenger, $gender, $messenger ]
        );
    }

    /**
     * مشابه بالا اما بدون شرط used_capacity < total_capacity — برای حالتی
     * که ادمین آگاهانه می‌خواهد علی‌رغم پر بودن ظرفیت ادامه دهد و فقط
     * نیاز به شناسایی ردیف ظرفیتی دارد که used_capacity روی آن sync شود.
     */
    private function find_any_capacity_row_for_ta( int $ta_id, string $course_type, int $term_id, string $gender, string $messenger ): ?object {
        return TA_Database::get_row(
            "SELECT * FROM " . TA_Database::table('capacities') . "
             WHERE ta_id = %d AND course_type = %s AND term_id = %d
               AND student_gender IN (%s, 'both')
               AND messenger IN (%s, 'both')
             ORDER BY (student_gender = %s) DESC, (messenger = %s) DESC, id ASC
             LIMIT 1",
            [ $ta_id, $course_type, $term_id, $gender, $messenger, $gender, $messenger ]
        );
    }

    // ════════════════════════════════════════════════════════════════
    // [FIX-EDIT-CAP-02] AJAX — فهرست ردیف‌های ظرفیت یک استادیار برای فیلد «ظرفیت» در فرم ویرایش
    // ════════════════════════════════════════════════════════════════
    /**
     * این فیلد جدید، آن ظرفیتی را که استادیار اعلام کرده و تخصیص فعلی به آن
     * تعلق دارد نمایش می‌دهد، و امکان انتقال صریح تخصیص به یک ردیف ظرفیت
     * دیگر (مثلاً وقتی استادیار تغییر می‌کند) را می‌دهد — به‌جای حدس خودکار
     * بر اساس جنسیت/پیام‌رسان که ممکن است در حالت وجود چند ردیف ظرفیت برای
     * یک استادیار (مثلاً هم male و هم female جدا) ابهام ایجاد کند.
     */
    public function ajax_list_ta_capacities(): void {
        check_ajax_referer( 'ta_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );

        $ta_id          = (int) ( $_POST['ta_id'] ?? 0 );
        $course_type    = sanitize_text_field( $_POST['course_type'] ?? '' );
        $term_id        = (int) ( $_POST['term_id'] ?? 0 );
        // capacity_id فعلی تخصیص (در صورت وجود) تا در لیست به‌صورت «انتخاب‌شده» مشخص شود
        $current_capacity_id = (int) ( $_POST['current_capacity_id'] ?? 0 );

        if ( ! $ta_id || ! $course_type || ! $term_id ) {
            wp_send_json_error( [ 'message' => 'داده ناقص است' ] );
        }

        $rows = TA_Database::get_results(
            "SELECT id, student_gender, messenger, total_capacity, used_capacity
             FROM " . TA_Database::table('capacities') . "
             WHERE ta_id = %d AND course_type = %s AND term_id = %d
             ORDER BY student_gender ASC, messenger ASC, id ASC",
            [ $ta_id, $course_type, $term_id ]
        );

        $gender_label = [ 'male' => 'آقا', 'female' => 'خانم', 'both' => 'هر دو جنسیت' ];
        $messenger_label = [ 'telegram' => 'تلگرام', 'bale' => 'بله', 'eitaa' => 'ایتا', 'both' => 'هر پیام‌رسان' ];

        $data = array_map( function ( $row ) use ( $current_capacity_id, $gender_label, $messenger_label ) {
            $remaining = (int) $row->total_capacity - (int) $row->used_capacity;
            return [
                'capacity_id'    => (int) $row->id,
                'student_gender' => $row->student_gender,
                'messenger'      => $row->messenger,
                'total_capacity' => (int) $row->total_capacity,
                'used_capacity'  => (int) $row->used_capacity,
                'remaining'      => $remaining,
                'is_full'        => $remaining <= 0,
                'is_current'     => ( $current_capacity_id && (int) $row->id === $current_capacity_id ),
                'label'          => sprintf(
                    '%s — %s — ظرفیت %d/%d %s',
                    $gender_label[ $row->student_gender ]    ?? $row->student_gender,
                    $messenger_label[ $row->messenger ]      ?? $row->messenger,
                    (int) $row->used_capacity,
                    (int) $row->total_capacity,
                    $remaining <= 0 ? '(تکمیل)' : ''
                ),
            ];
        }, $rows );

        wp_send_json_success( [ 'capacities' => $data ] );
    }

    // ════════════════════════════════════════════════════════════════
    // [FIX-EDIT-CAP-01] AJAX — پیش‌بررسی ظرفیت استادیار جدید قبل از ذخیره ویرایش
    // ════════════════════════════════════════════════════════════════
    public function ajax_check_edit_capacity(): void {
        check_ajax_referer( 'ta_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );

        $assignment_id = (int) ( $_POST['assignment_id'] ?? 0 );
        $new_ta_id     = (int) ( $_POST['ta_id'] ?? 0 );
        $gender        = sanitize_text_field( $_POST['student_gender'] ?? '' );
        $messenger     = sanitize_text_field( $_POST['messenger'] ?? '' );


        if ( ! $assignment_id || ! $new_ta_id ) {
            wp_send_json_error( [ 'message' => 'داده ناقص است' ] );
        }

        $current = TA_Database::get_row(
            "SELECT ta_id, course_type, term_id FROM " . TA_Database::table('assignments') . " WHERE id = %d",
            [ $assignment_id ]
        );
        if ( ! $current ) {
            wp_send_json_error( [ 'message' => 'تخصیص یافت نشد' ] );
        }

        // اگر استادیار تغییری نکرده، نیازی به بررسی ظرفیت نیست
        if ( $new_ta_id === (int) $current->ta_id ) {
            wp_send_json_success( [ 'ta_changed' => false, 'has_capacity' => true ] );
        }

        $capacity = $this->find_capacity_for_ta(
            $new_ta_id, $current->course_type, (int) $current->term_id, $gender, $messenger
        );

        $ta_name_row = TA_Database::get_row(
            "SELECT u.display_name FROM " . TA_Database::table('assistants') . " ta
             JOIN {$GLOBALS['wpdb']->users} u ON u.ID = ta.user_id WHERE ta.id = %d",
            [ $new_ta_id ]
        );
        $ta_name = $ta_name_row ? $ta_name_row->display_name : 'استادیار انتخابی';
        $course_label = class_exists( 'TA_Product_Meta' ) ? TA_Product_Meta::course_type_label( $current->course_type ) : $current->course_type;

        wp_send_json_success( [
            'ta_changed'   => true,
            'has_capacity' => (bool) $capacity,
            'remaining'    => $capacity ? (int) $capacity->total_capacity - (int) $capacity->used_capacity : 0,
            'message'      => $capacity
                ? ''
                : "ظرفیت آزادی برای «{$ta_name}» در دوره «{$course_label}» با این جنسیت/پیام‌رسان یافت نشد. آیا مطمئن هستید می‌خواهید بدون رعایت ظرفیت ادامه دهید؟",
        ] );
    }

    // AJAX — دریافت اطلاعات تخصیص برای ویرایش
    public function ajax_get_assignment(): void {
        check_ajax_referer( 'ta_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $id  = (int) ( $_POST['id'] ?? 0 );
        $row = TA_Database::get_row(
            "SELECT * FROM " . TA_Database::table( 'assignments' ) . " WHERE id = %d",
            [ $id ]
        );
        if ( ! $row ) wp_send_json_error( 'یافت نشد' );

        $student = get_userdata( $row->student_id );
        $phone = class_exists('TA_User_Helper')
    ? TA_User_Helper::get_phone( $row->student_id )
    : (
        get_user_meta($row->student_id, 'kerasno-phone',  true) ?:
        get_user_meta($row->student_id, 'phone',          true) ?:
        get_user_meta($row->student_id, 'Mreeir_phone',   true) ?:
        get_user_meta($row->student_id, 'billing_phone',  true) ?:
        ''
    );
        $ta      = TA_Database::get_row(
            "SELECT ta.*, u.display_name FROM " . TA_Database::table('assistants') . " ta
             JOIN {$GLOBALS['wpdb']->users} u ON u.ID = ta.user_id WHERE ta.id = %d",
            [ $row->ta_id ]
        );

        wp_send_json_success( [
            'id'             => $row->id,
            'student_id'     => $row->student_id,
            'student_name'   => $student ? $student->display_name : '',
            'student_email'  => $student ? $student->user_email : '',
            'student_phone'  => $phone ?: '',
            'ta_id'          => $row->ta_id,
            'ta_name'        => $ta ? $ta->display_name : '',
            'product_id'     => $row->product_id,
            'term_id'        => $row->term_id,
            'course_type'    => $row->course_type,
            'messenger'      => $row->messenger,
            'student_gender' => $row->student_gender,
            'group_link'     => $row->group_link,
            'status'         => $row->status,
            'cancel_date'         => $row->cancel_date ,
            'cancel_reason'         => $row->cancel_reason,
            // [FIX-EDIT-CAP-02] ظرفیتی که این تخصیص فعلاً به آن تعلق دارد
            'capacity_id'         => (int) ( $row->capacity_id ?? 0 ),
        ] );
    }

    // ════════════════════════════════════════════════════════════════
    // HANDLERS
    // ════════════════════════════════════════════════════════════════

    public function handle_assign(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
        check_admin_referer( 'ta_admin_assign' );

        $student_id = (int) ( $_POST['student_id'] ?? 0 );
        $ta_id      = (int) ( $_POST['ta_id'] ?? 0 );
        $product_id = (int) ( $_POST['product_id'] ?? 0 );
        $messenger  = sanitize_text_field( $_POST['messenger'] ?? '' );
        $gender     = sanitize_text_field( $_POST['student_gender'] ?? '' );
        $group_link = sanitize_url( TA_URL_Helper::normalize( $_POST['group_link'] ?? '' ) );
        $status     = sanitize_text_field( $_POST['status'] ?? 'active' );

        if ( ! $student_id || ! $ta_id || ! $product_id ) {
            $this->redirect( 'error=missing_fields' );
        }

        $settings = TA_Product_Meta::get_settings( $product_id );
        if ( ! $settings ) {
            $this->redirect( 'error=product_not_configured' );
        }

        // بررسی تکراری
        $existing = TA_Database::get_row(
            "SELECT id FROM " . TA_Database::table('assignments') . " WHERE student_id=%d AND product_id=%d",
            [ $student_id, $product_id ]
        );
        if ( $existing ) {
            $this->redirect( 'error=duplicate' );
        }

        TA_Database::insert( 'ta_assignments', [
            'student_id'     => $student_id,
            'ta_id'          => $ta_id,
            'product_id'     => $product_id,
            'term_id'        => $settings->term_id,
            'course_type'    => $settings->course_type,
            'messenger'      => $messenger,
            'student_gender' => $gender,
            'group_link'     => $group_link ?: null,
            'status'         => $status,
            'created_at'     => current_time( 'mysql' ),
            
        ] );

        $this->redirect( 'assigned=1' );
    }

public function handle_edit(): void {
    // 1. اعتبارسنجی اولیه و بررسی دسترسی‌ها
    if ( ! current_user_can('manage_options') ) {
        wp_die('دسترسی غیرمجاز');
    }
    check_admin_referer('ta_admin_edit_assign');

    // 2. خواندن و پاکسازی تمام داده‌های ورودی از فرم
    $id              = (int) ( $_POST['assignment_id'] ?? 0 );
    $ta_id           = (int) ( $_POST['ta_id'] ?? 0 );
    $messenger       = sanitize_text_field( $_POST['messenger'] ?? '' );
    $gender          = sanitize_text_field( $_POST['student_gender'] ?? '' );
    $group_link      = sanitize_url( TA_URL_Helper::normalize( $_POST['group_link'] ?? '' ) );
    $status          = sanitize_text_field( $_POST['status'] ?? 'active' );
    $cancel_reason   = sanitize_textarea_field( $_POST['cancel_reason'] ?? '' );
    // [FIX-EDIT-CAP-02] ظرفیتی که ادمین صریحاً از فیلد جدید «ظرفیت» انتخاب کرده است.
    // این مقدار اکنون منبع اصلی تصمیم‌گیری برای انتقال ظرفیت است — نه حدسِ خودکار
    // بر اساس جنسیت/پیام‌رسان (که در صورت وجود چند ردیف ظرفیت برای یک استادیار،
    // ممکن بود ردیف اشتباهی انتخاب شود یا اصلاً sync انجام نشود).
    $submitted_capacity_id = (int) ( $_POST['capacity_id'] ?? 0 );
    // [FIX-EDIT-CAP-01] آیا ادمین صراحتاً تأیید کرده که بدون رعایت ظرفیت ادامه پیدا کند؟
    $force_no_capacity = ! empty( $_POST['force_ignore_capacity'] );
    // تاریخ انصراف را از ورودی بخوانید، اگر خالی بود null در نظر بگیرید
    $cancel_date_from_post = ! empty( $_POST['cancel_date'] ) ? sanitize_text_field( $_POST['cancel_date'] ) : null;
    // [FIX-EDIT-RETURN] فیلترهای صفحه‌ای که فرم ویرایش از آن باز شده، برای بازگشت بعد از ذخیره
    // [FIX-EDIT-RETURN-WAF] مقدار با Base64 ارسال شده (نگاه کنید به توضیح در render())
    $return_qs_b64_in = sanitize_text_field( $_POST['return_qs'] ?? '' );
    $return_qs = $return_qs_b64_in !== '' ? (string) base64_decode( $return_qs_b64_in, true ) : '';

    // اگر شناسه‌ها وجود ندارند، عملیات را متوقف کن
    // [FIX-CAP-04-A] تخصیص‌های pending_admin ممکن است هنوز ta_id نداشته باشند (ta_id=0)،
    // بنابراین فقط id اجباری است؛ ta_id را بعد از خواندن وضعیت فعلی بررسی می‌کنیم.
    if ( ! $id ) {
        $this->redirect_with_return('error=missing_fields', $return_qs);
    }

    // 3. دریافت اطلاعات فعلی تخصیص از دیتابیس (برای بررسی وضعیت قبلی)
    $current_assignment = TA_Database::get_row(
        "SELECT * FROM " . TA_Database::table('assignments') . " WHERE id = %d",
        [ $id ]
    );

    // اگر تخصیص وجود نداشت، متوقف شو
    if ( ! $current_assignment ) {
        $this->redirect_with_return('error=not_found', $return_qs);
    }

    // [FIX-CAP-04-A] اعتبارسنجی ta_id با در نظر گرفتن وضعیت‌های بدون استادیار:
    // تخصیص‌های pending_admin می‌توانند ta_id=0 داشته باشند؛ در غیر این صورت ta_id اجباری است.
    $non_ta_statuses = [ 'pending_admin', 'pending_admin_reviewed' ];
    $current_is_non_ta = in_array( $current_assignment->status, $non_ta_statuses, true );
    if ( ! $ta_id && ! $current_is_non_ta ) {
        $this->redirect_with_return('error=missing_fields', $return_qs);
    }

    global $wpdb;

    $old_capacity_id = (int) ( $current_assignment->capacity_id ?? 0 );
    $capacity_changed = ( $submitted_capacity_id !== $old_capacity_id );

    // 3.۱ [FIX-EDIT-CAP-02] اعتبارسنجی ظرفیت صریحاً انتخاب‌شده
    // اگر ظرفیتی انتخاب شده، باید واقعاً متعلق به استادیار جدید و همان
    // course_type/term_id تخصیص باشد (جلوگیری از دستکاری دستی فرم) و آزاد باشد —
    // مگر این‌که ادمین صراحتاً force_ignore_capacity را تأیید کرده باشد.
    $new_capacity = null;
    if ( $submitted_capacity_id ) {
        $new_capacity = TA_Database::get_row(
            "SELECT * FROM " . TA_Database::table('capacities') . "
             WHERE id = %d AND ta_id = %d AND course_type = %s AND term_id = %d",
            [ $submitted_capacity_id, $ta_id, $current_assignment->course_type, (int) $current_assignment->term_id ]
        );

        if ( ! $new_capacity ) {
            // capacity_id ارسالی معتبر نیست (متعلق به این استادیار/دوره نیست) — رد کن
            $this->redirect_with_return( 'error=invalid_capacity&assignment_id=' . $id, $return_qs );
        }

        $is_full = (int) $new_capacity->used_capacity >= (int) $new_capacity->total_capacity;
        // [FIX-CAP-04-B] بررسی پر بودن ظرفیت باید در دو حالت انجام شود:
        // ۱. ظرفیت عوض شده (capacity_changed)
        // ۲. وضعیت از بی‌ظرفیت (pending_admin/canceled) به ظرفیت‌دار (active) تغییر می‌کند
        //    (becoming_capacity_consuming) — حتی اگر capacity_id همان قبلی باشد.
        // نکته: $was_without_capacity هنوز تعریف نشده (بعد از این بلاک تعریف می‌شود)،
        // پس مستقیم بررسی می‌کنیم.
        $non_cap_statuses_check = [ 'canceled', 'pending_admin', 'pending_admin_reviewed' ];
        $prev_is_non_cap  = in_array( $current_assignment->status, $non_cap_statuses_check, true );
        $new_is_cap_consuming = ! in_array( $status, $non_cap_statuses_check, true );
        $needs_capacity_check = $capacity_changed || ( $prev_is_non_cap && $new_is_cap_consuming );
        if ( $is_full && ! $force_no_capacity && $needs_capacity_check ) {
            $this->redirect_with_return( 'error=no_capacity&assignment_id=' . $id, $return_qs );
        }
    } elseif ( ( $ta_id !== (int) $current_assignment->ta_id ) && ! $force_no_capacity ) {
        // هیچ ظرفیتی انتخاب نشده (مثلاً استادیار جدید اصلاً ظرفیتی تعریف نکرده)
        // و ادمین هم override نزده -> متوقف شو
        $this->redirect_with_return( 'error=no_capacity&assignment_id=' . $id, $return_qs );
    }

    // 4. به‌روزرسانی اطلاعات پروفایل هنرجو (جنسیت)
    if ( ! empty( $current_assignment->student_id ) ) {
        $student_id = (int) $current_assignment->student_id;
        if ( in_array( $gender, [ 'male', 'female' ], true ) ) {
            update_user_meta( $student_id, 'student_gender', $gender );
            update_user_meta( $student_id, 'gender', $gender ); // جهت حفظ سازگاری
        }
    }

    // 5. آماده‌سازی آرایه داده‌ها برای به‌روزرسانی جدول اصلی
    $update_data = [
        'ta_id'          => $ta_id,
        'messenger'      => $messenger,
        'student_gender' => $gender,
        'group_link'     => $group_link ?: null,
        'status'         => $status,
        // [FIX-EDIT-CAP-02] capacity_id همیشه از فیلد صریح فرم گرفته می‌شود
        'capacity_id'    => $submitted_capacity_id ?: 0,
    ];

    // 6. منطق پردازش وضعیت انصراف
    $was_canceled      = ( $current_assignment->status === 'canceled' );
    $becoming_canceled = ( $status === 'canceled' && ! $was_canceled );
    // [FIX-EDIT-CAP-03] بازگشت از canceled به هر وضعیت دیگر — ظرفیت باید +1 شود
    $becoming_active   = ( $was_canceled && $status !== 'canceled' );

    // [FIX-CAP-04-B] وضعیت‌هایی که ظرفیت اشغال نمی‌کنند:
    // pending_admin و pending_admin_reviewed مانند canceled هستند — هیچ‌گاه ظرفیت نگرفته‌اند.
    // این متغیرها تعیین می‌کنند آیا تغییر وضعیت باید روی used_capacity تأثیر بگذارد.
    $non_capacity_statuses     = [ 'canceled', 'pending_admin', 'pending_admin_reviewed' ];
    $was_without_capacity      = in_array( $current_assignment->status, $non_capacity_statuses, true );
    $becoming_without_capacity = in_array( $status, $non_capacity_statuses, true );
    // از وضعیت بی‌ظرفیت به وضعیت ظرفیت‌دار (active / completed) رفتن → باید +1 شود
    $becoming_capacity_consuming = ( $was_without_capacity && ! $becoming_without_capacity );
    // از وضعیت ظرفیت‌دار به وضعیت بی‌ظرفیت رفتن → باید -1 شود
    $releasing_capacity = ( ! $was_without_capacity && $becoming_without_capacity );

    if ( $status === 'canceled' ) {
        $update_data['cancel_reason'] = $cancel_reason;

        // اگر کاربر تاریخی در فرم وارد کرده، همان را ذخیره کن
        if ( $cancel_date_from_post ) {
            $update_data['cancel_date'] = $cancel_date_from_post;
        }
        // در غیر این صورت، اگر از قبل هم تاریخ انصرافی وجود نداشته، تاریخ فعلی را ثبت کن
        elseif ( empty( $current_assignment->cancel_date ) ) {
            $update_data['cancel_date'] = current_time('mysql');
        }
        // اگر نه تاریخ جدیدی وارد شده و نه اولین بار است، تاریخ قبلی دست‌نخورده باقی می‌ماند

    } else {
        // اگر وضعیت چیزی غیر از "انصراف" بود، اطلاعات انصراف را پاک کن
        $update_data['cancel_reason'] = '';
        $update_data['cancel_date']   = null;
    }

    // 7. اجرای به‌روزرسانی در دیتابیس
    TA_Database::update( 'ta_assignments', $update_data, [ 'id' => $id ] );

    // 7.۱ همگام‌سازی used_capacity — منطق کامل‌شده با پوشش همه جایگشت‌ها
    //
    // قانون اصلی:
    //   تخصیص active  → یک واحد ظرفیت اشغال می‌کند
    //   تخصیص canceled → هیچ ظرفیتی اشغال نمی‌کند
    //
    // بنابراین هر ترکیبی از (وضعیت قبل, وضعیت جدید, ظرفیت قبل, ظرفیت جدید)
    // باید دقیقاً این قانون را برقرار کند.

    if ( $capacity_changed ) {
        // ── ظرفیت عوض شده ──────────────────────────────────────────────────
        // [FIX-CAP-04-B] از ظرفیت قدیم فقط کم کن اگر تخصیص قبلاً ظرفیت اشغال
        // کرده بود (یعنی وضعیت قبلی از نوع active/completed بوده، نه canceled
        // یا pending_admin که هیچ‌گاه ظرفیت نمی‌گیرند).
        if ( $old_capacity_id && ! $was_without_capacity ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE " . TA_Database::table('capacities') . "
                 SET used_capacity = GREATEST(0, used_capacity - 1)
                 WHERE id = %d",
                [ $old_capacity_id ]
            ) );
        }

        // ظرفیت جدید را فقط اگر وضعیت نهایی ظرفیت‌دار (active/completed) است اشغال کن.
        // اگر وضعیت جدید از نوع بی‌ظرفیت (canceled/pending_admin) باشد، ثبت نکن.
        if ( $submitted_capacity_id && ! $becoming_without_capacity ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE " . TA_Database::table('capacities') . "
                 SET used_capacity = used_capacity + 1
                 WHERE id = %d",
                [ $submitted_capacity_id ]
            ) );
        }

    } else {
        // ── ظرفیت عوض نشده — فقط وضعیت یا سایر فیلدها تغییر کرده ────────

        // [FIX-CAP-04-B] از وضعیت ظرفیت‌دار → بی‌ظرفیت: ظرفیت آزاد کن.
        // این شامل: active→canceled، active→pending_admin، completed→canceled و ... می‌شود.
        if ( $releasing_capacity && $submitted_capacity_id ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE " . TA_Database::table('capacities') . "
                 SET used_capacity = GREATEST(0, used_capacity - 1)
                 WHERE id = %d",
                [ $submitted_capacity_id ]
            ) );
        }

        // [FIX-CAP-04-B] از وضعیت بی‌ظرفیت → ظرفیت‌دار: ظرفیت اشغال کن.
        // این شامل: canceled→active، pending_admin→active، pending_admin→completed و ... می‌شود.
        // (باگ B1 و B2 اصلاح شد: قبلاً فقط canceled→active پوشش داده می‌شد،
        //  و pending_admin→active اصلاً ظرفیت +1 نمی‌شد.)
        if ( $becoming_capacity_consuming && $submitted_capacity_id ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE " . TA_Database::table('capacities') . "
                 SET used_capacity = used_capacity + 1
                 WHERE id = %d",
                [ $submitted_capacity_id ]
            ) );
        }
    }

    // 8. بازگرداندن کاربر به صفحه مدیریت
    $msg = $capacity_changed && $new_capacity && (int) $new_capacity->used_capacity >= (int) $new_capacity->total_capacity && $force_no_capacity
        ? 'edited=1&capacity_forced=1'
        : 'edited=1';
    $this->redirect_with_return($msg, $return_qs);
}


    public function handle_delete(): void {
    // ۱. بررسی دسترسی‌ها و امنیت درخواست
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
    check_admin_referer( 'ta_admin_delete_assign' );

    $id = (int) ( $_POST['assignment_id'] ?? 0 );
    if ( ! $id ) {
        $this->redirect( 'error=no_id' );
        return;
    }

    // ۲. دریافت اطلاعات تخصیص از دیتابیس
    $row = TA_Database::get_row(
        "SELECT * FROM " . TA_Database::table('assignments') . " WHERE id = %d",
        [ $id ]
    );

    // اگر تخصیص پیدا نشد، عملیات متوقف شود
    if ( ! $row ) {
        $this->redirect( 'error=not_found' );
        return;
    }

    global $wpdb;

    // ۳. آزاد کردن گروه (اگر دوره خلاق بوده و گروه داشته است)
    if ( ! empty( $row->group_id ) ) {
        TA_Database::update( 'ta_groups', [
            'is_used'             => 0,
            'assigned_to_user_id' => null,
            'assigned_at'         => null,
        ], [ 'id' => $row->group_id ] );
    }

    // ۴. کاهش ظرفیت استادیار (اصلاح شده و دقیق)
    if ( ! empty( $row->capacity_id ) ) {
        // اگر capacity_id وجود داشت، ظرفیت را مستقیماً و دقیق کم کن
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . TA_Database::table('capacities') . "
             SET used_capacity = GREATEST(0, used_capacity - 1)
             WHERE id = %d",
            [ $row->capacity_id ]
        ) );
    } else {
        // روش پشتیبان برای رکوردهای قدیمی که capacity_id آن‌ها ثبت نشده (صفر است)
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . TA_Database::table('capacities') . "
             SET used_capacity = GREATEST(0, used_capacity - 1)
             WHERE ta_id = %d AND course_type = %s AND term_id = %d
               AND student_gender IN (%s, 'both')
             ORDER BY student_gender DESC LIMIT 1",
            [ $row->ta_id, $row->course_type, $row->term_id, $row->student_gender ]
        ) );
    }

    // ۵. حذف نمره مرتبط (جلوگیری از نمرات یتیم در سیستم)
    if ( class_exists('TA_Grade') ) {
        $grade_exists = TA_Grade::get_grade( $row->student_id, $row->product_id );
        if ( $grade_exists ) {
            // [FIX-10] نام جدول اصلاح شد: 'grades' → 'ta_grades' (قبلاً به جدول اشتباه می‌رفت)
            TA_Database::delete( 'ta_grades', [
                'student_id' => $row->student_id,
                'product_id' => $row->product_id
            ] );
        }
    }

    // ۶. حذف رکورد تخصیص از دیتابیس
    TA_Database::delete( 'ta_assignments', [ 'id' => $id ] );
    
    // بازگشت با پیام موفقیت
    $this->redirect( 'deleted=1' );
}




    // ════════════════════════════════════════════════════════════════
    // EXPORT XLSX (با TA_Xlsx_Writer — بدون نیاز به کتابخانه خارجی)
    // ════════════════════════════════════════════════════════════════

    public function export_xlsx(): void {
        global $wpdb;

        $assign_table = TA_Database::table( 'assignments' );
        $ta_table     = TA_Database::table( 'assistants' );
        $terms_table  = TA_Database::table( 'terms' );

        // ── همان فیلترهای صفحه ──────────────────────────────────────────────
        $search        = sanitize_text_field( $_GET['s']             ?? '' );
        $f_term        = (int)  ( $_GET['f_term']                    ?? 0 );
        $f_course      = sanitize_text_field( $_GET['f_course']      ?? '' );
        $f_status      = sanitize_text_field( $_GET['f_status']      ?? '' );
        $filter_ta     = sanitize_text_field( $_GET['filter_ta']     ?? '' );
        $filter_ta_id  = (int)  ( $_GET['filter_ta_id']              ?? 0 );
        $filter_gender = sanitize_text_field( $_GET['filter_gender'] ?? '' );

        $where  = [ '1=1' ];
        $params = [];

        if ( $search ) {
            $like    = '%' . $wpdb->esc_like( $search ) . '%';
            $all_ids = array_unique( array_merge(
                (array) $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT user_id FROM {$wpdb->usermeta}
                     WHERE meta_key IN ('kerasno-phone','phone','billing_phone') AND meta_value LIKE %s", $like ) ),
                (array) $wpdb->get_col( $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->users} WHERE display_name LIKE %s OR user_email LIKE %s", $like, $like ) )
            ) );
            $where[] = $all_ids
                ? 'a.student_id IN (' . implode( ',', array_map( 'intval', $all_ids ) ) . ')'
                : '1=0';
        }
        if ( $f_term )        { $where[] = 'a.term_id = %d';        $params[] = $f_term; }
        if ( $f_course )      { $where[] = 'a.course_type = %s';    $params[] = $f_course; }
        if ( $f_status )      { $where[] = 'a.status = %s';         $params[] = $f_status; }
        if ( $filter_gender ) { $where[] = 'a.student_gender = %s'; $params[] = $filter_gender; }
        if ( $filter_ta === 'unassigned' ) {
            $where[] = '(a.ta_id IS NULL OR a.ta_id = 0 OR ta.id IS NULL)';
        } elseif ( $filter_ta_id > 0 ) {
            $where[] = 'ta.id = %d'; $params[] = $filter_ta_id;
        }

        $where_sql = implode( ' AND ', $where );
        $sql = "SELECT a.*, u.display_name AS student_name, u.user_email AS student_email,
                       tu.display_name AS ta_name, t.name AS term_name,
                       p.post_title AS product_name,
                       ta.ta_code AS ta_code
                FROM {$assign_table} a
                LEFT JOIN {$ta_table}    ta ON ta.id   = a.ta_id
                JOIN {$wpdb->users}      u  ON u.ID    = a.student_id
                LEFT JOIN {$wpdb->users} tu ON tu.ID   = ta.user_id
                LEFT JOIN {$terms_table} t  ON t.id    = a.term_id
                LEFT JOIN {$wpdb->posts} p  ON p.ID    = a.product_id
                WHERE {$where_sql}
                ORDER BY a.created_at DESC";

        $rows = $params
            ? ( $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?: [] )
            : ( $wpdb->get_results( $sql ) ?: [] );

        $course_labels = [
            'creative'      => 'نویسندگی خلاق',
            'intro'         => 'نویسندگی مقدماتی',
            'basic_writing' => 'نویسندگی پایه',
            'advanced'      => 'نویسندگی پیشرفته',
            'critique'      => 'نقد اثر',
            'ladder'        => 'نردبان حرفه‌ای',
            'professional'  => 'نویسندگی حرفه‌ای',
        ];
        $status_labels = [
            'active'        => 'فعال',
            'canceled'      => 'لغو‌شده',
            'completed'     => 'تکمیل‌شده',
            'pending_admin' => 'در انتظار ادمین',
        ];
        $gender_labels = [ 'male' => 'آقا', 'female' => 'خانم', 'both' => 'هر دو', 'unknown' => '-' ];
        $msg_labels    = [ 'telegram' => 'تلگرام', 'whatsapp' => 'واتساپ', 'eitaa' => 'ایتا', 'bale' => 'بله', 'both' => 'همه' ];

        // ── دریافت شماره تلفن هنرجویان ──────────────────────────────────────
        $student_ids = array_filter( array_unique( array_column( (array) $rows, 'student_id' ) ) );
        $phones_map  = [];
        if ( $student_ids ) {
            $id_list    = implode( ',', array_map( 'intval', $student_ids ) );
            $phone_rows = $wpdb->get_results(
                "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta}
                 WHERE user_id IN ({$id_list})
                   AND meta_key IN ('kerasno-phone','phone','Mreeir_phone','billing_phone')"
            );
            $priority = [ 'kerasno-phone' => 1, 'phone' => 2, 'Mreeir_phone' => 3, 'billing_phone' => 4 ];
            foreach ( $phone_rows as $pr ) {
                $uid  = (int) $pr->user_id;
                $prio = $priority[ $pr->meta_key ] ?? 99;
                if ( $pr->meta_value && ( ! isset( $phones_map[ $uid ] ) || $prio < ( $phones_map[ $uid ]['p'] ?? 99 ) ) ) {
                    $phones_map[ $uid ] = [ 'v' => $pr->meta_value, 'p' => $prio ];
                }
            }
            $phones_map = array_map( fn($x) => $x['v'], $phones_map );
        }

        // ── پاک کردن output buffer قبل از ارسال headers ─────────────────────
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        // ── ساخت XLSX با TA_Xlsx_Writer (بدون dependency خارجی) ─────────────
        $writer = new TA_Xlsx_Writer();
        $writer->set_rtl( true );

        // ردیف هدر
        $writer->add_row(
            [ 'ردیف', 'نام هنرجو', 'شماره همراه', 'ایمیل', 'جنسیت',
              'نوع دوره', 'محصول', 'ترم', 'استادیار', 'پیامرسان',
              'وضعیت', 'لینک گروه', 'تاریخ ثبت' ],
            true   // is_header
        );

        // ردیف‌های داده
        foreach ( $rows as $i => $r ) {
            $phone = $phones_map[ (int) $r->student_id ] ?? '';
            $writer->add_row( [
                $i + 1,
                $r->student_name ?? '',
                $phone,                                                    // شماره همراه — text
                $r->student_email ?? '',
                $gender_labels[ $r->student_gender ] ?? $r->student_gender,
                $course_labels[ $r->course_type ]    ?? $r->course_type,
                $r->product_name ?? '',
                $r->term_name    ?? '',
                $r->ta_name      ?? 'بدون استادیار',
                $msg_labels[ $r->messenger ] ?? $r->messenger,
                $status_labels[ $r->status ] ?? $r->status,
                $r->group_link  ?? '',
                $r->created_at  ?? '',
            ], false, true ); // force_text=true تا شماره تلفن‌ها صحیح باشند
        }

        $filename = 'assignments-' . date( 'Y-m-d' ) . '.xlsx';
        $writer->output( $filename );
    }

    // ════════════════════════════════════════════════════════════════
    // RENDER
    // ════════════════════════════════════════════════════════════════

    public function render(): void {
        if ( ! TA_Database::tables_exist() ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>جداول پایگاه داده ایجاد نشده‌اند.</p></div></div>';
            return;
        }

        global $wpdb;
        $this->enqueue_admin_scripts();

        // پارامترهای فیلتر
        $search   = sanitize_text_field( $_GET['s'] ?? '' );
        $f_term   = (int) ( $_GET['f_term'] ?? 0 );
        $f_course = sanitize_text_field( $_GET['f_course'] ?? '' );
        $f_status = sanitize_text_field( $_GET['f_status'] ?? '' );
        $filter_ta     = sanitize_text_field( $_GET['filter_ta'] ?? '' );
        $filter_ta_id  = (int) ( $_GET['filter_ta_id'] ?? 0 );

        $filter_gender = sanitize_text_field($_GET['filter_gender'] ?? '');
        $paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per_page = 200;

        $assign_table = TA_Database::table( 'assignments' );
        $ta_table     = TA_Database::table( 'assistants' );
        $terms_table  = TA_Database::table( 'terms' );
        $join_logs_table = TA_Database::table( 'group_join_logs' );

        // ── ساخت WHERE ───────────────────────────────────────────────────────
        [ $where, $params ] = $this->build_assignments_where( [
            's'             => $search,
            'f_term'        => $f_term,
            'f_course'      => $f_course,
            'f_status'      => $f_status,
            'filter_gender' => $filter_gender,
            'filter_ta'     => $filter_ta,
            'filter_ta_id'  => $filter_ta_id,
        ] );

        $where_sql = implode( ' AND ', $where );

       
        // [GROUP-REVIEW] وضعیت ورود واقعی هر طرف (هنرجو/استادیار/ادمین) به گروه —
        // بر اساس کلیک‌های ثبت‌شده در ta_group_join_logs برای هر تخصیص.
        $join_agg_sql = "SELECT assignment_id,
                                 MAX( actor_type = 'student' ) AS student_joined,
                                 MAX( actor_type = 'ta' )      AS ta_joined,
                                 MAX( actor_type = 'admin' )   AS admin_joined
                          FROM {$join_logs_table}
                          GROUP BY assignment_id";

        $base_sql = "FROM {$assign_table} a
             LEFT JOIN {$ta_table}    ta  ON ta.id  = a.ta_id
             JOIN {$wpdb->users}      u   ON u.ID   = a.student_id
             LEFT JOIN {$wpdb->users} tu  ON tu.ID  = ta.user_id
             LEFT JOIN {$terms_table} t   ON t.id   = a.term_id
             LEFT JOIN ( {$join_agg_sql} ) jl ON jl.assignment_id = a.id
             WHERE {$where_sql}";


        $total = (int) $wpdb->get_var(
            $params
                ? $wpdb->prepare( "SELECT COUNT(*) {$base_sql}", $params )
                : "SELECT COUNT(*) {$base_sql}"
        );

        $offset = ( $paged - 1 ) * $per_page;
        // [GROUP-REVIEW] گروه‌بندی صفحه:
        //   ۱) هنرجو وارد نشده   ۲) استادیار وارد نشده   ۳) ادمین وارد نشده
        //   ۴) همه وارد شده‌اند   ۵) بررسی‌شده (صرف‌نظر از وضعیت ورود، همیشه آخر)
        $group_rank_expr = "CASE
                WHEN a.admin_reviewed = 1 THEN 5
                WHEN COALESCE(jl.student_joined,0) = 0 THEN 1
                WHEN COALESCE(jl.ta_joined,0)      = 0 THEN 2
                WHEN COALESCE(jl.admin_joined,0)   = 0 THEN 3
                ELSE 4
            END";
        $select_sql = "SELECT a.*, u.display_name AS student_name, u.user_email AS student_email,
                              tu.display_name AS ta_name, t.name AS term_name,
                              ta.ta_code AS ta_code,
                              COALESCE(jl.student_joined,0) AS student_joined,
                              COALESCE(jl.ta_joined,0)      AS ta_joined,
                              COALESCE(jl.admin_joined,0)   AS admin_joined,
                              {$group_rank_expr} AS group_rank
                       {$base_sql}
                       ORDER BY group_rank ASC, a.created_at DESC LIMIT {$per_page} OFFSET {$offset}";

        $rows = $params
            ? ( $wpdb->get_results( $wpdb->prepare( $select_sql, $params ) ) ?: [] )
            : ( $wpdb->get_results( $select_sql ) ?: [] );

        // [GROUP-REVIEW] محافظ: اگر کوئری گروه‌بندی‌شده (مثلاً به‌خاطر نبود ستون admin_reviewed
        // روی هاست‌هایی با محدودیت ALTER) خطا داد، به‌جای نمایش خاموش «نتیجه‌ای یافت نشد»،
        // به کوئری ساده‌ی قدیمی (بدون گروه‌بندی) برمی‌گردیم و یک اعلان به ادمین نشان می‌دهیم.
        $ta_grouping_error = '';
        if ( $wpdb->last_error && empty( $rows ) && $total > 0 ) {
            $ta_grouping_error = $wpdb->last_error;
            $fallback_sql = "SELECT a.*, u.display_name AS student_name, u.user_email AS student_email,
                                    tu.display_name AS ta_name, t.name AS term_name,
                                    ta.ta_code AS ta_code, 0 AS student_joined, 0 AS ta_joined,
                                    0 AS admin_joined, 4 AS group_rank
                             FROM {$assign_table} a
                             LEFT JOIN {$ta_table}    ta  ON ta.id  = a.ta_id
                             JOIN {$wpdb->users}      u   ON u.ID   = a.student_id
                             LEFT JOIN {$wpdb->users} tu  ON tu.ID  = ta.user_id
                             LEFT JOIN {$terms_table} t   ON t.id   = a.term_id
                             WHERE {$where_sql}
                             ORDER BY a.created_at DESC LIMIT {$per_page} OFFSET {$offset}";
            $rows = $params
                ? ( $wpdb->get_results( $wpdb->prepare( $fallback_sql, $params ) ) ?: [] )
                : ( $wpdb->get_results( $fallback_sql ) ?: [] );
        }

        // — داده‌های فرم‌ها —
        $terms = TA_Database::get_results( "SELECT * FROM {$terms_table} ORDER BY order_index DESC" );
        $all_tas = $wpdb->get_results(
            "SELECT ta.id, ta.messengers, u.display_name
             FROM {$ta_table} ta JOIN {$wpdb->users} u ON u.ID = ta.user_id
             WHERE ta.is_active = 1 ORDER BY ta.rank DESC"
        ) ?: [];
        $products = function_exists('wc_get_products') ? wc_get_products( [ 'limit' => -1, 'status' => 'publish', 'orderby' => 'title' ] ) : [];

                        $course_labels = [ 'creative' => 'خلاق', 'critique' => 'نقد اثر', 'intro' => 'مقدماتی', 'basic_writing' => 'نویسندگی پایه', 'advanced' => 'پیشرفته', 'professional' => 'حرفه‌ای', 'ladder' => 'نردبان حرفه‌ای' ];


                $m_labels      = [ 'telegram' => 'تلگرام', 'bale' => 'بله', 'eitaa' => 'ایتا' ,'both'=>'همه'];

        $total_pages   = ceil( $total / $per_page );

        $base_url = admin_url( 'admin.php?page=ta-assignments' );

        // [FIX-EDIT-RETURN] رشته‌ی کوئری فیلترها/جستجو/صفحه‌بندی فعلی، برای اینکه بعد از
        // ویرایش یک تخصیص، ادمین به همین صفحه (با همان فیلترها) برگردد نه به صفحه‌ی اول.
        $return_qs = http_build_query( array_filter( [
            's'             => $search,
            'f_term'        => $f_term,
            'f_course'      => $f_course,
            'f_status'      => $f_status,
            'filter_ta'     => $filter_ta,
            'filter_ta_id'  => $filter_ta_id,
            'filter_gender' => $filter_gender,
            'paged'         => $paged,
        ] ) );
        // [FIX-EDIT-RETURN-WAF] روی برخی هاست‌ها فایروال (مثل ModSecurity/Imunify360) رشته‌ی
        // queryای که از قبل URL-encode شده (و دوباره ضمن ارسال فرم encode می‌شود → "%25...")
        // را به‌عنوان حملهٔ double-encoding تشخیص داده و حذف می‌کند. برای دور زدن این مشکل،
        // مقدار را با Base64 (بدون کاراکتر %) منتقل می‌کنیم.
        $return_qs_b64 = base64_encode( $return_qs );
        ?>
        <div class="wrap" dir="rtl">
            <h1 style="display:flex;align-items:center;gap:16px">
                تخصیص‌ها
                <button class="button button-primary" onclick="taOpenAssignModal()">➕ تخصیص تکی جدید</button>
                <button class="button button-secondary" onclick="taOpenCapacityAssignModal()" style="margin-right:8px">🎯 تخصیص بر اساس ظرفیت استادیار</button>

            </h1>

            <?php $this->render_notices(); ?>

            <?php if ( $ta_grouping_error ) : ?>
            <div class="notice notice-warning is-dismissible">
                <p>⚠️ گروه‌بندی صفحه به‌طور موقت غیرفعال شد (خطای دیتابیس). لیست به حالت ساده نمایش داده می‌شود. جزئیات فنی: <code><?= esc_html( $ta_grouping_error ) ?></code></p>
            </div>
            <?php endif; ?>

            <style>
            #ta-filter-form select:focus,
            #ta-filter-form input:focus { outline:2px solid #0073aa; border-color:#0073aa; }
            .widefat tbody tr:hover td { background:#f8fafe !important; }
            .widefat tbody tr { transition: background .12s; }
            .ta-row-canceled td { opacity:.7; background:#fff5f5 !important; }
            .ta-row-completed td { background:#f9f9f9 !important; }
            .ta-row-pending_admin td { background:#fffbeb !important;  }

            /* [GROUP-REVIEW] رنگ‌بندی گروه‌های ورود به گروه در صفحه‌ی تخصیص‌ها */
            .ta-group-header td { padding:8px 14px !important; font-weight:700; font-size:0.85rem; border-top:2px solid rgba(0,0,0,.06) !important; }
            .ta-group-header.g1 td { background:#fee2e2 !important; color:#991b1b; }
            .ta-group-header.g2 td { background:#ffedd5 !important; color:#9a3412; }
            .ta-group-header.g3 td { background:#dbeafe !important; color:#1e3a8a; }
            .ta-group-header.g4 td { background:#d1fae5 !important; color:#065f46; }
            .ta-group-header.g5 td { background:#f3f4f6 !important; color:#374151; }
            .ta-row-g1 td { background:#fef2f2 !important; }
            .ta-row-g2 td { background:#fffaf0 !important; }
            .ta-row-g3 td { background:#eff6ff !important; }
            .ta-row-g4 td { background:#f0fdf4 !important; }
            .ta-row-g5 td { background:#f9fafb !important; opacity:.85; }
            .ta-row-g1.ta-row-canceled td,
            .ta-row-g2.ta-row-canceled td,
            .ta-row-g3.ta-row-canceled td,
            .ta-row-g4.ta-row-canceled td,
            .ta-row-g5.ta-row-canceled td { background:#fff5f5 !important; opacity:.7; }
            .ta-reviewed-checkbox { width:18px; height:18px; cursor:pointer; }
            </style>

            <!-- ── فیلتر ─────────────────────────────────────────────────── -->
            <?php
            // تعداد فیلترهای فعال
            $active_filters = array_filter([
                $search, $f_term, $f_course, $f_status, $filter_gender,
                $filter_ta === 'unassigned' ? 'unassigned' : '', $filter_ta_id
            ]);
            $has_active_filter = !empty($active_filters);
            ?>
            <form method="get" id="ta-filter-form" style="background:#fff;padding:16px 20px;border:1px solid #ddd;border-radius:8px;margin:16px 0;box-shadow:0 1px 3px rgba(0,0,0,.06)">
                <input type="hidden" name="page" value="ta-assignments">

                <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end">

                    <!-- جستجو -->
                    <div style="flex:1;min-width:200px">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#555;margin-bottom:4px">🔍 جستجو (نام / شماره / ایمیل)</label>
                        <input type="text" name="s" value="<?= esc_attr( $search ) ?>"
                               placeholder="مثال: ۰۹۱۲..."
                               style="width:100%;padding:6px 10px;border:1px solid #ccc;border-radius:5px;font-size:0.9rem"
                               autocomplete="off">
                    </div>

                    <!-- استادیار -->
                    <div style="min-width:160px">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#555;margin-bottom:4px">👨‍🏫 استادیار</label>
                        <select name="filter_ta_id"
                                style="width:100%;padding:6px 8px;border:1px solid <?= $filter_ta_id ? '#0073aa' : '#ccc' ?>;border-radius:5px;<?= $filter_ta_id ? 'background:#f0f7ff;font-weight:600;color:#0073aa' : '' ?>">
                            <option value="">همه استادیاران</option>
                            <?php foreach ( $all_tas as $ta ) : ?>
                                <option value="<?= $ta->id ?>" <?= selected( $filter_ta_id, $ta->id, false ) ?>>
                                    <?= esc_html( $ta->display_name ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- ترم -->
                    <div style="min-width:130px">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#555;margin-bottom:4px">📅 ترم</label>
                        <select name="f_term"
                                style="width:100%;padding:6px 8px;border:1px solid <?= $f_term ? '#0073aa' : '#ccc' ?>;border-radius:5px;<?= $f_term ? 'background:#f0f7ff;font-weight:600;color:#0073aa' : '' ?>">
                            <option value="">همه</option>
                            <?php foreach ( $terms as $t ) : ?>
                                <option value="<?= $t->id ?>" <?= selected( $f_term, $t->id, false ) ?>><?= esc_html( $t->name ) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- نوع دوره -->
                    <div style="min-width:130px">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#555;margin-bottom:4px">📚 نوع دوره</label>
                        <select name="f_course"
                                style="width:100%;padding:6px 8px;border:1px solid <?= $f_course ? '#0073aa' : '#ccc' ?>;border-radius:5px;<?= $f_course ? 'background:#f0f7ff;font-weight:600;color:#0073aa' : '' ?>">
                            <option value="">همه</option>
                            <?php foreach ( $course_labels as $v => $l ) : ?>
                                <option value="<?= $v ?>" <?= selected( $f_course, $v, false ) ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- وضعیت -->
                    <div style="min-width:110px">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#555;margin-bottom:4px">🔖 وضعیت</label>
                        <select name="f_status"
                                style="width:100%;padding:6px 8px;border:1px solid <?= $f_status ? '#0073aa' : '#ccc' ?>;border-radius:5px;<?= $f_status ? 'background:#f0f7ff;font-weight:600;color:#0073aa' : '' ?>">
                            <option value="">همه</option>
                            <option value="active"        <?= selected( $f_status, 'active',        false ) ?>>✅ فعال</option>
                            <option value="completed"     <?= selected( $f_status, 'completed',     false ) ?>>🏁 تکمیل</option>
                            <option value="canceled"      <?= selected( $f_status, 'canceled',      false ) ?>>❌ انصراف</option>
                            <option value="pending_admin" <?= selected( $f_status, 'pending_admin', false ) ?>>⏳ در انتظار ادمین</option>
                        </select>
                    </div>

                    <!-- جنسیت -->
                    <div style="min-width:110px">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#555;margin-bottom:4px">⚧ جنسیت</label>
                        <select name="filter_gender"
                                style="width:100%;padding:6px 8px;border:1px solid <?= $filter_gender ? '#0073aa' : '#ccc' ?>;border-radius:5px;<?= $filter_gender ? 'background:#f0f7ff;font-weight:600;color:#0073aa' : '' ?>">
                            <option value="">همه</option>
                            <option value="male"   <?= selected( $filter_gender, 'male',   false ) ?>>👨 آقا</option>
                            <option value="female" <?= selected( $filter_gender, 'female', false ) ?>>👩 خانم</option>
                        </select>
                    </div>

                    <!-- دکمه‌های عملیات -->
                    <div style="display:flex;gap:8px;align-items:flex-end;padding-bottom:1px">
                        <button type="submit" class="button button-primary" style="padding:6px 16px;height:34px">
                            🔎 اعمال فیلتر
                        </button>
                        <?php if ( $has_active_filter ) : ?>
                        <a href="<?= $base_url ?>" class="button button-secondary" style="padding:6px 12px;height:34px;background:#fff3cd;border-color:#ffc107;color:#856404" title="پاک‌کردن همه فیلترها">
                            ✕ پاک‌کردن
                        </a>
                        <?php else : ?>
                        <a href="<?= $base_url ?>" class="button" style="padding:6px 12px;height:34px">پاک‌کردن</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- دکمه نمایش بدون استاد در ردیف جداگانه -->
                <div style="margin-top:10px;padding-top:10px;border-top:1px dashed #eee;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <a href="<?= esc_url( admin_url('admin.php?page=ta-assignments&filter_ta=unassigned') ) ?>"
                       class="button <?= $filter_ta === 'unassigned' ? 'button-primary' : 'button-secondary' ?>"
                       style="<?= $filter_ta === 'unassigned' ? '' : 'background:#fff8e1;border-color:#ffa000;color:#c17900' ?>">
                        ⚠️ تکالیف بدون استادیار
                    </a>
                    <?php
                    // ── ساخت URL اکسل با فیلترهای فعلی ─────────────────────
                    $export_params = array_merge(
                        $_GET,
                        [
                            'action'   => 'export_xlsx',
                            '_wpnonce' => wp_create_nonce( 'ta_export_assignments_xlsx' ),
                        ]
                    );
                    unset( $export_params['paged'] );
                    $export_url = admin_url( 'admin.php?' . http_build_query( $export_params ) );
                    ?>

                    <?php if ( $has_active_filter ) : ?>
                    <small style="color:#888;font-size:0.78rem">
                        <?= $total ?> نتیجه با فیلترهای فعال
                        <?php if ( $filter_ta_id ) :
                            $active_ta_name = '';
                            foreach ( $all_tas as $ta ) { if ( $ta->id == $filter_ta_id ) { $active_ta_name = $ta->display_name; break; } }
                        ?>
                        — استادیار: <strong><?= esc_html( $active_ta_name ) ?></strong>
                        <?php endif; ?>
                    </small>
                    <?php endif; ?>

                    <!-- دکمه خروجی اکسل -->
                    <a href="<?= esc_url( $export_url ) ?>"
                       class="button"
                       style="background:linear-gradient(135deg,#217346,#2e8b57);color:#fff;border:none;border-radius:6px;padding:6px 14px;display:inline-flex;align-items:center;gap:6px;font-weight:600;text-decoration:none;box-shadow:0 2px 6px rgba(33,115,70,.3);transition:all .2s"
                       title="<?= $has_active_filter ? 'خروجی اکسل از ' . $total . ' نتیجه فیلترشده' : 'خروجی اکسل از همه تخصیص‌ها' ?>">
                        📊 خروجی اکسل
                        <?php if ( $has_active_filter ) : ?>
                            <span style="background:rgba(255,255,255,.25);border-radius:10px;padding:1px 7px;font-size:.78rem"><?= number_format($total) ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </form>

            <!-- ── جدول تخصیص‌ها ─────────────────────────────────────────── -->
            <p style="color:#636e72;font-size:0.85rem;margin:8px 0">
                مجموع: <strong style="color:#2d3436"><?= number_format($total) ?></strong> تخصیص
                <?php if ( $has_active_filter ) : ?>
                <span style="margin-right:10px;padding:2px 8px;background:#dff0ff;border:1px solid #b3d7ff;border-radius:10px;font-size:0.78rem;color:#0073aa">فیلتر فعال</span>
                <?php endif; ?>
            </p>

            <!-- [GROUP-REVIEW] بررسی دسته‌جمعی همه‌ی نتایج فیلتر فعلی -->
            <div style="margin:8px 0 14px">
                <button type="button"
                        id="ta-bulk-mark-reviewed-btn"
                        class="button"
                        style="background:#ecfdf5;border-color:#10b981;color:#065f46;font-weight:600"
                        data-total="<?= (int) $total ?>"
                        data-s="<?= esc_attr( $search ) ?>"
                        data-f-term="<?= (int) $f_term ?>"
                        data-f-course="<?= esc_attr( $f_course ) ?>"
                        data-f-status="<?= esc_attr( $f_status ) ?>"
                        data-filter-gender="<?= esc_attr( $filter_gender ) ?>"
                        data-filter-ta="<?= esc_attr( $filter_ta ) ?>"
                        data-filter-ta-id="<?= (int) $filter_ta_id ?>">
                    ✅ بررسی همه‌ی این فیلتر (<?= number_format( $total ) ?> مورد)
                </button>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="30">#</th>
                        <th>هنرجو</th>
                       <th width="70">جنسیت</th>


                        <th width="120">شماره همراه</th>
                        <th>استادیار</th>
                        <th width="100">ترم</th>
                        <th width="80">دوره</th>
                        <th width="80">پیامرسان</th>
                        <th>لینک گروه</th>
                        <th>عنوان گروه</th>
                        <th width="70">وضعیت</th>
                        <th width="150">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="12" style="text-align:center;padding:40px 24px;color:#aaa">
                        <div style="font-size:2rem;margin-bottom:8px">🔍</div>
                        <div style="font-size:1rem;color:#888">نتیجه‌ای یافت نشد.</div>
                        <?php if ( $has_active_filter ) : ?>
                        <div style="margin-top:8px"><a href="<?= $base_url ?>" style="color:#0073aa;font-size:0.85rem">پاک‌کردن فیلترها</a></div>
                        <?php endif; ?>
                    </td></tr>
                <?php endif; ?>
                <?php
                // [GROUP-REVIEW] عناوین گروه‌ها برای ردیف‌های سرگروه
                $ta_group_titles = [
                    1 => [ '🔴', 'هنرجو وارد نشده' ],
                    2 => [ '🟠', 'استادیار وارد نشده' ],
                    3 => [ '🔵', 'ادمین وارد نشده' ],
                    4 => [ '🟢', 'همه وارد شده‌اند' ],
                    5 => [ '⚪', 'بررسی‌شده' ],
                ];
                $ta_prev_group = null;
                ?>
                <?php foreach ( $rows as $row ) :
                    $phone = class_exists('TA_User_Helper')
    ? TA_User_Helper::get_phone( $row->student_id )
    : (
        get_user_meta($row->student_id, 'kerasno-phone',  true) ?:
        get_user_meta($row->student_id, 'phone',          true) ?:
        get_user_meta($row->student_id, 'Mreeir_phone',   true) ?:
        get_user_meta($row->student_id, 'billing_phone',  true) ?:
        ''
    );
                    $row_class = $row->status === 'canceled' ? 'ta-row-canceled' : ( $row->status === 'completed' ? 'ta-row-completed' : ( $row->status === 'pending_admin' ? 'ta-row-pending_admin' : '' ) );
                    $ta_group = (int) $row->group_rank;
                    $row_class .= ' ta-row-g' . $ta_group;

                    if ( $ta_group !== $ta_prev_group ) :
                        $ta_prev_group = $ta_group;
                        [ $g_icon, $g_label ] = $ta_group_titles[ $ta_group ] ?? [ '', '' ];
                    ?>
                    <tr class="ta-group-header g<?= $ta_group ?>">
                        <td colspan="12"><?= $g_icon ?> <?= esc_html( $g_label ) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="<?= $row_class ?>">
                        <td style="color:#bbb"><?= $row->id ?></td>
                        <td>
                            <strong><?= esc_html( $row->student_name ) ?></strong><br>
                            <small style="color:#888"><?= esc_html( $row->student_email ) ?></small>
                        </td>
                         <td>
        <?php 
            if ( $row->student_gender === 'male' ) {
                echo 'آقا';
            } elseif ( $row->student_gender === 'female' ) {
                echo 'خانم';
            } else {
                echo '—';
            }
        ?>
    </td>
    
                        <td style="direction:ltr;text-align:left"><?= esc_html( $phone ?: '—' ) ?></td>
                        <td>
                            <?php if ( $row->ta_name ) : ?>
                            <a href="<?= esc_url( add_query_arg( ['page'=>'ta-assignments','filter_ta_id'=>$row->ta_id], admin_url('admin.php') ) ) ?>"
                               title="فیلتر بر اساس این استادیار"
                               style="color:#0073aa;text-decoration:none;font-size:0.9rem"
                               onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
                                <?= esc_html( $row->ta_name ) ?>
                            </a>
                            <?php elseif ( $row->status === 'pending_admin' ) : ?>
                            <span style="background:#fef3c7;color:#92400e;border-radius:6px;padding:2px 8px;font-size:0.78rem">⏳ منتظر تخصیص</span>
                            <?php else : ?>
                            <span style="color:#e17055;font-size:0.82rem">⚠️ بدون استاد</span>
                            <?php endif; ?>
                        </td>
                        <td><?= esc_html( $row->term_name ?? '—' ) ?></td>
                        <td><?= $course_labels[ $row->course_type ] ?? $row->course_type ?></td>
                        <td><?= $m_labels[ $row->messenger ] ?? esc_html( $row->messenger ?? '—' ) ?></td>
                        <td>
                            <?php if ( $row->group_link ) : ?>
                                <a href="<?= esc_url( TA_URL_Helper::build_join_url( (int) $row->id ) ) ?>" target="_blank" style="font-size:0.8rem">مشاهده</a>
                                <br>
                                <span class="ta-copy-cell"
                                      data-copy="<?= esc_attr( $row->group_link ) ?>"
                                      title="کلیک برای کپی"
                                      style="font-size:0.72rem;color:#6b7280;word-break:break-all;max-width:160px;display:inline-block;direction:ltr;cursor:pointer;border-bottom:1px dashed #d1d5db"
                                ><?= esc_html( $row->group_link ) ?></span>
                            <?php else : ?>
                                <span style="color:#bbb">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $group_title = '';
                            if ( ! empty( $row->ta_code ) && ! empty( $row->student_name ) ) {
                                $name_parts  = explode( ' ', trim( $row->student_name ), 2 );
                                $first_name  = $name_parts[0] ?? '';
                                $last_name   = $name_parts[1] ?? '';
                                $season_code = $this->term_name_to_season_code( $row->term_name ?? '' );
                                $cl          = $this->course_type_to_letter( $row->course_type ?? '' );
                                $group_title = trim( "{$first_name} {$last_name}" ) . " _ " . $this->to_persian_digits( (string) $row->ta_code ) . " {$cl} {$season_code}";
                            }
                            if ( $group_title ) : ?>
                                <span class="ta-copy-cell"
                                      data-copy="<?= esc_attr( $group_title ) ?>"
                                      title="کلیک برای کپی"
                                      style="font-size:0.82rem;font-family:monospace;background:#f5f3ff;border:1px dashed #a78bfa;padding:3px 8px;border-radius:4px;cursor:pointer;display:inline-block;direction:rtl;color:#5b21b6"
                                ><?= esc_html( $group_title ) ?></span>
                            <?php elseif ( empty( $row->ta_code ) ) : ?>
                                <span style="color:#bbb;font-size:0.78rem">کد استادیار ندارد</span>
                            <?php else : ?>
                                <span style="color:#bbb">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $row->status === 'active' ) : ?>
                                <span style="background:#d1fae5;color:#065f46;border-radius:20px;padding:2px 10px;font-size:0.78rem;font-weight:600">● فعال</span>
                            <?php elseif ( $row->status === 'canceled' ) : ?>
                                <span style="background:#fee2e2;color:#991b1b;border-radius:20px;padding:2px 10px;font-size:0.78rem" title="<?= esc_attr($row->cancel_reason ?? '') ?>">✕ انصراف</span>
                            <?php elseif ( $row->status === 'pending_admin' ) : ?>
                                <span style="background:#fef3c7;color:#92400e;border-radius:20px;padding:2px 10px;font-size:0.78rem;font-weight:600">⏳ انتظار ادمین</span>
                            <?php else : ?>
                                <span style="background:#f3f4f6;color:#374151;border-radius:20px;padding:2px 10px;font-size:0.78rem">🏁 تکمیل</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <label style="display:flex;align-items:center;gap:5px;margin-bottom:6px;font-size:11px;color:#555;cursor:pointer">
                                <input type="checkbox"
                                       class="ta-reviewed-checkbox"
                                       data-assignment-id="<?= (int) $row->id ?>"
                                       <?= ! empty( $row->admin_reviewed ) ? 'checked' : '' ?>>
                                بررسی شد
                            </label>
                            <button class="button button-small"
                                    onclick="taOpenEditModal(<?= $row->id ?>)">
                                ✏️
                            </button>
                            <button type="button"
                                    class="button button-small ta-view-join-log"
                                    style="margin-top:4px;font-size:11px;"
                                    data-assignment-id="<?= (int) $row->id ?>"
                                    data-student-name="<?= esc_attr( $row->student_name ) ?>"
                                    data-product-title="<?= esc_attr( get_the_title( $row->product_id ) ) ?>"
                            >
                                📋 لاگ ورود به گروه
                            </button>
                            <button type="button" class="button button-small button-link-delete ta-delete-assignment-ajax"
                                    data-assignment-id="<?= (int) $row->id ?>" title="حذف تخصیص">🗑️</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div id="ta-assignment-delete-notice" style="display:none;margin-top:12px"></div>
            <script>
            jQuery(function($){
                $(document).on('click','.ta-delete-assignment-ajax',function(){
                    var btn=$(this), id=btn.data('assignment-id');
                    if(!window.confirm('حذف تخصیص؟')) return;
                    btn.prop('disabled',true).text('⏳');
                    $.post(ajaxurl,{action:'ta_admin_delete_assign',nonce:window.taAdminNonce||'',assignment_id:id})
                    .done(function(res){
                        if(res.success){btn.closest('tr').fadeOut(220,function(){$(this).remove()});}
                        else {alert(res.data&&res.data.message?res.data.message:'حذف تخصیص انجام نشد.');btn.prop('disabled',false).text('🗑️');}
                    }).fail(function(){alert('ارتباط با سرور برقرار نشد.');btn.prop('disabled',false).text('🗑️');});
                });
            });
            </script>
            <?php TA_Admin_Customers::render_login_log_modal(); ?>

            <!-- pagination -->
            <?php if ( $total_pages > 1 ) : ?>
            <div style="margin-top:16px;display:flex;align-items:center;justify-content:center;gap:4px;flex-wrap:wrap">
                <?php
                $pagination_args = [
                    's'             => $search,
                    'f_term'        => $f_term,
                    'f_course'      => $f_course,
                    'f_status'      => $f_status,
                    'filter_gender' => $filter_gender,
                    'filter_ta_id'  => $filter_ta_id,
                    'filter_ta'     => $filter_ta,
                ];
                ?>
                <?php if ( $paged > 1 ) : ?>
                <a href="<?= esc_url( add_query_arg( array_merge( $pagination_args, ['paged' => $paged - 1] ), $base_url ) ) ?>"
                   class="button" style="padding:4px 10px">«</a>
                <?php endif; ?>

                <?php for ( $p = max(1, $paged - 3); $p <= min($total_pages, $paged + 3); $p++ ) : ?>
                    <a href="<?= esc_url( add_query_arg( array_merge( $pagination_args, ['paged' => $p] ), $base_url ) ) ?>"
                       class="button <?= $p === $paged ? 'button-primary' : '' ?>"
                       style="padding:4px 10px;min-width:34px;text-align:center">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <?php if ( $paged < $total_pages ) : ?>
                <a href="<?= esc_url( add_query_arg( array_merge( $pagination_args, ['paged' => $paged + 1] ), $base_url ) ) ?>"
                   class="button" style="padding:4px 10px">»</a>
                <?php endif; ?>

                <small style="color:#888;margin-right:8px">صفحه <?= $paged ?> از <?= $total_pages ?></small>
            </div>
            <?php endif; ?>

        </div>

     
        <!-- ════ مودال ویرایش ═══════════════════════════════════════════════ -->
        <div id="ta-edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;overflow:auto">
            <div style="background:#fff;max-width:560px;margin:60px auto;border-radius:8px;padding:28px;direction:rtl;position:relative">
                <button onclick="taCloseModal('ta-edit-modal')"
                        style="position:absolute;left:16px;top:16px;background:none;border:none;font-size:20px;cursor:pointer">✕</button>
                <h2 style="margin-top:0">✏️ ویرایش تخصیص</h2>
                <div id="ta-edit-info" style="background:#f0f4f8;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:0.85rem"></div>

                <form method="post" action="<?= admin_url('admin-post.php') ?>" id="ta-edit-form">
                    <?php wp_nonce_field('ta_admin_edit_assign'); ?>
                    <input type="hidden" name="action"        value="ta_admin_edit_assign">
                    <input type="hidden" name="assignment_id" id="edit_assignment_id">
                    <input type="hidden" name="force_ignore_capacity" id="edit_force_ignore_capacity" value="">
                    <!-- [FIX-EDIT-RETURN] نگه‌داشتن فیلترها/جستجو/صفحه فعلی برای بازگشت به همان صفحه بعد از ذخیره -->
                    <input type="hidden" name="return_qs" value="<?= esc_attr( $return_qs_b64 ) ?>">
                    <div id="edit-capacity-warning" style="display:none;background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:0.85rem;color:#664d03"></div>

                    <table class="form-table" style="direction:rtl">
                        <tr>
                            <th>استادیار</th>
                            <td>
                                <select name="ta_id" id="edit_ta_id" style="width:100%">
                                    <?php foreach ( $all_tas as $ta ) :
                                        $m = json_decode($ta->messengers,true) ?: [];
                                    ?>
                                        <option value="<?= $ta->id ?>"><?= esc_html($ta->display_name) ?> (<?= implode(', ',$m) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <!-- [FIX-EDIT-CAP-02] فیلد ظرفیت: نشان می‌دهد تخصیص فعلی متعلق به کدام ظرفیت
                             اعلام‌شده توسط استادیار است، و با تغییر آن، تخصیص به ظرفیت دیگری منتقل می‌شود -->
                        <tr>
                            <th>ظرفیت</th>
                            <td>
                                <select name="capacity_id" id="edit_capacity_id" style="width:100%">
                                    <option value="0">⏳ در حال بارگذاری ظرفیت‌ها...</option>
                                </select>
                                <p id="edit_capacity_hint" style="margin:4px 0 0;font-size:0.8rem;color:#888"></p>
                            </td>
                        </tr>
                        <tr>
                            <th>پیامرسان</th>
                            <td>
                                <select name="messenger" id="edit_messenger" style="width:auto">
                                    <option value="telegram">تلگرام</option>
                                    <option value="bale">بله</option>
                                    <option value="eitaa">ایتا</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>جنسیت هنرجو</th>
                            <td>
                                <select name="student_gender" id="edit_gender" style="width:auto">
                                    <option value="female">خانم</option>
                                    <option value="male">آقا</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>لینک گروه</th>
                            <td><input type="url" name="group_link" id="edit_group_link" style="width:100%"></td>
                        </tr>
                        <tr>
    <th>وضعیت</th>
    <td>
        <select name="status" id="edit_status" style="width:auto" onchange="taToggleCancelFields()">
            <option value="active">فعال</option>
            <option value="completed">تکمیل‌شده</option>
            <option value="canceled">انصراف</option>
            <option value="pending_admin">در انتظار ادمین</option>
        </select>
    </td>
</tr>
<tr id="row_cancel_reason" style="display:none;">
    <th>توضیحات انصراف</th>
    <td>
        <textarea name="cancel_reason" id="edit_cancel_reason" rows="3" style="width:100%"></textarea>
    </td>
</tr>
<tr id="row_cancel_date" style="display:none;">
    <th>تاریخ انصراف</th>
    <td id="edit_cancel_date_display" style="color:#888; direction:ltr; text-align:right;"></td>
</tr>

                    </table>
                    <p><button type="submit" class="button button-primary" id="ta-edit-submit-btn">💾 ذخیره تغییرات</button></p>
                </form>
            </div>
        </div>   
        
        <!-- ════ مودال تخصیص جدید ════════════════════════════════════════════ -->
        <div id="ta-assign-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;overflow:auto">
            <div style="background:#fff;max-width:600px;margin:60px auto;border-radius:8px;padding:28px;direction:rtl;position:relative">
                <button onclick="taCloseModal('ta-assign-modal')"
                        style="position:absolute;left:16px;top:16px;background:none;border:none;font-size:20px;cursor:pointer">✕</button>
                <h2 style="margin-top:0">➕ تخصیص تکی جدید</h2>


                <form method="post" action="<?= admin_url('admin-post.php') ?>">
                    <?php wp_nonce_field('ta_admin_assign'); ?>
                    <input type="hidden" name="action"     value="ta_admin_assign">
                    <input type="hidden" name="student_id" id="assign_student_id">

                    <table class="form-table" style="direction:rtl">
                        <tr>
                            <th>جستجوی هنرجو <span style="color:red">*</span></th>
                            <td>
                                <input type="text" id="assign_student_search"
                                       placeholder="نام، شماره همراه یا ایمیل..."
                                       style="width:100%" autocomplete="off">
                                <div id="assign_student_results" style="border:1px solid #ddd;display:none;max-height:200px;overflow:auto;background:#fff;position:absolute;z-index:9999;width:380px"></div>
                                <div id="assign_student_selected" style="margin-top:6px;color:#2ecc71;font-size:0.85rem"></div>
                            </td>
                        </tr>
                        <tr>
                            <th>محصول <span style="color:red">*</span></th>
                            <td>
                                <select name="product_id" id="assign_product" style="width:100%" required>
                                    <option value="">— انتخاب کنید —</option>
                                    <?php foreach ( $products as $p ) :
                                        $s = TA_Product_Meta::get_settings( $p->get_id() );
                                        if ( ! $s ) continue;
                                        $term = TA_Database::get_row("SELECT name FROM " . TA_Database::table('terms') . " WHERE id=%d", [$s->term_id]);
                                        $ctype = $course_labels[$s->course_type] ?? $s->course_type;
                                    ?>
                                        <option value="<?= $p->get_id() ?>">
                                            <?= esc_html( $p->get_name() ) ?> — <?= esc_html($term->name??'') ?> (<?= $ctype ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>استادیار <span style="color:red">*</span></th>
                            <td>
                                <select name="ta_id" style="width:100%" required>
                                    <option value="">— انتخاب کنید —</option>
                                    <?php foreach ( $all_tas as $ta ) :
                                        $m = json_decode($ta->messengers,true) ?: [];
                                    ?>
                                        <option value="<?= $ta->id ?>"><?= esc_html($ta->display_name) ?> (<?= implode(', ',$m) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>پیامرسان</th>
                            <td>
                                <select name="messenger" style="width:auto">
                                    <option value="telegram">تلگرام</option>
                                    <option value="bale">بله</option>
                                    <option value="eitaa">ایتا</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>جنسیت هنرجو</th>
                            <td>
                                <select name="student_gender" style="width:auto">
                                    <option value="female">خانم</option>
                                    <option value="male">آقا</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>لینک گروه</th>
                            <td><input type="url" name="group_link" style="width:100%" placeholder="https://t.me/..."></td>
                        </tr>
                        <tr>
                            <th>وضعیت</th>
                            <td>
                                <select name="status" style="width:auto">
                                    <option value="active">فعال</option>
                                    <option value="completed">تکمیل‌شده</option>
                                    <option value="pending_admin">در انتظار ادمین</option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <p><button type="submit" class="button button-primary">✅ ثبت تخصیص</button></p>
                </form>
            </div>
        </div>
    
        <!-- ════ مودال تخصیص بر اساس ظرفیت ════ -->
        <div id="ta-capacity-assign-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;overflow:auto">
    <div style="background:#fff;max-width:640px;margin:60px auto;border-radius:8px;padding:28px;direction:rtl;position:relative">
        <button onclick="document.getElementById('ta-capacity-assign-modal').style.display='none'"
                style="position:absolute;left:16px;top:16px;background:none;border:none;font-size:20px;cursor:pointer">✕</button>
        <h2 style="margin-top:0">🎯 تخصیص بر اساس ظرفیت استادیار</h2>

        <!-- مرحله ۱ -->
        <div id="cap-step-1">
            <p style="color:#555;margin-bottom:12px">نام یا شماره همراه هنرجو را وارد کنید:</p>
            <input type="text" id="cap-student-search" class="regular-text"
                   placeholder="جستجوی هنرجو..." autocomplete="off" style="width:100%;margin-bottom:8px">
            <div id="cap-student-results" style="border:1px solid #ddd;border-radius:4px;max-height:200px;overflow-y:auto;display:none"></div>
            <div id="cap-student-selected" style="display:none;background:#f0f7ff;border-radius:6px;padding:10px 14px;margin-top:8px;font-size:.9rem"></div>
            <input type="hidden" id="cap-student-id">
        </div>

        <!-- مرحله ۲ -->
        <div id="cap-step-2" style="display:none;margin-top:16px">
            <table class="form-table" style="margin:0">
                <tr>
                    <th style="width:130px">دوره / محصول</th>
                    <td>
                        <select id="cap-product-id" class="regular-text" style="width:100%">
                            <option value="">— انتخاب کنید —</option>
                            <?php
$cap_products = function_exists('wc_get_products') ? wc_get_products( [ 'limit' => -1, 'status' => 'publish' ] ) : [];
foreach ( $cap_products as $p ) :
    $s = TA_Product_Meta::get_settings( $p->get_id() );
    if ( ! $s ) {
        continue;
    }
    $ct = isset( $s->course_type ) ? $s->course_type : '';
    ?>
    <option value="<?= esc_attr( $p->get_id() ) ?>" data-course-type="<?= esc_attr( $ct ) ?>">
        <?= esc_html( $p->get_name() ) ?>
    </option>
<?php endforeach; ?>

                        </select>
                    </td>
                </tr>
<tr id="cap-messenger-row">
    <th>پیام‌رسان</th>
    <td>
        <select id="cap-messenger" class="regular-text">
            <option value="">انتخاب کنید...</option>
            <option value="telegram">تلگرام</option>
            <option value="eitaa">ایتا</option>
            <option value="bale">بله</option>
        </select>
    </td>
</tr>
<!-- ردیف نمایش اطلاعات خوانده شده از دوره قبل -->
<tr id="cap-prev-info-row" style="display:none;">
    <th>اطلاعات دوره پیش‌نیاز</th>
    <td>
        <div style="margin-bottom: 8px; background: #f0f0f1; padding: 10px; border-radius: 4px;">
            <span id="cap-prev-info-text" style="font-weight:bold;">پیام‌رسان: ... | لینک گروه: ...</span>
        </div>
        <button type="button" id="cap-edit-prev-info-btn" class="button button-small">ویرایش اطلاعات</button>
    </td>
</tr>

<!-- ردیف لینک گروه (برای حالت ویرایش) -->
<tr id="cap-group-link-row" style="display:none;">
    <th>لینک گروه هنرجو</th>
    <td>
        <input type="url" id="cap-group-link" class="regular-text" placeholder="https://..." dir="ltr">
    </td>
</tr>


                <tr>
<th>جنسیت هنرجو</th>
<td>
    <?php 
    $user_gender = get_user_meta( get_current_user_id(), 'student_gender', true );

    if ( in_array( $user_gender, [ 'male', 'female' ], true ) ) : 
        $gender_fa = ( $user_gender === 'male' ) ? 'آقا' : 'خانم';
    ?>
    <input type="hidden" id="cap-gender-hidden" value="<?= esc_attr( $user_gender ) ?>">

        <div class="ta-field-group" id="ta-gender-display-box" style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
            <span style="font-size: 14px;">جنسیت شما: <strong><?= esc_html( $gender_fa ) ?></strong></span>
            <button type="button" id="ta-btn-edit-gender" style="background: none; border: none; cursor: pointer; color: #007cba; padding: 0; font-size: 13px; display: inline-flex; align-items: center; gap: 4px;">
                ✏️ ویرایش
            </button>
        </div>

        <div class="ta-field-group" id="ta-gender-select-box" style="display: none;">
            <label>جنسیت خود را وارد کنید:</label>
            <select id="cap-gender" class="ta-select" name="student_gender">
                <option value="male" <?php selected( $user_gender, 'male' ); ?>>آقا</option>
                <option value="female" <?php selected( $user_gender, 'female' ); ?>>خانم</option>
            </select>
        </div>
<script>
(function(){
    var productSel    = document.getElementById('cap-product-id');
    var messengerRow  = document.getElementById('cap-messenger-row');
    var messengerSel  = document.getElementById('cap-messenger');

    if ( productSel && messengerRow ) {
        productSel.addEventListener('change', function(){
            var opt = this.options[this.selectedIndex];
            var ct  = opt ? opt.getAttribute('data-course-type') : '';
            // دوره‌هایی که پیامرسان ندارند (مثل خلاق با گروه ثابت)
            var noMessenger = ['creative', 'professional', 'critique'];
            if ( ct && noMessenger.indexOf(ct) !== -1 ) {
                messengerRow.style.display = 'none';
                if ( messengerSel ) messengerSel.value = '';
            } else {
                messengerRow.style.display = '';
            }
        });
    }

    // ریست مودال: نمایش مجدد ردیف پیام‌رسان
    var origReset = window.resetCapacityModal;
    if ( typeof origReset === 'function' ) {
        window.resetCapacityModal = function() {
            origReset.apply(this, arguments);
            if ( messengerRow ) messengerRow.style.display = '';
        };
    }
})();
</script>


        <script>
            document.getElementById('ta-btn-edit-gender').addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('ta-gender-display-box').style.display = 'none';
                document.getElementById('ta-gender-select-box').style.display = 'block';
            });
        </script>

    <?php else : ?>
        <div class="ta-field-group">
            <label>جنسیت خود را وارد کنید:</label>
            <select id="cap-gender" class="ta-select" name="student_gender">
                <option value="">— انتخاب کنید —</option>
                <option value="male">آقا</option>
                <option value="female">خانم</option>
            </select>
        </div>
    <?php endif; ?>
</td>

                </tr>
            </table>
            <p style="margin-top:12px">
                <button class="button button-primary" onclick="taLoadAvailableTAs()">🔍 نمایش ظرفیت‌های موجود</button>
            </p>
        </div>

        <!-- مرحله ۳ -->
        <div id="cap-step-3" style="display:none;margin-top:16px">
            <p style="font-weight:600;margin-bottom:8px">استادیارهای دارای ظرفیت:</p>
            <div id="cap-ta-list" style="border:1px solid #ddd;border-radius:6px;overflow:hidden"></div>
            <div id="cap-assign-msg" style="margin-top:12px;display:none;padding:10px;border-radius:6px"></div>
        </div>
        <!-- کادر بازخورد تخصیص -->
<div id="ta-assign-msg" style="display:none;"></div>

    </div>
</div>
   
  <script>    
function taToggleCancelFields() {
    var status = document.getElementById('edit_status').value;
    var reasonRow = document.getElementById('row_cancel_reason');
    var dateRow = document.getElementById('row_cancel_date');
    
    if (status === 'canceled') {
        reasonRow.style.display = 'table-row';
        // سطر تاریخ فقط در صورتی نمایش داده شود که از قبل تاریخی وجود داشته باشد
        if (document.getElementById('edit_cancel_date_display').innerText.trim() !== '') {
            dateRow.style.display = 'table-row';
        }
    } else {
        reasonRow.style.display = 'none';
        dateRow.style.display = 'none';
    }
}

// در تابعی که مودال را باز می‌کند (taOpenEditModal) باید مقادیر را ست کنید. 
// به عنوان مثال:
// document.getElementById('edit_cancel_reason').value = response.cancel_reason || '';
// document.getElementById('edit_cancel_date_display').innerText = response.cancel_date || '';
// taToggleCancelFields();
</script>

  
        <script>
var taAdminNonce = '<?= wp_create_nonce('ta_admin_nonce') ?>';
var taAjaxUrl    = '<?= admin_url('admin-ajax.php') ?>';

(function () {
    var capSearchTimer = null;

    function byId(id) {
        return document.getElementById(id);
    }

    function resetCapacityModal() {
        byId('cap-student-search').value = '';
        byId('cap-student-results').innerHTML = '';
        byId('cap-student-results').style.display = 'none';
        byId('cap-student-selected').innerHTML = '';
        byId('cap-student-selected').style.display = 'none';
        byId('cap-student-id').value = '';

        byId('cap-product-id').value = '';
      //  byId('cap-messenger').value = '';
        var messengerRow = document.getElementById('cap-messenger-row');
if (messengerRow && messengerRow.style.display !== 'none') {
    byId('cap-messenger').value = '';
}
       if (byId('cap-gender')) byId('cap-gender').value = '';
//جمال
       // byId('cap-gender').value = '';

        byId('cap-step-2').style.display = 'none';
        byId('cap-step-3').style.display = 'none';
        byId('cap-ta-list').innerHTML = '';

        hideAssignMessage();
    }

    function showCapacityModal() {
        resetCapacityModal();
        byId('ta-capacity-assign-modal').style.display = 'block';
    }

    function closeCapacityModal() {
        byId('ta-capacity-assign-modal').style.display = 'none';
    }

    window.taOpenCapacityAssignModal = showCapacityModal;

    window.taCloseCapacityAssignModal = closeCapacityModal;

    function showAssignMessage(type, html) {
        var box = byId('ta-assign-msg');
        if (!box) return;

        var styles = {
            loading: 'background:#f0f0f1;color:#555;border:1px solid #ccc;',
            success: 'background:#00a32a;color:#fff;border:1px solid #007a1f;',
            error:   'background:#d63638;color:#fff;border:1px solid #a00;'
        };

        box.style.cssText =
            'display:block;padding:10px 14px;border-radius:6px;margin-top:12px;font-size:13px;line-height:1.8;' +
            (styles[type] || styles.error);

        box.innerHTML = html;
    }

    function hideAssignMessage() {
        var box = byId('ta-assign-msg');
        if (!box) return;
        box.style.display = 'none';
        box.innerHTML = '';
    }

    function renderAvailableTAs(items) {
        var list = byId('cap-ta-list');
        list.innerHTML = '';

        if (!items || !items.length) {
            list.innerHTML = '<div style="padding:12px;color:#888">استادیاری با ظرفیت مناسب پیدا نشد.</div>';
            return;
        }

        var messengerLabels = {telegram:'تلگرام', whatsapp:'واتساپ', eitaa:'ایتا', bale:'بله', both:'همه'};
        var genderLabels    = {male:'آقایان', female:'خانم‌ها', both:'هر دو'};

        items.forEach(function (ta) {
            var row = document.createElement('div');
            row.style.cssText =
                'display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 16px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:8px;background:#fff;flex-wrap:wrap;transition:box-shadow .15s';
            row.onmouseenter = function(){ this.style.boxShadow = '0 2px 8px rgba(0,0,0,.1)'; };
            row.onmouseleave = function(){ this.style.boxShadow = ''; };

            var groupLinkHtml = ta.group_link
                ? '<a href="' + ta.group_link + '" target="_blank" rel="noopener" style="color:#0073aa;font-size:.82rem">🔗 گروه</a> '
                : '';

            var msgLabel     = ta.messenger    ? (messengerLabels[ta.messenger]    || ta.messenger)    : '';
            var genderLabel  = ta.student_gender ? (genderLabels[ta.student_gender] || ta.student_gender) : '';
            var metaHtml     = [msgLabel, genderLabel].filter(Boolean).join(' · ');

            var remainColor = ta.remaining > 3 ? '#059669' : ta.remaining > 0 ? '#d97706' : '#dc2626';

            row.innerHTML =
                '<div style="flex:1;min-width:220px">' +
                    '<strong style="font-size:.95rem">' + ta.name + '</strong>' +
                    '<div style="margin-top:5px;display:flex;flex-wrap:wrap;gap:6px;align-items:center">' +
                        '<span style="background:#f0fdf4;color:' + remainColor + ';border:1px solid ' + remainColor + '33;border-radius:20px;padding:2px 10px;font-size:.78rem;font-weight:600">' +
                            '⚡ ' + ta.remaining + ' ظرفیت باقی' +
                        '</span>' +
                        (metaHtml ? '<span style="color:#6b7280;font-size:.8rem">' + metaHtml + '</span>' : '') +
                        groupLinkHtml +
                    '</div>' +
                '</div>' +
                '<button type="button" class="button button-primary button-small ta-capacity-pick-btn" ' +
                    'data-ta-id="' + ta.ta_id + '" ' +
                    'data-capacity-id="' + ta.capacity_id + '" ' +
                    'style="flex-shrink:0">' +
                    'انتخاب ✓' +
                '</button>';

            list.appendChild(row);
        });
        
    }

// handleProductChange قدیمی حذف شد — منطق جدید در بخش event listener بالا است


function collectCapacityFormData() {
    var productSel   = byId('cap-product-id');
    var courseType   = productSel.options[productSel.selectedIndex] ? productSel.options[productSel.selectedIndex].getAttribute('data-course-type') : '';
    var genderEl     = byId('cap-gender');
    var genderHidden = byId('cap-gender-hidden');

    var genderVal = (genderEl && genderEl.value.trim()) ? genderEl.value.trim() : (genderHidden ? genderHidden.value.trim() : '');

    var messengerVal = byId('cap-messenger') ? byId('cap-messenger').value.trim() : '';
    
    // اگر ردیف پیامرسان مخفی است، مقدار را خالی بگذار (برای دوره‌های بدون پیامرسان)
    var messengerRow = document.getElementById('cap-messenger-row');
    if (messengerRow && messengerRow.style.display === 'none') {
        messengerVal = '';
    }

    return {
        student_id:     byId('cap-student-id').value.trim(),
        product_id:     byId('cap-product-id').value.trim(),
        messenger:      messengerVal,
        student_gender: genderVal
    };
}

function validateCapacityForm(data) {
    var missing = [];
    var productSel = byId('cap-product-id');
    var courseType = productSel.options[productSel.selectedIndex] ? productSel.options[productSel.selectedIndex].getAttribute('data-course-type') : '';

    if (!data.student_id) missing.push('هنرجو');
    if (!data.product_id) missing.push('محصول');

    // پیامرسان فقط وقتی نمایش داده می‌شود اجباری است
    var messengerRow = document.getElementById('cap-messenger-row');
    if (messengerRow && messengerRow.style.display !== 'none' && !data.messenger) {
        missing.push('پیام‌رسان');
    }
    
    if (!data.student_gender) missing.push('جنسیت');

    if (missing.length) {
        showAssignMessage('error', '❌ لطفاً این فیلدها را تکمیل کنید: ' + missing.join('، '));
        return false;
    }
    return true;
}

/* ── تابع کمکی: لود ظرفیت‌ها (بدون بررسی جنسیت) ── */
function doLoadAvailableTAs(formData) {
    byId('cap-step-3').style.display = 'block';
    byId('cap-ta-list').innerHTML = '<div style="padding:12px;color:#888">در حال بارگذاری...</div>';
    hideAssignMessage();

    fetch(taAjaxUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: new URLSearchParams({
            action: 'ta_admin_get_available_tas',
            nonce: taAdminNonce,
            product_id: formData.product_id,
            messenger: formData.messenger,
            student_gender: formData.student_gender,
            student_id: formData.student_id
        })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (!res.success) {
            byId('cap-ta-list').innerHTML =
                '<div style="padding:12px;color:#c00">' +
                (res.data && res.data.message ? res.data.message : (typeof res.data === 'string' ? res.data : 'خطا در دریافت ظرفیت‌ها')) +
                '</div>';
            return;
        }
        renderAvailableTAs(res.data);
    })
    .catch(function (err) {
        byId('cap-ta-list').innerHTML =
            '<div style="padding:12px;color:#c00">خطا در ارتباط با سرور: ' + err.message + '</div>';
    });
}

/* ── تابع اصلی دکمه «نمایش ظرفیت‌های موجود» ── */
window.taLoadAvailableTAs = function () {
    
    var productSel = byId('cap-product-id');
    var courseType = productSel.options[productSel.selectedIndex] ? productSel.options[productSel.selectedIndex].getAttribute('data-course-type') : '';

    // --- ذخیره ظاهری فیلدهای ویرایش شده و بازگشت به حالت قبل ---
    if (courseType === 'intro' || courseType === 'advanced') {
        var msgr = byId('cap-messenger').value.trim();
        var link = byId('cap-group-link').value.trim();
        
        // آپدیت متن باکس طوسی
        var infoText = '<strong>پیام‌رسان:</strong> ' + (msgr || 'وارد نشده') + ' | <strong>لینک گروه:</strong> <span dir="ltr">' + (link || 'وارد نشده') + '</span>';
        var prevTextEl = byId('cap-prev-info-text');
        if (prevTextEl) prevTextEl.innerHTML = infoText;

        // مخفی کردن فرم ویرایش و نمایش مجدد باکس طوسی
        var mRow = document.getElementById('cap-messenger-row');
        var lRow = document.getElementById('cap-group-link-row');
        var pRow = document.getElementById('cap-prev-info-row');
        
        if (mRow) mRow.style.display = 'none';
        if (lRow) lRow.style.display = 'none';
        if (pRow) pRow.style.display = '';
    }
    // -------------------------------------------------------------

    var formData = collectCapacityFormData();

    if (!validateCapacityForm(formData)) {
        return;
    }

    /* ── بررسی: آیا در حال ویرایش جنسیت هستیم و تغییر کرده؟ ── */
    var selectBox    = byId('ta-gender-select-box');
    var genderSel    = byId('cap-gender');
    var genderHidden = byId('cap-gender-hidden');

    if (selectBox && selectBox.style.display !== 'none' && genderSel) {
        var newGender = genderSel.value.trim();
        var oldGender = genderHidden ? genderHidden.value.trim() : '';

        if (newGender && newGender !== oldGender) {
            fetch(taAjaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({
                    action:     'ta_save_student_gender',
                    nonce:      taAdminNonce,
                    student_id: formData.student_id,
                    gender:     newGender
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    if (genderHidden) genderHidden.value = newGender;
                    var displayBox  = byId('ta-gender-display-box');
                    var genderLabel = (newGender === 'male') ? 'آقا' : 'خانم';
                    if (displayBox) {
                        var strong = displayBox.querySelector('strong');
                        if (strong) strong.textContent = genderLabel;
                        displayBox.style.display = '';
                    }
                    selectBox.style.display = 'none';
                }
                doLoadAvailableTAs(formData);
            })
            .catch(function (err) {
                doLoadAvailableTAs(formData);
            });
            return; 
        }
    }

    doLoadAvailableTAs(formData);
};

    function showCustomLinkForm(taId, capacityId) {
        var list = byId('cap-ta-list');

        var oldBox = byId('cap-custom-link-box');
        if (oldBox) oldBox.remove();

        var wrap = document.createElement('div');
        wrap.id = 'cap-custom-link-box';
        wrap.style.cssText = 'margin-top:12px;padding:14px;border:2px dashed #0073aa;border-radius:8px;background:#f0f8ff';

        wrap.innerHTML =
            '<div style="font-weight:700;margin-bottom:8px;color:#b32d2e">⚠️ این دوره نیاز به لینک گروه دارد.</div>' +
            '<div style="margin-bottom:8px">لینک گروه را وارد کنید:</div>' +
            '<input type="url" id="cap-custom-group-link" placeholder="https://..." dir="ltr" ' +
                'style="width:100%;padding:8px 10px;margin-bottom:10px">' +
            '<div style="display:flex;gap:8px;flex-wrap:wrap">' +
                '<button type="button" class="button button-primary" id="cap-submit-custom-link">ثبت لینک و تخصیص</button>' +
                '<button type="button" class="button" id="cap-cancel-custom-link">انصراف</button>' +
            '</div>';

        list.appendChild(wrap);

        byId('cap-submit-custom-link').addEventListener('click', function () {
            var link = byId('cap-custom-group-link').value.trim();

            // [FIX-GROUP-LINK-SCHEME] اگر لینک بدون http(s) وارد شده، خودکار https:// اضافه شود
            if (link && !/^https?:\/\//i.test(link)) {
                link = 'https://' + link;
                byId('cap-custom-group-link').value = link;
            }
            if (!link) {
                showAssignMessage('error', '❌ لطفاً یک لینک معتبر وارد کنید.');
                return;
            }

            submitCapacityAssign(taId, capacityId, link);
        });

        byId('cap-cancel-custom-link').addEventListener('click', function () {
            wrap.remove();
            hideAssignMessage();
        });
    }


function submitCapacityAssign(taId, capacityId, customGroupLink) {
    var formData = collectCapacityFormData();

    if (!validateCapacityForm(formData)) {
        return;
    }

    showAssignMessage('loading', '⏳ در حال ارسال...');

    var payload = {
        action: 'ta_admin_assign_by_capacity',
        nonce: taAdminNonce,
        student_id: formData.student_id,
        ta_id: taId,
        product_id: formData.product_id,
        capacity_id: capacityId,
        messenger: formData.messenger,
        student_gender: formData.student_gender
    };

    // اگر لینک کاستوم خالی بود اما مقداری در اینپوت لینک داشتیم (برای intro/advanced)
    if (!customGroupLink) {
        var productSel = byId('cap-product-id');
        var courseType = productSel.options[productSel.selectedIndex] ? productSel.options[productSel.selectedIndex].getAttribute('data-course-type') : '';
        if (courseType === 'intro' || courseType === 'advanced') {
            customGroupLink = byId('cap-group-link').value.trim();
        }
    }

    if (customGroupLink) {
        payload.custom_group_link = customGroupLink;
    }

    fetch(taAjaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: new URLSearchParams(payload)
    })
    .then(function (response) { return response.text(); })
    .then(function (rawText) {
        if (!rawText.trim()) throw new Error('پاسخ خالی از سرور دریافت شد');
        if (rawText.trim().charAt(0) === '<') throw new Error('سرور به‌جای JSON خروجی HTML برگردانده است');
        return JSON.parse(rawText);
    })
    .then(function (res) {
        if (res.success) {
            var message = (res.data && res.data.message) ? res.data.message : 'تخصیص با موفقیت انجام شد.';
            var groupLink = (res.data && res.data.group_link) ? res.data.group_link : '';
            var linkHtml = groupLink
                ? '<br><a href="' + groupLink + '" target="_blank" rel="noopener" style="color:#fff;text-decoration:underline">🔗 لینک گروه</a>'
                : '';

            showAssignMessage('success', '✅ ' + message + linkHtml);
            
            // ریست کردن مودال پس از موفقیت
            resetCapacityModal();

            // در صورت نیاز به ریلود صفحه:
            // setTimeout(function () { window.location.reload(); }, 1800);
            return;
        }

        if (res.data && res.data.needs_link) {
            showAssignMessage('error', '⚠️ ' + (res.data.message || 'لطفاً لینک گروه را وارد کنید.'));
            showCustomLinkForm(taId, capacityId);
            return;
        }

        var errorMessage = (res.data && res.data.message) ? res.data.message : (typeof res.data === 'string') ? res.data : 'خطا در تخصیص استادیار';
        showAssignMessage('error', '⚠️ ' + errorMessage);
    })
    .catch(function (err) {
        showAssignMessage('error', '🔴 ' + err.message);
    });
}



    window.taDoCapacityAssign = function (taId, capacityId) {
        submitCapacityAssign(taId, capacityId, '');
    };

    document.addEventListener('click', function (e) {
        var pickBtn = e.target.closest('.ta-capacity-pick-btn');
        if (pickBtn) {
            e.preventDefault();
            var taId = pickBtn.getAttribute('data-ta-id');
            var capacityId = pickBtn.getAttribute('data-capacity-id');
            submitCapacityAssign(taId, capacityId, '');
            return;
        }

        if (e.target === byId('ta-capacity-assign-modal')) {
            closeCapacityModal();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        var searchInput = byId('cap-student-search');
        if (!searchInput) return;

   searchInput.addEventListener('input', function () {
            clearTimeout(capSearchTimer);

            var q = this.value.trim();
            if (q.length < 2) {
                byId('cap-student-results').style.display = 'none';
                byId('cap-student-results').innerHTML = '';
                return;
            }

            capSearchTimer = setTimeout(function () {
                fetch(taAjaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: new URLSearchParams({
                        action: 'ta_search_users',
                        nonce: taAdminNonce,
                        q: q
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    var box = byId('cap-student-results');
                    box.innerHTML = '';

                    if (!res.success || !res.data || !res.data.length) {
                        box.innerHTML = '<div style="padding:10px;color:#888">نتیجه‌ای یافت نشد</div>';
                        box.style.display = 'block';
                        return;
                    }

                    res.data.forEach(function (u) {
                        var item = document.createElement('div');
                        item.style.cssText = 'padding:8px 12px;cursor:pointer;border-bottom:1px solid #eee';
                        item.textContent = (u.name || '') + ' — ' + (u.phone || '');
                        item.addEventListener('mouseenter', function () {
                            this.style.background = '#f0f7ff';
                        });
                        item.addEventListener('mouseleave', function () {
                            this.style.background = '';
                        });
                        item.addEventListener('click', function () {
                            byId('cap-student-id').value = u.id;
                            byId('cap-student-selected').textContent =
                                '✅ ' + (u.name || 'هنرجو') + ((u.phone ? ' (' + u.phone + ')' : ''));
                            byId('cap-student-selected').style.display = 'block';
                            byId('cap-student-search').value = '';
                            byId('cap-student-results').style.display = 'none';
                            byId('cap-step-2').style.display = 'block';
                            byId('cap-step-3').style.display = 'none';
                            byId('cap-ta-list').innerHTML = '';
                            hideAssignMessage();
                        });
                        box.appendChild(item);
                    });

                    box.style.display = 'block';
                })
                .catch(function () {
                    byId('cap-student-results').innerHTML =
                        '<div style="padding:10px;color:#c00">خطا در جستجوی هنرجو</div>';
                    byId('cap-student-results').style.display = 'block';
                });
            }, 350);
        });
    });

    jQuery(document).ready(function($) {

    // === مدیریت تغییر محصول در مودال ظرفیت ===
        $('#cap-product-id').on('change', function() {
        const $selectedOption = $(this).find('option:selected');
        const courseType = $selectedOption.data('course-type');
        const studentId = $('#cap-student-id').val();
        const productId = $(this).val();

        // ۱. ریست کردن ظاهر
        $('#cap-prev-info-row, #cap-group-link-row').hide();
        $('#cap-messenger, #cap-group-link').val('');

        // ۲. بررسی نوع دوره
        if (courseType === 'intro' || courseType === 'advanced') {
            
            $('#cap-messenger-row').hide();
            
            // نمایش متن "در حال بارگذاری" به کاربر
            $('#cap-prev-info-text').text('⏳ در حال دریافت اطلاعات دوره قبل...');
            $('#cap-prev-info-row').show();
            $('#cap-edit-prev-info-btn').hide(); 
            
            // ۳. درخواست AJAX
            // دقت کنید نام اکشن باید با بک‌اند تطابق داشته باشد
            $.post(ajaxurl, {
                action: 'ta_admin_get_prev_course_info', // مطمئن شوید نام اکشن در PHP هم همین است
                student_id: studentId,
                product_id: productId
            })
            .done((response) => {
                if (response.success && response.data) {
                    const { messenger, group_link } = response.data;
                    
                    $('#cap-prev-info-text').html(`<strong>پیام‌رسان:</strong> ${messenger} | <strong>لینک گروه:</strong> <span dir="ltr">${group_link}</span>`);
                    
                    $('#cap-messenger').val(messenger);
                    $('#cap-group-link').val(group_link);
                } else {
                    $('#cap-prev-info-text').text('⚠️ سابقه دوره پیش‌نیاز یافت نشد.');
                }
            })
            .fail(() => {
                $('#cap-prev-info-text').text('❌ خطا در دریافت اطلاعات. لطفاً ویرایش را بزنید.');
            })
            .always(() => {
                $('#cap-edit-prev-info-btn').show();
            });

        } else {
            $('#cap-messenger-row').show();
        }
    });

    // === دکمه ویرایش اطلاعات ===
    $('#cap-edit-prev-info-btn').on('click', function(e) {
        e.preventDefault(); // جلوگیری از رفتار پیش‌فرض دکمه
        $('#cap-prev-info-row').hide();
        $('#cap-messenger-row').fadeIn(); 
        $('#cap-group-link-row').fadeIn(); 
    });

});

})();
</script>
    
        <script>
        var taAdminNonce = '<?= wp_create_nonce('ta_admin_nonce') ?>';
        var taAjaxUrl   = '<?= admin_url('admin-ajax.php') ?>';
        var searchTimer = null;

        function taOpenAssignModal() {
            document.getElementById('ta-assign-modal').style.display = 'block';
        }
        function taCloseModal(id) {
            document.getElementById(id).style.display = 'none';
        }
        window.onclick = function(e) {
            ['ta-assign-modal','ta-edit-modal'].forEach(function(id){
                var el = document.getElementById(id);
                if (e.target === el) el.style.display = 'none';
            });
        };

        // جستجوی هنرجو با debounce
        document.getElementById('assign_student_search').addEventListener('input', function(){
            clearTimeout(searchTimer);
            var q = this.value.trim();
            if (q.length < 2) { document.getElementById('assign_student_results').style.display='none'; return; }
            searchTimer = setTimeout(function(){
                fetch(taAjaxUrl, {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body: 'action=ta_search_users&nonce='+taAdminNonce+'&q='+encodeURIComponent(q)
                }).then(r=>r.json()).then(function(res){
                    if (!res.success) return;
                    var box = document.getElementById('assign_student_results');
                    box.innerHTML = '';
                    if (!res.data.length) {
                        box.innerHTML = '<div style="padding:8px 12px;color:#888;font-size:0.85rem">نتیجه‌ای یافت نشد</div>';
                        box.style.display = 'block'; return;
                    }
                    res.data.forEach(function(u){
                        var d = document.createElement('div');
                        d.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:0.88rem;border-bottom:1px solid #eee';
                        d.textContent = u.label;
                        d.addEventListener('mouseover',function(){this.style.background='#f0f4f8'});
                        d.addEventListener('mouseout', function(){this.style.background=''});
                        d.addEventListener('click',function(){
                            document.getElementById('assign_student_id').value = u.id;
                            document.getElementById('assign_student_search').value = u.label;
                            document.getElementById('assign_student_selected').textContent = '✅ انتخاب شد: '+u.name;
                            box.style.display = 'none';
                        });
                        box.appendChild(d);
                    });
                    box.style.display = 'block';
                });
            }, 350);
        });

        // ویرایش تخصیص
           var taEditOriginalTaId = null; // [FIX-EDIT-CAP-01] برای تشخیص تغییر استادیار در سمت کلاینت

           // ════════════════════════════════════════════════════════════════
           // [FIX-EDIT-CAP-02] فیلد «ظرفیت» در فرم ویرایش — بارگذاری و همگام‌سازی
           // توجه: این بلوک عمداً در همین اسکریپت (همان جایی که taOpenEditModal
           // تعریف شده) قرار دارد، نه در اسکریپت قبلی که داخل یک IIFE جداگانه
           // است — چون آن IIFE اسکوپ محلی دارد و توابع/متغیرهای آن از بیرونش
           // (از جمله از اینجا) قابل دسترسی نیستند.
           // ════════════════════════════════════════════════════════════════
           var taEditCourseType         = '';
           var taEditTermId             = 0;
           var taEditOriginalCapacityId = '0'; // برای تشخیص تغییر دستی ظرفیت توسط ادمین

           function taLoadCapacitiesForEdit(taId, courseType, termId, currentCapacityId) {
               var sel = document.getElementById('edit_capacity_id');
               var hint = document.getElementById('edit_capacity_hint');
               sel.innerHTML = '<option value="0">⏳ در حال بارگذاری ظرفیت‌ها...</option>';
               hint.textContent = '';

               var body = new URLSearchParams({
                   action: 'ta_admin_list_ta_capacities',
                   nonce: taAdminNonce,
                   ta_id: taId,
                   course_type: courseType,
                   term_id: termId,
                   current_capacity_id: currentCapacityId || 0
               });

               fetch(taAjaxUrl, {
                   method: 'POST',
                   headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                   body: body
               })
               .then(function (r) { return r.json(); })
               .then(function (res) {
                   sel.innerHTML = '';

                   if (!res.success || !res.data.capacities || !res.data.capacities.length) {
                       var opt = document.createElement('option');
                       opt.value = '0';
                       opt.textContent = '— هیچ ظرفیتی برای این استادیار در این دوره/ترم تعریف نشده —';
                       sel.appendChild(opt);
                       hint.textContent = 'استادیار انتخابی هیچ ظرفیتی برای این دوره ثبت نکرده؛ تخصیص بدون ظرفیت مشخص ذخیره خواهد شد.';
                       hint.style.color = '#b94a48';
                       return;
                   }

                   res.data.capacities.forEach(function (cap) {
                       var opt = document.createElement('option');
                       opt.value = cap.capacity_id;
                       opt.textContent = cap.label;
                       if (cap.is_full) opt.textContent += ' ⚠️';
                       if (cap.is_current) opt.selected = true;
                       sel.appendChild(opt);
                   });

                   // اگر ظرفیت فعلی تخصیص در لیست جدید پیدا نشد (مثلاً استادیار عوض شده)
                   // اولین ظرفیت آزاد را پیش‌فرض انتخاب کن؛ در غیر این صورت اولین مورد لیست
                   var hasCurrentSelected = res.data.capacities.some(function (c) { return c.is_current; });
                   if (!hasCurrentSelected) {
                       var firstFree = res.data.capacities.find(function (c) { return !c.is_full; });
                       sel.value = firstFree ? firstFree.capacity_id : res.data.capacities[0].capacity_id;
                   }

                   hint.style.color = '#888';
                   hint.textContent = 'این فیلد، ظرفیتی که استادیار اعلام کرده را نشان می‌دهد. تغییر آن، تخصیص را به ظرفیت دیگری منتقل می‌کند.';
               })
               .catch(function () {
                   sel.innerHTML = '<option value="0">خطا در بارگذاری ظرفیت‌ها</option>';
                   hint.textContent = 'ارتباط با سرور برقرار نشد؛ لطفاً دوباره تلاش کنید.';
                   hint.style.color = '#b94a48';
               });
           }

           // وقتی ادمین استادیار را در فرم ویرایش عوض می‌کند، فهرست ظرفیت‌ها برای
           // استادیار جدید دوباره بارگذاری می‌شود (چون ظرفیت‌های استادیار قبلی
           // دیگر معتبر نیستند)
           (function () {
               var taSel = document.getElementById('edit_ta_id');
               if (!taSel) return;
               taSel.addEventListener('change', function () {
                   taLoadCapacitiesForEdit(taSel.value, taEditCourseType, taEditTermId, 0);
               });
           })();

           // ════════════════════════════════════════════════════════════════
           // [FIX-EDIT-CAP-01/02] بررسی ظرفیت قبل از ذخیره ویرایش تخصیص
           // بر مبنای فیلد صریح «ظرفیت» (capacity_id) عمل می‌کند، نه حدسِ
           // خودکار بر اساس جنسیت/پیام‌رسان — تا با وجود چند ردیف ظرفیت برای یک
           // استادیار (مثلاً ردیف جدا برای آقایان و خانم‌ها) ابهامی پیش نیاید.
           // ════════════════════════════════════════════════════════════════
           (function () {
               var editForm = document.getElementById('ta-edit-form');
               if (!editForm) return;

               editForm.addEventListener('submit', function (e) {
                   // اگر ادمین قبلاً override را تأیید کرده، اجازه ارسال عادی بده
                   var forceField = document.getElementById('edit_force_ignore_capacity');
                   if (forceField.value === '1') {
                       return;
                   }

                   var capSel = document.getElementById('edit_capacity_id');
                   var selectedOpt = capSel.options[capSel.selectedIndex];
                   var capacityChanged = String(capSel.value) !== String(taEditOriginalCapacityId);

                   // اگر نه استادیار و نه ظرفیت تغییری نکرده، نیازی به بررسی نیست
                   var newTaId = document.getElementById('edit_ta_id').value;
                   var taChanged = String(newTaId) !== String(taEditOriginalTaId);
                   if (!taChanged && !capacityChanged) {
                       return;
                   }

                   // ظرفیت انتخاب‌شده پر است؟ (اطلاعات از همان لیست بارگذاری‌شده در taLoadCapacitiesForEdit)
                   var isFull = selectedOpt && selectedOpt.textContent.indexOf('⚠️') !== -1;
                   var isEmptyChoice = !capSel.value || capSel.value === '0';

                   if (!isFull && !isEmptyChoice) {
                       // ظرفیت انتخابی آزاد است -> ارسال عادی
                       return;
                   }

                   // ظرفیت انتخابی پر است یا ظرفیتی برای این استادیار تعریف نشده -> نیاز به تأیید صریح
                   e.preventDefault();

                   var warnBox = document.getElementById('edit-capacity-warning');
                   var msg = isEmptyChoice
                       ? 'برای استادیار انتخابی، هیچ ظرفیتی در این دوره/ترم تعریف نشده است.'
                       : 'ظرفیت انتخابی تکمیل شده است (used_capacity برابر یا بیشتر از total_capacity).';

                   warnBox.innerHTML = '⚠️ ' + msg;
                   warnBox.style.display = 'block';

                   var confirmed = confirm(msg + '\n\nبرای ادامه بدون رعایت ظرفیت، تأیید کنید.');

                   if (confirmed) {
                       forceField.value = '1';
                       warnBox.innerHTML = '⚠️ این تخصیص بدون رعایت ظرفیت استادیار ذخیره خواهد شد.';
                       editForm.submit();
                   }
               });
           })();

           function taOpenEditModal(assignId) {
            fetch(taAjaxUrl, {
                method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body: 'action=ta_get_assignment&nonce='+taAdminNonce+'&id='+assignId
            }).then(r=>r.json()).then(function(res){
                if (!res.success) { alert('خطا در دریافت اطلاعات'); return; }
                var d = res.data;
                document.getElementById('edit_assignment_id').value = d.id;
                document.getElementById('edit_ta_id').value        = d.ta_id;
                document.getElementById('edit_messenger').value    = d.messenger||'telegram';
                document.getElementById('edit_gender').value       = d.student_gender||'female';
                document.getElementById('edit_group_link').value   = d.group_link||'';

                // [FIX-EDIT-CAP-02] بارگذاری فهرست ظرفیت‌های استادیار فعلی و انتخاب خودکار ظرفیتی
                // که این تخصیص هم‌اکنون به آن تعلق دارد
                taEditCourseType = d.course_type;
                taEditTermId     = d.term_id;
                taEditOriginalCapacityId = String(d.capacity_id || 0);
                taLoadCapacitiesForEdit(d.ta_id, d.course_type, d.term_id, d.capacity_id);

                // [FIX-EDIT-CAP-01] بازنشانی وضعیت هشدار ظرفیت برای ویرایش جدید
                taEditOriginalTaId = String(d.ta_id);
                document.getElementById('edit_force_ignore_capacity').value = '';
                var warnBox = document.getElementById('edit-capacity-warning');
                warnBox.style.display = 'none';
                warnBox.innerHTML = '';
                var submitBtn = document.getElementById('ta-edit-submit-btn');
                submitBtn.textContent = '💾 ذخیره تغییرات';
                
                // مقداردهی وضعیت
                var statusField = document.getElementById('edit_status');
                statusField.value = d.status||'active';

                // مقداردهی فیلدهای انصراف
                var cancelReasonField = document.getElementById('edit_cancel_reason');
                var cancelDateDisplay = document.getElementById('edit_cancel_date_display');
                
                if (cancelReasonField) cancelReasonField.value = d.cancel_reason || '';
                if (cancelDateDisplay) cancelDateDisplay.innerHTML = d.cancel_date || '';

                // فراخوانی تابعی که نمایش/مخفی کردن فیلدهای انصراف را کنترل می کند
                if (typeof taToggleCancelFields === 'function') {
                    taToggleCancelFields();
                }

                var phone = d.student_phone ? ' — ' + d.student_phone : '';
                document.getElementById('ta-edit-info').innerHTML =
                    '<strong>هنرجو:</strong> '+d.student_name+phone+
                    ' | <strong>محصول ID:</strong> '+d.product_id+
                    ' | <strong>دوره:</strong> '+d.course_type;
                document.getElementById('ta-edit-modal').style.display = 'block';
            });
        }

        // ── [GROUP-REVIEW] دکمه «بررسی همه‌ی این فیلتر» ────────────────────
        (function(){
            var btn = document.getElementById('ta-bulk-mark-reviewed-btn');
            if(!btn) return;

            btn.addEventListener('click', function(){
                var total = btn.dataset.total || '0';
                if(!confirm('همه‌ی ' + total + ' موردِ منطبق با فیلتر فعلی به‌عنوان «بررسی‌شده» علامت بخورند؟')) return;

                btn.disabled = true;
                var originalText = btn.textContent;
                btn.textContent = '⏳ در حال ذخیره...';

                var body = 'action=ta_admin_bulk_mark_reviewed'
                    + '&nonce=' + encodeURIComponent(taAdminNonce)
                    + '&s=' + encodeURIComponent(btn.dataset.s || '')
                    + '&f_term=' + encodeURIComponent(btn.dataset.fTerm || '')
                    + '&f_course=' + encodeURIComponent(btn.dataset.fCourse || '')
                    + '&f_status=' + encodeURIComponent(btn.dataset.fStatus || '')
                    + '&filter_gender=' + encodeURIComponent(btn.dataset.filterGender || '')
                    + '&filter_ta=' + encodeURIComponent(btn.dataset.filterTa || '')
                    + '&filter_ta_id=' + encodeURIComponent(btn.dataset.filterTaId || '');

                fetch(taAjaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if(!res || !res.success){
                        alert((res && res.data && res.data.message) || 'خطا در ذخیره‌سازی');
                        btn.disabled = false;
                        btn.textContent = originalText;
                        return;
                    }
                    location.reload();
                })
                .catch(function(){
                    alert('خطا در ارتباط با سرور');
                    btn.disabled = false;
                    btn.textContent = originalText;
                });
            });
        })();

        // ── [GROUP-REVIEW] چک‌باکس «بررسی شد» ─────────────────────────────
        (function(){
            document.addEventListener('change', function(e){
                var cb = e.target.closest('.ta-reviewed-checkbox');
                if(!cb) return;

                var assignmentId = cb.dataset.assignmentId;
                var reviewed     = cb.checked ? 1 : 0;
                cb.disabled = true;

                fetch(taAjaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=ta_admin_toggle_reviewed'
                        + '&nonce=' + encodeURIComponent(taAdminNonce)
                        + '&assignment_id=' + encodeURIComponent(assignmentId)
                        + '&reviewed=' + reviewed
                })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    cb.disabled = false;
                    if(!res || !res.success){
                        cb.checked = !reviewed; // برگرداندن چک‌باکس در صورت خطا
                        alert((res && res.data && res.data.message) || 'خطا در ذخیره‌سازی');
                        return;
                    }
                    // گروه‌بندی صفحه بعد از رفرش بعدی به‌روزرسانی می‌شود؛ ردیف فعلی را
                    // به‌صورت بصری هم علامت می‌زنیم تا ادمین بازخورد فوری ببیند.
                    var row = cb.closest('tr');
                    if(row){
                        row.className = row.className.replace(/ta-row-g\d/g, '').trim();
                        row.classList.add(reviewed ? 'ta-row-g5' : 'ta-row-g1');
                    }
                })
                .catch(function(){
                    cb.disabled = false;
                    cb.checked = !reviewed;
                    alert('خطا در ارتباط با سرور');
                });
            });
        })();

        // ── کپی با کلیک (لینک گروه و عنوان گروه) ─────────────────────────
        (function(){
            // toast container
            var toast = document.createElement('div');
            toast.id  = 'ta-copy-toast';
            toast.style.cssText = 'position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(20px);'
                + 'background:#1e293b;color:#fff;padding:9px 22px;border-radius:8px;font-size:13px;'
                + 'opacity:0;transition:all .25s;z-index:99999;pointer-events:none;direction:rtl';
            toast.textContent = '✓ کپی شد';
            document.body.appendChild(toast);

            function showToast(){
                toast.style.opacity  = '1';
                toast.style.transform = 'translateX(-50%) translateY(0)';
                clearTimeout(toast._t);
                toast._t = setTimeout(function(){
                    toast.style.opacity  = '0';
                    toast.style.transform = 'translateX(-50%) translateY(20px)';
                }, 1800);
            }

            document.addEventListener('click', function(e){
                var el = e.target.closest('.ta-copy-cell');
                if(!el) return;
                var text = el.dataset.copy || el.textContent.trim();
                if(navigator.clipboard && navigator.clipboard.writeText){
                    navigator.clipboard.writeText(text).then(showToast);
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
                    document.body.appendChild(ta); ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    showToast();
                }
            });
        })();

        </script>

        <?php
    }

    private function render_notices(): void {
        $map = [
            'assigned'                 => [ 'success', '✅ تخصیص با موفقیت ثبت شد.' ],
            'edited'                   => [ 'success', '✅ تخصیص ویرایش شد.' ],
            'deleted'                  => [ 'success', '🗑️ تخصیص حذف شد.' ],
            'error=missing_fields'     => [ 'error',   '❌ اطلاعات ناقص است.' ],
            'error=product_not_configured' => [ 'error', '❌ محصول در سیستم استادیار پیکربندی نشده.' ],
            'error=duplicate'          => [ 'error',   '❌ این هنرجو قبلاً برای این محصول تخصیص دارد.' ],
            // [FIX-EDIT-CAP-01]
            'error=no_capacity'        => [ 'error',   '❌ استادیار جدید ظرفیت آزادی برای این شرایط ندارد. اگر می‌خواهید بدون رعایت ظرفیت ادامه دهید، دوباره ویرایش را باز کرده و گزینه تأیید override را بزنید.' ],
            'capacity_forced'          => [ 'warning', '⚠️ تخصیص بدون رعایت ظرفیت استادیار ذخیره شد (با تأیید ادمین).' ],
            // [FIX-EDIT-CAP-02]
            'error=invalid_capacity'   => [ 'error',   '❌ ظرفیت انتخابی متعلق به این استادیار/دوره نیست. لطفاً دوباره فرم ویرایش را باز کرده و ظرفیت را از فهرست انتخاب کنید.' ],
        ];
        foreach ( $map as $key => [ $type, $msg ] ) {
            if ( strpos( $key, '=' ) !== false ) {
                [$k, $v] = explode( '=', $key, 2 );
                if ( ( $_GET[ $k ] ?? '' ) === $v ) {
                    echo "<div class='notice notice-{$type} is-dismissible'><p>{$msg}</p></div>";
                }
            } else {
                if ( isset( $_GET[ $key ] ) ) {
                    echo "<div class='notice notice-{$type} is-dismissible'><p>{$msg}</p></div>";
                }
            }
        }
    }

    private function enqueue_admin_scripts(): void {
        // Inline CSS برای مودال در صفحه ادمین
        echo '<style>
            #ta-assign-modal .form-table th { width:130px; font-weight:600; }
            #ta-edit-modal   .form-table th { width:130px; font-weight:600; }
        </style>';
    }

    private function redirect( string $params ): never {
        wp_redirect( admin_url( "admin.php?page=ta-assignments&{$params}" ) );
        exit;
    }

    /**
     * [FIX-EDIT-RETURN] مثل redirect() اما در صورت وجود $return_qs (فیلتر/جستجو/صفحه‌ی
     * فعلی که فرم ویرایش از آن باز شده)، کاربر را به همان صفحه با همان فیلترها برمی‌گرداند
     * نه به صفحه‌ی اول فهرست تخصیص‌ها.
     */
    private function redirect_with_return( string $params, string $return_qs ): never {
        // فقط کلیدهای شناخته‌شده‌ی فیلتر را از return_qs می‌پذیریم تا از تزریق پارامتر دلخواه جلوگیری شود
        $allowed = [ 's', 'f_term', 'f_course', 'f_status', 'filter_ta', 'filter_ta_id', 'filter_gender', 'paged' ];
        $clean   = [];
        if ( $return_qs ) {
            parse_str( $return_qs, $parsed );
            foreach ( $allowed as $key ) {
                if ( isset( $parsed[ $key ] ) && $parsed[ $key ] !== '' ) {
                    $clean[ $key ] = sanitize_text_field( wp_unslash( $parsed[ $key ] ) );
                }
            }
        }

        $url = admin_url( "admin.php?page=ta-assignments&{$params}" );
        if ( $clean ) {
            $url .= '&' . http_build_query( $clean );
        }
        wp_redirect( $url );
        exit;
    }

    /**
     * تبدیل نام ترم به کد فصل+سال
     * «بهار 1405» → 105 | «تابستان 1405» → 205 | «پاییز 1405» → 305 | «زمستان 1405» → 405
     */
    private function to_persian_digits( string $str ): string {
        return str_replace(
            ['0','1','2','3','4','5','6','7','8','9'],
            ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
            $str
        );
    }

    private function term_name_to_season_code( string $term_name ): string {
        $season_map = [ 'بهار' => 1, 'تابستان' => 2, 'پاییز' => 3, 'زمستان' => 4 ];
        preg_match( '/(\d{4})/', $term_name, $year_m );
        $year = $year_m[1] ?? '';
        foreach ( $season_map as $fa => $num ) {
            if ( str_contains( $term_name, $fa ) ) {
                return $this->to_persian_digits( $num . substr( $year, -2 ) );
            }
        }
        $slug_map = [ 'spring' => 1, 'summer' => 2, 'fall' => 3, 'autumn' => 3, 'winter' => 4 ];
        foreach ( $slug_map as $en => $num ) {
            if ( str_contains( strtolower( $term_name ), $en ) ) {
                return $this->to_persian_digits( $num . substr( $year, -2 ) );
            }
        }
        return $this->to_persian_digits( substr( $year, -2 ) );
    }

    /**
     * حرف اول نوع دوره به فارسی
     */
    private function course_type_to_letter( string $course_type ): string {
        return [
            'creative'      => 'خ',
            'intro'         => 'م',
            'advanced'      => 'پ',
            'professional'  => 'ح',
            'critique'      => 'ن',
            'basic_writing' => 'ت',
            'ladder'        => 'ن',
            'level_assess'  => 'س',
        ][ $course_type ] ?? mb_substr( $course_type, 0, 1 );
    }


}