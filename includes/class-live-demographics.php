<?php
defined('ABSPATH') || exit;

/** محاسبه توزیع فیلدهای جمعیت‌شناختی روی سفارش‌های واجد شرایط. */
final class TA_Live_Demographics {
    public static function distribution(array $orders, string $field, int $limit = 10): array {
        $counts = [];
        foreach ($orders as $order) {
            $value = trim((string) ($order[$field] ?? ''));
            if ($value === '') $value = 'ثبت نشده';
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        if (!$counts) return [];
        arsort($counts, SORT_NUMERIC);
        $total = array_sum($counts);
        $rows = [];
        foreach (array_slice($counts, 0, max(1, $limit), true) as $label => $value) {
            $rows[] = [
                'label' => $label,
                'value' => (int) $value,
                'aggregate' => (int) $value,
                'count' => (int) $value,
                'pct' => $total > 0 ? round(($value / $total) * 100, 1) : 0,
            ];
        }
        return $rows;
    }

    public static function birth_decades(array $orders): array {
        $counts = [];
        foreach ($orders as $order) {
            $raw = trim((string) ($order['birth_date'] ?? ''));
            $decade = self::extract_birth_decade($raw);
            $label = $decade === null ? 'ثبت نشده' : TA_Live_Jalali_Date::to_persian_digits($decade . '۰');
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        arsort($counts, SORT_NUMERIC);
        $total = array_sum($counts);
        $rows = [];
        foreach ($counts as $label => $value) {
            $rows[] = [
                'label' => $label,
                'value' => (int) $value,
                'aggregate' => (int) $value,
                'count' => (int) $value,
                'pct' => $total > 0 ? round(($value / $total) * 100, 1) : 0,
            ];
        }
        return $rows;
    }

    private static function extract_birth_decade(string $raw): ?int {
        if ($raw === '') return null;
        if (preg_match('/^(13|14)(\d{2})/', $raw, $m)) return (int) ($m[1] . $m[2]);
        if (preg_match('/^(19|20)(\d{2})/', $raw, $m)) {
            $year = (int) ($m[1] . $m[2]);
            return $year >= 1900 && $year <= 2100 ? $year - 621 : null;
        }
        return null;
    }
}
