<?php
defined( 'ABSPATH' ) || exit;

/**
 * صفحه تنظیمات حرفه‌ای سیستم استادیار
 *
 * گروه‌های تنظیمات:
 * 1. عمومی   — رفتار پایه سیستم
 * 2. تخصیص   — قوانین و محدودیت‌های تخصیص
 * 3. ظرفیت   — مدیریت ظرفیت و لیست سیاه
 * 4. نمره    — قوانین ثبت و نمایش نمره
 * 5. اعلان   — ایمیل‌ها و پیام‌های سیستم
 * 6. ظاهر    — متن‌های قابل سفارشی
 * 7. پیشرفته — دیباگ و عملکرد
 */
class TA_Settings {

    /** پیشوند تمام option ها */
    private const PFX = 'ta_';

    /** تعریف کامل تمام تنظیمات */
    private const SCHEMA = [

        // ─── عمومی ───────────────────────────────────────────────────────────
        'general' => [
            'label' => 'عمومی',
            'icon'  => '⚙️',
            'fields' => [
                'active_term_id' => [
                    'type'    => 'select_term',
                    'label'   => 'ترم فعال پیش‌فرض',
                    'desc'    => 'ترمی که هنگام ثبت تخصیص جدید به‌طور پیش‌فرض استفاده می‌شود.',
                    'default' => 0,
                ],
                'system_enabled' => [
                    'type'    => 'toggle',
                    'label'   => 'سیستم فعال است',
                    'desc'    => 'غیرفعال کردن این گزینه باعث می‌شود هنرجویان نتوانند استادیار انتخاب کنند.',
                    'default' => 'yes',
                ],
                'allow_custom_group_link' => [
                    'type'    => 'toggle',
                    'label'   => 'لینک گروه اختصاصی هنرجو',
                    'desc'    => 'در صورت پر بودن ظرفیت گروه‌های خلاق، هنرجو بتواند لینک گروه خود را وارد کند.',
                    'default' => 'no',
                ],
                'allow_ta_create_group' => [
                    'type'    => 'toggle',
                    'label'   => 'اجازه ساخت گروه خلاق توسط استادیار',
                    'desc'    => 'اگر فعال باشد، استادیار می‌تواند در داشبورد خود گروه جدید برای دوره خلاق بسازد. غیرفعال کردن این گزینه ساخت گروه را فقط در اختیار ادمین قرار می‌دهد.',
                    'default' => 'yes',
                ],
                'ta_group_creation_message' => [
                    'type'    => 'text',
                    'label'   => 'پیام محدودیت ساخت گروه',
                    'desc'    => 'پیامی که هنگام غیرفعال بودن ساخت گروه به استادیار نمایش داده می‌شود.',
                    'default' => 'ساخت گروه جدید در حال حاضر توسط ادمین مدیریت می‌شود. لطفاً با ادمین تماس بگیرید.',
                ],
                'supported_messengers' => [
                    'type'    => 'checkboxes',
                    'label'   => 'پیامرسان‌های فعال',
                    'desc'    => 'پیامرسان‌هایی که در فرم انتخاب استادیار نمایش داده می‌شوند.',
                    'options' => ['telegram'=>'تلگرام','bale'=>'بله','eitaa'=>'ایتا'],
                    'default' => ['telegram','bale','eitaa'],
                ],
            ],
        ],

        // ─── تخصیص ───────────────────────────────────────────────────────────
        'assignment' => [
            'label' => 'تخصیص',
            'icon'  => '🔗',
            'fields' => [
                'allow_reassignment' => [
                    'type'    => 'toggle',
                    'label'   => 'اجازه تغییر استادیار',
                    'desc'    => 'هنرجو بتواند استادیار قبلاً انتخاب‌شده را تغییر دهد.',
                    'default' => 'no',
                ],
                'reassignment_limit_days' => [
                    'type'    => 'number',
                    'label'   => 'مهلت تغییر استادیار (روز)',
                    'desc'    => 'هنرجو تا چند روز پس از انتخاب اولیه می‌تواند استادیار را تغییر دهد. (0 = بدون محدودیت)',
                    'default' => 3,
                    'min'     => 0,
                    'max'     => 30,
                    'depends' => 'allow_reassignment',
                ],
                'show_ta_remaining' => [
                    'type'    => 'toggle',
                    'label'   => 'نمایش ظرفیت باقیمانده',
                    'desc'    => 'تعداد جای خالی هر استادیار در فرم انتخاب نمایش داده شود.',
                    'default' => 'yes',
                ],
                'sort_ta_by' => [
                    'type'    => 'select',
                    'label'   => 'مرتب‌سازی لیست استادیاران',
                    'options' => [
                        'rank'      => 'رتبه استادیار',
                        'remaining' => 'بیشترین ظرفیت خالی',
                        'name'      => 'نام (الفبا)',
                    ],
                    'default' => 'rank',
                ],
                'require_purchase' => [
                    'type'    => 'toggle',
                    'label'   => 'بررسی خرید قبل از تخصیص',
                    'desc'    => 'قبل از تخصیص استادیار، خرید محصول توسط هنرجو تأیید شود.',
                    'default' => 'yes',
                ],
                'assignment_auto_status' => [
                    'type'    => 'select',
                    'label'   => 'وضعیت پیش‌فرض تخصیص جدید',
                    'options' => [
                        'active'  => 'فعال (active)',
                        'pending' => 'در انتظار تأیید (pending)',
                    ],
                    'default' => 'active',
                ],
            ],
        ],

        // ─── ظرفیت ───────────────────────────────────────────────────────────
        'capacity' => [
            'label' => 'ظرفیت و لیست سیاه',
            'icon'  => '📦',
            'fields' => [
                'blacklist_max_percent' => [
                    'type'    => 'number',
                    'label'   => 'حداکثر درصد لیست سیاه',
                    'desc'    => 'هر استادیار حداکثر چند درصد از هنرجویان ترم را می‌تواند بلاک کند.',
                    'default' => 10,
                    'min'     => 1,
                    'max'     => 50,
                    'suffix'  => '%',
                ],
                'capacity_warning_threshold' => [
                    'type'    => 'number',
                    'label'   => 'آستانه هشدار ظرفیت',
                    'desc'    => 'وقتی درصد پر بودن ظرفیت از این مقدار بیشتر شد، رنگ نارنجی نشان داده شود.',
                    'default' => 70,
                    'min'     => 10,
                    'max'     => 99,
                    'suffix'  => '%',
                ],
                'capacity_full_threshold' => [
                    'type'    => 'number',
                    'label'   => 'آستانه ظرفیت پر',
                    'desc'    => 'از این درصد به بالا ظرفیت «پر» تلقی می‌شود و رنگ قرمز نمایش داده می‌شود.',
                    'default' => 90,
                    'min'     => 50,
                    'max'     => 100,
                    'suffix'  => '%',
                ],
                'auto_sync_capacity' => [
                    'type'    => 'toggle',
                    'label'   => 'همگام‌سازی خودکار ظرفیت',
                    'desc'    => 'هر بار که تخصیص ثبت یا لغو می‌شود، used_capacity به‌طور خودکار به‌روز شود.',
                    'default' => 'yes',
                ],
            ],
        ],

        // ─── نمره ────────────────────────────────────────────────────────────
        'grade' => [
            'label' => 'نمره‌دهی',
            'icon'  => '🎓',
            'fields' => [
                'ta_grading_enabled' => [
                    'type'    => 'toggle',
                    'label'   => 'فعال بودن نمره‌دهی توسط استادیار',
                    'desc'    => 'اگر غیرفعال شود، استادیار قادر به ثبت یا ویرایش هیچ نمره‌ای نخواهد بود.',
                    'default' => 'yes',
                ],
                'allow_grade_edit' => [
                    'type'    => 'toggle',
                    'label'   => 'اجازه ویرایش نمره',
                    'desc'    => 'استادیار بتواند نمره‌ای که قبلاً ثبت کرده را ویرایش کند.',
                    'default' => 'yes',
                ],
                'grade_edit_limit_days' => [
                    'type'    => 'number',
                    'label'   => 'مهلت ویرایش نمره (روز)',
                    'desc'    => 'استادیار تا چند روز پس از ثبت نمره می‌تواند آن را تغییر دهد. (0 = همیشه)',
                    'default' => 7,
                    'min'     => 0,
                    'max'     => 90,
                    'depends' => 'allow_grade_edit',
                ],
                'critique_eligible_grades' => [
                    'type'    => 'checkboxes',
                    'label'   => 'نمره‌های مجاز برای ورود به نقد اثر',
                    'desc'    => 'هنرجو با کدام نمره‌هایی از مقدماتی می‌تواند به دوره نقد اثر برود.',
                    'options' => ['pass' => 'قبول'],
                    'default' => ['pass'],
                ],
                'advanced_eligible_grades' => [
                    'type'    => 'checkboxes',
                    'label'   => 'نمره‌های مجاز برای ورود به پیشرفته',
                    'desc'    => 'هنرجو با کدام نمره‌هایی از مقدماتی می‌تواند به پیشرفته برود.',
                    'options' => ['pass' => 'قبول', 'repeat_critique' => 'تکرار نقد اثر'],
                    'default' => ['pass'],
                ],
                'ladder_eligible_grades' => [
                    'type'    => 'checkboxes',
                    'label'   => 'نمره‌های مجاز برای ورود به نردبان حرفه‌ای',
                    'desc'    => 'هنرجو با کدام نمره‌هایی از پیشرفته می‌تواند به نردبان حرفه‌ای برود.',
                    'options' => ['ladder' => 'نردبان حرفه‌ای'],
                    'default' => ['ladder'],
                ],
                'pending_grade_label' => [
                    'type'    => 'text',
                    'label'   => 'برچسب نمره «در انتظار»',
                    'desc'    => 'متنی که به جای «در انتظار» نمایش داده می‌شود.',
                    'default' => 'در انتظار ارزیابی',
                ],
            ],
        ],

        // ─── اعلان ───────────────────────────────────────────────────────────
        'notification' => [
            'label' => 'اعلان‌ها',
            'icon'  => '🔔',
            'fields' => [
                'notify_admin_new_assignment' => [
                    'type'    => 'toggle',
                    'label'   => 'اعلان ایمیل به ادمین — تخصیص جدید',
                    'desc'    => 'هر بار که هنرجوی جدیدی استادیار انتخاب کرد، به ادمین ایمیل ارسال شود.',
                    'default' => 'no',
                ],
                'notify_admin_pending_assignment' => [
                    'type'    => 'toggle',
                    'label'   => 'اعلان ایمیل به ادمین — درخواست تخصیص در انتظار',
                    'desc'    => 'هر بار که هنرجویی دکمه «ادامه بدون استادیار قبلی» را زد، ایمیل به ادمین ارسال شود.',
                    'default' => 'yes',
                ],
                'notify_ta_new_student' => [
                    'type'    => 'toggle',
                    'label'   => 'اعلان ایمیل به استادیار — هنرجوی جدید',
                    'desc'    => 'هنگام تخصیص هنرجوی جدید، به استادیار ایمیل ارسال شود.',
                    'default' => 'no',
                ],
                'notify_student_assigned' => [
                    'type'    => 'toggle',
                    'label'   => 'اعلان ایمیل به هنرجو — تأیید تخصیص',
                    'desc'    => 'پس از ثبت موفق تخصیص، هنرجو ایمیل تأیید دریافت کند.',
                    'default' => 'no',
                ],
                'notify_student_graded' => [
                    'type'    => 'toggle',
                    'label'   => 'اعلان ایمیل به هنرجو — ثبت نمره',
                    'desc'    => 'هنگامی که استادیار نمره ثبت کرد، هنرجو ایمیل دریافت کند.',
                    'default' => 'no',
                ],
                'admin_notification_email' => [
                    'type'    => 'email',
                    'label'   => 'ایمیل دریافت اعلان‌های ادمین',
                    'desc'    => 'خالی بگذارید تا از ایمیل پیش‌فرض سایت استفاده شود.',
                    'default' => '',
                ],
                'email_from_name' => [
                    'type'    => 'text',
                    'label'   => 'نام فرستنده ایمیل',
                    'desc'    => 'نامی که در فیلد «از طرف» ایمیل‌های سیستم نمایش داده می‌شود.',
                    'default' => '',
                ],
            ],
        ],

        // ─── ظاهر / متن‌ها ───────────────────────────────────────────────────
        'ui' => [
            'label' => 'متن‌ها',
            'icon'  => '✏️',
            'fields' => [
                'text_select_ta_title' => [
                    'type'    => 'text',
                    'label'   => 'عنوان فرم انتخاب استادیار',
                    'default' => 'انتخاب استادیار',
                ],
                'text_no_ta_available' => [
                    'type'    => 'text',
                    'label'   => 'پیام «استادیاری موجود نیست»',
                    'default' => 'در حال حاضر ظرفیتی موجود نیست. لطفاً بعداً مراجعه کنید.',
                ],
                'text_already_assigned' => [
                    'type'    => 'text',
                    'label'   => 'پیام «قبلاً استادیار انتخاب شده»',
                    'default' => 'شما قبلاً استادیار این دوره را انتخاب کرده‌اید.',
                ],
                'text_not_purchased' => [
                    'type'    => 'text',
                    'label'   => 'پیام «محصول خریداری نشده»',
                    'default' => 'برای انتخاب استادیار ابتدا باید این دوره را خریداری کنید.',
                ],
                'text_login_required' => [
                    'type'    => 'text',
                    'label'   => 'پیام «لطفاً وارد شوید»',
                    'default' => 'برای انتخاب استادیار ابتدا وارد حساب کاربری خود شوید.',
                ],
                'text_assignment_success' => [
                    'type'    => 'text',
                    'label'   => 'پیام موفقیت تخصیص',
                    'default' => 'استادیار شما با موفقیت ثبت شد. به گروه خوش آمدید!',
                ],
                'dashboard_greeting' => [
                    'type'    => 'text',
                    'label'   => 'پیام خوش‌آمد داشبورد استادیار',
                    'default' => 'خوش آمدید {ta_name} عزیز 🎓',
                    'desc'    => 'از {ta_name} برای درج نام استادیار استفاده کنید.',
                ],
            ],
        ],

        // ─── پنل استادیار ───────────────────────────────────────────────────────
        'ta_panel' => [
            'label' => 'پنل استادیار',
            'icon'  => '🎓',
            'fields' => [
                'story_critique_enabled' => [
                    'type'    => 'toggle',
                    'label'   => 'فعال بودن درخواست نقد آثار',
                    'desc'    => 'دکمه «درخواست نقد» در تب نقد آثار پنل استادیار نمایش داده شود.',
                    'default' => 'yes',
                ],
            ],
        ],

        // ─── پنل استادیار ───────────────────────────────────────────────────────
        'ta_panel' => [
            'label' => 'پنل استادیار',
            'icon'  => '🎓',
            'fields' => [
                'story_critique_enabled' => [
                    'type'    => 'toggle',
                    'label'   => 'فعال بودن درخواست نقد آثار',
                    'desc'    => 'دکمه «درخواست نقد» در تب نقد آثار پنل استادیار نمایش داده شود.',
                    'default' => 'yes',
                ],
            ],
        ],

        // ─── پیشرفته ─────────────────────────────────────────────────────────
        'advanced' => [
            'label' => 'پیشرفته',
            'icon'  => '🔧',
            'fields' => [
                'enable_debug_log' => [
                    'type'    => 'toggle',
                    'label'   => 'ثبت لاگ دیباگ',
                    'desc'    => 'خطاها و رویدادهای سیستم در WP Debug Log ثبت شوند.',
                    'default' => 'no',
                ],
                'cache_ttl' => [
                    'type'    => 'number',
                    'label'   => 'مدت کش تنظیمات (ثانیه)',
                    'desc'    => 'تنظیمات محصول برای چند ثانیه کش می‌شوند. (0 = بدون کش)',
                    'default' => 300,
                    'min'     => 0,
                    'max'     => 3600,
                ],
                'import_batch_size' => [
                    'type'    => 'number',
                    'label'   => 'حجم هر دسته ایمپورت',
                    'desc'    => 'تعداد ردیف‌هایی که در هر مرحله از ایمپورت دسته‌ای پردازش می‌شوند.',
                    'default' => 30,
                    'min'     => 5,
                    'max'     => 200,
                ],
                'ta_rank_visible' => [
                    'type'    => 'toggle',
                    'label'   => 'نمایش رتبه استادیار به هنرجو',
                    'desc'    => 'ستاره‌های رتبه هر استادیار در فرم انتخاب نمایش داده شود.',
                    'default' => 'no',
                ],
                'cleanup_temp_files' => [
                    'type'    => 'toggle',
                    'label'   => 'پاک‌سازی خودکار فایل‌های موقت',
                    'desc'    => 'فایل‌های JSON موقت ایمپورت بعد از اتمام به‌طور خودکار حذف شوند.',
                    'default' => 'yes',
                ],
                'max_log_entries' => [
                    'type'    => 'number',
                    'label'   => 'حداکثر ردیف لاگ ایمپورت',
                    'desc'    => 'تعداد ردیف‌های لاگی که پس از اتمام ایمپورت نمایش داده می‌شود.',
                    'default' => 500,
                    'min'     => 50,
                    'max'     => 5000,
                ],
            ],
        ],
    ];

    /** جلوگیری از ثبت تکراری هوک‌ها هنگام چند بار نمونه‌سازی [FIX-DOUBLE-SETTINGS] */
    private static bool $hooks_registered = false;

    public function __construct() {
        if ( ! self::$hooks_registered ) {
            add_action( 'admin_post_ta_save_settings',  [ $this, 'handle_save' ] );
            add_action( 'admin_post_ta_reset_settings', [ $this, 'handle_reset' ] );
            self::$hooks_registered = true;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ذخیره تنظیمات
    // ─────────────────────────────────────────────────────────────────────────

    public function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );
        check_admin_referer( 'ta_save_settings' );

        $tab = sanitize_key( $_POST['current_tab'] ?? 'general' );

        if ( isset( self::SCHEMA[ $tab ] ) ) {
            foreach ( self::SCHEMA[ $tab ]['fields'] as $key => $field ) {
                $option_key = self::PFX . $key;
                $val        = $this->sanitize_field( $field, $_POST[ $option_key ] ?? null );
                update_option( $option_key, $val );
            }
        }

        // [FIX-CACHE] پاک‌سازی کش تنظیمات پس از ذخیره
        TA_Settings_Helper::flush();

        wp_safe_redirect( add_query_arg( [ 'page' => 'ta-settings', 'tab' => $tab, 'saved' => 1 ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ریست به پیش‌فرض
    // ─────────────────────────────────────────────────────────────────────────

    public function handle_reset(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );
        check_admin_referer( 'ta_reset_settings', '_wpnonce_reset' );

        $tab = sanitize_key( $_POST['reset_tab'] ?? 'general' );

        if ( isset( self::SCHEMA[ $tab ] ) ) {
            foreach ( self::SCHEMA[ $tab ]['fields'] as $key => $field ) {
                update_option( self::PFX . $key, $field['default'] );
            }
        }

        // [FIX-CACHE] پاک‌سازی کش تنظیمات پس از ریست
        TA_Settings_Helper::flush();

        wp_safe_redirect( add_query_arg( [ 'page' => 'ta-settings', 'tab' => $tab, 'reset' => 1 ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sanitize
    // ─────────────────────────────────────────────────────────────────────────

    private function sanitize_field( array $field, mixed $raw ): mixed {
        return match ( $field['type'] ) {
            'toggle'      => ( $raw === 'yes' ) ? 'yes' : 'no',
            'number'      => max( $field['min'] ?? 0, min( $field['max'] ?? 9999, (int) $raw ) ),
            'select'      => array_key_exists( $raw, $field['options'] ?? [] ) ? $raw : $field['default'],
            'select_term' => absint( $raw ),
            'checkboxes'  => array_values( array_intersect( (array) $raw, array_keys( $field['options'] ?? [] ) ) ),
            'text'        => sanitize_text_field( $raw ?? '' ),
            'email'       => sanitize_email( $raw ?? '' ),
            default       => sanitize_text_field( $raw ?? '' ),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // خواندن یک option
    // ─────────────────────────────────────────────────────────────────────────

    public static function get( string $key, mixed $fallback = null ): mixed {
        // اگر در SCHEMA پیدا شد default را برگردان، وگرنه fallback
        foreach ( self::SCHEMA as $group ) {
            if ( isset( $group['fields'][ $key ] ) ) {
                $default = $group['fields'][ $key ]['default'];
                return get_option( self::PFX . $key, $default );
            }
        }
        return get_option( self::PFX . $key, $fallback );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // رندر صفحه
    // ─────────────────────────────────────────────────────────────────────────

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $active_tab = sanitize_key( $_GET['tab'] ?? 'general' );
        if ( ! isset( self::SCHEMA[ $active_tab ] ) ) $active_tab = 'general';

        $terms = TA_Database::get_results( "SELECT id, name FROM " . TA_Database::table('terms') . " ORDER BY order_index ASC" );

        $this->render_styles();
        ?>

<div class="wrap tset-wrap" dir="rtl">

<!-- هدر -->
<div class="tset-header">
    <div class="tset-header-title">
        <span class="tset-header-icon">⚙️</span>
        <div>
            <h1>تنظیمات سیستم استادیار</h1>
            <p>پیکربندی رفتار، قوانین و ظاهر سیستم</p>
        </div>
    </div>
    <?php if ( isset($_GET['saved']) ) : ?>
        <div class="tset-notice tset-notice-success">✓ تنظیمات با موفقیت ذخیره شد.</div>
    <?php elseif ( isset($_GET['reset']) ) : ?>
        <div class="tset-notice tset-notice-info">↺ تنظیمات این بخش به حالت پیش‌فرض برگشت.</div>
    <?php endif; ?>
</div>

<div class="tset-layout">

    <!-- سایدبار تب‌ها -->
    <nav class="tset-sidebar">
        <?php foreach ( self::SCHEMA as $tab_key => $tab ) : ?>
            <a href="<?= esc_url( add_query_arg( ['page'=>'ta-settings','tab'=>$tab_key], admin_url('admin.php') ) ) ?>"
               class="tset-tab-link <?= $active_tab === $tab_key ? 'tset-tab-active' : '' ?>">
                <span class="tset-tab-icon"><?= esc_html( $tab['icon'] ) ?></span>
                <span><?= esc_html( $tab['label'] ) ?></span>
            </a>
        <?php endforeach; ?>
        <div class="tset-sidebar-bottom">
            <a href="https://fa.wordpress.org/support/" target="_blank" class="tset-help-link">❓ راهنما</a>
        </div>
    </nav>

    <!-- محتوا -->
    <div class="tset-content">
        <form method="POST" action="<?= esc_url( admin_url('admin-post.php') ) ?>" id="tset-form">
            <?php wp_nonce_field('ta_save_settings'); ?>
            <input type="hidden" name="action"       value="ta_save_settings">
            <input type="hidden" name="current_tab"  value="<?= esc_attr( $active_tab ) ?>">

            <div class="tset-section-title">
                <?= esc_html( self::SCHEMA[$active_tab]['icon'] ) ?>
                <?= esc_html( self::SCHEMA[$active_tab]['label'] ) ?>
            </div>

            <div class="tset-fields">
            <?php foreach ( self::SCHEMA[$active_tab]['fields'] as $key => $field ) :
                $opt_key = self::PFX . $key;
                $val     = get_option( $opt_key, $field['default'] );
                $has_dep = ! empty( $field['depends'] );
            ?>
                <div class="tset-field-row <?= $has_dep ? 'tset-field-dep' : '' ?>"
                     <?= $has_dep ? 'data-depends="' . esc_attr( self::PFX . $field['depends'] ) . '"' : '' ?>>
                    <div class="tset-field-label">
                        <label for="<?= esc_attr( $opt_key ) ?>"><?= esc_html($field['label']) ?></label>
                        <?php if ( ! empty($field['desc']) ) : ?>
                            <div class="tset-field-desc"><?= esc_html($field['desc']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="tset-field-input">
                        <?php $this->render_field( $opt_key, $field, $val, $terms ); ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>

            <div class="tset-form-footer">
                <button type="submit" class="tset-btn tset-btn-save">💾 ذخیره تنظیمات</button>
                <button type="button" class="tset-btn tset-btn-reset" onclick="tsetDoReset()">↺ بازگشت به پیش‌فرض</button>
            </div>
        </form>

        <!-- فرم ریست — خارج از فرم اصلی تا از تودرتویی جلوگیری شود [FIX-NESTED-FORM] -->
        <form method="POST" action="<?= esc_url( admin_url('admin-post.php') ) ?>" id="tset-reset-form" style="display:none">
            <?php wp_nonce_field('ta_reset_settings','_wpnonce_reset'); ?>
            <input type="hidden" name="action"    value="ta_reset_settings">
            <input type="hidden" name="reset_tab" value="<?= esc_attr( $active_tab ) ?>">
        </form>

        <!-- پنل اطلاعات سیستم (فقط در تب پیشرفته) -->
        <?php if ( $active_tab === 'advanced' ) : ?>
        <div class="tset-info-panel">
            <div class="tset-info-title">📊 اطلاعات سیستم</div>
            <div class="tset-info-grid">
                <?php
                global $wpdb;
                $info = [
                    'نسخه افزونه'        => TA_SYSTEM_VERSION,
                    'نسخه PHP'           => PHP_VERSION,
                    'نسخه وردپرس'        => get_bloginfo('version'),
                    'نسخه WooCommerce'   => defined('WC_VERSION') ? WC_VERSION : '—',
                    'تعداد تخصیص‌ها'     => number_format( (int) $wpdb->get_var("SELECT COUNT(*) FROM " . TA_Database::table('assignments')) ),
                    'تعداد نمره‌ها'       => number_format( (int) $wpdb->get_var("SELECT COUNT(*) FROM " . TA_Database::table('grades')) ),
                    'تعداد استادیاران'   => number_format( (int) $wpdb->get_var("SELECT COUNT(*) FROM " . TA_Database::table('assistants')) ),
                    'Schema Version'     => get_option('ta_system_db_version', '?'),
                    'Tables Exist'       => TA_Database::tables_exist() ? '✅ بله' : '❌ خیر',
                ];
                foreach ( $info as $k => $v ) :
                ?>
                    <div class="tset-info-row">
                        <span class="tset-info-key"><?= esc_html($k) ?></span>
                        <span class="tset-info-val"><?= esc_html($v) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /content -->
</div><!-- /layout -->

<?php $this->render_scripts(); ?>
</div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // رندر یک فیلد
    // ─────────────────────────────────────────────────────────────────────────

    private function render_field( string $key, array $field, mixed $val, array $terms ): void {
        switch ( $field['type'] ) {

            case 'toggle':
                ?>
                <label class="tset-toggle">
                    <input type="hidden"   name="<?= $key ?>" value="no">
                    <input type="checkbox" name="<?= $key ?>" value="yes"
                           id="<?= $key ?>" <?= checked('yes', $val, false) ?>
                           class="tset-toggle-input"
                           onchange="tsetHandleDepends('<?= $key ?>', this.checked)">
                    <span class="tset-toggle-track">
                        <span class="tset-toggle-thumb"></span>
                    </span>
                    <span class="tset-toggle-on">فعال</span>
                    <span class="tset-toggle-off">غیرفعال</span>
                </label>
                <?php break;

            case 'number':
                $min = $field['min'] ?? 0;
                $max = $field['max'] ?? 9999;
                ?>
                <div class="tset-number-wrap">
                    <input type="number" name="<?= $key ?>" id="<?= $key ?>"
                           value="<?= (int)$val ?>" min="<?= $min ?>" max="<?= $max ?>"
                           class="tset-input tset-input-number">
                    <?php if ( ! empty($field['suffix']) ) : ?>
                        <span class="tset-input-suffix"><?= esc_html($field['suffix']) ?></span>
                    <?php endif; ?>
                </div>
                <?php break;

            case 'text':
                ?>
                <input type="text" name="<?= $key ?>" id="<?= $key ?>"
                       value="<?= esc_attr($val) ?>" class="tset-input tset-input-text">
                <?php break;

            case 'email':
                ?>
                <input type="email" name="<?= $key ?>" id="<?= $key ?>"
                       value="<?= esc_attr($val) ?>" class="tset-input tset-input-text"
                       placeholder="پیش‌فرض: ایمیل مدیر سایت">
                <?php break;

            case 'select':
                ?>
                <select name="<?= $key ?>" id="<?= $key ?>" class="tset-select">
                    <?php foreach ( $field['options'] as $opt_val => $opt_label ) : ?>
                        <option value="<?= $opt_val ?>" <?= selected($val, $opt_val, false) ?>>
                            <?= esc_html($opt_label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php break;

            case 'select_term':
                ?>
                <select name="<?= $key ?>" id="<?= $key ?>" class="tset-select">
                    <option value="0">— انتخاب ترم —</option>
                    <?php foreach ( $terms as $t ) : ?>
                        <option value="<?= $t->id ?>" <?= selected((int)$val, (int)$t->id, false) ?>>
                            <?= esc_html($t->name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php break;

            case 'checkboxes':
                $checked_vals = (array)$val;
                ?>
                <div class="tset-checkboxes">
                    <?php foreach ( $field['options'] as $opt_val => $opt_label ) : ?>
                        <label class="tset-checkbox-label">
                            <input type="checkbox"
                                   name="<?= $key ?>[]"
                                   value="<?= $opt_val ?>"
                                   <?= in_array($opt_val, $checked_vals, true) ? 'checked' : '' ?>>
                            <span><?= esc_html($opt_label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php break;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CSS
    // ─────────────────────────────────────────────────────────────────────────

    private function render_styles(): void { ?>
<style>
:root{
    --tset-radius:12px;--tset-shadow:0 1px 3px rgba(0,0,0,.08);
    --tset-border:#e5e7eb;--tset-text:#111827;--tset-muted:#6b7280;
    --tset-white:#fff;--tset-accent:#6366f1;
}
.tset-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Tahoma,sans-serif;direction:rtl;color:var(--tset-text);max-width:1100px}
/* هدر */
.tset-header{display:flex;align-items:center;gap:16px;margin-bottom:24px;padding:18px 22px;background:var(--tset-white);border-radius:var(--tset-radius);box-shadow:var(--tset-shadow);flex-wrap:wrap}
.tset-header-title{display:flex;align-items:center;gap:14px;flex:1}
.tset-header-icon{font-size:34px;line-height:1}
.tset-header h1{font-size:20px;font-weight:700;margin:0 0 3px}
.tset-header p{margin:0;color:var(--tset-muted);font-size:13px}
.tset-notice{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:500}
.tset-notice-success{background:#d1fae5;color:#065f46}
.tset-notice-info{background:#dbeafe;color:#1e40af}
/* layout */
.tset-layout{display:grid;grid-template-columns:200px 1fr;gap:20px;align-items:start}
@media(max-width:800px){.tset-layout{grid-template-columns:1fr}}
/* sidebar */
.tset-sidebar{background:var(--tset-white);border-radius:var(--tset-radius);box-shadow:var(--tset-shadow);overflow:hidden;position:sticky;top:32px;display:flex;flex-direction:column}
.tset-tab-link{display:flex;align-items:center;gap:10px;padding:12px 16px;text-decoration:none;color:var(--tset-text);font-size:13px;font-weight:500;border-right:3px solid transparent;transition:all .15s}
.tset-tab-link:hover{background:#f9fafb;color:var(--tset-accent)}
.tset-tab-active{background:#eef2ff;color:var(--tset-accent)!important;border-right-color:var(--tset-accent)}
.tset-tab-icon{font-size:17px;width:22px;text-align:center;flex-shrink:0}
.tset-sidebar-bottom{margin-top:auto;border-top:1px solid var(--tset-border);padding:10px 16px}
.tset-help-link{font-size:12px;color:var(--tset-muted);text-decoration:none}.tset-help-link:hover{color:var(--tset-accent)}
/* محتوا */
.tset-content{display:flex;flex-direction:column;gap:16px}
.tset-section-title{font-size:16px;font-weight:700;padding:0 0 12px;border-bottom:2px solid var(--tset-border);display:flex;align-items:center;gap:8px}
.tset-fields{background:var(--tset-white);border-radius:var(--tset-radius);box-shadow:var(--tset-shadow);overflow:hidden}
.tset-field-row{display:grid;grid-template-columns:1fr auto;align-items:center;gap:24px;padding:16px 20px;border-bottom:1px solid #f3f4f6;transition:background .1s}
.tset-field-row:last-child{border-bottom:none}
.tset-field-row:hover{background:#fafafa}
.tset-field-dep{opacity:.5;pointer-events:none;transition:opacity .3s}
.tset-field-dep.dep-enabled{opacity:1;pointer-events:auto}
.tset-field-label label{font-size:13px;font-weight:600;color:var(--tset-text);display:block;margin-bottom:3px;cursor:pointer}
.tset-field-desc{font-size:12px;color:var(--tset-muted);line-height:1.5}
.tset-field-input{display:flex;align-items:center;gap:8px;min-width:160px;justify-content:flex-end}
/* toggle */
.tset-toggle{display:flex;align-items:center;gap:8px;cursor:pointer}
.tset-toggle-input{display:none}
.tset-toggle-track{width:44px;height:24px;background:#d1d5db;border-radius:99px;position:relative;transition:background .2s;flex-shrink:0}
.tset-toggle-input:checked + .tset-toggle-track{background:var(--tset-accent)}
.tset-toggle-thumb{position:absolute;right:2px;top:2px;width:20px;height:20px;background:#fff;border-radius:50%;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:right .2s}
.tset-toggle-input:checked + .tset-toggle-track .tset-toggle-thumb{right:calc(100% - 22px)}
.tset-toggle-on,.tset-toggle-off{font-size:12px;font-weight:500}
.tset-toggle-input:checked ~ .tset-toggle-on{color:var(--tset-accent)}
.tset-toggle-input:checked ~ .tset-toggle-off{display:none}
.tset-toggle-input:not(:checked) ~ .tset-toggle-on{display:none}
.tset-toggle-input:not(:checked) ~ .tset-toggle-off{color:var(--tset-muted)}
/* inputs */
.tset-input{padding:7px 10px;border:1px solid var(--tset-border);border-radius:8px;font-size:13px;font-family:inherit;outline:none;transition:border-color .15s;direction:rtl}
.tset-input:focus{border-color:var(--tset-accent);box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.tset-input-text{width:280px}
.tset-input-number{width:90px;text-align:center}
.tset-number-wrap{display:flex;align-items:center;gap:6px}
.tset-input-suffix{font-size:13px;color:var(--tset-muted);font-weight:500}
.tset-select{padding:7px 10px;border:1px solid var(--tset-border);border-radius:8px;font-size:13px;background:var(--tset-white);font-family:inherit;outline:none;min-width:180px}
.tset-select:focus{border-color:var(--tset-accent)}
.tset-checkboxes{display:flex;flex-direction:column;gap:7px}
.tset-checkbox-label{display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer}
.tset-checkbox-label input{accent-color:var(--tset-accent);width:15px;height:15px}
/* footer */
.tset-form-footer{background:var(--tset-white);border-radius:var(--tset-radius);padding:16px 20px;box-shadow:var(--tset-shadow);display:flex;align-items:center;gap:12px}
.tset-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s}
.tset-btn-save{background:var(--tset-accent);color:#fff}.tset-btn-save:hover{background:#4f46e5}
.tset-btn-reset{background:#f3f4f6;color:var(--tset-muted)}.tset-btn-reset:hover{background:#e5e7eb;color:var(--tset-text)}
/* پنل سیستم */
.tset-info-panel{background:var(--tset-white);border-radius:var(--tset-radius);box-shadow:var(--tset-shadow);overflow:hidden}
.tset-info-title{font-size:14px;font-weight:700;padding:14px 18px;border-bottom:1px solid var(--tset-border)}
.tset-info-grid{padding:4px 0}
.tset-info-row{display:flex;justify-content:space-between;align-items:center;padding:9px 18px;border-bottom:1px solid #f9fafb;font-size:13px}
.tset-info-row:last-child{border-bottom:none}
.tset-info-key{color:var(--tset-muted);font-weight:500}
.tset-info-val{font-weight:600;font-family:monospace;font-size:12px;background:#f3f4f6;padding:2px 8px;border-radius:4px}
</style>
    <?php }

    private function render_scripts(): void { ?>
<script>
// وابستگی فیلدها — وقتی یک toggle تغییر کرد، فیلدهای وابسته را فعال/غیرفعال کن
function tsetHandleDepends(key, enabled) {
    document.querySelectorAll('[data-depends="' + key + '"]').forEach(function(row) {
        row.classList.toggle('dep-enabled', enabled);
    });
}

// [FIX-NESTED-FORM] ریست از طریق فرم مجزای خارج از فرم اصلی
function tsetDoReset() {
    if ( confirm('تمام تنظیمات این بخش به حالت پیش‌فرض برمی‌گردند. ادامه می‌دهید؟') ) {
        document.getElementById('tset-reset-form').submit();
    }
}

// اجرا در بارگذاری اولیه
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.tset-toggle-input').forEach(function(inp) {
        tsetHandleDepends(inp.name, inp.checked);
    });
});
</script>
    <?php }
}
