<?php
defined( 'ABSPATH' ) || exit;

class TA_Roles {

    public static function add_roles(): void {
        add_role(
            'teaching_assistant',
            'استادیار',
            [
                'read'                       => true,
                'ta_view_dashboard'          => true,
                'ta_manage_capacity'         => true,
                'ta_grade_students'          => true,
                'ta_manage_blacklist'        => true,
                'ta_view_assigned_students'  => true,
                'ta_assess_levels'           => true,
            ]
        );
    }

    public static function remove_roles(): void {
        remove_role( 'teaching_assistant' );
    }

    /**
     * آیا کاربر جاری استادیار است؟
     */
    public static function is_ta( int $user_id = 0 ): bool {
        $user = $user_id ? get_user_by( 'id', $user_id ) : wp_get_current_user();
        if ( ! $user ) {
            return false;
        }
        return in_array( 'teaching_assistant', (array) $user->roles, true )
            || user_can( $user, 'manage_options' ); // ادمین هم دسترسی دارد
    }

    /**
     * آیا کاربر جاری استادیار است (با دسترسی تعیین سطح)؟
     * فعلاً همان استادیار معمولی — می‌توان جدا کرد
     */
    public static function is_level_assessor( int $user_id = 0 ): bool {
        return self::is_ta( $user_id );
    }

    /**
     * @deprecated [GROUP-JOIN-LOG] دیگر به هیچ هوکی وصل نیست.
     * ملاک لاگ از «لاگین واقعی به سایت» به «کلیک/تاچ روی لینک ورود به گروه»
     * تغییر کرد (ر.ک. TA_Ajax::join_group و جدول ta_group_join_logs).
     * این متد فقط برای سازگاری نگه داشته شده و در جایی صدا زده نمی‌شود.
     */
    public static function log_real_login( string $user_login, \WP_User $user ): void {
        if ( user_can( $user, 'manage_options' ) ) {
            $actor_type = 'admin';
        } elseif ( in_array( 'teaching_assistant', (array) $user->roles, true ) ) {
            $actor_type = 'ta';
        } else {
            $actor_type = 'student';
        }

        global $wpdb;
        $table = TA_Database::table( 'login_logs' );
        $wpdb->insert( $table, [
            'user_id'    => (int) $user->ID,
            'actor_type' => $actor_type,
            'logged_at'  => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s' ] );
    }
}
