<?php
defined('ABSPATH') || exit;

/**
 * JalaliDate — تبدیل تاریخ میلادی به شمسی، بدون وابستگی به کتابخانه خارجی.
 * الگوریتم استاندارد تبدیل گرگوری→جلالی (مبتنی بر جدول jdf).
 */
class TA_Live_Jalali_Date {

    /**
     * فرمت‌دهی یک تاریخ (timestamp یا رشته‌ی قابل‌فهم strtotime) به شمسی.
     * توکن‌های پشتیبانی‌شده: Y m n d j H i  (مشابه فرمت date() ولی روی تقویم شمسی)
     */
    public static function format($time, string $format = 'Y/m/d', bool $persian_digits = true): string {
        if (empty($time)) return '—';
        $ts = is_numeric($time) ? (int)$time : strtotime((string)$time);
        if (!$ts) return '—';

        [$jy, $jm, $jd] = self::gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));

        $out = strtr($format, [
            'Y' => (string)$jy,
            'm' => str_pad((string)$jm, 2, '0', STR_PAD_LEFT),
            'n' => (string)$jm,
            'd' => str_pad((string)$jd, 2, '0', STR_PAD_LEFT),
            'j' => (string)$jd,
            'H' => date('H', $ts),
            'i' => date('i', $ts),
        ]);

        return $persian_digits ? self::to_persian_digits($out) : $out;
    }

    public static function gregorian_to_jalali(int $gy, int $gm, int $gd): array {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2   = ($gm > 2) ? ($gy + 1) : $gy;
        $days  = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
               + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];

        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    public static function to_persian_digits(string $str): string {
        static $en = ['0','1','2','3','4','5','6','7','8','9'];
        static $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        return str_replace($en, $fa, $str);
    }
}
