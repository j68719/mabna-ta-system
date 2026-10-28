<?php
defined( 'ABSPATH' ) || exit;

class TA_Admin_TA_Manager {

    public function __construct() {
        add_action( 'admin_post_ta_save_assistant',   [ $this, 'handle_save' ] );
        add_action( 'admin_post_ta_delete_assistant', [ $this, 'handle_delete' ] );
        add_action( 'admin_post_ta_toggle_assistant', [ $this, 'handle_toggle' ] );
        add_action( 'wp_ajax_ta_search_users',        [ $this, 'ajax_search_users' ] );
    }

    public function ajax_search_users(): void {
        check_ajax_referer( 'ta_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();
        wp_send_json_success( TA_User_Helper::search( sanitize_text_field( $_POST['q'] ?? '' ), 20 ) );
    }

    public function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
        check_admin_referer( 'ta_save_assistant' );

        $user_id      = (int) ( $_POST['ta_user_id'] ?? 0 );
        $rank         = (int) ( $_POST['ta_rank'] ?? 0 );
        $ta_code      = sanitize_text_field( $_POST['ta_code'] ?? '' );
      //  $gender_teach = sanitize_text_field( $_POST['ta_gender_teach'] ?? 'both' );
       // $messengers   = array_map( 'sanitize_text_field', (array) ( $_POST['ta_messengers'] ?? [] ) );

        if ( ! $user_id ) {
            wp_redirect( admin_url( 'admin.php?page=ta-assistants&error=no_user' ) );
            exit;
        }

        $user = get_user_by( 'id', $user_id );
        if ( $user && ! in_array( 'teaching_assistant', (array) $user->roles, true ) ) {
            $user->add_role( 'teaching_assistant' );
        }

        $ta_table = TA_Database::table( 'assistants' );
        $existing = TA_Database::get_row( "SELECT id FROM {$ta_table} WHERE user_id = %d", [ $user_id ] );

        $data = [
            'rank'    => $rank,
            'ta_code' => $ta_code ?: null,
           // 'gender_teach' => $gender_teach,
           // 'messengers'   => json_encode( array_values( array_filter( $messengers ) ) ),
        ];

        if ( $existing ) {
            TA_Database::update( 'ta_assistants', $data, [ 'user_id' => $user_id ] );
        } else {
            TA_Database::insert( 'ta_assistants', array_merge( $data, [
                'user_id'    => $user_id,
                'is_active'  => 1,
                'created_at' => current_time( 'mysql' ),
            ] ) );
        }

        wp_redirect( admin_url( 'admin.php?page=ta-assistants&saved=1' ) );
        exit;
    }

    public function handle_delete(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
        check_admin_referer( 'ta_delete_assistant' );

        $ta_id = (int) ( $_POST['ta_id'] ?? 0 );
        if ( ! $ta_id ) {
            wp_redirect( admin_url( 'admin.php?page=ta-assistants&error=no_id' ) );
            exit;
        }

        $ta_table = TA_Database::table( 'assistants' );
        $ta = TA_Database::get_row( "SELECT user_id FROM {$ta_table} WHERE id = %d", [ $ta_id ] );
        if ( $ta ) {
            $user = get_user_by( 'id', $ta->user_id );
            if ( $user ) {
                $user->remove_role( 'teaching_assistant' );
            }
        }

        TA_Database::delete( 'ta_assistants', [ 'id' => $ta_id ] );
        TA_Database::delete( 'ta_capacities', [ 'ta_id' => $ta_id ] );

        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM " . TA_Database::table( 'groups' ) . " WHERE ta_id = %d AND is_used = 0",
            [ $ta_id ]
        ) );

        wp_redirect( admin_url( 'admin.php?page=ta-assistants&deleted=1' ) );
        exit;
    }

    public function handle_toggle(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
        check_admin_referer( 'ta_toggle_assistant' );

        $ta_id     = (int) ( $_POST['ta_id'] ?? 0 );
        $is_active = (int) ( $_POST['is_active'] ?? 0 );
        TA_Database::update( 'ta_assistants', [ 'is_active' => $is_active ? 0 : 1 ], [ 'id' => $ta_id ] );
        wp_redirect( admin_url( 'admin.php?page=ta-assistants&toggled=1' ) );
        exit;
    }

    public function render(): void {
        if ( ! TA_Database::tables_exist() ) {
            echo '<div class="notice notice-error"><p>جداول پایگاه داده وجود ندارند.</p></div>';
            return;
        }

        if ( isset( $_GET['view_caps'] ) ) {
            $this->render_capacity_view( (int) $_GET['view_caps'] );
            return;
        }

        $ta_table = TA_Database::table( 'assistants' );
        global $wpdb;

        $assistants = $wpdb->get_results(
            "SELECT ta.*, u.display_name, u.user_email
             FROM {$ta_table} ta
             JOIN {$wpdb->users} u ON u.ID = ta.user_id
             ORDER BY ta.rank DESC"
        ) ?: [];

        $gender_labels    = [ 'both' => 'هر دو', 'male' => 'آقایان', 'female' => 'خانم‌ها' ];
        $messenger_labels = [ 'telegram' => 'تلگرام', 'bale' => 'بله', 'eitaa' => 'ایتا' ];
        ?>
        <div class="wrap" dir="rtl">
            <h1>مدیریت استادیارها</h1>

            <?php
            if ( isset( $_GET['saved'] ) )   echo '<div class="notice notice-success is-dismissible"><p>✅ استادیار ذخیره شد.</p></div>';
            if ( isset( $_GET['deleted'] ) ) echo '<div class="notice notice-success is-dismissible"><p>🗑️ استادیار حذف شد.</p></div>';
            if ( isset( $_GET['toggled'] ) ) echo '<div class="notice notice-info is-dismissible"><p>وضعیت تغییر کرد.</p></div>';
            if ( isset( $_GET['error'] ) )   echo '<div class="notice notice-error is-dismissible"><p>خطا رخ داد.</p></div>';
            ?>

            <div class="postbox" style="padding:16px 20px; margin-bottom:20px">
                <h2 style="margin-top:0">افزودن / ویرایش استادیار</h2>
                <form method="post" action="<?= admin_url( 'admin-post.php' ) ?>">
                    <?php wp_nonce_field( 'ta_save_assistant' ); ?>
                    <input type="hidden" name="action"     value="ta_save_assistant">
                    <input type="hidden" name="ta_user_id" id="ta_edit_user_id">
                    <table class="form-table" style="direction:rtl">
                        <tr>
                            <th style="width:140px">کاربر <span style="color:red">*</span></th>
                            <td>
                                <div style="position:relative;display:inline-block">
                                    <input type="text"
                                           id="ta_user_search_input"
                                           placeholder="نام، شماره همراه یا ایمیل..."
                                           autocomplete="off"
                                           style="min-width:360px;padding:5px 10px;border:1px solid #8c8f94;border-radius:3px;font-size:14px">
                                    <div id="ta_user_search_results"
                                         style="display:none;position:absolute;right:0;top:100%;min-width:360px;max-height:260px;
                                                overflow-y:auto;background:#fff;border:1px solid #ccc;border-top:none;
                                                border-radius:0 0 4px 4px;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.12)"></div>
                                </div>
                                <div id="ta_user_selected"
                                     style="margin-top:8px;display:none;background:#eaf7ee;border:1px solid #b2dfdb;
                                            border-radius:4px;padding:7px 12px;font-size:13px;max-width:420px">
                                    <strong id="ta_user_selected_name"></strong>
                                    <span id="ta_user_selected_meta" style="color:#555;margin-right:8px;font-size:0.85rem"></span>
                                    <button type="button" onclick="taClearUserSelection()"
                                            style="float:left;background:none;border:none;color:#c00;cursor:pointer;font-size:16px;padding:0;line-height:1">✕</button>
                                </div>
                                <p style="margin:4px 0 0;color:#888;font-size:0.82rem">جستجو بر اساس نام، شماره همراه (kerasno-phone) یا ایمیل</p>
                            </td>
                        </tr>
                        <tr>
                            <th>رتبه <small>(کمتر = اول)</small></th>
                            <td><input type="number" name="ta_rank" id="ta_edit_rank" value="0" min="0" class="small-text"></td>
                        </tr>
                        <tr>
                            <th>کد استادیار</th>
                            <td>
                                <input type="text" name="ta_code" id="ta_edit_ta_code" value="" class="small-text" placeholder="مثال: 7" style="width:100px">
                                <p class="description">عدد یا کد یکتای استادیار (اختیاری). در عنوان گروه استفاده می‌شود.</p>
                            </td>
                        </tr>
                       
                    </table>
                    <?php submit_button( 'ذخیره استادیار', 'primary', 'submit', false ); ?>
                    <button type="button" class="button" onclick="taResetForm()">پاک‌کردن</button>
                </form>
            </div>

            <h2 style="display:flex;align-items:center;gap:12px">
                لیست استادیارها (<?= count( $assistants ) ?>)
                <a href="<?= esc_url( wp_nonce_url( add_query_arg( 'action', 'export_xlsx' ), 'ta_assistants_export' ) ) ?>"
                   style="font-size:13px;background:#217346;color:#fff;padding:4px 14px;border-radius:6px;text-decoration:none;font-weight:600">⬇ خروجی اکسل</a>
            </h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="50">رتبه</th>
                        <th width="60">کد</th>
                        <th>نام</th>
                        <th>ایمیل</th>
                        <th>عنوان گروه (نمونه)</th>
                        <th width="80">ظرفیت</th>
                        <th width="70">وضعیت</th>
                        <th width="170">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $assistants ) ) : ?>
                    <tr><td colspan="8" style="text-align:center;padding:20px;color:#999">هنوز استادیاری ثبت نشده</td></tr>
                <?php endif; ?>
                <?php foreach ( $assistants as $ta ) :
                    $messengers = json_decode( $ta->messengers, true ) ?: [];
                ?>
                    <tr style="<?= ! $ta->is_active ? 'opacity:0.5' : '' ?>">
                        <td><?= $ta->rank ?></td>
                        <td>
                            <?php if ( $ta->ta_code ) : ?>
                                <code style="background:#f0f4ff;border:1px solid #c7d2fe;padding:2px 7px;border-radius:4px;font-size:13px;font-weight:700;color:#4338ca"><?= esc_html( $ta->ta_code ) ?></code>
                            <?php else : ?>
                                <span style="color:#bbb">—</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= esc_html( $ta->display_name ) ?></strong></td>
                        <td><?= esc_html( $ta->user_email ) ?></td>
                        <td>
                            <?php
                            $sample_title = $this->build_group_title_sample( $ta );
                            if ( $sample_title ) :
                            ?>
                                <span class="ta-copy-cell"
                                      data-copy="<?= esc_attr( $sample_title ) ?>"
                                      title="کلیک برای کپی"
                                      style="font-size:11px;background:#f5f3ff;border:1px dashed #a78bfa;padding:3px 8px;border-radius:4px;cursor:pointer;direction:rtl;display:inline-block;color:#5b21b6;font-family:monospace">
                                    <?= esc_html( $sample_title ) ?>
                                </span>
                                <br><small style="color:#9ca3af;font-size:10px">بر اساس فعال‌ترین ترم — کلیک = کپی</small>
                            <?php else : ?>
                                <span style="color:#bbb;font-size:12px">نیاز به کد استادیار</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= esc_url( admin_url( 'admin.php?page=ta-assistants&view_caps=' . $ta->id ) ) ?>">
                                <?= $this->get_cap_summary( $ta->id ) ?>
                            </a>
                        </td>
                        <td><?= $ta->is_active ? '<span style="color:green">●&nbsp;فعال</span>' : '<span style="color:#bbb">●&nbsp;غیرفعال</span>' ?></td>
                        <td>
                            <?php
                            $phone_val = esc_js( get_user_meta( $ta->user_id, 'kerasno-phone', true ) ?: '' );
                            ?>
                            <button type="button" class="button button-small"
                                onclick='taEditTA(
                                  <?= (int) $ta->user_id ?>,
                                 "<?= esc_js( $ta->display_name ) ?>",
                                 "<?= esc_js( $ta->user_email ) ?>",
                                 "<?= esc_js( $phone_val ) ?>",
                                  <?= (int) $ta->rank ?>,
                                 "<?= esc_js( $ta->ta_code ?? '' ) ?>"
                                   )'>
                                   
                                ✏️ ویرایش
                            </button>

                            <form method="post" action="<?= admin_url( 'admin-post.php' ) ?>" style="display:inline">
                                <?php wp_nonce_field( 'ta_toggle_assistant' ); ?>
                                <input type="hidden" name="action"    value="ta_toggle_assistant">
                                <input type="hidden" name="ta_id"     value="<?= $ta->id ?>">
                                <input type="hidden" name="is_active" value="<?= $ta->is_active ?>">
                                <button class="button button-small" title="<?= $ta->is_active ? 'غیرفعال' : 'فعال' ?>">
                                    <?= $ta->is_active ? '⏸' : '▶️' ?>
                                </button>
                            </form>

                            <form method="post" action="<?= admin_url( 'admin-post.php' ) ?>" style="display:inline"
                                  onsubmit="return confirm('آیا مطمئنید؟\nظرفیت‌های استفاده‌نشده هم حذف می‌شوند.')">
                                <?php wp_nonce_field( 'ta_delete_assistant' ); ?>
                                <input type="hidden" name="action" value="ta_delete_assistant">
                                <input type="hidden" name="ta_id"  value="<?= $ta->id ?>">
                                <button class="button button-small button-link-delete">🗑️ حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
        var taNonce   = '<?= wp_create_nonce('ta_admin_nonce') ?>';
        var taAjax    = '<?= admin_url('admin-ajax.php') ?>';
        var taSearchT = null;

        // ── Live search ─────────────────────────────────────────
        document.getElementById('ta_user_search_input').addEventListener('input', function () {
            clearTimeout(taSearchT);
            var q = this.value.trim();
            var box = document.getElementById('ta_user_search_results');
            if (q.length < 2) { box.style.display = 'none'; return; }
            taSearchT = setTimeout(function () {
                fetch(taAjax, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=ta_search_users&nonce=' + taNonce + '&q=' + encodeURIComponent(q)
                }).then(r => r.json()).then(function (res) {
                    box.innerHTML = '';
                    if (!res.success || !res.data.length) {
                        box.innerHTML = '<div style="padding:9px 12px;color:#888;font-size:0.85rem">نتیجه‌ای یافت نشد.</div>';
                        box.style.display = 'block'; return;
                    }
                    res.data.forEach(function (u) {
                        var row = document.createElement('div');
                        row.style.cssText = 'padding:9px 14px;cursor:pointer;font-size:0.88rem;border-bottom:1px solid #f0f0f0;line-height:1.4';
                        row.innerHTML = '<strong>' + u.name + '</strong>'
                            + (u.phone ? ' <span style="color:#0073aa;font-size:0.82rem">📱 ' + u.phone + '</span>' : '')
                            + '<br><span style="color:#888;font-size:0.8rem">' + u.email + '</span>';
                        row.addEventListener('mouseover', function(){this.style.background='#f0f6fb'});
                        row.addEventListener('mouseout',  function(){this.style.background=''});
                        row.addEventListener('click', function () {
                            taSelectUser(u.id, u.name, u.phone, u.email);
                            box.style.display = 'none';
                        });
                        box.appendChild(row);
                    });
                    box.style.display = 'block';
                });
            }, 300);
        });

        // بستن dropdown با کلیک خارج
        document.addEventListener('click', function (e) {
            var box = document.getElementById('ta_user_search_results');
            if (!document.getElementById('ta_user_search_input').contains(e.target)) {
                box.style.display = 'none';
            }
        });

        function taSelectUser(id, name, phone, email) {
            document.getElementById('ta_edit_user_id').value    = id;
            document.getElementById('ta_user_search_input').value = name;
            document.getElementById('ta_user_selected_name').textContent = name;
            document.getElementById('ta_user_selected_meta').textContent =
                (phone ? '📱 ' + phone + '  ' : '') + '✉️ ' + email;
            document.getElementById('ta_user_selected').style.display = 'block';
        }

        function taClearUserSelection() {
            document.getElementById('ta_edit_user_id').value     = '';
            document.getElementById('ta_user_search_input').value = '';
            document.getElementById('ta_user_selected').style.display = 'none';
            document.getElementById('ta_user_search_input').focus();
        }

        // ── ویرایش استادیار موجود ───────────────────────────────
        function taEditTA(userId, userName, userEmail, userPhone, rank, taCode) {
            taSelectUser(userId, userName, userPhone || '', userEmail);
            document.getElementById('ta_edit_rank').value    = rank;
            document.getElementById('ta_edit_ta_code').value = taCode || '';
            window.scrollTo({top: 0, behavior: 'smooth'});
        }

        function taResetForm() {
            taClearUserSelection();
            document.getElementById('ta_edit_rank').value    = 0;
            document.getElementById('ta_edit_ta_code').value = '';
        }

        // ── کپی با کلیک (عنوان گروه) ──────────────────────────────────────
        (function(){
            var toast = document.createElement('div');
            toast.style.cssText = 'position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(20px);'
                + 'background:#1e293b;color:#fff;padding:9px 22px;border-radius:8px;font-size:13px;'
                + 'opacity:0;transition:all .25s;z-index:99999;pointer-events:none;direction:rtl';
            toast.textContent = '✓ کپی شد';
            document.body.appendChild(toast);
            function showToast(){
                toast.style.opacity='1'; toast.style.transform='translateX(-50%) translateY(0)';
                clearTimeout(toast._t);
                toast._t=setTimeout(function(){toast.style.opacity='0';toast.style.transform='translateX(-50%) translateY(20px)';},1800);
            }
            document.addEventListener('click',function(e){
                var el=e.target.closest('.ta-copy-cell');
                if(!el)return;
                var text=el.dataset.copy||el.textContent.trim();
                if(navigator.clipboard&&navigator.clipboard.writeText){
                    navigator.clipboard.writeText(text).then(showToast);
                }else{
                    var ta=document.createElement('textarea');ta.value=text;ta.style.position='fixed';ta.style.opacity='0';
                    document.body.appendChild(ta);ta.select();document.execCommand('copy');document.body.removeChild(ta);showToast();
                }
            });
        })();
        </script>
        <?php
    }

    // ── نمایش ظرفیت‌های استادیار ─────────────────────────────────────────────
    private function render_capacity_view( int $ta_id ): void {
        global $wpdb;

        $ta_table  = TA_Database::table( 'assistants' );
        $cap_table = TA_Database::table( 'capacities' );
        $gr_table  = TA_Database::table( 'groups' );

        $ta = $wpdb->get_row( $wpdb->prepare(
            "SELECT ta.*, u.display_name FROM {$ta_table} ta
             JOIN {$wpdb->users} u ON u.ID = ta.user_id WHERE ta.id = %d",
            [ $ta_id ]
        ) );

        if ( ! $ta ) {
            echo '<div class="wrap"><p>استادیار یافت نشد.</p><a href="' . admin_url( 'admin.php?page=ta-assistants' ) . '" class="button">← بازگشت</a></div>';
            return;
        }

        $caps = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, t.name AS term_name
             FROM {$cap_table} c
             LEFT JOIN " . TA_Database::table( 'terms' ) . " t ON t.id = c.term_id
             WHERE c.ta_id = %d
             ORDER BY t.order_index DESC, c.course_type, c.student_gender",
            [ $ta_id ]
        ) ) ?: [];

        $course_labels = [
            'creative'     => 'نویسندگی خلاق',
            'intro'        => 'نویسندگی مقدماتی',
            'advanced'     => 'نویسندگی پیشرفته',
            'level_assess' => 'تعیین سطح',
        ];
       // $gender_labels = [ 'male' => 'آقایان', 'female' => 'خانم‌ها', 'both' => 'هر دو' ];
        $m_labels      = [ 'telegram' => '✈️ تلگرام', 'bale' => '🔵 بله', 'eitaa' => '🟢 ایتا' ];
        ?>
        <div class="wrap" dir="rtl">
            <h1>ظرفیت‌های <?= esc_html( $ta->display_name ) ?></h1>
            <a href="<?= admin_url( 'admin.php?page=ta-assistants' ) ?>" class="button">← بازگشت به لیست</a>
            <hr style="margin:16px 0">

            <?php if ( empty( $caps ) ) : ?>
                <div class="notice notice-warning">
                    <p>هیچ ظرفیتی ثبت نشده. استادیار باید از <strong>داشبورد فرانت‌اند</strong> (<code>[ta_dashboard]</code>) ظرفیت‌های خود را تعریف کند.</p>
                </div>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ترم</th>
                        <th>نوع دوره</th>
                        <th>جنسیت هنرجو</th>
                        <th width="80">کل</th>
                        <th width="80">استفاده‌شده</th>
                        <th width="80">باقی‌مانده</th>
                        <th>گروه‌ها (فقط خلاق)</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $caps as $cap ) :
                    $groups = [];
                    if ( $cap->course_type === 'creative' ) {
                        $groups = $wpdb->get_results( $wpdb->prepare(
                            "SELECT g.*, u.display_name AS student_name
                             FROM {$gr_table} g
                             LEFT JOIN {$wpdb->users} u ON u.ID = g.assigned_to_user_id
                             WHERE g.capacity_id = %d ORDER BY g.is_used DESC, g.id ASC",
                            [ $cap->id ]
                        ) ) ?: [];
                    }
                    $remaining  = (int) $cap->total_capacity - (int) $cap->used_capacity;
                    $row_color  = match ( $cap->student_gender ) {
                        'female' => '#fce4ec',
                        'male'   => '#e3f2fd',
                        default  => '#f3e5f5',
                    };
                ?>
                    <tr>
                        <td><?= esc_html( $cap->term_name ?? '—' ) ?></td>
                        <td><?= $course_labels[ $cap->course_type ] ?? $cap->course_type ?></td>
                        <td>
                            <span style="background:<?= $row_color ?>;padding:2px 10px;border-radius:12px;font-size:12px">
                                <?= $gender_labels[ $cap->student_gender ] ?? $cap->student_gender ?>
                            </span>
                        </td>
                        <td><?= (int) $cap->total_capacity ?></td>
                        <td><?= (int) $cap->used_capacity ?></td>
                        <td><strong style="color:<?= $remaining > 0 ? 'green' : '#c00' ?>"><?= $remaining ?></strong></td>
                        <td>
                            <?php if ( $cap->course_type !== 'creative' ) : ?>
                                <em style="color:#999">—</em>
                            <?php elseif ( empty( $groups ) ) : ?>
                                <em style="color:#999">بدون گروه</em>
                            <?php else : ?>
                                <details>
                                    <summary style="cursor:pointer"><?= count( $groups ) ?> گروه</summary>
                                    <ul style="margin:6px 0;padding-right:16px;font-size:13px">
                                    <?php foreach ( $groups as $g ) : ?>
                                        <li style="margin-bottom:4px">
                                            <?= $m_labels[ $g->messenger_type ] ?? $g->messenger_type ?>
                                            — <a href="<?= esc_url( $g->group_link ) ?>" target="_blank">مشاهده گروه</a>
                                            — <?php if ( $g->is_used ) : ?>
                                                <span style="color:#c00">اختصاص‌یافته</span>
                                                <?= $g->student_name ? ' به ' . esc_html( $g->student_name ) : '' ?>
                                              <?php else : ?>
                                                <span style="color:green">آزاد</span>
                                              <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function get_cap_summary( int $ta_id ): string {
        $table = TA_Database::table( 'capacities' );
        $row   = TA_Database::get_row(
            "SELECT SUM(total_capacity) AS total, SUM(used_capacity) AS used FROM {$table} WHERE ta_id = %d",
            [ $ta_id ]
        );
        if ( ! $row || ! $row->total ) return '0 ظرفیت';
        return (int) $row->used . '/' . (int) $row->total;
    }

    /**
     * ساخت نمونه عنوان گروه برای یک استادیار
     * فرمت: [نام] [نام‌خانوادگی] _ [کد استادیار] [حرف اول دوره] [کد فصل+سال]
     * مثال: علی محمدی _ 7 خ 105
     */
    private function build_group_title_sample( object $ta ): string {
        if ( empty( $ta->ta_code ) ) return '';

        global $wpdb;
        $assign_tbl = TA_Database::table( 'assignments' );
        $term_tbl   = TA_Database::table( 'terms' );

        // آخرین تخصیص فعال این استادیار را بگیر
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.course_type, t.name AS term_name
             FROM {$assign_tbl} a
             LEFT JOIN {$term_tbl} t ON t.id = a.term_id
             WHERE a.ta_id = %d AND a.status = 'active'
             ORDER BY a.id DESC LIMIT 1",
            $ta->id
        ) );

        if ( ! $row ) {
            // اگر تخصیص نبود، از ترم فعال سیستم استفاده کن
            $active_term_id = (int) get_option( 'ta_active_term_id', 0 );
            if ( $active_term_id ) {
                $term = TA_Database::get_row(
                    "SELECT name FROM {$term_tbl} WHERE id = %d", [ $active_term_id ]
                );
                $course_type = 'creative';
                $term_name   = $term->name ?? '';
            } else {
                return '';
            }
        } else {
            $course_type = $row->course_type;
            $term_name   = $row->term_name ?? '';
        }

        $season_code   = $this->term_name_to_season_code( $term_name );
        $course_letter = $this->course_type_to_letter( $course_type );
        $name_parts    = explode( ' ', trim( $ta->display_name ), 2 );
        $first_name    = $name_parts[0] ?? '';
        $last_name     = $name_parts[1] ?? '';

        return trim( "{$first_name} {$last_name}" ) . " _ " . $this->to_persian_digits( (string) $ta->ta_code ) . " {$course_letter} {$season_code}";
    }

    private function to_persian_digits( string $str ): string {
        return str_replace(
            ['0','1','2','3','4','5','6','7','8','9'],
            ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],
            $str
        );
    }

    /**
     * تبدیل نام ترم به کد فصل+سال — اعداد فارسی
     * «بهار 1405» → ۱۰۵ | «تابستان 1405» → ۲۰۵
     */
    private function term_name_to_season_code( string $term_name ): string {
        $season_map = [
            'بهار'     => 1,
            'تابستان'  => 2,
            'پاییز'    => 3,
            'زمستان'   => 4,
        ];
        preg_match( '/(\d{4})/', $term_name, $year_m );
        $year = $year_m[1] ?? '';

        foreach ( $season_map as $fa => $num ) {
            if ( str_contains( $term_name, $fa ) ) {
                return $this->to_persian_digits( $num . substr( $year, -2 ) );
            }
        }

        $slug_map = [
            'spring' => 1, 'summer' => 2, 'fall' => 3, 'autumn' => 3, 'winter' => 4,
        ];
        foreach ( $slug_map as $en => $num ) {
            if ( str_contains( strtolower( $term_name ), $en ) ) {
                return $this->to_persian_digits( $num . substr( $year, -2 ) );
            }
        }

        return $this->to_persian_digits( $year ? substr( $year, -2 ) : '' );
    }

    /**
     * حرف اول نوع دوره
     */
    private function course_type_to_letter( string $course_type ): string {
        $map = [
            'creative'      => 'خ',  // خلاق
            'intro'         => 'م',  // مقدماتی
            'advanced'      => 'پ',  // پیشرفته
            'professional'  => 'ح',  // حرفه‌ای
            'critique'      => 'ن',  // نقد
            'basic_writing' => 'ا',  // اولیه
            'ladder'        => 'ن',  // نردبان
            'level_assess'  => 'س',  // سطح
        ];
        return $map[ $course_type ] ?? mb_substr( $course_type, 0, 1 );
    }
    public function export_xlsx(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );

        global $wpdb;
        $ta_table     = TA_Database::table( 'assistants' );
        $assign_table = TA_Database::table( 'assignments' );
        $cap_table    = TA_Database::table( 'capacities' );

        $assistants = $wpdb->get_results(
            "SELECT ta.*, u.display_name, u.user_email
             FROM {$ta_table} ta
             JOIN {$wpdb->users} u ON u.ID = ta.user_id
             ORDER BY ta.rank DESC"
        ) ?: [];

        // phone numbers
        $phone_map = [];
        $uids = array_filter( array_unique( array_column( (array)$assistants, 'user_id' ) ) );
        if ( $uids ) {
            $plist  = implode( ',', array_map('intval', $uids) );
            $phones = $wpdb->get_results(
                "SELECT user_id,meta_key,meta_value FROM {$wpdb->usermeta}
                 WHERE user_id IN ({$plist}) AND meta_key IN ('kerasno-phone','phone','Mreeir_phone','billing_phone')"
            );
            $prio = ['kerasno-phone'=>1,'phone'=>2,'Mreeir_phone'=>3,'billing_phone'=>4];
            foreach ( $phones as $pm ) {
                $uid=(int)$pm->user_id; $p=$prio[$pm->meta_key]??99;
                if ($pm->meta_value&&(!isset($phone_map[$uid])||$p<$phone_map[$uid]['p']))
                    $phone_map[$uid]=['v'=>$pm->meta_value,'p'=>$p];
            }
            $phone_map = array_map(fn($x)=>$x['v'], $phone_map);
        }

        // active assignment count per ta
        $counts = [];
        $rows_c = $wpdb->get_results(
            "SELECT ta_id, COUNT(*) AS cnt FROM {$assign_table} WHERE status='active' GROUP BY ta_id"
        ) ?: [];
        foreach ( $rows_c as $rc ) $counts[ (int)$rc->ta_id ] = (int)$rc->cnt;

        // total capacity per ta
        $caps = [];
        $rows_cap = $wpdb->get_results(
            "SELECT ta_id, SUM(total_capacity) AS cap FROM {$cap_table} GROUP BY ta_id"
        ) ?: [];
        foreach ( $rows_cap as $rc ) $caps[ (int)$rc->ta_id ] = (int)$rc->cap;

        $gender_labels    = [ 'both'=>'هر دو','male'=>'آقایان','female'=>'خانم‌ها' ];

        while ( ob_get_level() ) ob_end_clean();

        $writer = new TA_Xlsx_Writer();
        $writer->set_rtl( true );
        $writer->add_row( [
            'ردیف','نام استادیار','ایمیل','شماره همراه',
            'کد','رتبه','ظرفیت کل','هنرجویان فعال','باقیمانده','وضعیت',
        ], true );

        foreach ( $assistants as $i => $ta ) {
            $tid   = (int)$ta->id;
            $cap   = $caps[$tid]   ?? 0;
            $used  = $counts[$tid] ?? 0;
            $writer->add_row( [
                $i + 1,
                $ta->display_name ?? '',
                $ta->user_email   ?? '',
                $phone_map[ (int)$ta->user_id ] ?? '',
                $ta->ta_code      ?? '',
                $ta->rank         ?? 0,
                $cap,
                $used,
                max(0, $cap - $used),
                $ta->is_active ? 'فعال' : 'غیرفعال',
            ], false, true );
        }
        $writer->output( 'assistants-' . date('Y-m-d') . '.xlsx' );
        exit;
    }

}