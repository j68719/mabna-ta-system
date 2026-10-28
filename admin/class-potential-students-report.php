<?php
defined( 'ABSPATH' ) || exit;

/**
 * گزارش هنرجویان بالقوه بر اساس پیش‌نیازها، نمرات و تخصیص‌های ترم مقصد.
 */
class TA_Potential_Students_Report {
    private array $course_labels = [
        'creative' => 'نویسندگی خلاق', 'intro' => 'نویسندگی مقدماتی',
        'basic_writing' => 'نویسندگی پایه', 'advanced' => 'نویسندگی پیشرفته',
        'ladder' => 'نردبان حرفه‌ای', 'professional' => 'نویسندگی حرفه‌ای',
        'critique' => 'نقد اثر',
    ];

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );
        if ( ( $_GET['action'] ?? '' ) === 'export_xlsx' ) {
            $this->export_xlsx();
        }
        $data = $this->build_data();
        $export_url = wp_nonce_url( add_query_arg( [
            'page' => 'ta-potential-students-report', 'action' => 'export_xlsx',
            'term_id' => $data['term_id'], 'destination_product_id' => $data['destination_product_id'], 'prev_terms' => $data['prev_terms'],
        ], admin_url('admin.php') ), 'psr_export' );
        $this->styles();
        echo '<div class="wrap psr-wrap" dir="rtl">';
        echo '<div class="psr-hero"><div><span>ENROLLMENT OPPORTUNITY REPORT</span><h1>گزارش هنرجویان بالقوه</h1><p>مقایسه هنرجویان واجد شرایط با هنرجویانی که در ترم مقصد ثبت‌نام کرده‌اند.</p></div><a class="psr-export" href="'.esc_url($export_url).'">⬇ خروجی اکسل فیلتر نهایی</a></div>';
        echo '<form class="psr-filters" method="get"><input type="hidden" name="page" value="ta-potential-students-report"><div><label>ترم مقصد</label><select name="term_id" required><option value="">انتخاب ترم</option>';
        foreach ( $data['terms'] as $term ) echo '<option value="'.(int)$term->id.'" '.selected($data['term_id'],(int)$term->id,false).'>'.esc_html($term->name).'</option>';
        echo '</select></div><div><label>مقصد</label><select name="destination_product_id"><option value="">همه مقصدها</option>';
        foreach ( $data['destinations'] as $destination ) echo '<option value="'.(int)$destination->product_id.'" '.selected($data['destination_product_id'],(int)$destination->product_id,false).'>'.esc_html($destination->post_title).' — '.esc_html($this->course_labels[$destination->course_type]??$destination->course_type).'</option>';
        echo '</select></div><div><label>نمره‌های چند ترم قبل‌تر</label><select name="prev_terms"><option value="0" '.selected($data['prev_terms'],0,false).'>همه ترم‌ها</option>'.implode('',array_map(static fn($n)=>'<option value="'.$n.'" '.selected($data['prev_terms'],$n,false).'>'.$n.' ترم قبل‌تر</option>',range(1,8))).'</select></div><button class="button button-primary">نمایش گزارش</button><a class="button" href="'.esc_url(admin_url('admin.php?page=ta-potential-students-report')).'">پاک‌کردن</a></form>';
        if ( ! $data['term_id'] ) { echo '<div class="psr-empty">برای شروع، یک ترم مقصد را انتخاب کنید.</div></div>'; return; }
        if ( $data['prev_terms'] ) {
            echo '<div class="notice notice-info inline"><p>نمره‌های این ترم‌ها در نظر گرفته شد: <strong>'.( $data['prev_term_names'] ? esc_html( implode( '، ', $data['prev_term_names'] ) ) : 'هیچ ترمی قبل از ترم مقصد پیدا نشد' ).'</strong><br><small>ترتیب زمانی ترم‌ها در این گزارش: '.esc_html( implode( ' ← ', $data['chrono_names'] ) ).'</small></p></div>';
        }
        echo '<div class="psr-kpis">';
        $kpis = [
            [ 'potential', number_format_i18n($data['stats']['potential']), 'کل هنرجویان بالقوه' ],
            [ 'registered', number_format_i18n($data['stats']['registered']), 'ثبت‌نام‌کرده از بین بالقوه‌ها' ],
            [ 'missing', number_format_i18n($data['stats']['missing']), 'فرصت باقی‌مانده ثبت‌نام' ],
            [ 'conversion', esc_html($data['stats']['conversion_rate'].'٪'), 'نرخ تبدیل بالقوه به ثبت‌نام' ],
            [ 'products', number_format_i18n($data['stats']['products']), 'محصولات مقصد دارای پیش‌نیاز' ],
            [ 'top-course', number_format_i18n($data['stats']['top_course_count']), 'بیشترین فرصت: '.($data['stats']['top_course'] ?: '—') ],
        ];
        foreach ( $kpis as [$class,$number,$label] ) echo '<div class="psr-kpi psr-'.$class.'"><b>'.$number.'</b><span>'.esc_html($label).'</span></div>';
        echo '</div>';
        if ( ! empty( $data['destination_stats'] ) ) {
            echo '<h3 class="psr-section-title">آمار تفکیکی مقصدها</h3><div class="psr-destination-kpis">';
            foreach ( $data['destination_stats'] as $destination_stat ) {
                echo '<div class="psr-destination-card"><strong>'.esc_html($destination_stat['product']).'</strong><small>'.esc_html($destination_stat['course']).'</small><div class="psr-destination-numbers"><span><b>'.number_format_i18n($destination_stat['potential']).'</b>بالقوه</span><span><b>'.number_format_i18n($destination_stat['registered']).'</b>ثبت‌نام‌کرده</span><span><b>'.number_format_i18n($destination_stat['missing']).'</b>فرصت باقی‌مانده</span></div><div class="psr-destination-rate">نرخ تبدیل: '.esc_html($destination_stat['rate']).'٪</div></div>';
            }
            echo '</div>';
        }
        echo '<div class="psr-legend"><span class="psr-pill psr-pill-registered">● ثبت‌نام کرده</span><span class="psr-pill psr-pill-missing">● بالقوه، ثبت‌نام نکرده</span><span class="psr-pill psr-pill-pending">● ثبت‌نام در انتظار بررسی</span></div>';
        echo '<div class="psr-table-wrap"><table class="psr-table"><thead><tr><th>ردیف</th><th>محصول مقصد</th><th>ترم</th><th>هنرجو</th><th>منبع صلاحیت</th><th>همه تخصیص‌ها</th><th>وضعیت ثبت‌نام</th></tr></thead><tbody>';
        foreach ( $data['rows'] as $index => $row ) {
            $status_class = $row['registered'] ? ( $row['pending'] ? 'pending' : 'registered' ) : 'missing';
            $status_text  = $row['registered'] ? ( $row['pending'] ? 'ثبت‌نام در انتظار بررسی' : 'ثبت‌نام کرده' ) : 'واجد شرایط؛ ثبت‌نام نکرده';
            echo '<tr class="psr-row-'.$status_class.'"><td>'.($index+1).'</td><td><strong>'.esc_html($row['product']).'</strong><small>'.esc_html($row['course']).'</small></td><td>'.esc_html($row['term']).'</td><td><strong>'.esc_html($row['student']).'</strong><small>'.esc_html($row['phone']).'</small></td><td>'.$row['evidence'].'</td><td class="psr-all-assignments">'.($row['all_assignments'] ?: '<span class="psr-muted">تخصیصی ثبت نشده</span>').'</td><td><span class="psr-status psr-status-'.$status_class.'">'.esc_html($status_text).'</span>'.( $row['assignment_status'] ? '<small class="psr-assignment-status">وضعیت تخصیص: '.esc_html($row['assignment_status']).'</small>' : '' ).'</td></tr>';
        }
        if ( ! $data['rows'] ) echo '<tr><td colspan="7" class="psr-empty">برای این ترم، محصول دارای پیش‌نیاز و هنرجوی بالقوه‌ای پیدا نشد.</td></tr>';
        echo '</tbody></table></div><p class="psr-note">منظور از «ثبت‌نام کرده» وجود تخصیص همان هنرجو برای همان محصول در ترم مقصد است؛ تخصیص‌های لغوشده ثبت‌نام محسوب نمی‌شوند. اگر فیلتر «چند ترم قبل‌تر» انتخاب شده باشد، فقط نمره‌های همان ترم‌های اخیر (بدون خود ترم مقصد) در نظر گرفته می‌شود. برای دوره «نقد اثر»، هنرجویی که در ترم مقصد در یک دوره مقدماتی یا پایه ثبت‌نام فعال دارد بالقوه محسوب نمی‌شود، و هر هنرجویی که در هر ترمی در نویسندگی مقدماتی یا پایه نمره «نقد اثر» گرفته باشد بالقوه محسوب می‌شود.</p></div>';
    }

    private function export_xlsx(): void {
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'psr_export' ) ) wp_die( 'درخواست نامعتبر.' );
        $data = $this->build_data();
        while ( ob_get_level() ) ob_end_clean();
        $writer = new TA_Xlsx_Writer(); $writer->set_rtl( true );
        $writer->add_row( [ 'ردیف','محصول مقصد','نوع دوره','ترم مقصد','هنرجو','شماره تماس','منبع صلاحیت','وضعیت ثبت‌نام','همه تخصیص‌ها','وضعیت تخصیص' ], true );
        foreach ( $data['rows'] as $i => $row ) {
            $status = $row['registered'] ? ( $row['pending'] ? 'ثبت‌نام در انتظار بررسی' : 'ثبت‌نام کرده' ) : 'واجد شرایط؛ ثبت‌نام نکرده';
            $writer->add_row( [ $i+1, $row['product'], $row['course'], $row['term'], $row['student'], $row['phone'], wp_strip_all_tags(str_replace('<br>', "\n", $row['evidence'])), $status, wp_strip_all_tags(str_replace('<br>', "\n", $row['all_assignments'])), $row['assignment_status'] ], false, true );
        }
        $writer->output( 'potential-students-' . date('Y-m-d') . '.xlsx' ); exit;
    }

    private function build_data(): array {
        global $wpdb;
        $terms_table = TA_Database::table('terms');
        $settings_table = TA_Database::table('product_settings');
        $prereq_table = TA_Database::table('product_prerequisites');
        $grades_table = TA_Database::table('grades');
        $assign_table = TA_Database::table('assignments');
        $terms = $wpdb->get_results("SELECT id,name,order_index FROM {$terms_table} ORDER BY order_index ASC,id ASC") ?: [];
        $term_id = absint($_GET['term_id'] ?? 0);
        $destination_product_id = absint($_GET['destination_product_id'] ?? 0);
        $prev_terms = min( 8, absint($_GET['prev_terms'] ?? 0) ); // 0 = همه ترم‌ها
        $out = [ 'terms'=>$terms, 'term_id'=>$term_id, 'destination_product_id'=>$destination_product_id, 'prev_terms'=>$prev_terms, 'prev_term_names'=>[], 'chrono_names'=>[], 'destinations'=>[], 'rows'=>[], 'stats'=>['potential'=>0,'registered'=>0,'missing'=>0,'products'=>0,'conversion_rate'=>0,'top_course'=>'','top_course_count'=>0],'destination_stats'=>[] ];
        if ( ! $term_id ) return $out;
        $out['destinations'] = $wpdb->get_results($wpdb->prepare(
            "SELECT ps.product_id, ps.course_type, p.post_title FROM {$settings_table} ps
             JOIN {$wpdb->posts} p ON p.ID=ps.product_id
             WHERE ps.term_id=%d AND p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft')
             ORDER BY p.post_title ASC", $term_id
        )) ?: [];
        // همه مقصدهای ترم را ابتدا بررسی می‌کنیم تا اولویت دوره‌ها سراسری باشد.
        // فیلتر مقصد بعد از حذف مقصدهای پایین‌تر اعمال می‌شود.
        $target_products = $wpdb->get_results($wpdb->prepare(
            "SELECT ps.product_id, ps.course_type, p.post_title, t.name AS term_name
             FROM {$settings_table} ps JOIN {$wpdb->posts} p ON p.ID=ps.product_id
             LEFT JOIN {$terms_table} t ON t.id=ps.term_id
             WHERE ps.term_id=%d AND p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft')
             ORDER BY p.post_title ASC", $term_id
        )) ?: [];
        $term_name = '';
        foreach($terms as $t) if((int)$t->id===$term_id) $term_name=$t->name;
        // فیلتر «چند ترم قبل‌تر»: فقط نمره‌های N ترمِ بلافاصله قبل از ترم مقصد (خود ترم مقصد شامل نیست).
        $allowed_term_ids = null;
        if ( $prev_terms > 0 ) {
            // ترتیب زمانی از روی نام ترم (سال + فصل، مثل «پاییز ۱۴۰۵») ساخته می‌شود؛
            // ترم‌های هم‌فصل (مثل «پاییز ۱۴۰۵ (2)») یک دوره حساب می‌شوند.
            // اگر نام همه ترم‌ها خوانده نشود، ترتیب ایجاد ترم (شناسه) ملاک است.
            // order_index عمداً استفاده نمی‌شود چون دستی است و ممکن است معکوس/تکراری باشد.
            $keys = []; $all_parsed = true;
            foreach ( $terms as $x ) {
                $k = $this->term_chrono_key( (string)$x->name );
                if ( $k === null ) { $all_parsed = false; break; }
                $keys[(int)$x->id] = $k;
            }
            $chrono = $terms;
            if ( $all_parsed && $keys ) {
                usort( $chrono, static fn($a,$b)=>( $keys[(int)$a->id][0] <=> $keys[(int)$b->id][0] ) ?: ( $keys[(int)$a->id][1] <=> $keys[(int)$b->id][1] ) ?: ( (int)$a->id <=> (int)$b->id ) );
                $allowed_term_ids = [];
                if ( isset( $keys[$term_id] ) ) {
                    $target_period = $keys[$term_id][0] * 10 + $keys[$term_id][1];
                    $periods = [];
                    foreach ( $keys as $kid => $k ) { $p = $k[0] * 10 + $k[1]; if ( $p < $target_period ) $periods[$p] = true; }
                    $periods = array_keys( $periods ); rsort( $periods );
                    $take = array_slice( $periods, 0, $prev_terms );
                    foreach ( $chrono as $x ) { $k = $keys[(int)$x->id]; if ( in_array( $k[0] * 10 + $k[1], $take, true ) ) $allowed_term_ids[] = (int)$x->id; }
                }
            } else {
                usort( $chrono, static fn($a,$b)=>(int)$a->id <=> (int)$b->id );
                $ordered_ids = array_map( static fn($x)=>(int)$x->id, $chrono );
                $pos = array_search( $term_id, $ordered_ids, true );
                $allowed_term_ids = ( $pos === false ) ? [] : array_slice( $ordered_ids, max( 0, $pos - $prev_terms ), min( $prev_terms, $pos ) );
            }
            $names_by_id = []; foreach ( $chrono as $x ) $names_by_id[(int)$x->id] = (string)$x->name;
            $out['chrono_names'] = array_map( static fn($x)=>(string)$x->name, $chrono );
            $out['prev_term_names'] = array_map( static fn($id)=>$names_by_id[$id] ?? (string)$id, $allowed_term_ids );
        }
        foreach ( $target_products as $target ) {
            $prereqs = $wpdb->get_results($wpdb->prepare(
                "SELECT pp.prerequisite_product_id, pp.pass_eligible, p.post_title AS prereq_title, ps.course_type AS prereq_type
                 FROM {$prereq_table} pp
                 JOIN {$wpdb->posts} p ON p.ID=pp.prerequisite_product_id
                 LEFT JOIN {$settings_table} ps ON ps.product_id=pp.prerequisite_product_id
                 WHERE pp.product_id=%d", (int)$target->product_id
            )) ?: [];
            $is_critique_target = ( (string)$target->course_type === 'critique' );
            // برای مقصد «نقد اثر» شرط صلاحیت ثابت است (نمره «نقد اثر» در مقدماتی/پایه، همه ترم‌ها)
            // و به تنظیم پیش‌نیاز روی محصول وابسته نیست.
            $out['stats']['products']++;
            $prereq_ids = array_values(array_unique(array_map(static fn($r)=>(int)$r->prerequisite_product_id,$prereqs)));
            // حتی اگر برای مقصد پیش‌نیاز ثبت نشده باشد، نمره تکرار همان
            // نوع دوره باید مستقل از پیش‌نیازها بررسی شود.
            if ( ! $prereq_ids ) $prereq_ids = [ 0 ];
            $placeholders = implode(',',array_fill(0,count($prereq_ids),'%d'));
            $grades = $wpdb->get_results($wpdb->prepare(
                "SELECT g.student_id,g.product_id,g.grade,g.course_type,g.term_id,g.notes,u.display_name,u.user_email,pt.post_title AS source_title,t.name AS source_term
                 FROM {$grades_table} g JOIN {$wpdb->users} u ON u.ID=g.student_id
                 LEFT JOIN {$wpdb->posts} pt ON pt.ID=g.product_id LEFT JOIN {$terms_table} t ON t.id=g.term_id
                WHERE g.product_id IN ({$placeholders}) ORDER BY g.student_id,g.id DESC", ...$prereq_ids
            )) ?: [];
            // برای تکرار همان دوره، نمره‌های تکرارِ محصولات قبلیِ همان نوع
            // نیز منبع صلاحیت هستند؛ حتی اگر آن محصول در جدول پیش‌نیازها ثبت نشده باشد.
            $repeat_grade_names = [ 'repeat', 'repeat_' . (string) $target->course_type ];
            $repeat_placeholders = implode( ',', array_fill( 0, count( $repeat_grade_names ), '%s' ) );
            $same_course_repeat_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT g.student_id,g.product_id,g.grade,COALESCE(NULLIF(g.course_type,''),same_ps.course_type) AS course_type,g.term_id,g.notes,u.display_name,u.user_email,
                        pt.post_title AS source_title,t.name AS source_term
                 FROM {$grades_table} g
                 JOIN {$settings_table} same_ps ON same_ps.product_id=g.product_id AND same_ps.course_type=%s
                 JOIN {$wpdb->users} u ON u.ID=g.student_id
                 LEFT JOIN {$wpdb->posts} pt ON pt.ID=g.product_id
                 LEFT JOIN {$terms_table} t ON t.id=g.term_id
                 WHERE g.grade IN ({$repeat_placeholders})
                 ORDER BY g.student_id,g.id DESC",
                (string) $target->course_type, ...$repeat_grade_names
            ) ) ?: [];
            $existing_grade_keys = [];
            foreach ( $grades as $existing_grade ) $existing_grade_keys[(int)$existing_grade->student_id . ':' . (int)$existing_grade->product_id . ':' . (string)$existing_grade->grade] = true;
            foreach ( $same_course_repeat_rows as $repeat_row ) {
                $repeat_key = (int)$repeat_row->student_id . ':' . (int)$repeat_row->product_id . ':' . (string)$repeat_row->grade;
                if ( ! isset( $existing_grade_keys[$repeat_key] ) ) $grades[] = $repeat_row;
            }
            if ( $is_critique_target ) {
                $grades = $this->critique_source_grades();
                // برای نقد اثر نیز تکرار خودِ نقد اثر منبع صلاحیت مستقل است.
                $critique_repeat_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT g.student_id,g.product_id,g.grade,COALESCE(NULLIF(g.course_type,''),same_ps.course_type) AS course_type,
                            g.term_id,g.notes,u.display_name,u.user_email,pt.post_title AS source_title,t.name AS source_term
                     FROM {$grades_table} g
                     JOIN {$settings_table} same_ps ON same_ps.product_id=g.product_id AND same_ps.course_type='critique'
                     JOIN {$wpdb->users} u ON u.ID=g.student_id
                     LEFT JOIN {$wpdb->posts} pt ON pt.ID=g.product_id LEFT JOIN {$terms_table} t ON t.id=g.term_id
                     WHERE g.grade IN (%s,%s)
                     ORDER BY g.student_id,g.id DESC",
                    'repeat', 'repeat_critique'
                ) ) ?: [];
                $grades = array_merge( $grades, $critique_repeat_rows );
            }
            if ( $allowed_term_ids !== null && $grades ) {
                // ترم منبع = ترم ثبت‌شده روی نمره یا ترم تنظیمات محصولِ آن نمره؛ هرکدام در بازه باشد قبول است.
                $pids = array_values( array_unique( array_map( static fn($g)=>(int)$g->product_id, $grades ) ) );
                $pph  = implode( ',', array_fill( 0, count( $pids ), '%d' ) );
                $product_term = [];
                foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT product_id,term_id FROM {$settings_table} WHERE product_id IN ({$pph})", ...$pids ) ) ?: [] as $pt_row ) $product_term[(int)$pt_row->product_id] = (int)$pt_row->term_id;
                $grades = array_values( array_filter( $grades, static fn($g)=>in_array( (int)$g->term_id, $allowed_term_ids, true ) || in_array( $product_term[(int)$g->product_id] ?? 0, $allowed_term_ids, true ) ) );
            }
            $by_student=[];
            foreach($grades as $grade) $by_student[(int)$grade->student_id][]=$grade;
            if(!$by_student) continue;
            $student_ids=array_keys($by_student);
            $ids_sql=implode(',',array_map('absint',$student_ids));
            $assignments=$wpdb->get_results($wpdb->prepare(
                "SELECT a.student_id,a.status,a.id FROM {$assign_table} a WHERE a.product_id=%d AND a.term_id=%d AND a.student_id IN ({$ids_sql}) AND a.status <> 'canceled'", (int)$target->product_id,$term_id
            )) ?: [];
            $registered=[]; foreach($assignments as $a) $registered[(int)$a->student_id]=$a;
            // شرکت قبلی در همان نوع دوره، به‌تنهایی مانع بالقوه‌بودن نیست؛
            // چون هنرجو می‌تواند همان دوره را در ترم دیگری تکرار کند.
            // فقط سابقه یک نوع دوره متفاوت در ترمی جدیدتر از منبع صلاحیت، او را حذف می‌کند.
            $period_by_term = [];
            foreach ( $terms as $chrono_term ) {
                $chrono_key = $this->term_chrono_key( (string) $chrono_term->name );
                if ( $chrono_key !== null ) $period_by_term[(int) $chrono_term->id] = $chrono_key[0] * 10 + $chrono_key[1];
            }
            $history_rows = $wpdb->get_results(
                "SELECT g.student_id, ps.course_type, g.term_id, COALESCE(NULLIF(g.term_id,0), ps.term_id) AS effective_term
                 FROM {$grades_table} g JOIN {$settings_table} ps ON ps.product_id=g.product_id
                 WHERE g.student_id IN ({$ids_sql})
                 UNION ALL
                 SELECT a.student_id, aps.course_type, a.term_id, a.term_id AS effective_term
                 FROM {$assign_table} a JOIN {$settings_table} aps ON aps.product_id=a.product_id
                 WHERE a.status<>'canceled' AND a.student_id IN ({$ids_sql})"
            ) ?: [];
            $history_by_student = [];
            foreach ( $history_rows as $history ) {
                $history_by_student[(int)$history->student_id][] = $history;
            }
            // نقد اثر: هنرجویی که همین حالا (در ترم مقصد) در یک دوره مقدماتی یا پایه ثبت‌نام/تخصیص غیرلغوشده دارد،
            // در حال گذراندن مسیر قبل از نقد اثر است و دیگر بالقوه محسوب نمی‌شود.
            $current_source_enrolled=[];
            if($is_critique_target){
                $current_rows=$wpdb->get_results($wpdb->prepare(
                    "SELECT DISTINCT a.student_id FROM {$assign_table} a
                     JOIN {$settings_table} cur_ps ON cur_ps.product_id=a.product_id
                     WHERE cur_ps.course_type IN ('intro','basic_writing') AND a.term_id=%d AND a.status<>'canceled' AND a.student_id IN ({$ids_sql})",
                    $term_id
                )) ?: [];
                foreach($current_rows as $cr) $current_source_enrolled[(int)$cr->student_id]=true;
            }
            foreach($by_student as $student_id=>$student_grades){
                $source_period = 0;
                foreach ( $student_grades as $source_grade ) {
                    $source_term = (int) ( $source_grade->term_id ?: 0 );
                    $source_period = max( $source_period, (int) ( $period_by_term[$source_term] ?? 0 ) );
                }
                $has_newer_different_course = false;
                foreach ( $history_by_student[(int)$student_id] ?? [] as $history ) {
                    $history_period = (int) ( $period_by_term[(int)$history->effective_term] ?? 0 );
                    if ( (string)$history->course_type !== (string)$target->course_type && $history_period > $source_period ) {
                        $has_newer_different_course = true;
                        break;
                    }
                }
                if ( $has_newer_different_course ) continue;
                if($is_critique_target && isset($current_source_enrolled[(int)$student_id]) && !isset($registered[(int)$student_id])) continue;
                $evidence=$this->qualifying_evidence($target->course_type,$student_grades,$prereqs);
                if(!$evidence) continue;
                $user=$student_grades[0]; $assignment=$registered[$student_id]??null;
                $is_registered=(bool)$assignment; $is_pending=$assignment && in_array($assignment->status,['pending','pending_admin','pending_admin_reviewed'],true);
                $out['rows'][]=[
                    'student_id'=>(int)$student_id,'course_type'=>(string)$target->course_type,'product_id'=>(int)$target->product_id,'product'=>$target->post_title,'course'=>$this->course_labels[$target->course_type]??$target->course_type,'term'=>$term_name,
                    'student'=>$user->display_name,'phone'=>(string)get_user_meta($student_id,'billing_phone',true),
                    'evidence'=>implode('<br>',array_map(static fn($x)=>'<span class="psr-evidence">'.esc_html($x).'</span>',$evidence)),
                    'registered'=>$is_registered,'pending'=>$is_pending,'assignment_status'=>$assignment?( $assignment->status==='active'?'فعال':($assignment->status==='completed'?'تکمیل‌شده':$assignment->status) ):''
                ];
            }
        }

        // گزارش در سطح هنرجو است، نه در سطح محصول: یک هنرجو نباید برای چند محصول
        // مقصد چند بار در فهرست بالقوه‌ها دیده شود. اگر در یکی از محصولات مقصد
        // ثبت‌نام کرده باشد، همان ثبت‌نام بر بالقوه‌های قبلی مقدم است.
        $grouped=[];
        foreach($out['rows'] as $row){
            $key=(string)$row['student_id'];
            if(!isset($grouped[$key])) $grouped[$key]=[];
            $grouped[$key][]=$row;
        }
        $rows=[];
        $course_rank=['creative'=>1,'intro'=>2,'basic_writing'=>3,'critique'=>4,'advanced'=>5,'ladder'=>6,'professional'=>7];
        foreach($grouped as $items){
            // فقط بالاترین مقصد مجاز برای هر هنرجو نمایش داده می‌شود؛
            // بنابراین واجد شرایط شدن برای پیشرفته، ردیف مقدماتی/پایه را پنهان می‌کند.
            $max_rank=0;
            foreach($items as $item) $max_rank=max($max_rank,(int)($course_rank[$item['course_type']]??0));
            $items=array_values(array_filter($items,static fn($item)=>(int)($course_rank[$item['course_type']]??0)===$max_rank));
            $has_registered=false;
            foreach($items as $item) if($item['registered']) { $has_registered=true; break; }
            if($has_registered) $items=array_values(array_filter($items,static fn($item)=>(bool)$item['registered']));
            // فیلتر مقصد: پس از اعمال اولویت، ردیف فقط به همان مقصد محدود می‌شود تا نام محصول،
            // مستندات و آمار تفکیکی مقصدهای دیگرِ همان هنرجو در خروجی نیامد.
            if ( $destination_product_id ) {
                $items=array_values(array_filter($items,static fn($item)=>(int)$item['product_id']===$destination_product_id));
                if ( ! $items ) continue;
            }
            $first=$items[0];
            $products=[]; $product_ids=[]; $courses=[]; $evidence=[]; $statuses=[]; $pending=false; $registered=false;
            foreach($items as $item){
                $products[]=$item['product']; $product_ids[]=(int)$item['product_id']; $courses[]=$item['course'];
                if($item['evidence']) $evidence[]=$item['evidence'];
                if($item['assignment_status']) $statuses[]=$item['assignment_status'];
                $registered=$registered||$item['registered']; $pending=$pending||$item['pending'];
            }
            $first['product_ids']=array_values(array_unique($product_ids));
            $first['product']=implode('، ',array_values(array_unique($products)));
            $first['course']=implode('، ',array_values(array_unique($courses)));
            $first['evidence']=implode('<br>',array_values(array_unique($evidence)));
            $first['assignment_status']=implode('، ',array_values(array_unique($statuses)));
            $first['registered']=$registered; $first['pending']=$pending;
            // فیلتر مقصد بالاتر اعمال شده؛ مقصد پایین‌تر نمی‌تواند اولویت را دور بزند.
            $rows[]=$first;
        }
        // همه تخصیص‌های هر هنرجوی گزارش، مستقل از فیلتر مقصد، در یک ستون نمایش داده می‌شود.
        $report_student_ids=array_values(array_unique(array_map(static fn($r)=>(int)$r['student_id'],$rows)));
        $all_by_student=[];
        if($report_student_ids){
            $all_ids_sql=implode(',',array_map('absint',$report_student_ids));
            $all_assignments=$wpdb->get_results("SELECT a.student_id,a.status,a.product_id,a.course_type,a.term_id,p.post_title,t.name AS term_name
                FROM {$assign_table} a LEFT JOIN {$wpdb->posts} p ON p.ID=a.product_id LEFT JOIN {$terms_table} t ON t.id=a.term_id
                WHERE a.student_id IN ({$all_ids_sql}) ORDER BY a.student_id,t.order_index ASC,a.id ASC") ?: [];
            $assignment_grade_rows=$wpdb->get_results("SELECT student_id,product_id,grade FROM {$grades_table} WHERE student_id IN ({$all_ids_sql})") ?: [];
            $assignment_grades=[]; foreach($assignment_grade_rows as $ag) $assignment_grades[(int)$ag->student_id.'|'.(int)$ag->product_id]=(string)$ag->grade;
            foreach($all_assignments as $assignment){
                $status_label=$assignment->status==='active'?'فعال':($assignment->status==='completed'?'تکمیل‌شده':($assignment->status==='canceled'?'لغوشده':(string)$assignment->status));
                $grade_key=(int)$assignment->student_id.'|'.(int)$assignment->product_id;
                $grade_text=isset($assignment_grades[$grade_key]) ? $this->grade_label($assignment_grades[$grade_key]) : 'ثبت نشده';
                $all_by_student[(int)$assignment->student_id][]='<span class="psr-assignment-item"><strong>'.esc_html($assignment->post_title?:'محصول '.$assignment->product_id).'</strong><small>'.esc_html($assignment->term_name?:'ترم '.$assignment->term_id).' · نمره: '.esc_html($grade_text).' · '.esc_html($status_label).'</small></span>';
            }
        }
        foreach($rows as &$report_row) $report_row['all_assignments']=implode('<br>', $all_by_student[(int)$report_row['student_id']]??[]);
        unset($report_row);
        // جدول و خروجی اکسل باید از ردیف‌های نهایی (پس از اولویت دوره‌ها و فیلتر مقصد) ساخته شوند،
        // نه از ردیف‌های خام هر «هنرجو × محصول».
        $out['rows']=$rows;
        // آمار تفکیکی هر مقصد از همان ردیف‌های نهایی و پس از اولویت دوره‌ها محاسبه می‌شود.
        $destination_names=[]; foreach($out['destinations'] as $destination) $destination_names[(int)$destination->product_id]=['product'=>(string)$destination->post_title,'course'=>$this->course_labels[$destination->course_type]??$destination->course_type];
        $destination_stats=[];
        foreach($rows as $row){
            foreach(($row['product_ids']??[]) as $product_id){
                if(!isset($destination_names[$product_id])) continue;
                if(!isset($destination_stats[$product_id])) $destination_stats[$product_id]=['product'=>$destination_names[$product_id]['product'],'course'=>$destination_names[$product_id]['course'],'potential'=>0,'registered'=>0,'missing'=>0,'rate'=>0];
                $destination_stats[$product_id]['potential']++;
                if($row['registered']) $destination_stats[$product_id]['registered']++; else $destination_stats[$product_id]['missing']++;
            }
        }
        foreach($destination_stats as &$destination_stat) $destination_stat['rate']=$destination_stat['potential']>0 ? round(($destination_stat['registered']/$destination_stat['potential'])*100,1) : 0;
        unset($destination_stat);
        $out['destination_stats']=array_values($destination_stats);
        $out['stats']['potential']=count($rows);
        $course_counts=[];
        foreach($rows as $row){
            if($row['registered']) $out['stats']['registered']++; else $out['stats']['missing']++;
            $course_key=$row['course']; $course_counts[$course_key]=($course_counts[$course_key]??0)+1;
        }
        $out['stats']['conversion_rate']=$out['stats']['potential']>0 ? round(($out['stats']['registered']/$out['stats']['potential'])*100,1) : 0;
        arsort($course_counts); $out['stats']['top_course']=$course_counts ? (string)array_key_first($course_counts) : ''; $out['stats']['top_course_count']=$course_counts ? (int)reset($course_counts) : 0;
        usort($out['rows'],static fn($a,$b)=>($a['registered']<=>$b['registered'])?:strcmp($a['student'],$b['student']));
        return $out;
    }

    /**
     * همه نمره‌های «نقد اثر» ثبت‌شده در دوره‌های مقدماتی و پایه، در تمام ترم‌ها.
     */
    /**
     * کلید زمانی ترم از روی نام: [سال، فصل] یا null اگر قابل‌خواندن نباشد.
     */
    private function term_chrono_key( string $name ): ?array {
        $name = strtr( $name, [ '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9' ] );
        if ( ! preg_match( '/(?<!\d)((?:13|14)\d{2})(?!\d)/', $name, $m ) ) return null;
        $season = 0;
        foreach ( [ 'بهار'=>1, 'تابستان'=>2, 'پاییز'=>3, 'پائیز'=>3, 'زمستان'=>4 ] as $word => $rank ) {
            if ( mb_strpos( $name, $word ) !== false ) { $season = $rank; break; }
        }
        return $season ? [ (int)$m[1], $season ] : null;
    }

    private function critique_source_grades(): array {
        global $wpdb;
        $grades_table   = TA_Database::table('grades');
        $terms_table    = TA_Database::table('terms');
        $settings_table = TA_Database::table('product_settings');
        $rows = $wpdb->get_results(
            "SELECT g.student_id,g.product_id,g.grade,
                    COALESCE(NULLIF(g.course_type,''),(SELECT ps2.course_type FROM {$settings_table} ps2 WHERE ps2.product_id=g.product_id AND ps2.course_type<>'' LIMIT 1)) AS course_type,
                    g.term_id,g.notes,u.display_name,u.user_email,pt.post_title AS source_title,t.name AS source_term
             FROM {$grades_table} g JOIN {$wpdb->users} u ON u.ID=g.student_id
             LEFT JOIN {$wpdb->posts} pt ON pt.ID=g.product_id LEFT JOIN {$terms_table} t ON t.id=g.term_id
             WHERE g.grade='critique'
             ORDER BY g.student_id,t.order_index ASC,g.id DESC"
        ) ?: [];
        return array_values( array_filter( $rows, static fn( $r ) => in_array( (string) $r->course_type, [ 'intro', 'basic_writing' ], true ) ) );
    }

    private function qualifying_evidence(string $target_type,array $grades,array $prereqs): array {
        $by_id=[]; foreach($prereqs as $p)$by_id[(int)$p->prerequisite_product_id]=$p;
        $evidence=[]; $has_critique_pass=false; $has_intro_critique=false; $has_advanced_direct=false;
        foreach($grades as $g){
            $source=$by_id[(int)$g->product_id]??null;
            $source_type=$source?(string)$source->prereq_type:(string)$g->course_type;
            $label=$source?($source->prereq_title.' — '.$this->grade_label($g->grade)):($g->source_title.' — '.$this->grade_label($g->grade));
            // قاعده عمومی تکرار: تکرار همان نوع دوره، برای همان نوع دوره
            // منبع صلاحیت است؛ این قاعده به پیشرفته محدود نیست.
            $repeat_grades = [ 'repeat', 'repeat_' . $target_type ];
            if ( $source_type === $target_type && in_array( (string)$g->grade, $repeat_grades, true ) ) {
                $evidence[]=$label;
                if ( $target_type === 'advanced' ) $has_advanced_direct=true;
                continue;
            }
            if($target_type==='critique'){
                if($g->grade==='critique' && in_array((string)$g->course_type,['intro','basic_writing'],true)){
                    $evidence[]=($g->source_title?:'محصول '.(int)$g->product_id).($g->source_term?' ('.$g->source_term.')':'').' — نمره: نقد اثر';
                }
                continue;
            }
            if($target_type==='intro'){
                if($g->grade==='intro_permission' && $source_type==='basic_writing') $evidence[]=$label;
                elseif($g->grade==='pass') $evidence[]=$label;
                elseif($g->grade==='repeat' && $source_type==='intro') $evidence[]=$label;
                continue;
            }
            if($target_type==='advanced'){
                if($source_type==='critique' && $g->grade==='pass'){ $has_critique_pass=true; $evidence[]=$label; continue; }
                if($source_type==='intro' && $g->grade==='critique'){ $has_intro_critique=true; $evidence[]=$label; continue; }
                $pass_ok=$g->grade==='pass' && (!in_array($source_type,['intro','basic_writing'],true) || (int)($source->pass_eligible??1)===1);
                if($pass_ok) { $evidence[]=$label; $has_advanced_direct=true; }
                elseif(in_array($g->grade,['repeat','repeat_advanced'],true) && $source_type==='advanced') { $evidence[]=$label; $has_advanced_direct=true; }
                continue;
            }
            if($g->grade==='pass') $evidence[]=$label;
            elseif($target_type==='professional' && in_array($g->grade,['repeat','repeat_professional'],true) && $source_type==='professional') $evidence[]=$label;
            elseif($target_type==='ladder' && (($g->grade==='ladder') || (in_array($g->grade,['repeat','repeat_ladder'],true) && $source_type==='ladder'))) $evidence[]=$label;
        }
        if($target_type==='advanced' && $has_critique_pass && $has_intro_critique) return array_values(array_unique($evidence));
        // نقد اثر از مقدماتی به‌تنهایی برای پیشرفته کافی نیست؛ فقط همراه با قبولی نقد اثر معتبر است.
        if($target_type==='advanced' && !$has_advanced_direct) return [];
        return array_values(array_unique($evidence));
    }
    private function grade_label(string $grade): string { return TA_Grade::all_grades()[$grade]['label']??$grade; }
    private function styles(): void { echo '<style>.psr-wrap{max-width:1550px;color:#172033}.psr-hero{background:linear-gradient(120deg,#172554,#0f766e);color:#fff;border-radius:20px;padding:28px 32px;margin:20px 0}.psr-hero span{font-size:11px;letter-spacing:2px;opacity:.75}.psr-hero h1{margin:8px 0;font-size:28px}.psr-hero{display:flex;justify-content:space-between;align-items:center}.psr-export{background:#fff;color:#126b74;border-radius:10px;padding:11px 15px;text-decoration:none;font-weight:700;white-space:nowrap}.psr-hero p{margin:0;opacity:.82}.psr-filters{display:flex;gap:12px;align-items:end;flex-wrap:wrap;background:#fff;padding:18px;border:1px solid #e4eaf1;border-radius:16px}.psr-filters label{display:block;font-size:12px;font-weight:700;margin-bottom:6px}.psr-filters select{min-width:260px;height:38px;border:1px solid #dbe3ec;border-radius:8px;padding:5px 9px}.psr-kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin:18px 0}.psr-kpi{background:#fff;border:1px solid #e4eaf1;border-radius:14px;padding:17px;border-right:5px solid #64748b}.psr-kpi b,.psr-kpi span{display:block}.psr-kpi b{font-size:26px}.psr-kpi span{color:#718096;font-size:12px;margin-top:5px}.psr-potential{border-right-color:#2563eb}.psr-registered{border-right-color:#059669}.psr-missing{border-right-color:#d97706}.psr-products{border-right-color:#7c3aed}.psr-conversion{border-right-color:#0891b2}.psr-top-course{border-right-color:#db2777}.psr-section-title{font-size:16px;margin:22px 0 10px;color:#172033}.psr-destination-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:10px 0 18px}.psr-destination-card{background:#fff;border:1px solid #e4eaf1;border-top:4px solid #2563eb;border-radius:14px;padding:15px}.psr-destination-card strong,.psr-destination-card small{display:block}.psr-destination-card strong{font-size:13px}.psr-destination-card small{color:#64748b;font-size:11px;margin-top:4px}.psr-destination-numbers{display:flex;gap:12px;margin-top:14px}.psr-destination-numbers span{font-size:10px;color:#64748b}.psr-destination-numbers b{display:block;color:#172033;font-size:20px}.psr-destination-rate{margin-top:12px;color:#0f766e;font-size:11px;font-weight:700}.psr-legend{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0}.psr-pill{border-radius:20px;padding:6px 11px;font-size:11px;font-weight:700}.psr-pill-registered{background:#dcfce7;color:#166534}.psr-pill-missing{background:#fef3c7;color:#92400e}.psr-pill-pending{background:#dbeafe;color:#1e40af}.psr-table-wrap{overflow:auto;background:#fff;border:1px solid #e4eaf1;border-radius:16px}.psr-table{border-collapse:collapse;width:100%;min-width:1000px}.psr-table th{background:#f4f8fb;padding:14px;text-align:right;font-size:12px;white-space:nowrap}.psr-table td{padding:14px;border-top:1px solid #edf1f5;vertical-align:top}.psr-table td small{display:block;color:#718096;font-size:11px;margin-top:4px}.psr-row-registered{background:#f5fff8}.psr-row-pending{background:#f5f9ff}.psr-row-missing{background:#fffaf0}.psr-status{display:inline-block;border-radius:20px;padding:7px 10px;font-size:11px;font-weight:700;white-space:nowrap}.psr-status-registered{background:#dcfce7;color:#166534}.psr-status-pending{background:#dbeafe;color:#1e40af}.psr-status-missing{background:#fef3c7;color:#92400e}.psr-assignment-status{color:#64748b}.psr-all-assignments{min-width:230px}.psr-assignment-item{display:block;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:5px 7px;margin:3px 0;font-size:11px}.psr-assignment-item small{color:#64748b}.psr-muted{color:#94a3b8;font-size:11px}.psr-evidence{display:inline-block;background:#f1f5f9;border-radius:6px;padding:4px 7px;margin:2px;font-size:11px}.psr-empty{background:#fff;padding:42px;text-align:center;color:#718096;border-radius:16px;margin-top:18px}.psr-note{color:#718096;font-size:12px}@media(max-width:1100px){.psr-kpis{grid-template-columns:repeat(3,1fr)}.psr-destination-kpis{grid-template-columns:repeat(2,1fr)}}@media(max-width:800px){.psr-kpis,.psr-destination-kpis{grid-template-columns:repeat(2,1fr)}.psr-hero{padding:22px}.psr-filters select{min-width:200px}}</style>'; }
}
