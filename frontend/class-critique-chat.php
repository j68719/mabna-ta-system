<?php
defined( 'ABSPATH' ) || exit;

/**
 * گفتگوی (چت) داخلی مرتبط با هر سفارش نقد اثر.
 * سه نخِ جداگانه:
 *   - ta_student     : هنرجو ↔ استاد تسهیلگر
 *   - admin_student   : هنرجو ↔ ادمین
 *   - admin_ta        : استاد تسهیلگر ↔ ادمین
 * هرکس فقط پیام‌های نخ‌هایی را می‌بیند که یکی از دو طرفش باشد
 * (ادمین به‌عنوان طرف دوم در admin_student و admin_ta، و به‌صورت
 * فقط‌مشاهده هم در ta_student برای نظارت در پنل مدیریت).
 */
class TA_Critique_Chat {

    const THREADS = [ 'ta_student', 'admin_student', 'admin_ta' ];

    public function __construct() {
        add_action( 'wp_ajax_ta_chat_send',  [ $this, 'ajax_send' ] );
        add_action( 'wp_ajax_ta_chat_fetch', [ $this, 'ajax_fetch' ] );
        add_action( 'wp_ajax_ta_chat_unread_counts', [ $this, 'ajax_unread_counts' ] );
    }

    // ─────────────────────────────────────────────────────────────────────
    // دسترسی
    // ─────────────────────────────────────────────────────────────────────

    private function get_order( int $order_id ): ?object {
        $co_table = TA_Database::table( 'critique_orders' );
        return TA_Database::get_row( "SELECT * FROM {$co_table} WHERE id = %d", [ $order_id ] );
    }

    /** نقش کاربر جاری در این نخ گفتگو برای این سفارش، یا null اگر دسترسی نداشته باشد */
    private function resolve_role( object $order, string $thread, int $uid ): ?string {
        $is_admin   = user_can( $uid, 'manage_options' );
        $is_student = ( (int) $order->student_id === $uid );

        $sc_table = TA_Database::table( 'story_critique_forms' );
        $ta_form  = TA_Database::get_row( "SELECT user_id FROM {$sc_table} WHERE id = %d", [ (int) $order->ta_id ] );
        $is_ta    = $ta_form && (int) $ta_form->user_id === $uid;

        switch ( $thread ) {
            case 'ta_student':
                if ( $is_student ) return 'student';
                if ( $is_ta )      return 'ta';
                if ( $is_admin )   return 'admin'; // فقط مشاهده برای نظارت ادمین
                return null;
            case 'admin_student':
                if ( $is_student ) return 'student';
                if ( $is_admin )   return 'admin';
                return null;
            case 'admin_ta':
                if ( $is_ta )    return 'ta';
                if ( $is_admin ) return 'admin';
                return null;
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // AJAX
    // ─────────────────────────────────────────────────────────────────────

    public function ajax_send(): void {
        check_ajax_referer( 'ta_chat_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'ابتدا وارد شوید.' ] );

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $thread   = sanitize_key( $_POST['thread'] ?? '' );
        $message  = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );

        if ( ! in_array( $thread, self::THREADS, true ) ) {
            wp_send_json_error( [ 'message' => 'نخ گفتگو نامعتبر است.' ] );
        }

        $order = $this->get_order( $order_id );
        if ( ! $order ) wp_send_json_error( [ 'message' => 'سفارش یافت نشد.' ] );

        $role = $this->resolve_role( $order, $thread, $uid );
        if ( ! $role ) wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز.' ] );
        if ( $thread === 'ta_student' && $role === 'admin' ) {
            wp_send_json_error( [ 'message' => 'ادمین فقط می‌تواند این گفتگو را مشاهده کند.' ] );
        }

        $file_id = 0;
        if ( ! empty( $_FILES['file'] ) && empty( $_FILES['file']['error'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $att = media_handle_upload( 'file', 0 );
            if ( ! is_wp_error( $att ) ) $file_id = (int) $att;
        }

        if ( ! $message && ! $file_id ) {
            wp_send_json_error( [ 'message' => 'پیام یا فایل را وارد کنید.' ] );
        }

        $id = TA_Database::insert( 'ta_critique_messages', [
            'order_id'    => $order_id,
            'thread'      => $thread,
            'sender_id'   => $uid,
            'sender_role' => $role,
            'message'     => $message,
            'file_id'     => $file_id ?: null,
            'is_read'     => 0,
            'created_at'  => current_time( 'mysql' ),
        ] );

        // پیامک پیام جدید (فقط نخ هنرجو⇄استاد تسهیلگر)
        if ( $thread === 'ta_student' ) {
            $this->notify_new_chat_message( $order, $role, $uid );
        }

        wp_send_json_success( [ 'id' => $id ] );
    }

    /** پیامک به طرف مقابل: پیام جدید در چت هنرجو⇄استاد تسهیلگر */
    private function notify_new_chat_message( object $order, string $sender_role, int $sender_uid ): void {
        $sc_table = TA_Database::table( 'story_critique_forms' );
        $wc_order = wc_get_order( (int) $order->wc_order_id );
        $order_number = $wc_order ? (string) $wc_order->get_order_number() : (string) $order->wc_order_id;
        $sender = get_userdata( $sender_uid );

        if ( $sender_role === 'student' ) {
            // اطلاع به استاد تسهیلگر
            $ta = TA_Database::get_row( "SELECT full_name, phone FROM {$sc_table} WHERE id = %d", [ $order->ta_id ] );
            if ( ! $ta || ! $ta->phone ) return;
            TA_MeliPayamak::notify_chat( $ta->phone, $ta->full_name, $sender->display_name ?? '', $order_number );
        } elseif ( $sender_role === 'ta' ) {
            // اطلاع به هنرجو
            $student_phone = TA_User_Helper::get_phone( (int) $order->student_id );
            if ( ! $student_phone ) return;
            $student = get_userdata( (int) $order->student_id );
            TA_MeliPayamak::notify_chat( $student_phone, $student->display_name ?? '', $sender->display_name ?? '', $order_number );
        }
    }

    /**
     * [FEATURE-UNREAD-BADGE] تعداد پیام‌های نخوانده‌ی «طرف مقابل» در یک نخ گفتگو،
     * بدون این‌که چیزی را خوانده‌شده علامت بزند (صرفاً برای نمایش بج قرمز کنار
     * دکمهٔ باز/بسته‌کردن گفتگو، بدون باز کردن خودِ گفتگو).
     */
    public function ajax_unread_counts(): void {
        check_ajax_referer( 'ta_chat_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'ابتدا وارد شوید.' ] );

        $boxes = json_decode( wp_unslash( $_POST['boxes'] ?? '[]' ), true );
        if ( ! is_array( $boxes ) ) wp_send_json_error( [ 'message' => 'داده نامعتبر' ] );

        $msg_table = TA_Database::table( 'critique_messages' );
        $out = [];
        foreach ( $boxes as $b ) {
            $order_id = absint( $b['order_id'] ?? 0 );
            $thread   = sanitize_key( $b['thread'] ?? '' );
            if ( ! $order_id || ! in_array( $thread, self::THREADS, true ) ) continue;

            $order = $this->get_order( $order_id );
            if ( ! $order ) continue;
            $role = $this->resolve_role( $order, $thread, $uid );
            if ( ! $role ) continue;

            global $wpdb;
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$msg_table} WHERE order_id = %d AND thread = %s AND sender_id != %d AND is_read = 0",
                $order_id, $thread, $uid
            ) );
            $out[ $order_id . '_' . $thread ] = $count;
        }

        wp_send_json_success( $out );
    }

    public function ajax_fetch(): void {
        check_ajax_referer( 'ta_chat_nonce', 'nonce' );
        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'ابتدا وارد شوید.' ] );

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $thread   = sanitize_key( $_POST['thread'] ?? '' );
        $after_id = absint( $_POST['after_id'] ?? 0 );

        if ( ! in_array( $thread, self::THREADS, true ) ) {
            wp_send_json_error( [ 'message' => 'نخ گفتگو نامعتبر است.' ] );
        }

        $order = $this->get_order( $order_id );
        if ( ! $order ) wp_send_json_error( [ 'message' => 'سفارش یافت نشد.' ] );

        $role = $this->resolve_role( $order, $thread, $uid );
        if ( ! $role ) wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز.' ] );

        $msg_table = TA_Database::table( 'critique_messages' );
        $rows = TA_Database::get_results(
            "SELECT * FROM {$msg_table} WHERE order_id = %d AND thread = %s AND id > %d ORDER BY id ASC",
            [ $order_id, $thread, $after_id ]
        );

        global $wpdb;
        // علامت‌گذاری پیام‌های طرف مقابل به‌عنوان خوانده‌شده (به‌جز پیام‌های خودِ ادمینِ ناظر در ta_student)
        if ( ! ( $thread === 'ta_student' && $role === 'admin' ) ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$msg_table} SET is_read = 1 WHERE order_id = %d AND thread = %s AND sender_id != %d",
                $order_id, $thread, $uid
            ) );
        }

        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [
                'id'          => (int) $r->id,
                'sender_role' => $r->sender_role,
                'mine'        => (int) $r->sender_id === $uid,
                'message'     => $r->message,
                'file_url'    => $r->file_id ? wp_get_attachment_url( $r->file_id ) : null,
                'file_name'   => $r->file_id ? basename( (string) get_attached_file( $r->file_id ) ) : null,
                'created_at'  => $r->created_at,
            ];
        }
        wp_send_json_success( [ 'messages' => $out ] );
    }

    // ─────────────────────────────────────────────────────────────────────
    // رندر جعبهٔ چت (قابل‌استفاده در سمت هنرجو، استاد تسهیلگر و ادمین)
    // ─────────────────────────────────────────────────────────────────────

    public static function render_box( int $order_id, string $thread, string $title, bool $open = false ): void {
        static $printed_assets = false;
        $box_id = 'ta-chat-' . $order_id . '-' . $thread;
        $nonce  = wp_create_nonce( 'ta_chat_nonce' );
        $ajax   = admin_url( 'admin-ajax.php' );
        ?>
        <div class="ta-chat-box" id="<?= esc_attr( $box_id ) ?>"
             data-order="<?= (int) $order_id ?>" data-thread="<?= esc_attr( $thread ) ?>"
             data-nonce="<?= esc_attr( $nonce ) ?>" data-ajax="<?= esc_url( $ajax ) ?>">
            <div class="ta-chat-toggle">💬 <?= esc_html( $title ) ?> <span class="ta-chat-badge" style="display:none">0</span> <span class="ta-chat-arrow">▾</span></div>
            <div class="ta-chat-panel" style="display:<?= $open ? 'block' : 'none' ?>">
                <div class="ta-chat-messages"></div>
                <div class="ta-chat-inputrow">
                    <input type="file" class="ta-chat-file" style="display:none">
                    <button type="button" class="ta-chat-attach" title="پیوست فایل">📎</button>
                    <input type="text" class="ta-chat-text" placeholder="پیام خود را بنویسید…">
                    <button type="button" class="ta-chat-send">ارسال</button>
                </div>
                <div class="ta-chat-filename" style="display:none"></div>
            </div>
        </div>
        <?php
        if ( ! $printed_assets ) {
            $printed_assets = true;
            self::print_assets();
        }
        if ( $open ) {
            // برای جعبه‌هایی که از ابتدا باز رندر می‌شوند (مثلاً در پنل ادمین)
            echo '<script>jQuery(function($){ var $b=$("#' . esc_js( $box_id ) . '"); if(window.taChatLoad) taChatLoad($b,false); });</script>';
        }
    }

    private static function print_assets(): void {
        ?>
        <style>
        .ta-chat-box{border:1px solid #e5e7eb;border-radius:8px;margin-top:8px;overflow:hidden;font-size:13px;background:#fff}
        .ta-chat-toggle{background:#f8f9fa;padding:8px 12px;cursor:pointer;font-weight:700;display:flex;justify-content:space-between;align-items:center}
        .ta-chat-panel{padding:10px;border-top:1px solid #f0f0f0}
        .ta-chat-messages{max-height:260px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;margin-bottom:8px;padding:4px}
        .ta-chat-msg{max-width:80%;padding:6px 10px;border-radius:10px;background:#f1f5f9;align-self:flex-start;word-break:break-word}
        .ta-chat-msg.mine{align-self:flex-end;background:#dbeafe}
        .ta-chat-msg .ta-chat-meta{font-size:10px;color:#9ca3af;margin-top:2px}
        .ta-chat-empty{color:#9ca3af;font-size:12px;text-align:center;padding:10px}
        .ta-chat-inputrow{display:flex;gap:6px;align-items:center}
        .ta-chat-text{flex:1;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px}
        .ta-chat-send,.ta-chat-attach{padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer}
        .ta-chat-filename{font-size:11px;color:#2563eb;margin-top:4px}
        .ta-chat-badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:99px;background:#dc2626;color:#fff;font-size:11px;font-weight:700;line-height:18px}
        </style>
        <script>
        (function($){
            function esc(t){ return $('<div>').text(t||'').html(); }

            function updateBadge($box, count){
                var $b = $box.find('.ta-chat-badge');
                if (count > 0) { $b.text(count > 99 ? '99+' : count).show(); }
                else { $b.hide(); }
            }

            // [FEATURE-UNREAD-BADGE] برای همهٔ جعبه‌های گفتگوی این صفحه (چه باز، چه بسته)
            // تعداد پیام نخوانده را بدون باز کردن گفتگو نمایش می‌دهد و هر چند ثانیه به‌روزرسانی می‌کند
            function refreshAllBadges(){
                var $boxes = $('.ta-chat-box');
                if (!$boxes.length) return;
                var boxesData = [];
                var ajaxUrl, nonce;
                $boxes.each(function(){
                    var $b = $(this);
                    ajaxUrl = $b.data('ajax'); nonce = $b.data('nonce');
                    boxesData.push({ order_id: $b.data('order'), thread: $b.data('thread') });
                });
                $.post(ajaxUrl, { action:'ta_chat_unread_counts', nonce:nonce, boxes: JSON.stringify(boxesData) }, function(res){
                    if (!res.success) return;
                    $boxes.each(function(){
                        var $b = $(this);
                        // پنل بازِ در حال نمایش، خودش با taChatLoad پیام‌ها را خوانده‌شده می‌کند؛ بج آن صفر بماند
                        if ($b.find('.ta-chat-panel').is(':visible')) { updateBadge($b, 0); return; }
                        var key = $b.data('order') + '_' + $b.data('thread');
                        updateBadge($b, res.data[key] || 0);
                    });
                });
            }
            $(function(){
                refreshAllBadges();
                setInterval(refreshAllBadges, 15000);
            });

            window.taChatLoad = function($box, sinceOnly){
                var lastId = $box.data('lastId') || 0;
                $.post($box.data('ajax'), {
                    action:'ta_chat_fetch', nonce:$box.data('nonce'),
                    order_id:$box.data('order'), thread:$box.data('thread'), after_id: sinceOnly ? lastId : 0
                }, function(res){
                    if (!res.success) return;
                    var $wrap = $box.find('.ta-chat-messages');
                    if (!sinceOnly) $wrap.empty();
                    if (!sinceOnly && !res.data.messages.length) {
                        $wrap.html('<div class="ta-chat-empty">هنوز پیامی ثبت نشده — اولین پیام را بفرستید.</div>');
                    } else if (res.data.messages.length && $wrap.find('.ta-chat-empty').length) {
                        $wrap.empty();
                    }
                    var roleLabel = {student:'هنرجو', ta:'استاد تسهیلگر', admin:'ادمین'};
                    res.data.messages.forEach(function(m){
                        $box.data('lastId', m.id);
                        var html = '<div class="ta-chat-msg' + (m.mine ? ' mine' : '') + '">';
                        html += '<div class="ta-chat-meta">' + (roleLabel[m.sender_role] || m.sender_role) + '</div>';
                        if (m.message) html += '<div>' + esc(m.message) + '</div>';
                        if (m.file_url) html += '<div><a href="' + m.file_url + '" target="_blank" rel="noopener">📎 ' + esc(m.file_name||'فایل') + '</a></div>';
                        html += '<div class="ta-chat-meta">' + esc(m.created_at) + '</div>';
                        html += '</div>';
                        $wrap.append(html);
                    });
                    if (res.data.messages.length) $wrap.scrollTop($wrap[0].scrollHeight);
                });
            };

            $(document).on('click', '.ta-chat-toggle', function(){
                var $box = $(this).closest('.ta-chat-box');
                var $panel = $box.find('.ta-chat-panel');
                var wasHidden = $panel.is(':hidden');
                $panel.slideToggle(150);
                $box.find('.ta-chat-arrow').text(wasHidden ? '▴' : '▾');
                if (wasHidden) {
                    updateBadge($box, 0);
                    taChatLoad($box, false);
                    if (!$box.data('poll')) {
                        var t = setInterval(function(){
                            if ($box.find('.ta-chat-panel').is(':visible')) taChatLoad($box, true);
                        }, 8000);
                        $box.data('poll', t);
                    }
                }
            });

            $(document).on('click', '.ta-chat-attach', function(){
                $(this).closest('.ta-chat-inputrow').find('.ta-chat-file').trigger('click');
            });
            $(document).on('change', '.ta-chat-file', function(){
                var $row = $(this).closest('.ta-chat-inputrow');
                var name = this.files[0] ? this.files[0].name : '';
                $row.siblings('.ta-chat-filename').text(name ? '📎 ' + name : '').toggle(!!name);
            });

            $(document).on('click', '.ta-chat-send', function(){
                var $box = $(this).closest('.ta-chat-box');
                var $text = $box.find('.ta-chat-text');
                var $file = $box.find('.ta-chat-file');
                var text = $text.val().trim();
                var file = $file[0].files[0];
                if (!text && !file) return;

                var fd = new FormData();
                fd.append('action', 'ta_chat_send');
                fd.append('nonce', $box.data('nonce'));
                fd.append('order_id', $box.data('order'));
                fd.append('thread', $box.data('thread'));
                fd.append('message', text);
                if (file) fd.append('file', file);

                var $btn = $(this).prop('disabled', true);
                $.ajax({url:$box.data('ajax'), type:'POST', data:fd, processData:false, contentType:false})
                .done(function(res){
                    $btn.prop('disabled', false);
                    if (res.success) {
                        $text.val('');
                        $file.val('');
                        $box.find('.ta-chat-filename').hide().text('');
                        taChatLoad($box, true);
                    } else {
                        alert(res.data && res.data.message ? res.data.message : 'خطا در ارسال پیام');
                    }
                })
                .fail(function(){ $btn.prop('disabled', false); alert('خطا در ارتباط با سرور'); });
            });

            $(document).on('keydown', '.ta-chat-text', function(e){
                if (e.key === 'Enter') { e.preventDefault(); $(this).closest('.ta-chat-box').find('.ta-chat-send').trigger('click'); }
            });
        })(jQuery);
        </script>
        <?php
    }
}
