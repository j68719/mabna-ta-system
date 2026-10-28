<?php
defined('ABSPATH') || exit;


/**
 * SalesStatsEngine
 * موتور تجمیع «آمار زنده فروش»: تعداد سفارش، تعداد کالا و مبلغ فروش،
 * به تفکیک دسته‌بندی محصول، استان و شهرستان — بر مبنای سفارش‌های واجد شرایطِ
 * وضعیت/تاریخِ انتخاب‌شده (پیش‌فرض: «تکمیل‌شده» + «در حال انجام»).
 *
 * تمام کوئری‌ها مستقیم روی جداول ووکامرس اجرا می‌شوند (نه حلقه‌ی wc_get_order روی
 * تک‌تک سفارش‌ها) تا برای فروشگاه‌های بزرگ هم سریع بماند و مناسبِ حالتِ «زنده»
 * (رفرش خودکار هر چند ثانیه) باشد. هم مدل سنتی (wp_posts) و هم HPOS
 * (wp_wc_orders) پشتیبانی می‌شود. جدولِ آیتم‌های سفارش
 * (woocommerce_order_items/itemmeta) در هر دو مدل مشترک است.
 */
class TA_Live_Sales_Stats_Engine {

    /** همان کلیدهایی که DemographicsRepository برای استان/شهرستان محل سکونت می‌خواند */
    const PROVINCE_META_KEY = '_billing_residence_province';
    const CITY_META_KEY     = '_billing_residence_city';

    /** فیلدهای «اطلاعات تکمیلی» که به‌همراه استان/شهرستان روی هر سفارش تجمیع می‌شوند
     *  تا بخش «آمار جمعیت‌شناختی سفارش‌ها»ی همین صفحه بتواند برایشان نمودار جدا رسم کند. */
    const DEMO_META_KEYS = [
        '_billing_gender'             => 'gender',
        '_billing_education'          => 'education',
        '_billing_job'                => 'job',
        '_billing_referral'           => 'referral',
        '_billing_user_type'          => 'user_type',
        '_billing_educational_region' => 'educational_region',
        '_billing_teaching_subject'   => 'teaching_subject',
        '_billing_birth_date'         => 'birth_date',
    ];

    /** طول عمر کش (ثانیه) — عمداً کوتاه چون این صفحه قرار است «زنده» باشد */
    const CACHE_TTL = 25;

    // ─── API عمومی ───────────────────────────────────────────────────────────

    /** لیست دسته‌بندی‌های محصول برای پرکردن فیلتر صفحه */
    public static function get_categories_for_filter(): array {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'name']);
        if (is_wp_error($terms)) return [];
        $out = [];
        foreach ($terms as $t) {
            $out[] = ['id' => (int) $t->term_id, 'name' => $t->name, 'count' => (int) $t->count];
        }
        return $out;
    }

    /** خلاصه + تفکیک استان + تفکیک دسته‌بندی، برای فیلترهای داده‌شده */
    public static function get_stats(array $raw_args): array {
        $args   = self::normalize_args($raw_args);
        $rows   = self::build_rows($args);
        $orders = $rows['orders'];
        $items  = $rows['items'];

        $order_count = count($orders);
        $qty_sum     = 0.0;
        $rev_sum     = 0.0;
        foreach ($orders as $o) { $qty_sum += $o['qty']; $rev_sum += $o['revenue']; }

        $by_province = [];
        foreach ($orders as $o) {
            $p = $o['province'];
            if (!isset($by_province[$p])) {
                $by_province[$p] = ['label' => $p, 'order_count' => 0, 'qty' => 0.0, 'revenue' => 0.0];
            }
            $by_province[$p]['order_count']++;
            $by_province[$p]['qty']     += $o['qty'];
            $by_province[$p]['revenue'] += $o['revenue'];
        }
        $by_province = array_values($by_province);
        usort($by_province, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        $by_day = self::day_breakdown($orders);

        return [
            'summary' => [
                'order_count' => $order_count,
                'item_qty'    => $qty_sum,
                'revenue'     => $rev_sum,
                'aov'         => $order_count > 0 ? $rev_sum / $order_count : 0.0,
            ],
            'comparison'      => self::comparison_stats($args),
            'provinces'       => $by_province,
            'categories'      => self::category_breakdown($items, $args['category_ids']),
            'products'        => self::product_breakdown($items),
            'by_day'          => $by_day,
            'by_hour'         => self::hour_breakdown($orders),
            'by_hour_by_day'  => self::hour_breakdown_by_day($orders, $by_day),
            'demographics' => [
                'gender'             => TA_Live_Demographics::distribution($orders, 'gender', 6),
                'education'          => TA_Live_Demographics::distribution($orders, 'education', 20),
                'job'                => TA_Live_Demographics::distribution($orders, 'job', 10),
                'referral'           => TA_Live_Demographics::distribution($orders, 'referral', 8),
                'user_type'          => TA_Live_Demographics::distribution($orders, 'user_type', 8),
                'educational_region' => TA_Live_Demographics::distribution($orders, 'educational_region', 12),
                'teaching_subject'   => TA_Live_Demographics::distribution($orders, 'teaching_subject', 10),
                'birth_decade'       => TA_Live_Demographics::birth_decades($orders),
            ],
            'filters_used' => $args,
            'ta_overview'  => self::ta_overview(),
            'generated_at' => current_time('mysql'),
        ];
    }

    /** آمار «دوره‌ی قبل» برای مقایسه‌ی درصدی با کارت‌های KPI.
     *  فقط وقتی معنی دارد که کاربر «از تاریخ» را انتخاب کرده باشد — وگرنه (کل تاریخچه)
     *  طول یک «دوره» تعریف‌پذیر نیست، پس null برمی‌گردد و رابط کاربری بجِ درصد را نشان نمی‌دهد.
     *  دوره‌ی قبل هم‌طول با بازه‌ی فعلی است و بلافاصله قبل از آن قرار می‌گیرد
     *  (مثلاً بازه‌ی فعلی ۷ روزه → دوره‌ی قبل همان ۷ روزِ درست قبل از آن). */
    private static function comparison_stats(array $args): ?array {
        if ($args['date_from'] === '') return null;

        $from_str = $args['date_from'];
        $to_str   = $args['date_to'] !== '' ? $args['date_to'] : current_time('Y-m-d');

        try {
            $from_dt = new \DateTime($from_str);
            $to_dt   = new \DateTime($to_str);
        } catch (\Exception $e) {
            return null;
        }
        if ($from_dt > $to_dt) return null;

        $period_days = (int) $from_dt->diff($to_dt)->days + 1;

        $prev_to_dt   = (clone $from_dt)->modify('-1 day');
        $prev_from_dt = (clone $prev_to_dt)->modify('-' . ($period_days - 1) . ' days');

        $prev_args = $args;
        $prev_args['date_from'] = $prev_from_dt->format('Y-m-d');
        $prev_args['date_to']   = $prev_to_dt->format('Y-m-d');

        $prev_rows   = self::build_rows($prev_args);
        $prev_orders = $prev_rows['orders'];

        $order_count = count($prev_orders);
        $qty_sum = 0.0;
        $rev_sum = 0.0;
        foreach ($prev_orders as $o) {
            $qty_sum += $o['qty'];
            $rev_sum += $o['revenue'];
        }

        return [
            'summary' => [
                'order_count' => $order_count,
                'item_qty'    => $qty_sum,
                'revenue'     => $rev_sum,
                'aov'         => $order_count > 0 ? $rev_sum / $order_count : 0.0,
            ],
            'range' => [
                'from'       => $prev_args['date_from'],
                'to'         => $prev_args['date_to'],
                'from_label' => TA_Live_Jalali_Date::format($prev_args['date_from'], 'Y/m/d'),
                'to_label'   => TA_Live_Jalali_Date::format($prev_args['date_to'], 'Y/m/d'),
            ],
        ];
    }

    /** تفکیک روزانه (تاریخ شمسی) — برای نمایش روند فروش در طول زمان.
     *  اگر بازه‌ی تاریخی محدودی انتخاب نشده باشد (یعنی کل تاریخچه)، فقط ۶۰ روز اخیر
     *  نگه داشته می‌شود تا نمودار/جدول برای فروشگاه‌های قدیمی غیرقابل‌استفاده نشود. */
    private static function day_breakdown(array $orders): array {
        $by_day = [];
        foreach ($orders as $o) {
            $d = $o['date'] ?? '';
            if ($d === '') continue;
            if (!isset($by_day[$d])) {
                $by_day[$d] = ['date' => $d, 'order_count' => 0, 'qty' => 0.0, 'revenue' => 0.0];
            }
            $by_day[$d]['order_count']++;
            $by_day[$d]['qty']     += $o['qty'];
            $by_day[$d]['revenue'] += $o['revenue'];
        }
        ksort($by_day);

        if (count($by_day) > 60) {
            $by_day = array_slice($by_day, -60, null, true);
        }

        $out = [];
        foreach ($by_day as $d => $row) {
            $row['label'] = TA_Live_Jalali_Date::format($d . ' 00:00:00', 'Y/m/d');
            $out[] = $row;
        }
        return $out;
    }

    /** تفکیک ساعتی (۰ تا ۲۳) — بر مبنای ساعت محلی سایت، برای دیدن پرترافیک‌ترین ساعات فروش. */
    private static function hour_breakdown(array $orders): array {
        $by_hour = [];
        for ($h = 0; $h < 24; $h++) {
            $by_hour[$h] = ['hour' => $h, 'order_count' => 0, 'qty' => 0.0, 'revenue' => 0.0];
        }
        foreach ($orders as $o) {
            $h = $o['hour'] ?? null;
            if ($h === null || $h < 0 || $h > 23) continue;
            $by_hour[$h]['order_count']++;
            $by_hour[$h]['qty']     += $o['qty'];
            $by_hour[$h]['revenue'] += $o['revenue'];
        }
        $out = [];
        foreach ($by_hour as $h => $row) {
            $row['label'] = str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00';
            $out[] = $row;
        }
        return $out;
    }

    /** تفکیک ساعتی به‌ازای هر روز — برای اینکه با کلیک روی یک روز در «نمودار روز»،
     *  «نمودار ساعت» فقط همان روز را نشان دهد. فقط برای روزهایی محاسبه می‌شود که در
     *  خروجی day_breakdown() هستند (یعنی همان محدودیت ۶۰ روز اخیر رعایت می‌شود). */
    private static function hour_breakdown_by_day(array $orders, array $day_rows): array {
        $allowed_dates = [];
        foreach ($day_rows as $r) { $allowed_dates[$r['date']] = true; }

        $by_day_hour = [];
        foreach ($orders as $o) {
            $d = $o['date'] ?? '';
            $h = $o['hour'] ?? null;
            if ($d === '' || !isset($allowed_dates[$d]) || $h === null || $h < 0 || $h > 23) continue;

            if (!isset($by_day_hour[$d])) {
                $by_day_hour[$d] = [];
                for ($i = 0; $i < 24; $i++) {
                    $by_day_hour[$d][$i] = [
                        'hour' => $i, 'order_count' => 0, 'qty' => 0.0, 'revenue' => 0.0,
                        'label' => str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ':00',
                    ];
                }
            }
            $by_day_hour[$d][$h]['order_count']++;
            $by_day_hour[$d][$h]['qty']     += $o['qty'];
            $by_day_hour[$d][$h]['revenue'] += $o['revenue'];
        }

        $out = [];
        foreach ($by_day_hour as $d => $hours) {
            $out[$d] = array_values($hours);
        }
        return $out;
    }

    /** تفکیک شهرستان‌های یک استان مشخص، با همان فیلترهای صفحه */
    public static function get_city_breakdown(array $raw_args, string $province): array {
        $args = self::normalize_args($raw_args);
        $rows = self::build_rows($args);

        $by_city = [];
        foreach ($rows['orders'] as $o) {
            if ($o['province'] !== $province) continue;
            $c = $o['city'];
            if (!isset($by_city[$c])) {
                $by_city[$c] = ['label' => $c, 'order_count' => 0, 'qty' => 0.0, 'revenue' => 0.0];
            }
            $by_city[$c]['order_count']++;
            $by_city[$c]['qty']     += $o['qty'];
            $by_city[$c]['revenue'] += $o['revenue'];
        }
        $out = array_values($by_city);
        usort($out, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        return $out;
    }

    /** پاکسازی فیلترهای ورودی (برای AJAX و خروجی CSV مشترک است) */
    public static function normalize_args(array $raw): array {
        $all_statuses = array_map(
            fn($s) => preg_replace('/^wc-/', '', (string) $s),
            array_keys(wc_get_order_statuses())
        );

        $statuses = isset($raw['statuses']) && is_array($raw['statuses']) ? $raw['statuses'] : [];
        $statuses = array_values(array_intersect(array_map('sanitize_key', $statuses), $all_statuses));
        if (empty($statuses)) $statuses = array_values(array_intersect(['completed', 'processing', 'partial-payment'], $all_statuses));

        $cats = isset($raw['category_ids']) && is_array($raw['category_ids'])
            ? array_map('intval', $raw['category_ids'])
            : [];
        $cats = array_values(array_unique(array_filter($cats)));

        $from = isset($raw['date_from']) ? (string) $raw['date_from'] : '';
        $to   = isset($raw['date_to'])   ? (string) $raw['date_to']   : '';
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : '';
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? $to   : '';

        // محصولاتی که کاربر از داخل «محصولات دسته‌بندی‌های انتخابی» تیک آن‌ها را برداشته
        // (یعنی می‌خواهد از محاسبه کنار گذاشته شوند، هرچند در دسته‌بندی انتخاب‌شده باشند).
        $excluded_products = isset($raw['excluded_product_ids']) && is_array($raw['excluded_product_ids'])
            ? array_map('intval', $raw['excluded_product_ids'])
            : [];
        $excluded_products = array_values(array_unique(array_filter($excluded_products)));

        return [
            'statuses'             => $statuses,
            'category_ids'         => $cats,
            'date_from'            => $from,
            'date_to'              => $to,
            'excluded_product_ids' => $excluded_products,
        ];
    }

    /** لیست محصولات منتشرشده‌ی یک یا چند دسته‌بندی (+ زیردسته‌ها) — برای پیکر «انتخاب/عدم‌انتخاب محصول» زیر چیپ‌های دسته‌بندی */
    public static function products_for_categories(array $category_ids): array {
        $category_ids = array_values(array_unique(array_filter(array_map('intval', $category_ids))));
        if (empty($category_ids)) return [];

        $expanded = self::expand_with_children($category_ids);
        if (empty($expanded)) return [];

        $q = new \WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 300,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'tax_query'      => [[
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $expanded,
            ]],
        ]);

        $out = [];
        foreach ($q->posts as $pid) {
            $title = get_the_title($pid);
            // موجودیت‌های HTML خام («&#8211;» و مشابه) را به کاراکتر واقعی برگردان — wp_specialchars_decode
            // فقط ۵ کاراکتر پایه (& ' " < >) را می‌شناسد و موجودیت‌های عددی مثل &#8211; را دست‌نخورده رها
            // می‌کند، برای همین باید از html_entity_decode استفاده شود که موجودیت‌های عددی را هم می‌فهمد.
            $out[] = ['id' => (int) $pid, 'name' => html_entity_decode($title, ENT_QUOTES, 'UTF-8')];
        }
        return $out;
    }

    // ─── داخلی ────────────────────────────────────────────────────────────────

    private static function is_hpos(): bool {
        return class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && method_exists('\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_order_tables_usage_is_enabled')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_order_tables_usage_is_enabled();
    }

    private static function cache_key(array $args): string {
        return 'smss_' . md5(
            implode(',', $args['statuses']) . '|' .
            implode(',', $args['category_ids']) . '|' .
            $args['date_from'] . '|' . $args['date_to'] . '|' .
            implode(',', $args['excluded_product_ids'] ?? [])
        );
    }

    /**
     * هسته‌ی محاسبه: سفارش‌های واجد شرایط را با استان/شهرستان/تعداد/مبلغِ
     * از پیش‌تجمیع‌شده، به‌همراه ردیف‌های آیتم (برای تفکیک دسته‌بندی) برمی‌گرداند.
     * نتیجه چند ثانیه کش می‌شود تا رفرش خودکار صفحه فشار زیادی به دیتابیس نیاورد.
     */
    private static function build_rows(array $args): array {
        $key    = self::cache_key($args);
        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['orders'], $cached['items'])) {
            return $cached;
        }

        global $wpdb;
        $is_hpos = self::is_hpos();

        $status_in = "'" . implode(
            "','",
            array_map(fn($s) => esc_sql('wc-' . $s), $args['statuses'])
        ) . "'";

        $date_sql = '';
        if ($args['date_from'] !== '') {
            $date_sql .= $wpdb->prepare(
                $is_hpos ? ' AND date_created_gmt >= %s' : ' AND post_date_gmt >= %s',
                get_gmt_from_date($args['date_from'] . ' 00:00:00')
            );
        }
        if ($args['date_to'] !== '') {
            $date_sql .= $wpdb->prepare(
                $is_hpos ? ' AND date_created_gmt <= %s' : ' AND post_date_gmt <= %s',
                get_gmt_from_date($args['date_to'] . ' 23:59:59')
            );
        }

        // مرحله ۱: شناسه‌ی سفارش‌های واجد شرایط وضعیت/تاریخ (+ تاریخ ایجاد، برای تفکیک روز/ساعت)
        if ($is_hpos) {
            $order_rows = $wpdb->get_results("
                SELECT id AS order_id, date_created_gmt
                FROM {$wpdb->prefix}wc_orders
                WHERE type = 'shop_order' AND status IN ({$status_in}) {$date_sql}
            ", ARRAY_A) ?: [];
        } else {
            $order_rows = $wpdb->get_results("
                SELECT ID AS order_id, post_date
                FROM {$wpdb->posts}
                WHERE post_type = 'shop_order' AND post_status IN ({$status_in}) {$date_sql}
            ", ARRAY_A) ?: [];
        }
        $order_ids = array_map(fn($r) => (int) $r['order_id'], $order_rows);

        if (empty($order_ids)) {
            $empty = ['orders' => [], 'items' => []];
            set_transient($key, $empty, self::CACHE_TTL);
            return $empty;
        }

        // تاریخ/ساعت محلی (نه GMT) هر سفارش — برای بخش‌های «آمار بر اساس روز/ساعت»
        $date_map = [];
        foreach ($order_rows as $r) {
            $oid = (int) $r['order_id'];
            $local = $is_hpos
                ? get_date_from_gmt((string) $r['date_created_gmt'], 'Y-m-d H:i:s')
                : (string) $r['post_date'];
            if ($local !== '' && $local !== '0000-00-00 00:00:00') $date_map[$oid] = $local;
        }

        // مرحله ۲: استان/شهرستان هر سفارش + سایر فیلدهای «اطلاعات تکمیلی»
        $location_map = self::bulk_location_meta($order_ids, $is_hpos);
        $demo_map     = self::bulk_demographics_meta($order_ids, $is_hpos);

        // مرحله ۳: آیتم‌های ردیف هر سفارش (محصولات) + ردیف‌های «فی» (مثلاً تخفیف پلکانی، که به‌صورت
        // فیِ منفی به سبد اضافه می‌شود) — این جدول در هر دو مدل ذخیره‌سازی مشترک است.
        // طبق تأیید کارفرما فی مالیات ندارد، پس _line_tax فقط برای محصولات خوانده و جمع زده می‌شود.
        $item_rows       = [];
        $fee_sum_by_order = []; // order_id => جمع مبلغ فی‌های همان سفارش (بدون مالیات)
        foreach (array_chunk($order_ids, 1000) as $chunk) {
            $in = implode(',', $chunk);
            $meta_rows = $wpdb->get_results("
                SELECT oi.order_id, oi.order_item_id, oi.order_item_type, oim.meta_key, oim.meta_value
                FROM {$wpdb->prefix}woocommerce_order_items oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id
                WHERE oi.order_id IN ({$in})
                  AND oi.order_item_type IN ('line_item','fee')
                  AND oim.meta_key IN ('_product_id','_qty','_line_total','_line_tax')
            ", ARRAY_A) ?: [];

            $by_item = [];
            foreach ($meta_rows as $r) {
                $iid = (int) $r['order_item_id'];
                $by_item[$iid]['order_id']          = (int) $r['order_id'];
                $by_item[$iid]['type']              = $r['order_item_type'];
                $by_item[$iid][$r['meta_key']]      = $r['meta_value'];
            }
            foreach ($by_item as $it) {
                if ($it['type'] === 'fee') {
                    $oid = $it['order_id'];
                    $fee_sum_by_order[$oid] = ($fee_sum_by_order[$oid] ?? 0.0) + (float) ($it['_line_total'] ?? 0);
                    continue;
                }
                $item_rows[] = [
                    'order_id'   => $it['order_id'],
                    // نکته: برای واریانت‌ها، متای _product_id همان محصول والد است —
                    // دقیقاً همان چیزی که برای تطبیق دسته‌بندی لازم داریم.
                    'product_id' => (int) ($it['_product_id'] ?? 0),
                    'qty'        => (float) ($it['_qty'] ?? 0),
                    // قیمت خالص محصول (بدون مالیات) — مبنای تقسیم نسبی فی سفارش بین کالاها
                    'line_total' => (float) ($it['_line_total'] ?? 0),
                    'revenue'    => (float) ($it['_line_total'] ?? 0) + (float) ($it['_line_tax'] ?? 0),
                ];
            }
        }

        // مرحله ۳ب: مبلغ کل محصولات هر سفارش (بدون مالیات، بدون فی) — مبنای نسبت تقسیم فی.
        // سفارش‌هایی که فقط فی دارند و هیچ محصولی ندارند، از این محاسبه و از کل آمار کنار می‌مانند
        // (چون جایی برای پخش‌کردن فی‌شان نیست).
        $order_products_total = [];
        foreach ($item_rows as $it) {
            $order_products_total[$it['order_id']] = ($order_products_total[$it['order_id']] ?? 0.0) + $it['line_total'];
        }

        // مرحله ۴: دسته‌بندی هر محصول
        $product_ids      = array_values(array_unique(array_filter(array_column($item_rows, 'product_id'))));
        $product_cat_map  = self::bulk_product_categories($product_ids);

        // مرحله ۵: اگر دسته‌بندی خاصی انتخاب شده، فقط محصولات همان دسته‌ها (+ زیردسته‌ها) مجازند
        $allowed_products = null; // null یعنی بدون فیلتر دسته‌بندی
        if (!empty($args['category_ids'])) {
            $expanded          = self::expand_with_children($args['category_ids']);
            $allowed_products  = [];
            foreach ($product_cat_map as $pid => $cat_ids) {
                if (array_intersect($cat_ids, $expanded)) $allowed_products[$pid] = true;
            }
        }

        // مرحله ۶: فقط آیتم‌های واجد شرایط دسته‌بندی باقی می‌مانند
        // (و محصولاتی که کاربر از پیکر «محصولات دسته‌بندی‌های انتخابی» تیک‌شان را برداشته، کنار گذاشته می‌شوند)
        $excluded_map = !empty($args['excluded_product_ids']) ? array_flip($args['excluded_product_ids']) : [];
        $qualifying_items = [];
        foreach ($item_rows as $it) {
            if ($allowed_products !== null && empty($allowed_products[$it['product_id']])) continue;
            if (isset($excluded_map[$it['product_id']])) continue;
            $it['cat_ids'] = $product_cat_map[$it['product_id']] ?? [];

            // سهم این کالا از فیِ همان سفارش، به نسبت قیمتش از کل قیمت سفارش.
            // فی می‌تواند منفی هم باشد (مثلاً «تخفیف پلکانی» که به‌صورت فیِ منفی
            // به سبد خرید اضافه می‌شود) — پس فقط صفر را رد می‌کنیم، نه مقادیر منفی را.
            $oid          = $it['order_id'];
            $fee_of_order = $fee_sum_by_order[$oid] ?? 0.0;
            $order_total  = $order_products_total[$oid] ?? 0.0;
            if ($fee_of_order != 0.0 && $order_total > 0.0) {
                $it['revenue'] += ($it['line_total'] / $order_total) * $fee_of_order;
            }

            $qualifying_items[] = $it;
        }

        // مرحله ۷: تجمیع در سطح سفارش (یک سفارش فقط اگر حداقل یک آیتم واجد شرایط داشته باشد شمرده می‌شود)
        $order_agg = [];
        foreach ($qualifying_items as $it) {
            $oid = $it['order_id'];
            if (!isset($order_agg[$oid])) {
                $loc  = $location_map[$oid] ?? ['province' => '', 'city' => ''];
                $demo = $demo_map[$oid] ?? [];
                $dt   = $date_map[$oid] ?? '';
                $order_agg[$oid] = [
                    'order_id' => $oid,
                    'province' => $loc['province'] !== '' ? $loc['province'] : 'نامشخص',
                    'city'     => $loc['city']     !== '' ? $loc['city']     : 'نامشخص',
                    'qty'      => 0.0,
                    'revenue'  => 0.0,
                    'date'     => $dt !== '' ? substr($dt, 0, 10) : '',
                    'hour'     => $dt !== '' ? (int) substr($dt, 11, 2) : null,
                    'gender'             => $demo['gender'] ?? '',
                    'education'          => $demo['education'] ?? '',
                    'job'                => $demo['job'] ?? '',
                    'referral'           => $demo['referral'] ?? '',
                    'user_type'          => $demo['user_type'] ?? '',
                    'educational_region' => $demo['educational_region'] ?? '',
                    'teaching_subject'   => $demo['teaching_subject'] ?? '',
                    'birth_date'         => $demo['birth_date'] ?? '',
                ];
            }
            $order_agg[$oid]['qty']     += $it['qty'];
            $order_agg[$oid]['revenue'] += $it['revenue'];
        }

        $result = ['orders' => array_values($order_agg), 'items' => $qualifying_items];
        set_transient($key, $result, self::CACHE_TTL);
        return $result;
    }

    /** شاخص‌های زنده اختصاصی سیستم استادیار؛ در صورت نبود هر جدول مقدار صفر برمی‌گردد. */
    public static function ta_overview(): array {
        global $wpdb;
        $count = static function (string $table, string $where = '') use ($wpdb): int {
            $full = $wpdb->prefix . $table;
            if (!self::table_exists($full)) return 0;
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$full} {$where}");
        };
        $sum = static function (string $table, string $column) use ($wpdb): float {
            $full = $wpdb->prefix . $table;
            if (!self::table_exists($full)) return 0.0;
            return (float) $wpdb->get_var("SELECT COALESCE(SUM({$column}),0) FROM {$full}");
        };
        return [
            'active_assistants'  => $count('ta_assistants', "WHERE is_active = 1"),
            'active_assignments' => $count('ta_assignments', "WHERE status = 'active'"),
            'total_assignments'  => $count('ta_assignments'),
            'capacity_total'     => (int) $sum('ta_capacities', 'total_capacity'),
            'capacity_used'      => (int) $sum('ta_capacities', 'used_capacity'),
            'pending_grades'     => $count('ta_grades', "WHERE grade IN ('pending','in_progress','')"),
            'pending_levels'     => $count('ta_level_assessments', "WHERE status NOT IN ('completed','done','cancelled')"),
            'open_critiques'     => $count('ta_critique_orders', "WHERE status IN ('pending','in_progress','delivered','ta_completed')"),
            'pending_messages'   => $count('ta_admin_messages', "WHERE is_read = 0"),
        ];
    }

    private static function table_exists(string $table): bool {
        global $wpdb;
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $cache[$table] = ($found === $table);
    }

    /** استان/شهرستان چند سفارش را یکجا می‌خواند (بدون N+1 کوئری) */
    private static function bulk_location_meta(array $order_ids, bool $is_hpos): array {
        global $wpdb;
        $map = [];
        $keys_in = "'" . esc_sql(self::PROVINCE_META_KEY) . "','" . esc_sql(self::CITY_META_KEY) . "'";

        foreach (array_chunk($order_ids, 1000) as $chunk) {
            $in = implode(',', $chunk);

            if ($is_hpos) {
                $rows = $wpdb->get_results("
                    SELECT order_id, meta_key, meta_value
                    FROM {$wpdb->prefix}wc_orders_meta
                    WHERE order_id IN ({$in}) AND meta_key IN ({$keys_in})
                ", ARRAY_A) ?: [];
            } else {
                $rows = $wpdb->get_results("
                    SELECT post_id AS order_id, meta_key, meta_value
                    FROM {$wpdb->postmeta}
                    WHERE post_id IN ({$in}) AND meta_key IN ({$keys_in})
                ", ARRAY_A) ?: [];
            }

            foreach ($rows as $r) {
                $oid = (int) $r['order_id'];
                $val = trim(wp_strip_all_tags((string) $r['meta_value']));
                if ($r['meta_key'] === self::PROVINCE_META_KEY) $map[$oid]['province'] = $val;
                if ($r['meta_key'] === self::CITY_META_KEY)     $map[$oid]['city']     = $val;
            }
        }
        return $map;
    }

    /** سایر فیلدهای «اطلاعات تکمیلی» (جنسیت، تحصیلات، شغل، ...) هر سفارش را یکجا می‌خواند (بدون N+1) */
    private static function bulk_demographics_meta(array $order_ids, bool $is_hpos): array {
        global $wpdb;
        $map     = [];
        $keys_in = "'" . implode("','", array_map('esc_sql', array_keys(self::DEMO_META_KEYS))) . "'";

        foreach (array_chunk($order_ids, 1000) as $chunk) {
            $in = implode(',', $chunk);

            if ($is_hpos) {
                $rows = $wpdb->get_results("
                    SELECT order_id, meta_key, meta_value
                    FROM {$wpdb->prefix}wc_orders_meta
                    WHERE order_id IN ({$in}) AND meta_key IN ({$keys_in})
                ", ARRAY_A) ?: [];
            } else {
                $rows = $wpdb->get_results("
                    SELECT post_id AS order_id, meta_key, meta_value
                    FROM {$wpdb->postmeta}
                    WHERE post_id IN ({$in}) AND meta_key IN ({$keys_in})
                ", ARRAY_A) ?: [];
            }

            foreach ($rows as $r) {
                $oid   = (int) $r['order_id'];
                $field = self::DEMO_META_KEYS[$r['meta_key']] ?? null;
                if (!$field) continue;
                $map[$oid][$field] = trim(wp_strip_all_tags((string) $r['meta_value']));
            }
        }
        return $map;
    }

    /** دسته‌بندی‌های هر محصول را یکجا می‌خواند */
    private static function bulk_product_categories(array $product_ids): array {
        if (empty($product_ids)) return [];
        global $wpdb;
        $map = [];
        foreach (array_chunk($product_ids, 1000) as $chunk) {
            $in   = implode(',', $chunk);
            $rows = $wpdb->get_results("
                SELECT tr.object_id AS product_id, tt.term_id
                FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tt.taxonomy = 'product_cat' AND tr.object_id IN ({$in})
            ", ARRAY_A) ?: [];
            foreach ($rows as $r) {
                $map[(int) $r['product_id']][] = (int) $r['term_id'];
            }
        }
        return $map;
    }

    /** انتخاب یک دسته‌ی والد یعنی زیردسته‌هایش هم مجاز باشند */
    private static function expand_with_children(array $cat_ids): array {
        $all = $cat_ids;
        foreach ($cat_ids as $cid) {
            $children = get_term_children((int) $cid, 'product_cat');
            if (!is_wp_error($children)) $all = array_merge($all, $children);
        }
        return array_values(array_unique(array_map('intval', $all)));
    }

    /** تفکیک فروش به ازای هر دسته‌بندی (فقط دسته‌های انتخاب‌شده، یا ۱۵ تای برتر اگر چیزی انتخاب نشده) */
    private static function category_breakdown(array $items, array $selected_ids): array {
        $agg = []; // cat_id => ['order_ids'=>set, 'qty'=>, 'revenue'=>]
        foreach ($items as $it) {
            $cats = $it['cat_ids'];
            if (!empty($selected_ids)) $cats = array_intersect($cats, $selected_ids);
            foreach ($cats as $cid) {
                if (!isset($agg[$cid])) $agg[$cid] = ['order_ids' => [], 'qty' => 0.0, 'revenue' => 0.0];
                $agg[$cid]['order_ids'][$it['order_id']] = true;
                $agg[$cid]['qty']     += $it['qty'];
                $agg[$cid]['revenue'] += $it['revenue'];
            }
        }
        if (empty($agg)) return [];

        $names = [];
        $terms = get_terms(['taxonomy' => 'product_cat', 'include' => array_keys($agg), 'hide_empty' => false]);
        if (!is_wp_error($terms)) foreach ($terms as $t) $names[(int) $t->term_id] = $t->name;

        $out = [];
        foreach ($agg as $cid => $a) {
            $out[] = [
                'id'          => (int) $cid,
                'name'        => $names[$cid] ?? ('#' . $cid),
                'order_count' => count($a['order_ids']),
                'qty'         => $a['qty'],
                'revenue'     => $a['revenue'],
            ];
        }
        usort($out, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        if (empty($selected_ids) && count($out) > 15) {
            $top  = array_slice($out, 0, 15);
            $rest = array_slice($out, 15);
            $other = ['id' => 0, 'name' => 'سایر دسته‌ها', 'order_count' => 0, 'qty' => 0.0, 'revenue' => 0.0];
            foreach ($rest as $r) {
                $other['qty']         += $r['qty'];
                $other['revenue']     += $r['revenue'];
                $other['order_count'] += $r['order_count']; // ممکن است سفارش مشترک چندبار شمرده شود؛ این ردیف فقط برای دید کلی است
            }
            $top[] = $other;
            return $top;
        }

        return $out;
    }

    /** تفکیک فروش به ازای هر محصول (داخل دسته‌بندی‌های انتخاب‌شده — یا همه‌ی محصولات اگر دسته‌بندی‌ای انتخاب نشده)،
     *  برای بخش «فروش به تفکیک محصول» — ۲۰ محصول برتر بر مبنای مبلغ فروش، بقیه زیر «سایر محصولات». */
    private static function product_breakdown(array $items): array {
        $agg = []; // product_id => ['order_ids'=>set, 'qty'=>, 'revenue'=>]
        foreach ($items as $it) {
            $pid = (int) $it['product_id'];
            if ($pid <= 0) continue;
            if (!isset($agg[$pid])) $agg[$pid] = ['order_ids' => [], 'qty' => 0.0, 'revenue' => 0.0];
            $agg[$pid]['order_ids'][$it['order_id']] = true;
            $agg[$pid]['qty']     += $it['qty'];
            $agg[$pid]['revenue'] += $it['revenue'];
        }
        if (empty($agg)) return [];

        $names = [];
        foreach (array_keys($agg) as $pid) {
            $title = get_the_title($pid);
            // برخی عنوان‌های محصول به‌جای «–» واقعی، موجودیت HTML آن («&#8211;») را به‌صورت متن خام دارند
            // (معمولاً از کپی/پیست در ویرایشگر وردپرس) — اگر رمزگشایی نشود، هم در جدول و هم در
            // برچسب‌های نمودار (که مستقیم روی canvas رسم می‌شوند و خودشان موجودیت HTML را نمی‌فهمند)
            // به‌جای یک خط تیره، همان متن «&#8211;» عیناً نمایش داده می‌شود و فاصله‌بندی به‌هم می‌ریزد.
            $title = $title !== '' ? html_entity_decode($title, ENT_QUOTES, 'UTF-8') : '';
            $names[$pid] = $title !== '' ? $title : ('#' . $pid);
        }

        $out = [];
        foreach ($agg as $pid => $a) {
            $out[] = [
                'id'          => (int) $pid,
                'name'        => $names[$pid],
                'order_count' => count($a['order_ids']),
                'qty'         => $a['qty'],
                'revenue'     => $a['revenue'],
            ];
        }
        usort($out, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        if (count($out) > 20) {
            $top  = array_slice($out, 0, 20);
            $rest = array_slice($out, 20);
            $other = ['id' => 0, 'name' => 'سایر محصولات', 'order_count' => 0, 'qty' => 0.0, 'revenue' => 0.0];
            foreach ($rest as $r) {
                $other['qty']         += $r['qty'];
                $other['revenue']     += $r['revenue'];
                $other['order_count'] += $r['order_count']; // ممکن است سفارش مشترک چندبار شمرده شود؛ این ردیف فقط برای دید کلی است
            }
            $top[] = $other;
            return $top;
        }

        return $out;
    }
}
