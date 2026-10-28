from pathlib import Path
root=Path('/home/ubuntu/work/ta-system')
# Admin event fields and persistence.
p=root/'admin/class-mabna-bale-admin.php'; s=p.read_text()
old="<tr><th>لینک پست آموزشی</th><td><input class=\"regular-text\" type=\"url\" name=\"result_url\" value=\"'.esc_attr($config['post_url']??'').'\" placeholder=\"https://ble.ir/...\"></td></tr>"
new="<tr><th>لینک کلید بله</th><td><input class=\"regular-text\" type=\"url\" name=\"bale_result_url\" value=\"'.esc_attr($config['bale_result_url']??'').'\" placeholder=\"https://ble.ir/...\"></td></tr><tr><th>لینک کلید ایتا</th><td><input class=\"regular-text\" type=\"url\" name=\"eitaa_result_url\" value=\"'.esc_attr($config['eitaa_result_url']??'').'\" placeholder=\"https://eitaa.com/...\"></td></tr><tr><th>لینک پست آموزشی قدیمی</th><td><input class=\"regular-text\" type=\"url\" name=\"result_url\" value=\"'.esc_attr($config['post_url']??'').'\" placeholder=\"https://...\"></td></tr>"
if old not in s: raise SystemExit('admin field target missing')
s=s.replace(old,new,1)
old2="'post_url'=>esc_url_raw(wp_unslash($_POST['result_url']??'')),'authors'=>$authors"
new2="'post_url'=>esc_url_raw(wp_unslash($_POST['result_url']??'')),'bale_result_url'=>esc_url_raw(wp_unslash($_POST['bale_result_url']??'')),'eitaa_result_url'=>esc_url_raw(wp_unslash($_POST['eitaa_result_url']??'')),'authors'=>$authors"
if old2 not in s: raise SystemExit('admin save target missing')
s=s.replace(old2,new2,1); p.write_text(s)
# Bot: use event-configured URLs and remove keyboard with final message.
p=root/'includes/class-mabna-bale-bot.php'; s=p.read_text()
s=s.replace("$channel_url = $channel ? ( str_starts_with( $channel, 'http' ) ? $channel : 'https://ble.ir/' . ltrim( $channel, '@' ) ) : 'https://ble.ir/';", "$channel_url = (string) ( $config['bale_result_url'] ?? '' );\n        if ( $channel_url === '' ) $channel_url = $channel ? ( str_starts_with( $channel, 'http' ) ? $channel : 'https://ble.ir/' . ltrim( $channel, '@' ) ) : 'https://ble.ir/';",1)
s=s.replace("'url' => (string) ( $config['eitaa_url'] ?? 'https://eitaa.com/' )", "'url' => (string) ( $config['eitaa_result_url'] ?? 'https://eitaa.com/' )",1)
s=s.replace("if ( $url ) mabna_bale_api()->send_photo( $s['chat_id'], $url, $caption, [ 'reply_markup' => $markup ] );\n        else $this->send( $s['chat_id'], $caption, [ 'reply_markup' => $markup ] );", "if ( $url ) mabna_bale_api()->send_photo( $s['chat_id'], $url, $caption, [ 'reply_markup' => $markup, 'remove_keyboard' => true ] );\n        else $this->send( $s['chat_id'], $caption, [ 'reply_markup' => array_merge( $markup, [ 'remove_keyboard' => true ] ) ] );",1)
p.write_text(s)
# API: preserve escaped ZWNJ sequences and literal U+200C through transport.
p=root/'includes/class-mabna-bale-api.php'; s=p.read_text()
s=s.replace("$text = str_replace( '\\\\n', \"\\n\", $text );", "$text = str_replace( [ '\\\\n', '\\\\u200c', '\\u200c' ], [ \"\\n\", \"\\u{200C}\", \"\\u{200C}\" ], $text );",1)
s=s.replace("$caption = str_replace( '\\\\n', \"\\n\", $caption );", "$caption = str_replace( [ '\\\\n', '\\\\u200c', '\\u200c' ], [ \"\\n\", \"\\u{200C}\", \"\\u{200C}\" ], $caption );",1)
p.write_text(s)
