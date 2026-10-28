<?php
defined( 'ABSPATH' ) || exit;

/**
 * کمک‌کننده خواندن تنظیمات — در کل سیستم استفاده می‌شود
 * به جای get_option مستقیم، همیشه از این کلاس استفاده کنید
 */
class TA_Settings_Helper {

    private static array $cache = [];

    /** مقادیر پیش‌فرض همه تنظیمات */
    private const DEFAULTS = [
        // عمومی
        'active_term_id'              => 0,
        'system_enabled'              => 'yes',
        'allow_custom_group_link'     => 'no',
        'allow_ta_create_group'       => 'yes',
        'ta_group_creation_message'   => 'ساخت گروه جدید در حال حاضر توسط مسئول اجرایی مبنا مدیریت می‌شود. لطفاً با مسئول اجرایی مبنا تماس بگیرید.',
        'supported_messengers'        => ['telegram','bale','eitaa'],
        // تخصیص
        'allow_reassignment'          => 'no',
        'reassignment_limit_days'     => 3,
        'show_ta_remaining'           => 'yes',
        'sort_ta_by'                  => 'rank',
        'require_purchase'            => 'yes',
        'assignment_auto_status'      => 'active',
        // ظرفیت
        'blacklist_max_percent'       => 10,
        'capacity_warning_threshold'  => 70,
        'capacity_full_threshold'     => 90,
        'auto_sync_capacity'          => 'yes',
        // نمره
        'allow_grade_edit'            => 'yes',
        'grade_edit_limit_days'       => 7,
        'critique_eligible_grades'    => ['pass'],
        'advanced_eligible_grades'    => ['pass'],
        'ladder_eligible_grades'      => ['ladder'],
        'pending_grade_label'         => 'در انتظار ارزیابی',
        // اعلان
        'notify_admin_new_assignment' => 'no',
        'notify_ta_new_student'       => 'no',
        'notify_student_assigned'     => 'no',
        'notify_student_graded'       => 'no',
        'admin_notification_email'    => '',
        'email_from_name'             => '',
        // متن‌ها
        'text_select_ta_title'        => 'انتخاب استادیار',
        'text_no_ta_available'        => 'در حال حاضر ظرفیتی موجود نیست. لطفاً بعداً مراجعه کنید.',
        'text_already_assigned'       => 'شما قبلاً استادیار این دوره را انتخاب کرده‌اید.',
        'text_not_purchased'          => 'برای انتخاب استادیار ابتدا باید این دوره را خریداری کنید.',
        'text_login_required'         => 'برای انتخاب استادیار ابتدا وارد حساب کاربری خود شوید.',
        'text_assignment_success'     => 'استادیار شما با موفقیت ثبت شد. به گروه خوش آمدید!',
        'dashboard_greeting'          => 'خوش آمدید {ta_name} عزیز 🎓',
        // پیشرفته
        'enable_debug_log'            => 'no',
        'story_critique_enabled'      => 'yes',
        'cache_ttl'                   => 300,
        'import_batch_size'           => 30,
        'ta_rank_visible'             => 'no',
        'cleanup_temp_files'          => 'yes',
        'max_log_entries'             => 500,
    ];

    public static function get( string $key, mixed $fallback = null ): mixed {
        if ( isset( self::$cache[ $key ] ) ) return self::$cache[ $key ];
        $default = self::DEFAULTS[ $key ] ?? $fallback;
        $val     = get_option( 'ta_' . $key, $default );
        self::$cache[ $key ] = $val;
        return $val;
    }

    public static function is_enabled( string $key ): bool {
        return self::get( $key ) === 'yes';
    }

    public static function get_text( string $key ): string {
        return (string) self::get( $key, self::DEFAULTS[ $key ] ?? '' );
    }

    /** آیا سیستم فعال است؟ */
    public static function system_active(): bool {
        return self::is_enabled( 'system_enabled' );
    }

    /** مرتب‌سازی ORDER BY برای لیست استادیاران */
    public static function ta_sort_sql(): string {
        return match ( self::get('sort_ta_by') ) {
            'remaining' => 'remaining DESC',
            'name'      => 'ta_name ASC',
            default     => 'ta.rank DESC, cap.student_gender DESC',
        };
    }

    /** حداکثر درصد بلاک */
    public static function blacklist_max_pct(): int {
        return (int) self::get( 'blacklist_max_percent', 10 );
    }

    /** وضعیت پیش‌فرض تخصیص */
    public static function default_assignment_status(): string {
        return self::get( 'assignment_auto_status', 'active' );
    }

    /** ارسال اعلان ایمیل */
    public static function send_notification( string $event, array $data ): void {
        if ( ! self::is_enabled( "notify_{$event}" ) ) return;

        $from_name  = self::get('email_from_name') ?: get_bloginfo('name');
        $from_email = get_option('admin_email');

        add_filter( 'wp_mail_from_name', fn() => $from_name );

        switch ( $event ) {
            case 'admin_new_assignment':
                $to      = self::get('admin_notification_email') ?: get_option('admin_email');
                $subject = 'تخصیص استادیار جدید — ' . get_bloginfo('name');
                $body    = sprintf( "هنرجوی جدیدی استادیار انتخاب کرد.\nهنرجو: %s\nمحصول: %s\nاستادیار: %s",
                    $data['student_name'] ?? '', $data['product_name'] ?? '', $data['ta_name'] ?? '' );
                wp_mail( $to, $subject, $body );
                break;

            case 'admin_pending_assignment':
                $to      = self::get('admin_notification_email') ?: get_option('admin_email');
                $subject = '📬 درخواست تخصیص استادیار — ' . get_bloginfo('name');
                $body    = sprintf(
                    "یک هنرجو درخواست تخصیص استادیار داده است.\n\nهنرجو: %s\nایمیل: %s\nمحصول: %s\nپیام‌رسان: %s\nجنسیت: %s\nتوضیحات: %s\n\nبرای تخصیص استادیار به پنل ادمین مراجعه کنید:\n%s",
                    $data['student_name'] ?? '',
                    $data['student_email'] ?? '',
                    $data['product_name'] ?? '',
                    $data['messenger'] ?? '',
                    $data['gender'] ?? '',
                    $data['notes'] ?? '',
                    admin_url( 'admin.php?page=ta-admin-messages' )
                );
                wp_mail( $to, $subject, $body );
                break;

            case 'ta_new_student':
                $to      = $data['ta_email'] ?? '';
                $subject = 'هنرجوی جدید — ' . get_bloginfo('name');
                $body    = sprintf( "هنرجوی جدیدی به شما تخصیص یافت:\nنام: %s\nمحصول: %s",
                    $data['student_name'] ?? '', $data['product_name'] ?? '' );
                if ( $to ) wp_mail( $to, $subject, $body );
                break;

            case 'student_assigned':
                $to      = $data['student_email'] ?? '';
                $subject = 'تأیید انتخاب استادیار — ' . get_bloginfo('name');
                $body    = sprintf( "استادیار شما ثبت شد.\nاستادیار: %s\nمحصول: %s",
                    $data['ta_name'] ?? '', $data['product_name'] ?? '' );
                if ( $to ) wp_mail( $to, $subject, $body );
                break;

            case 'student_graded':
                $to      = $data['student_email'] ?? '';
                $subject = 'نمره شما ثبت شد — ' . get_bloginfo('name');
                $body    = sprintf( "نمره شما در دوره %s ثبت شد: %s\n%s",
                    $data['product_name'] ?? '', $data['grade_label'] ?? '', $data['notes'] ?? '' );
                if ( $to ) wp_mail( $to, $subject, $body );
                break;
        }

        remove_all_filters( 'wp_mail_from_name' );
    }

    /** لاگ دیباگ */
    public static function log( string $msg ): void {
        if ( self::is_enabled('enable_debug_log') ) {
            error_log( '[TA System] ' . $msg );
        }
    }

    /** پاک کردن کش */
    public static function flush(): void {
        self::$cache = [];
    }
}
