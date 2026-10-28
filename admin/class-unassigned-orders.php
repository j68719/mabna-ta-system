<?php
/**
 * Class TA_Admin_Unassigned_Orders
 * گزارش سفارشات بدون تخصیص
 */
class TA_Admin_Unassigned_Orders {
    
    private $per_page = 20;
    
    public function render() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Unassigned Orders Report', 'ta-system'); ?></h1>
            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'export_xlsx' ), 'ta_unassigned_export' ) ); ?>"
               class="page-title-action" style="background:#217346;color:#fff;border-color:#1a5c38">⬇ خروجی اکسل</a>
            <hr class="wp-header-end">
            
            <?php $this->render_filters(); ?>
            
            <div class="ta-admin-container">
                <?php
                $orders = $this->get_unassigned_orders();
                $this->render_table($orders);
                ?>
            </div>
        </div>
        <?php
    }
    
    private function render_filters() {
        $current_term = isset($_GET['term_id']) ? intval($_GET['term_id']) : '';
        $current_course_type = isset($_GET['course_type']) ? sanitize_text_field($_GET['course_type']) : '';
        $current_date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $current_date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        
        // دریافت ترم‌ها از دیتابیس
        global $wpdb;
        $terms = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}ta_terms ORDER BY id DESC");
        
        ?>
        <div class="ta-filters">
            <form method="get" action="">
                <input type="hidden" name="page" value="ta-unassigned-orders">
                
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="term_filter"><?php _e('Term:', 'ta-system'); ?></label>
                        <select name="term_id" id="term_filter">
                            <option value=""><?php _e('All Terms', 'ta-system'); ?></option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?php echo esc_attr($term->id); ?>" <?php selected($current_term, $term->id); ?>>
                                    <?php echo esc_html($term->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="course_type_filter"><?php _e('Course Type:', 'ta-system'); ?></label>
                        <select name="course_type" id="course_type_filter">
                            <option value=""><?php _e('All Types', 'ta-system'); ?></option>
                            <option value="creative" <?php selected($current_course_type, 'creative'); ?>><?php _e('Creative', 'ta-system'); ?></option>
                            <option value="academic" <?php selected($current_course_type, 'intro'); ?>><?php _e('Academic', 'ta-system'); ?></option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_from"><?php _e('From Date:', 'ta-system'); ?></label>
                        <input type="date" name="date_from" id="date_from" value="<?php echo esc_attr($current_date_from); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_to"><?php _e('To Date:', 'ta-system'); ?></label>
                        <input type="date" name="date_to" id="date_to" value="<?php echo esc_attr($current_date_to); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <input type="submit" class="button button-primary" value="<?php _e('Filter', 'ta-system'); ?>">
                        <a href="?page=ta-unassigned-orders" class="button"><?php _e('Reset', 'ta-system'); ?></a>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }
    
    private function get_unassigned_orders() {
        global $wpdb;
        
        $where_clauses = [];
        $params = [];
        
        // فیلتر ترم
        if (!empty($_GET['term_id'])) {
            $where_clauses[] = "ps.term_id = %d";
            $params[] = intval($_GET['term_id']);
        }
        
        // فیلتر نوع دوره
        if (!empty($_GET['course_type'])) {
            $where_clauses[] = "ps.course_type = %s";
            $params[] = sanitize_text_field($_GET['course_type']);
        }
        
        // فیلتر تاریخ
        if (!empty($_GET['date_from'])) {
            $where_clauses[] = "o.post_date >= %s";
            $params[] = sanitize_text_field($_GET['date_from']) . ' 00:00:00';
        }
        
        if (!empty($_GET['date_to'])) {
            $where_clauses[] = "o.post_date <= %s";
            $params[] = sanitize_text_field($_GET['date_to']) . ' 23:59:59';
        }
        
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'AND ' . implode(' AND ', $where_clauses);
        }
        
        // کوئری اصلی
        $query = $wpdb->prepare("
            SELECT 
                o.ID as order_id,
                o.post_date as order_date,
                u.ID as customer_id,
                u.display_name as customer_name,
                u.user_email as customer_email,
                p.ID as product_id,
                p.post_title as product_name,
                ps.term_id,
                ps.course_type,
                t.name as term_name,
                GROUP_CONCAT(DISTINCT oi.order_item_name SEPARATOR ', ') as order_items,
                COUNT(DISTINCT oi.order_item_id) as item_count
            FROM 
                {$wpdb->posts} o
                INNER JOIN {$wpdb->postmeta} om ON o.ID = om.post_id AND om.meta_key = '_customer_user'
                INNER JOIN {$wpdb->users} u ON om.meta_value = u.ID
                INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON o.ID = oi.order_id AND oi.order_item_type = 'line_item'
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
                INNER JOIN {$wpdb->posts} p ON oim.meta_value = p.ID
                INNER JOIN {$wpdb->prefix}ta_product_settings ps ON p.ID = ps.product_id
                LEFT JOIN {$wpdb->prefix}ta_terms t ON ps.term_id = t.id
                LEFT JOIN {$wpdb->prefix}ta_assignments a ON u.ID = a.student_id AND p.ID = a.product_id AND a.status = 'active'
            WHERE 
                o.post_type = 'shop_order'
                AND o.post_status IN ('wc-completed', 'wc-processing')
                AND a.id IS NULL
                {$where_sql}
            GROUP BY o.ID, u.ID, p.ID
            ORDER BY o.post_date DESC
        ", $params);
        
        return $wpdb->get_results($query);
    }
    
    private function render_table($orders) {
        if (empty($orders)) {
            echo '<div class="notice notice-info"><p>' . __('No unassigned orders found.', 'ta-system') . '</p></div>';
            return;
        }
        
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Order ID', 'ta-system'); ?></th>
                    <th><?php _e('Order Date', 'ta-system'); ?></th>
                    <th><?php _e('Customer', 'ta-system'); ?></th>
                    <th><?php _e('Email', 'ta-system'); ?></th>
                    <th><?php _e('Product', 'ta-system'); ?></th>
                    <th><?php _e('Term', 'ta-system'); ?></th>
                    <th><?php _e('Course Type', 'ta-system'); ?></th>
                    <th><?php _e('Items', 'ta-system'); ?></th>
                    <th><?php _e('Actions', 'ta-system'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td>
                            <a href="<?php echo admin_url('post.php?post=' . $order->order_id . '&action=edit'); ?>" target="_blank">
                                #<?php echo esc_html($order->order_id); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html(date_i18n('Y/m/d H:i', strtotime($order->order_date))); ?></td>
                        <td>
                            <a href="<?php echo admin_url('user-edit.php?user_id=' . $order->customer_id); ?>" target="_blank">
                                <?php echo esc_html($order->customer_name); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html($order->customer_email); ?></td>
                        <td>
                            <a href="<?php echo admin_url('post.php?post=' . $order->product_id . '&action=edit'); ?>" target="_blank">
                                <?php echo esc_html($order->product_name); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html($order->term_name ?: 'N/A'); ?></td>
                        <td>
                            <span class="ta-badge ta-badge-<?php echo esc_attr($order->course_type); ?>">
                                <?php echo esc_html(ucfirst($order->course_type)); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($order->order_items); ?></td>
                        <td>
                            <div class="row-actions">
                                <a href="<?php echo admin_url('admin.php?page=ta-assignments&action=add&student_id=' . $order->customer_id . '&product_id=' . $order->product_id); ?>" 
                                   class="button button-small">
                                    <?php _e('Assign TA', 'ta-system'); ?>
                                </a>
                                <a href="<?php echo admin_url('post.php?post=' . $order->order_id . '&action=edit'); ?>" 
                                   class="button button-small" target="_blank">
                                    <?php _e('View Order', 'ta-system'); ?>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="9">
                        <div class="tablenav">
                            <div class="tablenav-pages">
                                <span class="displaying-num">
                                    <?php printf(__('%d orders found', 'ta-system'), count($orders)); ?>
                                </span>
                            </div>
                        </div>
                    </td>
                </tr>
            </tfoot>
        </table>
        
        <style>
        .ta-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 600;
        }
        .ta-badge-creative {
            background-color: #e8f5e9;
            color: #2e7d32;
        }
        .ta-badge-academic {
            background-color: #e3f2fd;
            color: #1565c0;
        }
        .ta-filters {
            background: #fff;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
        }
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            min-width: 200px;
        }
        .filter-group label {
            margin-bottom: 5px;
            font-weight: 600;
        }
        /* در فایل assets/css/ta-system.css اضافه کنید */

/* استایل‌های گزارش سفارشات بدون تخصیص */
.ta-admin-container {
    margin-top: 20px;
}

.ta-filters {
    background: #fff;
    padding: 15px;
    margin-bottom: 20px;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}

.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    min-width: 200px;
}

.filter-group label {
    margin-bottom: 5px;
    font-weight: 600;
}

.filter-group input[type="date"],
.filter-group select {
    padding: 5px 10px;
    border: 1px solid #8c8f94;
    border-radius: 4px;
    height: 32px;
}

.ta-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 600;
}

.ta-badge-creative {
    background-color: #e8f5e9;
    color: #2e7d32;
}

.ta-badge-academic {
    background-color: #e3f2fd;
    color: #1565c0;
}

.row-actions {
    display: flex;
    gap: 5px;
}

.row-actions .button {
    font-size: 12px;
    padding: 2px 8px;
    height: auto;
    line-height: 1.5;
}

        </style>
        <?php
    }
    public function export_xlsx(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );

        $orders = $this->get_unassigned_orders();

        // phone numbers
        global $wpdb;
        $phone_map = [];
        $sids = array_filter( array_unique( array_column( (array)$orders, 'customer_id' ) ) );
        if ( $sids ) {
            $plist = implode( ',', array_map('intval', $sids) );
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

        $course_labels = [
            'creative'=>'نویسندگی خلاق','intro'=>'مقدماتی','basic_writing'=>'پایه',
            'advanced'=>'پیشرفته','critique'=>'نقد اثر','ladder'=>'نردبان','professional'=>'حرفه‌ای',
        ];

        while ( ob_get_level() ) ob_end_clean();

        $writer = new TA_Xlsx_Writer();
        $writer->set_rtl( true );
        $writer->add_row( [
            'ردیف','شماره سفارش','تاریخ سفارش',
            'نام مشتری','شماره همراه','ایمیل',
            'محصول','ترم','نوع دوره',
        ], true );

        foreach ( $orders as $i => $o ) {
            $writer->add_row( [
                $i + 1,
                '#' . $o->order_id,
                $o->order_date    ?? '',
                $o->customer_name ?? '',
                $phone_map[ (int)$o->customer_id ] ?? '',
                $o->customer_email ?? '',
                $o->product_name  ?? '',
                $o->term_name     ?? '',
                $course_labels[ $o->course_type ] ?? $o->course_type,
            ], false, true );
        }
        $writer->output( 'unassigned-orders-' . date('Y-m-d') . '.xlsx' );
        exit;
    }

}