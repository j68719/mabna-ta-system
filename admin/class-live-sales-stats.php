<?php
defined('ABSPATH') || exit;


/**
 * SalesStatsPage
 * صفحه‌ی «آمار زنده فروش»: فروش بر اساس دسته‌بندی، استان و شهرستان،
 * با ملاک سفارش‌های «تکمیل‌شده» و «در حال انجام» (قابل تغییر).
 * تمام محاسبات از طریق AJAX انجام می‌شود تا امکان «رفرش خودکار» فراهم باشد.
 */
class TA_Live_Sales_Stats {

    const SLUG = 'wls-sales-stats';

    public static function register(): void {
        add_action('wp_ajax_ta_live_sales_stats',      [self::class, 'ajax_stats']);
        add_action('wp_ajax_ta_live_sales_stats_city', [self::class, 'ajax_city']);
        add_action('wp_ajax_ta_live_sales_stats_category_products', [self::class, 'ajax_category_products']);
        add_action('admin_post_ta_live_sales_stats_export', [self::class, 'handle_export']);
    }

    // ─── رندر ─────────────────────────────────────────────────────────────────

    public static function render(): void {
        if (!function_exists('wc_get_order_statuses')) {
            echo '<div class="wrap"><div class="notice notice-error"><p>ووکامرس در دسترس نیست.</p></div></div>';
            return;
        }

        $categories   = TA_Live_Sales_Stats_Engine::get_categories_for_filter();
        $all_statuses = wc_get_order_statuses(); // 'wc-completed' => 'تکمیل شده' ...
        $default_on   = ['wc-completed', 'wc-processing', 'wc-partial-payment'];
        ?>
        <div class="wrap sm-dashboard sm-sales-stats-page" dir="rtl">

            <div class="sm-page-header">
                <div>
                    <h1>📈 آمار زنده فروش</h1>
                    <p class="sm-subtitle">فروش به تفکیک دسته‌بندی، استان و شهرستان — بر مبنای وضعیت سفارش انتخاب‌شده</p>
                </div>
                <div class="sm-header-actions">
                    <span id="sm-sales-updated" class="sm-subtitle" style="margin-inline-end:6px"></span>
                    <a href="#" class="button" id="sm-sales-export">⬇️ خروجی CSV</a>
                    <button type="button" class="button button-primary" id="sm-sales-refresh">🔄 به‌روزرسانی</button>
                </div>
            </div>

            <div class="sm-filter-panel">
                <div class="sm-filter-header">
                    <h3>🔍 فیلترها</h3>
                    <button type="button" class="button" id="sm-sales-reset">✕ پاک‌کردن فیلترها</button>
                </div>

                <div class="sm-filter-grid" style="grid-template-columns:2fr 1fr 1fr 1fr;">
                    <div class="sm-filter-group">
                        <label class="sm-filter-label">🗂 دسته‌بندی محصول (چند انتخابی — خالی یعنی همه)</label>
                        <div class="sm-product-picker">
                            <div class="sm-sales-cat-picker-wrap">
                                <input type="text" id="sm-sales-cat-search" class="sm-search-input" placeholder="🔎 جست‌وجوی دسته‌بندی...">
                                <ul id="sm-sales-cat-dropdown" class="sm-product-dropdown" style="display:none">
                                    <?php foreach ($categories as $c): ?>
                                    <li class="sm-sales-cat-option" data-id="<?= (int) $c['id'] ?>" data-name="<?= esc_attr($c['name']) ?>">
                                        <?= esc_html($c['name']) ?> <span style="color:#8c8f94">(<?= (int) $c['count'] ?>)</span>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <div id="sm-sales-cat-chips" class="sm-product-chips"></div>
                        </div>
                    </div>
                    <div class="sm-filter-group sm-filter-group--small">
                        <label class="sm-filter-label">📅 از تاریخ</label>
                        <input type="date" id="sm-sales-date-from" class="sm-search-input">
                    </div>
                    <div class="sm-filter-group sm-filter-group--small">
                        <label class="sm-filter-label">📅 تا تاریخ</label>
                        <input type="date" id="sm-sales-date-to" class="sm-search-input">
                    </div>
                    <div class="sm-filter-group sm-filter-group--small">
                        <label class="sm-filter-label"><span id="sm-sales-live-dot" class="sm-sales-live-dot" style="display:none"></span> به‌روزرسانی خودکار</label>
                        <select id="sm-sales-live" class="sm-select">
                            <option value="0">خاموش</option>
                            <option value="10000">هر ۱۰ ثانیه</option>
                            <option value="30000" selected>هر ۳۰ ثانیه</option>
                            <option value="60000">هر ۶۰ ثانیه</option>
                        </select>
                    </div>
                </div>

                <div id="sm-sales-product-panel" class="sm-sales-product-panel" style="display:none">
                    <div class="sm-sales-product-panel-header">
                        <span>📦 محصولات دسته‌بندی‌های انتخابی <small id="sm-sales-product-count" class="sm-hint"></small></span>
                        <div>
                            <button type="button" class="button button-small" id="sm-sales-products-select-all">✅ انتخاب همه</button>
                            <button type="button" class="button button-small" id="sm-sales-products-select-none">◻️ هیچ‌کدام</button>
                        </div>
                    </div>
                    <div id="sm-sales-product-list" class="sm-season-checkboxes sm-inline sm-sales-product-list"></div>
                </div>

                <div class="sm-filter-group" style="margin-top:16px">
                    <label class="sm-filter-label">📦 ملاک وضعیت سفارش (پیش‌فرض: تکمیل‌شده + در حال انجام)</label>
                    <div class="sm-season-checkboxes">
                        <?php foreach ($all_statuses as $key => $label): ?>
                        <label class="sm-checkbox-item">
                            <input type="checkbox" value="<?= esc_attr(preg_replace('/^wc-/', '', $key)) ?>"
                                   class="sm-sales-status" <?= in_array($key, $default_on, true) ? 'checked' : '' ?>>
                            <?= esc_html($label) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="sm-kpi-grid" id="sm-sales-kpis"></div>
            <div class="sm-history-section ta-live-overview-section" style="margin:18px 0">
                <div class="sm-section-header"><h3>🎓 وضعیت زنده سیستم استادیار</h3><span class="sm-hint">شاخص‌های جاری تخصیص، ظرفیت و کارهای در انتظار</span></div>
                <div class="sm-kpi-grid" id="ta-live-overview-kpis"><p class="sm-empty-state">در حال بارگذاری...</p></div>
            </div>
            <div id="sm-sales-kpi-compare-hint" class="sm-hint" style="display:none;margin:-10px 0 18px"></div>

            <div class="sm-charts-row">
                <div class="sm-chart-card" style="flex:2;min-width:320px">
                    <div class="sm-chart-header" style="display:flex;align-items:center;justify-content:space-between">
                        <h3>🗺 فروش به تفکیک استان (۱۰ استان برتر)</h3>
                        <select id="sm-sales-metric" class="sm-select" style="width:auto">
                            <option value="revenue">بر اساس مبلغ فروش</option>
                            <option value="order_count">بر اساس تعداد سفارش</option>
                            <option value="qty">بر اساس تعداد کالا</option>
                        </select>
                    </div>
                    <div class="sm-chart-body" id="sm-sales-province-bars">
                        <p class="sm-empty-state" style="padding:16px 0">در حال بارگذاری...</p>
                    </div>
                </div>
                <div class="sm-chart-card" style="flex:1;min-width:260px">
                    <div class="sm-chart-header" style="display:flex;align-items:center;justify-content:space-between">
                        <h3>🗂 سهم دسته‌بندی‌ها از فروش</h3>
                        <select id="sm-sales-category-metric" class="sm-select" style="width:auto">
                            <option value="revenue">بر اساس مبلغ فروش</option>
                            <option value="order_count">بر اساس تعداد سفارش</option>
                            <option value="qty">بر اساس تعداد کالا</option>
                        </select>
                    </div>
                    <div class="sm-chart-body" id="sm-sales-category-bars">
                        <p class="sm-empty-state" style="padding:16px 0">در حال بارگذاری...</p>
                    </div>
                </div>
            </div>

            <div class="sm-charts-row">
                <div class="sm-chart-card" style="flex:1;min-width:320px">
                    <div class="sm-chart-header" style="display:flex;align-items:center;justify-content:space-between">
                        <h3>🛍 فروش به تفکیک محصول (۲۰ محصول برتر)</h3>
                        <select id="sm-sales-product-metric" class="sm-select" style="width:auto">
                            <option value="revenue">بر اساس مبلغ فروش</option>
                            <option value="qty">بر اساس تعداد کالا</option>
                        </select>
                    </div>
                    <p class="sm-hint" style="margin:0 0 10px">اگر از فیلتر «دسته‌بندی محصول» بالا استفاده کنید، فقط محصولات همان دسته‌بندی‌ها اینجا نشان داده می‌شود</p>
                    <div class="sm-chart-body sm-scroll-chart" id="sm-sales-product-bars">
                        <p class="sm-empty-state" style="padding:16px 0">در حال بارگذاری...</p>
                    </div>
                </div>
            </div>

            <div class="sm-history-section">
                <div class="sm-section-header"><h3>🛍 جدول کامل محصولات</h3></div>
                <div style="overflow-x:auto">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr><th>محصول</th><th style="width:120px">تعداد کالا</th><th style="width:160px">مبلغ فروش</th></tr>
                    </thead>
                    <tbody id="sm-sales-product-tbody">
                        <tr><td colspan="3"><p class="sm-empty-state" style="padding:20px 0">در حال بارگذاری...</p></td></tr>
                    </tbody>
                    <tfoot id="sm-sales-product-tfoot"></tfoot>
                </table>
                </div>
            </div>

            <div class="sm-charts-row">
                <div class="sm-chart-card" style="flex:2;min-width:320px">
                    <div class="sm-chart-header" style="display:flex;align-items:center;justify-content:space-between">
                        <h3>📅 آمار بر اساس روز</h3>
                        <select id="sm-sales-day-metric" class="sm-select" style="width:auto">
                            <option value="revenue">بر اساس مبلغ فروش</option>
                            <option value="order_count">بر اساس تعداد سفارش</option>
                            <option value="qty">بر اساس تعداد کالا</option>
                        </select>
                    </div>
                    <p class="sm-hint" style="margin:0 0 10px">اگر بازه‌ی تاریخی انتخاب نکرده باشید، فقط ۶۰ روز اخیر نمایش داده می‌شود — روی هر نقطه از نمودار کلیک کنید تا نمودار «آمار بر اساس ساعت» فقط برای همان روز نمایش داده شود</p>
                    <div class="sm-chart-canvas-wrap" style="height:300px">
                        <canvas id="sm-sales-day-chart"></canvas>
                    </div>
                </div>
                <div class="sm-chart-card" style="flex:1;min-width:260px">
                    <div class="sm-chart-header" style="display:flex;align-items:center;justify-content:space-between">
                        <h3>🕒 آمار بر اساس ساعت</h3>
                        <select id="sm-sales-hour-metric" class="sm-select" style="width:auto">
                            <option value="revenue">بر اساس مبلغ فروش</option>
                            <option value="order_count">بر اساس تعداد سفارش</option>
                            <option value="qty">بر اساس تعداد کالا</option>
                        </select>
                    </div>
                    <div id="sm-sales-hour-day-label" class="sm-hint" style="display:none;margin:0 0 10px"></div>
                    <div class="sm-chart-canvas-wrap" style="height:300px">
                        <canvas id="sm-sales-hour-chart"></canvas>
                    </div>
                </div>
            </div>

            <div class="sm-history-section">
                <div class="sm-section-header">
                    <h3>📊 آمار جمعیت‌شناختی سفارش‌ها</h3>
                    <span class="sm-hint">بر اساس اطلاعات تکمیلی ثبت‌شده روی همان سفارش‌های واجد شرایط فیلترهای بالا</span>
                </div>
                <div class="sm-mini-chart-grid" id="sm-sales-demo-grid">
                    <p class="sm-empty-state" style="padding:16px 0">در حال بارگذاری...</p>
                </div>
            </div>

            <div class="sm-history-section">
                <div class="sm-section-header">
                    <h3>🗺 جدول کامل استان‌ها</h3>
                    <span class="sm-hint">روی هر ردیف بزنید تا شهرستان‌های همان استان باز شود</span>
                </div>
                <div style="overflow-x:auto">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>استان</th>
                            <th style="width:120px">تعداد سفارش</th>
                            <th style="width:120px">تعداد کالا</th>
                            <th style="width:160px">مبلغ فروش</th>
                            <th style="width:100px">سهم از فروش</th>
                        </tr>
                    </thead>
                    <tbody id="sm-sales-province-tbody">
                        <tr><td colspan="5"><p class="sm-empty-state" style="padding:20px 0">در حال بارگذاری...</p></td></tr>
                    </tbody>
                    <tfoot id="sm-sales-province-tfoot"></tfoot>
                </table>
                </div>
            </div>

            <div class="sm-history-section" id="sm-sales-city-section" style="display:none">
                <div class="sm-section-header">
                    <h3 id="sm-sales-city-title">🏙 شهرستان‌های ...</h3>
                    <button type="button" class="button" id="sm-sales-city-close">✕ بستن</button>
                </div>
                <div style="overflow-x:auto">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr><th>شهرستان</th><th style="width:120px">تعداد سفارش</th><th style="width:120px">تعداد کالا</th><th style="width:160px">مبلغ فروش</th></tr>
                    </thead>
                    <tbody id="sm-sales-city-tbody"></tbody>
                </table>
                </div>
            </div>

            <div class="sm-history-section">
                <div class="sm-section-header"><h3>🗂 جدول کامل دسته‌بندی‌ها</h3></div>
                <div style="overflow-x:auto">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr><th>دسته‌بندی</th><th style="width:120px">تعداد سفارش</th><th style="width:120px">تعداد کالا</th><th style="width:160px">مبلغ فروش</th></tr>
                    </thead>
                    <tbody id="sm-sales-category-tbody">
                        <tr><td colspan="4"><p class="sm-empty-state" style="padding:20px 0">در حال بارگذاری...</p></td></tr>
                    </tbody>
                    <tfoot id="sm-sales-category-tfoot"></tfoot>
                </table>
                </div>
            </div>

        </div>
        <?php
    }

    // ─── AJAX ─────────────────────────────────────────────────────────────────

    public static function ajax_stats(): void {
        check_ajax_referer('ta_live_sales_stats');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();
        wp_send_json_success(TA_Live_Sales_Stats_Engine::get_stats(self::args_from_request($_POST)));
    }

    public static function ajax_city(): void {
        check_ajax_referer('ta_live_sales_stats');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        $province = sanitize_text_field(wp_unslash($_POST['province'] ?? ''));
        if ($province === '') wp_send_json_error(['message' => 'استان نامعتبر است.']);

        wp_send_json_success([
            'province' => $province,
            'cities'   => TA_Live_Sales_Stats_Engine::get_city_breakdown(self::args_from_request($_POST), $province),
        ]);
    }

    /** فهرست محصولات دسته‌بندی‌های انتخاب‌شده — برای پیکر «انتخاب/عدم‌انتخاب محصول» زیر چیپ‌های دسته‌بندی */
    public static function ajax_category_products(): void {
        check_ajax_referer('ta_live_sales_stats');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();

        $cat_ids = isset($_POST['category_ids']) ? array_map('intval', (array) $_POST['category_ids']) : [];
        wp_send_json_success(['products' => TA_Live_Sales_Stats_Engine::products_for_categories($cat_ids)]);
    }

    private static function args_from_request(array $req): array {
        return [
            'statuses'             => isset($req['statuses']) ? array_map('sanitize_key', (array) $req['statuses']) : [],
            'category_ids'         => isset($req['category_ids']) ? array_map('intval', (array) $req['category_ids']) : [],
            'date_from'            => sanitize_text_field(wp_unslash($req['date_from'] ?? '')),
            'date_to'              => sanitize_text_field(wp_unslash($req['date_to'] ?? '')),
            'excluded_product_ids' => isset($req['excluded_product_ids']) ? array_map('intval', (array) $req['excluded_product_ids']) : [],
        ];
    }

    // ─── خروجی CSV ───────────────────────────────────────────────────────────

    public static function handle_export(): void {
        check_admin_referer('ta_live_sales_stats_export');
        if (!current_user_can('manage_woocommerce')) wp_die('دسترسی ندارید.');

        $args  = self::args_from_request($_GET);
        $stats = TA_Live_Sales_Stats_Engine::get_stats($args);

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="sales-stats-' . gmdate('Y-m-d-His') . '.csv"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM برای نمایش درست فارسی در اکسل

        fputcsv($out, ['خلاصه']);
        fputcsv($out, ['تعداد سفارش', 'تعداد کالا', 'مبلغ فروش (تومان)', 'میانگین سفارش (تومان)']);
        fputcsv($out, [
            $stats['summary']['order_count'],
            $stats['summary']['item_qty'],
            $stats['summary']['revenue'],
            round($stats['summary']['aov']),
        ]);
        fputcsv($out, []);

        fputcsv($out, ['استان', 'شهرستان', 'تعداد سفارش', 'تعداد کالا', 'مبلغ فروش (تومان)']);
        foreach ($stats['provinces'] as $p) {
            fputcsv($out, [$p['label'], '(همه)', $p['order_count'], $p['qty'], $p['revenue']]);
            $cities = TA_Live_Sales_Stats_Engine::get_city_breakdown($args, $p['label']);
            foreach ($cities as $c) {
                fputcsv($out, [$p['label'], $c['label'], $c['order_count'], $c['qty'], $c['revenue']]);
            }
        }
        fputcsv($out, []);

        fputcsv($out, ['دسته‌بندی', 'تعداد سفارش', 'تعداد کالا', 'مبلغ فروش (تومان)']);
        foreach ($stats['categories'] as $c) {
            fputcsv($out, [$c['name'], $c['order_count'], $c['qty'], $c['revenue']]);
        }
        fputcsv($out, []);

        fputcsv($out, ['محصول', 'تعداد سفارش', 'تعداد کالا', 'مبلغ فروش (تومان)']);
        foreach ($stats['products'] as $p) {
            fputcsv($out, [$p['name'], $p['order_count'], $p['qty'], $p['revenue']]);
        }

        fclose($out);
        exit;
    }
}
