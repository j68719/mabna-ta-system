<?php
defined( 'ABSPATH' ) || exit;

/** گزارش مسیر آموزشی هنرجویان: یک ردیف برای هر هنرجو و یک ستون مسیر شامل همه دوره‌ها. */
class TA_Student_Path_Report {
    private array $course_types = [
        'creative'=>'نویسندگی خلاق','intro'=>'نویسندگی مقدماتی','basic_writing'=>'نویسندگی پایه',
        'advanced'=>'نویسندگی پیشرفته','ladder'=>'نردبان حرفه‌ای','professional'=>'نویسندگی حرفه‌ای','critique'=>'نقد اثر',
    ];

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );
        wp_enqueue_script( 'selectWoo' );
        wp_enqueue_style( 'select2' );
        $data = $this->build_data();
        $export_args = [ 'page'=>'ta-student-path-report', 'action'=>'export_xlsx', 'search'=>$data['filters']['search'], 'absence_course_type'=>$data['filters']['absence_course_types'], 'present_course_type'=>$data['filters']['present_course_types'], 'grade'=>$data['filters']['grades'] ];
        $export = wp_nonce_url( add_query_arg( array_filter( $export_args, static fn($v) => $v !== '' && $v !== 0 ), admin_url( 'admin.php' ) ), 'ta_student_path_export' );
        $this->styles();
        echo '<div class="wrap spr-wrap" dir="rtl"><div class="spr-hero"><div><span>LEARNING PATH ANALYTICS</span><h1>گزارش مسیر هنرجویان</h1><p>تمام دوره‌های تخصیص‌داده‌شده و نمره‌های هر هنرجو در یک مسیر خوانا.</p></div><a class="spr-export" href="'.esc_url($export).'">⬇ خروجی Excel واقعی</a></div>';
        echo '<form class="spr-filters" method="get"><input type="hidden" name="page" value="ta-student-path-report"><div><label>جست‌وجوی هنرجو</label><input name="search" value="'.esc_attr($data['filters']['search']).'" placeholder="نام یا ایمیل"></div><div><label>فیلتر نبودن دوره <small>(چندانتخابی؛ فقدان همه موارد)</small></label><select class="spr-select2" name="absence_course_type[]" multiple data-placeholder="دوره‌های فاقد رکورد را انتخاب کنید">';
        foreach($this->course_types as $k=>$v) echo '<option value="'.esc_attr($k).'" '.(in_array($k,$data['filters']['absence_course_types'],true)?'selected':'').'>'.esc_html($v).'</option>';
        echo '</select></div><div><label>فیلتر داشتن دوره <small>(چندانتخابی؛ داشتن همه موارد)</small></label><select class="spr-select2" name="present_course_type[]" multiple data-placeholder="دوره‌های دارای رکورد را انتخاب کنید">';
        foreach($this->course_types as $k=>$v) echo '<option value="'.esc_attr($k).'" '.(in_array($k,$data['filters']['present_course_types'],true)?'selected':'').'>'.esc_html($v).'</option>';
        echo '</select></div><div><label>فیلتر نمره <small>(چندانتخابی؛ وجود یکی از نمره‌ها)</small></label><select class="spr-select2" name="grade[]" multiple data-placeholder="نمره‌ها را انتخاب کنید">';
        foreach(TA_Grade::all_grades() as $k=>$g) echo '<option value="'.esc_attr($k).'" '.(in_array($k,$data['filters']['grades'],true)?'selected':'').'>'.esc_html($g['label']).'</option>';
        echo '</select></div><button class="button button-primary">اعمال فیلتر</button><a class="button" href="'.esc_url(admin_url('admin.php?page=ta-student-path-report')).'">پاک‌کردن</a></form>';
        echo '<div class="spr-kpis"><div><b>'.number_format_i18n(count($data['rows'])).'</b><span>هنرجویان گزارش</span></div><div><b>'.number_format_i18n($data['stats']['passed']).'</b><span>دارای قبولی</span></div><div><b>'.number_format_i18n($data['stats']['courses']).'</b><span>رکوردهای دوره</span></div></div>';
        echo '<div class="spr-table-wrap"><table id="spr-table"><thead><tr><th>ردیف</th><th>هنرجو</th><th>شماره</th><th class="spr-path-heading">مسیر آموزشی · ترم / دوره / تخصیص / نمره</th></tr></thead><tbody>';
        foreach($data['rows'] as $i=>$r){
            echo '<tr><td>'.($i+1).'</td><td><strong>'.esc_html($r['name']).'</strong><small>#'.(int)$r['id'].'</small></td><td>'.esc_html($r['phone']).'</td><td class="spr-path-cell">'.$r['path_html'].'</td></tr>';
        }
        if(!$data['rows']) echo '<tr><td colspan="4" class="spr-empty">رکوردی با این فیلتر پیدا نشد.</td></tr>';
        echo '</tbody></table></div><p class="spr-note">هر کارت در مسیر یک دوره است. نمره و وضعیت تخصیص در همان کارت نمایش داده می‌شود؛ دوره‌های بدون نمره نیز حفظ شده‌اند.</p></div><script>jQuery(function($){if(!$.fn.selectWoo)return;$(".spr-select2").selectWoo({width:"100%",dir:"rtl",closeOnSelect:false,allowClear:true,placeholder:function(){return $(this).data("placeholder")||"انتخاب کنید"},language:{noResults:function(){return "موردی پیدا نشد"}}});});</script>';
    }

    public function export_xlsx(): void {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'ta_student_path_export' ) ) wp_die( 'دسترسی غیرمجاز.' );
        $data=$this->build_data(); while(ob_get_level())ob_end_clean(); $w=new TA_Xlsx_Writer(); $w->set_rtl(true);
        // یک ردیف برای هر هنرجو و یک گروه ستون برای هر دوره؛ از تکرار نام هنرجو جلوگیری می‌شود.
        $max_items = 0;
        foreach($data['rows'] as $r) $max_items = max($max_items, count($r['path_items'] ?? []));
        $headers = ['ردیف','نام هنرجو','شماره تلفن'];
        for($n=1; $n<=$max_items; $n++) {
            $headers[] = 'ترم '.$n;
            $headers[] = 'دوره '.$n;
            $headers[] = 'وضعیت تخصیص '.$n;
            $headers[] = 'نمره '.$n;
        }
        $w->add_row($headers,true);
        foreach($data['rows'] as $i=>$r) {
            $cells = [$i+1,$r['name'],$r['phone']];
            foreach(($r['path_items'] ?? []) as $item) {
                $cells[] = $item['term'];
                $cells[] = $item['course'];
                $cells[] = $item['assignment'];
                $cells[] = $item['grade'];
            }
            while(count($cells) < count($headers)) $cells[] = '';
            $w->add_row($cells,false,true);
        }
        $w->output('student-path-'.date('Y-m-d').'.xlsx'); exit;
    }

    private function build_data(): array {
        global $wpdb;
        $grades=TA_Database::table('grades'); $assign=TA_Database::table('assignments'); $terms=TA_Database::table('terms');
        $absence_raw = isset($_GET['absence_course_type']) ? (array) wp_unslash($_GET['absence_course_type']) : [];
        $present_raw = isset($_GET['present_course_type']) ? (array) wp_unslash($_GET['present_course_type']) : [];
        $grade_raw = isset($_GET['grade']) ? (array) wp_unslash($_GET['grade']) : [];
        $allowed_course_types = array_keys($this->course_types);
        $absence_types = array_values(array_intersect(array_map('sanitize_key',$absence_raw),$allowed_course_types));
        $present_types = array_values(array_intersect(array_map('sanitize_key',$present_raw),$allowed_course_types));
        $allowed_grades = array_keys(TA_Grade::all_grades());
        $grade_filters = array_values(array_intersect(array_map('sanitize_key',$grade_raw),$allowed_grades));
        $f=['search'=>sanitize_text_field(wp_unslash($_GET['search']??'')),'absence_course_types'=>$absence_types,'present_course_types'=>$present_types,'grades'=>$grade_filters];
        $terms_rows=$wpdb->get_results("SELECT id,name,order_index FROM {$terms} ORDER BY order_index ASC,id ASC");
        $events=$wpdb->get_results("SELECT g.student_id,g.term_id,g.course_type,g.grade AS value,'grade' AS kind,g.product_id,t.name AS term_name,t.order_index FROM {$grades} g LEFT JOIN {$terms} t ON t.id=g.term_id UNION ALL SELECT a.student_id,a.term_id,a.course_type,a.status AS value,'assignment' AS kind,a.product_id,t.name AS term_name,t.order_index FROM {$assign} a LEFT JOIN {$terms} t ON t.id=a.term_id");
        $by=[]; $ids=[]; $courses=0;
        foreach((array)$events as $e){$id=(int)$e->student_id;$ids[$id]=1;$course_type=sanitize_key(trim((string)$e->course_type));if($course_type==='creative_writing')$course_type='creative';$value=$e->kind==='grade'?sanitize_key(trim((string)$e->value)):(string)$e->value;$key=$id.'|'.$e->term_id.'|'.$course_type.'|'.$e->product_id;if(!isset($by[$id][$key])){$by[$id][$key]=['term_id'=>(int)$e->term_id,'term_name'=>$e->term_name?:'ترم '.$e->term_id,'order'=>(int)$e->order_index,'course_type'=>$course_type,'product_id'=>(int)$e->product_id,'assignment'=>null,'grade'=>null];$courses++;}$e->kind==='grade'?$by[$id][$key]['grade']=$value:$by[$id][$key]['assignment']=$value;}
        $out=['rows'=>[],'terms'=>$terms_rows,'filters'=>$f,'stats'=>['passed'=>0,'missing'=>0,'courses'=>$courses]]; if(!$ids)return $out;
        $users=$wpdb->get_results("SELECT ID,display_name,user_email FROM {$wpdb->users} WHERE ID IN (".implode(',',array_map('absint',array_keys($ids))).")");
        foreach($users as $u){$id=(int)$u->ID;if($f['search']&&stripos($u->display_name,$f['search'])===false&&stripos($u->user_email,$f['search'])===false)continue;$items=array_values($by[$id]??[]);usort($items,static fn($a,$b)=>($a['order']<=>$b['order'])?:($a['term_id']<=>$b['term_id'])?:strcmp($a['course_type'],$b['course_type']));
            $has_pass=false;$present_courses=[];$present_grades=[];$course_grade_match=false; foreach($items as $it){$present_courses[$it['course_type']]=true;if($it['grade']!==null){$present_grades[$it['grade']]=true;if($it['grade']==='pass')$has_pass=true;if($f['grades']&&in_array($it['grade'],$f['grades'],true)&&(!$f['present_course_types']||in_array($it['course_type'],$f['present_course_types'],true)))$course_grade_match=true;}}
            if($f['absence_course_types']&&count(array_intersect($f['absence_course_types'],array_keys($present_courses)))>0)continue;
            if($f['present_course_types']&&count(array_intersect($f['present_course_types'],array_keys($present_courses)))<count($f['present_course_types']))continue;
            if($f['grades']&&(!$course_grade_match))continue;if($has_pass)$out['stats']['passed']++;$path_html='';$path_text=[];
            $path_items=[];
            foreach($items as $it){$course=$this->course_types[$it['course_type']]??$it['course_type'];$grade=$it['grade']!==null?(TA_Grade::all_grades()[$it['grade']]['label']??$it['grade']):'ثبت نشده';$as=$it['assignment']!==null?($it['assignment']==='completed'?'تکمیل‌شده':($it['assignment']==='canceled'?'لغو شده':'تخصیص دارد')):'تخصیص ندارد';$state=($it['assignment']!==null&&$it['assignment']!=='canceled')?'has-assignment':'no-assignment';$path_html.='<div class="spr-step '.esc_attr($state).'"><i></i><div class="spr-step-main"><strong>'.esc_html($course).'</strong><span>'.esc_html($it['term_name']).'</span></div><div class="spr-step-meta"><em>تخصیص: '.esc_html($as).'</em><em>نمره: '.esc_html($grade).'</em></div></div>';$path_text[]=$it['term_name'].' | '.$course.' | تخصیص: '.$as.' | نمره: '.$grade;$path_items[]=['term'=>$it['term_name'],'course'=>$course,'assignment'=>$as,'grade'=>$grade];}
            $out['rows'][]=['id'=>$id,'name'=>$u->display_name,'phone'=>(string)get_user_meta($id,'billing_phone',true),'path_html'=>$path_html?:'<span class="spr-no-path">هنوز دوره‌ای ثبت نشده است.</span>','path_text'=>implode("\n",$path_text),'path_items'=>$path_items];
        }
        return $out;
    }

    private function styles(): void { echo '<style>.spr-wrap{max-width:1600px;color:#172033}.spr-hero{display:flex;justify-content:space-between;align-items:center;background:linear-gradient(120deg,#10213f,#176b78);color:#fff;border-radius:20px;padding:28px 32px;margin:20px 0}.spr-hero span{font-size:11px;letter-spacing:2px;opacity:.7}.spr-hero h1{margin:8px 0;font-size:28px}.spr-hero p{margin:0;opacity:.8}.spr-export{background:#fff;color:#126b74;border-radius:10px;padding:12px 18px;text-decoration:none;font-weight:700}.spr-filters{display:flex;gap:12px;align-items:end;flex-wrap:wrap;background:#fff;padding:18px;border:1px solid #e4eaf1;border-radius:16px}.spr-filters>div{min-width:180px;max-width:280px;flex:1}.spr-filters label{display:block;font-size:12px;font-weight:700;margin-bottom:6px}.spr-filters label small{font-weight:400;color:#7b8798}.spr-filters input,.spr-filters select{width:100%;height:38px;border:1px solid #dbe3ec;border-radius:8px;padding:5px 9px}.spr-filters .select2-container{width:100%!important}.spr-filters .select2-container--default .select2-selection--multiple{min-height:38px;border:1px solid #dbe3ec;border-radius:8px;padding:3px 6px;background:#fff}.spr-filters .select2-container--default .select2-selection--multiple .select2-selection__choice{background:#e8f5f3;border:1px solid #a8d8d0;color:#145e58;border-radius:5px;padding:3px 7px}.spr-filters .select2-search__field{min-width:120px}.spr-check{display:flex!important;gap:7px;align-items:center;height:38px}.spr-check input{width:auto}.spr-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:18px 0}.spr-kpis div{background:#fff;border:1px solid #e4eaf1;border-radius:14px;padding:16px}.spr-kpis b,.spr-kpis span{display:block}.spr-kpis b{font-size:25px}.spr-kpis span{color:#718096;font-size:12px;margin-top:5px}.spr-table-wrap{overflow:auto;background:#fff;border:1px solid #e4eaf1;border-radius:16px}.spr-table-wrap table{border-collapse:collapse;width:100%;min-width:1050px}.spr-table-wrap th{background:#f4f8fb;padding:14px;text-align:right;font-size:12px;white-space:nowrap}.spr-table-wrap td{padding:14px;border-top:1px solid #edf1f5;vertical-align:top}.spr-table-wrap td small{display:block;color:#8491a3;font-size:10px;margin-top:4px}.spr-path-heading{min-width:650px}.spr-path-cell{background:#fbfcfe}.spr-step{display:grid;grid-template-columns:18px minmax(170px,1fr) minmax(250px,1fr);gap:10px;align-items:center;position:relative;padding:11px 10px;border-bottom:1px dashed #dce5ed}.spr-step:last-child{border-bottom:0}.spr-step i{width:10px;height:10px;border-radius:50%;background:#9aa8b8;display:block;position:relative}.spr-step.has-assignment i{background:#159b82;box-shadow:0 0 0 4px #d9f3ec}.spr-step.no-assignment i{background:#e09a32;box-shadow:0 0 0 4px #fff0d2}.spr-step-main strong,.spr-step-main span,.spr-step-meta em{display:block}.spr-step-main strong{font-size:13px}.spr-step-main span{font-size:11px;color:#738196;margin-top:3px}.spr-step-meta{display:flex;gap:18px;flex-wrap:wrap}.spr-step-meta em{font-style:normal;font-size:11px;color:#546274}.spr-status{border-radius:20px;padding:5px 9px;font-size:11px;white-space:nowrap}.spr-status.ok{background:#e5f7ed;color:#16804e}.spr-status.warn{background:#fff4dc;color:#9a6500}.spr-status.muted{background:#eef1f5;color:#657080}.spr-empty{text-align:center!important;padding:40px!important;color:#8491a3}.spr-no-path{color:#8793a2;font-size:12px}.spr-note{color:#7b8798;font-size:12px}@media(max-width:800px){.spr-kpis{grid-template-columns:repeat(2,1fr)}.spr-hero{display:block}.spr-export{display:inline-block;margin-top:16px}.spr-step{grid-template-columns:18px 1fr}.spr-step-meta{grid-column:2}}</style>'; }
}
