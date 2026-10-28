<?php
defined( 'ABSPATH' ) || exit;

/**
 * مدیریت حرفه‌ای ظرفیت‌های استادیاران
 *
 * قابلیت‌ها:
 * - داشبورد آماری (کل / استفاده‌شده / خالی / درصد پر بودن)
 * - نمودار توزیع نوع دوره
 * - فیلتر پیشرفته (ترم / نوع دوره / استادیار / جنسیت / پیامرسان)
 * - جدول با Progress Bar برای هر ردیف
 * - ویرایش درون‌خطی با Modal (بدون صفحه جداگانه)
 * - مدیریت گروه‌های خلاق inline
 * - نمایش گروه‌های غیرخلاق inline
 * - عملیات گروهی (حذف)
 * - همگام‌سازی ظرفیت‌ها (فقط active)
 * - خروجی CSV با nonce
 */
class TA_Capacity_Manager {

    private $db;
    private $cap_table;
    private $term_table;
    private $ta_table;
    private $assign_table;
    private $users_table;
    private $group_table;

    private const COURSE_LABELS = [
        'creative'      => 'نویسندگی خلاق',
        'intro'         => 'نویسندگی مقدماتی',
        'basic_writing' => 'نویسندگی پایه',
        'advanced'      => 'نویسندگی پیشرفته',
        'professional'  => 'نویسندگی حرفه‌ای',
        'critique'      => 'نقد اثر',
        'ladder'        => 'نردبان حرفه‌ای',
    ];

    private const COURSE_COLORS = [
        'creative'      => '#8b5cf6',
        'intro'         => '#3b82f6',
        'basic_writing' => '#06b6d4',
        'advanced'      => '#10b981',
        'professional'  => '#f59e0b',
        'critique'      => '#ec4899',
        'ladder'        => '#f97316',
    ];

    private const GENDER_LABELS = [
        'both'   => 'هر دو',
        'male'   => 'آقایان',
        'female' => 'خانم‌ها',
    ];

    private const MESSENGER_LABELS = [
        'telegram' => 'تلگرام',
        'bale'     => 'بله',
        'eitaa'    => 'ایتا',
        'both'     => 'همه',
    ];

    private const MESSENGER_ICONS = [
        'telegram' => '✈',
        'bale'     => '🔵',
        'eitaa'    => '🟢',
        'both'     => '📱',
    ];

    public function __construct() {
        global $wpdb;
        $this->db           = $wpdb;
        $this->cap_table    = $wpdb->prefix . 'ta_capacities';
        $this->term_table   = $wpdb->prefix . 'ta_terms';
        $this->ta_table     = $wpdb->prefix . 'ta_assistants';
        $this->assign_table = $wpdb->prefix . 'ta_assignments';
        $this->users_table  = $wpdb->users;
        $this->group_table  = $wpdb->prefix . 'ta_groups';

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        $this->handle_actions();
    }

    /**
     * برگرداندن پیامرسان‌های فعال از تنظیمات (با لیبل)
     */
    private function get_active_messenger_labels(): array {
        $all = [
            'telegram' => 'تلگرام',
            'bale'     => 'بله',
            'eitaa'    => 'ایتا',
        ];
        $active = TA_Settings_Helper::get( 'supported_messengers', ['telegram','bale','eitaa'] );
        if ( ! is_array( $active ) || empty( $active ) ) {
            return $all;
        }
        $filtered = array_intersect_key( $all, array_flip( $active ) );
        return ! empty( $filtered ) ? $filtered : $all;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Enqueue
    // ─────────────────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( false === strpos( $hook, 'ta-capacities' ) ) return;

        wp_enqueue_style( 'ta-system', TA_SYSTEM_URL . 'assets/css/ta-system.css', [], ta_system_asset_ver( 'assets/css/ta-system.css' ) );
        wp_enqueue_script( 'chart-js', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js', [], '4.4.1', true );
        wp_enqueue_script( 'ta-system', TA_SYSTEM_URL . 'assets/js/ta-system.js', [ 'jquery' ], ta_system_asset_ver( 'assets/js/ta-system.js' ), true );
        $active_msg_labels = $this->get_active_messenger_labels();
        $active_msg_icons  = array_map( fn($k) => self::MESSENGER_ICONS[$k] ?? '📱', array_combine(
            array_keys($active_msg_labels), array_keys($active_msg_labels)
        ));
        wp_localize_script( 'ta-system', 'taSystem', [
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'ta_dashboard_nonce' ),
            'adminNonce'       => wp_create_nonce( 'ta_admin_nonce' ),
            'isDashboard'      => 0,
            'messengerLabels'  => $active_msg_labels,
            'messengerIcons'   => $active_msg_icons,
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // پردازش اکشن‌ها
    // ─────────────────────────────────────────────────────────────────────────

    private function handle_actions(): void {
        // ایجاد ظرفیت جدید از صفحه مدیریت ظرفیت‌ها
        if ( isset( $_POST['tcm_add_nonce'] ) && wp_verify_nonce( $_POST['tcm_add_nonce'], 'tcm_add_capacity' ) ) {
            if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );
            $ta_id = absint( $_POST['ta_id'] ?? 0 );
            $term_id = absint( $_POST['term_id'] ?? 0 );
            $course_type = sanitize_text_field( $_POST['course_type'] ?? '' );
            $student_gender = sanitize_text_field( $_POST['student_gender'] ?? 'both' );
            $messenger = sanitize_text_field( $_POST['messenger'] ?? 'both' );
            $total_capacity = absint( $_POST['total_capacity'] ?? 0 );
            $valid_genders = array_keys( self::GENDER_LABELS );
            $valid_messages = array_merge( [ 'both' ], array_keys( $this->get_active_messenger_labels() ) );
            if ( ! $ta_id || ! $term_id || ! array_key_exists( $course_type, self::COURSE_LABELS )
                 || ! in_array( $student_gender, $valid_genders, true )
                 || ! in_array( $messenger, $valid_messages, true ) ) {
                wp_safe_redirect( add_query_arg( 'error', 'invalid_capacity', remove_query_arg( [] ) ) ); exit;
            }
            $inserted = $this->db->insert( $this->cap_table, [
                'ta_id' => $ta_id, 'term_id' => $term_id, 'course_type' => $course_type,
                'student_gender' => $student_gender, 'messenger' => $messenger,
                'total_capacity' => $total_capacity, 'used_capacity' => 0,
            ], [ '%d', '%d', '%s', '%s', '%s', '%d', '%d' ] );
            wp_safe_redirect( add_query_arg( $inserted ? 'added' : 'error', $inserted ? 1 : 'invalid_capacity', remove_query_arg( [] ) ) ); exit;
        }

        // همگام‌سازی
        if ( isset( $_GET['action'], $_GET['_wpnonce'] ) && $_GET['action'] === 'sync_capacity'
             && wp_verify_nonce( $_GET['_wpnonce'], 'ta_sync_capacity' ) ) {
            $stats = $this->sync_capacities();
            wp_safe_redirect( add_query_arg( [
                'synced'  => 1,
                'total'   => $stats['total'],
                'counted' => $stats['counted'],
            ], remove_query_arg( [ 'action', '_wpnonce' ] ) ) );
            exit;
        }

        // خروجی CSV
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'export_csv' ) {
            $this->export_csv();
        }

        // ذخیره ویرایش (Modal POST)
        if ( isset( $_POST['tcm_edit_nonce'] ) && wp_verify_nonce( $_POST['tcm_edit_nonce'], 'tcm_edit_capacity' ) ) {
            if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );

            $id             = absint( $_POST['cap_id']         ?? 0 );
            $term_id        = absint( $_POST['term_id']        ?? 0 );
            $course_type    = sanitize_text_field( $_POST['course_type']    ?? '' );
            $student_gender = sanitize_text_field( $_POST['student_gender'] ?? 'both' );
            $messenger      = sanitize_text_field( $_POST['messenger']      ?? 'both' );
            $total_capacity = absint( $_POST['total_capacity'] ?? 0 );

            if ( ! array_key_exists( $course_type, self::COURSE_LABELS ) ) {
                wp_safe_redirect( add_query_arg( 'error', 'invalid_type', remove_query_arg( [] ) ) );
                exit;
            }

            $this->db->update( $this->cap_table,
                [ 'term_id' => $term_id, 'course_type' => $course_type,
                  'student_gender' => $student_gender, 'messenger' => $messenger,
                  'total_capacity' => $total_capacity ],
                [ 'id' => $id ], [ '%d','%s','%s','%s','%d' ], [ '%d' ]
            );
            wp_safe_redirect( add_query_arg( 'updated', 1, remove_query_arg( [ 'action', 'cap_id' ] ) ) );
            exit;
        }

        // حذف یک ظرفیت
        if ( isset( $_GET['action'], $_GET['cap_id'], $_GET['_wpnonce'] )
             && $_GET['action'] === 'delete'
             && wp_verify_nonce( $_GET['_wpnonce'], 'delete_capacity_' . $_GET['cap_id'] ) ) {
            if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );
            $this->db->delete( $this->cap_table, [ 'id' => absint( $_GET['cap_id'] ) ] );
            wp_safe_redirect( add_query_arg( 'deleted', 1, remove_query_arg( [ 'action', 'cap_id', '_wpnonce' ] ) ) );
            exit;
        }

        // عملیات گروهی
        if ( isset( $_POST['tcm_bulk_nonce'] ) && wp_verify_nonce( $_POST['tcm_bulk_nonce'], 'tcm_bulk_action' ) ) {
            $action = sanitize_text_field( $_POST['bulk_action'] ?? '' );
            $ids    = array_map( 'absint', (array) ( $_POST['cap_ids'] ?? [] ) );
            if ( $action === 'delete' && ! empty( $ids ) ) {
                foreach ( $ids as $id ) {
                    $this->db->delete( $this->group_table,  [ 'capacity_id' => $id ] );
                    $this->db->delete( $this->cap_table, [ 'id' => $id ] );
                }
                wp_safe_redirect( add_query_arg( 'bulk_deleted', count( $ids ), remove_query_arg( [] ) ) );
                exit;
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // همگام‌سازی ظرفیت‌ها (فقط active)
    // ─────────────────────────────────────────────────────────────────────────

    private function sync_capacities(): array {
        $counts = $this->db->get_results(
            "SELECT capacity_id, COUNT(*) AS cnt
             FROM {$this->assign_table}
             WHERE capacity_id > 0 AND status = 'active'
             GROUP BY capacity_id",
            OBJECT_K
        );
        $total = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->assign_table} WHERE capacity_id > 0" );
        $this->db->query( "UPDATE {$this->cap_table} SET used_capacity = 0" );
        foreach ( (array) $counts as $cid => $row ) {
            $this->db->update( $this->cap_table, [ 'used_capacity' => (int) $row->cnt ], [ 'id' => (int) $cid ], [ '%d' ], [ '%d' ] );
        }
        return [ 'total' => $total, 'counted' => count( (array) $counts ), 'updated_ids' => count( (array) $counts ) ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // خروجی XLSX
    // ─────────────────────────────────────────────────────────────────────────

    private function export_csv(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ta_export_capacities' ) ) wp_die( 'درخواست نامعتبر.' );

        $rows = $this->db->get_results(
            "SELECT c.*, u.display_name, t.name AS term_name
             FROM {$this->cap_table} c
             LEFT JOIN {$this->ta_table} ta ON c.ta_id = ta.id
             LEFT JOIN {$this->users_table} u ON ta.user_id = u.ID
             LEFT JOIN {$this->term_table} t ON c.term_id = t.id
             ORDER BY t.id DESC, c.id DESC"
        );

        while ( ob_get_level() ) ob_end_clean();

        $writer = new TA_Xlsx_Writer();
        $writer->set_rtl( true );
        $writer->add_row(
            [ 'ردیف', 'استادیار', 'ترم', 'نوع دوره', 'جنسیت', 'پیامرسان', 'ظرفیت کل', 'استفاده‌شده', 'باقیمانده', 'درصد اشغال' ],
            true
        );
        foreach ( $rows as $i => $r ) {
            $total = (int) $r->total_capacity;
            $used  = (int) $r->used_capacity;
            $writer->add_row( [
                $i + 1,
                $r->display_name ?? '',
                $r->term_name    ?? '',
                self::COURSE_LABELS[ $r->course_type ]    ?? $r->course_type,
                self::GENDER_LABELS[ $r->student_gender ] ?? $r->student_gender,
                self::MESSENGER_LABELS[ $r->messenger ]   ?? $r->messenger,
                $total,
                $used,
                $total - $used,
                ( $total > 0 ? round( $used / $total * 100 ) : 0 ) . '%',
            ] );
        }
        $writer->output( 'capacities-' . date('Y-m-d') . '.xlsx' );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // رندر صفحه اصلی
    // ─────────────────────────────────────────────────────────────────────────

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'دسترسی غیرمجاز.' ); }

        global $wpdb;

        // ── فیلترها ──────────────────────────────────────────────────────────
        $f_term     = absint( $_GET['filter_term']      ?? 0 );
        $f_type     = sanitize_text_field( $_GET['filter_type']     ?? '' );
        $f_ta       = absint( $_GET['filter_ta']        ?? 0 );
        $f_gender   = sanitize_text_field( $_GET['filter_gender']   ?? '' );
        $f_msg      = sanitize_text_field( $_GET['filter_messenger'] ?? '' );
        $f_status   = sanitize_text_field( $_GET['filter_status']   ?? '' ); // full | available | empty

        // ── کوئری اصلی ──────────────────────────────────────────────────────
        $where = [ '1=1' ];
        $params = [];
        if ( $f_term )   { $where[] = 'c.term_id = %d';     $params[] = $f_term; }
        if ( $f_type )   { $where[] = 'c.course_type = %s'; $params[] = $f_type; }
        if ( $f_ta )     { $where[] = 'c.ta_id = %d';       $params[] = $f_ta; }
        if ( $f_gender ) { $where[] = 'c.student_gender = %s'; $params[] = $f_gender; }
        if ( $f_msg )    { $where[] = 'c.messenger = %s';   $params[] = $f_msg; }

        $where_sql = implode( ' AND ', $where );

        $sql = "SELECT c.*, u.display_name AS ta_name, t.name AS term_name,
                    (SELECT COUNT(*) FROM {$this->group_table} g WHERE g.capacity_id = c.id) AS group_count,
                    (SELECT COUNT(*) FROM {$this->group_table} g WHERE g.capacity_id = c.id AND g.is_used = 1) AS group_used
                FROM {$this->cap_table} c
                LEFT JOIN {$this->ta_table} ta ON c.ta_id = ta.id
                LEFT JOIN {$this->users_table} u ON ta.user_id = u.ID
                LEFT JOIN {$this->term_table} t ON c.term_id = t.id
                WHERE {$where_sql}
                ORDER BY t.id DESC, u.display_name ASC, c.course_type ASC";

        $results = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) )
            : $wpdb->get_results( $sql );

        // فیلتر وضعیت (post-query چون نیاز به محاسبه دارد)
        if ( $f_status ) {
            $results = array_filter( $results, function( $r ) use ( $f_status ) {
                $pct = $r->total_capacity > 0 ? $r->used_capacity / $r->total_capacity : 0;
                if ( $f_status === 'full' )      return $pct >= 1;
                if ( $f_status === 'available' ) return $pct < 1 && $r->used_capacity > 0;
                if ( $f_status === 'empty' )     return $r->used_capacity === '0' || $r->used_capacity == 0;
                return true;
            } );
        }
        $results = array_values( $results );

        // ── آمار بر اساس نتایج فیلترشده ────────────────────────────────────
        $sum_total = 0;
        $sum_used  = 0;
        $cnt_full  = 0;
        $cnt_all   = count( $results );
        $type_dist_map = [];
        foreach ( $results as $r ) {
            $sum_total += (int) $r->total_capacity;
            $sum_used  += (int) $r->used_capacity;
            if ( (int) $r->used_capacity >= (int) $r->total_capacity && (int) $r->total_capacity > 0 ) {
                $cnt_full++;
            }
            $ct = $r->course_type;
            if ( ! isset( $type_dist_map[$ct] ) ) {
                $type_dist_map[$ct] = (object) [ 'course_type' => $ct, 'total' => 0, 'used' => 0 ];
            }
            $type_dist_map[$ct]->total += (int) $r->total_capacity;
            $type_dist_map[$ct]->used  += (int) $r->used_capacity;
        }
        $sum_free  = $sum_total - $sum_used;
        $fill_rate = $sum_total > 0 ? round( $sum_used / $sum_total * 100 ) : 0;
        $type_dist = array_values( $type_dist_map );

        // ── داده‌های فیلتر ────────────────────────────────────────────────
        $terms   = $wpdb->get_results( "SELECT id, name FROM {$this->term_table} ORDER BY order_index ASC" );
        $all_tas = $wpdb->get_results(
            "SELECT ta.id, u.display_name FROM {$this->ta_table} ta
             JOIN {$this->users_table} u ON u.ID = ta.user_id
             WHERE ta.is_active = 1 ORDER BY u.display_name ASC"
        );

        $base_url   = admin_url( 'admin.php?page=ta-capacities' );
        $sync_url   = wp_nonce_url( add_query_arg( 'action', 'sync_capacity', $base_url ), 'ta_sync_capacity' );
        $export_url = wp_nonce_url( add_query_arg( 'action', 'export_csv', $base_url ), 'ta_export_capacities' );

        $this->render_styles();
        ?>

<div class="wrap tcm-wrap" dir="rtl">

<!-- ── نوتیس‌ها ─────────────────────────────────────────────────────── -->
<?php if ( isset($_GET['added']) )        : ?><div class="tcm-notice tcm-notice-success">✓ ظرفیت جدید با موفقیت ایجاد شد.</div><?php endif; ?>
<?php if ( isset($_GET['error']) && $_GET['error'] === 'invalid_capacity' ) : ?><div class="tcm-notice tcm-notice-danger">اطلاعات ظرفیت نامعتبر است.</div><?php endif; ?>
<?php if ( isset($_GET['updated']) )      : ?><div class="tcm-notice tcm-notice-success">✓ ظرفیت با موفقیت ذخیره شد.</div><?php endif; ?>
<?php if ( isset($_GET['deleted']) )      : ?><div class="tcm-notice tcm-notice-danger">🗑 ظرفیت حذف شد.</div><?php endif; ?>
<?php if ( isset($_GET['bulk_deleted']) ) : ?><div class="tcm-notice tcm-notice-danger">🗑 <?= intval($_GET['bulk_deleted']) ?> ظرفیت حذف شد.</div><?php endif; ?>
<?php if ( isset($_GET['synced']) ) : ?>
<div class="tcm-notice tcm-notice-success">
    🔄 همگام‌سازی انجام شد — <?= intval($_GET['total']) ?> تخصیص بررسی، <?= intval($_GET['counted']) ?> ظرفیت به‌روز شد.
</div>
<?php endif; ?>

<!-- ── هدر ─────────────────────────────────────────────────────────── -->
<div class="tcm-header">
    <div class="tcm-header-title">
        <span class="tcm-header-icon">📦</span>
        <div>
            <h1>مدیریت ظرفیت‌ها</h1>
            <p>تنظیم و نظارت بر ظرفیت پذیرش استادیاران</p>
        </div>
    </div>
    <div class="tcm-header-actions">
        <button type="button" class="tcm-btn tcm-btn-add" onclick="tcmOpenAdd()">＋ تعریف ظرفیت</button>
        <a href="<?= esc_url($sync_url) ?>" class="tcm-btn tcm-btn-sync">🔄 همگام‌سازی</a>
        <a href="<?= esc_url($export_url) ?>" class="tcm-btn tcm-btn-export">⬇ خروجی اکسل</a>
    </div>
</div>

<!-- ── کارت‌های آمار ─────────────────────────────────────────────── -->
<div class="tcm-stats-grid">
    <div class="tcm-stat-card tcm-stat-total">
        <div class="tcm-stat-icon">📊</div>
        <div class="tcm-stat-body">
            <div class="tcm-stat-number"><?= number_format($sum_total) ?></div>
            <div class="tcm-stat-label">کل ظرفیت</div>
        </div>
        <div class="tcm-stat-sub"><?= $cnt_all ?> ردیف</div>
    </div>
    <div class="tcm-stat-card tcm-stat-used">
        <div class="tcm-stat-icon">👥</div>
        <div class="tcm-stat-body">
            <div class="tcm-stat-number"><?= number_format($sum_used) ?></div>
            <div class="tcm-stat-label">استفاده‌شده</div>
        </div>
        <div class="tcm-stat-sub"><?= $fill_rate ?>% اشغال</div>
    </div>
    <div class="tcm-stat-card tcm-stat-free">
        <div class="tcm-stat-icon">✅</div>
        <div class="tcm-stat-body">
            <div class="tcm-stat-number"><?= number_format($sum_free) ?></div>
            <div class="tcm-stat-label">ظرفیت خالی</div>
        </div>
        <div class="tcm-stat-sub"><?= 100 - $fill_rate ?>% آزاد</div>
    </div>
    <div class="tcm-stat-card tcm-stat-full">
        <div class="tcm-stat-icon">🔴</div>
        <div class="tcm-stat-body">
            <div class="tcm-stat-number"><?= $cnt_full ?></div>
            <div class="tcm-stat-label">ظرفیت پر</div>
        </div>
        <div class="tcm-stat-sub">از <?= $cnt_all ?> ردیف</div>
    </div>
    <div class="tcm-stat-card tcm-stat-rate">
        <div class="tcm-stat-icon">📈</div>
        <div class="tcm-stat-body">
            <div class="tcm-stat-number"><?= $fill_rate ?>%</div>
            <div class="tcm-stat-label">نرخ اشغال</div>
        </div>
        <div class="tcm-stat-sub">
            <div class="tcm-progress-bar">
                <div class="tcm-progress-fill" style="width:<?= $fill_rate ?>%;background:<?= $fill_rate >= 90 ? '#ef4444' : ($fill_rate >= 70 ? '#f59e0b' : '#10b981') ?>"></div>
            </div>
        </div>
    </div>
</div>

<!-- ── نمودار توزیع دوره‌ها ────────────────────────────────────────── -->
<div class="tcm-chart-row">
    <div class="tcm-card tcm-chart-card">
        <div class="tcm-card-header"><span class="tcm-card-title">📊 ظرفیت به تفکیک نوع دوره</span></div>
        <canvas id="tcmBarChart" height="70"></canvas>
    </div>
    <div class="tcm-card tcm-donut-card">
        <div class="tcm-card-header"><span class="tcm-card-title">🍩 توزیع ظرفیت کل</span></div>
        <div class="tcm-donut-wrap">
            <canvas id="tcmDonutChart"></canvas>
        </div>
        <div class="tcm-donut-legend">
            <?php foreach ( $type_dist as $td ) :
                $color = self::COURSE_COLORS[ $td->course_type ] ?? '#94a3b8';
                $label = self::COURSE_LABELS[ $td->course_type ] ?? $td->course_type;
            ?>
                <div class="tcm-legend-item">
                    <span class="tcm-legend-dot" style="background:<?= $color ?>"></span>
                    <span><?= esc_html($label) ?></span>
                    <strong><?= (int)$td->total ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ── فیلترها ───────────────────────────────────────────────────── -->
<div class="tcm-card tcm-filter-card">
    <form method="GET" class="tcm-filter-form">
        <input type="hidden" name="page" value="ta-capacities">
        <div class="tcm-filter-row">
            <select name="filter_term" class="tcm-select">
                <option value="">همه ترم‌ها</option>
                <?php foreach ( $terms as $t ) : ?>
                    <option value="<?= $t->id ?>" <?= selected($f_term, $t->id, false) ?>><?= esc_html($t->name) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="filter_type" class="tcm-select">
                <option value="">همه نوع دوره‌ها</option>
                <?php foreach ( self::COURSE_LABELS as $k => $v ) : ?>
                    <option value="<?= $k ?>" <?= selected($f_type, $k, false) ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <select name="filter_ta" class="tcm-select">
                <option value="">همه استادیاران</option>
                <?php foreach ( $all_tas as $ta ) : ?>
                    <option value="<?= $ta->id ?>" <?= selected($f_ta, $ta->id, false) ?>><?= esc_html($ta->display_name) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="filter_gender" class="tcm-select">
                <option value="">همه جنسیت‌ها</option>
                <?php foreach ( self::GENDER_LABELS as $k => $v ) : ?>
                    <option value="<?= $k ?>" <?= selected($f_gender, $k, false) ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <select name="filter_messenger" class="tcm-select">
                <option value="">همه پیامرسان‌ها</option>
                <?php foreach ( $this->get_active_messenger_labels() as $k => $v ) : ?>
                    <option value="<?= $k ?>" <?= selected($f_msg, $k, false) ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <select name="filter_status" class="tcm-select">
                <option value="">همه وضعیت‌ها</option>
                <option value="full"      <?= selected($f_status, 'full', false) ?>>🔴 پر</option>
                <option value="available" <?= selected($f_status, 'available', false) ?>>🟡 در حال استفاده</option>
                <option value="empty"     <?= selected($f_status, 'empty', false) ?>>🟢 خالی</option>
            </select>
            <button type="submit" class="tcm-btn tcm-btn-filter">اعمال</button>
            <a href="<?= $base_url ?>" class="tcm-btn tcm-btn-reset">پاک</a>
        </div>
    </form>
</div>

<!-- ── جدول ─────────────────────────────────────────────────────── -->
<div class="tcm-card">
    <div class="tcm-table-header">
        <div class="tcm-table-info">
            نمایش <strong><?= count($results) ?></strong> ردیف
            <?php if ($f_term || $f_type || $f_ta || $f_gender || $f_msg || $f_status) : ?>
                <span class="tcm-filter-badge">فیلتر فعال</span>
            <?php endif; ?>
        </div>
        <form method="POST" id="tcm-bulk-form">
            <?php wp_nonce_field( 'tcm_bulk_action', 'tcm_bulk_nonce' ); ?>
            <div class="tcm-bulk-bar">
                <select name="bulk_action" class="tcm-select tcm-select-sm">
                    <option value="">عملیات گروهی</option>
                    <option value="delete">🗑 حذف انتخاب‌شده‌ها</option>
                </select>
                <button type="submit" class="tcm-btn tcm-btn-bulk"
                        onclick="return confirmBulk(this)">اجرا</button>
            </div>
        </form>
    </div>

    <div class="tcm-table-wrap">
        <table class="tcm-table" id="tcm-main-table">
            <thead>
                <tr>
                    <th class="tcm-th-check"><input type="checkbox" id="tcm-check-all" onclick="tcmToggleAll(this)"></th>
                    <th>استادیار</th>
                    <th>ترم</th>
                    <th>نوع دوره</th>
                    <th>جنسیت</th>
                    <th>پیامرسان</th>
                    <th>ظرفیت</th>
                    <th>اشغال</th>
                    <th>گروه‌ها</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $results ) ) : ?>
                <tr>
                    <td colspan="10" class="tcm-empty">
                        <div class="tcm-empty-state">
                            <div>📦</div>
                            <p>ظرفیتی یافت نشد.</p>
                            <?php if ($f_term||$f_type||$f_ta||$f_gender||$f_msg||$f_status) : ?>
                                <a href="<?= $base_url ?>">پاک کردن فیلترها</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ( $results as $r ) :
                    $total   = (int) $r->total_capacity;
                    $used    = (int) $r->used_capacity;
                    $free    = $total - $used;
                    $pct     = $total > 0 ? round( $used / $total * 100 ) : 0;
                    $bar_col = $pct >= 90 ? '#ef4444' : ( $pct >= 70 ? '#f59e0b' : '#10b981' );
                    $type_color = self::COURSE_COLORS[ $r->course_type ] ?? '#94a3b8';
                    $del_url    = wp_nonce_url( add_query_arg( ['action'=>'delete','cap_id'=>$r->id], remove_query_arg(['updated','deleted','bulk_deleted','synced']) ), 'delete_capacity_' . $r->id );
                    $grp_cnt  = (int) $r->group_count;
                    $grp_used = (int) $r->group_used;
                    $grp_free = $grp_cnt - $grp_used;
                    $edit_data = htmlspecialchars( json_encode([
                        'id'             => $r->id,
                        'term_id'        => $r->term_id,
                        'course_type'    => $r->course_type,
                        'student_gender' => $r->student_gender,
                        'messenger'      => $r->messenger,
                        'total_capacity' => $total,
                        'ta_name'        => $r->ta_name,
                        'term_name'      => $r->term_name,
                    ]), ENT_QUOTES );
                ?>
                <tr class="tcm-row <?= $pct >= 100 ? 'tcm-row-full' : '' ?>" data-id="<?= $r->id ?>">
                    <td><input type="checkbox" name="cap_ids[]" value="<?= $r->id ?>" form="tcm-bulk-form" class="tcm-row-check"></td>

                    <td class="tcm-ta-cell">
                        <?php
                        $ta_uid = (int) $this->db->get_var( $this->db->prepare( "SELECT user_id FROM {$this->ta_table} WHERE id = %d", $r->ta_id ) );
                        $att_id = $ta_uid ? get_user_meta( $ta_uid, 'image', true ) : 0;
                        $av_url = $att_id ? wp_get_attachment_url( $att_id ) : 'https://mabnaschool.ir/wp-content/uploads/2025/11/Frame-1666952.svg';
                        ?>
                        <img src="<?= esc_url($av_url) ?>" class="tcm-avatar" alt="">
                        <span class="tcm-ta-name"><?= esc_html($r->ta_name) ?></span>
                    </td>

                    <td><span class="tcm-term-badge"><?= esc_html($r->term_name ?: '—') ?></span></td>

                    <td>
                        <span class="tcm-type-badge" style="background:<?= $type_color ?>20;color:<?= $type_color ?>">
                            <?= esc_html( self::COURSE_LABELS[$r->course_type] ?? $r->course_type ) ?>
                        </span>
                    </td>

                    <td><span class="tcm-muted-badge"><?= self::GENDER_LABELS[$r->student_gender] ?? '—' ?></span></td>

                    <td>
                        <span class="tcm-msg-badge">
                            <?= self::MESSENGER_ICONS[$r->messenger] ?? '📱' ?>
                            <?= self::MESSENGER_LABELS[$r->messenger] ?? '—' ?>
                        </span>
                    </td>

                    <td class="tcm-cap-cell">
                        <div class="tcm-cap-nums">
                            <span class="tcm-cap-used"><?= $used ?></span>
                            <span class="tcm-cap-sep">/</span>
                            <span class="tcm-cap-total"><?= $total ?></span>
                        </div>
                        <div class="tcm-mini-bar">
                            <div class="tcm-mini-fill" style="width:<?= $pct ?>%;background:<?= $bar_col ?>"></div>
                        </div>
                    </td>

                    <td>
                        <span class="tcm-pct-badge" style="background:<?= $bar_col ?>20;color:<?= $bar_col ?>"><?= $pct ?>%</span>
                        <?php if ($free > 0) : ?>
                            <span class="tcm-free-badge"><?= $free ?> خالی</span>
                        <?php else : ?>
                            <span class="tcm-full-badge">پر</span>
                        <?php endif; ?>
                    </td>

                    <td class="tcm-grp-cell">
                        <?php if ( in_array( $r->course_type, ['creative','basic_writing'], true ) ) : ?>
                            <button class="tcm-btn tcm-btn-groups"
                                    onclick="tcmOpenGroups(<?= $r->id ?>, '<?= esc_js($r->ta_name) ?>')"
                                    title="مدیریت گروه‌ها">
                                📁 <?= $grp_free ?>/<?= $grp_cnt ?>
                            </button>
                        <?php elseif ( $r->used_capacity > 0 ) : ?>
                            <button class="tcm-btn tcm-btn-groups tcm-btn-groups-info"
                                    onclick="tcmOpenAssignments(<?= $r->id ?>, '<?= esc_js($r->ta_name) ?>')"
                                    title="مشاهده هنرجویان">
                                👥 <?= $used ?>
                            </button>
                        <?php else : ?>
                            <span class="tcm-muted">—</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <div class="tcm-actions">
                            <button class="tcm-btn tcm-btn-edit" onclick="tcmOpenEdit(<?= $edit_data ?>)" title="ویرایش">✏️</button>
                            <a href="<?= esc_url($del_url) ?>" class="tcm-btn tcm-btn-delete"
                               onclick="return confirm('حذف این ظرفیت؟')" title="حذف">🗑</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <?php if ( ! empty($results) ) :
                $r_total = array_sum( array_column($results,'total_capacity') );
                $r_used  = array_sum( array_column($results,'used_capacity') );
            ?>
            <tfoot>
                <tr class="tcm-foot">
                    <td colspan="6" class="tcm-foot-label">جمع فیلتر فعلی:</td>
                    <td><strong><?= $r_used ?> / <?= $r_total ?></strong></td>
                    <td><strong><?= $r_total > 0 ? round($r_used/$r_total*100) : 0 ?>%</strong></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- ── Modal افزودن ظرفیت ───────────────────────────────────────────── -->
<div id="tcm-add-modal" class="tcm-modal-overlay" style="display:none">
    <div class="tcm-modal">
        <div class="tcm-modal-header"><h2>＋ تعریف ظرفیت جدید</h2><button class="tcm-modal-close" type="button" onclick="tcmCloseAdd()">✕</button></div>
        <form method="POST">
            <?php wp_nonce_field( 'tcm_add_capacity', 'tcm_add_nonce' ); ?>
            <div class="tcm-modal-body"><div class="tcm-form-grid">
                <div class="tcm-form-group tcm-form-group-full"><label>استادیار</label><select name="ta_id" class="tcm-select tcm-select-full" required><option value="">انتخاب استادیار</option><?php foreach ( $all_tas as $ta ) : ?><option value="<?= (int) $ta->id ?>"><?= esc_html($ta->display_name) ?></option><?php endforeach; ?></select></div>
                <div class="tcm-form-group"><label>ترم</label><select name="term_id" class="tcm-select tcm-select-full" required><option value="">انتخاب ترم</option><?php foreach ( $terms as $t ) : ?><option value="<?= (int) $t->id ?>"><?= esc_html($t->name) ?></option><?php endforeach; ?></select></div>
                <div class="tcm-form-group"><label>نوع دوره</label><select name="course_type" class="tcm-select tcm-select-full" required><?php foreach ( self::COURSE_LABELS as $k => $v ) : ?><option value="<?= esc_attr($k) ?>"><?= esc_html($v) ?></option><?php endforeach; ?></select></div>
                <div class="tcm-form-group"><label>جنسیت</label><select name="student_gender" class="tcm-select tcm-select-full"><?php foreach ( self::GENDER_LABELS as $k => $v ) : ?><option value="<?= esc_attr($k) ?>"><?= esc_html($v) ?></option><?php endforeach; ?></select></div>
                <div class="tcm-form-group"><label>پیامرسان</label><select name="messenger" class="tcm-select tcm-select-full"><option value="both">📱 همه</option><?php foreach ( $this->get_active_messenger_labels() as $k => $v ) : ?><option value="<?= esc_attr($k) ?>"><?= esc_html($v) ?></option><?php endforeach; ?></select></div>
                <div class="tcm-form-group"><label>ظرفیت کل</label><input type="number" name="total_capacity" class="tcm-input" min="0" value="0" required></div>
            </div></div>
            <div class="tcm-modal-footer"><button type="submit" class="tcm-btn tcm-btn-save">ایجاد ظرفیت</button><button type="button" class="tcm-btn tcm-btn-cancel" onclick="tcmCloseAdd()">انصراف</button></div>
        </form>
    </div>
</div>

<!-- ── Modal ویرایش ───────────────────────────────────────────── -->
<div id="tcm-edit-modal" class="tcm-modal-overlay" style="display:none">
    <div class="tcm-modal">
        <div class="tcm-modal-header">
            <h2>✏️ ویرایش ظرفیت</h2>
            <button class="tcm-modal-close" onclick="tcmCloseEdit()">✕</button>
        </div>
        <form method="POST" id="tcm-edit-form">
            <?php wp_nonce_field( 'tcm_edit_capacity', 'tcm_edit_nonce' ); ?>
            <input type="hidden" name="cap_id" id="tcm-edit-id">
            <div class="tcm-modal-body">
                <div class="tcm-modal-ta-info" id="tcm-edit-tainfo"></div>
                <div class="tcm-form-grid">
                    <div class="tcm-form-group">
                        <label>ترم</label>
                        <select name="term_id" id="tcm-edit-term" class="tcm-select tcm-select-full">
                            <?php foreach ( $terms as $t ) : ?>
                                <option value="<?= $t->id ?>"><?= esc_html($t->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tcm-form-group">
                        <label>نوع دوره</label>
                        <select name="course_type" id="tcm-edit-type" class="tcm-select tcm-select-full">
                            <?php foreach ( self::COURSE_LABELS as $k => $v ) : ?>
                                <option value="<?= $k ?>"><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tcm-form-group">
                        <label>جنسیت</label>
                        <select name="student_gender" id="tcm-edit-gender" class="tcm-select tcm-select-full">
                            <?php foreach ( self::GENDER_LABELS as $k => $v ) : ?>
                                <option value="<?= $k ?>"><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tcm-form-group">
                        <label>پیامرسان</label>
                        <select name="messenger" id="tcm-edit-msg" class="tcm-select tcm-select-full">
                            <option value="both">📱 همه</option>
                            <?php foreach ( $this->get_active_messenger_labels() as $k => $v ) : ?>
                                <option value="<?= $k ?>"><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tcm-form-group tcm-form-group-full">
                        <label>ظرفیت کل</label>
                        <input type="number" name="total_capacity" id="tcm-edit-total"
                               class="tcm-input" min="0" style="width:140px">
                        <span class="tcm-input-hint" id="tcm-edit-used-hint"></span>
                    </div>
                </div>
            </div>
            <div class="tcm-modal-footer">
                <button type="submit" class="tcm-btn tcm-btn-save">ذخیره</button>
                <button type="button" class="tcm-btn tcm-btn-cancel" onclick="tcmCloseEdit()">انصراف</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Modal گروه‌های خلاق ────────────────────────────────────── -->
<div id="tcm-groups-modal" class="tcm-modal-overlay" style="display:none">
    <div class="tcm-modal tcm-modal-wide">
        <div class="tcm-modal-header">
            <h2>📁 گروه‌های ظرفیت</h2>
            <button class="tcm-modal-close" onclick="tcmCloseGroups()">✕</button>
        </div>
        <div class="tcm-modal-body" id="tcm-groups-body">
            <div class="tcm-loading">در حال بارگذاری...</div>
        </div>
        <div class="tcm-modal-footer tcm-groups-footer">
            <button class="tcm-btn tcm-btn-save" onclick="tcmShowAddGroup()">+ افزودن گروه</button>
            <button class="tcm-btn tcm-btn-cancel" onclick="tcmCloseGroups()">بستن</button>
        </div>
        <!-- فرم افزودن گروه -->
        <div id="tcm-add-group-form" style="display:none" class="tcm-add-group-form">
            <div class="tcm-form-grid">
                <div class="tcm-form-group">
                    <label>پیامرسان</label>
                    <select id="tcm-add-msg" class="tcm-select tcm-select-full">
                        <?php foreach ( $this->get_active_messenger_labels() as $k => $v ) : ?>
                            <option value="<?= $k ?>"><?= self::MESSENGER_ICONS[$k] ?? '📱' ?> <?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tcm-form-group">
                    <label>لینک گروه</label>
                    <input type="url" id="tcm-add-link" class="tcm-input tcm-select-full" placeholder="https://t.me/...">
                </div>
            </div>
            <div style="margin-top:10px;display:flex;gap:8px">
                <button class="tcm-btn tcm-btn-save" onclick="tcmSubmitAddGroup()">ثبت گروه</button>
                <button class="tcm-btn tcm-btn-cancel" onclick="document.getElementById('tcm-add-group-form').style.display='none'">انصراف</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal هنرجویان ────────────────────────────────────────── -->
<div id="tcm-assign-modal" class="tcm-modal-overlay" style="display:none">
    <div class="tcm-modal tcm-modal-wide">
        <div class="tcm-modal-header">
            <h2>👥 هنرجویان این ظرفیت</h2>
            <button class="tcm-modal-close" onclick="document.getElementById('tcm-assign-modal').style.display='none'">✕</button>
        </div>
        <div class="tcm-modal-body" id="tcm-assign-body">
            <div class="tcm-loading">در حال بارگذاری...</div>
        </div>
        <div class="tcm-modal-footer">
            <button class="tcm-btn tcm-btn-cancel" onclick="document.getElementById('tcm-assign-modal').style.display='none'">بستن</button>
        </div>
    </div>
</div>

<?php $this->render_scripts( $type_dist ); ?>
</div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CSS
    // ─────────────────────────────────────────────────────────────────────────

    private function render_styles(): void { ?>
<style>
:root{
    --tcm-radius:12px;--tcm-shadow:0 1px 3px rgba(0,0,0,.08);
    --tcm-shadow-md:0 4px 16px rgba(0,0,0,.1);
    --tcm-border:#e5e7eb;--tcm-text:#111827;--tcm-muted:#6b7280;
    --tcm-bg:#f9fafb;--tcm-white:#fff;
}
.tcm-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Tahoma,sans-serif;direction:rtl;color:var(--tcm-text);max-width:1600px}
/* نوتیس */
.tcm-notice{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;font-weight:500}
.tcm-notice-success{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.tcm-notice-danger{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
/* هدر */
.tcm-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding:18px 22px;background:var(--tcm-white);border-radius:var(--tcm-radius);box-shadow:var(--tcm-shadow)}
.tcm-header-title{display:flex;align-items:center;gap:14px}
.tcm-header-icon{font-size:34px;line-height:1}
.tcm-header h1{font-size:21px;font-weight:700;margin:0 0 3px;color:var(--tcm-text)}
.tcm-header p{margin:0;color:var(--tcm-muted);font-size:13px}
.tcm-header-actions{display:flex;gap:10px}
/* آمار */
.tcm-stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:18px}
@media(max-width:1100px){.tcm-stats-grid{grid-template-columns:repeat(3,1fr)}}
.tcm-stat-card{background:var(--tcm-white);border-radius:var(--tcm-radius);padding:16px;box-shadow:var(--tcm-shadow);transition:transform .15s,box-shadow .15s}
.tcm-stat-card:hover{transform:translateY(-2px);box-shadow:var(--tcm-shadow-md)}
.tcm-stat-icon{font-size:26px;line-height:1;margin-bottom:8px}
.tcm-stat-number{font-size:26px;font-weight:800;line-height:1;margin-bottom:3px}
.tcm-stat-label{font-size:12px;color:var(--tcm-muted);font-weight:500}
.tcm-stat-sub{font-size:11px;color:var(--tcm-muted);margin-top:6px}
.tcm-stat-total .tcm-stat-number{color:#3b82f6}
.tcm-stat-used  .tcm-stat-number{color:#f59e0b}
.tcm-stat-free  .tcm-stat-number{color:#10b981}
.tcm-stat-full  .tcm-stat-number{color:#ef4444}
.tcm-stat-rate  .tcm-stat-number{color:#8b5cf6}
.tcm-progress-bar{height:4px;background:#f3f4f6;border-radius:99px;overflow:hidden;margin-top:4px}
.tcm-progress-fill{height:100%;border-radius:99px}
/* نمودار */
.tcm-chart-row{display:grid;grid-template-columns:1fr 300px;gap:12px;margin-bottom:18px}
@media(max-width:1000px){.tcm-chart-row{grid-template-columns:1fr}}
.tcm-card{background:var(--tcm-white);border-radius:var(--tcm-radius);box-shadow:var(--tcm-shadow);overflow:hidden}
.tcm-card-header{padding:12px 16px;border-bottom:1px solid var(--tcm-border)}
.tcm-card-title{font-size:13px;font-weight:600}
.tcm-chart-card canvas{padding:14px;display:block}
.tcm-donut-card{display:flex;flex-direction:column}
.tcm-donut-wrap{display:flex;justify-content:center;padding:14px 14px 6px}
.tcm-donut-wrap canvas{max-width:130px;max-height:130px}
.tcm-donut-legend{padding:0 14px 14px;flex:1}
.tcm-legend-item{display:flex;align-items:center;gap:7px;padding:4px 0;font-size:12px}
.tcm-legend-item strong{margin-right:auto;font-weight:700}
.tcm-legend-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
/* فیلتر */
.tcm-filter-card{padding:14px;margin-bottom:16px}
.tcm-filter-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
/* دکمه */
.tcm-btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:8px;border:none;font-size:13px;font-weight:500;cursor:pointer;text-decoration:none;transition:all .15s;font-family:inherit;line-height:1}
.tcm-btn-add{background:#0ea5e9;color:#fff}.tcm-btn-add:hover{background:#0284c7}
.tcm-btn-sync{background:#6366f1;color:#fff}.tcm-btn-sync:hover{background:#4f46e5}
.tcm-btn-export{background:#10b981;color:#fff}.tcm-btn-export:hover{background:#059669}
.tcm-btn-filter{background:#6366f1;color:#fff}.tcm-btn-filter:hover{background:#4f46e5}
.tcm-btn-reset{background:#f3f4f6;color:var(--tcm-text)}.tcm-btn-reset:hover{background:#e5e7eb}
.tcm-btn-bulk{background:#374151;color:#fff;padding:5px 12px;font-size:12px}
.tcm-btn-edit{background:transparent;padding:4px 7px;font-size:15px;border-radius:6px}.tcm-btn-edit:hover{background:#f3f4f6}
.tcm-btn-delete{background:transparent;padding:4px 7px;font-size:15px;border-radius:6px;color:inherit;text-decoration:none}.tcm-btn-delete:hover{background:#fee2e2}
.tcm-btn-groups{background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;padding:4px 10px;font-size:12px}.tcm-btn-groups:hover{background:#dbeafe}
.tcm-btn-groups-info{background:#f0fdf4;color:#15803d;border-color:#bbf7d0}.tcm-btn-groups-info:hover{background:#dcfce7}
.tcm-btn-save{background:#6366f1;color:#fff;padding:9px 22px}.tcm-btn-save:hover{background:#4f46e5}
.tcm-btn-cancel{background:#f3f4f6;color:var(--tcm-text)}.tcm-btn-cancel:hover{background:#e5e7eb}
/* select/input */
.tcm-select{padding:7px 10px;border:1px solid var(--tcm-border);border-radius:8px;font-size:13px;background:var(--tcm-white);font-family:inherit;cursor:pointer;outline:none}
.tcm-select:focus{border-color:#6366f1}
.tcm-select-full{width:100%}
.tcm-select-sm{padding:5px 8px;font-size:12px}
.tcm-input{padding:7px 10px;border:1px solid var(--tcm-border);border-radius:8px;font-size:13px;font-family:inherit;outline:none}
.tcm-input:focus{border-color:#6366f1}
.tcm-input-hint{font-size:12px;color:var(--tcm-muted);margin-right:8px}
/* جدول */
.tcm-table-header{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--tcm-border);gap:10px;flex-wrap:wrap}
.tcm-table-info{font-size:13px;color:var(--tcm-muted)}
.tcm-table-info strong{color:var(--tcm-text)}
.tcm-filter-badge{background:#ede9fe;color:#7c3aed;font-size:11px;padding:2px 8px;border-radius:99px;margin-right:6px}
.tcm-bulk-bar{display:flex;gap:8px;align-items:center}
.tcm-table-wrap{overflow-x:auto}
.tcm-table{width:100%;border-collapse:collapse;font-size:13px}
.tcm-table thead th{background:#f9fafb;padding:9px 11px;text-align:right;font-size:11px;font-weight:700;color:var(--tcm-muted);text-transform:uppercase;border-bottom:1px solid var(--tcm-border);white-space:nowrap}
.tcm-th-check{width:34px}
.tcm-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .1s}
.tcm-table tbody tr:hover{background:#fafafa}
.tcm-table tbody td{padding:9px 11px;vertical-align:middle}
.tcm-row-full{background:#fff5f5}
.tcm-ta-cell{display:flex;align-items:center;gap:8px;min-width:130px}
.tcm-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;flex-shrink:0}
.tcm-ta-name{font-weight:600;font-size:13px}
.tcm-term-badge{background:#eff6ff;color:#1d4ed8;font-size:11px;padding:2px 8px;border-radius:99px;white-space:nowrap}
.tcm-type-badge{display:inline-block;padding:3px 9px;border-radius:99px;font-size:12px;font-weight:600;white-space:nowrap}
.tcm-muted-badge{font-size:12px;color:var(--tcm-muted)}
.tcm-msg-badge{font-size:12px;white-space:nowrap}
.tcm-cap-cell{min-width:100px}
.tcm-cap-nums{display:flex;align-items:baseline;gap:3px;margin-bottom:4px}
.tcm-cap-used{font-size:16px;font-weight:700}
.tcm-cap-sep{color:var(--tcm-muted);font-size:12px}
.tcm-cap-total{font-size:13px;color:var(--tcm-muted)}
.tcm-mini-bar{height:4px;background:#e5e7eb;border-radius:99px;overflow:hidden;width:80px}
.tcm-mini-fill{height:100%;border-radius:99px;transition:width .6s ease}
.tcm-pct-badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700}
.tcm-free-badge{font-size:11px;color:#059669;background:#d1fae5;padding:2px 7px;border-radius:99px;margin-right:4px}
.tcm-full-badge{font-size:11px;color:#dc2626;background:#fee2e2;padding:2px 7px;border-radius:99px;margin-right:4px}
.tcm-grp-cell{white-space:nowrap}
.tcm-muted{color:#d1d5db;font-size:13px}
.tcm-actions{display:flex;gap:3px}
.tcm-empty{padding:40px 0!important;text-align:center}
.tcm-empty-state{display:flex;flex-direction:column;align-items:center;gap:7px;font-size:14px;color:var(--tcm-muted)}
.tcm-empty-state div{font-size:36px}
/* foot */
.tcm-foot td{padding:10px 11px;background:#f9fafb;border-top:2px solid var(--tcm-border);font-size:13px}
.tcm-foot-label{color:var(--tcm-muted);font-weight:600}
/* modal */
.tcm-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(3px)}
.tcm-modal{background:var(--tcm-white);border-radius:16px;width:100%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,.3);animation:tcmUp .2s ease;max-height:90vh;display:flex;flex-direction:column}
.tcm-modal-wide{max-width:680px}
@keyframes tcmUp{from{transform:translateY(16px);opacity:0}to{transform:translateY(0);opacity:1}}
.tcm-modal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid var(--tcm-border);flex-shrink:0}
.tcm-modal-header h2{margin:0;font-size:15px;font-weight:700}
.tcm-modal-close{background:none;border:none;font-size:17px;cursor:pointer;color:var(--tcm-muted);width:26px;height:26px;border-radius:6px;display:flex;align-items:center;justify-content:center}.tcm-modal-close:hover{background:#f3f4f6}
.tcm-modal-body{padding:18px;overflow-y:auto;flex:1}
.tcm-modal-ta-info{background:#f9fafb;border-radius:8px;padding:10px 12px;margin-bottom:16px;font-size:13px;font-weight:600;color:var(--tcm-text)}
.tcm-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.tcm-form-group{display:flex;flex-direction:column;gap:5px}
.tcm-form-group label{font-size:11px;font-weight:700;color:var(--tcm-muted);text-transform:uppercase;letter-spacing:.5px}
.tcm-form-group-full{grid-column:1/-1;flex-direction:row;align-items:center;gap:10px}
.tcm-form-group-full label{min-width:80px}
.tcm-modal-footer{display:flex;gap:8px;padding:14px 18px;border-top:1px solid var(--tcm-border);flex-shrink:0}
.tcm-groups-footer{justify-content:space-between}
.tcm-loading{text-align:center;padding:32px;color:var(--tcm-muted);font-size:13px}
.tcm-add-group-form{padding:14px 18px;background:#f9fafb;border-top:1px solid var(--tcm-border)}
/* جدول گروه‌ها در modal */
.tcm-inner-table{width:100%;border-collapse:collapse;font-size:13px}
.tcm-inner-table th{background:#f9fafb;padding:8px 10px;font-size:11px;font-weight:700;color:var(--tcm-muted);text-align:right;border-bottom:1px solid var(--tcm-border)}
.tcm-inner-table td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
.tcm-inner-table tr:last-child td{border-bottom:none}
.tcm-grp-used-badge{background:#d1fae5;color:#065f46;font-size:11px;padding:2px 7px;border-radius:99px}
.tcm-grp-free-badge{background:#f3f4f6;color:var(--tcm-muted);font-size:11px;padding:2px 7px;border-radius:99px}
.tcm-row.selected{background:#f5f3ff!important}
</style>
    <?php }

    // ─────────────────────────────────────────────────────────────────────────
    // JavaScript
    // ─────────────────────────────────────────────────────────────────────────

    private function render_scripts( array $type_dist ): void {
        $chart_labels  = [];
        $chart_total   = [];
        $chart_used    = [];
        $chart_colors  = [];

        foreach ( $type_dist as $td ) {
            $chart_labels[] = self::COURSE_LABELS[ $td->course_type ] ?? $td->course_type;
            $chart_total[]  = (int) $td->total;
            $chart_used[]   = (int) $td->used;
            $chart_colors[] = self::COURSE_COLORS[ $td->course_type ] ?? '#94a3b8';
        }
        ?>
<script>
document.addEventListener('DOMContentLoaded', function() {

    // ── Bar Chart ──────────────────────────────────────────────────────────
    const barCtx = document.getElementById('tcmBarChart');
    if (barCtx && <?= json_encode(!empty($chart_labels)) ?>) {
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [
                    { label: 'کل ظرفیت',       data: <?= json_encode($chart_total) ?>, backgroundColor: <?= json_encode(array_map(fn($c)=>$c.'30', $chart_colors)) ?>, borderColor: <?= json_encode($chart_colors) ?>, borderWidth: 2, borderRadius: 6 },
                    { label: 'استفاده‌شده', data: <?= json_encode($chart_used) ?>,  backgroundColor: <?= json_encode($chart_colors) ?>, borderRadius: 6 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: true,
                plugins: { legend: { labels: { font: { family: 'Tahoma', size: 11 }, boxWidth: 12 } } },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { family: 'Tahoma', size: 11 } } },
                    y: { beginAtZero: true, ticks: { font: { family: 'Tahoma', size: 11 } }, grid: { color: '#f3f4f6' } }
                }
            }
        });
    }

    // ── Donut Chart ────────────────────────────────────────────────────────
    const donutCtx = document.getElementById('tcmDonutChart');
    if (donutCtx && <?= json_encode(!empty($chart_labels)) ?>) {
        new Chart(donutCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [{ data: <?= json_encode($chart_total) ?>, backgroundColor: <?= json_encode($chart_colors) ?>, borderWidth: 2, borderColor: '#fff', hoverOffset: 4 }]
            },
            options: { responsive: true, maintainAspectRatio: true, cutout: '70%', plugins: { legend: { display: false } } }
        });
    }
});

// ── Modal افزودن ────────────────────────────────────────────────────────────
function tcmOpenAdd() { document.getElementById('tcm-add-modal').style.display = 'flex'; document.body.style.overflow = 'hidden'; }
function tcmCloseAdd() { document.getElementById('tcm-add-modal').style.display = 'none'; document.body.style.overflow = ''; }
document.getElementById('tcm-add-modal').addEventListener('click', e => { if (e.target === document.getElementById('tcm-add-modal')) tcmCloseAdd(); });

// ── Modal ویرایش ────────────────────────────────────────────────────────────
function tcmOpenEdit(data) {
    document.getElementById('tcm-edit-id').value       = data.id;
    document.getElementById('tcm-edit-term').value     = data.term_id;
    document.getElementById('tcm-edit-type').value     = data.course_type;
    document.getElementById('tcm-edit-gender').value   = data.student_gender;
    document.getElementById('tcm-edit-msg').value      = data.messenger;
    document.getElementById('tcm-edit-total').value    = data.total_capacity;
    document.getElementById('tcm-edit-tainfo').textContent = '👤 ' + data.ta_name + '  |  📅 ' + data.term_name;
    document.getElementById('tcm-edit-used-hint').textContent = '(فعلی: ' + data.total_capacity + ' ظرفیت)';
    document.getElementById('tcm-edit-modal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function tcmCloseEdit() {
    document.getElementById('tcm-edit-modal').style.display = 'none';
    document.body.style.overflow = '';
}
document.getElementById('tcm-edit-modal').addEventListener('click', e => { if (e.target === document.getElementById('tcm-edit-modal')) tcmCloseEdit(); });

// ── Modal گروه‌ها ────────────────────────────────────────────────────────────
let _tcmCapId = null;

function tcmOpenGroups(capId, taName) {
    _tcmCapId = capId;
    document.getElementById('tcm-add-group-form').style.display = 'none';
    document.getElementById('tcm-groups-modal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    tcmLoadGroups(capId, taName);
}
function tcmCloseGroups() {
    document.getElementById('tcm-groups-modal').style.display = 'none';
    document.body.style.overflow = '';
    _tcmCapId = null;
}
document.getElementById('tcm-groups-modal').addEventListener('click', e => { if (e.target === document.getElementById('tcm-groups-modal')) tcmCloseGroups(); });

function tcmLoadGroups(capId) {
    const body = document.getElementById('tcm-groups-body');
    body.innerHTML = '<div class="tcm-loading">در حال بارگذاری...</div>';
    jQuery.post(taSystem.ajaxUrl, {
        action: 'ta_get_capacity_groups', nonce: taSystem.nonce, capacity_id: capId
    }, function(res) {
        if (!res.success || !res.data.length) {
            body.innerHTML = '<p style="color:#9ca3af;text-align:center;padding:24px">هیچ گروهی ثبت نشده است.</p>';
            return;
        }
        let html = '<table class="tcm-inner-table"><thead><tr><th>#</th><th>پیامرسان</th><th>لینک</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
        const icons  = Object.assign({}, taSystem.messengerIcons  || {telegram:'✈',bale:'🔵',eitaa:'🟢'});
        const labels = Object.assign({}, taSystem.messengerLabels || {telegram:'تلگرام',bale:'بله',eitaa:'ایتا'});
        res.data.forEach((g, i) => {
            const used = g.is_used == 1;
            html += `<tr>
                <td>${i+1}</td>
                <td>${icons[g.messenger_type]||'📱'} ${labels[g.messenger_type]||g.messenger_type}</td>
                <td>${g.group_link ? '<a href="'+g.group_link+'" target="_blank">مشاهده ↗</a>' : '—'}</td>
                <td>${used ? '<span class="tcm-grp-used-badge">استفاده‌شده</span>' : '<span class="tcm-grp-free-badge">آزاد</span>'}</td>
                <td>
                    ${!used ? `<button class="tcm-btn tcm-btn-edit" onclick="tcmDeleteGroup(${g.id})" title="حذف">🗑</button>` : ''}
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        body.innerHTML = html;
    });
}

function tcmShowAddGroup() {
    const f = document.getElementById('tcm-add-group-form');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}

function tcmSubmitAddGroup() {
    const msg  = document.getElementById('tcm-add-msg').value;
    const link = document.getElementById('tcm-add-link').value;
    if (!link) { alert('لینک گروه را وارد کنید.'); return; }
    jQuery.post(taSystem.ajaxUrl, {
        action: 'ta_add_group_slot', nonce: taSystem.nonce,
        capacity_id: _tcmCapId, messenger_type: msg, group_link: link
    }, function(res) {
        if (res.success) {
            document.getElementById('tcm-add-link').value = '';
            document.getElementById('tcm-add-group-form').style.display = 'none';
            tcmLoadGroups(_tcmCapId);
        } else {
            alert(res.data?.message || 'خطا');
        }
    });
}

function tcmDeleteGroup(groupId) {
    if (!confirm('حذف این گروه؟')) return;
    jQuery.post(taSystem.ajaxUrl, {
        action: 'ta_delete_group_slot', nonce: taSystem.nonce, group_id: groupId
    }, function(res) {
        if (res.success) tcmLoadGroups(_tcmCapId);
        else alert(res.data?.message || 'خطا');
    });
}

// ── Modal هنرجویان ───────────────────────────────────────────────────────────
function tcmOpenAssignments(capId, taName) {
    document.getElementById('tcm-assign-modal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    const body = document.getElementById('tcm-assign-body');
    body.innerHTML = '<div class="tcm-loading">در حال بارگذاری...</div>';
    jQuery.post(taSystem.ajaxUrl, {
        action: 'ta_get_capacity_assignments', nonce: taSystem.adminNonce, capacity_id: capId
    }, function(res) {
        if (!res.success || !res.data.length) {
            body.innerHTML = '<p style="color:#9ca3af;text-align:center;padding:24px">هنرجویی یافت نشد.</p>';
            return;
        }
        const icons  = Object.assign({}, taSystem.messengerIcons  || {telegram:'✈',bale:'🔵',eitaa:'🟢'});
        const labels = Object.assign({}, taSystem.messengerLabels || {telegram:'تلگرام',bale:'بله',eitaa:'ایتا'});
        let html = '<table class="tcm-inner-table"><thead><tr><th>نام هنرجو</th><th>پیامرسان</th><th>لینک گروه</th><th>وضعیت</th></tr></thead><tbody>';
        res.data.forEach(a => {
            html += `<tr>
                <td>${a.student_name}</td>
                <td>${icons[a.messenger]||'📱'} ${a.messenger}</td>
                <td>${a.group_link ? '<a href="'+a.group_link+'" target="_blank">مشاهده ↗</a>' : '—'}</td>
                <td><span class="tcm-grp-used-badge">${a.status}</span></td>
            </tr>`;
        });
        html += '</tbody></table>';
        body.innerHTML = html;
    });
}
document.getElementById('tcm-assign-modal').addEventListener('click', e => { if (e.target === document.getElementById('tcm-assign-modal')) { document.getElementById('tcm-assign-modal').style.display='none'; document.body.style.overflow=''; } });

// ── انتخاب همه ──────────────────────────────────────────────────────────────
function tcmToggleAll(cb) {
    document.querySelectorAll('.tcm-row-check').forEach(c => {
        c.checked = cb.checked;
        c.closest('tr').classList.toggle('selected', cb.checked);
    });
}
document.querySelectorAll('.tcm-row-check').forEach(cb => {
    cb.addEventListener('change', function() {
        this.closest('tr').classList.toggle('selected', this.checked);
    });
});

// ── تأیید bulk ──────────────────────────────────────────────────────────────
function confirmBulk(btn) {
    const action  = document.querySelector('[name=bulk_action]').value;
    const checked = document.querySelectorAll('.tcm-row-check:checked').length;
    if (!action) { alert('عملیات را انتخاب کنید.'); return false; }
    if (!checked) { alert('حداقل یک ردیف انتخاب کنید.'); return false; }
    return confirm('حذف ' + checked + ' ظرفیت؟ این عمل قابل بازگشت نیست.');
}

// بستن با Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        tcmCloseEdit();
        tcmCloseGroups();
        document.getElementById('tcm-assign-modal').style.display = 'none';
        document.body.style.overflow = '';
    }
});
</script>
        <?php
    }
}
