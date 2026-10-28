<?php
defined( 'ABSPATH' ) || exit;

/** Lightweight client for the Bale Bot API. */
class Mabna_Bale_API {
    private string $token;
    private string $base = 'https://tapi.bale.ai/bot';

    public function __construct( string $token ) { $this->token = trim( $token ); }

    public function call( string $method, array $params = [] ): array {
        if ( $this->token === '' ) return [ 'ok' => false, 'description' => 'توکن ربات وارد نشده است.' ];
        $response = wp_remote_post( $this->base . rawurlencode( $this->token ) . '/' . $method, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
            'body'    => wp_json_encode( $params, JSON_UNESCAPED_UNICODE ),
        ] );
        if ( is_wp_error( $response ) ) {
            mabna_bale_log( 'api_error', [ 'method' => $method, 'error' => $response->get_error_message() ] );
            return [ 'ok' => false, 'description' => $response->get_error_message() ];
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            mabna_bale_log( 'api_error', [ 'method' => $method, 'error' => 'invalid_response', 'http_code' => wp_remote_retrieve_response_code( $response ) ] );
            return [ 'ok' => false, 'description' => 'پاسخ نامعتبر از API بله.' ];
        }
        if ( empty( $body['ok'] ) ) mabna_bale_log( 'api_error', [ 'method' => $method, 'error_code' => $body['error_code'] ?? '', 'description' => $body['description'] ?? '' ] );
        return $body;
    }

    public function send_message( string $chat_id, string $text, array $extra = [] ): array {
        $text = str_replace( [ '\\n', '\\u200c', '\u200c' ], [ "\n", "\u{200C}", "\u{200C}" ], $text );
        $result = $this->call( 'sendMessage', array_merge( [ 'chat_id' => $chat_id, 'text' => $text ], $extra ) );
        if ( empty( $result['ok'] ) ) mabna_bale_log( 'send_message_error', [ 'chat_id' => $chat_id, 'description' => $result['description'] ?? '' ] );
        return $result;
    }

    public function set_webhook( string $url ): array { return $this->call( 'setWebhook', [ 'url' => $url ] ); }
    public function delete_webhook(): array { return $this->call( 'deleteWebhook' ); }
    public function webhook_info(): array { return $this->call( 'getWebhookInfo' ); }
    public function get_me(): array { return $this->call( 'getMe' ); }
    public function get_chat_member( string $chat_id, string $user_id ): array { return $this->call( 'getChatMember', [ 'chat_id' => $chat_id, 'user_id' => $user_id ] ); }
    public function answer_callback_query( string $callback_id, string $text = '' ): array { return $this->call( 'answerCallbackQuery', [ 'callback_query_id' => $callback_id, 'text' => $text ] ); }
    public function edit_message_text( string $chat_id, string $message_id, string $text, array $extra = [] ): array { return $this->call( 'editMessageText', array_merge( [ 'chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text ], $extra ) ); }
    public function delete_message( string $chat_id, string $message_id ): array { return $this->call( 'deleteMessage', [ 'chat_id' => $chat_id, 'message_id' => $message_id ] ); }
    public function send_photo( string $chat_id, string $photo, string $caption = '', array $extra = [] ): array { $caption = str_replace( [ '\\n', '\\u200c', '\u200c' ], [ "\n", "\u{200C}", "\u{200C}" ], $caption ); return $this->call( 'sendPhoto', array_merge( [ 'chat_id'=>$chat_id, 'photo'=>$photo, 'caption'=>$caption ], $extra ) ); }
}

function mabna_bale_api(): Mabna_Bale_API {
    return new Mabna_Bale_API( (string) get_option( 'mabna_bale_token', '' ) );
}

function mabna_bale_webhook_url(): string {
    return home_url( '/wp-json/mabna-bale/v1/webhook' );
}

function mabna_bale_log( string $event, array $data = [] ): void {
    if ( class_exists( 'Mabna_Bale_Challenge_DB' ) ) Mabna_Bale_Challenge_DB::log( $event, (string) ( $data['chat_id'] ?? '' ), (int) ( $data['user_id'] ?? 0 ), $data );
    $logs = get_option( 'mabna_bale_logs', [] );
    if ( ! is_array( $logs ) ) $logs = [];
    $logs[] = [ 'time' => current_time( 'mysql' ), 'event' => $event, 'data' => $data ];
    update_option( 'mabna_bale_logs', array_slice( $logs, -500 ) );
}

function mabna_bale_text( string $key, string $fallback ): string {
    return (string) get_option( 'mabna_bale_msg_' . $key, $fallback );
}

function mabna_bale_normalize_phone( string $phone ): string {
    $phone = strtr( trim( $phone ), [ '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9' ] );
    $phone = preg_replace( '/[^0-9+]/', '', $phone );
    if ( str_starts_with( $phone, '+98' ) ) $phone = '0' . substr( $phone, 3 );
    if ( str_starts_with( $phone, '98' ) && strlen( $phone ) === 12 ) $phone = '0' . substr( $phone, 2 );
    return $phone;
}

function mabna_bale_phone_user( string $phone ): ?WP_User {
    global $wpdb;
    $phone = mabna_bale_normalize_phone( $phone );
    $keys = get_option( 'mabna_bale_phone_priority', [ 'kerasno-phone', 'phone', 'Mreeir_phone', 'billing_phone' ] );
    $keys = array_values( array_filter( array_map( 'strval', (array) $keys ) ) );
    if ( ! $keys ) return null;
    $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ($placeholders)", $keys ) );
    $matches = [];
    foreach ( (array) $rows as $row ) {
        if ( mabna_bale_normalize_phone( (string) $row->meta_value ) === $phone && ! isset( $matches[ $row->meta_key ] ) ) $matches[ $row->meta_key ] = (int) $row->user_id;
    }
    foreach ( $keys as $key ) {
        if ( empty( $matches[ $key ] ) ) continue;
        $user = get_user_by( 'id', $matches[ $key ] );
        if ( $user instanceof WP_User ) return $user;
    }
    return null;
}

function mabna_bale_is_mabna_member( int $user_id, string $phone = '' ): bool {
    $phone = mabna_bale_normalize_phone( $phone );
    if ( $user_id <= 0 && ! $phone ) return false;
    $cache_key = 'mabna_member_' . ( $user_id > 0 ? (string) $user_id : md5( $phone ) );
    $cached = wp_cache_get( $cache_key, 'mabna_bale' );
    if ( $cached !== false ) return (bool) $cached;
    global $wpdb;
    $slug = sanitize_title( (string) get_option( 'mabna_bale_product_category', 'nevisandegi' ) );
    $statuses = [ 'wc-completed', 'wc-processing', 'wc-partial-payment' ];
    $status_sql = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
    $phone_variants = array_values( array_unique( array_filter( [ $phone, ltrim( $phone, '0' ), '+98' . ltrim( $phone, '0' ), '98' . ltrim( $phone, '0' ) ] ) ) );
    $customer_clause = "(customer.meta_key='_customer_user' AND customer.meta_value=%d)";
    $args = [ $user_id ];
    if ( $phone_variants ) {
        $phone_sql = implode( ',', array_fill( 0, count( $phone_variants ), '%s' ) );
        $customer_clause .= " OR (customer.meta_key='_billing_phone' AND customer.meta_value IN ($phone_sql))";
        $args = array_merge( $args, $phone_variants );
    }
    $sql = "SELECT 1 FROM {$wpdb->posts} o
        INNER JOIN {$wpdb->postmeta} customer ON customer.post_id=o.ID AND ($customer_clause)
        INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id=o.ID AND oi.order_item_type='line_item'
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id=oi.order_item_id AND im.meta_key='_product_id'
        INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=CAST(im.meta_value AS UNSIGNED)
        INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
        INNER JOIN {$wpdb->terms} term ON term.term_id=tt.term_id AND (term.slug=%s OR term.name='نویسندگی')
        WHERE o.post_type='shop_order' AND o.post_status IN ($status_sql) LIMIT 1";
    // Placeholder order: user, billing-phone variants, category slug, order statuses.
    $args = array_merge( $args, [ $slug ], $statuses );
    $found = (bool) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
    wp_cache_set( $cache_key, $found ? 1 : 0, 'mabna_bale', DAY_IN_SECONDS );
    return $found;
}
function mabna_bale_chat_user( string $chat_id ): ?WP_User {
    global $wpdb;
    $id = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND meta_value=%s LIMIT 1", 'mabna_bale_chat_id', $chat_id ) );
    if ( ! $id ) return null;
    $user = get_user_by( 'id', (int) $id );
    return $user instanceof WP_User ? $user : null;
}

function mabna_bale_mask_phone( string $phone ): string {
    return strlen( $phone ) > 6 ? substr( $phone, 0, 4 ) . '*****' . substr( $phone, -2 ) : $phone;
}

function mabna_bale_event_in_window( array $event ): bool { $now = current_time( 'timestamp' ); $start = ! empty( $event['start'] ) ? strtotime( $event['start'] ) : 0; $end = ! empty( $event['end'] ) ? strtotime( $event['end'] ) : 0; return ( ! $start || $now >= $start ) && ( ! $end || $now <= $end ); }
function mabna_bale_event_markup( int $event_id ): array { $channel = (string) get_option( 'mabna_bale_channel', '' ); $url = $channel ? ( str_starts_with( $channel, 'http' ) ? $channel : 'https://ble.ir/' . ltrim( $channel, '@' ) ) : 'https://ble.ir/'; return [ 'inline_keyboard' => [ [ [ 'text' => '📚 کانال مدرسه نویسندگی مبنا', 'url' => $url ], [ 'text' => '🚀 شروع چالش', 'callback_data' => 'mabna_event_check_' . $event_id ] ] ] ]; }
function mabna_bale_event_member_markup( int $event_id ): array { $channel = (string) get_option( 'mabna_bale_channel', '' ); $url = $channel ? ( str_starts_with( $channel, 'http' ) ? $channel : 'https://ble.ir/' . ltrim( $channel, '@' ) ) : 'https://ble.ir/'; return [ 'inline_keyboard' => [ [ [ 'text' => '👀 از کانال ما دیدن کن!', 'url' => $url ] ] ] ]; }
