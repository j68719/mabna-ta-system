<?php
defined( 'ABSPATH' ) || exit;

class TA_Admin_Menu {

   
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menus' ] );
        add_action( 'admin_notices', [ $this, 'notice_tables_missing' ] );
        add_action( 'admin_init', [ $this, 'handle_manual_install' ] );
        add_action( 'admin_init', [ $this, 'handle_export_assignments' ] );
        add_action( 'admin_init', [ $this, 'handle_export_messages' ] );
        add_action( 'admin_init', [ $this, 'handle_export_customers' ] );
        add_action( 'admin_init', [ $this, 'handle_export_unassigned' ] );
        add_action( 'admin_init', [ $this, 'handle_export_assistants' ] );
        add_action( 'admin_init', [ $this, 'handle_export_summary' ] );
        add_action( 'admin_init', [ $this, 'handle_export_assistant_report' ] );
        add_action( 'admin_init', [ $this, 'handle_export_grades' ] );
        add_action( 'admin_init', [ $this, 'handle_export_student_path' ] );
        add_action( 'admin_init', [ $this, 'handle_export_sms_logs' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_shared_admin_style' ] );
    }

    /**
     * [UI-UNIFY] بارگذاری متمرکز CSS مشترک روی همهٔ صفحات این افزونه در ادمین
     * (قبلاً فقط صفحهٔ «ظرفیت‌ها» این فایل را لود می‌کرد و بقیهٔ صفحات هرکدام
     * استایل جداگانهٔ خودشان را داشتند). صفحات می‌توانند تدریجاً کلاس‌های
     * ta-adm-* را جایگزین استایل‌های inline خودشان کنند.
     */
    public function enqueue_shared_admin_style(): void {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        if ( 0 !== strpos( $page, 'ta-' ) ) return;

        wp_enqueue_style( 'ta-system', TA_SYSTEM_URL . 'assets/css/ta-system.css', [], ta_system_asset_ver( 'assets/css/ta-system.css' ) );
        wp_enqueue_style( 'ta-admin',  TA_SYSTEM_URL . 'assets/css/ta-admin.css', [ 'ta-system' ], ta_system_asset_ver( 'assets/css/ta-admin.css' ) );
    }

  

    /**
     * اجرای نصب دستی از طریق دکمه در پیشخوان
     */
    public function handle_manual_install(): void {
        if (
            isset( $_GET['ta_install_tables'] ) &&
            current_user_can( 'manage_options' ) &&
            wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'ta_install_tables' )
        ) {
            TA_Database::install();
            wp_redirect( admin_url( 'admin.php?page=ta-system&ta_installed=1' ) );
            exit;
        }
    }

    /**
     * خروجی اکسل تخصیص‌ها — باید قبل از ارسال هر HTML اجرا شود
     */
    public function handle_export_assignments(): void {
        if (
            isset( $_GET['page'] )      && $_GET['page']   === 'ta-assignments' &&
            isset( $_GET['action'] )    && $_GET['action'] === 'export_xlsx' &&
            isset( $_GET['_wpnonce'] )  && wp_verify_nonce( $_GET['_wpnonce'], 'ta_export_assignments_xlsx' ) &&
            current_user_can( 'manage_options' )
        ) {
            require_once TA_SYSTEM_PATH . 'admin/class-assignment-manager.php';
            ( new TA_Admin_Assignments() )->export_xlsx();
            exit;
        }
    }

    public function handle_export_messages(): void {
        if (
            isset( $_GET['page'] )     && $_GET['page']   === 'ta-admin-messages' &&
            isset( $_GET['action'] )   && $_GET['action'] === 'export_xlsx' &&
            isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'ta_messages_export' ) &&
            current_user_can( 'manage_options' )
        ) {
            require_once TA_SYSTEM_PATH . 'admin/class-admin-messages.php';
            ( new TA_Admin_Messages() )->export_xlsx();
            exit;
        }
    }

    public function handle_export_customers(): void {
        if (
            isset( $_GET['page'] )     && $_GET['page']   === 'ta-customers' &&
            isset( $_GET['action'] )   && $_GET['action'] === 'export_xlsx' &&
            isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'ta_customers_export' ) &&
            current_user_can( 'manage_options' )
        ) {
            ( new TA_Admin_Customers() )->export_xlsx();
            exit;
        }
    }

    public function handle_export_unassigned(): void {
        if (
            isset( $_GET['page'] )     && $_GET['page']   === 'ta-unassigned-orders' &&
            isset( $_GET['action'] )   && $_GET['action'] === 'export_xlsx' &&
            isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'ta_unassigned_export' ) &&
            current_user_can( 'manage_options' )
        ) {
            require_once TA_SYSTEM_PATH . 'admin/class-unassigned-orders.php';
            ( new TA_Admin_Unassigned_Orders() )->export_xlsx();
            exit;
        }
    }

    public function handle_export_assistants(): void {
        if (
            isset( $_GET['page'] )     && $_GET['page']   === 'ta-assistants' &&
            isset( $_GET['action'] )   && $_GET['action'] === 'export_xlsx' &&
            isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'ta_assistants_export' ) &&
            current_user_can( 'manage_options' )
        ) {
            ( new TA_Admin_TA_Manager() )->export_xlsx();
            exit;
        }
    }

    public function handle_export_summary(): void {
        if (
            isset( $_GET['page'] )     && ( $_GET['page'] === 'ta-summary-report' || $_GET['page'] === 'ta-capacity-summary' ) &&
            isset( $_GET['action'] )   && $_GET['action'] === 'export_xlsx' &&
            isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'ta_summary_export' ) &&
            current_user_can( 'manage_options' )
        ) {
            require_once TA_SYSTEM_PATH . 'admin/class-summary-report.php';
            ( new TA_Summary_Report() )->export_xlsx();
            exit;
        }
    }

    public function handle_export_assistant_report(): void {
        if (
            isset( $_GET['page'] )     && $_GET['page']   === 'ta-assistant-report' &&
            isset( $_GET['action'] )   && $_GET['action'] === 'export_xlsx' &&
            isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'ta_assistant_report_export' ) &&
            current_user_can( 'manage_options' )
        ) {
            require_once TA_SYSTEM_PATH . 'admin/class-assistant-report.php';
            ( new TA_Assistant_Report() )->export_xlsx();
            exit;
        }
    }

    public function handle_export_grades(): void {
        if (
            isset( $_GET['page'] ) && $_GET['page'] === 'ta-grades' &&
            isset( $_GET['action'] ) && $_GET['action'] === 'export_xlsx' &&
            isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'ta_export_grades_xlsx' ) &&
            current_user_can( 'manage_options' )
        ) {
            require_once TA_SYSTEM_PATH . 'admin/class-grades-manager.php';
            ( new TA_Grades_Manager() )->export_xlsx();
            exit;
        }
    }

    public function handle_export_student_path(): void {
        if (
            isset( $_GET['page'] ) && $_GET['page'] === 'ta-student-path-report' &&
            isset( $_GET['action'] ) && $_GET['action'] === 'export_xlsx' &&
            isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'ta_student_path_export' ) &&
            current_user_can( 'manage_options' )
        ) {
            require_once TA_SYSTEM_PATH . 'admin/class-student-path-report.php';
            ( new TA_Student_Path_Report() )->export_xlsx();
            exit;
        }
    }

    /**
     * نمایش notice اگر جداول وجود ندارند
     */
    public function notice_tables_missing(): void {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'ta-' ) === false ) {
            return;
        }
        if ( TA_Database::tables_exist() ) {
            if ( isset( $_GET['ta_installed'] ) ) {
                echo '<div class="notice notice-success is-dismissible"><p>✅ جداول پایگاه داده با موفقیت ایجاد شدند.</p></div>';
            }
            return;
        }
        $install_url = wp_nonce_url(
            admin_url( 'admin.php?page=ta-system&ta_install_tables=1' ),
            'ta_install_tables'
        );
        echo '<div class="notice notice-error">';
        echo '<p><strong>سیستم استادیار:</strong> جداول پایگاه داده وجود ندارند.</p>';
        echo '<p><a href="' . esc_url( $install_url ) . '" class="button button-primary">▶ ایجاد جداول الان</a></p>';
        echo '</div>';
    }
    
    
    public function page_customers(): void {
        // [FIX-PATH] require_once زائد حذف شد — autoloader این کلاس را بارگذاری می‌کند
        ( new TA_Admin_Customers() )->render();
    }

    public function register_menus(): void {
        // [FIX-DOUBLE-CAPACITY] TA_Capacity_Manager اینجا یک‌بار ساخته می‌شود
        // (نسخه ta-system.php حذف شده تا از ثبت دوباره هوک‌های AJAX جلوگیری شود)
        require_once TA_SYSTEM_PATH . 'admin/class-capacity-manager.php';
        $this->capacity_manager = new TA_Capacity_Manager();

        add_menu_page(
            'سیستم استادیار',
            'سیستم استادیار',
            TA_Critique_Admin_Access::CAPABILITY,
            'ta-system',
            [ $this, 'page_dashboard' ],
            'dashicons-groups',
            56
        );

        add_submenu_page(
            'ta-system',
            'مدیریت استادیارها',
            'استادیارها',
            'manage_options',
            'ta-assistants',
            [ $this, 'page_assistants' ]
        );

        add_submenu_page(
            'ta-system',
            'ترم‌ها',
            'ترم‌ها',
            'manage_options',
            'ta-terms',
            [ $this, 'page_terms' ]
        );

        add_submenu_page(
            'ta-system',
            'تخصیص‌ها',
            'تخصیص‌ها',
            'manage_options',
            'ta-assignments',
            [ $this, 'page_assignments' ]
        );
        
        // مشتریان دوره‌ها
add_submenu_page(
    'ta-system',
    'مشتریان دوره‌ها',
    'مشتریان دوره‌ها',
    'manage_options',
    'ta-customers',
    [ $this, 'page_customers' ]
);

        add_submenu_page(
            'ta-system',
            'نمره‌ها',
            'نمره‌ها',
            'manage_options',
            'ta-grades',
            [ $this, 'page_grades' ]
        );

        add_submenu_page(
            'ta-system',
            'اصلاح نمره‌های پیشرفته',
            'اصلاح نمره‌های پیشرفته',
            'manage_options',
            'ta-advanced-grade-repair',
            [ $this, 'page_advanced_grade_repair' ]
        );

        add_submenu_page(
            'ta-system',
            'گزارش مسیر هنرجویان',
            'گزارش مسیر هنرجویان',
            'manage_options',
            'ta-student-path-report',
            [ $this, 'page_student_path_report' ]
        );

       // زیرمنو: بررسی ظرفیت‌ها
        add_submenu_page(
            'ta-system',
            'بررسی ظرفیت‌ها',
            'ظرفیت‌ها',
            'manage_options', // به جای $cap، این مقدار را مستقیم وارد کنید
            'ta-capacities',
            [ $this, 'render_capacities_page' ]
        );

        add_submenu_page(
            'ta-system',
            'حسابداری حق‌الزحمه',
            '💰 حق‌الزحمه',
            'manage_options',
            'ta-payroll',
            [ $this, 'page_payroll' ]
        );

        add_submenu_page(
            'ta-system',
            'گزارش نقد آثار',
            '📝 نقد آثار',
            TA_Critique_Admin_Access::CAPABILITY,
            'ta-story-critique',
            [ $this, 'page_story_critique' ]
        );

        add_submenu_page(
            'ta-system',
            'ایمپورت داده',
            '📥 ایمپورت داده',
            'manage_options',
            'ta-import',
            [ $this, 'page_import' ]
        );
        
                // اضافه کردن زیرمنوی تنظیمات (حدود خط 113)
        add_submenu_page(
            'ta-system',
            'تنظیمات',
            'تنظیمات',
            'manage_options',
            'ta-settings',
            [ $this, 'page_settings' ]
        );

        // [FIX-UNASSIGNED] ثبت صفحه سفارشات بدون استادیار که قبلاً در منو نبود
        add_submenu_page(
            'ta-system',
            'سفارشات بدون استادیار',
            '⚠️ بدون استادیار',
            'manage_options',
            'ta-unassigned-orders',
            [ $this, 'page_unassigned_orders' ]
        );

        // گزارش هنرجویان بالقوه برای سنجش فرصت ثبت‌نام هر ترم
        add_submenu_page(
            'ta-system',
            'گزارش هنرجویان بالقوه',
            '🎯 هنرجویان بالقوه',
            'manage_options',
            'ta-potential-students-report',
            [ $this, 'page_potential_students_report' ]
        );

        // گزارش خلاصه بر حسب ترم
        add_submenu_page(
            'ta-system',
            'گزارش خلاصه',
            '📋 گزارش خلاصه',
            'manage_options',
            'ta-summary-report',
            [ $this, 'page_summary_report' ]
        );

        // گزارش بر مبنای استادیار
        add_submenu_page(
            'ta-system',
            'گزارش استادیار',
            '📊 گزارش استادیار',
            'manage_options',
            'ta-assistant-report',
            [ $this, 'page_assistant_report' ]
        );

        add_submenu_page(
            'ta-system',
            'گزارش جامع همکاری و ظرفیت',
            '📈 گزارش همکاری و ظرفیت',
            'manage_options',
            'ta-admin-cooperation-report',
            [ $this, 'page_admin_cooperation_report' ]
        );

        // صفحه پیام‌های ادمین — درخواست‌های تخصیص در انتظار
        $pending_count = $this->get_pending_admin_count();
        $messages_label = '📬 پیام‌ها';
        if ( $pending_count > 0 ) {
            $messages_label .= ' <span class="ta-adm-badge ta-adm-badge-count">' . $pending_count . '</span>';
        }
        add_submenu_page(
            'ta-system',
            'پیام‌های ادمین',
            $messages_label,
            'manage_options',
            'ta-admin-messages',
            [ $this, 'page_admin_messages' ]
        );

        add_submenu_page(
            'ta-system',
            'گزارش پیامک‌های ارسالی',
            '📨 گزارش پیامک‌ها',
            'manage_options',
            'ta-sms-logs',
            [ $this, 'page_sms_logs' ]
        );

    }

    public function page_payroll(): void {
        ( new TA_Payroll() )->render();
    }

    public function page_import(): void {
        ( new TA_Admin_Import() )->render();
    }

    public function page_dashboard(): void {
        echo '<div class="wrap"><h1>سیستم تخصیص استادیار</h1>';
        echo '<p>به پنل مدیریت سیستم استادیار خوش آمدید.</p></div>';
    }

    public function page_assistants(): void {
        ( new TA_Admin_TA_Manager() )->render();
    }

    public function page_terms(): void {
        $this->handle_term_actions();
        $this->render_terms_page();
    }

    public function page_assignments(): void {
        ( new TA_Admin_Assignments() )->render();
    }

    public function page_grades(): void {
        require_once TA_SYSTEM_PATH . 'admin/class-grades-manager.php';
        ( new TA_Grades_Manager() )->render();
    }

    public function page_advanced_grade_repair(): void {
        require_once TA_SYSTEM_PATH . 'admin/class-advanced-grade-repair.php';
        ( new TA_Advanced_Grade_Repair() )->render();
    }

    public function page_student_path_report(): void {
        require_once TA_SYSTEM_PATH . 'admin/class-student-path-report.php';
        ( new TA_Student_Path_Report() )->render();
    }

    // ── Terms Management ─────────────────────────────────────────────────────
    private function handle_term_actions(): void {
        if ( ! isset( $_POST['ta_term_nonce'] ) || ! wp_verify_nonce( $_POST['ta_term_nonce'], 'ta_save_term' ) ) {
            return;
        }

        $action = sanitize_text_field( $_POST['ta_action'] ?? '' );

        if ( $action === 'set_active_term' ) {
            $active_id = absint( $_POST['active_term_id'] ?? 0 );
            if ( $active_id ) update_option( 'ta_active_term_id', $active_id );
            echo '<div class="notice notice-success"><p>ترم جاری به‌روزرسانی شد.</p></div>';
        }

        if ( $action === 'set_term_cooperation_requirement' ) {
            $term_id = absint( $_POST['term_id'] ?? 0 );
            if ( $term_id ) update_option( 'ta_term_require_cooperation_' . $term_id, ! empty( $_POST['require_cooperation'] ) ? 1 : 0, false );
            echo '<div class="notice notice-success"><p>تنظیم الزام نوع همکاری برای این ترم ذخیره شد.</p></div>';
        }

        if ( $action === 'set_term_capacity_permission' ) {
            $term_id = absint( $_POST['term_id'] ?? 0 );
            if ( $term_id ) update_option( 'ta_term_allow_capacity_edit_' . $term_id, ! empty( $_POST['allow_capacity_edit'] ) ? 1 : 0, false );
            echo '<div class="notice notice-success"><p>مجوز ویرایش ظرفیت استادیار برای این ترم ذخیره شد.</p></div>';
        }

        if ( $action === 'save_term' ) {
            $name  = sanitize_text_field( $_POST['term_name'] ?? '' );
            $slug  = sanitize_key( $_POST['term_slug'] ?? '' );
            $order = (int) ( $_POST['term_order'] ?? 0 );
            $id    = (int) ( $_POST['term_id'] ?? 0 );

            if ( $name && $slug ) {
                if ( $id ) {
                    TA_Database::update( 'ta_terms', [ 'name' => $name, 'slug' => $slug, 'order_index' => $order ], [ 'id' => $id ] );
                } else {
                    TA_Database::insert( 'ta_terms', [ 'name' => $name, 'slug' => $slug, 'order_index' => $order, 'created_at' => current_time( 'mysql' ) ] );
                }
                echo '<div class="notice notice-success"><p>ترم ذخیره شد.</p></div>';
            }
        }

        if ( $action === 'delete_term' ) {
            $id = (int) ( $_POST['term_id'] ?? 0 );
            TA_Database::delete( 'ta_terms', [ 'id' => $id ] );
            echo '<div class="notice notice-success"><p>ترم حذف شد.</p></div>';
        }
    }

    private function render_terms_page(): void {
        if ( ! TA_Database::tables_exist() ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>جداول پایگاه داده وجود ندارند.</p></div></div>';
            return;
        }
        $terms = TA_Database::get_results(
            "SELECT * FROM " . TA_Database::table( 'terms' ) . " ORDER BY created_at DESC, id DESC"
        );
        ?>
        <div class="wrap" dir="rtl">
            <h1>مدیریت ترم‌ها</h1>

            <h2>افزودن / ویرایش ترم</h2>
            <form method="post">
                <?php wp_nonce_field( 'ta_save_term', 'ta_term_nonce' ); ?>
                <input type="hidden" name="ta_action" value="save_term">
                <input type="hidden" name="term_id" id="edit_term_id" value="0">
                <table class="form-table">
                    <tr>
                        <th>نام ترم <span style="color:red">*</span></th>
                        <td><input type="text" name="term_name" id="edit_term_name" class="regular-text" placeholder="مثلاً: زمستان ۱۴۰۴" required></td>
                    </tr>
                    <tr>
                        <th>شناسه (Slug) <span style="color:red">*</span></th>
                        <td><input type="text" name="term_slug" id="edit_term_slug" class="regular-text" placeholder="winter_1404" required></td>
                    </tr>
                    <tr>
                        <th>ترتیب</th>
                        <td><input type="number" name="term_order" id="edit_term_order" value="0" class="small-text"></td>
                    </tr>
                </table>
                <?php submit_button( 'ذخیره ترم', 'primary', 'submit', false ); ?>
                <button type="button" onclick="resetTermForm()" class="button">انصراف</button>
            </form>

            <?php $active_term_id = (int) get_option( 'ta_active_term_id', 0 ); if ( ! $active_term_id && ! empty( $terms ) ) $active_term_id = (int) $terms[0]->id; ?>
            <div class="ta-active-term-box">
                <div><strong>ترم جاری سیستم</strong><p>ترمی که در پنل‌ها و کمپین جدید به‌صورت پیش‌فرض استفاده می‌شود.</p></div>
                <form method="post" class="ta-active-term-form"><?php wp_nonce_field( 'ta_save_term', 'ta_term_nonce' ); ?><input type="hidden" name="ta_action" value="set_active_term"><select name="active_term_id" required><?php foreach ( $terms as $term ) : ?><option value="<?= (int) $term->id ?>" <?= selected( $active_term_id, $term->id, false ) ?>><?= esc_html( $term->name ) ?></option><?php endforeach; ?></select><button class="button button-primary">ذخیره ترم جاری</button></form>
            </div>
            <style>.ta-active-term-box{display:flex;justify-content:space-between;align-items:center;gap:20px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:14px;padding:18px;margin:18px 0;max-width:980px}.ta-active-term-box p{margin:5px 0 0;color:#64748b}.ta-active-term-form{display:flex;gap:8px;align-items:center}.ta-active-term-form select{min-width:180px;padding:6px}@media(max-width:700px){.ta-active-term-box{display:block}.ta-active-term-form{margin-top:12px}}.ta-term-permission-form{display:flex;align-items:center;gap:7px}.ta-term-permission-form label{white-space:nowrap}</style>

            <h2>ترم‌های موجود</h2>
            <table class="wp-list-table widefat fixed">
                <thead>
                    <tr>
                        <th>ID</th><th>نام</th><th>Slug</th><th>ترتیب</th><th>ویرایش ظرفیت توسط استادیار</th><th>الزام تعیین نوع همکاری</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $terms as $term ) : ?>
                    <tr>
                        <td><?= $term->id ?></td>
                        <td><?= esc_html( $term->name ) ?></td>
                        <td><?= esc_html( $term->slug ) ?></td>
                        <td><?= $term->order_index ?></td>
                        <td>
                            <form method="post" class="ta-term-permission-form"><?php wp_nonce_field( 'ta_save_term', 'ta_term_nonce' ); ?><input type="hidden" name="ta_action" value="set_term_capacity_permission"><input type="hidden" name="term_id" value="<?= (int) $term->id ?>"><label><input type="checkbox" name="allow_capacity_edit" value="1" <?= checked( get_option( 'ta_term_allow_capacity_edit_' . (int) $term->id, 1 ), 1, false ) ?>> مجاز</label><button class="button button-small">ذخیره</button></form>
                        </td>
                        <td>
                            <form method="post" class="ta-term-permission-form"><?php wp_nonce_field( 'ta_save_term', 'ta_term_nonce' ); ?><input type="hidden" name="ta_action" value="set_term_cooperation_requirement"><input type="hidden" name="term_id" value="<?= (int) $term->id ?>"><label><input type="checkbox" name="require_cooperation" value="1" <?= checked( get_option( 'ta_term_require_cooperation_' . (int) $term->id, 1 ), 1, false ) ?>> الزامی</label><button class="button button-small">ذخیره</button></form>
                        </td>
                        <td>
                            <button type="button" class="button button-small"
                                onclick="editTerm(<?= $term->id ?>, '<?= esc_js( $term->name ) ?>', '<?= esc_js( $term->slug ) ?>', <?= $term->order_index ?>)">
                                ویرایش
                            </button>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field( 'ta_save_term', 'ta_term_nonce' ); ?>
                                <input type="hidden" name="ta_action" value="delete_term">
                                <input type="hidden" name="term_id" value="<?= $term->id ?>">
                                <button class="button button-small button-link-delete" onclick="return confirm('حذف شود؟')">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <script>
        function editTerm(id, name, slug, order) {
            document.getElementById('edit_term_id').value = id;
            document.getElementById('edit_term_name').value = name;
            document.getElementById('edit_term_slug').value = slug;
            document.getElementById('edit_term_order').value = order;
            window.scrollTo(0, 0);
        }
        function resetTermForm() {
            document.getElementById('edit_term_id').value = 0;
            document.getElementById('edit_term_name').value = '';
            document.getElementById('edit_term_slug').value = '';
            document.getElementById('edit_term_order').value = 0;
        }
        </script>
        <?php
    }


    // render_capacities_page دیگر نیازی به ساختن کلاس ندارد
    public function render_capacities_page(): void {
        $this->capacity_manager->render_page();
    }
   

    public function page_potential_students_report(): void {
        require_once TA_SYSTEM_PATH . 'admin/class-potential-students-report.php';
        ( new TA_Potential_Students_Report() )->render();
    }

    public function page_summary_report(): void {
        require_once TA_SYSTEM_PATH . 'admin/class-summary-report.php';
        ( new TA_Summary_Report() )->render();
    }

    public function page_assistant_report(): void {
        require_once TA_SYSTEM_PATH . 'admin/class-assistant-report.php';
        ( new TA_Assistant_Report() )->render();
    }

    public function page_admin_cooperation_report(): void {
        require_once TA_SYSTEM_PATH . 'admin/class-admin-cooperation-report.php';
        ( new TA_Admin_Cooperation_Report() )->render();
    }

    public function page_unassigned_orders(): void {
        // [FIX-UNASSIGNED] بارگذاری کلاس که قبلاً در autoloader و منو ثبت نشده بود
        $file = TA_SYSTEM_PATH . 'admin/class-unassigned-orders.php';
        if ( file_exists( $file ) ) {
            require_once $file;
            ( new TA_Admin_Unassigned_Orders() )->render();
        } else {
            echo '<div class="wrap"><div class="notice notice-error"><p>فایل class-unassigned-orders.php یافت نشد.</p></div></div>';
        }
    }

    public function page_admin_messages(): void {
        $file = TA_SYSTEM_PATH . 'admin/class-admin-messages.php';
        if ( file_exists( $file ) ) {
            require_once $file;
            ( new TA_Admin_Messages() )->render();
        } else {
            echo '<div class="wrap"><div class="notice notice-error"><p>فایل class-admin-messages.php یافت نشد.</p></div></div>';
        }
    }

    public function handle_export_sms_logs(): void {
        if (
            isset( $_GET['page'], $_GET['action'], $_GET['_wpnonce'] ) &&
            $_GET['page'] === 'ta-sms-logs' &&
            $_GET['action'] === 'export_csv' &&
            wp_verify_nonce( $_GET['_wpnonce'], 'ta_sms_logs_export' ) &&
            current_user_can( 'manage_options' )
        ) {
            ( new TA_SMS_Log_Report() )->export_csv();
        }
    }

    private function get_pending_admin_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ta_assignments WHERE status = 'pending_admin'"
        );
    }

    public function page_story_critique(): void {
        ( new TA_Story_Critique_Manager() )->render();
    }

    public function page_sms_logs(): void {
        ( new TA_SMS_Log_Report() )->render();
    }

    public function page_settings(): void {
        // [FIX-DOUBLE-SETTINGS] هوک‌ها قبلاً در ta-system.php ثبت شده‌اند
        // TA_Settings::$hooks_registered=true است پس constructor تکراری ثبت نمی‌کند
        ( new TA_Settings() )->render();
    }


}
