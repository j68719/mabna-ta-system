<?php
defined( 'ABSPATH' ) || exit;

/**
 * دسترسی محدود مدیر نقد آثار بر اساس شماره موبایل تنظیم‌شده برای هشدارها.
 * این دسترسی فقط برای خودِ پنل نقد آثار است و manage_options را اعطا نمی‌کند.
 */
class TA_Critique_Admin_Access {

    public const CAPABILITY = 'ta_manage_critique_orders';

    public static function can_manage_orders(): bool {
        return current_user_can( 'manage_options' ) || self::is_matched_phone_user();
    }

    public static function is_matched_phone_user( ?int $user_id = null ): bool {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }

        // از user_can() استفاده نمی‌کنیم تا هنگام اجرای user_has_cap وارد بازگشت نشویم.
        $user = get_userdata( $user_id );
        if ( ! $user || ! empty( $user->allcaps['manage_options'] ) ) {
            return false;
        }

        $configured = self::normalize_phone( (string) get_option( 'ta_sc_admin_alert_phone', '' ) );
        if ( $configured === '' ) {
            return false;
        }

        foreach ( [ 'kerasno-phone', 'phone', 'Mreeir_phone', 'billing_phone' ] as $meta_key ) {
            $phone = self::normalize_phone( (string) get_user_meta( $user_id, $meta_key, true ) );
            if ( $phone !== '' && $phone === $configured ) {
                return true;
            }
        }

        return false;
    }

    public static function normalize_phone( string $phone ): string {
        $phone = strtr( trim( $phone ), [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ] );
        $phone = preg_replace( '/[^0-9+]/', '', $phone );
        if ( str_starts_with( $phone, '0098' ) ) {
            $phone = '+98' . substr( $phone, 4 );
        }
        if ( str_starts_with( $phone, '98' ) ) {
            $phone = '+' . $phone;
        }
        if ( str_starts_with( $phone, '+98' ) ) {
            $phone = '0' . substr( $phone, 3 );
        }
        return ltrim( $phone, '+' );
    }

    public static function filter_user_caps( array $allcaps, array $caps, array $args, WP_User $user ): array {
        // مدیرکل باید مثل قبل به منوی والد و همهٔ بخش‌های نقد آثار دسترسی کامل داشته باشد.
        // حساب منطبق با شماره موبایل فقط همین قابلیت محدود را دریافت می‌کند.
        if ( ! empty( $allcaps['manage_options'] ) || self::is_matched_phone_user( (int) $user->ID ) ) {
            $allcaps[ self::CAPABILITY ] = true;
        }
        if ( self::is_matched_phone_user( (int) $user->ID ) ) {
            $allcaps['read'] = true;
        }
        return $allcaps;
    }
}
