<?php
defined( 'ABSPATH' ) || exit;

/**
 * مدیریت حرفه‌ای نمره‌های هنرجویان
 * ویژگی‌ها: داشبورد آماری، فیلتر پیشرفته، ویرایش سریع، عملیات گروهی، خروجی CSV
 */
class TA_Grades_Manager {

    // ── تعریف انواع نمره و رنگ‌ها ────────────────────────────────────────────
    // نمرات دینامیک بر اساس نوع دوره — از TA_Grade::all_grades() بارگذاری می‌شود

    private const COURSE_TYPES = [
        'creative'      => 'نویسندگی خلاق',
        'intro'         => 'نویسندگی مقدماتی',
        'basic_writing' => 'نویسندگی پایه',
        'advanced'      => 'نویسندگی پیشرفته',
        'ladder'        => 'نردبان حرفه‌ای',
        'professional'  => 'نویسندگی حرفه‌ای',
        'critique'      => 'نقد اثر',
    ];

    private const PER_PAGE = 25;

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'دسترسی غیرمجاز.' );
        }

        // پردازش اکشن‌های POST
        $this->handle_post_actions();

        global $wpdb;
        $grade_tbl  = TA_Database::table( 'grades' );
        $ta_tbl     = TA_Database::table( 'assistants' );
        $term_tbl   = TA_Database::table( 'terms' );

        // ── فیلترها ──────────────────────────────────────────────────────────
        $filter_term    = absint( $_GET['filter_term']    ?? 0 );
        $filter_type    = sanitize_text_field( $_GET['filter_type']    ?? '' );
        $filter_grade   = sanitize_text_field( $_GET['filter_grade']   ?? '' );
        $filter_ta      = absint( $_GET['filter_ta']      ?? 0 );
        $filter_product = absint( $_GET['filter_product'] ?? 0 );
        $filter_search  = sanitize_text_field( $_GET['search']         ?? '' );
        $sort_col       = sanitize_key( $_GET['orderby'] ?? 'created_at' );
        $sort_dir       = strtoupper( sanitize_text_field( $_GET['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';
        $paged          = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $offset         = ( $paged - 1 ) * self::PER_PAGE;

        $allowed_sort = [ 'created_at', 'updated_at', 'grade', 'student_name', 'product_name', 'term_name', 'ta_name' ];
        if ( ! in_array( $sort_col, $allowed_sort, true ) ) $sort_col = 'created_at';

        // ── ساخت WHERE ───────────────────────────────────────────────────────
        $where   = [ '1=1' ];
        $params  = [];

        if ( $filter_term ) {
            $where[]  = 'g.term_id = %d';
            $params[] = $filter_term;
        }
        if ( $filter_type ) {
            $where[]  = 'g.course_type = %s';
            $params[] = $filter_type;
        }
        if ( $filter_grade ) {
            $where[]  = 'g.grade = %s';
            $params[] = $filter_grade;
        }
        if ( $filter_ta ) {
            $where[]  = 'g.ta_id = %d';
            $params[] = $filter_ta;
        }
        if ( $filter_product ) {
            $where[]  = 'g.product_id = %d';
            $params[] = $filter_product;
        }
        if ( $filter_search ) {
            $like     = '%' . $wpdb->esc_like( $filter_search ) . '%';
            $where[]  = '(u.display_name LIKE %s OR u.user_email LIKE %s OR p.post_title LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where );

        $base_sql = "
            FROM {$grade_tbl} g
            JOIN {$wpdb->users}   u  ON u.ID      = g.student_id
            JOIN {$ta_tbl}        ta ON ta.id      = g.ta_id
            JOIN {$wpdb->users}   tu ON tu.ID      = ta.user_id
            LEFT JOIN {$term_tbl} t  ON t.id       = g.term_id
            LEFT JOIN {$wpdb->posts} p ON p.ID     = g.product_id
            WHERE {$where_sql}
        ";

        // تعداد کل
        $total_filtered = (int) ( $params
            ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$base_sql}", ...$params ) )
            : $wpdb->get_var( "SELECT COUNT(*) {$base_sql}" )
        );

        // آمار بر اساس فیلتر جاری
        $stats_sql = "SELECT g.grade, COUNT(*) as cnt {$base_sql} GROUP BY g.grade";
        $stats_rows = $params
            ? $wpdb->get_results( $wpdb->prepare( $stats_sql, ...$params ) )
            : $wpdb->get_results( $stats_sql );
        $stats = [];
        foreach ( (array) $stats_rows as $row ) {
            $stats[ $row->grade ] = $row;
        }

        $total_all  = array_sum( array_column( (array) $stats, 'cnt' ) );
        $pass_cnt   = (int) ( $stats['pass']->cnt     ?? 0 );
        $repeat_cnt = 0;
        foreach ( $stats as $key => $row ) {
            if ( strpos( $key, 'repeat' ) !== false ) $repeat_cnt += (int) $row->cnt;
        }
        $crit_cnt   = (int) ( $stats['critique']->cnt ?? 0 );
        $pend_cnt   = (int) ( $stats['pending']->cnt  ?? 0 );
        $pass_rate  = $total_all > 0 ? round( $pass_cnt / $total_all * 100 ) : 0;

        // آمار ماه جاری (بر اساس فیلتر جاری)
        $month_where = $where;
        $month_params = $params;
        $month_where[] = 'MONTH(g.created_at) = MONTH(NOW()) AND YEAR(g.created_at) = YEAR(NOW())';
        $month_where_sql = implode( ' AND ', $month_where );
        $month_base_sql = "
            FROM {$grade_tbl} g
            JOIN {$wpdb->users}   u  ON u.ID      = g.student_id
            JOIN {$ta_tbl}        ta ON ta.id      = g.ta_id
            JOIN {$wpdb->users}   tu ON tu.ID      = ta.user_id
            LEFT JOIN {$term_tbl} t  ON t.id       = g.term_id
            LEFT JOIN {$wpdb->posts} p ON p.ID     = g.product_id
            WHERE {$month_where_sql}
        ";
        $this_month_cnt = (int) ( $month_params
            ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$month_base_sql}", ...$month_params ) )
            : $wpdb->get_var( "SELECT COUNT(*) {$month_base_sql}" )
        );

        // ردیف‌های صفحه
        $order_map = [
            'student_name'  => 'u.display_name',
            'product_name'  => 'p.post_title',
            'term_name'     => 't.name',
            'ta_name'       => 'tu.display_name',
            'grade'         => 'g.grade',
            'created_at'    => 'g.created_at',
            'updated_at'    => 'g.updated_at',
        ];
        $order_col = $order_map[ $sort_col ] ?? 'g.created_at';

        $select_sql = "SELECT g.*, u.display_name AS student_name, u.user_email AS student_email,
                tu.display_name AS ta_name, t.name AS term_name, p.post_title AS product_name";

        $rows_sql = "{$select_sql} {$base_sql} ORDER BY {$order_col} {$sort_dir} LIMIT %d OFFSET %d";

        $params_paged = array_merge( $params, [ self::PER_PAGE, $offset ] );
        $rows = $params_paged
            ? $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$params_paged ) )
            : $wpdb->get_results( $wpdb->prepare( "SELECT g.*, u.display_name AS student_name, u.user_email AS student_email, tu.display_name AS ta_name, t.name AS term_name, p.post_title AS product_name {$base_sql} ORDER BY {$order_col} {$sort_dir} LIMIT %d OFFSET %d", self::PER_PAGE, $offset ) );

        // داده‌های فیلتر
        $terms     = TA_Database::get_results( "SELECT id, name FROM {$term_tbl} ORDER BY order_index ASC" );
        $all_tas   = $wpdb->get_results( "SELECT ta.id, u.display_name FROM {$ta_tbl} ta JOIN {$wpdb->users} u ON u.ID = ta.user_id ORDER BY u.display_name ASC" );
        $all_prods = $wpdb->get_results( "SELECT DISTINCT g.product_id, p.post_title FROM {$grade_tbl} g LEFT JOIN {$wpdb->posts} p ON p.ID = g.product_id ORDER BY p.post_title ASC" );

        $total_pages = (int) ceil( $total_filtered / self::PER_PAGE );

        // نمودار توزیع نمره‌ها (ماهانه – ۶ ماه اخیر، بر اساس فیلتر جاری)
        $chart_where   = $where;
        $chart_where[] = "g.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
        $chart_where_sql = implode( ' AND ', $chart_where );
        $chart_base_sql = "
            FROM {$grade_tbl} g
            JOIN {$wpdb->users}   u  ON u.ID      = g.student_id
            JOIN {$ta_tbl}        ta ON ta.id      = g.ta_id
            JOIN {$wpdb->users}   tu ON tu.ID      = ta.user_id
            LEFT JOIN {$term_tbl} t  ON t.id       = g.term_id
            LEFT JOIN {$wpdb->posts} p ON p.ID     = g.product_id
            WHERE {$chart_where_sql}
        ";
        $chart_sql = "SELECT DATE_FORMAT(g.created_at, '%Y-%m') as month, g.grade, COUNT(*) as cnt
                      {$chart_base_sql}
                      GROUP BY month, g.grade ORDER BY month ASC";
        $monthly = $params
            ? $wpdb->get_results( $wpdb->prepare( $chart_sql, ...$params ) )
            : $wpdb->get_results( $chart_sql );

        $this->enqueue_assets();
        $this->render_html( compact(
            'rows','stats','total_all','pass_cnt','repeat_cnt','crit_cnt','pend_cnt',
            'pass_rate','this_month_cnt','total_filtered','total_pages','paged',
            'filter_term','filter_type','filter_grade','filter_ta','filter_product',
            'filter_search','sort_col','sort_dir','terms','all_tas','all_prods','monthly'
        ) );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // پردازش POST (ویرایش / حذف / عملیات گروهی)
    // ─────────────────────────────────────────────────────────────────────────

    private function handle_post_actions(): void {
        // ویرایش نمره
        if ( isset( $_POST['ta_grade_edit_nonce'] ) && wp_verify_nonce( $_POST['ta_grade_edit_nonce'], 'ta_grade_edit' ) ) {
            $id    = absint( $_POST['grade_id'] ?? 0 );
            $grade = sanitize_text_field( $_POST['grade'] ?? '' );
            $notes = sanitize_textarea_field( $_POST['notes'] ?? '' );

            if ( $id && array_key_exists( $grade, TA_Grade::all_grades() ) ) {
                TA_Database::update( 'ta_grades',
                    [ 'grade' => $grade, 'notes' => $notes, 'updated_at' => current_time('mysql') ],
                    [ 'id' => $id ]
                );
                wp_safe_redirect( add_query_arg( 'grade_updated', 1, $this->current_url() ) );
                exit;
            }
        }

        // حذف یک نمره
        if ( isset( $_GET['action'], $_GET['grade_id'], $_GET['_wpnonce'] )
             && $_GET['action'] === 'delete_grade'
             && wp_verify_nonce( $_GET['_wpnonce'], 'delete_grade_' . $_GET['grade_id'] ) ) {
            TA_Database::delete( 'ta_grades', [ 'id' => absint( $_GET['grade_id'] ) ] );
            wp_safe_redirect( add_query_arg( 'grade_deleted', 1, remove_query_arg( ['action','grade_id','_wpnonce'] ) ) );
            exit;
        }

        // عملیات گروهی
        if ( isset( $_POST['bulk_action_nonce'] ) && wp_verify_nonce( $_POST['bulk_action_nonce'], 'ta_bulk_grade_action' ) ) {
            $bulk_action = sanitize_text_field( $_POST['bulk_action'] ?? '' );
            $ids = array_map( 'absint', (array) ( $_POST['grade_ids'] ?? [] ) );

            if ( ! empty( $ids ) ) {
                if ( $bulk_action === 'delete' ) {
                    foreach ( $ids as $id ) {
                        TA_Database::delete( 'ta_grades', [ 'id' => $id ] );
                    }
                    wp_safe_redirect( add_query_arg( 'bulk_deleted', count($ids), $this->current_url() ) );
                    exit;
                }
                if ( array_key_exists( $bulk_action, TA_Grade::all_grades() ) ) {
                    global $wpdb;
                    $ph = implode( ',', array_fill(0, count($ids), '%d') );
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE " . TA_Database::table('grades') . " SET grade = %s, updated_at = %s WHERE id IN ({$ph})",
                        array_merge( [ $bulk_action, current_time('mysql') ], $ids )
                    ) );
                    wp_safe_redirect( add_query_arg( 'bulk_updated', count($ids), $this->current_url() ) );
                    exit;
                }
            }
        }

        // خروجی CSV
        if ( isset( $_GET['action'] ) && in_array( $_GET['action'], [ 'export_csv', 'export_xlsx' ], true )
             && isset( $_GET['_wpnonce'] ) && ( wp_verify_nonce( $_GET['_wpnonce'], 'ta_export_grades_csv' ) || wp_verify_nonce( $_GET['_wpnonce'], 'ta_export_grades_xlsx' ) ) ) {
            $this->export_xlsx();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // خروجی XLSX
    // ─────────────────────────────────────────────────────────────────────────

    public function export_xlsx(): void {
        global $wpdb;
        $grade_tbl = TA_Database::table( 'grades' );
        $ta_tbl    = TA_Database::table( 'assistants' );
        $term_tbl  = TA_Database::table( 'terms' );
        $where = [ '1=1' ]; $params = [];
        $term = absint( $_GET['filter_term'] ?? 0 ); $type = sanitize_key( $_GET['filter_type'] ?? '' ); $grade = sanitize_key( $_GET['filter_grade'] ?? '' ); $ta = absint( $_GET['filter_ta'] ?? 0 ); $product = absint( $_GET['filter_product'] ?? 0 ); $search = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
        if ( $term ) { $where[] = 'g.term_id=%d'; $params[] = $term; } if ( $type ) { $where[] = 'g.course_type=%s'; $params[] = $type; } if ( $grade ) { $where[] = 'g.grade=%s'; $params[] = $grade; } if ( $ta ) { $where[] = 'g.ta_id=%d'; $params[] = $ta; } if ( $product ) { $where[] = 'g.product_id=%d'; $params[] = $product; }
        if ( $search ) { $like = '%' . $wpdb->esc_like( $search ) . '%'; $where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR p.post_title LIKE %s)'; array_push( $params, $like, $like, $like ); }
        $sql = "SELECT g.*, u.display_name AS student_name, u.user_email AS student_email, tu.display_name AS ta_name, t.name AS term_name, p.post_title AS product_name FROM {$grade_tbl} g JOIN {$wpdb->users} u ON u.ID=g.student_id JOIN {$ta_tbl} ta ON ta.id=g.ta_id JOIN {$wpdb->users} tu ON tu.ID=ta.user_id LEFT JOIN {$term_tbl} t ON t.id=g.term_id LEFT JOIN {$wpdb->posts} p ON p.ID=g.product_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY g.created_at DESC';
        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_results( $sql );

        while ( ob_get_level() ) ob_end_clean();

        $writer = new TA_Xlsx_Writer();
        $writer->set_rtl( true );
        $writer->add_row(
            [ 'ردیف', 'نام هنرجو', 'ایمیل', 'محصول', 'ترم', 'نوع دوره', 'نمره', 'استادیار', 'یادداشت', 'تاریخ ثبت', 'تاریخ ویرایش' ],
            true
        );
        foreach ( $rows as $i => $r ) {
            $writer->add_row( [
                $i + 1,
                $r->student_name,
                $r->student_email,
                $r->product_name  ?? '',
                $r->term_name     ?? '',
                self::COURSE_TYPES[ $r->course_type ] ?? $r->course_type,
                TA_Grade::all_grades()[ $r->grade ]['label'] ?? $r->grade,
                $r->ta_name,
                $r->notes ?? '',
                $r->created_at,
                $r->updated_at,
            ] );
        }
        $writer->output( 'grades-' . date('Y-m-d') . '.xlsx' );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Enqueue
    // ─────────────────────────────────────────────────────────────────────────

    private function enqueue_assets(): void {
        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 'chart-js', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js', [], '4.4.1', true );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // رندر HTML اصلی
    // ─────────────────────────────────────────────────────────────────────────

    private function render_html( array $d ): void {
        extract( $d );
        $base_url    = admin_url( 'admin.php?page=ta-grades' );
        $export_args = [ 'action'=>'export_xlsx', 'filter_term'=>$filter_term, 'filter_type'=>$filter_type, 'filter_grade'=>$filter_grade, 'filter_ta'=>$filter_ta, 'filter_product'=>$filter_product, 'search'=>$filter_search ];
        $export_url  = wp_nonce_url( add_query_arg( array_filter( $export_args, static fn($v) => $v !== '' && $v !== 0 ), $base_url ), 'ta_export_grades_xlsx' );
        ?>
<!DOCTYPE html>
<div class="wrap tgm-wrap" dir="rtl">

<?php $this->render_styles(); ?>

<!-- ── نوتیس‌ها ───────────────────────────────────────────────────────── -->
<?php if ( isset($_GET['grade_updated']) )  : ?><div class="tgm-notice tgm-notice-success">✓ نمره با موفقیت ویرایش شد.</div><?php endif; ?>
<?php if ( isset($_GET['grade_deleted']) )  : ?><div class="tgm-notice tgm-notice-danger">🗑 نمره حذف شد.</div><?php endif; ?>
<?php if ( isset($_GET['bulk_deleted']) )   : ?><div class="tgm-notice tgm-notice-danger">🗑 <?= intval($_GET['bulk_deleted']) ?> نمره حذف شد.</div><?php endif; ?>
<?php if ( isset($_GET['bulk_updated']) )   : ?><div class="tgm-notice tgm-notice-success">✓ <?= intval($_GET['bulk_updated']) ?> نمره به‌روز شد.</div><?php endif; ?>

<!-- ── هدر ────────────────────────────────────────────────────────────── -->
<div class="tgm-header">
    <div class="tgm-header-title">
        <span class="tgm-header-icon">🎓</span>
        <div>
            <h1>مدیریت نمره‌ها</h1>
            <p>مشاهده، ویرایش و تحلیل نمرات هنرجویان</p>
        </div>
    </div>
    <a href="<?= esc_url($export_url) ?>" class="tgm-btn tgm-btn-export">
        ⬇ خروجی اکسل
    </a>
</div>

<!-- ── کارت‌های آمار ────────────────────────────────────────────────── -->
<div class="tgm-stats-grid">
    <div class="tgm-stat-card tgm-stat-total">
        <div class="tgm-stat-icon">📊</div>
        <div class="tgm-stat-body">
            <div class="tgm-stat-number"><?= number_format($total_all) ?></div>
            <div class="tgm-stat-label">کل نمرات</div>
        </div>
        <div class="tgm-stat-sub">این ماه: +<?= $this_month_cnt ?><?php if ($filter_term || $filter_type || $filter_grade || $filter_ta || $filter_product || $filter_search): ?> <span style="color:#6366f1;font-size:10px">(فیلتر فعال)</span><?php endif; ?></div>
    </div>
    <div class="tgm-stat-card tgm-stat-pass">
        <div class="tgm-stat-icon">✓</div>
        <div class="tgm-stat-body">
            <div class="tgm-stat-number"><?= number_format($pass_cnt) ?></div>
            <div class="tgm-stat-label">قبولی</div>
        </div>
        <div class="tgm-stat-sub">نرخ موفقیت: <?= $pass_rate ?>%</div>
    </div>
    <div class="tgm-stat-card tgm-stat-repeat">
        <div class="tgm-stat-icon">↺</div>
        <div class="tgm-stat-body">
            <div class="tgm-stat-number"><?= number_format($repeat_cnt) ?></div>
            <div class="tgm-stat-label">تکرار دوره</div>
        </div>
        <div class="tgm-stat-sub"><?= $total_all > 0 ? round($repeat_cnt / $total_all * 100) : 0 ?>% از کل</div>
    </div>
    <div class="tgm-stat-card tgm-stat-critique">
        <div class="tgm-stat-icon">✦</div>
        <div class="tgm-stat-body">
            <div class="tgm-stat-number"><?= number_format($crit_cnt) ?></div>
            <div class="tgm-stat-label">نقد اثر</div>
        </div>
        <div class="tgm-stat-sub"><?= $total_all > 0 ? round($crit_cnt / $total_all * 100) : 0 ?>% از کل</div>
    </div>
    <div class="tgm-stat-card tgm-stat-pending">
        <div class="tgm-stat-icon">⏳</div>
        <div class="tgm-stat-body">
            <div class="tgm-stat-number"><?= number_format($pend_cnt) ?></div>
            <div class="tgm-stat-label">در انتظار</div>
        </div>
        <div class="tgm-stat-sub">نیاز به بررسی</div>
    </div>
    <div class="tgm-stat-card tgm-stat-rate">
        <div class="tgm-stat-icon">📈</div>
        <div class="tgm-stat-body">
            <div class="tgm-stat-number"><?= $pass_rate ?>%</div>
            <div class="tgm-stat-label">نرخ قبولی</div>
        </div>
        <div class="tgm-stat-sub">
            <div class="tgm-progress-bar">
                <div class="tgm-progress-fill" style="width:<?= $pass_rate ?>%"></div>
            </div>
        </div>
    </div>
</div>

<!-- ── نمودار ────────────────────────────────────────────────────────── -->
<div class="tgm-chart-row">
    <div class="tgm-card tgm-chart-card">
        <div class="tgm-card-header">
            <span class="tgm-card-title">📊 روند ثبت نمره — ۶ ماه اخیر</span>
        </div>
        <canvas id="tgmLineChart" height="80"></canvas>
    </div>
    <div class="tgm-card tgm-donut-card">
        <div class="tgm-card-header">
            <span class="tgm-card-title">🍩 توزیع نمره‌ها</span>
        </div>
        <div class="tgm-donut-wrap">
            <canvas id="tgmDonutChart"></canvas>
        </div>
        <div class="tgm-donut-legend">
            <?php foreach ( TA_Grade::all_grades() as $key => $g ) : ?>
                <div class="tgm-legend-item">
                    <span class="tgm-legend-dot" style="background:<?= $g['color'] ?>"></span>
                    <span><?= $g['label'] ?></span>
                    <strong><?= (int)($stats[$key]->cnt ?? 0) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ── فیلترها ───────────────────────────────────────────────────────── -->
<div class="tgm-card tgm-filter-card">
    <form method="GET" class="tgm-filter-form">
        <input type="hidden" name="page" value="ta-grades">
        <div class="tgm-filter-row">
            <div class="tgm-search-wrap">
                <span class="tgm-search-icon">🔍</span>
                <input type="text" name="search" value="<?= esc_attr($filter_search) ?>"
                       placeholder="جستجو نام، ایمیل یا محصول..." class="tgm-search-input">
            </div>
            <select name="filter_term" class="tgm-select">
                <option value="">همه ترم‌ها</option>
                <?php foreach ( $terms as $t ) : ?>
                    <option value="<?= $t->id ?>" <?= selected($filter_term, $t->id, false) ?>><?= esc_html($t->name) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="filter_type" class="tgm-select">
                <option value="">همه نوع دوره‌ها</option>
                <?php foreach ( self::COURSE_TYPES as $key => $label ) : ?>
                    <option value="<?= $key ?>" <?= selected($filter_type, $key, false) ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <select name="filter_grade" class="tgm-select">
                <option value="">همه نمره‌ها</option>
                <?php foreach ( TA_Grade::all_grades() as $key => $g ) : ?>
                    <option value="<?= $key ?>" <?= selected($filter_grade, $key, false) ?>><?= $g['label'] ?></option>
                <?php endforeach; ?>
            </select>
            <select name="filter_ta" class="tgm-select">
                <option value="">همه استادیاران</option>
                <?php foreach ( $all_tas as $ta ) : ?>
                    <option value="<?= $ta->id ?>" <?= selected($filter_ta, $ta->id, false) ?>><?= esc_html($ta->display_name) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="tgm-btn tgm-btn-filter">اعمال فیلتر</button>
            <a href="<?= $base_url ?>" class="tgm-btn tgm-btn-reset">پاک کردن</a>
        </div>
    </form>
</div>

<!-- ── جدول ─────────────────────────────────────────────────────────── -->
<div class="tgm-card">
    <div class="tgm-table-header">
        <div class="tgm-table-info">
            نمایش <strong><?= count($rows) ?></strong> از <strong><?= number_format($total_filtered) ?></strong> نمره
            <?php if ($filter_search || $filter_term || $filter_type || $filter_grade || $filter_ta) : ?>
                <span class="tgm-filter-active-badge">فیلتر فعال</span>
            <?php endif; ?>
        </div>
        <div class="tgm-bulk-bar">
            <form method="POST" id="tgm-bulk-form">
                <?php wp_nonce_field( 'ta_bulk_grade_action', 'bulk_action_nonce' ); ?>
                <select name="bulk_action" class="tgm-select tgm-select-sm">
                    <option value="">عملیات گروهی</option>
                    <optgroup label="تغییر نمره">
                        <?php foreach ( TA_Grade::all_grades() as $key => $g ) : ?>
                            <option value="<?= $key ?>">تبدیل به: <?= $g['label'] ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <option value="delete">🗑 حذف انتخاب‌شده‌ها</option>
                </select>
                <button type="submit" class="tgm-btn tgm-btn-bulk" onclick="return confirmBulk(this)">اجرا</button>
            </form>
        </div>
    </div>

    <div class="tgm-table-wrap">
        <table class="tgm-table" id="tgm-main-table">
            <thead>
                <tr>
                    <th class="tgm-th-check"><input type="checkbox" id="tgm-check-all" onclick="toggleAll(this)"></th>
                    <?php $this->sort_header( 'student_name', 'هنرجو', $sort_col, $sort_dir ) ?>
                    <?php $this->sort_header( 'product_name', 'محصول', $sort_col, $sort_dir ) ?>
                    <?php $this->sort_header( 'term_name', 'ترم', $sort_col, $sort_dir ) ?>
                    <th>نوع دوره</th>
                    <?php $this->sort_header( 'grade', 'نمره', $sort_col, $sort_dir ) ?>
                    <?php $this->sort_header( 'ta_name', 'استادیار', $sort_col, $sort_dir ) ?>
                    <th>یادداشت</th>
                    <?php $this->sort_header( 'created_at', 'تاریخ ثبت', $sort_col, $sort_dir ) ?>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr>
                    <td colspan="10" class="tgm-empty">
                        <div class="tgm-empty-state">
                            <div class="tgm-empty-icon">🎓</div>
                            <p>نمره‌ای یافت نشد.</p>
                            <?php if ($filter_search || $filter_grade || $filter_term) : ?>
                                <a href="<?= $base_url ?>">پاک کردن فیلترها</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ( $rows as $r ) :
                    $gd      = TA_Grade::all_grades()[ $r->grade ] ?? TA_Grade::all_grades()['pending'];
                    $ct      = self::COURSE_TYPES[ $r->course_type ] ?? $r->course_type;
                    $del_url = wp_nonce_url( add_query_arg( ['action'=>'delete_grade','grade_id'=>$r->id], remove_query_arg(['grade_updated','grade_deleted','bulk_deleted','bulk_updated']) ), 'delete_grade_' . $r->id );
                    $date_fa = date_i18n( 'j F Y', strtotime( $r->created_at ) );
                    // آواتار سفارشی از user meta 'image' (سیستم قالب سایت)
                    $attachment_id = get_user_meta( $r->student_id, 'image', true );
                    $avatar = $attachment_id
                        ? wp_get_attachment_url( $attachment_id )
                        : 'https://mabnaschool.ir/wp-content/uploads/2025/11/Frame-1666952.svg';
                ?>
                <tr class="tgm-row" data-id="<?= $r->id ?>">
                    <td><input type="checkbox" name="grade_ids[]" value="<?= $r->id ?>" form="tgm-bulk-form" class="tgm-row-check"></td>
                    <td>
                        <div class="tgm-student-cell">
                            <img src="<?= esc_url($avatar) ?>" class="tgm-avatar" alt="">
                            <div>
                                <div class="tgm-student-name"><?= esc_html($r->student_name) ?></div>
                                <div class="tgm-student-email"><?= esc_html($r->student_email) ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="tgm-product"><?= esc_html($r->product_name ?: '—') ?></td>
                    <td><span class="tgm-term-badge"><?= esc_html($r->term_name ?: '—') ?></span></td>
                    <td><?= esc_html($ct) ?></td>
                    <td>
                        <span class="tgm-grade-badge" style="color:<?= $gd['color'] ?>;background:<?= $gd['bg'] ?>">
                            <?= $gd['icon'] ?> <?= $gd['label'] ?>
                        </span>
                    </td>
                    <td class="tgm-ta"><?= esc_html($r->ta_name) ?></td>
                    <td class="tgm-notes" title="<?= esc_attr($r->notes) ?>"><?= $r->notes ? esc_html(mb_strimwidth($r->notes, 0, 40, '…')) : '<span class="tgm-muted">—</span>' ?></td>
                    <td class="tgm-date"><?= esc_html($date_fa) ?></td>
                    <td>
                        <div class="tgm-actions">
                            <button class="tgm-btn tgm-btn-edit" onclick="openEdit(<?= htmlspecialchars(json_encode([
                                'id'          => $r->id,
                                'grade'       => $r->grade,
                                'notes'       => $r->notes,
                                'name'        => $r->student_name,
                                'prod'        => $r->product_name,
                                'course_type' => $r->course_type,
                            ]), ENT_QUOTES) ?>)" title="ویرایش">✏️</button>
                            <a href="<?= esc_url($del_url) ?>"
                               class="tgm-btn tgm-btn-delete"
                               onclick="return confirm('حذف نمره «<?= esc_js($r->student_name) ?>» ؟')"
                               title="حذف">🗑</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- صفحه‌بندی -->
    <?php if ( $total_pages > 1 ) : ?>
    <div class="tgm-pagination">
        <?php
        $page_links = paginate_links( [
            'base'      => add_query_arg( 'paged', '%#%' ),
            'format'    => '',
            'prev_text' => '→',
            'next_text' => '←',
            'total'     => $total_pages,
            'current'   => $paged,
            'type'      => 'array',
        ] );
        if ( $page_links ) {
            echo '<div class="tgm-page-links">' . implode( '', $page_links ) . '</div>';
        }
        ?>
        <div class="tgm-page-info">صفحه <?= $paged ?> از <?= $total_pages ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- ── مودال ویرایش ──────────────────────────────────────────────────── -->
<div id="tgm-modal" class="tgm-modal-overlay" style="display:none">
    <div class="tgm-modal">
        <div class="tgm-modal-header">
            <h2 id="tgm-modal-title">ویرایش نمره</h2>
            <button class="tgm-modal-close" onclick="closeEdit()">✕</button>
        </div>
        <form method="POST" id="tgm-edit-form">
            <?php wp_nonce_field( 'ta_grade_edit', 'ta_grade_edit_nonce' ); ?>
            <input type="hidden" name="grade_id" id="edit-grade-id">
            <div class="tgm-modal-body">
                <div class="tgm-modal-student" id="edit-student-info"></div>
                <div class="tgm-form-group">
                    <label>نمره</label>
                    <div class="tgm-grade-picker" id="tgm-grade-picker">
                        <?php foreach ( TA_Grade::all_grades() as $key => $g ) : ?>
                            <label class="tgm-grade-option" style="--gc:<?= $g['color'] ?>;--gb:<?= $g['bg'] ?>">
                                <input type="radio" name="grade" value="<?= $key ?>" onchange="selectGrade('<?= $key ?>')">
                                <span><?= $g['icon'] ?> <?= $g['label'] ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="tgm-form-group">
                    <label>یادداشت استادیار</label>
                    <textarea name="notes" id="edit-notes" class="tgm-textarea" rows="3" placeholder="یادداشت اختیاری..."></textarea>
                </div>
            </div>
            <div class="tgm-modal-footer">
                <button type="submit" class="tgm-btn tgm-btn-save">ذخیره تغییرات</button>
                <button type="button" class="tgm-btn tgm-btn-cancel" onclick="closeEdit()">انصراف</button>
            </div>
        </form>
    </div>
</div>

<?php $this->render_scripts( $monthly ); ?>
</div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // هدر ستون قابل مرتب‌سازی
    // ─────────────────────────────────────────────────────────────────────────

    private function sort_header( string $col, string $label, string $current, string $dir ): void {
        $new_dir = ( $current === $col && $dir === 'ASC' ) ? 'DESC' : 'ASC';
        $url     = add_query_arg( [ 'orderby' => $col, 'order' => $new_dir ] );
        $arrow   = '';
        if ( $current === $col ) $arrow = $dir === 'ASC' ? ' ▲' : ' ▼';
        echo "<th><a href=\"" . esc_url($url) . "\" class=\"tgm-sort-link\">{$label}{$arrow}</a></th>";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // URL فعلی (بدون پارامترهای نوتیس)
    // ─────────────────────────────────────────────────────────────────────────

    private function current_url(): string {
        return remove_query_arg( ['grade_updated','grade_deleted','bulk_deleted','bulk_updated'] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CSS
    // ─────────────────────────────────────────────────────────────────────────

    private function render_styles(): void { ?>
<style>
:root{
    --tgm-pass:#10b981;--tgm-pass-bg:#d1fae5;
    --tgm-repeat:#f59e0b;--tgm-repeat-bg:#fef3c7;
    --tgm-critique:#8b5cf6;--tgm-critique-bg:#ede9fe;
    --tgm-pending:#6b7280;--tgm-pending-bg:#f3f4f6;
    --tgm-total:#3b82f6;--tgm-rate:#ec4899;
    --tgm-radius:12px;--tgm-shadow:0 1px 3px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.06);
    --tgm-shadow-md:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -1px rgba(0,0,0,.06);
    --tgm-border:#e5e7eb;--tgm-text:#111827;--tgm-muted:#6b7280;--tgm-bg:#f9fafb;
}
.tgm-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Tahoma,sans-serif;direction:rtl;color:var(--tgm-text);max-width:1600px}
/* ── نوتیس ── */
.tgm-notice{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;font-weight:500}
.tgm-notice-success{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.tgm-notice-danger{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
/* ── هدر ── */
.tgm-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;padding:20px 24px;background:#fff;border-radius:var(--tgm-radius);box-shadow:var(--tgm-shadow)}
.tgm-header-title{display:flex;align-items:center;gap:16px}
.tgm-header-icon{font-size:36px;line-height:1}
.tgm-header h1{font-size:22px;font-weight:700;margin:0 0 4px;color:var(--tgm-text)}
.tgm-header p{margin:0;color:var(--tgm-muted);font-size:13px}
/* ── آمار ── */
.tgm-stats-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:20px}
@media(max-width:1200px){.tgm-stats-grid{grid-template-columns:repeat(3,1fr)}}
.tgm-stat-card{background:#fff;border-radius:var(--tgm-radius);padding:18px;box-shadow:var(--tgm-shadow);position:relative;overflow:hidden;transition:transform .15s,box-shadow .15s}
.tgm-stat-card:hover{transform:translateY(-2px);box-shadow:var(--tgm-shadow-md)}
.tgm-stat-icon{font-size:28px;margin-bottom:8px;line-height:1}
.tgm-stat-number{font-size:28px;font-weight:800;line-height:1;margin-bottom:4px}
.tgm-stat-label{font-size:12px;color:var(--tgm-muted);font-weight:500}
.tgm-stat-sub{font-size:11px;color:var(--tgm-muted);margin-top:8px}
.tgm-stat-total .tgm-stat-number{color:var(--tgm-total)}
.tgm-stat-pass .tgm-stat-number{color:var(--tgm-pass)}
.tgm-stat-repeat .tgm-stat-number{color:var(--tgm-repeat)}
.tgm-stat-critique .tgm-stat-number{color:var(--tgm-critique)}
.tgm-stat-pending .tgm-stat-number{color:var(--tgm-pending)}
.tgm-stat-rate .tgm-stat-number{color:var(--tgm-rate)}
.tgm-progress-bar{height:4px;background:#f3f4f6;border-radius:99px;overflow:hidden;margin-top:4px}
.tgm-progress-fill{height:100%;background:var(--tgm-rate);border-radius:99px;transition:width 1s ease}
/* ── نمودار ── */
.tgm-chart-row{display:grid;grid-template-columns:1fr 340px;gap:14px;margin-bottom:20px}
@media(max-width:1100px){.tgm-chart-row{grid-template-columns:1fr}}
.tgm-card{background:#fff;border-radius:var(--tgm-radius);box-shadow:var(--tgm-shadow);overflow:hidden}
.tgm-card-header{padding:14px 18px;border-bottom:1px solid var(--tgm-border)}
.tgm-card-title{font-size:14px;font-weight:600;color:var(--tgm-text)}
.tgm-chart-card canvas{padding:16px;display:block;max-height:200px}
.tgm-donut-card{padding:0}
.tgm-donut-wrap{display:flex;justify-content:center;padding:16px 16px 8px}
.tgm-donut-wrap canvas{max-width:160px;max-height:160px}
.tgm-donut-legend{padding:0 16px 16px}
.tgm-legend-item{display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px}
.tgm-legend-item strong{margin-right:auto;font-weight:700}
.tgm-legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
/* ── فیلتر ── */
.tgm-filter-card{margin-bottom:16px;padding:16px}
.tgm-filter-form{}
.tgm-filter-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.tgm-search-wrap{position:relative;flex:1;min-width:200px}
.tgm-search-icon{position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:14px;pointer-events:none}
.tgm-search-input{width:100%;padding:8px 32px 8px 12px;border:1px solid var(--tgm-border);border-radius:8px;font-size:13px;outline:none;transition:border-color .15s;box-sizing:border-box;font-family:inherit;direction:rtl}
.tgm-search-input:focus{border-color:#6366f1}
.tgm-select{padding:8px 12px;border:1px solid var(--tgm-border);border-radius:8px;font-size:13px;outline:none;background:#fff;font-family:inherit;cursor:pointer;color:var(--tgm-text)}
.tgm-select:focus{border-color:#6366f1}
.tgm-select-sm{padding:6px 10px;font-size:12px}
/* ── دکمه‌ها ── */
.tgm-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;border:none;font-size:13px;font-weight:500;cursor:pointer;text-decoration:none;transition:all .15s;font-family:inherit;line-height:1}
.tgm-btn-filter{background:#6366f1;color:#fff}.tgm-btn-filter:hover{background:#4f46e5}
.tgm-btn-reset{background:#f3f4f6;color:var(--tgm-text)}.tgm-btn-reset:hover{background:#e5e7eb}
.tgm-btn-export{background:#10b981;color:#fff}.tgm-btn-export:hover{background:#059669}
.tgm-btn-bulk{background:#374151;color:#fff;padding:6px 14px;font-size:12px}.tgm-btn-bulk:hover{background:#1f2937}
.tgm-btn-edit{background:transparent;padding:4px 8px;font-size:16px;border-radius:6px}.tgm-btn-edit:hover{background:#f3f4f6}
.tgm-btn-delete{background:transparent;padding:4px 8px;font-size:16px;border-radius:6px;color:inherit}.tgm-btn-delete:hover{background:#fee2e2}
.tgm-btn-save{background:#6366f1;color:#fff;padding:10px 24px}.tgm-btn-save:hover{background:#4f46e5}
.tgm-btn-cancel{background:#f3f4f6;color:var(--tgm-text)}.tgm-btn-cancel:hover{background:#e5e7eb}
/* ── جدول ── */
.tgm-table-header{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--tgm-border);gap:12px}
.tgm-table-info{font-size:13px;color:var(--tgm-muted)}
.tgm-table-info strong{color:var(--tgm-text)}
.tgm-filter-active-badge{background:#ede9fe;color:#7c3aed;font-size:11px;padding:2px 8px;border-radius:99px;margin-right:8px}
.tgm-bulk-bar{display:flex;gap:8px;align-items:center}
.tgm-table-wrap{overflow-x:auto}
.tgm-table{width:100%;border-collapse:collapse;font-size:13px}
.tgm-table thead th{background:#f9fafb;padding:10px 12px;text-align:right;font-weight:600;font-size:12px;color:var(--tgm-muted);text-transform:uppercase;border-bottom:1px solid var(--tgm-border);white-space:nowrap}
.tgm-th-check{width:36px}
.tgm-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .1s}
.tgm-table tbody tr:hover{background:#fafafa}
.tgm-table tbody td{padding:10px 12px;vertical-align:middle}
.tgm-sort-link{color:var(--tgm-muted);text-decoration:none;font-size:12px;font-weight:600}.tgm-sort-link:hover{color:var(--tgm-text)}
.tgm-student-cell{display:flex;align-items:center;gap:10px}
.tgm-avatar{width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0}
.tgm-student-name{font-weight:600;font-size:13px;color:var(--tgm-text)}
.tgm-student-email{font-size:11px;color:var(--tgm-muted)}
.tgm-grade-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:600;white-space:nowrap}
.tgm-term-badge{background:#eff6ff;color:#1d4ed8;font-size:11px;padding:2px 8px;border-radius:99px;white-space:nowrap}
.tgm-product{max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:12px}
.tgm-ta{font-size:12px;color:#374151}
.tgm-date{font-size:11px;color:var(--tgm-muted);white-space:nowrap}
.tgm-notes{font-size:11px;color:var(--tgm-muted);max-width:120px}
.tgm-muted{color:#d1d5db}
.tgm-actions{display:flex;gap:4px}
.tgm-empty{padding:48px 0 !important;text-align:center}
.tgm-empty-state{display:flex;flex-direction:column;align-items:center;gap:8px}
.tgm-empty-icon{font-size:40px}
.tgm-empty-state p{color:var(--tgm-muted);font-size:14px;margin:0}
/* ── صفحه‌بندی ── */
.tgm-pagination{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid var(--tgm-border)}
.tgm-page-links{display:flex;gap:4px}
.tgm-page-links .page-numbers{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;font-size:13px;text-decoration:none;color:var(--tgm-text);border:1px solid var(--tgm-border);transition:all .1s}
.tgm-page-links .page-numbers:hover{border-color:#6366f1;color:#6366f1}
.tgm-page-links .page-numbers.current{background:#6366f1;color:#fff;border-color:#6366f1}
.tgm-page-info{font-size:12px;color:var(--tgm-muted)}
/* ── مودال ── */
.tgm-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
.tgm-modal{background:#fff;border-radius:16px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.3);animation:tgmSlideUp .2s ease}
@keyframes tgmSlideUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.tgm-modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid var(--tgm-border)}
.tgm-modal-header h2{margin:0;font-size:16px;font-weight:700}
.tgm-modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--tgm-muted);width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center}.tgm-modal-close:hover{background:#f3f4f6}
.tgm-modal-body{padding:20px}
.tgm-modal-student{background:#f9fafb;border-radius:10px;padding:12px 14px;margin-bottom:18px;font-size:13px;font-weight:600;color:var(--tgm-text)}
.tgm-form-group{margin-bottom:16px}
.tgm-form-group label{display:block;font-size:12px;font-weight:600;color:var(--tgm-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}
.tgm-grade-picker{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.tgm-grade-option{display:block;cursor:pointer}
.tgm-grade-option input{display:none}
.tgm-grade-option span{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;border:2px solid var(--tgm-border);font-size:13px;font-weight:500;transition:all .15s;color:var(--tgm-text)}
.tgm-grade-option input:checked + span{border-color:var(--gc);background:var(--gb);color:var(--gc)}
.tgm-grade-option:hover span{border-color:var(--gc);background:var(--gb)}
.tgm-textarea{width:100%;padding:10px 12px;border:1px solid var(--tgm-border);border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;outline:none;direction:rtl;box-sizing:border-box;transition:border-color .15s}
.tgm-textarea:focus{border-color:#6366f1}
.tgm-modal-footer{display:flex;gap:10px;padding:16px 20px;border-top:1px solid var(--tgm-border);justify-content:flex-end}
/* ── رنگ ردیف انتخاب‌شده ── */
.tgm-row.selected{background:#f5f3ff !important}
</style>
    <?php }

    // ─────────────────────────────────────────────────────────────────────────
    // JavaScript
    // ─────────────────────────────────────────────────────────────────────────

    private function render_scripts( array $monthly ): void {
        // پردازش داده نمودار ماهانه
        $months_map = [];
        foreach ( $monthly as $row ) {
            $months_map[ $row->month ][ $row->grade ] = (int) $row->cnt;
        }
        ksort( $months_map );

        $month_labels  = [];
        $pass_data     = [];
        $repeat_data   = [];
        $critique_data = [];
        $pending_data  = [];

        $persian_months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];

        foreach ( $months_map as $ym => $grades ) {
            [ $y, $m ] = explode( '-', $ym );
            $month_labels[]  = ( $persian_months[ (int)$m - 1 ] ?? $m ) . ' ' . $y;
            $pass_data[]     = $grades['pass']     ?? 0;
            $repeat_data[]   = $grades['repeat']   ?? 0;
            $critique_data[] = $grades['critique'] ?? 0;
            $pending_data[]  = $grades['pending']  ?? 0;
        }

        $grades_json  = TA_Grade::all_grades();
        ?>
<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── نمودار خطی ──────────────────────────────────────────────────────────
    const lineCtx = document.getElementById('tgmLineChart');
    if (lineCtx) {
        new Chart(lineCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($month_labels) ?>,
                datasets: [
                    { label: 'قبولی',      data: <?= json_encode($pass_data) ?>,     borderColor: '#10b981', backgroundColor: '#10b98120', tension: .4, fill: true },
                    { label: 'تکرار دوره', data: <?= json_encode($repeat_data) ?>,   borderColor: '#f59e0b', backgroundColor: '#f59e0b10', tension: .4 },
                    { label: 'نقد اثر',    data: <?= json_encode($critique_data) ?>, borderColor: '#8b5cf6', backgroundColor: '#8b5cf610', tension: .4 },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: true,
                plugins: { legend: { labels: { font: { family: 'Tahoma', size: 11 }, boxWidth: 12 } } },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { family: 'Tahoma', size: 11 } } },
                    y: { beginAtZero: true, ticks: { stepSize: 1, font: { family: 'Tahoma', size: 11 } }, grid: { color: '#f3f4f6' } }
                }
            }
        });
    }

    // ── نمودار دونات ────────────────────────────────────────────────────────
    const donutCtx = document.getElementById('tgmDonutChart');
    if (donutCtx) {
        new Chart(donutCtx, {
            type: 'doughnut',
            data: {
                labels: ['قبولی', 'تکرار دوره', 'نقد اثر', 'در انتظار'],
                datasets: [{
                    data: [<?= $pass_cnt ?>, <?= $repeat_cnt ?>, <?= $crit_cnt ?>, <?= $pend_cnt ?>],
                    backgroundColor: ['#10b981','#f59e0b','#8b5cf6','#9ca3af'],
                    borderWidth: 2, borderColor: '#fff', hoverOffset: 4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: true, cutout: '72%',
                plugins: { legend: { display: false } }
            }
        });
    }
});

// ── مودال ویرایش ────────────────────────────────────────────────────────────
const ALL_GRADES = <?= json_encode(TA_Grade::all_grades()) ?>;

function getGradesForCourseType(courseType) {
    const gradesByCourse = {
        'intro':    ['pass', 'critique', 'repeat', 'pending'],
        'basic_writing': ['pass', 'critique', 'intro_permission', 'repeat', 'pending'],
        'critique': ['pass', 'repeat_critique', 'repeat_intro', 'pending'],
        'advanced': ['pass', 'repeat_advanced', 'ladder', 'pending'],
        'ladder':   ['pass', 'repeat_ladder', 'repeat_advanced', 'pending'],
    };
    const allowed = gradesByCourse[courseType] || ['pass', 'repeat', 'pending'];
    const result = {};
    allowed.forEach(k => { if (ALL_GRADES[k]) result[k] = ALL_GRADES[k]; });
    return result;
}

function openEdit(data) {
    document.getElementById('edit-grade-id').value = data.id;
    document.getElementById('edit-notes').value = data.notes || '';
    document.getElementById('tgm-modal-title').textContent = 'ویرایش نمره';
    document.getElementById('edit-student-info').textContent = '👤 ' + data.name + '  |  📚 ' + data.prod;

    // نمرات مخصوص نوع دوره
    const grades = getGradesForCourseType(data.course_type || '');
    const picker = document.getElementById('tgm-grade-picker');
    picker.innerHTML = '';
    Object.entries(grades).forEach(([key, g]) => {
        const label = document.createElement('label');
        label.className = 'tgm-grade-option';
        label.style.cssText = `--gc:${g.color};--gb:${g.bg}`;
        label.innerHTML = `<input type="radio" name="grade" value="${key}" ${key === data.grade ? 'checked' : ''}>
            <span>${g.icon} ${g.label}</span>`;
        picker.appendChild(label);
    });

    document.getElementById('tgm-modal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeEdit() {
    document.getElementById('tgm-modal').style.display = 'none';
    document.body.style.overflow = '';
}

function selectGrade(grade) {
    // visual feedback فقط — رادیو خودش انتخاب می‌شود
}

// بستن با کلیک بیرون
document.getElementById('tgm-modal').addEventListener('click', function(e) {
    if (e.target === this) closeEdit();
});

// بستن با Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeEdit();
});

// ── انتخاب همه ──────────────────────────────────────────────────────────────
function toggleAll(cb) {
    document.querySelectorAll('.tgm-row-check').forEach(c => {
        c.checked = cb.checked;
        c.closest('tr').classList.toggle('selected', cb.checked);
    });
}

document.querySelectorAll('.tgm-row-check').forEach(cb => {
    cb.addEventListener('change', function() {
        this.closest('tr').classList.toggle('selected', this.checked);
        const all = document.querySelectorAll('.tgm-row-check');
        const checked = document.querySelectorAll('.tgm-row-check:checked');
        document.getElementById('tgm-check-all').indeterminate = checked.length > 0 && checked.length < all.length;
        document.getElementById('tgm-check-all').checked = checked.length === all.length;
    });
});

// ── تأیید عملیات گروهی ──────────────────────────────────────────────────────
function confirmBulk(btn) {
    const action = document.querySelector('[name=bulk_action]').value;
    const checked = document.querySelectorAll('.tgm-row-check:checked').length;
    if (!action) { alert('لطفاً یک عملیات انتخاب کنید.'); return false; }
    if (!checked) { alert('لطفاً حداقل یک نمره انتخاب کنید.'); return false; }
    if (action === 'delete') return confirm('آیا از حذف ' + checked + ' نمره مطمئن هستید؟ این عمل قابل بازگشت نیست.');
    return confirm('تغییر نمره ' + checked + ' ردیف انتخابی؟');
}
</script>
        <?php
    }
}
