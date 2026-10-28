<?php
defined( 'ABSPATH' ) || exit;

/**
 * یافتن کاربر بر اساس ایمیل یا شماره همراه (kerasno-phone)
 */
class TA_User_Helper {

    const PHONE_META = 'kerasno-phone';

    /**
     * یافتن کاربر با شناسه‌ی متنی:
     *  - اگر حاوی @ باشد → ایمیل (یا الگوی خاص شماره)
     *  - در غیر این صورت → شماره همراه
     */
    public static function find_by_identifier( string $value ): ?\WP_User {
        $value = trim( $value );
        if ( ! $value ) return null;

        // اگر ایمیل است (شامل @)
        if ( str_contains( $value, '@' ) ) {
            // بررسی الگوی خاص: 0912...@mabnaschool.ir
            if ( str_ends_with( $value, '@mabnaschool.ir' ) ) {
                $phone = str_replace( '@mabnaschool.ir', '', $value );
                return self::find_by_phone( $phone );
            }
            // ایمیل معمولی
            return get_user_by( 'email', $value ) ?: null;
        }

        // اگر شماره تلفن است
        return self::find_by_phone( $value );
    }

    /**
     * یافتن کاربر بر اساس شماره همراه
     * تمام فیلدهای ممکن را چک می‌کند: kerasno-phone, phone, Mreeir_phone, billing_phone
     */
    public static function find_by_phone( string $phone ): ?\WP_User {
        global $wpdb;

        // نرمال‌سازی شماره تلفن (حذف فاصله، خط تیره و پرانتز)
        $phone = preg_replace( '/[\s\-\(\)]+/', '', $phone );

        // لیست متاهای دیتابیس که ممکن است شماره در آن‌ها ذخیره شده باشد
        $meta_keys = [ 'kerasno-phone', 'phone', 'Mreeir_phone', 'billing_phone' ];

        foreach ( $meta_keys as $meta_key ) {
            // جستجوی مستقیم با کوئری برای سرعت بیشتر
            $user_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} 
                 WHERE meta_key = %s AND meta_value = %s 
                 LIMIT 1",
                $meta_key, $phone
            ) );

            if ( $user_id ) {
                return get_user_by( 'id', (int) $user_id );
            }
        }

        return null;
    }

    /**
     * دریافت شماره همراه کاربر (اولین مورد موجود)
     */
    public static function get_phone( int $user_id ): string {
        $meta_keys = [ 'kerasno-phone', 'phone', 'Mreeir_phone', 'billing_phone' ];
        foreach ( $meta_keys as $meta_key ) {
            $phone = get_user_meta( $user_id, $meta_key, true );
            if ( $phone ) {
                return (string) $phone;
            }
        }
        return '';
    }

    /**
     * جستجوی کاربران با نام، ایمیل یا شماره همراه — برای autocomplete
     */
    public static function search( string $query, int $limit = 20 ): array {
        $query = trim( $query );
        if ( strlen( $query ) < 2 ) return [];

        global $wpdb;
        $like = '%' . $wpdb->esc_like( $query ) . '%';

        // جستجو در نام و ایمیل
        $by_name = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, display_name, user_email
             FROM {$wpdb->users}
             WHERE display_name LIKE %s OR user_email LIKE %s
             LIMIT %d",
            [ $like, $like, $limit ]
        ) ) ?: [];

        // جستجو در تمام فیلدهای تلفن
        $meta_keys = [ 'kerasno-phone', 'phone', 'Mreeir_phone', 'billing_phone' ];
        $placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
        
        $by_phone = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT u.ID, u.display_name, u.user_email
             FROM {$wpdb->users} u
             JOIN {$wpdb->usermeta} m ON m.user_id = u.ID
             WHERE m.meta_key IN ($placeholders) AND m.meta_value LIKE %s
             LIMIT %d",
            array_merge( $meta_keys, [ $like, $limit ] )
        ) ) ?: [];

        // ادغام و حذف تکراری
        $seen   = [];
        $result = [];
        foreach ( array_merge( $by_name, $by_phone ) as $u ) {
            if ( isset( $seen[ $u->ID ] ) ) continue;
            $seen[ $u->ID ] = true;
            $phone = self::get_phone( (int) $u->ID );
            $result[] = [
                'id'    => (int) $u->ID,
                'name'  => $u->display_name,
                'email' => $u->user_email,
                'phone' => $phone,
                'label' => $u->display_name
                    . ( $phone ? " — {$phone}" : '' )
                    . " ({$u->user_email})",
            ];
        }
        return $result;
    }
    
    /**
     * نمایش خلاصه کاربر (نام + ایمیل/موبایل)
     */
    public static function display( int $user_id ): string {
        $user  = get_user_by( 'id', $user_id );
        $phone = self::get_phone( $user_id );
        if ( ! $user ) return "ID:{$user_id}";
        return $user->display_name . ( $phone ? " ({$phone})" : " ({$user->user_email})" );
    }
}