<?php
/**
 * ابزار ایمن اصلاح نمره‌های دوره پیشرفته.
 * فقط رکوردهای advanced + repeat را نمایش می‌دهد و با انتخاب مدیر به repeat_advanced تبدیل می‌کند.
 */
defined( 'ABSPATH' ) || exit;

class TA_Advanced_Grade_Repair {
    private const PAGE = 'ta-advanced-grade-repair';

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );
        $notice = $this->handle_post();
        global $wpdb;
        $grades = TA_Database::table( 'grades' );
        $terms  = TA_Database::table( 'terms' );
        $search = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
        $term_id = absint( $_GET['term_id'] ?? 0 );
        $where = [ 'g.course_type = %s', 'g.grade = %s' ];
        $params = [ 'advanced', 'repeat' ];
        if ( $term_id ) { $where[] = 'g.term_id = %d'; $params[] = $term_id; }
        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR CAST(g.student_id AS CHAR) LIKE %s)';
            array_push( $params, $like, $like, $like );
        }
        $sql = "SELECT g.id,g.student_id,g.product_id,g.term_id,g.grade,g.created_at,g.updated_at,
                       u.display_name,u.user_email,t.name AS term_name,p.post_title AS product_name
                FROM {$grades} g
                LEFT JOIN {$wpdb->users} u ON u.ID=g.student_id
                LEFT JOIN {$terms} t ON t.id=g.term_id
                LEFT JOIN {$wpdb->posts} p ON p.ID=g.product_id
                WHERE " . implode( ' AND ', $where ) . ' ORDER BY g.id DESC';
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        $terms_rows = $wpdb->get_results( "SELECT id,name FROM {$terms} ORDER BY order_index ASC,id ASC" );
        $logs = get_option( 'ta_advanced_grade_repair_log', [] );
        if ( ! is_array( $logs ) ) $logs = [];
        echo '<div class="wrap tagr-wrap" dir="rtl">';
        echo '<div class="tagr-hero"><div><span>DATA INTEGRITY TOOL</span><h1>اصلاح نمره‌های دوره پیشرفته</h1><p>رکوردهای مشکوک: دوره پیشرفته با نمره فعلی «تکرار دوره»</p></div><div class="tagr-count">'.number_format_i18n(count($rows)).'<small>رکورد مشکوک</small></div></div>';
        if ( $notice ) echo '<div class="notice notice-'.esc_attr($notice['type']).' is-dismissible"><p>'.esc_html($notice['message']).'</p></div>';
        echo '<div class="tagr-warning"><strong>تغییر کنترل‌شده و قابل بررسی</strong><br>فقط رکوردهای انتخاب‌شده از <code>repeat</code> به <code>repeat_advanced</code> تبدیل می‌شوند. هیچ رکورد دیگری تغییر نمی‌کند.</div>';
        echo '<form method="get" class="tagr-filters"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'">';
        echo '<input name="search" value="'.esc_attr($search).'" placeholder="نام، ایمیل یا شناسه هنرجو">';
        echo '<select name="term_id"><option value="0">همه ترم‌ها</option>';
        foreach ( $terms_rows as $term ) echo '<option value="'.(int)$term->id.'" '.selected($term_id,(int)$term->id,false).'>'.esc_html($term->name).'</option>';
        echo '</select><button class="button button-primary">جست‌وجو</button><a class="button" href="'.esc_url(admin_url('admin.php?page='.self::PAGE)).'">حذف فیلتر</a></form>';
        echo '<form method="post" onsubmit="return confirm(\'آیا از اصلاح رکوردهای انتخاب‌شده مطمئن هستید؟ این عملیات مقدار نمره را تغییر می‌دهد.\');">';
        wp_nonce_field( 'ta_advanced_grade_repair', '_tagr_nonce' );
        echo '<input type="hidden" name="ta_repair_action" value="convert"><div class="tagr-toolbar"><label><input type="checkbox" id="tagr-all"> انتخاب همه این صفحه</label><button class="button button-primary" type="submit">تبدیل انتخاب‌شده‌ها به repeat_advanced</button></div>';
        echo '<div class="tagr-table-wrap"><table class="widefat striped"><thead><tr><th></th><th>شناسه</th><th>هنرجو</th><th>محصول / دوره</th><th>ترم</th><th>نمره فعلی</th><th>تاریخ ثبت</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            echo '<tr><td><input type="checkbox" name="grade_ids[]" value="'.(int)$row->id.'" class="tagr-item"></td><td>#'.(int)$row->id.'</td><td><strong>'.esc_html($row->display_name ?: 'هنرجوی حذف‌شده').'</strong><small>'.esc_html($row->user_email ?: 'شناسه: '.$row->student_id).'</small></td><td>'.esc_html($row->product_name ?: 'محصول #'.$row->product_id).'<small>شناسه محصول: '.(int)$row->product_id.'</small></td><td>'.esc_html($row->term_name ?: 'ترم #'.$row->term_id).'</td><td><span class="tagr-old">repeat</span> ← تکرار دوره</td><td>'.esc_html($row->created_at ?: '—').'</td></tr>';
        }
        if ( ! $rows ) echo '<tr><td colspan="7" class="tagr-empty">رکورد مشکوکی با این فیلتر پیدا نشد.</td></tr>';
        echo '</tbody></table></div></form>';
        if ( $logs ) {
            echo '<h2>گزارش آخرین اصلاح‌ها</h2><div class="tagr-log"><table class="widefat striped"><thead><tr><th>زمان</th><th>کاربر اجراکننده</th><th>تعداد</th><th>شناسه رکوردها</th></tr></thead><tbody>';
            foreach ( array_slice($logs,0,10) as $log ) echo '<tr><td>'.esc_html($log['time']??'—').'</td><td>'.esc_html($log['user']??'—').'</td><td>'.number_format_i18n((int)($log['count']??0)).'</td><td>'.esc_html(implode(', ',array_map('strval',$log['ids']??[]))).'</td></tr>';
            echo '</tbody></table></div>';
        }
        echo '<style>.tagr-wrap{max-width:1500px}.tagr-hero{display:flex;justify-content:space-between;align-items:center;background:linear-gradient(120deg,#28144f,#812d59);color:#fff;border-radius:18px;padding:26px 30px;margin:20px 0}.tagr-hero span{font-size:11px;letter-spacing:2px;opacity:.7}.tagr-hero h1{margin:8px 0}.tagr-hero p{margin:0;opacity:.8}.tagr-count{font-size:34px;font-weight:800;text-align:center}.tagr-count small{display:block;font-size:11px;font-weight:400}.tagr-warning{background:#fff7e6;border-right:4px solid #d9822b;padding:14px 18px;margin:14px 0;border-radius:8px}.tagr-filters,.tagr-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#fff;padding:14px;border:1px solid #dfe5ec;border-radius:10px;margin:14px 0}.tagr-filters input:not([type=checkbox]),.tagr-filters select{min-width:220px;height:36px;border:1px solid #c9d2dd;border-radius:6px;padding:4px 8px}.tagr-toolbar{justify-content:space-between}.tagr-table-wrap{overflow:auto;background:#fff}.tagr-table-wrap table{min-width:950px}.tagr-table-wrap td,.tagr-table-wrap th{vertical-align:middle}.tagr-table-wrap small{display:block;color:#7b8794;margin-top:4px}.tagr-old{display:inline-block;background:#fff0d2;color:#9a6500;padding:4px 8px;border-radius:12px;font-family:monospace}.tagr-empty{text-align:center!important;padding:40px!important;color:#7b8794}.tagr-log{max-width:1000px;margin-top:12px}</style><script>jQuery(function($){$("#tagr-all").on("change",function(){$(".tagr-item").prop("checked",this.checked);});});</script></div>';
    }

    private function handle_post(): ?array {
        if ( 'convert' !== ( $_POST['ta_repair_action'] ?? '' ) ) return null;
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_tagr_nonce'] ?? '', 'ta_advanced_grade_repair' ) ) wp_die( 'درخواست نامعتبر یا دسترسی غیرمجاز.' );
        $ids = array_values(array_filter(array_map('absint',(array)($_POST['grade_ids']??[]))));
        if ( ! $ids ) return [ 'type'=>'warning', 'message'=>'هیچ رکوردی برای اصلاح انتخاب نشده است.' ];
        global $wpdb; $table=TA_Database::table('grades'); $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $sql=$wpdb->prepare("UPDATE {$table} SET grade='repeat_advanced', updated_at=%s WHERE course_type='advanced' AND grade='repeat' AND id IN ({$placeholders})",array_merge([current_time('mysql')],$ids));
        $changed=(int)$wpdb->query($sql); $valid_ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE course_type='advanced' AND grade='repeat_advanced' AND id IN ({$placeholders})",$ids));
        $logs=get_option('ta_advanced_grade_repair_log',[]);if(!is_array($logs))$logs=[];array_unshift($logs,['time'=>current_time('mysql'),'user'=>wp_get_current_user()->user_login,'count'=>$changed,'ids'=>array_map('absint',$valid_ids),'from'=>'repeat','to'=>'repeat_advanced']);update_option('ta_advanced_grade_repair_log',array_slice($logs,0,30),false);
        return [ 'type'=>$changed?'success':'warning', 'message'=>$changed?'تعداد '.$changed.' رکورد با موفقیت به repeat_advanced تبدیل شد.':'رکورد انتخاب‌شده دیگر با شرط repeat و advanced منطبق نبود.' ];
    }
}
