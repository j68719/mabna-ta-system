<?php
defined( 'ABSPATH' ) || exit;

/**
 * ایمپورت دسته‌جمعی داده‌های تاریخی
 *
 * قابلیت‌ها:
 * - آپلود Drag & Drop
 * - پیش‌نمایش ردیف‌ها پیش از ایمپورت
 * - پردازش دسته‌ای (Batch) با Progress Bar زنده
 * - لاگ رنگ‌بندی‌شده (موفق / خطا / تکراری)
 * - دانلود فایل نمونه
 * - جدول مرجع ترم‌ها و محصولات
 * - اصلاح باگ $course_type در handle_capacities
 */
class TA_Admin_Import {

    private const BATCH_SIZE = 30;

    private const COURSE_LABELS = [
        'creative'     => 'نویسندگی خلاق',
        'intro'        => 'نویسندگی مقدماتی',
        'advanced'     => 'نویسندگی پیشرفته',
        'professional' => 'نویسندگی حرفه‌ای',
        'critique'     => 'نقد اثر',
    ];

    public function __construct() {
        add_action( 'admin_post_ta_import_assignments', [ $this, 'handle_assignments' ] );
        add_action( 'admin_post_ta_import_grades',      [ $this, 'handle_grades'      ] );
        add_action( 'admin_post_ta_import_capacities',  [ $this, 'handle_capacities'  ] );
        add_action( 'admin_init',                       [ $this, 'maybe_download_sample' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // دانلود فایل نمونه
    // ─────────────────────────────────────────────────────────────────────────

    public function maybe_download_sample(): void {
        if ( ! isset( $_GET['ta_download_sample'] )
             || ! current_user_can( 'manage_options' )
             || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'ta_download_sample' ) ) return;

        $type = sanitize_key( $_GET['ta_download_sample'] );
        $map  = [
            'assignments' => TA_SYSTEM_PATH . 'assets/samples/sample_assignments.xlsx',
            'grades'      => TA_SYSTEM_PATH . 'assets/samples/sample_grades.xlsx',
            'capacities'  => TA_SYSTEM_PATH . 'assets/samples/sample_capacities.xlsx',
        ];
        $names = [
            'assignments' => 'نمونه_تخصیص‌ها.xlsx',
            'grades'      => 'نمونه_نمره‌ها.xlsx',
            'capacities'  => 'نمونه_ظرفیت‌ها.xlsx',
        ];

        if ( ! isset( $map[$type] ) || ! file_exists( $map[$type] ) ) return;

        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $names[$type] . '"' );
        header( 'Content-Length: ' . filesize( $map[$type] ) );
        header( 'Cache-Control: no-cache' );
        readfile( $map[$type] );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // رندر صفحه
    // ─────────────────────────────────────────────────────────────────────────

    public function render(): void {
        if ( ! TA_Database::tables_exist() ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>جداول پایگاه داده ایجاد نشده‌اند.</p></div></div>';
            return;
        }

        // نتایج ایمپورت از Transient
        $uid             = get_current_user_id();
        $result_data     = get_transient( "ta_import_results_{$uid}" );
        $result_type     = get_transient( "ta_import_type_{$uid}" );
        if ( $result_data ) {
            delete_transient( "ta_import_results_{$uid}" );
            delete_transient( "ta_import_type_{$uid}" );
        }

        global $wpdb;
        $terms    = TA_Database::get_results( "SELECT * FROM " . TA_Database::table('terms') . " ORDER BY order_index ASC" );
        $products = $wpdb->get_results(
            "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
             JOIN " . TA_Database::table('product_settings') . " ps ON ps.product_id = p.ID
             WHERE p.post_status = 'publish'
             ORDER BY p.post_title ASC
             LIMIT 100"
        );

        $this->render_styles();
        ?>

<div class="wrap tim-wrap" dir="rtl">

<!-- ── هدر ────────────────────────────────────────────────────── -->
<div class="tim-header">
    <div class="tim-header-title">
        <span class="tim-header-icon">📥</span>
        <div>
            <h1>ایمپورت دسته‌جمعی داده‌های تاریخی</h1>
            <p>داده‌های ترم‌های قبل را از فایل Excel یا CSV وارد کنید</p>
        </div>
    </div>
</div>

<!-- ── نتیجه ایمپورت ────────────────────────────────────────── -->
<?php if ( $result_data ) : ?>
<div class="tim-card tim-result-card">
    <div class="tim-result-header">
        <span class="tim-result-icon"><?= $result_data['errors'] === 0 ? '✅' : ( $result_data['imported'] + $result_data['updated'] > 0 ? '⚠️' : '❌' ) ?></span>
        <div>
            <div class="tim-result-title">نتیجه ایمپورت <?= esc_html($result_data['title'] ?? '') ?></div>
            <div class="tim-result-stats">
                <span class="tim-badge tim-badge-success">✓ <?= $result_data['imported'] ?> جدید</span>
                <span class="tim-badge tim-badge-info">↺ <?= $result_data['updated'] ?> به‌روز</span>
                <span class="tim-badge tim-badge-skip">⏭ <?= $result_data['skipped'] ?? 0 ?> تکراری</span>
                <?php if ($result_data['errors'] > 0) : ?>
                    <span class="tim-badge tim-badge-error">✕ <?= $result_data['errors'] ?> خطا</span>
                <?php endif; ?>
            </div>
        </div>
        <button class="tim-result-close" onclick="this.closest('.tim-result-card').style.display='none'">✕</button>
    </div>
    <?php if ( ! empty($result_data['log']) ) : ?>
    <div class="tim-log-wrap">
        <div class="tim-log-filter">
            <button class="tim-log-btn tim-log-btn-active" onclick="timFilterLog('all', this)">همه (<?= count($result_data['log']) ?>)</button>
            <button class="tim-log-btn" onclick="timFilterLog('success', this)">موفق</button>
            <button class="tim-log-btn" onclick="timFilterLog('error', this)">خطا</button>
            <button class="tim-log-btn" onclick="timFilterLog('skip', this)">تکراری</button>
        </div>
        <div class="tim-log" id="tim-log">
            <?php foreach ( $result_data['log'] as $entry ) :
                $cls = $entry['type'] ?? 'info';
                $icon = $cls === 'success' ? '✓' : ( $cls === 'error' ? '✕' : ( $cls === 'skip' ? '⏭' : 'ℹ' ) );
            ?>
                <div class="tim-log-row tim-log-<?= $cls ?>">
                    <span class="tim-log-icon"><?= $icon ?></span>
                    <span class="tim-log-msg"><?= esc_html($entry['msg']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── سه کارت ایمپورت ─────────────────────────────────────── -->
<div class="tim-cards-grid">

    <!-- تخصیص‌ها -->
    <?php $this->render_import_card(
        'assignments', '🔗', 'ایمپورت تخصیص‌ها', 'هنرجو به استادیار',
        '#6366f1',
        [
            ['ایمیل یا موبایل هنرجو', true],
            ['شناسه محصول (ID)', true],
            ['ایمیل یا موبایل استادیار', true],
            ['پیامرسان: telegram / bale / eitaa', true],
            ['جنسیت: male / female', true],
            ['لینک گروه', false],
            ['یادداشت', false],
        ]
    ); ?>

    <!-- نمره‌ها -->
    <?php $this->render_import_card(
        'grades', '📊', 'ایمپورت نمره‌ها', 'ثبت نمره هنرجویان',
        '#10b981',
        [
            ['ایمیل یا موبایل هنرجو', true],
            ['شناسه محصول (ID)', true],
            ['نمره: pass / repeat / critique', true],
            ['ایمیل یا موبایل استادیار', true],
            ['یادداشت', false],
        ]
    ); ?>

    <!-- ظرفیت‌ها -->
    <?php $this->render_import_card(
        'capacities', '📦', 'ایمپورت ظرفیت‌ها', 'ظرفیت استادیارها',
        '#f59e0b',
        [
            ['ایمیل یا موبایل استادیار', true],
            ['شناسه ترم (ID)', true],
            ['نوع دوره: creative / intro / advanced / professional / critique', true],
            ['جنسیت: male / female / both', true],
            ['پیامرسان: telegram / bale / eitaa / both', true],
            ['ظرفیت کل', true],
            ['ظرفیت استفاده‌شده', false],
        ]
    ); ?>

</div>

<!-- ── راهنمای شناسه‌ها ────────────────────────────────────── -->
<div class="tim-refs-grid">

    <div class="tim-card tim-ref-card">
        <div class="tim-card-header">
            <span class="tim-card-title">📅 شناسه ترم‌ها</span>
        </div>
        <table class="tim-ref-table">
            <thead><tr><th>ID</th><th>نام</th><th>Slug</th></tr></thead>
            <tbody>
            <?php if ( empty($terms) ) : ?>
                <tr><td colspan="3" class="tim-empty-cell">ترمی ثبت نشده</td></tr>
            <?php else : ?>
                <?php foreach ( $terms as $t ) : ?>
                    <tr>
                        <td><code class="tim-code"><?= $t->id ?></code></td>
                        <td><?= esc_html($t->name) ?></td>
                        <td class="tim-muted"><?= esc_html($t->slug) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="tim-card tim-ref-card">
        <div class="tim-card-header">
            <span class="tim-card-title">📚 محصولات دارای تنظیمات</span>
            <span class="tim-card-sub"><?= count($products) ?> محصول</span>
        </div>
        <div class="tim-ref-scroll">
            <table class="tim-ref-table">
                <thead><tr><th>ID</th><th>عنوان</th></tr></thead>
                <tbody>
                <?php if ( empty($products) ) : ?>
                    <tr><td colspan="2" class="tim-empty-cell">محصولی یافت نشد</td></tr>
                <?php else : ?>
                    <?php foreach ( $products as $p ) : ?>
                        <tr>
                            <td><code class="tim-code"><?= $p->ID ?></code></td>
                            <td><?= esc_html($p->post_title) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="tim-card tim-ref-card">
        <div class="tim-card-header">
            <span class="tim-card-title">📋 مقادیر معتبر</span>
        </div>
        <div class="tim-vals-list">
            <div class="tim-val-group">
                <div class="tim-val-title">نوع دوره</div>
                <?php foreach ( self::COURSE_LABELS as $k => $v ) : ?>
                    <div class="tim-val-row"><code class="tim-code"><?= $k ?></code><span><?= $v ?></span></div>
                <?php endforeach; ?>
            </div>
            <div class="tim-val-group">
                <div class="tim-val-title">پیامرسان</div>
                <?php foreach (['telegram'=>'تلگرام','bale'=>'بله','eitaa'=>'ایتا','both'=>'همه'] as $k=>$v) : ?>
                    <div class="tim-val-row"><code class="tim-code"><?= $k ?></code><span><?= $v ?></span></div>
                <?php endforeach; ?>
            </div>
            <div class="tim-val-group">
                <div class="tim-val-title">جنسیت</div>
                <?php foreach (['male'=>'آقایان','female'=>'خانم‌ها','both'=>'هر دو'] as $k=>$v) : ?>
                    <div class="tim-val-row"><code class="tim-code"><?= $k ?></code><span><?= $v ?></span></div>
                <?php endforeach; ?>
            </div>
            <div class="tim-val-group">
                <div class="tim-val-title">نمره</div>
                <?php foreach (['pass'=>'قبولی','repeat'=>'تکرار','critique'=>'نقد اثر'] as $k=>$v) : ?>
                    <div class="tim-val-row"><code class="tim-code"><?= $k ?></code><span><?= $v ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>

<?php $this->render_scripts(); ?>
</div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // کارت ایمپورت
    // ─────────────────────────────────────────────────────────────────────────

    private function render_import_card( string $type, string $icon, string $title, string $sub, string $color, array $cols ): void {
        $dl = wp_nonce_url( admin_url("admin.php?page=ta-import&ta_download_sample={$type}"), 'ta_download_sample' );
        ?>
        <div class="tim-card tim-import-card" style="--card-color:<?= $color ?>">
            <div class="tim-card-top">
                <div class="tim-card-icon"><?= $icon ?></div>
                <div>
                    <div class="tim-card-title"><?= $title ?></div>
                    <div class="tim-card-sub"><?= $sub ?></div>
                </div>
                <a href="<?= esc_url($dl) ?>" class="tim-sample-btn" title="دانلود فایل نمونه">⬇ نمونه</a>
            </div>

            <!-- ستون‌های مورد نیاز -->
            <div class="tim-cols-list">
                <?php foreach ( $cols as $i => [$label, $required] ) : ?>
                    <div class="tim-col-item">
                        <span class="tim-col-num"><?= $i+1 ?></span>
                        <span class="tim-col-label"><?= $label ?></span>
                        <?php if ($required) : ?>
                            <span class="tim-col-req">*</span>
                        <?php else : ?>
                            <span class="tim-col-opt">اختیاری</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- فرم آپلود -->
            <form method="post" action="<?= admin_url('admin-post.php') ?>"
                  enctype="multipart/form-data" class="tim-upload-form"
                  id="form-<?= $type ?>">
                <?php wp_nonce_field("ta_import_{$type}"); ?>
                <input type="hidden" name="action" value="ta_import_<?= $type ?>">

                <!-- Drag & Drop Zone -->
                <div class="tim-drop-zone" id="drop-<?= $type ?>"
                     onclick="document.getElementById('file-<?= $type ?>').click()">
                    <div class="tim-drop-icon">📂</div>
                    <div class="tim-drop-text">فایل را اینجا رها کنید یا کلیک کنید</div>
                    <div class="tim-drop-hint">xlsx, xls, csv پشتیبانی می‌شود</div>
                    <div class="tim-drop-filename" id="fname-<?= $type ?>"></div>
                </div>
                <input type="file" name="import_file" id="file-<?= $type ?>"
                       accept=".xlsx,.xls,.csv" required style="display:none"
                       onchange="timFileSelected('<?= $type ?>', this)">

                <div class="tim-form-options">
                    <label class="tim-checkbox-label">
                        <input type="checkbox" name="skip_errors" value="1" checked>
                        <span>خطاها را رد کن، بقیه ایمپورت شود</span>
                    </label>
                </div>

                <button type="submit" class="tim-submit-btn" style="background:<?= $color ?>">
                    <span>📤 شروع ایمپورت</span>
                </button>
            </form>
        </div>
        <?php
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HANDLERS
    // ═══════════════════════════════════════════════════════════════════════

    public function handle_assignments(): void {
        if ( ! current_user_can('manage_options') || ! wp_verify_nonce($_POST['_wpnonce'] ?? '', 'ta_import_assignments') ) {
            wp_die('دسترسی غیرمجاز.');
        }

        $skip      = ! empty($_POST['skip_errors']);
        $processed = (int)($_POST['processed'] ?? 0);
        $log       = isset($_POST['log']) ? json_decode(stripslashes($_POST['log']), true) : [];
        $counters  = isset($_POST['counters']) ? json_decode(stripslashes($_POST['counters']), true) : ['imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>0];
        if (!is_array($log))      $log = [];
        if (!is_array($counters)) $counters = ['imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>0];
        $temp_file = sanitize_text_field($_POST['temp_file'] ?? '');

        // بار اول: پارس فایل
        if (!empty($_FILES['import_file']['tmp_name'])) {
            $rows = $this->parse_upload($_FILES['import_file']['tmp_name']);
            if (is_wp_error($rows)) { $this->finish_error($rows->get_error_message()); }
            array_shift($rows); // حذف هدر
            $temp_file = $this->save_temp($rows);
            $processed = 0; $log = []; $counters = ['imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>0];
        } else {
            $rows = $this->load_temp($temp_file);
        }

        $total = count($rows);
        $batch = array_slice($rows, $processed, self::BATCH_SIZE);

        foreach ($batch as $idx => $row) {
            $line = $processed + $idx + 2;
            $r    = $this->norm($row);

            $student = TA_User_Helper::find_by_identifier($r[0] ?? '');
            if (!$student) { $this->log_err($log, $counters, "ردیف {$line}: هنرجو «{$r[0]}» یافت نشد"); continue; }

            $product_id = (int)($r[1] ?? 0);
            $ta_user    = TA_User_Helper::find_by_identifier($r[2] ?? '');
            if (!$ta_user) { $this->log_err($log, $counters, "ردیف {$line}: استادیار «{$r[2]}» یافت نشد"); continue; }

            $ta = TA_Database::get_row("SELECT id FROM " . TA_Database::table('assistants') . " WHERE user_id = %d", [$ta_user->ID]);
            if (!$ta) { $this->log_err($log, $counters, "ردیف {$line}: استادیار در سیستم ثبت نشده «{$r[2]}»"); continue; }

            $messenger = strtolower(trim($r[3] ?? ''));
            $gender    = strtolower(trim($r[4] ?? ''));
            $group_link= esc_url_raw( TA_URL_Helper::normalize( $r[5] ?? '' ) ) ?: null;
            $notes     = sanitize_textarea_field($r[6] ?? '');

            if (!in_array($messenger, ['telegram','bale','eitaa'], true)) { $this->log_err($log, $counters, "ردیف {$line}: پیامرسان نامعتبر «{$messenger}»"); continue; }
            if (!in_array($gender,    ['male','female'], true))          { $this->log_err($log, $counters, "ردیف {$line}: جنسیت نامعتبر «{$gender}»"); continue; }

            $settings = TA_Product_Meta::get_settings($product_id);
            if (!$settings) { $this->log_err($log, $counters, "ردیف {$line}: محصول {$product_id} تنظیمات ندارد"); continue; }

            $exists = TA_Database::get_row("SELECT id FROM " . TA_Database::table('assignments') . " WHERE student_id = %d AND product_id = %d", [$student->ID, $product_id]);
            if ($exists) { $this->log_skip($log, $counters, "ردیف {$line}: تکراری — {$r[0]}"); continue; }

            TA_Database::insert('ta_assignments', [
                'student_id'     => $student->ID,
                'ta_id'          => $ta->id,
                'product_id'     => $product_id,
                'term_id'        => $settings->term_id,
                'course_type'    => $settings->course_type,
                'messenger'      => $messenger,
                'student_gender' => $gender,
                'group_link'     => $group_link,
                'notes'          => $notes,
                'status'         => 'active',
                'created_at'     => current_time('mysql'),
            ]);
            $this->log_ok($log, $counters, "ردیف {$line}: ✓ {$r[0]} → {$r[2]}");
        }

        $processed += count($batch);
        $this->maybe_continue_or_finish('ta_import_assignments', $temp_file, $rows, $processed, $total, $log, $counters, 'تخصیص‌ها');
    }

    public function handle_grades(): void {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'ta_import_grades')) {
            wp_die('دسترسی غیرمجاز.');
        }

        $skip      = !empty($_POST['skip_errors']);
        $processed = (int)($_POST['processed'] ?? 0);
        $log       = isset($_POST['log']) ? json_decode(stripslashes($_POST['log']), true) : [];
        $counters  = isset($_POST['counters']) ? json_decode(stripslashes($_POST['counters']), true) : ['imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>0];
        if (!is_array($log))      $log = [];
        if (!is_array($counters)) $counters = ['imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>0];
        $temp_file = sanitize_text_field($_POST['temp_file'] ?? '');

        if (!empty($_FILES['import_file']['tmp_name'])) {
            $rows = $this->parse_upload($_FILES['import_file']['tmp_name']);
            if (is_wp_error($rows)) { $this->finish_error($rows->get_error_message()); }
            array_shift($rows);
            $temp_file = $this->save_temp($rows);
            $processed = 0; $log = []; $counters = ['imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>0];
        } else {
            $rows = $this->load_temp($temp_file);
        }

        $total = count($rows);
        $batch = array_slice($rows, $processed, self::BATCH_SIZE);

        foreach ($batch as $idx => $row) {
            $line = $processed + $idx + 2;
            $r    = $this->norm($row);

            $student = TA_User_Helper::find_by_identifier($r[0] ?? '');
            if (!$student) { $this->log_err($log, $counters, "ردیف {$line}: هنرجو «{$r[0]}» یافت نشد"); continue; }

            $pid      = (int)($r[1] ?? 0);
            $settings = $pid ? TA_Product_Meta::get_settings($pid) : null;
            if (!$settings) { $this->log_err($log, $counters, "ردیف {$line}: محصول {$pid} تنظیمات ندارد"); continue; }

            $grade = strtolower(trim($r[2] ?? ''));
            if (!in_array($grade, ['pass','repeat','critique','pending'], true)) {
                $this->log_err($log, $counters, "ردیف {$line}: نمره «{$grade}» نامعتبر"); continue;
            }

            $ta_user = TA_User_Helper::find_by_identifier($r[3] ?? '');
            $ta      = $ta_user ? TA_Database::get_row("SELECT id FROM " . TA_Database::table('assistants') . " WHERE user_id = %d", [$ta_user->ID]) : null;
            if (!$ta) { $this->log_err($log, $counters, "ردیف {$line}: استادیار «{$r[3]}» ثبت نشده"); continue; }

            $notes    = sanitize_textarea_field($r[4] ?? '');
            $existing = TA_Database::get_row("SELECT id FROM " . TA_Database::table('grades') . " WHERE student_id = %d AND product_id = %d", [$student->ID, $pid]);
            $data     = ['grade'=>$grade,'ta_id'=>$ta->id,'notes'=>$notes,'updated_at'=>current_time('mysql')];

            if ($existing) {
                TA_Database::update('ta_grades', $data, ['id'=>$existing->id]);
                $this->log_update($log, $counters, "ردیف {$line}: ↺ به‌روز {$r[0]}");
            } else {
                TA_Database::insert('ta_grades', array_merge($data, [
                    'student_id'=>$student->ID,'product_id'=>$pid,
                    'term_id'=>$settings->term_id,'course_type'=>$settings->course_type,
                    'created_at'=>current_time('mysql'),
                ]));
                $this->log_ok($log, $counters, "ردیف {$line}: ✓ {$r[0]} — {$grade}");
            }
        }

        $processed += count($batch);
        $this->maybe_continue_or_finish('ta_import_grades', $temp_file, $rows, $processed, $total, $log, $counters, 'نمره‌ها');
    }

    public function handle_capacities(): void {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'] ?? '', 'ta_import_capacities')) {
            wp_die('دسترسی غیرمجاز.');
        }

        $skip      = !empty($_POST['skip_errors']);
        $processed = (int)($_POST['processed'] ?? 0);
        $log       = isset($_POST['log']) ? json_decode(stripslashes($_POST['log']), true) : [];
        $counters  = isset($_POST['counters']) ? json_decode(stripslashes($_POST['counters']), true) : ['imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>0];
        if (!is_array($log))      $log = [];
        if (!is_array($counters)) $counters = ['imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>0];
        $temp_file = sanitize_text_field($_POST['temp_file'] ?? '');

        if (!empty($_FILES['import_file']['tmp_name'])) {
            $rows = $this->parse_upload($_FILES['import_file']['tmp_name']);
            if (is_wp_error($rows)) { $this->finish_error($rows->get_error_message()); }
            array_shift($rows);
            $temp_file = $this->save_temp($rows);
            $processed = 0; $log = []; $counters = ['imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>0];
        } else {
            $rows = $this->load_temp($temp_file);
        }

        $total = count($rows);
        $batch = array_slice($rows, $processed, self::BATCH_SIZE);

        foreach ($batch as $idx => $row) {
            $line = $processed + $idx + 2;
            $r    = $this->norm($row);

            $ta_user = TA_User_Helper::find_by_identifier($r[0] ?? '');
            $ta      = $ta_user ? TA_Database::get_row("SELECT id FROM " . TA_Database::table('assistants') . " WHERE user_id = %d", [$ta_user->ID]) : null;
            if (!$ta) { $this->log_err($log, $counters, "ردیف {$line}: استادیار «{$r[0]}» ثبت نشده"); continue; }

            $term_id = (int)($r[1] ?? 0);
            if (!$term_id || !TA_Database::get_row("SELECT id FROM " . TA_Database::table('terms') . " WHERE id = %d", [$term_id])) {
                $this->log_err($log, $counters, "ردیف {$line}: ترم {$term_id} یافت نشد"); continue;
            }

            // [FIX] متغیر $course به جای $course_type (باگ نسخه قبل)
            $course    = strtolower(trim($r[2] ?? ''));
            $gender    = strtolower(trim($r[3] ?? 'both'));
            $messenger = strtolower(trim($r[4] ?? 'both'));
            $total_cap = (int)($r[5] ?? 0);
            $used_cap  = (int)($r[6] ?? 0);

            if (!array_key_exists($course, self::COURSE_LABELS)) {
                $this->log_err($log, $counters, "ردیف {$line}: نوع دوره «{$course}» نامعتبر"); continue;
            }
            if (!in_array($gender,    ['male','female','both'], true)) { $this->log_err($log, $counters, "ردیف {$line}: جنسیت «{$gender}» نامعتبر"); continue; }
            if (!in_array($messenger, ['telegram','bale','eitaa','both'], true)) { $this->log_err($log, $counters, "ردیف {$line}: پیامرسان «{$messenger}» نامعتبر"); continue; }

            // [FIX] استفاده از $course (نه $course_type) در کوئری
            $existing = TA_Database::get_row(
                "SELECT id FROM " . TA_Database::table('capacities') .
                " WHERE ta_id = %d AND course_type = %s AND term_id = %d AND student_gender = %s AND messenger = %s",
                [$ta->id, $course, $term_id, $gender, $messenger]
            );

            if ($existing) {
                TA_Database::update('ta_capacities', ['total_capacity'=>$total_cap,'used_capacity'=>$used_cap], ['id'=>$existing->id]);
                $this->log_update($log, $counters, "ردیف {$line}: ↺ به‌روز {$r[0]} / {$course}");
            } else {
                TA_Database::insert('ta_capacities', [
                    'ta_id'=>$ta->id,'course_type'=>$course,'term_id'=>$term_id,
                    'student_gender'=>$gender,'messenger'=>$messenger,
                    'total_capacity'=>$total_cap,'used_capacity'=>$used_cap,
                    'created_at'=>current_time('mysql'),
                ]);
                $this->log_ok($log, $counters, "ردیف {$line}: ✓ {$r[0]} / {$course} ظرفیت {$total_cap}");
            }
        }

        $processed += count($batch);
        $this->maybe_continue_or_finish('ta_import_capacities', $temp_file, $rows, $processed, $total, $log, $counters, 'ظرفیت‌ها');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Batch helpers
    // ═══════════════════════════════════════════════════════════════════════

    private function maybe_continue_or_finish( string $action, string $temp_file, array $rows, int $processed, int $total, array $log, array $counters, string $title ): void {
        if ($processed < $total) {
            $this->render_progress_page($action, $temp_file, $processed, $total, $log, $counters);
        } else {
            $this->cleanup_temp($temp_file);
            set_transient("ta_import_results_" . get_current_user_id(), array_merge($counters, ['log'=>$log,'title'=>$title]), 120);
            wp_safe_redirect(admin_url('admin.php?page=ta-import'));
            exit;
        }
    }

    private function render_progress_page( string $action, string $temp_file, int $processed, int $total, array $log, array $counters ): void {
        $pct = $total > 0 ? round($processed / $total * 100) : 0;
        ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>در حال ایمپورت...</title>
<style>
*{box-sizing:border-box}
body{font-family:Tahoma,sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;direction:rtl}
.box{background:#fff;border-radius:16px;padding:32px;max-width:560px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,.12)}
h2{margin:0 0 6px;font-size:20px;color:#111}
p{color:#6b7280;font-size:13px;margin:0 0 24px}
.bar-wrap{height:10px;background:#e5e7eb;border-radius:99px;overflow:hidden;margin-bottom:8px}
.bar-fill{height:100%;background:linear-gradient(90deg,#6366f1,#8b5cf6);border-radius:99px;transition:width .4s ease}
.pct{font-size:13px;color:#6b7280;text-align:left;margin-bottom:20px}
.pct strong{color:#111;font-size:16px}
.log-box{max-height:200px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;padding:10px;background:#f9fafb;font-size:12px}
.log-row{display:flex;gap:6px;padding:3px 0;border-bottom:1px solid #f3f4f6}
.log-row:last-child{border-bottom:none}
.log-ok{color:#059669}.log-upd{color:#2563eb}.log-skip{color:#9ca3af}.log-err{color:#dc2626}
.stats{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.stat{font-size:12px;padding:4px 10px;border-radius:99px}
.s-ok{background:#d1fae5;color:#065f46}
.s-upd{background:#dbeafe;color:#1e40af}
.s-skip{background:#f3f4f6;color:#374151}
.s-err{background:#fee2e2;color:#991b1b}
</style>
</head>
<body>
<div class="box">
    <h2>⏳ در حال پردازش...</h2>
    <p>لطفاً صبر کنید. این صفحه را نبندید.</p>
    <div class="bar-wrap"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
    <div class="pct"><strong><?= $pct ?>%</strong> — <?= $processed ?> از <?= $total ?> ردیف</div>
    <div class="stats">
        <span class="stat s-ok">✓ <?= $counters['imported'] ?> جدید</span>
        <span class="stat s-upd">↺ <?= $counters['updated'] ?> به‌روز</span>
        <span class="stat s-skip">⏭ <?= $counters['skipped'] ?> تکراری</span>
        <span class="stat s-err">✕ <?= $counters['errors'] ?> خطا</span>
    </div>
    <div class="log-box" id="log-box">
        <?php foreach (array_slice($log, -20) as $e) :
            $cls = ['success'=>'log-ok','updated'=>'log-upd','skip'=>'log-skip','error'=>'log-err'][$e['type']] ?? '';
        ?>
            <div class="log-row <?= $cls ?>"><span><?= esc_html($e['msg']) ?></span></div>
        <?php endforeach; ?>
    </div>
    <form method="POST" id="go" action="<?= admin_url('admin-post.php') ?>">
        <?php wp_nonce_field($action); ?>
        <input type="hidden" name="action"    value="<?= $action ?>">
        <input type="hidden" name="processed" value="<?= $processed ?>">
        <input type="hidden" name="temp_file" value="<?= esc_attr($temp_file) ?>">
        <input type="hidden" name="log"       value="<?= htmlspecialchars(json_encode(array_slice($log,-200,null,true), JSON_UNESCAPED_UNICODE)) ?>">
        <input type="hidden" name="counters"  value="<?= htmlspecialchars(json_encode($counters, JSON_UNESCAPED_UNICODE)) ?>">
    </form>
</div>
<script>
document.getElementById('log-box').scrollTop = 99999;
setTimeout(function(){ document.getElementById('go').submit(); }, 800);
</script>
</body>
</html>
        <?php
        exit;
    }

    private function finish_error( string $msg ): never {
        set_transient("ta_import_results_" . get_current_user_id(), ['imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>1,'log'=>[['type'=>'error','msg'=>$msg]],'title'=>''], 60);
        wp_safe_redirect(admin_url('admin.php?page=ta-import'));
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Temp file helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function save_temp( array $rows ): string {
        $dir  = wp_upload_dir()['basedir'] . '/ta_temp/';
        wp_mkdir_p($dir);
        $name = 'ta_' . get_current_user_id() . '_' . time() . '.json';
        file_put_contents($dir . $name, json_encode($rows, JSON_UNESCAPED_UNICODE));
        return $name;
    }

    private function load_temp( string $name ): array {
        $path = wp_upload_dir()['basedir'] . '/ta_temp/' . $name;
        if (!file_exists($path)) wp_die('فایل موقت یافت نشد.');
        return json_decode(file_get_contents($path), true) ?: [];
    }

    private function cleanup_temp( string $name ): void {
        $path = wp_upload_dir()['basedir'] . '/ta_temp/' . $name;
        if (file_exists($path)) unlink($path);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Log helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function log_ok(   array &$log, array &$c, string $msg ): void { $log[] = ['type'=>'success','msg'=>$msg]; $c['imported']++; }
    private function log_update(array &$log, array &$c, string $msg ): void { $log[] = ['type'=>'updated','msg'=>$msg]; $c['updated']++; }
    private function log_skip( array &$log, array &$c, string $msg ): void { $log[] = ['type'=>'skip','msg'=>$msg]; $c['skipped']++; }
    private function log_err(  array &$log, array &$c, string $msg ): void { $log[] = ['type'=>'error','msg'=>$msg]; $c['errors']++; }

    // ─────────────────────────────────────────────────────────────────────────
    // Parse
    // ─────────────────────────────────────────────────────────────────────────

    private function parse_upload( string $path ): array|\WP_Error {
        if (empty($path)) return new \WP_Error('no_file', 'فایلی انتخاب نشده.');

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $path);
        finfo_close($finfo);

        $excel_mimes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip', 'application/octet-stream', 'application/vnd.ms-excel',
        ];

        $ext = strtolower(pathinfo($_FILES['import_file']['name'] ?? $path, PATHINFO_EXTENSION));

        if ($ext === 'csv' || $mime === 'text/csv' || $mime === 'text/plain') {
            return $this->parse_csv($path);
        }

        if (in_array($mime, $excel_mimes, true) || in_array($ext, ['xlsx','xls'], true)) {
            $result = $this->parse_xlsx($path);
            if (is_wp_error($result)) return new \WP_Error('bad_file', 'فایل اکسل خوانده نشد.');
            return $result;
        }

        return new \WP_Error('bad_ext', 'فرمت نامعتبر. xlsx یا csv استفاده کنید.');
    }

    private function parse_csv( string $path ): array|\WP_Error {
        $rows = []; $fh = fopen($path, 'r');
        if (!$fh) return new \WP_Error('read', 'CSV خوانده نشد.');
        $n = 0;
        while (($row = fgetcsv($fh, 2000)) !== false) {
            if (++$n <= 1) continue; // skip header
            if ($this->blank($row)) continue;
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }

    private function parse_xlsx( string $path ): array|\WP_Error {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) return new \WP_Error('zip', 'xlsx باز نشد.');
        $ss = []; $ssxml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssxml) { $d = new \DOMDocument(); @$d->loadXML($ssxml); foreach ($d->getElementsByTagName('si') as $si) $ss[] = $si->textContent; }
        $sheetxml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (!$sheetxml) return new \WP_Error('sheet', 'شیت اول خوانده نشد.');
        $d = new \DOMDocument(); @$d->loadXML($sheetxml);
        $rows = []; $rn = 0;
        foreach ($d->getElementsByTagName('row') as $row_el) {
            if (++$rn <= 1) continue; // skip header row
            $cells = []; $mc = 0;
            foreach ($row_el->getElementsByTagName('c') as $c) {
                $col = $this->col_idx(preg_replace('/[0-9]/', '', $c->getAttribute('r')));
                $vel = $c->getElementsByTagName('v')->item(0);
                $val = $vel ? $vel->textContent : '';
                if ($c->getAttribute('t') === 's') $val = $ss[(int)$val] ?? '';
                $cells[$col] = $val; $mc = max($mc, $col);
            }
            if (empty($cells)) continue;
            $arr = []; for ($ci = 0; $ci <= $mc; $ci++) $arr[] = $cells[$ci] ?? '';
            if (!$this->blank($arr)) $rows[] = $arr;
        }
        return $rows;
    }

    private function col_idx( string $col ): int {
        $i = 0;
        foreach (str_split(strtoupper($col)) as $c) $i = $i * 26 + (ord($c) - 64);
        return $i - 1;
    }

    private function norm( array $row ): array { return array_map(fn($v) => trim((string)$v), $row); }
    private function blank( array $row ): bool { foreach ($row as $v) if (trim((string)$v) !== '') return false; return true; }

    // ─────────────────────────────────────────────────────────────────────────
    // CSS
    // ─────────────────────────────────────────────────────────────────────────

    private function render_styles(): void { ?>
<style>
:root{
    --tim-radius:12px;--tim-shadow:0 1px 3px rgba(0,0,0,.08);
    --tim-shadow-md:0 4px 16px rgba(0,0,0,.1);
    --tim-border:#e5e7eb;--tim-text:#111827;--tim-muted:#6b7280;--tim-white:#fff;
}
.tim-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Tahoma,sans-serif;direction:rtl;color:var(--tim-text);max-width:1400px}
/* هدر */
.tim-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;padding:18px 22px;background:var(--tim-white);border-radius:var(--tim-radius);box-shadow:var(--tim-shadow)}
.tim-header-title{display:flex;align-items:center;gap:14px}
.tim-header-icon{font-size:34px}
.tim-header h1{font-size:20px;font-weight:700;margin:0 0 3px}
.tim-header p{margin:0;color:var(--tim-muted);font-size:13px}
/* کارت */
.tim-card{background:var(--tim-white);border-radius:var(--tim-radius);box-shadow:var(--tim-shadow);overflow:hidden;margin-bottom:0}
.tim-card-header{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--tim-border)}
.tim-card-title{font-size:13px;font-weight:600}
.tim-card-sub{font-size:12px;color:var(--tim-muted)}
/* نتیجه */
.tim-result-card{margin-bottom:20px;border:1px solid var(--tim-border)}
.tim-result-header{display:flex;align-items:flex-start;gap:12px;padding:16px}
.tim-result-icon{font-size:28px;line-height:1;flex-shrink:0}
.tim-result-title{font-size:15px;font-weight:700;margin-bottom:6px}
.tim-result-stats{display:flex;gap:8px;flex-wrap:wrap}
.tim-result-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--tim-muted);margin-right:auto}
.tim-badge{display:inline-block;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:600}
.tim-badge-success{background:#d1fae5;color:#065f46}
.tim-badge-info{background:#dbeafe;color:#1e40af}
.tim-badge-skip{background:#f3f4f6;color:#374151}
.tim-badge-error{background:#fee2e2;color:#991b1b}
/* لاگ */
.tim-log-wrap{border-top:1px solid var(--tim-border)}
.tim-log-filter{display:flex;gap:6px;padding:10px 16px;background:#f9fafb;border-bottom:1px solid var(--tim-border)}
.tim-log-btn{padding:4px 12px;border-radius:99px;border:1px solid var(--tim-border);background:var(--tim-white);font-size:12px;cursor:pointer;font-family:inherit;transition:all .15s}
.tim-log-btn:hover,.tim-log-btn-active{background:#6366f1;color:#fff;border-color:#6366f1}
.tim-log{max-height:240px;overflow-y:auto;padding:10px 16px}
.tim-log-row{display:flex;align-items:baseline;gap:8px;padding:4px 0;border-bottom:1px solid #f9fafb;font-size:12px}
.tim-log-row:last-child{border-bottom:none}
.tim-log-success .tim-log-icon{color:#10b981}
.tim-log-updated .tim-log-icon{color:#3b82f6}
.tim-log-skip .tim-log-icon{color:#9ca3af}
.tim-log-error .tim-log-icon{color:#ef4444}
.tim-log-row[style*="display:none"]{display:none!important}
/* grid کارت‌های ایمپورت */
.tim-cards-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px}
@media(max-width:1100px){.tim-cards-grid{grid-template-columns:1fr 1fr}}
@media(max-width:700px){.tim-cards-grid{grid-template-columns:1fr}}
.tim-import-card{border-top:4px solid var(--card-color);padding:0;display:flex;flex-direction:column}
.tim-card-top{display:flex;align-items:center;gap:12px;padding:16px 16px 12px}
.tim-card-icon{font-size:28px;line-height:1}
.tim-card-title{font-size:15px;font-weight:700;line-height:1.2}
.tim-card-sub{font-size:12px;color:var(--tim-muted);margin-top:2px}
.tim-sample-btn{margin-right:auto;font-size:12px;padding:4px 10px;border-radius:8px;border:1px solid var(--tim-border);background:#f9fafb;color:var(--tim-text);text-decoration:none;white-space:nowrap;transition:all .15s}
.tim-sample-btn:hover{background:#e5e7eb}
/* ستون‌ها */
.tim-cols-list{padding:0 16px 12px;display:flex;flex-direction:column;gap:5px}
.tim-col-item{display:flex;align-items:center;gap:8px;font-size:12px}
.tim-col-num{width:20px;height:20px;border-radius:50%;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;color:var(--tim-muted)}
.tim-col-label{flex:1;color:var(--tim-text)}
.tim-col-req{color:#ef4444;font-weight:700}
.tim-col-opt{font-size:11px;color:#9ca3af;background:#f9fafb;padding:1px 6px;border-radius:99px}
/* فرم آپلود */
.tim-upload-form{padding:0 16px 16px;margin-top:auto}
.tim-drop-zone{border:2px dashed var(--card-color);border-radius:10px;padding:20px;text-align:center;cursor:pointer;transition:all .2s;background:color-mix(in srgb, var(--card-color) 5%, white)}
.tim-drop-zone:hover,.tim-drop-zone.drag-over{background:color-mix(in srgb, var(--card-color) 12%, white);border-style:solid}
.tim-drop-icon{font-size:28px;margin-bottom:6px}
.tim-drop-text{font-size:13px;font-weight:600;color:var(--tim-text);margin-bottom:3px}
.tim-drop-hint{font-size:11px;color:var(--tim-muted)}
.tim-drop-filename{font-size:12px;color:var(--card-color);font-weight:600;margin-top:6px;word-break:break-all}
.tim-form-options{margin:10px 0 12px}
.tim-checkbox-label{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--tim-muted);cursor:pointer}
.tim-submit-btn{width:100%;padding:10px;border:none;border-radius:8px;color:#fff;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;transition:opacity .15s;display:flex;align-items:center;justify-content:center;gap:8px}
.tim-submit-btn:hover{opacity:.88}
/* refs */
.tim-refs-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
@media(max-width:1100px){.tim-refs-grid{grid-template-columns:1fr 1fr}}
@media(max-width:700px){.tim-refs-grid{grid-template-columns:1fr}}
.tim-ref-card{}
.tim-ref-scroll{max-height:240px;overflow-y:auto}
.tim-ref-table{width:100%;border-collapse:collapse;font-size:12px}
.tim-ref-table th{background:#f9fafb;padding:7px 10px;text-align:right;font-weight:600;font-size:11px;color:var(--tim-muted);border-bottom:1px solid var(--tim-border)}
.tim-ref-table td{padding:6px 10px;border-bottom:1px solid #f9fafb}
.tim-ref-table tr:last-child td{border-bottom:none}
.tim-empty-cell{text-align:center;color:var(--tim-muted);padding:14px!important}
.tim-code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:11px;font-family:monospace}
.tim-muted{color:var(--tim-muted)}
/* مقادیر */
.tim-vals-list{padding:12px 16px;display:flex;flex-direction:column;gap:14px}
.tim-val-group{}
.tim-val-title{font-size:11px;font-weight:700;color:var(--tim-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.tim-val-row{display:flex;align-items:center;gap:8px;padding:3px 0;font-size:12px}
</style>
    <?php }

    // ─────────────────────────────────────────────────────────────────────────
    // JS
    // ─────────────────────────────────────────────────────────────────────────

    private function render_scripts(): void { ?>
<script>
// ── Drag & Drop ────────────────────────────────────────────────────────────
['assignments','grades','capacities'].forEach(function(type) {
    const zone = document.getElementById('drop-' + type);
    const inp  = document.getElementById('file-' + type);
    if (!zone || !inp) return;

    zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', e => { zone.classList.remove('drag-over'); });
    zone.addEventListener('drop', e => {
        e.preventDefault(); zone.classList.remove('drag-over');
        if (e.dataTransfer.files.length) {
            inp.files = e.dataTransfer.files;
            timFileSelected(type, inp);
        }
    });
});

function timFileSelected(type, input) {
    const fname = document.getElementById('fname-' + type);
    if (fname && input.files.length) {
        fname.textContent = '📄 ' + input.files[0].name + ' (' + (input.files[0].size / 1024).toFixed(1) + ' KB)';
    }
}

// ── فیلتر لاگ ─────────────────────────────────────────────────────────────
function timFilterLog(filter, btn) {
    document.querySelectorAll('.tim-log-btn').forEach(b => b.classList.remove('tim-log-btn-active'));
    btn.classList.add('tim-log-btn-active');
    document.querySelectorAll('#tim-log .tim-log-row').forEach(row => {
        if (filter === 'all') {
            row.style.display = '';
        } else {
            row.style.display = row.classList.contains('tim-log-' + filter) ? '' : 'none';
        }
    });
}
</script>
    <?php }
}
