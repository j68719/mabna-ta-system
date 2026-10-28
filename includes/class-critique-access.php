<?php
defined( 'ABSPATH' ) || exit;

/** دسترسی محدود حساب متصل به شماره هشدار ادمین، بدون اعطای نقش وردپرس. */
class TA_Critique_Access {
    /** کاربران مشخص‌شده‌ای که باید به کل پنل نقد آثار دسترسی داشته باشند. */
    private const EXPLICIT_CRITIQUE_ADMIN_IDS = [ 2644 ];

    public static function boot(): void {
        if ( ! get_role( 'ta_critique_admin' ) ) {
            add_role( 'ta_critique_admin', 'ادمین نقد اثر', [ 'read' => true, 'ta_manage_critique' => true ] );
        }
        // boot پیش از بارگذاری توابع pluggable وردپرس اجرا می‌شود؛ بنابراین
        // دسترسی مبتنی بر شناسه را بعد از تکمیل بارگذاری وردپرس همگام می‌کنیم.
        add_action( 'init', [ __CLASS__, 'ensure_explicit_users_have_role' ], 1 );
        // user-edit.php وردپرس نقش انتخاب‌شده را با set_role() جایگزین می‌کند.
        // نقش‌های قبلی را هنگام انتخاب ادمین نقد اثر دوباره حفظ می‌کنیم.
        add_action( 'set_user_role', [ __CLASS__, 'preserve_previous_roles' ], 10, 3 );
        add_action( 'admin_init', [ __CLASS__, 'restrict_admin_request' ], 1 );
        add_action( 'admin_menu', [ __CLASS__, 'restrict_admin_menu' ], 999 );
        add_filter( 'login_redirect', [ __CLASS__, 'login_redirect' ], 10, 3 );
    }

    public static function normalize_phone( $phone ): string {
        $phone = (string) $phone;
        $phone = strtr( $phone, [ '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9', '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9' ] );
        $phone = preg_replace( '/[^0-9+]/', '', $phone );
        if ( str_starts_with( $phone, '+98' ) ) $phone = '0' . substr( $phone, 3 );
        if ( str_starts_with( $phone, '0098' ) ) $phone = '0' . substr( $phone, 4 );
        return preg_replace( '/^98/', '0', $phone );
    }

    public static function is_alert_phone_user( int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) return false;
        $target = self::normalize_phone( get_option( 'ta_sc_admin_alert_phone', '' ) );
        if ( $target === '' ) return false;
        $user = get_userdata( $user_id );
        if ( ! $user ) return false;
        $keys = [ 'billing_phone', 'phone', 'mobile', 'mobile_number', 'user_phone', 'digits_phone' ];
        foreach ( $keys as $key ) {
            if ( self::normalize_phone( get_user_meta( $user_id, $key, true ) ) === $target ) return true;
        }
        return false;
    }

    public static function is_critique_admin( int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        $user = $user_id ? get_userdata( $user_id ) : false;
        return $user && ( in_array( 'ta_critique_admin', (array) $user->roles, true )
            || in_array( (int) $user->ID, self::EXPLICIT_CRITIQUE_ADMIN_IDS, true ) );
    }

    /**
     * نقش اختصاصی را به کاربران تعیین‌شده اضافه می‌کند، بدون آن‌که نقش‌های فعلی
     * آن‌ها حذف شود. این متد عمداً از add_role() استفاده می‌کند؛ set_role() تمام
     * نقش‌های قبلی کاربر را جایگزین می‌کرد و باعث از بین رفتن دسترسی‌های دیگر می‌شد.
     */
    public static function ensure_explicit_users_have_role(): void {
        foreach ( self::EXPLICIT_CRITIQUE_ADMIN_IDS as $user_id ) {
            $user = get_userdata( (int) $user_id );
            if ( $user && ! in_array( 'ta_critique_admin', (array) $user->roles, true ) ) {
                $user->add_role( 'ta_critique_admin' );
            }
        }
    }

    /**
     * فرم استاندارد ویرایش کاربر وردپرس نقش‌ها را تک‌انتخابی در نظر می‌گیرد و
     * هنگام ذخیره set_role() را اجرا می‌کند. اگر نقش جدید ادمین نقد اثر باشد،
     * نقش‌های قبلی (مثل نویسنده) را از آرگومان old_roles بازمی‌گردانیم.
     */
    public static function preserve_previous_roles( int $user_id, string $new_role, array $old_roles ): void {
        if ( $new_role !== 'ta_critique_admin' || empty( $old_roles ) ) {
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        foreach ( $old_roles as $old_role ) {
            if ( is_string( $old_role ) && $old_role !== 'ta_critique_admin' ) {
                $user->add_role( $old_role );
            }
        }
    }

    public static function sync_alert_phone_role(): void {
        $target = self::normalize_phone( get_option( 'ta_sc_admin_alert_phone', '' ) );
        if ( $target === '' ) return;
        $users = get_users( [ 'fields' => 'ID', 'number' => 1, 'meta_query' => [ 'relation' => 'OR', [ 'key' => 'billing_phone', 'value' => $target ], [ 'key' => 'phone', 'value' => $target ], [ 'key' => 'mobile', 'value' => $target ], [ 'key' => 'mobile_number', 'value' => $target ], [ 'key' => 'user_phone', 'value' => $target ], [ 'key' => 'digits_phone', 'value' => $target ] ] ] );
        foreach ( $users as $user_id ) {
            if ( self::is_alert_phone_user( (int) $user_id ) ) {
                $user = get_userdata( (int) $user_id );
                if ( $user && ! user_can( $user, 'manage_options' ) && ! in_array( 'ta_critique_admin', (array) $user->roles, true ) ) {
                    // add_role نقش نقد آثار را اضافه می‌کند و نقش‌های قبلی را حفظ می‌کند.
                    $user->add_role( 'ta_critique_admin' );
                }
            }
        }
    }

    public static function can_manage( int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        return user_can( $user_id, 'manage_options' ) || self::is_critique_admin( $user_id ) || self::is_alert_phone_user( $user_id );
    }

    /** قابلیت مجازی برای ثبت منوی اختصاصی بدون افزودن نقش یا capability دائمی به کاربر. */
    public static function grant_virtual_cap( array $allcaps, array $caps, array $args, $user ): array {
        // مدیرکل نیز باید قابلیت مجازی منوی مستقل نقد آثار را داشته باشد.
        // از user_can() استفاده نمی‌کنیم تا در فیلتر user_has_cap حلقه ایجاد نشود.
        $is_full_admin = ! empty( $allcaps['manage_options'] );
        $is_critique_user = self::is_alert_phone_user( (int) $user->ID ) || self::is_critique_admin( (int) $user->ID );
        if ( ( $is_full_admin || $is_critique_user ) && in_array( 'ta_manage_critique', $caps, true ) ) {
            $allcaps['ta_manage_critique'] = true;
        }
        if ( ( $is_full_admin || $is_critique_user ) && in_array( 'read', $caps, true ) ) {
            $allcaps['read'] = true;
        }
        return $allcaps;
    }

    public static function restrict_admin_request(): void {
        if ( ! self::is_restricted_admin() ) return;
        $page = sanitize_key( $_GET['page'] ?? '' );
        $script = basename( (string) ( $_SERVER['PHP_SELF'] ?? '' ) );
        $allowed = $script === 'admin.php' && $page === 'ta-story-critique';
        if ( in_array( $script, [ 'admin-ajax.php', 'admin-post.php', 'async-upload.php' ], true ) ) return;
        if ( ! $allowed ) {
            wp_safe_redirect( admin_url( 'admin.php?page=ta-story-critique' ) );
            exit;
        }
    }

    public static function restrict_admin_menu(): void {
        if ( ! self::is_restricted_admin() ) return;
        global $menu, $submenu;
        foreach ( (array) $menu as $key => $item ) {
            $slug = (string) ( $item[2] ?? '' );
            if ( $slug !== 'ta-story-critique' ) unset( $menu[ $key ] );
        }
        foreach ( (array) $submenu as $parent => $items ) {
            if ( $parent !== 'ta-story-critique' ) unset( $submenu[ $parent ] );
        }
    }

    public static function login_redirect( $redirect_to, $requested, $user ) {
        if ( $user instanceof WP_User && ( self::is_alert_phone_user( (int) $user->ID ) || self::is_critique_admin( (int) $user->ID ) ) ) {
            return admin_url( 'admin.php?page=ta-story-critique' );
        }
        return $redirect_to;
    }

    public static function is_restricted_admin( int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        return ! user_can( $user_id, 'manage_options' ) && ( self::is_critique_admin( $user_id ) || self::is_alert_phone_user( $user_id ) );
    }
}
