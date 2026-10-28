<?php
defined( 'ABSPATH' ) || exit;

class TA_Database {

   const SCHEMA_VERSION = '3.3.0';



    /**
     * بررسی خودکار: اگر جداول وجود ندارند، نصب کن
     */
    public static function maybe_install(): void {
        $current = get_option( 'ta_system_db_version', '0' );
        if ( $current !== self::SCHEMA_VERSION ) {
            self::install();
        }
        // migrate همیشه اجرا می‌شود — ستون‌های جدید را safe اضافه می‌کند
        self::migrate( $current );
    }

    /**
     * مایگریشن برای نصب‌های قبلی
     */
   private static function migrate( $from_version ) {
    global $wpdb;
    
    $current_version = get_option( 'ta_system_db_version', '0' );

    // Migration 3.3.0 — ظرفیت‌های هم‌مشخصات نیز مجاز هستند؛ قید یکتایی قبلی حذف می‌شود.
    $capacity_table = self::table( 'capacities' );
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$capacity_table}'" ) ) {
        $capacity_indexes = $wpdb->get_results( "SHOW INDEX FROM `{$capacity_table}`" );
        foreach ( (array) $capacity_indexes as $capacity_index ) {
            if ( isset( $capacity_index->Key_name ) && $capacity_index->Key_name === 'uniq_ta_course_term_gender_messenger' ) {
                $wpdb->query( "ALTER TABLE `{$capacity_table}` DROP INDEX `uniq_ta_course_term_gender_messenger`" );
                break;
            }
        }
    }

    // Migration 3.1.0 — یادآوری‌های SLA مستقل برای هر وضعیت سفارش.
    // این بلاک عمداً idempotent است تا روی نصب‌های قدیمی و جدید بدون خطا اجرا شود.
    $co_sla_table = self::table( 'critique_orders' );
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$co_sla_table}'" ) ) {
        $co_sla_cols = $wpdb->get_col( "DESCRIBE {$co_sla_table}", 0 );
        $sla_columns = [
            'in_progress_at'                  => "DATETIME DEFAULT NULL AFTER created_at",
            'sla_pending_eligible_at'         => "DATETIME DEFAULT NULL AFTER sla_reminder_sent",
            'sla_pending_reminder_sent'      => "TINYINT(1) NOT NULL DEFAULT 0 AFTER sla_pending_eligible_at",
            'sla_in_progress_reminder_sent'  => "TINYINT(1) NOT NULL DEFAULT 0 AFTER sla_pending_reminder_sent",
            'sla_delivered_reminder_sent'    => "TINYINT(1) NOT NULL DEFAULT 0 AFTER sla_in_progress_reminder_sent",
            'sla_completed_reminder_sent'    => "TINYINT(1) NOT NULL DEFAULT 0 AFTER sla_delivered_reminder_sent",
            'sla_pending_last_sent_at'       => "DATETIME DEFAULT NULL AFTER sla_pending_eligible_at",
        ];
        foreach ( $sla_columns as $column => $definition ) {
            if ( ! in_array( $column, $co_sla_cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$co_sla_table} ADD COLUMN {$column} {$definition}" );
            }
        }
    }
    
    // Migration 3.2.0 — تاریخچه کامل پیامک‌های نقد آثار.
    $sms_log_table = self::table( 'sms_logs' );
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$sms_log_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        critique_order_id BIGINT UNSIGNED DEFAULT NULL,
        wc_order_id BIGINT UNSIGNED DEFAULT NULL,
        notification_type VARCHAR(60) NOT NULL,
        recipient_role VARCHAR(30) DEFAULT NULL,
        recipient_name VARCHAR(191) DEFAULT NULL,
        phone VARCHAR(40) DEFAULT NULL,
        pattern_id VARCHAR(100) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'failed',
        message TEXT DEFAULT NULL,
        provider_response LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_order (critique_order_id), KEY idx_type (notification_type),
        KEY idx_status (status), KEY idx_created (created_at)
    ) " . $wpdb->get_charset_collate() );

    // قید یکتایی ظرفیت عمداً حذف شده است؛ ترکیب‌های مشابه نیز مجاز هستند.

    // Migration for version 1.1.0
    if ( version_compare( $current_version, '1.1.0', '<' ) ) {
        $wpdb->query( "ALTER TABLE {$wpdb->prefix}ta_assignments 
            ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'active'" );
    }
    
    // Migration for version 1.2.0
    if ( version_compare( $current_version, '1.2.0', '<' ) ) {
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ta_capacity (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ta_id bigint(20) NOT NULL,
            product_id bigint(20) NOT NULL,
            max_capacity int(11) DEFAULT 0,
            current_load int(11) DEFAULT 0,
            PRIMARY KEY (id),
            KEY ta_id (ta_id),
            KEY product_id (product_id)
        ) {$wpdb->get_charset_collate()};" );
    }
    
    // Migration for version 1.3.0
    if ( version_compare( $current_version, '1.3.0', '<' ) ) {
        $wpdb->query( "ALTER TABLE {$wpdb->prefix}ta_assignments 
            ADD COLUMN IF NOT EXISTS assigned_date datetime DEFAULT NULL" );
    }
    
    // Migration for version 1.4.0
    if ( version_compare( $current_version, '1.4.0', '<' ) ) {
        $wpdb->query( "ALTER TABLE {$wpdb->prefix}ta_grades 
            ADD COLUMN IF NOT EXISTS feedback_date datetime DEFAULT NULL" );
    }
    
   // آپدیت نسخه 1.6.0
if ( version_compare( $current_version, '1.6.0', '<' ) ) {
    $table_assignments = self::table('assignments');
    
    // چک کردن وجود ستون‌ها قبل از اضافه کردن
    $columns = $wpdb->get_col( "DESCRIBE {$table_assignments}", 0 );
    
    if ( ! in_array( 'cancel_reason', $columns ) ) {
        $wpdb->query("ALTER TABLE {$table_assignments} ADD COLUMN cancel_reason TEXT DEFAULT NULL");
    }
    
    if ( ! in_array( 'cancel_date', $columns ) ) {
        $wpdb->query("ALTER TABLE {$table_assignments} ADD COLUMN cancel_date DATETIME DEFAULT NULL");
    }
}

    // [BUGFIX-NOTES-COLUMN] افزودن ستون notes به ta_assignments — همیشه اجرا می‌شود (idempotent)
    // قبلاً admin_msg_mark_read() سعی می‌کرد روی این ستونِ ناموجود بنویسد و همیشه با خطای
    // دیتابیس مواجه می‌شد. این بلاک مستقل از شماره نسخه است تا حتی نصب‌های قدیمی هم اصلاح شوند.
    {
        $table_assignments = self::table('assignments');
        $columns = $wpdb->get_col( "DESCRIBE {$table_assignments}", 0 );
        if ( ! in_array( 'notes', $columns ) ) {
            $wpdb->query( "ALTER TABLE {$table_assignments} ADD COLUMN notes TEXT DEFAULT NULL" );
        }
    }

   

     // Migration for version 1.7.0 - payroll approval requests
    if ( version_compare( $current_version, '1.7.0', '<' ) ) {
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ta_payroll_requests (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ta_id       BIGINT UNSIGNED NOT NULL,
            term_id     BIGINT UNSIGNED NOT NULL,
            total_fee   DECIMAL(15,2) NOT NULL DEFAULT 0,
            status      VARCHAR(20)   NOT NULL DEFAULT 'pending',
            admin_note  TEXT          DEFAULT NULL,
            ta_note     TEXT          DEFAULT NULL,
            requested_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at  DATETIME     DEFAULT NULL,
            paid_at      DATETIME     DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_ta_term (ta_id, term_id),
            KEY idx_ta   (ta_id),
            KEY idx_term (term_id),
            KEY idx_status (status)
        ) " . $wpdb->get_charset_collate() );
    }

    // Migration for version 1.1.3 - payroll request logs + approved_fee column
    if ( version_compare( $current_version, '1.1.3', '<' ) ) {
        $tbl = self::table('payroll_requests');
        $cols = $wpdb->get_col( "DESCRIBE {$tbl}", 0 );
        if ( ! in_array( 'approved_fee', $cols ) ) {
            $wpdb->query( "ALTER TABLE {$tbl} ADD COLUMN approved_fee DECIMAL(15,2) DEFAULT NULL AFTER total_fee" );
        }
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ta_payroll_request_logs (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id   BIGINT UNSIGNED NOT NULL,
            ta_id        BIGINT UNSIGNED NOT NULL,
            term_id      BIGINT UNSIGNED NOT NULL,
            action       VARCHAR(30)  NOT NULL,
            actor_type   VARCHAR(10)  NOT NULL DEFAULT 'admin',
            actor_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            total_fee    DECIMAL(15,2) NOT NULL DEFAULT 0,
            note         TEXT DEFAULT NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_request (request_id),
            KEY idx_ta      (ta_id),
            KEY idx_term    (term_id)
        ) " . $wpdb->get_charset_collate() );
    }


    // Migration: اطمینان از وجود همه ستون‌های لازم (همیشه اجرا — safe با IF NOT EXISTS)
    {
        $tbl  = self::table('payroll_requests');
        $cols = $wpdb->get_col( "DESCRIBE {$tbl}", 0 );
        $needed = [
            'approved_fee'      => "ALTER TABLE {$tbl} ADD COLUMN approved_fee      DECIMAL(15,2) DEFAULT NULL AFTER total_fee",
            'prev_approved_fee' => "ALTER TABLE {$tbl} ADD COLUMN prev_approved_fee DECIMAL(15,2) DEFAULT NULL AFTER approved_fee",
            'diff_status'       => "ALTER TABLE {$tbl} ADD COLUMN diff_status       VARCHAR(20)   DEFAULT NULL AFTER paid_at",
            'diff_amount'       => "ALTER TABLE {$tbl} ADD COLUMN diff_amount       DECIMAL(15,2) DEFAULT NULL AFTER diff_status",
            'diff_approved_fee' => "ALTER TABLE {$tbl} ADD COLUMN diff_approved_fee DECIMAL(15,2) DEFAULT NULL AFTER diff_amount",
            'diff_ta_note'      => "ALTER TABLE {$tbl} ADD COLUMN diff_ta_note      TEXT          DEFAULT NULL AFTER diff_approved_fee",
            'diff_admin_note'   => "ALTER TABLE {$tbl} ADD COLUMN diff_admin_note   TEXT          DEFAULT NULL AFTER diff_ta_note",
            'diff_reviewed_at'  => "ALTER TABLE {$tbl} ADD COLUMN diff_reviewed_at  DATETIME      DEFAULT NULL AFTER diff_admin_note",
            'diff_paid_at'      => "ALTER TABLE {$tbl} ADD COLUMN diff_paid_at      DATETIME      DEFAULT NULL AFTER diff_reviewed_at",
        ];
        foreach ( $needed as $col => $sql ) {
            if ( ! in_array( $col, $cols ) ) $wpdb->query( $sql );
        }
    }


    // Migration for version 1.3.4 - payroll adjustments (extra/deduction per TA per term)
    {
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ta_payroll_adjustments (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ta_id       BIGINT UNSIGNED NOT NULL,
            term_id     BIGINT UNSIGNED NOT NULL,
            type        ENUM('extra','deduction') NOT NULL DEFAULT 'extra',
            amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
            description TEXT DEFAULT NULL,
            created_by  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ta_term (ta_id, term_id),
            KEY idx_term    (term_id),
            KEY idx_ta      (ta_id)
        ) " . $wpdb->get_charset_collate() );
    }

    // Migration: اضافه کردن ta_code به جدول استادیارها (همیشه safe)
    {
        $tbl  = self::table('assistants');
        $cols = $wpdb->get_col( "DESCRIBE {$tbl}", 0 );
        if ( ! in_array( 'ta_code', $cols ) ) {
            $wpdb->query( "ALTER TABLE {$tbl} ADD COLUMN ta_code VARCHAR(20) DEFAULT NULL AFTER `rank`" );
            $wpdb->query( "ALTER TABLE {$tbl} ADD UNIQUE KEY `ta_code` (`ta_code`)" );
        }
    }

    // Migration 2.4.0 — جدول لاگ ورود به گروه
    if ( version_compare( $current_version, '2.4.0', '<' ) ) {
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_group_join_logs` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `assignment_id` BIGINT UNSIGNED NOT NULL,
            `student_id`    BIGINT UNSIGNED NOT NULL,
            `product_id`    BIGINT UNSIGNED NOT NULL,
            `actor_type`    ENUM('student','ta','admin') NOT NULL,
            `actor_id`      BIGINT UNSIGNED NOT NULL,
            `messenger`     VARCHAR(30) DEFAULT NULL,
            `group_link`    VARCHAR(500) DEFAULT NULL,
            `note`          TEXT DEFAULT NULL,
            `logged_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_assignment` (`assignment_id`),
            KEY `idx_student`    (`student_id`),
            KEY `idx_actor`      (`actor_type`, `actor_id`),
            KEY `idx_logged_at`  (`logged_at`)
        ) " . $wpdb->get_charset_collate() );
    }

    // Migration 2.5.0 — جدول لاگ واقعی ورود (به‌جای ثبت دستی، ورود واقعی کاربر ثبت می‌شود)
    if ( version_compare( $current_version, '2.5.0', '<' ) ) {
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_login_logs` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`    BIGINT UNSIGNED NOT NULL,
            `actor_type` ENUM('student','ta','admin') NOT NULL,
            `logged_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user`      (`user_id`),
            KEY `idx_actor`     (`actor_type`, `user_id`),
            KEY `idx_logged_at` (`logged_at`)
        ) " . $wpdb->get_charset_collate() );
    }

    // [GROUP-REVIEW] افزودن ستون admin_reviewed به ta_assignments — مستقل از شماره نسخه
    // اجرا می‌شود (idempotent)، دقیقاً مثل بلاک BUGFIX-NOTES-COLUMN بالا؛ چون install()
    // پیش از migrate() آپشن نسخه را به‌روزرسانی می‌کند، شرط version_compare برای همین
    // نسخه همیشه false می‌شود و ستون هرگز اضافه نمی‌شد.
    {
        $tbl  = self::table('assignments');
        $cols = $wpdb->get_col( "DESCRIBE {$tbl}", 0 );
        if ( ! in_array( 'admin_reviewed', $cols ) ) {
            $wpdb->query( "ALTER TABLE {$tbl} ADD COLUMN admin_reviewed TINYINT(1) NOT NULL DEFAULT 0 AFTER notes" );
        }
    }

    // Migration 2.7.0 — گسترش نقد آثار: ظرفیت/قیمت‌پایه/رزومه + جدول تخصص‌ها + لاگ تغییرات
    if ( version_compare( $current_version, '2.7.0', '<' ) ) {
        $sc_tbl = self::table( 'story_critique_forms' );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$sc_tbl}'" ) ) {
            $cols = $wpdb->get_col( "DESCRIBE {$sc_tbl}", 0 );
            if ( ! in_array( 'capacity', $cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$sc_tbl} ADD COLUMN capacity INT UNSIGNED DEFAULT NULL AFTER hours_per_day" );
            }
            if ( ! in_array( 'base_price', $cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$sc_tbl} ADD COLUMN base_price DECIMAL(15,2) DEFAULT NULL AFTER capacity" );
            }
            if ( ! in_array( 'resume', $cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$sc_tbl} ADD COLUMN resume TEXT DEFAULT NULL AFTER base_price" );
            }
            if ( ! in_array( 'updated_at', $cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$sc_tbl} ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at" );
            }
        }

        // seed اولیهٔ تخصص‌های نقد آثار (فقط یک‌بار — اگر جدول خالی باشد)
        $spec_tbl = self::table( 'specialties' );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$spec_tbl}'" ) ) {
            $has_any = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$spec_tbl}" );
            if ( ! $has_any ) {
                $default_specs = [ 'کودک', 'نوجوان', 'رمان', 'طنز', 'داستان', 'روایت', 'فیلمنامه', 'مرورنویسی', 'نقد فیلم', 'تسهیلگر در حوزه کتاب‌خوانی' ];
                foreach ( $default_specs as $order => $name ) {
                    $wpdb->insert( $spec_tbl, [
                        'name'       => $name,
                        'sort_order' => $order,
                        'is_active'  => 1,
                        'created_at' => current_time( 'mysql' ),
                    ] );
                }
            }
        }
    }

    // Migration 2.8.0 — سفارش‌های نقد آثار + نظرسنجی ارزیابی + فیلدهای قیمت‌گذاری بر اساس کلمه
    if ( version_compare( $current_version, '2.8.0', '<' ) ) {
        $sc_tbl = self::table( 'story_critique_forms' );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$sc_tbl}'" ) ) {
            $cols = $wpdb->get_col( "DESCRIBE {$sc_tbl}", 0 );
            foreach ( [
                'base_word_count'   => 'INT UNSIGNED DEFAULT NULL',
                'increment_words'   => 'INT UNSIGNED DEFAULT NULL',
                'increment_percent' => 'DECIMAL(6,2) DEFAULT NULL',
            ] as $col => $def ) {
                if ( ! in_array( $col, $cols, true ) ) {
                    $wpdb->query( "ALTER TABLE {$sc_tbl} ADD COLUMN {$col} {$def}" );
                }
            }
        }

        // سؤال پیش‌فرض نظرسنجی (فقط یک‌بار)
        $eval_q_tbl = self::table( 'eval_questions' );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$eval_q_tbl}'" ) ) {
            $has_any = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$eval_q_tbl}" );
            if ( ! $has_any ) {
                $wpdb->insert( $eval_q_tbl, [
                    'question'   => 'رضایت کلی شما از کیفیت نقد ارائه‌شده چقدر است؟',
                    'type'       => 'rating',
                    'sort_order' => 0,
                    'is_active'  => 1,
                    'created_at' => current_time( 'mysql' ),
                ] );
                $wpdb->insert( $eval_q_tbl, [
                    'question'   => 'اگر نظر یا پیشنهادی دارید بنویسید.',
                    'type'       => 'text',
                    'sort_order' => 1,
                    'is_active'  => 1,
                    'created_at' => current_time( 'mysql' ),
                ] );
            }
        }
    }

    // Migration 2.9.0 — لینک گروه بله استاد برای نقد آثار (نمایش شرطی به هنرجو بر اساس وضعیت سفارش)
    if ( version_compare( $current_version, '2.9.0', '<' ) ) {
        $sc_tbl = self::table( 'story_critique_forms' );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$sc_tbl}'" ) ) {
            $cols = $wpdb->get_col( "DESCRIBE {$sc_tbl}", 0 );
            if ( ! in_array( 'group_link', $cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$sc_tbl} ADD COLUMN group_link VARCHAR(500) DEFAULT NULL AFTER resume" );
            }
        }
    }

    // Migration 2.9.1 — ترمیمِ خودکار (repair pass) برای نصب‌هایی که به هر دلیل
    // (مثلاً ثبت نسخهٔ دیتابیس بدون اجرای واقعیِ ALTER TABLE در یک دیپلوی ناقص قبلی)
    // ستون‌های نقد آثار را از دست داده‌اند، با اینکه شمارهٔ نسخهٔ ذخیره‌شده
    // می‌گفته این مایگریشن‌ها قبلاً انجام شده. چون شمارهٔ نسخهٔ 2.9.1 برای هیچ
    // نصب قبلی‌ای وجود نداشته، این بلاک تضمین می‌کند حداقل یک‌بار روی همهٔ
    // سایت‌ها اجرا و وجود واقعی ستون‌ها مستقیماً از دیتابیس چک شود (نه فقط بر
    // پایهٔ شمارهٔ نسخهٔ ذخیره‌شده که ممکن است نادرست باشد).
    if ( version_compare( $current_version, '2.9.2', '<' ) ) {
        $sc_tbl = self::table( 'story_critique_forms' );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$sc_tbl}'" ) ) {
            $cols = $wpdb->get_col( "DESCRIBE {$sc_tbl}", 0 );
            $required_cols = [
                'capacity'           => "INT UNSIGNED DEFAULT NULL AFTER hours_per_day",
                'base_price'         => "DECIMAL(15,2) DEFAULT NULL AFTER capacity",
                'resume'             => "TEXT DEFAULT NULL AFTER base_price",
                'group_link'         => "VARCHAR(500) DEFAULT NULL AFTER resume",
                'base_word_count'    => "INT UNSIGNED DEFAULT NULL",
                'increment_words'    => "INT UNSIGNED DEFAULT NULL",
                'increment_percent'  => "DECIMAL(6,2) DEFAULT NULL",
                'updated_at'         => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
            ];
            foreach ( $required_cols as $col => $def ) {
                if ( ! in_array( $col, $cols, true ) ) {
                    $wpdb->query( "ALTER TABLE {$sc_tbl} ADD COLUMN `{$col}` {$def}" );
                    $cols[] = $col; // جلوگیری از خطای AFTER برای ستونی که همین الان اضافه شد
                }
            }
        }
    }

    // [v2.9.3] افزودن وضعیت «cancelled» به ستون status جدول critique_orders
    // (برای برگرداندن ظرفیت استادیار هنگام لغو/مرجوع‌شدن سفارش) و جدول جدید
    // ta_critique_messages (چت هنرجو/استادیار/ادمین).
    if ( version_compare( $current_version, '2.9.3', '<' ) ) {
        $co_tbl = self::table( 'critique_orders' );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$co_tbl}'" ) ) {
            $wpdb->query( "ALTER TABLE `{$co_tbl}` MODIFY COLUMN `status` ENUM('pending','in_progress','delivered','completed','cancelled') NOT NULL DEFAULT 'pending'" );
        }
    }

    // [v2.9.4] ستون سلامتِ یادآوری SLA (برای این‌که هر سفارش فقط یک‌بار
    // یادآوری «مدت زیادی است پیشرفتی نداشته» برای استادیار دریافت کند)
    // و اعلان‌های جدید پیامکی: لغو سفارش، پیام جدید در چت، هشدار تکمیل ظرفیت.
    if ( version_compare( $current_version, '2.9.4', '<' ) ) {
        $co_tbl = self::table( 'critique_orders' );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$co_tbl}'" ) ) {
            $cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$co_tbl}`" );
            if ( ! in_array( 'sla_reminder_sent', $cols, true ) ) {
                $wpdb->query( "ALTER TABLE `{$co_tbl}` ADD COLUMN `sla_reminder_sent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `sms_sent`" );
            }
        }
    }

    // [v2.9.5] ستون «شغل/نقش» استادیار (مثلاً «استادیار و منتقد») برای نمایش
    // در کارت و مودال «اطلاعات بیشتر» صفحهٔ نقد آثار — از صفحهٔ استادیارها قابل ویرایش است.
    if ( version_compare( $current_version, '2.9.5', '<' ) ) {
        $sc_tbl = self::table( 'story_critique_forms' );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$sc_tbl}'" ) ) {
            $cols = $wpdb->get_col( "DESCRIBE {$sc_tbl}", 0 );
            if ( ! in_array( 'role_title', $cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$sc_tbl} ADD COLUMN role_title VARCHAR(191) DEFAULT NULL AFTER group_link" );
            }
        }
    }

    // [v2.9.7] حذف کامل شناسه‌های بله از سیستم نقد آثار
    if ( version_compare( $current_version, '2.9.7', '<' ) ) {
        $sc_tbl = self::table( 'story_critique_forms' );
        $co_tbl = self::table( 'critique_orders' );
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$sc_tbl}'" ) ) {
            $cols = $wpdb->get_col( "DESCRIBE `{$sc_tbl}`", 0 );
            if ( in_array( 'bale_id', $cols, true ) ) $wpdb->query( "ALTER TABLE `{$sc_tbl}` DROP COLUMN `bale_id`" );
        }
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$co_tbl}'" ) ) {
            $cols = $wpdb->get_col( "DESCRIBE `{$co_tbl}`", 0 );
            if ( in_array( 'student_bale_id', $cols, true ) ) $wpdb->query( "ALTER TABLE `{$co_tbl}` DROP COLUMN `student_bale_id`" );
        }
    }

    // Migration 2.9.8 — فرم‌های استاندارد و مستقل ارزیابی تجربهٔ نقد آثار.
    // شرط خالی‌بودن جدول، repair pass نصب‌هایی است که نسخهٔ migration ثبت شده
    // اما seed سؤال‌های فرم دوم در آن‌ها کامل اجرا نشده است.
    $student_table = self::table( 'eval_questions' );
    $critic_table  = self::table( 'student_eval_questions' );
    if ( version_compare( $current_version, '2.9.8', '<' ) || ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$critic_table}" ) || get_option( 'ta_eval_content_sync_298', '0' ) !== '1' ) {
        $now = current_time( 'mysql' );
        $student_questions = [
            [ 'تعهد زمانی', 'منتقد به زمان‌بندی توافق‌شده برای تحویل نقد و برگزاری گفت‌وگو پایبند بود.', [ 'کاملاً', 'تا حد زیادی', 'تا حدی', 'کم', 'اصلاً', 'امکان ارزیابی این مورد را نداشتم' ] ],
            [ 'دقت و تسلط در نقد', 'نقد نشان می‌داد منتقد متن را با دقت بررسی کرده و بر موضوعات مطرح‌شده تسلط کافی دارد.', [ 'کاملاً', 'تا حد زیادی', 'تا حدی', 'کم', 'اصلاً', 'امکان ارزیابی این مورد را نداشتم' ] ],
            [ 'استناد به متن', 'منتقد برای توضیح قوت‌ها، ضعف‌ها و نکات قابل بهبود، به نمونه‌های مشخص از خود متن استناد می‌کرد.', [ 'کاملاً', 'تا حد زیادی', 'تا حدی', 'کم', 'اصلاً', 'امکان ارزیابی این مورد را نداشتم' ] ],
            [ 'راهگشا بودن نقد', 'نقد و پیشنهادهای ارائه‌شده به من کمک کرد بدانم برای بهتر شدن متن، روی چه چیزهایی و چگونه کار کنم.', [ 'کاملاً', 'تا حد زیادی', 'تا حدی', 'کم', 'اصلاً', 'امکان ارزیابی این مورد را نداشتم' ] ],
            [ 'پاسخگویی و گفت‌وگو', 'منتقد با دقت، حوصله و شفافیت به پرسش‌ها و ابهام‌های من پاسخ داد.', [ 'کاملاً', 'تا حد زیادی', 'تا حدی', 'کم', 'اصلاً', 'امکان ارزیابی این مورد را نداشتم' ] ],
            [ 'اخلاق و تعامل حرفه‌ای', 'در تمام مراحل، احترام، مرزهای حرفه‌ای و شأن نویسنده رعایت شد.', [ 'کاملاً', 'تا حد زیادی', 'تا حدی', 'کم', 'اصلاً', 'امکان ارزیابی این مورد را نداشتم' ] ],
            [ 'ارزیابی کلی', 'در مجموع، تجربهٔ نقد با این منتقد را چگونه ارزیابی می‌کنید؟', [ 'عالی', 'خوب', 'متوسط', 'نیازمند بهبود', 'ضعیف' ] ],
            [ 'نظر شما', 'اگر نکته‌ای برای بهتر شدن کیفیت نقد یا تجربهٔ همکاری دارید، با ما در میان بگذارید.', [] ],
        ];
        $critic_questions = [
            [ 'تعهد زمانی و نظم', 'هنرجو زمان‌بندی، مهلت‌ها و چارچوب‌های توافق‌شده را رعایت می‌کرد.', [ 'عالی', 'خوب', 'متوسط', 'نیازمند بهبود', 'ضعیف', 'در این‌باره موضوعی پیش نیامد' ] ],
            [ 'مواجهه با نقد', 'هنرجو با رویکردی سازنده با نقد روبه‌رو می‌شد و برای شنیدن، فهمیدن و گفت‌وگو دربارهٔ آن آمادگی داشت.', [ 'عالی', 'خوب', 'متوسط', 'نیازمند بهبود', 'ضعیف', 'در این‌باره موضوعی پیش نیامد' ] ],
            [ 'کیفیت پرسش و پیگیری', 'پرسش‌ها و پیگیری‌های هنرجو روشن، مرتبط با متن و نقد و قابل پاسخگویی بود.', [ 'عالی', 'خوب', 'متوسط', 'نیازمند بهبود', 'ضعیف', 'در این‌باره موضوعی پیش نیامد' ] ],
            [ 'مشارکت در گفت‌وگوی حرفه‌ای', 'هنرجو در گفت‌وگو مشارکت می‌کرد و دربارهٔ انتخاب‌ها، منظور و منطق متن خود توضیح می‌داد.', [ 'عالی', 'خوب', 'متوسط', 'نیازمند بهبود', 'ضعیف', 'در این‌باره موضوعی پیش نیامد' ] ],
            [ 'تأمل دربارهٔ بازخوردها', 'هنرجو آمادگی داشت بازخوردها را بررسی کند و حتی در صورت موافق نبودن، دربارهٔ آن‌ها تأمل و گفت‌وگو کند.', [ 'عالی', 'خوب', 'متوسط', 'نیازمند بهبود', 'ضعیف', 'در این‌باره موضوعی پیش نیامد' ] ],
            [ 'اخلاق و تعامل حرفه‌ای', 'در طول مسیر همراهی، احترام، مرزهای حرفه‌ای و چارچوب ارتباطی رعایت شد.', [ 'عالی', 'خوب', 'متوسط', 'نیازمند بهبود', 'ضعیف', 'در این‌باره موضوعی پیش نیامد' ] ],
            [ 'ارزیابی کلی همکاری', 'کیفیت تعامل و همکاری این هنرجو را در مجموع چگونه ارزیابی می‌کنید؟', [ 'عالی', 'خوب', 'متوسط', 'نیازمند بهبود', 'ضعیف' ] ],
            [ 'نام متن', 'نام متن نقدشده را وارد کنید.', [] ],
            [ 'قالب / گونه', 'قالب یا گونهٔ متن را وارد کنید.', [] ],
            [ 'امتیاز متن', 'امتیاز متن از ۱۰؛ این امتیاز ارزیابی کیفیت فعلی همین متن است، نه توانایی یا استعداد نویسنده.', [] ],
            [ 'نظر کلی دربارهٔ متن', 'مهم‌ترین نقطه قوت متن و مهم‌ترین زمینه برای رشد و بهبود آن را کوتاه بیان کنید.', [] ],
        ];
        // همگام‌سازی متن‌های رسمی فرم با نصب‌های قبلی؛ این بخش idempotent است.
        foreach ( [ $student_table => $student_questions, $critic_table => $critic_questions ] as $table_name => $canonical_questions ) {
            foreach ( $canonical_questions as $sort_order => $canonical ) {
                $existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE sort_order = %d ORDER BY id ASC LIMIT 1", $sort_order ) );
                $data = [
                    'question'   => $canonical[1],
                    'type'       => empty( $canonical[2] ) ? 'text' : 'choice',
                    'options'    => $canonical[2] ? wp_json_encode( $canonical[2], JSON_UNESCAPED_UNICODE ) : null,
                    'is_active'  => 1,
                ];
                if ( $existing_id ) {
                    $wpdb->update( $table_name, $data, [ 'id' => $existing_id ] );
                } else {
                    $data['sort_order'] = $sort_order;
                    $data['created_at'] = $now;
                    $wpdb->insert( $table_name, $data );
                }
            }
        }
        $student_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$student_table}" );
        if ( $student_count <= 2 ) {
            $wpdb->query( "DELETE FROM {$student_table}" );
            foreach ( $student_questions as $i => $q ) {
                $wpdb->insert( $student_table, [ 'question' => $q[1], 'type' => empty( $q[2] ) ? 'text' : 'choice', 'options' => $q[2] ? wp_json_encode( $q[2], JSON_UNESCAPED_UNICODE ) : null, 'sort_order' => $i, 'is_active' => 1, 'created_at' => $now ] );
            }
        }
        $critic_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$critic_table}" );
        if ( ! $critic_count ) {
            foreach ( $critic_questions as $i => $q ) {
                $wpdb->insert( $critic_table, [ 'question' => $q[1], 'type' => empty( $q[2] ) ? 'text' : 'choice', 'options' => $q[2] ? wp_json_encode( $q[2], JSON_UNESCAPED_UNICODE ) : null, 'sort_order' => $i, 'is_active' => 1, 'created_at' => $now ] );
            }
        }
        update_option( 'ta_eval_content_sync_298', '1' );
    }

    // [EVAL-DEDUPE] جلوگیری دائمی از تکرار سؤال‌ها در هر دو فرم.
    // پاسخ‌های سؤال تکراری ابتدا به اولین سؤال منتقل می‌شوند تا گزارش‌های قبلی حفظ شوند.
    foreach ( [
        $student_table => self::table( 'eval_responses' ),
        $critic_table  => self::table( 'student_eval_responses' ),
    ] as $question_table => $response_table ) {
        $seen_questions = [];
        $question_rows  = $wpdb->get_results( "SELECT id, question FROM {$question_table} ORDER BY id ASC" );
        foreach ( $question_rows as $question_row ) {
            $question_key = function_exists( 'mb_strtolower' )
                ? mb_strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $question_row->question ) ), 'UTF-8' )
                : strtolower( trim( preg_replace( '/\s+/', ' ', (string) $question_row->question ) ) );
            if ( $question_key === '' ) continue;
            if ( isset( $seen_questions[ $question_key ] ) ) {
                $keeper_id = (int) $seen_questions[ $question_key ];
                $duplicate_id = (int) $question_row->id;
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$response_table} SET question_id = %d WHERE question_id = %d",
                    $keeper_id,
                    $duplicate_id
                ) );
                $wpdb->delete( $question_table, [ 'id' => $duplicate_id ], [ '%d' ] );
            } else {
                $seen_questions[ $question_key ] = (int) $question_row->id;
            }
        }
    }

    update_option( 'ta_system_db_version', self::SCHEMA_VERSION );
}

    /**
     * بررسی اینکه آیا جداول اصلی وجود دارند
     */
    public static function tables_exist(): bool {
        global $wpdb;
        $result = $wpdb->get_var(
            "SHOW TABLES LIKE '{$wpdb->prefix}ta_assistants'"
        );
        return ! empty( $result );
    }

    /**
     * ایجاد جداول با CREATE TABLE IF NOT EXISTS
     */
    public static function install(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $tables = [

            // ── ترم‌ها ──────────────────────────────────────────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_terms` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`        VARCHAR(100) NOT NULL,
                `slug`        VARCHAR(100) NOT NULL,
                `order_index` INT NOT NULL DEFAULT 0,
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `slug` (`slug`)
            ) {$charset}",

            // ── تنظیمات محصول ───────────────────────────────────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_product_settings` (
                `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_id`     BIGINT UNSIGNED NOT NULL,
                `term_id`        BIGINT UNSIGNED NOT NULL,
                `course_type`    VARCHAR(20) NOT NULL,
                `pro_group_link` VARCHAR(500) DEFAULT NULL,
                `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `product_id` (`product_id`),
                KEY `idx_term` (`term_id`)
            ) {$charset}",

            // ── پیش‌نیازهای چندگانه ──────────────────────────────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_product_prerequisites` (
                `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_id`              BIGINT UNSIGNED NOT NULL,
                `prerequisite_product_id` BIGINT UNSIGNED NOT NULL,
                `pass_eligible`           TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_prod_prereq` (`product_id`, `prerequisite_product_id`),
                KEY `idx_product` (`product_id`)
            ) {$charset}",

            // ── استادیارها ───────────────────────────────────────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_assistants` (
                `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`      BIGINT UNSIGNED NOT NULL,
                `rank`         INT UNSIGNED NOT NULL DEFAULT 0,
                `ta_code`      VARCHAR(20) DEFAULT NULL,
                `bio`          TEXT DEFAULT NULL,
                `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
                `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `user_id` (`user_id`),
                UNIQUE KEY `ta_code` (`ta_code`),
                KEY `idx_rank` (`rank`)
            ) {$charset}",

            // ── ظرفیت استادیار ───────────────────────────────────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_capacities` (
                `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `ta_id`          BIGINT UNSIGNED NOT NULL,
                `course_type`    VARCHAR(20) NOT NULL,
                `term_id`        BIGINT UNSIGNED NOT NULL,
                `student_gender` VARCHAR(10) NOT NULL DEFAULT 'both',
                `messenger`      VARCHAR(20) NOT NULL DEFAULT 'both',
                `total_capacity` INT UNSIGNED NOT NULL DEFAULT 0,
                `used_capacity`  INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_ta` (`ta_id`)
            ) {$charset}",

            // ── گروه‌های نویسندگی خلاق ───────────────────────────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_groups` (
                `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `capacity_id`         BIGINT UNSIGNED NOT NULL,
                `ta_id`               BIGINT UNSIGNED NOT NULL,
                `messenger_type`      VARCHAR(20) NOT NULL,
                `group_link`          VARCHAR(500) NOT NULL,
                `is_used`             TINYINT(1) NOT NULL DEFAULT 0,
                `assigned_to_user_id` BIGINT UNSIGNED DEFAULT NULL,
                `assigned_at`         DATETIME DEFAULT NULL,
                `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_capacity` (`capacity_id`),
                KEY `idx_ta` (`ta_id`),
                KEY `idx_used` (`is_used`)
            ) {$charset}",

            // ── تخصیص هنرجو به استادیار ─────────────────────────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_assignments` (
                `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `student_id`     BIGINT UNSIGNED NOT NULL,
                `ta_id`          BIGINT UNSIGNED NOT NULL,
                `product_id`     BIGINT UNSIGNED NOT NULL,
                `term_id`        BIGINT UNSIGNED NOT NULL,
                `capacity_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `course_type`    VARCHAR(20) NOT NULL,
                `messenger`      VARCHAR(20) DEFAULT NULL,
                `student_gender` VARCHAR(10) DEFAULT NULL,
                `group_id`       BIGINT UNSIGNED DEFAULT NULL,
                `group_link`     VARCHAR(500) DEFAULT NULL,
                `status`         VARCHAR(20) NOT NULL DEFAULT 'active',
                `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `cancel_reason`  text DEFAULT NULL,
                `cancel_date`    datetime DEFAULT NULL,
                `notes`          TEXT DEFAULT NULL,
                `admin_reviewed` TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_student_product` (`student_id`, `product_id`),
                KEY `idx_student` (`student_id`),
                KEY `idx_ta` (`ta_id`),
                KEY `idx_term` (`term_id`)
            ) {$charset}",

            // ── نمره‌ها ──────────────────────────────────────────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_grades` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `student_id`  BIGINT UNSIGNED NOT NULL,
                `product_id`  BIGINT UNSIGNED NOT NULL,
                `term_id`     BIGINT UNSIGNED NOT NULL,
                `course_type` VARCHAR(20) NOT NULL,
                `grade`       VARCHAR(20) NOT NULL,
                `ta_id`       BIGINT UNSIGNED NOT NULL,
                `notes`       TEXT DEFAULT NULL,
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_student_product` (`student_id`, `product_id`),
                KEY `idx_student` (`student_id`),
                KEY `idx_term` (`term_id`),
                KEY `idx_grade` (`grade`)
            ) {$charset}",

            // ── تعیین سطح ────────────────────────────────────────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_level_assessments` (
                `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `student_id`            BIGINT UNSIGNED NOT NULL,
                `product_id`            BIGINT UNSIGNED NOT NULL,
                `ta_id`                 BIGINT UNSIGNED DEFAULT NULL,
                `file_path`             VARCHAR(500) DEFAULT NULL,
                `file_original_name`    VARCHAR(255) DEFAULT NULL,
                `status`                VARCHAR(30) NOT NULL DEFAULT 'pending_ta',
                `group_link_by_student` VARCHAR(500) DEFAULT NULL,
                `group_messenger`       VARCHAR(20) DEFAULT NULL,
                `result_notes`          TEXT DEFAULT NULL,
                `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_student_product` (`student_id`, `product_id`),
                KEY `idx_student` (`student_id`),
                KEY `idx_ta` (`ta_id`),
                KEY `idx_status` (`status`)
            ) {$charset}",

            // ── درخواست‌های تایید حق‌الزحمه ─────────────────────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_payroll_requests` (
                `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `ta_id`        BIGINT UNSIGNED NOT NULL,
                `term_id`      BIGINT UNSIGNED NOT NULL,
                `total_fee`    DECIMAL(15,2)   NOT NULL DEFAULT 0,
                `approved_fee`      DECIMAL(15,2)   DEFAULT NULL,
                `prev_approved_fee` DECIMAL(15,2)   DEFAULT NULL,
                `status`       VARCHAR(20)     NOT NULL DEFAULT 'pending',
                `admin_note`   TEXT            DEFAULT NULL,
                `ta_note`      TEXT            DEFAULT NULL,
                `requested_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `reviewed_at`  DATETIME        DEFAULT NULL,
                `paid_at`      DATETIME        DEFAULT NULL,
                `diff_status`       VARCHAR(20)     DEFAULT NULL COMMENT 'مسیر مستقل تفاوت: pending|ta_approved|ta_rejected|approved|paid',
                `diff_amount`       DECIMAL(15,2)   DEFAULT NULL COMMENT 'مبلغ تفاوت = total_fee - prev_approved_fee',
                `diff_approved_fee` DECIMAL(15,2)   DEFAULT NULL COMMENT 'مبلغ تایید‌شده تفاوت توسط ادمین',
                `diff_ta_note`      TEXT            DEFAULT NULL,
                `diff_admin_note`   TEXT            DEFAULT NULL,
                `diff_reviewed_at`  DATETIME        DEFAULT NULL,
                `diff_paid_at`      DATETIME        DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_ta_term` (`ta_id`, `term_id`),
                KEY `idx_ta`     (`ta_id`),
                KEY `idx_term`   (`term_id`),
                KEY `idx_status` (`status`)
            ) {$charset}",

            // ── لاگ تاریخچه درخواست‌های حق‌الزحمه ──────────────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_payroll_request_logs` (
                `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `request_id`   BIGINT UNSIGNED NOT NULL,
                `ta_id`        BIGINT UNSIGNED NOT NULL,
                `term_id`      BIGINT UNSIGNED NOT NULL,
                `action`       VARCHAR(30)     NOT NULL,
                `actor_type`   VARCHAR(10)     NOT NULL DEFAULT 'admin',
                `actor_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `total_fee`    DECIMAL(15,2)   NOT NULL DEFAULT 0,
                `note`         TEXT            DEFAULT NULL,
                `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_request` (`request_id`),
                KEY `idx_ta`      (`ta_id`),
                KEY `idx_term`    (`term_id`)
            ) {$charset}",


            // ── تعدیلات حق‌الزحمه (مبلغ اضافی / کسورات) ────────────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_payroll_adjustments` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `ta_id`       BIGINT UNSIGNED NOT NULL,
                `term_id`     BIGINT UNSIGNED NOT NULL,
                `type`        ENUM('extra','deduction') NOT NULL DEFAULT 'extra',
                `amount`      DECIMAL(15,2) NOT NULL DEFAULT 0,
                `description` TEXT DEFAULT NULL,
                `created_by`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_ta_term` (`ta_id`, `term_id`),
                KEY `idx_term`    (`term_id`),
                KEY `idx_ta`      (`ta_id`)
            ) {$charset}",

            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_blacklist` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `ta_id`      BIGINT UNSIGNED NOT NULL,
                `student_id` BIGINT UNSIGNED NOT NULL,
                `reason`     TEXT DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_ta_student` (`ta_id`, `student_id`),
                KEY `idx_ta` (`ta_id`)
            ) {$charset}",

            // ── لاگ ورود به گروه (هنرجو / استادیار / ادمین) ─────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_group_join_logs` (
                `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `assignment_id` BIGINT UNSIGNED NOT NULL,
                `student_id`    BIGINT UNSIGNED NOT NULL,
                `product_id`    BIGINT UNSIGNED NOT NULL,
                `actor_type`    ENUM('student','ta','admin') NOT NULL,
                `actor_id`      BIGINT UNSIGNED NOT NULL,
                `messenger`     VARCHAR(30) DEFAULT NULL,
                `group_link`    VARCHAR(500) DEFAULT NULL,
                `note`          TEXT DEFAULT NULL,
                `logged_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_assignment` (`assignment_id`),
                KEY `idx_student`    (`student_id`),
                KEY `idx_actor`      (`actor_type`, `actor_id`),
                KEY `idx_logged_at`  (`logged_at`)
            ) {$charset}",

            // ── لاگ واقعی ورود به سایت (هنرجو / استادیار / ادمین) ────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_login_logs` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`    BIGINT UNSIGNED NOT NULL,
                `actor_type` ENUM('student','ta','admin') NOT NULL,
                `logged_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user`      (`user_id`),
                KEY `idx_actor`     (`actor_type`, `user_id`),
                KEY `idx_logged_at` (`logged_at`)
            ) {$charset}",

            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_story_critique_forms` (
                `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `ta_id`           BIGINT UNSIGNED NOT NULL,
                `user_id`         BIGINT UNSIGNED NOT NULL,
                `full_name`       VARCHAR(191) NOT NULL DEFAULT '',
                `phone`           VARCHAR(30)  NOT NULL DEFAULT '',
                `specialties`     TEXT         NOT NULL DEFAULT '',
                `other_specialty` TEXT         DEFAULT NULL,
                `hours_per_day`   VARCHAR(100) NOT NULL DEFAULT '',
                `capacity`        INT UNSIGNED DEFAULT NULL,
                `base_price`      DECIMAL(15,2) DEFAULT NULL,
                `base_word_count` INT UNSIGNED DEFAULT NULL,
                `increment_words` INT UNSIGNED DEFAULT NULL,
                `increment_percent` DECIMAL(6,2) DEFAULT NULL,
                `resume`          TEXT         DEFAULT NULL,
                `group_link`      VARCHAR(500) DEFAULT NULL,
                `role_title`      VARCHAR(191) DEFAULT NULL,
                `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_ta` (`ta_id`)
            ) {$charset}",

            // ── تخصص‌های نقد آثار ────────────────────────────────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_specialties` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`        VARCHAR(191) NOT NULL,
                `image_id`    BIGINT UNSIGNED DEFAULT NULL,
                `description` TEXT DEFAULT NULL,
                `sort_order`  INT UNSIGNED NOT NULL DEFAULT 0,
                `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `name` (`name`)
            ) {$charset}",

            // ── تاریخچهٔ تغییرات ظرفیت/قیمت پایه نقد آثار ───────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_story_critique_field_logs` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `form_id`     BIGINT UNSIGNED NOT NULL,
                `ta_id`       BIGINT UNSIGNED NOT NULL,
                `field`       VARCHAR(20) NOT NULL,
                `old_value`   VARCHAR(50) DEFAULT NULL,
                `new_value`   VARCHAR(50) DEFAULT NULL,
                `actor_type`  ENUM('admin','ta') NOT NULL DEFAULT 'admin',
                `actor_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_form` (`form_id`),
                KEY `idx_ta`   (`ta_id`)
            ) {$charset}",

            // ── سفارش‌های نقد آثار (هر ردیف = یک آیتم سفارش برای یک استادیار) ──
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_critique_orders` (
                `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `wc_order_id`       BIGINT UNSIGNED NOT NULL,
                `wc_order_item_id`  BIGINT UNSIGNED DEFAULT NULL,
                `ta_id`             BIGINT UNSIGNED NOT NULL,
                `student_id`        BIGINT UNSIGNED NOT NULL,
                `specialty`         VARCHAR(191) DEFAULT NULL,
                `file_id`           BIGINT UNSIGNED DEFAULT NULL,
                `word_count`        INT UNSIGNED DEFAULT NULL,
                `price`             DECIMAL(15,2) DEFAULT NULL,
                `status`            ENUM('pending','in_progress','delivered','ta_completed','completed','cancelled') NOT NULL DEFAULT 'pending',
                `result_file_id`    BIGINT UNSIGNED DEFAULT NULL,
                `note_to_admin`     TEXT DEFAULT NULL,
                `note_to_student`   TEXT DEFAULT NULL,
                `sms_sent`          TINYINT(1) NOT NULL DEFAULT 0,
                `sla_reminder_sent` TINYINT(1) NOT NULL DEFAULT 0,
                `delivered_at`      DATETIME DEFAULT NULL,
                `ta_completed_at`   DATETIME DEFAULT NULL,
                `completed_at`      DATETIME DEFAULT NULL,
                `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_order`   (`wc_order_id`),
                KEY `idx_ta`      (`ta_id`),
                KEY `idx_student` (`student_id`),
                KEY `idx_status`  (`status`)
            ) {$charset}",

            // ── سؤال‌های نظرسنجی ارزیابی نقد آثار ───────────────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_eval_questions` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `question`    TEXT NOT NULL,
                `type`        ENUM('rating','choice','text') NOT NULL DEFAULT 'rating',
                `options`     TEXT DEFAULT NULL,
                `sort_order`  INT UNSIGNED NOT NULL DEFAULT 0,
                `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) {$charset}",

            // ── پاسخ‌های نظرسنجی ─────────────────────────────────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_eval_responses` (
                `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `critique_order_id`  BIGINT UNSIGNED NOT NULL,
                `question_id`        BIGINT UNSIGNED NOT NULL,
                `student_id`         BIGINT UNSIGNED NOT NULL,
                `answer`             TEXT DEFAULT NULL,
                `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_corder` (`critique_order_id`),
                KEY `idx_q`      (`question_id`)
            ) {$charset}",

            // ── گفتگوی (چت) مرتبط با هر سفارش نقد اثر — سه نخ جدا: هنرجو↔استادیار،
            // هنرجو↔ادمین، استادیار↔ادمین (thread) ───────────────────────────
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_critique_messages` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id`    BIGINT UNSIGNED NOT NULL,
                `thread`      ENUM('ta_student','admin_student','admin_ta') NOT NULL,
                `sender_id`   BIGINT UNSIGNED NOT NULL,
                `sender_role` ENUM('student','ta','admin') NOT NULL,
                `message`     TEXT DEFAULT NULL,
                `file_id`     BIGINT UNSIGNED DEFAULT NULL,
                `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_order_thread` (`order_id`,`thread`)
            ) {$charset}",
        ];

        // اجرای هر query به صورت مستقیم
        foreach ( $tables as $sql ) {
            $wpdb->query( $sql ); // phpcs:ignore
        }

        
        // ── Migration 2.9.6 ──────────────────────────────────────────────
        // ۱) قیمت‌گذاری مستقل هر تخصص برای هر استادیار (جدول جدید، اختیاری —
        //    اگر ردیفی برای یک تخصص نباشد، calc_price() به قیمت پایه‌ی خودِ
        //    استادیار fallback می‌کند)
        // ۲) دو جدول سؤال/پاسخِ «ارزیابی هنرجو توسط استاد» (جدا از جدول قبلیِ
        //    eval_questions/eval_responses که «ارزیابی استاد توسط هنرجو» است)
        // ۳) ستون‌های جدید روی ta_critique_orders: ta_completed_at (لحظه‌ای که
        //    استاد کار را تکمیل و ارزیابی هنرجو را ثبت می‌کند) و مقدار جدید
        //    'ta_completed' برای ستون status (بین delivered و completed)
        // ─────────────────────────────────────────────────────────────────
        {
            $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_specialty_pricing` (
                `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `form_id`           BIGINT UNSIGNED NOT NULL,
                `specialty`         VARCHAR(191) NOT NULL,
                `base_price`        DECIMAL(15,2) DEFAULT NULL,
                `base_word_count`   INT UNSIGNED DEFAULT NULL,
                `increment_words`   INT UNSIGNED DEFAULT NULL,
                `increment_percent` DECIMAL(6,2) DEFAULT NULL,
                `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_form_spec` (`form_id`,`specialty`),
                KEY `idx_form` (`form_id`)
            ) {$charset}" );

            $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_student_eval_questions` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `question`    TEXT NOT NULL,
                `type`        ENUM('rating','choice','text') NOT NULL DEFAULT 'rating',
                `options`     TEXT DEFAULT NULL,
                `sort_order`  INT UNSIGNED NOT NULL DEFAULT 0,
                `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) {$charset}" );

            $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_student_eval_responses` (
                `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `critique_order_id`  BIGINT UNSIGNED NOT NULL,
                `question_id`        BIGINT UNSIGNED NOT NULL,
                `ta_id`              BIGINT UNSIGNED NOT NULL,
                `answer`             TEXT DEFAULT NULL,
                `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_corder` (`critique_order_id`),
                KEY `idx_q`      (`question_id`)
            ) {$charset}" );

            $co_tbl = self::table( 'critique_orders' );
            if ( $wpdb->get_var( "SHOW TABLES LIKE '{$co_tbl}'" ) ) {
                $co_cols = $wpdb->get_col( "DESCRIBE {$co_tbl}", 0 );
                if ( ! in_array( 'ta_completed_at', $co_cols, true ) ) {
                    $wpdb->query( "ALTER TABLE {$co_tbl} ADD COLUMN ta_completed_at DATETIME DEFAULT NULL AFTER delivered_at" );
                }
                // افزودن مقدار 'ta_completed' به ENUM وضعیت (اگر قبلاً اضافه نشده)
                $status_col = $wpdb->get_row( "SHOW COLUMNS FROM {$co_tbl} WHERE Field = 'status'" );
                if ( $status_col && strpos( (string) $status_col->Type, "'ta_completed'" ) === false ) {
                    $wpdb->query( "ALTER TABLE {$co_tbl} MODIFY COLUMN status ENUM('pending','in_progress','delivered','ta_completed','completed','cancelled') NOT NULL DEFAULT 'pending'" );
                }
                if ( ! in_array( 'sla_pending_last_sent_at', $co_cols, true ) ) {
                    $wpdb->query( "ALTER TABLE {$co_tbl} ADD COLUMN sla_pending_last_sent_at DATETIME DEFAULT NULL AFTER sla_pending_eligible_at" );
                }
            }

            // تاریخچه کامل پیامک‌های نقد آثار — شامل موفق، ناموفق و پیام‌های غیرفعال.
            $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}ta_sms_logs` (
                `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `critique_order_id`   BIGINT UNSIGNED DEFAULT NULL,
                `wc_order_id`         BIGINT UNSIGNED DEFAULT NULL,
                `notification_type`   VARCHAR(60) NOT NULL,
                `recipient_role`      VARCHAR(30) DEFAULT NULL,
                `recipient_name`      VARCHAR(191) DEFAULT NULL,
                `phone`               VARCHAR(40) DEFAULT NULL,
                `pattern_id`          VARCHAR(100) DEFAULT NULL,
                `status`              VARCHAR(20) NOT NULL DEFAULT 'failed',
                `message`             TEXT DEFAULT NULL,
                `provider_response`   LONGTEXT DEFAULT NULL,
                `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_order` (`critique_order_id`),
                KEY `idx_type` (`notification_type`),
                KEY `idx_status` (`status`),
                KEY `idx_created` (`created_at`)
            ) {$charset}" );
        }

        // admin messages table
        $current_version = get_option( 'ta_system_db_version', '0' );
        if ( version_compare( $current_version, '2.2.0', '<' ) ) {
            $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ta_admin_messages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                student_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                assignment_id BIGINT UNSIGNED DEFAULT 0,
                message TEXT NULL,
                is_read TINYINT(1) DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) " . $wpdb->get_charset_collate() );
        }

        // افزودن ستون pass_eligible برای نصب‌های قبلی (سازگار با همه نسخه‌های MySQL/MariaDB)
        $prereq_table = $wpdb->prefix . 'ta_product_prerequisites';
        $col_exists   = $wpdb->get_var( "SHOW COLUMNS FROM `{$prereq_table}` LIKE 'pass_eligible'" );
        if ( ! $col_exists ) {
            $wpdb->query( "ALTER TABLE `{$prereq_table}` ADD COLUMN `pass_eligible` TINYINT(1) NOT NULL DEFAULT 1" );
        }

        // [BUGFIX] update_option حذف شد — این‌جا زودتر از موقع اجرا می‌شد و باعث می‌شد
        // migrate() که بلافاصله بعد از install() صدا زده می‌شود، نسخهٔ دیتابیس را از
        // قبل «آپدیت‌شده» ببیند (چون از get_option می‌خواند، نه از پارامتر ورودی) و
        // در نتیجه هیچ‌کدام از ALTER TABLE های نسخه‌دار (از جمله ستون‌های
        // base_word_count / increment_words / increment_percent) هرگز اجرا نمی‌شدند.
        // آپدیت نسخهٔ دیتابیس فقط باید در پایان migrate() انجام شود.
    }

    // ── Helper: اجرای query با کش ─────────────────────────────────────────
    public static function get_row( string $sql, array $args = [] ): ?object {
        global $wpdb;
        if ( $args ) {
            $sql = $wpdb->prepare( $sql, $args );
        }
        return $wpdb->get_row( $sql ) ?: null;
    }

    public static function get_results( string $sql, array $args = [] ): array {
        global $wpdb;
        if ( $args ) {
            $sql = $wpdb->prepare( $sql, $args );
        }
        return $wpdb->get_results( $sql ) ?: [];
    }

    public static function insert( string $table, array $data, array $format = [] ): int|false {
        global $wpdb;
        $result = $wpdb->insert( $wpdb->prefix . $table, $data, $format ?: null );
        return $result ? $wpdb->insert_id : false;
    }

    public static function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int|false {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . $table,
            $data,
            $where,
            $format ?: null,
            $where_format ?: null
        );
    }

    public static function delete( string $table, array $where, array $where_format = [] ): int|false {
        global $wpdb;
        return $wpdb->delete( $wpdb->prefix . $table, $where, $where_format ?: null );
    }

    /**
     * دریافت نام جدول با prefix
     */
    public static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . 'ta_' . $name;
    }
}
