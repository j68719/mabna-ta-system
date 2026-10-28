from pathlib import Path
p=Path('/home/ubuntu/work/ta-system/includes/class-mabna-bale-bot.php')
s=p.read_text()
old="if(preg_match('/^mabna_referral_(\\d+)$/',$data,$m)){$this->send_referral((int)$m[1],$chat);return;}"
new="if(preg_match('/^mabna_phone_request_(\\d+)$/',$data,$m)){$this->phone_prompt((int)$m[1],$chat);return;}if(preg_match('/^mabna_referral_(\\d+)$/',$data,$m)){$this->send_referral((int)$m[1],$chat);return;}"
if old not in s: raise SystemExit('callback target missing')
s=s.replace(old,new,1)
start=s.index('    private function request_phone(')
end=s.index('    private function create_user_from_phone(',start)
new_request=r'''    private function request_phone( array $event, array $s ): void {
        if ( ! empty( $s['phone'] ) || ! empty( $s['user_id'] ) ) { $this->finish( $event, $s ); return; }
        $s['current_step'] = 'phone';
        Mabna_Bale_Challenge_DB::save_session( $s );
        $message = "🎉 تبریک! نویسنده مورد نظر رو پیدا کردیم!  \n🔐 اما متاسفانه توی گاوصندوق ماشین زمان گیر کرده! فقط کافیه شمارهات رو وارد کنی تا قفل باز بشه... 📱🔓\n\n✨ چی در انتظارته؟  \n👤 اسم نویسنده‌ای که در آینده شبیهش میشی،  \n🌟 ویژگیهای منحصر به فردش،\n🧗‍♂️️ چالشهایی که در آینده مسیر سر راهت قرار میگیره\n🔑 و البته راه حل این چالشها!\n\nپس عجله کن! 🚀⌛️";
        $this->send( $s['chat_id'], $message, [ 'reply_markup' => [ 'inline_keyboard' => [ [ [ 'text' => 'وارد کردن شماره تلفن و باز شدن قفل!📱🔓', 'callback_data' => 'mabna_phone_request_' . (int) $s['id'] ] ] ] ] ] );
        $this->phone_prompt( (int) $s['id'], (string) $s['chat_id'] );
    }
    private function phone_prompt( int $sid, string $chat ): void {
        $s = $this->session_by_id( $sid );
        if ( ! $s || $s['chat_id'] !== $chat || ( $s['current_step'] ?? '' ) !== 'phone' ) return;
        $this->send( $chat, 'برای باز شدن قفل، شماره تلفنت رو با دکمه زیر به اشتراک بذار:', [ 'reply_markup' => [ 'keyboard' => [ [ [ 'text' => 'وارد کردن شماره تلفن و باز شدن قفل!📱🔓', 'request_contact' => true ] ] ], 'resize_keyboard' => true, 'one_time_keyboard' => true ] ] );
    }
'''
s=s[:start]+new_request+s[end:]
s=s.replace("$name = $full_name !== '' ? $full_name . \" عزیز،\\n\\n\" : '';", "$name = $full_name !== '' ? '❣️ ' . $full_name . \" عزیز،\\n\\n\" : '';",1)
start=s.index('    private function send_referral(')
end=s.index('\n}',start)
old_ref=s[start:end]
new_ref=r'''    private function send_referral( int $sid, string $chat ): void {
        $s = $this->session_by_id( $sid );
        if ( ! $s || $s['chat_id'] !== $chat ) return;
        $identity = (array) get_option( 'mabna_bale_bot_identity', [] );
        $username = $identity['username'] ?? '';
        $code = $s['referral_code'] ?? substr( hash( 'sha256', $s['event_id'] . '|' . $s['id'] . '|' . $chat ), 0, 12 );
        $link = $username ? 'https://ble.ir/' . $username . '?start=ref_' . $code : home_url( '/' );
        $count = Mabna_Bale_Challenge_DB::referral_count( $sid );
        $text = "🎁✨ یک سفر هیجان‌انگیز! ✨🎁\n\n🪐 ماشین زمان نویسندگی مبنا آماده‌ست تا بهت بگه در آینده شبیه کدوم نویسنده می‌شی!\n✍️ فقط به ۶ سؤال کوتاه جواب بده و نویسندهٔ آینده‌ات رو پیدا کن.\n\n🚀 از این لینک وارد شو:\n$link";
        $markup = [ 'inline_keyboard' => [ [ [ 'text' => '👥 تعداد دعوت‌های ثبت‌شده: ' . $count, 'callback_data' => 'mabna_referral_' . $sid ] ] ] ];
        $this->send( $chat, $text, [ 'reply_markup' => $markup ] );
    }
'''
s=s[:start]+new_ref+s[end:]
p.write_text(s)
