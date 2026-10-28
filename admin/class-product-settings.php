<?php
defined( 'ABSPATH' ) || exit;

class TA_Product_Settings {

    public function __construct() {
        add_action( 'woocommerce_product_data_tabs',   [ $this, 'add_tab' ] );
        add_action( 'woocommerce_product_data_panels', [ $this, 'render_panel' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save' ] );
        add_action( 'admin_head', [ $this, 'inline_styles' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_ta_search_prerequisite_products', [ $this, 'ajax_search_products' ] );
    }

    public function enqueue_admin_assets( string $hook ): void {
        if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) return;
        if ( get_current_screen() && get_current_screen()->id === 'product' ) {
            wp_enqueue_script( 'selectWoo' );
            wp_enqueue_style( 'select2' );
        }
    }

    public function ajax_search_products(): void {
        check_ajax_referer( 'ta_prerequisite_search', 'security' );
        if ( ! current_user_can( 'edit_products' ) || ! function_exists( 'wc_get_products' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ], 403 );
        }
        $term = sanitize_text_field( wp_unslash( $_GET['term'] ?? '' ) );
        $exclude = absint( $_GET['exclude'] ?? 0 );
        global $wpdb;
        $settings_table = TA_Database::table( 'product_settings' );
        $term_product_ids = array_map( 'absint', $wpdb->get_col(
            "SELECT product_id FROM {$settings_table} WHERE term_id IS NOT NULL AND term_id > 0"
        ) );
        if ( $exclude ) $term_product_ids = array_values( array_diff( $term_product_ids, [ $exclude ] ) );
        if ( empty( $term_product_ids ) ) wp_send_json( [ 'results' => [] ] );
        if ( $term !== '' ) {
            $like = '%' . $wpdb->esc_like( $term ) . '%';
            $title_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_title LIKE %s",
                $like
            ) );
            $term_product_ids = array_values( array_intersect( $term_product_ids, array_map( 'absint', $title_ids ) ) );
            if ( empty( $term_product_ids ) ) wp_send_json( [ 'results' => [] ] );
        }
        $product_args = [
            'status' => [ 'publish', 'draft', 'pending', 'private' ], 'limit' => 30,
            'exclude' => $exclude ? [ $exclude ] : [],
            'orderby' => 'title', 'order' => 'ASC', 'return' => 'objects',
            'include' => $term_product_ids,
        ];
        // فیلتر نوع دوره: یک مقدار یا چند مقدار جداشده با ویرگول (مثلاً intro,basic_writing)
        $allowed_types = [ 'creative', 'intro', 'basic_writing', 'critique', 'advanced', 'ladder', 'professional' ];
        $type_filter   = array_values( array_intersect(
            array_map( 'sanitize_key', explode( ',', (string) wp_unslash( $_GET['course_type'] ?? '' ) ) ),
            $allowed_types
        ) );
        if ( ! empty( $type_filter ) ) {
            $type_placeholders = implode( ',', array_fill( 0, count( $type_filter ), '%s' ) );
            $type_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT product_id FROM {$settings_table} WHERE course_type IN ({$type_placeholders})",
                ...$type_filter
            ) );
            $product_args['include'] = array_values( array_intersect( $product_args['include'], array_map( 'absint', $type_ids ) ) );
            if ( empty( $product_args['include'] ) ) wp_send_json( [ 'results' => [] ] );
        }
        $products = wc_get_products( $product_args );
        $results = [];
        foreach ( $products as $product ) {
            $results[] = [ 'id' => (string) $product->get_id(), 'text' => $product->get_name() . ' (ID: ' . $product->get_id() . ')' ];
        }
        wp_send_json( [ 'results' => $results ] );
    }

    public function inline_styles(): void {
        if ( ! get_current_screen() || get_current_screen()->id !== 'product' ) return;
        echo '<style>
            #ta_prereq_row .select2-container, #ta_pass_eligible_row .select2-container { width: 60% !important; min-width: 360px; }
            #ta_prereq_row .select2-selection--multiple, #ta_pass_eligible_row .select2-selection--multiple { min-height: 42px; border: 1px solid #8c8f94; border-radius: 4px; padding: 3px 5px; background: #fff; }
            #ta_prereq_row .select2-selection__choice, #ta_pass_eligible_row .select2-selection__choice { background: #eef4ff; border: 1px solid #b8c9ee; color: #1d3d78; border-radius: 4px; padding: 4px 8px; }
            #ta_prereq_row .select2-search__field, #ta_pass_eligible_row .select2-search__field { min-width: 180px; padding: 5px; }
            #ta_prereq_row .ta-prereq-help, #ta_pass_eligible_row .ta-prereq-help { color: #666; font-style: italic; font-size: 12px; margin-top: 7px; display: block; }
        </style>';
    }

    public function add_tab( array $tabs ): array {
        $tabs['ta_settings'] = [
            'label'    => 'تنظیمات استادیار',
            'target'   => 'ta_settings_panel',
            'class'    => [],
            'priority' => 80,
        ];
        return $tabs;
    }

    public function render_panel(): void {
        global $post;
        global $wpdb;

        $terms    = TA_Database::get_results(
            "SELECT * FROM " . TA_Database::table( 'terms' ) . " ORDER BY order_index ASC"
        );
        $settings  = TA_Product_Meta::get_settings( $post->ID );
        $prereq_ids = TA_Product_Meta::get_prerequisites( $post->ID );
        $pass_eligible_ids = TA_Product_Meta::get_pass_eligible_prerequisites( $post->ID );

        // نوع دورهٔ تمام محصولات (برای فیلتر کردن مقدماتی‌ها در کادر «قبولی مستقیم»)
        $course_type_map = [];
        $settings_table   = TA_Database::table( 'product_settings' );
        foreach ( TA_Database::get_results( "SELECT product_id, course_type FROM {$settings_table}" ) as $row ) {
            $course_type_map[ (int) $row->product_id ] = $row->course_type;
        }

        // فهرست اولیه فقط شامل محصولات دارای ترم است؛ جست‌وجو روی همین فهرست انجام می‌شود.
        if ( ! function_exists( 'wc_get_products' ) ) { return; }
        $term_product_ids = array_map( 'absint', $wpdb->get_col(
            "SELECT product_id FROM {$settings_table} WHERE term_id IS NOT NULL AND term_id > 0 AND product_id != " . (int) $post->ID
        ) );
        $products = wc_get_products( [
            'limit'   => -1,
            'include' => $term_product_ids,
            'status'  => [ 'publish', 'draft', 'pending', 'private' ],
            'orderby' => 'include',
        ] );
        
        // این خط را بعد از دریافت $products در متد render_panel اضافه کنید

     //   $tas = get_users( ['role' => 'teaching_assistant', 'orderby' => 'display_name'] );
$ta_table = TA_Database::table( 'assistants' );

// دریافت استادیاران فعال و مرتب‌سازی بر اساس رتبه با اتصال به جدول کاربران وردپرس
$tas = $wpdb->get_results( "
    SELECT u.ID, u.display_name 
    FROM {$ta_table} ta
    JOIN {$wpdb->users} u ON ta.user_id = u.ID
    WHERE ta.is_active = 1 
    ORDER BY ta.rank DESC
" );






        $selected_term = $settings ? (int) $settings->term_id : 0;
        $selected_type = $settings ? $settings->course_type : '';
        ?>
        <div id="ta_settings_panel" class="panel woocommerce_options_panel" style="direction:rtl">
            <div class="options_group">

                <p class="form-field">
                    <label for="ta_term_id">ترم:</label>
                    <select name="ta_term_id" id="ta_term_id" style="width:60%">
                        <option value="">— انتخاب کنید —</option>
                        <?php foreach ( $terms as $term ) : ?>
                            <option value="<?= $term->id ?>" <?= selected( $selected_term, $term->id, false ) ?>>
                                <?= esc_html( $term->name ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <p class="form-field">
                    <label for="ta_course_type_select">نوع دوره:</label>
                    <select name="ta_course_type" id="ta_course_type_select" style="width:60%">
                        <option value="">— انتخاب کنید —</option>
                        <option value="creative"     <?= selected( $selected_type, 'creative',     false ) ?>>نویسندگی خلاق</option>
                        <option value="intro"        <?= selected( $selected_type, 'intro',        false ) ?>>نویسندگی مقدماتی</option>
                        <option value="basic_writing" <?= selected( $selected_type, 'basic_writing', false ) ?>>نویسندگی پایه</option>
                        <option value="critique"     <?= selected( $selected_type, 'critique',     false ) ?>>نقد اثر</option>
                        <option value="advanced"     <?= selected( $selected_type, 'advanced',     false ) ?>>نویسندگی پیشرفته</option>
                        <option value="ladder"       <?= selected( $selected_type, 'ladder',       false ) ?>>نردبان حرفه‌ای</option>
                        <option value="professional" <?= selected( $selected_type, 'professional', false ) ?>>نویسندگی حرفه‌ای</option>
                    </select>
                </p>

                <p class="form-field" id="ta_prereq_row" style="<?= in_array($selected_type, ['creative','critique','basic_writing','']) ? 'display:none' : '' ?>">
                    <label for="ta_prerequisite_product_ids">محصولات پیش‌نیاز:</label>
                    <select name="ta_prerequisite_product_ids[]"
                            id="ta_prerequisite_product_ids"
                            multiple
                            class="ta-product-search"
                            data-exclude="<?= (int) $post->ID ?>"
                            data-nonce="<?= esc_attr( wp_create_nonce( 'ta_prerequisite_search' ) ) ?>">
                        <?php foreach ( $products as $product ) :
                            $pid = $product->get_id();
                        ?>
                            <option value="<?= $pid ?>"
                                <?= in_array( $pid, $prereq_ids, true ) ? 'selected' : '' ?>>
                                <?= esc_html( $product->get_name() ) ?> (ID: <?= $pid ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="ta-prereq-help">
                        💡 چند محصول را با Ctrl+کلیک انتخاب کنید. گذراندن هر یک از آن‌ها برای ورود به این دوره کافی است.
                    </span>
                </p>

                <p class="form-field" id="ta_pass_eligible_row" style="<?= $selected_type === 'advanced' ? '' : 'display:none' ?>">
                    <label for="ta_pass_eligible_product_ids">مقدماتی‌های مجاز برای «قبولی مستقیم»:</label>
                    <select name="ta_pass_eligible_product_ids[]"
                            id="ta_pass_eligible_product_ids"
                            multiple
                            class="ta-product-search"
                            data-exclude="<?= (int) $post->ID ?>"
                            data-course-type="intro,basic_writing"
                            data-nonce="<?= esc_attr( wp_create_nonce( 'ta_prerequisite_search' ) ) ?>">
                        <?php foreach ( $products as $product ) :
                            $pid = $product->get_id();
                            if ( ! in_array( $course_type_map[ $pid ] ?? '', [ 'intro', 'basic_writing' ], true ) ) { continue; }
                        ?>
                            <option value="<?= $pid ?>"
                                <?= in_array( $pid, $pass_eligible_ids, true ) ? 'selected' : '' ?>>
                                <?= esc_html( $product->get_name() ) ?> (ID: <?= $pid ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="ta-prereq-help">
                        💡 فقط از بین محصولات «مقدماتی» و «پایه»ای که بالا به‌عنوان پیش‌نیاز انتخاب کرده‌اید. قبولی در هر یک از این موارد به‌تنهایی برای ورود کافی است.
                        مقدماتی‌هایی که اینجا انتخاب نشوند، فقط برای بررسی نمرهٔ «نقد اثر» در نظر گرفته می‌شوند (نه قبولی مستقیم).
                        اگر هیچ‌کدام را انتخاب نکنید، رفتار پیش‌فرض اعمال می‌شود: قبولی هر مقدماتیِ موجود در پیش‌نیاز، کافی است.
                    </span>
                </p>






            </div>

            <div class="options_group" id="ta-live-status-bar" style="padding:10px 16px;background:#f0f4ff;border-top:1px solid #ddd;display:none">
                <p style="margin:0;color:#333;font-size:12px" id="ta-live-status-text"></p>
            </div>
        </div>

        <script>
        (function() {
            function initTaProductSearch() {
                if ( typeof jQuery === 'undefined' || ! jQuery.fn.selectWoo ) return;
                jQuery('.ta-product-search').each(function() {
                    var $field = jQuery(this);
                    if ( $field.hasClass('select2-hidden-accessible') ) return;
                    $field.selectWoo({
                        width: '60%', dir: 'rtl',
                        placeholder: 'برای جست‌وجو نام محصول را تایپ کنید…',
                        minimumInputLength: 0,
                        ajax: {
                            url: ajaxurl, dataType: 'json', delay: 250,
                            data: function(params) {
                                return { action: 'ta_search_prerequisite_products', security: $field.data('nonce'), term: params.term || '', exclude: $field.data('exclude'), course_type: $field.data('course-type') || '' };
                            },
                            processResults: function(data) { return data; }, cache: true
                        }
                    });
                });
            }
            initTaProductSearch();
            var typeLabels = {
                'creative':      'نویسندگی خلاق',
                'intro':         'نویسندگی مقدماتی',
                'basic_writing': 'نویسندگی پایه',
                'critique':      'نقد اثر',
                'advanced':      'نویسندگی پیشرفته',
                'ladder':        'نردبان حرفه‌ای',
                'professional':  'نویسندگی حرفه‌ای'
            };
            var noPrereq = ['creative', 'critique', 'basic_writing', ''];

            function updateLiveStatus() {
                var typeVal  = document.getElementById('ta_course_type_select').value;
                var termEl   = document.getElementById('ta_term_id');
                var termText = termEl ? termEl.options[termEl.selectedIndex].text : '—';
                var bar      = document.getElementById('ta-live-status-bar');
                var txt      = document.getElementById('ta-live-status-text');

                if (!typeVal) { bar.style.display = 'none'; return; }

                var typeLabel   = typeLabels[typeVal] || typeVal;
                var needsPrereq = !noPrereq.includes(typeVal);
                var prereqStr;

                if (!needsPrereq) {
                    prereqStr = '<span style="color:#888">ندارد</span>';
                } else {
                    var prereqEl = document.getElementById('ta_prerequisite_product_ids');
                    var selected = [];
                    if (prereqEl) {
                        for (var i = 0; i < prereqEl.options.length; i++) {
                            if (prereqEl.options[i].selected) {
                                selected.push(prereqEl.options[i].text.replace(/\s*\(ID:.*\)$/, '').trim());
                            }
                        }
                    }
                    prereqStr = selected.length
                        ? '<span style="color:#0a7c3a">' + selected.join(' یا ') + '</span>'
                        : '<span style="color:#c0392b">⚠️ هنوز انتخاب نشده — حتماً انتخاب کنید</span>';
                }

                txt.innerHTML = '✅ نوع دوره: <strong>' + typeLabel + '</strong>'
                    + '  |  📅 ترم: <strong>' + termText + '</strong>'
                    + '  |  پیش‌نیاز: ' + prereqStr;
                bar.style.display = 'block';
            }

            function handleTypeChange() {
                var val = this.value;
                // پیش‌نیاز
                document.getElementById('ta_prereq_row').style.display =
                    noPrereq.includes(val) ? 'none' : '';
                // مقدماتی‌های مجاز برای قبولی مستقیم — فقط برای پیشرفته
                var passRow = document.getElementById('ta_pass_eligible_row');
                if (passRow) passRow.style.display = (val === 'advanced') ? '' : 'none';
                updateLiveStatus();
            }

            var typeEl = document.getElementById('ta_course_type_select');
            var termEl = document.getElementById('ta_term_id');
            var prereqEl = document.getElementById('ta_prerequisite_product_ids');

            if (typeEl)   typeEl.addEventListener('change', handleTypeChange);
            if (termEl)   termEl.addEventListener('change', updateLiveStatus);
            if (prereqEl) prereqEl.addEventListener('change', updateLiveStatus);

            // نمایش اولیه
            updateLiveStatus();
        })();
        </script>
        <?php
    }

    public function save( int $product_id ): void {
    // 1. بررسی ارسال فرم و وجود فیلد ترم
    if ( ! isset( $_POST['ta_term_id'] ) ) {
        return;
    }

    // 2. دریافت و پاکسازی مقادیر فرم
    $term_id        = (int) sanitize_text_field( $_POST['ta_term_id'] ?? '' );
    $course_type    = sanitize_text_field( $_POST['ta_course_type'] ?? '' );
    $prereq_ids     = array_map( 'intval', (array) ( $_POST['ta_prerequisite_product_ids'] ?? [] ) );

    // زیرمجموعهٔ مقدماتی‌های مجاز برای «قبولی مستقیم» — فقط برای دوره پیشرفته معنا دارد.
    // اگر فیلد اصلاً ارسال نشده باشد (مثلاً نوع دوره غیر از advanced است)، null می‌فرستیم
    // تا رفتار پیش‌فرض (بدون محدودیت) حفظ شود.
    $pass_eligible_ids = isset( $_POST['ta_pass_eligible_product_ids'] )
        ? array_map( 'intval', (array) $_POST['ta_pass_eligible_product_ids'] )
        : null;

    $pro_group_link = ''; // لینک گروه ثابت حذف شده — از ظرفیت‌های استادیاران استفاده می‌شود

    // 3. اگر ترم یا نوع دوره خالی باشد، هیچ چیز ذخیره نمی‌شود!
    if ( ! $term_id || ! $course_type ) {
        return;
    }

    // 4. برای دوره خلاق پیش‌نیاز نداریم
    if ( $course_type === 'creative' ) {
        $prereq_ids = [];
    }

    // استادیار نقد اثر و حرفه‌ای دیگر از تنظیمات محصول تعیین نمی‌شود — از ظرفیت‌های استادیاران استفاده می‌شود


    // 6. ذخیره سایر تنظیمات شامل لینک گروه در جدول/متای اختصاصی
    TA_Product_Meta::save_settings( $product_id, $term_id, $course_type, $prereq_ids, $pro_group_link, $pass_eligible_ids );
}

}
