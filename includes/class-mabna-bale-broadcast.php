<?php
/**
 * مرکز ارسال پیام بله: کمپین، صف، گزارش زنده و فیلترهای پایه.
 */
defined( 'ABSPATH' ) || exit;

class Mabna_Bale_Broadcast {
    const VERSION = '1.0.0';
    const CRON_HOOK = 'mabna_bale_broadcast_worker';
    const BATCH_SIZE = 25;

    public function __construct() {
        add_filter( 'cron_schedules', [ $this, 'cron_schedules' ] );
        add_action( 'admin_menu', [ $this, 'menu' ], 20 );
        add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
        add_action( 'wp_ajax_mabna_bale_broadcast_counts', [ $this, 'ajax_counts' ] );
        add_action( 'wp_ajax_mabna_bale_broadcast_create', [ $this, 'ajax_create' ] );
        add_action( 'wp_ajax_mabna_bale_broadcast_status', [ $this, 'ajax_status' ] );
        add_action( 'wp_ajax_mabna_bale_broadcast_stop', [ $this, 'ajax_stop' ] );
        add_action( self::CRON_HOOK, [ $this, 'worker' ] );
    }

    public function cron_schedules( array $schedules ): array {
        if ( empty( $schedules['minute'] ) ) $schedules['minute'] = [ 'interval' => 60, 'display' => 'هر دقیقه — صف ارسال بله' ];
        return $schedules;
    }

    public static function tables(): array {
        global $wpdb;
        return [
            'campaigns' => $wpdb->prefix . 'mabna_bale_broadcasts',
            'queue'     => $wpdb->prefix . 'mabna_bale_broadcast_queue',
        ];
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::tables();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$t['campaigns']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(190) NOT NULL,
            message longtext NOT NULL,
            target_type varchar(40) NOT NULL DEFAULT 'all',
            keyboard longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            total int unsigned NOT NULL DEFAULT 0,
            sent int unsigned NOT NULL DEFAULT 0,
            failed int unsigned NOT NULL DEFAULT 0,
            skipped int unsigned NOT NULL DEFAULT 0,
            attempts int unsigned NOT NULL DEFAULT 0,
            last_error text NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            started_at datetime NULL,
            finished_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), KEY status_updated (status,updated_at)
        ) {$charset};" );
        dbDelta( "CREATE TABLE {$t['queue']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            chat_id varchar(100) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            attempts tinyint unsigned NOT NULL DEFAULT 0,
            error text NULL,
            sent_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY campaign_chat (campaign_id,chat_id), KEY work (campaign_id,status,id)
        ) {$charset};" );
        update_option( 'mabna_bale_broadcast_version', self::VERSION, false );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) wp_schedule_single_event( time() + 60, self::CRON_HOOK );
    }

    public static function maybe_install(): void {
        if ( get_option( 'mabna_bale_broadcast_version' ) !== self::VERSION ) self::install();
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) wp_schedule_single_event( time() + 60, self::CRON_HOOK );
    }

    public function menu(): void {
        add_submenu_page( 'mabna-bale', 'مرکز ارسال پیام', 'مرکز ارسال پیام', 'manage_options', 'mabna-bale-broadcast', [ $this, 'page' ] );
    }

    public function assets( string $hook ): void {
        if ( empty( $_GET['page'] ) || $_GET['page'] !== 'mabna-bale-broadcast' ) return;
        wp_enqueue_media();
    }

    private function check(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز.' ], 403 );
        check_ajax_referer( 'mabna_bale_broadcast', 'nonce' );
    }

    private function phone_meta_keys(): array {
        $keys = get_option( 'mabna_bale_phone_priority', [ 'kerasno-phone', 'phone', 'Mreeir_phone', 'billing_phone', 'mabna_bale_phone' ] );
        $keys = array_map( 'sanitize_key', (array) $keys );
        $keys[] = 'mabna_bale_phone';
        return array_values( array_unique( array_filter( $keys ) ) );
    }

    private function no_phone_clause( string $chat_alias = 'chat' ): string {
        global $wpdb;
        $keys = array_map( static function ( string $key ): string { return "'" . esc_sql( $key ) . "'"; }, $this->phone_meta_keys() );
        return " AND NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} phone WHERE phone.user_id={$chat_alias}.user_id AND phone.meta_key IN (" . implode( ',', $keys ) . ") AND TRIM(phone.meta_value)<>'')";
    }

    private function challenge_no_phone_sql(): string {
        global $wpdb;
        $sessions = $wpdb->prefix . 'mabna_bale_challenge_sessions';
        // گزارش شرکت‌کنندگان نیز دقیقاً از همین دو ستون استفاده می‌کند:
        // کل = COUNT(*) و دارای شماره = phone IS NOT NULL AND phone<>''.
        // عمداً به user_id یا usermeta وابسته نیستیم؛ بعضی شرکت‌کنندگان
        // حساب وردپرس ندارند اما chat_id معتبر و قابل ارسال دارند.
        return "SELECT DISTINCT s.chat_id, s.user_id FROM {$sessions} s
            WHERE s.chat_id<>'' AND (s.phone IS NULL OR TRIM(s.phone)='')";
    }

    private function admin_target_sql(): string {
        global $wpdb;
        $m = $wpdb->usermeta;
        $sessions = $wpdb->prefix . 'mabna_bale_challenge_sessions';
        return "SELECT DISTINCT targets.chat_id, targets.user_id FROM (
            SELECT chat.meta_value AS chat_id, chat.user_id FROM {$m} chat
            INNER JOIN {$wpdb->users} users ON users.ID=chat.user_id
            WHERE chat.meta_key='mabna_bale_chat_id' AND chat.meta_value<>''
            AND EXISTS (SELECT 1 FROM {$m} caps WHERE caps.user_id=chat.user_id AND caps.meta_key='{$wpdb->prefix}capabilities'
                AND (caps.meta_value LIKE '%administrator%' OR caps.meta_value LIKE '%manage_options%'))
            UNION
            SELECT s.chat_id, COALESCE(admin_chat.user_id,s.user_id) AS user_id FROM {$sessions} s
            LEFT JOIN {$m} admin_chat ON admin_chat.meta_key='mabna_bale_chat_id' AND admin_chat.meta_value=s.chat_id
            WHERE s.chat_id<>''
            AND EXISTS (SELECT 1 FROM {$m} session_caps WHERE session_caps.user_id=COALESCE(admin_chat.user_id,s.user_id)
                AND session_caps.meta_key='{$wpdb->prefix}capabilities'
                AND (session_caps.meta_value LIKE '%administrator%' OR session_caps.meta_value LIKE '%manage_options%'))
        ) AS targets";
    }

    private function admin_target_rows(): array {
        global $wpdb;
        $sessions = $wpdb->prefix . 'mabna_bale_challenge_sessions';
        $cap_key  = $wpdb->prefix . 'capabilities';
        $admins   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT u.ID FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} caps ON caps.user_id=u.ID AND caps.meta_key=%s WHERE caps.meta_value LIKE %s OR caps.meta_value LIKE %s", $cap_key, '%administrator%', '%manage_options%' ) );
        $admins = array_map( 'absint', (array) $admins );
        if ( ! $admins ) return [];
        $admin_ids = implode( ',', $admins );
        $phone_keys = array_map( static function ( string $key ): string { return "'" . esc_sql( $key ) . "'"; }, $this->phone_meta_keys() );
        $phone_rows = $wpdb->get_results( "SELECT user_id,meta_value FROM {$wpdb->usermeta} WHERE user_id IN ({$admin_ids}) AND meta_key IN (" . implode( ',', $phone_keys ) . ") AND TRIM(meta_value)<>''", ARRAY_A );
        $admin_phones = [];
        foreach ( $phone_rows as $row ) {
            $phone = mabna_bale_normalize_phone( (string) $row['meta_value'] );
            if ( $phone ) $admin_phones[$phone] = (int) $row['user_id'];
        }
        $rows = $wpdb->get_results( "SELECT DISTINCT chat_id,user_id,phone FROM {$sessions} WHERE chat_id<>''", ARRAY_A );
        $out = [];
        foreach ( $rows as $row ) {
            $uid = (int) $row['user_id'];
            $phone = mabna_bale_normalize_phone( (string) ( $row['phone'] ?? '' ) );
            if ( in_array( $uid, $admins, true ) || ( $phone && isset( $admin_phones[$phone] ) ) ) {
                $out[(string) $row['chat_id']] = [ 'chat_id'=>(string) $row['chat_id'], 'user_id'=>$uid ?: (int)($admin_phones[$phone] ?? 0) ];
            }
        }
        // پوشش مدیرانی که اتصال مستقیم کاربر وردپرس دارند اما در جدول نشست نیستند.
        $meta_rows = $wpdb->get_results( "SELECT user_id,meta_value AS chat_id FROM {$wpdb->usermeta} WHERE user_id IN ({$admin_ids}) AND meta_key='mabna_bale_chat_id' AND meta_value<>''", ARRAY_A );
        foreach ( $meta_rows as $row ) $out[(string) $row['chat_id']] = [ 'chat_id'=>(string) $row['chat_id'], 'user_id'=>(int) $row['user_id'] ];
        return array_values( $out );
    }

    private function student_clause( string $chat_alias = 'chat' ): string {
        global $wpdb;
        $orders      = $wpdb->posts;
        $postmeta    = $wpdb->postmeta;
        $order_items = $wpdb->prefix . 'woocommerce_order_items';
        $itemmeta    = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $statuses    = "'wc-completed','wc-processing','wc-partial-payment'";
        return " EXISTS (
            SELECT 1 FROM {$orders} student_order
            INNER JOIN {$postmeta} customer ON customer.post_id=student_order.ID
                AND customer.meta_key='_customer_user'
                AND CAST(customer.meta_value AS UNSIGNED)={$chat_alias}.user_id
            INNER JOIN {$order_items} order_item ON order_item.order_id=student_order.ID
                AND order_item.order_item_type='line_item'
            INNER JOIN {$itemmeta} product_meta ON product_meta.order_item_id=order_item.order_item_id
                AND product_meta.meta_key='_product_id'
            INNER JOIN {$wpdb->term_relationships} product_rel ON product_rel.object_id=CAST(product_meta.meta_value AS UNSIGNED)
            INNER JOIN {$wpdb->term_taxonomy} product_tax ON product_tax.term_taxonomy_id=product_rel.term_taxonomy_id
                AND product_tax.taxonomy='product_cat'
            INNER JOIN {$wpdb->terms} product_cat ON product_cat.term_id=product_tax.term_id
                AND product_cat.slug='writing'
            WHERE student_order.post_type='shop_order'
                AND student_order.post_status IN ({$statuses})
        )";
    }

    private function target_sql( string $type ): string {
        global $wpdb;
        if ( $type === 'no_phone' ) return $this->challenge_no_phone_sql();
        if ( $type === 'admins' ) return 'SELECT NULL AS chat_id, 0 AS user_id WHERE 1=0';
        $u = $wpdb->users;
        $m = $wpdb->usermeta;
        $base = "SELECT DISTINCT chat.meta_value AS chat_id, chat.user_id FROM {$m} chat INNER JOIN {$u} users ON users.ID=chat.user_id";
        $where = " WHERE chat.meta_key='mabna_bale_chat_id' AND chat.meta_value<>''";
        if ( $type === 'new_7' ) $where .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$m} cm WHERE cm.user_id=chat.user_id AND cm.meta_key='mabna_bale_connected_at' AND cm.meta_value >= %s)", date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS ) );
        if ( $type === 'active_30' ) $where .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$m} cm WHERE cm.user_id=chat.user_id AND cm.meta_key='mabna_bale_connected_at' AND cm.meta_value >= %s)", date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ) );
        if ( $type === 'students' ) $where .= ' AND ' . $this->student_clause();
        if ( $type === 'non_students' ) $where .= ' AND NOT ' . $this->student_clause();
        return $base . $where;
    }

    private function target_options(): array {
        global $wpdb;
        $m = $wpdb->usermeta;
        $all = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$m} WHERE meta_key='mabna_bale_chat_id' AND meta_value<>''" );
        $new = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$m} WHERE meta_key='mabna_bale_chat_id' AND meta_value<>'' AND EXISTS (SELECT 1 FROM {$m} c WHERE c.user_id={$m}.user_id AND c.meta_key='mabna_bale_connected_at' AND c.meta_value >= %s)", date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS ) ) );
        $active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$m} WHERE meta_key='mabna_bale_chat_id' AND meta_value<>'' AND EXISTS (SELECT 1 FROM {$m} c WHERE c.user_id={$m}.user_id AND c.meta_key='mabna_bale_connected_at' AND c.meta_value >= %s)", date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ) ) );
        $admins = count( $this->admin_target_rows() );
        $no_phone = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (" . $this->challenge_no_phone_sql() . ") AS challenge_no_phone" );
        $students = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (" . $this->target_sql( 'students' ) . ") AS student_targets" );
        $non_students = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (" . $this->target_sql( 'non_students' ) . ") AS non_student_targets" );
        return [
            [ 'key'=>'all', 'label'=>'همه کاربران متصل', 'desc'=>'تمام چت‌های معتبر ثبت‌شده در ربات', 'count'=>$all, 'tone'=>'blue' ],
            [ 'key'=>'active_30', 'label'=>'فعال در ۳۰ روز اخیر', 'desc'=>'کاربرانی که اخیراً به ربات متصل شده‌اند', 'count'=>$active, 'tone'=>'green' ],
            [ 'key'=>'new_7', 'label'=>'اتصال‌های جدید', 'desc'=>'کاربران متصل‌شده در ۷ روز گذشته', 'count'=>$new, 'tone'=>'violet' ],
            [ 'key'=>'admins', 'label'=>'مدیران سایت', 'desc'=>'مدیرانی که چت‌آیدی ربات دارند', 'count'=>$admins, 'tone'=>'orange' ],
            [ 'key'=>'no_phone', 'label'=>'استارت‌زده بدون شماره', 'desc'=>'شرکت‌کنندگان ماشین زمان که شماره را به اشتراک نگذاشته‌اند', 'count'=>$no_phone, 'tone'=>'orange' ],
            [ 'key'=>'students', 'label'=>'هنرجویان ما', 'desc'=>'کاربرانی با سفارش معتبر از محصول دسته writing', 'count'=>$students, 'tone'=>'green' ],
            [ 'key'=>'non_students', 'label'=>'کاربرانی که هنرجوی ما نیستند', 'desc'=>'کاربران متصل بدون سفارش معتبر از دسته writing', 'count'=>$non_students, 'tone'=>'violet' ],
        ];
    }

    public function ajax_counts(): void { $this->check(); wp_send_json_success( $this->target_options() ); }

    /** نام نویسنده‌ای که در چالش ماشین زمان به این کاربر/چت اختصاص یافته است. */
    private function challenge_author_name( int $user_id, string $chat_id = '' ): string {
        global $wpdb;
        $tables = Mabna_Bale_Challenge_DB::tables();
        $where  = [];
        $args   = [];
        if ( $user_id ) { $where[] = 's.user_id=%d'; $args[] = $user_id; }
        if ( $chat_id !== '' ) { $where[] = 's.chat_id=%s'; $args[] = $chat_id; }
        if ( ! $where ) return 'دوست نویسنده';

        $sql = "SELECT s.result_key, e.result_config
                FROM {$tables['sessions']} s
                LEFT JOIN {$tables['events']} e ON e.id=s.event_id
                WHERE (" . implode( ' OR ', $where ) . ")
                  AND s.result_key<>''
                ORDER BY (s.completed_at IS NOT NULL) DESC, s.updated_at DESC, s.id DESC
                LIMIT 1";
        $row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
        if ( ! $row ) return 'دوست نویسنده';

        $config  = ! empty( $row['result_config'] ) ? json_decode( $row['result_config'], true ) : [];
        $authors = is_array( $config ) && is_array( $config['authors'] ?? null ) ? $config['authors'] : [];
        $result  = (string) $row['result_key'];
        $name    = $authors[ $result ]['name'] ?? '';
        return is_string( $name ) && $name !== '' ? $name : 'دوست نویسنده';
    }

    private function replace_vars( string $message, int $user_id, string $chat_id = '' ): string {
        $user = $user_id ? get_userdata( $user_id ) : false;
        $first = $user ? (string) get_user_meta( $user_id, 'first_name', true ) : '';
        $last  = $user ? (string) get_user_meta( $user_id, 'last_name', true ) : '';
        $display = $user ? (string) $user->display_name : '';
        $login   = $user ? (string) $user->user_login : '';
        $author  = $this->challenge_author_name( $user_id, $chat_id );
        return strtr( $message, [
            '{{first_name}}'  => $first ?: $display,
            '{{last_name}}'   => $last,
            '{{display_name}}'=> $display,
            '{{username}}'    => $login,
            '{{author_name}}' => $author,
            '{author_name}'   => $author,
            '[نام نویسنده]'  => $author,
        ] );
    }

    public function ajax_create(): void {
        $this->check();
        global $wpdb;
        $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? 'کمپین جدید' ) );
        $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        $type = sanitize_key( $_POST['target_type'] ?? 'all' );
        if ( ! $message ) wp_send_json_error( [ 'message'=>'متن پیام را وارد کنید.' ] );
        if ( ! in_array( $type, [ 'all','active_30','new_7','admins','no_phone','students','non_students' ], true ) ) $type = 'all';
        $keyboard = wp_unslash( $_POST['keyboard'] ?? '' );
        $keyboard_json = '';
        if ( $keyboard ) {
            $decoded = json_decode( $keyboard, true );
            if ( ! is_array( $decoded ) ) wp_send_json_error( [ 'message'=>'ساختار JSON کیبرد معتبر نیست.' ] );
            $keyboard_json = wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE );
        }
        $rows = $type === 'admins' ? $this->admin_target_rows() : $wpdb->get_results( $this->target_sql( $type ), ARRAY_A );
        if ( ! $rows ) wp_send_json_error( [ 'message'=>'برای این گروه کاربر قابل ارسالی پیدا نشد.' ] );
        $t = self::tables(); $now = current_time( 'mysql' );
        $wpdb->insert( $t['campaigns'], [ 'title'=>$title ?: 'کمپین جدید', 'message'=>$message, 'target_type'=>$type, 'keyboard'=>$keyboard_json, 'status'=>'queued', 'total'=>count( $rows ), 'created_by'=>get_current_user_id(), 'created_at'=>$now, 'updated_at'=>$now ], [ '%s','%s','%s','%s','%s','%d','%d','%s','%s' ] );
        $campaign_id = (int) $wpdb->insert_id;
        foreach ( $rows as $row ) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t['queue']} (campaign_id,user_id,chat_id,status,updated_at) VALUES (%d,%d,%s,'queued',%s)", $campaign_id, (int)$row['user_id'], (string)$row['chat_id'], $now ) );
        wp_schedule_single_event( time() + 1, self::CRON_HOOK );
        wp_send_json_success( [ 'id'=>$campaign_id, 'total'=>count($rows) ] );
    }

    public function worker(): void {
        global $wpdb;
        $t = self::tables();
        $campaign = $wpdb->get_row( "SELECT * FROM {$t['campaigns']} WHERE status IN ('queued','running') ORDER BY id ASC LIMIT 1", ARRAY_A );
        if ( ! $campaign ) return;
        $id = (int) $campaign['id'];
        $wpdb->update( $t['campaigns'], [ 'status'=>'running', 'started_at'=>$campaign['started_at'] ?: current_time('mysql'), 'updated_at'=>current_time('mysql') ], [ 'id'=>$id ], [ '%s','%s','%s' ], [ '%d' ] );
        $items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['queue']} WHERE campaign_id=%d AND status='queued' ORDER BY id ASC LIMIT %d", $id, self::BATCH_SIZE ), ARRAY_A );
        if ( ! $items ) { $this->finish( $id ); return; }
        $keyboard = $campaign['keyboard'] ? json_decode( $campaign['keyboard'], true ) : [];
        foreach ( $items as $item ) {
            $wpdb->update( $t['queue'], [ 'status'=>'sending', 'attempts'=>(int)$item['attempts']+1, 'updated_at'=>current_time('mysql') ], [ 'id'=>(int)$item['id'] ] );
            $extra = is_array( $keyboard ) && $keyboard ? [ 'reply_markup'=>$keyboard ] : [];
            $result = mabna_bale_api()->send_message( (string)$item['chat_id'], $this->replace_vars( (string)$campaign['message'], (int)$item['user_id'], (string)$item['chat_id'] ), $extra );
            $ok = ! empty( $result['ok'] );
            $error = $ok ? '' : (string)($result['description'] ?? 'خطای نامشخص API');
            $wpdb->update( $t['queue'], [ 'status'=>$ok?'sent':'failed', 'error'=>$error, 'sent_at'=>$ok?current_time('mysql'):null, 'updated_at'=>current_time('mysql') ], [ 'id'=>(int)$item['id'] ], [ '%s','%s','%s','%s' ], [ '%d' ] );
            if ( ! $ok ) $wpdb->update( $t['campaigns'], [ 'last_error'=>$error, 'updated_at'=>current_time('mysql') ], [ 'id'=>$id ], [ '%s','%s' ], [ '%d' ] );
        }
        $this->sync_counts( $id );
        if ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['queue']} WHERE campaign_id=%d AND status='queued'", $id ) ) ) wp_schedule_single_event( time() + 2, self::CRON_HOOK ); else $this->finish( $id );
    }

    private function sync_counts( int $id ): void {
        global $wpdb; $t=self::tables();
        $counts=$wpdb->get_row($wpdb->prepare("SELECT SUM(status='sent') sent,SUM(status='failed') failed,SUM(status='skipped') skipped,COUNT(*) total FROM {$t['queue']} WHERE campaign_id=%d",$id),ARRAY_A);
        $wpdb->update($t['campaigns'],['sent'=>(int)$counts['sent'],'failed'=>(int)$counts['failed'],'skipped'=>(int)$counts['skipped'],'attempts'=>(int)$counts['sent']+(int)$counts['failed'],'updated_at'=>current_time('mysql')],['id'=>$id]);
    }
    private function finish( int $id ): void { global $wpdb; $t=self::tables(); $this->sync_counts($id); $wpdb->query($wpdb->prepare("UPDATE {$t['campaigns']} SET status=IF(failed>0,'completed_with_errors','completed'),finished_at=%s,updated_at=%s WHERE id=%d AND status<>'stopped'",current_time('mysql'),current_time('mysql'),$id)); }

    public function ajax_status(): void {
        $this->check(); global $wpdb; $t=self::tables(); $id=absint($_POST['id']??0);
        $c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['campaigns']} WHERE id=%d",$id),ARRAY_A); if(!$c)wp_send_json_error(['message'=>'کمپین پیدا نشد.']);
        $q=$wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) count FROM {$t['queue']} WHERE campaign_id=%d GROUP BY status",$id),ARRAY_A); $breakdown=[];foreach($q as $r)$breakdown[$r['status']]=(int)$r['count'];
        wp_send_json_success(['campaign'=>$c,'breakdown'=>$breakdown,'remaining'=>(int)($breakdown['queued']??0)+(int)($breakdown['sending']??0)]);
    }
    public function ajax_stop(): void { $this->check(); global $wpdb; $id=absint($_POST['id']??0);$t=self::tables();$wpdb->query($wpdb->prepare("UPDATE {$t['campaigns']} SET status='stopped',finished_at=%s,updated_at=%s WHERE id=%d AND status IN ('queued','running')",current_time('mysql'),current_time('mysql'),$id));$wpdb->query($wpdb->prepare("UPDATE {$t['queue']} SET status='skipped',updated_at=%s WHERE campaign_id=%d AND status IN ('queued','sending')",current_time('mysql'),$id));wp_send_json_success(); }

    private function built_in_keyboards(): array {
        return [
            [ 'key'=>'welcome_start', 'title'=>'خوش‌آمدگویی — شروع ماشین زمان', 'markup'=>[ 'inline_keyboard'=>[ [ [ 'text'=>'شروع به کار ماشینِ زمان!🚀', 'callback_data'=>'mabna_machine_start_{session_id}' ] ] ] ] ],
            [ 'key'=>'name_choice', 'title'=>'انتخاب نام — ورود یا رد کردن', 'markup'=>[ 'inline_keyboard'=>[ [ [ 'text'=>'وارد کردن نام ✍️', 'callback_data'=>'mabna_name_yes_{session_id}' ], [ 'text'=>'رد کردن این مرحله ⭕️', 'callback_data'=>'mabna_name_no_{session_id}' ] ] ] ] ],
            [ 'key'=>'name_confirm', 'title'=>'تأیید نام و اصلاح نام', 'markup'=>[ 'inline_keyboard'=>[ [ [ 'text'=>'درسته بزن بریم!', 'callback_data'=>'mabna_name_confirm_yes_{session_id}' ] ], [ [ 'text'=>'اصلاح نام و نام خانوادگی', 'callback_data'=>'mabna_name_confirm_no_{session_id}' ] ] ] ] ],
            [ 'key'=>'question_answers', 'title'=>'پاسخ سؤال — الف و ب', 'markup'=>[ 'inline_keyboard'=>[ [ [ 'text'=>'الف', 'callback_data'=>'mabna_author_answer_{session_id}_{question_index}_0' ], [ 'text'=>'ب', 'callback_data'=>'mabna_author_answer_{session_id}_{question_index}_1' ] ] ] ] ],
            [ 'key'=>'share_phone', 'title'=>'اشتراک‌گذاری شماره تلفن', 'markup'=>[ 'keyboard'=>[ [ [ 'text'=>'وارد کردن شماره تلفن و باز شدن قفل!📱🔓', 'request_contact'=>true ] ] ], 'resize_keyboard'=>true, 'one_time_keyboard'=>true ] ],
            [ 'key'=>'result_referral', 'title'=>'نتیجه — دعوت از دوستان', 'markup'=>[ 'inline_keyboard'=>[ [ [ 'text'=>'🌟 دریافت لینک معرف برای دعوت دوستان', 'callback_data'=>'mabna_referral_{session_id}' ] ] ] ] ],
            [ 'key'=>'event_channel_start', 'title'=>'رویداد — کانال و شروع چالش', 'markup'=>[ 'inline_keyboard'=>[ [ [ 'text'=>'📚 کانال مدرسه نویسندگی مبنا', 'url'=>'https://ble.ir/' ], [ 'text'=>'🚀 شروع چالش', 'callback_data'=>'mabna_event_check_{event_id}' ] ] ] ] ],
            [ 'key'=>'event_channel_only', 'title'=>'رویداد — فقط لینک کانال', 'markup'=>[ 'inline_keyboard'=>[ [ [ 'text'=>'👀 از کانال ما دیدن کن!', 'url'=>'https://ble.ir/' ] ] ] ] ],
        ];
    }

    public function page(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );
        $options=$this->target_options(); $t=self::tables(); global $wpdb; $history=$wpdb->get_results("SELECT * FROM {$t['campaigns']} ORDER BY id DESC LIMIT 12",ARRAY_A); $nonce=wp_create_nonce('mabna_bale_broadcast');
        $builtins=$this->built_in_keyboards(); $keyboards=array_merge($builtins,(array)get_option('mabna_bale_keyboards',[]));
        echo '<div class="wrap mabna-broadcast-wrap"><div class="mb-hero"><div><span class="mb-kicker">MABNA · BALE OPERATIONS</span><h1>مرکز ارسال پیام</h1><p>کمپین‌های هدفمند بسازید، ارسال را در پس‌زمینه اجرا کنید و مسیر هر پیام را زنده ببینید.</p></div><div class="mb-hero-mark">↗</div></div><div id="mb-notice"></div><div class="mb-grid"><main><section class="mb-section"><div class="mb-section-title"><span>۱</span><div><h2>انتخاب مخاطبان</h2><p>یک گروه را فعال کنید؛ تعداد واقعی کاربران قبل از ارسال محاسبه می‌شود.</p></div></div><div class="mb-cards">';
        foreach($options as $o) echo '<button type="button" class="mb-card '.esc_attr($o['tone']).'" data-target="'.esc_attr($o['key']).'"><span class="mb-card-check">✓</span><strong>'.esc_html($o['label']).'</strong><small>'.esc_html($o['desc']).'</small><b>'.number_format_i18n($o['count']).' <em>گیرنده</em></b></button>';
        echo '</div></section><section class="mb-section"><div class="mb-section-title"><span>۲</span><div><h2>متن پیام</h2><p>از متغیرهای پویا برای شخصی‌سازی استفاده کنید.</p></div></div><input id="mb-title" class="mb-input" placeholder="نام کمپین، مثلاً اطلاعیه آغاز دوره"><textarea id="mb-message" class="mb-textarea" rows="8" placeholder="متن پیام را بنویسید...\n\nمتغیرها: {{first_name}} ، {{last_name}} ، {{display_name}} ، {{author_name}} یا [نام نویسنده]"></textarea><p class="mb-variable-help">متغیر نام نویسنده چالش ماشین زمان: <code>{{author_name}}</code> یا <code>[نام نویسنده]</code> — برای هر گیرنده بر اساس نتیجه همان کاربر جایگزین می‌شود.</p><div class="mb-meta"><span id="mb-counter">۰ کاراکتر</span><span>پیش‌نمایش در مرحله‌ی ارسال فعال می‌شود</span></div></section><section class="mb-section"><div class="mb-section-title"><span>۳</span><div><h2>کیبرد همراه</h2><p>کیبرد را به‌صورت JSON انتخاب یا سفارشی‌سازی کنید.</p></div></div><select id="mb-keyboard-select" class="mb-input"><option value="">بدون کیبرد</option><option value="event">کیبرد کانال و شروع چالش</option>';foreach($keyboards as $i=>$kb)echo '<option value="saved_'.(int)$i.'">'.esc_html($kb['title']??('کیبرد '.($i+1))).'</option>';echo '</select><textarea id="mb-keyboard" class="mb-textarea mb-code" rows="5" placeholder="اختیاری: {&quot;inline_keyboard&quot;:[[{&quot;text&quot;:&quot;مشاهده&quot;,&quot;url&quot;:&quot;https://ble.ir/&quot;}]]}"></textarea></section></main><aside><section class="mb-side"><span class="mb-kicker">LIVE DELIVERY</span><h2>خلاصه ارسال</h2><div class="mb-summary"><div><small>مخاطب انتخاب‌شده</small><strong id="mb-selected-count">—</strong></div><div><small>وضعیت</small><strong id="mb-selected-label">آماده‌سازی</strong></div></div><button type="button" id="mb-create" class="button button-primary mb-submit">ایجاد و شروع کمپین</button><p class="mb-side-note">ارسال در پس‌زمینه انجام می‌شود و لازم نیست این صفحه باز بماند.</p></section><section class="mb-side mb-live" id="mb-live" style="display:none"><div class="mb-live-head"><h2>گزارش لحظه‌ای</h2><span id="mb-status-pill">در صف</span></div><div class="mb-progress"><i id="mb-progress-bar"></i></div><div class="mb-stat-grid"><div><b id="mb-sent">۰</b><small>موفق</small></div><div><b id="mb-failed">۰</b><small>ناموفق</small></div><div><b id="mb-remaining">۰</b><small>باقی‌مانده</small></div></div><button type="button" id="mb-stop" class="button mb-stop">توقف کمپین</button><p id="mb-last-error" class="mb-error"></p></section></aside></div><section class="mb-section mb-history"><div class="mb-section-title"><span>۴</span><div><h2>تاریخچه کمپین‌ها</h2><p>آخرین عملیات و وضعیت ارسال‌های قبلی.</p></div></div><div class="mb-table-wrap"><table class="widefat striped"><thead><tr><th>کمپین</th><th>مخاطب</th><th>پیشرفت</th><th>وضعیت</th><th>زمان ایجاد</th></tr></thead><tbody id="mb-history-body">';foreach($history as $h){$done=(int)$h['sent']+(int)$h['failed']+(int)$h['skipped'];$pct=$h['total']?round($done*100/$h['total']):0;echo '<tr><td><strong>'.esc_html($h['title']).'</strong><br><small>#'.(int)$h['id'].'</small></td><td>'.number_format_i18n($h['total']).'</td><td>'.number_format_i18n($pct).'% · '.number_format_i18n($h['sent']).' موفق</td><td><span class="mb-pill mb-pill-'.esc_attr($h['status']).'">'.esc_html($this->status_label($h['status'])).'</span></td><td>'.esc_html($h['created_at']).'</td></tr>'; }if(!$history)echo '<tr><td colspan="5" class="mb-empty">هنوز کمپینی ایجاد نشده است.</td></tr>';echo '</tbody></table></div></section></div><style>'.self::css().'</style><script>'.self::js($nonce).'</script>';
    }
    private function status_label(string $s):string{return ['queued'=>'در صف','running'=>'در حال ارسال','completed'=>'تکمیل‌شده','completed_with_errors'=>'با خطا','stopped'=>'متوقف‌شده'][$s]??$s;}
    private static function css():string{return <<<'CSS'
.mabna-broadcast-wrap{max-width:1480px;margin:24px 20px 40px 0;direction:rtl;color:#172033}.mb-hero{background:linear-gradient(120deg,#101b35,#163b64 58%,#127c8d);color:#fff;border-radius:24px;padding:34px 38px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 18px 50px #0b234022}.mb-kicker{font-size:11px;letter-spacing:1.5px;font-weight:800;opacity:.7}.mb-hero h1{font-size:34px;margin:10px 0 6px}.mb-hero p{margin:0;opacity:.78;font-size:14px}.mb-hero-mark{font-size:70px;opacity:.6}.mb-grid{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:20px;margin-top:20px}.mb-section,.mb-side{background:#fff;border:1px solid #e7ebf3;border-radius:20px;padding:24px;margin-bottom:20px;box-shadow:0 8px 25px #12264708}.mb-section-title{display:flex;gap:13px;align-items:flex-start;margin-bottom:20px}.mb-section-title>span{background:#e9f7f8;color:#087d87;border-radius:10px;padding:7px 10px;font-weight:800}.mb-section h2,.mb-side h2{margin:0 0 5px;font-size:18px}.mb-section-title p{margin:0;color:#7b8798;font-size:13px}.mb-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.mb-card{position:relative;text-align:right;border:1px solid #e5eaf1;background:#fbfcfe;border-radius:16px;padding:17px;cursor:pointer;transition:.18s;min-height:130px}.mb-card:hover{transform:translateY(-2px);box-shadow:0 8px 18px #10244612}.mb-card.active{border-color:#1596a2;background:#effbfb;box-shadow:0 0 0 3px #1596a222}.mb-card-check{position:absolute;left:12px;top:12px;border:1px solid #d7dfe9;color:transparent;border-radius:50%;width:20px;height:20px;text-align:center}.mb-card.active .mb-card-check{background:#12939f;color:#fff;border-color:#12939f}.mb-card strong,.mb-card small,.mb-card b{display:block}.mb-card strong{font-size:14px;margin-bottom:9px}.mb-card small{color:#7b8798;line-height:1.7}.mb-card b{font-size:25px;margin-top:12px}.mb-card em{font-size:11px;color:#7b8798;font-style:normal;font-weight:400}.mb-card.blue{border-top:3px solid #3978de}.mb-card.green{border-top:3px solid #21a36c}.mb-card.violet{border-top:3px solid #8a5ce3}.mb-card.orange{border-top:3px solid #e99038}.mb-input,.mb-textarea{width:100%;box-sizing:border-box;border:1px solid #dfe5ee;border-radius:12px;padding:12px 14px;margin-bottom:10px;background:#fbfcfe;direction:rtl}.mb-textarea{resize:vertical;line-height:1.9}.mb-code{direction:ltr;text-align:left;font-family:monospace;font-size:12px}.mb-meta{display:flex;justify-content:space-between;color:#8893a5;font-size:11px}.mb-side{position:sticky;top:32px}.mb-side .mb-kicker{color:#0c8894}.mb-summary{background:#f6f8fb;border-radius:14px;padding:15px;margin:16px 0;display:grid;grid-template-columns:1fr 1fr;gap:12px}.mb-summary small,.mb-summary strong,.mb-stat-grid small,.mb-stat-grid b{display:block}.mb-summary small,.mb-stat-grid small{color:#7b8798;font-size:11px}.mb-summary strong{font-size:20px;margin-top:5px}.mb-submit{width:100%;height:46px;border:0!important;border-radius:12px!important;background:#087d87!important}.mb-side-note{font-size:11px;color:#8993a1;line-height:1.8}.mb-live-head{display:flex;justify-content:space-between;align-items:center}.mb-live-head span,.mb-pill{border-radius:30px;padding:4px 9px;font-size:11px;background:#e9f7f8;color:#087d87}.mb-progress{height:9px;background:#e9edf3;border-radius:9px;overflow:hidden;margin:18px 0}.mb-progress i{display:block;height:100%;width:0;background:linear-gradient(90deg,#17a4a9,#59d3a0);transition:width .3s}.mb-stat-grid{display:grid;grid-template-columns:repeat(3,1fr);text-align:center;gap:8px}.mb-stat-grid b{font-size:22px}.mb-stop{width:100%;margin-top:18px}.mb-error{color:#c53b3b;font-size:11px;line-height:1.7}.mb-pill-running{background:#fff5dc;color:#a66a00}.mb-pill-completed{background:#e5f7ed;color:#16804e}.mb-pill-completed_with_errors{background:#fff0e7;color:#b95816}.mb-pill-stopped{background:#f0f1f4;color:#6e7785}.mb-table-wrap{overflow:auto}.mb-history table{border:0}.mb-history td,.mb-history th{text-align:right;padding:14px}.mb-empty{text-align:center;color:#8993a1;padding:30px!important}@media(max-width:900px){.mb-grid{grid-template-columns:1fr}.mb-side{position:static}.mb-cards{grid-template-columns:repeat(2,1fr)}}@media(max-width:560px){.mb-cards{grid-template-columns:1fr}.mb-hero{padding:24px}.mb-hero h1{font-size:26px}.mb-hero-mark{display:none}}
CSS;}
    private static function js(string $nonce):string{return '(()=>{const n='.wp_json_encode($nonce).',ajax='.wp_json_encode(admin_url('admin-ajax.php')).';let target="all",cid=0,timer=null;const $=s=>document.querySelector(s),cards=[...document.querySelectorAll(".mb-card")],kb={welcome_start:{inline_keyboard:[[{text:"شروع به کار ماشینِ زمان!🚀",callback_data:"mabna_machine_start_{session_id}"}]]},name_choice:{inline_keyboard:[[{text:"وارد کردن نام ✍️",callback_data:"mabna_name_yes_{session_id}"},{text:"رد کردن این مرحله ⭕️",callback_data:"mabna_name_no_{session_id}"}]]},name_confirm:{inline_keyboard:[[{text:"درسته بزن بریم!",callback_data:"mabna_name_confirm_yes_{session_id}"}],[{text:"اصلاح نام و نام خانوادگی",callback_data:"mabna_name_confirm_no_{session_id}"}]]},question_answers:{inline_keyboard:[[{text:"الف",callback_data:"mabna_author_answer_{session_id}_{question_index}_0"},{text:"ب",callback_data:"mabna_author_answer_{session_id}_{question_index}_1"}]]},share_phone:{keyboard:[[{text:"وارد کردن شماره تلفن و باز شدن قفل!📱🔓",request_contact:true}]],resize_keyboard:true,one_time_keyboard:true},result_referral:{inline_keyboard:[[{text:"🌟 دریافت لینک معرف برای دعوت دوستان",callback_data:"mabna_referral_{session_id}"}]]},event_channel_start:{inline_keyboard:[[{text:"📚 کانال مدرسه نویسندگی مبنا",url:"https://ble.ir/"},{text:"🚀 شروع چالش",callback_data:"mabna_event_check_{event_id}"}]]},event_channel_only:{inline_keyboard:[[{text:"👀 از کانال ما دیدن کن!",url:"https://ble.ir/"}]]}};function post(action,data={}){data.action=action;data.nonce=n;return fetch(ajax,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:new URLSearchParams(data)}).then(r=>r.json())}function fa(n){return new Intl.NumberFormat("fa-IR").format(n||0)}function notify(m,err=false){$("#mb-notice").innerHTML=`<div class="notice notice-${err?"error":"success"} is-dismissible"><p>${m}</p></div>`}cards.forEach(c=>c.onclick=()=>{cards.forEach(x=>x.classList.remove("active"));c.classList.add("active");target=c.dataset.target;$("#mb-selected-count").textContent=c.querySelector("b").textContent.trim();$("#mb-selected-label").textContent=c.querySelector("strong").textContent});$("#mb-message").oninput=e=>$("#mb-counter").textContent=fa(e.target.value.length)+" کاراکتر";$("#mb-keyboard-select").onchange=e=>{let v=e.target.value;if(v.startsWith("builtin_")){let k=v.substring(8);$("#mb-keyboard").value=JSON.stringify(kb[k]||{},null,2)}else if(v.startsWith("saved_")){let i=parseInt(v.substring(6),10),keys=["welcome_start","name_choice","name_confirm","question_answers","share_phone","result_referral","event_channel_start","event_channel_only"],k=keys[i];if(k&&kb[k])$("#mb-keyboard").value=JSON.stringify(kb[k],null,2)}else if(v==="event")$("#mb-keyboard").value=JSON.stringify(kb.event_channel_start,null,2)};$("#mb-create").onclick=()=>{let k=$("#mb-keyboard").value.trim();post("mabna_bale_broadcast_create",{title:$("#mb-title").value,message:$("#mb-message").value,target_type:target,keyboard:k}).then(r=>{if(!r.success)return notify(r.data?.message||"خطا در ایجاد کمپین",true);cid=r.data.id;$("#mb-live").style.display="block";notify("کمپین ایجاد شد؛ ارسال در پس‌زمینه آغاز شد.");poll()})};function poll(){clearTimeout(timer);post("mabna_bale_broadcast_status",{id:cid}).then(r=>{if(!r.success)return;let c=r.data.campaign,b=r.data.breakdown||{},done=(b.sent||0)+(b.failed||0)+(b.skipped||0),pct=c.total?Math.round(done*100/c.total):0;$("#mb-progress-bar").style.width=pct+"%";$("#mb-sent").textContent=fa(b.sent);$("#mb-failed").textContent=fa(b.failed);$("#mb-remaining").textContent=fa(r.data.remaining);$("#mb-status-pill").textContent={queued:"در صف",running:"در حال ارسال",completed:"تکمیل‌شده",completed_with_errors:"با خطا",stopped:"متوقف‌شده"}[c.status]||c.status;$("#mb-last-error").textContent=c.last_error||"";if(["queued","running"].includes(c.status))timer=setTimeout(poll,2500)})}$("#mb-stop").onclick=()=>{if(!cid)return;post("mabna_bale_broadcast_stop",{id:cid}).then(()=>{notify("کمپین متوقف شد.");poll()})};})();';}
}
