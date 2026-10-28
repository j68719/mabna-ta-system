<?php
defined( 'ABSPATH' ) || exit;

final class TA_SMS_Log_Report {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ta_sms_logs';
    }

    private function filters(): array {
        $type = sanitize_key( wp_unslash( $_GET['sms_type'] ?? '' ) );
        $status = sanitize_key( wp_unslash( $_GET['sms_status'] ?? '' ) );
        $search = sanitize_text_field( wp_unslash( $_GET['sms_search'] ?? '' ) );
        $from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['sms_from'] ?? '' ) ? sanitize_text_field( wp_unslash( $_GET['sms_from'] ) ) : '';
        $to = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['sms_to'] ?? '' ) ? sanitize_text_field( wp_unslash( $_GET['sms_to'] ) ) : '';
        $allowed_types = array_keys( TA_MeliPayamak::NOTIFICATION_TYPES );
        if ( $type && ! in_array( $type, $allowed_types, true ) && $type !== 'unknown' ) $type = '';
        if ( $status && ! in_array( $status, [ 'sent', 'failed' ], true ) ) $status = '';
        return compact( 'type', 'status', 'search', 'from', 'to' );
    }

    private function where( array $filters, array &$args ): string {
        global $wpdb;
        $where = [ '1=1' ];
        if ( $filters['type'] !== '' ) { $where[] = 'notification_type = %s'; $args[] = $filters['type']; }
        if ( $filters['status'] !== '' ) { $where[] = 'status = %s'; $args[] = $filters['status']; }
        if ( $filters['from'] !== '' ) { $where[] = 'created_at >= %s'; $args[] = $filters['from'] . ' 00:00:00'; }
        if ( $filters['to'] !== '' ) { $where[] = 'created_at <= %s'; $args[] = $filters['to'] . ' 23:59:59'; }
        if ( $filters['search'] !== '' ) {
            $like = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
            $where[] = '(phone LIKE %s OR recipient_name LIKE %s OR CAST(critique_order_id AS CHAR) LIKE %s OR CAST(wc_order_id AS CHAR) LIKE %s OR notification_type LIKE %s)';
            array_push( $args, $like, $like, $like, $like, $like );
        }
        return implode( ' AND ', $where );
    }

    private function rows( array $filters, int $limit = 50, int $offset = 0 ): array {
        global $wpdb;
        $args = [];
        $where = $this->where( $filters, $args );
        $args[] = $limit;
        $args[] = $offset;
        $sql = "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
        return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: [];
    }

    private function count( array $filters ): int {
        global $wpdb;
        $args = [];
        $where = $this->where( $filters, $args );
        $sql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$where}";
        return (int) $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql );
    }

    public function export_csv(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
        $filters = $this->filters();
        $rows = $this->rows( $filters, 100000, 0 );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=ta-sms-report-' . gmdate( 'Y-m-d-His' ) . '.csv' );
        echo "\xEF\xBB\xBF";
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'ردیف', 'تاریخ', 'نوع پیام', 'وضعیت', 'نقش گیرنده', 'نام گیرنده', 'شماره', 'شناسه سفارش نقد', 'شناسه سفارش ووکامرس', 'کد الگو', 'پیام/نتیجه' ] );
        foreach ( $rows as $i => $row ) {
            fputcsv( $out, [ $i + 1, $row['created_at'], $this->type_label( $row['notification_type'] ), $this->status_label( $row['status'] ), $row['recipient_role'], $row['recipient_name'], $row['phone'], $row['critique_order_id'], $row['wc_order_id'], $row['pattern_id'], $row['message'] ] );
        }
        fclose( $out );
        exit;
    }

    private function type_label( string $type ): string {
        return TA_MeliPayamak::NOTIFICATION_TYPES[ $type ] ?? ( 'unknown' === $type ? 'نامشخص' : $type );
    }

    private function status_label( string $status ): string {
        return 'sent' === $status ? 'موفق' : 'ناموفق';
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
        global $wpdb;
        $filters = $this->filters();
        $paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $per_page = min( 100, max( 10, absint( $_GET['per_page'] ?? 25 ) ) );
        $total = $this->count( $filters );
        $rows = $this->rows( $filters, $per_page, ( $paged - 1 ) * $per_page );
        $args = [];
        $where = $this->where( $filters, $args );
        $summary = $wpdb->get_row( $args ? $wpdb->prepare( "SELECT COUNT(*) total, SUM(status='sent') sent, SUM(status='failed') failed, MAX(created_at) last_sent FROM {$this->table()} WHERE {$where}", $args ) : "SELECT COUNT(*) total, SUM(status='sent') sent, SUM(status='failed') failed, MAX(created_at) last_sent FROM {$this->table()} WHERE {$where}", ARRAY_A );
        $export_args = array_filter( [ 'page' => 'ta-sms-logs', 'action' => 'export_csv', 'sms_type' => $filters['type'], 'sms_status' => $filters['status'], 'sms_search' => $filters['search'], 'sms_from' => $filters['from'], 'sms_to' => $filters['to'] ] );
        $export_url = wp_nonce_url( add_query_arg( $export_args, admin_url( 'admin.php' ) ), 'ta_sms_logs_export' );
        $base = array_filter( [ 'page' => 'ta-sms-logs', 'sms_type' => $filters['type'], 'sms_status' => $filters['status'], 'sms_search' => $filters['search'], 'sms_from' => $filters['from'], 'sms_to' => $filters['to'], 'per_page' => $per_page ] );
        ?>
        <div class="wrap" dir="rtl" style="max-width:1400px">
            <h1 style="display:flex;justify-content:space-between;align-items:center">گزارش پیامک‌های ارسالی <a class="button button-primary" href="<?= esc_url( $export_url ) ?>">خروجی CSV</a></h1>
            <p style="color:#646970">تاریخچه موفقیت، خطا، گیرنده، نوع الگو و ارتباط پیامک با سفارش‌های نقد آثار.</p>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:18px 0">
                <?php foreach ( [ [ 'کل رکوردها', (int) ( $summary['total'] ?? 0 ), '#2271b1' ], [ 'ارسال موفق', (int) ( $summary['sent'] ?? 0 ), '#16855b' ], [ 'ناموفق', (int) ( $summary['failed'] ?? 0 ), '#d63638' ], [ 'آخرین ارسال', $summary['last_sent'] ?: '—', '#7040a0' ] ] as $card ) : ?>
                    <div style="background:#fff;border:1px solid #dcdcde;border-right:4px solid <?= esc_attr( $card[2] ) ?>;padding:16px;border-radius:8px"><div style="color:#646970"><?= esc_html( $card[0] ) ?></div><strong style="font-size:23px;display:block;margin-top:8px"><?= esc_html( is_numeric( $card[1] ) ? number_format_i18n( (float) $card[1] ) : (string) $card[1] ) ?></strong></div>
                <?php endforeach; ?>
            </div>
            <form method="get" style="background:#fff;border:1px solid #dcdcde;padding:15px;border-radius:8px;margin-bottom:18px">
                <input type="hidden" name="page" value="ta-sms-logs">
                <input type="search" name="sms_search" value="<?= esc_attr( $filters['search'] ) ?>" placeholder="جست‌وجو: نام، شماره یا شناسه سفارش" style="min-width:260px">
                <select name="sms_type"><option value="">همه انواع پیام</option><?php foreach ( TA_MeliPayamak::NOTIFICATION_TYPES as $key => $label ) : ?><option value="<?= esc_attr( $key ) ?>" <?= selected( $filters['type'], $key, false ) ?>><?= esc_html( $label ) ?></option><?php endforeach; ?></select>
                <select name="sms_status"><option value="">همه وضعیت‌ها</option><option value="sent" <?= selected( $filters['status'], 'sent', false ) ?>>موفق</option><option value="failed" <?= selected( $filters['status'], 'failed', false ) ?>>ناموفق</option></select>
                از <input type="date" name="sms_from" value="<?= esc_attr( $filters['from'] ) ?>"> تا <input type="date" name="sms_to" value="<?= esc_attr( $filters['to'] ) ?>">
                <button class="button button-primary">اعمال فیلتر</button>
                <a class="button" href="<?= esc_url( admin_url( 'admin.php?page=ta-sms-logs' ) ) ?>">پاک‌کردن</a>
            </form>
            <div style="overflow:auto;background:#fff;border:1px solid #dcdcde;border-radius:8px">
                <table class="widefat striped" style="min-width:1100px"><thead><tr><th>ردیف</th><th>تاریخ</th><th>نوع</th><th>وضعیت</th><th>گیرنده</th><th>شماره</th><th>سفارش</th><th>کد الگو</th><th>نتیجه</th></tr></thead><tbody>
                <?php if ( ! $rows ) : ?><tr><td colspan="9">رکوردی پیدا نشد.</td></tr><?php else : foreach ( $rows as $i => $row ) : ?><tr><td><?= esc_html( ( ( $paged - 1 ) * $per_page ) + $i + 1 ) ?></td><td dir="ltr"><?= esc_html( $row['created_at'] ) ?></td><td><?= esc_html( $this->type_label( $row['notification_type'] ) ) ?></td><td><strong style="color:<?= 'sent' === $row['status'] ? '#16855b' : '#d63638' ?>"><?= esc_html( $this->status_label( $row['status'] ) ) ?></strong></td><td><?= esc_html( $row['recipient_name'] ?: $row['recipient_role'] ) ?></td><td dir="ltr"><?= esc_html( $row['phone'] ) ?></td><td>#<?= esc_html( $row['critique_order_id'] ?: $row['wc_order_id'] ?: '—' ) ?></td><td dir="ltr"><?= esc_html( $row['pattern_id'] ) ?></td><td><?= esc_html( $row['message'] ) ?></td></tr><?php endforeach; endif; ?></tbody></table>
            </div>
            <?php if ( $total > $per_page ) : ?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( [ 'base' => add_query_arg( array_merge( $base, [ 'paged' => '%#%' ] ), admin_url( 'admin.php' ) ), 'format' => '', 'current' => $paged, 'total' => (int) ceil( $total / $per_page ), 'prev_text' => 'قبلی', 'next_text' => 'بعدی' ] ) ); ?></div></div><?php endif; ?>
        </div>
        <?php
    }
}
