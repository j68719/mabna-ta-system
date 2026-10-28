<?php

/**
 * مدیریت تخصیص‌ها بر اساس سفارشات مشتریان (فقط محصولات دارای ترم)
 */
class TA_Admin_Customers {

    /**
     * تبدیل تاریخ میلادی به شمسی (بدون نیاز به کتابخانه)
     * @param string $datetime رشته تاریخ میلادی (مثل: 2024-03-15 10:30:00)
     * @return string تاریخ شمسی (مثل: ۱۴۰۳/۱۲/۲۵)
     */
    /**
     * توابع کمکی الگوریتم Borkowski برای تبدیل دقیق میلادی↔شمسی
     * (همان الگوریتمی که کتابخانه‌ی معتبر jalaali-js از آن استفاده می‌کند)
     */
    private static function ta_div( $a, $b ) {
        return intdiv( (int) $a, (int) $b );
    }

    private static function ta_mod( $a, $b ) {
        $r = $a % $b;
        return $r;
    }

    private static function ta_jal_cal( $jy ) {
        $breaks = [ -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178 ];
        $gy     = $jy + 621;
        $leap_j = -14;
        $jp     = $breaks[0];
        $jm = 0; $jump = 0;

        for ( $i = 1; $i < count( $breaks ); $i++ ) {
            $jm   = $breaks[ $i ];
            $jump = $jm - $jp;
            if ( $jy < $jm ) {
                break;
            }
            $leap_j = $leap_j + self::ta_div( $jump, 33 ) * 8 + self::ta_div( self::ta_mod( $jump, 33 ), 4 );
            $jp     = $jm;
        }

        $n      = $jy - $jp;
        $leap_j = $leap_j + self::ta_div( $n, 33 ) * 8 + self::ta_div( self::ta_mod( $n, 33 ) + 3, 4 );

        if ( self::ta_mod( $jump, 33 ) === 4 && $jump - $n === 4 ) {
            $leap_j += 1;
        }

        $leap_g = self::ta_div( $gy, 4 ) - self::ta_div( ( self::ta_div( $gy, 100 ) + 1 ) * 3, 4 ) - 150;
        $march  = 20 + $leap_j - $leap_g;

        if ( $jump - $n < 6 ) {
            $n = $n - $jump + self::ta_div( $jump + 4, 33 ) * 33;
        }

        $leap = self::ta_mod( self::ta_mod( $n + 1, 33 ) - 1, 4 );
        if ( $leap === -1 ) {
            $leap = 4;
        }

        return [ 'leap' => $leap, 'gy' => $gy, 'march' => $march ];
    }

    private static function ta_g2d( $gy, $gm, $gd ) {
        $d = self::ta_div( ( $gy + self::ta_div( $gm - 8, 6 ) + 100100 ) * 1461, 4 )
            + self::ta_div( 153 * self::ta_mod( $gm + 9, 12 ) + 2, 5 )
            + $gd - 34840408;
        $d = $d - self::ta_div( self::ta_div( $gy + 100100 + self::ta_div( $gm - 8, 6 ), 100 ) * 3, 4 ) + 752;
        return $d;
    }

    private static function ta_d2g( $jdn ) {
        $j = 4 * $jdn + 139361631;
        $j = $j + self::ta_div( self::ta_div( 4 * $jdn + 183187720, 146097 ) * 3, 4 ) * 4 - 3908;
        $i  = self::ta_div( self::ta_mod( $j, 1461 ), 4 ) * 5 + 308;
        $gd = self::ta_div( self::ta_mod( $i, 153 ), 5 ) + 1;
        $gm = self::ta_mod( self::ta_div( $i, 153 ), 12 ) + 1;
        $gy = self::ta_div( $j, 1461 ) - 100100 + self::ta_div( 8 - $gm, 6 );
        return [ 'gy' => $gy, 'gm' => $gm, 'gd' => $gd ];
    }

    private static function ta_d2j( $jdn ) {
        $g2  = self::ta_d2g( $jdn );
        $gy  = $g2['gy'];
        $jy  = $gy - 621;
        $r   = self::ta_jal_cal( $jy );
        $jdn1f = self::ta_g2d( $gy, 3, $r['march'] );
        $k = $jdn - $jdn1f;

        if ( $k >= 0 ) {
            if ( $k <= 185 ) {
                return [ 'jy' => $jy, 'jm' => 1 + self::ta_div( $k, 31 ), 'jd' => self::ta_mod( $k, 31 ) + 1 ];
            }
            $k -= 186;
        } else {
            $jy -= 1;
            $k  += 179;
            if ( $r['leap'] === 1 ) {
                $k += 1;
            }
        }

        return [ 'jy' => $jy, 'jm' => 7 + self::ta_div( $k, 30 ), 'jd' => self::ta_mod( $k, 30 ) + 1 ];
    }

    public static function to_jalali( $datetime ) {
        if ( empty( $datetime ) || $datetime === '0000-00-00 00:00:00' ) {
            return '—';
        }

        $timestamp = strtotime( $datetime );
        if ( ! $timestamp ) {
            return '—';
        }

        list( $gy, $gm, $gd ) = explode( '-', date( 'Y-m-d', $timestamp ) );
        $gy = (int) $gy;
        $gm = (int) $gm;
        $gd = (int) $gd;

        $jalali = self::ta_d2j( self::ta_g2d( $gy, $gm, $gd ) );

        // اضافه کردن ساعت
        $time_part = date( 'H:i', $timestamp );

        return sprintf( '%04d/%02d/%02d %s', $jalali['jy'], $jalali['jm'], $jalali['jd'], $time_part );
    }

    public static function render() {
        global $wpdb;

        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error"><p>افزونه ووکامرس فعال نیست.</p></div>';
            return;
        }
        if (!TA_Database::tables_exist()) {
            echo '<div class="notice notice-error"><p>جداول مورد نیاز وجود ندارند. لطفا ابتدا دیتابیس را نصب کنید.</p></div>';
            return;
        }

        // [UI-UNIFY] استایل مشترک اکنون به‌صورت متمرکز در class-admin-menu.php
        // برای همهٔ صفحات ta-* لود می‌شود؛ فراخوانی قبلی این‌جا هندل‌هایی را
        // صدا می‌زد که هرگز register نشده بودند و اثری نداشتند.

        // دریافت مقادیر فیلترها از URL
        $search          = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $f_product_id    = isset($_GET['f_product_id']) ? intval($_GET['f_product_id']) : 0;
        $f_product_name  = isset($_GET['f_product_name']) ? sanitize_text_field($_GET['f_product_name']) : ''; 
        $f_assign_status = isset($_GET['f_assign_status']) ? sanitize_text_field($_GET['f_assign_status']) : '';
        $f_order_status  = isset($_GET['f_order_status']) ? sanitize_text_field($_GET['f_order_status']) : ''; 
        $f_term_id       = isset($_GET['f_term_id']) ? intval($_GET['f_term_id']) : 0; 
        $f_ta_id         = isset($_GET['f_ta_id']) ? intval($_GET['f_ta_id']) : 0; 
        $paged           = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

        $per_page = 20000;

        $assign_table = TA_Database::table('assignments');
        $ta_table     = TA_Database::table('assistants');
        $terms_table  = TA_Database::table('terms');
        $product_set_table = TA_Database::table('product_settings');

        $allowed_wc_statuses = "('wc-processing', 'wc-completed', 'wc-partial-payment', 'wc-pending-deposit', 'wc-scheduled-payment')";

        $where_conditions = array("p.post_type = 'shop_order'");
        
        if (!empty($f_order_status)) {
            $where_conditions[] = "p.post_status = %s";
            $where_params[] = $f_order_status;
        } else {
            $where_conditions[] = "p.post_status IN {$allowed_wc_statuses}";
        }
        
        $where_conditions[] = "oi.order_item_type = 'line_item'";
        if (!isset($where_params)) {
            $where_params = array();
        }

        $where_conditions[] = "oim.meta_key IN ('_product_id', '_variation_id')";
        $where_conditions[] = "oim.meta_value != ''";
        $where_conditions[] = "oim.meta_value != '0'";

        if ($f_product_id > 0) {
            $where_conditions[] = "oim.meta_value = %d";
            $where_params[] = $f_product_id;
        }

        if (!empty($f_product_name)) {
            $where_conditions[] = "prod.post_title LIKE %s";
            $where_params[] = '%' . $wpdb->esc_like($f_product_name) . '%';
        }

        if ($f_term_id > 0) {
            $where_conditions[] = "t.id = %d";
            $where_params[] = $f_term_id;
        }

        if ($f_ta_id > 0) {
            $where_conditions[] = "ta.id = %d";
            $where_params[] = $f_ta_id;
        }

        if (!empty($search)) {
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $user_ids = $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->users} WHERE display_name LIKE %s OR user_email LIKE %s
     UNION 
     SELECT DISTINCT user_id FROM {$wpdb->usermeta} 
     WHERE meta_key IN ('kerasno-phone','phone','Mreeir_phone','billing_phone') 
     AND meta_value LIKE %s",
    $search_like, $search_like, $search_like
));

            $search_conditions = array("p.ID = %d");
            $search_params = array(intval($search));

            if (!empty($user_ids)) {
                $placeholders = array_fill(0, count($user_ids), '%d');
                $search_conditions[] = "pm_user.meta_value IN (" . implode(',', $placeholders) . ")";
                $search_params = array_merge($search_params, $user_ids);
            }

            $where_conditions[] = "(" . implode(' OR ', $search_conditions) . ")";
            $where_params = array_merge($where_params, $search_params);
        }

        if ($f_assign_status === 'assigned') {
            $where_conditions[] = "a.id IS NOT NULL";
        } elseif ($f_assign_status === 'unassigned') {
            $where_conditions[] = "a.id IS NULL";
        }

        $where_sql = implode(' AND ', $where_conditions);
        
        $joins = "
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
            LEFT JOIN {$wpdb->posts} prod ON prod.ID = oim.meta_value 
            LEFT JOIN {$wpdb->postmeta} pm_user ON p.ID = pm_user.post_id AND pm_user.meta_key = '_customer_user'
            LEFT JOIN {$wpdb->users} u ON u.ID = pm_user.meta_value
            INNER JOIN {$product_set_table} ps ON ps.product_id = oim.meta_value
            INNER JOIN {$terms_table} t ON t.id = ps.term_id
            LEFT JOIN {$assign_table} a ON a.student_id = pm_user.meta_value AND a.product_id = ps.product_id
            LEFT JOIN {$ta_table} ta ON ta.id = a.ta_id
            LEFT JOIN {$wpdb->users} tu ON tu.ID = ta.user_id
        ";

        $count_query = "SELECT COUNT(DISTINCT p.ID) {$joins} WHERE {$where_sql}";
        if (!empty($where_params)) {
            $count_query = $wpdb->prepare($count_query, $where_params);
        }
        $total_items = $wpdb->get_var($count_query);
        $total_pages = ceil($total_items / $per_page);
        $offset = ($paged - 1) * $per_page;

        $select_query = "
            SELECT 
                p.ID as order_id,
                p.post_status as order_status,
                p.post_date as order_date,
                pm_user.meta_value as student_id,
                u.display_name as student_name,
                u.user_email as student_email,
                oim.meta_value as product_id,
                prod.post_title as product_title,
                a.id as assignment_id,
                a.status as assignment_status,
                a.created_at as assignment_created_at,
                a.messenger,
                a.group_link,
                tu.display_name as ta_name,
                t.name as term_name
            {$joins} 
            WHERE {$where_sql}
            GROUP BY p.ID
            ORDER BY p.post_date DESC
            LIMIT %d OFFSET %d
        ";

        $query_params = array_merge($where_params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($select_query, $query_params));

        // =========================================================
        // اینجا محل درست برای ساخت متغیر است (قبل از استفاده در HTML)
        // =========================================================
        $grouped_rows = [];
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $student_id = $row->student_id;
                $product_id = $row->product_id;
                $group_key = $student_id ? "{$student_id}_{$product_id}" : "guest_{$row->order_id}_{$product_id}";

                if (!isset($grouped_rows[$group_key])) {
                    $grouped_rows[$group_key] = $row;
                    $grouped_rows[$group_key]->all_order_ids = [$row->order_id];
                    $grouped_rows[$group_key]->all_order_statuses = [$row->order_status];
                    $grouped_rows[$group_key]->all_order_dates = [$row->order_date];
                } else {
                    if (!in_array($row->order_id, $grouped_rows[$group_key]->all_order_ids)) {
                        $grouped_rows[$group_key]->all_order_ids[] = $row->order_id;
                        $grouped_rows[$group_key]->all_order_statuses[] = $row->order_status;
                        $grouped_rows[$group_key]->all_order_dates[] = $row->order_date;
                    }
                }
            }
        }

        $all_terms = $wpdb->get_results("SELECT id, name FROM {$terms_table} ORDER BY name ASC");
        $all_tas = $wpdb->get_results("SELECT ta.id, u.display_name FROM {$ta_table} ta INNER JOIN {$wpdb->users} u ON ta.user_id = u.ID ORDER BY u.display_name ASC");

        if (isset($_GET['debug_ta']) && $_GET['debug_ta'] == '1') {
            echo '<div style="direction:ltr; text-align:left; background:#fff; padding:15px; border:2px solid red; margin:10px 0;">';
            echo '<h3 style="color:red; margin-top:0;">حالت دیباگ روشن است</h3>';
            echo '<strong>آخرین خطای دیتابیس (اگر خالی است یعنی ارور سینتکس نداریم):</strong><br><span style="color:darkred;">' . $wpdb->last_error . '</span><br><br>';
            echo '<strong>کوئری تولید شده:</strong><br><textarea style="width:100%; height:150px; font-family:monospace;">' . esc_textarea($wpdb->last_query) . '</textarea>';
            echo '</div>';
        }

        $wc_status_labels = array(
            'wc-processing'      => '<span class="wc-badge wc-processing">در حال انجام</span>',
            'wc-completed'       => '<span class="wc-badge wc-completed">تکمیل شده</span>',
            'wc-partial-payment' => '<span class="wc-badge wc-partial">پرداخت قسطی</span>',
            'wc-pending-deposit' => '<span class="wc-badge wc-pending-deposit">در انتظار پرداخت قسطی</span>',
            'wc-scheduled-payment' => '<span class="wc-scheduled-payment">زمانبندی شده</span>',
        );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">بررسی سفارشات (فقط محصولات دارای ترم)</h1>
            <a href="?page=<?php echo esc_attr($_GET['page']); ?>&debug_ta=1" class="page-title-action" style="background:red; color:white; border-color:darkred;">روشن کردن حالت دیباگ</a>
            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'export_xlsx' ), 'ta_customers_export' ) ); ?>"
               class="page-title-action" style="background:#217346;color:#fff;border-color:#1a5c38">⬇ خروجی اکسل</a>
            <hr class="wp-header-end">

            <form method="get" action="" class="ta-filter-form" style="margin-bottom: 20px; background: #fff; padding: 15px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page']); ?>">
                <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
                    <div><label style="display:block; margin-bottom:5px;">جستجو:</label><input type="text" name="s" value="<?php echo esc_attr($search); ?>" class="regular-text" placeholder="نام، ایمیل یا موبایل"></div>
                    <div><label style="display:block; margin-bottom:5px;">نام محصول/دوره:</label><input type="text" name="f_product_name" value="<?php echo esc_attr($f_product_name); ?>" class="regular-text"></div>
                    <div><label style="display:block; margin-bottom:5px;">شناسه محصول:</label><input type="number" name="f_product_id" value="<?php echo $f_product_id ? esc_attr($f_product_id) : ''; ?>" style="width: 100px;"></div>
                    
                    <div>
                        <label style="display:block; margin-bottom:5px;">وضعیت سفارش:</label>
                        <select name="f_order_status">
                            <option value="">پیش‌فرض مجاز</option>
                            <option value="wc-processing" <?php selected($f_order_status, 'wc-processing'); ?>>در حال انجام</option>
                            <option value="wc-completed" <?php selected($f_order_status, 'wc-completed'); ?>>تکمیل شده</option>
                            <option value="wc-partial-payment" <?php selected($f_order_status, 'wc-partial-payment'); ?>>پرداخت قسطی</option>
                            <option value="wc-on-hold" <?php selected($f_order_status, 'wc-on-hold'); ?>>در انتظار بررسی</option>
                            <option value="wc-pending" <?php selected($f_order_status, 'wc-pending'); ?>>در انتظار پرداخت</option>
                            <option value="wc-pending-deposit" <?php selected($f_order_status, 'wc-pending-deposit'); ?>>در انتظار پرداخت قسطی</option>
                            <option value="wc-scheduled-payment" <?php selected($f_order_status, 'wc-scheduled-payment'); ?>>زمانبندی شده</option>
                        </select>
                    </div>

                    <div>
                        <label style="display:block; margin-bottom:5px;">ترم مربوطه:</label>
                        <select name="f_term_id">
                            <option value="0">همه ترم‌ها</option>
                            <?php foreach ($all_terms as $t): ?>
                                <option value="<?php echo $t->id; ?>" <?php selected($f_term_id, $t->id); ?>><?php echo esc_html($t->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="display:block; margin-bottom:5px;">استادیار:</label>
                        <select name="f_ta_id">
                            <option value="0">همه استادیارها</option>
                            <?php foreach ($all_tas as $ta): ?>
                                <option value="<?php echo $ta->id; ?>" <?php selected($f_ta_id, $ta->id); ?>><?php echo esc_html($ta->display_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="display:block; margin-bottom:5px;">وضعیت تخصیص:</label>
                        <select name="f_assign_status">
                            <option value="">همه</option>
                            <option value="assigned" <?php selected($f_assign_status, 'assigned'); ?>>تخصیص داده شده</option>
                            <option value="unassigned" <?php selected($f_assign_status, 'unassigned'); ?>>بدون استادیار</option>
                        </select>
                    </div>

                    <div>
                        <button type="submit" class="button button-primary">اعمال فیلتر</button>
                        <a href="?page=<?php echo esc_attr($_GET['page']); ?>" class="button">پاک‌کردن</a>
                    </div>
                </div>
            </form>

            <?php
            $page_links = paginate_links( array(
                'base'      => add_query_arg( 'paged', '%#%' ),
                'format'    => '',
                'prev_text' => '&laquo; قبلی',
                'next_text' => 'بعدی &raquo;',
                'total'     => $total_pages,
                'current'   => $paged,
                'type'      => 'plain'
            ) );
            ?>
            
            <div class="tablenav top">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php echo number_format_i18n(count($grouped_rows)); ?> تخصیص در این صفحه
                    </span>                    
                    <?php if ( $page_links ) : ?>
                        <span class="pagination-links"><?php echo $page_links; ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" width="80">سفارش</th>
                        <th scope="col" width="120">وضعیت سفارش</th>
                        <th scope="col">نام محصول/دوره</th>
                        <th scope="col">هنرجو / خریدار</th>
                        <th scope="col" width="120">موبایل</th>
                        <th scope="col" width="120">وضعیت تخصیص</th>
                        <th scope="col" width="130">تاریخ تخصیص</th>
                        <th scope="col">استادیار</th>
                        <th scope="col" width="100">ترم مربوطه</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($grouped_rows)): ?>
                        <tr><td colspan="9" class="text-center" style="padding: 20px;">هیچ سفارش مرتبط با ترم یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($grouped_rows as $row):
                            if ( $row->student_id ) {
    $phone = class_exists('TA_User_Helper')
        ? TA_User_Helper::get_phone( $row->student_id )
        : (
            get_user_meta($row->student_id, 'kerasno-phone',  true) ?:
            get_user_meta($row->student_id, 'phone',          true) ?:
            get_user_meta($row->student_id, 'Mreeir_phone',   true) ?:
            get_user_meta($row->student_id, 'billing_phone',  true) ?:
            '—'
        );
} else {
    $phone = '—';
}
                            $product_name = $row->product_title ? $row->product_title : get_the_title($row->product_id);

                            if ($row->assignment_id) {
                                $assign_badge = ($row->assignment_status == 'active')
                                    ? '<span class="status-active">● فعال</span>'
                                    : '<span class="status-completed">تکمیل</span>';
                            } else {
                                $assign_badge = '<span style="color:#d63638; font-weight:bold;">نیازمند تخصیص</span>';
                            }
                        ?>
                            <tr>
                                <td>
                                    <?php foreach ($row->all_order_ids as $index => $oid): ?>
                                        <strong><a href="<?php echo get_edit_post_link($oid); ?>" target="_blank">#<?php echo $oid; ?></a></strong>
                                        <br><small dir="ltr"><?php echo date_i18n('Y/m/d', strtotime($row->all_order_dates[$index])); ?></small><br>
                                    <?php endforeach; ?>
                                </td>
                                
                                <td>
                                    <?php foreach ($row->all_order_statuses as $status): ?>
                                        <div style="margin-bottom:5px;"><?php echo isset($wc_status_labels[$status]) ? $wc_status_labels[$status] : $status; ?></div>
                                    <?php endforeach; ?>
                                </td>
                                
                                <td><a href="<?php echo get_edit_post_link($row->product_id); ?>" target="_blank"><?php echo esc_html($product_name); ?></a><br><small>ID: <?php echo $row->product_id; ?></small></td>
                                <td>
                                    <?php if($row->student_id): ?>
                                        <strong><a href="<?php echo get_edit_user_link($row->student_id); ?>" target="_blank"><?php echo esc_html($row->student_name); ?></a></strong><br>
                                        <small><?php echo esc_html($row->student_email); ?></small>
                                    <?php else: ?>
                                        <span style="color:gray;">مهمان</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($phone); ?></td>
                                <td>
                                    <?php echo $assign_badge; ?>
                                    <?php if ( $row->assignment_id ): ?>
                                        <br>
                                        <button
                                            type="button"
                                            class="button button-small ta-view-join-log"
                                            style="margin-top:5px; font-size:11px;"
                                            data-assignment-id="<?php echo (int) $row->assignment_id; ?>"
                                            data-student-name="<?php echo esc_attr( $row->student_name ); ?>"
                                            data-product-title="<?php echo esc_attr( $product_name ); ?>"
                                        >
                                            📋 لاگ ورود به گروه
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    if ( $row->assignment_id && ! empty( $row->assignment_created_at ) ) {
                                        echo '<span style="font-size:12px; color:#50575e;">' . esc_html( self::to_jalali( $row->assignment_created_at ) ) . '</span>';
                                    } else {
                                        echo '<span style="color:#ccc;">—</span>';
                                    }
                                    ?>
                                </td>
                                <td><?php echo $row->ta_name ? esc_html($row->ta_name) : '—'; ?></td>
                                <td><?php echo $row->term_name ? esc_html($row->term_name) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php echo number_format_i18n(count($grouped_rows)); ?> تخصیص در این صفحه
                </span>                   
                <?php if ( $page_links ) : ?>
                    <span class="pagination-links"><?php echo $page_links; ?></span>
                <?php endif; ?>
            </div>
        </div>

        <style>
        .wc-badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .wc-processing { background: #c6e1c6; color: #5b841b; }
        .wc-completed { background: #c8d7e1; color: #2e4453; }
        .wc-partial { background: #ffdfa0; color: #8a641c; }
        .wc-pending-deposit { background: #ffdf00; color: #8a641c; }
        .wc-scheduled-payment { background: #ffdf0a; color: #8a641c; }
        
        .status-active { color: #46b450; font-weight: bold; }
        .status-completed { color: #f56e28; font-weight: bold; }
        .text-center { text-align: center; }
        .tablenav .tablenav-pages {
            float: left; 
            margin: 5px 0;
        }
        .tablenav .displaying-num {
            margin-left: 15px;
            font-size: 13px;
            color: #50575e;
            font-weight: 500;
        }
        .pagination-links .page-numbers {
            display: inline-block;
            min-width: 15px;
            padding: 4px 10px;
            margin: 0 2px;
            font-size: 13px;
            color: #2271b1;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            text-align: center;
            text-decoration: none;
            transition: all 0.2s ease-in-out;
            box-shadow: 0 1px 1px rgba(0,0,0,0.04);
        }
        .pagination-links .page-numbers:hover {
            background: #f0f0f1;
            border-color: #8c8f94;
            color: #135e96;
        }
        .pagination-links .page-numbers.current {
            background: #2271b1;
            color: #fff;
            border-color: #2271b1;
            font-weight: bold;
            box-shadow: none;
        }
        .pagination-links .page-numbers.dots {
            border: none;
            background: transparent;
            box-shadow: none;
            color: #50575e;
            pointer-events: none;
        }
        </style>
        <?php self::render_login_log_modal(); ?>
        <?php
    }

    /**
     * مودال لاگ ورود به گروه (هنرجو / استادیار / ادمین) — قابل استفاده در چند صفحه‌ی ادمین
     * (صفحه‌ی مشتریان و صفحه‌ی تخصیص‌ها). این لاگ دستی نیست: هر رکورد،
     * یک کلیک/تاچ واقعی روی لینک «ورود به گروه» است که هنگام باز شدن آن لینک ثبت می‌شود.
     */
    public static function render_login_log_modal() {
        static $rendered = false;
        if ( $rendered ) {
            return; // از رندر تکراری مودال/اسکریپت در یک صفحه جلوگیری می‌کند
        }
        $rendered = true;
        ?>
        <style>
        /* ══════════════════════════════════════
           مودال لاگ واقعی ورود
        ══════════════════════════════════════ */
        #ta-join-log-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.55);
            z-index: 99998;
            backdrop-filter: blur(2px);
        }
        #ta-join-log-modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 99999;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
            width: 780px;
            max-width: 96vw;
            max-height: 88vh;
            overflow: hidden;
            flex-direction: column;
            font-family: inherit;
            min-height: 0;
        }
        #ta-join-log-modal.ta-modal-open {
            display: flex;
        }
        .ta-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 24px;
            background: linear-gradient(135deg, #1e3a5f 0%, #2271b1 100%);
            color: #fff;
            flex-shrink: 0;
        }
        .ta-modal-header h2 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            color: #fff;
        }
        .ta-modal-header .ta-modal-subtitle {
            font-size: 12px;
            opacity: .75;
            margin-top: 3px;
        }
        .ta-modal-close {
            background: rgba(255,255,255,.15);
            border: none;
            color: #fff;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .2s;
            flex-shrink: 0;
        }
        .ta-modal-close:hover { background: rgba(255,255,255,.3); }

        .ta-modal-toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 24px;
            border-bottom: 1px solid #e8ecf0;
            background: #f8fafc;
            flex-shrink: 0;
            flex-wrap: wrap;
        }
        .ta-log-auto-note {
            font-size: 12.5px;
            color: #475569;
            background: #eef2f7;
            border-right: 3px solid #94a3b8;
            padding: 8px 12px;
            border-radius: 0 6px 6px 0;
            line-height: 1.7;
        }

        .ta-modal-body {
            overflow-y: auto;
            flex: 1 1 auto;
            min-height: 0;
            padding: 20px 24px;
        }

        /* Timeline */
        .ta-log-timeline { list-style: none; margin: 0; padding: 0; position: relative; }
        .ta-log-timeline::before {
            content: '';
            position: absolute;
            right: 22px;
            top: 0; bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }
        .ta-log-item {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            margin-bottom: 18px;
            position: relative;
        }
        .ta-log-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            position: relative;
            z-index: 1;
            box-shadow: 0 2px 8px rgba(0,0,0,.12);
        }
        .ta-log-icon.student { background: #e3f2fd; }
        .ta-log-icon.ta      { background: #e8f5e9; }
        .ta-log-icon.admin   { background: #fce4ec; }

        .ta-log-card {
            flex: 1;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 16px;
            position: relative;
        }
        .ta-log-card::before {
            content: '';
            position: absolute;
            right: -8px;
            top: 14px;
            width: 0; height: 0;
            border-top: 8px solid transparent;
            border-bottom: 8px solid transparent;
            border-left: 8px solid #e2e8f0;
        }
        .ta-log-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 6px;
        }
        .ta-log-actor-name {
            font-weight: 600;
            font-size: 13px;
            color: #1a202c;
        }
        .ta-log-actor-badge {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 500;
        }
        .ta-log-actor-badge.student { background:#e3f2fd; color:#1565c0; }
        .ta-log-actor-badge.ta      { background:#e8f5e9; color:#2e7d32; }
        .ta-log-actor-badge.admin   { background:#fce4ec; color:#c62828; }

        .ta-log-time {
            font-size: 11px;
            color: #64748b;
            white-space: nowrap;
        }
        .ta-log-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 6px;
        }
        .ta-log-meta-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 3px 10px;
            color: #475569;
        }
        .ta-log-empty {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }
        .ta-log-empty .ta-log-empty-icon { font-size: 40px; display: block; margin-bottom: 10px; }
        .ta-log-spinner {
            text-align: center;
            padding: 30px;
            color: #64748b;
        }
        .ta-log-count-badge {
            background: rgba(255,255,255,.2);
            border-radius: 20px;
            padding: 2px 10px;
            font-size: 12px;
            margin-right: 8px;
        }
        </style>

        <!-- ══ مودال لاگ ورود به گروه ══ -->
        <div id="ta-join-log-overlay"></div>
        <div id="ta-join-log-modal" role="dialog" aria-modal="true">
            <div class="ta-modal-header">
                <div>
                    <h2>📋 لاگ ورود به گروه <span id="ta-log-count-badge" class="ta-log-count-badge"></span></h2>
                    <div class="ta-modal-subtitle" id="ta-log-modal-subtitle"></div>
                </div>
                <button class="ta-modal-close" id="ta-log-modal-close" title="بستن">✕</button>
            </div>
            <div class="ta-modal-toolbar">
                <span class="ta-log-auto-note">🔒 این لاگ به‌صورت خودکار ثبت می‌شود: به‌محض اینکه هر یک از نقش‌ها (هنرجو، استادیار، ادمین) روی لینک «ورود به گروه» کلیک/تاچ کند.</span>
            </div>
            <div class="ta-modal-body">
                <div id="ta-log-body">
                    <div class="ta-log-spinner">در حال بارگذاری...</div>
                </div>
            </div>
        </div>

        <script>
        var taAdminData = taAdminData || { nonce: '<?php echo esc_js( wp_create_nonce( "ta_admin_nonce" ) ); ?>' };
        </script>
        <script>
        (function($) {
            'use strict';

            // ── helper: تبدیل میلادی به شمسی (JS) ──────────────────────────
            // پیاده‌سازی صحیح بر اساس الگوریتم Borkowski (همان الگوریتم jalaali-js)
            function taDiv(a, b) { return ~~(a / b); }
            function taMod(a, b) { return a - ~~(a / b) * b; }

            function taJalCal(jy) {
                var breaks = [-61,9,38,199,426,686,756,818,1111,1181,1210,1635,2060,2097,2192,2262,2324,2394,2456,3178];
                var gy = jy + 621;
                var leapJ = -14;
                var jp = breaks[0];
                var jm, jump, leap, n, i;
                for (i = 1; i < breaks.length; i++) {
                    jm = breaks[i];
                    jump = jm - jp;
                    if (jy < jm) break;
                    leapJ = leapJ + taDiv(jump, 33) * 8 + taDiv(taMod(jump, 33), 4);
                    jp = jm;
                }
                n = jy - jp;
                leapJ = leapJ + taDiv(n, 33) * 8 + taDiv(taMod(n, 33) + 3, 4);
                if (taMod(jump, 33) === 4 && jump - n === 4) leapJ += 1;
                var leapG = taDiv(gy, 4) - taDiv((taDiv(gy, 100) + 1) * 3, 4) - 150;
                var march = 20 + leapJ - leapG;
                if (jump - n < 6) n = n - jump + taDiv(jump + 4, 33) * 33;
                leap = taMod(taMod(n + 1, 33) - 1, 4);
                if (leap === -1) leap = 4;
                return { leap: leap, gy: gy, march: march };
            }

            function taG2d(gy, gm, gd) {
                var d = taDiv((gy + taDiv(gm - 8, 6) + 100100) * 1461, 4)
                    + taDiv(153 * taMod(gm + 9, 12) + 2, 5)
                    + gd - 34840408;
                d = d - taDiv(taDiv(gy + 100100 + taDiv(gm - 8, 6), 100) * 3, 4) + 752;
                return d;
            }

            function taD2g(jdn) {
                var j = 4 * jdn + 139361631;
                j = j + taDiv(taDiv(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
                var i = taDiv(taMod(j, 1461), 4) * 5 + 308;
                var gd = taDiv(taMod(i, 153), 5) + 1;
                var gm = taMod(taDiv(i, 153), 12) + 1;
                var gy = taDiv(j, 1461) - 100100 + taDiv(8 - gm, 6);
                return { gy: gy, gm: gm, gd: gd };
            }

            function taD2j(jdn) {
                var gy = taD2g(jdn).gy;
                var jy = gy - 621;
                var r = taJalCal(jy);
                var jdn1f = taG2d(gy, 3, r.march);
                var jd, jm, k;
                k = jdn - jdn1f;
                if (k >= 0) {
                    if (k <= 185) {
                        jm = 1 + taDiv(k, 31);
                        jd = taMod(k, 31) + 1;
                        return { jy: jy, jm: jm, jd: jd };
                    }
                    k -= 186;
                } else {
                    jy -= 1;
                    k += 179;
                    if (r.leap === 1) k += 1;
                }
                jm = 7 + taDiv(k, 30);
                jd = taMod(k, 30) + 1;
                return { jy: jy, jm: jm, jd: jd };
            }

            function toJalali(dateStr) {
                if (!dateStr) return '—';
                var d = new Date(dateStr.replace(' ', 'T'));
                if (isNaN(d)) return dateStr;

                var gy = d.getFullYear(), gm = d.getMonth() + 1, gd = d.getDate();
                var j = taD2j(taG2d(gy, gm, gd));

                var hh = String(d.getHours()).padStart(2,'0');
                var mm = String(d.getMinutes()).padStart(2,'0');
                return j.jy + '/' + String(j.jm).padStart(2,'0') + '/' + String(j.jd).padStart(2,'0') + ' ' + hh + ':' + mm;
            }

            var actorLabels = { student: 'هنرجو', ta: 'استادیار', admin: 'ادمین' };
            var actorIcons  = { student: '👩‍🎨', ta: '👨‍🏫', admin: '🛡️' };
            var messengerLabels = { telegram: 'تلگرام', bale: 'بله', whatsapp: 'واتساپ', eitaa: 'ایتا' };

            var currentAssignmentId = null;

            function openModal(assignmentId, studentName, productTitle) {
                currentAssignmentId = assignmentId;
                $('#ta-log-modal-subtitle').text(studentName + ' — ' + productTitle);
                // نکته: از jQuery .show() استفاده نمی‌کنیم چون display:block این‌لاین ست می‌کند
                // و روی display:flex کلاس ta-modal-open که برای اسکرول‌شدن بدنه لازم است اولویت می‌گیرد.
                $('#ta-join-log-overlay').css('display', 'block');
                $('#ta-join-log-modal').css('display', 'flex').addClass('ta-modal-open');
                loadLogs();
            }

            function closeModal() {
                $('#ta-join-log-overlay, #ta-join-log-modal').css('display', 'none');
                $('#ta-join-log-modal').removeClass('ta-modal-open');
                currentAssignmentId = null;
            }

            function loadLogs() {
                $('#ta-log-body').html('<div class="ta-log-spinner">⏳ در حال بارگذاری...</div>');
                $.post(ajaxurl, {
                    action: 'ta_get_join_logs',
                    nonce: taAdminData.nonce,
                    assignment_id: currentAssignmentId
                }, function(res) {
                    if (res.success) {
                        renderLogs(res.data.logs);
                    } else {
                        $('#ta-log-body').html('<div class="ta-log-empty"><span class="ta-log-empty-icon">⚠️</span>' + (res.data.message || 'خطا در بارگذاری') + '</div>');
                    }
                }).fail(function() {
                    $('#ta-log-body').html('<div class="ta-log-empty"><span class="ta-log-empty-icon">🔌</span>خطا در اتصال به سرور</div>');
                });
            }

            function renderLogs(logs) {
                var count = logs ? logs.length : 0;
                $('#ta-log-count-badge').text(count + ' رویداد');

                if (!count) {
                    $('#ta-log-body').html(
                        '<div class="ta-log-empty">' +
                        '<span class="ta-log-empty-icon">📭</span>' +
                        '<strong>هنوز هیچ ورودی به گروه ثبت نشده است</strong><br>' +
                        '<small>به‌محض کلیک هنرجو، استادیار یا ادمین روی لینک «ورود به گروه»، این‌جا نمایش داده می‌شود.</small>' +
                        '</div>'
                    );
                    return;
                }

                var html = '<ul class="ta-log-timeline">';
                $.each(logs, function(i, log) {
                    var aType  = log.actor_type || 'admin';
                    var icon   = actorIcons[aType]  || '👤';
                    var label  = actorLabels[aType] || aType;
                    var msng   = messengerLabels[log.messenger] || log.messenger || '';
                    var jalali = toJalali(log.logged_at);
                    var name   = log.actor_name || ('کاربر #' + log.actor_id);

                    var metaChips = '';
                    if (msng) metaChips += '<span class="ta-log-meta-chip">💬 ' + msng + '</span>';
                    if (log.group_link) {
                        metaChips += '<span class="ta-log-meta-chip">🔗 <a href="' + log.group_link + '" target="_blank" style="color:inherit;">لینک گروه</a></span>';
                    }

                    html +=
                        '<li class="ta-log-item">' +
                            '<div class="ta-log-icon ' + aType + '">' + icon + '</div>' +
                            '<div class="ta-log-card">' +
                                '<div class="ta-log-card-header">' +
                                    '<div>' +
                                        '<span class="ta-log-actor-name">' + $('<div>').text(name).html() + '</span>' +
                                        ' <span class="ta-log-actor-badge ' + aType + '">' + label + '</span>' +
                                    '</div>' +
                                    '<span class="ta-log-time">🕐 ' + jalali + '</span>' +
                                '</div>' +
                                (metaChips ? '<div class="ta-log-meta">' + metaChips + '</div>' : '') +
                            '</div>' +
                        '</li>';
                });
                html += '</ul>';
                $('#ta-log-body').html(html);
            }

            // ── رویدادها ──────────────────────────────────────────────────
            $(document).on('click', '.ta-view-join-log', function() {
                var $btn = $(this);
                openModal(
                    $btn.data('assignment-id'),
                    $btn.data('student-name'),
                    $btn.data('product-title')
                );
            });

            $('#ta-log-modal-close, #ta-join-log-overlay').on('click', closeModal);

            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') closeModal();
            });

        })(jQuery);
        </script>
        <?php
    }
    public function export_xlsx(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );

        global $wpdb;
        $assign_table      = TA_Database::table('assignments');
        $ta_table          = TA_Database::table('assistants');
        $terms_table       = TA_Database::table('terms');
        $product_set_table = TA_Database::table('product_settings');

        $where_conditions = [ "p.post_type = 'shop_order'" ];
        $where_params     = [];
        $allowed_wc       = "('wc-processing','wc-completed','wc-partial-payment','wc-pending-deposit','wc-scheduled-payment')";

        if ( ! empty( $_GET['f_order_status'] ) ) {
            $where_conditions[] = 'p.post_status = %s';
            $where_params[]     = sanitize_text_field( $_GET['f_order_status'] );
        } else {
            $where_conditions[] = "p.post_status IN {$allowed_wc}";
        }
        if ( ! empty( $_GET['f_product_id'] ) ) {
            $where_conditions[] = 'oim.meta_value = %d';
            $where_params[]     = intval( $_GET['f_product_id'] );
        }
        if ( ! empty( $_GET['f_term_id'] ) ) {
            $where_conditions[] = 'ps.term_id = %d';
            $where_params[]     = intval( $_GET['f_term_id'] );
        }
        if ( ! empty( $_GET['f_assign_status'] ) ) {
            $s = sanitize_text_field( $_GET['f_assign_status'] );
            if ( $s === 'unassigned' ) {
                $where_conditions[] = 'a.id IS NULL';
            } else {
                $where_conditions[] = 'a.status = %s';
                $where_params[]     = $s;
            }
        }
        if ( ! empty( $_GET['f_ta_id'] ) ) {
            $where_conditions[] = 'ta.id = %d';
            $where_params[]     = intval( $_GET['f_ta_id'] );
        }
        if ( ! empty( $_GET['s'] ) ) {
            $like = '%' . $wpdb->esc_like( sanitize_text_field( $_GET['s'] ) ) . '%';
            $ids  = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE display_name LIKE %s OR user_email LIKE %s", $like, $like
            ) );
            if ( $ids ) {
                $ph = implode( ',', array_map( 'intval', $ids ) );
                $where_conditions[] = "pm_user.meta_value IN ({$ph})";
            } else {
                $where_conditions[] = '1=0';
            }
        }

        $where_sql = implode( ' AND ', $where_conditions );
        $joins = "
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->prefix}woocommerce_order_items oi     ON p.ID = oi.order_id AND oi.order_item_type='line_item'
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key='_product_id'
            LEFT  JOIN {$wpdb->posts}   prod     ON prod.ID     = oim.meta_value
            LEFT  JOIN {$wpdb->postmeta} pm_user ON p.ID        = pm_user.post_id AND pm_user.meta_key='_customer_user'
            LEFT  JOIN {$wpdb->users}   u        ON u.ID        = pm_user.meta_value
            INNER JOIN {$product_set_table} ps   ON ps.product_id = oim.meta_value
            INNER JOIN {$terms_table}   t        ON t.id        = ps.term_id
            LEFT  JOIN {$assign_table}  a        ON a.student_id = pm_user.meta_value AND a.product_id = ps.product_id
            LEFT  JOIN {$ta_table}      ta       ON ta.id        = a.ta_id
            LEFT  JOIN {$wpdb->users}   tu       ON tu.ID        = ta.user_id
        ";

        $sql = "SELECT
                    p.ID as order_id, p.post_status as order_status, p.post_date as order_date,
                    pm_user.meta_value as student_id,
                    u.display_name as student_name, u.user_email as student_email,
                    oim.meta_value as product_id, prod.post_title as product_title,
                    a.status as assignment_status, a.created_at as assignment_date,
                    a.messenger, a.group_link,
                    tu.display_name as ta_name,
                    t.name as term_name
                {$joins} WHERE {$where_sql}
                GROUP BY p.ID, oim.meta_value
                ORDER BY p.post_date DESC";

        $rows = $where_params
            ? ( $wpdb->get_results( $wpdb->prepare( $sql, $where_params ) ) ?: [] )
            : ( $wpdb->get_results( $sql ) ?: [] );

        // phone numbers
        $phone_map = [];
        $sids = array_filter( array_unique( array_column( (array)$rows, 'student_id' ) ) );
        if ( $sids ) {
            $plist = implode( ',', array_map('intval', $sids) );
            $phones = $wpdb->get_results(
                "SELECT user_id,meta_key,meta_value FROM {$wpdb->usermeta}
                 WHERE user_id IN ({$plist}) AND meta_key IN ('kerasno-phone','phone','Mreeir_phone','billing_phone')"
            );
            $prio = [ 'kerasno-phone'=>1,'phone'=>2,'Mreeir_phone'=>3,'billing_phone'=>4 ];
            foreach ( $phones as $pm ) {
                $uid = (int)$pm->user_id; $p = $prio[$pm->meta_key]??99;
                if ( $pm->meta_value && (!isset($phone_map[$uid])||$p<$phone_map[$uid]['p']) )
                    $phone_map[$uid] = ['v'=>$pm->meta_value,'p'=>$p];
            }
            $phone_map = array_map( fn($x) => $x['v'], $phone_map );
        }

        $status_labels = [
            'wc-processing'=>'در حال پردازش','wc-completed'=>'تکمیل‌شده',
            'wc-partial-payment'=>'پرداخت ناقص','wc-pending-deposit'=>'در انتظار واریز',
            'wc-scheduled-payment'=>'پرداخت زمان‌بندی‌شده',
        ];
        $assign_labels = [ 'active'=>'فعال','canceled'=>'لغو‌شده','completed'=>'تکمیل‌شده','pending_admin'=>'در انتظار ادمین' ];
        $msg_labels    = [ 'telegram'=>'تلگرام','whatsapp'=>'واتساپ','eitaa'=>'ایتا','bale'=>'بله','both'=>'همه' ];

        while ( ob_get_level() ) ob_end_clean();

        $writer = new TA_Xlsx_Writer();
        $writer->set_rtl( true );
        $writer->add_row( [
            'ردیف','شماره سفارش','تاریخ سفارش','وضعیت سفارش',
            'نام هنرجو','شماره همراه','ایمیل',
            'محصول','ترم','استادیار','پیامرسان',
            'وضعیت تخصیص','لینک گروه','تاریخ تخصیص',
        ], true );

        foreach ( $rows as $i => $r ) {
            $writer->add_row( [
                $i + 1,
                '#' . $r->order_id,
                $r->order_date        ?? '',
                $status_labels[ $r->order_status ] ?? $r->order_status,
                $r->student_name      ?? '',
                $phone_map[ (int)$r->student_id ] ?? '',
                $r->student_email     ?? '',
                $r->product_title     ?? '',
                $r->term_name         ?? '',
                $r->ta_name           ?? 'بدون استادیار',
                $msg_labels[ $r->messenger ] ?? $r->messenger,
                $r->assignment_status ? ( $assign_labels[$r->assignment_status] ?? $r->assignment_status ) : 'بدون تخصیص',
                $r->group_link        ?? '',
                $r->assignment_date   ?? '',
            ], false, true );
        }
        $writer->output( 'customers-' . date('Y-m-d') . '.xlsx' );
        exit;
    }

}