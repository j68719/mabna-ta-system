<?php
defined( 'ABSPATH' ) || exit;

/**
 * [FIX-GROUP-LINK-SCHEME] کمک‌کار نرمال‌سازی لینک‌های گروه
 *
 * استادیار/ادمین/هنرجو گاهی لینک گروه را بدون http:// یا https:// وارد می‌کنند
 * (مثلاً صرفاً «t.me/xxxx»). این کلاس پیش از ذخیره یا اعتبارسنجی لینک، در صورت
 * نبودن schema، به‌صورت خودکار «https://» را اضافه می‌کند؛ اگر لینک از قبل
 * http:// یا https:// داشته باشد، دست‌نخورده باقی می‌ماند.
 */
class TA_URL_Helper {

    /**
     * در صورت نبود http:// یا https:// در ابتدای لینک، https:// اضافه می‌کند.
     * فاصله‌های ابتدا/انتهای رشته نیز حذف می‌شوند. ورودی خالی همان خالی برمی‌گردد.
     */
    public static function normalize( ?string $url ): string {
        $url = trim( (string) $url );

        if ( $url === '' ) {
            return '';
        }

        if ( ! preg_match( '#^https?://#i', $url ) ) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    public static function is_valid_for_messenger( ?string $url, ?string $messenger ): bool {
        $url = self::normalize( $url );
        if ( $url === '' || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return false;
        }
        $messenger = strtolower( trim( (string) $messenger ) );
        if ( $messenger === 'bale' ) {
            return (bool) preg_match( '#^https://ble\.ir/#i', $url );
        }
        if ( $messenger === 'eitaa' ) {
            return (bool) preg_match( '#^https://eitaa\.com/#i', $url );
        }
        return true;
    }

    /**
     * [GROUP-JOIN-LOG] ساخت لینک واسط برای «ورود به گروه».
     *
     * به‌جای لینک‌دهی مستقیم به group_link، کاربر را به این آدرس (روی خود سایت)
     * هدایت می‌کنیم. این آدرس ورود را لاگ می‌کند و سپس با wp_redirect کاربر را
     * به لینک واقعی گروه می‌فرستد. چون این یک لینک عادی <a href="..."> است، تمام
     * روش‌های دنبال‌کردن لینک (کلیک چپ، کلیک وسط، Ctrl+Click، تاچ، و همچنین
     * راست‌کلیک → Open in new tab/window) از همین آدرس عبور می‌کنند و لاگ می‌خورند.
     */
    public static function build_join_url( int $assignment_id ): string {
        if ( ! $assignment_id ) {
            return '';
        }
        $nonce = wp_create_nonce( 'ta_join_group_' . $assignment_id );
        return add_query_arg(
            [
                'action'        => 'ta_join_group',
                'assignment_id' => $assignment_id,
                'nonce'         => $nonce,
            ],
            admin_url( 'admin-ajax.php' )
        );
    }
}
