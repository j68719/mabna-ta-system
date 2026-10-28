<?php
defined( 'ABSPATH' ) || exit;

/**
 * گزارش بر مبنای استادیار
 *
 * برای هر استادیار در یک ترم مشخص نشان می‌دهد:
 *  - ظرفیت کل / پرشده / باقیمانده / درصد پر شدن
 *  - تعداد کل هنرجو، به تفکیک آقا/خانم
 *  - وضعیت‌ها: فعال، تکمیل‌شده، انصرافی، در انتظار ادمین
 *  - کد استادیار، رتبه، وضعیت فعال/غیرفعال حساب
 *  - ریز ظرفیت به تفکیک نوع دوره (در خروجی اکسل)
 */
class TA_Assistant_Report {

    private $db;
    private $ta_table;
    private $cap_table;
    private $assign_table;
    private $term_table;
    private $users_table;

    private const COURSE_LABELS = [
        'creative'      => 'خلاق',
        'intro'         => 'مقدماتی',
        'basic_writing' => 'پایه',
        'advanced'      => 'پیشرفته',
        'professional'  => 'حرفه‌ای',
        'critique'      => 'نقد اثر',
        'ladder'        => 'نردبان',
    ];

    private const STATUS_LABELS = [
        'active'        => 'فعال',
        'completed'     => 'تکمیل‌شده',
        'canceled'      => 'انصرافی',
        'pending_admin' => 'در انتظار ادمین',
    ];

    public function __construct() {
        global $wpdb;
        $this->db           = $wpdb;
        $this->ta_table     = TA_Database::table( 'assistants' );
        $this->cap_table    = TA_Database::table( 'capacities' );
        $this->assign_table = TA_Database::table( 'assignments' );
        $this->term_table   = TA_Database::table( 'terms' );
        $this->users_table  = $wpdb->users;
    }

    // ─────────────────────────────────────────────────────────────────────
    // داده‌ها
    // ─────────────────────────────────────────────────────────────────────

    private function get_terms(): array {
        return $this->db->get_results( "SELECT id, name FROM {$this->term_table} ORDER BY order_index DESC, id DESC" );
    }

    /** لیست استادیارها با فیلتر وضعیت/جستجو */
    private function get_ta_list( string $status_filter, string $search ): array {
        $where = [];
        if ( $status_filter === 'active' )   $where[] = 'a.is_active = 1';
        if ( $status_filter === 'inactive' ) $where[] = 'a.is_active = 0';

        $params = [];
        if ( $search !== '' ) {
            $where[]  = '( u.display_name LIKE %s OR a.ta_code LIKE %s )';
            $like     = '%' . $this->db->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';
        $sql = "SELECT a.id AS ta_id, a.user_id, a.rank, a.ta_code, a.is_active, u.display_name, u.user_email
                FROM {$this->ta_table} a
                INNER JOIN {$this->users_table} u ON u.ID = a.user_id
                {$where_sql}
                ORDER BY u.display_name ASC";

        return $params ? $this->db->get_results( $this->db->prepare( $sql, $params ) ) : $this->db->get_results( $sql );
    }

    /** ظرفیت کل/استفاده‌شده به ازای هر استادیار در یک ترم */
    private function get_capacity_map( int $term_id ): array {
        $rows = $this->db->get_results( $this->db->prepare(
            "SELECT ta_id, SUM(total_capacity) AS total, SUM(used_capacity) AS used
             FROM {$this->cap_table} WHERE term_id = %d GROUP BY ta_id", $term_id
        ) );
        $out = [];
        foreach ( $rows as $r ) {
            $out[ (int) $r->ta_id ] = [ 'total' => (int) $r->total, 'used' => (int) $r->used ];
        }
        return $out;
    }

    /** ریز ظرفیت به تفکیک نوع دوره برای هر استادیار (برای خروجی اکسل) */
    private function get_capacity_breakdown( int $term_id ): array {
        $rows = $this->db->get_results( $this->db->prepare(
            "SELECT ta_id, course_type, SUM(total_capacity) AS total, SUM(used_capacity) AS used
             FROM {$this->cap_table} WHERE term_id = %d GROUP BY ta_id, course_type", $term_id
        ) );
        $out = [];
        foreach ( $rows as $r ) {
            $out[ (int) $r->ta_id ][ $r->course_type ] = [ 'total' => (int) $r->total, 'used' => (int) $r->used ];
        }
        return $out;
    }

    /** آمار تخصیص‌ها (وضعیت + جنسیت) به ازای هر استادیار در یک ترم */
    private function get_assignment_stats( int $term_id ): array {
        $rows = $this->db->get_results( $this->db->prepare(
            "SELECT ta_id, status, student_gender, COUNT(*) AS cnt
             FROM {$this->assign_table} WHERE term_id = %d GROUP BY ta_id, status, student_gender", $term_id
        ) );

        $out = [];
        foreach ( $rows as $r ) {
            $ta_id = (int) $r->ta_id;
            if ( ! isset( $out[ $ta_id ] ) ) {
                $out[ $ta_id ] = [
                    'total' => 0, 'active' => 0, 'completed' => 0, 'canceled' => 0, 'pending_admin' => 0,
                    'male' => 0, 'female' => 0, 'other_gender' => 0,
                ];
            }
            $cnt = (int) $r->cnt;
            $out[ $ta_id ]['total'] += $cnt;

            if ( isset( $out[ $ta_id ][ $r->status ] ) ) {
                $out[ $ta_id ][ $r->status ] += $cnt;
            }

            if ( $r->student_gender === 'male' )        $out[ $ta_id ]['male']         += $cnt;
            elseif ( $r->student_gender === 'female' )  $out[ $ta_id ]['female']       += $cnt;
            else                                        $out[ $ta_id ]['other_gender'] += $cnt;
        }
        return $out;
    }

    /** آمار تخصیص‌ها به تفکیک نوع دوره و وضعیت (از جمله انصرافی) به ازای هر استادیار در یک ترم */
    private function get_assignment_stats_by_course( int $term_id ): array {
        $rows = $this->db->get_results( $this->db->prepare(
            "SELECT ta_id, course_type, status, COUNT(*) AS cnt
             FROM {$this->assign_table} WHERE term_id = %d GROUP BY ta_id, course_type, status", $term_id
        ) );

        $out = [];
        foreach ( $rows as $r ) {
            $ta_id = (int) $r->ta_id;
            $ct    = $r->course_type;
            if ( ! isset( $out[ $ta_id ][ $ct ] ) ) {
                $out[ $ta_id ][ $ct ] = [
                    'total' => 0, 'active' => 0, 'completed' => 0, 'canceled' => 0, 'pending_admin' => 0,
                ];
            }
            $cnt = (int) $r->cnt;
            $out[ $ta_id ][ $ct ]['total'] += $cnt;
            if ( isset( $out[ $ta_id ][ $ct ][ $r->status ] ) ) {
                $out[ $ta_id ][ $ct ][ $r->status ] += $cnt;
            }
        }
        return $out;
    }

    /** ساخت ردیف‌های نهایی گزارش، آماده برای رندر یا اکسل */
    private function build_rows( int $term_id, string $status_filter, string $search, bool $only_with_activity ): array {
        $ta_list      = $this->get_ta_list( $status_filter, $search );
        $cap_map      = $this->get_capacity_map( $term_id );
        $assign_map   = $this->get_assignment_stats( $term_id );
        $course_map   = $this->get_assignment_stats_by_course( $term_id );

        $rows = [];
        foreach ( $ta_list as $ta ) {
            $tid = (int) $ta->ta_id;
            $cap = $cap_map[ $tid ] ?? [ 'total' => 0, 'used' => 0 ];
            $as  = $assign_map[ $tid ] ?? [
                'total' => 0, 'active' => 0, 'completed' => 0, 'canceled' => 0, 'pending_admin' => 0,
                'male' => 0, 'female' => 0, 'other_gender' => 0,
            ];
            $course_breakdown = $course_map[ $tid ] ?? [];

            if ( $only_with_activity && $cap['total'] === 0 && $as['total'] === 0 ) {
                continue;
            }

            $free = max( 0, $cap['total'] - $cap['used'] );
            $pct  = $cap['total'] > 0 ? round( $cap['used'] / $cap['total'] * 100 ) : 0;

            $rows[] = [
                'ta_id'         => $tid,
                'name'          => $ta->display_name,
                'email'         => $ta->user_email,
                'ta_code'       => $ta->ta_code,
                'rank'          => (int) $ta->rank,
                'is_active'     => (int) $ta->is_active,
                'cap_total'     => $cap['total'],
                'cap_used'      => $cap['used'],
                'cap_free'      => $free,
                'cap_pct'       => $pct,
                'students_total'=> $as['total'],
                'male'          => $as['male'],
                'female'        => $as['female'],
                'other_gender'  => $as['other_gender'],
                'active_cnt'    => $as['active'],
                'completed_cnt' => $as['completed'],
                'canceled_cnt'  => $as['canceled'],
                'pending_cnt'   => $as['pending_admin'],
                'course_breakdown' => $course_breakdown,
            ];
        }
        return $rows;
    }

    private function get_filters(): array {
        $terms  = $this->get_terms();
        $term_id = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : ( $terms[0]->id ?? 0 );
        $status  = isset( $_GET['ta_status'] ) ? sanitize_text_field( $_GET['ta_status'] ) : 'all';
        if ( ! in_array( $status, [ 'all', 'active', 'inactive' ], true ) ) $status = 'all';
        $search  = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $only    = isset( $_GET['only_activity'] ) && $_GET['only_activity'] === '1';

        return [ $terms, $term_id, $status, $search, $only ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // رندر صفحه
    // ─────────────────────────────────────────────────────────────────────

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );

        if ( ! TA_Database::tables_exist() ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>جداول پایگاه داده وجود ندارند.</p></div></div>';
            return;
        }

        [ $terms, $term_id, $status_filter, $search, $only_activity ] = $this->get_filters();

        $term_name = '';
        foreach ( $terms as $t ) if ( (int) $t->id === $term_id ) { $term_name = $t->name; break; }

        $rows = $term_id ? $this->build_rows( $term_id, $status_filter, $search, $only_activity ) : [];

        // آمار کلی
        $sum_cap_total = $sum_cap_used = $sum_students = $sum_male = $sum_female = $sum_canceled = $sum_active_ta = 0;
        foreach ( $rows as $r ) {
            $sum_cap_total += $r['cap_total'];
            $sum_cap_used  += $r['cap_used'];
            $sum_students  += $r['students_total'];
            $sum_male      += $r['male'];
            $sum_female    += $r['female'];
            $sum_canceled  += $r['canceled_cnt'];
            if ( $r['cap_total'] > 0 || $r['students_total'] > 0 ) $sum_active_ta++;
        }
        $overall_pct = $sum_cap_total > 0 ? round( $sum_cap_used / $sum_cap_total * 100 ) : 0;

        $export_url = wp_nonce_url(
            add_query_arg( array_merge( $_GET, [ 'page' => 'ta-assistant-report', 'action' => 'export_xlsx' ] ), admin_url( 'admin.php' ) ),
            'ta_assistant_report_export'
        );
        ?>
        <div class="wrap" dir="rtl">
            <div class="tar-wrap">

                <div class="tar-header">
                    <div>
                        <h1 class="tar-title">📊 گزارش بر مبنای استادیار</h1>
                        <p class="tar-subtitle">وضعیت ظرفیت، تخصیص‌ها و هنرجویان هر استادیار به تفکیک ترم</p>
                    </div>
                    <?php if ( $term_id ) : ?>
                        <a href="<?= esc_url( $export_url ) ?>" class="tar-btn-export">⬇️ خروجی اکسل</a>
                    <?php endif; ?>
                </div>

                <form method="get" class="tar-filter-bar">
                    <input type="hidden" name="page" value="ta-assistant-report">

                    <div class="tar-filter-item">
                        <label>ترم</label>
                        <select name="term_id" class="tar-select" onchange="this.form.submit()">
                            <?php if ( empty( $terms ) ) : ?>
                                <option value="0">— ترمی ثبت نشده —</option>
                            <?php endif; ?>
                            <?php foreach ( $terms as $t ) : ?>
                                <option value="<?= (int) $t->id ?>" <?= selected( $term_id, (int) $t->id, false ) ?>>
                                    <?= esc_html( $t->name ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="tar-filter-item">
                        <label>وضعیت حساب استادیار</label>
                        <select name="ta_status" class="tar-select" onchange="this.form.submit()">
                            <option value="all"      <?= selected( $status_filter, 'all', false ) ?>>همه</option>
                            <option value="active"   <?= selected( $status_filter, 'active', false ) ?>>فعال</option>
                            <option value="inactive" <?= selected( $status_filter, 'inactive', false ) ?>>غیرفعال</option>
                        </select>
                    </div>

                    <div class="tar-filter-item tar-filter-grow">
                        <label>جستجوی نام / کد استادیار</label>
                        <input type="text" name="s" class="tar-input" value="<?= esc_attr( $search ) ?>" placeholder="مثلاً: زهرا احمدی یا TA-014">
                    </div>

                    <div class="tar-filter-item tar-filter-check">
                        <label class="tar-check-label">
                            <input type="checkbox" name="only_activity" value="1" <?= checked( $only_activity, true, false ) ?> onchange="this.form.submit()">
                            فقط استادیارهای دارای ظرفیت/هنرجو در این ترم
                        </label>
                    </div>

                    <button type="submit" class="tar-btn-apply">اعمال فیلتر</button>
                </form>

                <?php if ( empty( $terms ) ) : ?>
                    <div class="tar-empty">هنوز هیچ ترمی ثبت نشده است.</div>
                <?php elseif ( empty( $rows ) ) : ?>
                    <div class="tar-empty">برای این فیلترها استادیاری یافت نشد.</div>
                <?php else : ?>

                    <div class="tar-stats-grid">
                        <div class="tar-stat-card tar-stat-blue">
                            <div class="tar-stat-icon">👥</div>
                            <div class="tar-stat-num"><?= number_format_i18n( count( $rows ) ) ?></div>
                            <div class="tar-stat-label">تعداد استادیار</div>
                        </div>
                        <div class="tar-stat-card tar-stat-green">
                            <div class="tar-stat-icon">✅</div>
                            <div class="tar-stat-num"><?= number_format_i18n( $sum_active_ta ) ?></div>
                            <div class="tar-stat-label">دارای ظرفیت/هنرجو</div>
                        </div>
                        <div class="tar-stat-card tar-stat-purple">
                            <div class="tar-stat-icon">🎯</div>
                            <div class="tar-stat-num"><?= number_format_i18n( $sum_cap_total ) ?></div>
                            <div class="tar-stat-label">ظرفیت کل</div>
                        </div>
                        <div class="tar-stat-card tar-stat-orange">
                            <div class="tar-stat-icon">📈</div>
                            <div class="tar-stat-num"><?= $overall_pct ?>%</div>
                            <div class="tar-stat-label">درصد پر شدن کلی</div>
                        </div>
                        <div class="tar-stat-card tar-stat-teal">
                            <div class="tar-stat-icon">🧑‍🎓</div>
                            <div class="tar-stat-num"><?= number_format_i18n( $sum_students ) ?></div>
                            <div class="tar-stat-label">کل هنرجو (<?= number_format_i18n($sum_male) ?> آقا / <?= number_format_i18n($sum_female) ?> خانم)</div>
                        </div>
                        <div class="tar-stat-card tar-stat-red">
                            <div class="tar-stat-icon">✕</div>
                            <div class="tar-stat-num"><?= number_format_i18n( $sum_canceled ) ?></div>
                            <div class="tar-stat-label">انصرافی</div>
                        </div>
                    </div>

                    <div class="tar-card">
                        <div class="tar-card-head">
                            نتایج برای ترم: <strong><?= esc_html( $term_name ) ?></strong>
                            <span class="tar-hint">(روی سربرگ ستون‌ها برای مرتب‌سازی کلیک کنید)</span>
                        </div>
                        <div class="tar-table-wrap">
                            <table class="tar-table" id="tar-table">
                                <thead>
                                    <tr>
                                        <th data-sort="text">استادیار</th>
                                        <th data-sort="text">کد</th>
                                        <th data-sort="num">رتبه</th>
                                        <th data-sort="text">وضعیت حساب</th>
                                        <th data-sort="num">ظرفیت کل</th>
                                        <th data-sort="num">پرشده</th>
                                        <th data-sort="num">باقیمانده</th>
                                        <th data-sort="num">درصد پر شدن</th>
                                        <th data-sort="num">کل هنرجو</th>
                                        <th data-sort="num">👨 آقا</th>
                                        <th data-sort="num">👩 خانم</th>
                                        <th data-sort="num">فعال</th>
                                        <th data-sort="num">تکمیل‌شده</th>
                                        <th data-sort="num">انصرافی</th>
                                        <th data-sort="num">در انتظار ادمین</th>
                                        <th>به تفکیک دوره</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $rows as $r ) :
                                    $bar_col = $r['cap_pct'] >= 100 ? '#ef4444' : ( $r['cap_pct'] >= 70 ? '#f59e0b' : '#10b981' );
                                    $initial = mb_substr( trim( $r['name'] ), 0, 1 );
                                ?>
                                    <tr>
                                        <td class="tar-th-right">
                                            <div class="tar-ta-cell">
                                                <span class="tar-avatar"><?= esc_html( $initial ) ?></span>
                                                <div class="tar-ta-info">
                                                    <span class="tar-ta-name"><?= esc_html( $r['name'] ) ?></span>
                                                    <span class="tar-ta-email"><?= esc_html( $r['email'] ) ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= $r['ta_code'] ? '<code class="tar-code">' . esc_html( $r['ta_code'] ) . '</code>' : '<span class="tar-muted">—</span>' ?></td>
                                        <td class="tar-num"><?= (int) $r['rank'] ?></td>
                                        <td>
                                            <?php if ( $r['is_active'] ) : ?>
                                                <span class="tar-badge tar-badge-green">فعال</span>
                                            <?php else : ?>
                                                <span class="tar-badge tar-badge-gray">غیرفعال</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="tar-num"><?= number_format_i18n( $r['cap_total'] ) ?></td>
                                        <td class="tar-num"><?= number_format_i18n( $r['cap_used'] ) ?></td>
                                        <td class="tar-num"><?= number_format_i18n( $r['cap_free'] ) ?></td>
                                        <td class="tar-pct-cell">
                                            <div class="tar-bar"><div class="tar-bar-fill" style="width:<?= $r['cap_pct'] ?>%;background:<?= $bar_col ?>"></div></div>
                                            <span class="tar-pct-num"><?= $r['cap_pct'] ?>%</span>
                                        </td>
                                        <td class="tar-num tar-strong"><?= number_format_i18n( $r['students_total'] ) ?></td>
                                        <td class="tar-num"><?= number_format_i18n( $r['male'] ) ?></td>
                                        <td class="tar-num"><?= number_format_i18n( $r['female'] ) ?></td>
                                        <td class="tar-num"><span class="tar-badge tar-badge-blue"><?= number_format_i18n( $r['active_cnt'] ) ?></span></td>
                                        <td class="tar-num"><span class="tar-badge tar-badge-teal"><?= number_format_i18n( $r['completed_cnt'] ) ?></span></td>
                                        <td class="tar-num"><span class="tar-badge tar-badge-red"><?= number_format_i18n( $r['canceled_cnt'] ) ?></span></td>
                                        <td class="tar-num"><span class="tar-badge tar-badge-orange"><?= number_format_i18n( $r['pending_cnt'] ) ?></span></td>
                                        <td class="tar-th-right">
                                            <?php if ( ! empty( $r['course_breakdown'] ) ) : ?>
                                            <div class="tar-course-chips">
                                                <?php foreach ( $r['course_breakdown'] as $ct => $cd ) :
                                                    if ( $cd['total'] === 0 ) continue;
                                                    $has_cancel = $cd['canceled'] > 0;
                                                    $tip = ( self::COURSE_LABELS[$ct] ?? $ct ) . ': کل ' . $cd['total']
                                                         . '، فعال ' . $cd['active'] . '، تکمیل‌شده ' . $cd['completed']
                                                         . '، انصرافی ' . $cd['canceled'] . '، در انتظار ادمین ' . $cd['pending_admin'];
                                                ?>
                                                <span class="tar-course-chip" title="<?= esc_attr( $tip ) ?>">
                                                    <span class="tar-course-chip-name"><?= esc_html( self::COURSE_LABELS[$ct] ?? $ct ) ?> (<?= number_format_i18n( $cd['total'] ) ?>)</span>
                                                    <?php if ( $has_cancel ) : ?>
                                                        <span class="tar-course-chip-cancel">✕ انصرافی: <?= number_format_i18n( $cd['canceled'] ) ?></span>
                                                    <?php else : ?>
                                                        <span class="tar-course-chip-ok">بدون انصراف</span>
                                                    <?php endif; ?>
                                                </span>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php else : ?>
                                                <span class="tar-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="tar-foot">
                                        <td class="tar-th-right" colspan="4">جمع کل</td>
                                        <td class="tar-num"><?= number_format_i18n( $sum_cap_total ) ?></td>
                                        <td class="tar-num"><?= number_format_i18n( $sum_cap_used ) ?></td>
                                        <td class="tar-num"><?= number_format_i18n( max( 0, $sum_cap_total - $sum_cap_used ) ) ?></td>
                                        <td class="tar-num"><?= $overall_pct ?>%</td>
                                        <td class="tar-num"><?= number_format_i18n( $sum_students ) ?></td>
                                        <td class="tar-num"><?= number_format_i18n( $sum_male ) ?></td>
                                        <td class="tar-num"><?= number_format_i18n( $sum_female ) ?></td>
                                        <td colspan="3"></td>
                                        <td class="tar-num"><?= number_format_i18n( $sum_canceled ) ?></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                <?php endif; ?>
            </div>
        </div>

        <style>
        .tar-wrap{direction:rtl;font-family:inherit;max-width:1400px}
        .tar-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px;flex-wrap:wrap}
        .tar-title{font-size:22px;margin:0 0 4px}
        .tar-subtitle{margin:0;color:#64748b;font-size:13px}
        .tar-btn-export{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-weight:700;padding:10px 20px;border-radius:10px;text-decoration:none;box-shadow:0 2px 8px rgba(16,185,129,.3);transition:transform .15s}
        .tar-btn-export:hover{transform:translateY(-1px);color:#fff}

        .tar-filter-bar{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.03)}
        .tar-filter-item{display:flex;flex-direction:column;gap:5px}
        .tar-filter-item label{font-size:12px;font-weight:600;color:#475569}
        .tar-filter-grow{flex:1;min-width:220px}
        .tar-select,.tar-input{padding:7px 11px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;min-width:170px}
        .tar-input{width:100%}
        .tar-filter-check{padding-bottom:8px}
        .tar-check-label{display:flex !important;flex-direction:row !important;align-items:center;gap:6px;font-size:12.5px !important;color:#334155;white-space:nowrap;cursor:pointer}
        .tar-btn-apply{padding:8px 18px;border:none;border-radius:8px;background:#3b82f6;color:#fff;font-weight:600;cursor:pointer;height:34px}
        .tar-btn-apply:hover{background:#2563eb}

        .tar-empty{background:#fff;border:1px dashed #cbd5e1;border-radius:12px;padding:50px;text-align:center;color:#64748b;font-size:14px}

        .tar-stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:22px}
        .tar-stat-card{background:#fff;border-radius:14px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.05);border:1px solid #eef2f7;position:relative;overflow:hidden}
        .tar-stat-card::before{content:'';position:absolute;top:0;right:0;width:4px;height:100%}
        .tar-stat-blue::before{background:#3b82f6}.tar-stat-green::before{background:#10b981}
        .tar-stat-purple::before{background:#8b5cf6}.tar-stat-orange::before{background:#f59e0b}
        .tar-stat-teal::before{background:#06b6d4}.tar-stat-red::before{background:#ef4444}
        .tar-stat-icon{font-size:20px;margin-bottom:6px}
        .tar-stat-num{font-size:24px;font-weight:800;color:#0f172a;font-variant-numeric:tabular-nums;line-height:1.2}
        .tar-stat-label{font-size:12px;color:#64748b;margin-top:4px}

        .tar-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden}
        .tar-card-head{padding:14px 18px;border-bottom:1px solid #e2e8f0;font-size:14px;color:#334155;background:#f8fafc}
        .tar-hint{font-size:11.5px;color:#94a3b8;margin-right:8px;font-weight:400}
        .tar-table-wrap{overflow-x:auto}
        .tar-table{width:100%;border-collapse:collapse;font-size:13px;white-space:nowrap}
        .tar-table thead th{background:#f1f5f9;color:#475569;font-weight:700;padding:11px 12px;text-align:center;border-bottom:1px solid #e2e8f0;cursor:pointer;user-select:none}
        .tar-table thead th:hover{background:#e2e8f0}
        .tar-table td{padding:10px 12px;text-align:center;border-bottom:1px solid #f1f5f9}
        .tar-th-right{text-align:right !important}
        .tar-num{font-variant-numeric:tabular-nums;font-weight:600;color:#1e293b}
        .tar-strong{font-weight:800;color:#0f172a}
        .tar-ta-cell{display:flex;align-items:center;gap:10px;justify-content:flex-start}
        .tar-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0}
        .tar-ta-info{display:flex;flex-direction:column;text-align:right}
        .tar-ta-name{font-weight:700;color:#1e293b;font-size:13px}
        .tar-ta-email{font-size:11px;color:#94a3b8;direction:ltr;text-align:right}
        .tar-code{background:#f0f4ff;border:1px solid #c7d2fe;padding:2px 7px;border-radius:5px;font-size:12px;font-weight:700;color:#4338ca}
        .tar-muted{color:#94a3b8}
        .tar-badge{display:inline-block;padding:3px 10px;border-radius:99px;font-weight:700;font-size:12px}
        .tar-badge-green{background:#dcfce7;color:#166534}
        .tar-badge-gray{background:#f1f5f9;color:#64748b}
        .tar-badge-blue{background:#dbeafe;color:#1e40af}
        .tar-badge-teal{background:#cffafe;color:#155e75}
        .tar-badge-red{background:#fee2e2;color:#991b1b}
        .tar-badge-orange{background:#fef3c7;color:#92400e}
        .tar-pct-cell{display:flex;align-items:center;gap:8px;justify-content:center}
        .tar-bar{width:80px;height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden}
        .tar-bar-fill{height:100%;border-radius:99px}
        .tar-pct-num{font-weight:700;font-size:12px;color:#475569;min-width:32px}
        .tar-foot{background:#f8fafc;font-weight:700}
        .tar-foot td{border-top:2px solid #e2e8f0;border-bottom:none}

        .tar-course-chips{display:flex;flex-wrap:wrap;gap:6px;white-space:normal}
        .tar-course-chip{display:inline-flex;flex-direction:column;align-items:flex-start;gap:2px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:5px 9px;font-size:11px;line-height:1.5}
        .tar-course-chip-name{font-weight:700;color:#1e293b}
        .tar-course-chip-cancel{color:#dc2626;font-weight:700}
        .tar-course-chip-ok{color:#16a34a;font-weight:600}
        </style>

        <script>
        (function(){
            var table = document.getElementById('tar-table');
            if (!table) return;
            var tbody = table.tBodies[0];
            var headers = table.querySelectorAll('thead th[data-sort]');
            var dir = {};
            headers.forEach(function(th, idx){
                th.addEventListener('click', function(){
                    var type = th.getAttribute('data-sort');
                    dir[idx] = !dir[idx];
                    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
                    rows.sort(function(a, b){
                        var av = a.children[idx].innerText.trim();
                        var bv = b.children[idx].innerText.trim();
                        if (type === 'num') {
                            av = parseFloat(av.replace(/[^0-9.-]/g,'')) || 0;
                            bv = parseFloat(bv.replace(/[^0-9.-]/g,'')) || 0;
                            return dir[idx] ? av - bv : bv - av;
                        }
                        return dir[idx] ? av.localeCompare(bv, 'fa') : bv.localeCompare(av, 'fa');
                    });
                    rows.forEach(function(r){ tbody.appendChild(r); });
                });
            });
        })();
        </script>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────
    // خروجی اکسل
    // ─────────────────────────────────────────────────────────────────────

    /** فرمت خلاصه‌ی متنی «به تفکیک دوره» برای یک سلول اکسل، شامل وضعیت انصرافی هر دوره */
    private function format_course_breakdown_text( array $course_breakdown ): string {
        $parts = [];
        foreach ( $course_breakdown as $ct => $cd ) {
            if ( $cd['total'] === 0 ) continue;
            $label = self::COURSE_LABELS[ $ct ] ?? $ct;
            $cancel_txt = $cd['canceled'] > 0 ? ( $cd['canceled'] . ' انصرافی' ) : 'بدون انصراف';
            $parts[] = $label . ': ' . $cd['total'] . ' (' . $cancel_txt . ')';
        }
        return $parts ? implode( ' | ', $parts ) : '—';
    }

    public function export_xlsx(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );

        [ $terms, $term_id, $status_filter, $search, $only_activity ] = $this->get_filters();
        $term_name = '';
        foreach ( $terms as $t ) if ( (int) $t->id === $term_id ) { $term_name = $t->name; break; }

        $rows      = $term_id ? $this->build_rows( $term_id, $status_filter, $search, $only_activity ) : [];
        $cap_by_ct = $term_id ? $this->get_capacity_breakdown( $term_id ) : [];

        while ( ob_get_level() ) ob_end_clean();

        $writer = new TA_Xlsx_Writer();
        $writer->set_rtl( true );

        $writer->add_row( [ 'گزارش بر مبنای استادیار — ترم: ' . $term_name . ' — تاریخ تولید: ' . date( 'Y-m-d H:i' ) ], true );
        $writer->add_row( [ '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '' ] );

        $writer->add_row( [
            'نام استادیار', 'ایمیل', 'کد', 'رتبه', 'وضعیت حساب',
            'ظرفیت کل', 'پرشده', 'باقیمانده', 'درصد پر شدن',
            'کل هنرجو', 'آقا', 'خانم',
            'فعال', 'تکمیل‌شده', 'انصرافی', 'در انتظار ادمین',
            'به تفکیک دوره (تعداد و وضعیت انصراف)',
        ], true );

        $sum = [ 'cap_total'=>0,'cap_used'=>0,'students_total'=>0,'male'=>0,'female'=>0,'active_cnt'=>0,'completed_cnt'=>0,'canceled_cnt'=>0,'pending_cnt'=>0 ];

        foreach ( $rows as $r ) {
            foreach ( $sum as $k => $v ) $sum[ $k ] += $r[ $k ];
            $writer->add_row( [
                $r['name'],
                $r['email'],
                $r['ta_code'] ?: '—',
                $r['rank'],
                $r['is_active'] ? 'فعال' : 'غیرفعال',
                $r['cap_total'], $r['cap_used'], $r['cap_free'], $r['cap_pct'] . '%',
                $r['students_total'], $r['male'], $r['female'],
                $r['active_cnt'], $r['completed_cnt'], $r['canceled_cnt'], $r['pending_cnt'],
                $this->format_course_breakdown_text( $r['course_breakdown'] ?? [] ),
            ] );
        }

        $overall_pct = $sum['cap_total'] > 0 ? round( $sum['cap_used'] / $sum['cap_total'] * 100 ) : 0;
        $writer->add_row( [
            'جمع کل', '', '', '', '',
            $sum['cap_total'], $sum['cap_used'], max( 0, $sum['cap_total'] - $sum['cap_used'] ), $overall_pct . '%',
            $sum['students_total'], $sum['male'], $sum['female'],
            $sum['active_cnt'], $sum['completed_cnt'], $sum['canceled_cnt'], $sum['pending_cnt'],
        ] );

        // ── بخش دوم: ریز ظرفیت به تفکیک نوع دوره برای هر استادیار ──────────
        $writer->add_row( [ '' ] );
        $writer->add_row( [ 'ریز ظرفیت به تفکیک نوع دوره' ], true );
        $writer->add_row( [ 'نام استادیار', 'نوع دوره', 'ظرفیت کل', 'استفاده‌شده', 'باقیمانده' ], true );

        foreach ( $rows as $r ) {
            $bd = $cap_by_ct[ $r['ta_id'] ] ?? [];
            foreach ( $bd as $ct => $d ) {
                if ( $d['total'] === 0 && $d['used'] === 0 ) continue;
                $writer->add_row( [
                    $r['name'],
                    self::COURSE_LABELS[ $ct ] ?? $ct,
                    $d['total'], $d['used'], max( 0, $d['total'] - $d['used'] ),
                ] );
            }
        }

        // ── بخش سوم: آمار تخصیص به تفکیک نوع دوره برای هر استادیار (شامل انصرافی) ──
        $writer->add_row( [ '' ] );
        $writer->add_row( [ 'آمار تخصیص به تفکیک نوع دوره' ], true );
        $writer->add_row( [
            'نام استادیار', 'نوع دوره', 'کل', 'فعال', 'تکمیل‌شده', 'انصرافی', 'در انتظار ادمین', 'وضعیت انصراف',
        ], true );

        foreach ( $rows as $r ) {
            $bd = $r['course_breakdown'] ?? [];
            foreach ( $bd as $ct => $cd ) {
                if ( $cd['total'] === 0 ) continue;
                $writer->add_row( [
                    $r['name'],
                    self::COURSE_LABELS[ $ct ] ?? $ct,
                    $cd['total'], $cd['active'], $cd['completed'], $cd['canceled'], $cd['pending_admin'],
                    $cd['canceled'] > 0 ? 'دارای انصرافی' : 'بدون انصراف',
                ] );
            }
        }

        $fn = 'assistant-report-' . ( $term_name ? sanitize_file_name( $term_name ) . '-' : '' ) . date( 'Y-m-d' ) . '.xlsx';
        $writer->output( $fn );
        exit;
    }
}
