<?php
defined( 'ABSPATH' ) || exit;

/**
 * ارسال پیامک الگودار (Pattern) از طریق وب‌سرویس ملی‌پیامک
 * مستندات: https://www.melipayamak.com/webservice/pattern/
 *
 * تنظیمات لازم (در تب «تنظیمات» صفحه گزارش نقد آثار وارد می‌شود):
 * - ta_sc_melipayamak_username
 * - ta_sc_melipayamak_password
 * - ta_sc_pattern_*                کد الگوهای هر نوع پیامک
 * - ta_sc_sms_on_*                 کلید فعال/غیرفعال بودن هر نوع پیامک (پیش‌فرض: فعال)
 */
class TA_MeliPayamak {

    const API_URL = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';
    private static array $log_context = [];

    /**
     * لیست کلید انواع پیامک‌ها برای رندر صفحه‌ی تنظیمات (کلید => برچسب فارسی)
     * (فقط برای استفاده‌ی رابط کاربری؛ منطق فعال/غیرفعال از enabled() می‌آید)
     */
    const NOTIFICATION_TYPES = [
        'customer'        => 'ثبت سفارش — به هنرجو',
        'ta'               => 'ثبت سفارش — به استادیار',
        'started'          => 'شروع کار — به هنرجو',
        'delivered'        => 'تحویل نقد — به هنرجو',
        'ta_completed'     => 'تکمیل سفارش — به استادیار',
        'eval_reminder'    => 'یادآوری ارزیابی — به هنرجو',
        'cancelled'        => 'لغو/استرداد سفارش — به هنرجو و استادیار',
        'chat'             => 'پیام جدید در چت هنرجو⇄استادیار',
        'sla_reminder'     => 'یادآوری SLA — به استادیار',
        'sla_pending'      => 'یادآوری سفارش در انتظار — به استادیار',
        'sla_in_progress'  => 'یادآوری سفارش در حال انجام — به استادیار',
        'sla_delivered'    => 'یادآوری ارزیابی هنرجو — به استادیار',
        'sla_completed'    => 'یادآوری ارزیابی استاد — به هنرجو',
        'capacity_alert'   => 'هشدار تکمیل ظرفیت — به ادمین',
        'capacity_alert_ta' => 'هشدار تکمیل ظرفیت — به خود استادیار',
    ];

    /** آیا این نوع پیامک فعال است؟ (پیش‌فرض: بله) */
    public static function enabled( string $key ): bool {
        return get_option( 'ta_sc_sms_on_' . $key, '1' ) === '1';
    }

    public static function set_log_context( array $context ): void {
        self::$log_context = $context;
    }

    public static function clear_log_context(): void {
        self::$log_context = [];
    }

    /**
     * ارسال پیامک الگودار
     *
     * @param string $to      شماره موبایل گیرنده
     * @param string $body_id کد الگوی تأییدشده در پنل ملی‌پیامک
     * @param array  $args    مقادیر جایگزین متغیرهای الگو؛ آرایهٔ صفرمبنا: مقدار اول %0، سپس %1 و ...
     */
    public static function send_pattern( string $to, string $body_id, array $args ): array {
        $username = get_option( 'ta_sc_melipayamak_username', '' );
        $password = get_option( 'ta_sc_melipayamak_password', '' );
        $type = self::infer_type( $body_id );

        if ( ! $username || ! $password || ! $body_id ) {
            $result = [ 'ok' => false, 'message' => 'تنظیمات ملی‌پیامک یا کد الگو کامل نیست.' ];
            self::log_sms( $type, $to, $body_id, $args, $result );
            return $result;
        }

        // ملی‌پیامک متغیرهای الگو را از %0 می‌خواند؛ بنابراین اولین مقدار ارسالی
        // باید همیشه متغیر صفرم (%0) باشد، حتی اگر آرایهٔ ورودی کلیدهای دیگری داشته باشد.
        $args = array_values( $args );

        $to = preg_replace( '/\D+/', '', $to );
        if ( strpos( $to, '0' ) !== 0 && strpos( $to, '98' ) === 0 ) {
            $to = '0' . substr( $to, 2 );
        }

        $body = [
            'username' => $username,
            'password' => $password,
            'text'     => implode( ';', $args ),
            'to'       => $to,
            'bodyId'   => $body_id,
        ];

        $response = wp_remote_post( self::API_URL, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            $result = [ 'ok' => false, 'message' => $response->get_error_message() ];
            self::log_sms( $type, $to, $body_id, $args, $result );
            return $result;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        // پاسخ موفق ملی‌پیامک معمولاً یک عدد رسید (RecId) در فیلد Value برمی‌گرداند
        $ok = $code === 200 && isset( $data['RetStatus'] ) && (int) $data['RetStatus'] === 1;

        $result = [
            'ok'      => $ok,
            'message' => $ok ? 'ارسال شد' : ( $data['StrRetStatus'] ?? 'خطای نامشخص در ارسال پیامک' ),
            'raw'     => $data,
        ];
        self::log_sms( $type, $to, $body_id, $args, $result );
        return $result;
    }

    private static function infer_type( string $body_id ): string {
        foreach ( array_keys( self::NOTIFICATION_TYPES ) as $type ) {
            if ( (string) get_option( 'ta_sc_pattern_' . $type, '' ) === $body_id && $body_id !== '' ) {
                return $type;
            }
        }
        return 'unknown';
    }

    private static function log_sms( string $type, string $to, string $body_id, array $args, array $result ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ta_sms_logs';
        $status = ! empty( $result['ok'] ) ? 'sent' : 'failed';
        $context = self::$log_context;
        $wpdb->insert( $table, [
            'critique_order_id' => ! empty( $context['critique_order_id'] ) ? absint( $context['critique_order_id'] ) : null,
            'wc_order_id'       => ! empty( $context['wc_order_id'] ) ? absint( $context['wc_order_id'] ) : null,
            'notification_type' => $type,
            'recipient_role'    => sanitize_text_field( $context['recipient_role'] ?? '' ),
            'recipient_name'    => sanitize_text_field( $context['recipient_name'] ?? (string) ( $args[0] ?? '' ) ),
            'phone'             => sanitize_text_field( $to ),
            'pattern_id'        => sanitize_text_field( $body_id ),
            'status'            => $status,
            'message'           => sanitize_textarea_field( (string) ( $result['message'] ?? '' ) ),
            'provider_response' => wp_json_encode( $result['raw'] ?? $result, JSON_UNESCAPED_UNICODE ),
            'created_at'        => current_time( 'mysql' ),
        ], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );
    }

    /** پیامک ثبت سفارش به هنرجو */
    public static function notify_student( string $phone, string $student_name, string $ta_name, string $order_number ): array {
        if ( ! self::enabled( 'customer' ) ) return [ 'ok' => false, 'message' => 'غیرفعال' ];
        $body_id = get_option( 'ta_sc_pattern_customer', '' );
        return self::send_pattern( $phone, $body_id, [
            $student_name,
            $ta_name,
            $order_number,
        ] );
    }

    /** پیامک سفارش جدید به استادیار */
    public static function notify_ta( string $phone, string $ta_name, string $student_name, string $order_number ): array {
        if ( ! self::enabled( 'ta' ) ) return [ 'ok' => false, 'message' => 'غیرفعال' ];
        $body_id = get_option( 'ta_sc_pattern_ta', '' );
        return self::send_pattern( $phone, $body_id, [
            $ta_name,
            $student_name,
            $order_number,
        ] );
    }

    /** پیامک به هنرجو: استادیار کار روی نقدش را شروع کرد */
    public static function notify_student_started( string $phone, string $student_name, string $ta_name, string $order_number ): array {
        if ( ! self::enabled( 'started' ) ) return [ 'ok' => false, 'message' => 'غیرفعال' ];
        $body_id = get_option( 'ta_sc_pattern_started', '' );
        return self::send_pattern( $phone, $body_id, [
            $student_name,
            $ta_name,
            $order_number,
        ] );
    }

    /** پیامک به هنرجو: نقد اثر تحویل داده شد */
    public static function notify_student_delivered( string $phone, string $student_name, string $ta_name, string $order_number ): array {
        if ( ! self::enabled( 'delivered' ) ) return [ 'ok' => false, 'message' => 'غیرفعال' ];
        $body_id = get_option( 'ta_sc_pattern_delivered', '' );
        return self::send_pattern( $phone, $body_id, [
            $student_name,
            $ta_name,
            $order_number,
        ] );
    }

    /** پیامک به هنرجو: سفارش تکمیل شد، برو ارزیابی استاد را ثبت کن */
    public static function notify_student_evaluate( string $phone, string $student_name, string $ta_name, string $order_number ): array {
        if ( ! self::enabled( 'eval_reminder' ) ) return [ 'ok' => false, 'message' => 'غیرفعال' ];
        $body_id = get_option( 'ta_sc_pattern_eval_reminder', '' );
        return self::send_pattern( $phone, $body_id, [
            $student_name,
            $ta_name,
            $order_number,
        ] );
    }

    /** پیامک به استادیار: هنرجو سفارش را تکمیل/تأیید کرد */
    public static function notify_ta_completed( string $phone, string $ta_name, string $student_name, string $order_number ): array {
        if ( ! self::enabled( 'ta_completed' ) ) return [ 'ok' => false, 'message' => 'غیرفعال' ];
        $body_id = get_option( 'ta_sc_pattern_ta_completed', '' );
        return self::send_pattern( $phone, $body_id, [
            $ta_name,
            $student_name,
            $order_number,
        ] );
    }

    /** پیامک به هنرجو: سفارش لغو/مرجوع شد */
    public static function notify_student_cancelled( string $phone, string $student_name, string $ta_name, string $order_number ): array {
        if ( ! self::enabled( 'cancelled' ) ) return [ 'ok' => false, 'message' => 'غیرفعال' ];
        $body_id = get_option( 'ta_sc_pattern_cancelled_student', '' );
        return self::send_pattern( $phone, $body_id, [
            $student_name,
            $ta_name,
            $order_number,
        ] );
    }

    /** پیامک به استادیار: سفارش لغو/مرجوع شد */
    public static function notify_ta_cancelled( string $phone, string $ta_name, string $student_name, string $order_number ): array {
        if ( ! self::enabled( 'cancelled' ) ) return [ 'ok' => false, 'message' => 'غیرفعال' ];
        $body_id = get_option( 'ta_sc_pattern_cancelled_ta', '' );
        return self::send_pattern( $phone, $body_id, [
            $ta_name,
            $student_name,
            $order_number,
        ] );
    }

    /** پیامک به گیرنده (هنرجو یا استادیار): پیام جدید در چت */
    public static function notify_chat( string $phone, string $recipient_name, string $sender_name, string $order_number ): array {
        if ( ! self::enabled( 'chat' ) ) return [ 'ok' => false, 'message' => 'غیرفعال' ];
        $body_id = get_option( 'ta_sc_pattern_chat', '' );
        return self::send_pattern( $phone, $body_id, [
            $recipient_name,
            $sender_name,
            $order_number,
        ] );
    }

    /** پیامک به استادیار: یادآوری SLA (سفارش مدتی است بدون پیشرفت مانده) */
    public static function notify_ta_sla_reminder( string $phone, string $ta_name, string $student_name, string $order_number, string $days ): array {
        if ( ! self::enabled( 'sla_reminder' ) ) return [ 'ok' => false, 'message' => 'غیرفعال' ];
        $body_id = get_option( 'ta_sc_pattern_sla_reminder', '' );
        return self::send_pattern( $phone, $body_id, [
            $ta_name,
            $student_name,
            $order_number,
            $days,
        ] );
    }

    /** یادآوری‌های SLA جدید؛ آرگومان‌ها: نام گیرنده، نام طرف مقابل، شماره سفارش، تعداد روز */
    public static function notify_sla( string $type, string $phone, string $recipient_name, string $other_name, string $order_number, string $days ): array {
        $allowed = [ 'sla_pending', 'sla_in_progress', 'sla_delivered', 'sla_completed' ];
        if ( ! in_array( $type, $allowed, true ) ) return [ 'ok' => false, 'message' => 'نوع یادآوری نامعتبر است.' ];
        if ( ! self::enabled( $type ) ) return [ 'ok' => false, 'message' => 'غیرفعال' ];
        $body_id = get_option( 'ta_sc_pattern_' . $type, '' );
        return self::send_pattern( $phone, $body_id, [
            $recipient_name,
            $other_name,
            $order_number,
            $days,
        ] );
    }

    /** پیامک به ادمین: ظرفیت یک استادیار به صفر رسید */
    public static function notify_admin_capacity_alert( string $phone, string $ta_name ): array {
        if ( ! self::enabled( 'capacity_alert' ) ) return [ 'ok' => false, 'message' => 'غیرفعال' ];
        $body_id = get_option( 'ta_sc_pattern_capacity_alert', '' );
        return self::send_pattern( $phone, $body_id, [
            $ta_name,
        ] );
    }

    /** پیامک به خودِ استادیار: ظرفیتش به صفر رسید */
    public static function notify_ta_capacity_alert( string $phone, string $ta_name ): array {
        if ( ! self::enabled( 'capacity_alert_ta' ) ) return [ 'ok' => false, 'message' => 'غیرفعال' ];
        $body_id = get_option( 'ta_sc_pattern_capacity_alert_ta', '' );
        return self::send_pattern( $phone, $body_id, [
            $ta_name,
        ] );
    }
}
