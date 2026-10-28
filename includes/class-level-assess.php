<?php
defined( 'ABSPATH' ) || exit;

class TA_Level_Assessment {

    /**
     * هنرجو: ثبت درخواست تعیین سطح + آپلود فایل
     */
    public static function submit(
        int    $student_id,
        int    $product_id,
        int    $ta_id,
        array  $uploaded_file  // $_FILES['ta_level_file']
    ): array {
        // بررسی وجود قبلی
        $table   = TA_Database::table( 'level_assessments' );
        $existing = TA_Database::get_row(
            "SELECT id, status FROM {$table} WHERE student_id = %d AND product_id = %d",
            [ $student_id, $product_id ]
        );
        if ( $existing ) {
            return [ 'success' => false, 'message' => 'شما قبلاً درخواست تعیین سطح ثبت کرده‌اید.' ];
        }

        // آپلود فایل
        $file_result = self::handle_upload( $uploaded_file, $student_id );
        if ( ! $file_result['success'] ) {
            return $file_result;
        }

        TA_Database::insert(
            'ta_level_assessments',
            [
                'student_id'         => $student_id,
                'product_id'         => $product_id,
                'ta_id'              => $ta_id,
                'file_path'          => $file_result['path'],
                'file_original_name' => $file_result['original_name'],
                'status'             => 'pending_ta',
                'created_at'         => current_time( 'mysql' ),
            ]
        );

        return [ 'success' => true, 'message' => 'فایل شما با موفقیت ارسال شد. منتظر بررسی باشید.' ];
    }

    /**
     * استادیار: تغییر وضعیت تعیین سطح
     */
    public static function update_status(
        int    $assessment_id,
        string $status,           // approved | rejected
        string $notes = ''
    ): bool {
        return false !== TA_Database::update(
            'ta_level_assessments',
            [
                'status'      => $status,
                'result_notes' => $notes,
                'updated_at'  => current_time( 'mysql' ),
            ],
            [ 'id' => $assessment_id ]
        );
    }

    /**
     * هنرجو: ثبت لینک گروه پس از تأیید تعیین سطح
     */
    public static function submit_group_link(
        int    $student_id,
        int    $product_id,
        string $group_link,
        string $messenger
    ): bool {
        return false !== TA_Database::update(
            'ta_level_assessments',
            [
                'group_link_by_student' => $group_link,
                'group_messenger'       => $messenger,
                'updated_at'            => current_time( 'mysql' ),
            ],
            [ 'student_id' => $student_id, 'product_id' => $product_id ]
        );
    }

    /**
     * دریافت تعیین سطح برای یک هنرجو و محصول
     */
    public static function get( int $student_id, int $product_id ): ?object {
        $table = TA_Database::table( 'level_assessments' );
        return TA_Database::get_row(
            "SELECT * FROM {$table} WHERE student_id = %d AND product_id = %d",
            [ $student_id, $product_id ]
        );
    }

    /**
     * لیست تعیین سطح‌های یک استادیار
     */
    public static function get_for_ta( int $ta_id, string $status = '' ): array {
        $table = TA_Database::table( 'level_assessments' );
        $sql   = "SELECT la.*, u.display_name AS student_name, u.user_email AS student_email
                  FROM {$table} la
                  JOIN {$GLOBALS['wpdb']->users} u ON u.ID = la.student_id
                  WHERE la.ta_id = %d";
        $args  = [ $ta_id ];

        if ( $status ) {
            $sql  .= ' AND la.status = %s';
            $args[] = $status;
        }
        $sql .= ' ORDER BY la.created_at DESC';

        return TA_Database::get_results( $sql, $args );
    }

    // ── File upload helper ───────────────────────────────────────────────────
    private static function handle_upload( array $file, int $student_id ): array {
        $allowed_types = [ 'application/pdf', 'application/msword',
                           'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                           'text/plain' ];

        if ( ! in_array( $file['type'], $allowed_types, true ) ) {
            return [ 'success' => false, 'message' => 'فقط فایل‌های PDF، Word و TXT مجاز هستند.' ];
        }

        if ( $file['size'] > 10 * 1024 * 1024 ) {  // 10MB
            return [ 'success' => false, 'message' => 'حجم فایل نباید بیشتر از ۱۰ مگابایت باشد.' ];
        }

        $upload_dir  = wp_upload_dir();
        $target_dir  = $upload_dir['basedir'] . '/ta-level-assessments/' . $student_id . '/';
        $target_url  = $upload_dir['baseurl'] . '/ta-level-assessments/' . $student_id . '/';

        if ( ! file_exists( $target_dir ) ) {
            wp_mkdir_p( $target_dir );
            // جلوگیری از دسترسی مستقیم
            file_put_contents( $target_dir . '.htaccess', "deny from all\n" );
        }

        $ext      = pathinfo( $file['name'], PATHINFO_EXTENSION );
        $filename = sanitize_file_name( 'level_' . $student_id . '_' . time() . '.' . $ext );

        if ( ! move_uploaded_file( $file['tmp_name'], $target_dir . $filename ) ) {
            return [ 'success' => false, 'message' => 'خطا در آپلود فایل.' ];
        }

        return [
            'success'       => true,
            'path'          => $target_dir . $filename,
            'url'           => $target_url . $filename,
            'original_name' => sanitize_file_name( $file['name'] ),
        ];
    }
}
