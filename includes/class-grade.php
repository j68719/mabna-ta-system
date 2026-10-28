<?php
defined( 'ABSPATH' ) || exit;

/**
 * مدیریت نمره‌های هنرجویان
 * 
 * نمرات بر اساس نوع دوره متفاوت‌اند:
 * - دوره‌های خلاق/نویسندگی پایه: pass | repeat | pending
 * - مقدماتی: pass | critique | repeat | pending
 * - نقد اثر: pass | repeat_critique | repeat_intro | pending
 * - پیشرفته: pass | repeat_advanced | ladder | pending
 * - نردبان حرفه‌ای: pass | repeat_ladder | repeat_advanced | pending
 * - حرفه‌ای: pass | repeat | pending
 */
class TA_Grade {

    // ── نمرات عمومی ─────────────────────────────────────────────────────────
    const GRADE_PASS    = 'pass';
    const GRADE_REPEAT  = 'repeat';
    const GRADE_PENDING = 'pending';

    // ── نمرات نقد اثر ───────────────────────────────────────────────────────
    const GRADE_CRITIQUE_REPEAT_CRITIQUE = 'repeat_critique'; // تکرار نقد اثر
    const GRADE_CRITIQUE_REPEAT_INTRO    = 'repeat_intro';    // تکرار مقدماتی

    // ── نمرات پیشرفته ───────────────────────────────────────────────────────
    const GRADE_ADVANCED_REPEAT  = 'repeat_advanced'; // تکرار دوره پیشرفته
    const GRADE_ADVANCED_LADDER  = 'ladder';          // نردبان حرفه‌ای

    // ── نمرات نردبان حرفه‌ای ───────────────────────────────────────────────
    const GRADE_LADDER_REPEAT         = 'repeat_ladder';   // تکرار نردبان
    const GRADE_LADDER_REPEAT_ADVANCED = 'repeat_advanced'; // تکرار پیشرفته

    // ── نمرات بر اساس نوع دوره ─────────────────────────────────────────────

    /**
     * نمرات مجاز برای هر نوع دوره
     */
    public static function get_grades_for_course( string $course_type ): array {
        return match( $course_type ) {
            'critique' => [
                'pass'            => [ 'label' => 'قبول',              'color' => '#10b981', 'bg' => '#d1fae5', 'icon' => '✓' ],
                'repeat_critique' => [ 'label' => 'تکرار نقد اثر',     'color' => '#f59e0b', 'bg' => '#fef3c7', 'icon' => '↺' ],
                'repeat_intro'    => [ 'label' => 'تکرار مقدماتی',     'color' => '#ef4444', 'bg' => '#fee2e2', 'icon' => '↩' ],
                'pending'         => [ 'label' => 'ثبت‌نشده',           'color' => '#6b7280', 'bg' => '#f3f4f6', 'icon' => '○' ],
            ],
            'advanced' => [
                'pass'            => [ 'label' => 'قبول',              'color' => '#10b981', 'bg' => '#d1fae5', 'icon' => '✓' ],
                'repeat_advanced' => [ 'label' => 'تکرار دوره',        'color' => '#f59e0b', 'bg' => '#fef3c7', 'icon' => '↺' ],
                'ladder'          => [ 'label' => 'نردبان حرفه‌ای',    'color' => '#8b5cf6', 'bg' => '#ede9fe', 'icon' => '🪜' ],
                'pending'         => [ 'label' => 'ثبت‌نشده',           'color' => '#6b7280', 'bg' => '#f3f4f6', 'icon' => '○' ],
            ],
            'ladder' => [
                'pass'            => [ 'label' => 'قبول',              'color' => '#10b981', 'bg' => '#d1fae5', 'icon' => '✓' ],
                'repeat_ladder'   => [ 'label' => 'تکرار نردبان',      'color' => '#f59e0b', 'bg' => '#fef3c7', 'icon' => '↺' ],
                'repeat_advanced' => [ 'label' => 'تکرار پیشرفته',    'color' => '#ef4444', 'bg' => '#fee2e2', 'icon' => '↩' ],
                'pending'         => [ 'label' => 'ثبت‌نشده',           'color' => '#6b7280', 'bg' => '#f3f4f6', 'icon' => '○' ],
            ],
            'intro' => [
                'pass'            => [ 'label' => 'قبول',          'color' => '#10b981', 'bg' => '#d1fae5', 'icon' => '✓' ],
                'critique'        => [ 'label' => 'نقد اثر',       'color' => '#8b5cf6', 'bg' => '#ede9fe', 'icon' => '✦' ],
                'repeat'          => [ 'label' => 'تکرار دوره',    'color' => '#f59e0b', 'bg' => '#fef3c7', 'icon' => '↺' ],
                'pending'         => [ 'label' => 'ثبت‌نشده',       'color' => '#6b7280', 'bg' => '#f3f4f6', 'icon' => '○' ],
            ],
            'basic_writing' => [
                'pass'             => [ 'label' => 'قبول',             'color' => '#10b981', 'bg' => '#d1fae5', 'icon' => '✓' ],
                'critique'         => [ 'label' => 'نقد اثر',          'color' => '#8b5cf6', 'bg' => '#ede9fe', 'icon' => '✦' ],
                'intro_permission'=> [ 'label' => 'مجوز مقدماتی',     'color' => '#2563eb', 'bg' => '#dbeafe', 'icon' => '→' ],
                'repeat'           => [ 'label' => 'تکرار دوره',       'color' => '#f59e0b', 'bg' => '#fef3c7', 'icon' => '↺' ],
                'pending'          => [ 'label' => 'ثبت‌نشده',          'color' => '#6b7280', 'bg' => '#f3f4f6', 'icon' => '○' ],
            ],
            default => [
                'pass'    => [ 'label' => 'قبول',        'color' => '#10b981', 'bg' => '#d1fae5', 'icon' => '✓' ],
                'repeat'  => [ 'label' => 'تکرار دوره',  'color' => '#f59e0b', 'bg' => '#fef3c7', 'icon' => '↺' ],
                'pending' => [ 'label' => 'ثبت‌نشده',     'color' => '#6b7280', 'bg' => '#f3f4f6', 'icon' => '○' ],
            ],
        };
    }

    /**
     * همه نمرات ممکن (برای فیلتر و جستجو)
     */
    public static function all_grades(): array {
        return [
            'pass'            => [ 'label' => 'قبول',              'color' => '#10b981', 'bg' => '#d1fae5', 'icon' => '✓' ],
            'repeat'          => [ 'label' => 'تکرار دوره',        'color' => '#f59e0b', 'bg' => '#fef3c7', 'icon' => '↺' ],
            'critique'         => [ 'label' => 'نقد اثر',           'color' => '#8b5cf6', 'bg' => '#ede9fe', 'icon' => '✦' ],
            'intro_permission'=> [ 'label' => 'مجوز مقدماتی',     'color' => '#2563eb', 'bg' => '#dbeafe', 'icon' => '→' ],
            'repeat_critique' => [ 'label' => 'تکرار نقد اثر',     'color' => '#7c3aed', 'bg' => '#ede9fe', 'icon' => '↺' ],
            'repeat_intro'    => [ 'label' => 'تکرار مقدماتی',     'color' => '#ef4444', 'bg' => '#fee2e2', 'icon' => '↩' ],
            'repeat_advanced' => [ 'label' => 'تکرار پیشرفته',    'color' => '#dc2626', 'bg' => '#fee2e2', 'icon' => '↩' ],
            'repeat_ladder'   => [ 'label' => 'تکرار نردبان',      'color' => '#d97706', 'bg' => '#fef3c7', 'icon' => '↺' ],
            'ladder'          => [ 'label' => 'نردبان حرفه‌ای',    'color' => '#8b5cf6', 'bg' => '#ede9fe', 'icon' => '🪜' ],
            'pending'         => [ 'label' => 'ثبت‌نشده',           'color' => '#6b7280', 'bg' => '#f3f4f6', 'icon' => '○' ],
        ];
    }

    /**
     * ثبت یا به‌روزرسانی نمره
     */
    public static function set_grade(
        int    $student_id,
        int    $product_id,
        int    $term_id,
        string $course_type,
        string $grade,
        int    $ta_id,
        string $notes = ''
    ): bool {
        global $wpdb;
        $table = TA_Database::table( 'grades' );

        $existing = TA_Database::get_row(
            "SELECT id FROM {$table} WHERE student_id = %d AND product_id = %d",
            [ $student_id, $product_id ]
        );

        if ( $existing ) {
            return false !== TA_Database::update(
                'ta_grades',
                [
                    'grade'      => $grade,
                    'ta_id'      => $ta_id,
                    'notes'      => $notes,
                    'updated_at' => current_time( 'mysql' ),
                ],
                [ 'id' => $existing->id ]
            );
        }

        return false !== TA_Database::insert(
            'ta_grades',
            [
                'student_id'  => $student_id,
                'product_id'  => $product_id,
                'term_id'     => $term_id,
                'course_type' => $course_type,
                'grade'       => $grade,
                'ta_id'       => $ta_id,
                'notes'       => $notes,
                'created_at'  => current_time( 'mysql' ),
            ]
        );
    }

    /**
     * دریافت نمره هنرجو برای یک محصول
     */
    public static function get_grade( int $student_id, int $product_id ): ?object {
        $table = TA_Database::table( 'grades' );
        return TA_Database::get_row(
            "SELECT * FROM {$table} WHERE student_id = %d AND product_id = %d",
            [ $student_id, $product_id ]
        );
    }

    /**
     * لیبل فارسی نمره
     */
    public static function grade_label( string $grade ): string {
        return self::all_grades()[ $grade ]['label'] ?? 'نامشخص';
    }

    /**
     * بررسی اینکه نمره، نوعی از «قبولی» است
     */
    public static function is_pass( string $grade ): bool {
        return $grade === self::GRADE_PASS;
    }

    /**
     * بررسی اینکه نمره «نردبان حرفه‌ای» است (از نمره پیشرفته)
     */
    public static function is_ladder_eligible( string $grade ): bool {
        return $grade === self::GRADE_ADVANCED_LADDER;
    }
}
