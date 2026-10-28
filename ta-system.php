<?php
/**
 * Plugin Name:       اختصاصی ***  TA System – سیستم تخصیص استادیار
 * Plugin URI:        https://mabnaschool.ir
 * Description:       سیستم تخصیص استادیار به هنرجویان دوره‌های نویسندگی
 * Version: 2.3.6
 * Author:            جمال جمشیدی
 * Text Domain:       ta-system
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────────────────────
// ثوابت
// ─────────────────────────────────────────────────────────────────────────────
define( 'TA_SYSTEM_VERSION',  '2.3.6' );
define( 'TA_SYSTEM_PATH',     plugin_dir_path( __FILE__ ) );
define( 'TA_SYSTEM_URL',      plugin_dir_url( __FILE__ ) );
define( 'TA_SYSTEM_BASENAME', plugin_basename( __FILE__ ) );

/**
 * [CACHE-BUST] نسخهٔ واقعی یک فایل استاتیک (CSS/JS) را برمی‌گرداند.
 *
 * قبلاً همه‌جا مقدار ثابت TA_SYSTEM_VERSION به‌عنوان پارامتر ver در
 * wp_enqueue_style/script استفاده می‌شد. اگر هنگام تغییر فایل CSS/JS این
 * شمارهٔ نسخه به‌روزرسانی نمی‌شد، مرورگرها و هرگونه کش میانی (CDN، پراکسی
 * هاست و ...) همان فایل قدیمیِ کش‌شده را برای همان URL بی‌نهایت نمایش
 * می‌دادند؛ حتی وقتی هیچ پلاگین کشی روی سایت فعال نبود. با گرفتن filemtime
 * واقعیِ خود فایل، به‌محض هر تغییر در فایل، عدد نسخه (و در نتیجه URL) خودکار
 * عوض می‌شود و دیگر نیازی به یادآوریِ دستی نیست.
 */
function ta_system_asset_ver( string $relative_path ): string {
	$full_path = TA_SYSTEM_PATH . ltrim( $relative_path, '/' );
	$mtime     = file_exists( $full_path ) ? filemtime( $full_path ) : false;
	return $mtime ? (string) $mtime : TA_SYSTEM_VERSION;
}

// ─────────────────────────────────────────────────────────────────────────────
// بررسی پیش‌نیازها (PHP + WP + WooCommerce)
// این بررسی‌ها پیش از هر autoload انجام می‌شوند تا هیچ Fatal Error رخ ندهد
// ─────────────────────────────────────────────────────────────────────────────
if ( ! ta_system_check_requirements() ) {
    return; // بقیه فایل اجرا نمی‌شود
}

// ─────────────────────────────────────────────────────────────────────────────
// Autoloader
// ─────────────────────────────────────────────────────────────────────────────
spl_autoload_register( function ( string $class ): void {
    static $map = [
        // includes
        'TA_Database'          => 'includes/class-database.php',
        'TA_Roles'             => 'includes/class-roles.php',
        'TA_Product_Meta'      => 'includes/class-product-meta.php',
        'TA_Eligibility'       => 'includes/class-eligibility.php',
        'TA_Assignment'        => 'includes/class-assignment.php',
        'TA_Grade'             => 'includes/class-grade.php',
        'TA_Level_Assessment'  => 'includes/class-level-assess.php',
        'TA_Ajax'              => 'includes/class-ajax.php',
        'TA_User_Helper'       => 'includes/class-user-helper.php',
        'TA_Critique_Admin_Access' => 'includes/class-critique-admin-access.php',
        'TA_URL_Helper'        => 'includes/class-url-helper.php',
        'TA_Grupo_Chat'        => 'includes/class-grupo-chat.php',
        'TA_Xlsx_Reader'       => 'includes/class-xlsx-reader.php',
        'TA_Xlsx_Writer'       => 'includes/class-xlsx-writer.php',
        // admin
        'TA_Admin_Menu'        => 'admin/class-admin-menu.php',
        'TA_Admin_TA_Manager'  => 'admin/class-ta-manager.php',
        'TA_Admin_Import'      => 'admin/class-import.php',
        'TA_Admin_Assignments' => 'admin/class-assignment-manager.php',
        'TA_Product_Settings'  => 'admin/class-product-settings.php',
        'TA_Admin_Customers'   => 'admin/class-admin-customers.php',
        'TA_Grades_Manager'    => 'admin/class-grades-manager.php',
        'TA_Payroll'           => 'admin/class-payroll.php',
        'TA_Settings'          => 'admin/class-settings.php',
        'TA_Settings_Helper'   => 'includes/class-settings-helper.php',
        // [FIX-UNASSIGNED] اضافه شد به autoloader
        'TA_Admin_Unassigned_Orders' => 'admin/class-unassigned-orders.php',
        'TA_Order_Allowed'           => 'admin/class-order-allowed.php',
        // [BUGFIX-AUTOLOAD] قبلاً این کلاس در autoloader نبود و فقط داخل
        // TA_Admin_Menu::register_menus() (که به admin_menu وصل است و در
        // درخواست‌های AJAX اجرا نمی‌شود) require می‌شد — اکنون همیشه در دسترس است
        'TA_Capacity_Manager'         => 'admin/class-capacity-manager.php',
        'TA_Summary_Report'           => 'admin/class-summary-report.php',
        'TA_Assistant_Report'         => 'admin/class-assistant-report.php',
        'TA_Story_Critique_Manager'   => 'admin/class-story-critique-manager.php',
        'TA_SMS_Log_Report'           => 'admin/class-sms-log-report.php',
        'TA_Docx_Reader'              => 'includes/class-docx-reader.php',
        'TA_MeliPayamak'              => 'includes/class-melipayamak.php',
        'TA_Critique_Order'           => 'frontend/class-critique-order.php',
        'TA_Critique_Chat'            => 'frontend/class-critique-chat.php',
        // frontend
        'TA_Dashboard_TA'      => 'frontend/class-dashboard-ta.php',
        'TA_Product_Widget'    => 'frontend/class-product-widget.php',
        'TA_Student_Portal'    => 'frontend/class-student-portal.php',
        'Mabna_Bale_API'        => 'includes/class-mabna-bale-api.php',
        'Mabna_Bale_Bot'        => 'includes/class-mabna-bale-bot.php',
        'Mabna_Bale_Card_Renderer' => 'includes/class-mabna-bale-card-renderer.php',
        'Mabna_Bale_Result_Content' => 'includes/class-mabna-bale-result-content.php',
        'Mabna_Bale_Admin'      => 'admin/class-mabna-bale-admin.php',
        'Mabna_Bale_Broadcast'  => 'includes/class-mabna-bale-broadcast.php',
    ];

    if ( isset( $map[ $class ] ) ) {
        $file = TA_SYSTEM_PATH . $map[ $class ];
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
} );

// محتوای اختصاصی کارت نتیجهٔ ۱۶ نویسنده باید در درخواست‌های وبهوک نیز در دسترس باشد.
require_once TA_SYSTEM_PATH . 'includes/class-mabna-bale-result-content.php';

// ─────────────────────────────────────────────────────────────────────────────
// Activation / Deactivation
// ─────────────────────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, static function (): void {
    if ( ! ta_system_woocommerce_active() ) {
        deactivate_plugins( TA_SYSTEM_BASENAME );
        wp_die(
            '<p>افزونه TA System برای فعال‌شدن نیاز به <strong>WooCommerce</strong> دارد.</p>',
            'خطای فعال‌سازی',
            [ 'back_link' => true ]
        );
    }
    TA_Database::install();
    require_once TA_SYSTEM_PATH . 'includes/class-mabna-bale-challenge-db.php';
    Mabna_Bale_Challenge_DB::install();
    require_once TA_SYSTEM_PATH . 'includes/class-mabna-bale-broadcast.php';
    Mabna_Bale_Broadcast::install();
    TA_Roles::add_roles();
} );

register_deactivation_hook( __FILE__, static function (): void {
    TA_Roles::remove_roles();
    $broadcast_ts = wp_next_scheduled( 'mabna_bale_broadcast_worker' );
    if ( $broadcast_ts ) wp_unschedule_event( $broadcast_ts, 'mabna_bale_broadcast_worker' );
    $ts = wp_next_scheduled( 'ta_co_sla_reminder_cron' );
    if ( $ts ) {
        wp_unschedule_event( $ts, 'ta_co_sla_reminder_cron' );
    }
} );

// ─────────────────────────────────────────────────────────────────────────────
// Boot — اجرای اصلی افزونه
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', static function (): void {

    // دسترسی محدود پنل نقد آثار برای حسابی که شماره‌اش با شماره ادمین هشدارها یکی است.
    add_filter( 'user_has_cap', [ 'TA_Critique_Admin_Access', 'filter_user_caps' ], 10, 4 );

    // WooCommerce بررسی مجدد (ممکن است بعد از activation غیرفعال شده باشد)
    if ( ! ta_system_woocommerce_active() ) {
        add_action( 'admin_notices', static function (): void {
            $link = admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' );
            echo wp_kses_post(
                '<div class="notice notice-error">'
                . '<p><strong>TA System:</strong> این افزونه نیاز به '
                . '<a href="' . esc_url( $link ) . '">WooCommerce</a>'
                . ' دارد. لطفاً ابتدا WooCommerce را نصب و فعال کنید.</p>'
                . '</div>'
            );
        } );
        return;
    }

    // اطمینان از به‌روز بودن جداول دیتابیس
    TA_Database::maybe_install();

    // [GROUP-JOIN-LOG] لاگ دیگر بر اساس لاگین واقعی به سایت ثبت نمی‌شود؛
    // ملاک لاگ، ورود واقعی به گروه (کلیک/تاچ روی لینک گروه) است که در
    // TA_Ajax::join_group() ثبت می‌شود. به همین دلیل هوک wp_login حذف شد.

    // ماژول بله — این فایل علاوه بر کلاس API، توابع کمکی Webhook و لاگ را نیز تعریف می‌کند.
    // باید پیش از ساخت ربات و پنل مدیریت به‌صورت صریح بارگذاری شود؛ چون autoloader
    // فقط هنگام ارجاع به کلاس‌ها فعال می‌شود و توابع مستقل را به‌تنهایی بارگذاری نمی‌کند.
    require_once TA_SYSTEM_PATH . 'includes/class-mabna-bale-api.php';
    require_once TA_SYSTEM_PATH . 'includes/class-mabna-bale-challenge-db.php';
    Mabna_Bale_Challenge_DB::maybe_install();
    require_once TA_SYSTEM_PATH . 'includes/class-mabna-bale-broadcast.php';
    Mabna_Bale_Broadcast::maybe_install();
    require_once TA_SYSTEM_PATH . 'frontend/class-mabna-bale-challenge-shortcode.php';

    // هسته
    new TA_Product_Meta();
    require_once TA_SYSTEM_PATH . 'includes/class-term-age-checkout.php';
    new TA_Term_Age_Checkout();
    new TA_Ajax();
    new TA_Critique_Chat();
    new Mabna_Bale_Bot();
    new Mabna_Bale_Broadcast();
    new Mabna_Bale_Challenge_Shortcode();

    // ادمین
    if ( is_admin() ) {
        new TA_Admin_Menu();       // منو را ثبت می‌کند (TA_Capacity_Manager داخل register_menus ساخته می‌شود)
        require_once TA_SYSTEM_PATH . 'admin/class-admin-cooperation-report.php';
        new TA_Admin_Cooperation_Report();
        new TA_Product_Settings();
        new TA_Admin_TA_Manager();
        new TA_Admin_Import();
        new TA_Admin_Assignments();
        new TA_Story_Critique_Manager(); // ثبت hookهای admin_post مدیریت فرم‌ها/تخصص‌های نقد آثار
        // [FIX-DOUBLE-SETTINGS] TA_Settings فقط یک‌بار اینجا ساخته می‌شود
        // page_settings() در admin-menu فقط render() را صدا می‌زند، نه new TA_Settings()
        new TA_Settings();
        new TA_Payroll();
        new TA_Order_Allowed();
        $mabna_bale_admin = new Mabna_Bale_Admin();
        add_action( 'admin_menu', [ $mabna_bale_admin, 'menu' ], 5 );

        // [FIX-DOUBLE-CAPACITY] TA_Capacity_Manager فقط از TA_Admin_Menu::register_menus() ساخته می‌شود
        // نمونه‌سازی اینجا حذف شد تا از ثبت تکراری AJAX hooks جلوگیری شود
    }

    // فرانت‌اند
    new TA_Product_Widget();
    new TA_Dashboard_TA();
    new TA_Student_Portal();
    new TA_Critique_Order();
    new TA_Grupo_Chat();

}, 10 );

// ─────────────────────────────────────────────────────────────────────────────
// توابع کمکی
// ─────────────────────────────────────────────────────────────────────────────

/**
 * بررسی اینکه آیا WooCommerce فعال است
 */
function ta_system_woocommerce_active(): bool {
    return class_exists( 'WooCommerce' );
}

/**
 * بررسی تمام پیش‌نیازها و نمایش نوتیس در صورت مشکل.
 * اگر همه چیز درست باشد true برمی‌گرداند.
 */
function ta_system_check_requirements(): bool {
    $errors = [];

    // PHP
    if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
        $errors[] = sprintf(
            'TA System نیاز به PHP نسخه ۸.۰ یا بالاتر دارد. نسخه فعلی: %s',
            PHP_VERSION
        );
    }

    // WordPress
    global $wp_version;
    if ( isset( $wp_version ) && version_compare( $wp_version, '6.0', '<' ) ) {
        $errors[] = sprintf(
            'TA System نیاز به WordPress نسخه ۶.۰ یا بالاتر دارد. نسخه فعلی: %s',
            $wp_version
        );
    }

    // افزونه‌های مورد نیاز
    $required_plugins = [
        'WooCommerce' => [
            'class'  => 'WooCommerce',
            'name'   => 'WooCommerce',
            'url'    => 'https://wordpress.org/plugins/woocommerce/',
        ],
    ];

    // WooCommerce ممکن است در plugins_loaded هنوز بارگذاری نشده باشد
    // پس این بررسی فقط اگر is_admin() باشد و plugins لود شده باشند اجرا می‌شود
    if ( did_action( 'plugins_loaded' ) || doing_action( 'plugins_loaded' ) ) {
        foreach ( $required_plugins as $id => $plugin ) {
            if ( ! class_exists( $plugin['class'] ) ) {
                $errors[] = sprintf(
                    'TA System نیاز به <a href="%s" target="_blank">%s</a> دارد.',
                    esc_url( $plugin['url'] ),
                    esc_html( $plugin['name'] )
                );
            }
        }
    }

    if ( empty( $errors ) ) {
        return true;
    }

    // نمایش نوتیس‌های خطا فقط در پنل ادمین
    add_action( 'admin_notices', static function () use ( $errors ): void {
        foreach ( $errors as $msg ) {
            echo wp_kses_post(
                '<div class="notice notice-error is-dismissible"><p>'
                . '<strong>TA System:</strong> ' . $msg
                . '</p></div>'
            );
        }
    } );

    // اگر فقط مشکل PHP یا WP version است (نه WooCommerce که بعداً چک می‌شود) برگردان false
    $hard_errors = array_filter( $errors, fn($e) => str_contains($e, 'PHP') || str_contains($e, 'WordPress') );
    return empty( $hard_errors );
}
