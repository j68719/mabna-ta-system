<?php
defined( 'ABSPATH' ) || exit;

/**
 * پورتال هنرجو
 * شورت‌کد: [ta_student_portal]
 */
class TA_Student_Portal {

    public function __construct() {
        add_shortcode( 'ta_student_portal', [ $this, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function enqueue(): void {
        if ( ! is_page() ) return;
        global $post;
        if ( $post && has_shortcode( $post->post_content, 'ta_student_portal' ) ) {
            wp_enqueue_style( 'ta-system', TA_SYSTEM_URL . 'assets/css/ta-system.css', [], ta_system_asset_ver( 'assets/css/ta-system.css' ) );
        }
    }

    public function render_shortcode(): string {
        if ( ! is_user_logged_in() ) {
            return '<div class="ta-student-portal" dir="rtl"><p>برای مشاهده وضعیت خود ابتدا <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">وارد سایت شوید</a>.</p></div>';
        }
        if ( ! TA_Database::tables_exist() ) {
            return '<div class="ta-student-portal" dir="rtl"><p>سیستم در حال راه‌اندازی است.</p></div>';
        }

        $uid = get_current_user_id();
        ob_start();
        $this->render( $uid );
        return ob_get_clean();
    }

    private function render( int $uid ): void {
        $tab = sanitize_key( $_GET['ta_portal_tab'] ?? 'courses' );
        if ( ! in_array( $tab, [ 'courses', 'critique' ], true ) ) {
            $tab = 'courses';
        }
        ?>
        <div class="ta-student-portal" dir="rtl">
            <div class="ta-portal-tabs">
                <a href="<?= esc_url( add_query_arg( 'ta_portal_tab', 'courses' ) ) ?>" class="ta-portal-tab <?= $tab === 'courses' ? 'active' : '' ?>">📚 دوره‌ها</a>
                <a href="<?= esc_url( add_query_arg( 'ta_portal_tab', 'critique' ) ) ?>" class="ta-portal-tab <?= $tab === 'critique' ? 'active' : '' ?>">📝 نقد آثار</a>
            </div>
            <div class="ta-portal-tab-content">
                <?php
                if ( $tab === 'critique' ) {
                    $this->render_critique_tab( $uid );
                } else {
                    $this->render_courses_tab( $uid );
                }
                ?>
            </div>
        </div>
        <style>
        .ta-portal-tabs{display:flex;gap:6px;border-bottom:2px solid #e5e7eb;margin-bottom:18px}
        .ta-portal-tab{padding:9px 18px;font-size:13px;font-weight:700;color:#64748b;text-decoration:none;border-radius:8px 8px 0 0;margin-bottom:-2px;border:2px solid transparent}
        .ta-portal-tab.active{color:#4338ca;background:#f5f3ff;border-color:#e5e7eb;border-bottom-color:#f5f3ff}
        .ta-portal-tab:hover{color:#4338ca}
        </style>
        <?php
    }

    /** تب «نقد آثار»: سفارش‌های نقد اثر هنرجو، وضعیتشان، لینک گروه بله استاد و ارزیابی */
    private function render_critique_tab( int $uid ): void {
        if ( ! class_exists( 'TA_Critique_Order' ) ) {
            echo '<p style="color:#888">ماژول نقد آثار در دسترس نیست.</p>';
            return;
        }
        TA_Critique_Order::render_orders_panel( $uid, true );
    }

    /** تب «دوره‌ها»: وضعیت دوره‌های هنرجو نزد استادیار (محتوای قبلی این پیشخوان) */
    private function render_courses_tab( int $uid ): void {
        global $wpdb;

        $assign_table   = TA_Database::table( 'assignments' );
        $grade_table    = TA_Database::table( 'grades' );
        $ta_table       = TA_Database::table( 'assistants' );
        $terms_table    = TA_Database::table( 'terms' );

        // ── تخصیص‌های موجود ─────────────────────────────────────────────────
        $assignments = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.*, tu.display_name AS ta_name,
                    t.name AS term_name,
                    p.post_title AS product_name,
                    g.grade
             FROM {$assign_table} a
             JOIN {$ta_table}         ta  ON ta.id  = a.ta_id
             JOIN {$wpdb->users}      tu  ON tu.ID  = ta.user_id
             LEFT JOIN {$terms_table} t   ON t.id   = a.term_id
             LEFT JOIN {$wpdb->posts} p   ON p.ID   = a.product_id
             LEFT JOIN {$grade_table} g   ON g.student_id = a.student_id
                                         AND g.product_id = a.product_id
             WHERE a.student_id = %d
             ORDER BY a.created_at DESC",
            [ $uid ]
        ) ) ?: [];

        // ── محصولات ثبت‌نام‌شده که هنوز استادیار انتخاب نشده ────────────────
        $pending = $this->get_pending_products( $uid );

        $course_labels = [
            'creative' => 'نویسندگی خلاق',
            'intro'    => 'نویسندگی مقدماتی',
            'advanced' => 'نویسندگی پیشرفته',
        ];
        $m_labels = [ 'telegram' => 'تلگرام', 'bale' => 'بله', 'eitaa' => 'ایتا' ];
        ?>
            <?php if ( ! empty( $pending ) ) : ?>
            <div class="ta-pending-box">
                <h3>⚡ اقدام لازم: انتخاب استادیار</h3>
                <p>دوره‌های زیر را ثبت‌نام کرده‌اید اما هنوز استادیار انتخاب نکرده‌اید.<br>
                برای انتخاب استادیار روی دکمه مقابل هر دوره کلیک کنید:</p>
                <ul class="ta-pending-list">
                    <?php foreach ( $pending as $pp ) : ?>
                    <li>
                        <span class="ta-pending-course">
                            <strong><?= esc_html( $pp->product_name ) ?></strong>
                            <?php if ( ! empty( $pp->term_name ) ) : ?>
                                <span class="ta-badge ta-badge-pending"><?= esc_html( $pp->term_name ) ?></span>
                            <?php endif; ?>
                            <small><?= $course_labels[ $pp->course_type ] ?? '' ?></small>
                        </span>
                        <a href="<?= esc_url( get_permalink( $pp->product_id ) ) ?>"
                           class="ta-btn ta-btn-primary">
                            👈 انتخاب استادیار
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <h3>وضعیت دوره‌های من</h3>

            <?php if ( empty( $assignments ) ) : ?>
                <p style="color:#636e72; padding:20px 0">
                    <?php if ( ! empty( $pending ) ) : ?>
                        پس از انتخاب استادیار، اطلاعات دوره در این جدول نمایش داده می‌شود.
                    <?php else : ?>
                        هنوز هیچ دوره‌ای در سیستم استادیار ثبت نشده است.
                    <?php endif; ?>
                </p>
            <?php else : ?>
            <table class="ta-table">
                <thead>
                    <tr>
                        <th>دوره</th><th>ترم</th><th>استادیار</th>
                        <th>پیامرسان</th><th>گروه</th><th>چت Grupo</th><th>نمره</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $assignments as $a ) : ?>
                    <tr>
                        <td>
                            <?= $course_labels[ $a->course_type ] ?? esc_html( $a->course_type ) ?><br>
                            <small style="color:#636e72"><?= esc_html( $a->product_name ) ?></small>
                        </td>
                        <td><?= esc_html( $a->term_name ?? '—' ) ?></td>
                        <td><?= esc_html( $a->ta_name ) ?></td>
                        <td><?= $m_labels[ $a->messenger ] ?? esc_html( $a->messenger ?? '—' ) ?></td>
                        <td>
                            <?php if ( $a->group_link ) : ?>
                                <a href="<?= esc_url( TA_URL_Helper::build_join_url( (int) $a->id ) ) ?>" target="_blank"
                                   class="ta-btn ta-btn-sm ta-btn-primary">ورود به گروه</a>
                            <?php else : ?>
                                <span style="color:#b2bec3">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( class_exists( 'TA_Grupo_Chat' ) && TA_Grupo_Chat::enabled() && $a->status !== 'canceled' ) : ?>
                                <a href="<?= esc_url( TA_Grupo_Chat::build_join_url( (int) $a->id ) ) ?>" target="_blank" rel="noopener"
                                   class="ta-btn ta-btn-sm ta-btn-primary" style="background:#7c3aed;color:#fff!important">ورود به چت</a>
                            <?php else : ?>
                                <span style="color:#b2bec3">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $a->grade ) : ?>
                                <span class="ta-badge ta-badge-<?= esc_attr( $a->grade ) ?>">
                                    <?= TA_Grade::grade_label( $a->grade ) ?>
                                </span>
                            <?php else : ?>
                                <span style="color:#b2bec3">در انتظار نمره</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        <?php
    }

    /**
     * پیدا کردن محصولاتی که هنرجو خریده ولی هنوز استادیار انتخاب نکرده
     */
    private function get_pending_products( int $uid ): array {
        global $wpdb;

        $settings_table = TA_Database::table( 'product_settings' );
        $assign_table   = TA_Database::table( 'assignments' );
        $terms_table    = TA_Database::table( 'terms' );

        if ( ! function_exists( 'wc_get_orders' ) ) {
            return [];
        }
        $orders = wc_get_orders( [
            'customer_id' => $uid,
            'status'      => [ 'wc-completed', 'wc-processing', 'wc-partial-payment' ],
            'limit'       => -1,
        ] );

        if ( empty( $orders ) ) {
            return [];
        }

        $purchased = [];
        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $pid = (int) $item->get_product_id();
                if ( $pid ) {
                    $purchased[ $pid ] = true;
                }
            }
        }

        if ( empty( $purchased ) ) {
            return [];
        }

        $pending = [];
        foreach ( array_keys( $purchased ) as $pid ) {

            // آیا این محصول در سیستم استادیار پیکربندی شده؟
            $setting = $wpdb->get_row( $wpdb->prepare(
                "SELECT ps.*, t.name AS term_name, p.post_title AS product_name
                 FROM {$settings_table} ps
                 LEFT JOIN {$terms_table} t ON t.id = ps.term_id
                 LEFT JOIN {$wpdb->posts} p ON p.ID = ps.product_id
                 WHERE ps.product_id = %d",
                [ $pid ]
            ) );

            if ( ! $setting ) {
                continue;
            }

            // آیا قبلاً استادیار انتخاب کرده؟
            $assigned = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$assign_table} WHERE student_id = %d AND product_id = %d",
                [ $uid, $pid ]
            ) );

            if ( ! $assigned ) {
                $pending[] = $setting;
            }
        }

        return $pending;
    }
}
