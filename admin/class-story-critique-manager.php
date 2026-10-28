<?php
defined( 'ABSPATH' ) || exit;

/**
 * مدیریت صفحه «📝 گزارش فرم‌های نقد آثار» (پیش‌تر «نقد داستان»)
 * - تب فرم‌های ثبت‌شده: افزودن/ویرایش/حذف رکورد، آپلود عکس پروفایل، ظرفیت/قیمت‌پایه با تاریخچه، رزومه
 * - تب مدیریت تخصص‌ها: افزودن/ویرایش/حذف تخصص (عکس + توضیح بلند)
 *
 * نکته: نام جدول (ta_story_critique_forms)، اسلاگ صفحه (ta-story-critique) و
 * اکشن‌های AJAX قدیمی به‌عمد تغییر نکرده‌اند تا داده‌ها و لینک‌های موجود نشکنند.
 * فقط عناوین/لیبل‌های نمایشی از «نقد داستان» به «نقد آثار» تغییر کرده‌اند.
 */
class TA_Story_Critique_Manager {

    public function __construct() {
        add_action( 'admin_post_ta_sc_form_save',       [ $this, 'handle_form_save' ] );
        add_action( 'admin_post_ta_sc_form_delete',     [ $this, 'handle_form_delete' ] );
        add_action( 'admin_post_ta_sc_specialty_save',  [ $this, 'handle_specialty_save' ] );
        add_action( 'admin_post_ta_sc_specialty_delete',[ $this, 'handle_specialty_delete' ] );
        add_action( 'admin_post_ta_sc_settings_save',   [ $this, 'handle_settings_save' ] );
        add_action( 'admin_post_ta_sc_eval_q_save',     [ $this, 'handle_eval_question_save' ] );
        add_action( 'admin_post_ta_sc_eval_q_delete',   [ $this, 'handle_eval_question_delete' ] );
        add_action( 'admin_post_ta_sc_group_link_save', [ $this, 'handle_group_link_save' ] );
        // سقف + دسترسی ویرایش هر ستون قیمت‌گذاری (سراسری، برای همه استاد تسهیلگرها)
        add_action( 'admin_post_ta_sc_pricing_limits_save', [ $this, 'handle_pricing_limits_save' ] );
        // سؤال‌های «ارزیابی هنرجو توسط استاد»
        add_action( 'admin_post_ta_sc_student_eval_q_save',   [ $this, 'handle_student_eval_question_save' ] );
        add_action( 'admin_post_ta_sc_student_eval_q_delete', [ $this, 'handle_student_eval_question_delete' ] );
    }

    /** لیست ستون‌های قیمت‌گذاری که سقف/دسترسی سراسری برایشان تعریف می‌شود */
    const PRICING_FIELDS = [
        'capacity'           => 'ظرفیت',
        'base_price'         => 'قیمت پایه',
        'base_word_count'    => 'تعداد کلمه پایه',
        'increment_words'    => 'پله افزایش (کلمه)',
        'increment_percent'  => 'درصد افزایش هر پله',
    ];

    /** سقف تعیین‌شده برای یک ستون (null یعنی بدون سقف) */
    public static function pricing_field_cap( string $field ) {
        $v = get_option( 'ta_sc_field_cap_' . $field, '' );
        return ( $v === '' || $v === false ) ? null : (float) $v;
    }

    /** آیا استاد تسهیلگرها اجازهٔ ویرایش این ستون را (خودشان) دارند؟ پیش‌فرض: بله */
    public static function pricing_field_editable( string $field ): bool {
        return get_option( 'ta_sc_field_editable_' . $field, '1' ) === '1';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // رندر صفحه
    // ─────────────────────────────────────────────────────────────────────────

    public function render(): void {
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) {
            echo '<div class="wrap"><p>دسترسی غیرمجاز.</p></div>';
            return;
        }

        $restricted_orders_user = TA_Critique_Admin_Access::is_matched_phone_user();
        $tab = sanitize_key( $_GET['sc_tab'] ?? 'forms' );
        $valid_tabs = [ 'forms', 'pricing', 'specialties', 'orders', 'bale_group', 'evaluation', 'evaluation_report', 'settings' ];
        if ( ! in_array( $tab, $valid_tabs, true ) ) {
            $tab = 'forms';
        }

        $base_url = admin_url( 'admin.php?page=ta-story-critique' );
        $labels = [
            'forms'       => 'فرم‌های ثبت‌شده',
            'pricing'     => '💰 ظرفیت و قیمت‌گذاری',
            'specialties' => 'مدیریت تخصص‌ها',
            'orders'      => 'سفارش‌های نقد آثار',
            'bale_group'  => '💬 گروه بله استاد',
            'evaluation'  => 'ارزیابی',
            'evaluation_report' => '📊 گزارش ارزیابی',
            'settings'    => 'تنظیمات',
        ];
        ?>
        <div class="wrap" dir="rtl">
            <h1>📝 گزارش فرم‌های نقد آثار</h1>

            <?php $this->render_notices(); ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach ( $labels as $key => $label ) : ?>
                <a href="<?= esc_url( add_query_arg( 'sc_tab', $key, $base_url ) ) ?>" class="nav-tab <?= $tab === $key ? 'nav-tab-active' : '' ?>"><?= esc_html( $label ) ?></a>
                <?php endforeach; ?>
            </h2>

            <div style="margin-top:20px">
                <?php
                switch ( $tab ) {
                    case 'pricing':    $this->render_pricing_tab(); break;
                    case 'specialties': $this->render_specialties_tab(); break;
                    case 'orders':      $this->render_orders_tab(); break;
                    case 'bale_group':  $this->render_bale_group_tab(); break;
                    case 'evaluation':  $this->render_evaluation_tab(); break;
                    case 'evaluation_report': $this->render_evaluation_report_tab(); break;
                    case 'settings':    $this->render_settings_tab(); break;
                    default:            $this->render_forms_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_notices(): void {
        $map = [
            'saved'            => [ 'success', '✅ رکورد ذخیره شد.' ],
            'deleted'          => [ 'success', '🗑️ رکورد حذف شد.' ],
            'spec_saved'       => [ 'success', '✅ تخصص ذخیره شد.' ],
            'spec_deleted'     => [ 'success', '🗑️ تخصص حذف شد.' ],
            'settings_saved'   => [ 'success', '✅ تنظیمات ذخیره شد.' ],
            'q_saved'          => [ 'success', '✅ سؤال ذخیره شد.' ],
            'q_deleted'        => [ 'success', '🗑️ سؤال حذف شد.' ],
            'group_link_saved' => [ 'success', '✅ لینک گروه بله استاد ذخیره شد.' ],
            'error'            => [ 'error',   '❌ خطایی رخ داد.' ],
            'error_no_user'    => [ 'error',   '❌ لطفاً یک کاربر انتخاب کنید.' ],
            'error_dup_name'   => [ 'error',   '❌ تخصصی با این نام از قبل وجود دارد.' ],
        ];
        foreach ( $map as $key => [ $type, $msg ] ) {
            if ( isset( $_GET[ $key ] ) ) {
                printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $msg ) );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تب: فرم‌های ثبت‌شده
    // ─────────────────────────────────────────────────────────────────────────

    private function render_forms_tab(): void {
        $table = TA_Database::table( 'story_critique_forms' );
        $rows  = TA_Database::get_results(
            "SELECT scf.*, u.user_email, u.display_name
             FROM {$table} scf
             LEFT JOIN {$GLOBALS['wpdb']->users} u ON u.ID = scf.user_id
             ORDER BY scf.created_at DESC LIMIT 1000"
        );

        $spec_table = TA_Database::table( 'specialties' );
        $specialties = TA_Database::get_results( "SELECT * FROM {$spec_table} ORDER BY sort_order ASC, id ASC" );

        $admin_nonce  = wp_create_nonce( 'ta_admin_nonce' );
        $ajax_url     = admin_url( 'admin-ajax.php' );
        $post_url     = admin_url( 'admin-post.php' );
        $default_avatar = 'https://mabnaschool.ir/wp-content/uploads/2025/11/Frame-1666952.svg';
        ?>
        <p>
            <button type="button" class="button button-primary" onclick="scOpenFormModal()">➕ افزودن رکورد جدید</button>
            <span style="color:#555;margin-right:10px">تعداد کل: <strong><?= count( $rows ) ?></strong> فرم</span>
        </p>

        <?php if ( empty( $rows ) ) : ?>
            <div class="notice notice-info"><p>هنوز فرمی ثبت نشده است.</p></div>
        <?php else : ?>
        <div class="ta-sc-wrap" style="border:1px solid #e5e7eb;border-radius:8px;overflow-x:auto">
            <table class="ta-sc-table widefat" style="width:100%;border-collapse:collapse;font-size:13px;background:#fff">
                <thead><tr>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">#</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">عکس</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">نام و نام‌خانوادگی</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">شغل / نقش</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">شماره تماس</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">ایمیل</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">تخصص‌ها</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">ساعات در روز</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">رزومه</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">تاریخ ارسال</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">عملیات</th>
                </tr></thead>
                <tbody>
                <?php
                $CHIP_COLORS = ['#ede9fe:#6d28d9','#fce7f3:#9d174d','#d1fae5:#065f46','#fef3c7:#92400e','#dbeafe:#1e40af','#fee2e2:#991b1b','#e0f2fe:#0369a1','#f0fdf4:#166534','#fdf4ff:#7e22ce','#fff7ed:#c2410c'];
                foreach ( $rows as $idx => $r ) :
                    $specs = $r->specialties ? json_decode( $r->specialties, true ) : [];
                    $specs = is_array( $specs ) ? $specs : [];
                    $att_id     = $r->user_id ? get_user_meta( $r->user_id, 'image', true ) : 0;
                    $avatar_url = $att_id ? wp_get_attachment_url( $att_id ) : $default_avatar;
                    $row_json   = esc_attr( wp_json_encode( [
                        'id' => $r->id, 'user_id' => $r->user_id,
                        'user_label' => $r->display_name ?: $r->full_name,
                        'user_email' => $r->user_email,
                        'full_name' => $r->full_name, 'role_title' => $r->role_title, 'phone' => $r->phone,
                        'specialties' => $specs, 'other_specialty' => $r->other_specialty,
                        'hours_per_day' => $r->hours_per_day, 'capacity' => $r->capacity,
                        'base_price' => $r->base_price, 'resume' => $r->resume,
                        'base_word_count' => $r->base_word_count, 'increment_words' => $r->increment_words,
                        'increment_percent' => $r->increment_percent,
                    ] ) );
                ?>
                <tr>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0;color:#9ca3af;font-size:11px"><?= $idx + 1 ?></td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0">
                        <div class="sc-avatar-cell" data-userid="<?= (int) $r->user_id ?>">
                            <img src="<?= esc_url( $avatar_url ) ?>" class="sc-avatar-img" width="40" height="40" style="border-radius:10px;object-fit:cover;display:block;margin-bottom:4px">
                            <label style="cursor:pointer;color:#0073aa;font-size:10px">
                                تغییر عکس
                                <input type="file" accept="image/*" class="sc-avatar-input" style="display:none">
                            </label>
                            <span class="sc-avatar-status" style="font-size:10px;color:#888"></span>
                        </div>
                    </td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0"><strong><?= esc_html( $r->full_name ) ?></strong></td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0;color:#6b7280"><?= esc_html( $r->role_title ?: 'نویسنده و تسهیلگر' ) ?></td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0;direction:ltr;text-align:right"><?= esc_html( $r->phone ) ?></td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0;font-size:11px;color:#6b7280"><?= esc_html( $r->user_email ?: '—' ) ?></td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0">
                        <?php foreach ( $specs as $i => $s ) :
                            $c = $CHIP_COLORS[ $i % count( $CHIP_COLORS ) ];
                            [ $bg, $fg ] = explode( ':', $c );
                        ?>
                        <span style="display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;margin:2px;background:<?= $bg ?>;color:<?= $fg ?>"><?= esc_html( $s ) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0"><?= esc_html( $r->hours_per_day ) ?></td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0;max-width:140px">
                        <?php if ( $r->resume ) : ?>
                            <button type="button" class="button button-small" onclick="scShowResume(<?= (int) $r->id ?>)">مشاهده</button>
                            <div id="sc-resume-<?= (int) $r->id ?>" style="display:none"><?= esc_html( $r->resume ) ?></div>
                        <?php else : ?>
                            <span style="color:#9ca3af">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0;font-size:11px;color:#9ca3af;white-space:nowrap"><?= esc_html( $r->created_at ) ?></td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0;white-space:nowrap">
                        <button type="button" class="button button-small" onclick='scEditRecord(<?= $row_json ?>)'>✏️ ویرایش</button>
                        <form method="post" action="<?= esc_url( $post_url ) ?>" style="display:inline" onsubmit="return confirm('این رکورد حذف شود؟');">
                            <?php wp_nonce_field( 'ta_sc_form_delete_' . $r->id ); ?>
                            <input type="hidden" name="action" value="ta_sc_form_delete">
                            <input type="hidden" name="form_id" value="<?= (int) $r->id ?>">
                            <button type="submit" class="button button-small button-link-delete">🗑️</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- مودال افزودن/ویرایش رکورد -->
        <div id="sc-form-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center">
            <div style="background:#fff;border-radius:10px;padding:24px;width:640px;max-width:94vw;max-height:88vh;overflow-y:auto">
                <h2 id="sc-form-modal-title" style="margin-top:0">افزودن رکورد جدید</h2>
                <form method="post" action="<?= esc_url( $post_url ) ?>">
                    <?php wp_nonce_field( 'ta_sc_form_save' ); ?>
                    <input type="hidden" name="action" value="ta_sc_form_save">
                    <input type="hidden" name="form_id" id="sc-f-id" value="0">
                    <input type="hidden" name="user_id" id="sc-f-user-id" value="">

                    <table class="form-table" style="direction:rtl">
                        <tr>
                            <th style="width:130px">کاربر <span style="color:red">*</span></th>
                            <td>
                                <div style="position:relative">
                                    <input type="text" id="sc-user-search" placeholder="نام، شماره همراه یا ایمیل..." autocomplete="off" style="min-width:340px;padding:5px 10px;border:1px solid #8c8f94;border-radius:3px">
                                    <div id="sc-user-results" style="display:none;position:absolute;right:0;top:100%;min-width:340px;max-height:220px;overflow-y:auto;background:#fff;border:1px solid #ccc;border-top:none;z-index:100001;box-shadow:0 4px 12px rgba(0,0,0,.12)"></div>
                                </div>
                                <div id="sc-user-selected" style="margin-top:6px;color:#166534;font-size:12px"></div>
                            </td>
                        </tr>
                        <tr><th>نام و نام‌خانوادگی *</th><td><input type="text" name="full_name" id="sc-f-full-name" class="regular-text" required></td></tr>
                        <tr><th>شغل / نقش</th><td><input type="text" name="role_title" id="sc-f-role-title" class="regular-text" placeholder="نویسنده و تسهیلگر"><p class="description" style="margin:4px 0 0">در صورت خالی بودن، «نویسنده و تسهیلگر» نمایش داده می‌شود. همین متن روی کارت و مودال «اطلاعات بیشتر» صفحهٔ نقد آثار نشان داده می‌شود.</p></td></tr>
                        <tr><th>شماره تماس *</th><td><input type="text" name="phone" id="sc-f-phone" class="regular-text" dir="ltr" required></td></tr>
                        <tr>
                            <th>تخصص‌ها</th>
                            <td>
                                <?php foreach ( $specialties as $s ) : ?>
                                <label style="display:inline-block;margin:2px 8px 2px 0">
                                    <input type="checkbox" name="specialties[]" class="sc-f-spec" value="<?= esc_attr( $s->name ) ?>"> <?= esc_html( $s->name ) ?>
                                </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <tr><th>سایر موارد</th><td><textarea name="other_specialty" id="sc-f-other" rows="2" class="large-text"></textarea></td></tr>
                        <tr><th>ساعات در روز *</th><td><input type="text" name="hours_per_day" id="sc-f-hours" class="regular-text" required></td></tr>
                        <tr><th>ظرفیت</th><td><input type="number" min="0" name="capacity" id="sc-f-capacity" class="small-text"></td></tr>
                        <tr><th>قیمت پایه</th><td><input type="number" min="0" step="0.01" name="base_price" id="sc-f-price" class="regular-text"> تومان</td></tr>
                        <tr><th>تعداد کلمه پایه</th><td><input type="number" min="0" name="base_word_count" id="sc-f-base-words" class="regular-text" placeholder="مثلاً 3000"></td></tr>
                        <tr><th>پله افزایش (کلمه)</th><td><input type="number" min="0" name="increment_words" id="sc-f-inc-words" class="regular-text" placeholder="مثلاً 500"></td></tr>
                        <tr><th>درصد افزایش هر پله</th><td><input type="number" min="0" step="0.01" name="increment_percent" id="sc-f-inc-percent" class="regular-text" placeholder="مثلاً 10"> %</td></tr>
                        <tr><th>رزومه</th><td><textarea name="resume" id="sc-f-resume" rows="4" class="large-text" placeholder="متن رزومه..."></textarea></td></tr>
                    </table>

                    <p style="margin-top:16px">
                        <button type="submit" class="button button-primary">ذخیره</button>
                        <button type="button" class="button" onclick="scCloseFormModal()">انصراف</button>
                    </p>
                </form>
            </div>
        </div>

        <script>
        var scAjax  = '<?= esc_js( $ajax_url ) ?>';
        var scNonce = '<?= esc_js( $admin_nonce ) ?>';

        function scOpenFormModal(){
            document.getElementById('sc-form-modal-title').textContent = 'افزودن رکورد جدید';
            document.getElementById('sc-f-id').value = 0;
            document.getElementById('sc-f-user-id').value = '';
            document.getElementById('sc-user-search').value = '';
            document.getElementById('sc-user-search').disabled = false;
            document.getElementById('sc-user-selected').textContent = '';
            ['full-name','role-title','phone','other','hours','capacity','price','resume','base-words','inc-words','inc-percent'].forEach(function(k){
                document.getElementById('sc-f-'+k).value = '';
            });
            document.querySelectorAll('.sc-f-spec').forEach(function(cb){ cb.checked = false; });
            document.getElementById('sc-form-modal').style.display = 'flex';
        }
        function scCloseFormModal(){ document.getElementById('sc-form-modal').style.display = 'none'; }

        function scEditRecord(r){
            document.getElementById('sc-form-modal-title').textContent = 'ویرایش رکورد';
            document.getElementById('sc-f-id').value = r.id;
            document.getElementById('sc-f-user-id').value = r.user_id;
            document.getElementById('sc-user-search').value = r.user_label || '';
            document.getElementById('sc-user-search').disabled = true; // کاربر رکورد موجود قابل تغییر نیست
            document.getElementById('sc-user-selected').textContent = r.user_email || '';
            document.getElementById('sc-f-full-name').value = r.full_name || '';
            document.getElementById('sc-f-role-title').value = r.role_title || '';
            document.getElementById('sc-f-phone').value     = r.phone || '';
            document.getElementById('sc-f-other').value     = r.other_specialty || '';
            document.getElementById('sc-f-hours').value     = r.hours_per_day || '';
            document.getElementById('sc-f-capacity').value  = r.capacity ?? '';
            document.getElementById('sc-f-price').value     = r.base_price ?? '';
            document.getElementById('sc-f-base-words').value = r.base_word_count ?? '';
            document.getElementById('sc-f-inc-words').value  = r.increment_words ?? '';
            document.getElementById('sc-f-inc-percent').value = r.increment_percent ?? '';
            document.getElementById('sc-f-resume').value    = r.resume || '';
            var specs = r.specialties || [];
            document.querySelectorAll('.sc-f-spec').forEach(function(cb){ cb.checked = specs.indexOf(cb.value) !== -1; });
            document.getElementById('sc-form-modal').style.display = 'flex';
        }

        function scShowResume(id){
            var txt = document.getElementById('sc-resume-'+id).textContent;
            alert(txt);
        }

        // جستجوی کاربر برای رکورد جدید
        var scSearchT = null;
        document.getElementById('sc-user-search').addEventListener('input', function(){
            clearTimeout(scSearchT);
            var q = this.value.trim();
            var box = document.getElementById('sc-user-results');
            if (q.length < 2) { box.style.display = 'none'; return; }
            scSearchT = setTimeout(function(){
                fetch(scAjax, {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: 'action=ta_search_users&nonce=' + scNonce + '&q=' + encodeURIComponent(q)
                }).then(r=>r.json()).then(function(res){
                    box.innerHTML = '';
                    if (!res.success || !res.data.length){
                        box.innerHTML = '<div style="padding:8px 12px;color:#888;font-size:12px">نتیجه‌ای یافت نشد.</div>';
                        box.style.display = 'block'; return;
                    }
                    res.data.forEach(function(u){
                        var row = document.createElement('div');
                        row.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:12px;border-bottom:1px solid #f0f0f0';
                        row.innerHTML = '<strong>'+u.name+'</strong><br><span style="color:#888">'+u.email+'</span>';
                        row.addEventListener('click', function(){
                            document.getElementById('sc-f-user-id').value = u.id;
                            document.getElementById('sc-user-search').value = u.name;
                            document.getElementById('sc-user-selected').textContent = u.email;
                            if (!document.getElementById('sc-f-full-name').value) document.getElementById('sc-f-full-name').value = u.name;
                            if (!document.getElementById('sc-f-phone').value && u.phone) document.getElementById('sc-f-phone').value = u.phone;
                            box.style.display = 'none';
                        });
                        box.appendChild(row);
                    });
                    box.style.display = 'block';
                });
            }, 300);
        });
        document.addEventListener('click', function(e){
            var box = document.getElementById('sc-user-results');
            if (box && !document.getElementById('sc-user-search').contains(e.target)) box.style.display = 'none';
        });

        // آپلود عکس پروفایل
        document.addEventListener('change', function(e){
            var input = e.target.closest('.sc-avatar-input');
            if (!input) return;
            var cell   = input.closest('.sc-avatar-cell');
            var userId = cell.dataset.userid;
            var status = cell.querySelector('.sc-avatar-status');
            if (!userId) { status.textContent = 'کاربری برای این رکورد یافت نشد'; return; }
            var file = input.files[0];
            if (!file) return;
            var fd = new FormData();
            fd.append('action', 'ta_sc_admin_avatar_upload');
            fd.append('nonce', scNonce);
            fd.append('user_id', userId);
            fd.append('file', file);
            status.textContent = '⏳ در حال آپلود...';
            fetch(scAjax, { method:'POST', body: fd }).then(r=>r.json()).then(function(res){
                if (res.success){
                    cell.querySelector('.sc-avatar-img').src = res.data.url;
                    status.textContent = '✅ ذخیره شد';
                } else {
                    status.textContent = '❌ ' + (res.data && res.data.message ? res.data.message : 'خطا');
                }
                setTimeout(function(){ status.textContent=''; }, 3000);
            });
        });
        </script>
        <?php
    }

    private function render_field_editor( int $form_id, string $field, $value, string $nonce ): void {
        $step = in_array( $field, [ 'base_price', 'increment_percent' ], true ) ? '0.01' : '1';
        ?>
        <div class="sc-field-editor" data-form-id="<?= $form_id ?>" data-field="<?= esc_attr( $field ) ?>" style="display:flex;align-items:center;gap:4px">
            <input type="number" class="sc-field-input" min="0" step="<?= $step ?>" value="<?= esc_attr( $value ?? '' ) ?>" style="width:80px;padding:2px 4px;font-size:12px">
            <button type="button" class="button button-small sc-field-save" title="ذخیره">💾</button>
            <button type="button" class="button button-small sc-field-history-btn" title="تاریخچه">🕘</button>
            <span class="sc-field-status" style="font-size:11px"></span>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تب: ظرفیت و قیمت‌گذاری (بعد از «فرم‌های ثبت‌شده»)
    // ─────────────────────────────────────────────────────────────────────────

    private function render_pricing_tab(): void {
        $table = TA_Database::table( 'story_critique_forms' );
        $rows  = TA_Database::get_results(
            "SELECT scf.id, scf.full_name, scf.capacity, scf.base_price, scf.base_word_count,
                    scf.increment_words, scf.increment_percent, scf.specialties, u.user_email
             FROM {$table} scf
             LEFT JOIN {$GLOBALS['wpdb']->users} u ON u.ID = scf.user_id
             ORDER BY scf.full_name ASC"
        );

        $admin_nonce = wp_create_nonce( 'ta_admin_nonce' );
        $ajax_url    = admin_url( 'admin-ajax.php' );
        $post_url    = admin_url( 'admin-post.php' );
        ?>
        <div class="notice notice-info" style="padding:10px 14px">
            <p style="margin:0">
                ظرفیت، قیمت پایه و پارامترهای قیمت‌گذاری پلکانی بر اساس تعداد کلمه برای هر استاد تسهیلگر.
                با 💾 مقدار جدید را ذخیره و با 🕘 تاریخچهٔ تغییرات هر فیلد را مشاهده کنید.
                «تعداد کلمه پایه»، «پله افزایش» و «درصد افزایش» اختیاری‌اند؛ اگر خالی بمانند فقط قیمت پایه (بدون پله‌بندی) اعمال می‌شود.
                همچنین برای هر تخصص می‌توانید قیمت‌گذاری مستقل تعریف کنید (دکمهٔ «💰 قیمت تخصص‌ها» در هر ردیف) — اگر برای یک تخصص چیزی ثبت نشود، همان قیمت پایهٔ استاد تسهیلگر برایش استفاده می‌شود.
            </p>
        </div>

        <!-- سقف و دسترسی تغییر هر ستون (سراسری برای همهٔ استاد تسهیلگرها) -->
        <div style="border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;margin-bottom:16px;background:#fafafa">
            <h3 style="margin:0 0 10px;font-size:14px">⚙️ سقف و دسترسی ویرایش هر ستون (سراسری)</h3>
            <form method="post" action="<?= esc_url( $post_url ) ?>">
                <?php wp_nonce_field( 'ta_sc_pricing_limits_save' ); ?>
                <input type="hidden" name="action" value="ta_sc_pricing_limits_save">
                <table class="widefat" style="max-width:760px;background:#fff">
                    <thead><tr><th>ستون</th><th>سقف (خالی = بدون سقف)</th><th>دسترسی تغییر برای استاد تسهیلگرها</th></tr></thead>
                    <tbody>
                    <?php foreach ( self::PRICING_FIELDS as $field => $label ) :
                        $cap      = self::pricing_field_cap( $field );
                        $editable = self::pricing_field_editable( $field );
                    ?>
                    <tr>
                        <td><strong><?= esc_html( $label ) ?></strong></td>
                        <td><input type="number" step="0.01" min="0" name="cap_<?= esc_attr( $field ) ?>" value="<?= $cap !== null ? esc_attr( $cap ) : '' ?>" style="width:140px"></td>
                        <td><label><input type="checkbox" name="editable_<?= esc_attr( $field ) ?>" value="1" <?php checked( $editable ); ?>> استاد تسهیلگر می‌تواند خودش تغییر دهد</label></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="submit" class="button button-primary">ذخیره تنظیمات ستون‌ها</button></p>
            </form>
        </div>

        <?php if ( empty( $rows ) ) : ?>
            <div class="notice notice-info"><p>هنوز فرمی ثبت نشده است.</p></div>
        <?php else : ?>
        <div class="ta-sc-wrap" style="border:1px solid #e5e7eb;border-radius:8px;overflow-x:auto">
            <table class="ta-sc-table widefat" style="width:100%;border-collapse:collapse;font-size:13px;background:#fff">
                <thead><tr>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">#</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">نام و نام‌خانوادگی</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">ایمیل</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">ظرفیت</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">قیمت پایه</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">تعداد کلمه پایه</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">پله افزایش (کلمه)</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">درصد افزایش هر پله</th>
                    <th style="padding:10px 12px;text-align:right;background:#f8f9fa;border-bottom:2px solid #dee2e6">قیمت تخصص‌ها</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $rows as $idx => $r ) :
                    $specs = $r->specialties ? json_decode( $r->specialties, true ) : [];
                    $specs = is_array( $specs ) ? array_values( $specs ) : [];
                ?>
                <tr>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0;color:#9ca3af;font-size:11px"><?= $idx + 1 ?></td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0"><strong><?= esc_html( $r->full_name ) ?></strong></td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0;font-size:11px;color:#6b7280"><?= esc_html( $r->user_email ?: '—' ) ?></td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0">
                        <?php $this->render_field_editor( (int) $r->id, 'capacity', $r->capacity, $admin_nonce ); ?>
                    </td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0">
                        <?php $this->render_field_editor( (int) $r->id, 'base_price', $r->base_price, $admin_nonce ); ?>
                    </td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0">
                        <?php $this->render_field_editor( (int) $r->id, 'base_word_count', $r->base_word_count, $admin_nonce ); ?>
                    </td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0">
                        <?php $this->render_field_editor( (int) $r->id, 'increment_words', $r->increment_words, $admin_nonce ); ?>
                    </td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0">
                        <?php $this->render_field_editor( (int) $r->id, 'increment_percent', $r->increment_percent, $admin_nonce ); ?>
                    </td>
                    <td style="padding:9px 12px;border-bottom:1px solid #f0f0f0">
                        <?php if ( ! empty( $specs ) ) : ?>
                        <button type="button" class="button button-small sc-spec-price-btn"
                                data-form-id="<?= (int) $r->id ?>" data-name="<?= esc_attr( $r->full_name ) ?>"
                                data-specs='<?= esc_attr( wp_json_encode( $specs ) ) ?>'>💰 قیمت تخصص‌ها</button>
                        <?php else : ?>
                            <span style="color:#9ca3af;font-size:11px">بدون تخصص</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif;

        $this->render_field_editor_assets( $ajax_url, $admin_nonce );
        $this->render_specialty_pricing_modal( $ajax_url, $admin_nonce );
    }

    /**
     * مودال قیمت‌گذاری مستقل هر تخصص برای یک استاد تسهیلگر. اگر برای تخصصی چیزی
     * ثبت نشود، محاسبهٔ قیمت (calc_price در TA_Critique_Order) به‌طور خودکار
     * از قیمت‌پایهٔ همان استاد تسهیلگر استفاده می‌کند.
     */
    private function render_specialty_pricing_modal( string $ajax_url, string $nonce ): void {
        ?>
        <div id="sc-spec-price-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center">
            <div style="background:#fff;border-radius:10px;padding:22px;width:560px;max-width:94vw;max-height:85vh;overflow-y:auto">
                <h3 style="margin-top:0" id="sc-spec-price-title">قیمت‌گذاری تخصص‌ها</h3>
                <p style="font-size:12px;color:#6b7280;margin-top:-8px">هر فیلد اختیاری است — اگر خالی بماند، برای همان تخصص از قیمت پایهٔ استاد تسهیلگر استفاده می‌شود.</p>
                <div id="sc-spec-price-body">در حال بارگذاری…</div>
                <p style="margin-top:16px">
                    <button type="button" class="button button-primary" id="sc-spec-price-save-all">💾 ذخیرهٔ همه</button>
                    <button type="button" class="button" onclick="document.getElementById('sc-spec-price-modal').style.display='none'">بستن</button>
                    <span id="sc-spec-price-status" style="font-size:12px;margin-right:8px"></span>
                </p>
            </div>
        </div>
        <script>
        (function(){
            var ajaxUrl = '<?= esc_js( $ajax_url ) ?>';
            var nonce   = '<?= esc_js( $nonce ) ?>';
            var currentFormId = 0;

            function fieldRow(spec, data){
                data = data || {};
                return '<div class="sc-spec-price-row" data-spec="' + spec.replace(/"/g,'&quot;') + '" style="border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin-bottom:10px">' +
                    '<strong style="display:block;margin-bottom:6px">' + spec + '</strong>' +
                    '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px">' +
                    '<label style="font-size:11px">قیمت پایه<br><input type="number" step="0.01" class="sc-sp-base-price" style="width:100%" value="' + (data.base_price ?? '') + '"></label>' +
                    '<label style="font-size:11px">کلمهٔ پایه<br><input type="number" class="sc-sp-base-words" style="width:100%" value="' + (data.base_word_count ?? '') + '"></label>' +
                    '<label style="font-size:11px">پلهٔ افزایش<br><input type="number" class="sc-sp-inc-words" style="width:100%" value="' + (data.increment_words ?? '') + '"></label>' +
                    '<label style="font-size:11px">درصد افزایش<br><input type="number" step="0.01" class="sc-sp-inc-percent" style="width:100%" value="' + (data.increment_percent ?? '') + '"></label>' +
                    '</div></div>';
            }

            document.addEventListener('click', function(e){
                var btn = e.target.closest('.sc-spec-price-btn');
                if (!btn) return;
                currentFormId = parseInt(btn.dataset.formId, 10);
                var specs = JSON.parse(btn.dataset.specs || '[]');
                document.getElementById('sc-spec-price-title').textContent = '💰 قیمت تخصص‌ها — ' + btn.dataset.name;
                document.getElementById('sc-spec-price-body').innerHTML = 'در حال بارگذاری…';
                document.getElementById('sc-spec-price-modal').style.display = 'flex';

                fetch(ajaxUrl, {
                    method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: 'action=ta_sc_admin_specialty_pricing_get&nonce=' + encodeURIComponent(nonce) + '&form_id=' + currentFormId
                }).then(r => r.json()).then(function(res){
                    var existing = (res.success && res.data) ? res.data : {};
                    var html = specs.map(function(s){ return fieldRow(s, existing[s]); }).join('');
                    document.getElementById('sc-spec-price-body').innerHTML = html || '<p>تخصصی ثبت نشده.</p>';
                });
            });

            document.getElementById('sc-spec-price-save-all').addEventListener('click', function(){
                var status = document.getElementById('sc-spec-price-status');
                status.textContent = '⏳ در حال ذخیره…';
                var rows = document.querySelectorAll('.sc-spec-price-row');
                var items = [];
                rows.forEach(function(row){
                    items.push({
                        specialty: row.dataset.spec,
                        base_price: row.querySelector('.sc-sp-base-price').value,
                        base_word_count: row.querySelector('.sc-sp-base-words').value,
                        increment_words: row.querySelector('.sc-sp-inc-words').value,
                        increment_percent: row.querySelector('.sc-sp-inc-percent').value
                    });
                });
                fetch(ajaxUrl, {
                    method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: 'action=ta_sc_admin_specialty_pricing_save&nonce=' + encodeURIComponent(nonce) +
                          '&form_id=' + currentFormId + '&items=' + encodeURIComponent(JSON.stringify(items))
                }).then(r => r.json()).then(function(res){
                    status.textContent = res.success ? '✅ ذخیره شد' : '❌ خطا در ذخیره';
                    setTimeout(function(){ status.textContent=''; }, 2500);
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * مودال تاریخچه + اسکریپت ذخیره/تاریخچهٔ inline برای فیلدهای ظرفیت/قیمت‌گذاری.
     * جدا شده تا فقط در تب‌هایی که از render_field_editor() استفاده می‌کنند بارگذاری شود.
     */
    private function render_field_editor_assets( string $ajax_url, string $nonce ): void {
        ?>
        <div id="sc-history-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center">
            <div style="background:#fff;border-radius:10px;padding:20px;width:420px;max-width:92vw;max-height:80vh;overflow-y:auto">
                <h3 style="margin-top:0" id="sc-history-title">تاریخچه</h3>
                <div id="sc-history-body" style="font-size:13px"></div>
                <p><button type="button" class="button" onclick="document.getElementById('sc-history-modal').style.display='none'">بستن</button></p>
            </div>
        </div>
        <script>
        (function(){
        var scAjax  = '<?= esc_js( $ajax_url ) ?>';
        var scNonce = '<?= esc_js( $nonce ) ?>';

        document.addEventListener('click', function(e){
            var btn = e.target.closest('.sc-field-save');
            if (btn){
                var wrap  = btn.closest('.sc-field-editor');
                var formId= wrap.dataset.formId;
                var field = wrap.dataset.field;
                var input = wrap.querySelector('.sc-field-input');
                var status= wrap.querySelector('.sc-field-status');
                status.textContent = '⏳';
                fetch(scAjax, {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body: 'action=ta_sc_admin_field_update&nonce='+scNonce+'&form_id='+formId+'&field='+field+'&value='+encodeURIComponent(input.value)
                }).then(r=>r.json()).then(function(res){
                    status.textContent = res.success ? '✅' : '❌';
                    setTimeout(function(){ status.textContent=''; }, 2000);
                });
                return;
            }
            var hbtn = e.target.closest('.sc-field-history-btn');
            if (hbtn){
                var wrap2  = hbtn.closest('.sc-field-editor');
                var formId2= wrap2.dataset.formId;
                var field2 = wrap2.dataset.field;
                var title  = ({capacity:'تاریخچه ظرفیت', base_price:'تاریخچه قیمت پایه', base_word_count:'تاریخچه تعداد کلمه پایه', increment_words:'تاریخچه پله افزایش', increment_percent:'تاریخچه درصد افزایش'})[field2] || 'تاریخچه';
                document.getElementById('sc-history-title').textContent = title;
                var body = document.getElementById('sc-history-body');
                body.innerHTML = 'در حال بارگذاری...';
                document.getElementById('sc-history-modal').style.display = 'flex';
                fetch(scAjax, {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body: 'action=ta_sc_admin_field_history&nonce='+scNonce+'&form_id='+formId2+'&field='+field2
                }).then(r=>r.json()).then(function(res){
                    if (!res.success || !res.data.length){ body.innerHTML = '<p style="color:#888">تغییری ثبت نشده است.</p>'; return; }
                    var html = '<table style="width:100%;font-size:12px;border-collapse:collapse">';
                    res.data.forEach(function(l){
                        html += '<tr><td style="padding:4px;border-bottom:1px solid #eee">'+(l.old_value ?? '—')+' ← '+(l.new_value ?? '—')+'</td>'
                              + '<td style="padding:4px;border-bottom:1px solid #eee;color:#888">'+(l.actor_type==='admin'?'ادمین':'استاد تسهیلگر')+' / '+l.actor_name+'</td>'
                              + '<td style="padding:4px;border-bottom:1px solid #eee;color:#aaa;white-space:nowrap">'+l.created_at+'</td></tr>';
                    });
                    html += '</table>';
                    body.innerHTML = html;
                });
            }
        });
        })();
        </script>
        <?php
    }

    public function handle_form_save(): void {
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) wp_die( 'دسترسی غیرمجاز' );
        check_admin_referer( 'ta_sc_form_save' );

        $form_id = absint( $_POST['form_id'] ?? 0 );
        $user_id = absint( $_POST['user_id'] ?? 0 );

        $full_name = sanitize_text_field( $_POST['full_name'] ?? '' );
        $role_title = sanitize_text_field( $_POST['role_title'] ?? '' );
        $phone     = sanitize_text_field( $_POST['phone'] ?? '' );
        $other     = sanitize_textarea_field( $_POST['other_specialty'] ?? '' );
        $hours     = sanitize_text_field( $_POST['hours_per_day'] ?? '' );
        $resume    = sanitize_textarea_field( $_POST['resume'] ?? '' );
        $specialties_raw = $_POST['specialties'] ?? [];
        $specialties = is_array( $specialties_raw ) ? array_map( 'sanitize_text_field', $specialties_raw ) : [];

        // فیلدهای عددی قیمت‌گذاری (با کست مناسب برای هرکدام)
        $numeric_fields = [];
        foreach ( [ 'capacity', 'base_price', 'base_word_count', 'increment_words', 'increment_percent' ] as $nf ) {
            $raw = $_POST[ $nf ] ?? '';
            $numeric_fields[ $nf ] = ( $raw === '' ) ? null : ( in_array( $nf, [ 'base_price', 'increment_percent' ], true ) ? round( (float) $raw, 2 ) : absint( $raw ) );
        }

        $sc_table = TA_Database::table( 'story_critique_forms' );
        global $wpdb;

        $data = [
            'full_name'       => $full_name,
            'role_title'      => $role_title !== '' ? $role_title : null,
            'phone'           => $phone,
            'specialties'     => wp_json_encode( $specialties, JSON_UNESCAPED_UNICODE ),
            'other_specialty' => $other,
            'hours_per_day'   => $hours,
            'resume'          => $resume,
        ];

        if ( $form_id ) {
            // ── ویرایش رکورد موجود ──────────────────────────────────────
            $existing = TA_Database::get_row( "SELECT * FROM {$sc_table} WHERE id = %d", [ $form_id ] );
            if ( ! $existing ) {
                wp_redirect( admin_url( 'admin.php?page=ta-story-critique&error=1' ) );
                exit;
            }
            foreach ( $numeric_fields as $nf => $nv ) {
                $this->log_field_if_changed( $form_id, (int) $existing->ta_id, $nf, $existing->$nf, $nv );
            }
            $data = array_merge( $data, $numeric_fields );
            $wpdb->update( $sc_table, $data, [ 'id' => $form_id ] );
        } else {
            // ── رکورد جدید ───────────────────────────────────────────────
            if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
                wp_redirect( admin_url( 'admin.php?page=ta-story-critique&error_no_user=1' ) );
                exit;
            }
            $ta_id = $this->ensure_assistant( $user_id );

            $existing_form = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$sc_table} WHERE ta_id = %d", $ta_id ) );
            $data = array_merge( $data, $numeric_fields );

            if ( $existing_form ) {
                $this_existing = TA_Database::get_row( "SELECT * FROM {$sc_table} WHERE id = %d", [ $existing_form ] );
                foreach ( $numeric_fields as $nf => $nv ) {
                    $this->log_field_if_changed( (int) $existing_form, (int) $ta_id, $nf, $this_existing->$nf, $nv );
                }
                $wpdb->update( $sc_table, $data, [ 'id' => $existing_form ] );
                $form_id = (int) $existing_form;
            } else {
                $data['ta_id']   = $ta_id;
                $data['user_id'] = $user_id;
                $wpdb->insert( $sc_table, $data );
                $form_id = (int) $wpdb->insert_id;
                foreach ( $numeric_fields as $nf => $nv ) {
                    $this->log_field_if_changed( $form_id, (int) $ta_id, $nf, null, $nv );
                }
            }
        }

        wp_redirect( admin_url( 'admin.php?page=ta-story-critique&saved=1' ) );
        exit;
    }

    /** اطمینان از وجود رکورد استاد تسهیلگر برای این کاربر (در صورت نبود، ایجاد می‌شود) */
    private function ensure_assistant( int $user_id ): int {
        $ta_table = TA_Database::table( 'assistants' );
        $existing = TA_Database::get_row( "SELECT id FROM {$ta_table} WHERE user_id = %d", [ $user_id ] );
        if ( $existing ) {
            return (int) $existing->id;
        }
        $user = get_user_by( 'id', $user_id );
        if ( $user && ! in_array( 'teaching_assistant', (array) $user->roles, true ) ) {
            $user->add_role( 'teaching_assistant' );
        }
        return (int) TA_Database::insert( 'ta_assistants', [
            'user_id'    => $user_id,
            'is_active'  => 1,
            'created_at' => current_time( 'mysql' ),
        ] );
    }

    private function log_field_if_changed( int $form_id, int $ta_id, string $field, $old, $new ): void {
        $old_n = $old === null ? null : (string) $old;
        $new_n = $new === null ? null : (string) $new;
        if ( $old_n === $new_n ) return;
        TA_Database::insert( 'ta_story_critique_field_logs', [
            'form_id'    => $form_id,
            'ta_id'      => $ta_id,
            'field'      => $field,
            'old_value'  => $old_n,
            'new_value'  => $new_n,
            'actor_type' => 'admin',
            'actor_id'   => get_current_user_id(),
            'created_at' => current_time( 'mysql' ),
        ] );
    }

    public function handle_form_delete(): void {
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) wp_die( 'دسترسی غیرمجاز' );
        $form_id = absint( $_POST['form_id'] ?? 0 );
        check_admin_referer( 'ta_sc_form_delete_' . $form_id );

        if ( $form_id ) {
            TA_Database::delete( 'ta_story_critique_forms', [ 'id' => $form_id ] );
            TA_Database::delete( 'ta_story_critique_field_logs', [ 'form_id' => $form_id ] );
        }
        wp_redirect( admin_url( 'admin.php?page=ta-story-critique&deleted=1' ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تب: مدیریت تخصص‌ها
    // ─────────────────────────────────────────────────────────────────────────

    private function render_specialties_tab(): void {
        $table = TA_Database::table( 'specialties' );
        $specialties = TA_Database::get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC" );
        $post_url = admin_url( 'admin-post.php' );
        ?>
        <p><button type="button" class="button button-primary" onclick="scOpenSpecModal()">➕ افزودن تخصص جدید</button></p>

        <?php if ( empty( $specialties ) ) : ?>
            <div class="notice notice-info"><p>هنوز تخصصی ثبت نشده است.</p></div>
        <?php else : ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px">
            <?php foreach ( $specialties as $s ) :
                $img = $s->image_id ? wp_get_attachment_image_url( $s->image_id, 'medium' ) : '';
                $row_json = esc_attr( wp_json_encode( [
                    'id' => $s->id, 'name' => $s->name, 'description' => $s->description,
                    'sort_order' => $s->sort_order, 'is_active' => (int) $s->is_active,
                    'image_url' => $img,
                ] ) );
            ?>
            <div style="border:1px solid #e5e7eb;border-radius:10px;padding:14px;background:#fff;<?= $s->is_active ? '' : 'opacity:.55' ?>">
                <?php if ( $img ) : ?>
                    <img src="<?= esc_url( $img ) ?>" style="width:100%;height:120px;object-fit:cover;border-radius:8px;margin-bottom:8px">
                <?php endif; ?>
                <strong><?= esc_html( $s->name ) ?></strong>
                <?php if ( ! $s->is_active ) : ?><span style="color:#c00;font-size:11px">(غیرفعال)</span><?php endif; ?>
                <p style="font-size:12px;color:#555;max-height:60px;overflow:hidden"><?= esc_html( wp_trim_words( (string) $s->description, 20 ) ) ?></p>
                <div style="margin-top:8px">
                    <button type="button" class="button button-small" onclick='scEditSpec(<?= $row_json ?>)'>✏️ ویرایش</button>
                    <form method="post" action="<?= esc_url( $post_url ) ?>" style="display:inline" onsubmit="return confirm('این تخصص حذف شود؟');">
                        <?php wp_nonce_field( 'ta_sc_specialty_delete_' . $s->id ); ?>
                        <input type="hidden" name="action" value="ta_sc_specialty_delete">
                        <input type="hidden" name="specialty_id" value="<?= (int) $s->id ?>">
                        <button type="submit" class="button button-small button-link-delete">🗑️</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div id="sc-spec-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center">
            <div style="background:#fff;border-radius:10px;padding:24px;width:520px;max-width:94vw;max-height:88vh;overflow-y:auto">
                <h2 id="sc-spec-modal-title" style="margin-top:0">افزودن تخصص جدید</h2>
                <form method="post" action="<?= esc_url( $post_url ) ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'ta_sc_specialty_save' ); ?>
                    <input type="hidden" name="action" value="ta_sc_specialty_save">
                    <input type="hidden" name="specialty_id" id="sc-s-id" value="0">
                    <table class="form-table" style="direction:rtl">
                        <tr><th style="width:110px">نام *</th><td><input type="text" name="name" id="sc-s-name" class="regular-text" required></td></tr>
                        <tr><th>عکس</th><td>
                            <input type="file" name="image" accept="image/*">
                            <div id="sc-s-current-img" style="margin-top:6px"></div>
                        </td></tr>
                        <tr><th>توضیحات</th><td><textarea name="description" id="sc-s-desc" rows="5" class="large-text"></textarea></td></tr>
                        <tr><th>ترتیب نمایش</th><td><input type="number" name="sort_order" id="sc-s-order" value="0" class="small-text"></td></tr>
                        <tr><th>فعال</th><td><label><input type="checkbox" name="is_active" id="sc-s-active" value="1" checked> نمایش در فرم نقد آثار</label></td></tr>
                    </table>
                    <p><button type="submit" class="button button-primary">ذخیره</button>
                       <button type="button" class="button" onclick="document.getElementById('sc-spec-modal').style.display='none'">انصراف</button></p>
                </form>
            </div>
        </div>

        <script>
        function scOpenSpecModal(){
            document.getElementById('sc-spec-modal-title').textContent = 'افزودن تخصص جدید';
            document.getElementById('sc-s-id').value = 0;
            document.getElementById('sc-s-name').value = '';
            document.getElementById('sc-s-desc').value = '';
            document.getElementById('sc-s-order').value = 0;
            document.getElementById('sc-s-active').checked = true;
            document.getElementById('sc-s-current-img').innerHTML = '';
            document.getElementById('sc-spec-modal').style.display = 'flex';
        }
        function scEditSpec(s){
            document.getElementById('sc-spec-modal-title').textContent = 'ویرایش تخصص';
            document.getElementById('sc-s-id').value = s.id;
            document.getElementById('sc-s-name').value = s.name || '';
            document.getElementById('sc-s-desc').value = s.description || '';
            document.getElementById('sc-s-order').value = s.sort_order || 0;
            document.getElementById('sc-s-active').checked = !!s.is_active;
            document.getElementById('sc-s-current-img').innerHTML = s.image_url ? '<img src="'+s.image_url+'" style="width:80px;border-radius:6px">' : '';
            document.getElementById('sc-spec-modal').style.display = 'flex';
        }
        </script>
        <?php
    }

    public function handle_specialty_save(): void {
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) wp_die( 'دسترسی غیرمجاز' );
        check_admin_referer( 'ta_sc_specialty_save' );

        $spec_id     = absint( $_POST['specialty_id'] ?? 0 );
        $name        = sanitize_text_field( $_POST['name'] ?? '' );
        $description = sanitize_textarea_field( $_POST['description'] ?? '' );
        $sort_order  = absint( $_POST['sort_order'] ?? 0 );
        $is_active   = ! empty( $_POST['is_active'] ) ? 1 : 0;

        if ( ! $name ) {
            wp_redirect( admin_url( 'admin.php?page=ta-story-critique&sc_tab=specialties&error=1' ) );
            exit;
        }

        $table = TA_Database::table( 'specialties' );
        global $wpdb;

        $dup = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE name = %s AND id != %d", $name, $spec_id
        ) );
        if ( $dup ) {
            wp_redirect( admin_url( 'admin.php?page=ta-story-critique&sc_tab=specialties&error_dup_name=1' ) );
            exit;
        }

        // نام قبلیِ تخصص را قبل از ذخیره نگه می‌داریم تا در صورت تغییر، همه‌جا cascade شود
        $old_name = $spec_id ? $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table} WHERE id = %d", $spec_id ) ) : null;

        $data = [
            'name'        => $name,
            'description' => $description,
            'sort_order'  => $sort_order,
            'is_active'   => $is_active,
        ];

        if ( ! empty( $_FILES['image'] ) && empty( $_FILES['image']['error'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attachment_id = media_handle_upload( 'image', 0 );
            if ( ! is_wp_error( $attachment_id ) ) {
                $data['image_id'] = $attachment_id;
            }
        }

        if ( $spec_id ) {
            $wpdb->update( $table, $data, [ 'id' => $spec_id ] );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table, $data );
        }

        // تب «مدیریت تخصص‌ها» مرجع اصلیِ عنوان تخصص است؛ در صورت تغییر نام،
        // همان تغییر را در جاهایی که پیش‌تر یک کپیِ متنی از نام تخصص ذخیره
        // کرده‌اند (فرم‌های ثبت‌شدهٔ استاد تسهیلگران و سفارش‌ها) هم اعمال می‌کنیم
        // تا همه‌جا با تب مدیریت تخصص‌ها یکسان بماند.
        if ( $spec_id && $old_name && $old_name !== $name ) {
            self::cascade_specialty_rename( $old_name, $name );
        }

        wp_redirect( admin_url( 'admin.php?page=ta-story-critique&sc_tab=specialties&spec_saved=1' ) );
        exit;
    }

    /**
     * وقتی نام یک تخصص در تب «مدیریت تخصص‌ها» تغییر می‌کند، همان تغییر را در
     * تمام رکوردهایی که پیش‌تر نام تخصص را به‌صورت رشتهٔ متنی (نه شناسه) کپی
     * کرده‌اند اعمال می‌کند، تا تب مدیریت تخصص‌ها همیشه مرجع واحد بماند:
     *  - ستون JSON «specialties» در جدول فرم‌های ثبت‌شدهٔ استاد تسهیلگران
     *  - ستون «specialty» در جدول سفارش‌های نقد آثار
     */
    private static function cascade_specialty_rename( string $old_name, string $new_name ): void {
        global $wpdb;

        // ۱) فرم‌های ثبت‌شدهٔ استاد تسهیلگران — ستون specialties یک آرایهٔ JSON از نام‌هاست
        $forms_table = TA_Database::table( 'story_critique_forms' );
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT id, specialties FROM {$forms_table} WHERE specialties LIKE %s", '%' . $wpdb->esc_like( $old_name ) . '%' )
        );
        foreach ( $rows as $row ) {
            $specs = json_decode( $row->specialties, true );
            if ( ! is_array( $specs ) ) {
                continue;
            }
            $changed = false;
            foreach ( $specs as $i => $s ) {
                if ( $s === $old_name ) {
                    $specs[ $i ] = $new_name;
                    $changed = true;
                }
            }
            if ( $changed ) {
                $wpdb->update(
                    $forms_table,
                    [ 'specialties' => wp_json_encode( $specs, JSON_UNESCAPED_UNICODE ) ],
                    [ 'id' => $row->id ]
                );
            }
        }

        // ۲) سفارش‌های نقد آثار — ستون specialty یک رشتهٔ متنی سادهٔ برابر با نام تخصص است
        $orders_table = TA_Database::table( 'critique_orders' );
        $wpdb->update(
            $orders_table,
            [ 'specialty' => $new_name ],
            [ 'specialty' => $old_name ]
        );
    }

    public function handle_specialty_delete(): void {
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) wp_die( 'دسترسی غیرمجاز' );
        $spec_id = absint( $_POST['specialty_id'] ?? 0 );
        check_admin_referer( 'ta_sc_specialty_delete_' . $spec_id );

        if ( $spec_id ) {
            TA_Database::delete( 'ta_specialties', [ 'id' => $spec_id ] );
        }
        wp_redirect( admin_url( 'admin.php?page=ta-story-critique&sc_tab=specialties&spec_deleted=1' ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تب: سفارش‌های نقد آثار (نمای ادمین بر تمام سفارش‌ها)
    // ─────────────────────────────────────────────────────────────────────────

    private function render_orders_tab(): void {
        $hide_order_files = TA_Critique_Admin_Access::is_matched_phone_user();
        $table = TA_Database::table( 'critique_orders' );
        $sc_table = TA_Database::table( 'story_critique_forms' );
        $orders = TA_Database::get_results(
            "SELECT co.*, scf.full_name AS ta_name, u.display_name AS student_name, u.user_email AS student_email
             FROM {$table} co
             LEFT JOIN {$sc_table} scf ON scf.id = co.ta_id
             LEFT JOIN {$GLOBALS['wpdb']->users} u ON u.ID = co.student_id
             ORDER BY co.created_at DESC LIMIT 500"
        );
        $status_labels = [
            'pending'      => '⏳ در انتظار استاد تسهیلگر',
            'in_progress'  => '✍️ در حال انجام',
            'delivered'    => '📬 تحویل شد',
            'ta_completed' => '🧾 تکمیل شد — منتظر تأیید هنرجو',
            'completed'    => '✅ تکمیل‌شده',
            'cancelled'    => '❌ لغو شده',
        ];
        ?>
        <p style="color:#555">تعداد کل: <strong><?= count( $orders ) ?></strong> سفارش</p>
        <?php if ( empty( $orders ) ) : ?>
            <div class="notice notice-info"><p>هنوز سفارشی ثبت نشده است.</p></div>
        <?php else : ?>
        <div style="border:1px solid #e5e7eb;border-radius:8px;overflow-x:auto">
        <table class="widefat" style="width:100%;border-collapse:collapse;font-size:13px;background:#fff">
            <thead><tr>
                <th style="padding:8px 10px;text-align:right;background:#f8f9fa">#</th>
                <th style="padding:8px 10px;text-align:right;background:#f8f9fa">سفارش WC</th>
                <th style="padding:8px 10px;text-align:right;background:#f8f9fa">هنرجو</th>
                <th style="padding:8px 10px;text-align:right;background:#f8f9fa">استاد تسهیلگر</th>
                <th style="padding:8px 10px;text-align:right;background:#f8f9fa">تخصص</th>
                <th style="padding:8px 10px;text-align:right;background:#f8f9fa">کلمات</th>
                <th style="padding:8px 10px;text-align:right;background:#f8f9fa">قیمت</th>
                <th style="padding:8px 10px;text-align:right;background:#f8f9fa">وضعیت</th>
                <?php if ( ! $hide_order_files ) : ?>
                <th style="padding:8px 10px;text-align:right;background:#f8f9fa">فایل اثر</th>
                <th style="padding:8px 10px;text-align:right;background:#f8f9fa">فایل نقد</th>
                <?php endif; ?>
                <th style="padding:8px 10px;text-align:right;background:#f8f9fa">یادداشت به ادمین</th>
                <th style="padding:8px 10px;text-align:right;background:#f8f9fa">گفتگو</th>
                <th style="padding:8px 10px;text-align:right;background:#f8f9fa">تاریخ</th>
                <th style="padding:8px 10px;text-align:right;background:#f8f9fa">عملیات</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $orders as $o ) : ?>
            <tr data-corder-id="<?= (int) $o->id ?>">
                <td style="padding:7px 10px;border-bottom:1px solid #f0f0f0"><?= (int) $o->id ?></td>
                <td style="padding:7px 10px;border-bottom:1px solid #f0f0f0">
                    <?php if ( $o->wc_order_id && function_exists( 'wc_get_order' ) ) : $wo = wc_get_order( $o->wc_order_id ); ?>
                    <a href="<?= esc_url( admin_url( 'post.php?post=' . $o->wc_order_id . '&action=edit' ) ) ?>" target="_blank">#<?= (int) $o->wc_order_id ?></a>
                    <?php if ( $wo ) : ?><br><span style="font-size:11px;color:#888"><?= esc_html( wc_get_order_status_name( $wo->get_status() ) ) ?></span><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td style="padding:7px 10px;border-bottom:1px solid #f0f0f0"><?= esc_html( $o->student_name ?: '—' ) ?><br><span style="font-size:11px;color:#888"><?= esc_html( $o->student_email ?: '' ) ?></span></td>
                <td style="padding:7px 10px;border-bottom:1px solid #f0f0f0"><?= esc_html( $o->ta_name ?: '—' ) ?></td>
                <td style="padding:7px 10px;border-bottom:1px solid #f0f0f0"><?= esc_html( $o->specialty ?: '—' ) ?></td>
                <td style="padding:7px 10px;border-bottom:1px solid #f0f0f0"><?= esc_html( $o->word_count ?: '—' ) ?></td>
                <td style="padding:7px 10px;border-bottom:1px solid #f0f0f0"><?= $o->price ? number_format( (float) $o->price ) . ' تومان' : '—' ?></td>
                <td style="padding:7px 10px;border-bottom:1px solid #f0f0f0">
                    <select class="ta-co-admin-status-select" style="font-size:12px">
                        <?php foreach ( $status_labels as $st_key => $st_label ) : ?>
                        <option value="<?= esc_attr( $st_key ) ?>" <?php selected( $o->status, $st_key ); ?>><?= esc_html( $st_label ) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <?php if ( ! $hide_order_files ) : ?><td style="padding:7px 10px;border-bottom:1px solid #f0f0f0">
                    <?php if ( $o->file_id ) : ?><a href="<?= esc_url( wp_get_attachment_url( $o->file_id ) ) ?>" target="_blank">📥</a><?php else : ?>—<?php endif; ?>
                </td>
                <td style="padding:7px 10px;border-bottom:1px solid #f0f0f0">
                    <?php if ( $o->result_file_id ) : ?><a href="<?= esc_url( wp_get_attachment_url( $o->result_file_id ) ) ?>" target="_blank">📥</a><?php else : ?>—<?php endif; ?>
                </td><?php endif; ?>
                <td style="padding:7px 10px;border-bottom:1px solid #f0f0f0;max-width:180px;font-size:12px;color:#4b5563"><?= esc_html( $o->note_to_admin ?: '—' ) ?></td>
                <td style="padding:7px 10px;border-bottom:1px solid #f0f0f0">
                    <button type="button" class="button button-small ta-co-admin-chat-toggle" data-target="ta-co-admin-chat-row-<?= (int) $o->id ?>">💬 گفتگوها</button>
                </td>
                <td style="padding:7px 10px;border-bottom:1px solid #f0f0f0;font-size:11px;color:#9ca3af;white-space:nowrap"><?= esc_html( $o->created_at ) ?></td>
                <td style="padding:7px 10px;border-bottom:1px solid #f0f0f0;white-space:nowrap">
                    <button type="button" class="button button-small button-link-delete ta-co-admin-delete-btn">🗑️ حذف</button>
                    <span class="ta-co-admin-row-msg" style="font-size:11px;margin-right:4px"></span>
                </td>
            </tr>
            <tr id="ta-co-admin-chat-row-<?= (int) $o->id ?>" class="ta-co-admin-chat-row" style="display:none">
                <td colspan="<?= $hide_order_files ? 12 : 14 ?>" style="padding:12px 16px;background:#fafafa;border-bottom:1px solid #e5e7eb">
                    <div style="display:flex;gap:16px;flex-wrap:wrap">
                        <div style="flex:1;min-width:280px">
                            <?php TA_Critique_Chat::render_box( (int) $o->id, 'admin_student', 'گفتگو با هنرجو (' . esc_html( $o->student_name ?: '' ) . ')' ); ?>
                        </div>
                        <div style="flex:1;min-width:280px">
                            <?php TA_Critique_Chat::render_box( (int) $o->id, 'admin_ta', 'گفتگو با استاد تسهیلگر (' . esc_html( $o->ta_name ?: '' ) . ')' ); ?>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <script>
        jQuery(function($){
            var ajaxUrl = '<?= esc_js( admin_url( 'admin-ajax.php' ) ) ?>';
            var nonce   = '<?= esc_js( wp_create_nonce( 'ta_admin_nonce' ) ) ?>';

            $(document).on('click', '.ta-co-admin-chat-toggle', function(){
                $('#' + $(this).data('target')).toggle();
            });

            // تغییر وضعیت سفارش
            $(document).on('change', '.ta-co-admin-status-select', function(){
                var $sel = $(this);
                var $row = $sel.closest('tr');
                var $msg = $row.find('.ta-co-admin-row-msg');
                var corderId = $row.data('corder-id');
                $sel.prop('disabled', true);
                $msg.text('⏳').css('color', '#888');
                $.post(ajaxUrl, { action:'ta_sc_admin_order_status_update', nonce:nonce, corder_id:corderId, status:$sel.val() }, function(res){
                    $sel.prop('disabled', false);
                    if (res.success) {
                        var note = (res.data && res.data.capacity_note) ? (' — ' + res.data.capacity_note) : '';
                        $msg.text('✅ ذخیره شد' + note).css('color', '#16a34a');
                    }
                    else { $msg.text('❌ ' + (res.data && res.data.message ? res.data.message : 'خطا')).css('color', '#dc2626'); }
                    setTimeout(function(){ $msg.fadeOut(400, function(){ $(this).text('').show(); }); }, 4000);
                }).fail(function(){
                    $sel.prop('disabled', false);
                    $msg.text('❌ خطای شبکه').css('color', '#dc2626');
                });
            });

            // حذف سفارش
            $(document).on('click', '.ta-co-admin-delete-btn', function(){
                var $btn = $(this);
                var $row = $btn.closest('tr');
                var corderId = $row.data('corder-id');
                if (!confirm('سفارش #' + corderId + ' برای همیشه حذف شود؟ این کار قابل بازگشت نیست؛ گفتگوها/ارزیابی‌های مرتبط حذف می‌شوند و اگر ظرفیت استاد تسهیلگر بابت این سفارش کسر شده باشد، برمی‌گردد.')) return;
                $btn.prop('disabled', true).text('در حال حذف…');
                $.post(ajaxUrl, { action:'ta_sc_admin_order_delete', nonce:nonce, corder_id:corderId }, function(res){
                    if (res.success) {
                        $row.next('.ta-co-admin-chat-row').remove();
                        $row.fadeOut(250, function(){ $(this).remove(); });
                    } else {
                        $btn.prop('disabled', false).text('🗑️ حذف');
                        alert(res.data && res.data.message ? res.data.message : 'خطا در حذف');
                    }
                }).fail(function(){
                    $btn.prop('disabled', false).text('🗑️ حذف');
                    alert('خطای شبکه');
                });
            });
        });
        </script>
        <?php endif; ?>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تب: گروه بله استاد
    // ─────────────────────────────────────────────────────────────────────────

    private function render_bale_group_tab(): void {
        $table = TA_Database::table( 'story_critique_forms' );
        $rows  = TA_Database::get_results(
            "SELECT scf.id, scf.ta_id, scf.full_name, scf.group_link, u.user_email
             FROM {$table} scf
             LEFT JOIN {$GLOBALS['wpdb']->users} u ON u.ID = scf.user_id
             ORDER BY scf.full_name ASC"
        );
        $post_url = admin_url( 'admin-post.php' );
        ?>
        <div class="notice notice-info" style="padding:10px 14px">
            <p style="margin:0">
                لینک گروه بله هر استاد اینجا ثبت می‌شود. این لینک فقط زمانی که وضعیت سفارش نقد هنرجو
                «✍️ در حال انجام» یا «📬 تحویل‌شده» باشد به همان هنرجو نمایش داده می‌شود؛ به‌محض «✅ تکمیل‌شدن» سفارش،
                لینک از دید هنرجو حذف می‌شود.
            </p>
        </div>

        <?php if ( empty( $rows ) ) : ?>
            <div class="notice notice-info"><p>هنوز هیچ استادی در سیستم نقد آثار ثبت نشده است.</p></div>
        <?php else : ?>
        <div style="border:1px solid #e5e7eb;border-radius:8px;overflow-x:auto">
            <table class="widefat" style="width:100%;border-collapse:collapse;font-size:13px;background:#fff">
                <thead><tr>
                    <th style="padding:8px 10px;text-align:right;background:#f8f9fa">استاد</th>
                    <th style="padding:8px 10px;text-align:right;background:#f8f9fa">ایمیل</th>
                    <th style="padding:8px 10px;text-align:right;background:#f8f9fa">لینک گروه بله</th>
                    <th style="padding:8px 10px;text-align:right;background:#f8f9fa">عملیات</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $rows as $r ) : ?>
                <tr>
                    <td style="padding:8px 10px;border-bottom:1px solid #f0f0f0"><strong><?= esc_html( $r->full_name ) ?></strong></td>
                    <td style="padding:8px 10px;border-bottom:1px solid #f0f0f0;font-size:11px;color:#6b7280"><?= esc_html( $r->user_email ?: '—' ) ?></td>
                    <td style="padding:8px 10px;border-bottom:1px solid #f0f0f0">
                        <form method="post" action="<?= esc_url( $post_url ) ?>" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                            <?php wp_nonce_field( 'ta_sc_group_link_save_' . $r->id ); ?>
                            <input type="hidden" name="action" value="ta_sc_group_link_save">
                            <input type="hidden" name="form_id" value="<?= (int) $r->id ?>">
                            <input type="text" name="group_link" value="<?= esc_attr( $r->group_link ) ?>" dir="ltr"
                                   placeholder="https://ble.ir/join/..." style="min-width:280px;padding:5px 8px;border:1px solid #8c8f94;border-radius:3px">
                            <button type="submit" class="button button-small">💾 ذخیره</button>
                        </form>
                    </td>
                    <td style="padding:8px 10px;border-bottom:1px solid #f0f0f0;white-space:nowrap">
                        <?php if ( $r->group_link ) : ?>
                            <a href="<?= esc_url( TA_URL_Helper::normalize( $r->group_link ) ) ?>" target="_blank" class="button button-small">مشاهده گروه</a>
                        <?php else : ?>
                            <span style="color:#9ca3af">— ثبت نشده —</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif;
    }

    public function handle_group_link_save(): void {
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) {
            wp_die( 'دسترسی غیرمجاز' );
        }
        $form_id = absint( $_POST['form_id'] ?? 0 );
        check_admin_referer( 'ta_sc_group_link_save_' . $form_id );

        $link = trim( sanitize_text_field( wp_unslash( $_POST['group_link'] ?? '' ) ) );
        $link = $link !== '' ? TA_URL_Helper::normalize( $link ) : '';

        global $wpdb;
        $wpdb->update(
            TA_Database::table( 'story_critique_forms' ),
            [ 'group_link' => $link !== '' ? $link : null ],
            [ 'id' => $form_id ]
        );

        wp_redirect( admin_url( 'admin.php?page=ta-story-critique&sc_tab=bale_group&group_link_saved=1' ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تب: ارزیابی (سازنده نظرسنجی + نتایج)
    // ─────────────────────────────────────────────────────────────────────────

    private function render_evaluation_report_tab(): void {
        global $wpdb;
        $orders=TA_Database::table('critique_orders');$forms=TA_Database::table('story_critique_forms');$users=$wpdb->users;$eval=TA_Database::table('eval_responses');$student_eval=TA_Database::table('student_eval_responses');$questions=TA_Database::table('eval_questions');$student_questions=TA_Database::table('student_eval_questions');
        $direction=sanitize_key($_GET['direction']??'student');if(!in_array($direction,['student','ta'],true))$direction='student';$search=sanitize_text_field(wp_unslash($_GET['s']??''));$order_from=sanitize_text_field(wp_unslash($_GET['order_from']??''));$order_to=sanitize_text_field(wp_unslash($_GET['order_to']??''));$eval_from=sanitize_text_field(wp_unslash($_GET['eval_from']??''));$eval_to=sanitize_text_field(wp_unslash($_GET['eval_to']??''));$show=sanitize_key($_GET['show']??'all');if(!in_array($show,['all','answered','unanswered'],true))$show='all';$paged=max(1,absint($_GET['paged']??1));$per_page=min(100,max(10,absint($_GET['per_page']??25)));$response_table=$direction==='student'?$eval:$student_eval;$question_table=$direction==='student'?$questions:$student_questions;
        $where=['1=1'];$args=[];$exists="SELECT 1 FROM {$response_table} er LEFT JOIN {$question_table} eq ON eq.id=er.question_id WHERE er.critique_order_id=o.id";
        if($search!==''){$like='%'.$wpdb->esc_like($search).'%';$where[]='(CAST(o.id AS CHAR) LIKE %s OR CAST(o.wc_order_id AS CHAR) LIKE %s OR o.specialty LIKE %s OR su.display_name LIKE %s OR ta_form.full_name LIKE %s OR EXISTS ('.$exists.' AND (eq.question LIKE %s OR er.answer LIKE %s)) )';array_push($args,$like,$like,$like,$like,$like,$like,$like);}
        if(preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$order_from)){$where[]='DATE(o.created_at)>=%s';$args[]=$order_from;}if(preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$order_to)){$where[]='DATE(o.created_at)<=%s';$args[]=$order_to;}if(preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$eval_from)){$where[]='EXISTS ('.$exists.' AND DATE(er.created_at)>=%s)';$args[]=$eval_from;}if(preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$eval_to)){$where[]='EXISTS ('.$exists.' AND DATE(er.created_at)<=%s)';$args[]=$eval_to;}
        if($show==='answered')$where[]='EXISTS ('.$exists.')';if($show==='unanswered')$where[]='NOT EXISTS ('.$exists.')';
        $where_sql=implode(' AND ',$where);$count_sql="SELECT COUNT(*) FROM {$orders} o LEFT JOIN {$users} su ON su.ID=o.student_id LEFT JOIN {$forms} ta_form ON ta_form.id=o.ta_id WHERE {$where_sql}";$total=(int)$wpdb->get_var($args?$wpdb->prepare($count_sql,$args):$count_sql);$offset=($paged-1)*$per_page;
        $sql="SELECT o.id,o.wc_order_id,o.student_id,o.ta_id,o.specialty,o.status,o.price,o.created_at,o.delivered_at,o.completed_at,o.ta_completed_at,su.display_name AS student_name,su.user_email AS student_email,ta_form.full_name AS ta_name,ta_form.user_id AS ta_user_id,COUNT(DISTINCT r.id) AS response_count,MAX(r.created_at) AS evaluation_last_at FROM {$orders} o LEFT JOIN {$response_table} r ON r.critique_order_id=o.id LEFT JOIN {$users} su ON su.ID=o.student_id LEFT JOIN {$forms} ta_form ON ta_form.id=o.ta_id WHERE {$where_sql} GROUP BY o.id ORDER BY o.created_at DESC LIMIT %d OFFSET %d";$query_args=$args;$query_args[]=$per_page;$query_args[]=$offset;$rows=$wpdb->get_results($wpdb->prepare($sql,$query_args),ARRAY_A);
        $response_map=[];$ids=array_values(array_filter(array_map(static fn($r)=>(int)$r['id'],$rows)));if($ids){$ph=implode(',',array_fill(0,count($ids),'%d'));$response_sql="SELECT r.critique_order_id,r.answer,r.created_at,q.question,q.type,q.sort_order FROM {$response_table} r LEFT JOIN {$question_table} q ON q.id=r.question_id WHERE r.critique_order_id IN ({$ph}) ORDER BY r.critique_order_id ASC,q.sort_order ASC,r.id ASC";$response_rows=$wpdb->get_results($wpdb->prepare($response_sql,$ids),ARRAY_A);foreach($response_rows as $rr){$response_order_id=(int)$rr['critique_order_id'];$response_map[$response_order_id][]=$rr;}}
        $score_map=$direction==='student'?['کاملاً'=>5,'تا حد زیادی'=>4,'تا حدی'=>3,'کم'=>2,'اصلاً'=>1]:['عالی'=>5,'خوب'=>4,'متوسط'=>3,'نیازمند بهبود'=>2,'ضعیف'=>1];$normalize=static fn($v)=>preg_replace('/[\x{200C}\x{200B}\s]+/u',' ',trim((string)$v));$person_scores=['student'=>[],'ta'=>[]];$all_person_sql="SELECT o.student_id,o.ta_id,r.answer,q.type FROM {$orders} o INNER JOIN {$response_table} r ON r.critique_order_id=o.id LEFT JOIN {$question_table} q ON q.id=r.question_id";$all_person_rows=(array)$wpdb->get_results($all_person_sql,ARRAY_A);foreach($all_person_rows as $pr){$pk=$normalize($pr['answer']);$ps=isset($score_map[$pk])?$score_map[$pk]:(is_numeric($pk)&&((float)$pk>=1)&&((float)$pk<=5)?(float)$pk:null);if($ps===null||($pr['type']??'')==='text')continue;$sid=(int)$pr['student_id'];$tid=(int)$pr['ta_id'];if($sid){$person_scores['student'][$sid]['sum']=($person_scores['student'][$sid]['sum']??0)+$ps;$person_scores['student'][$sid]['count']=($person_scores['student'][$sid]['count']??0)+1;}if($tid){$person_scores['ta'][$tid]['sum']=($person_scores['ta'][$tid]['sum']??0)+$ps;$person_scores['ta'][$tid]['count']=($person_scores['ta'][$tid]['count']??0)+1;}}$person_average=static function(array $bucket,int $id):?float{return !empty($bucket[$id]['count'])?$bucket[$id]['sum']/$bucket[$id]['count']:null;};$overall_scores=[];$answered_orders=0;foreach($rows as &$row){$rid=(int)$row['id'];$row['answers']=$response_map[$rid]??[];$row['scores']=[];foreach($row['answers'] as $ans){$key=$normalize($ans['answer']);$score=isset($score_map[$key])?$score_map[$key]:(is_numeric($key)&&((float)$key>=1)&&((float)$key<=5)?(float)$key:null);if($score!==null&&($ans['type']??'')!=='text'){$row['scores'][]=$score;$overall_scores[]=$score;}}if($row['answers'])$answered_orders++;$row['average_score']=$row['scores']?array_sum($row['scores'])/count($row['scores']):null;$row['student_avg_score']=$person_average($person_scores['student'],(int)$row['student_id']);$row['ta_avg_score']=$person_average($person_scores['ta'],(int)$row['ta_id']);}unset($row);
        $status_labels=['pending'=>'در انتظار بررسی','in_progress'=>'در حال انجام','delivered'=>'نقد تحویل‌شده','ta_completed'=>'ارزیابی استاد ثبت‌شده','completed'=>'تکمیل‌شده','cancelled'=>'لغوشده'];$is_export=isset($_GET['export'])&&$_GET['export']==='csv';
        $base=['page'=>'ta-story-critique','sc_tab'=>'evaluation_report','direction'=>$direction,'s'=>$search,'order_from'=>$order_from,'order_to'=>$order_to,'eval_from'=>$eval_from,'eval_to'=>$eval_to,'show'=>$show];$export_url=add_query_arg(array_filter(array_merge($base,['export'=>'csv'])),admin_url('admin.php'));$student_tab=add_query_arg(array_filter(array_merge($base,['direction'=>'student','paged'=>1])),admin_url('admin.php'));$ta_tab=add_query_arg(array_filter(array_merge($base,['direction'=>'ta','paged'=>1])),admin_url('admin.php'));$clear_url=add_query_arg(['page'=>'ta-story-critique','sc_tab'=>'evaluation_report','direction'=>$direction],admin_url('admin.php'));
        if($is_export){nocache_headers();header('Content-Type:text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=ta-evaluation-orders-'.$direction.'-'.gmdate('Y-m-d').'.csv');echo "\\xEF\\xBB\\xBF";$out=fopen('php://output','w');fputcsv($out,['شماره سفارش','هنرجو','استاد','تخصص','وضعیت سفارش','تعداد پاسخ','میانگین امتیاز','پاسخ‌های کامل','تاریخ سفارش','آخرین ارزیابی'],',');foreach($rows as $row){$answers=[];foreach($row['answers'] as $a)$answers[]=$a['question'].': '.$a['answer'];fputcsv($out,[$row['wc_order_id']?:$row['id'],$row['student_name'],$row['ta_name'],$row['specialty'],$status_labels[$row['status']]??$row['status'],$row['response_count'],$row['average_score']!==null?number_format((float)$row['average_score'],2):'—',implode(' | ',$answers),$row['created_at'],$row['evaluation_last_at']?:'—'],',');}fclose($out);exit;}
        $pages=max(1,(int)ceil($total/$per_page));$avg=$overall_scores?number_format_i18n(array_sum($overall_scores)/count($overall_scores),2):'—';$unanswered=max(0,$total-$answered_orders);
        ?>
        <style>.ta-report-hero{background:linear-gradient(135deg,#0f172a,#164e63 55%,#0891b2);color:#fff;border-radius:18px;padding:25px 28px;margin:0 0 20px;box-shadow:0 12px 30px rgba(8,145,178,.18)}.ta-report-hero h2{margin:0 0 7px;color:#fff;font-size:23px}.ta-report-hero p{margin:0;color:#cffafe;font-size:13px}.ta-report-hero .dashicons{float:left;font-size:58px;width:58px;height:58px;opacity:.8}.ta-report-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px}.ta-report-tabs a{background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:11px 16px;text-decoration:none;color:#334155;font-weight:700}.ta-report-tabs a.active{background:#0891b2;border-color:#0891b2;color:#fff}.ta-report-grid{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:14px;margin-bottom:20px}.ta-report-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px;border-bottom:3px solid var(--c);box-shadow:0 4px 15px rgba(15,23,42,.05)}.ta-report-card small{display:block;color:#64748b;margin-bottom:8px}.ta-report-card strong{font-size:27px;color:#0f172a}.ta-report-card .dashicons{float:left;color:var(--c);font-size:27px}.ta-report-panel{background:#fff;border:1px solid #e2e8f0;border-radius:15px;padding:18px;margin-bottom:18px;box-shadow:0 4px 15px rgba(15,23,42,.04)}.ta-report-filter{display:grid;grid-template-columns:2fr repeat(4,1fr) auto;gap:10px;align-items:end}.ta-report-filter label{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:5px}.ta-report-filter input,.ta-report-filter select{width:100%;min-height:38px}.ta-report-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px}.ta-report-table{overflow:auto}.ta-report-table table{min-width:1350px}.ta-report-table th{background:#f8fafc;color:#334155;white-space:nowrap}.ta-report-table td,.ta-report-table th{padding:11px 10px;vertical-align:top}.ta-report-table tbody tr:hover{background:#f0fdfa}.ta-order{font-weight:800;color:#0e7490}.ta-status{display:inline-flex;border-radius:99px;padding:4px 8px;font-size:11px;font-weight:700;background:#ecfeff;color:#155e75}.ta-answers{min-width:360px;max-width:560px;margin:0;padding-right:18px;line-height:1.8;color:#475569}.ta-answers li{margin-bottom:4px}.ta-pagination{display:flex;justify-content:space-between;align-items:center;margin-top:16px}.ta-pagination a,.ta-pagination strong{display:inline-block;padding:6px 10px;border:1px solid #cbd5e1;border-radius:7px;text-decoration:none}.ta-pagination strong{background:#0891b2;color:#fff;border-color:#0891b2}.ta-empty{text-align:center;color:#64748b;padding:36px!important}@media(max-width:900px){.ta-report-grid{grid-template-columns:repeat(2,1fr)}.ta-report-filter{grid-template-columns:1fr 1fr}.ta-report-filter>div:first-child{grid-column:1/-1}}@media(max-width:520px){.ta-report-grid{grid-template-columns:1fr}.ta-report-filter{grid-template-columns:1fr}.ta-report-filter>div:first-child{grid-column:auto}}</style>
        <div class="ta-report-hero"><span class="dashicons dashicons-chart-bar"></span><h2>گزارش جامع ارزیابی نقد آثار</h2><p>هر سفارش یک ردیف مستقل است؛ تمام پاسخ‌های همان سفارش بدون حذف متن نمایش داده می‌شود.</p></div><div class="ta-report-tabs"><a class="<?= $direction==='student'?'active':'' ?>" href="<?= esc_url($student_tab) ?>">🎓 ارزیابی هنرجو از استاد</a><a class="<?= $direction==='ta'?'active':'' ?>" href="<?= esc_url($ta_tab) ?>">🖋️ ارزیابی استاد از هنرجو</a></div>
        <div class="ta-report-grid"><div class="ta-report-card" style="--c:#0891b2"><span class="dashicons dashicons-clipboard"></span><small>کل سفارش‌ها</small><strong><?= number_format_i18n($total) ?></strong></div><div class="ta-report-card" style="--c:#16a34a"><span class="dashicons dashicons-yes-alt"></span><small>دارای ارزیابی</small><strong><?= number_format_i18n($answered_orders) ?></strong></div><div class="ta-report-card" style="--c:#ea580c"><span class="dashicons dashicons-minus"></span><small>بدون ارزیابی</small><strong><?= number_format_i18n($unanswered) ?></strong></div><div class="ta-report-card" style="--c:#7c3aed"><span class="dashicons dashicons-star-filled"></span><small>میانگین واقعی امتیاز</small><strong><?= esc_html($avg) ?></strong><small>از ۵</small></div></div>
        <div class="ta-report-panel"><form method="get"><input type="hidden" name="page" value="ta-story-critique"><input type="hidden" name="sc_tab" value="evaluation_report"><input type="hidden" name="direction" value="<?= esc_attr($direction) ?>"><div class="ta-report-filter"><div><label>جست‌وجو</label><input type="search" name="s" value="<?= esc_attr($search) ?>" placeholder="شماره سفارش، نام، استاد، تخصص، سؤال یا پاسخ"></div><div><label>وضعیت نمایش</label><select name="show"><option value="all" <?= selected($show,'all',false) ?>>همه سفارش‌ها</option><option value="answered" <?= selected($show,'answered',false) ?>>فقط دارای ارزیابی</option><option value="unanswered" <?= selected($show,'unanswered',false) ?>>فقط بدون ارزیابی</option></select></div><div><label>از تاریخ سفارش</label><input type="date" name="order_from" value="<?= esc_attr($order_from) ?>"></div><div><label>تا تاریخ سفارش</label><input type="date" name="order_to" value="<?= esc_attr($order_to) ?>"></div><div><label>از تاریخ ارزیابی</label><input type="date" name="eval_from" value="<?= esc_attr($eval_from) ?>"></div><div><label>تا تاریخ ارزیابی</label><input type="date" name="eval_to" value="<?= esc_attr($eval_to) ?>"></div><button class="button button-primary">اعمال فیلتر</button></div><div class="ta-report-actions"><a class="button" href="<?= esc_url($clear_url) ?>">پاک‌کردن فیلترها</a><a class="button" href="<?= esc_url($export_url) ?>">دانلود گزارش CSV</a><span style="color:#64748b;font-size:12px"><?= number_format_i18n($total) ?> سفارش مطابق فیلتر</span></div></form></div>
        <div class="ta-report-panel"><h3 style="margin:0 0 13px">فهرست سفارش‌های ارزیابی‌شده</h3><div class="ta-report-table"><table class="widefat"><thead><tr><th>سفارش</th><th>هنرجو</th><th>استاد</th><th>تخصص</th><th>وضعیت</th><th>تعداد پاسخ</th><th>میانگین</th><th>پاسخ‌های کامل همین سفارش</th><th>تاریخ سفارش</th><th>آخرین ارزیابی</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="10" class="ta-empty">سفارشی مطابق فیلترها پیدا نشد.</td></tr><?php else: foreach($rows as $row): ?><tr><td><span class="ta-order">#<?= esc_html($row['wc_order_id']?:$row['id']) ?></span><small style="display:block;color:#94a3b8">رکورد #<?= (int)$row['id'] ?></small></td><td><strong><?= esc_html($row['student_name']?:'—') ?></strong><?php if($direction==='ta' && $row['student_avg_score']!==null): ?><small style="display:block;color:#64748b">(میانگین کل: <?= esc_html(number_format_i18n((float)$row['student_avg_score'],2)) ?> از ۵)</small><?php endif; ?></td><td><strong><?= esc_html($row['ta_name']?:'—') ?></strong><?php if($direction==='student' && $row['ta_avg_score']!==null): ?><small style="display:block;color:#64748b">(میانگین کل: <?= esc_html(number_format_i18n((float)$row['ta_avg_score'],2)) ?> از ۵)</small><?php endif; ?></td><td><?= esc_html($row['specialty']?:'—') ?></td><td><span class="ta-status"><?= esc_html($status_labels[$row['status']]??$row['status']??'—') ?></span></td><td><?= number_format_i18n((int)$row['response_count']) ?></td><td><?= $row['average_score']!==null?esc_html(number_format_i18n((float)$row['average_score'],2)).' از ۵':'—' ?></td><td><?php if($row['answers']): ?><ul class="ta-answers"><?php foreach($row['answers'] as $answer): ?><li><strong><?= esc_html($answer['question']?:'سؤال') ?></strong><br><?= esc_html($answer['answer']?:'بدون پاسخ') ?></li><?php endforeach; ?></ul><?php else: ?><span style="color:#94a3b8">بدون پاسخ</span><?php endif; ?></td><td style="white-space:nowrap;color:#64748b"><?= esc_html($row['created_at']) ?></td><td style="white-space:nowrap;color:#64748b"><?= esc_html($row['evaluation_last_at']?:'—') ?></td></tr><?php endforeach; endif; ?></tbody></table></div><?php if($pages>1): ?><div class="ta-pagination"><span style="color:#64748b;font-size:12px">صفحه <?= number_format_i18n($paged) ?> از <?= number_format_i18n($pages) ?></span><div><?php for($i=1;$i<=$pages;$i++): if($i===1||$i===$pages||abs($i-$paged)<=2): $u=add_query_arg(array_filter(array_merge($base,['paged'=>$i,'per_page'=>$per_page])),admin_url('admin.php')); ?><<?= $i===$paged?'strong':'a' ?> href="<?= esc_url($u) ?>"><?= number_format_i18n($i) ?></<?= $i===$paged?'strong':'a' ?>> <?php endif; endfor; ?></div></div><?php endif; ?></div>
        <?php
    }

    private function render_evaluation_tab(): void {
        $q_table = TA_Database::table( 'eval_questions' );
        $questions = TA_Database::get_results( "SELECT * FROM {$q_table} ORDER BY sort_order ASC, id ASC" );
        $post_url = admin_url( 'admin-post.php' );
        ?>
        <style>
        .ta-eval-hero{background:linear-gradient(135deg,#24134f,#5b21b6 58%,#8b5cf6);color:#fff;border-radius:18px;padding:24px 28px;margin:0 0 22px;box-shadow:0 12px 30px rgba(76,29,149,.18)}
        .ta-eval-hero h2{margin:0 0 7px;font-size:22px}.ta-eval-hero p{margin:0;opacity:.86;font-size:13px}
        .ta-eval-section{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:20px;margin:18px 0;box-shadow:0 5px 18px rgba(15,23,42,.05)}
        .ta-eval-section h2{margin:0 0 8px;font-size:17px;color:#24134f}.ta-eval-section .description{color:#64748b;font-size:12px;margin:0 0 16px}
        .ta-eval-report{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:18px;margin-top:18px}.ta-eval-report h3{margin:0 0 14px;color:#0f172a;font-size:15px}
        .ta-eval-report>div{background:#fff;border-radius:10px;padding:12px 14px;margin:9px 0;border-right:4px solid #8b5cf6}.ta-eval-report strong{color:#1e293b}.ta-eval-report ul{margin:7px 0 0;padding-right:18px;color:#475569}
        </style>
        <div class="ta-eval-hero"><span style="font-size:12px;color:#ddd6fe;font-weight:700">📝 گزارش فرم‌های نقد آثار</span><h2>مرکز ارزیابی پایگاه نقد مبنا</h2><p>فرم‌های ارزیابی و گزارش‌های تحلیلی در دو مسیر مستقل مدیریت می‌شوند؛ ارزیابی هنرجو از منتقد و ارزیابی منتقد از هنرجو.</p></div>
        <section class="ta-eval-section">
        <h2>📝 فرم اول: ارزیابی تجربهٔ هنرجو از منتقد</h2>
        <p class="description">هنرجو پس از دریافت نقد، کیفیت نقد، گفت‌وگو و تعامل حرفه‌ای منتقد را ارزیابی می‌کند.</p>
        <p><button type="button" class="button button-primary" onclick="scOpenEvalModal()">➕ افزودن سؤال جدید</button></p>

        <?php if ( empty( $questions ) ) : ?>
            <div class="notice notice-info"><p>هنوز سؤالی تعریف نشده است.</p></div>
        <?php else : ?>
        <table class="widefat" style="max-width:800px">
            <thead><tr><th>سؤال</th><th>نوع</th><th>وضعیت</th><th>عملیات</th></tr></thead>
            <tbody>
            <?php foreach ( $questions as $q ) :
                $type_label = [ 'rating' => 'امتیازی (۱ تا ۵)', 'choice' => 'چندگزینه‌ای', 'text' => 'متنی' ][ $q->type ] ?? $q->type;
                $row_json = esc_attr( wp_json_encode( [
                    'id' => $q->id, 'question' => $q->question, 'type' => $q->type,
                    'options' => $q->options ? json_decode( $q->options ) : [],
                    'sort_order' => $q->sort_order, 'is_active' => (int) $q->is_active,
                ] ) );
            ?>
            <tr>
                <td><?= esc_html( $q->question ) ?></td>
                <td><?= esc_html( $type_label ) ?></td>
                <td><?= $q->is_active ? '✅ فعال' : '⛔️ غیرفعال' ?></td>
                <td>
                    <button type="button" class="button button-small" onclick='scEditEval(<?= $row_json ?>)'>✏️</button>
                    <form method="post" action="<?= esc_url( $post_url ) ?>" style="display:inline" onsubmit="return confirm('این سؤال حذف شود؟');">
                        <?php wp_nonce_field( 'ta_sc_eval_q_delete_' . $q->id ); ?>
                        <input type="hidden" name="action" value="ta_sc_eval_q_delete">
                        <input type="hidden" name="question_id" value="<?= (int) $q->id ?>">
                        <button type="submit" class="button button-small button-link-delete">🗑️</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div id="sc-eval-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center">
            <div style="background:#fff;border-radius:10px;padding:24px;width:480px;max-width:94vw">
                <h2 id="sc-eval-modal-title" style="margin-top:0">افزودن سؤال جدید</h2>
                <form method="post" action="<?= esc_url( $post_url ) ?>">
                    <?php wp_nonce_field( 'ta_sc_eval_q_save' ); ?>
                    <input type="hidden" name="action" value="ta_sc_eval_q_save">
                    <input type="hidden" name="question_id" id="sc-eq-id" value="0">
                    <table class="form-table" style="direction:rtl">
                        <tr><th style="width:100px">متن سؤال *</th><td><textarea name="question" id="sc-eq-text" rows="2" class="large-text" required></textarea></td></tr>
                        <tr><th>نوع پاسخ</th><td>
                            <select name="type" id="sc-eq-type" onchange="document.getElementById('sc-eq-options-row').style.display = this.value==='choice' ? '' : 'none'">
                                <option value="rating">امتیازی (۱ تا ۵ ستاره)</option>
                                <option value="choice">چندگزینه‌ای</option>
                                <option value="text">متنی (پاسخ آزاد)</option>
                            </select>
                        </td></tr>
                        <tr id="sc-eq-options-row" style="display:none"><th>گزینه‌ها</th><td>
                            <input type="text" name="options" id="sc-eq-options" class="large-text" placeholder="با کاما جدا کنید، مثلاً: عالی, خوب, متوسط, ضعیف">
                        </td></tr>
                        <tr><th>ترتیب نمایش</th><td><input type="number" name="sort_order" id="sc-eq-order" value="0" class="small-text"></td></tr>
                        <tr><th>فعال</th><td><label><input type="checkbox" name="is_active" id="sc-eq-active" value="1" checked> نمایش در فرم ارزیابی</label></td></tr>
                    </table>
                    <p><button type="submit" class="button button-primary">ذخیره</button>
                       <button type="button" class="button" onclick="document.getElementById('sc-eval-modal').style.display='none'">انصراف</button></p>
                </form>
            </div>
        </div>

        <hr style="margin:28px 0">
        <h2 style="font-size:15px">📊 گزارش پاسخ‌های هنرجویان دربارهٔ منتقدان</h2>
        <div class="ta-eval-report"><h3>📊 گزارش مستقل تجربهٔ هنرجویان از منتقدان</h3><?php $this->render_evaluation_results(); ?></div>
        </section>

        <hr style="margin:28px 0;border-top:2px solid #ddd">

        <section class="ta-eval-section">
        <h2>🎓 فرم دوم: ارزیابی تجربهٔ منتقد از هنرجو</h2>
        <p class="description">منتقد در پایان فرایند، همکاری هنرجو و کیفیت متن را با مقیاس مستقل ارزیابی می‌کند.</p>
        <p><button type="button" class="button button-primary" onclick="scStudentOpenEvalModal()">➕ افزودن سؤال جدید</button></p>

        <?php
        $sq_table = TA_Database::table( 'student_eval_questions' );
        $student_questions = TA_Database::get_results( "SELECT * FROM {$sq_table} ORDER BY sort_order ASC, id ASC" );
        ?>
        <?php if ( empty( $student_questions ) ) : ?>
            <div class="notice notice-info"><p>هنوز سؤالی تعریف نشده است.</p></div>
        <?php else : ?>
        <table class="widefat" style="max-width:800px">
            <thead><tr><th>سؤال</th><th>نوع</th><th>وضعیت</th><th>عملیات</th></tr></thead>
            <tbody>
            <?php foreach ( $student_questions as $q ) :
                $type_label = [ 'rating' => 'امتیازی (۱ تا ۵)', 'choice' => 'چندگزینه‌ای', 'text' => 'متنی' ][ $q->type ] ?? $q->type;
                $row_json = esc_attr( wp_json_encode( [
                    'id' => $q->id, 'question' => $q->question, 'type' => $q->type,
                    'options' => $q->options ? json_decode( $q->options ) : [],
                    'sort_order' => $q->sort_order, 'is_active' => (int) $q->is_active,
                ] ) );
            ?>
            <tr>
                <td><?= esc_html( $q->question ) ?></td>
                <td><?= esc_html( $type_label ) ?></td>
                <td><?= $q->is_active ? '✅ فعال' : '⛔️ غیرفعال' ?></td>
                <td>
                    <button type="button" class="button button-small" onclick='scStudentEditEval(<?= $row_json ?>)'>✏️</button>
                    <form method="post" action="<?= esc_url( $post_url ) ?>" style="display:inline" onsubmit="return confirm('این سؤال حذف شود؟');">
                        <?php wp_nonce_field( 'ta_sc_student_eval_q_delete_' . $q->id ); ?>
                        <input type="hidden" name="action" value="ta_sc_student_eval_q_delete">
                        <input type="hidden" name="question_id" value="<?= (int) $q->id ?>">
                        <button type="submit" class="button button-small button-link-delete">🗑️</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div id="sc-student-eval-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center">
            <div style="background:#fff;border-radius:10px;padding:24px;width:480px;max-width:94vw">
                <h2 id="sc-student-eval-modal-title" style="margin-top:0">افزودن سؤال جدید</h2>
                <form method="post" action="<?= esc_url( $post_url ) ?>">
                    <?php wp_nonce_field( 'ta_sc_student_eval_q_save' ); ?>
                    <input type="hidden" name="action" value="ta_sc_student_eval_q_save">
                    <input type="hidden" name="question_id" id="sc-sq-id" value="0">
                    <table class="form-table" style="direction:rtl">
                        <tr><th style="width:100px">متن سؤال *</th><td><textarea name="question" id="sc-sq-text" rows="2" class="large-text" required></textarea></td></tr>
                        <tr><th>نوع پاسخ</th><td>
                            <select name="type" id="sc-sq-type" onchange="document.getElementById('sc-sq-options-row').style.display = this.value==='choice' ? '' : 'none'">
                                <option value="rating">امتیازی (۱ تا ۵ ستاره)</option>
                                <option value="choice">چندگزینه‌ای</option>
                                <option value="text">متنی (پاسخ آزاد)</option>
                            </select>
                        </td></tr>
                        <tr id="sc-sq-options-row" style="display:none"><th>گزینه‌ها</th><td>
                            <input type="text" name="options" id="sc-sq-options" class="large-text" placeholder="با کاما جدا کنید">
                        </td></tr>
                        <tr><th>ترتیب نمایش</th><td><input type="number" name="sort_order" id="sc-sq-order" value="0" class="small-text"></td></tr>
                        <tr><th>فعال</th><td><label><input type="checkbox" name="is_active" id="sc-sq-active" value="1" checked> نمایش در فرم تکمیل نقد</label></td></tr>
                    </table>
                    <p><button type="submit" class="button button-primary">ذخیره</button>
                       <button type="button" class="button" onclick="document.getElementById('sc-student-eval-modal').style.display='none'">انصراف</button></p>
                </form>
            </div>
        </div>

        <h3 style="font-size:14px">📈 گزارش پاسخ‌های منتقدان دربارهٔ هنرجویان و متن‌ها</h3>
        <div class="ta-eval-report"><h3>📈 گزارش مستقل تجربهٔ منتقدان از هنرجویان و متن‌ها</h3><?php $this->render_student_evaluation_results(); ?></div>
        </section>

        <script>
        function scStudentOpenEvalModal(){
            document.getElementById('sc-student-eval-modal-title').textContent = 'افزودن سؤال جدید';
            document.getElementById('sc-sq-id').value = 0;
            document.getElementById('sc-sq-text').value = '';
            document.getElementById('sc-sq-type').value = 'rating';
            document.getElementById('sc-sq-options').value = '';
            document.getElementById('sc-sq-options-row').style.display = 'none';
            document.getElementById('sc-sq-order').value = 0;
            document.getElementById('sc-sq-active').checked = true;
            document.getElementById('sc-student-eval-modal').style.display = 'flex';
        }
        function scStudentEditEval(q){
            document.getElementById('sc-student-eval-modal-title').textContent = 'ویرایش سؤال';
            document.getElementById('sc-sq-id').value = q.id;
            document.getElementById('sc-sq-text').value = q.question || '';
            document.getElementById('sc-sq-type').value = q.type || 'rating';
            document.getElementById('sc-sq-options').value = (q.options||[]).join(', ');
            document.getElementById('sc-sq-options-row').style.display = q.type === 'choice' ? '' : 'none';
            document.getElementById('sc-sq-order').value = q.sort_order || 0;
            document.getElementById('sc-sq-active').checked = !!q.is_active;
            document.getElementById('sc-student-eval-modal').style.display = 'flex';
        }
        </script>

        <script>
        function scOpenEvalModal(){
            document.getElementById('sc-eval-modal-title').textContent = 'افزودن سؤال جدید';
            document.getElementById('sc-eq-id').value = 0;
            document.getElementById('sc-eq-text').value = '';
            document.getElementById('sc-eq-type').value = 'rating';
            document.getElementById('sc-eq-options').value = '';
            document.getElementById('sc-eq-options-row').style.display = 'none';
            document.getElementById('sc-eq-order').value = 0;
            document.getElementById('sc-eq-active').checked = true;
            document.getElementById('sc-eval-modal').style.display = 'flex';
        }
        function scEditEval(q){
            document.getElementById('sc-eval-modal-title').textContent = 'ویرایش سؤال';
            document.getElementById('sc-eq-id').value = q.id;
            document.getElementById('sc-eq-text').value = q.question || '';
            document.getElementById('sc-eq-type').value = q.type || 'rating';
            document.getElementById('sc-eq-options').value = (q.options||[]).join(', ');
            document.getElementById('sc-eq-options-row').style.display = q.type === 'choice' ? '' : 'none';
            document.getElementById('sc-eq-order').value = q.sort_order || 0;
            document.getElementById('sc-eq-active').checked = !!q.is_active;
            document.getElementById('sc-eval-modal').style.display = 'flex';
        }
        </script>
        <?php
    }

    private function render_evaluation_results(): void {
        $q_table = TA_Database::table( 'eval_questions' );
        $r_table = TA_Database::table( 'eval_responses' );
        $questions = TA_Database::get_results( "SELECT * FROM {$q_table} ORDER BY sort_order ASC, id ASC" );

        if ( empty( $questions ) ) {
            echo '<p style="color:#888">سؤالی برای نمایش نتایج وجود ندارد.</p>';
            return;
        }

        foreach ( $questions as $q ) {
            $responses = TA_Database::get_results( "SELECT answer FROM {$r_table} WHERE question_id = %d ORDER BY created_at DESC", [ $q->id ] );
            echo '<div style="margin-bottom:18px"><strong>' . esc_html( $q->question ) . '</strong> <span style="color:#888;font-size:12px">(' . count( $responses ) . ' پاسخ)</span>';
            if ( $q->type === 'rating' && $responses ) {
                $avg = array_sum( array_map( 'floatval', wp_list_pluck( $responses, 'answer' ) ) ) / count( $responses );
                echo '<p>میانگین امتیاز: <strong>' . number_format( $avg, 1 ) . ' / ۵</strong> ⭐</p>';
            } elseif ( $q->type === 'choice' && $responses ) {
                $counts = array_count_values( wp_list_pluck( $responses, 'answer' ) );
                echo '<ul style="margin:4px 0">';
                foreach ( $counts as $opt => $c ) {
                    echo '<li>' . esc_html( $opt ) . ': ' . (int) $c . '</li>';
                }
                echo '</ul>';
            } elseif ( $responses ) {
                echo '<ul style="margin:4px 0;font-size:12px;color:#4b5563">';
                foreach ( array_slice( $responses, 0, 10 ) as $r ) {
                    echo '<li>' . esc_html( wp_trim_words( $r->answer, 25 ) ) . '</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }
    }

    private function render_student_evaluation_results(): void {
        $q_table = TA_Database::table( 'student_eval_questions' );
        $r_table = TA_Database::table( 'student_eval_responses' );
        $questions = TA_Database::get_results( "SELECT * FROM {$q_table} ORDER BY sort_order ASC, id ASC" );

        if ( empty( $questions ) ) {
            echo '<p style="color:#888">سؤالی برای نمایش نتایج وجود ندارد.</p>';
            return;
        }

        foreach ( $questions as $q ) {
            $responses = TA_Database::get_results( "SELECT answer FROM {$r_table} WHERE question_id = %d ORDER BY created_at DESC", [ $q->id ] );
            echo '<div style="margin-bottom:18px"><strong>' . esc_html( $q->question ) . '</strong> <span style="color:#888;font-size:12px">(' . count( $responses ) . ' پاسخ)</span>';
            if ( $q->type === 'rating' && $responses ) {
                $avg = array_sum( array_map( 'floatval', wp_list_pluck( $responses, 'answer' ) ) ) / count( $responses );
                echo '<p>میانگین امتیاز: <strong>' . number_format( $avg, 1 ) . ' / ۵</strong> ⭐</p>';
            } elseif ( $q->type === 'choice' && $responses ) {
                $counts = array_count_values( wp_list_pluck( $responses, 'answer' ) );
                echo '<ul style="margin:4px 0">';
                foreach ( $counts as $opt => $c ) {
                    echo '<li>' . esc_html( $opt ) . ': ' . (int) $c . '</li>';
                }
                echo '</ul>';
            } elseif ( $responses ) {
                echo '<ul style="margin:4px 0;font-size:12px;color:#4b5563">';
                foreach ( array_slice( $responses, 0, 10 ) as $r ) {
                    echo '<li>' . esc_html( wp_trim_words( $r->answer, 25 ) ) . '</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }
    }

    public function handle_eval_question_save(): void {
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) wp_die( 'دسترسی غیرمجاز' );
        check_admin_referer( 'ta_sc_eval_q_save' );

        $q_id      = absint( $_POST['question_id'] ?? 0 );
        $question  = sanitize_textarea_field( $_POST['question'] ?? '' );
        $type      = in_array( $_POST['type'] ?? '', [ 'rating', 'choice', 'text' ], true ) ? $_POST['type'] : 'rating';
        $options_raw = sanitize_text_field( $_POST['options'] ?? '' );
        $options   = $type === 'choice' && $options_raw
            ? wp_json_encode( array_map( 'trim', explode( ',', $options_raw ) ), JSON_UNESCAPED_UNICODE )
            : null;
        $sort_order = absint( $_POST['sort_order'] ?? 0 );
        $is_active  = ! empty( $_POST['is_active'] ) ? 1 : 0;

        if ( ! $question ) {
            wp_redirect( admin_url( 'admin.php?page=ta-story-critique&sc_tab=evaluation&error=1' ) );
            exit;
        }

        $table = TA_Database::table( 'eval_questions' );
        global $wpdb;
        $data = [ 'question' => $question, 'type' => $type, 'options' => $options, 'sort_order' => $sort_order, 'is_active' => $is_active ];

        if ( $q_id ) {
            $wpdb->update( $table, $data, [ 'id' => $q_id ] );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table, $data );
        }

        wp_redirect( admin_url( 'admin.php?page=ta-story-critique&sc_tab=evaluation&q_saved=1' ) );
        exit;
    }

    public function handle_eval_question_delete(): void {
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) wp_die( 'دسترسی غیرمجاز' );
        $q_id = absint( $_POST['question_id'] ?? 0 );
        check_admin_referer( 'ta_sc_eval_q_delete_' . $q_id );

        if ( $q_id ) {
            TA_Database::delete( 'ta_eval_questions', [ 'id' => $q_id ] );
        }
        wp_redirect( admin_url( 'admin.php?page=ta-story-critique&sc_tab=evaluation&q_deleted=1' ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تب: تنظیمات (آیدی پشتیبان، محصول نمونه، اطلاعات ملی‌پیامک و کد پترن‌ها)
    // ─────────────────────────────────────────────────────────────────────────

    private function render_settings_tab(): void {
        $product_id       = get_option( 'ta_sc_product_id', '' );
        $mp_username      = get_option( 'ta_sc_melipayamak_username', '' );
        $mp_password      = get_option( 'ta_sc_melipayamak_password', '' );
        $admin_alert_phone = get_option( 'ta_sc_admin_alert_phone', '' );
        $sla_reminder_days = get_option( 'ta_sc_sla_reminder_days', 3 );
        $sla_pending_days  = get_option( 'ta_sc_sla_pending_days', $sla_reminder_days );
        $sla_pending_once  = get_option( 'ta_sc_sla_pending_once', '0' ) === '1';
        $sla_in_progress_days = get_option( 'ta_sc_sla_in_progress_days', 3 );
        $sla_delivered_days = get_option( 'ta_sc_sla_delivered_days', 3 );
        $sla_completed_days = get_option( 'ta_sc_sla_completed_days', 3 );

        $patterns = [
            'customer'          => get_option( 'ta_sc_pattern_customer', '' ),
            'ta'                => get_option( 'ta_sc_pattern_ta', '' ),
            'started'           => get_option( 'ta_sc_pattern_started', '' ),
            'delivered'         => get_option( 'ta_sc_pattern_delivered', '' ),
            'ta_completed'      => get_option( 'ta_sc_pattern_ta_completed', '' ),
            'eval_reminder'     => get_option( 'ta_sc_pattern_eval_reminder', '' ),
            'cancelled_student' => get_option( 'ta_sc_pattern_cancelled_student', '' ),
            'cancelled_ta'      => get_option( 'ta_sc_pattern_cancelled_ta', '' ),
            'chat'              => get_option( 'ta_sc_pattern_chat', '' ),
            'sla_reminder'      => get_option( 'ta_sc_pattern_sla_reminder', '' ),
            'sla_pending'       => get_option( 'ta_sc_pattern_sla_pending', get_option( 'ta_sc_pattern_sla_reminder', '' ) ),
            'sla_in_progress'   => get_option( 'ta_sc_pattern_sla_in_progress', '' ),
            'sla_delivered'     => get_option( 'ta_sc_pattern_sla_delivered', '' ),
            'sla_completed'     => get_option( 'ta_sc_pattern_sla_completed', '' ),
            'capacity_alert'    => get_option( 'ta_sc_pattern_capacity_alert', '' ),
            'capacity_alert_ta' => get_option( 'ta_sc_pattern_capacity_alert_ta', '' ),
        ];

        // نگاشت هر پترن به کلید فعال/غیرفعال مربوطه در TA_MeliPayamak::NOTIFICATION_TYPES
        $toggle_key = [
            'customer'          => 'customer',
            'ta'                => 'ta',
            'started'           => 'started',
            'delivered'         => 'delivered',
            'ta_completed'      => 'ta_completed',
            'eval_reminder'     => 'eval_reminder',
            'cancelled_student' => 'cancelled',
            'cancelled_ta'      => 'cancelled',
            'chat'              => 'chat',
            'sla_reminder'      => 'sla_reminder',
            'sla_pending'       => 'sla_pending',
            'sla_in_progress'   => 'sla_in_progress',
            'sla_delivered'     => 'sla_delivered',
            'sla_completed'     => 'sla_completed',
            'capacity_alert'    => 'capacity_alert',
            'capacity_alert_ta' => 'capacity_alert_ta',
        ];

        $post_url = admin_url( 'admin-post.php' );

        $toggle_html = function ( string $key ) {
            $checked = TA_MeliPayamak::enabled( $key ) ? 'checked' : '';
            printf(
                '<label style="font-weight:400;display:inline-flex;align-items:center;gap:4px;margin-bottom:6px">
                    <input type="checkbox" name="sms_on_%1$s" value="1" %2$s> فعال</label>',
                esc_attr( $key ),
                $checked
            );
        };
        ?>
        <form method="post" action="<?= esc_url( $post_url ) ?>" style="max-width:680px">
            <?php wp_nonce_field( 'ta_sc_settings_save' ); ?>
            <input type="hidden" name="action" value="ta_sc_settings_save">

            <h2 style="font-size:15px">اطلاعات کلی</h2>
            <table class="form-table">
                <tr>
                    <th>شناسه محصول نمونه</th>
                    <td><input type="number" name="product_id" value="<?= esc_attr( $product_id ) ?>" class="regular-text" dir="ltr">
                        <p class="description">شناسه (ID) محصول ووکامرسی که برایش قیمت پویا تنظیم و به سفارش اضافه می‌شود.</p></td>
                </tr>
                <tr>
                    <th>شماره موبایل ادمین (برای هشدارها)</th>
                    <td><input type="text" name="admin_alert_phone" value="<?= esc_attr( $admin_alert_phone ) ?>" class="regular-text" dir="ltr" placeholder="09xxxxxxxxx">
                        <p class="description">هشدار «تکمیل ظرفیت استاد تسهیلگر» به این شماره ارسال می‌شود.</p></td>
                </tr>
                <tr>
                    <th>یادآوری سفارش «در انتظار» (روز)</th>
                    <td><input type="number" min="1" name="sla_pending_days" value="<?= esc_attr( $sla_pending_days ) ?>" class="small-text" dir="ltr">
                        <p class="description">شمارش از زمانی شروع می‌شود که استاد دیگر هیچ سفارش «در حال انجام» نداشته باشد.</p></td>
                </tr>
                <tr>
                    <th>تکرار یادآوری سفارش «در انتظار»</th>
                    <td>
                        <label><input type="checkbox" name="sla_pending_once" value="1" <?= checked( $sla_pending_once, true, false ) ?>> فقط یک بار ارسال شود</label>
                        <p class="description">اگر فعال باشد، بعد از اولین ارسال موفق دیگر تکرار نمی‌شود. اگر غیرفعال باشد، هر تعداد روزی که در فیلد بالا تعیین شده، یک‌بار ارسال می‌شود؛ مثلاً ۱ یعنی روزانه و ۲ یعنی یک روز در میان.</p>
                    </td>
                </tr>
                <tr>
                    <th>یادآوری سفارش «در حال انجام» (روز)</th>
                    <td><input type="number" min="1" name="sla_in_progress_days" value="<?= esc_attr( $sla_in_progress_days ) ?>" class="small-text" dir="ltr"></td>
                </tr>
                <tr>
                    <th>یادآوری ارزیابی هنرجو پس از «تحویل‌شده» (روز)</th>
                    <td><input type="number" min="1" name="sla_delivered_days" value="<?= esc_attr( $sla_delivered_days ) ?>" class="small-text" dir="ltr"></td>
                </tr>
                <tr>
                    <th>یادآوری ارزیابی استاد پس از «تکمیل‌شده» (روز)</th>
                    <td><input type="number" min="1" name="sla_completed_days" value="<?= esc_attr( $sla_completed_days ) ?>" class="small-text" dir="ltr"></td>
                </tr>
            </table>

            <h2 style="font-size:15px">ملی‌پیامک (پیامک الگودار)</h2>
            <table class="form-table">
                <tr><th>نام کاربری</th><td><input type="text" name="mp_username" value="<?= esc_attr( $mp_username ) ?>" class="regular-text" dir="ltr"></td></tr>
                <tr><th>رمز عبور</th><td><input type="password" name="mp_password" value="<?= esc_attr( $mp_password ) ?>" class="regular-text" dir="ltr" autocomplete="new-password"></td></tr>

                <tr>
                    <th>کد الگو — پیامک به هنرجو</th>
                    <td><?php $toggle_html( $toggle_key['customer'] ); ?>
                        <input type="text" name="pattern_customer" value="<?= esc_attr( $patterns['customer'] ) ?>" class="regular-text" dir="ltr">
                        <p class="description">
                            متغیرهای الگو به همین ترتیب باید در پنل ملی‌پیامک ساخته شود:<br>
                            %0 = نام هنرجو &nbsp; %1 = نام استاد تسهیلگر &nbsp; %2 = شماره سفارش<br>
                            نمونه متن پیشنهادی: «سلام %0؛ سفارش نقد اثر شما ثبت شد. استاد تسهیلگر: %1 — شماره سفارش: %2»
                        </p></td>
                </tr>
                <tr>
                    <th>کد الگو — پیامک به استاد تسهیلگر</th>
                    <td><?php $toggle_html( $toggle_key['ta'] ); ?>
                        <input type="text" name="pattern_ta" value="<?= esc_attr( $patterns['ta'] ) ?>" class="regular-text" dir="ltr">
                        <p class="description">
                            متغیرهای الگو: %0 = نام استاد تسهیلگر &nbsp; %1 = نام هنرجو &nbsp; %2 = شماره سفارش<br>
                            نمونه متن پیشنهادی: «سلام %0؛ یک نقد اثر جدید برایتان ثبت شد (هنرجو: %1 — شماره سفارش: %2). لطفاً به پیشخوان خود مراجعه کنید.»
                        </p></td>
                </tr>
                <tr>
                    <th>کد الگو — شروع کار (به هنرجو)</th>
                    <td><?php $toggle_html( $toggle_key['started'] ); ?>
                        <input type="text" name="pattern_started" value="<?= esc_attr( $patterns['started'] ) ?>" class="regular-text" dir="ltr">
                        <p class="description">
                            وقتی استاد تسهیلگر روی سفارش «شروع کار» می‌زند، به هنرجو ارسال می‌شود.<br>
                            متغیرهای الگو: %0 = نام هنرجو &nbsp; %1 = نام استاد تسهیلگر &nbsp; %2 = شماره سفارش<br>
                            نمونه متن پیشنهادی: «سلام %0؛ استاد تسهیلگر %1 کار روی نقد اثر شما (سفارش %2) را آغاز کرد.»
                        </p></td>
                </tr>
                <tr>
                    <th>کد الگو — تحویل نقد (به هنرجو)</th>
                    <td><?php $toggle_html( $toggle_key['delivered'] ); ?>
                        <input type="text" name="pattern_delivered" value="<?= esc_attr( $patterns['delivered'] ) ?>" class="regular-text" dir="ltr">
                        <p class="description">
                            وقتی استاد تسهیلگر فایل نقد را تحویل می‌دهد، به هنرجو ارسال می‌شود.<br>
                            متغیرهای الگو: %0 = نام هنرجو &nbsp; %1 = نام استاد تسهیلگر &nbsp; %2 = شماره سفارش<br>
                            نمونه متن پیشنهادی: «سلام %0؛ نقد اثر شما توسط %1 تحویل داده شد (سفارش %2). برای مشاهده به پیشخوان خود مراجعه کنید.»
                        </p></td>
                </tr>
                <tr>
                    <th>کد الگو — تکمیل سفارش (به استاد تسهیلگر)</th>
                    <td><?php $toggle_html( $toggle_key['ta_completed'] ); ?>
                        <input type="text" name="pattern_ta_completed" value="<?= esc_attr( $patterns['ta_completed'] ) ?>" class="regular-text" dir="ltr">
                        <p class="description">
                            وقتی هنرجو سفارش را نهایی/تأیید می‌کند، به استاد تسهیلگر ارسال می‌شود.<br>
                            متغیرهای الگو: %0 = نام استاد تسهیلگر &nbsp; %1 = نام هنرجو &nbsp; %2 = شماره سفارش<br>
                            نمونه متن پیشنهادی: «سلام %0؛ هنرجو %1 سفارش نقد اثر (شماره %2) را تکمیل و تأیید کرد.»
                        </p></td>
                </tr>
                <tr>
                    <th>کد الگو — یادآوری ارزیابی (به هنرجو)</th>
                    <td><?php $toggle_html( $toggle_key['eval_reminder'] ); ?>
                        <input type="text" name="pattern_eval_reminder" value="<?= esc_attr( $patterns['eval_reminder'] ) ?>" class="regular-text" dir="ltr">
                        <p class="description">
                            وقتی خودِ استاد تسهیلگر سفارش را «تکمیل» می‌زند (بدون این‌که هنرجو خودش تکمیل کرده باشد)، به هنرجو ارسال می‌شود تا برای ثبت ارزیابی مراجعه کند.<br>
                            متغیرهای الگو: %0 = نام هنرجو &nbsp; %1 = نام استاد تسهیلگر &nbsp; %2 = شماره سفارش<br>
                            نمونه متن پیشنهادی: «سلام %0؛ کار نقد اثر شما توسط %1 تکمیل شد. لطفاً به پیشخوان خود مراجعه و ارزیابی استاد را ثبت کنید (سفارش %2).»
                        </p></td>
                </tr>
                <tr>
                    <th>کد الگو — لغو سفارش (به هنرجو)</th>
                    <td><?php $toggle_html( $toggle_key['cancelled_student'] ); ?>
                        <input type="text" name="pattern_cancelled_student" value="<?= esc_attr( $patterns['cancelled_student'] ) ?>" class="regular-text" dir="ltr">
                        <p class="description">
                            وقتی سفارش لغو/مرجوع می‌شود، به هنرجو ارسال می‌شود. (یک کلید فعال/غیرفعال برای هر دو پیامک لغو استفاده می‌شود.)<br>
                            متغیرهای الگو: %0 = نام هنرجو &nbsp; %1 = نام استاد تسهیلگر &nbsp; %2 = شماره سفارش<br>
                            نمونه متن پیشنهادی: «سلام %0؛ سفارش نقد اثر شما (شماره %2) لغو/مرجوع شد.»
                        </p></td>
                </tr>
                <tr>
                    <th>کد الگو — لغو سفارش (به استاد تسهیلگر)</th>
                    <td><?php $toggle_html( $toggle_key['cancelled_ta'] ); ?>
                        <input type="text" name="pattern_cancelled_ta" value="<?= esc_attr( $patterns['cancelled_ta'] ) ?>" class="regular-text" dir="ltr">
                        <p class="description">
                            وقتی سفارش لغو/مرجوع می‌شود، به استاد تسهیلگر ارسال می‌شود.<br>
                            متغیرهای الگو: %0 = نام استاد تسهیلگر &nbsp; %1 = نام هنرجو &nbsp; %2 = شماره سفارش<br>
                            نمونه متن پیشنهادی: «سلام %0؛ سفارش نقد اثر هنرجو %1 (شماره %2) لغو/مرجوع شد و ظرفیت شما آزاد شد.»
                        </p></td>
                </tr>
                <tr>
                    <th>کد الگو — پیام جدید در چت</th>
                    <td><?php $toggle_html( $toggle_key['chat'] ); ?>
                        <input type="text" name="pattern_chat" value="<?= esc_attr( $patterns['chat'] ) ?>" class="regular-text" dir="ltr">
                        <p class="description">
                            فقط برای گفتگوی هنرجو⇄استاد تسهیلگر؛ وقتی یک طرف پیام می‌فرستد، به طرف مقابل ارسال می‌شود.<br>
                            متغیرهای الگو: %0 = نام گیرنده &nbsp; %1 = نام فرستنده &nbsp; %2 = شماره سفارش<br>
                            نمونه متن پیشنهادی: «سلام %0؛ پیام جدیدی از %1 برای سفارش %2 دارید. برای مشاهده به پیشخوان مراجعه کنید.»
                        </p></td>
                </tr>
                <tr>
                    <th>کد الگو — سفارش در انتظار (به استاد)</th>
                    <td><?php $toggle_html( $toggle_key['sla_pending'] ); ?>
                        <input type="text" name="pattern_sla_pending" value="<?= esc_attr( $patterns['sla_pending'] ) ?>" class="regular-text" dir="ltr">
                        <p class="description">متغیرها: %0 = نام استاد، %1 = نام هنرجو، %2 = شماره سفارش، %3 = تعداد روز. فقط وقتی استاد سفارش «در حال انجام» دیگری ندارد.</p></td>
                </tr>
                <tr>
                    <th>کد الگو — سفارش در حال انجام (به استاد)</th>
                    <td><?php $toggle_html( $toggle_key['sla_in_progress'] ); ?>
                        <input type="text" name="pattern_sla_in_progress" value="<?= esc_attr( $patterns['sla_in_progress'] ) ?>" class="regular-text" dir="ltr">
                        <p class="description">متغیرها: %0 = نام استاد، %1 = نام هنرجو، %2 = شماره سفارش، %3 = تعداد روز.</p></td>
                </tr>
                <tr>
                    <th>کد الگو — ارزیابی هنرجو پس از تحویل (به استاد)</th>
                    <td><?php $toggle_html( $toggle_key['sla_delivered'] ); ?>
                        <input type="text" name="pattern_sla_delivered" value="<?= esc_attr( $patterns['sla_delivered'] ) ?>" class="regular-text" dir="ltr">
                        <p class="description">متغیرها: %0 = نام استاد، %1 = نام هنرجو، %2 = شماره سفارش، %3 = تعداد روز. یادآوری ثبت ارزیابی هنرجو.</p></td>
                </tr>
                <tr>
                    <th>کد الگو — ارزیابی استاد پس از تکمیل (به هنرجو)</th>
                    <td><?php $toggle_html( $toggle_key['sla_completed'] ); ?>
                        <input type="text" name="pattern_sla_completed" value="<?= esc_attr( $patterns['sla_completed'] ) ?>" class="regular-text" dir="ltr">
                        <p class="description">متغیرها: %0 = نام هنرجو، %1 = نام استاد، %2 = شماره سفارش، %3 = تعداد روز. یادآوری ثبت ارزیابی استاد.</p></td>
                </tr>
                <tr>
                    <th>کد الگو — هشدار تکمیل ظرفیت (به ادمین)</th>
                    <td><?php $toggle_html( $toggle_key['capacity_alert'] ); ?>
                        <input type="text" name="pattern_capacity_alert" value="<?= esc_attr( $patterns['capacity_alert'] ) ?>" class="regular-text" dir="ltr">
                        <p class="description">
                            وقتی ظرفیت یک استاد تسهیلگر به صفر می‌رسد، به شماره‌ی ادمین (بالا) ارسال می‌شود.<br>
                            متغیرهای الگو: %0 = نام استاد تسهیلگر<br>
                            نمونه متن پیشنهادی: «هشدار: ظرفیت استاد تسهیلگر %0 تکمیل شد.»
                        </p></td>
                </tr>
                <tr>
                    <th>کد الگو — هشدار تکمیل ظرفیت (به خود استاد تسهیلگر)</th>
                    <td><?php $toggle_html( $toggle_key['capacity_alert_ta'] ); ?>
                        <input type="text" name="pattern_capacity_alert_ta" value="<?= esc_attr( $patterns['capacity_alert_ta'] ) ?>" class="regular-text" dir="ltr">
                        <p class="description">
                            وقتی ظرفیت خودِ استاد تسهیلگر به صفر می‌رسد، به شماره تماس خودش ارسال می‌شود.<br>
                            متغیرهای الگو: %0 = نام استاد تسهیلگر<br>
                            نمونه متن پیشنهادی: «سلام %0؛ ظرفیت پذیرش سفارش نقد اثر شما تکمیل شد. برای دریافت سفارش جدید، ظرفیت را افزایش دهید.»
                        </p></td>
                </tr>
            </table>

            <p><button type="submit" class="button button-primary">ذخیره تنظیمات</button></p>
        </form>
        <?php
    }

    public function handle_settings_save(): void {
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) wp_die( 'دسترسی غیرمجاز' );
        check_admin_referer( 'ta_sc_settings_save' );

        delete_option( 'ta_sc_support_bale_id' );
        update_option( 'ta_sc_product_id', absint( $_POST['product_id'] ?? 0 ) );
        update_option( 'ta_sc_admin_alert_phone', sanitize_text_field( $_POST['admin_alert_phone'] ?? '' ) );
        $pending_days = max( 1, absint( $_POST['sla_pending_days'] ?? $_POST['sla_reminder_days'] ?? 3 ) );
        update_option( 'ta_sc_sla_reminder_days', $pending_days ); // سازگاری با نسخه‌های قدیمی
        update_option( 'ta_sc_sla_pending_days', $pending_days );
        update_option( 'ta_sc_sla_pending_once', ! empty( $_POST['sla_pending_once'] ) ? '1' : '0' );
        update_option( 'ta_sc_sla_in_progress_days', max( 1, absint( $_POST['sla_in_progress_days'] ?? 3 ) ) );
        update_option( 'ta_sc_sla_delivered_days', max( 1, absint( $_POST['sla_delivered_days'] ?? 3 ) ) );
        update_option( 'ta_sc_sla_completed_days', max( 1, absint( $_POST['sla_completed_days'] ?? 3 ) ) );
        update_option( 'ta_sc_melipayamak_username', sanitize_text_field( $_POST['mp_username'] ?? '' ) );
        update_option( 'ta_sc_melipayamak_password', sanitize_text_field( $_POST['mp_password'] ?? '' ) );

        update_option( 'ta_sc_pattern_customer', sanitize_text_field( $_POST['pattern_customer'] ?? '' ) );
        update_option( 'ta_sc_pattern_ta', sanitize_text_field( $_POST['pattern_ta'] ?? '' ) );
        update_option( 'ta_sc_pattern_started', sanitize_text_field( $_POST['pattern_started'] ?? '' ) );
        update_option( 'ta_sc_pattern_delivered', sanitize_text_field( $_POST['pattern_delivered'] ?? '' ) );
        update_option( 'ta_sc_pattern_ta_completed', sanitize_text_field( $_POST['pattern_ta_completed'] ?? '' ) );
        update_option( 'ta_sc_pattern_eval_reminder', sanitize_text_field( $_POST['pattern_eval_reminder'] ?? '' ) );
        update_option( 'ta_sc_pattern_cancelled_student', sanitize_text_field( $_POST['pattern_cancelled_student'] ?? '' ) );
        update_option( 'ta_sc_pattern_cancelled_ta', sanitize_text_field( $_POST['pattern_cancelled_ta'] ?? '' ) );
        update_option( 'ta_sc_pattern_chat', sanitize_text_field( $_POST['pattern_chat'] ?? '' ) );
        update_option( 'ta_sc_pattern_sla_reminder', sanitize_text_field( $_POST['pattern_sla_reminder'] ?? '' ) );
        update_option( 'ta_sc_pattern_sla_pending', sanitize_text_field( $_POST['pattern_sla_pending'] ?? $_POST['pattern_sla_reminder'] ?? '' ) );
        update_option( 'ta_sc_pattern_sla_in_progress', sanitize_text_field( $_POST['pattern_sla_in_progress'] ?? '' ) );
        update_option( 'ta_sc_pattern_sla_delivered', sanitize_text_field( $_POST['pattern_sla_delivered'] ?? '' ) );
        update_option( 'ta_sc_pattern_sla_completed', sanitize_text_field( $_POST['pattern_sla_completed'] ?? '' ) );
        update_option( 'ta_sc_pattern_capacity_alert', sanitize_text_field( $_POST['pattern_capacity_alert'] ?? '' ) );
        update_option( 'ta_sc_pattern_capacity_alert_ta', sanitize_text_field( $_POST['pattern_capacity_alert_ta'] ?? '' ) );

        // کلیدهای فعال/غیرفعال هر نوع پیامک (چک‌باکس‌ها) — چون چک‌باکس نزده در POST نمی‌آید، صریح 0/1 ذخیره می‌شود
        foreach ( array_keys( TA_MeliPayamak::NOTIFICATION_TYPES ) as $key ) {
            update_option( 'ta_sc_sms_on_' . $key, ! empty( $_POST[ 'sms_on_' . $key ] ) ? '1' : '0' );
        }

        wp_redirect( admin_url( 'admin.php?page=ta-story-critique&sc_tab=settings&settings_saved=1' ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // سقف + دسترسی تغییر هر ستون قیمت‌گذاری (سراسری)
    // ─────────────────────────────────────────────────────────────────────────

    public function handle_pricing_limits_save(): void {
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) wp_die( 'دسترسی غیرمجاز' );
        check_admin_referer( 'ta_sc_pricing_limits_save' );

        foreach ( array_keys( self::PRICING_FIELDS ) as $field ) {
            $cap = trim( sanitize_text_field( $_POST[ 'cap_' . $field ] ?? '' ) );
            update_option( 'ta_sc_field_cap_' . $field, $cap === '' ? '' : (string) round( (float) $cap, 2 ) );
            update_option( 'ta_sc_field_editable_' . $field, ! empty( $_POST[ 'editable_' . $field ] ) ? '1' : '0' );
        }

        wp_redirect( admin_url( 'admin.php?page=ta-story-critique&sc_tab=pricing&settings_saved=1' ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // سؤال‌های «ارزیابی هنرجو توسط استاد» (جدا از سؤال‌های ارزیابی استاد)
    // ─────────────────────────────────────────────────────────────────────────

    public function handle_student_eval_question_save(): void {
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) wp_die( 'دسترسی غیرمجاز' );
        check_admin_referer( 'ta_sc_student_eval_q_save' );

        $id       = absint( $_POST['question_id'] ?? 0 );
        $question = sanitize_textarea_field( wp_unslash( $_POST['question'] ?? '' ) );
        $type     = in_array( $_POST['type'] ?? '', [ 'rating', 'choice', 'text' ], true ) ? $_POST['type'] : 'rating';
        $options  = $type === 'choice' ? wp_json_encode( array_filter( array_map( 'trim', explode( ',', (string) ( $_POST['options'] ?? '' ) ) ) ) ) : null;
        $sort     = absint( $_POST['sort_order'] ?? 0 );
        $active   = ! empty( $_POST['is_active'] ) ? 1 : 0;

        if ( ! $question ) {
            wp_redirect( admin_url( 'admin.php?page=ta-story-critique&sc_tab=evaluation&error=1' ) );
            exit;
        }

        $table = TA_Database::table( 'student_eval_questions' );
        global $wpdb;
        $data = [
            'question'   => $question,
            'type'       => $type,
            'options'    => $options,
            'sort_order' => $sort,
            'is_active'  => $active,
        ];
        if ( $id ) {
            $wpdb->update( $table, $data, [ 'id' => $id ] );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table, $data );
        }

        wp_redirect( admin_url( 'admin.php?page=ta-story-critique&sc_tab=evaluation&q_saved=1' ) );
        exit;
    }

    public function handle_student_eval_question_delete(): void {
        if ( ! TA_Critique_Admin_Access::can_manage_orders() ) wp_die( 'دسترسی غیرمجاز' );
        $id = absint( $_POST['question_id'] ?? 0 );
        check_admin_referer( 'ta_sc_student_eval_q_delete_' . $id );

        global $wpdb;
        $wpdb->delete( TA_Database::table( 'student_eval_questions' ), [ 'id' => $id ] );

        wp_redirect( admin_url( 'admin.php?page=ta-story-critique&sc_tab=evaluation&q_deleted=1' ) );
        exit;
    }
}
