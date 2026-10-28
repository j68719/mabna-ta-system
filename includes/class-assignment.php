<?php
defined( 'ABSPATH' ) || exit;

class TA_Assignment {

    public static function get_available_tas(
        string $course_type,
        int    $term_id,
        string $messenger,
        string $student_gender,
        int    $student_id
    ): array {
        global $wpdb;

        $ta_table    = TA_Database::table( 'assistants' );
        $cap_table   = TA_Database::table( 'capacities' );
        $bl_table    = TA_Database::table( 'blacklist' );
        $group_table = TA_Database::table( 'groups' );

        $is_simple = in_array( $course_type, ['critique', 'professional'], true );

        // کوئری پایه
        if ( $is_simple ) {
            // نقد اثر و حرفه‌ای: بدون فیلتر جنسیت و پیامرسان
            // ظرفیت بر اساس total_capacity سنجیده می‌شود، نه تعداد گروه‌های آزاد
            $sql_base = "
                SELECT
                    ta.*,
                    cap.id             AS capacity_id,
                    cap.total_capacity,
                    cap.used_capacity,
                    cap.student_gender AS cap_gender,
                    (cap.total_capacity - cap.used_capacity) AS remaining,
                    u.display_name     AS ta_name
                FROM {$ta_table}    ta
                JOIN {$cap_table}   cap ON cap.ta_id      = ta.id
                                       AND cap.course_type = %s
                                       AND cap.term_id     = %d
                JOIN {$wpdb->users} u   ON u.ID = ta.user_id
                LEFT JOIN {$bl_table} bl ON bl.ta_id = ta.id AND bl.student_id = %d
                WHERE ta.is_active  = 1
                  AND bl.id         IS NULL
                  AND cap.used_capacity < cap.total_capacity
            ";
            $params = [ $course_type, $term_id, $student_id ];
        } else {
            // سایر دوره‌ها: فیلتر جنسیت و پیامرسان اعمال می‌شود
            $sql_base = "
                SELECT
                    ta.*,
                    cap.id             AS capacity_id,
                    cap.total_capacity,
                    cap.used_capacity,
                    cap.student_gender AS cap_gender,
                    (cap.total_capacity - cap.used_capacity) AS remaining,
                    u.display_name     AS ta_name
                FROM {$ta_table}    ta
                JOIN {$cap_table}   cap ON cap.ta_id       = ta.id
                                       AND cap.course_type  = %s
                                       AND cap.term_id      = %d
                                       AND cap.student_gender IN (%s, 'both')
                                       AND cap.messenger IN (%s, 'both')
                JOIN {$wpdb->users} u   ON u.ID = ta.user_id
                LEFT JOIN {$bl_table} bl ON bl.ta_id = ta.id AND bl.student_id = %d
                WHERE ta.is_active  = 1
                  AND bl.id         IS NULL
                  AND cap.used_capacity < cap.total_capacity
            ";
            $params = [ $course_type, $term_id, $student_gender, $messenger, $student_id ];

            // خلاق و پایه: اگر allow_custom_link غیرفعال بود گروه آزاد لازم است
            $allow_custom_link = TA_Settings_Helper::is_enabled( 'allow_custom_group_link' );
            if ( in_array( $course_type, ['creative', 'basic_writing'], true ) && ! $allow_custom_link ) {
                $sql_base .= "
                    AND EXISTS (
                        SELECT 1 FROM {$group_table} g
                        WHERE g.capacity_id = cap.id
                          AND g.messenger_type = %s
                          AND g.is_used = 0
                    )
                ";
                $params[] = $messenger;
            }
        }

        // مرتب‌سازی نهایی
        $sql_base .= " ORDER BY " . TA_Settings_Helper::ta_sort_sql();

        // اجرای کوئری
        $sql = $wpdb->prepare( $sql_base, ...$params );
        $rows = $wpdb->get_results( $sql );

        if ( ! $rows ) {
            return [];
        }

        // فیلتر نهایی در PHP
        $seen     = [];
        $filtered = [];
        foreach ( $rows as $row ) {
            if ( isset( $seen[ $row->id ] ) ) continue;
            $seen[ $row->id ] = true;
            $filtered[] = $row;
        }

        return $filtered;
    }

    /**
     * تخصیص استادیار به هنرجو — نویسندگی خلاق
     * در صورت نبود گروه آزاد و فعال بودن تنظیمات، لینک را از کاربر دریافت و ثبت می‌کند
     */
    private static function cooperation_allows_assignment( int $ta_id, int $term_id ): array {
        // ترم‌هایی که ادمین «الزام تعیین نوع همکاری» را برایشان خاموش کرده، بررسی نمی‌شوند
        if ( ! (bool) get_option( 'ta_term_require_cooperation_' . $term_id, 1 ) ) return [ true, '' ];
        $setting = get_option( 'ta_cooperation_' . $ta_id . '_' . $term_id, [] );
        $mode = is_array( $setting ) ? (string) ( $setting['mode'] ?? '' ) : '';
        if ( in_array( $mode, [ 'professional', 'critique', 'professional_critique' ], true ) ) return [ true, '' ];
        if ( ! in_array( $mode, [ 'educational', 'development', 'capacity' ], true ) ) return [ false, 'نوع همکاری این ترم ثبت نشده است.' ];
        if ( ( $setting['status'] ?? 'complete' ) === 'incomplete' ) return [ false, 'با حذف ظرفیت، شرایط حداقلی همکاری کمتر شده است؛ لطفاً ظرفیت‌ها را اصلاح کنید تا همکاری دوباره ثبت شود.' ];
        global $wpdb;
        $table=TA_Database::table('capacities'); $rows=$wpdb->get_results($wpdb->prepare("SELECT course_type,total_capacity FROM {$table} WHERE ta_id=%d AND term_id=%d",$ta_id,$term_id));
        $weights=['creative'=>1,'basic_writing'=>1.5,'intro'=>2,'advanced'=>3,'ladder'=>0.5];$total=0;$score=0;$intro=0;$advanced=0;
        foreach($rows as $row){$n=max(0,(int)$row->total_capacity);if(isset($weights[$row->course_type])){$total+=$n;$score+=$n*$weights[$row->course_type];}if($row->course_type==='intro')$intro+=$n;if($row->course_type==='advanced')$advanced+=$n;}
        $ok=$mode==='educational'?($total>=22&&$score>=35):($mode==='development'?($total>=15&&$score>=20&&!empty($setting['commitment'])):($advanced>=20||($total>=32&&$score>=57&&($intro>=12||$advanced>=12))));
        return $ok?[true,'']:[false,'با حذف ظرفیت، شرایط حداقلی همکاری کمتر شده است؛ لطفاً ظرفیت‌ها را اصلاح کنید تا همکاری دوباره ثبت شود.'];
    }

    public static function assign_creative(
        int    $student_id,
        int    $ta_id,
        int    $product_id,
        int    $term_id,
        string $messenger,
        string $student_gender,
        string $custom_group_link = '', // پارامتر جدید برای دریافت لینک کاربر
        string $course_type = 'creative' // نوع دوره — creative یا basic_writing
    ): array {
        global $wpdb;
        [ $cooperation_ok, $cooperation_message ] = self::cooperation_allows_assignment( $ta_id, $term_id );
        if ( ! $cooperation_ok ) return [ 'success' => false, 'message' => '⚠️ تخصیص هنرجوی جدید مسدود است. ' . $cooperation_message ];

        if ( TA_Eligibility::already_assigned( $student_id, $product_id ) ) {
            return [ 'success' => false, 'message' => 'شما قبلاً استادیار انتخاب کرده‌اید.' ];
        }

        $group_table = TA_Database::table( 'groups' );
        $cap_table   = TA_Database::table( 'capacities' );

        // پیدا کردن گروه آزاد
        $is_simple = in_array( $course_type, ['critique', 'professional'], true );

        if ( $is_simple ) {
            // نقد اثر و حرفه‌ای: یک گروه واحد برای همه هنرجوهای ظرفیت — بدون فیلتر is_used، جنسیت و پیامرسان
            $group = $wpdb->get_row( $wpdb->prepare(
                "SELECT g.* FROM {$group_table} g
                 JOIN {$cap_table} c ON c.id = g.capacity_id
                 WHERE g.ta_id = %d
                   AND c.course_type = %s
                   AND c.term_id = %d
                   AND c.used_capacity < c.total_capacity
                 ORDER BY g.id ASC
                 LIMIT 1",
                $ta_id,
                $course_type,
                $term_id
            ) );
        } else {
            // سایر دوره‌ها: با فیلتر جنسیت و پیامرسان
            $group = $wpdb->get_row( $wpdb->prepare(
                "SELECT g.* FROM {$group_table} g
                 JOIN {$cap_table} c ON c.id = g.capacity_id
                 WHERE g.ta_id = %d
                   AND g.messenger_type = %s
                   AND g.is_used = 0
                   AND c.course_type = %s
                   AND c.term_id = %d
                   AND c.student_gender IN (%s, 'both')
                   AND c.used_capacity < c.total_capacity
                 ORDER BY c.student_gender DESC, g.id ASC
                 LIMIT 1",
                $ta_id,
                $messenger,
                $course_type,
                $term_id,
                $student_gender
            ) );
        }

        $group_id         = 0;
        $final_group_link = '';
        $needs_new_group  = false;
        $capacity_id      = 0;

        // اگر گروه آزادی یافت نشد
        if ( ! $group ) {
            // بررسی فعال بودن قابلیت دریافت لینک از کاربر در تنظیمات
            $allow_custom_link = TA_Settings_Helper::is_enabled( 'allow_custom_group_link' );

            if ( $allow_custom_link ) {
                if ( empty( $custom_group_link ) ) {
                    // درخواست لینک از کاربر (بازگشت به فرانت‌اند برای نمایش فرم)
                    $ta_table_name = TA_Database::table( 'assistants' );
                    $ta_wp_user_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$ta_table_name} WHERE id = %d", $ta_id ) );
                    $ta_user       = $ta_wp_user_id ? get_userdata( $ta_wp_user_id ) : null;
                    $ta_full_name  = $ta_user ? $ta_user->display_name : 'استادیار';
                    return [
                        'success'    => false,
                        'needs_link' => true,
                        'ta_name'    => $ta_full_name,
                        'message'    => sprintf( 'استادیار انتخابی شما "%s" لینک گروه آماده ندارد.', $ta_full_name ) . "\n" . 'لطفا در پیامرسانی که انتخاب کرده‌اید یک گروه بسازید و لینک آن را اینجا وارد کنید. به زودی استادیار انتخاب شده به گروه ملحق خواهد شد.'
                    ];
                }

                // پیدا کردن ظرفیت برای ساخت گروه جدید
                $capacity = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id FROM {$cap_table}
                     WHERE ta_id = %d
                       AND course_type = %s
                       AND term_id = %d
                       AND messenger IN (%s, 'both')
                       AND student_gender IN (%s, 'both')
                       AND used_capacity < total_capacity
                     ORDER BY student_gender DESC
                     LIMIT 1",
                    $ta_id, $course_type, $term_id, $messenger, $student_gender
                ) );

                if ( ! $capacity ) {
                    return [ 'success' => false, 'message' => 'متأسفانه ظرفیت این استادیار به طور کامل پر شده است.' ];
                }

                $needs_new_group  = true;
                $capacity_id      = (int) $capacity->id;
                $final_group_link = $custom_group_link;

            } else {
                return [ 'success' => false, 'message' => 'متأسفانه ظرفیت این استادیار با پیامرسان انتخابی پر شده است.' ];
            }
        } else {
            $group_id         = (int) $group->id;
            $final_group_link = $group->group_link;
            $capacity_id      = (int) $group->capacity_id; // مقدارگیری دقیق ظرفیت گروه یافت‌شده
        }

        $wpdb->query( 'START TRANSACTION' );
        try {
            // ساخت گروه جدید با لینک کاربر در صورت نیاز
            if ( $needs_new_group ) {
                TA_Database::insert( 'ta_groups', [
                    'ta_id'               => $ta_id,
                    'capacity_id'         => $capacity_id,
                    'messenger_type'      => $messenger,
                    'group_link'          => $final_group_link,
                    'is_used'             => 1,
                    'assigned_to_user_id' => $student_id,
                    'assigned_at'         => current_time( 'mysql' )
                ] );
                $group_id = $wpdb->insert_id;
            } elseif ( ! $is_simple ) {
                // گروه‌های خلاق/مقدماتی/پیشرفته: گروه اختصاصی هر هنرجو — به‌روزرسانی معمول
                TA_Database::update( 'ta_groups', [
                    'is_used'             => 1,
                    'assigned_to_user_id' => $student_id,
                    'assigned_at'         => current_time( 'mysql' ),
                ], [ 'id' => $group_id ] );
            }
            // نقد اثر و حرفه‌ای ($is_simple): گروه مشترک است — نیازی به تغییر is_used نیست

            // ثبت تخصیص نهایی (تغییر مهم: ذخیره capacity_id انجام شد)
            TA_Database::insert( 'ta_assignments', [
                'student_id'     => $student_id,
                'ta_id'          => $ta_id,
                'capacity_id'    => $capacity_id, // <-- اضافه شدن شماره ظرفیت
                'product_id'     => $product_id,
                'term_id'        => $term_id,
                'course_type'    => $course_type,
                'messenger'      => $messenger,
                'student_gender' => $student_gender,
                'group_id'       => $group_id,
                'group_link'     => $final_group_link,
                'status'         => TA_Settings_Helper::default_assignment_status(),
                'created_at'     => current_time( 'mysql' ),
            ] );

            // افزایش used_capacity — فقط اگر auto_sync_capacity فعال باشد
            if ( TA_Settings_Helper::is_enabled( 'auto_sync_capacity' ) ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$cap_table} SET used_capacity = used_capacity + 1 
                     WHERE id = %d 
                     AND used_capacity < total_capacity",
                    $capacity_id
                ) );
            }

            $wpdb->query( 'COMMIT' );

            TA_Settings_Helper::log( "assign_creative: student={$student_id} ta={$ta_id} product={$product_id}" );
            TA_Settings_Helper::send_notification( 'admin_new_assignment', [
                'student_name' => get_userdata($student_id)->display_name ?? '',
                'ta_name'      => get_userdata($ta_id)->display_name ?? '',
                'product_name' => get_the_title($product_id),
            ] );
            TA_Settings_Helper::send_notification( 'student_assigned', [
                'student_email' => get_userdata($student_id)->user_email ?? '',
                'ta_name'       => get_userdata($ta_id)->display_name ?? '',
                'product_name'  => get_the_title($product_id),
            ] );
            return [
                'success'    => true,
                'message'    => TA_Settings_Helper::get_text('text_assignment_success'),
                'group_link' => $final_group_link,
            ];
            
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            
            TA_Settings_Helper::log( 'assign_creative error: ' . $e->getMessage() );
            return [ 'success' => false, 'message' => 'خطایی رخ داد. لطفاً دوباره تلاش کنید.' ];
        }
    }

    /**
     * تخصیص استادیار — مقدماتی و پیشرفته (گروه از هنرجو می‌آید)
     */
    public static function assign_with_student_group(
        int    $student_id,
        int    $ta_id,
        int    $product_id,
        int    $term_id,
        string $course_type,
        string $messenger,
        string $student_gender,
        string $group_link
    ): array {
        global $wpdb;
        // دورهٔ حرفه‌ای بر اساس ظرفیت و گروه خودش تخصیص می‌یابد و به ثبت
        // «نوع همکاری» استادیار وابسته نیست.
        if ( $course_type !== 'professional' ) {
            [ $cooperation_ok, $cooperation_message ] = self::cooperation_allows_assignment( $ta_id, $term_id );
            if ( ! $cooperation_ok ) return [ 'success' => false, 'message' => '⚠️ تخصیص هنرجوی جدید مسدود است. ' . $cooperation_message ];
        }

        if ( TA_Eligibility::already_assigned( $student_id, $product_id ) ) {
            return [ 'success' => false, 'message' => 'شما قبلاً استادیار انتخاب کرده‌اید.' ];
        }

        $cap_table = TA_Database::table( 'capacities' );

        $wpdb->query( 'START TRANSACTION' );
        try {

            // تغییر مهم: ابتدا ظرفیت مناسب را پیدا می‌کنیم تا capacity_id را داشته باشیم
            // نقد اثر و حرفه‌ای: بدون فیلتر جنسیت و پیامرسان (ظرفیت کلی)
            $is_simple_course = in_array( $course_type, [ 'critique', 'professional' ], true );

            if ( $is_simple_course ) {
                $capacity = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id FROM {$cap_table}
                     WHERE ta_id = %d
                       AND term_id = %d
                       AND course_type = %s
                       AND used_capacity < total_capacity
                     ORDER BY id ASC
                     LIMIT 1",
                    $ta_id, $term_id, $course_type
                ) );
            } else {
                $capacity = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id FROM {$cap_table}
                     WHERE ta_id = %d 
                       AND term_id = %d 
                       AND course_type = %s
                       AND messenger IN (%s, 'both')
                       AND (student_gender = %s OR student_gender = 'both')
                       AND used_capacity < total_capacity
                     ORDER BY (student_gender = 'both') ASC, (messenger = 'both') ASC
                     LIMIT 1",
                    $ta_id, $term_id, $course_type, $messenger, $student_gender
                ) );
            }

            if ( ! $capacity ) {
                $wpdb->query( 'ROLLBACK' );
                return [ 'success' => false, 'message' => 'متأسفانه ظرفیت این استادیار پر شده است.' ];
            }

            $capacity_id = (int) $capacity->id;

            // ثبت رکورد همراه با capacity_id
            TA_Database::insert( 'ta_assignments', [
                'student_id'     => $student_id,
                'ta_id'          => $ta_id,
                'capacity_id'    => $capacity_id, // <-- اضافه شدن شماره ظرفیت
                'product_id'     => $product_id,
                'term_id'        => $term_id,
                'course_type'    => $course_type,
                'messenger'      => $messenger,
                'student_gender' => $student_gender,
                'group_id'       => null,
                'group_link'     => $group_link,
                'status'         => TA_Settings_Helper::default_assignment_status(),
                'created_at'     => current_time( 'mysql' ),
            ] );

            // تخصیص ظرفیت — فقط اگر auto_sync_capacity فعال باشد
            if ( TA_Settings_Helper::is_enabled( 'auto_sync_capacity' ) ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$cap_table}
                     SET used_capacity = used_capacity + 1
                     WHERE id = %d",
                    $capacity_id
                ) );
            }

            $wpdb->query( 'COMMIT' );
            TA_Settings_Helper::log( "assign_with_group: student={$student_id} product={$product_id}" );
            TA_Settings_Helper::send_notification( 'admin_new_assignment', [
                'student_name' => get_userdata($student_id)->display_name ?? '',
                'ta_name'      => get_userdata($ta_id)->display_name ?? '',
                'product_name' => get_the_title($product_id),
            ] );
            TA_Settings_Helper::send_notification( 'student_assigned', [
                'student_email' => get_userdata($student_id)->user_email ?? '',
                'ta_name'       => get_userdata($ta_id)->display_name ?? '',
                'product_name'  => get_the_title($product_id),
            ] );
            return [ 'success' => true, 'message' => TA_Settings_Helper::get_text('text_assignment_success') ];
        } catch ( \Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            return [ 'success' => false, 'message' => 'خطایی رخ داد. لطفاً دوباره تلاش کنید.' ];
        }
    }

    /**
     * دریافت تخصیص هنرجو برای یک محصول
     */
    public static function get_assignment( int $student_id, int $product_id ): ?object {
        $table = TA_Database::table( 'assignments' );
        return TA_Database::get_row(
            "SELECT a.*, u.display_name AS ta_name
             FROM {$table} a
             LEFT JOIN {$GLOBALS['wpdb']->prefix}ta_assistants ta ON ta.id = a.ta_id
             LEFT JOIN {$GLOBALS['wpdb']->users} u ON u.ID = ta.user_id
             WHERE a.student_id = %d AND a.product_id = %d
             ORDER BY a.id DESC LIMIT 1",
            [ $student_id, $product_id ]
        );
    }

    /**
     * یافتن تخصیص دوره پیش‌نیازِ مرجعِ پیام‌رسان/لینک گروه برای یک محصول
     * (همان قاعده‌ای که در get_available_tas / assign_with_group / assign_ladder استفاده می‌شود:
     *  اولین پیش‌نیازی که تخصیص فعال دارد، با این استثنا که برای «پیشرفته»، تخصیص «نقد اثر» نادیده گرفته می‌شود)
     */
    public static function find_prereq_assignment( int $student_id, int $product_id, string $course_type = '' ): ?object {
        $prerequisites = TA_Product_Meta::get_prerequisites( $product_id );

        foreach ( (array) $prerequisites as $prereq_id ) {
            $assignment = self::get_assignment( $student_id, (int) $prereq_id );
            if ( ! $assignment ) continue;

            if ( $course_type === 'advanced' && ( $assignment->course_type ?? '' ) === 'critique' ) {
                continue;
            }

            if ( ! empty( $assignment->messenger ) || ! empty( $assignment->group_link ) ) {
                return $assignment;
            }
        }

        return null;
    }

    // [BUGFIX-REMOVED] assign_professional_course() حذف شد.
    // این متد به ستون‌هایی (`capacity`, `term`) ارجاع می‌داد که در اسکیمای واقعی دیتابیس
    // وجود نداشتند (نام صحیح: total_capacity, term_id) و همچنین در هیچ‌جای دیگر کد
    // فراخوانی نمی‌شد (کد مرده). مسیر واقعی تخصیص دوره حرفه‌ای از طریق
    // TA_Assignment::assign_with_student_group() در TA_Ajax::assign_professional() انجام می‌شود.
/**
 * تخصیص استاندارد بر اساس ظرفیت
 * برای دوره‌های: standard, critique, professional
 *
 * @return array ['success'=>bool, 'message'=>string, 'group_link'=>string|null]
 */



public static function assign_standard_with_capacity(
    int $student_id,
    int $ta_id,
    int $product_id,
    int $capacity_id,
    string $messenger,
    string $student_gender,
    string $custom_group_link = ''
): array {
    global $wpdb;

    // وضعیت همکاری پس از خواندن ظرفیت کنترل می‌شود؛ term_id از خود ظرفیت گرفته می‌شود.

    // ۱. بررسی واجد شرایط بودن هنرجو
    $eligibility = TA_Eligibility::check( $student_id, $product_id );
    if ( ! $eligibility['eligible'] ) {
        return [
            'success'    => false,
            'message'    => $eligibility['message'],
            'group_link' => null
        ];
    }

    // ۲. دریافت اطلاعات ظرفیت
    $cap_table = TA_Database::table( 'capacities' );
    $capacity  = $wpdb->get_row( $wpdb->prepare(
        "SELECT ta_id, course_type, term_id, student_gender, messenger,
                total_capacity, used_capacity
         FROM {$cap_table}
         WHERE id = %d",
        $capacity_id
    ) );
    if ( ! $capacity ) return [ 'success' => false, 'message' => 'ظرفیت یافت نشد.', 'group_link' => null ];
    if ( ! $capacity ) {
        return [
            'success'    => false,
            'message'    => 'ظرفیت مورد نظر یافت نشد.',
            'group_link' => null
        ];
    }

    // ۳. بررسی تطابق ta_id
    if ( (int) $capacity->ta_id !== $ta_id ) {
        return [
            'success'    => false,
            'message'    => 'ظرفیت انتخابی متعلق به این استادیار نیست.',
            'group_link' => null
        ];
    }

    // ۴. بررسی ظرفیت باقیمانده
    if ( $capacity->used_capacity >= $capacity->total_capacity ) {
        return [
            'success'    => false,
            'message'    => 'ظرفیت این استادیار تکمیل شده است.',
            'group_link' => null
        ];
    }

    // ۵. بررسی تطابق جنسیت
    if ( $capacity->student_gender !== 'both' && $capacity->student_gender !== $student_gender ) {
        return [
            'success'    => false,
            'message'    => 'جنسیت هنرجو با ظرفیت استادیار مطابقت ندارد.',
            'group_link' => null
        ];
    }

    // ۶. بررسی تطابق پیام‌رسان
    if ( $capacity->messenger !== 'both' && $capacity->messenger !== $messenger ) {
        return [
            'success'    => false,
            'message'    => 'پیام‌رسان انتخابی با ظرفیت استادیار مطابقت ندارد.',
            'group_link' => null
        ];
    }



    // ۷. یافتن لینک گروه
    $groups_table = TA_Database::table( 'groups' );
    $course_type_now = $capacity->course_type;
    $group_id   = 0;
    $group_link = '';

    $is_simple_course = in_array( $course_type_now, [ 'critique', 'professional' ], true );

    if ( $is_simple_course ) {
        // نقد اثر و حرفه‌ای: یک گروه مشترک برای همه هنرجوهای این ظرفیت
        // گروه is_used نمی‌شود — همه هنرجوها لینک همان گروه را می‌گیرند
        $group = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, group_link
             FROM {$groups_table}
             WHERE capacity_id = %d
             ORDER BY id ASC
             LIMIT 1",
            $capacity_id
        ) );
        if ( $group ) {
            $group_id   = (int) $group->id;
            $group_link = $group->group_link;
        }
        // اگر گروهی تعریف نشده بود، بدون لینک ادامه می‌دهیم — ظرفیت used_capacity کافی است
    } else {
        // سایر دوره‌ها: گروه آزاد الزامی است
        $group = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, group_link
             FROM {$groups_table}
             WHERE capacity_id = %d
               AND messenger_type = %s
               AND is_used = 0
             LIMIT 1",
            $capacity_id,
            $messenger
        ) );

        if ( $group ) {
            $group_id   = (int) $group->id;
            $group_link = $group->group_link;
        } elseif ( ! empty( $custom_group_link ) ) {
            $group_link = $custom_group_link;
        } else {
            if ( $course_type_now === 'creative' || $course_type_now === 'basic_writing' ) {
                $allow_custom = TA_Settings_Helper::is_enabled( 'allow_custom_group_link' );
                if ( $allow_custom ) {
                    $ta_tbl_2       = TA_Database::table( 'assistants' );
                    $ta_wp_uid_2    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$ta_tbl_2} WHERE id = %d", $ta_id ) );
                    $ta_user_2      = $ta_wp_uid_2 ? get_userdata( $ta_wp_uid_2 ) : null;
                    $ta_full_name_2 = $ta_user_2 ? $ta_user_2->display_name : 'استادیار';
                    return [
                        'success'    => false,
                        'needs_link' => true,
                        'ta_name'    => $ta_full_name_2,
                        'message'    => sprintf( 'استادیار انتخابی شما "%s" لینک گروه آماده ندارد.', $ta_full_name_2 ) . "\n" . 'لطفا در پیامرسانی که انتخاب کرده‌اید یک گروه بسازید و لینک آن را اینجا وارد کنید.'
                    ];
                }
                return [
                    'success'    => false,
                    'message'    => 'گروه آزاد برای این ظرفیت موجود نیست.',
                    'group_link' => null
                ];
            }
            return [
                'success'    => false,
                'message'    => 'لینک گروه برای این ظرفیت و پیام‌رسان موجود نیست.',
                'group_link' => null
            ];
        }
    }

    // ۸. ثبت تخصیص
    $wpdb->query( 'START TRANSACTION' );
    try {
        // ۹. بررسی وضعیت گروه
        if ( $is_simple_course ) {
            // نقد اثر و حرفه‌ای: گروه مشترک است — is_used نمی‌شود
            // همه هنرجوها به همان گروه تخصیص می‌گیرند، گروه باز می‌ماند
        } elseif ( $group_id > 0 ) {
            // سایر دوره‌ها: گروه اختصاصی — علامت‌گذاری به عنوان مصرف‌شده
            TA_Database::update( 'ta_groups', [
                'is_used'             => 1,
                'assigned_to_user_id' => $student_id,
                'assigned_at'         => current_time( 'mysql' ),
            ], [
                'id' => $group_id,
            ] );
        } elseif ( ! empty( $group_link ) ) {
            // ساخت گروه جدید برای لینک‌های وارد شده توسط کاربر (از جمله خلاق)
            TA_Database::insert( 'ta_groups', [
                'ta_id'               => $ta_id,
                'capacity_id'         => $capacity_id,
                'messenger_type'      => $messenger,
                'group_link'          => $group_link,
                'is_used'             => 1,
                'assigned_to_user_id' => $student_id,
                'assigned_at'         => current_time( 'mysql' )
            ] );
            $group_id = $wpdb->insert_id; // دریافت آیدی گروه جدید
        }

        // ثبت در جدول تخصیص‌ها
        TA_Database::insert( 'ta_assignments', [
            'student_id'     => $student_id,
            'ta_id'          => $ta_id,
            'product_id'     => $product_id,
            'term_id'        => $capacity->term_id,
            'capacity_id'    => $capacity_id,
            'course_type'    => $capacity->course_type,
            'messenger'      => $messenger,
            'student_gender' => $student_gender,
            'group_id'       => $group_id, // حالا آیدی به درستی ثبت می‌شود
            'group_link'     => $group_link,
            'status'         => 'active',
            'created_at'     => current_time( 'mysql' ),
        ] );

        

        // ۱۰. افزایش ظرفیت استفاده‌شده
        // [SECURITY-FIX-RACE] شرط محافظتی used_capacity < total_capacity اضافه شد تا در صورت
        // درخواست‌های هم‌زمان، ظرفیت هرگز از سقف مجاز عبور نکند (race condition قبلی)
        $updated_rows = $wpdb->query( $wpdb->prepare(
            "UPDATE {$cap_table}
             SET used_capacity = used_capacity + 1
             WHERE id = %d
               AND used_capacity < total_capacity",
            $capacity_id
        ) );

        if ( ! $updated_rows ) {
            // ظرفیت دقیقاً در همین لحظه توسط یک درخواست هم‌زمان دیگر تکمیل شده است
            $wpdb->query( 'ROLLBACK' );
            return [
                'success'    => false,
                'message'    => 'ظرفیت این استادیار درست در همین لحظه تکمیل شد. لطفاً ظرفیت دیگری انتخاب کنید.',
                'group_link' => null,
            ];
        }

        $wpdb->query( 'COMMIT' );

        return [
            'success'    => true,
            'message'    => 'تخصیص با موفقیت انجام شد.',
            'group_link' => $group_link,
        ];

    } catch ( \Throwable $e ) {
        $wpdb->query( 'ROLLBACK' );
        return [
            'success'    => false,
            'message'    => 'خطا در ثبت تخصیص. لطفاً دوباره تلاش کنید.',
            'group_link' => null,
        ];
    }
}







}
