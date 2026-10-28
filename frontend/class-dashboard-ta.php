<?php
defined( 'ABSPATH' ) || exit;

/**
 * داشبورد فرانت‌اند استادیار
 * با شورت‌کد [ta_dashboard]
 */
class TA_Dashboard_TA {

    public function __construct() {
        add_shortcode( 'ta_dashboard', [ $this, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets(): void {
        if ( ! is_page() ) {
            return;
        }
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'ta_dashboard' ) ) {
            return;
        }

        // [SECURITY-FIX-IDOR] قبلاً این nonce برای هر کاربر لاگین‌شده‌ای (حتی هنرجوها)
        // که فقط وارد صفحه‌ی داشبورد می‌شد تولید و در HTML چاپ می‌شد، در حالی‌که
        // پیام «دسترسی ندارید» فقط در خروجی شورت‌کد نمایش داده می‌شد و اسکریپت/nonce
        // از قبل enqueue شده بودند. اکنون فقط برای استادیارهای واقعی بارگذاری می‌شود.
        if ( ! is_user_logged_in() || ! TA_Roles::is_ta( get_current_user_id() ) ) {
            return;
        }

        wp_enqueue_style( 'ta-system', TA_SYSTEM_URL . 'assets/css/ta-system.css', [], ta_system_asset_ver( 'assets/css/ta-system.css' ) );
        wp_enqueue_script( 'ta-system', TA_SYSTEM_URL . 'assets/js/ta-system.js', [ 'jquery' ], ta_system_asset_ver( 'assets/js/ta-system.js' ), true );
        $all_msg_labels_js = ['telegram'=>'تلگرام','bale'=>'بله','eitaa'=>'ایتا'];
        $all_msg_icons_js  = ['telegram'=>'✈','bale'=>'🔵','eitaa'=>'🟢'];
        $active_msgs_js = TA_Settings_Helper::get( 'supported_messengers', ['telegram','bale','eitaa'] );
        if ( ! is_array( $active_msgs_js ) || empty( $active_msgs_js ) ) $active_msgs_js = ['telegram','bale','eitaa'];
        $active_msgs_js = array_values( array_intersect( $active_msgs_js, array_keys( $all_msg_labels_js ) ) );
        wp_localize_script( 'ta-system', 'taSystem', [
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'ta_dashboard_nonce' ),
            'isDashboard'     => 1,
            'messengerLabels' => array_intersect_key( $all_msg_labels_js, array_flip( $active_msgs_js ) ),
            'messengerIcons'  => array_intersect_key( $all_msg_icons_js,  array_flip( $active_msgs_js ) ),
        ] );
    }

    public function render_shortcode(): string {
        if ( ! is_user_logged_in() ) {
            return '<p>برای دسترسی به داشبورد باید <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">وارد شوید</a>.</p>';
        }

        $uid = get_current_user_id();
        if ( ! TA_Roles::is_ta( $uid ) ) {
            return '<p>شما دسترسی به این بخش ندارید.</p>';
        }

        $ta_table = TA_Database::table( 'assistants' );
        $ta = TA_Database::get_row( "SELECT * FROM {$ta_table} WHERE user_id = %d", [ $uid ] );
        if ( ! $ta ) {
            return '<p>اطلاعات شما به عنوان استادیار یافت نشد. با مسئول اجرایی مبنا تماس بگیرید.</p>';
        }

        $tab = sanitize_text_field( $_GET['ta_tab'] ?? 'overview' );

        ob_start();
        $this->render_dashboard( $ta, $tab );
        return ob_get_clean();
    }

    private function render_dashboard( object $ta, string $tab ): void {
        $tabs = [
            'overview'       => 'خلاصه',
            'capacity'       => 'ظرفیت و گروه‌ها',
            'cooperation_report' => 'گزارش همکاری',
            'students'       => 'هنرجویان',
            'grades'         => '🎓 نمره‌ها',
            'payroll'        => '💰 حق‌الزحمه',
            'story_critique' => '✍️ نقد آثار',
            'critique_orders'=> '📋 سفارش‌های نقد آثار',
            'blacklist'      => 'لیست سیاه',
        ];
        ?>
        <div class="ta-dashboard" dir="rtl">
            <div class="ta-dashboard-header">
                <h2>داشبورد استادیار — <?= esc_html( wp_get_current_user()->display_name ) ?></h2>
            </div>

            <nav class="ta-tabs">
                <?php foreach ( $tabs as $key => $label ) :
                    $url = add_query_arg( 'ta_tab', $key );
                    $active = $tab === $key ? ' ta-tab-active' : '';
                ?>
                    <a href="<?= esc_url( $url ) ?>" class="ta-tab<?= $active ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="ta-dashboard-content">
                <?php
                match ( $tab ) {
                    'capacity'       => $this->tab_capacity( $ta ),
                    'cooperation_report' => $this->tab_cooperation_report( $ta ),
                    'students'       => $this->tab_students( $ta ),
                    'grades'         => $this->tab_grades( $ta ),
                    'payroll'        => $this->tab_payroll( $ta ),
                    'story_critique' => $this->tab_story_critique( $ta ),
                    'critique_orders'=> $this->tab_critique_orders( $ta ),
                    'blacklist'      => $this->tab_blacklist( $ta ),
                    default          => $this->tab_overview( $ta ),
                };
                ?>
            </div>
        </div>
        <?php
    }

    // ── Overview ─────────────────────────────────────────────────────────────
    private function tab_overview( object $ta ): void {
        ?>
        <div class="ta-overview-wrapper" id="ta-overview-root">

            <!-- ── بارگذاری اولیه ── -->
            <div class="ta-overview-loading" id="ta-overview-loading">
                <div class="ta-spinner"></div>
                <span>در حال بارگذاری آمار…</span>
            </div>

            <!-- ── محتوای اصلی (پنهان تا بارگذاری) ── -->
            <div id="ta-overview-content" style="display:none">

                <!-- کارت‌های خلاصه کلی -->
                <div class="ta-ov-global-cards" id="ta-ov-global-cards"></div>

                <!-- نوار پر شدن ظرفیت کلی -->
                <div class="ta-ov-fill-bar-wrap">
                    <div class="ta-ov-fill-bar-label">
                        <span>پر شدن ظرفیت کل</span>
                        <span id="ta-ov-fill-pct" class="ta-ov-fill-pct-val">۰٪</span>
                    </div>
                    <div class="ta-ov-fill-track">
                        <div class="ta-ov-fill-thumb" id="ta-ov-fill-thumb" style="width:0%"></div>
                    </div>
                </div>

                <!-- آمار نمرات کلی -->
                <div class="ta-ov-grades-row" id="ta-ov-grades-row"></div>

                <!-- سربرگ ترم‌ها -->
                <div class="ta-ov-section-title">
                    <span class="ta-ov-section-icon">📅</span>
                    آمار به تفکیک ترم
                </div>

                <!-- آکاردئون ترم‌ها -->
                <div id="ta-ov-terms-list"></div>

            </div>
        </div>

        <style>
        .ta-overview-wrapper { padding: 0; }
        .ta-overview-loading {
            display: flex; align-items: center; gap: 12px;
            padding: 40px 0; color: #636e72; font-size: 0.95rem;
        }
        .ta-spinner {
            width: 22px; height: 22px; border: 3px solid #e0e0e0;
            border-top-color: var(--ta-primary); border-radius: 50%;
            animation: ta-spin 0.8s linear infinite;
        }
        @keyframes ta-spin { to { transform: rotate(360deg); } }
        .ta-ov-global-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 14px; margin-bottom: 20px;
        }
        .ta-ov-kpi {
            background: #fff; border: 1.5px solid #f0f0f0; border-radius: 14px;
            padding: 18px 16px 14px; text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,.04);
            transition: transform .2s, box-shadow .2s; position: relative; overflow: hidden;
        }
        .ta-ov-kpi:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(0,0,0,.09); }
        .ta-ov-kpi::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
            background: var(--kpi-color, var(--ta-primary)); border-radius: 14px 14px 0 0;
        }
        .ta-ov-kpi-icon { font-size: 1.6rem; line-height: 1; margin-bottom: 8px; }
        .ta-ov-kpi-val  { font-size: 2rem; font-weight: 800; line-height: 1; color: var(--kpi-color, #2d3436); margin-bottom: 4px; }
        .ta-ov-kpi-label { font-size: 0.78rem; color: #636e72; font-weight: 500; }
        .ta-ov-fill-bar-wrap { margin-bottom: 22px; }
        .ta-ov-fill-bar-label {
            display: flex; justify-content: space-between; align-items: center;
            font-size: 0.85rem; color: #636e72; margin-bottom: 6px; font-weight: 600;
        }
        .ta-ov-fill-pct-val { font-size: 1rem; font-weight: 800; color: #2d3436; }
        .ta-ov-fill-track { height: 10px; background: #f0f0f0; border-radius: 99px; overflow: hidden; }
        .ta-ov-fill-thumb {
            height: 100%; border-radius: 99px;
            background: linear-gradient(90deg, var(--ta-primary), #f2965f);
            transition: width 1s cubic-bezier(.4,0,.2,1);
        }
        .ta-ov-grades-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 28px; }
        .ta-ov-grade-chip {
            display: flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: 99px; font-size: 0.82rem; font-weight: 700;
            border: 1.5px solid transparent;
        }
        .ta-ov-grade-chip.pass     { background: #d4edda; color: #155724; border-color: #c3e6cb; }
        .ta-ov-grade-chip.repeat   { background: #fff3cd; color: #856404; border-color: #ffeeba; }
        .ta-ov-grade-chip.critique { background: #cce5ff; color: #004085; border-color: #b8daff; }
        .ta-ov-section-title {
            font-size: 1rem; font-weight: 700; color: #2d3436;
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 14px; padding-bottom: 8px; border-bottom: 2px solid #f0f0f0;
        }
        .ta-ov-section-icon { font-size: 1.2rem; }
        .ta-ov-term-card {
            background: #fff; border: 1.5px solid #efefef; border-radius: 12px;
            margin-bottom: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.04);
        }
        .ta-ov-term-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 18px; cursor: pointer; user-select: none; transition: background .15s;
        }
        .ta-ov-term-header:hover { background: #fafafa; }
        .ta-ov-term-header-left { display: flex; align-items: center; gap: 10px; }
        .ta-ov-term-name { font-weight: 700; font-size: 0.95rem; color: #2d3436; }
        .ta-ov-term-badge {
            font-size: 0.72rem; font-weight: 700; padding: 2px 9px; border-radius: 99px;
            background: #fdece3; color: var(--ta-primary);
        }
        .ta-ov-term-summary { display: flex; gap: 16px; align-items: center; }
        .ta-ov-term-mini-stat { font-size: 0.78rem; color: #636e72; display: flex; align-items: center; gap: 4px; }
        .ta-ov-term-mini-stat strong { color: #2d3436; font-weight: 700; }
        .ta-ov-term-chevron { font-size: 0.85rem; color: #b2bec3; transition: transform .25s; }
        .ta-ov-term-card.open .ta-ov-term-chevron { transform: rotate(180deg); }
        .ta-ov-term-fill-track { height: 4px; background: #f0f0f0; border-radius: 0; }
        .ta-ov-term-fill-thumb {
            height: 100%; background: linear-gradient(90deg, #00b894, #55efc4);
            transition: width 1.2s cubic-bezier(.4,0,.2,1);
        }
        .ta-ov-term-fill-thumb.warn { background: linear-gradient(90deg, #fdcb6e, #ffeaa7); }
        .ta-ov-term-fill-thumb.full { background: linear-gradient(90deg, #d63031, #ff7675); }
        .ta-ov-term-body { max-height: 0; overflow: hidden; transition: max-height .35s ease; border-top: 0; }
        .ta-ov-term-card.open .ta-ov-term-body { max-height: 800px; border-top: 1px solid #f0f0f0; }
        .ta-ov-term-body-inner { padding: 16px 18px 18px; }
        .ta-ov-term-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 560px) { .ta-ov-term-cols { grid-template-columns: 1fr; } }
        .ta-ov-sub-title { font-size: 0.78rem; font-weight: 700; color: #b2bec3; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 8px; }
        .ta-ov-stat-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 5px 0; border-bottom: 1px dashed #f0f0f0; font-size: 0.84rem;
        }
        .ta-ov-stat-row:last-child { border-bottom: none; }
        .ta-ov-stat-row-label { color: #636e72; }
        .ta-ov-stat-row-val { font-weight: 700; color: #2d3436; }
        .ta-ov-stat-row-val.green  { color: #00b894; }
        .ta-ov-stat-row-val.red    { color: #d63031; }
        .ta-ov-stat-row-val.blue   { color: #0984e3; }
        .ta-ov-grade-chips-sm { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
        .ta-ov-grade-chip-sm {
            display: flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 99px; font-size: 0.76rem; font-weight: 700;
        }
        .ta-ov-grade-chip-sm.pass     { background: #d4edda; color: #155724; }
        .ta-ov-grade-chip-sm.repeat   { background: #fff3cd; color: #856404; }
        .ta-ov-grade-chip-sm.critique { background: #cce5ff; color: #004085; }
        .ta-ov-grade-chip-sm.pending  { background: #f8f9fa; color: #888; }
        .ta-ov-course-pills { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
        .ta-ov-course-pill {
            display: flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 8px; font-size: 0.76rem; font-weight: 600;
            background: #f0f4f8; color: #495057; border: 1px solid #e2e8f0;
        }
        .ta-ov-course-pill .pill-count { font-weight: 800; color: #2d3436; }
        .ta-ov-empty { text-align: center; padding: 40px; color: #b2bec3; font-size: 0.9rem; }
        </style>

        <script>
        (function() {
            const toFa = n => String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
            const gradeLabel = { pass: 'قبولی', repeat: 'تکرار دوره', critique: 'نقد اثر', pending: 'ثبت‌نشده' };
            const courseLabel = { creative: 'خلاق', intro: 'مقدماتی', advanced: 'پیشرفته', professional: 'حرفه‌ای', critique: 'نقد اثر' };

            function kpiCard(icon, val, label, color) {
                return '<div class="ta-ov-kpi" style="--kpi-color:' + color + '">'
                    + '<div class="ta-ov-kpi-icon">' + icon + '</div>'
                    + '<div class="ta-ov-kpi-val">' + toFa(val) + '</div>'
                    + '<div class="ta-ov-kpi-label">' + label + '</div>'
                    + '</div>';
            }

            function renderGlobal(g) {
                document.getElementById('ta-ov-global-cards').innerHTML =
                    kpiCard('👥', g.total_students, 'کل هنرجویان', '#6c5ce7') +
                    kpiCard('✅', g.active_count,    'هنرجویان فعال', '#00b894') +
                    kpiCard('🎓', g.completed_count, 'تکمیل‌شده', '#0984e3') +
                    kpiCard('❌', g.canceled_count,  'انصرافی', '#d63031') +
                    kpiCard('📦', g.total_cap,       'کل ظرفیت', '#fdcb6e') +
                    kpiCard('🔓', g.remaining_cap,   'ظرفیت آزاد', '#55efc4');

                document.getElementById('ta-ov-fill-pct').textContent = toFa(g.fill_pct) + '٪';
                setTimeout(function() {
                    document.getElementById('ta-ov-fill-thumb').style.width = g.fill_pct + '%';
                }, 50);

                var gRow = document.getElementById('ta-ov-grades-row');
                var grades = g.grades || {};
                var html = '';
                if (grades.pass)     html += '<span class="ta-ov-grade-chip pass">' + gradeLabel.pass + ' <strong>' + toFa(grades.pass) + '</strong></span>';
                if (grades.repeat)   html += '<span class="ta-ov-grade-chip repeat">' + gradeLabel.repeat + ' <strong>' + toFa(grades.repeat) + '</strong></span>';
                if (grades.critique) html += '<span class="ta-ov-grade-chip critique">' + gradeLabel.critique + ' <strong>' + toFa(grades.critique) + '</strong></span>';
                if (!html) html = '<span style="color:#b2bec3;font-size:0.85rem">هنوز نمره‌ای ثبت نشده است.</span>';
                gRow.innerHTML = '<span style="font-size:0.82rem;font-weight:600;color:#636e72;margin-left:6px">نمرات کل:</span>' + html;
            }

            function fillColor(pct) {
                if (pct >= 90) return 'full';
                if (pct >= 60) return 'warn';
                return '';
            }

            function renderTerms(terms) {
                var list = document.getElementById('ta-ov-terms-list');
                if (!terms.length) {
                    list.innerHTML = '<div class="ta-ov-empty">هیچ ترمی یافت نشد.</div>';
                    return;
                }
                var out = '';
                terms.forEach(function(t) {
                    var fc = fillColor(t.fill_pct);
                    var coursePills = '';
                    var courses = t.courses || {};
                    Object.keys(courses).forEach(function(type) {
                        var row = courses[type];
                        var label = courseLabel[type] || type;
                        coursePills += '<span class="ta-ov-course-pill">' + label + ' <span class="pill-count">' + toFa(row.cnt) + '</span></span>';
                    });
                    if (!coursePills) coursePills = '<span style="color:#b2bec3;font-size:0.78rem">—</span>';

                    var gradeChips = '';
                    var grades = t.grades || {};
                    if (grades.pass)     gradeChips += '<span class="ta-ov-grade-chip-sm pass">' + gradeLabel.pass + ' ' + toFa(grades.pass) + '</span>';
                    if (grades.repeat)   gradeChips += '<span class="ta-ov-grade-chip-sm repeat">' + gradeLabel.repeat + ' ' + toFa(grades.repeat) + '</span>';
                    if (grades.critique) gradeChips += '<span class="ta-ov-grade-chip-sm critique">' + gradeLabel.critique + ' ' + toFa(grades.critique) + '</span>';
                    if (!gradeChips)     gradeChips = '<span class="ta-ov-grade-chip-sm pending">بدون نمره</span>';

                    var remClass = t.remaining_cap > 0 ? 'green' : 'red';
                    var canClass = t.canceled_count > 0 ? 'red' : '';

                    out += '<div class="ta-ov-term-card" id="ta-term-card-' + t.id + '">'
                        + '<div class="ta-ov-term-fill-track"><div class="ta-ov-term-fill-thumb ' + fc + '" id="ta-tfill-' + t.id + '" style="width:0%"></div></div>'
                        + '<div class="ta-ov-term-header" onclick="taOvToggleTerm(' + t.id + ')">'
                        +   '<div class="ta-ov-term-header-left">'
                        +     '<span class="ta-ov-term-name">' + t.name + '</span>'
                        +     '<span class="ta-ov-term-badge">' + toFa(t.fill_pct) + '٪ پر</span>'
                        +   '</div>'
                        +   '<div class="ta-ov-term-summary">'
                        +     '<span class="ta-ov-term-mini-stat">👥 <strong>' + toFa(t.total_students) + '</strong> هنرجو</span>'
                        +     '<span class="ta-ov-term-mini-stat">📦 <strong>' + toFa(t.used_cap) + '/' + toFa(t.total_cap) + '</strong></span>'
                        +     '<span class="ta-ov-term-chevron">▼</span>'
                        +   '</div>'
                        + '</div>'
                        + '<div class="ta-ov-term-body" id="ta-tbody-' + t.id + '">'
                        +   '<div class="ta-ov-term-body-inner">'
                        +     '<div class="ta-ov-term-cols">'
                        +       '<div>'
                        +         '<div class="ta-ov-sub-title">ظرفیت و وضعیت</div>'
                        +         '<div class="ta-ov-stat-row"><span class="ta-ov-stat-row-label">کل ظرفیت</span><span class="ta-ov-stat-row-val">' + toFa(t.total_cap) + '</span></div>'
                        +         '<div class="ta-ov-stat-row"><span class="ta-ov-stat-row-label">استفاده‌شده</span><span class="ta-ov-stat-row-val blue">' + toFa(t.used_cap) + '</span></div>'
                        +         '<div class="ta-ov-stat-row"><span class="ta-ov-stat-row-label">ظرفیت آزاد</span><span class="ta-ov-stat-row-val ' + remClass + '">' + toFa(t.remaining_cap) + '</span></div>'
                        +         '<div class="ta-ov-stat-row"><span class="ta-ov-stat-row-label">هنرجوی فعال</span><span class="ta-ov-stat-row-val green">' + toFa(t.active_count) + '</span></div>'
                        +         '<div class="ta-ov-stat-row"><span class="ta-ov-stat-row-label">تکمیل‌شده</span><span class="ta-ov-stat-row-val blue">' + toFa(t.completed_count) + '</span></div>'
                        +         '<div class="ta-ov-stat-row"><span class="ta-ov-stat-row-label">انصرافی</span><span class="ta-ov-stat-row-val ' + canClass + '">' + toFa(t.canceled_count) + '</span></div>'
                        +       '</div>'
                        +       '<div>'
                        +         '<div class="ta-ov-sub-title">نوع دوره‌ها</div>'
                        +         '<div class="ta-ov-course-pills">' + coursePills + '</div>'
                        +         '<div class="ta-ov-sub-title" style="margin-top:14px">نمرات</div>'
                        +         '<div class="ta-ov-grade-chips-sm">' + gradeChips + '</div>'
                        +       '</div>'
                        +     '</div>'
                        +   '</div>'
                        + '</div>'
                        + '</div>';
                });
                list.innerHTML = out;

                terms.forEach(function(t, i) {
                    setTimeout(function() {
                        var el = document.getElementById('ta-tfill-' + t.id);
                        if (el) el.style.width = t.fill_pct + '%';
                    }, 100 + i * 80);
                });
            }

            window.taOvToggleTerm = function(id) {
                var card = document.getElementById('ta-term-card-' + id);
                var body = document.getElementById('ta-tbody-' + id);
                var isOpen = card.classList.contains('open');
                document.querySelectorAll('.ta-ov-term-card.open').forEach(function(c) {
                    c.classList.remove('open');
                    c.querySelector('.ta-ov-term-body').style.maxHeight = '0';
                });
                if (!isOpen) {
                    card.classList.add('open');
                    body.style.maxHeight = body.scrollHeight + 'px';
                }
            };

            function loadStats() {
                jQuery.post(taSystem.ajaxUrl, {
                    action: 'ta_get_term_stats',
                    nonce:  taSystem.nonce
                }, function(res) {
                    document.getElementById('ta-overview-loading').style.display = 'none';
                    document.getElementById('ta-overview-content').style.display  = 'block';
                    if (res.success) {
                        renderGlobal(res.data.global);
                        renderTerms(res.data.terms);
                        if (res.data.terms.length) {
                            setTimeout(function() { taOvToggleTerm(res.data.terms[0].id); }, 200);
                        }
                    }
                }).fail(function() {
                    document.getElementById('ta-overview-loading').innerHTML =
                        '<span style="color:#d63031">خطا در بارگذاری آمار. صفحه را بازخوانی کنید.</span>';
                });
            }

            if (typeof jQuery !== 'undefined' && typeof taSystem !== 'undefined') {
                loadStats();
            } else {
                document.addEventListener('DOMContentLoaded', loadStats);
            }
        })();
        </script>
        <?php
    }

    // ── ظرفیت‌ها و مسیر همکاری ────────────────────────────────────────────────
    private function tab_capacity( object $ta ): void {
        $term_table = TA_Database::table( 'terms' );
        $terms = TA_Database::get_results( "SELECT * FROM {$term_table} ORDER BY created_at DESC, id DESC" );
        $active_term_id = absint( $_GET['ta_term'] ?? 0 );
        if ( ! $active_term_id ) $active_term_id = (int) get_option( 'ta_active_term_id', 0 );
        if ( ! $active_term_id && ! empty( $terms ) ) $active_term_id = (int) $terms[0]->id;
        $active_term = null; foreach ( $terms as $term ) if ( (int) $term->id === $active_term_id ) $active_term = $term;
        $can_edit_capacity = (bool) get_option( 'ta_term_allow_capacity_edit_' . $active_term_id, 1 );
        $coop_required = (bool) get_option( 'ta_term_require_cooperation_' . $active_term_id, 1 );
        $types = [
            'creative'=>['label'=>'خلاق','icon'=>'✍️','tone'=>'violet'], 'basic_writing'=>['label'=>'پایه','icon'=>'📝','tone'=>'cyan'],
            'intro'=>['label'=>'مقدماتی','icon'=>'🌱','tone'=>'green'], 'advanced'=>['label'=>'پیشرفته','icon'=>'🚀','tone'=>'orange'],
            'professional'=>['label'=>'حرفه‌ای','icon'=>'🏆','tone'=>'amber'], 'ladder'=>['label'=>'نردبان حرفه‌ای','icon'=>'🪜','tone'=>'blue'], 'critique'=>['label'=>'نقد اثر','icon'=>'🔍','tone'=>'pink'],
        ];
        $group_allowed = ['creative','basic_writing','critique','professional']; $single_group = ['critique','professional'];
        $gender_labels = ['male'=>'آقایان','female'=>'خانم‌ها','both'=>'هر دو'];
        $messenger_labels = ['telegram'=>'تلگرام','bale'=>'بله','eitaa'=>'ایتا','both'=>'فرقی ندارد'];
        $messenger_icons = ['telegram'=>'✈️','bale'=>'🔵','eitaa'=>'🟢','both'=>'🔀'];
        $active_messengers = TA_Settings_Helper::get('supported_messengers',['telegram','bale','eitaa']); if (!is_array($active_messengers)||empty($active_messengers)) $active_messengers=['telegram','bale','eitaa'];
        $cap_table=TA_Database::table('capacities'); $gr_table=TA_Database::table('groups');
        $rows=$active_term_id?TA_Database::get_results("SELECT * FROM {$cap_table} WHERE ta_id=%d AND term_id=%d ORDER BY course_type, student_gender, messenger, id",[$ta->id,$active_term_id]):[];
        $by_type=[]; foreach($rows as $row)$by_type[$row->course_type][]=$row;
        $cooperation=get_option('ta_cooperation_'.(int)$ta->id.'_'.$active_term_id,['mode'=>'','commitment'=>0]); if(!is_array($cooperation))$cooperation=['mode'=>'','commitment'=>0];
        $commitment_checked = in_array( strtolower( trim( (string) ( $cooperation['commitment'] ?? '' ) ) ), [ '1', 'true', 'yes', 'on' ], true );
        $nonce=wp_create_nonce('ta_dashboard_nonce');
        ?>
        <section class="ta-capacity-modern ta-capacity-full" dir="rtl">
          <div class="ta-capacity-hero"><div><span class="ta-eyebrow">مدیریت ظرفیت</span><h3>ظرفیت‌های کمپین را یکجا تنظیم کنید</h3><p>ظرفیت هر دوره را بر اساس جنسیت و پیام‌رسان مدیریت کنید؛ همه تغییرات را در پایان یکجا ذخیره کنید.</p></div><div class="ta-capacity-term"><label>ترم کمپین</label><select class="ta-capacity-select" onchange="var u='<?= esc_js(remove_query_arg(['ta_tab','ta_term'])) ?>';window.location.href=u+(u.indexOf('?')===-1?'?':'&')+'ta_tab=capacity&ta_term='+encodeURIComponent(this.value)"><?php foreach($terms as $term): ?><option value="<?= (int)$term->id ?>" <?= selected($active_term_id,$term->id,false) ?>><?= esc_html($term->name) ?><?= (int)$term->id===$active_term_id?' — فعال':'' ?></option><?php endforeach; ?></select><small>پیش‌فرض: جدیدترین ترم ایجادشده</small></div></div>
          <?php if(!$active_term): ?><div class="ta-capacity-empty">هنوز ترمی تعریف نشده است.</div><?php else: ?>
          <div class="ta-cooperation-panel" <?= $coop_required ? '' : 'style="display:none"' ?>><div class="ta-panel-heading"><div><span class="ta-eyebrow">مسیر همکاری استادیار</span><h4>یک مسیر را برای این ترم انتخاب کنید</h4></div><span class="ta-required-pill">انتخاب و ذخیره الزامی</span></div><div class="ta-cooperation-options">
          <?php $modes=['educational'=>['title'=>'همکاری آموزشی','name'=>'همراهِ آموزنده','desc'=>'تمرکز اصلی روی آموزش باکیفیت، باثبات و قابل‌اتکا است.','meta'=>'حداقل ۲۲ هنرجو · مرز بار آموزشی ۳۵'],'development'=>['title'=>'همکاری توسعه‌محور','name'=>'همراهِ سازنده','desc'=>'علاوه بر آموزش، بخشی از ظرفیت برای حل یک نیاز واقعی مبنا اختصاص می‌یابد.','meta'=>'حداقل ۱۵ هنرجو · مرز بار آموزشی ۲۰'],'capacity'=>['title'=>'همکاری ظرفیت‌محور','name'=>'همراهِ برازنده','desc'=>'پذیرش بخش بزرگ‌تری از بار آموزشی برای کمک به رشد مبنا.','meta'=>'حداقل ۳۲ هنرجو · مرز بار آموزشی ۵۷']]; foreach($modes as $key=>$mode): ?><button type="button" class="ta-cooperation-card <?= $cooperation['mode']===$key?'is-selected':'' ?>" data-mode="<?= esc_attr($key) ?>" onclick="taSelectCooperation(this)"><span class="ta-cooperation-check">✓</span><strong><?= esc_html($mode['title']) ?></strong><b><?= esc_html($mode['name']) ?></b><span><?= esc_html($mode['desc']) ?></span><small><?= esc_html($mode['meta']) ?></small></button><?php endforeach; ?></div><input type="hidden" id="ta-cooperation-mode" value="<?= esc_attr($coop_required ? $cooperation['mode'] : '') ?>"><label id="ta-development-commitment" class="ta-commitment <?= $cooperation['mode']==='development'?'':'is-hidden' ?>"><input type="checkbox" id="ta-commitment-checkbox" <?= $commitment_checked?'checked':'' ?>><span><strong>پذیرش تعهد غیرآموزشی</strong><small>برای حمل حداقل یک و حداکثر سه امانت در مبنا اعلام آمادگی می‌کنم.</small></span></label><div id="ta-capacity-commitment" class="ta-optional-note <?= $cooperation['mode']==='capacity'?'':'is-hidden' ?>">اعلام آمادگی برای حمل حداکثر یک امانت در مبنا اختیاری است. <label><input type="checkbox" id="ta-capacity-commitment-checkbox" <?= $commitment_checked?'checked':'' ?>> اعلام آمادگی می‌کنم</label></div></div>
          <div class="ta-guide-box"><div class="ta-guide-icon">ⓘ</div><div><strong>راهنمای شرایط</strong><p>امتیاز بار آموزشی = خلاق × ۱ + پایه × ۱.۵ + مقدماتی × ۲ + پیشرفته × ۳ + نردبان × ۰.۵. حداقل تعداد هنرجو و مرز بار آموزشی باید هم‌زمان محقق شوند. در ظرفیت‌محور حداقل ۱۲ هنرجوی مقدماتی یا حداقل ۱۲ هنرجوی پیشرفته لازم است؛ استثنا: با ۲۰ هنرجوی پیشرفته، این دو شرط اصلی نادیده گرفته می‌شود.</p></div></div>
          <div class="ta-group-after-save-guide"><strong>راهنمای ساخت گروه</strong><p>پس از ذخیره موفق ظرفیت‌ها، برای هر ظرفیتی که امکان ساخت گروه دارد، گزینه «＋ گروه» فعال می‌شود. اگر قصد ساخت گروه دارید، ابتدا ظرفیت را ذخیره کنید و سپس از بخش گروه‌های همان ردیف، پیام‌رسان و لینک گروه را ثبت کنید.</p></div><div class="ta-capacity-summary"><div><span>تعداد کل هنرجویان</span><strong id="ta-total-students">۰</strong></div><div><span>امتیاز بار آموزشی</span><strong id="ta-load-score">۰</strong></div><div><span>تنوع دوره</span><strong id="ta-row-count">۰</strong></div></div>
          <div class="ta-course-stack">
          <?php foreach($types as $type=>$meta): $course_rows=$by_type[$type]??[]; ?><article class="ta-course-card" data-course-card="<?= esc_attr($type) ?>"><header><div class="ta-course-title"><span><?= $meta['icon'] ?></span><div><h4><?= esc_html($meta['label']) ?></h4><small>ظرفیت‌های تفکیک‌شده دوره</small></div></div><button type="button" class="ta-add-row-button" <?= $can_edit_capacity ? '' : 'disabled' ?> onclick="taAddCapacityRow('<?= esc_js($type) ?>')">＋ افزودن ظرفیت</button></header><div class="ta-capacity-rows" data-rows-for="<?= esc_attr($type) ?>">
          <?php foreach($course_rows as $row): $groups=in_array($type,$group_allowed,true)?TA_Database::get_results("SELECT * FROM {$gr_table} WHERE capacity_id=%d ORDER BY id",[$row->id]):[]; $remaining=(int)$row->total_capacity-(int)$row->used_capacity; $pct=$row->total_capacity>0?min(100,round($row->used_capacity/$row->total_capacity*100)):0; ?><div class="ta-capacity-row" data-row-id="<?= (int)$row->id ?>" data-course-type="<?= esc_attr($type) ?>" data-used="<?= (int)$row->used_capacity ?>"><input type="hidden" class="cap-row-id" value="<?= (int)$row->id ?>"><select class="cap-row-gender" <?= $can_edit_capacity ? '' : 'disabled' ?>><option value="male" <?= selected($row->student_gender,'male',false) ?>>👨 آقایان</option><option value="female" <?= selected($row->student_gender,'female',false) ?>>👩 خانم‌ها</option><option value="both" <?= selected($row->student_gender,'both',false) ?>>👥 هر دو</option></select><select class="cap-row-messenger" <?= $can_edit_capacity ? '' : 'disabled' ?>><option value="both" <?= selected($row->messenger,'both',false) ?>>🔀 فرقی ندارد</option><?php foreach($active_messengers as $mk): ?><option value="<?= esc_attr($mk) ?>" <?= selected($row->messenger,$mk,false) ?>><?= $messenger_icons[$mk] ?> <?= esc_html($messenger_labels[$mk]) ?></option><?php endforeach; ?></select><label class="cap-row-number"><span>ظرفیت کل</span><input type="number" min="<?= (int)$row->used_capacity ?>" value="<?= (int)$row->total_capacity > 0 ? (int)$row->total_capacity : '' ?>" placeholder="—" class="cap-row-total" <?= $can_edit_capacity ? '' : 'readonly' ?>></label><div class="cap-row-stats"><b><?= (int)$row->used_capacity ?></b><small>استفاده‌شده</small><b class="remaining-<?= $remaining>0?'ok':'full' ?>"><?= $remaining ?></b><small>باقی‌مانده</small><div class="cap-mini-bar"><i style="width:<?= $pct ?>%"></i></div></div><div class="cap-row-actions"><button type="button" class="cap-icon-btn" onclick="this.closest('.ta-capacity-row').querySelector('.cap-row-total').focus()" title="ویرایش در همین ردیف">✎</button><?php if((int)$row->used_capacity===0): ?><button type="button" class="cap-icon-btn danger" onclick="taDeleteCapacity(<?= (int)$row->id ?>)" title="حذف ظرفیت">🗑</button><?php else: ?><span class="cap-locked">🔒</span><?php endif; ?></div><?php if(in_array($type,$group_allowed,true)): ?><div class="cap-groups-area"><div class="cap-group-heading"><b>گروه‌ها</b><?php $can_add=!in_array($type,$single_group,true)||empty($groups); if($can_add&&TA_Settings_Helper::is_enabled('allow_ta_create_group')): ?><button type="button" class="cap-group-add" onclick="taShowAddGroup(<?= (int)$row->id ?>)">＋ گروه</button><?php elseif($can_add): ?><span class="cap-locked">🔒 ساخت گروه محدود است</span><?php endif; ?></div><div id="groups-<?= (int)$row->id ?>" class="cap-group-list"><?php foreach($groups as $g): ?><div class="ta-group-row <?= $g->is_used?'ta-used':'' ?>"><?php if($g->is_used): ?><span class="ta-badge ta-badge-used">استفاده‌شده</span><?php else: ?><span class="ta-badge ta-badge-free">آزاد</span><a href="<?= esc_url($g->group_link) ?>" target="_blank">مشاهده</a><button type="button" class="cap-group-edit" onclick="taEditGroup(<?= (int)$g->id ?>,<?= (int)$row->id ?>,'<?= esc_js($g->messenger_type) ?>','<?= esc_js($g->group_link) ?>')">ویرایش</button><button type="button" class="cap-group-delete" onclick="taDeleteGroup(<?= (int)$g->id ?>)">حذف</button><?php endif; ?> <span><?= esc_html($messenger_labels[$g->messenger_type]??$g->messenger_type) ?></span></div><?php endforeach; ?></div><div id="add-group-form-<?= (int)$row->id ?>" class="cap-add-group-form" style="display:none"><select id="group-messenger-<?= (int)$row->id ?>"><?php $allowed=$row->messenger!=='both'?[$row->messenger]:$active_messengers; foreach($allowed as $mk): ?><option value="<?= esc_attr($mk) ?>"><?= esc_html($messenger_labels[$mk]) ?></option><?php endforeach; ?></select><input type="url" id="group-link-<?= (int)$row->id ?>" placeholder="لینک گروه را وارد کنید"><button type="button" onclick="taAddGroupSlot(<?= (int)$row->id ?>)">افزودن گروه</button></div></div><?php endif; ?></div><?php endforeach; ?></div><div class="ta-empty-course" <?= empty($course_rows)?'':'style="display:none"' ?>>هنوز ظرفیتی برای این دوره ثبت نشده است.</div></article><?php endforeach; ?></div>
          <div id="ta-validation-strip" class="ta-validation-strip"><?= $coop_required ? 'یک مسیر همکاری انتخاب کنید تا وضعیت شروط نمایش داده شود.' : 'برای این ترم تعیین نوع همکاری لازم نیست.' ?></div><div class="ta-save-row"><span id="ta-bulk-save-message"></span><button type="button" class="ta-save-all-button" onclick="taSaveAllCapacities()">✓ ذخیره همه ظرفیت‌ها</button></div></div>
          <?php endif; ?></section>
        <style>
        .ta-capacity-full{--cap-ink:#172033;--cap-muted:#64748b;--cap-brand:#5146e5;--cap-border:#e5e7eb;width:100%;max-width:1120px;min-width:0;color:var(--cap-ink);font-family:inherit;overflow:hidden}.ta-capacity-full *{box-sizing:border-box}.ta-capacity-full h3,.ta-capacity-full h4,.ta-capacity-full p{font-family:inherit}.ta-capacity-hero{display:flex;justify-content:space-between;align-items:center;gap:24px;margin:0 0 18px;padding:28px 32px;border-radius:24px;background:linear-gradient(135deg,#171b3a 0%,#3730a3 100%);color:#fff;box-shadow:0 18px 40px rgba(55,48,163,.18)}.ta-capacity-hero>div:first-child{min-width:0}.ta-capacity-hero h3{margin:8px 0!important;color:#fff!important;font-size:25px!important;line-height:1.45!important;font-weight:800!important}.ta-capacity-hero p{margin:0;color:#dbe2ff;font-size:13px;line-height:1.9}.ta-eyebrow{display:block;color:#a5b4fc;font-size:11px;font-weight:800;letter-spacing:.06em}.ta-capacity-term{flex:0 0 235px;padding:15px;border:1px solid rgba(255,255,255,.2);border-radius:16px;background:rgba(255,255,255,.1)}.ta-capacity-term label,.ta-capacity-term small{display:block;color:#dbe2ff;font-size:11px;margin-bottom:6px}.ta-capacity-term small{margin:7px 0 0;color:#bfdbfe}.ta-capacity-select{width:100%!important;min-height:40px;border:0!important;border-radius:10px!important;padding:9px 11px!important;background:#fff!important;color:#172033!important;font:600 12px inherit!important}.ta-cooperation-panel,.ta-guide-box,.ta-capacity-summary{margin-bottom:16px}.ta-cooperation-panel{padding:22px;background:#fff;border:1px solid var(--cap-border);border-radius:20px;box-shadow:0 8px 25px rgba(23,32,51,.04)}.ta-panel-heading{display:flex;justify-content:space-between;align-items:center;gap:15px}.ta-panel-heading h4{margin:5px 0;font-size:18px;line-height:1.5}.ta-required-pill{padding:6px 12px;border-radius:99px;background:#fff1f2;color:#be123c;font-size:11px;font-weight:800;white-space:nowrap}.ta-cooperation-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:18px}.ta-capacity-full .ta-cooperation-card{position:relative;display:flex;flex-direction:column;align-items:flex-start;gap:6px;width:100%;min-width:0;min-height:168px;padding:18px 42px 16px 18px!important;border:1.5px solid #e5e7eb!important;border-radius:16px!important;background:#fafbff!important;color:var(--cap-ink)!important;text-align:right!important;font:inherit!important;cursor:pointer!important;overflow:hidden;overflow-wrap:anywhere;transition:transform .2s,box-shadow .2s,border-color .2s,background .2s!important}.ta-capacity-full .ta-cooperation-card:hover{transform:translateY(-2px);border-color:#a5b4fc!important;box-shadow:0 8px 20px rgba(79,70,229,.12)!important}.ta-capacity-full .ta-cooperation-card.is-selected{border-color:var(--cap-brand)!important;background:#f4f3ff!important;box-shadow:0 0 0 3px rgba(81,70,229,.12)!important}.ta-cooperation-card strong{display:block;width:100%;font-size:15px;line-height:1.7;color:#172033!important}.ta-cooperation-card b{display:block;width:100%;font-size:12px;line-height:1.7;color:#5146e5!important}.ta-cooperation-card span:not(.ta-cooperation-check){display:block;width:100%;font-size:12px;line-height:1.8;color:#64748b!important}.ta-cooperation-card small{display:block;width:100%;margin-top:auto;font-size:11px;line-height:1.8;color:#475569!important;font-weight:700}.ta-cooperation-check{position:absolute;top:15px;right:15px;width:22px;height:22px;border:1px solid #cbd5e1;border-radius:50%;color:transparent!important;text-align:center;line-height:20px!important;font-size:12px!important}.ta-cooperation-card.is-selected .ta-cooperation-check{background:var(--cap-brand);border-color:var(--cap-brand);color:#fff!important}.ta-commitment{display:flex;align-items:flex-start;gap:10px;margin-top:14px;padding:13px;border:1px solid #fed7aa;border-radius:12px;background:#fff7ed}.ta-commitment input,.ta-optional-note input{accent-color:var(--cap-brand);margin-top:3px}.ta-commitment span{display:flex;flex-direction:column;gap:4px}.ta-commitment strong{font-size:12px}.ta-commitment small{color:#9a3412;font-size:11px;line-height:1.8}.ta-optional-note{margin-top:14px;padding:12px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;color:#1d4ed8;font-size:12px;line-height:1.9}.ta-optional-note label{font-weight:800;white-space:nowrap}.is-hidden{display:none!important}.ta-guide-box{display:flex;gap:12px;align-items:flex-start;padding:15px 18px;border:1px solid #c7d2fe;border-radius:16px;background:#eef2ff;color:#3730a3}.ta-guide-icon{font-size:20px;line-height:1.4}.ta-guide-box strong{font-size:13px}.ta-guide-box p{margin:5px 0 0;color:#3730a3;font-size:12px;line-height:2}.ta-validation-strip{padding:12px;border-radius:12px;background:#f8fafc;color:#64748b;font-size:12px;line-height:1.8}.ta-save-row{display:flex;justify-content:space-between;align-items:center;gap:14px}.ta-save-all-button{appearance:none!important;border:0!important;padding:13px 22px!important;border-radius:12px!important;background:linear-gradient(135deg,#635bff,#4338ca)!important;color:#fff!important;font:800 13px/1.3 inherit!important;cursor:pointer!important;box-shadow:0 8px 18px rgba(99,91,255,.22)!important}.ta-save-all-button:hover{transform:translateY(-1px);background:linear-gradient(135deg,#5148f5,#3730a3)!important}.ta-bulk-save-message{font-size:12px}.ta-course-stack{display:grid;gap:16px}.ta-group-after-save-guide{display:flex;gap:10px;align-items:flex-start;margin:0 0 16px;padding:13px 16px;border:1px solid #bfdbfe;border-radius:14px;background:#eff6ff;color:#1e40af}.ta-group-after-save-guide strong{font-size:12px;white-space:nowrap}.ta-group-after-save-guide p{margin:0;font-size:11px;line-height:2;color:#1e40af;overflow-wrap:anywhere}.ta-capacity-full .ta-cooperation-card span,.ta-capacity-full .ta-cooperation-card small,.ta-capacity-full .ta-cooperation-card b,.ta-capacity-full .ta-cooperation-card strong{overflow-wrap:anywhere;word-break:normal}.ta-capacity-full button:disabled{opacity:.5!important;cursor:not-allowed!important;transform:none!important}@media(max-width:800px){.ta-group-after-save-guide{display:block}.ta-group-after-save-guide strong{display:block;margin-bottom:5px}.ta-capacity-full .ta-cooperation-card{overflow-wrap:anywhere}}.ta-capacity-full *{box-sizing:border-box}.ta-course-stack{display:grid;gap:16px}.ta-course-card{background:#fff;border:1px solid #e7eaf4;border-radius:20px;padding:18px;box-shadow:0 8px 25px #17203308;min-width:0}.ta-course-card>header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px}.ta-course-title{display:flex;gap:10px;align-items:center}.ta-course-title>span{font-size:28px}.ta-course-title h4{margin:0;font-size:17px}.ta-course-title small{color:#718096;font-size:11px}.ta-add-row-button,.cap-group-add,.cap-group-edit,.cap-group-delete,.cap-icon-btn{appearance:none!important;-webkit-appearance:none!important;font:700 11px/1.2 inherit!important;cursor:pointer!important;border-radius:9px!important;padding:8px 11px!important;border:1px solid #dbe2ef!important;background:#fff!important;color:#475569!important}.ta-add-row-button{background:#eef2ff!important;color:#4338ca!important;border-color:#c7d2fe!important}.ta-capacity-rows{display:grid;gap:10px}.ta-capacity-row{display:grid;grid-template-columns:145px 145px 125px 1fr auto;gap:9px;align-items:center;padding:12px;background:#f8fafc;border:1px solid #edf0f5;border-radius:14px;min-width:0}.cap-row-gender,.cap-row-messenger,.cap-row-number input,.cap-add-group-form select,.cap-add-group-form input{width:100%;min-width:0;border:1px solid #dbe2ef;border-radius:9px;background:#fff;padding:9px;font:500 12px inherit}.cap-row-number{display:flex;flex-direction:column;gap:4px;font-size:10px;color:#64748b}.cap-row-number input{font-size:15px;font-weight:800;text-align:center}.cap-row-stats{display:grid;grid-template-columns:auto auto auto auto;gap:2px 7px;align-items:center;color:#64748b;font-size:10px}.cap-row-stats b{font-size:14px;color:#334155}.cap-row-stats .remaining-ok{color:#059669}.cap-row-stats .remaining-full{color:#dc2626}.cap-mini-bar{grid-column:1/-1;height:5px;background:#e2e8f0;border-radius:9px;overflow:hidden}.cap-mini-bar i{display:block;height:100%;background:linear-gradient(90deg,#6366f1,#22c55e);border-radius:9px}.cap-row-actions{display:flex;gap:5px}.cap-icon-btn.danger,.cap-group-delete{color:#dc2626!important}.cap-locked{font-size:11px;color:#a16207}.cap-groups-area{grid-column:1/-1;border-top:1px dashed #dbe2ef;padding-top:10px}.cap-group-heading{display:flex;justify-content:space-between;align-items:center;font-size:12px}.cap-group-list{display:grid;gap:6px;margin-top:7px}.ta-group-row{display:flex;align-items:center;gap:7px;flex-wrap:wrap;background:#fff;padding:7px 9px;border-radius:9px;font-size:11px}.ta-group-row a{color:#4338ca}.cap-group-edit,.cap-group-delete{padding:5px 8px!important}.cap-add-group-form{display:flex;gap:7px;margin-top:8px;padding:9px;background:#fff;border:1px solid #c7d2fe;border-radius:10px}.cap-add-group-form button{border:0;background:#4338ca;color:#fff;border-radius:8px;padding:8px 12px;font:700 11px inherit;cursor:pointer}.ta-capacity-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:16px 0}.ta-capacity-summary>div{background:#fff;border:1px solid #e7eaf4;border-radius:14px;padding:14px}.ta-capacity-summary span{display:block;color:#718096;font-size:11px}.ta-capacity-summary strong{display:block;color:#4338ca;font-size:24px;margin-top:5px}.ta-empty-course{color:#94a3b8;font-size:12px;padding:9px}.ta-capacity-full .ta-save-row{margin-top:18px}.ta-capacity-full .ta-save-all-button{appearance:none!important;border:0!important;background:linear-gradient(135deg,#635bff,#4338ca)!important;color:#fff!important;border-radius:12px!important;padding:13px 22px!important;font:800 13px/1.2 inherit!important;cursor:pointer!important}.ta-capacity-full .ta-cooperation-card{min-width:0;max-width:100%;overflow:hidden;overflow-wrap:anywhere}@media(max-width:800px){.ta-capacity-full{overflow:hidden}.ta-course-card>header{align-items:flex-start;flex-direction:column}.ta-capacity-row{grid-template-columns:1fr 1fr;gap:8px}.cap-row-stats,.cap-row-actions,.cap-groups-area{grid-column:1/-1}.cap-row-actions{justify-content:flex-start}.cap-add-group-form{flex-direction:column}.ta-capacity-summary{grid-template-columns:1fr}.ta-capacity-full .ta-cooperation-options{grid-template-columns:minmax(0,1fr)}.ta-capacity-full .ta-cooperation-card{width:100%;padding-left:48px!important}}
        @media(max-width:800px){.ta-capacity-full{max-width:100%;overflow-x:hidden}.ta-capacity-hero{display:block;padding:22px 18px;border-radius:18px}.ta-capacity-hero h3{font-size:20px!important}.ta-capacity-term{width:100%;margin-top:18px}.ta-panel-heading{align-items:flex-start;flex-direction:column}.ta-required-pill{align-self:flex-start}.ta-cooperation-options{grid-template-columns:minmax(0,1fr)!important}.ta-capacity-full .ta-cooperation-card{min-height:0;padding:15px 48px 15px 15px!important}.ta-guide-box{padding:13px}.ta-save-row{align-items:stretch;flex-direction:column}.ta-save-all-button{width:100%}}</style>
        <script>
        (function(){window.taCoopRequired=<?= $coop_required ? 'true' : 'false' ?>;var weights={creative:1,basic_writing:1.5,intro:2,advanced:3,ladder:0.5};var modes={educational:{count:22,score:35},development:{count:15,score:20},capacity:{count:32,score:57}};window.taDetectAutomaticMode=function(){var hasPro=false,hasCrit=false,hasOther=false;document.querySelectorAll('.ta-capacity-row').forEach(function(r){var t=r.dataset.courseType,n=parseInt(r.querySelector('.cap-row-total').value||0);if(n<=0)return;if(t==='professional')hasPro=true;else if(t==='critique')hasCrit=true;else hasOther=true});var auto=(!hasOther&&(hasPro||hasCrit))?(hasPro&&hasCrit?'professional_critique':(hasPro?'professional':'critique')):'';var hidden=document.getElementById('ta-cooperation-mode');if(hidden&&auto)hidden.value=auto;var pill=document.querySelector('.ta-required-pill');if(pill&&window.taCoopRequired!==false)pill.textContent=auto?'انتخاب مسیر سه‌گانه اختیاری':'انتخاب و ذخیره الزامی';return auto|| (hidden?hidden.value:'')};window.taSelectCooperation=function(el){document.querySelectorAll('.ta-cooperation-card').forEach(function(x){x.classList.remove('is-selected')});el.classList.add('is-selected');var mode=el.dataset.mode;document.getElementById('ta-cooperation-mode').value=mode;document.getElementById('ta-development-commitment').classList.toggle('is-hidden',mode!=='development');document.getElementById('ta-capacity-commitment').classList.toggle('is-hidden',mode!=='capacity');taUpdateCapacityGuide()};window.taAddCapacityRow=function(type){var box=document.querySelector('[data-rows-for="'+type+'"]');var card=document.querySelector('[data-course-card="'+type+'"]');var empty=card.querySelector('.ta-empty-course');if(empty)empty.style.display='none';var div=document.createElement('div');div.className='ta-capacity-row ta-new-row';div.dataset.courseType=type;div.dataset.used='0';div.innerHTML='<input type="hidden" class="cap-row-id" value="0"><select class="cap-row-gender"><option value="male">👨 آقایان</option><option value="female">👩 خانم‌ها</option><option value="both" selected>👥 هر دو</option></select><select class="cap-row-messenger"><option value="both" selected>🔀 فرقی ندارد</option><?php foreach($active_messengers as $mk): ?><option value="<?= esc_js($mk) ?>"><?= esc_js($messenger_icons[$mk].' '.$messenger_labels[$mk]) ?></option><?php endforeach; ?></select><label class="cap-row-number"><span>ظرفیت کل</span><input type="number" min="0" value="" placeholder="—" class="cap-row-total"></label><div class="cap-row-stats"><b>۰</b><small>استفاده‌شده</small><b class="remaining-ok"></b><small>باقی‌مانده</small><div class="cap-mini-bar"><i style="width:0%"></i></div></div><div class="cap-row-actions"><button type="button" class="cap-icon-btn danger" onclick="this.closest(\'.ta-capacity-row\').remove();taUpdateCapacityGuide()">🗑</button></div>';box.appendChild(div);div.querySelector('.cap-row-total').addEventListener('input',function(){taRefreshCapacityRow(div);taUpdateCapacityGuide()});div.querySelector('input[type=number]').focus();taUpdateCapacityGuide()};window.taUpdateCapacityGuide=function(){var total=0,score=0,pre=0,adv=0,rows=0;document.querySelectorAll('.ta-capacity-row').forEach(function(r){var t=r.dataset.courseType,n=parseInt(r.querySelector('.cap-row-total').value||0);if(weights[t]){total+=n;score+=n*weights[t];if(t==='intro')pre+=n;if(t==='advanced')adv+=n}rows++});document.getElementById('ta-total-students').textContent=total.toLocaleString('fa-IR');document.getElementById('ta-load-score').textContent=score.toLocaleString('fa-IR');document.getElementById('ta-row-count').textContent=rows.toLocaleString('fa-IR');var mode=taDetectAutomaticMode(),strip=document.getElementById('ta-validation-strip');if(!mode){strip.textContent=window.taCoopRequired===false?'برای این ترم تعیین نوع همکاری لازم نیست.':'یک مسیر همکاری انتخاب کنید تا وضعیت شروط نمایش داده شود.';return}if(mode==='professional'||mode==='critique'||mode==='professional_critique'){strip.textContent='✓ نوع همکاری خودکار: '+(mode==='professional'?'نویسندگی حرفه‌ای':(mode==='critique'?'نقد اثر':'نویسندگی حرفه‌ای و نقد اثر'))+' — انتخاب مسیر سه‌گانه لازم نیست.';strip.style.color='#047857';return}var ok;if(mode==='capacity'){ok=adv>=20||(total>=32&&score>=57&&(pre>=12||adv>=12))}else{ok=modes[mode].count<=total&&modes[mode].score<=score;}strip.textContent=(ok?'✓ شروط اصلی تکمیل است: ':'⚠ هنوز شروط کامل نشده: ')+'تعداد '+total+' / '+modes[mode].count+'، امتیاز '+score+' / '+modes[mode].score+(mode==='capacity'?'، مقدماتی '+pre+' / ۱۲ یا پیشرفته '+adv+' / ۱۲؛ استثنا: پیشرفته ۲۰':'');strip.style.color=ok?'#047857':'#b45309'};window.taRefreshCapacityRow=function(row){var input=row.querySelector('.cap-row-total');if(!input)return;var total=parseInt(input.value||0),used=parseInt(row.dataset.used||0),remaining=Math.max(0,total-used),pct=total>0?Math.min(100,Math.round(used/total*100)):0;var stats=row.querySelectorAll('.cap-row-stats b');if(stats[1]){stats[1].textContent=remaining;stats[1].className=remaining>0?'remaining-ok':'remaining-full'}var bar=row.querySelector('.cap-mini-bar i');if(bar)bar.style.width=pct+'%'};document.querySelectorAll('.ta-capacity-row').forEach(function(r){var i=r.querySelector('.cap-row-total');if(i)i.addEventListener('input',function(){taRefreshCapacityRow(r);taUpdateCapacityGuide()})});window.taSaveAllCapacities=function(){var mode=taDetectAutomaticMode(),msg=document.getElementById('ta-bulk-save-message');if(!mode&&window.taCoopRequired!==false){msg.textContent='ابتدا یک مسیر همکاری انتخاب کنید.';return}if(mode==='development'&&!document.getElementById('ta-commitment-checkbox').checked){msg.textContent='پذیرش تعهد توسعه‌محور الزامی است.';return}var data={action:'ta_save_all_capacities',nonce:'<?= esc_js($nonce) ?>',term_id:'<?= (int)$active_term_id ?>',cooperation_mode:mode,commitment:(mode==='development'?document.getElementById('ta-commitment-checkbox').checked:(mode==='capacity'?document.getElementById('ta-capacity-commitment-checkbox').checked:false)),capacity_rows:'[]'};document.querySelectorAll('.ta-capacity-row').forEach(function(r){var row={id:r.querySelector('.cap-row-id').value||0,course_type:r.dataset.courseType,student_gender:r.querySelector('.cap-row-gender').value,messenger:r.querySelector('.cap-row-messenger').value,total_capacity:r.querySelector('.cap-row-total').value};data.capacity_rows=JSON.stringify((JSON.parse(data.capacity_rows||'[]')).concat([row]))});msg.textContent='در حال ذخیره…';jQuery.post(taSystem.ajaxUrl,data).done(function(r){msg.textContent=r.success?'✓ همه ظرفیت‌ها و مسیر همکاری ذخیره شد.':(r.data&&r.data.message||'خطا در ذخیره‌سازی');msg.style.color=r.success?'#047857':'#b91c1c';if(r.success)setTimeout(function(){location.reload()},700)}).fail(function(){msg.textContent='خطای ارتباط با سرور';msg.style.color='#b91c1c'})};taUpdateCapacityGuide()})();
        </script>
        <script>
        (function(){
            var originalSelect = window.taSelectCooperation;
            window.taSelectCooperation = function(el){
                if (typeof originalSelect === 'function') originalSelect(el);
                var development = document.getElementById('ta-commitment-checkbox');
                var capacity = document.getElementById('ta-capacity-commitment-checkbox');
                if (development) development.checked = false;
                if (capacity) capacity.checked = false;
            };
        })();
        </script>
        <?php
    }

    // ── گزارش تفصیلی مسیر همکاری ─────────────────────────────────────────────
    private function tab_cooperation_report( object $ta ): void {
        $term_id = absint( $_GET['ta_term'] ?? get_option( 'ta_active_term_id', 0 ) );
        $terms = TA_Database::get_results( "SELECT * FROM " . TA_Database::table('terms') . " ORDER BY created_at DESC, id DESC" );
        if ( ! $term_id && ! empty($terms) ) $term_id=(int)$terms[0]->id;
        $term=null; foreach($terms as $t) if((int)$t->id===$term_id)$term=$t;
        $setting=get_option('ta_cooperation_'.(int)$ta->id.'_'.$term_id,['mode'=>'','commitment'=>0]); if(!is_array($setting))$setting=['mode'=>'','commitment'=>0];
        $mode_data=['educational'=>['title'=>'همکاری آموزشی','name'=>'همراهِ آموزنده','desc'=>'سهم اصلی این مسیر آموزش باکیفیت، باثبات و قابل‌اتکا است.','count'=>22,'score'=>35,'commit'=>'غیرآموزشی الزامی نیست.','benefits'=>'قیمت پایه، اثرگذاری در امتیاز بورس نویسندگی و مزایای عمومی استادیاری.'],'development'=>['title'=>'همکاری توسعه‌محور','name'=>'همراهِ سازنده','desc'=>'استادیار علاوه بر آموزش، بخشی از ظرفیت خود را برای حل یک نیاز واقعی مبنا اختصاص می‌دهد.','count'=>15,'score'=>20,'commit'=>'اعلام آمادگی برای حمل حداقل یک و حداکثر سه امانت در مبنا الزامی است.','benefits'=>'قیمت پایه، اثرگذاری در امتیاز بورس نویسندگی و مزایای عمومی استادیاری.'],'capacity'=>['title'=>'همکاری ظرفیت‌محور','name'=>'همراهِ برازنده','desc'=>'استادیار بخش بزرگ‌تری از ظرفیت آموزشی را برای رشد مبنا به کار می‌گیرد.','count'=>32,'score'=>57,'commit'=>'اعلام آمادگی برای حمل حداکثر یک امانت در مبنا اختیاری است.','benefits'=>'قیمت ویژه، حق‌الزحمه امانت، اثرگذاری در امتیاز دریافت ویژه، بورس نویسندگی و مزایای عمومی استادیاری.'],'professional'=>['title'=>'نویسندگی حرفه‌ای','name'=>'همکاری خودکار','desc'=>'این نوع همکاری به‌صورت خودکار برای ظرفیت‌های نویسندگی حرفه‌ای ثبت شده است.','count'=>0,'score'=>0,'commit'=>'انتخاب مسیر سه‌گانه الزامی نیست.','benefits'=>'مزایای مربوط به ظرفیت نویسندگی حرفه‌ای.'],'critique'=>['title'=>'نقد اثر','name'=>'همکاری خودکار','desc'=>'این نوع همکاری به‌صورت خودکار برای ظرفیت‌های نقد اثر ثبت شده است.','count'=>0,'score'=>0,'commit'=>'انتخاب مسیر سه‌گانه الزامی نیست.','benefits'=>'مزایای مربوط به ظرفیت نقد اثر.'],'professional_critique'=>['title'=>'نویسندگی حرفه‌ای و نقد اثر','name'=>'همکاری خودکار','desc'=>'این نوع همکاری به‌صورت خودکار برای ظرفیت‌های نویسندگی حرفه‌ای و نقد اثر ثبت شده است.','count'=>0,'score'=>0,'commit'=>'انتخاب مسیر سه‌گانه الزامی نیست.','benefits'=>'مزایای مربوط به ظرفیت‌های نویسندگی حرفه‌ای و نقد اثر.']];
        $chosen=$mode_data[$setting['mode']]??null; $rows=$term_id?TA_Database::get_results("SELECT * FROM ".TA_Database::table('capacities')." WHERE ta_id=%d AND term_id=%d",[$ta->id,$term_id]):[]; $labels=['creative'=>'خلاق','basic_writing'=>'پایه','intro'=>'مقدماتی','advanced'=>'پیشرفته','professional'=>'حرفه‌ای','ladder'=>'نردبان حرفه‌ای','critique'=>'نقد اثر']; $weights=['creative'=>1,'basic_writing'=>1.5,'intro'=>2,'advanced'=>3,'ladder'=>0.5]; $total=0;$score=0;foreach($rows as $r){if(isset($weights[$r->course_type])){$total+=(int)$r->total_capacity;$score+=(int)$r->total_capacity*$weights[$r->course_type];}}
        ?>
        <section class="ta-cooperation-report" dir="rtl"><div class="ta-report-hero"><span>گزارش جامع مسیر همکاری</span><h3><?= $chosen?esc_html($chosen['title']):'هنوز مسیر همکاری انتخاب نشده است' ?></h3><p>ترم: <?= esc_html($term->name??'—') ?> · اطلاعات ثبت‌شده و شرایط قابل پیگیری</p></div><div class="ta-report-toolbar"><label>ترم گزارش<select onchange="var u='<?= esc_js(remove_query_arg(['ta_tab','ta_term'])) ?>';location.href=u+(u.indexOf('?')===-1?'?':'&')+'ta_tab=cooperation_report&ta_term='+encodeURIComponent(this.value)"><?php foreach($terms as $t): ?><option value="<?= (int)$t->id ?>" <?= selected($term_id,$t->id,false) ?>><?= esc_html($t->name) ?></option><?php endforeach; ?></select></label></div><?php if($chosen): ?><div class="ta-report-grid"><div class="ta-report-card"><span class="ta-report-label">نام مسیر</span><strong><?= esc_html($chosen['name']) ?></strong><p><?= esc_html($chosen['desc']) ?></p></div><div class="ta-report-card"><span class="ta-report-label">شرط تعداد</span><strong><?= (int)$chosen['count'] ?> هنرجو</strong><p>حداقل تعداد هنرجوی لازم برای این مسیر</p></div><div class="ta-report-card"><span class="ta-report-label">مرز بار آموزشی</span><strong><?= (int)$chosen['score'] ?></strong><p>حداقل امتیاز بار آموزشی</p></div><div class="ta-report-card"><span class="ta-report-label">تعهد غیرآموزشی</span><strong><?= !empty($setting['commitment'])?'پذیرفته شده':'الزامی نیست / انتخاب نشده' ?></strong><p><?= esc_html($chosen['commit']) ?></p></div></div><div class="ta-report-section"><h4>وضعیت ظرفیت‌های این ترم</h4><div class="ta-report-kpis"><div><b><?= number_format_i18n($total) ?></b><span>کل هنرجویان سطوح اصلی</span></div><div><b><?= number_format_i18n($score) ?></b><span>امتیاز بار آموزشی</span></div><div><b><?= number_format_i18n(count($rows)) ?></b><span>ردیف ظرفیت ثبت‌شده</span></div></div><table><thead><tr><th>دوره</th><th>جنسیت</th><th>پیام‌رسان</th><th>کل</th><th>استفاده‌شده</th><th>باقی‌مانده</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= esc_html($labels[$r->course_type]??$r->course_type) ?></td><td><?= esc_html(['male'=>'آقایان','female'=>'خانم‌ها','both'=>'هر دو'][$r->student_gender]??$r->student_gender) ?></td><td><?= esc_html(['telegram'=>'تلگرام','bale'=>'بله','eitaa'=>'ایتا','both'=>'فرقی ندارد'][$r->messenger]??$r->messenger) ?></td><td><?= (int)$r->total_capacity ?></td><td><?= (int)$r->used_capacity ?></td><td><?= max(0,(int)$r->total_capacity-(int)$r->used_capacity) ?></td></tr><?php endforeach; ?></tbody></table></div><div class="ta-report-section"><h4>مزایا و الزامات مسیر</h4><p class="ta-report-detail"><?= esc_html($chosen['benefits']) ?></p><p class="ta-report-detail"><?= esc_html($chosen['commit']) ?></p><p class="ta-report-detail">ضرایب بار آموزشی: خلاق ۱، پایه ۱.۵، مقدماتی ۲، پیشرفته ۳ و نردبان ۰.۵. در همکاری ظرفیت‌محور حداقل ۳۲ هنرجوی کل، امتیاز بار آموزشی ۵۷ و حداقل ۱۲ هنرجوی مقدماتی یا ۱۲ هنرجوی پیشرفته لازم است؛ استثنا، ۲۰ هنرجوی پیشرفته است.</p></div><?php else: ?><div class="ta-report-empty">برای مشاهده گزارش، ابتدا در تب ظرفیت‌ها یک مسیر همکاری انتخاب و ذخیره کنید.</div><?php endif; ?></section><style>.ta-cooperation-report{max-width:1100px;color:#172033}.ta-report-hero{padding:26px 30px;border-radius:22px;background:linear-gradient(135deg,#171b3a,#3730a3);color:#fff}.ta-report-hero span{color:#c7d2fe;font-size:12px}.ta-report-hero h3{color:#fff!important;margin:9px 0;font-size:24px}.ta-report-hero p{color:#dbe2ff;margin:0}.ta-report-toolbar{margin:15px 0;padding:14px;background:#fff;border:1px solid #e5e7eb;border-radius:14px}.ta-report-toolbar label{display:flex;align-items:center;gap:10px;font-weight:800;font-size:12px}.ta-report-toolbar select{border:1px solid #dbe2ef;border-radius:8px;padding:8px}.ta-report-grid,.ta-report-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.ta-report-card,.ta-report-section{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;margin-bottom:15px}.ta-report-label{color:#64748b;font-size:11px}.ta-report-card strong{display:block;color:#4338ca;font-size:18px;margin:8px 0}.ta-report-card p,.ta-report-detail{color:#64748b;font-size:12px;line-height:2}.ta-report-kpis{grid-template-columns:repeat(3,1fr)}.ta-report-kpis div{background:#f8fafc;border-radius:12px;padding:15px}.ta-report-kpis b{display:block;color:#4338ca;font-size:24px}.ta-report-kpis span{color:#64748b;font-size:11px}.ta-report-section h4{margin-top:0}.ta-report-section table{width:100%;border-collapse:collapse;font-size:12px}.ta-report-section th,.ta-report-section td{padding:10px;border-bottom:1px solid #edf0f5;text-align:right}.ta-report-empty{padding:25px;background:#fff7ed;border:1px solid #fed7aa;border-radius:15px;color:#9a3412}@media(max-width:750px){.ta-report-grid,.ta-report-kpis{grid-template-columns:1fr}.ta-report-section{overflow-x:auto}.ta-report-section table{min-width:650px}.ta-report-hero{padding:21px}.ta-report-hero h3{font-size:20px}}</style><?php
    }

    // ── هنرجویان ────────────────────────────────────────────────────────────
    private function tab_students( object $ta ): void {
        $assign_table = TA_Database::table( 'assignments' );
        $bl_table     = TA_Database::table( 'blacklist' );
        $term_table   = TA_Database::table( 'terms' );
        $grade_table  = TA_Database::table( 'grades' );
        global $wpdb;

        $status_filter = isset( $_GET['ta_status'] ) ? sanitize_text_field( $_GET['ta_status'] ) : 'active';
        $allowed_statuses = [ 'active', 'completed', 'canceled', 'all' ];

        if ( ! in_array( $status_filter, $allowed_statuses, true ) ) {
            $status_filter = 'active';
        }

        $term_filter    = isset( $_GET['ta_term'] )    ? sanitize_text_field( $_GET['ta_term'] )    : '';
        $product_filter = isset( $_GET['ta_product'] ) ? sanitize_text_field( $_GET['ta_product'] ) : '';
        $grade_filter   = isset( $_GET['ta_grade'] )   ? sanitize_text_field( $_GET['ta_grade'] )   : '';
        $search_filter  = isset( $_GET['ta_search'] )  ? sanitize_text_field( $_GET['ta_search'] )  : '';

        // ساخت شرط‌های پویا
        $where_clauses = [ 'a.ta_id = %d' ];
        $query_args    = [ $ta->id ];

        if ( $status_filter !== 'all' ) {
            $where_clauses[] = 'a.status = %s';
            $query_args[]    = $status_filter;
        }
        if ( $term_filter !== '' ) {
            $where_clauses[] = 'a.term_id = %d';
            $query_args[]    = (int) $term_filter;
        }
        if ( $product_filter !== '' ) {
            $where_clauses[] = 'a.product_id = %d';
            $query_args[]    = (int) $product_filter;
        }
        if ( $grade_filter === 'none' ) {
            $where_clauses[] = 'g.grade IS NULL';
        } elseif ( $grade_filter !== '' ) {
            $where_clauses[] = 'g.grade = %s';
            $query_args[]    = $grade_filter;
        }
        if ( $search_filter !== '' ) {
            $where_clauses[] = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
            $like = '%' . $wpdb->esc_like( $search_filter ) . '%';
            $query_args[] = $like;
            $query_args[] = $like;
        }

        $where_sql = implode( ' AND ', $where_clauses );

        $students = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                a.id, a.status, a.product_id, a.course_type, a.messenger, a.group_link,
                u.ID   AS student_id,
                u.display_name,
                u.user_email,
                t.name AS term_name,
                t.id   AS term_id,
                p.post_title AS product_name,
                g.grade,
                g.notes AS grade_notes,
                b.id   AS is_blacklisted
             FROM {$assign_table} a
             JOIN {$wpdb->users} u ON u.ID = a.student_id
             JOIN {$term_table} t ON t.id = a.term_id
             JOIN {$wpdb->posts} p ON p.ID = a.product_id
             LEFT JOIN {$grade_table} g ON g.student_id = a.student_id AND g.product_id = a.product_id
             LEFT JOIN {$bl_table} b ON b.student_id = a.student_id AND b.ta_id = a.ta_id
             WHERE {$where_sql}
             ORDER BY a.id DESC",
            $query_args
        ) );

        if ( ! is_array( $students ) ) {
            $students = [];
        }

        // واکشی ترم‌های منحصربه‌فرد این استادیار
        $terms_list = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT t.id, t.name
             FROM {$assign_table} a
             JOIN {$term_table} t ON t.id = a.term_id
             WHERE a.ta_id = %d
             ORDER BY t.name",
            $ta->id
        ) );
        if ( ! is_array( $terms_list ) ) $terms_list = [];

        // واکشی دوره‌های منحصربه‌فرد این استادیار
        $products_list = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title
             FROM {$assign_table} a
             JOIN {$wpdb->posts} p ON p.ID = a.product_id
             WHERE a.ta_id = %d
             ORDER BY p.post_title",
            $ta->id
        ) );
        if ( ! is_array( $products_list ) ) $products_list = [];

        // [FIX-STUDENTS-COURSE-LABEL] لیست کامل انواع دوره — قبلاً «نویسندگی پایه» و «نردبان حرفه‌ای»
        // در این آرایه نبودند و در ستون «دوره» جدول هنرجویان به‌صورت خالی نمایش داده می‌شدند.
        $course_labels = [
            'creative'      => '✨ خلاق',
            'critique'      => '🔍 نقد اثر',
            'intro'         => '🌱 مقدماتی',
            'basic_writing' => '📝 نویسندگی پایه',
            'advanced'      => '🚀 پیشرفته',
            'professional'  => '🏆 حرفه‌ای',
            'ladder'        => '🪜 نردبان حرفه‌ای',
        ];
        // پیامرسان‌های فعال از تنظیمات
        $all_msg_labels_ref = ['telegram'=>'تلگرام','bale'=>'بله','eitaa'=>'ایتا'];
        $all_msg_icons_ref  = ['telegram'=>'✈️','bale'=>'🔵','eitaa'=>'🟢'];
        $active_msgs_ref    = TA_Settings_Helper::get( 'supported_messengers', ['telegram','bale','eitaa'] );
        if ( ! is_array( $active_msgs_ref ) || empty( $active_msgs_ref ) ) $active_msgs_ref = ['telegram','bale','eitaa'];
        $m_labels = array_intersect_key( $all_msg_labels_ref, array_flip( $active_msgs_ref ) ) + $all_msg_labels_ref;

        // ── کارت‌های آمار (بدون اعمال فیلتر، تصویری کلی از وضعیت هنرجویان) ──────
        $status_counts_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.status, COUNT(*) c FROM {$assign_table} a WHERE a.ta_id = %d GROUP BY a.status",
            $ta->id
        ) );
        $status_counts = [ 'active' => 0, 'completed' => 0, 'canceled' => 0 ];
        $total_all_students = 0;
        foreach ( (array) $status_counts_raw as $row ) {
            $status_counts[ $row->status ] = (int) $row->c;
            $total_all_students += (int) $row->c;
        }
        $no_grade_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$assign_table} a
             LEFT JOIN {$grade_table} g ON g.student_id = a.student_id AND g.product_id = a.product_id
             WHERE a.ta_id = %d AND a.status != 'canceled' AND g.grade IS NULL",
            $ta->id
        ) );

        $base_url = remove_query_arg( [ 'ta_status', 'ta_term', 'ta_product', 'ta_grade', 'ta_search' ] );
        $has_filter = $term_filter !== '' || $product_filter !== '' || $grade_filter !== '' || $search_filter !== '';
        ?>

        <style>
        :root{
            --tss-primary:var(--ta-primary);--tss-primary-dark:var(--ta-primary-dark);
            --tss-active:var(--ta-adm-green);--tss-active-bg:#d1fae5;
            --tss-completed:var(--ta-adm-blue);--tss-completed-bg:#dbeafe;
            --tss-canceled:var(--ta-adm-red);--tss-canceled-bg:#fee2e2;
            --tss-pending:var(--ta-adm-gray-500);--tss-pending-bg:var(--ta-adm-gray-100);
            --tss-border:var(--ta-adm-gray-200);--tss-text:var(--ta-adm-gray-900);--tss-muted:var(--ta-adm-gray-500);--tss-bg:var(--ta-adm-gray-50);
            --tss-radius:12px;
            --tss-shadow:0 1px 3px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.06);
            --tss-shadow-md:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -1px rgba(0,0,0,.06);
        }
        .tss-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding-bottom:14px;border-bottom:1px solid var(--tss-border);flex-wrap:wrap;gap:10px}
        .tss-header-title{display:flex;align-items:center;gap:12px}
        .tss-header-title h3{margin:0 0 3px;font-size:18px;font-weight:800;color:var(--tss-text)}
        .tss-header-title p{margin:0;font-size:12px;color:var(--tss-muted)}

        .tss-stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px}
        @media(max-width:900px){.tss-stats-grid{grid-template-columns:repeat(2,1fr)}}
        .tss-stat-card{background:#fff;border-radius:var(--tss-radius);padding:16px;box-shadow:var(--tss-shadow);position:relative;overflow:hidden;transition:transform .15s,box-shadow .15s;border:1px solid var(--tss-border);cursor:pointer;text-decoration:none;display:block}
        .tss-stat-card:hover{transform:translateY(-2px);box-shadow:var(--tss-shadow-md)}
        .tss-stat-card.is-active{outline:2px solid var(--tss-primary);outline-offset:-1px}
        .tss-stat-icon{font-size:22px;margin-bottom:6px;line-height:1}
        .tss-stat-number{font-size:24px;font-weight:800;line-height:1;margin-bottom:4px;color:var(--tss-text)}
        .tss-stat-label{font-size:12px;color:var(--tss-muted);font-weight:500}
        .tss-stat-total .tss-stat-number{color:var(--tss-primary)}
        .tss-stat-active .tss-stat-number{color:var(--tss-active)}
        .tss-stat-completed .tss-stat-number{color:var(--tss-completed)}
        .tss-stat-canceled .tss-stat-number{color:var(--tss-canceled)}
        .tss-stat-nograde .tss-stat-number{color:#f59e0b}

        .tss-card{background:#fff;border-radius:var(--tss-radius);box-shadow:var(--tss-shadow);overflow:hidden;border:1px solid var(--tss-border)}
        .tss-filter-card{margin-bottom:14px;padding:14px 16px}
        .tss-filter-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
        .tss-search-wrap{position:relative;flex:1;min-width:200px}
        .tss-search-icon{position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:13px;pointer-events:none;color:var(--tss-muted)}
        .tss-search-input{width:100%;padding:8px 32px 8px 10px;border:1px solid var(--tss-border);border-radius:8px;font-size:13px;outline:none;transition:border-color .15s;box-sizing:border-box;font-family:inherit;direction:rtl;color:var(--tss-text);background:#fff}
        .tss-search-input:focus{border-color:var(--tss-primary)}
        .tss-select{padding:8px 10px;border:1px solid var(--tss-border);border-radius:8px;font-size:13px;outline:none;background:#fff;font-family:inherit;cursor:pointer;color:var(--tss-text)}
        .tss-select:focus{border-color:var(--tss-primary)}
        .tss-btn{display:inline-flex;align-items:center;gap:5px;padding:8px 14px;border-radius:8px;border:none;font-size:13px;font-weight:500;cursor:pointer;text-decoration:none;transition:all .15s;font-family:inherit;line-height:1}
        .tss-btn-reset{background:var(--ta-adm-gray-100);color:var(--tss-text)}.tss-btn-reset:hover{background:var(--ta-adm-gray-200)}

        .tss-bulkbar{display:none;gap:10px;align-items:center;background:#fdece3;border:1px solid #f6c6a8;border-radius:10px;padding:8px 12px;margin-bottom:12px}
        .tss-bulkbar .tss-count{font-size:13px;font-weight:600;color:#4338ca}
        .tss-btn-bulk{padding:6px 12px;border-radius:7px;border:none;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit}
        .tss-btn-bulk-active{background:var(--tss-active);color:#fff}.tss-btn-bulk-active:hover{background:#0d9c6f}
        .tss-btn-bulk-completed{background:var(--tss-completed);color:#fff}.tss-btn-bulk-completed:hover{background:#2563eb}

        .tss-table-header{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--tss-border);gap:10px;flex-wrap:wrap}
        .tss-table-info{font-size:13px;color:var(--tss-muted)}
        .tss-table-info strong{color:var(--tss-text)}
        .tss-filter-active-badge{background:#fdece3;color:var(--ta-primary);font-size:11px;padding:2px 8px;border-radius:99px;margin-right:8px}
        .tss-table-wrap{overflow-x:auto}
        .tss-table{width:100%;border-collapse:collapse;font-size:13px}
        .tss-table thead th{background:var(--tss-bg);padding:9px 12px;text-align:right;font-weight:600;font-size:11px;color:var(--tss-muted);text-transform:uppercase;border-bottom:1px solid var(--tss-border);white-space:nowrap;letter-spacing:.03em}
        .tss-table tbody tr{border-bottom:1px solid var(--ta-adm-gray-100);transition:background .1s}
        .tss-table tbody tr:hover{background:#fafafa}
        .tss-table tbody td{padding:9px 12px;vertical-align:middle}
        .tss-student-cell{display:flex;align-items:center;gap:9px}
        .tss-avatar{width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;background:linear-gradient(135deg,var(--tss-primary),#f2965f)}
        .tss-student-name{font-weight:600;font-size:13px;color:var(--tss-text)}
        .tss-student-email{font-size:11px;color:var(--tss-muted);direction:ltr;text-align:right}
        .tss-term-badge{background:#eff6ff;color:#1d4ed8;font-size:11px;padding:2px 7px;border-radius:99px;white-space:nowrap}
        .tss-course-badge{background:#f5f3ff;color:#6d28d9;font-size:11px;padding:2px 7px;border-radius:99px;white-space:nowrap;display:inline-block}
        .tss-msg-badge{font-size:12px;white-space:nowrap}
        .tss-link-btn{font-size:12px;color:var(--tss-primary);text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:3px}
        .tss-link-btn:hover{text-decoration:underline}
        .tss-muted-dash{color:#d1d5db}
        .tss-status-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:600;white-space:nowrap}
        .tss-status-active{background:var(--tss-active-bg);color:#047857}
        .tss-status-completed{background:var(--tss-completed-bg);color:#1d4ed8}
        .tss-status-canceled{background:var(--tss-canceled-bg);color:#b91c1c}
        .tss-grade-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:600;white-space:nowrap}
        .tss-bl-badge{background:#fee2e2;color:#b91c1c;font-size:10px;padding:1px 6px;border-radius:99px;margin-right:5px;white-space:nowrap}
        .tss-actions{display:flex;gap:5px;flex-wrap:wrap}
        .tss-act-btn{padding:5px 10px;border-radius:7px;border:1px solid var(--tss-border);background:#fff;font-size:11px;font-weight:600;cursor:pointer;transition:all .15s;font-family:inherit;color:var(--tss-text)}
        .tss-act-btn:hover{border-color:var(--tss-primary);color:var(--tss-primary)}
        .tss-act-btn-danger{color:#b91c1c}.tss-act-btn-danger:hover{border-color:#b91c1c;color:#b91c1c}
        .tss-act-btn-muted{color:var(--tss-muted)}
        .tss-empty{padding:48px 0 !important;text-align:center}
        .tss-empty-state{display:flex;flex-direction:column;align-items:center;gap:8px}
        .tss-empty-icon{font-size:38px}
        .tss-empty-state p{color:var(--tss-muted);font-size:14px;margin:0}
        .tss-checkbox{width:16px;height:16px;cursor:pointer}
        </style>

        <!-- ── هدر تب ─────────────────────────────────────────────────────────── -->
        <div class="tss-header">
            <div class="tss-header-title">
                <span style="font-size:28px;line-height:1">🧑‍🎓</span>
                <div>
                    <h3>هنرجویان من</h3>
                    <p>مدیریت هنرجویان، گروه‌ها، نمرات و وضعیت تخصیص‌ها</p>
                </div>
            </div>
        </div>

        <!-- ── کارت‌های آمار ────────────────────────────────────────────────── -->
        <div class="tss-stats-grid">
            <a href="<?= esc_url( add_query_arg( 'ta_status', 'all', $base_url ) ) ?>"
               class="tss-stat-card tss-stat-total<?= $status_filter === 'all' ? ' is-active' : '' ?>">
                <div class="tss-stat-icon">👥</div>
                <div class="tss-stat-number"><?= number_format( $total_all_students ) ?></div>
                <div class="tss-stat-label">کل هنرجویان</div>
            </a>
            <a href="<?= esc_url( add_query_arg( 'ta_status', 'active', $base_url ) ) ?>"
               class="tss-stat-card tss-stat-active<?= $status_filter === 'active' ? ' is-active' : '' ?>">
                <div class="tss-stat-icon">✅</div>
                <div class="tss-stat-number"><?= number_format( $status_counts['active'] ) ?></div>
                <div class="tss-stat-label">فعال</div>
            </a>
            <a href="<?= esc_url( add_query_arg( 'ta_status', 'completed', $base_url ) ) ?>"
               class="tss-stat-card tss-stat-completed<?= $status_filter === 'completed' ? ' is-active' : '' ?>">
                <div class="tss-stat-icon">🎓</div>
                <div class="tss-stat-number"><?= number_format( $status_counts['completed'] ) ?></div>
                <div class="tss-stat-label">تکمیل‌شده</div>
            </a>
            <a href="<?= esc_url( add_query_arg( 'ta_status', 'canceled', $base_url ) ) ?>"
               class="tss-stat-card tss-stat-canceled<?= $status_filter === 'canceled' ? ' is-active' : '' ?>">
                <div class="tss-stat-icon">❌</div>
                <div class="tss-stat-number"><?= number_format( $status_counts['canceled'] ) ?></div>
                <div class="tss-stat-label">انصرافی</div>
            </a>
            <a href="<?= esc_url( add_query_arg( [ 'ta_status' => 'all', 'ta_grade' => 'none' ], $base_url ) ) ?>"
               class="tss-stat-card tss-stat-nograde<?= $grade_filter === 'none' ? ' is-active' : '' ?>">
                <div class="tss-stat-icon">⏳</div>
                <div class="tss-stat-number"><?= number_format( $no_grade_count ) ?></div>
                <div class="tss-stat-label">بدون نمره</div>
            </a>
        </div>

        <!-- ── فیلترها ───────────────────────────────────────────────────────── -->
        <div class="tss-card tss-filter-card">
            <form method="GET">
                <?php foreach ( $_GET as $k => $v ) :
                    if ( in_array( $k, [ 'ta_status', 'ta_term', 'ta_product', 'ta_grade', 'ta_search' ], true ) ) continue;
                ?>
                    <input type="hidden" name="<?= esc_attr($k) ?>" value="<?= esc_attr($v) ?>">
                <?php endforeach; ?>
                <input type="hidden" name="ta_status" value="<?= esc_attr( $status_filter ) ?>">
                <div class="tss-filter-row">
                    <div class="tss-search-wrap">
                        <span class="tss-search-icon">🔍</span>
                        <input type="text" name="ta_search" value="<?= esc_attr( $search_filter ) ?>"
                               placeholder="جستجو نام یا ایمیل هنرجو..." class="tss-search-input">
                    </div>

                    <select name="ta_term" class="tss-select" onchange="this.form.submit()">
                        <option value="">همه ترم‌ها</option>
                        <?php foreach ( $terms_list as $term ) : ?>
                            <option value="<?= (int) $term->id ?>" <?= (string) $term_filter === (string) $term->id ? 'selected' : '' ?>>
                                <?= esc_html( $term->name ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="ta_product" class="tss-select" onchange="this.form.submit()">
                        <option value="">همه دوره‌ها</option>
                        <?php foreach ( $products_list as $prod ) : ?>
                            <option value="<?= (int) $prod->ID ?>" <?= (string) $product_filter === (string) $prod->ID ? 'selected' : '' ?>>
                                <?= esc_html( $prod->post_title ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="ta_grade" class="tss-select" onchange="this.form.submit()">
                        <option value=""         <?= $grade_filter === ''         ? 'selected' : '' ?>>همه نمرات</option>
                        <option value="none"     <?= $grade_filter === 'none'     ? 'selected' : '' ?>>ثبت‌نشده</option>
                        <option value="pass"     <?= $grade_filter === 'pass'     ? 'selected' : '' ?>>قبولی</option>
                        <option value="repeat"   <?= $grade_filter === 'repeat'   ? 'selected' : '' ?>>تکرار دوره</option>
                        <option value="critique" <?= $grade_filter === 'critique' ? 'selected' : '' ?>>نقد اثر</option>
                    </select>

                    <button type="submit" class="tss-btn" style="background:var(--tss-primary);color:#fff">🔎 اعمال فیلتر</button>
                    <?php if ( $has_filter || $status_filter !== 'active' ) : ?>
                        <a href="<?= esc_url( $base_url ) ?>" class="tss-btn tss-btn-reset">پاک کردن</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- ── نوار وضعیت + bulk actions ───────────────────────────────────── -->
        <div id="ta-bulk-actions" class="tss-bulkbar">
            <span id="ta-selected-count" class="tss-count">۰ انتخاب‌شده</span>
            <button class="tss-btn-bulk tss-btn-bulk-active" onclick="taBulkUpdateStatus('active')">تغییر وضعیت به فعال</button>
            <button class="tss-btn-bulk tss-btn-bulk-completed" onclick="taBulkUpdateStatus('completed')">تغییر وضعیت به تکمیل‌شده</button>
        </div>

        <!-- ── جدول هنرجویان ────────────────────────────────────────────────── -->
        <div class="tss-card">
            <div class="tss-table-header">
                <div class="tss-table-info">
                    نمایش <strong><?= number_format( count( $students ) ) ?></strong> هنرجو
                    <?php if ( $has_filter ) : ?>
                        <span class="tss-filter-active-badge">فیلتر فعال</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="tss-table-wrap">
            <table class="tss-table">
                <thead>
                    <tr>
                        <th style="width:36px;"><input type="checkbox" class="tss-checkbox" id="ta-select-all" title="انتخاب همه" onchange="taToggleSelectAll(this.checked)"></th>
                        <th>هنرجو</th><th>ترم</th><th>دوره</th><th>پیام‌رسان</th><th>گروه</th><?php if ( class_exists( 'TA_Grupo_Chat' ) && TA_Grupo_Chat::enabled() ) : ?><th>ورود به چت</th><?php endif; ?><th>وضعیت</th><th>نمره</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $students ) ) : ?>
                    <tr><td colspan="<?= ( class_exists( 'TA_Grupo_Chat' ) && TA_Grupo_Chat::enabled() ) ? '10' : '9' ?>" class="tss-empty">
                        <div class="tss-empty-state">
                            <div class="tss-empty-icon">🔍</div>
                            <p>هنرجویی با این فیلترها یافت نشد.</p>
                        </div>
                    </td></tr>
                <?php else : ?>
                    <?php foreach ( $students as $s ) :
                        $initial = mb_substr( trim( (string) $s->display_name ), 0, 1 );
                    ?>
                    <tr id="student-row-<?= $s->id ?>">
                        <td><input type="checkbox" class="ta-row-check tss-checkbox" data-id="<?= $s->id ?>" onchange="taOnRowCheck()"></td>
                        <td>
                            <div class="tss-student-cell">
                                <div class="tss-avatar"><?= esc_html( $initial ) ?></div>
                                <div>
                                    <div class="tss-student-name">
                                        <?= esc_html( $s->display_name ) ?>
                                        <?php if ( ! empty( $s->is_blacklisted ) ) : ?>
                                            <span class="tss-bl-badge">لیست سیاه</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="tss-student-email"><?= esc_html( $s->user_email ) ?></div>
                                </div>
                            </div>
                        </td>
                        <td><?php if ( $s->term_name ) : ?><span class="tss-term-badge"><?= esc_html( $s->term_name ) ?></span><?php else : ?><span class="tss-muted-dash">—</span><?php endif; ?></td>
                        <td><span class="tss-course-badge"><?= $course_labels[ $s->course_type ] ?? esc_html( $s->course_type ) ?></span></td>
                        <td><span class="tss-msg-badge"><?= $all_msg_icons_ref[ $s->messenger ] ?? '' ?> <?= $m_labels[ $s->messenger ] ?? esc_html( $s->messenger ) ?></span></td>
                        <td>
                            <?php if ( $s->group_link ) : ?>
                                <a href="<?= esc_url( TA_URL_Helper::build_join_url( (int) $s->id ) ) ?>" target="_blank" class="tss-link-btn">🔗 ورود به گروه</a>
                            <?php else : ?>
                                <span class="tss-muted-dash">—</span>
                            <?php endif; ?>
                        </td>
                        <?php if ( class_exists( 'TA_Grupo_Chat' ) && TA_Grupo_Chat::enabled() ) : ?><td>
                            <?php if ( $s->status !== 'canceled' ) : ?>
                                <a href="<?= esc_url( TA_Grupo_Chat::build_join_url( (int) $s->id ) ) ?>" target="_blank" rel="noopener" class="tss-link-btn" style="color:#fff!important;background:#7c3aed;padding:5px 9px;border-radius:6px">💬 ورود به چت</a>
                            <?php else : ?>
                                <span class="tss-muted-dash">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <?php if ( $s->status === 'completed' ) : ?>
                                <span class="tss-status-badge tss-status-completed">🎓 تکمیل‌شده</span>
                            <?php elseif ( $s->status === 'canceled' ) : ?>
                                <span class="tss-status-badge tss-status-canceled">❌ انصرافی</span>
                            <?php else : ?>
                                <span class="tss-status-badge tss-status-active">✅ فعال</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $s->grade ) :
                                $gi = TA_Grade::all_grades()[ $s->grade ] ?? null;
                                $gcolor = $gi['color'] ?? '#6366f1';
                                $gbg    = $gi['bg']    ?? '#eef2ff';
                                $gicon  = $gi['icon']  ?? '🏷';
                            ?>
                                <span class="tss-grade-badge" style="color:<?= esc_attr($gcolor) ?>;background:<?= esc_attr($gbg) ?>"><?= $gicon ?> <?= TA_Grade::grade_label( $s->grade ) ?></span>
                            <?php else : ?>
                                <span class="tss-grade-badge" style="color:#6b7280;background:#f3f4f6">⏳ ثبت‌نشده</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="tss-actions">
                            <?php if ( $s->status !== 'canceled' ) : ?>
                                <?php if ( TA_Settings_Helper::is_enabled( 'ta_grading_enabled' ) ) : ?>
                                <button class="tss-act-btn" onclick="taOpenGradeModal(<?= htmlspecialchars( json_encode( [
                                    'student_id'    => $s->student_id,
                                    'product_id'    => $s->product_id,
                                    'course_type'   => $s->course_type,
                                    'assignment_id' => $s->id,
                                    'name'          => $s->display_name,
                                    'prod'          => $s->product_name ?? '',
                                    'grade'         => $s->grade ?? 'pending',
                                ] ), ENT_QUOTES ) ?>)">📝 ثبت نمره</button>
                                <?php endif; ?>
                            <?php endif; ?>
                                <?php if ( ! empty( $s->is_blacklisted ) ) : ?>
                                    <button class="tss-act-btn tss-act-btn-muted" onclick="taRemoveBlacklist(<?= $s->student_id ?>, '<?= esc_js( $s->display_name ) ?>')">↩️ حذف از لیست سیاه</button>
                                <?php else : ?>
                                    <button class="tss-act-btn tss-act-btn-danger" onclick="taBlacklist(<?= $s->student_id ?>, '<?= esc_js( $s->display_name ) ?>')">🚫 لیست سیاه</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>


        <?php if ( TA_Settings_Helper::is_enabled( 'ta_grading_enabled' ) ) : ?>
        <!-- ── مودال ثبت نمره (تب هنرجو) ─────────────────────────────────── -->
        <style>
        :root{--tgm-text:var(--ta-adm-gray-900);--tgm-muted:var(--ta-adm-gray-500);--tgm-border:var(--ta-adm-gray-200)}
        .tgm-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;display:flex;align-items:center;justify-content:center}
        .tgm-modal{background:#fff;border-radius:16px;width:100%;max-width:460px;box-shadow:0 20px 60px rgba(0,0,0,.25);animation:tgmSlideUp .2s ease}
        @keyframes tgmSlideUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
        .tgm-modal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--tgm-border)}
        .tgm-modal-header h2{margin:0;font-size:16px;font-weight:700;color:var(--tgm-text)}
        .tgm-modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--tgm-muted);width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;transition:background .1s}.tgm-modal-close:hover{background:var(--ta-adm-gray-100)}
        .tgm-modal-body{padding:20px}
        .tgm-modal-student{background:var(--ta-adm-gray-50);border-radius:10px;padding:11px 14px;margin-bottom:16px;font-size:13px;font-weight:600;color:var(--tgm-text);line-height:1.5;border:1px solid var(--tgm-border)}
        .tgm-form-group{margin-bottom:16px}
        .tgm-form-group label{display:block;font-size:11px;font-weight:700;color:var(--tgm-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}
        .tgm-grade-picker{display:grid;grid-template-columns:1fr 1fr;gap:8px}
        .tgm-grade-option{display:block;cursor:pointer}
        .tgm-grade-option input{display:none}
        .tgm-grade-option span{display:flex;align-items:center;gap:8px;padding:9px 12px;border-radius:10px;border:2px solid var(--tgm-border);font-size:13px;font-weight:500;transition:all .15s;color:var(--tgm-text)}
        .tgm-grade-option input:checked + span{border-color:var(--gc);background:var(--gb);color:var(--gc)}
        .tgm-grade-option:hover span{border-color:var(--gc);background:var(--gb)}
        .tgm-textarea{width:100%;padding:9px 12px;border:1px solid var(--tgm-border);border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;outline:none;direction:rtl;box-sizing:border-box;transition:border-color .15s;color:var(--tgm-text)}.tgm-textarea:focus{border-color:var(--ta-primary)}
        .tgm-modal-footer{display:flex;gap:8px;padding:14px 20px;border-top:1px solid var(--tgm-border);justify-content:flex-end}
        .tgm-btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:8px;border:none;font-size:13px;font-weight:500;cursor:pointer;transition:all .15s;font-family:inherit;line-height:1}
        .tgm-btn-save{background:var(--ta-primary);color:#fff;padding:10px 22px}.tgm-btn-save:hover{background:var(--ta-primary-dark)}
        .tgm-btn-cancel{background:var(--ta-adm-gray-100);color:var(--tgm-text);padding:10px 18px}.tgm-btn-cancel:hover{background:var(--ta-adm-gray-200)}
        </style>
        <div id="ta-gm-modal" class="tgm-modal-overlay" style="display:none">
            <div class="tgm-modal">
                <div class="tgm-modal-header">
                    <h2>ثبت نمره</h2>
                    <button class="tgm-modal-close" onclick="taCloseGradeModal()">✕</button>
                </div>
                <div>
                    <input type="hidden" id="ta-gm-student-id">
                    <input type="hidden" id="ta-gm-product-id">
                    <input type="hidden" id="ta-gm-assignment-id">
                    <div class="tgm-modal-body">
                        <div class="tgm-modal-student" id="ta-gm-student-info"></div>
                        <div class="tgm-form-group">
                            <label>نمره</label>
                            <div class="tgm-grade-picker" id="ta-gm-grade-picker"></div>
                        </div>
                        <div class="tgm-form-group">
                            <label>یادداشت</label>
                            <textarea id="ta-gm-notes" class="tgm-textarea" rows="3" placeholder="یادداشت اختیاری..."></textarea>
                        </div>
                        <div class="tgm-form-group">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal;">
                                <input type="checkbox" id="ta-gm-complete" checked style="width:16px;height:16px;">
                                <span>وضعیت تخصیص به «تکمیل‌شده» تغییر کند</span>
                            </label>
                        </div>
                    </div>
                    <div class="tgm-modal-footer">
                        <button type="button" class="tgm-btn tgm-btn-save" id="ta-gm-save-btn" onclick="taSubmitGradeModal()">ثبت نمره</button>
                        <button type="button" class="tgm-btn tgm-btn-cancel" onclick="taCloseGradeModal()">انصراف</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function(){
            var ALL_GRADES_GM = <?= json_encode( TA_Grade::all_grades() ) ?>;

            function getGradesForType(courseType){
                var map = {
                    'intro':    ['pass','critique','repeat','pending'],
                    'basic_writing': ['pass','critique','intro_permission','repeat','pending'],
                    'critique': ['pass','repeat_critique','repeat_intro','pending'],
                    'advanced': ['pass','repeat_advanced','ladder','pending'],
                    'ladder':   ['pass','repeat_ladder','repeat_advanced','pending'],
                };
                var allowed = map[courseType] || ['pass','repeat','pending'];
                var result = {};
                allowed.forEach(function(k){ if(ALL_GRADES_GM[k]) result[k] = ALL_GRADES_GM[k]; });
                return result;
            }

            window.taOpenGradeModal = function(data){
                document.getElementById('ta-gm-student-id').value    = data.student_id;
                document.getElementById('ta-gm-product-id').value    = data.product_id;
                document.getElementById('ta-gm-assignment-id').value = data.assignment_id;
                document.getElementById('ta-gm-notes').value         = '';
                document.getElementById('ta-gm-complete').checked    = true;
                document.getElementById('ta-gm-student-info').innerHTML =
                    '👤 ' + data.name + (data.prod ? '  |  📚 ' + data.prod : '');

                var grades = getGradesForType(data.course_type || '');
                var picker = document.getElementById('ta-gm-grade-picker');
                picker.innerHTML = '';
                Object.entries(grades).forEach(function([key, g]){
                    var label = document.createElement('label');
                    label.className = 'tgm-grade-option';
                    label.style.cssText = '--gc:' + g.color + ';--gb:' + g.bg;
                    label.innerHTML = '<input type="radio" name="ta_gm_grade" value="' + key + '"'
                        + (key === (data.grade || 'pending') ? ' checked' : '') + '>'
                        + '<span>' + g.icon + ' ' + g.label + '</span>';
                    picker.appendChild(label);
                });

                document.getElementById('ta-gm-modal').style.display = 'flex';
                document.body.style.overflow = 'hidden';
            };

            window.taCloseGradeModal = function(){
                document.getElementById('ta-gm-modal').style.display = 'none';
                document.body.style.overflow = '';
            };

            window.taSubmitGradeModal = function(){
                var studentId    = document.getElementById('ta-gm-student-id').value;
                var productId    = document.getElementById('ta-gm-product-id').value;
                var assignmentId = document.getElementById('ta-gm-assignment-id').value;
                var gradeEl      = document.querySelector('#ta-gm-grade-picker input[name="ta_gm_grade"]:checked');
                var notes        = document.getElementById('ta-gm-notes').value;
                var markComplete = document.getElementById('ta-gm-complete').checked ? 1 : 0;
                var $btn         = document.getElementById('ta-gm-save-btn');

                if(!gradeEl){ alert('لطفاً یک نمره انتخاب کنید.'); return; }

                $btn.disabled    = true;
                $btn.textContent = '...';

                jQuery.post(
                    taSystem.ajaxUrl,
                    {
                        action:        'ta_grade_student',
                        nonce:         taSystem.nonce,
                        student_id:    studentId,
                        product_id:    productId,
                        grade:         gradeEl.value,
                        notes:         notes,
                        assignment_id: assignmentId,
                        mark_complete: markComplete,
                    },
                    function(res){
                        $btn.disabled    = false;
                        $btn.textContent = 'ثبت نمره';
                        if(res && res.success){
                            taCloseGradeModal();
                            window.location.reload();
                        } else {
                            alert((res && res.data && res.data.message) ? res.data.message : 'خطا در ثبت نمره');
                        }
                    }
                );
            };

            document.addEventListener('keydown', function(e){
                if(e.key === 'Escape') taCloseGradeModal();
            });
            document.addEventListener('DOMContentLoaded', function(){
                var overlay = document.getElementById('ta-gm-modal');
                if(overlay) overlay.addEventListener('click', function(e){ if(e.target === this) taCloseGradeModal(); });
            });
        })();
        </script>
        <?php endif; ?>

        <?php
    }



    // ── حق‌الزحمه ────────────────────────────────────────────────────────────
    private function tab_payroll( object $ta ): void {
        $nonce = wp_create_nonce('ta_dashboard_nonce');
        ?>
        <div class="ta-section ta-payroll-ta-wrap" dir="rtl">

            <div class="tapr-header">
                <h3>💰 حق‌الزحمه من</h3>
                <p class="tapr-subtitle">درخواست‌های تایید حق‌الزحمه که مسئول اجرایی مبنا برای شما ارسال کرده است</p>
            </div>

            <div id="tapr-loading" class="tapr-loading">
                <div class="ta-spinner"></div> در حال بارگذاری…
            </div>

            <div id="tapr-empty" class="tapr-empty" style="display:none">
                هیچ درخواست حق‌الزحمه‌ای وجود ندارد.
            </div>

            <div id="tapr-list" style="display:none"></div>

        </div>

        <!-- مودال تاریخچه استادیار -->
        <div id="tapr-history-overlay" class="ta-modal-overlay" style="display:none">
            <div class="ta-modal-box" style="width:520px;max-width:95vw">
                <div class="ta-modal-box-header">
                    <h4>📋 تاریخچه درخواست</h4>
                    <button id="tapr-history-close" class="ta-modal-close-btn">✕</button>
                </div>
                <div class="ta-modal-box-body" style="max-height:55vh;overflow-y:auto" id="tapr-history-body">
                </div>
                <div class="ta-modal-box-footer">
                    <button class="ta-btn" id="tapr-history-cancel">بستن</button>
                </div>
            </div>
        </div>

        <!-- مودال پاسخ استادیار -->
        <div id="tapr-respond-overlay" class="ta-modal-overlay" style="display:none">
            <div class="ta-modal-box">
                <div class="ta-modal-box-header">
                    <h4 id="tapr-respond-title">پاسخ به درخواست</h4>
                    <button id="tapr-respond-close" class="ta-modal-close-btn">✕</button>
                </div>
                <div class="ta-modal-box-body">
                    <div id="tapr-respond-info" class="tapr-respond-info"></div>
                    <div class="tapr-field">
                        <label>توضیحات شما (اختیاری)</label>
                        <textarea id="tapr-respond-note" rows="3" placeholder="دلیل تایید یا رد، سوالات و…"></textarea>
                    </div>
                </div>
                <div class="ta-modal-box-footer" style="flex-wrap:wrap;gap:8px">
                    <button class="ta-btn ta-btn-success" id="tapr-approve-btn">✅ تایید مبلغ جدید</button>
                    <div id="tapr-approve-prev-wrap" style="display:none">
                        <button class="ta-btn" id="tapr-approve-prev-btn" style="background:#8b5cf6;color:#fff">✅ تایید مبلغ قبلی</button>
                    </div>
                    <button class="ta-btn ta-btn-danger"  id="tapr-reject-btn">❌ رد می‌کنم</button>
                    <button class="ta-btn" id="tapr-respond-cancel">انصراف</button>
                </div>
            </div>
        </div>

        <style>
        .ta-payroll-ta-wrap{padding:0}
        .tapr-header{margin-bottom:20px}
        .tapr-header h3{margin:0 0 4px;font-size:18px;font-weight:800;color:var(--ta-adm-gray-900)}
        .tapr-subtitle{margin:0;font-size:13px;color:var(--ta-adm-gray-500)}
        .tapr-loading,.tapr-empty{display:flex;align-items:center;gap:10px;padding:40px 0;color:var(--ta-adm-gray-500);font-size:14px}
        .tapr-card{background:#fff;border:1.5px solid var(--ta-adm-gray-200);border-radius:14px;padding:20px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
        .tapr-card-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px}
        .tapr-term-name{font-size:16px;font-weight:800;color:var(--ta-adm-gray-900)}
        .tapr-req-date{font-size:11px;color:var(--ta-adm-gray-400);margin-top:2px}
        .tapr-status-badge{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:99px;font-size:12px;font-weight:700}
        .tapr-fee-row{display:flex;align-items:center;gap:8px;margin-bottom:14px;padding:12px;background:#f0fdf4;border-radius:10px;border:1px solid #bbf7d0}
        .tapr-fee-label{font-size:13px;color:var(--ta-adm-gray-700);font-weight:600}
        .tapr-fee-amount{font-size:20px;font-weight:900;color:#059669;font-family:monospace}
        .tapr-fee-unit{font-size:12px;color:var(--ta-adm-gray-500)}
        .tapr-notes-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
        .tapr-note-box{padding:10px;background:var(--ta-adm-gray-50);border:1px solid var(--ta-adm-gray-200);border-radius:8px}
        .tapr-note-box-label{font-size:11px;font-weight:700;color:var(--ta-adm-gray-500);margin-bottom:5px}
        .tapr-note-box-content{font-size:13px;color:var(--ta-adm-gray-700);line-height:1.5}
        .tapr-actions{display:flex;gap:8px;flex-wrap:wrap}
        .tapr-paid-banner{display:flex;align-items:center;gap:8px;padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:13px;font-weight:700;color:#1d4ed8}
        .tapr-field{display:flex;flex-direction:column;gap:6px}
        .tapr-field label{font-size:12px;font-weight:700;color:var(--ta-adm-gray-700)}
        .tapr-field textarea{padding:8px 10px;border:1.5px solid var(--ta-adm-gray-200);border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;min-height:80px;outline:none}
        .tapr-field textarea:focus{border-color:#3b82f6}
        .tapr-respond-info{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px;font-size:13px;margin-bottom:14px}
        .ta-modal-box-footer{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
        .tapr-card-diff{border-color:#fde68a;background:#fffbeb}
        .tapr-diff-alert{background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:12px;color:#92400e;margin-bottom:12px}
        .tapr-sep{margin:0 8px;color:#d1d5db}
        .tapr-approved-fee{color:#1d4ed8!important}
        /* جدول مبالغ */
        .tapr-fee-table{width:100%;border-collapse:collapse;margin-bottom:14px;font-size:13px}
        .tapr-fee-table tbody tr{border-bottom:1px solid var(--ta-adm-gray-100)}
        .tapr-fee-table tbody tr:last-child{border-bottom:none}
        .tapr-ft-label{padding:8px 10px;color:var(--ta-adm-gray-700);font-weight:600;width:55%}
        .tapr-ft-amount{padding:8px 10px;font-family:monospace;font-weight:800;font-size:15px;color:#059669;white-space:nowrap}
        .tapr-ft-unit{font-size:11px;font-weight:400;color:var(--ta-adm-gray-400)}
        .tapr-ft-status{padding:8px 10px;text-align:left}
        .tapr-ft-diff-row{background:#fef9ee}
        .tapr-ft-diff-row .tapr-ft-label,.tapr-ft-diff-row .tapr-ft-amount{font-weight:800}
        /* بخش تفاوت مستقل */
        .tapr-diff-section{border:2px solid #fde68a;background:#fffbeb;border-radius:12px;padding:14px;margin:12px 0}
        .tapr-diff-section-header{display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap}
        .tapr-diff-section-title{font-weight:800;font-size:13px;color:#92400e}
        .tapr-diff-section-badge{padding:2px 10px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}
        .tapr-diff-admin-note{font-size:12px;color:var(--ta-adm-gray-500);background:var(--ta-adm-gray-100);border-radius:6px;padding:6px 10px;margin-top:8px}
        /* جدول جزئیات breakdown */
        .tapr-section-title{font-size:12px;font-weight:700;color:var(--ta-adm-gray-500);margin:14px 0 6px;letter-spacing:.03em;text-transform:uppercase}
        .tapr-breakdown-toggle{margin:10px 0 4px}
        .tapr-breakdown-table-wrap{margin:6px 0 12px;overflow-x:auto;border-radius:10px;border:1.5px solid var(--ta-adm-gray-200)}
        .tapr-bd-table{width:100%;border-collapse:collapse;font-size:12px}
        .tapr-bd-table thead tr{background:var(--ta-adm-gray-100)}
        .tapr-bd-table thead th{padding:8px 10px;text-align:right;font-weight:700;color:var(--ta-adm-gray-700);border-bottom:2px solid var(--ta-adm-gray-200);white-space:nowrap}
        .tapr-bd-row{border-bottom:1px solid var(--ta-adm-gray-100)}
        .tapr-bd-row:last-child{border-bottom:none}
        .tapr-bd-row td{padding:8px 10px;color:var(--ta-adm-gray-700)}
        .tapr-bd-excluded td{color:var(--ta-adm-gray-400);background:#fafafa}
        .tapr-bd-product{max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .tapr-bd-mono{font-family:monospace;font-weight:700;white-space:nowrap}
        .tapr-bd-fee{font-family:monospace;font-weight:800;color:#059669}
        .tapr-bd-fee-ex{color:var(--ta-adm-gray-400)!important}
        .tapr-bd-total{background:#eff6ff}
        .tapr-bd-total td{padding:8px 10px;font-size:13px}
        .tapr-excluded-lbl{background:#fee2e2;color:#991b1b;border-radius:4px;padding:1px 5px;font-size:10px;margin-right:4px}
        .tapr-course-chip{background:#ede9fe;color:#6d28d9;border-radius:6px;padding:2px 7px;font-size:11px;font-weight:700;white-space:nowrap}
        .tapr-chg-list{list-style:none;padding:0;margin:0;border-right:3px solid var(--ta-adm-gray-200);margin-right:8px}
        .tapr-chg-item{position:relative;padding:0 20px 12px 0;margin-right:-3px}
        .tapr-chg-item:last-child{padding-bottom:0}
        .tapr-chg-pay{background:linear-gradient(90deg,#eff6ff 0%,transparent 100%);border-radius:6px;padding-right:20px}
        .tapr-chg-dot{position:absolute;right:-7px;top:4px;width:12px;height:12px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 2px var(--ta-adm-gray-200)}
        .tapr-chg-body{display:flex;flex-direction:column;gap:4px}
        .tapr-chg-amount{font-size:15px;font-weight:800;font-family:monospace}
        .tapr-chg-meta{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
        .tapr-chg-date{font-size:11px;color:var(--ta-adm-gray-400)}
        .tapr-chg-status{padding:1px 8px;border-radius:99px;font-size:10px;font-weight:700;white-space:nowrap}
        .tapr-chg-note{font-size:11px;color:var(--ta-adm-gray-500);background:var(--ta-adm-gray-50);border-radius:5px;padding:3px 7px;border:1px solid var(--ta-adm-gray-200)}
        .tapr-changes-inline{padding:4px 0}
        /* جدول breakdown همیشه باز */
        .tapr-breakdown-table-wrap{margin:6px 0 12px;overflow-x:auto;border-radius:10px;border:1.5px solid var(--ta-adm-gray-200)}
        /* تاریخچه */
        .tapr-timeline{padding:0;margin:0;list-style:none;border-right:3px solid var(--ta-adm-gray-200);margin-right:10px}
        .tapr-timeline-item{position:relative;padding:0 22px 14px 0;margin-right:-3px}
        .tapr-timeline-item:last-child{padding-bottom:0}
        .tapr-timeline-dot{position:absolute;right:-7px;top:4px;width:12px;height:12px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 2px var(--ta-adm-gray-200)}
        .tapr-dot-sent,.tapr-dot-resent{background:#8b5cf6}
        .tapr-dot-approved,.tapr-dot-ta_approved,.tapr-dot-admin_approved{background:#10b981}
        .tapr-dot-rejected,.tapr-dot-admin_rejected{background:#ef4444}
        .tapr-dot-ta_rejected{background:#f97316}
        .tapr-dot-paid{background:#1d4ed8}
        .tapr-tl-meta{font-size:11px;color:var(--ta-adm-gray-400);margin-bottom:2px}
        .tapr-tl-action{font-size:13px;font-weight:700;color:var(--ta-adm-gray-900)}
        .tapr-tl-fee{font-size:12px;color:#059669;font-family:monospace;font-weight:700}
        .tapr-tl-note{font-size:12px;color:var(--ta-adm-gray-500);margin-top:3px;background:var(--ta-adm-gray-50);border-radius:6px;padding:4px 8px}
        .tapr-tl-empty{color:var(--ta-adm-gray-400);font-size:13px;text-align:center;padding:20px}
        .ta-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center}
        .ta-modal-box{background:#fff;border-radius:14px;width:460px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.2)}
        .ta-modal-box-header{padding:16px 20px 12px;border-bottom:1px solid var(--ta-adm-gray-200);display:flex;align-items:center;justify-content:space-between}
        .ta-modal-box-header h4{margin:0;font-size:15px;font-weight:700}
        .ta-modal-close-btn{background:none;border:none;font-size:18px;cursor:pointer;color:#888;line-height:1;padding:0}
        .ta-modal-box-body{padding:18px 20px}
        .ta-modal-box-footer{padding:14px 20px;border-top:1px solid var(--ta-adm-gray-200);display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
        .ta-btn-danger{background:#ef4444;color:#fff;padding:7px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none}
        </style>

        <script>
        (function($){
            var ajaxUrl = '<?= admin_url('admin-ajax.php') ?>';
            var nonce   = '<?= $nonce ?>';

            // ── بارگذاری ─────────────────────────────────────────────────────
            function loadPayroll(){
                $.post(ajaxUrl, {action:'ta_payroll_ta_get_items', nonce:nonce}, function(res){
                    $('#tapr-loading').hide();
                    if(!res.success){ $('#tapr-empty').show(); return; }
                    var items=res.data;
                    if(!items||!items.length){ $('#tapr-empty').show(); return; }
                    renderItems(items);
                });
            }

            var STATUS_LABELS = {
                pending:     {label:'در انتظار پاسخ شما',       color:'#f59e0b', icon:'⏳'},
                ta_approved: {label:'تایید شما — منتظر ادمین', color:'#8b5cf6', icon:'✅'},
                ta_rejected: {label:'رد کردید',                 color:'#f97316', icon:'↩️'},
                approved:    {label:'تایید نهایی ادمین',         color:'#10b981', icon:'✅'},
                rejected:    {label:'رد شده توسط ادمین',        color:'#ef4444', icon:'❌'},
                paid:        {label:'پرداخت شده',                color:'#3b82f6', icon:'💳'},
            };

            // نگاشت نوع دوره به برچسب فارسی
            var COURSE_LABELS = {
                'خلاق':'خلاق','مقدماتی':'مقدماتی','پیشرفته':'پیشرفته','نقد اثر':'نقد اثر'
            };
            var DIFF_ST_LABELS = {
                'pending'    :{color:'#f59e0b',icon:'⏳',label:'انتظار تایید شما'},
                'ta_approved':{color:'#8b5cf6',icon:'✅',label:'تایید شما'},
                'ta_rejected':{color:'#f97316',icon:'↩️',label:'رد شده توسط شما'},
                'approved'   :{color:'#10b981',icon:'✅',label:'تایید نهایی ادمین'},
                'rejected'   :{color:'#ef4444',icon:'❌',label:'رد شده'},
                'paid'       :{color:'#3b82f6',icon:'💳',label:'پرداخت شده'},
            };

            function renderItems(items){
                var html='';
                $.each(items, function(i,r){
                    var st=STATUS_LABELS[r.status]||{label:r.status,color:'#888',icon:'•'};
                    var approvedFee    = parseFloat(r.approved_fee||0);
                    var prevApprovedFee= parseFloat(r.prev_approved_fee||0);
                    var sentFee        = parseFloat(r.total_fee||0);
                    var refFee         = approvedFee>0 ? approvedFee : (prevApprovedFee>0 ? prevApprovedFee : 0);
                    var hasDiff        = refFee>0 && Math.abs(refFee-sentFee)>1;

                    // داده‌های مسیر تفاوت مستقل
                    var diffStatus     = r.diff_status||null;
                    var diffAmount     = parseFloat(r.diff_amount||0);
                    var diffStInfo     = diffStatus ? (DIFF_ST_LABELS[diffStatus]||null) : null;

                    // تفاوت کنونی با آخرین تایید (ستون تفاوت)
                    var finalApproved  = approvedFee>0 ? approvedFee : (prevApprovedFee>0 ? prevApprovedFee : 0);
                    var realDiff       = finalApproved>0 ? (sentFee - finalApproved) : 0;

                    html+='<div class="tapr-card'+(hasDiff?' tapr-card-diff':'')+'" data-req-id="'+r.id+'">';

                    // ── هدر ─────────────────────────────────────────────────────
                    html+='<div class="tapr-card-header">';
                    html+='<div>';
                    html+='<div class="tapr-term-name">📅 ترم: '+esc(r.term_name)+'</div>';
                    html+='<div class="tapr-req-date">ارسال شده: '+esc(r.requested_at)+'</div>';
                    html+='</div>';
                    html+='</div>';

                    // ── جدول مبالغ اصلی ─────────────────────────────────────────
                    html+='<div class="tapr-section-title">💰 خلاصه مالی</div>';
                    html+='<table class="tapr-fee-table">';
                    html+='<tbody>';
                    html+='<tr><td class="tapr-ft-label">💸 مبلغ درخواست شده</td>';
                    html+='<td class="tapr-ft-amount">'+nfFa(sentFee)+' <span class="tapr-ft-unit">ت</span></td>';
                    html+='<td class="tapr-ft-status"></td></tr>';
                    if(refFee>0){
                        var refLbl=approvedFee>0?(r.status==='paid'?'💳 مبلغ پرداخت شده':'✅ مبلغ تایید شده'):'🔒 مبلغ قبلی تایید/پرداخت‌شده';
                        var refCol=approvedFee>0?'#10b981':'#6b7280';
                        html+='<tr><td class="tapr-ft-label" style="color:'+refCol+'">'+refLbl+'</td>';
                        html+='<td class="tapr-ft-amount" style="color:'+refCol+'">'+nfFa(refFee)+' <span class="tapr-ft-unit">ت</span></td>';
                        html+='<td class="tapr-ft-status"></td></tr>';
                    }
                    // نمایش تفاوت کنونی با آخرین تایید
                    if(finalApproved>0 && Math.abs(realDiff)>1){
                        var diffColor=realDiff>0?'#ef4444':'#059669';
                        var diffIcon=realDiff>0?'▲':'▼';
                        html+='<tr style="background:#fffbeb"><td class="tapr-ft-label" style="color:'+diffColor+'">⚡ تفاوت (کنونی − تایید شده)</td>';
                        html+='<td class="tapr-ft-amount" style="color:'+diffColor+'">'+diffIcon+' '+nfFa(Math.abs(realDiff))+' <span class="tapr-ft-unit">ت</span></td>';
                        html+='<td class="tapr-ft-status"></td></tr>';
                    }
                    html+='</tbody></table>';



                    // ── جدول جزئیات کامل تخصیص‌ها (مشابه صفحه حسابداری ادمین) ──
                    if(r.breakdown && r.breakdown.length>0){
                        html+='<div class="tapr-section-title" style="margin-top:14px">📊 جزئیات محاسبه حق‌الزحمه</div>';
                        html+='<div class="tapr-breakdown-table-wrap" id="tapr-bd-'+r.id+'">';
                        html+='<table class="tapr-bd-table">';
                        html+='<thead><tr>';
                        html+='<th>#</th><th>هنرجو</th><th>دوره</th><th>نوع دوره</th><th>قیمت محاسبه</th><th>درصد</th><th>حق‌الزحمه</th><th>شامل محاسبه</th>';
                        html+='</tr></thead><tbody>';
                        var totalBd=0;
                        $.each(r.breakdown, function(j,b){
                            var excCls=b.excluded?' tapr-bd-excluded':'';
                            html+='<tr class="tapr-bd-row'+excCls+'">';
                            html+='<td style="color:#9ca3af;font-size:11px">'+toFa(j+1)+'</td>';
                            html+='<td style="font-weight:600">'+esc(b.student_name)+'</td>';
                            html+='<td class="tapr-bd-product">'+esc(b.product_name||'—')+'</td>';
                            var chipColors={'مقدماتی':'#dbeafe:#1e40af','پیشرفته':'#d1fae5:#065f46','خلاق':'#ede9fe:#6d28d9','حرفه‌ای':'#fef3c7:#92400e','نقد اثر':'#fee2e2:#991b1b'};
                            var cLabel=courseLabel(b.course_type);
                            var cColors=(chipColors[cLabel]||'#f3f4f6:#374151').split(':');
                            html+='<td><span class="tapr-course-chip" style="background:'+cColors[0]+';color:'+cColors[1]+'">'+esc(cLabel)+'</span></td>';
                            html+='<td class="tapr-bd-mono">'+nfFa(b.calc_price)+' ت</td>';
                            html+='<td class="tapr-bd-mono">'+toFa(b.rate)+'٪</td>';
                            html+='<td class="tapr-bd-fee'+(b.excluded?' tapr-bd-fee-ex':'')+'">'+
                                (b.excluded?'<s style="color:#9ca3af">'+nfFa(b.fee)+'</s> <span class="tapr-excluded-lbl">خارج</span>':nfFa(b.fee)+' ت')+'</td>';
                            html+='<td>'+(b.excluded?'<span style="color:#9ca3af;font-size:12px">خارج</span>':'<span style="color:#10b981;font-weight:700">✔ بله</span>')+'</td>';
                            html+='</tr>';
                            if(!b.excluded) totalBd+=b.fee;
                        });
                        html+='<tr class="tapr-bd-total">';
                        html+='<td colspan="6" style="font-weight:700;color:#374151">🔢 جمع کل حق‌الزحمه این ترم</td>';
                        html+='<td class="tapr-bd-fee" style="color:#1d4ed8;font-size:14px">'+nfFa(totalBd)+' ت</td><td></td></tr>';
                        html+='</tbody></table></div>';
                    }

                    // ── یادداشت‌ها ─────────────────────────────────────────────
                    html+='<div class="tapr-notes-row">';
                    html+='<div class="tapr-note-box"><div class="tapr-note-box-label">📋 توضیح ادمین</div>';
                    html+='<div class="tapr-note-box-content">'+(r.admin_note?esc(r.admin_note):'—')+'</div></div>';
                    html+='<div class="tapr-note-box"><div class="tapr-note-box-label">👤 پاسخ من</div>';
                    html+='<div class="tapr-note-box-content">'+(r.ta_note?esc(r.ta_note):'—')+'</div></div>';
                    html+='</div>';

                    // ── ستون تغییرات: Timeline کامل پرداخت‌ها ─────────────────
                    html+='<div class="tapr-section-title" style="margin-top:14px">📜 تاریخچه تغییرات</div>';
                    html+='<div class="tapr-changes-inline" id="tapr-changes-'+r.id+'">';
                    html+='<div style="color:#9ca3af;font-size:12px;padding:8px 0">⏳ در حال بارگذاری تاریخچه…</div>';
                    html+='</div>';

                    // ── اکشن مبلغ اصلی ────────────────────────────────────────
                    if(r.status==='paid'){
                        html+='<div class="tapr-paid-banner">💳 حق‌الزحمه اصلی پرداخت شده است';
                        if(r.paid_at) html+=' — تاریخ: '+esc(r.paid_at);
                        html+='</div>';
                    } else if(r.status==='pending' || r.status==='ta_rejected'){
                        html+='<div class="tapr-actions">';
                        var prevFee=prevApprovedFee>0?prevApprovedFee:(approvedFee>0?approvedFee:0);
                        var hasPrevDiff=prevFee>0&&Math.abs(prevFee-sentFee)>1;
                        html+='<button class="ta-btn ta-btn-primary tapr-respond-btn"'+
                            ' data-req-id="'+r.id+'"'+
                            ' data-fee="'+sentFee+'"'+
                            ' data-prev-fee="'+prevFee+'"'+
                            ' data-has-diff="'+(hasPrevDiff?1:0)+'"'+
                            ' data-term="'+esc(r.term_name)+'">💬 پاسخ به درخواست</button>';
                        html+='</div>';
                    }

                    html+='</div>'; // end card
                });
                $('#tapr-list').html(html).show();

                // بارگذاری تاریخچه تغییرات برای هر کارت
                $.each(items, function(i,r){
                    loadChanges(r.id);
                });
            }

            // ── تابع بارگذاری تاریخچه تغییرات (inline) ──────────────────────
            var CHANGE_LABELS_TA={
                sent:          {icon:'📨', label:'درخواست دریافت شد',             color:'#3b82f6'},
                resent:        {icon:'🔄', label:'درخواست مجدد دریافت شد',        color:'#8b5cf6'},
                ta_approved:   {icon:'✅', label:'شما تایید کردید',               color:'#8b5cf6'},
                ta_rejected:   {icon:'↩️', label:'شما رد کردید',                  color:'#f97316'},
                ta_approved_prev:{icon:'✅',label:'تایید مبلغ قبلی (توسط شما)',   color:'#8b5cf6'},
                admin_approved:{icon:'✅', label:'تایید نهایی ادمین',              color:'#10b981'},
                admin_rejected:{icon:'❌', label:'ادمین رد کرد',                  color:'#ef4444'},
                approved:      {icon:'✅', label:'تایید نهایی',                   color:'#10b981'},
                rejected:      {icon:'❌', label:'رد شد',                         color:'#ef4444'},
                paid:          {icon:'💳', label:'پرداخت انجام شد',               color:'#1d4ed8'},
                diff_ta_approved:{icon:'✅',label:'تایید تفاوت (توسط شما)',       color:'#8b5cf6'},
                diff_ta_rejected:{icon:'↩️',label:'رد تفاوت (توسط شما)',          color:'#f97316'},
                diff_admin_approved:{icon:'✅',label:'تایید تفاوت توسط ادمین',   color:'#10b981'},
                diff_admin_rejected:{icon:'❌',label:'رد تفاوت توسط ادمین',      color:'#ef4444'},
                diff_paid:     {icon:'💳', label:'پرداخت تفاوت انجام شد',         color:'#1d4ed8'},
            };

            function loadChanges(reqId){
                $.post(ajaxUrl,{action:'ta_payroll_ta_get_logs',nonce:nonce,req_id:reqId})
                .done(function(res){
                    var $wrap=$('#tapr-changes-'+reqId);
                    if(!$wrap.length) return;
                    if(!res||!res.success||!res.data||!res.data.length){
                        $wrap.html('<div style="color:#9ca3af;font-size:12px;padding:6px 0">هنوز سابقه‌ای ثبت نشده.</div>');
                        return;
                    }
                    var logs=res.data;
                    var html='<ul class="tapr-chg-list">';
                    var prevChgFee=0;
                    $.each(logs,function(i,l){
                        var a=CHANGE_LABELS_TA[l.action]||{icon:'•',label:l.action,color:'#888'};
                        var isPay=(l.action==='paid'||l.action==='diff_paid');
                        var curChgFee=parseFloat(l.total_fee||0);
                        html+='<li class="tapr-chg-item'+(isPay?' tapr-chg-pay':'')+'">';
                        html+='<div class="tapr-chg-dot" style="background:'+a.color+'"></div>';
                        html+='<div class="tapr-chg-body">';
                        // مبلغ + تفاوت
                        if(curChgFee>0){
                            html+='<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">';
                            html+='<div class="tapr-chg-amount" style="color:'+a.color+'">'+a.icon+' '+nfFa(curChgFee)+' تومان</div>';
                            if(prevChgFee>0 && Math.abs(curChgFee-prevChgFee)>1){
                                var chgDiff=curChgFee-prevChgFee;
                                var chgClr=chgDiff>0?'#10b981':'#ef4444';
                                var chgIco=chgDiff>0?'▲':'▼';
                                var chgLbl=chgDiff>0?'افزایش':'کاهش';
                                html+='<span style="display:inline-flex;align-items:center;gap:3px;background:'+(chgDiff>0?'#d1fae5':'#fee2e2')+';color:'+chgClr+';padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700">'+chgIco+' '+nfFa(Math.abs(chgDiff))+' ت '+chgLbl+'</span>';
                            }
                            html+='</div>';
                            prevChgFee=curChgFee;
                        }
                        // تاریخ و وضعیت
                        html+='<div class="tapr-chg-meta">';
                        html+='<span class="tapr-chg-date">📅 '+esc(l.created_at)+'</span>';
                        html+='<span class="tapr-chg-status" style="background:'+a.color+'18;color:'+a.color+';border:1px solid '+a.color+'33">'+a.label+'</span>';
                        html+='</div>';
                        // یادداشت
                        if(l.note) html+='<div class="tapr-chg-note">💬 '+esc(l.note)+'</div>';
                        html+='</div></li>';
                    });
                    html+='</ul>';
                    $wrap.html(html);
                })
                .fail(function(){
                    $('#tapr-changes-'+reqId).html('<div style="color:#9ca3af;font-size:12px">خطا در بارگذاری</div>');
                });
            }

            // ── مودال پاسخ ───────────────────────────────────────────────────
            var _curReqId=0;

            var _curPrevFee=0, _curHasDiff=false;

            $(document).on('click','.tapr-respond-btn',function(){
                _curReqId   = $(this).data('req-id');
                _curPrevFee = parseFloat($(this).data('prev-fee')||0);
                _curHasDiff = $(this).data('has-diff')==1;
                var fee  = parseFloat($(this).data('fee'));
                var term = $(this).data('term');

                var infoHtml = 'ترم: <strong>'+esc(term)+'</strong>';
                infoHtml += ' &nbsp;|&nbsp; مبلغ جدید: <strong style="color:#1d4ed8">'+nfFa(fee)+'</strong> تومان';

                if(_curHasDiff && _curPrevFee>0){
                    var diff=fee-_curPrevFee;
                    infoHtml+='<br><span style="color:#f59e0b;font-size:12px">⚠️ مبلغ قبلی تایید شده: <strong>'+nfFa(_curPrevFee)+'</strong> ت';
                    infoHtml+=' &nbsp;(تفاوت: '+(diff>0?'<span style=color:#ef4444>+':'<span style=color:#059669>')+nfFa(Math.abs(diff))+'</span> ت)</span>';
                    // نمایش دکمه تایید مبلغ قبلی
                    $('#tapr-approve-prev-wrap').show();
                    $('#tapr-approve-prev-btn').text('✅ تایید مبلغ قبلی ('+nfFa(_curPrevFee)+' ت)');
                } else {
                    $('#tapr-approve-prev-wrap').hide();
                }

                $('#tapr-respond-info').html(infoHtml);
                $('#tapr-respond-note').val('');
                $('#tapr-respond-overlay').fadeIn(150);
            });

            function doRespond(action){
                var $btn;
                if(action==='approve')      $btn=$('#tapr-approve-btn');
                else if(action==='reject')  $btn=$('#tapr-reject-btn');
                else                        $btn=$('#tapr-approve-prev-btn');
                $btn.prop('disabled',true).text('…');

                var postData={
                    action:'ta_payroll_ta_respond', nonce:nonce,
                    req_id:_curReqId, ta_action:action, ta_note:$('#tapr-respond-note').val()
                };
                if(action==='approve_prev') postData.prev_fee=_curPrevFee;

                $.post(ajaxUrl, postData, function(res){
                    var origLabel = action==='approve'?'✅ تایید مبلغ جدید':
                                   action==='reject' ?'❌ رد می‌کنم':
                                                      '✅ تایید مبلغ قبلی ('+nfFa(_curPrevFee)+' ت)';
                    $btn.prop('disabled',false).text(origLabel);
                    if(res.success){
                        $('#tapr-respond-overlay').fadeOut(150);
                        $('#tapr-loading').show(); $('#tapr-list').hide();
                        loadPayroll();
                    } else alert(res.data||'خطا');
                });
            }

            $('#tapr-approve-btn').on('click',function(){ doRespond('approve'); });
            $('#tapr-reject-btn').on('click', function(){ doRespond('reject');  });
            $('#tapr-approve-prev-btn').on('click',function(){ doRespond('approve_prev'); });
            $('#tapr-respond-close,#tapr-respond-cancel').on('click',function(){ $('#tapr-respond-overlay').fadeOut(150); });
            $('#tapr-respond-overlay').on('click',function(e){ if($(e.target).is(this)) $(this).fadeOut(150); });

            // ── تاریخچه (modal همچنان برای compatibility) ───────────────────────────────────────
            var TA_ACTION_LABELS={
                ta_approved_prev:{icon:'✅', label:'تایید مبلغ قبلی (توسط شما)',  cls:'tapr-dot-ta_approved'},
                sent:          {icon:'📨', label:'درخواست دریافت شد',            cls:'tapr-dot-sent'},
                resent:        {icon:'🔄', label:'درخواست مجدد دریافت شد',       cls:'tapr-dot-resent'},
                ta_approved:   {icon:'✅', label:'شما تایید کردید',               cls:'tapr-dot-ta_approved'},
                ta_rejected:   {icon:'↩️', label:'شما رد کردید',                  cls:'tapr-dot-ta_rejected'},
                admin_approved:{icon:'✅', label:'ادمین تایید نهایی کرد',          cls:'tapr-dot-approved'},
                admin_rejected:{icon:'❌', label:'ادمین رد کرد',                  cls:'tapr-dot-rejected'},
                approved:      {icon:'✅', label:'تایید نهایی ادمین',              cls:'tapr-dot-approved'},
                rejected:      {icon:'❌', label:'رد شد',                         cls:'tapr-dot-rejected'},
                paid:          {icon:'💳', label:'پرداخت انجام شد',               cls:'tapr-dot-paid'},
            };

            $(document).on('click','.tapr-history-btn',function(){
                var reqId=$(this).data('req-id');
                $('#tapr-history-body').html('<div style="padding:20px;text-align:center;color:#9ca3af">در حال بارگذاری…</div>');
                $('#tapr-history-overlay').fadeIn(150);
                $.post(ajaxUrl,{action:'ta_payroll_ta_get_logs',nonce:nonce,req_id:reqId})
                .done(function(res){
                    if(!res||!res.success){
                        $('#tapr-history-body').html('<div class="tapr-tl-empty" style="color:#ef4444">⚠️ خطا در بارگذاری'+(res&&res.data?' ('+res.data+')':'')+'</div>');
                        return;
                    }
                    var logs=res.data;
                    if(!logs||!logs.length){$('#tapr-history-body').html('<div class="tapr-tl-empty">هیچ سابقه‌ای ثبت نشده.</div>');return;}
                    var html='<ul class="tapr-timeline">';
                    $.each(logs,function(i,l){
                        var a=TA_ACTION_LABELS[l.action]||{icon:'•',label:l.action,cls:''};
                        html+='<li class="tapr-timeline-item">';
                        html+='<span class="tapr-timeline-dot '+a.cls+'"></span>';
                        html+='<div class="tapr-tl-meta">'+esc(l.created_at)+'</div>';
                        html+='<div><span class="tapr-tl-action">'+a.icon+' '+a.label+'</span>';
                        if(parseFloat(l.total_fee)>0) html+=' &nbsp;<span class="tapr-tl-fee">'+nfFa(l.total_fee)+' ت</span>';
                        html+='</div>';
                        if(l.note) html+='<div class="tapr-tl-note">'+esc(l.note)+'</div>';
                        html+='</li>';
                    });
                    html+='</ul>';
                    $('#tapr-history-body').html(html);
                })
                .fail(function(xhr,status,err){
                    $('#tapr-history-body').html('<div class="tapr-tl-empty" style="color:#ef4444">⚠️ خطای شبکه</div>');
                });
            });
            $('#tapr-history-close,#tapr-history-cancel').on('click',function(){$('#tapr-history-overlay').fadeOut(150);});
            $('#tapr-history-overlay').on('click',function(e){if($(e.target).is(this))$(this).fadeOut(150);});

            function nf(n){ return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,','); }
            function esc(s){ return $('<div>').text(s||'').html(); }

            function toFa(n){ return Math.round(n).toString(); }
            function nfFa(n){ return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,','); }

            // نگاشت نوع دوره انگلیسی به فارسی
            var COURSE_LABEL_MAP={
                'intro':'مقدماتی','advanced':'پیشرفته','creative':'خلاق',
                'professional':'حرفه‌ای','critique':'نقد اثر'
            };
            function courseLabel(ct){ return COURSE_LABEL_MAP[ct]||ct||'—'; }

            loadPayroll();

        })(jQuery);
        </script>
        <?php
    }

    // ── نقد آثار (سابقاً «نقد داستان») ─────────────────────────────────────
    private function tab_story_critique( object $ta ): void {
        $nonce    = wp_create_nonce( 'ta_dashboard_nonce' );
        $enabled  = TA_Settings_Helper::is_enabled( 'story_critique_enabled' );
        $bale_link = 'ble.ir/join/CqUvdvAhhB';

        $spec_table  = TA_Database::table( 'specialties' );
        $specialties = TA_Database::get_results( "SELECT * FROM {$spec_table} WHERE is_active = 1 ORDER BY sort_order ASC, id ASC" );
        ?>
        <div class="ta-section ta-sc-wrap" dir="rtl">

            <div class="ta-sc-header">
                <h3>✍️ نقد آثار</h3>
                <p class="ta-sc-subtitle">اگر در حوزه نقد آثار فعالیت می‌کنید، فرم زیر را تکمیل کنید تا به گروه ما بپیوندید.</p>
            </div>

            <?php if ( ! $enabled ) : ?>
            <div class="ta-sc-disabled-notice">
                🔒 درخواست نقد آثار در حال حاضر غیرفعال است.
            </div>
            <?php else : ?>

            <!-- فرم -->
            <!-- لودر اولیه -->
            <div id="ta-sc-init-loading" style="display:flex;align-items:center;gap:10px;padding:32px 0;color:#6b7280;font-size:14px">
                <div class="ta-spinner"></div> در حال بارگذاری…
            </div>

            <div id="ta-sc-form-wrap" style="display:none">
                <div class="ta-sc-form-card">

                    <!-- ردیف ۱: اطلاعات شخصی -->
                    <div class="ta-sc-grid-3">
                        <div class="ta-sc-field">
                            <label>نام و نام‌خانوادگی <span class="ta-sc-req">*</span></label>
                            <input type="text" id="ta-sc-full-name" placeholder="مثلاً: علی محمدی" maxlength="100">
                        </div>
                        <div class="ta-sc-field">
                            <label>شماره تماس <span class="ta-sc-req">*</span></label>
                            <input type="tel" id="ta-sc-phone" placeholder="09123456789" maxlength="15" dir="ltr">
                        </div>
                    </div>

                    <!-- تخصص‌ها -->
                    <div class="ta-sc-field ta-sc-field-full">
                        <label>در چه حوزه‌ای تخصص نقد دارید؟ <span class="ta-sc-req">*</span> <span class="ta-sc-hint">(چند گزینه قابل انتخاب)</span></label>
                        <div class="ta-sc-chips-grid" id="ta-sc-specialties">
                            <?php
                            $colors = ['#dbeafe:#1e40af','#d1fae5:#065f46','#ede9fe:#6d28d9','#fef3c7:#92400e','#fee2e2:#991b1b','#e0f2fe:#0369a1','#fdf4ff:#7e22ce','#fff7ed:#c2410c','#f0fdf4:#166534','#fce7f3:#9d174d'];
                            foreach($specialties as $i => $s):
                                $c   = explode(':', $colors[$i % count($colors)]);
                                $img = $s->image_id ? wp_get_attachment_image_url( $s->image_id, 'thumbnail' ) : '';
                            ?>
                            <label class="ta-sc-chip-label" style="--chip-bg:<?= $c[0] ?>;--chip-fg:<?= $c[1] ?>" <?php if ( $s->description ) : ?>title="<?= esc_attr( $s->description ) ?>"<?php endif; ?>>
                                <input type="checkbox" class="ta-sc-spec" value="<?= esc_attr($s->name) ?>">
                                <?php if ( $img ) : ?><img src="<?= esc_url($img) ?>" alt="" style="width:18px;height:18px;border-radius:50%;object-fit:cover;margin-left:4px;vertical-align:middle"><?php endif; ?>
                                <span><?= esc_html($s->name) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- سایر موارد -->
                    <div class="ta-sc-field ta-sc-field-full">
                        <label>اگر موردی هست که اینجا ذکر نشده، بنویسید <span class="ta-sc-hint">(اختیاری)</span></label>
                        <textarea id="ta-sc-other" rows="2" placeholder="توضیح دهید…"></textarea>
                    </div>

                    <!-- ساعات -->
                    <div class="ta-sc-field ta-sc-field-full">
                        <label>چند ساعت در روز می‌توانید وقت بگذارید؟ (چند متن در هفته نقد می‌کنید؟) <span class="ta-sc-req">*</span></label>
                        <input type="text" id="ta-sc-hours" placeholder="مثلاً: ۲ ساعت در روز / ۳ تا ۵ متن در هفته">
                    </div>

                    <!-- رزومه -->
                    <div class="ta-sc-field ta-sc-field-full">
                        <label>رزومه <span class="ta-sc-hint">(اختیاری — می‌توانید چند پاراگراف درباره سوابق خود بنویسید)</span></label>
                        <textarea id="ta-sc-resume" rows="5" placeholder="سوابق تحصیلی، تجربه نقد، آثار منتشرشده و..."></textarea>
                    </div>

                    <!-- ارسال -->
                    <div class="ta-sc-actions">
                        <button id="ta-sc-submit" class="ta-btn ta-btn-primary ta-sc-submit-btn">
                            📨 ارسال درخواست نقد
                        </button>
                        <div id="ta-sc-msg" class="ta-sc-msg" style="display:none"></div>
                    </div>

                </div>
            </div>

            <!-- بعد از ارسال -->
            <div id="ta-sc-success-wrap" style="display:none">
                <div class="ta-sc-success-card">
                    <div class="ta-sc-success-icon">✅</div>
                    <h4>فرم با موفقیت ثبت شد!</h4>
                    <p>برای ارتباط بیشتر و عضویت در گروه روی دکمه زیر کلیک کنید:</p>
                    <a href="https://<?= esc_attr($bale_link) ?>" target="_blank" class="ta-btn ta-sc-join-btn">
                        💬 عضویت در گروه نقد آثار
                    </a>
                    <p class="ta-sc-link-raw"><?= esc_html($bale_link) ?></p>
                    <button id="ta-sc-edit-btn" class="ta-btn ta-sc-edit-btn">✏️ ویرایش اطلاعات</button>
                </div>

                <!-- ظرفیت و قیمت‌گذاری -->
                <div class="ta-sc-form-card" style="margin-top:16px">
                    <h4 style="margin:0 0 4px;font-size:15px;font-weight:800;color:var(--ta-adm-gray-900)">ظرفیت و قیمت‌گذاری</h4>
                    <p style="margin:0 0 16px;font-size:12px;color:var(--ta-adm-gray-400)">
                        قیمت هر سفارش = قیمت پایه، به‌ازای هر <strong>پلهٔ کلمه</strong> اضافه بر «تعداد کلمهٔ پایه»، به‌اندازهٔ «درصد افزایش هر پله» بیشتر می‌شود.
                    </p>
                    <div class="ta-sc-pricing-grid">
                        <div class="ta-sc-pricing-field">
                            <label>ظرفیت <span class="ta-sc-hint">(تعداد اثر)</span></label>
                            <div class="ta-sc-pricing-row">
                                <input type="number" min="0" id="ta-sc-capacity">
                                <div class="ta-sc-pricing-row-btns">
                                    <button type="button" class="ta-sc-save-icon-btn" id="ta-sc-capacity-save">💾 ذخیره</button>
                                    <button type="button" class="ta-sc-hist-icon-btn" onclick="taScShowHistory('capacity')">🕘 تاریخچه</button>
                                </div>
                            </div>
                        </div>
                        <div class="ta-sc-pricing-field">
                            <label>قیمت پایه <span class="ta-sc-hint">(تومان)</span></label>
                            <div class="ta-sc-pricing-row">
                                <input type="number" min="0" step="0.01" id="ta-sc-price">
                                <div class="ta-sc-pricing-row-btns">
                                    <button type="button" class="ta-sc-save-icon-btn" id="ta-sc-price-save">💾 ذخیره</button>
                                    <button type="button" class="ta-sc-hist-icon-btn" onclick="taScShowHistory('base_price')">🕘 تاریخچه</button>
                                </div>
                            </div>
                        </div>
                        <div class="ta-sc-pricing-field">
                            <label>تعداد کلمهٔ پایه <span class="ta-sc-hint">(کلمه)</span></label>
                            <div class="ta-sc-pricing-row">
                                <input type="number" min="0" id="ta-sc-base-word-count">
                                <div class="ta-sc-pricing-row-btns">
                                    <button type="button" class="ta-sc-save-icon-btn" id="ta-sc-base-word-count-save">💾 ذخیره</button>
                                    <button type="button" class="ta-sc-hist-icon-btn" onclick="taScShowHistory('base_word_count')">🕘 تاریخچه</button>
                                </div>
                            </div>
                        </div>
                        <div class="ta-sc-pricing-field">
                            <label>پلهٔ افزایش <span class="ta-sc-hint">(کلمه)</span></label>
                            <div class="ta-sc-pricing-row">
                                <input type="number" min="0" id="ta-sc-increment-words">
                                <div class="ta-sc-pricing-row-btns">
                                    <button type="button" class="ta-sc-save-icon-btn" id="ta-sc-increment-words-save">💾 ذخیره</button>
                                    <button type="button" class="ta-sc-hist-icon-btn" onclick="taScShowHistory('increment_words')">🕘 تاریخچه</button>
                                </div>
                            </div>
                        </div>
                        <div class="ta-sc-pricing-field">
                            <label>درصد افزایش هر پله <span class="ta-sc-hint">(٪)</span></label>
                            <div class="ta-sc-pricing-row">
                                <input type="number" min="0" step="0.01" id="ta-sc-increment-percent">
                                <div class="ta-sc-pricing-row-btns">
                                    <button type="button" class="ta-sc-save-icon-btn" id="ta-sc-increment-percent-save">💾 ذخیره</button>
                                    <button type="button" class="ta-sc-hist-icon-btn" onclick="taScShowHistory('increment_percent')">🕘 تاریخچه</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="ta-sc-field-msg" class="ta-sc-msg" style="display:none"></div>

                    <!-- قیمت‌گذاری مستقل هر تخصص -->
                    <div style="margin-top:16px;border-top:1px dashed var(--ta-adm-gray-200);padding-top:14px">
                        <button type="button" class="ta-btn ta-btn-outline ta-btn-sm" id="ta-sc-spec-price-btn">💰 قیمت تخصص‌ها</button>
                        <p style="margin:6px 0 0;font-size:11.5px;color:var(--ta-adm-gray-400)">برای هر تخصص می‌توانید قیمت جداگانه تعیین کنید؛ اگر برای تخصصی چیزی ثبت نکنید، همین قیمت‌گذاری بالا برایش استفاده می‌شود.</p>
                    </div>
                </div>

                <!-- مودال قیمت‌گذاری تخصص‌ها -->
                <div id="ta-sc-spec-price-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center">
                    <div style="background:#fff;border-radius:10px;padding:20px;width:480px;max-width:94vw;max-height:80vh;overflow-y:auto">
                        <h4 style="margin-top:0">💰 قیمت‌گذاری تخصص‌ها</h4>
                        <p style="font-size:11.5px;color:var(--ta-adm-gray-400);margin-top:-6px">هر فیلد اختیاری است — خالی‌گذاشتن یعنی از قیمت پایهٔ بالا استفاده شود.</p>
                        <div id="ta-sc-spec-price-body">در حال بارگذاری…</div>
                        <p style="margin-top:14px">
                            <button type="button" class="ta-btn ta-btn-primary ta-btn-sm" id="ta-sc-spec-price-save">💾 ذخیرهٔ همه</button>
                            <button type="button" class="ta-btn ta-sc-edit-btn" onclick="document.getElementById('ta-sc-spec-price-overlay').style.display='none'">بستن</button>
                            <span id="ta-sc-spec-price-status" style="font-size:12px"></span>
                        </p>
                    </div>
                </div>

                <!-- مودال تاریخچه -->
                <div id="ta-sc-history-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center">
                    <div style="background:#fff;border-radius:10px;padding:20px;width:380px;max-width:92vw;max-height:75vh;overflow-y:auto">
                        <h4 id="ta-sc-history-title" style="margin-top:0">تاریخچه</h4>
                        <div id="ta-sc-history-body" style="font-size:13px"></div>
                        <button type="button" class="ta-btn ta-sc-edit-btn" onclick="document.getElementById('ta-sc-history-overlay').style.display='none'">بستن</button>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </div>

        <style>
        .ta-sc-wrap { padding: 0; }
        .ta-sc-header { margin-bottom: 20px; }
        .ta-sc-header h3 { margin: 0 0 4px; font-size: 18px; font-weight: 800; color: var(--ta-adm-gray-900); }
        .ta-sc-subtitle { margin: 0; font-size: 13px; color: var(--ta-adm-gray-500); }
        .ta-sc-disabled-notice { padding: 20px; background: var(--ta-adm-gray-50); border: 1.5px solid var(--ta-adm-gray-200); border-radius: 12px; color: var(--ta-adm-gray-500); font-size: 14px; text-align: center; }
        .ta-sc-form-card { background: #fff; border: 1.5px solid var(--ta-adm-gray-200); border-radius: 14px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
        .ta-sc-grid-3 { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin-bottom: 18px; }
        .ta-sc-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
        .ta-sc-field-full { margin-bottom: 16px; }
        .ta-sc-field label, .ta-sc-field-full label { font-size: 13px; font-weight: 700; color: var(--ta-adm-gray-700); }
        .ta-sc-req { color: #ef4444; }
        .ta-sc-hint { font-weight: 400; color: var(--ta-adm-gray-400); font-size: 11px; }
        .ta-sc-field input, .ta-sc-field textarea, .ta-sc-field-full input, .ta-sc-field-full textarea {
            padding: 9px 12px; border: 1.5px solid var(--ta-adm-gray-200); border-radius: 8px; font-size: 13px; font-family: inherit; outline: none; transition: border-color .15s;
        }
        .ta-sc-field input:focus, .ta-sc-field-full input:focus, .ta-sc-field-full textarea:focus { border-color: var(--ta-primary); }
        .ta-sc-chips-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
        .ta-sc-chip-label { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 99px; cursor: pointer; font-size: 13px; font-weight: 600; border: 2px solid transparent; transition: all .15s; background: var(--ta-adm-gray-100); color: var(--ta-adm-gray-700); user-select: none; }
        .ta-sc-chip-label input { display: none; }
        .ta-sc-chip-label.active { background: var(--chip-bg); color: var(--chip-fg); border-color: var(--chip-fg); }
        .ta-sc-actions { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-top: 4px; }
        .ta-sc-submit-btn { font-size: 14px; padding: 10px 24px; border-radius: 10px; font-weight: 700; }
        .ta-sc-msg { font-size: 13px; padding: 8px 14px; border-radius: 8px; }
        .ta-sc-msg.error { background: #fee2e2; color: #991b1b; }
        .ta-sc-msg.info { background: #eff6ff; color: #1e40af; }
        .ta-sc-success-card { background: #f0fdf4; border: 2px solid #86efac; border-radius: 14px; padding: 32px 24px; text-align: center; }
        .ta-sc-success-icon { font-size: 3rem; margin-bottom: 10px; }
        .ta-sc-success-card h4 { margin: 0 0 8px; font-size: 18px; font-weight: 800; color: #166534; }
        .ta-sc-success-card p { color: var(--ta-adm-gray-700); font-size: 14px; margin: 0 0 14px; }
        .ta-sc-join-btn { background: #0369a1; color: #fff; padding: 10px 24px; border-radius: 10px; font-size: 14px; font-weight: 700; text-decoration: none; display: inline-block; }
        .ta-sc-link-raw { font-size: 12px; color: var(--ta-adm-gray-400); margin-top: 10px !important; direction: ltr; }
        .ta-sc-edit-btn { background: var(--ta-adm-gray-100); color: var(--ta-adm-gray-700); padding: 8px 18px; border-radius: 8px; font-size: 12px; margin-top: 12px; border: 1.5px solid var(--ta-adm-gray-200); cursor: pointer; }

        /* ظرفیت و قیمت‌گذاری */
        .ta-sc-pricing-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 14px; }
        .ta-sc-pricing-field { background: var(--ta-adm-gray-50); border: 1.5px solid var(--ta-adm-gray-200); border-radius: 10px; padding: 12px 14px; display: flex; flex-direction: column; gap: 8px; }
        .ta-sc-pricing-field label { font-size: 12.5px; font-weight: 700; color: var(--ta-adm-gray-700); }
        .ta-sc-pricing-row { display: flex; flex-direction: column; align-items: stretch; gap: 8px; }
        .ta-sc-pricing-row input { width: 100%; padding: 8px 10px; border: 1.5px solid var(--ta-adm-gray-200); border-radius: 8px; font-size: 13px; font-family: inherit; outline: none; transition: border-color .15s; background: #fff; box-sizing: border-box; }
        .ta-sc-pricing-row input:focus { border-color: var(--ta-primary); }
        .ta-sc-pricing-row-btns { display: flex; gap: 6px; }
        .ta-sc-pricing-row-btns > button { flex: 1; }
        .ta-sc-save-icon-btn, .ta-sc-hist-icon-btn { flex-shrink: 0; padding: 5px 8px; display: inline-flex; align-items: center; justify-content: center; gap: 3px; border-radius: 6px; border: 1.5px solid var(--ta-adm-gray-200); background: #fff; cursor: pointer; font-size: 11px; line-height: 1.5; font-weight: 700; white-space: nowrap; font-family: inherit; transition: background .15s, border-color .15s, color .15s; }
        .ta-sc-save-icon-btn { color: #fff; background: var(--ta-primary); border-color: var(--ta-primary); }
        .ta-sc-save-icon-btn:hover { opacity: .85; }
        .ta-sc-hist-icon-btn { color: var(--ta-adm-gray-700); }
        .ta-sc-hist-icon-btn:hover { background: var(--ta-adm-gray-100); }
        </style>

        <script>
        (function($){
            var ajaxUrl = '<?= admin_url('admin-ajax.php') ?>';
            var nonce   = '<?= $nonce ?>';

            // بارگذاری داده‌های موجود
            $.post(ajaxUrl, {action:'ta_story_critique_get', nonce:nonce}, function(res){
                $('#ta-sc-init-loading').hide();
                if(res.success && res.data){
                    prefillForm(res.data);
                    if(res.data.is_saved){
                        // فرم قبلاً ارسال شده — فقط حالت موفقیت نمایش داده می‌شود
                        showSuccess();
                    } else {
                        // فرم جدید — نام/تلفن پیش‌پر شده، منتظر ارسال
                        $('#ta-sc-form-wrap').show();
                    }
                } else {
                    // هیچ داده‌ای نیست — فرم خالی
                    $('#ta-sc-form-wrap').show();
                }
            }).fail(function(){
                $('#ta-sc-init-loading').hide();
                $('#ta-sc-form-wrap').show();
            });

            var ownSpecialties = [];

            function prefillForm(d){
                $('#ta-sc-full-name').val(d.full_name||'');
                $('#ta-sc-phone').val(d.phone||'');
                $('#ta-sc-other').val(d.other_specialty||'');
                $('#ta-sc-hours').val(d.hours_per_day||'');
                $('#ta-sc-resume').val(d.resume||'');
                $('#ta-sc-capacity').val(d.capacity===null||d.capacity===undefined?'':d.capacity);
                $('#ta-sc-price').val(d.base_price===null||d.base_price===undefined?'':d.base_price);
                $('#ta-sc-base-word-count').val(d.base_word_count===null||d.base_word_count===undefined?'':d.base_word_count);
                $('#ta-sc-increment-words').val(d.increment_words===null||d.increment_words===undefined?'':d.increment_words);
                $('#ta-sc-increment-percent').val(d.increment_percent===null||d.increment_percent===undefined?'':d.increment_percent);
                if(Array.isArray(d.specialties)){
                    ownSpecialties = d.specialties;
                    d.specialties.forEach(function(s){
                        $('.ta-sc-spec').filter(function(){ return $(this).val()===s; }).closest('.ta-sc-chip-label').addClass('active').find('input').prop('checked',true);
                    });
                }
            }

            function showSuccess(){
                $('#ta-sc-form-wrap').hide();
                $('#ta-sc-success-wrap').show();
            }
            function showForm(){
                $('#ta-sc-success-wrap').hide();
                $('#ta-sc-form-wrap').show();
            }

            // chip toggle
            $(document).on('click','.ta-sc-chip-label',function(){
                var $l=$(this);
                var $cb=$l.find('input');
                $cb.prop('checked',!$cb.prop('checked'));
                $l.toggleClass('active',$cb.prop('checked'));
            });

            // ارسال فرم
            $('#ta-sc-submit').on('click',function(){
                var fullName=$('#ta-sc-full-name').val().trim();
                var phone   =$('#ta-sc-phone').val().trim();
                var other   =$('#ta-sc-other').val().trim();
                var hours   =$('#ta-sc-hours').val().trim();
                var resume  =$('#ta-sc-resume').val().trim();
                var specs   =[];
                $('.ta-sc-spec:checked').each(function(){ specs.push($(this).val()); });

                if(!fullName||!phone||!hours){
                    showMsg('لطفاً فیلدهای ضروری را پر کنید.','error'); return;
                }

                var $btn=$(this).prop('disabled',true).text('در حال ارسال…');
                var data={action:'ta_story_critique_submit',nonce:nonce,full_name:fullName,phone:phone,other_specialty:other,hours_per_day:hours,resume:resume};
                specs.forEach(function(s,i){ data['specialties['+i+']']=s; });

                $.post(ajaxUrl,data,function(res){
                    $btn.prop('disabled',false).text('📨 ارسال درخواست نقد');
                    if(res.success){
                        showSuccess();
                    } else {
                        showMsg(res.data||'خطا در ارسال','error');
                    }
                });
            });

            $('#ta-sc-edit-btn').on('click', showForm);

            function showMsg(msg,type){
                var $m=$('#ta-sc-msg').text(msg).removeClass('error info').addClass(type).show();
                setTimeout(function(){ $m.fadeOut(); },4000);
            }

            // ── ظرفیت / قیمت پایه ─────────────────────────────────────────
            function saveScField(field, val){
                var $msg = $('#ta-sc-field-msg').text('⏳ در حال ذخیره…').removeClass('error info').addClass('info').show();
                $.post(ajaxUrl, {action:'ta_sc_ta_field_update', nonce:nonce, field:field, value:val}, function(res){
                    if(res.success){
                        $msg.text('✅ ذخیره شد').removeClass('error').addClass('info');
                    } else {
                        $msg.text('❌ ' + (res.data && res.data.message ? res.data.message : 'خطا در ذخیره')).removeClass('info').addClass('error');
                    }
                    setTimeout(function(){ $msg.fadeOut(); },3000);
                });
            }
            $('#ta-sc-capacity-save').on('click', function(){ saveScField('capacity', $('#ta-sc-capacity').val().trim()); });
            $('#ta-sc-price-save').on('click', function(){ saveScField('base_price', $('#ta-sc-price').val().trim()); });
            $('#ta-sc-base-word-count-save').on('click', function(){ saveScField('base_word_count', $('#ta-sc-base-word-count').val().trim()); });
            $('#ta-sc-increment-words-save').on('click', function(){ saveScField('increment_words', $('#ta-sc-increment-words').val().trim()); });
            $('#ta-sc-increment-percent-save').on('click', function(){ saveScField('increment_percent', $('#ta-sc-increment-percent').val().trim()); });

            window.taScShowHistory = function(field){
                var titles = {
                    capacity:           'تاریخچه ظرفیت',
                    base_price:         'تاریخچه قیمت پایه',
                    base_word_count:    'تاریخچه تعداد کلمه پایه',
                    increment_words:    'تاریخچه پله افزایش (کلمه)',
                    increment_percent:  'تاریخچه درصد افزایش هر پله'
                };
                var title = titles[field] || 'تاریخچه';
                $('#ta-sc-history-title').text(title);
                var $body = $('#ta-sc-history-body').html('در حال بارگذاری…');
                $('#ta-sc-history-overlay').css('display','flex');
                $.post(ajaxUrl, {action:'ta_sc_ta_field_history', nonce:nonce, field:field}, function(res){
                    if(!res.success || !res.data.length){ $body.html('<p style="color:#888">تغییری ثبت نشده است.</p>'); return; }
                    var html = '<table style="width:100%;font-size:12px;border-collapse:collapse">';
                    res.data.forEach(function(l){
                        html += '<tr><td style="padding:4px;border-bottom:1px solid #eee">'+(l.old_value ?? '—')+' ← '+(l.new_value ?? '—')+'</td>'
                              + '<td style="padding:4px;border-bottom:1px solid #eee;color:#888">'+(l.actor_type==='admin'?'ادمین':'خودتان')+'</td>'
                              + '<td style="padding:4px;border-bottom:1px solid #eee;color:#aaa;white-space:nowrap">'+l.created_at+'</td></tr>';
                    });
                    html += '</table>';
                    $body.html(html);
                });
            };

            // ── قیمت‌گذاری تخصص‌ها ──────────────────────────────────────
            $('#ta-sc-spec-price-btn').on('click', function(){
                if(!ownSpecialties.length){
                    $('#ta-sc-spec-price-body').html('<p style="color:#888">ابتدا حداقل یک تخصص در فرم بالا انتخاب و ذخیره کنید.</p>');
                    $('#ta-sc-spec-price-overlay').css('display','flex');
                    return;
                }
                $('#ta-sc-spec-price-body').html('در حال بارگذاری…');
                $('#ta-sc-spec-price-overlay').css('display','flex');
                $.post(ajaxUrl, {action:'ta_sc_ta_specialty_pricing_get', nonce:nonce}, function(res){
                    var existing = (res.success && res.data) ? res.data : {};
                    var html = '';
                    ownSpecialties.forEach(function(s){
                        var d = existing[s] || {};
                        html += '<div class="ta-sc-spec-price-row" data-spec="'+ $('<div>').text(s).html() +'" style="border:1.5px solid var(--ta-adm-gray-200);border-radius:8px;padding:10px 12px;margin-bottom:10px">'
                              + '<strong style="display:block;margin-bottom:6px;font-size:13px">'+ $('<div>').text(s).html() +'</strong>'
                              + '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px">'
                              + '<label style="font-size:11px">قیمت پایه<br><input type="number" step="0.01" class="ta-sc-sp-base-price" style="width:100%" value="'+(d.base_price??'')+'"></label>'
                              + '<label style="font-size:11px">کلمهٔ پایه<br><input type="number" class="ta-sc-sp-base-words" style="width:100%" value="'+(d.base_word_count??'')+'"></label>'
                              + '<label style="font-size:11px">پلهٔ افزایش<br><input type="number" class="ta-sc-sp-inc-words" style="width:100%" value="'+(d.increment_words??'')+'"></label>'
                              + '<label style="font-size:11px">درصد افزایش<br><input type="number" step="0.01" class="ta-sc-sp-inc-percent" style="width:100%" value="'+(d.increment_percent??'')+'"></label>'
                              + '</div></div>';
                    });
                    $('#ta-sc-spec-price-body').html(html);
                });
            });

            $('#ta-sc-spec-price-save').on('click', function(){
                var $status = $('#ta-sc-spec-price-status').text('⏳ در حال ذخیره…');
                var items = [];
                $('.ta-sc-spec-price-row').each(function(){
                    var $r = $(this);
                    items.push({
                        specialty: $r.data('spec'),
                        base_price: $r.find('.ta-sc-sp-base-price').val(),
                        base_word_count: $r.find('.ta-sc-sp-base-words').val(),
                        increment_words: $r.find('.ta-sc-sp-inc-words').val(),
                        increment_percent: $r.find('.ta-sc-sp-inc-percent').val()
                    });
                });
                $.post(ajaxUrl, {action:'ta_sc_ta_specialty_pricing_save', nonce:nonce, items: JSON.stringify(items)}, function(res){
                    $status.text(res.success ? '✅ ذخیره شد' : ('❌ ' + (res.data && res.data.message ? res.data.message : 'خطا')));
                    setTimeout(function(){ $status.text(''); }, 3000);
                });
            });

        })(jQuery);
        </script>
        <?php
    }

    // ── سفارش‌های نقد آثار (فهرست سفارش‌های محول‌شده به این استادیار) ─────────
    private function tab_critique_orders( object $ta ): void {
        $nonce = wp_create_nonce( 'ta_dashboard_nonce' );
        $table    = TA_Database::table( 'critique_orders' );
        $sc_table = TA_Database::table( 'story_critique_forms' );

        // [BUGFIX] ستون critique_orders.ta_id در واقع مقدار story_critique_forms.id را ذخیره
        // می‌کند، نه ta_assistants.id (که $ta->id همان است). قبلاً این تابع مستقیم $ta->id را
        // به‌جای شناسه فرم استفاده می‌کرد؛ در نتیجه هم لینک گروه بله («هنوز ثبت نشده» غلط)
        // و هم لیست سفارش‌ها می‌توانستند نادرست/خالی نمایش داده شوند.
        $own_form   = TA_Database::get_row( "SELECT id, group_link FROM {$sc_table} WHERE ta_id = %d", [ $ta->id ] );
        $own_form_id = $own_form->id ?? 0;
        $own_link    = $own_form->group_link ?? '';

        $orders = TA_Database::get_results(
            "SELECT co.*, u.display_name AS student_name
             FROM {$table} co
             LEFT JOIN {$GLOBALS['wpdb']->users} u ON u.ID = co.student_id
             WHERE co.ta_id = %d ORDER BY co.created_at DESC",
            [ $own_form_id ]
        );

        // [FEATURE-STUDENT-EVAL] سؤال‌های «ارزیابی هنرجو» که استاد در لحظهٔ «تکمیل نقد» پر می‌کند
        $seq_table = TA_Database::table( 'student_eval_questions' );
        $student_eval_questions = TA_Database::get_results( "SELECT * FROM {$seq_table} WHERE is_active = 1 ORDER BY sort_order ASC, id ASC" );

        $status_labels = [
            'pending'      => [ '⏳ در انتظار شروع', '#f59e0b' ],
            'in_progress'  => [ '✍️ در حال انجام',   '#2563eb' ],
            'delivered'    => [ '📬 تحویل شده',      '#7c3aed' ],
            'ta_completed' => [ '🧾 تکمیل شد — منتظر تأیید هنرجو', '#0891b2' ],
            'completed'    => [ '✅ تکمیل‌شده',       '#16a34a' ],
            'cancelled'    => [ '❌ لغو شده',         '#dc2626' ],
        ];
        ?>
        <div class="ta-section" dir="rtl">
            <h3>📋 سفارش‌های نقد آثار</h3>

            <div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12.5px;color:#4c1d95">
                💬 گروه بله شما:
                <?php if ( $own_link ) : ?>
                    <a href="<?= esc_url( TA_URL_Helper::normalize( $own_link ) ) ?>" target="_blank"><?= esc_html( $own_link ) ?></a>
                    — این لینک به‌صورت خودکار وقتی سفارشی را «در حال انجام» می‌کنید به همان هنرجو نمایش داده می‌شود و بعد از «تکمیل نقد» از دید او حذف می‌شود.
                <?php else : ?>
                    هنوز ثبت نشده. برای ثبت یا تغییر لینک گروه بله با ادمین هماهنگ کنید.
                <?php endif; ?>
            </div>

            <?php if ( empty( $orders ) ) : ?>
                <p style="color:#888">فعلاً سفارشی برای شما ثبت نشده است.</p>
            <?php else : ?>
            <div class="ta-co-ta-orders">
                <?php foreach ( $orders as $o ) :
                    [ $label, $color ] = $status_labels[ $o->status ] ?? [ $o->status, '#888' ];
                ?>
                <div class="ta-co-ta-order-card" data-id="<?= (int) $o->id ?>" data-status="<?= esc_attr( $o->status ) ?>">
                    <div class="ta-co-ta-order-head">
                        <strong><?= esc_html( $o->student_name ?: 'هنرجو #' . $o->student_id ) ?></strong>
                        <span style="background:<?= $color ?>22;color:<?= $color ?>;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:700"><?= esc_html( $label ) ?></span>
                    </div>
                    <div style="font-size:12px;color:#6b7280;margin:4px 0">
                        تخصص: <?= esc_html( $o->specialty ?: '—' ) ?> | تعداد کلمات: <?= esc_html( $o->word_count ?: '—' ) ?> | تاریخ: <?= esc_html( $o->created_at ) ?>
                    </div>

                    <div style="margin:8px 0">
                        <?php if ( $o->file_id ) : ?>
                        <a href="<?= esc_url( wp_get_attachment_url( $o->file_id ) ) ?>" target="_blank" class="ta-btn ta-btn-sm ta-btn-outline">📥 دانلود فایل اثر</a>
                        <?php endif; ?>
                    </div>

                    <?php if ( $o->status === 'pending' ) : ?>
                        <button type="button" class="ta-btn ta-btn-primary ta-btn-sm ta-co-start-btn">شروع کار</button>
                    <?php endif; ?>

                    <?php if ( in_array( $o->status, [ 'in_progress', 'delivered', 'ta_completed', 'completed' ], true ) ) : ?>
                    <div class="ta-co-ta-fulfill" style="margin-top:10px;border-top:1px dashed #e5e7eb;padding-top:10px">
                        <?php if ( $o->status === 'in_progress' ) : /* [FIX-FILE-OPTIONAL] فقط تا وقتی تحویل نشده، آپلود فایل نمایش داده می‌شود — بعد از تحویل، ورودی فایل ناپدید می‌شود (ارسال فایل فقط یک‌بار) */ ?>
                        <div class="ta-sc-field">
                            <label>فایل نقد (اختیاری — می‌توانید بدون فایل هم تحویل بزنید)</label>
                            <input type="file" class="ta-co-result-file">
                        </div>
                        <?php else : ?>
                        <div class="ta-sc-field">
                            <label>فایل نقد</label>
                            <?php if ( $o->result_file_id ) : ?>
                                <p style="font-size:12px">فایل ارسالی: <a href="<?= esc_url( wp_get_attachment_url( $o->result_file_id ) ) ?>" target="_blank">دانلود</a> ✅</p>
                            <?php else : ?>
                                <p style="font-size:12px;color:#9ca3af">بدون فایل تحویل داده شده.</p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="ta-sc-field">
                            <label>پیام به هنرجو <span class="ta-sc-hint">(همراه تحویل ارسال می‌شود)</span></label>
                            <textarea class="ta-co-note-student" rows="2" <?= $o->status !== 'in_progress' ? 'readonly' : '' ?>><?= esc_textarea( $o->note_to_student ) ?></textarea>
                        </div>
                        <div class="ta-sc-field">
                            <label>توضیحات به ادمین <span class="ta-sc-hint">(اختیاری)</span></label>
                            <textarea class="ta-co-note-admin" rows="2" <?= $o->status !== 'in_progress' ? 'readonly' : '' ?>><?= esc_textarea( $o->note_to_admin ) ?></textarea>
                        </div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">
                            <?php if ( $o->status === 'in_progress' ) : ?>
                            <button type="button" class="ta-btn ta-btn-outline ta-btn-sm ta-co-save-note-btn">💾 ذخیره یادداشت‌ها</button>
                            <button type="button" class="ta-btn ta-btn-primary ta-btn-sm ta-co-deliver-btn">📬 تحویل به هنرجو</button>
                            <?php endif; ?>
                            <?php if ( $o->status === 'delivered' ) : ?>
                            <button type="button" class="ta-btn ta-btn-success ta-btn-sm ta-co-complete-open-btn">📝 تکمیل نقد و ثبت ارزیابی هنرجو</button>
                            <?php endif; ?>
                            <?php if ( $o->status === 'ta_completed' ) : ?>
                            <span style="font-size:12px;color:#0891b2">در انتظار تیک «دریافت کردم» هنرجو…</span>
                            <?php endif; ?>
                        </div>
                        <div class="ta-co-ta-msg ta-sc-msg" style="display:none"></div>
                    </div>
                    <?php endif; ?>

                    <?php if ( $o->status !== 'cancelled' ) : ?>
                        <?php TA_Critique_Chat::render_box( (int) $o->id, 'ta_student', 'گفتگو با هنرجو' ); ?>
                        <?php TA_Critique_Chat::render_box( (int) $o->id, 'admin_ta', 'گفتگو با ادمین' ); ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- مودال «تکمیل نقد و ثبت ارزیابی هنرجو» (مشترک بین همهٔ کارت‌ها) -->
        <div id="ta-co-complete-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center">
            <div class="ta-co-complete-dialog" dir="rtl">
                <div class="ta-eval-ta-hero"><span>📝 سؤال‌های ارزیابی هنرجو (توسط استاد)</span><h3>ارزیابی تجربهٔ منتقد از هنرجو</h3><p>منتقد و استاد گرامی، نظر شما به ما کمک می‌کند کیفیت فرایند نقد و تجربهٔ همکاری در پایگاه نقد مبنا را بهتر ارزیابی و مدیریت کنیم.</p><p>لطفاً هر مورد را بر اساس تجربهٔ واقعی خودتان در همین فرایند ارزیابی کنید.</p></div>
                <div class="ta-eval-ta-scale"><strong>مقیاس ارزیابی:</strong><span>عالی</span><span>خوب</span><span>متوسط</span><span>نیازمند بهبود</span><span>ضعیف</span><span>در این‌باره موضوعی پیش نیامد</span></div>
                <?php if ( empty( $student_eval_questions ) ) : ?>
                    <p style="color:#dc2626">هنوز هیچ سؤالی برای ارزیابی هنرجو توسط ادمین تعریف نشده است. لطفاً با ادمین هماهنگ کنید.</p>
                <?php else : ?>
                <p style="font-size:12px;color:#6b7280;margin-top:-8px">برای تکمیل نهایی این سفارش، لطفاً دربارهٔ کار هنرجو نظر بدهید.</p>
                <div id="ta-co-complete-fields">
                    <?php foreach ( $student_eval_questions as $q_index => $q ) :
                        $options = $q->options ? json_decode( $q->options, true ) : [];
                    ?>
                    <?php if ( $q_index === 0 ) : ?><div class="ta-eval-subheading">بخش اول: ارزیابی همکاری هنرجو</div><?php endif; ?>
                    <?php if ( $q_index === 7 ) : ?><div class="ta-eval-subheading ta-eval-subheading-text">بخش دوم: ارزیابی متن هنرجو</div><?php endif; ?>
                    <div class="ta-sc-field ta-eval-question-row <?= $q->type === 'text' ? 'ta-eval-text-row' : '' ?>" style="margin-bottom:12px">
                        <label><span class="ta-eval-number"><?= (int) $q_index + 1 ?></span><?= esc_html( $q->question ) ?></label>
                        <?php if ( $q->type === 'rating' ) : ?>
                            <div class="ta-eval-toggle-group" role="group" aria-label="انتخاب ارزیابی">
                                <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                                <button type="button" class="ta-eval-toggle" data-value="<?= $i ?>"><?= str_repeat( '⭐', $i ) ?> <small><?= $i ?></small></button>
                                <?php endfor; ?>
                                <input type="hidden" class="ta-co-complete-answer" data-qid="<?= (int) $q->id ?>" value="">
                            </div>
                        <?php elseif ( $q->type === 'choice' && ! empty( $options ) ) : ?>
                            <div class="ta-eval-toggle-group" role="group" aria-label="انتخاب ارزیابی">
                                <?php foreach ( $options as $opt ) : ?>
                                <button type="button" class="ta-eval-toggle" data-value="<?= esc_attr( $opt ) ?>"><?= esc_html( $opt ) ?></button>
                                <?php endforeach; ?>
                                <input type="hidden" class="ta-co-complete-answer" data-qid="<?= (int) $q->id ?>" value="">
                            </div>
                        <?php else : ?>
                            <?php if ( mb_strpos( $q->question, 'امتیاز متن' ) !== false ) : ?>
                                <input type="number" class="ta-co-complete-answer" data-qid="<?= (int) $q->id ?>" min="0" max="10" step="0.5" placeholder="۰ تا ۱۰">
                            <?php else : ?>
                                <textarea class="ta-co-complete-answer" data-qid="<?= (int) $q->id ?>" rows="2"></textarea>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div id="ta-co-complete-msg" class="ta-sc-msg" style="display:none;margin-bottom:8px"></div>
                <p>
                    <button type="button" class="ta-btn ta-btn-success ta-btn-sm" id="ta-co-complete-submit-btn" <?= empty( $student_eval_questions ) ? 'disabled' : '' ?>>✅ ثبت و تکمیل نقد</button>
                    <button type="button" class="ta-btn ta-btn-outline ta-btn-sm" onclick="document.getElementById('ta-co-complete-modal').style.display='none'">انصراف</button>
                </p>
            </div>
        </div>

        <style>
        #ta-co-complete-modal .ta-co-complete-dialog{background:#fff;border-radius:18px;padding:24px;width:920px;max-width:calc(100vw - 48px);max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(15,23,42,.25)}#ta-co-complete-modal .ta-sc-field{background:#fafafa;border:1px solid #e5e7eb;border-radius:12px;padding:12px}.ta-co-complete-answer{max-width:100%;box-sizing:border-box}
        #ta-co-complete-modal input[type=number]{width:140px;padding:8px;border:1px solid #cbd5e1;border-radius:8px}
        #ta-co-complete-modal select,#ta-co-complete-modal textarea{border:1px solid #cbd5e1;border-radius:8px;padding:8px;width:100%}.ta-eval-question-row{display:block!important}.ta-eval-question-row>label{display:block;margin:0 0 13px!important;line-height:1.9;color:#172033;font-weight:800}.ta-eval-text-row{display:block}.ta-eval-text-row textarea,.ta-eval-text-row input[type=number]{display:block}.ta-eval-ta-hero{background:linear-gradient(135deg,#172554,#2563eb);color:#fff;padding:18px;border-radius:15px;margin-bottom:12px}.ta-eval-ta-hero span{font-size:12px;color:#bfdbfe;font-weight:700}.ta-eval-ta-hero h3{margin:7px 0;font-size:19px;color:#fff!important}.ta-eval-ta-hero p{font-size:12px;line-height:1.8;margin:3px 0;opacity:.9}.ta-eval-ta-scale{display:flex;flex-wrap:wrap;gap:6px;align-items:center;background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:10px;margin-bottom:14px;color:#1e3a8a;font-size:11px}.ta-eval-ta-scale span{background:#fff;padding:4px 8px;border-radius:99px}.ta-eval-subheading{font-weight:800;color:#1d4ed8;border-bottom:2px solid #dbeafe;padding:10px 2px;margin:12px 0}.ta-eval-subheading-text{margin-top:22px}.ta-eval-number{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;background:#dbeafe;color:#1d4ed8;border-radius:7px;margin-left:7px;font-size:11px}.ta-eval-toggle-group{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:4px 0 1px}.ta-eval-toggle{appearance:none!important;-webkit-appearance:none!important;font-family:inherit!important;font-size:12px!important;line-height:1.4!important;border:1px solid #d7deea!important;background:linear-gradient(180deg,#fff,#f8fafc)!important;color:#344054!important;border-radius:12px!important;padding:10px 15px!important;cursor:pointer!important;transition:transform .16s,box-shadow .16s,background .16s,border-color .16s,color .16s!important;white-space:nowrap!important;box-shadow:0 2px 6px rgba(15,23,42,.06)!important}.ta-eval-toggle:hover{transform:translateY(-1px)!important;border-color:#6d8fe8!important;color:#2454b8!important;box-shadow:0 5px 12px rgba(37,99,235,.12)!important}.ta-eval-toggle.is-on{background:linear-gradient(135deg,#2454b8,#4f7bea)!important;border-color:#2454b8!important;color:#fff!important;box-shadow:0 6px 14px rgba(37,99,235,.25)!important;transform:translateY(-1px)!important}.ta-eval-toggle small{font-size:10px;opacity:.85}
        @media (max-width:700px){#ta-co-complete-modal .ta-co-complete-dialog{width:calc(100vw - 24px);max-width:calc(100vw - 24px);padding:16px}.ta-eval-toggle-group{flex-wrap:wrap}.ta-eval-toggle{flex:1 1 auto;min-width:110px}}
        </style>
        <script>
        (function($){
            var ajaxUrl = ( typeof taSystem !== 'undefined' && taSystem.ajaxUrl ) ? taSystem.ajaxUrl : '<?= esc_js( admin_url( 'admin-ajax.php' ) ) ?>';
            var nonce   = '<?= esc_js( $nonce ) ?>';
            var completeCorderId = 0;

            function reload(){ window.location.reload(); }
            function showCardMsg($card, msg, type){
                $card.find('.ta-co-ta-msg').text(msg).removeClass('error info').addClass(type).show();
            }

            $(document).on('click', '.ta-co-start-btn', function(){
                var $card = $(this).closest('.ta-co-ta-order-card');
                $.post(ajaxUrl, {action:'ta_co_ta_status', nonce:nonce, corder_id:$card.data('id'), status:'in_progress'}, function(res){
                    if (res.success) reload(); else showCardMsg($card, res.data && res.data.message ? res.data.message : 'خطا', 'error');
                });
            });

            $(document).on('click', '.ta-co-save-note-btn', function(){
                var $card = $(this).closest('.ta-co-ta-order-card');
                $.post(ajaxUrl, {
                    action:'ta_co_ta_save_notes', nonce:nonce, corder_id:$card.data('id'),
                    note_to_admin: $card.find('.ta-co-note-admin').val(),
                    note_to_student: $card.find('.ta-co-note-student').val()
                }, function(res){
                    showCardMsg($card, res.success ? '✅ ذخیره شد' : 'خطا', res.success ? 'info' : 'error');
                });
            });

            $(document).on('click', '.ta-co-deliver-btn', function(){
                var $card = $(this).closest('.ta-co-ta-order-card');
                var fileInput = $card.find('.ta-co-result-file')[0];
                var fd = new FormData();
                fd.append('action', 'ta_co_ta_deliver');
                fd.append('nonce', nonce);
                fd.append('corder_id', $card.data('id'));
                fd.append('note_to_student', $card.find('.ta-co-note-student').val());
                fd.append('note_to_admin', $card.find('.ta-co-note-admin').val());
                // [FIX-FILE-OPTIONAL] فایل دیگر اجباری نیست — اگر انتخاب نشده باشد بدون آن ارسال می‌شود
                if (fileInput && fileInput.files[0]) fd.append('file', fileInput.files[0]);
                var $btn = $(this).prop('disabled', true).text('در حال ارسال…');
                $.ajax({url:ajaxUrl, type:'POST', data:fd, processData:false, contentType:false})
                .done(function(res){
                    if (res.success) reload();
                    else { $btn.prop('disabled', false).text('📬 تحویل به هنرجو'); showCardMsg($card, res.data && res.data.message ? res.data.message : 'خطا', 'error'); }
                });
            });

            // [FEATURE-STUDENT-EVAL] «تکمیل نقد» حالا یعنی: فرم ارزیابی هنرجو را پر کن و بفرست
            $(document).on('click', '.ta-co-complete-open-btn', function(){
                var $card = $(this).closest('.ta-co-ta-order-card');
                completeCorderId = $card.data('id');
                $('#ta-co-complete-fields .ta-co-complete-answer').val('');
                $('#ta-co-complete-fields .ta-eval-toggle').removeClass('is-on');
                $('#ta-co-complete-msg').hide();
                $('#ta-co-complete-modal').css('display', 'flex');
            });

            $(document).on('click', '#ta-co-complete-fields .ta-eval-toggle', function(){
                var $button = $(this);
                var $group = $button.closest('.ta-eval-toggle-group');
                $group.find('.ta-eval-toggle').removeClass('is-on');
                $button.addClass('is-on');
                $group.find('.ta-co-complete-answer').val($button.data('value'));
            });

            $('#ta-co-complete-submit-btn').on('click', function(){
                var $btn = $(this);
                var answers = {};
                $('#ta-co-complete-fields .ta-co-complete-answer').each(function(){
                    var v = $(this).val();
                    if (v) answers[$(this).data('qid')] = v;
                });
                if (!Object.keys(answers).length) {
                    $('#ta-co-complete-msg').text('لطفاً حداقل به یک سؤال پاسخ دهید.').removeClass('info').addClass('error').show();
                    return;
                }
                $btn.prop('disabled', true).text('در حال ثبت…');
                var data = {action:'ta_co_ta_complete', nonce:nonce, corder_id:completeCorderId};
                Object.keys(answers).forEach(function(qid){ data['answers['+qid+']'] = answers[qid]; });
                $.post(ajaxUrl, data, function(res){
                    if (res.success) { reload(); }
                    else {
                        $btn.prop('disabled', false).text('✅ ثبت و تکمیل نقد');
                        $('#ta-co-complete-msg').text(res.data && res.data.message ? res.data.message : 'خطا').removeClass('info').addClass('error').show();
                    }
                });
            });
        })(jQuery);
        </script>
        <style>
        .ta-co-ta-orders{display:flex;flex-direction:column;gap:12px}
        .ta-co-ta-order-card{border:1px solid #e5e7eb;border-radius:10px;padding:14px}
        .ta-co-ta-order-head{display:flex;justify-content:space-between;align-items:center}
        </style>
        <?php
    }

    // ── نمره‌های من ──────────────────────────────────────────────────────────
    private function tab_grades( object $ta ): void {
        global $wpdb;

        $grade_tbl  = TA_Database::table( 'grades' );
        $ta_tbl     = TA_Database::table( 'assistants' );
        $term_tbl   = TA_Database::table( 'terms' );

        // ── فیلترها ──────────────────────────────────────────────────────────
        $filter_term    = absint( $_GET['fg_term']    ?? 0 );
        $filter_type    = sanitize_text_field( $_GET['fg_type']    ?? '' );
        $filter_grade   = sanitize_text_field( $_GET['fg_grade']   ?? '' );
        $filter_product = absint( $_GET['fg_product'] ?? 0 );
        $filter_search  = sanitize_text_field( $_GET['fg_search']  ?? '' );
        $sort_col       = sanitize_key( $_GET['fg_orderby'] ?? 'created_at' );
        $sort_dir       = strtoupper( sanitize_text_field( $_GET['fg_order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';
        $paged          = max( 1, absint( $_GET['fg_paged'] ?? 1 ) );
        $per_page       = 20;
        $offset         = ( $paged - 1 ) * $per_page;

        $allowed_sort = [ 'created_at', 'updated_at', 'grade', 'student_name', 'product_name', 'term_name' ];
        if ( ! in_array( $sort_col, $allowed_sort, true ) ) $sort_col = 'created_at';

        // ── WHERE ────────────────────────────────────────────────────────────
        $where   = [ 'g.ta_id = %d' ];
        $params  = [ $ta->id ];

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
            LEFT JOIN {$term_tbl} t  ON t.id       = g.term_id
            LEFT JOIN {$wpdb->posts} p ON p.ID     = g.product_id
            WHERE {$where_sql}
        ";

        // تعداد کل
        $total_filtered = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$base_sql}", ...$params ) );

        // ── آمار بر اساس فیلتر جاری ─────────────────────────────────────────
        $stats_sql = "SELECT g.grade, COUNT(*) as cnt {$base_sql} GROUP BY g.grade";
        $stats_rows = $wpdb->get_results( $wpdb->prepare( $stats_sql, ...$params ) );
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
        $ladder_cnt = (int) ( $stats['ladder']->cnt   ?? 0 );
        $pend_cnt   = (int) ( $stats['pending']->cnt  ?? 0 );
        $pass_rate  = $total_all > 0 ? round( $pass_cnt / $total_all * 100 ) : 0;

        // آمار ماه جاری (بر اساس فیلتر جاری)
        $month_where   = $where;
        $month_params  = $params;
        $month_where[] = 'MONTH(g.created_at) = MONTH(NOW()) AND YEAR(g.created_at) = YEAR(NOW())';
        $month_where_sql = implode( ' AND ', $month_where );
        $month_base_sql = "
            FROM {$grade_tbl} g
            JOIN {$wpdb->users}   u  ON u.ID      = g.student_id
            LEFT JOIN {$term_tbl} t  ON t.id       = g.term_id
            LEFT JOIN {$wpdb->posts} p ON p.ID     = g.product_id
            WHERE {$month_where_sql}
        ";
        $this_month_cnt = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) {$month_base_sql}", ...$month_params
        ) );

        // ── ردیف‌های صفحه ────────────────────────────────────────────────
        $order_map = [
            'student_name'  => 'u.display_name',
            'product_name'  => 'p.post_title',
            'term_name'     => 't.name',
            'grade'         => 'g.grade',
            'created_at'    => 'g.created_at',
            'updated_at'    => 'g.updated_at',
        ];
        $order_col = $order_map[ $sort_col ] ?? 'g.created_at';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT g.*, u.display_name AS student_name, u.user_email AS student_email,
                    t.name AS term_name, p.post_title AS product_name
             {$base_sql} ORDER BY {$order_col} {$sort_dir} LIMIT %d OFFSET %d",
            ...array_merge( $params, [ $per_page, $offset ] )
        ) );

        // داده‌های فیلتر
        $terms     = TA_Database::get_results( "SELECT id, name FROM {$term_tbl} ORDER BY order_index ASC" );
        $all_prods = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT g.product_id, p.post_title FROM {$grade_tbl} g LEFT JOIN {$wpdb->posts} p ON p.ID = g.product_id WHERE g.ta_id = %d ORDER BY p.post_title ASC",
            $ta->id
        ) );

        $total_pages = (int) ceil( $total_filtered / $per_page );

        $course_types = [
            'creative'      => 'نویسندگی خلاق',
            'intro'         => 'نویسندگی مقدماتی',
            'basic_writing' => 'نویسندگی پایه',
            'advanced'      => 'نویسندگی پیشرفته',
            'ladder'        => 'نردبان حرفه‌ای',
            'professional'  => 'نویسندگی حرفه‌ای',
            'critique'      => 'نقد اثر',
        ];

        $base_tab_url = add_query_arg( 'ta_tab', 'grades' );
        ?>

<style>
:root{
    --tgm-pass:var(--ta-adm-green);--tgm-pass-bg:#d1fae5;
    --tgm-repeat:var(--ta-adm-amber);--tgm-repeat-bg:#fef3c7;
    --tgm-critique:var(--ta-adm-purple);--tgm-critique-bg:#ede9fe;
    --tgm-ladder:#7c3aed;--tgm-ladder-bg:#ede9fe;
    --tgm-pending:var(--ta-adm-gray-500);--tgm-pending-bg:var(--ta-adm-gray-100);
    --tgm-total:var(--ta-adm-blue);--tgm-rate:#ec4899;
    --tgm-radius:12px;
    --tgm-shadow:0 1px 3px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.06);
    --tgm-shadow-md:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -1px rgba(0,0,0,.06);
    --tgm-border:var(--ta-adm-gray-200);--tgm-text:var(--ta-adm-gray-900);--tgm-muted:var(--ta-adm-gray-500);--tgm-bg:var(--ta-adm-gray-50);
}
/* ── آمار ── */
.tgm-stats-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:20px}
@media(max-width:1100px){.tgm-stats-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){.tgm-stats-grid{grid-template-columns:repeat(2,1fr)}}
.tgm-stat-card{background:#fff;border-radius:var(--tgm-radius);padding:16px;box-shadow:var(--tgm-shadow);position:relative;overflow:hidden;transition:transform .15s,box-shadow .15s;border:1px solid var(--tgm-border)}
.tgm-stat-card:hover{transform:translateY(-2px);box-shadow:var(--tgm-shadow-md)}
.tgm-stat-icon{font-size:24px;margin-bottom:6px;line-height:1}
.tgm-stat-number{font-size:26px;font-weight:800;line-height:1;margin-bottom:4px}
.tgm-stat-label{font-size:12px;color:var(--tgm-muted);font-weight:500}
.tgm-stat-sub{font-size:11px;color:var(--tgm-muted);margin-top:6px}
.tgm-stat-total .tgm-stat-number{color:var(--tgm-total)}
.tgm-stat-pass .tgm-stat-number{color:var(--tgm-pass)}
.tgm-stat-repeat .tgm-stat-number{color:var(--tgm-repeat)}
.tgm-stat-critique .tgm-stat-number{color:var(--tgm-critique)}
.tgm-stat-ladder .tgm-stat-number{color:var(--tgm-ladder)}
.tgm-stat-pending .tgm-stat-number{color:var(--tgm-pending)}
.tgm-progress-bar{height:4px;background:var(--ta-adm-gray-100);border-radius:99px;overflow:hidden;margin-top:4px}
.tgm-progress-fill{height:100%;background:var(--tgm-rate);border-radius:99px}
/* ── کارت ── */
.tgm-card{background:#fff;border-radius:var(--tgm-radius);box-shadow:var(--tgm-shadow);overflow:hidden;border:1px solid var(--tgm-border)}
/* ── فیلتر ── */
.tgm-filter-card{margin-bottom:14px;padding:14px 16px}
.tgm-filter-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.tgm-search-wrap{position:relative;flex:1;min-width:180px}
.tgm-search-icon{position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:13px;pointer-events:none;color:var(--tgm-muted)}
.tgm-search-input{width:100%;padding:7px 32px 7px 10px;border:1px solid var(--tgm-border);border-radius:8px;font-size:13px;outline:none;transition:border-color .15s;box-sizing:border-box;font-family:inherit;direction:rtl;color:var(--tgm-text);background:#fff}
.tgm-search-input:focus{border-color:var(--ta-primary)}
.tgm-select{padding:7px 10px;border:1px solid var(--tgm-border);border-radius:8px;font-size:13px;outline:none;background:#fff;font-family:inherit;cursor:pointer;color:var(--tgm-text)}
.tgm-select:focus{border-color:var(--ta-primary)}
/* ── دکمه‌ها ── */
.tgm-btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:8px;border:none;font-size:13px;font-weight:500;cursor:pointer;text-decoration:none;transition:all .15s;font-family:inherit;line-height:1}
.tgm-btn-filter{background:var(--ta-primary);color:#fff}.tgm-btn-filter:hover{background:var(--ta-primary-dark)}
.tgm-btn-reset{background:var(--ta-adm-gray-100);color:var(--tgm-text)}.tgm-btn-reset:hover{background:var(--ta-adm-gray-200)}
.tgm-btn-edit{background:transparent;padding:4px 8px;font-size:15px;border-radius:6px;border:1px solid transparent;color:inherit}.tgm-btn-edit:hover{background:var(--ta-adm-gray-100);border-color:var(--tgm-border)}
.tgm-btn-save{background:var(--ta-primary);color:#fff;padding:10px 22px}.tgm-btn-save:hover{background:var(--ta-primary-dark)}
.tgm-btn-cancel{background:var(--ta-adm-gray-100);color:var(--tgm-text);padding:10px 18px}.tgm-btn-cancel:hover{background:var(--ta-adm-gray-200)}
/* ── جدول ── */
.tgm-table-header{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--tgm-border);gap:10px;flex-wrap:wrap}
.tgm-table-info{font-size:13px;color:var(--tgm-muted)}
.tgm-table-info strong{color:var(--tgm-text)}
.tgm-filter-active-badge{background:#fdece3;color:var(--ta-primary);font-size:11px;padding:2px 8px;border-radius:99px;margin-right:8px}
.tgm-table-wrap{overflow-x:auto}
.tgm-table{width:100%;border-collapse:collapse;font-size:13px}
.tgm-table thead th{background:var(--ta-adm-gray-50);padding:9px 12px;text-align:right;font-weight:600;font-size:11px;color:var(--tgm-muted);text-transform:uppercase;border-bottom:1px solid var(--tgm-border);white-space:nowrap;letter-spacing:.03em}
.tgm-table tbody tr{border-bottom:1px solid var(--ta-adm-gray-100);transition:background .1s}
.tgm-table tbody tr:hover{background:#fafafa}
.tgm-table tbody td{padding:9px 12px;vertical-align:middle}
.tgm-sort-link{color:var(--tgm-muted);text-decoration:none;font-size:11px;font-weight:600;white-space:nowrap}.tgm-sort-link:hover{color:var(--tgm-text)}
.tgm-student-cell{display:flex;align-items:center;gap:9px}
.tgm-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1.5px solid var(--tgm-border)}
.tgm-student-name{font-weight:600;font-size:13px;color:var(--tgm-text)}
.tgm-student-email{font-size:11px;color:var(--tgm-muted)}
.tgm-grade-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:12px;font-weight:600;white-space:nowrap}
.tgm-term-badge{background:#eff6ff;color:#1d4ed8;font-size:11px;padding:2px 7px;border-radius:99px;white-space:nowrap}
.tgm-product{max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:12px;color:var(--tgm-text)}
.tgm-date{font-size:11px;color:var(--tgm-muted);white-space:nowrap}
.tgm-notes{font-size:11px;color:var(--tgm-muted);max-width:110px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tgm-muted-dash{color:#d1d5db}
.tgm-actions{display:flex;gap:3px}
.tgm-empty{padding:40px 0 !important;text-align:center}
.tgm-empty-state{display:flex;flex-direction:column;align-items:center;gap:8px}
.tgm-empty-icon{font-size:36px}
.tgm-empty-state p{color:var(--tgm-muted);font-size:14px;margin:0}
/* ── صفحه‌بندی ── */
.tgm-pagination{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-top:1px solid var(--tgm-border);flex-wrap:wrap;gap:8px}
.tgm-page-links{display:flex;gap:4px;flex-wrap:wrap}
.tgm-page-links .page-numbers{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;font-size:12px;text-decoration:none;color:var(--tgm-text);border:1px solid var(--tgm-border);transition:all .1s}
.tgm-page-links .page-numbers:hover{border-color:var(--ta-primary);color:var(--ta-primary)}
.tgm-page-links .page-numbers.current{background:var(--ta-primary);color:#fff;border-color:var(--ta-primary)}
.tgm-page-info{font-size:12px;color:var(--tgm-muted)}
/* ── مودال ویرایش ── */
.tgm-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;display:flex;align-items:center;justify-content:center}
.tgm-modal{background:#fff;border-radius:16px;width:100%;max-width:460px;box-shadow:0 20px 60px rgba(0,0,0,.25);animation:tgmSlideUp .2s ease}
@keyframes tgmSlideUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.tgm-modal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--tgm-border)}
.tgm-modal-header h2{margin:0;font-size:16px;font-weight:700;color:var(--tgm-text)}
.tgm-modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--tgm-muted);width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;transition:background .1s}.tgm-modal-close:hover{background:var(--ta-adm-gray-100)}
.tgm-modal-body{padding:20px}
.tgm-modal-student{background:var(--ta-adm-gray-50);border-radius:10px;padding:11px 14px;margin-bottom:16px;font-size:13px;font-weight:600;color:var(--tgm-text);line-height:1.5;border:1px solid var(--tgm-border)}
.tgm-form-group{margin-bottom:16px}
.tgm-form-group label{display:block;font-size:11px;font-weight:700;color:var(--tgm-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}
.tgm-grade-picker{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.tgm-grade-option{display:block;cursor:pointer}
.tgm-grade-option input{display:none}
.tgm-grade-option span{display:flex;align-items:center;gap:8px;padding:9px 12px;border-radius:10px;border:2px solid var(--tgm-border);font-size:13px;font-weight:500;transition:all .15s;color:var(--tgm-text)}
.tgm-grade-option input:checked + span{border-color:var(--gc);background:var(--gb);color:var(--gc)}
.tgm-grade-option:hover span{border-color:var(--gc);background:var(--gb)}
.tgm-textarea{width:100%;padding:9px 12px;border:1px solid var(--tgm-border);border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;outline:none;direction:rtl;box-sizing:border-box;transition:border-color .15s;color:var(--tgm-text)}
.tgm-textarea:focus{border-color:var(--ta-primary)}
.tgm-modal-footer{display:flex;gap:8px;padding:14px 20px;border-top:1px solid var(--tgm-border);justify-content:flex-end}
</style>

<!-- ── هدر تب ─────────────────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding-bottom:14px;border-bottom:1px solid var(--tgm-border,#e5e7eb)">
    <div style="display:flex;align-items:center;gap:12px">
        <span style="font-size:28px;line-height:1">🎓</span>
        <div>
            <h3 style="margin:0 0 3px;font-size:18px;font-weight:800;color:#111827">نمره‌های من</h3>
            <p style="margin:0;font-size:12px;color:var(--tgm-muted,#6b7280)">مشاهده و ویرایش نمرات هنرجویان شما</p>
        </div>
    </div>
</div>

<!-- ── کارت‌های آمار ────────────────────────────────────────────────── -->
<?php $has_filter = $filter_term || $filter_type || $filter_grade || $filter_product || $filter_search; ?>
<div class="tgm-stats-grid">
    <div class="tgm-stat-card tgm-stat-total">
        <div class="tgm-stat-icon">📊</div>
        <div class="tgm-stat-number"><?= number_format($total_all) ?></div>
        <div class="tgm-stat-label">کل نمرات<?php if ($has_filter): ?> <span style="font-size:9px;background:#fdece3;color:var(--ta-primary);padding:1px 5px;border-radius:99px;vertical-align:middle">فیلتر فعال</span><?php endif; ?></div>
        <div class="tgm-stat-sub">این ماه: +<?= $this_month_cnt ?></div>
    </div>
    <div class="tgm-stat-card tgm-stat-pass">
        <div class="tgm-stat-icon">✓</div>
        <div class="tgm-stat-number"><?= number_format($pass_cnt) ?></div>
        <div class="tgm-stat-label">قبولی</div>
        <div class="tgm-stat-sub">نرخ: <?= $pass_rate ?>%</div>
    </div>
    <div class="tgm-stat-card tgm-stat-repeat">
        <div class="tgm-stat-icon">↺</div>
        <div class="tgm-stat-number"><?= number_format($repeat_cnt) ?></div>
        <div class="tgm-stat-label">تکرار دوره</div>
        <div class="tgm-stat-sub"><?= $total_all > 0 ? round($repeat_cnt / $total_all * 100) : 0 ?>% از کل</div>
    </div>
    <div class="tgm-stat-card tgm-stat-critique">
        <div class="tgm-stat-icon">✦</div>
        <div class="tgm-stat-number"><?= number_format($crit_cnt) ?></div>
        <div class="tgm-stat-label">نقد اثر</div>
        <div class="tgm-stat-sub"><?= $total_all > 0 ? round($crit_cnt / $total_all * 100) : 0 ?>% از کل</div>
    </div>
    <div class="tgm-stat-card tgm-stat-ladder">
        <div class="tgm-stat-icon">🪜</div>
        <div class="tgm-stat-number"><?= number_format($ladder_cnt) ?></div>
        <div class="tgm-stat-label">نردبان حرفه‌ای</div>
        <div class="tgm-stat-sub"><?= $total_all > 0 ? round($ladder_cnt / $total_all * 100) : 0 ?>% از کل</div>
    </div>
    <div class="tgm-stat-card tgm-stat-pending">
        <div class="tgm-stat-icon">⏳</div>
        <div class="tgm-stat-number"><?= number_format($pend_cnt) ?></div>
        <div class="tgm-stat-label">در انتظار</div>
        <div class="tgm-stat-sub">
            <div class="tgm-progress-bar">
                <div class="tgm-progress-fill" style="width:<?= $pass_rate ?>%;background:var(--tgm-pass)"></div>
            </div>
        </div>
    </div>
</div>

<!-- ── فیلترها ───────────────────────────────────────────────────────── -->
<div class="tgm-card tgm-filter-card" style="margin-bottom:14px">
    <form method="GET">
        <input type="hidden" name="ta_tab" value="grades">
        <div class="tgm-filter-row">
            <div class="tgm-search-wrap">
                <span class="tgm-search-icon">🔍</span>
                <input type="text" name="fg_search" value="<?= esc_attr($filter_search) ?>"
                       placeholder="جستجو نام، ایمیل یا دوره..." class="tgm-search-input">
            </div>
            <select name="fg_term" class="tgm-select">
                <option value="">همه ترم‌ها</option>
                <?php foreach ( $terms as $t ) : ?>
                    <option value="<?= $t->id ?>" <?= selected($filter_term, $t->id, false) ?>><?= esc_html($t->name) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="fg_type" class="tgm-select">
                <option value="">همه نوع دوره‌ها</option>
                <?php foreach ( $course_types as $key => $label ) : ?>
                    <option value="<?= $key ?>" <?= selected($filter_type, $key, false) ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <select name="fg_grade" class="tgm-select">
                <option value="">همه نمره‌ها</option>
                <?php foreach ( TA_Grade::all_grades() as $key => $g ) : ?>
                    <option value="<?= $key ?>" <?= selected($filter_grade, $key, false) ?>><?= $g['label'] ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ( $all_prods ) : ?>
            <select name="fg_product" class="tgm-select">
                <option value="">همه دوره‌ها</option>
                <?php foreach ( $all_prods as $prod ) : ?>
                    <option value="<?= $prod->product_id ?>" <?= selected($filter_product, $prod->product_id, false) ?>><?= esc_html($prod->post_title ?: '—') ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <button type="submit" class="tgm-btn tgm-btn-filter">اعمال فیلتر</button>
            <a href="<?= esc_url(add_query_arg('ta_tab', 'grades', remove_query_arg(['fg_term','fg_type','fg_grade','fg_product','fg_search','fg_orderby','fg_order','fg_paged']))) ?>" class="tgm-btn tgm-btn-reset">پاک کردن</a>
        </div>
    </form>
</div>

<!-- ── جدول ─────────────────────────────────────────────────────────── -->
<div class="tgm-card">
    <div class="tgm-table-header">
        <div class="tgm-table-info">
            نمایش <strong><?= count($rows ?? []) ?></strong> از <strong><?= number_format($total_filtered) ?></strong> نمره
            <?php if ($filter_search || $filter_term || $filter_type || $filter_grade || $filter_product) : ?>
                <span class="tgm-filter-active-badge">فیلتر فعال</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="tgm-table-wrap">
        <table class="tgm-table">
            <thead>
                <tr>
                    <?php $this->grade_sort_header( 'student_name', 'هنرجو',    $sort_col, $sort_dir, $base_tab_url ) ?>
                    <?php $this->grade_sort_header( 'product_name', 'دوره',     $sort_col, $sort_dir, $base_tab_url ) ?>
                    <?php $this->grade_sort_header( 'term_name',    'ترم',      $sort_col, $sort_dir, $base_tab_url ) ?>
                    <th>نوع دوره</th>
                    <?php $this->grade_sort_header( 'grade',        'نمره',     $sort_col, $sort_dir, $base_tab_url ) ?>
                    <th>یادداشت</th>
                    <?php $this->grade_sort_header( 'created_at',   'تاریخ ثبت', $sort_col, $sort_dir, $base_tab_url ) ?>
                    <?php if ( TA_Settings_Helper::is_enabled( 'ta_grading_enabled' ) ) : ?>
                    <th>عملیات</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr>
                    <td colspan="8" class="tgm-empty">
                        <div class="tgm-empty-state">
                            <div class="tgm-empty-icon">🎓</div>
                            <p>نمره‌ای یافت نشد.</p>
                            <?php if ($filter_search || $filter_grade || $filter_term) : ?>
                                <a href="<?= esc_url(add_query_arg('ta_tab', 'grades', remove_query_arg(['fg_term','fg_type','fg_grade','fg_product','fg_search']))) ?>">پاک کردن فیلترها</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ( $rows as $r ) :
                    $gd      = TA_Grade::all_grades()[ $r->grade ] ?? TA_Grade::all_grades()['pending'];
                    $ct      = $course_types[ $r->course_type ] ?? $r->course_type;
                    $date_fa = date_i18n( 'j F Y', strtotime( $r->created_at ) );
                    $attachment_id = get_user_meta( $r->student_id, 'image', true );
                    $avatar = $attachment_id
                        ? wp_get_attachment_url( $attachment_id )
                        : 'https://mabnaschool.ir/wp-content/uploads/2025/11/Frame-1666952.svg';
                ?>
                <tr>
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
                    <td style="font-size:12px;color:var(--tgm-muted)"><?= esc_html($ct) ?></td>
                    <td>
                        <span class="tgm-grade-badge" style="color:<?= $gd['color'] ?>;background:<?= $gd['bg'] ?>">
                            <?= $gd['icon'] ?> <?= $gd['label'] ?>
                        </span>
                    </td>
                    <td class="tgm-notes" title="<?= esc_attr($r->notes) ?>">
                        <?= $r->notes ? esc_html(mb_strimwidth($r->notes, 0, 40, '…')) : '<span class="tgm-muted-dash">—</span>' ?>
                    </td>
                    <td class="tgm-date"><?= esc_html($date_fa) ?></td>
                    <?php if ( TA_Settings_Helper::is_enabled( 'ta_grading_enabled' ) ) : ?>
                    <td>
                        <div class="tgm-actions">
                            <button class="tgm-btn tgm-btn-edit" title="ویرایش نمره"
                                onclick="tgmOpenEdit(<?= htmlspecialchars(json_encode([
                                    'id'          => $r->id,
                                    'grade'       => $r->grade,
                                    'notes'       => $r->notes,
                                    'name'        => $r->student_name,
                                    'prod'        => $r->product_name,
                                    'course_type' => $r->course_type,
                                ]), ENT_QUOTES) ?>)">✏️</button>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ( $total_pages > 1 ) : ?>
    <div class="tgm-pagination">
        <?php
        $page_links = paginate_links( [
            'base'      => add_query_arg( 'fg_paged', '%#%' ),
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

<?php if ( TA_Settings_Helper::is_enabled( 'ta_grading_enabled' ) ) : ?>
<!-- ── مودال ویرایش نمره ─────────────────────────────────────────────── -->
<div id="tgm-ta-modal" class="tgm-modal-overlay" style="display:none">
    <div class="tgm-modal">
        <div class="tgm-modal-header">
            <h2>ویرایش نمره</h2>
            <button class="tgm-modal-close" onclick="tgmCloseEdit()">✕</button>
        </div>
        <form method="POST" id="tgm-ta-edit-form" action="<?= esc_url(admin_url('admin-ajax.php')) ?>">
            <?php wp_nonce_field( 'ta_grade_edit', 'ta_grade_edit_nonce' ); ?>
            <input type="hidden" name="action" value="ta_ta_update_grade">
            <input type="hidden" name="grade_id" id="tgm-ta-grade-id">
            <div class="tgm-modal-body">
                <div class="tgm-modal-student" id="tgm-ta-student-info"></div>
                <div class="tgm-form-group">
                    <label>نمره</label>
                    <div class="tgm-grade-picker" id="tgm-ta-grade-picker"></div>
                </div>
                <div class="tgm-form-group">
                    <label>یادداشت</label>
                    <textarea name="notes" id="tgm-ta-notes" class="tgm-textarea" rows="3" placeholder="یادداشت اختیاری..."></textarea>
                </div>
            </div>
            <div class="tgm-modal-footer">
                <button type="button" class="tgm-btn tgm-btn-save" id="tgm-ta-save-btn" onclick="tgmSubmitEdit()">ذخیره تغییرات</button>
                <button type="button" class="tgm-btn tgm-btn-cancel" onclick="tgmCloseEdit()">انصراف</button>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    var ALL_GRADES = <?= json_encode( TA_Grade::all_grades() ) ?>;

    function getGradesForCourseType(courseType){
        var map = {
            'intro':    ['pass','critique','repeat','pending'],
                    'basic_writing': ['pass','critique','intro_permission','repeat','pending'],
            'critique': ['pass','repeat_critique','repeat_intro','pending'],
            'advanced': ['pass','repeat_advanced','ladder','pending'],
            'ladder':   ['pass','repeat_ladder','repeat_advanced','pending'],
        };
        var allowed = map[courseType] || ['pass','repeat','pending'];
        var result = {};
        allowed.forEach(function(k){ if(ALL_GRADES[k]) result[k] = ALL_GRADES[k]; });
        return result;
    }

    window.tgmOpenEdit = function(data){
        document.getElementById('tgm-ta-grade-id').value = data.id;
        document.getElementById('tgm-ta-notes').value    = data.notes || '';
        document.getElementById('tgm-ta-student-info').innerHTML =
            '👤 ' + data.name + (data.prod ? '  |  📚 ' + data.prod : '');

        var grades = getGradesForCourseType(data.course_type || '');
        var picker = document.getElementById('tgm-ta-grade-picker');
        picker.innerHTML = '';
        Object.entries(grades).forEach(function([key, g]){
            var label = document.createElement('label');
            label.className = 'tgm-grade-option';
            label.style.cssText = '--gc:' + g.color + ';--gb:' + g.bg;
            label.innerHTML = '<input type="radio" name="grade" value="' + key + '"' + (key === data.grade ? ' checked' : '') + '>'
                + '<span>' + g.icon + ' ' + g.label + '</span>';
            picker.appendChild(label);
        });

        document.getElementById('tgm-ta-modal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    };

    window.tgmCloseEdit = function(){
        document.getElementById('tgm-ta-modal').style.display = 'none';
        document.body.style.overflow = '';
    };

    window.tgmSubmitEdit = function(){
        var gradeId = document.getElementById('tgm-ta-grade-id').value;
        var grade   = document.querySelector('#tgm-ta-grade-picker input[name="grade"]:checked');
        var notes   = document.getElementById('tgm-ta-notes').value;
        var nonce   = document.querySelector('[name="ta_grade_edit_nonce"]').value;
        var $btn    = document.getElementById('tgm-ta-save-btn');

        if(!grade){ alert('لطفاً یک نمره انتخاب کنید.'); return; }

        $btn.disabled = true;
        $btn.textContent = '...';

        jQuery.post(
            '<?= esc_url(admin_url('admin-ajax.php')) ?>',
            {
                action:              'ta_ta_update_grade',
                ta_grade_edit_nonce: nonce,
                grade_id:            gradeId,
                grade:               grade.value,
                notes:               notes,
            },
            function(res){
                $btn.disabled = false;
                $btn.textContent = 'ذخیره تغییرات';
                if(res && res.success){
                    tgmCloseEdit();
                    window.location.reload();
                } else {
                    alert((res && res.data) ? res.data : 'خطا در ذخیره‌سازی');
                }
            }
        );
    };

    document.addEventListener('DOMContentLoaded', function(){
        var overlay = document.getElementById('tgm-ta-modal');
        if(overlay){
            overlay.addEventListener('click', function(e){ if(e.target === this) tgmCloseEdit(); });
        }
        document.addEventListener('keydown', function(e){ if(e.key === 'Escape') tgmCloseEdit(); });
    });

})();
</script>
<?php endif; ?>
        <?php
    }

    // هدر ستون قابل مرتب‌سازی (مخصوص تب نمره‌ها)
    private function grade_sort_header( string $col, string $label, string $current, string $dir, string $base_url ): void {
        $new_dir = ( $current === $col && $dir === 'ASC' ) ? 'DESC' : 'ASC';
        $url     = add_query_arg( [ 'fg_orderby' => $col, 'fg_order' => $new_dir ], $base_url );
        $arrow   = '';
        if ( $current === $col ) $arrow = $dir === 'ASC' ? ' ▲' : ' ▼';
        echo "<th><a href=\"" . esc_url($url) . "\" class=\"tgm-sort-link\">{$label}{$arrow}</a></th>";
    }

    // ── لیست سیاه ───────────────────────────────────────────────────────────
    private function tab_blacklist( object $ta ): void {
        $bl_table = TA_Database::table( 'blacklist' );
        global $wpdb;

        $list = $wpdb->get_results( $wpdb->prepare(
            "SELECT bl.*, u.display_name, u.user_email
             FROM {$bl_table} bl
             JOIN {$wpdb->users} u ON u.ID = bl.student_id
             WHERE bl.ta_id = %d
             ORDER BY bl.created_at DESC",
            [ $ta->id ]
        ) );
        ?>
        <div class="ta-section">
            <h3>لیست سیاه</h3>
            <?php if ( empty($list) ) : ?>
                <p>لیست سیاه خالی است.</p>
            <?php else : ?>
            <table class="ta-table">
                <thead>
                    <tr><th>هنرجو</th><th>دلیل</th><th>تاریخ</th><th>عملیات</th></tr>
                </thead>
                <tbody>
                <?php foreach ($list as $row) : ?>
                    <tr>
                        <td><?= esc_html($row->display_name) ?><br><small><?= esc_html($row->user_email) ?></small></td>
                        <td><?= esc_html($row->reason ?: '—') ?></td>
                        <td><?= esc_html($row->created_at) ?></td>
                        <td>
                            <button class="ta-btn ta-btn-sm" 
                                onclick="taRemoveBlacklist(<?= $row->student_id ?>)">
                                حذف از لیست
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
