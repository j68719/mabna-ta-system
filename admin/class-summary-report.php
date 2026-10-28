<?php
defined( 'ABSPATH' ) || exit;

/**
 * گزارش خلاصه ظرفیت‌ها بر حسب ترم
 *
 * یک نمای جمع‌وجور و جدولی از وضعیت کلی هر نوع دوره در یک ترم مشخص:
 * ظرفیت کل / استفاده‌شده / باقیمانده / درصد پر شدن / تعداد نفرات تخصیص‌نگرفته
 */
class TA_Summary_Report {

    private $db;
    private $cap_table;
    private $term_table;
    private $ps_table;
    private $assign_table;
    private $users_table;

    private const COURSE_ORDER = [
        'creative', 'basic_writing', 'intro', 'advanced', 'critique', 'professional', 'ladder',
    ];

    private const COURSE_LABELS = [
        'creative'      => 'خلاق',
        'intro'         => 'مقدماتی',
        'basic_writing' => 'پایه',
        'advanced'      => 'پیشرفته',
        'professional'  => 'حرفه‌ای',
        'critique'      => 'نقد اثر',
        'ladder'        => 'نردبان',
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

    public function __construct() {
        global $wpdb;
        $this->db           = $wpdb;
        $this->cap_table    = $wpdb->prefix . 'ta_capacities';
        $this->term_table   = $wpdb->prefix . 'ta_terms';
        $this->ps_table     = $wpdb->prefix . 'ta_product_settings';
        $this->assign_table = $wpdb->prefix . 'ta_assignments';
        $this->users_table  = $wpdb->users;
    }

    // ─────────────────────────────────────────────────────────────────────
    // داده‌ها
    // ─────────────────────────────────────────────────────────────────────

    private function get_terms(): array {
        return $this->db->get_results( "SELECT id, name FROM {$this->term_table} ORDER BY id DESC" );
    }

    /** ظرفیت کل/استفاده‌شده به تفکیک نوع دوره برای یک ترم (یا همه ترم‌ها) */
    private function get_capacity_by_type( int $term_id ): array {
        $where = $term_id ? $this->db->prepare( 'WHERE term_id = %d', $term_id ) : '';
        $rows  = $this->db->get_results(
            "SELECT course_type, SUM(total_capacity) AS total, SUM(used_capacity) AS used
             FROM {$this->cap_table} {$where}
             GROUP BY course_type"
        );
        $out = [];
        foreach ( $rows as $r ) {
            $out[ $r->course_type ] = [ 'total' => (int) $r->total, 'used' => (int) $r->used ];
        }
        return $out;
    }

    /** تعداد نفرات (مشتری‌های یکتا) که سفارش ثبت کرده‌اند اما هنوز تخصیص نگرفته‌اند، به تفکیک نوع دوره */
    private function get_unassigned_by_type( int $term_id ): array {
        $where_term = $term_id ? $this->db->prepare( 'AND ps.term_id = %d', $term_id ) : '';

        $rows = $this->db->get_results(
            "SELECT ps.course_type, COUNT(DISTINCT u.ID) AS unassigned
             FROM {$this->db->posts} o
             INNER JOIN {$this->db->postmeta} om ON o.ID = om.post_id AND om.meta_key = '_customer_user'
             INNER JOIN {$this->users_table} u ON om.meta_value = u.ID
             INNER JOIN {$this->db->prefix}woocommerce_order_items oi ON o.ID = oi.order_id AND oi.order_item_type = 'line_item'
             INNER JOIN {$this->db->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
             INNER JOIN {$this->db->posts} p ON oim.meta_value = p.ID
             INNER JOIN {$this->ps_table} ps ON p.ID = ps.product_id
             LEFT JOIN {$this->assign_table} a ON u.ID = a.student_id AND p.ID = a.product_id AND a.status = 'active'
             WHERE o.post_type = 'shop_order'
               AND o.post_status IN ('wc-completed','wc-processing')
               AND a.id IS NULL
               {$where_term}
             GROUP BY ps.course_type"
        );

        $out = [];
        foreach ( $rows as $r ) {
            $out[ $r->course_type ] = (int) $r->unassigned;
        }
        return $out;
    }

    /** تفکیک تودرتوی ظرفیت: پیام‌رسان در سطح اول، جنسیت زیرمجموعه‌ی هر پیام‌رسان */
    private function get_capacity_breakdown( int $term_id ): array {
        $where = $term_id ? $this->db->prepare( 'WHERE term_id = %d', $term_id ) : '';
        $rows  = $this->db->get_results(
            "SELECT course_type, messenger, student_gender,
                    SUM(total_capacity) AS total, SUM(used_capacity) AS used
             FROM {$this->cap_table} {$where}
             GROUP BY course_type, messenger, student_gender"
        );

        $out = [];
        foreach ( $rows as $r ) {
            $ct     = $r->course_type;
            $total  = (int) $r->total;
            $used   = (int) $r->used;
            $msg    = $r->messenger ?: 'both';
            $gender = $r->student_gender ?: 'both';

            if ( ! isset( $out[ $ct ][ $msg ] ) ) {
                $out[ $ct ][ $msg ] = [ 'total' => 0, 'used' => 0, 'by_gender' => [] ];
            }
            $out[ $ct ][ $msg ]['total'] += $total;
            $out[ $ct ][ $msg ]['used']  += $used;

            if ( ! isset( $out[ $ct ][ $msg ]['by_gender'][ $gender ] ) ) {
                $out[ $ct ][ $msg ]['by_gender'][ $gender ] = [ 'total' => 0, 'used' => 0 ];
            }
            $out[ $ct ][ $msg ]['by_gender'][ $gender ]['total'] += $total;
            $out[ $ct ][ $msg ]['by_gender'][ $gender ]['used']  += $used;
        }
        return $out;
    }

    /** ادغام دو ساختار تودرتوی پیام‌رسان/جنسیت (برای ردیف «جمع کل») */
    private function merge_breakdown( array $into, array $from ): array {
        foreach ( $from as $msg => $data ) {
            if ( ! isset( $into[ $msg ] ) ) {
                $into[ $msg ] = [ 'total' => 0, 'used' => 0, 'by_gender' => [] ];
            }
            $into[ $msg ]['total'] += $data['total'];
            $into[ $msg ]['used']  += $data['used'];
            foreach ( $data['by_gender'] as $gender => $g ) {
                if ( ! isset( $into[ $msg ]['by_gender'][ $gender ] ) ) {
                    $into[ $msg ]['by_gender'][ $gender ] = [ 'total' => 0, 'used' => 0 ];
                }
                $into[ $msg ]['by_gender'][ $gender ]['total'] += $g['total'];
                $into[ $msg ]['by_gender'][ $gender ]['used']  += $g['used'];
            }
        }
        return $into;
    }

    // ─────────────────────────────────────────────────────────────────────
    // رندر
    // ─────────────────────────────────────────────────────────────────────

    private const MESSENGER_LABELS = [
        'telegram' => 'تلگرام',
        'bale'     => 'بله',
        'eitaa'    => 'ایتا',
        'both'     => 'همه',
    ];

    private const MESSENGER_ICONS = [
        'telegram' => '✈️',
        'bale'     => '🔵',
        'eitaa'    => '🟢',
        'both'     => '📡',
    ];

    private const GENDER_LABELS = [
        'male'   => 'آقا',
        'female' => 'خانم',
        'both'   => 'هر دو',
    ];

    private const GENDER_ICONS = [
        'male'   => '👨',
        'female' => '👩',
        'both'   => '🧑‍🤝‍🧑',
    ];

    /**
     * رندر تفکیک تودرتو: هر پیام‌رسان یک خط (با «باقیمانده»)، و زیر آن
     * خطوط جنسیت مربوط به همان پیام‌رسان (نیز با «باقیمانده»).
     * مثال خروجی:
     *   ایتا: 34/77 باقیمانده 43
     *     هر دو: 10/50 باقیمانده 40
     *     خانم: 20/20 باقیمانده 0
     *     آقا: 4/7 باقیمانده 3
     *   بله: 42/70 باقیمانده 28
     */
    private function render_breakdown_nested( array $breakdown ): string {
        // ترتیب نمایش پیام‌رسان‌ها ثابت و قابل‌پیش‌بینی باشد
        $order = [ 'telegram', 'bale', 'eitaa', 'both' ];
        uksort( $breakdown, function( $a, $b ) use ( $order ) {
            $pa = array_search( $a, $order, true );
            $pb = array_search( $b, $order, true );
            $pa = $pa === false ? 99 : $pa;
            $pb = $pb === false ? 99 : $pb;
            return $pa <=> $pb;
        } );

        $gender_order = [ 'both', 'female', 'male' ];

        $html = '';
        foreach ( $breakdown as $msg => $data ) {
            if ( $data['total'] <= 0 ) {
                continue;
            }
            $label     = self::MESSENGER_LABELS[ $msg ] ?? $msg;
            $icon      = self::MESSENGER_ICONS[ $msg ] ?? '';
            $remaining = max( 0, $data['total'] - $data['used'] );

            $html .= sprintf(
                '<div class="tsr-bd-msg-line"><span class="tsr-bd-icon">%s</span> <b>%s:</b> %d/%d <span class="tsr-bd-remain">باقیمانده %d</span></div>',
                $icon, esc_html( $label ), $data['used'], $data['total'], $remaining
            );

            $by_gender = $data['by_gender'];
            uksort( $by_gender, function( $a, $b ) use ( $gender_order ) {
                $pa = array_search( $a, $gender_order, true );
                $pb = array_search( $b, $gender_order, true );
                $pa = $pa === false ? 99 : $pa;
                $pb = $pb === false ? 99 : $pb;
                return $pa <=> $pb;
            } );

            // زیرخط جنسیت فقط زمانی نمایش داده می‌شود که بیش از یک دسته‌ی جنسیتی
            // زیر این پیام‌رسان وجود داشته باشد؛ در غیر این‌صورت عددش دقیقاً با
            // خط پیام‌رسان یکی است و تکراری/زائد خواهد بود
            if ( count( array_filter( $by_gender, fn( $g ) => $g['total'] > 0 ) ) > 1 ) {
                foreach ( $by_gender as $gender => $g ) {
                    if ( $g['total'] <= 0 ) {
                        continue;
                    }
                    $g_label     = self::GENDER_LABELS[ $gender ] ?? $gender;
                    $g_icon      = self::GENDER_ICONS[ $gender ] ?? '';
                    $g_remaining = max( 0, $g['total'] - $g['used'] );
                    $html .= sprintf(
                        '<div class="tsr-bd-gender-line"><span class="tsr-bd-icon">%s</span> %s: %d/%d <span class="tsr-bd-remain">باقیمانده %d</span></div>',
                        $g_icon, esc_html( $g_label ), $g['used'], $g['total'], $g_remaining
                    );
                }
            }
        }

        return $html !== '' ? $html : '<span class="tsr-muted">—</span>';
    }

    public function render(): void {
        $terms       = $this->get_terms();
        $f_term      = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : ( $terms[0]->id ?? 0 );
        $cap_by_type = $this->get_capacity_by_type( $f_term );
        $unassigned  = $this->get_unassigned_by_type( $f_term );
        $breakdown   = $this->get_capacity_breakdown( $f_term );

        $rows = [];
        foreach ( self::COURSE_ORDER as $ct ) {
            $total = $cap_by_type[ $ct ]['total'] ?? 0;
            $used  = $cap_by_type[ $ct ]['used']  ?? 0;
            $un    = $unassigned[ $ct ] ?? 0;
            if ( $total === 0 && $used === 0 && $un === 0 ) {
                continue;
            }
            $rows[] = [
                'course_type' => $ct,
                'total'       => $total,
                'used'        => $used,
                'unassigned'  => $un,
                'breakdown'   => $breakdown[ $ct ] ?? [],
            ];
        }

        $sum_total = array_sum( array_column( $rows, 'total' ) );
        $sum_used  = array_sum( array_column( $rows, 'used' ) );
        $sum_un    = array_sum( array_column( $rows, 'unassigned' ) );

        // تجمیع تفکیک تودرتو روی همه‌ی نوع‌دوره‌ها برای ردیف «جمع کل»
        $sum_breakdown = [];
        foreach ( $rows as $r ) {
            $sum_breakdown = $this->merge_breakdown( $sum_breakdown, $r['breakdown'] );
        }

        $term_name = '';
        foreach ( $terms as $t ) {
            if ( (int) $t->id === $f_term ) { $term_name = $t->name; break; }
        }
        ?>
        <div class="wrap tsr-wrap">
            <h1 class="tsr-title">📋 گزارش خلاصه ظرفیت‌ها</h1>

            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap">
                <form method="GET" class="tsr-filter-form" style="margin-bottom:0">
                <input type="hidden" name="page" value="<?= esc_attr( $_GET['page'] ?? 'ta-summary-report' ) ?>">
                <label class="tsr-filter-label">ترم:</label>
                <select name="term_id" class="tsr-select" onchange="this.form.submit()">
                    <?php foreach ( $terms as $t ) : ?>
                        <option value="<?= (int) $t->id ?>" <?= selected( $f_term, $t->id, false ) ?>>
                            <?= esc_html( $t->name ) ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if ( empty( $terms ) ) : ?>
                        <option value="0">— ترمی ثبت نشده —</option>
                    <?php endif; ?>
                </select>
                <noscript><button type="submit" class="tsr-btn-apply">اعمال</button></noscript>
            </form>
                <a href="<?= esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'export_xlsx', 'term_id' => $f_term ] ), 'ta_summary_export' ) ) ?>"
                   style="background:#217346;color:#fff;padding:7px 16px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;white-space:nowrap">⬇ خروجی اکسل</a>
            </div>

            <?php if ( empty( $rows ) ) : ?>
                <div class="tsr-empty">📭 برای این ترم هنوز داده‌ای ثبت نشده است.</div>
            <?php else : ?>

            <div class="tsr-card">
                <div class="tsr-card-head">
                    خلاصه ترم: <strong><?= esc_html( $term_name ?: '—' ) ?></strong>
                </div>
                <div class="tsr-table-wrap">
                    <table class="tsr-table">
                        <thead>
                            <tr>
                                <th class="tsr-th-right">نوع دوره</th>
                                <th>ظرفیت کل</th>
                                <th>استفاده‌شده</th>
                                <th>باقیمانده</th>
                                <th>درصد پر شدن</th>
                                <th>تخصیص‌نگرفته (نفر)</th>
                                <th class="tsr-th-right">تفکیک پیام‌رسان / جنسیت</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $rows as $r ) :
                            $ct      = $r['course_type'];
                            $total   = $r['total'];
                            $used    = $r['used'];
                            $free    = max( 0, $total - $used );
                            $pct     = $total > 0 ? round( $used / $total * 100 ) : 0;
                            $color   = self::COURSE_COLORS[ $ct ] ?? '#94a3b8';
                            $bar_col = $pct >= 100 ? '#ef4444' : ( $pct >= 70 ? '#f59e0b' : '#10b981' );
                        ?>
                            <tr>
                                <td class="tsr-th-right">
                                    <span class="tsr-type-badge" style="background:<?= $color ?>1a;color:<?= $color ?>">
                                        <?= esc_html( self::COURSE_LABELS[ $ct ] ?? $ct ) ?>
                                    </span>
                                </td>
                                <td class="tsr-num"><?= $total ?></td>
                                <td class="tsr-num"><?= $used ?></td>
                                <td class="tsr-num"><?= $free ?></td>
                                <td class="tsr-pct-cell">
                                    <div class="tsr-bar">
                                        <div class="tsr-bar-fill" style="width:<?= $pct ?>%;background:<?= $bar_col ?>"></div>
                                    </div>
                                    <span class="tsr-pct-num"><?= $pct ?>%</span>
                                </td>
                                <td class="tsr-num">
                                    <?php if ( $r['unassigned'] > 0 ) : ?>
                                        <span class="tsr-unassigned-badge"><?= $r['unassigned'] ?> نفر</span>
                                    <?php else : ?>
                                        <span class="tsr-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="tsr-breakdown-cell">
                                    <?= $this->render_breakdown_nested( $r['breakdown'] ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="tsr-foot">
                                <td class="tsr-th-right">جمع کل</td>
                                <td class="tsr-num"><?= $sum_total ?></td>
                                <td class="tsr-num"><?= $sum_used ?></td>
                                <td class="tsr-num"><?= max( 0, $sum_total - $sum_used ) ?></td>
                                <td class="tsr-num"><?= $sum_total > 0 ? round( $sum_used / $sum_total * 100 ) : 0 ?>%</td>
                                <td class="tsr-num"><?= $sum_un ?> نفر</td>
                                <td class="tsr-breakdown-cell"><?= $this->render_breakdown_nested( $sum_breakdown ) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <?php endif; ?>
        </div>

        <style>
        .tsr-wrap{direction:rtl;font-family:inherit;max-width:1100px}
        .tsr-title{font-size:20px;margin-bottom:18px}
        .tsr-filter-form{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;margin-bottom:20px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
        .tsr-filter-label{font-weight:600;font-size:13px;color:#475569}
        .tsr-select{padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;min-width:200px}
        .tsr-btn-apply{padding:6px 14px;border:none;border-radius:8px;background:#3b82f6;color:#fff;cursor:pointer}
        .tsr-empty{background:#fff;border:1px dashed #cbd5e1;border-radius:10px;padding:40px;text-align:center;color:#64748b}
        .tsr-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden}
        .tsr-card-head{padding:14px 18px;border-bottom:1px solid #e2e8f0;font-size:14px;color:#334155;background:#f8fafc}
        .tsr-table-wrap{overflow-x:auto}
        .tsr-table{width:100%;border-collapse:collapse;font-size:13.5px}
        .tsr-table thead th{background:#f1f5f9;color:#475569;font-weight:600;padding:11px 14px;text-align:center;white-space:nowrap;border-bottom:1px solid #e2e8f0}
        .tsr-table td{padding:12px 14px;text-align:center;border-bottom:1px solid #f1f5f9}
        .tsr-th-right{text-align:right !important}
        .tsr-num{font-variant-numeric:tabular-nums;font-weight:600;color:#1e293b}
        .tsr-type-badge{display:inline-block;padding:5px 12px;border-radius:99px;font-weight:700;font-size:13px}
        .tsr-pct-cell{display:flex;align-items:center;gap:8px;justify-content:center}
        .tsr-bar{width:90px;height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden}
        .tsr-bar-fill{height:100%;border-radius:99px}
        .tsr-pct-num{font-weight:700;font-size:12.5px;color:#475569;min-width:32px}
        .tsr-unassigned-badge{display:inline-block;background:#fef2f2;color:#dc2626;font-weight:700;padding:4px 10px;border-radius:8px;font-size:12.5px}
        .tsr-muted{color:#94a3b8}
        .tsr-foot{background:#f8fafc;font-weight:700}
        .tsr-foot td{border-top:2px solid #e2e8f0;border-bottom:none}
        .tsr-breakdown-cell{text-align:right !important;white-space:normal;min-width:220px;vertical-align:top;padding-top:10px;padding-bottom:10px}
        .tsr-bd-msg-line{font-size:12.5px;color:#1e293b;padding:3px 0;font-weight:600}
        .tsr-bd-gender-line{font-size:11.5px;color:#64748b;padding:2px 0 2px 0;padding-right:20px}
        .tsr-bd-icon{display:inline-block;width:16px;text-align:center}
        .tsr-bd-remain{color:#94a3b8;font-weight:500;margin-right:4px}
        </style>
        <?php
    }
    public function export_xlsx(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );

        $terms       = $this->get_terms();
        $f_term      = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : ( $terms[0]->id ?? 0 );
        $cap_by_type = $this->get_capacity_by_type( $f_term );
        $unassigned  = $this->get_unassigned_by_type( $f_term );
        $breakdown   = $this->get_capacity_breakdown( $f_term );

        $term_name = '';
        foreach ( $terms as $t ) {
            if ( (int)$t->id === $f_term ) { $term_name = $t->name; break; }
        }

        $msg_labels = [ 'telegram'=>'تلگرام','bale'=>'بله','eitaa'=>'ایتا','both'=>'همه','whatsapp'=>'واتساپ' ];
        $gen_labels = [ 'male'=>'آقا','female'=>'خانم','both'=>'هر دو' ];

        while ( ob_get_level() ) ob_end_clean();

        $writer = new TA_Xlsx_Writer();
        $writer->set_rtl( true );

        // Sheet 1: summary per course type
        $writer->add_row( [
            'نوع دوره','ظرفیت کل','استفاده‌شده','باقیمانده','درصد پر شدن','تخصیص‌نگرفته (نفر)',
        ], true );

        $sum_t = $sum_u = $sum_un = 0;
        foreach ( self::COURSE_ORDER as $ct ) {
            $total = $cap_by_type[$ct]['total'] ?? 0;
            $used  = $cap_by_type[$ct]['used']  ?? 0;
            $un    = $unassigned[$ct] ?? 0;
            if ( $total===0 && $used===0 && $un===0 ) continue;
            $free = max(0, $total - $used);
            $pct  = $total > 0 ? round($used/$total*100) : 0;
            $sum_t += $total; $sum_u += $used; $sum_un += $un;
            $writer->add_row( [
                self::COURSE_LABELS[$ct] ?? $ct,
                $total, $used, $free,
                $pct . '%',
                $un,
            ] );
        }
        // total row
        $writer->add_row( [
            'جمع کل',
            $sum_t, $sum_u, max(0,$sum_t-$sum_u),
            ($sum_t>0 ? round($sum_u/$sum_t*100) : 0).'%',
            $sum_un,
        ] );

        // blank separator row
        $writer->add_row(['','','','','','']);

        // Section 2: breakdown per course type / messenger / gender
        $writer->add_row( [
            'نوع دوره','پیامرسان','جنسیت','ظرفیت','استفاده','باقیمانده',
        ], true );

        foreach ( self::COURSE_ORDER as $ct ) {
            $bd = $breakdown[$ct] ?? [];
            if ( empty($bd) ) continue;
            foreach ( $bd as $msg => $md ) {
                foreach ( ($md['by_gender'] ?? []) as $gen => $gd ) {
                    $t = (int)$gd['total']; $u = (int)$gd['used'];
                    $writer->add_row( [
                        self::COURSE_LABELS[$ct] ?? $ct,
                        $msg_labels[$msg] ?? $msg,
                        $gen_labels[$gen] ?? $gen,
                        $t, $u, max(0,$t-$u),
                    ] );
                }
            }
        }

        $fn = 'summary-report-' . ($term_name ? sanitize_file_name($term_name) . '-' : '') . date('Y-m-d') . '.xlsx';
        $writer->output( $fn );
        exit;
    }

}