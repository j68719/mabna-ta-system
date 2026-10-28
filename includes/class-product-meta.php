<?php
defined( 'ABSPATH' ) || exit;

class TA_Product_Meta {

    public function __construct() {}

    /**
     * بررسی و در صورت نیاز ایجاد ستون pass_eligible — مستقل از سیستم نسخه‌بندی دیتابیس.
     * این تابع تضمین می‌کند حتی اگر مهاجرت اصلی (در class-database.php) به هر دلیلی
     * اجرا نشده یا شکست خورده باشد، ذخیره‌سازی پیش‌نیازها هرگز کامل از کار نمی‌افتد.
     */
    private static function ensure_pass_eligible_column(): bool {
        $cache_key = 'ta_db_has_pass_eligible_col';
        $cached = wp_cache_get( $cache_key );
        if ( $cached !== false ) {
            return (bool) $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ta_product_prerequisites';
        $exists = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'pass_eligible'" );

        if ( ! $exists ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `pass_eligible` TINYINT(1) NOT NULL DEFAULT 1" );
            $exists = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'pass_eligible'" );
        }

        // مهاجرت یک‌باره: تا قبل از این نسخه، فهرست «قبولی مستقیم» فقط شامل مقدماتی‌ها بود و
        // پیش‌نیازهای «پایه» همیشه pass_eligible=0 ذخیره می‌شدند، در حالی که قبولی پایه همیشه
        // مجوز می‌داد. برای حفظ رفتار قبلی، ردیف‌های پایهٔ موجود یک بار روی ۱ تنظیم می‌شوند.
        if ( $exists && ! get_option( 'ta_pass_eligible_basic_migrated' ) ) {
            $settings_tbl = $wpdb->prefix . 'ta_product_settings';
            $wpdb->query(
                "UPDATE `{$table}` pp
                 JOIN `{$settings_tbl}` ps ON ps.product_id = pp.prerequisite_product_id
                 SET pp.pass_eligible = 1
                 WHERE ps.course_type = 'basic_writing'"
            );
            update_option( 'ta_pass_eligible_basic_migrated', 1, false );
        }

        // اگر ستون پیدا/ساخته نشد، فقط چند دقیقه کش می‌کنیم تا روی هر درخواست دوباره تلاش کند
        wp_cache_set( $cache_key, $exists, '', $exists ? HOUR_IN_SECONDS : 60 );
        return $exists;
    }

    /**
     * دریافت تنظیمات محصول با cache
     */
    public static function get_settings( int $product_id ): ?object {
        $cache_key = "ta_product_settings_{$product_id}";
        $cached    = wp_cache_get( $cache_key );
        if ( $cached !== false ) {
            return $cached ?: null;
        }

        $table = TA_Database::table( 'product_settings' );
        $row   = TA_Database::get_row(
            "SELECT * FROM {$table} WHERE product_id = %d",
            [ $product_id ]
        );

        wp_cache_set( $cache_key, $row ?: 0, '', 300 );
        return $row ?: null;
    }

    /**
     * دریافت لیست پیش‌نیازهای یک محصول (آرایه‌ای از product_id ها)
     */
    public static function get_prerequisites( int $product_id ): array {
        $cache_key = "ta_prerequisites_{$product_id}";
        $cached    = wp_cache_get( $cache_key );
        if ( $cached !== false ) {
            return $cached ?: [];
        }

        $table = TA_Database::table( 'product_prerequisites' );
        $rows  = TA_Database::get_results(
            "SELECT prerequisite_product_id FROM {$table} WHERE product_id = %d",
            [ $product_id ]
        );

        $ids = array_map( fn( $r ) => (int) $r->prerequisite_product_id, $rows );
        wp_cache_set( $cache_key, $ids, '', 300 );
        return $ids;
    }

    /**
     * دریافت زیرمجموعه‌ای از پیش‌نیازها که برای «قبولی مستقیم» در نظر گرفته شده‌اند
     * (برای سایر بررسی‌ها مثل نمره «نقد اثر» محدودیتی اعمال نمی‌شود)
     */
    public static function get_pass_eligible_prerequisites( int $product_id ): array {
        $cache_key = "ta_pass_eligible_prereqs_{$product_id}";
        $cached    = wp_cache_get( $cache_key );
        if ( $cached !== false ) {
            return $cached ?: [];
        }

        if ( ! self::ensure_pass_eligible_column() ) {
            // ستون هنوز در دسترس نیست — به‌جای خطای دیتابیس، رفتار پیش‌فرض (بدون محدودیت) را برمی‌گردانیم
            return [];
        }

        $table = TA_Database::table( 'product_prerequisites' );
        $rows  = TA_Database::get_results(
            "SELECT prerequisite_product_id FROM {$table} WHERE product_id = %d AND pass_eligible = 1",
            [ $product_id ]
        );

        $ids = array_map( fn( $r ) => (int) $r->prerequisite_product_id, $rows );
        wp_cache_set( $cache_key, $ids, '', 300 );
        return $ids;
    }

    /**
     * ذخیره تنظیمات محصول
     */
    public static function save_settings(
        int     $product_id,
        int     $term_id,
        string  $course_type,
        array   $prerequisite_ids  = [],   // آرایه‌ای از product_id ها
        string  $pro_group_link    = '',   // لینک ثابت برای دوره حرفه‌ای
        ?array  $pass_eligible_ids = null  // زیرمجموعه‌ای از $prerequisite_ids که قبولی‌شان «مستقیم» پذیرفته می‌شود؛ null یعنی همه (سازگار با نسخه قبل)
    ): bool {
        global $wpdb;

        wp_cache_delete( "ta_product_settings_{$product_id}" );
        wp_cache_delete( "ta_prerequisites_{$product_id}" );
        wp_cache_delete( "ta_pass_eligible_prereqs_{$product_id}" );

        $settings_table = TA_Database::table( 'product_settings' );
        $prereq_table   = TA_Database::table( 'product_prerequisites' );

        $existing = TA_Database::get_row(
            "SELECT id FROM {$settings_table} WHERE product_id = %d",
            [ $product_id ]
        );

        if ( $existing ) {
            TA_Database::update( 'ta_product_settings', [
                'term_id'        => $term_id,
                'course_type'    => $course_type,
                'pro_group_link' => $course_type === 'professional'|| $course_type === 'critique' ? $pro_group_link : null,
            ], [ 'product_id' => $product_id ] );
        } else {
            TA_Database::insert( 'ta_product_settings', [
                'product_id'     => $product_id,
                'term_id'        => $term_id,
                'course_type'    => $course_type,
                'pro_group_link' => $course_type === 'professional'|| $course_type === 'critique' ? $pro_group_link : null,
                'created_at'     => current_time( 'mysql' ),
            ] );
        }

        // بازنویسی کامل پیش‌نیازها
        $wpdb->delete( $wpdb->prefix . 'ta_product_prerequisites', [ 'product_id' => $product_id ] );

        $has_pass_col = self::ensure_pass_eligible_column();

        $prerequisite_ids = array_filter( array_map( 'intval', $prerequisite_ids ) );
        foreach ( $prerequisite_ids as $prereq_id ) {
            if ( $prereq_id && $prereq_id !== $product_id ) {
                $row = [
                    'product_id'              => $product_id,
                    'prerequisite_product_id' => $prereq_id,
                ];

                if ( $has_pass_col ) {
                    $row['pass_eligible'] = ( $pass_eligible_ids === null )
                        ? 1
                        : ( in_array( $prereq_id, $pass_eligible_ids, true ) ? 1 : 0 );
                }

                TA_Database::insert( 'ta_product_prerequisites', $row );
            }
        }

        return true;
    }

    /**
     * بررسی اینکه آیا یک محصول از نوع نردبان حرفه‌ای است
     */
    public static function is_ladder( int $product_id ): bool {
        $settings = self::get_settings( $product_id );
        return $settings && $settings->course_type === 'ladder';
    }

    /**
     * لیبل فارسی نوع دوره
     */
    public static function course_type_label( string $type ): string {
        return match ( $type ) {
            'creative'      => 'نویسندگی خلاق',
            'intro'         => 'نویسندگی مقدماتی',
            'basic_writing' => 'نویسندگی پایه',
            'critique'      => 'نقد اثر',
            'advanced'      => 'نویسندگی پیشرفته',
            'ladder'        => 'نردبان حرفه‌ای',
            'professional'  => 'نویسندگی حرفه‌ای',
            default         => $type,
        };
    }
}
