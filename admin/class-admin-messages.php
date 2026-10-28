<?php
defined( 'ABSPATH' ) || exit;

/**
 * صفحه پیام‌های ادمین — درخواست‌های تخصیص استادیار
 *
 * v2.4:
 * - حذف دکمه تخصیص و Modal مرتبط
 * - بازسازی کامل ظاهر جدول
 */
class TA_Admin_Messages {

    public function export_xlsx(): void {
        global $wpdb;

        $show_reviewed = isset( $_GET['show_reviewed'] ) && $_GET['show_reviewed'] === '1';

        $msg_table    = $wpdb->prefix . 'ta_admin_messages';
        $assign_table = $wpdb->prefix . 'ta_assignments';
        $ta_table     = $wpdb->prefix . 'ta_assistants';
        $users_table  = $wpdb->users;

        $status_clause = $show_reviewed
            ? "a.status IN ('pending_admin', 'pending_admin_reviewed')"
            : "a.status = 'pending_admin'";

        $rows = $wpdb->get_results( "
            SELECT
                a.id, a.student_id, a.course_type, a.messenger, a.student_gender, a.status, a.created_at,
                m.message                   AS msg_text,
                u_student.display_name      AS student_name,
                u_student.user_email        AS student_email,
                ta_user.display_name        AS ta_name,
                p.post_title                AS product_name
            FROM {$msg_table} m
            LEFT JOIN {$assign_table} a  ON a.id = m.assignment_id
            LEFT JOIN {$users_table}  u_student ON u_student.ID = a.student_id
            LEFT JOIN {$ta_table}     ta_row    ON ta_row.id    = a.ta_id
            LEFT JOIN {$users_table}  ta_user   ON ta_user.ID   = ta_row.user_id
            LEFT JOIN {$wpdb->posts}  p         ON p.ID         = a.product_id
            WHERE {$status_clause}
            ORDER BY a.created_at DESC
        " );

        $phone_map = [];
        if ( ! empty( $rows ) ) {
            $ids    = implode( ',', array_unique( array_map( fn($r) => (int)$r->student_id, $rows ) ) );
            $phones = $wpdb->get_results(
                "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta}
                 WHERE user_id IN ({$ids}) AND meta_key IN ('kerasno-phone','phone','Mreeir_phone','billing_phone')"
            );
            $prio = [ 'kerasno-phone'=>1, 'phone'=>2, 'Mreeir_phone'=>3, 'billing_phone'=>4 ];
            foreach ( $phones as $pm ) {
                $uid = (int)$pm->user_id; $p = $prio[$pm->meta_key] ?? 99;
                if ( $pm->meta_value && ( !isset($phone_map[$uid]) || $p < $phone_map[$uid]['p'] ) )
                    $phone_map[$uid] = ['v'=>$pm->meta_value,'p'=>$p];
            }
            $phone_map = array_map( fn($x) => $x['v'], $phone_map );
        }

        $status_labels  = [ 'pending_admin'=>'در انتظار بررسی', 'pending_admin_reviewed'=>'بررسی‌شده' ];
        $course_labels  = [ 'creative'=>'نویسندگی خلاق','intro'=>'مقدماتی','basic_writing'=>'پایه','advanced'=>'پیشرفته','critique'=>'نقد اثر','ladder'=>'نردبان','professional'=>'حرفه‌ای' ];
        $gender_labels  = [ 'male'=>'آقا','female'=>'خانم','both'=>'هر دو','unknown'=>'—' ];
        $msg_labels     = [ 'telegram'=>'تلگرام','whatsapp'=>'واتساپ','eitaa'=>'ایتا','bale'=>'بله','both'=>'همه' ];

        while ( ob_get_level() ) ob_end_clean();

        $writer = new TA_Xlsx_Writer();
        $writer->set_rtl( true );
        $writer->add_row( [
            'ردیف', 'نام هنرجو', 'شماره همراه', 'ایمیل',
            'محصول', 'نوع دوره', 'جنسیت', 'پیامرسان',
            'وضعیت تخصیص', 'پیام استادیار', 'تاریخ ثبت',
        ], true );

        foreach ( $rows as $i => $r ) {
            $writer->add_row( [
                $i + 1,
                $r->student_name  ?? '',
                $phone_map[ (int)$r->student_id ] ?? '',
                $r->student_email ?? '',
                $r->product_name  ?? '',
                $course_labels[ $r->course_type ]  ?? $r->course_type,
                $gender_labels[ $r->student_gender ] ?? $r->student_gender,
                $msg_labels[ $r->messenger ]       ?? $r->messenger,
                $status_labels[ $r->status ]       ?? $r->status,
                $r->msg_text      ?? '',
                $r->created_at    ?? '',
            ], false, true );
        }
        $writer->output( 'admin-messages-' . date('Y-m-d') . '.xlsx' );
        exit;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'دسترسی غیرمجاز.' );
        }

        global $wpdb;

        $show_reviewed = isset( $_GET['show_reviewed'] ) && $_GET['show_reviewed'] === '1';

        $msg_table    = $wpdb->prefix . 'ta_admin_messages';
        $assign_table = $wpdb->prefix . 'ta_assignments';
        $ta_table     = $wpdb->prefix . 'ta_assistants';
        $users_table  = $wpdb->users;

        $status_clause = $show_reviewed
            ? "a.status IN ('pending_admin', 'pending_admin_reviewed')"
            : "a.status = 'pending_admin'";

        $rows = $wpdb->get_results( "
            SELECT
                a.id,
                a.student_id,
                a.ta_id,
                a.product_id,
                a.course_type,
                a.messenger,
                a.student_gender,
                a.status,
                a.created_at,
                m.message                   AS msg_text,
                u_student.display_name      AS student_name,
                u_student.user_email        AS student_email,
                ta_user.display_name        AS ta_name,
                p.post_title                AS product_name
            FROM {$msg_table} m
            LEFT JOIN {$assign_table} a  ON a.id = m.assignment_id
            LEFT JOIN {$users_table}  u_student ON u_student.ID = a.student_id
            LEFT JOIN {$ta_table}     ta_row    ON ta_row.id    = a.ta_id
            LEFT JOIN {$users_table}  ta_user   ON ta_user.ID   = ta_row.user_id
            LEFT JOIN {$wpdb->posts}  p         ON p.ID         = a.product_id
            WHERE {$status_clause}
            ORDER BY a.created_at DESC
        " );

        $total_pending  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$assign_table} WHERE status = 'pending_admin'" );
        $total_reviewed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$assign_table} WHERE status = 'pending_admin_reviewed'" );

        $student_phones = [];
        if ( ! empty( $rows ) ) {
            $student_ids = array_unique( array_column( $rows, 'student_id' ) );
            $ids_in      = implode( ',', array_map( 'intval', $student_ids ) );
            $phone_meta  = $wpdb->get_results(
                "SELECT user_id, meta_value FROM {$wpdb->usermeta}
                 WHERE user_id IN ({$ids_in})
                   AND meta_key IN ('kerasno-phone','phone','Mreeir_phone','billing_phone')
                 ORDER BY FIELD(meta_key,'kerasno-phone','phone','Mreeir_phone','billing_phone')"
            );
            foreach ( $phone_meta as $pm ) {
                if ( ! isset( $student_phones[ $pm->user_id ] ) && $pm->meta_value ) {
                    $student_phones[ $pm->user_id ] = $pm->meta_value;
                }
            }
        }
        ?>
        <div class="wrap ta-messages-wrap" dir="rtl">

            <!-- ── سربرگ ─────────────────────────────────────────────────────── -->
            <div class="ta-page-header">
                <div class="ta-page-header__title">
                    <span class="ta-page-header__icon">📬</span>
                    <div>
                        <h1>پیام‌های ادمین</h1>
                        <p>درخواست‌های تخصیص استادیار</p>
                    </div>
                </div>
                <div class="ta-page-header__badges">
                    <?php if ( $total_pending > 0 ) : ?>
                        <span class="ta-stat-chip ta-stat-chip--red">
                            <span class="ta-stat-chip__num"><?= $total_pending ?></span>
                            در انتظار
                        </span>
                    <?php endif; ?>
                    <?php if ( $total_reviewed > 0 ) : ?>
                        <span class="ta-stat-chip ta-stat-chip--gray">
                            <span class="ta-stat-chip__num"><?= $total_reviewed ?></span>
                            پردازش‌شده
                        </span>
                    <?php endif; ?>
                </div>
                <div class="ta-page-header__actions" style="display:flex;gap:8px;align-items:center">
                    <a href="<?= esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'export_xlsx', 'show_reviewed' => $show_reviewed ? '1' : '0' ] ), 'ta_messages_export' ) ) ?>"
                       class="ta-stat-chip" style="background:#217346;color:#fff;text-decoration:none;padding:6px 14px;border-radius:8px;font-weight:600;font-size:13px">
                        ⬇ خروجی اکسل
                    </a>
                </div>
            </div>

            <!-- ── توضیح ─────────────────────────────────────────────────────── -->
            <div class="ta-info-banner">
                <span class="ta-info-banner__icon">ℹ️</span>
                این درخواست‌ها توسط هنرجویانی ثبت شده‌اند که روی دکمه
                <strong>«🔄 ادامه بدون استادیار قبلی — تخصیص توسط ادمین»</strong>
                کلیک کرده‌اند یا استادیار قبلی‌شان ظرفیت نداشته است.
                برای تخصیص استادیار به صفحه
                <a href="<?= esc_url( admin_url( 'admin.php?page=ta-assignments' ) ) ?>">مدیریت تخصیص‌ها</a>
                مراجعه کنید.
            </div>

            <!-- ── تب‌های فیلتر ──────────────────────────────────────────────── -->
            <div class="ta-tabs">
                <a href="<?= esc_url( admin_url( 'admin.php?page=ta-admin-messages' ) ) ?>"
                   class="ta-tab <?= !$show_reviewed ? 'ta-tab--active' : '' ?>">
                    ⏳ در انتظار
                    <span class="ta-tab__count"><?= $total_pending ?></span>
                </a>
                <a href="<?= esc_url( admin_url( 'admin.php?page=ta-admin-messages&show_reviewed=1' ) ) ?>"
                   class="ta-tab <?= $show_reviewed ? 'ta-tab--active' : '' ?>">
                    📋 همه درخواست‌ها
                    <span class="ta-tab__count"><?= $total_pending + $total_reviewed ?></span>
                </a>
            </div>

            <div id="ta-msg-notice" style="display:none;margin-bottom:12px"></div>

            <?php if ( empty( $rows ) ) : ?>

            <!-- ── حالت خالی ─────────────────────────────────────────────────── -->
            <div class="ta-empty-state">
                <div class="ta-empty-state__icon">✅</div>
                <h3><?= $show_reviewed ? 'هیچ درخواستی در این بازه وجود ندارد.' : 'هیچ درخواست در انتظاری وجود ندارد.' ?></h3>
                <?php if ( !$show_reviewed && $total_reviewed > 0 ) : ?>
                    <p>
                        <a href="<?= esc_url( admin_url( 'admin.php?page=ta-admin-messages&show_reviewed=1' ) ) ?>">
                            مشاهده <?= $total_reviewed ?> درخواست پردازش‌شده
                        </a>
                    </p>
                <?php else : ?>
                    <p>تمام هنرجویان استادیار دارند.</p>
                <?php endif; ?>
            </div>

            <?php else : ?>

            <!-- ── جدول ─────────────────────────────────────────────────────── -->
            <?php
            $course_labels = [
                'creative'      => 'خلاق',
                'basic_writing' => 'نویسندگی پایه',
                'intro'         => 'مقدماتی',
                'advanced'      => 'پیشرفته',
                'professional'  => 'حرفه‌ای',
                'critique'      => 'نقد اثر',
                'ladder'        => 'نردبان حرفه‌ای',
            ];
            $messenger_icons = [
                'telegram' => '✈️',
                'bale'     => '🔵',
                'eitaa'    => '🟢',
            ];
            $messenger_labels = [
                'telegram' => 'تلگرام',
                'bale'     => 'بله',
                'eitaa'    => 'ایتا',
            ];
            $gender_labels = [ 'male' => '🙍‍♂️ آقا', 'female' => '🙍‍♀️ خانم', 'unknown' => '—' ];
            ?>

            <div class="ta-table-wrap">
            <table class="ta-msg-table" id="ta-messages-table">
                <thead>
                    <tr>
                        <th class="col-num">#</th>
                        <th class="col-student">هنرجو</th>
                        <th class="col-phone">تلفن</th>
                        <th class="col-product">محصول</th>
                        <th class="col-course">نوع دوره</th>
                        <th class="col-msg">نوع درخواست</th>
                        <th class="col-messenger">پیامرسان</th>
                        <th class="col-gender">جنسیت</th>
                        <th class="col-date">تاریخ ثبت</th>
                        <th class="col-actions">اقدام</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $rows as $i => $row ) :
                    $is_reviewed = $row->status === 'pending_admin_reviewed';
                    $msg_text    = $row->msg_text ?? '';
                    $phone       = $student_phones[ $row->student_id ] ?? '—';

                    $is_decline   = str_contains( $msg_text, 'بدون استادیار قبلی' );
                    $is_no_cap    = str_contains( $msg_text, 'ظرفیت' );
                    $is_not_found = str_contains( $msg_text, 'یافت نشد' );

                    if ( $is_reviewed ) {
                        $reason_badge = '<span class="ta-reason ta-reason--reviewed">👁 مشاهده‌شده</span>';
                    } elseif ( $is_decline ) {
                        $reason_badge = '<span class="ta-reason ta-reason--decline">🔄 بدون استادیار قبلی</span>';
                    } elseif ( $is_no_cap ) {
                        $reason_badge = '<span class="ta-reason ta-reason--nocap">⚡ ظرفیت تمام</span>';
                    } elseif ( $is_not_found ) {
                        $reason_badge = '<span class="ta-reason ta-reason--notfound">🔍 استادیار یافت نشد</span>';
                    } else {
                        $reason_badge = '<span class="ta-reason ta-reason--pending">⏳ در انتظار</span>';
                    }

                    $course_label = $course_labels[ $row->course_type ?? '' ] ?? ( $row->course_type ?? '—' );
                    $messenger_key = $row->messenger ?? '';
                    $date_short = substr( $row->created_at ?? '', 0, 16 );
                ?>
                <tr id="msg-row-<?= $row->id ?>" class="ta-msg-row <?= $is_reviewed ? 'ta-msg-row--reviewed' : '' ?>">

                    <td class="col-num">
                        <span class="ta-row-num"><?= $i + 1 ?></span>
                    </td>

                    <td class="col-student">
                        <div class="ta-student-cell">
                            <span class="ta-student-cell__name"><?= esc_html( $row->student_name ?? '—' ) ?></span>
                            <span class="ta-student-cell__email"><?= esc_html( $row->student_email ?? '—' ) ?></span>
                        </div>
                    </td>

                    <td class="col-phone">
                        <span class="ta-phone"><?= esc_html( $phone ) ?></span>
                    </td>

                    <td class="col-product">
                        <span class="ta-product-name"><?= esc_html( $row->product_name ?? '—' ) ?></span>
                    </td>

                    <td class="col-course">
                        <span class="ta-course-chip"><?= esc_html( $course_label ) ?></span>
                    </td>

                    <td class="col-msg">
                        <?= $reason_badge ?>
                    </td>

                    <td class="col-messenger">
                        <?php if ( $messenger_key ) : ?>
                        <span class="ta-messenger-cell">
                            <span><?= $messenger_icons[ $messenger_key ] ?? '📱' ?></span>
                            <span><?= esc_html( $messenger_labels[ $messenger_key ] ?? $messenger_key ) ?></span>
                        </span>
                        <?php else : ?>
                            <span style="color:#9ca3af">—</span>
                        <?php endif; ?>
                    </td>

                    <td class="col-gender">
                        <?= $gender_labels[ $row->student_gender ?? 'unknown' ] ?? '—' ?>
                    </td>

                    <td class="col-date">
                        <span class="ta-date"><?= esc_html( $date_short ) ?></span>
                    </td>

                    <td class="col-actions">
                        <?php if ( ! $is_reviewed ) : ?>
                            <button type="button"
                                    class="ta-btn ta-btn--read ta-msg-mark-read"
                                    data-id="<?= $row->id ?>"
                                    data-nonce="<?= wp_create_nonce( 'ta_msg_mark_read_' . $row->id ) ?>">
                                👁 خوانده شد
                            </button>
                        <?php else : ?>
                            <button type="button"
                                    class="ta-btn ta-btn--delete ta-msg-delete"
                                    data-id="<?= $row->id ?>"
                                    data-nonce="<?= wp_create_nonce( 'ta_msg_delete_' . $row->id ) ?>">
                                🗑 حذف
                            </button>
                        <?php endif; ?>
                    </td>

                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <p class="ta-table-footer-note">
                دکمه <strong>👁 خوانده شد</strong> درخواست را پردازش‌شده علامت می‌زند و از لیست «در انتظار» خارج می‌کند.
                برای تخصیص استادیار به
                <a href="<?= esc_url( admin_url( 'admin.php?page=ta-assignments' ) ) ?>">مدیریت تخصیص‌ها</a>
                بروید.
            </p>

            <?php endif; ?>

        </div><!-- /.ta-messages-wrap -->

        <style>
        /* ═══════════════════════════════════════════════════════════════════
           ta-messages — v2.4 بازسازی کامل ظاهر
        ═══════════════════════════════════════════════════════════════════ */
        .ta-messages-wrap { font-family: Tahoma, Arial, sans-serif; max-width: 1400px; }

        /* ── سربرگ ──────────────────────────────────────────────────────── */
        .ta-page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
        }
        .ta-page-header__title {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .ta-page-header__icon {
            font-size: 2.2rem;
            line-height: 1;
        }
        .ta-page-header__title h1 {
            margin: 0 0 2px;
            font-size: 1.4rem;
            color: #1e293b;
        }
        .ta-page-header__title p {
            margin: 0;
            font-size: 0.82rem;
            color: #64748b;
        }
        .ta-page-header__badges { display: flex; gap: 8px; align-items: center; }

        .ta-stat-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 99px;
            font-size: 12px;
            font-weight: 700;
        }
        .ta-stat-chip__num { font-size: 1rem; }
        .ta-stat-chip--red  { background: #fef2f2; color: #b91c1c; border: 1.5px solid #fca5a5; }
        .ta-stat-chip--gray { background: #f1f5f9; color: #475569; border: 1.5px solid #cbd5e1; }

        /* ── بنر اطلاعات ────────────────────────────────────────────────── */
        .ta-info-banner {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: #f0f9ff;
            border: 1.5px solid #bae6fd;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 12.5px;
            color: #0c4a6e;
            line-height: 1.7;
            margin-bottom: 18px;
        }
        .ta-info-banner__icon { font-size: 1rem; flex-shrink: 0; margin-top: 2px; }
        .ta-info-banner a { color: #0073aa; font-weight: 700; }

        /* ── تب‌ها ───────────────────────────────────────────────────────── */
        .ta-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 0;
        }
        .ta-tab {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 18px;
            border-radius: 8px 8px 0 0;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-bottom: none;
            margin-bottom: -2px;
            transition: all .15s;
        }
        .ta-tab:hover { background: #f1f5f9; color: #334155; }
        .ta-tab--active { background: #fff; color: #0073aa; border-color: #e2e8f0; border-bottom-color: #fff; }
        .ta-tab__count {
            background: #e2e8f0;
            color: #475569;
            border-radius: 99px;
            padding: 1px 8px;
            font-size: 11px;
            font-weight: 700;
        }
        .ta-tab--active .ta-tab__count { background: #dbeafe; color: #1d4ed8; }

        /* ── حالت خالی ──────────────────────────────────────────────────── */
        .ta-empty-state {
            text-align: center;
            background: #f0fdf4;
            border: 1.5px solid #86efac;
            border-radius: 14px;
            padding: 48px 32px;
            color: #166534;
        }
        .ta-empty-state__icon { font-size: 3rem; margin-bottom: 12px; }
        .ta-empty-state h3 { margin: 0 0 8px; font-size: 1.1rem; }
        .ta-empty-state p  { margin: 0; font-size: 13px; color: #16a34a; }
        .ta-empty-state a  { color: #0073aa; font-weight: 700; }

        /* ── جدول ───────────────────────────────────────────────────────── */
        .ta-table-wrap {
            overflow-x: auto;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
        }
        .ta-msg-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            font-size: 13px;
            min-width: 900px;
        }

        /* هدر */
        .ta-msg-table thead tr {
            background: linear-gradient(135deg, #1e3a5f 0%, #0f4c81 100%);
        }
        .ta-msg-table th {
            color: #e2e8f0;
            padding: 13px 14px;
            text-align: right;
            font-weight: 700;
            font-size: 11.5px;
            letter-spacing: .4px;
            white-space: nowrap;
            border-bottom: none;
        }
        .ta-msg-table th.col-num    { width: 36px; text-align: center; }
        .ta-msg-table th.col-phone  { width: 110px; }
        .ta-msg-table th.col-course { width: 110px; }
        .ta-msg-table th.col-msg    { width: 150px; }
        .ta-msg-table th.col-messenger { width: 90px; }
        .ta-msg-table th.col-gender { width: 80px; }
        .ta-msg-table th.col-date   { width: 120px; }
        .ta-msg-table th.col-actions{ width: 110px; }
        .ta-msg-table th:first-child { border-radius: 14px 0 0 0; }
        .ta-msg-table th:last-child  { border-radius: 0 14px 0 0; }

        /* سطرها */
        .ta-msg-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            vertical-align: middle;
        }
        .ta-msg-row:hover td { background: #f8faff; }
        .ta-msg-row:last-child td { border-bottom: none; }
        .ta-msg-row--reviewed td { opacity: .55; background: #f9fafb; }
        .ta-msg-row--reviewed:hover td { opacity: .75; background: #f3f4f6; }

        /* ردیف‌های زوج */
        .ta-msg-row:nth-child(even) td { background: #fafbfc; }
        .ta-msg-row:nth-child(even):hover td { background: #f0f7ff; }

        /* ── سلول‌های محتوا ─────────────────────────────────────────────── */
        .ta-row-num {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 26px; height: 26px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #475569;
            font-size: 11px;
            font-weight: 700;
            margin: auto;
        }

        .ta-student-cell { display: flex; flex-direction: column; gap: 2px; }
        .ta-student-cell__name  { font-weight: 700; color: #1e293b; font-size: 13px; }
        .ta-student-cell__email { font-size: 11px; color: #64748b; direction: ltr; }

        .ta-phone { font-size: 12px; color: #374151; direction: ltr; display: block; }

        .ta-product-name { font-weight: 600; color: #1e3a5f; font-size: 12.5px; }

        .ta-course-chip {
            display: inline-block;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            padding: 3px 9px;
            font-size: 11.5px;
            font-weight: 700;
            white-space: nowrap;
        }

        /* ── بج نوع درخواست ─────────────────────────────────────────────── */
        .ta-reason {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }
        .ta-reason--pending  { background: #fef9c3; color: #92400e; border: 1px solid #fde68a; }
        .ta-reason--decline  { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .ta-reason--nocap    { background: #fdf4ff; color: #7e22ce; border: 1px solid #e9d5ff; }
        .ta-reason--notfound { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .ta-reason--reviewed { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

        .ta-messenger-cell { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: #374151; }

        .ta-date { font-size: 11.5px; color: #64748b; white-space: nowrap; }

        /* ── دکمه‌های اقدام ─────────────────────────────────────────────── */
        .ta-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 13px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border: 1.5px solid transparent;
            transition: all .15s;
            white-space: nowrap;
            font-family: inherit;
        }
        .ta-btn--read {
            background: #f0f9ff;
            color: #0369a1;
            border-color: #bae6fd;
        }
        .ta-btn--read:hover {
            background: #e0f2fe;
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(3,105,161,.15);
        }
        .ta-btn--delete {
            background: #fff1f2;
            color: #be123c;
            border-color: #fecdd3;
        }
        .ta-btn--delete:hover {
            background: #ffe4e6;
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(190,18,60,.15);
        }
        .ta-btn:disabled { opacity: .55; cursor: not-allowed; transform: none !important; }

        /* ── پاورقی جدول ────────────────────────────────────────────────── */
        .ta-table-footer-note {
            margin-top: 10px;
            font-size: 12px;
            color: #94a3b8;
            padding: 0 4px;
        }
        .ta-table-footer-note a { color: #0073aa; font-weight: 700; }
        </style>

        <script>
        (function($){
            var ajaxUrl = '<?= admin_url('admin-ajax.php') ?>';

            function showNotice(msg, type) {
                var cfg = {
                    success: ['#f0fdf4','#86efac','#166534'],
                    error:   ['#fef2f2','#fca5a5','#991b1b'],
                    info:    ['#eff6ff','#93c5fd','#1e40af']
                };
                var c = cfg[type] || cfg.info;
                $('#ta-msg-notice')
                    .html('<div style="padding:10px 16px;border-radius:8px;border:1.5px solid '+c[1]+';background:'+c[0]+';color:'+c[2]+';font-weight:600">'+msg+'</div>')
                    .show();
                setTimeout(function(){ $('#ta-msg-notice').fadeOut(400); }, 4000);
            }

            function updateBadge(newCount) {
                var $b = $('#ta-msg-badge');
                if (newCount > 0) { $b.text(newCount + ' جدید').show(); } else { $b.hide(); }
            }

            // ── Mark Read ─────────────────────────────────────────────────
            $(document).on('click', '.ta-msg-mark-read', function(){
                var $btn  = $(this);
                var id    = $btn.data('id');
                var nonce = $btn.data('nonce');
                $btn.prop('disabled', true).text('...');

                $.post(ajaxUrl, {
                    action: 'ta_admin_msg_mark_read',
                    assignment_id: id,
                    nonce: nonce
                }, function(res){
                    if (res.success) {
                        var $row = $('#msg-row-' + id);
                        $row.addClass('ta-msg-row--reviewed').removeClass('');
                        $row.find('.ta-reason').replaceWith('<span class="ta-reason ta-reason--reviewed">👁 مشاهده‌شده</span>');
                        $btn.replaceWith(
                            '<button type="button" class="ta-btn ta-btn--delete ta-msg-delete" '
                            + 'data-id="'+id+'" data-nonce="'+res.data.delete_nonce+'">🗑 حذف</button>'
                        );
                        updateBadge(res.data.pending_count);
                        showNotice('✅ درخواست مشاهده‌شده علامت خورد.', 'success');
                    } else {
                        $btn.prop('disabled', false).text('👁 خوانده شد');
                        showNotice('❌ ' + (res.data || 'عملیات ناموفق'), 'error');
                    }
                }).fail(function(){
                    $btn.prop('disabled', false).text('👁 خوانده شد');
                    showNotice('❌ خطای ارتباطی با سرور', 'error');
                });
            });

            // ── Delete ────────────────────────────────────────────────────
            $(document).on('click', '.ta-msg-delete', function(){
                if (!confirm('این ردیف حذف شود؟')) return;
                var $btn  = $(this);
                var id    = $btn.data('id');
                var nonce = $btn.data('nonce');
                $btn.prop('disabled', true).text('...');

                $.post(ajaxUrl, {
                    action: 'ta_admin_msg_delete',
                    assignment_id: id,
                    nonce: nonce
                }, function(res){
                    if (res.success) {
                        $('#msg-row-' + id).fadeOut(300, function(){ $(this).remove(); });
                        showNotice('🗑 ردیف حذف شد.', 'success');
                    } else {
                        $btn.prop('disabled', false).text('🗑 حذف');
                        showNotice('❌ ' + (res.data || 'عملیات ناموفق'), 'error');
                    }
                }).fail(function(){
                    $btn.prop('disabled', false).text('🗑 حذف');
                    showNotice('❌ خطای ارتباطی با سرور', 'error');
                });
            });

        })(jQuery);
        </script>
        <?php
    }
}
