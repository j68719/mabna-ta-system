<?php

defined( 'ABSPATH' ) || exit;

/**
 * اتصال پنل استادیار به Grupo Chat.
 * برای هر تخصیص یک گروه Grupo می‌سازد و هنرجو، استادیار و ادمین منتخب را عضو می‌کند.
 */
class TA_Grupo_Chat {

    private const WEB_OPTION = 'ta_grupo_chat_web_address';
    private const SECRET_OPTION = 'ta_grupo_chat_secret_key';
    private const ADMIN_OPTION = 'ta_grupo_chat_admin_user_id';
    private const ENABLED_OPTION = 'ta_grupo_chat_enabled';
    private const GROUP_PREFIX = 'ta_grupo_assignment_';
    private const CONTENT_GROUP_PREFIX = 'ta_grupo_content_';
    private const PRODUCT_IMAGE_PREFIX = 'ta_grupo_product_image_';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
        add_action( 'admin_post_ta_grupo_chat_join', [ $this, 'handle_join' ] );
        add_action( 'admin_post_ta_grupo_create_content_groups', [ $this, 'handle_create_content_groups' ] );
        add_action( 'wp_ajax_ta_grupo_content_batch', [ $this, 'ajax_content_batch' ] );
        add_shortcode( 'grupo_chat_button', [ __CLASS__, 'shortcode' ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            'ta-system',
            'تنظیمات چت Grupo',
            'تنظیمات چت Grupo',
            'manage_options',
            'ta-grupo-chat',
            [ $this, 'render_settings' ]
        );
    }

    private static function web_address(): string {
        return trim( (string) get_option( self::WEB_OPTION, 'https://chat.mabnaschool.ir' ) );
    }

    private static function secret_key(): string {
        return trim( (string) get_option( self::SECRET_OPTION, '' ) );
    }

    private static function product_image_url( int $product_id ): string {
        $attachment_id = (int) get_option( self::PRODUCT_IMAGE_PREFIX . $product_id, 0 );
        return $attachment_id > 0 ? (string) wp_get_attachment_url( $attachment_id ) : '';
    }

    private static function update_group_icon( string $slug, int $product_id ): bool {
        $image_url = self::product_image_url( $product_id );
        if ( $slug === '' || $image_url === '' ) return false;
        $response = self::api_request( [
            'update'          => 'groups',
            'group'           => $slug,
            'group_icon_url'  => $image_url,
        ] );
        return ! is_wp_error( $response );
    }

    public static function enabled(): bool {
        return get_option( self::ENABLED_OPTION, 'yes' ) === 'yes';
    }

    private static function phone( int $user_id ): string {
        $keys = [ 'kerasno-phone', 'phone', 'Mreeir_phone', 'billing_phone' ];
        foreach ( $keys as $key ) {
            $value = get_user_meta( $user_id, $key, true );
            if ( is_scalar( $value ) && trim( (string) $value ) !== '' ) {
                return self::normalize_phone( (string) $value );
            }
        }
        return '';
    }

    private static function normalize_phone( string $phone ): string {
        $phone = str_replace(
            [ '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩' ],
            [ '0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9' ],
            trim( $phone )
        );
        return preg_replace( '/[^0-9+]/', '', $phone ) ?: '';
    }

    private static function user_payload( int $user_id ) {
        $user = get_userdata( $user_id );
        $phone = self::phone( $user_id );
        if ( ! $user || $phone === '' ) {
            return new WP_Error( 'ta_grupo_phone_missing', 'شماره همراه کاربر در فیلدهای تعیین‌شده پیدا نشد.' );
        }
        return [
            'email_address' => $user->user_email,
            'full_name'     => $user->display_name ?: $user->user_login,
            'username'      => $phone,
            'password'      => $phone,
            'avatarURL'     => get_avatar_url( $user_id ),
        ];
    }

    private static function api_request( array $data ) {
        $address = self::web_address();
        $secret  = self::secret_key();
        if ( $address === '' || $secret === '' || ! wp_http_validate_url( $address ) ) {
            return new WP_Error( 'ta_grupo_config', 'آدرس سامانه چت یا API Secret Key تنظیم نشده است.' );
        }
        $data['api_secret_key'] = $secret;
        $response = wp_remote_post( rtrim( $address, '/' ) . '/api_request/', [
            'body'        => $data,
            'timeout'     => 25,
            'redirection' => 3,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => [ 'Accept' => 'application/json' ],
        ] );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ) );
        if ( $code < 200 || $code >= 300 || ! is_object( $body ) ) {
            return new WP_Error( 'ta_grupo_bad_response', 'پاسخ معتبر از سامانه چت دریافت نشد.' );
        }
        if ( isset( $body->success ) && ! $body->success ) {
            return new WP_Error(
                'ta_grupo_api_error',
                ! empty( $body->error_message ) ? (string) $body->error_message : 'درخواست سامانه چت ناموفق بود.',
                $body
            );
        }
        return $body;
    }

    private static function ensure_account( array $payload ) {
        $response = self::api_request( array_merge( $payload, [
            'add'       => 'site_users',
            'site_role' => '',
        ] ) );
        if ( ! is_wp_error( $response ) ) return true;

        // نسخه‌های مختلف Grupo برای حساب تکراری error_key متفاوت دارند؛
        // پیام‌های واضحِ تکراری را موفقیت تلقی می‌کنیم و سپس login_session را می‌سازیم.
        $data = $response->get_error_data();
        $error_key = is_object( $data ) && isset( $data->error_key ) ? strtolower( (string) $data->error_key ) : '';
        $message = strtolower( $response->get_error_message() );
        if ( preg_match( '/exist|already|duplicate|taken|تکراری|وجود دارد/u', $error_key . ' ' . $message ) ) {
            return true;
        }
        return $response;
    }

    private static function login_user( int $user_id ) {
        $payload = self::user_payload( $user_id );
        if ( is_wp_error( $payload ) ) return $payload;

        // طبق API رسمی، ساخت حساب و ایجاد نشست دو درخواست جدا هستند.
        $account = self::ensure_account( $payload );
        if ( is_wp_error( $account ) ) return $account;
        $response = self::api_request( [
            'add'  => 'login_session',
            'user' => $payload['username'],
        ] );
        // بعضی نصب‌های Grupo حساب را با ایمیل قابل شناسایی می‌کنند، حتی اگر
        // username هنگام ساخت حساب شماره همراه بوده باشد.
        if ( is_wp_error( $response ) && ! empty( $payload['email_address'] ) ) {
            $response = self::api_request( [
                'add'  => 'login_session',
                'user' => $payload['email_address'],
            ] );
        }
        if ( is_wp_error( $response ) ) return $response;
        if ( empty( $response->auto_login_url ) || ! wp_http_validate_url( $response->auto_login_url ) ) {
            return new WP_Error( 'ta_grupo_no_login_url', 'سامانه چت لینک ورود خودکار برنگرداند.' );
        }
        return (string) $response->auto_login_url;
    }

    private static function assignment( int $assignment_id ) {
        global $wpdb;
        $a  = TA_Database::table( 'assignments' );
        $t  = TA_Database::table( 'assistants' );
        $tr = TA_Database::table( 'terms' );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.id, a.student_id, a.ta_id, a.product_id, a.term_id, a.course_type, a.status,
                    u.display_name AS student_name, ta.user_id AS ta_user_id, ta.ta_code,
                    tu.display_name AS ta_name, te.name AS term_name, p.post_title AS product_name
             FROM {$a} a
             JOIN {$t} ta ON ta.id = a.ta_id
             JOIN {$wpdb->users} u ON u.ID = a.student_id
             JOIN {$wpdb->users} tu ON tu.ID = ta.user_id
             LEFT JOIN {$tr} te ON te.id = a.term_id
             LEFT JOIN {$wpdb->posts} p ON p.ID = a.product_id
             WHERE a.id = %d LIMIT 1",
            $assignment_id
        ) );
        return $row;
    }

    private static function can_join( object $assignment ): bool {
        if ( current_user_can( 'manage_options' ) ) return true;
        if ( ! is_user_logged_in() ) return false;
        $current_id = get_current_user_id();
        return (int) $assignment->ta_user_id === $current_id || (int) $assignment->student_id === $current_id;
    }

    private static function course_letter( string $course_type ): string {
        return [
            'creative' => 'خ', 'intro' => 'م', 'advanced' => 'پ', 'professional' => 'ح',
            'critique' => 'ن', 'basic_writing' => 'ا', 'ladder' => 'ن', 'level_assess' => 'س',
        ][ $course_type ] ?? mb_substr( $course_type, 0, 1 );
    }

    private static function season_code( string $term_name ): string {
        $season_map = [ 'بهار' => 1, 'تابستان' => 2, 'پاییز' => 3, 'زمستان' => 4 ];
        preg_match( '/(\d{4})/', $term_name, $year_match );
        $year = $year_match[1] ?? '';
        foreach ( $season_map as $name => $number ) {
            if ( mb_strpos( $term_name, $name ) !== false ) return self::persian_digits( (string) $number . substr( $year, -2 ) );
        }
        return self::persian_digits( $year ? substr( $year, -2 ) : '' );
    }

    private static function persian_digits( string $value ): string {
        return str_replace( [ '0','1','2','3','4','5','6','7','8','9' ], [ '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹' ], $value );
    }

    private static function group_title( object $assignment ): string {
        $parts = preg_split( '/\s+/', trim( (string) $assignment->ta_name ), 2 );
        $name = trim( ( $parts[0] ?? '' ) . ' ' . ( $parts[1] ?? '' ) );
        $course_name = trim( (string) ( $assignment->product_name ?? '' ) );
        if ( $course_name === '' ) {
            $course_name = trim( (string) $assignment->course_type );
        }
        $base = trim( $name . ' _ ' . $course_name . ' ' . (string) $assignment->term_name );
        // شناسه هنرجو برای جلوگیری از هم‌نامی گروه‌های اختصاصی در Grupo اضافه می‌شود.
        return trim( $base . ' — ' . (string) $assignment->student_name );
    }

    private static function option_key( int $assignment_id ): string {
        return self::GROUP_PREFIX . $assignment_id;
    }

    private static function group_for_assignment( object $assignment ) {
        $key = self::option_key( (int) $assignment->id );
        $stored = get_option( $key, [] );

        // اسلاگ ثابت است، اما حتی اگر قبلاً در option ذخیره شده باشد، دوباره
        // درخواست ساخت/بررسی می‌فرستیم تا گروهی که حذف یا ناقص شده بازیابی شود.
        $title = self::group_title( $assignment );
        $slug = is_array( $stored ) && ! empty( $stored['slug'] )
            ? sanitize_title( (string) $stored['slug'] )
            : sanitize_title( 'ta-assignment-' . (int) $assignment->id . '-' . $assignment->student_id );
        $title = mb_substr( $title, 0, 180 );
        $group_request = [
            'add'             => 'groups',
            'group_name'      => $title,
            'description'     => 'گروه اختصاصی ' . $assignment->student_name . ' و ' . $assignment->ta_name,
            'slug'             => $slug,
            'secret_group'    => 'yes',
            'unleavable'      => 'no',
            'pin_group'       => 'no',
            'password_protect'=> 'no',
        ];
        $assignment_image = self::product_image_url( (int) $assignment->product_id );
        if ( $assignment_image !== '' ) $group_request['group_icon_url'] = $assignment_image;
        $response = self::api_request( $group_request );
        if ( is_wp_error( $response ) ) {
            $data = $response->get_error_data();
            $error_key = is_object( $data ) && isset( $data->error_key ) ? (string) $data->error_key : '';
            $duplicate_keys = [ 'group_exists', 'slug_exists', 'group_slug_exists', 'already_exists' ];
            $message = strtolower( $response->get_error_message() );
            if ( ! in_array( $error_key, $duplicate_keys, true ) && ! preg_match( '/exist|already|duplicate|تکراری|وجود دارد/u', $message ) ) {
                return new WP_Error( 'ta_grupo_group_create', 'ساخت گروه تخصیص ناموفق بود' . ( $error_key ? ' [' . $error_key . ']' : '' ) . ': ' . $response->get_error_message(), $data );
            }
        }
        $stored = [ 'slug' => $slug, 'name' => $title, 'created_at' => current_time( 'mysql' ) ];
        update_option( $key, $stored, false );
        self::update_group_icon( $slug, (int) $assignment->product_id );
        return $stored;
    }

    private static function add_member( int $assignment_id, array $group, int $user_id, string $role = 'کاربر' ) {
        $members_key = 'ta_grupo_members_' . md5( (string) $group['slug'] );
        $members = get_option( $members_key, [] );
        if ( ! is_array( $members ) ) $members = [];
        $payload = self::user_payload( $user_id );
        if ( is_wp_error( $payload ) ) return $payload;
        $request = [
            'add'        => 'group_members',
            'group'      => $group['slug'],
            'user'       => $payload['username'],
            'group_role' => '',
        ];
        $response = self::api_request( $request );
        $last_error = $response;
        if ( is_wp_error( $response ) ) {
            $data = $response->get_error_data();
            $key = is_object( $data ) && isset( $data->error_key ) ? (string) $data->error_key : '';
            $message = strtolower( $response->get_error_message() );
            $is_missing = preg_match( '/not.?exist|no.?user|user.?not|account.?not|حساب.*وجود|کاربر.*وجود/u', strtolower( $key . ' ' . $message ) );
            if ( $is_missing ) {
                // ممکن است ساخت حساب در درخواست قبلی با تأخیر پردازش شده باشد.
                // یک بار دیگر ساخت رسمی حساب و سپس عضویت را امتحان می‌کنیم.
                $account = self::ensure_account( $payload );
                if ( ! is_wp_error( $account ) ) {
                    $response = self::api_request( $request );
                    $last_error = $response;
                    if ( is_wp_error( $response ) && ! empty( $payload['email_address'] ) ) {
                        $request['user'] = $payload['email_address'];
                        $response = self::api_request( $request );
                    }
                }
            }
            if ( is_wp_error( $response ) ) {
                $data = $response->get_error_data();
                $key = is_object( $data ) && isset( $data->error_key ) ? (string) $data->error_key : '';
                if ( ! in_array( $key, [ 'already_member', 'user_already_member', 'member_exists' ], true ) ) {
                    // ساخت گروه و آماده‌شدن ایندکس اعضا ممکن است کمی زمان ببرد.
                    // یک تلاش مجدد واقعی انجام می‌دهیم و به کش عضویت اعتماد نمی‌کنیم.
                    usleep( 800000 );
                    $retry = self::api_request( $request );
                    if ( ! is_wp_error( $retry ) ) {
                        $response = $retry;
                    } else {
                        $retry_data = $retry->get_error_data();
                        $retry_key = is_object( $retry_data ) && isset( $retry_data->error_key ) ? (string) $retry_data->error_key : '';
                        if ( in_array( $retry_key, [ 'already_member', 'user_already_member', 'member_exists' ], true ) ) {
                            $response = $retry;
                        } else {
                            $data = $retry_data;
                            $response = $retry;
                        }
                    }
                }
                if ( is_wp_error( $response ) ) {
                    $data = $response->get_error_data();
                    $key = is_object( $data ) && isset( $data->error_key ) ? (string ) $data->error_key : '';
                    if ( ! in_array( $key, [ 'already_member', 'user_already_member', 'member_exists' ], true ) ) {
                    $detail = $key !== '' ? ' [' . $key . ']' : '';
                    return new WP_Error( 'ta_grupo_member_' . $user_id, 'افزودن ' . $role . ' به گروه ناموفق بود' . $detail . ': ' . $response->get_error_message(), $data );
                    }
                }
            }
        }
        $members[ $user_id ] = current_time( 'mysql' );
        update_option( $members_key, $members, false );
        return true;
    }

    private static function join_assignment( object $assignment ) {
        // حساب هر سه نقش پیش از افزودن به گروه ساخته/همگام می‌شود.
        $actor_url = self::login_user( (int) $assignment->ta_user_id );
        if ( is_wp_error( $actor_url ) ) return $actor_url;

        // قبلاً فقط استادیار با login_session ساخته می‌شد و برای هنرجو
        // مستقیماً درخواست group_members ارسال می‌گردید؛ در این حالت Grupo
        // برای هنرجویی که حساب نداشت خطای «حساب وجود ندارد» برمی‌گرداند.
        $student_login = self::login_user( (int) $assignment->student_id );
        if ( is_wp_error( $student_login ) ) return $student_login;

        $group = self::group_for_assignment( $assignment );
        if ( is_wp_error( $group ) ) return $group;

        // فرصت کوتاه برای نهایی‌شدن ساخت گروه در Grupo پیش از group_members.
        usleep( 800000 );

        foreach ( [ (int) $assignment->student_id, (int) $assignment->ta_user_id ] as $member_id ) {
            $member_role = $member_id === (int) $assignment->ta_user_id ? 'استادیار' : 'هنرجو';
            $result = self::add_member( (int) $assignment->id, $group, $member_id, $member_role );
            if ( is_wp_error( $result ) ) return $result;
        }
        $admin_id = (int) get_option( self::ADMIN_OPTION, 0 );
        if ( $admin_id > 0 && $admin_id !== (int) $assignment->student_id && $admin_id !== (int) $assignment->ta_user_id ) {
            $admin_login = self::login_user( $admin_id );
            if ( is_wp_error( $admin_login ) ) return $admin_login;
            $result = self::add_member( (int) $assignment->id, $group, $admin_id );
            if ( is_wp_error( $result ) ) return $result;
        }
        return $actor_url;
    }

    private static function content_group( int $product_id, int $term_id, string $product_name, string $term_name ) {
        $option_key = self::CONTENT_GROUP_PREFIX . $product_id . '_' . $term_id;
        $slug = sanitize_title( 'content-' . $product_id . '-' . $term_id );
        $title = trim( 'محتوا _ ' . $product_name . ' ' . $term_name );
        $group_request = [
            'add'              => 'groups',
            'group_name'       => mb_substr( $title, 0, 180 ),
            'description'      => 'گروه محتوای ' . $product_name . ' ' . $term_name,
            'slug'             => $slug,
            'secret_group'     => 'yes',
            'unleavable'       => 'no',
            'pin_group'        => 'no',
            'password_protect' => 'no',
        ];
        $content_image = self::product_image_url( $product_id );
        if ( $content_image !== '' ) $group_request['group_icon_url'] = $content_image;
        $response = self::api_request( $group_request );
        if ( is_wp_error( $response ) ) {
            $data = $response->get_error_data();
            $key = is_object( $data ) && isset( $data->error_key ) ? (string) $data->error_key : '';
            if ( ! preg_match( '/exist|already|duplicate|تکراری|وجود دارد/u', strtolower( $key . ' ' . $response->get_error_message() ) ) ) {
                return new WP_Error( 'ta_grupo_content_group', 'ساخت گروه محتوا ناموفق بود: ' . $response->get_error_message(), $data );
            }
        }
        $stored = [ 'slug' => $slug, 'name' => $title, 'product_id' => $product_id, 'term_id' => $term_id ];
        update_option( $option_key, $stored, false );
        self::update_group_icon( $slug, $product_id );
        return $stored;
    }

    private static function create_content_groups() {
        global $wpdb;
        $assign = TA_Database::table( 'assignments' );
        $assist = TA_Database::table( 'assistants' );
        $terms = TA_Database::table( 'terms' );
        $rows = $wpdb->get_results(
            "SELECT a.product_id, a.term_id, a.student_id, ta.user_id AS ta_user_id,
                    p.post_title AS product_name, t.name AS term_name
             FROM {$assign} a
             JOIN {$assist} ta ON ta.id = a.ta_id
             LEFT JOIN {$wpdb->posts} p ON p.ID = a.product_id
             LEFT JOIN {$terms} t ON t.id = a.term_id
             WHERE a.product_id > 0 AND a.status <> 'canceled'
             ORDER BY a.product_id, a.term_id, a.id"
        ) ?: [];
        $groups = [];
        foreach ( $rows as $row ) {
            $key = (int) $row->product_id . ':' . (int) $row->term_id;
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = [
                    'product_id' => (int) $row->product_id,
                    'term_id' => (int) $row->term_id,
                    'product_name' => (string) ( $row->product_name ?: 'دوره' ),
                    'term_name' => (string) ( $row->term_name ?: '' ),
                    'users' => [],
                ];
            }
            $groups[ $key ]['users'][(int) $row->student_id] = 'هنرجو';
            $groups[ $key ]['users'][(int) $row->ta_user_id] = 'استادیار';
        }
        $admin_id = (int) get_option( self::ADMIN_OPTION, 0 );
        $created = 0;
        $added = 0;
        $errors = [];
        foreach ( $groups as $group_data ) {
            $group = self::content_group( $group_data['product_id'], $group_data['term_id'], $group_data['product_name'], $group_data['term_name'] );
            if ( is_wp_error( $group ) ) {
                $errors[] = $group->get_error_message();
                continue;
            }
            $created++;
            if ( $admin_id > 0 ) $group_data['users'][ $admin_id ] = 'ادمین';
            foreach ( $group_data['users'] as $user_id => $role ) {
                $login = self::login_user( (int) $user_id );
                if ( is_wp_error( $login ) ) {
                    $errors[] = $group_data['product_name'] . ': ساخت حساب ' . $role . ' ناموفق بود: ' . $login->get_error_message();
                    continue;
                }
                $result = self::add_member( 0, $group, (int) $user_id, $role );
                if ( is_wp_error( $result ) ) $errors[] = $group_data['product_name'] . ': ' . $result->get_error_message();
                else $added++;
            }
        }
        return compact( 'created', 'added', 'errors' );
    }

    private static function content_group_jobs( int $product_filter = 0 ): array {
        global $wpdb;
        $assign = TA_Database::table( 'assignments' );
        $assist = TA_Database::table( 'assistants' );
        $terms = TA_Database::table( 'terms' );
        $rows = $wpdb->get_results( "SELECT a.product_id, a.term_id, a.student_id, ta.user_id AS ta_user_id,
                p.post_title AS product_name, t.name AS term_name
                FROM {$assign} a JOIN {$assist} ta ON ta.id = a.ta_id
                LEFT JOIN {$wpdb->posts} p ON p.ID = a.product_id
                LEFT JOIN {$terms} t ON t.id = a.term_id
                WHERE a.product_id > 0 AND a.status <> 'canceled' " . ( $product_filter > 0 ? $wpdb->prepare( 'AND a.product_id = %d', $product_filter ) : '' ) . "
                ORDER BY a.product_id, a.term_id, a.id" ) ?: [];
        $jobs = [];
        foreach ( $rows as $row ) {
            $key = (int) $row->product_id . ':' . (int) $row->term_id;
            if ( ! isset( $jobs[ $key ] ) ) $jobs[ $key ] = [
                'product_id' => (int) $row->product_id, 'term_id' => (int) $row->term_id,
                'product_name' => (string) ( $row->product_name ?: 'دوره' ),
                'term_name' => (string) ( $row->term_name ?: '' ), 'users' => [],
            ];
            $jobs[ $key ]['users'][(int) $row->student_id] = 'هنرجو';
            $jobs[ $key ]['users'][(int) $row->ta_user_id] = 'استادیار';
        }
        $admin_id = (int) get_option( self::ADMIN_OPTION, 0 );
        foreach ( $jobs as &$job ) if ( $admin_id > 0 ) $job['users'][ $admin_id ] = 'ادمین';
        unset( $job );
        return array_values( $jobs );
    }

    private static function process_content_batch( array &$state ): bool {
        if ( ! isset( $state['jobs'][ $state['job_index'] ] ) ) return true;
        $job =& $state['jobs'][ $state['job_index'] ];
        if ( empty( $job['group'] ) ) {
            $group = self::content_group( $job['product_id'], $job['term_id'], $job['product_name'], $job['term_name'] );
            if ( is_wp_error( $group ) ) { $state['errors'][] = $group->get_error_message(); $state['job_index']++; return false; }
            $job['group'] = $group;
            $state['created']++;
        }
        $user_ids = array_keys( $job['users'] );
        $processed = 0;
        while ( $job['user_index'] < count( $user_ids ) && $processed < 1 ) {
            $user_id = (int) $user_ids[ $job['user_index'] ];
            $role = (string) $job['users'][ $user_id ];
            $login = self::login_user( $user_id );
            if ( is_wp_error( $login ) ) $state['errors'][] = $job['product_name'] . ': ساخت حساب ' . $role . ' ناموفق بود: ' . $login->get_error_message();
            else {
                $result = self::add_member( 0, $job['group'], $user_id, $role );
                if ( is_wp_error( $result ) ) $state['errors'][] = $job['product_name'] . ': ' . $result->get_error_message();
                else $state['added']++;
            }
            $job['user_index']++; $processed++;
        }
        if ( $job['user_index'] >= count( $user_ids ) ) $state['job_index']++;
        return ! isset( $state['jobs'][ $state['job_index'] ] );
    }

    public function handle_create_content_groups(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی مجاز نیست.', 'خطا', [ 'response' => 403 ] );
        $token = sanitize_key( $_GET['ta_batch'] ?? '' );
        $key = 'ta_grupo_batch_' . get_current_user_id() . '_' . $token;
        $state = $token ? get_transient( $key ) : false;
        // nonce فقط برای شروع عملیات فرم لازم است. ادامه مراحل با transient
        // اختصاصی مدیر اعتبارسنجی می‌شود تا لینک مرحله بعد منقضی نشود.
        if ( ! $token ) check_admin_referer( 'ta_grupo_create_content_groups' );
        if ( $token && ! is_array( $state ) ) wp_die( 'مرحله پردازش پیدا نشد یا منقضی شده است. دوباره عملیات را شروع کنید.', 'خطا', [ 'response' => 400 ] );
        if ( ! is_array( $state ) ) {
            $token = sanitize_key( wp_generate_password( 20, false, false ) );
            $key = 'ta_grupo_batch_' . get_current_user_id() . '_' . $token;
            $state = [ 'jobs' => self::content_group_jobs(), 'job_index' => 0, 'created' => 0, 'added' => 0, 'errors' => [] ];
        }
        $done = self::process_content_batch( $state );
        if ( ! $done ) {
            set_transient( $key, $state, HOUR_IN_SECONDS );
            $next = add_query_arg( [ 'action' => 'ta_grupo_create_content_groups', 'ta_batch' => $token ], admin_url( 'admin-post.php' ) );
            wp_safe_redirect( wp_nonce_url( $next, 'ta_grupo_create_content_groups' ) );
            exit;
        }
        $notice = sprintf( 'گروه‌های محتوا: %d گروه بررسی/ایجاد شد و %d عضویت ثبت شد.', (int) $state['created'], (int) $state['added'] );
        if ( ! empty( $state['errors'] ) ) $notice .= ' خطاها: ' . implode( ' | ', array_slice( $state['errors'], 0, 3 ) );
        set_transient( 'ta_grupo_content_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
        delete_transient( $key );
        wp_safe_redirect( admin_url( 'admin.php?page=ta-grupo-chat&ta_content_done=1' ) );
        exit;
    }

    public function ajax_content_batch(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'دسترسی مجاز نیست.' ], 403 );
        check_ajax_referer( 'ta_grupo_content_groups_ajax', 'nonce' );
        $mode = sanitize_key( $_POST['mode'] ?? 'groups' );
        $product_id = absint( $_POST['product_id'] ?? 0 );
        if ( in_array( $mode, [ 'assignment_icon', 'content_icon', 'all_icon' ], true ) ) {
            if ( $product_id < 1 ) wp_send_json_error( [ 'message' => 'محصول انتخاب نشده است.' ], 400 );
            $scope = $mode === 'assignment_icon' ? 'assignment' : ( $mode === 'content_icon' ? 'content' : 'all' );
            wp_send_json_success( [ 'done' => true, 'updated' => self::sync_product_icons( $product_id, $scope ) ] );
        }
        $token = sanitize_key( $_POST['token'] ?? '' );
        $key = 'ta_grupo_batch_' . get_current_user_id() . '_' . $token;
        $state = $token ? get_transient( $key ) : false;
        if ( ! is_array( $state ) ) {
            $token = sanitize_key( wp_generate_password( 20, false, false ) );
            $key = 'ta_grupo_batch_' . get_current_user_id() . '_' . $token;
            $state = [ 'jobs' => self::content_group_jobs( $product_id ), 'job_index' => 0, 'created' => 0, 'added' => 0, 'errors' => [] ];
        }
        $total = count( $state['jobs'] );
        $done = self::process_content_batch( $state );
        if ( ! $done ) {
            set_transient( $key, $state, HOUR_IN_SECONDS );
            wp_send_json_success( [ 'done' => false, 'token' => $token, 'current' => (int) $state['job_index'], 'total' => $total ] );
        }
        delete_transient( $key );
        wp_send_json_success( [ 'done' => true, 'created' => (int) $state['created'], 'added' => (int) $state['added'], 'errors' => array_slice( $state['errors'], 0, 5 ) ] );
    }

    private static function sync_existing_product_icons(): void {
        global $wpdb;
        $assign = TA_Database::table( 'assignments' );
        $rows = $wpdb->get_results( "SELECT id, product_id FROM {$assign} WHERE product_id > 0 AND status <> 'canceled'" ) ?: [];
        foreach ( $rows as $row ) {
            $stored = get_option( self::option_key( (int) $row->id ), [] );
            if ( is_array( $stored ) && ! empty( $stored['slug'] ) ) self::update_group_icon( (string) $stored['slug'], (int) $row->product_id );
        }
        $pairs = $wpdb->get_results( "SELECT DISTINCT product_id, term_id FROM {$assign} WHERE product_id > 0 AND status <> 'canceled'" ) ?: [];
        foreach ( $pairs as $pair ) {
            $stored = get_option( self::CONTENT_GROUP_PREFIX . (int) $pair->product_id . '_' . (int) $pair->term_id, [] );
            if ( is_array( $stored ) && ! empty( $stored['slug'] ) ) self::update_group_icon( (string) $stored['slug'], (int) $pair->product_id );
        }
    }

    private static function sync_product_icons( int $product_id, string $scope ): int {
        global $wpdb;
        $count = 0;
        $assign = TA_Database::table( 'assignments' );
        if ( in_array( $scope, [ 'assignment', 'all' ], true ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$assign} WHERE product_id = %d AND status <> 'canceled'", $product_id ) ) ?: [];
            foreach ( $rows as $row ) {
                $stored = get_option( self::option_key( (int) $row->id ), [] );
                if ( is_array( $stored ) && ! empty( $stored['slug'] ) && self::product_image_url( $product_id ) !== '' ) {
                    if ( self::update_group_icon( (string) $stored['slug'], $product_id ) ) $count++;
                }
            }
        }
        if ( in_array( $scope, [ 'content', 'all' ], true ) ) {
            $pairs = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT term_id FROM {$assign} WHERE product_id = %d AND status <> 'canceled'", $product_id ) ) ?: [];
            foreach ( $pairs as $pair ) {
                $stored = get_option( self::CONTENT_GROUP_PREFIX . $product_id . '_' . (int) $pair->term_id, [] );
                if ( is_array( $stored ) && ! empty( $stored['slug'] ) && self::product_image_url( $product_id ) !== '' ) {
                    if ( self::update_group_icon( (string) $stored['slug'], $product_id ) ) $count++;
                }
            }
        }
        return $count;
    }

    public static function shortcode( $atts = [] ): string {
        if ( ! self::enabled() ) return '';
        if ( ! is_user_logged_in() ) return '<p class="ta-grupo-login-required">برای ورود به گروه چت ابتدا وارد سایت شوید.</p>';
        global $wpdb;
        $table = TA_Database::table( 'assignments' );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.status, a.course_type, a.term_id, a.product_id,
                    t.name AS term_name, p.post_title AS product_name
             FROM {$table} a
             LEFT JOIN " . TA_Database::table( 'terms' ) . " t ON t.id = a.term_id
             LEFT JOIN {$wpdb->posts} p ON p.ID = a.product_id
             WHERE a.student_id = %d AND a.status <> 'canceled'
             ORDER BY a.id DESC",
            get_current_user_id()
        ) ) ?: [];
        if ( empty( $rows ) ) return '<p class="ta-grupo-empty">گروه چتی برای شما ثبت نشده است.</p>';
        $html = '<div class="ta-grupo-student-groups" dir="rtl">';
        foreach ( $rows as $row ) {
            $label = trim( (string) ( $row->product_name ?: 'دوره' ) . ' — ' . (string) ( $row->term_name ?: '' ) );
            $html .= '<a class="button ta-grupo-student-group" target="_blank" rel="noopener" href="' . esc_url( self::build_join_url( (int) $row->id ) ) . '">💬 ورود به گروه ' . esc_html( $label ) . '</a> ';
        }
        return $html . '</div>';
    }

    public static function build_join_url( int $assignment_id ): string {
        return wp_nonce_url(
            add_query_arg( [ 'action' => 'ta_grupo_chat_join', 'assignment_id' => $assignment_id ], admin_url( 'admin-post.php' ) ),
            'ta_grupo_chat_join_' . $assignment_id
        );
    }

    public function handle_join(): void {
        if ( ! self::enabled() ) wp_die( 'ورود به چت در تنظیمات افزونه غیرفعال است.', 'قابلیت غیرفعال', [ 'response' => 403 ] );
        $assignment_id = absint( $_GET['assignment_id'] ?? 0 );
        $assignment = self::assignment( $assignment_id );
        if ( ! $assignment || ! self::can_join( $assignment ) ) wp_die( 'دسترسی به این تخصیص مجاز نیست.', 'خطا', [ 'response' => 403 ] );
        check_admin_referer( 'ta_grupo_chat_join_' . $assignment_id );
        $url = self::join_assignment( $assignment );
        if ( is_wp_error( $url ) ) {
            $detail = $url->get_error_message();
            $data = $url->get_error_data();
            if ( is_object( $data ) && ! empty( $data->error_key ) ) $detail .= ' [' . sanitize_key( (string) $data->error_key ) . ']';
            wp_die( esc_html( $detail ), 'خطا در ورود به چت', [ 'response' => 502 ] );
        }
        // همه اعضا همگام شدند؛ لینک نهایی باید برای همان کاربری باشد که کلیک کرده است.
        $current_user_url = self::login_user( get_current_user_id() );
        if ( is_wp_error( $current_user_url ) ) {
            wp_die( esc_html( $current_user_url->get_error_message() ), 'خطا در ورود به چت', [ 'response' => 502 ] );
        }
        wp_redirect( esc_url_raw( $current_user_url ) );
        exit;
    }

    public function render_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( isset( $_POST['ta_grupo_save'] ) ) {
            check_admin_referer( 'ta_grupo_save_settings' );
            update_option( self::WEB_OPTION, esc_url_raw( wp_unslash( $_POST['web_address'] ?? '' ) ) );
            update_option( self::SECRET_OPTION, sanitize_text_field( wp_unslash( $_POST['secret_key'] ?? '' ) ) );
            update_option( self::ADMIN_OPTION, absint( $_POST['admin_user_id'] ?? 0 ) );
            update_option( self::ENABLED_OPTION, isset( $_POST['enabled'] ) && $_POST['enabled'] === 'yes' ? 'yes' : 'no' );
            foreach ( (array) ( $_POST['product_image'] ?? [] ) as $product_id => $attachment_id ) {
                update_option( self::PRODUCT_IMAGE_PREFIX . absint( $product_id ), absint( $attachment_id ) );
            }
            self::sync_existing_product_icons();
            echo '<div class="notice notice-success"><p>تنظیمات چت ذخیره شد.</p></div>';
        }
        wp_enqueue_media();
        $admin_id = (int) get_option( self::ADMIN_OPTION, 0 );
        $enabled = self::enabled();
        $admins = get_users( [ 'role__in' => [ 'administrator' ], 'orderby' => 'display_name', 'order' => 'ASC' ] );
        global $wpdb;
        $products = $wpdb->get_results( "SELECT DISTINCT a.product_id, p.post_title FROM " . TA_Database::table( 'assignments' ) . " a JOIN {$wpdb->posts} p ON p.ID = a.product_id WHERE a.product_id > 0 AND a.status <> 'canceled' ORDER BY p.post_title" ) ?: [];
        $content_notice = get_transient( 'ta_grupo_content_notice_' . get_current_user_id() );
        if ( $content_notice ) delete_transient( 'ta_grupo_content_notice_' . get_current_user_id() );
        ?>
        <div class="wrap" dir="rtl">
            <h1>تنظیمات چت Grupo</h1>
            <?php if ( $content_notice ) : ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html( $content_notice ); ?></p></div><?php endif; ?>
            <p>در پنل استادیار، دکمه «ورود به چت» کنار هر تخصیص نمایش داده می‌شود. با اولین ورود، گروه ساخته و هنرجو، استادیار و ادمین منتخب عضو می‌شوند.</p>
            <form method="post">
                <?php wp_nonce_field( 'ta_grupo_save_settings' ); ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="ta_grupo_enabled">اتصال چت Grupo</label></th><td><label><input type="checkbox" id="ta_grupo_enabled" name="enabled" value="yes" <?php checked( $enabled, true ); ?>> فعال باشد</label><p class="description">در حالت غیرفعال، ستون و دکمه «ورود به چت» از پنل استادیار مخفی می‌شود و لینک‌های قبلی نیز قابل استفاده نخواهند بود.</p></td></tr>
                    <tr><th><label for="ta_grupo_web_address">آدرس سامانه چت</label></th><td><input class="regular-text" type="url" id="ta_grupo_web_address" name="web_address" value="<?php echo esc_attr( self::web_address() ); ?>" required></td></tr>
                    <tr><th><label for="ta_grupo_secret_key">API Secret Key</label></th><td><input class="regular-text" type="password" id="ta_grupo_secret_key" name="secret_key" value="<?php echo esc_attr( self::secret_key() ); ?>" required autocomplete="off"></td></tr>
                    <tr><th><label for="ta_grupo_admin_user_id">ادمین عضو گروه</label></th><td><select id="ta_grupo_admin_user_id" name="admin_user_id"><option value="0">— بدون ادمین خودکار —</option><?php foreach ( $admins as $admin ) : ?><option value="<?php echo (int) $admin->ID; ?>" <?php selected( $admin_id, $admin->ID ); ?>><?php echo esc_html( $admin->display_name . ' — ' . $admin->user_email ); ?></option><?php endforeach; ?></select><p class="description">در اولین ورود به هر گروه، این ادمین نیز به‌صورت خودکار حساب‌سازی و عضو گروه می‌شود.</p></td></tr>
                    <?php foreach ( $products as $product ) : $image_id = (int) get_option( self::PRODUCT_IMAGE_PREFIX . (int) $product->product_id, 0 ); $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : ''; ?>
                    <tr><th><?php echo esc_html( $product->post_title ); ?><br><span class="description">تصویر همه گروه‌های این محصول</span></th><td>
                        <input type="hidden" name="product_image[<?php echo (int) $product->product_id; ?>]" class="ta-product-image-id" value="<?php echo (int) $image_id; ?>">
                        <img class="ta-product-image-preview" src="<?php echo esc_url( $image_url ?: '' ); ?>" style="<?php echo $image_url ? '' : 'display:none;'; ?>width:70px;height:70px;object-fit:cover;border-radius:8px;vertical-align:middle;margin-left:8px">
                        <button type="button" class="button ta-select-product-image">انتخاب تصویر</button>
                        <button type="button" class="button ta-remove-product-image" style="<?php echo $image_id ? '' : 'display:none;'; ?>">حذف</button>
                    </td></tr>
                    <?php endforeach; ?>
                </table>
                <p><button type="submit" name="ta_grupo_save" class="button button-primary">ذخیره تنظیمات</button></p>
            </form>
            <hr>
            <h2>مدیریت گروه‌های محصولات</h2>
            <p>هر ردیف یک محصول است. دکمه‌های تصویر، تصویر همه گروه‌های همان محصول را در Grupo بروزرسانی می‌کنند.</p>
            <p>
                <button type="button" class="button ta-grupo-all-icon" data-scope="assignment">همگام‌سازی تصویر همه گروه‌های هنرجوها</button>
                <button type="button" class="button ta-grupo-all-icon" data-scope="content">همگام‌سازی تصویر همه گروه‌های محتوا</button>
                <span class="ta-grupo-global-status" style="margin-right:8px"></span>
            </p>
            <table class="widefat striped" style="max-width:1100px">
                <thead><tr><th>محصول</th><th>گروه‌ها و عملیات</th><th>وضعیت</th></tr></thead>
                <tbody>
                <?php foreach ( $products as $product ) : ?>
                    <tr data-product-id="<?php echo (int) $product->product_id; ?>">
                        <td><strong><?php echo esc_html( $product->post_title ); ?></strong></td>
                        <td>
                            <button type="button" class="button button-primary ta-product-groups" data-product-id="<?php echo (int) $product->product_id; ?>">ایجاد و همگام‌سازی گروه‌های این محصول</button>
                            <button type="button" class="button ta-product-icon" data-mode="assignment_icon" data-product-id="<?php echo (int) $product->product_id; ?>">بروزرسانی تصویر گروه‌های هنرجوها</button>
                            <button type="button" class="button ta-product-icon" data-mode="content_icon" data-product-id="<?php echo (int) $product->product_id; ?>">بروزرسانی تصویر گروه محتوا</button>
                        </td>
                        <td class="ta-product-status" style="min-width:180px">—</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <hr>
            <h2>گروه‌های محتوای دوره‌ها</h2>
            <p>برای هر ترکیب محصول و ترم، یک گروه مخفی با پیشوند «محتوا» ساخته می‌شود. همه هنرجویان و استادیاران دارای تخصیص در آن دوره و ادمین منتخب به گروه اضافه می‌شوند.</p>
            <form id="ta-grupo-content-groups-form" method="post" action="#">
                <p><button type="submit" id="ta-grupo-content-groups-button" class="button button-secondary">ایجاد و همگام‌سازی گروه‌های محصولات</button></p>
                <div id="ta-grupo-content-groups-progress" style="display:none;max-width:620px;padding:10px;background:#f0f6fc;border-right:4px solid #2271b1"></div>
            </form>
            <script>
            jQuery(function($){
                $(document).on('click','.ta-select-product-image',function(e){
                    e.preventDefault();
                    var row=$(this).closest('td'), frame=wp.media({title:'انتخاب تصویر گروه',button:{text:'استفاده از تصویر'},multiple:false});
                    frame.on('select',function(){var a=frame.state().get('selection').first().toJSON();row.find('.ta-product-image-id').val(a.id);row.find('.ta-product-image-preview').attr('src',a.url).show();row.find('.ta-remove-product-image').show();});
                    frame.open();
                });
                $(document).on('click','.ta-remove-product-image',function(){var row=$(this).closest('td');row.find('.ta-product-image-id').val('0');row.find('.ta-product-image-preview').hide().attr('src','');$(this).hide();});
                $('#ta-grupo-content-groups-form').on('submit',function(e){
                    e.preventDefault();
                    var btn=$('#ta-grupo-content-groups-button'), box=$('#ta-grupo-content-groups-progress'), token='';
                    btn.prop('disabled',true).text('در حال پردازش...'); box.show().text('شروع همگام‌سازی؛ لطفاً این صفحه را نبندید.');
                    function run(){
                        $.post(ajaxurl,{action:'ta_grupo_content_batch',nonce:'<?php echo esc_js( wp_create_nonce( 'ta_grupo_content_groups_ajax' ) ); ?>',token:token})
                        .done(function(r){
                            if(!r.success){box.text('خطا: '+(r.data&&r.data.message?r.data.message:'پردازش ناموفق بود.'));btn.prop('disabled',false).text('تلاش دوباره');return;}
                            if(!r.data.done){token=r.data.token;box.text('در حال پردازش گروه '+(r.data.current+1)+' از '+r.data.total+' ...');run();return;}
                            var msg='همگام‌سازی پایان یافت. '+r.data.created+' گروه بررسی/ایجاد و '+r.data.added+' عضویت ثبت شد.';
                            if(r.data.errors&&r.data.errors.length)msg+=' خطاها: '+r.data.errors.join(' | ');
                            box.text(msg);btn.prop('disabled',false).text('اجرای دوباره همگام‌سازی');
                        }).fail(function(){box.text('ارتباط با سرور قطع شد؛ دوباره تلاش کنید.');btn.prop('disabled',false).text('تلاش دوباره');});
                    } run();
                });
                var ajaxNonce='<?php echo esc_js( wp_create_nonce( 'ta_grupo_content_groups_ajax' ) ); ?>';
                function productGroups(id,row){
                    var token='',status=row.find('.ta-product-status'),button=row.find('.ta-product-groups');
                    button.prop('disabled',true); status.text('شروع...');
                    function next(){ $.post(ajaxurl,{action:'ta_grupo_content_batch',nonce:ajaxNonce,token:token,product_id:id,mode:'groups'}).done(function(r){
                        if(!r.success){status.text('خطا: '+(r.data&&r.data.message?r.data.message:'ناموفق'));button.prop('disabled',false);return;}
                        if(!r.data.done){token=r.data.token;status.text('در حال پردازش گروه '+(r.data.current+1)+' از '+r.data.total);next();}
                        else {status.text('انجام شد: '+r.data.created+' گروه، '+r.data.added+' عضویت');button.prop('disabled',false);}
                    }).fail(function(){status.text('ارتباط با سرور قطع شد');button.prop('disabled',false);}); } next();
                }
                $(document).on('click','.ta-product-groups',function(){var b=$(this),r=b.closest('tr');productGroups(b.data('product-id'),r);});
                $(document).on('click','.ta-product-icon',function(){var b=$(this),r=b.closest('tr');b.prop('disabled',true);r.find('.ta-product-status').text('در حال بروزرسانی تصویر...');$.post(ajaxurl,{action:'ta_grupo_content_batch',nonce:ajaxNonce,mode:b.data('mode'),product_id:b.data('product-id')}).done(function(x){r.find('.ta-product-status').text(x.success?'تصویر '+x.data.updated+' گروه بروزرسانی شد':'خطا: '+x.data.message);}).fail(function(){r.find('.ta-product-status').text('ارتباط با سرور قطع شد');}).always(function(){b.prop('disabled',false);});});
                $(document).on('click','.ta-grupo-all-icon',function(){var b=$(this),scope=b.data('scope'),ids=[];$('.ta-product-icon[data-mode="'+scope+'_icon"]').each(function(){ids.push($(this).data('product-id'));});b.prop('disabled',true);$('.ta-grupo-global-status').text('در حال پردازش ۰ از '+ids.length);var i=0,total=0;function loop(){if(i>=ids.length){$('.ta-grupo-global-status').text('انجام شد: '+total+' گروه بروزرسانی شد');b.prop('disabled',false);return;}$.post(ajaxurl,{action:'ta_grupo_content_batch',nonce:ajaxNonce,mode:scope+'_icon',product_id:ids[i]}).always(function(x){if(x.success&&x.data&&x.data.updated)total+=parseInt(x.data.updated,10)||0;i++;$('.ta-grupo-global-status').text('در حال پردازش '+i+' از '+ids.length);loop();});}loop();});
            });
            </script>
        </div>
        <?php
    }
}
