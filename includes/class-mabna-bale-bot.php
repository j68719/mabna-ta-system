<?php
defined( 'ABSPATH' ) || exit;

class Mabna_Bale_Bot {
    public function __construct() { add_action( 'rest_api_init', [ $this, 'register_route' ] ); }
    public function register_route(): void { register_rest_route( 'mabna-bale/v1', '/webhook', [ 'methods'=>'POST','callback'=>[$this,'webhook'],'permission_callback'=>'__return_true' ] ); }
    public function webhook( WP_REST_Request $request ): WP_REST_Response {
        $update=$request->get_json_params();if(!is_array($update))return new WP_REST_Response(['ok'=>false],400);$uid=isset($update['update_id'])?(string)absint($update['update_id']):'';if($uid){$seen=get_option('mabna_bale_processed_updates',[]);if(!is_array($seen))$seen=[];if(in_array($uid,$seen,true))return new WP_REST_Response(['ok'=>true],200);$seen[]=$uid;update_option('mabna_bale_processed_updates',array_slice($seen,-1000));}
        if(isset($update['callback_query'])){$this->callback($update['callback_query']);return new WP_REST_Response(['ok'=>true],200);} $m=$update['message']??null;if(!is_array($m))return new WP_REST_Response(['ok'=>true],200);$chat=(string)($m['chat']['id']??'');if($chat==='')return new WP_REST_Response(['ok'=>true],200);$text=trim((string)($m['text']??''));
        if(is_array($m['contact']??null)){$this->phone_received($chat,$m['contact']);return new WP_REST_Response(['ok'=>true],200);}if(str_starts_with($text,'/start')){$ref='';if(preg_match('/^\/start\s+ref_([A-Za-z0-9_-]+)$/',$text,$x))$ref=$x[1];$this->start($chat,$ref);return new WP_REST_Response(['ok'=>true],200);}if($text==='🌟دریافت لینک معرف برای دعوت دوستان'){$this->send_referral_for_chat($chat);return new WP_REST_Response(['ok'=>true],200);}$this->text_received($chat,$text,(int)($m['message_id']??0));return new WP_REST_Response(['ok'=>true],200);
    }
    private function send(string $chat,string $text,array $extra=[]):array{return mabna_bale_api()->send_message($chat,$text,$extra);}
    private function fa_digits( int|string $value ): string {
        return strtr( (string) $value, [ '0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹' ] );
    }
    private function send_welcome( array $event, array $s ): void {
        $config = $event['result_config'] ?? [];
        $button = [ [ 'text'=>'شروع به کار ماشینِ زمان!🚀', 'callback_data'=>'mabna_machine_start_' . (int) $s['id'] ] ];
        $caption = ! empty( $event['message'] )
            ? (string) $event['message']
            : "✍️🪐 به ماشینِ زمان نویسندگی مبنا خوش آمدید!\nماشینِ زمان روشنه و فقط منتظر تأیید شماست تا کارش رو شروع کنه!\n\n🚀 قانون سفر سادهست:\nاگه میخوای بدونی آیندهات شبیه به کدوم نویسنده میشه، فقط کافیه به ۶تا سوال بعدی جواب بدی!\n\n🎟️ بلیتت صادر شده… آمادهای ببینی در آینده پشت کدوم میز نویسندگی نشستی؟";
        $r = ! empty( $config['intro_image_url'] ) ? mabna_bale_api()->send_photo( $s['chat_id'], $config['intro_image_url'], $caption, [ 'reply_markup'=>[ 'inline_keyboard'=>[ $button ] ] ] ) : $this->send( $s['chat_id'], $caption, [ 'reply_markup'=>[ 'inline_keyboard'=>[ $button ] ] ] );
        $s['message_ids']['main'] = (int) ( $r['result']['message_id'] ?? 0 );
        Mabna_Bale_Challenge_DB::save_session( $s );
    }
    private function start( string $chat, string $ref='' ): void {
        $events = Mabna_Bale_Challenge_DB::active_events();
        if ( ! $events ) { $this->send( $chat, 'در حال حاضر چالش فعالی وجود ندارد.' ); return; }
        foreach ( $events as $event ) {
            $s = Mabna_Bale_Challenge_DB::session( (int) $event['id'], $chat );
            if ( $s && $s['status'] === 'active' ) { $this->send_welcome( $event, $s ); continue; }
            $this->begin( $event, $chat, $ref );
        }
    }
    private function begin( array $event, string $chat, string $ref='' ): void {
        $s = [ 'event_id'=>(int)$event['id'], 'chat_id'=>$chat, 'user_id'=>0, 'phone'=>null, 'first_name'=>null, 'last_name'=>null, 'current_step'=>'machine_start', 'answers'=>[], 'message_ids'=>[], 'status'=>'active', 'source'=>'bale', 'referred_by'=>$ref?:null ];
        $user = mabna_bale_chat_user( $chat );
        if ( $user ) { $s['user_id']=$user->ID; $s['phone']=(string)get_user_meta($user->ID,'mabna_bale_phone',true); $s['first_name']=$user->first_name; }
        $id = Mabna_Bale_Challenge_DB::save_session( $s ); $s['id'] = $id;
        if ( $ref && ($owner=Mabna_Bale_Challenge_DB::session_by_referral((int)$event['id'],$ref)) && $owner['chat_id'] !== $chat ) Mabna_Bale_Challenge_DB::add_referral((int)$event['id'],$owner['id'],$ref,$chat);
        $this->send_welcome( $event, $s );
    }
    private function resume(array $event,array $s):void{if(($s['current_step']??'')==='machine_start')return;if(($s['current_step']??'')==='full_name'){$this->name_prompt($s,'نام و نام خانوادگی‌ات رو در یک پیام وارد کن:');return;}$this->send_question($event,$s,0);}
    private function callback(array $cb):void{$id=(string)($cb['id']??'');$data=(string)($cb['data']??'');$chat=(string)($cb['message']['chat']['id']??'');if($id)mabna_bale_api()->answer_callback_query($id);if(preg_match('/^mabna_machine_start_(\d+)$/',$data,$m)){$this->machine_start((int)$m[1],$chat);return;}if(preg_match('/^mabna_name_confirm_(yes|no)_(\d+)$/',$data,$m)){$this->name_confirm($m[1],(int)$m[2],$chat);return;}if(preg_match('/^mabna_name_(yes|no)_(\d+)$/',$data,$m)){$this->name_choice($m[1],(int)$m[2],$chat);return;}if(preg_match('/^mabna_author_answer_(\d+)_(\d+)_(\d+)$/',$data,$m)){$this->answer((int)$m[1],(int)$m[2],(int)$m[3],$chat);return;}if(preg_match('/^mabna_referral_(\d+)$/',$data,$m)){$this->send_referral((int)$m[1],$chat);return;}}
    private function session_by_id(int $id):?array{global $wpdb;$t=Mabna_Bale_Challenge_DB::tables()['sessions'];$r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$id),ARRAY_A);if(!$r)return null;foreach(['answers','message_ids'] as $f)$r[$f]=$r[$f]?json_decode($r[$f],true):[];return $r;}
    private function delete_flow_messages( array $s ): void {
        $ids = array_merge( (array) ( $s['message_ids']['name_flow'] ?? [] ), (array) ( $s['message_ids']['name_inputs'] ?? [] ) );
        foreach ( $ids as $message_id ) if ( $message_id ) mabna_bale_api()->delete_message( (string) $s['chat_id'], (string) $message_id );
    }
    private function machine_start(int $sid,string $chat):void{$s=$this->session_by_id($sid);if(!$s||$s['chat_id']!==$chat)return;$user=!empty($s['user_id'])?get_user_by('id',(int)$s['user_id']):mabna_bale_chat_user($chat);$s['message_ids']['name_flow']=[];if($user instanceof WP_User){$s['user_id']=$user->ID;$s['phone']=(string)get_user_meta($user->ID,'mabna_bale_phone',true);$s['first_name']=(string)$user->first_name;$s['last_name']=(string)$user->last_name;$s['current_step']='name_confirm';Mabna_Bale_Challenge_DB::save_session($s);$full_name=trim($s['first_name'].' '.$s['last_name']);$r=$this->send($chat,$full_name.' عزیز\nاین نام و نام خانوادگی روی کارت خروجی ماشینِ زمان نمایش داده میشه.',['reply_markup'=>['inline_keyboard'=>[[['text'=>'درسته بزن بریم!','callback_data'=>'mabna_name_confirm_yes_'.$sid]],[['text'=>'اصلاح نام و نام خانوادگی','callback_data'=>'mabna_name_confirm_no_'.$sid]]]]]);$s['message_ids']['name_flow'][]=(int)($r['result']['message_id']??0);Mabna_Bale_Challenge_DB::save_session($s);return;}$s['first_name']='';$s['last_name']='';$s['current_step']='name_choice';Mabna_Bale_Challenge_DB::save_session($s);$r=$this->send($chat,'مسافر این سفر رو با چه نامی ثبت کنیم؟🖋️📋\nاین اسم روی کارت پایانی شما نمایش داده میشه.',['reply_markup'=>['inline_keyboard'=>[[['text'=>'وارد کردن نام ✍️','callback_data'=>'mabna_name_yes_'.$sid],['text'=>'رد کردن این مرحله ⭕️','callback_data'=>'mabna_name_no_'.$sid]]]]]);$s['message_ids']['name_flow'][]=(int)($r['result']['message_id']??0);Mabna_Bale_Challenge_DB::save_session($s);}
    private function name_confirm(string $choice,int $sid,string $chat):void{$s=$this->session_by_id($sid);if(!$s||$s['chat_id']!==$chat)return;if($choice==='yes'){$this->delete_flow_messages($s);$s['current_step']='question_1';Mabna_Bale_Challenge_DB::save_session($s);$this->send_question(Mabna_Bale_Challenge_DB::event((int)$s['event_id']),$s,0);return;}$this->delete_flow_messages($s);$s['first_name']='';$s['last_name']='';$s['current_step']='full_name';Mabna_Bale_Challenge_DB::save_session($s);$this->name_prompt($s,'نام و نام خانوادگی‌ات رو در یک پیام وارد کن:');}
    private function name_choice(string $choice,int $sid,string $chat):void{$s=$this->session_by_id($sid);if(!$s||$s['chat_id']!==$chat)return;if($choice==='no'){$this->delete_flow_messages($s);$s['current_step']='question_1';Mabna_Bale_Challenge_DB::save_session($s);$this->send_question(Mabna_Bale_Challenge_DB::event((int)$s['event_id']),$s,0);return;}$s['current_step']='full_name';Mabna_Bale_Challenge_DB::save_session($s);$this->name_prompt($s,'نام و نام خانوادگی‌ات رو در یک پیام وارد کن:');}
    private function name_prompt(array $s,string $text):void{$r=$this->send($s['chat_id'],$text);$s['message_ids']['name_flow'][]=(int)($r['result']['message_id']??0);Mabna_Bale_Challenge_DB::save_session($s);}
    private function text_received(string $chat,string $text,int $message_id=0):void{foreach(Mabna_Bale_Challenge_DB::active_events() as $event){$s=Mabna_Bale_Challenge_DB::session((int)$event['id'],$chat);if(!$s||$s['status']!=='active')continue;if(($s['current_step']??'')==='phone'){$this->phone_prompt($chat,true);return;}if($s['current_step']==='full_name'){$full_name=trim(sanitize_text_field($text));$parts=preg_split('/\s+/u',$full_name,2);$s['first_name']=trim((string)($parts[0]??''));$s['last_name']=trim((string)($parts[1]??''));if($message_id)$s['message_ids']['name_inputs'][]=$message_id;if(!empty($s['user_id']))wp_update_user(['ID'=>(int)$s['user_id'],'first_name'=>$s['first_name'],'last_name'=>$s['last_name'],'display_name'=>trim($s['first_name'].' '.$s['last_name'])]);$this->delete_flow_messages($s);$s['current_step']='question_1';Mabna_Bale_Challenge_DB::save_session($s);$this->send_question($event,$s,0);return;}if($s['current_step']==='first_name'){$s['first_name']=sanitize_text_field($text);if($message_id)$s['message_ids']['name_inputs'][]=$message_id;$s['current_step']='last_name';Mabna_Bale_Challenge_DB::save_session($s);$this->name_prompt($s,'نام خانوادگی‌ات رو هم وارد کن.');return;}if($s['current_step']==='last_name'){$s['last_name']=sanitize_text_field($text);if($message_id)$s['message_ids']['name_inputs'][]=$message_id;if(!empty($s['user_id']))wp_update_user(['ID'=>(int)$s['user_id'],'first_name'=>$s['first_name'],'last_name'=>$s['last_name'],'display_name'=>trim($s['first_name'].' '.$s['last_name'])]);$this->delete_flow_messages($s);$s['current_step']='question_1';Mabna_Bale_Challenge_DB::save_session($s);$this->send_question($event,$s,0);return;}}}
    private function send_question(array $event,array $s,int $index):void{$qs=mabna_bale_challenge_questions($event);if(!isset($qs[$index])){$this->request_phone($event,$s);return;}$q=$qs[$index];$options=(array)($q['options']??[]);$option_text='';foreach($options as $i=>$o){$o=preg_replace('/^\s*(?:الف|ب)\s*[.)]\s*/u','',(string)$o);$o=preg_replace('/\s*\([^()]*\)\s*$/u','',$o);$option_text.=($option_text?"\n\n":'').($i===0?'الف':'ب').') '.$o;}$keys=[[['text'=>'الف','callback_data'=>'mabna_author_answer_'.$s['id'].'_'.$index.'_0'],['text'=>'ب','callback_data'=>'mabna_author_answer_'.$s['id'].'_'.$index.'_1']]];$message='*سؤال '.$this->fa_digits($index+1).' از ۶*' . "\n\n*" . $q['text'] . "*\n\n" . $option_text . "\n\nکدوم مورد بیشتر بهت نزدیکه؟ الف یا ب؟";$this->send($s['chat_id'],$message,['parse_mode'=>'Markdown','reply_markup'=>['inline_keyboard'=>$keys]]);$s['current_step']='question_'.($index+1);Mabna_Bale_Challenge_DB::save_session($s);}
    private function answer(int $sid,int $index,int $choice,string $chat):void{$s=$this->session_by_id($sid);if(!$s||$s['chat_id']!==$chat)return;$event=Mabna_Bale_Challenge_DB::event((int)$s['event_id']);$q=mabna_bale_challenge_questions($event);if(!isset($q[$index]['options'][$choice]))return;$s['answers'][(string)$index]=['choice'=>$choice,'text'=>$q[$index]['options'][$choice]];Mabna_Bale_Challenge_DB::save_session($s);$this->send_question($event,$s,$index+1);}
    private function phone_prompt( string $chat, bool $reminder = false ): void {
        $message = $reminder
            ? "هنوز شمارهات رو به اشتراک نگذاشتی.\nروی دکمه پایین صفحه، «وارد کردن شماره تلفن و بازشدن ققل📱🔓» بزن!"
            : "🎉 تبریک! نویسنده مورد نظر رو پیدا کردیم!  \n🔐 اما متاسفانه توی گاوصندوق ماشینِ زمان گیر کرده! فقط کافیه شماره‌ات رو وارد کنی تا قفل باز بشه... 📱🔓\n\n✨ چی در انتظارته؟  \n👤 اسم نویسنده‌ای که در آینده شبیهش میشی،  \n🌟 ویژگی‌های منحصر به فردش،\n🧗‍♂️️ چالش‌هایی که در آینده مسیر سر راهت قرار میگیره\n🔑 و البته راه‌حل این چالش‌ها!\n\nپس عجله کن! 🚀⌛️";
        $this->send( $chat, $message, [ 'reply_markup' => [ 'keyboard' => [ [ [ 'text' => 'وارد کردن شماره تلفن و باز شدن قفل!📱🔓', 'request_contact' => true ] ] ], 'resize_keyboard' => true, 'one_time_keyboard' => true ] ] );
    }

    private function request_phone( array $event, array $s ): void {
        if ( ! empty( $s['phone'] ) || ! empty( $s['user_id'] ) ) { $this->finish( $event, $s ); return; }
        $s['current_step'] = 'phone';
        Mabna_Bale_Challenge_DB::save_session( $s );
        $this->phone_prompt( (string) $s['chat_id'] );
    }
    private function create_user_from_phone(string $phone,string $chat,array $s):?WP_User{$first=trim((string)($s['first_name']??''));$last=trim((string)($s['last_name']??''));if($first===''){$first='شرکت‌کننده ماشینِ زمان';$last='';}$base='bale_'.preg_replace('/\D+/','',$phone);$login=$base;$n=1;while(username_exists($login))$login=$base.'_'.$n++;$email=preg_replace('/\D+/','',$phone).'@mabnaschool.ir';if(email_exists($email))$email=$login.'@mabnaschool.ir';$id=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(24,true,true),'user_email'=>$email,'first_name'=>$first,'last_name'=>$last,'display_name'=>trim($first.' '.$last),'role'=>'customer']);if(is_wp_error($id)){mabna_bale_log('bale_user_create_error',['chat_id'=>$chat,'phone'=>mabna_bale_mask_phone($phone),'error'=>$id->get_error_message()]);return null;}$user=get_user_by('id',(int)$id);return $user instanceof WP_User?$user:null;}
    private function phone_received(string $chat,array $contact):void{$phone=mabna_bale_normalize_phone((string)($contact['phone_number']??''));if(!preg_match('/^09[0-9]{9}$/',$phone))return;foreach(Mabna_Bale_Challenge_DB::active_events() as $event){$s=Mabna_Bale_Challenge_DB::session((int)$event['id'],$chat);if(!$s||$s['status']!=='active'||$s['current_step']!=='phone')continue;$s['phone']=$phone;$user=mabna_bale_phone_user($phone);if(!$user)$user=$this->create_user_from_phone($phone,$chat,$s);if($user){$s['user_id']=$user->ID;$this->connect_user($user,$chat,$phone);}Mabna_Bale_Challenge_DB::save_session($s);$this->finish($event,$s);}}
    private function connect_user(WP_User $user,string $chat,string $phone):void{update_user_meta($user->ID,'mabna_bale_chat_id',$chat);update_user_meta($user->ID,'mabna_bale_phone',$phone);update_user_meta($user->ID,'mabna_bale_connected_at',current_time('mysql'));foreach(['kerasno-phone','phone','Mreeir_phone','billing_phone'] as $key)update_user_meta($user->ID,$key,$phone);}
    private function author(array $event,array $s):array{$config=$event['result_config']??[];$authors=is_array($config['authors']??null)&&$config['authors']? $config['authors']:mabna_bale_mbti_authors();$map=mabna_bale_mbti_score_map();$type='';foreach([2,3,4,5] as $i){$type.=(string)($map[(string)$i][(string)($s['answers'][(string)$i]['choice']??$s['answers'][$i]['choice']??0)]??'');}$key=$type?:($authors[0]['key']??'default');foreach($authors as $a)if(strtoupper((string)($a['key']??''))===strtoupper($key))return $a;return ['key'=>$key,'name'=>$key,'features'=>['میدان‌دار','دغدغه‌مند','جدی'],'image_url'=>$config['image_url']??''];}
    private function finish( array $event, array $s ): void {
        $config = $event['result_config'] ?? [];
        $a = $this->author( $event, $s );
        $s['status'] = 'completed';
        $s['current_step'] = 'result';
        $s['completed_at'] = current_time( 'mysql' );
        $s['result_key'] = $a['key'] ?? 'default';
        Mabna_Bale_Challenge_DB::save_session( $s );
        $rid = Mabna_Bale_Challenge_DB::result( $s, $a['key'] ?? 'default' );

        $is_member = ! empty( $s['user_id'] ) && mabna_bale_is_mabna_member( (int) $s['user_id'], (string) $s['phone'] );
        $pattern = (string) ( $config[ $is_member ? 'sms_mabna_pattern' : 'sms_non_mabna_pattern' ] ?? '' );
        $sms = ( $pattern && $s['phone'] )
            ? TA_MeliPayamak::send_pattern( $s['phone'], $pattern, [ trim( (string) $s['first_name'] ), $a['name'] ?? '' ] )
            : [ 'ok' => false, 'message' => 'الگوی پیامک این گروه تنظیم نشده است.' ];
        Mabna_Bale_Challenge_DB::log( 'challenge_sms_sent', $s['chat_id'], (int) $s['user_id'], [ 'ok' => ! empty( $sms['ok'] ), 'result_id' => $rid ] );

        $full_name = trim( trim( (string) ( $s['first_name'] ?? '' ) ) . ' ' . trim( (string) ( $s['last_name'] ?? '' ) ) );
        $name = $full_name !== '' ? '❣️ ' . $full_name . " عزیز،\n\n" : '';
        $features = is_array( $a['features'] ?? null ) ? $a['features'] : [];
        $feature_text = implode( '، ', array_slice( $features, 0, 3 ) );
        $result_content = function_exists( 'mabna_bale_result_content' ) ? mabna_bale_result_content() : [];
        $fallback = '✨تو «' . ( $a['name'] ?? 'نویسنده منتخب' ) . "» آینده‌ای!\n\n" . ( $feature_text ? '🌟 ' . $feature_text . "\n\n" : '' );
        $caption = $name . (string) ( $result_content[ $a['key'] ?? '' ] ?? $fallback );

        $code = substr( hash( 'sha256', $s['event_id'] . '|' . $s['id'] . '|' . $s['chat_id'] ), 0, 12 );
        $s['referral_code'] = $code;
        Mabna_Bale_Challenge_DB::save_session( $s );
        $identity = (array) get_option( 'mabna_bale_bot_identity', [] );
        $username = trim( (string) ( $identity['username'] ?? '' ) );
        $referral_url = $username
            ? 'https://ble.ir/' . $username . '?start=ref_' . $code
            : home_url( '/' );
        $caption .= "\n\nنتیجه رو برای دوستت بفرست. ببین اون قراره شبیه کدوم نویسنده بشه👇\n🔗[" . $referral_url . "](" . $referral_url . ")";
        $url = (string) ( $a['image_url'] ?? ( $event['result_config']['image_url'] ?? '' ) );
        $card_url = Mabna_Bale_Card_Renderer::render( $url, $full_name ? $full_name . ' عزیز!' : '', (string) ( $a['key'] ?? '' ), (string) ( $event['title'] ?? 'mabna-bale-results' ) );
        if ( $card_url ) $url = $card_url;

        // لینک کلید بر اساس تیپ شخصیتی نتیجه تعیین می‌شود.
        $key_links = [
            'INTJ' => [ 'eitaa' => 'https://eitaa.com/mabnaschoole/9551', 'bale' => 'https://ble.ir/mabna_schoole/-6487364985819038479/1789654984019' ],
            'INFJ' => [ 'eitaa' => 'https://eitaa.com/mabnaschoole/9551', 'bale' => 'https://ble.ir/mabna_schoole/-6487364985819038479/1789654984019' ],
            'INTP' => [ 'eitaa' => 'https://eitaa.com/mabnaschoole/9551', 'bale' => 'https://ble.ir/mabna_schoole/-6487364985819038479/1789654984019' ],
            'ISTJ' => [ 'eitaa' => 'https://eitaa.com/mabnaschoole/9551', 'bale' => 'https://ble.ir/mabna_schoole/-6487364985819038479/1789654984019' ],
            'ENFP' => [ 'eitaa' => 'https://eitaa.com/mabnaschoole/9558', 'bale' => 'https://ble.ir/mabna_schoole/405125047979127723/1789746649543' ],
            'ENTP' => [ 'eitaa' => 'https://eitaa.com/mabnaschoole/9558', 'bale' => 'https://ble.ir/mabna_schoole/405125047979127723/1789746649543' ],
            'INFP' => [ 'eitaa' => 'https://eitaa.com/mabnaschoole/9558', 'bale' => 'https://ble.ir/mabna_schoole/405125047979127723/1789746649543' ],
            'ISFP' => [ 'eitaa' => 'https://eitaa.com/mabnaschoole/9558', 'bale' => 'https://ble.ir/mabna_schoole/405125047979127723/1789746649543' ],
            'ENTJ' => [ 'eitaa' => 'https://eitaa.com/mabnaschoole/9548', 'bale' => 'https://ble.ir/mabna_schoole/-7747849249466471183/1789632141662' ],
            'ESTJ' => [ 'eitaa' => 'https://eitaa.com/mabnaschoole/9548', 'bale' => 'https://ble.ir/mabna_schoole/-7747849249466471183/1789632141662' ],
            'ENFJ' => [ 'eitaa' => 'https://eitaa.com/mabnaschoole/9548', 'bale' => 'https://ble.ir/mabna_schoole/-7747849249466471183/1789632141662' ],
            'ESFJ' => [ 'eitaa' => 'https://eitaa.com/mabnaschoole/9548', 'bale' => 'https://ble.ir/mabna_schoole/-7747849249466471183/1789632141662' ],
            'ESTP' => [ 'eitaa' => 'https://eitaa.com/mabnaschoole/9554', 'bale' => 'https://ble.ir/mabna_schoole/2071413075221672801/1789676392411' ],
            'ESFP' => [ 'eitaa' => 'https://eitaa.com/mabnaschoole/9554', 'bale' => 'https://ble.ir/mabna_schoole/2071413075221672801/1789676392411' ],
            'ISTP' => [ 'eitaa' => 'https://eitaa.com/mabnaschoole/9554', 'bale' => 'https://ble.ir/mabna_schoole/2071413075221672801/1789676392411' ],
            'ISFJ' => [ 'eitaa' => 'https://eitaa.com/mabnaschoole/9554', 'bale' => 'https://ble.ir/mabna_schoole/2071413075221672801/1789676392411' ],
        ];
        $personality_type = strtoupper( (string) ( $a['key'] ?? '' ) );
        $links = $key_links[ $personality_type ] ?? null;
        if ( ! $links ) return;
        $markup = [
            'inline_keyboard' => [
                [ [ 'text' => '🔑دریافت کلید در ایتا', 'url' => $links['eitaa'] ] ],
                [ [ 'text' => '🔑دریافت کلید در بله', 'url' => $links['bale'] ] ],
                [ [ 'text' => '🌟دریافت لینک معرف برای دعوت دوستان', 'callback_data' => 'mabna_referral_' . $s['id'] ] ],
            ],
        ];
        if ( $url ) mabna_bale_api()->send_photo( $s['chat_id'], $url, $caption, [ 'parse_mode' => 'Markdown', 'reply_markup' => $markup ] );
        else $this->send( $s['chat_id'], $caption, [ 'parse_mode' => 'Markdown', 'reply_markup' => $markup ] );
    }

    private function send_referral_for_chat( string $chat ): void {
        foreach ( array_reverse( Mabna_Bale_Challenge_DB::active_events() ) as $event ) {
            $session = Mabna_Bale_Challenge_DB::session( (int) $event['id'], $chat );
            if ( $session && ( $session['status'] ?? '' ) === 'completed' ) {
                $this->send_referral( (int) $session['id'], $chat );
                return;
            }
        }
    }

    private function send_referral( int $sid, string $chat ): void {
        $s = $this->session_by_id( $sid );
        if ( ! $s || $s['chat_id'] !== $chat ) return;
        $identity = (array) get_option( 'mabna_bale_bot_identity', [] );
        $username = $identity['username'] ?? '';
        $code = $s['referral_code'] ?? substr( hash( 'sha256', $s['event_id'] . '|' . $s['id'] . '|' . $chat ), 0, 12 );
        $link = $username ? 'https://ble.ir/' . $username . '?start=ref_' . $code : home_url( '/' );
        $count = Mabna_Bale_Challenge_DB::referral_count( $sid );
        $text = "🎁✨ یک سفر هیجان‌انگیز! ✨🎁\n\n🪐 ماشینِ زمان نویسندگی مبنا آماده‌ست تا بهت بگه در آینده شبیه کدوم نویسنده می‌شی!\n✍️ فقط به ۶ سؤال کوتاه جواب بده و نویسندهٔ آینده‌ات رو پیدا کن.\n\n🚀 از این لینک وارد شو:\n$link";
        $markup = [ 'inline_keyboard' => [ [ [ 'text' => '👥 تعداد دعوت‌های ثبت‌شده: ' . $count, 'callback_data' => 'mabna_referral_' . $sid ] ] ] ];
        $this->send( $chat, $text, [ 'reply_markup' => $markup ] );
    }

}
