<?php
defined( 'ABSPATH' ) || exit;

class Mabna_Bale_Challenge_DB {
    const VERSION = '1.4.0';

    public static function tables(): array {
        global $wpdb;
        return [
            'events'   => $wpdb->prefix . 'mabna_bale_events',
            'sessions' => $wpdb->prefix . 'mabna_bale_challenge_sessions',
            'results'  => $wpdb->prefix . 'mabna_bale_challenge_results',
            'logs'     => $wpdb->prefix . 'mabna_bale_logs',
            'referrals'=> $wpdb->prefix . 'mabna_bale_referrals',
        ];
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::tables(); $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$t['events']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(190) NOT NULL,
            message text NOT NULL,
            questions longtext NULL,
            result_config longtext NULL,
            start_at datetime NULL,
            end_at datetime NULL,
            is_active tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), KEY active_window (is_active,start_at,end_at)
        ) $charset;" );
        dbDelta( "CREATE TABLE {$t['sessions']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            chat_id varchar(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            phone varchar(32) NULL,
            session_token_hash char(64) NULL,
            source varchar(30) NOT NULL DEFAULT 'bale',
            marketing_consent tinyint(1) NOT NULL DEFAULT 0,
            consent_at datetime NULL,
            first_name varchar(100) NULL,
            last_name varchar(100) NULL,
            current_step varchar(40) NOT NULL DEFAULT 'start',
            answers longtext NULL,
            message_ids longtext NULL,
            status varchar(30) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            completed_at datetime NULL,
            result_key varchar(100) NULL,
            referral_code varchar(40) NULL,
            referred_by varchar(40) NULL,
            PRIMARY KEY (id), UNIQUE KEY event_chat (event_id,chat_id), KEY token_hash (session_token_hash), KEY chat_status (chat_id,status), KEY user_id (user_id)
        ) $charset;" );
        dbDelta( "CREATE TABLE {$t['results']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            session_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            chat_id varchar(64) NOT NULL,
            phone varchar(32) NULL,
            source varchar(30) NOT NULL DEFAULT 'bale',
            result_key varchar(100) NULL,
            answer_summary longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id), KEY event_id (event_id), KEY user_id (user_id), KEY chat_id (chat_id)
        ) $charset;" );
        dbDelta( "CREATE TABLE {$t['referrals']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            referrer_session_id bigint(20) unsigned NOT NULL,
            referrer_code varchar(40) NOT NULL,
            referred_chat_id varchar(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY event_referred (event_id,referred_chat_id), KEY referrer_code (referrer_code), KEY referrer_session (referrer_session_id)
        ) $charset;" );
        dbDelta( "CREATE TABLE {$t['logs']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(80) NOT NULL,
            chat_id varchar(64) NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            payload longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id), KEY event_type (event_type), KEY chat_id (chat_id), KEY created_at (created_at)
        ) $charset;" );
        self::migrate_legacy_events();
        if ( ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['events']}" ) ) self::upsert_event( [ 'title'=>'آینده‌ات شبیه کدوم نویسنده میشه؟', 'message'=>"✍🪐 به ماشین زمان نویسندگی مبنا خوش آمدید!\n\nماشین زمان روشنه و فقط منتظر تأیید شماست تا کارش رو شروع کنه!\n\n🚀 قانون سفر ساده‌ست:\nاگه می‌خوای بدونی آینده‌ات شبیه به کدوم نویسنده می‌شه، فقط کافیه به ۶تا سوال بعدی جواب بدی!\n\n🎟 بلیتت صادر شده… آماده‌ای ببینی در آینده پشت کدوم میز نویسندگی نشستی؟", 'questions'=>mabna_bale_challenge_questions(), 'active'=>false ] );
        $kept_event = self::remove_duplicate_events();
        if ( $kept_event ) {
            global $wpdb; $table = self::tables()['events'];
            $questions = wp_json_encode( mabna_bale_challenge_questions( [] ), JSON_UNESCAPED_UNICODE );
            $config = wp_json_encode( [ 'authors'=>mabna_bale_mbti_authors(), 'score_map'=>mabna_bale_mbti_score_map(), 'image_url'=>'', 'intro_image_url'=>'', 'post_url'=>'', 'sms_mabna_pattern'=>'', 'sms_non_mabna_pattern'=>'' ], JSON_UNESCAPED_UNICODE );
            $wpdb->update( $table, [ 'questions'=>$questions, 'result_config'=>$config, 'updated_at'=>current_time('mysql') ], [ 'id'=>$kept_event ], [ '%s','%s','%s' ], [ '%d' ] );
        }
        // Migration 1.4.0: همگام‌سازی رویدادهای آزمون با منطق شش‌سؤالی جدید.
        // تنظیمات تصویری، پیامکی و لینک‌های سفارشی حفظ می‌شوند.
        $new_questions = wp_json_encode( mabna_bale_challenge_questions( [] ), JSON_UNESCAPED_UNICODE );
        foreach ( $wpdb->get_results( "SELECT id, result_config FROM {$t['events']}" ) as $event_row ) {
            $config = $event_row->result_config ? ( json_decode( $event_row->result_config, true ) ?: [] ) : [];
            $config['authors'] = mabna_bale_mbti_authors();
            $config['score_map'] = mabna_bale_mbti_score_map();
            $wpdb->update( $t['events'], [
                'message' => "✍🪐 به ماشین زمان نویسندگی مبنا خوش آمدید!\n\nماشین زمان روشنه و فقط منتظر تأیید شماست تا کارش رو شروع کنه!\n\n🚀 قانون سفر ساده‌ست:\nاگه می‌خوای بدونی آینده‌ات شبیه به کدوم نویسنده می‌شه، فقط کافیه به ۶تا سوال بعدی جواب بدی!\n\n🎟 بلیتت صادر شده… آماده‌ای ببینی در آینده پشت کدوم میز نویسندگی نشستی؟",
                'questions' => $new_questions,
                'result_config' => wp_json_encode( $config, JSON_UNESCAPED_UNICODE ),
                'updated_at' => current_time( 'mysql' ),
            ], [ 'id' => (int) $event_row->id ] );
        }
        update_option( 'mabna_bale_challenge_db_version', self::VERSION );
    }

    public static function maybe_install(): void { if ( get_option( 'mabna_bale_challenge_db_version' ) !== self::VERSION ) self::install(); }

    public static function upsert_event( array $event, int $id = 0 ): int {
        global $wpdb; $table = self::tables()['events']; $now = current_time( 'mysql' );
        $values = [ 'title'=>$event['title']??'', 'message'=>$event['message']??'', 'questions'=>wp_json_encode($event['questions']??[],JSON_UNESCAPED_UNICODE), 'result_config'=>wp_json_encode($event['result_config']??[],JSON_UNESCAPED_UNICODE), 'start_at'=>!empty($event['start'])?str_replace('T',' ',$event['start']):null, 'end_at'=>!empty($event['end'])?str_replace('T',' ',$event['end']):null, 'is_active'=>!empty($event['active'])?1:0, 'updated_at'=>$now ];
        if($id){$wpdb->update($table,$values,['id'=>$id]);return $id;}$values['created_at']=$now;$wpdb->insert($table,$values);return (int)$wpdb->insert_id;
    }
    public static function migrate_legacy_events(): void { global $wpdb; $legacy=(array)get_option('mabna_bale_events',[]); if(!$legacy)return; $table=self::tables()['events']; foreach($legacy as $id=>$event){if(!is_array($event))continue;$exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id=%d",(int)$id));if(!$exists)self::upsert_event($event,(int)$id);} }
    public static function remove_duplicate_events(): int { global $wpdb; $t=self::tables()['events']; $ids=$wpdb->get_col("SELECT id FROM {$t} WHERE title LIKE '%نویسنده%' OR title LIKE '%ماشین زمان%' ORDER BY id ASC"); if(count($ids)!==3)return 0; $keep=(int)$ids[1]; $wpdb->query("DELETE FROM {$t} WHERE id IN (".(int)$ids[0].','.(int)$ids[2].")"); return $keep; }

    public static function session( int $event_id, string $chat_id ): ?array {
        global $wpdb; $t = self::tables()['sessions'];
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE event_id=%d AND chat_id=%s LIMIT 1", $event_id, $chat_id ), ARRAY_A );
        if ( ! $row ) return null;
        foreach ( [ 'answers', 'message_ids' ] as $field ) $row[ $field ] = $row[ $field ] ? ( json_decode( $row[ $field ], true ) ?: [] ) : [];
        return $row;
    }

    public static function save_session( array $data ): int {
        global $wpdb; $t = self::tables()['sessions']; $now = current_time( 'mysql' );
        $existing = self::session( (int) $data['event_id'], (string) $data['chat_id'] );
        $values = [ 'event_id'=>(int)$data['event_id'], 'chat_id'=>(string)$data['chat_id'], 'user_id'=>(int)($data['user_id']??0), 'phone'=>$data['phone']??null, 'session_token_hash'=>$data['session_token_hash']??null, 'source'=>$data['source']??'bale', 'marketing_consent'=>!empty($data['marketing_consent'])?1:0, 'consent_at'=>$data['consent_at']??null, 'first_name'=>$data['first_name']??null, 'last_name'=>$data['last_name']??null, 'current_step'=>$data['current_step']??'start', 'answers'=>wp_json_encode($data['answers']??[],JSON_UNESCAPED_UNICODE), 'message_ids'=>wp_json_encode($data['message_ids']??[],JSON_UNESCAPED_UNICODE), 'status'=>$data['status']??'active', 'completed_at'=>$data['completed_at']??null, 'result_key'=>$data['result_key']??null, 'referral_code'=>$data['referral_code']??null, 'referred_by'=>$data['referred_by']??null, 'updated_at'=>$now ];
        if ( $existing ) { $wpdb->update( $t, $values, [ 'id'=>(int)$existing['id'] ] ); return (int)$existing['id']; }
        $values['created_at']=$now; $wpdb->insert( $t, $values ); return (int)$wpdb->insert_id;
    }

    public static function active_events(): array {
        global $wpdb; $t = self::tables()['events']; $now = current_time( 'mysql' );
        $rows=(array)$wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE is_active=1 AND (start_at IS NULL OR start_at='0000-00-00 00:00:00' OR start_at<=%s) AND (end_at IS NULL OR end_at='0000-00-00 00:00:00' OR end_at>=%s) ORDER BY id ASC", $now, $now ), ARRAY_A ); foreach($rows as &$row){$row['questions']=$row['questions']?(json_decode($row['questions'],true)?:[]):[];$row['result_config']=$row['result_config']?(json_decode($row['result_config'],true)?:[]):[];$row['start']=$row['start_at'];$row['end']=$row['end_at'];$row['active']=$row['is_active'];} return $rows;
    }

    public static function event( int $id ): ?array { global $wpdb; $t=self::tables()['events']; $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$id),ARRAY_A); if(!$row)return null; $row['questions']=$row['questions']?(json_decode($row['questions'],true)?:[]):[]; $row['result_config']=$row['result_config']?(json_decode($row['result_config'],true)?:[]):[]; $row['start']=$row['start_at'];$row['end']=$row['end_at'];$row['active']=$row['is_active']; return $row; }

    public static function log( string $type, string $chat_id = '', int $user_id = 0, array $payload = [] ): void { global $wpdb; $t=self::tables()['logs']; $wpdb->insert($t,[ 'event_type'=>$type,'chat_id'=>$chat_id,'user_id'=>$user_id,'payload'=>wp_json_encode($payload,JSON_UNESCAPED_UNICODE),'created_at'=>current_time('mysql') ]); }

    public static function result( array $session, string $result_key = 'default' ): int { global $wpdb; $t=self::tables()['results']; $wpdb->insert($t,[ 'event_id'=>(int)$session['event_id'],'session_id'=>(int)$session['id'],'user_id'=>(int)($session['user_id']??0),'chat_id'=>$session['chat_id'],'phone'=>$session['phone']??null,'source'=>$session['source']??'bale','result_key'=>$result_key,'answer_summary'=>wp_json_encode($session['answers']??[],JSON_UNESCAPED_UNICODE),'created_at'=>current_time('mysql') ]); return (int)$wpdb->insert_id; }
    public static function add_referral( int $event_id, int $session_id, string $code, string $referred_chat ): void { global $wpdb; $t=self::tables()['referrals']; $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$t} (event_id,referrer_session_id,referrer_code,referred_chat_id,created_at) VALUES (%d,%d,%s,%s,%s)",$event_id,$session_id,$code,$referred_chat,current_time('mysql'))); }
    public static function referral_count( int $session_id ): int { global $wpdb; $t=self::tables()['referrals']; return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE referrer_session_id=%d",$session_id)); }
    public static function session_by_referral( int $event_id, string $code ): ?array { global $wpdb; $t=self::tables()['sessions']; $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE event_id=%d AND referral_code=%s LIMIT 1",$event_id,$code),ARRAY_A); if(!$r)return null; foreach(['answers','message_ids'] as $f)$r[$f]=$r[$f]?json_decode($r[$f],true):[]; return $r; }
}

function mabna_bale_challenge_questions( array $event = [] ): array {
    $questions = ! empty( $event['questions'] ) && is_array( $event['questions'] ) ? $event['questions'] : [];
    $defaults = [
        [ 'text'=>'راستش رو بگو! بخش یادداشت‌های گوشیت شبیه کدوم یکیه؟ ⁉️🗒', 'options'=>[ 'الف. لیست خرید مثل گوجه، باطری قلمی، آب پنیر + بدوبیراه‌هایی که جرئت نمی‌کنم مستقیم تو روی رئیسم بگم! 👀', 'ب. یه آرشیو محرمانه از ایده‌ها و دیالوگ‌هایی که توی فیلم‌ها و کتاب‌ها دیدم و پنج سال و سه ماهه که قراره از شنبه روشون کار کنم! 😁' ] ],
        [ 'text'=>'اولین متن یا انشایی که نوشتی رو یادته؟ سرنوشتش چی شد؟ 📝', 'options'=>[ 'الف. مچاله‌اش کردم و انداختمش دور، اگه کسی می‌دیدش مایه آبروریزی بود! 😰', 'ب. مادرم قاب کرده زده روی یخچال و دائم می‌گه قربون نویسنده فرهیخته‌ام برم! 😎' ] ],
        [ 'text'=>'بیشتر چه وقت‌هایی ایده نوشتن میاد سراغت؟⚡️', 'options'=>[ 'الف. تو اوج مهمونی وقتی صدا به صدا نمی‌رسه، بچه‌ شیطون فامیل از یقه‌ام آویزونه و هم‌زمان دارم به ماجرای دخترخاله شوهرعمه بابام گوش می‌دم! 😁 (برونگرا E)', 'ب. وقتی گوشی رو گذاشتم رو حالت پرواز و رفتم جایی که فاصله هر جنبنده‌ای باهاش حداقل ۱۰۰ کیلومتره! 📱❌ (درونگرا I)' ] ],
        [ 'text'=>'به نظرت عطر خاص نویسنده ها چیه؟ 🧬✨', 'options'=>[ 'الف. ترکیبی از بوی کتاب ورق نخورده، جوهر خودکار بیک و خاک کیبورد! 📖✏️ (حسی S)', 'ب. ترکیبی از بوی داستان های شگفت انگیز، کلمه های ناب به علاوه عطر برف‌های قله کلیمانجارو و شکوفه‌های سیب سارایوو همراه با یه کم بوی روشنفکری ته گرفته! 🌟🌸 (شهودی N)' ] ],
        [ 'text'=>'اگه متن داستانت رو بخونم و بهت بگم اصلا خوب نبود و باید بیشتر تلاش کنی، واکنشت چیه؟📜❗️', 'options'=>[ 'الف. ازت می‌پرسم: «دقیقاً کجاش خوب نبود؟» بعد سه دلیل، دو شاهد و گزارش دقیق کارشناسی ازت می‌خوام! 🧐 (منطقی T)', 'ب. اول چند دقیقه وانمود می‌کنم مهم نیست، بعد انقدر گریه می‌کنم تا آخرش مجبور شی داستانم رو بپذیری! 🥲 (احساسی F)' ] ],
        [ 'text'=>'وقتی داری کتاب می‌خونی دوست داری پایان بندیش چطور باشه؟ 😇📚', 'options'=>[ 'الف. طوری تموم شه که تکلیف همه‌چیز مشخص باشه؛ حتی اون گربه‌ای که توی صفحه ۳۷ کنار صحنه جرم یه «میو» گفته! 🐈👀 (J ساختارمند)', 'ب. جوری تموم شه که مغزم درگیر هزار فرضیه بشه و حتی آخرش هم دقیق نفهمم چی به چی شد؛ آخه مزه‌اش به همینه! 😁 (P انعطاف پذیر)' ] ],
    ];
    foreach ( $defaults as $i => $default ) if ( ! empty( $questions[ $i ]['text'] ) && is_array( $questions[ $i ]['options'] ?? null ) ) $defaults[ $i ] = $questions[ $i ];
    return $defaults;
}
function mabna_bale_mbti_authors(): array {
    return [
        'INTJ'=>['key'=>'INTJ','name'=>'جلال آل احمد','features'=>['نویسنده داستان با چاشنی نقد اجتماعی','نگاهی به ژرفی چاه‌های نفت','قلمی با جملات صریح و مردافکن'],'image_url'=>''], 'INTP'=>['key'=>'INTP','name'=>'ژول ورن','features'=>['ذهنی با موتور همیشه روشن','دشمن خونی زندگی یکنواخت','کشته‌مردهٔ نظریه‌پردازی فلسفی'],'image_url'=>''], 'ENTJ'=>['key'=>'ENTJ','name'=>'سیمین دانشور','features'=>['رهبر خودخواسته','سریع و خشن و کارآمد','اعتمادبه‌نفس خالص'],'image_url'=>''], 'ENTP'=>['key'=>'ENTP','name'=>'علی‌اکبر دهخدا','features'=>['عاشق معماهای حل‌نشده','کارخانهٔ تولید ایدهٔ ۲۴ساعته','منظم در عین بی‌نظمی'],'image_url'=>''],
    'INFJ'=>['key'=>'INFJ','name'=>'لئو تولستوی','features'=>['آرمان‌گرا','ژرف‌اندیش','دغدغه‌مند'],'image_url'=>''], 'INFP'=>['key'=>'INFP','name'=>'ویرجینیا وولف','features'=>['کاشف اقیانوس‌های ناشناختهٔ ذهن','خلوت‌نشین و کنج‌عزلت‌گزین','استاد کوبیدن احساسات و از نو ساختنشان با ادبیات'],'image_url'=>''], 'ENFJ'=>['key'=>'ENFJ','name'=>'ویکتور هوگو','features'=>['اثرگذار','انسان‌دوست','روایتگر'],'image_url'=>''], 'ENFP'=>['key'=>'ENFP','name'=>'محمدعلی جمالزاده','features'=>['خلاق','مردم‌دار','قصه‌گو'],'image_url'=>''],
    'ISTJ'=>['key'=>'ISTJ','name'=>'ابوالفضل بیهقی','features'=>['دارای کهن‌ترین سنسور اصالت‌سنجی محتوا','منظم‌ترین نویسنده','فیلم‌بردار تمام‌وقت مستندهای تاریخی'],'image_url'=>''], 'ISFJ'=>['key'=>'ISFJ','name'=>'سعدی','features'=>['نویسندهٔ کف بازار','داستانک‌نویس پیش از اختراع داستانک','یک‌جا ننشین و عشق جاده'],'image_url'=>''], 'ESTJ'=>['key'=>'ESTJ','name'=>'همینگوی','features'=>['اهل عمل','میرزابنویس سه‌پیچ','فرماندهٔ لشکر حذف واژه‌های کم‌اثر'],'image_url'=>''], 'ESFJ'=>['key'=>'ESFJ','name'=>'رضا امیرخانی','features'=>['مخترع رسم‌الخط‌های بدیع','کلکسیونر تجربه‌های زیسته','دارای سردرد همیشگی برای شیرجه در دل ماجرا'],'image_url'=>''],
        'ISTP'=>['key'=>'ISTP','name'=>'نادر ابراهیمی','features'=>['توانایی بیرون کشیدن حق آرمان‌ها','اولین کاشف معنای زندگی در گرهٔ قالی','عاشق تجربه'],'image_url'=>''], 'ISFP'=>['key'=>'ISFP','name'=>'احمد محمود','features'=>['دارای پوآروی درون برای کشف جزئیات','مدیر کارخانهٔ تبدیل زندگی به ادبیات','اهل گفت‌وگو'],'image_url'=>''], 'ESTP'=>['key'=>'ESTP','name'=>'مهدی آذریزدی','features'=>['عاشق سرگرم‌کردن بچه‌های کتاب‌گریز','توانایی نوشتن دربارهٔ موضوع نامشخص','بیرون کشیدن تنقلات از ادبیات کهن'],'image_url'=>''], 'ESFP'=>['key'=>'ESFP','name'=>'علی‌محمد افغانی','features'=>['سوژه‌پیداکن از هیچ','پدر دل‌سوز شخصیت‌های داستان','متخصص به جنون رساندن ناشر'],'image_url'=>''],
    ];
}
function mabna_bale_mbti_score_map(): array { return [ '0'=>['0'=>'','1'=>''], '1'=>['0'=>'','1'=>''], '2'=>['0'=>'E','1'=>'I'], '3'=>['0'=>'S','1'=>'N'], '4'=>['0'=>'T','1'=>'F'], '5'=>['0'=>'J','1'=>'P'] ]; }
