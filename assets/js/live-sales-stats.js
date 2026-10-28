/* Season Manager Pro — آمار زنده فروش (اسکریپت مستقل، فقط همین صفحه) */
(function ($) {
    'use strict';

    var selectedCats = [];   // [{id,name,stat:null|{order_count,qty,revenue}}]
    var liveTimer     = null;
    var metric        = 'revenue';
    var categoryMetric = 'revenue';
    var productMetric  = 'revenue';
    var dayMetric      = 'revenue';
    var hourMetric     = 'revenue';
    var lastProvinces  = [];
    var lastCategories = [];
    var lastProducts   = [];
    var lastByDay      = [];
    var lastByHour     = [];
    var lastByHourByDay = {}; // { 'YYYY-MM-DD': [ {hour,label,order_count,qty,revenue}, ... 24تایی ] }
    var selectedDayDate = null; // تاریخ روزی که در نمودار «آمار بر اساس روز» کلیک شده — نمودار ساعت را فقط برای همین روز نشان می‌دهد
    var lastDemographics = {};
    var lastSummary     = {};
    var isLoading      = false;

    // ── فیلدهای «اطلاعات تکمیلی» که در بخش «آمار جمعیت‌شناختی سفارش‌ها» نمودار مخصوص خودشان را دارند ──
    var SM_SALES_DEMO_FIELDS = [
        { key: 'gender',             title: '🚻 جنسیت' },
        { key: 'education',          title: '🎓 تحصیلات' },
        { key: 'job',                title: '💼 شغل' },
        { key: 'referral',           title: '📣 نحوه آشنایی' },
        { key: 'user_type',          title: '🏷 نوع کاربر' },
        { key: 'educational_region', title: '📍 منطقه آموزشی' },
        { key: 'teaching_subject',   title: '📖 موضوع تدریس' },
        { key: 'birth_decade',       title: '🎂 دهه تولد' }
    ];

    // ── محصولات دسته‌بندی‌های انتخابی (پیکر انتخاب/عدم‌انتخاب زیر چیپ‌ها) ──
    var categoryProducts   = [];  // [{id,name}] — محصولات دسته‌های فعلاً انتخاب‌شده
    var excludedProductIds = [];  // شناسه‌ی محصولاتی که کاربر تیک‌شان را برداشته
    var productsFetchSeq   = 0;   // برای نادیده‌گرفتن پاسخ AJAX قدیمی هنگام تغییر سریع دسته‌بندی‌ها

    $(function () {
        if (!$('.sm-sales-stats-page').length) return; // فقط همین صفحه
        bindFilters();
        bindCategoryPicker();
        bindProductPanel();
        bindProvinceMetric();
        bindCategoryMetric();
        bindCityPanel();
        bindDayHourMetric();
        bindProductMetric();
        fetchStats();
    });

    function formatMoney(n) {
        return Math.round(Number(n) || 0).toLocaleString('fa') + ' تومان';
    }
    function formatNum(n) {
        return Math.round(Number(n) || 0).toLocaleString('fa');
    }
    function formatPercent(n) {
        n = Math.round((Number(n) || 0) * 10) / 10;
        return n.toLocaleString('fa', { minimumFractionDigits: 0, maximumFractionDigits: 1 });
    }

    function currentFilters() {
        var statuses = [];
        $('.sm-sales-status:checked').each(function () { statuses.push($(this).val()); });
        return {
            statuses:             statuses,
            category_ids:         selectedCats.map(function (c) { return c.id; }),
            date_from:            $('#sm-sales-date-from').val(),
            date_to:              $('#sm-sales-date-to').val(),
            excluded_product_ids: excludedProductIds.slice()
        };
    }

    function postPayload(action, extra) {
        var f = currentFilters();
        return $.extend({
            action:                  action,
            _ajax_nonce:             SM.nonce,
            'statuses[]':            f.statuses,
            statuses:                f.statuses,
            category_ids:            f.category_ids,
            date_from:               f.date_from,
            date_to:                 f.date_to,
            excluded_product_ids:    f.excluded_product_ids
        }, extra || {});
    }

    function updateExportLink() {
        var f = currentFilters();
        var params = [];
        params.push('action=ta_live_sales_stats_export');
        params.push('_wpnonce=' + encodeURIComponent((window.SM_SALES && SM_SALES.export_nonce) || ''));
        f.statuses.forEach(function (s) { params.push('statuses[]=' + encodeURIComponent(s)); });
        f.category_ids.forEach(function (c) { params.push('category_ids[]=' + encodeURIComponent(c)); });
        f.excluded_product_ids.forEach(function (p) { params.push('excluded_product_ids[]=' + encodeURIComponent(p)); });
        if (f.date_from) params.push('date_from=' + encodeURIComponent(f.date_from));
        if (f.date_to)   params.push('date_to=' + encodeURIComponent(f.date_to));
        var base = (window.SM_SALES && SM_SALES.admin_post_url) || '';
        $('#sm-sales-export').attr('href', base + '?' + params.join('&'));
    }

    function renderTaOverview(data) {
        var cards = [
            {key:'active_assistants', label:'استادیار فعال', icon:'👨‍🏫', color:'#2271b1'},
            {key:'active_assignments', label:'تخصیص فعال', icon:'🎯', color:'#00a32a'},
            {key:'capacity_total', label:'ظرفیت کل', icon:'🪑', color:'#8c5db5'},
            {key:'capacity_used', label:'ظرفیت مصرف‌شده', icon:'📌', color:'#dba617'},
            {key:'pending_grades', label:'نمره‌های در انتظار', icon:'📝', color:'#d63638'},
            {key:'pending_levels', label:'تعیین‌سطح‌های باز', icon:'📋', color:'#826eb4'},
            {key:'open_critiques', label:'نقدهای باز', icon:'✍️', color:'#e8890c'},
            {key:'pending_messages', label:'پیام‌های در انتظار', icon:'📬', color:'#2271b1'}
        ];
        var html = '';
        cards.forEach(function (c) {
            html += '<div class="sm-kpi-card" style="--sm-kpi-color:' + c.color + '">' +
                '<span class="sm-kpi-icon" style="color:' + c.color + '">' + c.icon + '</span>' +
                '<span class="sm-kpi-value" style="color:' + c.color + ';font-size:22px">' + formatNum(data[c.key] || 0) + '</span>' +
                '<span class="sm-kpi-label">' + c.label + '</span></div>';
        });
        $('#ta-live-overview-kpis').html(html);
    }

    function fetchStats() {
        if (isLoading) return;
        isLoading = true;
        $('#sm-sales-refresh').prop('disabled', true);
        updateExportLink();

        $.post(SM.ajax_url, postPayload('ta_live_sales_stats'))
            .done(function (r) {
                if (!r || !r.success) {
                    if (window.smToast) smToast((r && r.data && r.data.message) || 'خطا در دریافت آمار', 'error');
                    return;
                }
                renderKpis(r.data.summary, r.data.comparison);
                renderTaOverview(r.data.ta_overview || {});
                lastProvinces = r.data.provinces || [];
                lastCategories = r.data.categories || [];
                lastProducts = r.data.products || [];
                lastByDay = r.data.by_day || [];
                lastByHour = r.data.by_hour || [];
                lastByHourByDay = r.data.by_hour_by_day || {};
                lastDemographics = r.data.demographics || {};
                lastSummary = r.data.summary || {};
                // اگر روزِ انتخاب‌شده در پاسخ تازه دیگر وجود ندارد (مثلاً فیلترها عوض شده) به «همه‌ی روزها» برگرد
                if (selectedDayDate && !lastByHourByDay[selectedDayDate]) selectedDayDate = null;
                renderProvinceBars();
                renderProvinceTable();
                renderCategoryBars();
                renderCategoryTable();
                renderProductBars();
                renderProductTable();
                renderDayBars();
                renderHourBars();
                renderDemoGrid();
                applyCategoryStats();
                $('#sm-sales-updated').text('آخرین به‌روزرسانی: ' + (r.data.generated_at || ''));
            })
            .fail(function () {
                if (window.smToast) smToast('ارتباط با سرور برقرار نشد.', 'error');
            })
            .always(function () {
                isLoading = false;
                $('#sm-sales-refresh').prop('disabled', false);
            });
    }

    function fetchCity(province) {
        $('#sm-sales-city-section').show();
        $('#sm-sales-city-title').text('🏙 شهرستان‌های ' + province);
        $('#sm-sales-city-tbody').html('<tr><td colspan="4"><p class="sm-empty-state" style="padding:16px 0">در حال بارگذاری...</p></td></tr>');

        $.post(SM.ajax_url, postPayload('ta_live_sales_stats_city', { province: province }))
            .done(function (r) {
                if (!r || !r.success) return;
                var rows = r.data.cities || [];
                var $tb = $('#sm-sales-city-tbody').empty();
                if (!rows.length) {
                    $tb.append('<tr><td colspan="4"><p class="sm-empty-state" style="padding:16px 0">داده‌ای برای این استان یافت نشد</p></td></tr>');
                    return;
                }
                rows.forEach(function (c) {
                    $tb.append(
                        '<tr><td>' + escapeHtml(c.label) + '</td>' +
                        '<td>' + formatNum(c.order_count) + '</td>' +
                        '<td>' + formatNum(c.qty) + '</td>' +
                        '<td>' + formatMoney(c.revenue) + '</td></tr>'
                    );
                });
            });
    }

    /** درصد تغییر نسبت به دوره‌ی قبل. اگر دوره‌ی قبل صفر بوده و الان هم صفر است یعنی «بدون تغییر» (null)،
     *  اگر قبل صفر بوده ولی الان مقداری دارد یعنی رشد از صفر («جدید» → Infinity)، در غیر این‌صورت درصد معمولی. */
    function pctChange(cur, prev) {
        cur = Number(cur) || 0;
        prev = Number(prev) || 0;
        if (prev === 0) return cur === 0 ? null : Infinity;
        return ((cur - prev) / Math.abs(prev)) * 100;
    }

    function kpiBadge(pct) {
        if (pct === null) return '<span class="sm-kpi-badge sm-kpi-badge--flat">بدون تغییر</span>';
        if (pct === Infinity) return '<span class="sm-kpi-badge sm-kpi-badge--up">جدید</span>';
        var up = pct >= 0;
        return '<span class="sm-kpi-badge ' + (up ? 'sm-kpi-badge--up' : 'sm-kpi-badge--down') + '">' +
            (up ? '▲' : '▼') + ' ' + formatPercent(Math.abs(pct)) + '٪</span>';
    }

    function renderKpis(s, comparison) {
        s = s || { order_count: 0, item_qty: 0, revenue: 0, aov: 0 };
        var prev = comparison && comparison.summary ? comparison.summary : null;
        var kpis = [
            { icon: '🧾', key: 'order_count', value: formatNum(s.order_count), label: 'تعداد سفارش', color: '#2271b1' },
            { icon: '📦', key: 'item_qty',    value: formatNum(s.item_qty),    label: 'تعداد کالای فروخته‌شده', color: '#8c8f94' },
            { icon: '💰', key: 'revenue',     value: formatMoney(s.revenue),   label: 'جمع فروش', color: '#00a32a' },
            { icon: '📊', key: 'aov',         value: formatMoney(s.aov),       label: 'میانگین هر سفارش', color: '#dba617' }
        ];
        var html = '';
        kpis.forEach(function (k) {
            var badge = prev ? kpiBadge(pctChange(s[k.key], prev[k.key])) : '';
            html += '<div class="sm-kpi-card" style="--sm-kpi-color:' + k.color + '">' +
                '<span class="sm-kpi-icon" style="color:' + k.color + '">' + k.icon + '</span>' +
                '<span class="sm-kpi-value" style="color:' + k.color + ';font-size:22px">' + k.value + '</span>' +
                '<span class="sm-kpi-label">' + k.label + '</span>' +
                badge +
                '</div>';
        });
        $('#sm-sales-kpis').html(html);

        var $hint = $('#sm-sales-kpi-compare-hint');
        if (comparison && comparison.range) {
            $hint.text('📈 مقایسه با دوره‌ی قبل: ' + comparison.range.from_label + ' تا ' + comparison.range.to_label).show();
        } else {
            $hint.hide().empty();
        }
    }

    function renderProvinceBars() {
        var rows = lastProvinces.slice().sort(function (a, b) { return (b[metric] || 0) - (a[metric] || 0); }).slice(0, 10);
        var $box = $('#sm-sales-province-bars').empty();
        if (!rows.length) { $box.append('<p class="sm-empty-state" style="padding:16px 0">داده‌ای یافت نشد</p>'); return; }
        var max = Math.max.apply(null, rows.map(function (r) { return r[metric] || 0; })) || 1;
        rows.forEach(function (r) {
            var pct = Math.round(((r[metric] || 0) / max) * 100);
            var valText = metric === 'revenue' ? formatMoney(r.revenue) : formatNum(r[metric]);
            $box.append(
                '<div class="sm-bar-row sm-sales-province-bar" data-province="' + escapeHtml(r.label) + '" style="cursor:pointer">' +
                '<span class="sm-bar-label">' + escapeHtml(r.label) + '</span>' +
                '<div class="sm-bar-track"><div class="sm-bar-fill" style="width:' + pct + '%"></div></div>' +
                '<span class="sm-bar-value">' + valText + '</span></div>'
            );
        });
    }

    function renderCategoryBars() {
        var rows = lastCategories.slice().sort(function (a, b) { return (b[categoryMetric] || 0) - (a[categoryMetric] || 0); }).slice(0, 10);
        var $box = $('#sm-sales-category-bars').empty();
        if (!rows.length) { $box.append('<p class="sm-empty-state" style="padding:16px 0">داده‌ای یافت نشد</p>'); return; }
        var max = Math.max.apply(null, rows.map(function (r) { return r[categoryMetric] || 0; })) || 1;
        rows.forEach(function (r) {
            var pct = Math.round(((r[categoryMetric] || 0) / max) * 100);
            var valText = categoryMetric === 'revenue' ? formatMoney(r.revenue) : formatNum(r[categoryMetric]);
            $box.append(
                '<div class="sm-bar-row">' +
                '<span class="sm-bar-label">' + escapeHtml(r.name) + '</span>' +
                '<div class="sm-bar-track"><div class="sm-bar-fill" style="width:' + pct + '%;background:#dba617"></div></div>' +
                '<span class="sm-bar-value">' + valText + '</span></div>'
            );
        });
    }

    function bindCategoryMetric() {
        $(document).on('change', '#sm-sales-category-metric', function () {
            categoryMetric = $(this).val();
            renderCategoryBars();
        });
    }

    // ─── فروش به تفکیک محصول ────────────────────────────────────────────────
    // (میله‌ای، هم‌شکل با بقیه‌ی نمودارهای این صفحه — نه خط نقطه‌ای)

    function renderProductBars() {
        var rows = lastProducts.slice().sort(function (a, b) { return (b[productMetric] || 0) - (a[productMetric] || 0); }).slice(0, 20);
        var $box = $('#sm-sales-product-bars').empty();
        if (!rows.length) { $box.append('<p class="sm-empty-state" style="padding:16px 0">داده‌ای برای این فیلترها یافت نشد</p>'); return; }
        var max = Math.max.apply(null, rows.map(function (r) { return r[productMetric] || 0; })) || 1;
        rows.forEach(function (r) {
            var pct = Math.round(((r[productMetric] || 0) / max) * 100);
            var valText = productMetric === 'revenue' ? formatMoney(r.revenue) : formatNum(r[productMetric]);
            $box.append(
                '<div class="sm-bar-row">' +
                '<span class="sm-bar-label" title="' + escapeHtml(r.name) + '">' + escapeHtml(r.name) + '</span>' +
                '<div class="sm-bar-track"><div class="sm-bar-fill" style="width:' + pct + '%;background:#e8890c"></div></div>' +
                '<span class="sm-bar-value">' + valText + '</span></div>'
            );
        });
    }

    function renderProductTable() {
        var $tb = $('#sm-sales-product-tbody').empty();
        var $tf = $('#sm-sales-product-tfoot').empty();
        if (!lastProducts.length) {
            $tb.append('<tr><td colspan="3"><p class="sm-empty-state" style="padding:20px 0">داده‌ای برای این فیلترها یافت نشد</p></td></tr>');
            return;
        }
        lastProducts.forEach(function (p) {
            $tb.append(
                '<tr><td>' + escapeHtml(p.name) + '</td>' +
                '<td>' + formatNum(p.qty) + '</td>' +
                '<td>' + formatMoney(p.revenue) + '</td></tr>'
            );
        });
        $tf.append(
            '<tr style="font-weight:700;background:var(--sm-bg,#f6f7f7)">' +
            '<td>جمع کل</td>' +
            '<td>' + formatNum(lastSummary.item_qty) + '</td>' +
            '<td>' + formatMoney(lastSummary.revenue) + '</td></tr>'
        );
    }

    function bindProductMetric() {
        $(document).on('change', '#sm-sales-product-metric', function () {
            productMetric = $(this).val();
            renderProductBars();
        });
    }

    // ─── آمار بر اساس روز ───────────────────────────────────────────────────
    // (خط نقطه‌ای Chart.js — مثل نموداری که «فروش به تفکیک محصول» قبلاً داشت.
    //  کلیک روی هر نقطه، همان روز را برای فیلترکردنِ نمودار «آمار بر اساس ساعت» انتخاب می‌کند)

    var dayChart = null;

    function renderDayBars() {
        var rows = lastByDay || [];
        var $canvas = $('#sm-sales-day-chart');
        if (!$canvas.length) return;

        function build() {
            if (dayChart) { dayChart.destroy(); dayChart = null; }
            if (!rows.length) return;
            var labels = rows.map(function (r) { return r.label; });
            var data = rows.map(function (r) { return r[dayMetric] || 0; });
            var pointColors = rows.map(function (r) { return r.date === selectedDayDate ? '#d63638' : '#2271b1'; });
            var pointRadii  = rows.map(function (r) { return r.date === selectedDayDate ? 6 : 3; });
            dayChart = new Chart($canvas[0], {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: dayMetric === 'revenue' ? 'مبلغ فروش' : (dayMetric === 'order_count' ? 'تعداد سفارش' : 'تعداد کالا'),
                        data: data,
                        borderColor: '#2271b1',
                        backgroundColor: 'rgba(34,113,177,.15)',
                        borderWidth: 2,
                        tension: 0.25,
                        fill: true,
                        pointBackgroundColor: pointColors,
                        pointBorderColor: pointColors,
                        pointRadius: pointRadii,
                        pointHoverRadius: 7
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick: function (evt, elements) {
                        if (!elements || !elements.length) return;
                        var row = rows[elements[0].index];
                        if (!row || !row.date) return;
                        selectedDayDate = (selectedDayDate === row.date) ? null : row.date; // کلیک دوباره روی همان روز = پاک‌کردن انتخاب
                        renderDayBars();
                        renderHourBars();
                    },
                    onHover: function (evt, elements) {
                        if (evt.native && evt.native.target) {
                            evt.native.target.style.cursor = (elements && elements.length) ? 'pointer' : 'default';
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    return dayMetric === 'revenue' ? formatMoney(ctx.parsed.y) : formatNum(ctx.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { callback: function (v) { return formatNum(v); } } },
                        x: { ticks: { autoSkip: true, maxRotation: 60, minRotation: 30 } }
                    }
                }
            });
        }

        if (typeof Chart === 'undefined') {
            if (window.smLoadChartJs) window.smLoadChartJs(build); else return;
        } else {
            build();
        }
    }

    // ─── آمار بر اساس ساعت ──────────────────────────────────────────────────
    // با کلیک روی یک روز در نمودار بالا، این نمودار فقط همان روز را نشان می‌دهد؛
    // در غیر این‌صورت (بدون انتخاب روز) مجموع همه‌ی روزهاست.

    var hourChart = null;

    function updateHourDayLabel() {
        var $label = $('#sm-sales-hour-day-label');
        if (!$label.length) return;
        if (selectedDayDate) {
            var row = (lastByDay || []).filter(function (r) { return r.date === selectedDayDate; })[0];
            $label.html('⏱ فقط برای روز <strong>' + escapeHtml(row ? row.label : selectedDayDate) + '</strong> — <a href="#" id="sm-sales-hour-day-clear">نمایش همه‌ی روزها</a>').show();
        } else {
            $label.hide().empty();
        }
    }

    function renderHourBars() {
        var rows = (selectedDayDate && lastByHourByDay[selectedDayDate]) ? lastByHourByDay[selectedDayDate] : (lastByHour || []);
        updateHourDayLabel();

        var $canvas = $('#sm-sales-hour-chart');
        if (!$canvas.length) return;

        function build() {
            if (hourChart) { hourChart.destroy(); hourChart = null; }
            if (!rows.length) return;
            var labels = rows.map(function (r) { return r.label; });
            var data = rows.map(function (r) { return r[hourMetric] || 0; });
            hourChart = new Chart($canvas[0], {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: hourMetric === 'revenue' ? 'مبلغ فروش' : (hourMetric === 'order_count' ? 'تعداد سفارش' : 'تعداد کالا'),
                        data: data,
                        borderColor: '#8c8f94',
                        backgroundColor: 'rgba(140,143,148,.15)',
                        borderWidth: 2,
                        tension: 0.25,
                        fill: true,
                        pointBackgroundColor: '#8c8f94',
                        pointRadius: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    return hourMetric === 'revenue' ? formatMoney(ctx.parsed.y) : formatNum(ctx.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { callback: function (v) { return formatNum(v); } } },
                        x: { ticks: { autoSkip: true, maxRotation: 60, minRotation: 30 } }
                    }
                }
            });
        }

        if (typeof Chart === 'undefined') {
            if (window.smLoadChartJs) window.smLoadChartJs(build); else return;
        } else {
            build();
        }
    }

    function bindDayHourMetric() {
        $(document).on('change', '#sm-sales-day-metric', function () {
            dayMetric = $(this).val();
            renderDayBars();
        });
        $(document).on('change', '#sm-sales-hour-metric', function () {
            hourMetric = $(this).val();
            renderHourBars();
        });
        $(document).on('click', '#sm-sales-hour-day-clear', function (e) {
            e.preventDefault();
            selectedDayDate = null;
            renderDayBars();
            renderHourBars();
        });
    }

    // ─── آمار جمعیت‌شناختی سفارش‌ها (جنسیت، تحصیلات، شغل، ...) ─────────────────

    function renderDemoGrid() {
        var $grid = $('#sm-sales-demo-grid').empty();
        var any = false;
        SM_SALES_DEMO_FIELDS.forEach(function (f) {
            var rows = (lastDemographics && lastDemographics[f.key]) || [];
            if (!rows.length) return;
            any = true;
            var top = rows.slice(0, 8);
            var max = Math.max.apply(null, top.map(function (r) { return r.count || 0; })) || 1;
            var $card = $('<div class="sm-chart-card sm-mini-chart-card"></div>');
            $card.append('<h4>' + f.title + '</h4>');
            top.forEach(function (r) {
                var pct = Math.round(((r.count || 0) / max) * 100);
                $card.append(
                    '<div class="sm-bar-row">' +
                    '<span class="sm-bar-label" title="' + escapeHtml(r.label) + '">' + escapeHtml(r.label) + '</span>' +
                    '<div class="sm-bar-track"><div class="sm-bar-fill" style="width:' + pct + '%;background:#9c27b0"></div></div>' +
                    '<span class="sm-bar-value">' + formatNum(r.count) + '</span></div>'
                );
            });
            $grid.append($card);
        });
        if (!any) {
            $grid.append('<p class="sm-empty-state" style="padding:16px 0">داده‌ی تکمیلی‌ای برای این سفارش‌ها یافت نشد (این فیلدها فقط برای سفارش محصولات خاصی ثبت می‌شوند)</p>');
        }
    }

    function renderProvinceTable() {
        var $tb = $('#sm-sales-province-tbody').empty();
        var $tf = $('#sm-sales-province-tfoot').empty();
        if (!lastProvinces.length) {
            $tb.append('<tr><td colspan="5"><p class="sm-empty-state" style="padding:20px 0">داده‌ای برای این فیلترها یافت نشد</p></td></tr>');
            return;
        }
        var revenueBase = lastSummary.revenue || 1;
        lastProvinces.forEach(function (r) {
            var pct = Math.round(((r.revenue || 0) / revenueBase) * 1000) / 10;
            $tb.append(
                '<tr class="sm-sales-province-row" data-province="' + escapeHtml(r.label) + '" style="cursor:pointer">' +
                '<td><strong>' + escapeHtml(r.label) + '</strong></td>' +
                '<td>' + formatNum(r.order_count) + '</td>' +
                '<td>' + formatNum(r.qty) + '</td>' +
                '<td>' + formatMoney(r.revenue) + '</td>' +
                '<td>' + pct + '٪</td></tr>'
            );
        });
        // هر سفارش دقیقاً یک استان دارد (بر خلاف محصول/دسته‌بندی که یک سفارش می‌تواند چندتا داشته باشد)
        // پس اینجا جمع‌زدن ردیف‌ها هم با summary واقعی برابر است — با این‌حال برای یکدستی از خودِ summary استفاده می‌کنیم.
        $tf.append(
            '<tr style="font-weight:700;background:var(--sm-bg,#f6f7f7)">' +
            '<td>جمع کل</td>' +
            '<td>' + formatNum(lastSummary.order_count) + '</td>' +
            '<td>' + formatNum(lastSummary.item_qty) + '</td>' +
            '<td>' + formatMoney(lastSummary.revenue) + '</td>' +
            '<td>۱۰۰٪</td></tr>'
        );
    }

    function renderCategoryTable() {
        var $tb = $('#sm-sales-category-tbody').empty();
        var $tf = $('#sm-sales-category-tfoot').empty();
        if (!lastCategories.length) {
            $tb.append('<tr><td colspan="4"><p class="sm-empty-state" style="padding:20px 0">داده‌ای برای این فیلترها یافت نشد</p></td></tr>');
            return;
        }
        lastCategories.forEach(function (c) {
            $tb.append(
                '<tr><td>' + escapeHtml(c.name) + '</td>' +
                '<td>' + formatNum(c.order_count) + '</td>' +
                '<td>' + formatNum(c.qty) + '</td>' +
                '<td>' + formatMoney(c.revenue) + '</td></tr>'
            );
        });
        // یک محصول می‌تواند در چند دسته‌بندی هم‌زمان باشد، پس «تعداد سفارش» جمع کل از summary واقعی می‌آید
        // نه از جمع‌زدن ردیف‌ها (وگرنه سفارش‌های مشترک چندبار شمرده می‌شوند).
        $tf.append(
            '<tr style="font-weight:700;background:var(--sm-bg,#f6f7f7)">' +
            '<td>جمع کل</td>' +
            '<td>' + formatNum(lastSummary.order_count) + '</td>' +
            '<td>' + formatNum(lastSummary.item_qty) + '</td>' +
            '<td>' + formatMoney(lastSummary.revenue) + '</td></tr>'
        );
    }

    /** بعد از هر پاسخ موفق sm_sales_stats، آمار هر چیپ دسته‌بندی را از data.categories پر می‌کند
     *  (اگر دسته‌ای هیچ سفارشی نداشته باشد اصلاً در پاسخ نیست، پس صفر در نظر گرفته می‌شود
     *  — وگرنه لودینگ چیپ آن هیچ‌وقت تمام نمی‌شد). */
    function applyCategoryStats() {
        var byId = {};
        lastCategories.forEach(function (c) { byId[c.id] = c; });
        selectedCats.forEach(function (cat) {
            var match = byId[cat.id];
            cat.stat = match
                ? { order_count: match.order_count, qty: match.qty, revenue: match.revenue }
                : { order_count: 0, qty: 0, revenue: 0 };
        });
        renderCatChips();
    }

    function renderCatChips() {
        var $c = $('#sm-sales-cat-chips').empty();
        selectedCats.forEach(function (cat, idx) {
            var $chip = $('<span class="sm-product-chip sm-sales-cat-chip"><span class="sm-chip-label"></span></span>');
            $chip.find('.sm-chip-label').text(cat.name);

            if (cat.stat === null || cat.stat === undefined) {
                // دسته‌بندی تازه‌انتخاب‌شده — هنوز آمارش نیامده
                $chip.append('<span class="sm-sales-chip-stat sm-sales-chip-stat--loading"><span class="sm-sales-mini-spinner"></span></span>');
            } else {
                $chip.append('<span class="sm-sales-chip-stat">🧾 ' + formatNum(cat.stat.order_count) + '</span>');
            }

            var $remove = $('<button type="button" class="sm-sales-chip-remove" title="حذف">✕</button>').data('idx', idx);
            $chip.append($remove);
            $c.append($chip);
        });
    }

    // ─── محصولات دسته‌بندی‌های انتخابی ──────────────────────────────────────

    function fetchCategoryProducts() {
        var ids = selectedCats.map(function (c) { return c.id; });
        var seq = ++productsFetchSeq;

        if (!ids.length) {
            categoryProducts = [];
            excludedProductIds = [];
            $('#sm-sales-product-panel').hide();
            $('#sm-sales-product-list').empty();
            return;
        }

        $('#sm-sales-product-panel').show();
        $('#sm-sales-product-list').html('<p class="sm-sales-product-list-empty">در حال بارگذاری محصولات...</p>');
        $('#sm-sales-product-count').text('');

        $.post(SM.ajax_url, { action: 'ta_live_sales_stats_category_products', _ajax_nonce: SM.nonce, category_ids: ids })
            .done(function (r) {
                if (seq !== productsFetchSeq) return; // پاسخ قدیمی — دسته‌بندی‌ها از آن زمان عوض شده‌اند
                var products = (r && r.success && r.data && r.data.products) ? r.data.products : [];
                categoryProducts = products;
                var validIds = {};
                products.forEach(function (p) { validIds[p.id] = true; });
                // فقط عدم‌انتخاب‌های مربوط به محصولات هنوز-موجود در لیست نگه داشته می‌شود
                excludedProductIds = excludedProductIds.filter(function (id) { return validIds[id]; });
                renderProductList();
            })
            .fail(function () {
                if (seq !== productsFetchSeq) return;
                $('#sm-sales-product-list').html('<p class="sm-sales-product-list-empty">خطا در دریافت لیست محصولات.</p>');
            });
    }

    function renderProductList() {
        var $list = $('#sm-sales-product-list').empty();
        if (!categoryProducts.length) {
            $list.append('<p class="sm-sales-product-list-empty">محصولی در این دسته‌بندی(ها) یافت نشد.</p>');
            $('#sm-sales-product-count').text('');
            return;
        }
        categoryProducts.forEach(function (p) {
            var checked = excludedProductIds.indexOf(p.id) === -1;
            var $item = $('<label class="sm-checkbox-item"></label>');
            var $cb = $('<input type="checkbox" class="sm-sales-product-check">').val(p.id).prop('checked', checked);
            $item.append($cb).append(' ' + escapeHtml(p.name));
            $list.append($item);
        });
        var includedCount = categoryProducts.length - excludedProductIds.length;
        $('#sm-sales-product-count').text('(' + formatNum(includedCount) + ' از ' + formatNum(categoryProducts.length) + ' انتخاب‌شده)');
    }

    function bindProductPanel() {
        $(document).on('change', '.sm-sales-product-check', function () {
            var id = parseInt($(this).val(), 10);
            var idx = excludedProductIds.indexOf(id);
            if ($(this).is(':checked')) {
                if (idx !== -1) excludedProductIds.splice(idx, 1);
            } else if (idx === -1) {
                excludedProductIds.push(id);
            }
            $('#sm-sales-product-count').text('(' + formatNum(categoryProducts.length - excludedProductIds.length) + ' از ' + formatNum(categoryProducts.length) + ' انتخاب‌شده)');
            fetchStats();
        });
        $(document).on('click', '#sm-sales-products-select-all', function () {
            excludedProductIds = [];
            renderProductList();
            fetchStats();
        });
        $(document).on('click', '#sm-sales-products-select-none', function () {
            excludedProductIds = categoryProducts.map(function (p) { return p.id; });
            renderProductList();
            fetchStats();
        });
    }

    function escapeHtml(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    // ─── فیلترها ────────────────────────────────────────────────────────────

    function bindFilters() {
        $(document).on('change', '.sm-sales-status, #sm-sales-date-from, #sm-sales-date-to', fetchStats);
        $(document).on('click', '#sm-sales-refresh', fetchStats);

        $(document).on('click', '#sm-sales-reset', function () {
            selectedCats = [];
            renderCatChips();
            fetchCategoryProducts();
            $('#sm-sales-date-from,#sm-sales-date-to').val('');
            $('.sm-sales-status').prop('checked', false);
            $('.sm-sales-status[value="completed"],.sm-sales-status[value="processing"]').prop('checked', true);
            fetchStats();
        });

        $(document).on('change', '#sm-sales-live', function () {
            var ms = parseInt($(this).val(), 10) || 0;
            if (liveTimer) { clearInterval(liveTimer); liveTimer = null; }
            $('#sm-sales-live-dot').toggle(ms > 0);
            if (ms > 0) liveTimer = setInterval(fetchStats, ms);
        });
        $('#sm-sales-live').trigger('change'); // فعال‌سازی حالت پیش‌فرض (هر ۳۰ ثانیه)
    }

    function bindProvinceMetric() {
        $(document).on('change', '#sm-sales-metric', function () {
            metric = $(this).val();
            renderProvinceBars();
        });
    }

    function bindCityPanel() {
        $(document).on('click', '.sm-sales-province-bar, .sm-sales-province-row', function () {
            fetchCity($(this).data('province'));
        });
        $(document).on('click', '#sm-sales-city-close', function () {
            $('#sm-sales-city-section').hide();
        });
    }

    function bindCategoryPicker() {
        $(document).on('click', '#sm-sales-cat-search', function (e) {
            e.stopPropagation();
            var $dd = $('#sm-sales-cat-dropdown');
            if ($dd.is(':visible')) { $dd.hide(); return; }
            $('#sm-sales-cat-dropdown li').each(function () {
                var id = $(this).data('id');
                $(this).toggle(!selectedCats.some(function (c) { return c.id === id; }));
            });
            $dd.show();
        });
        $(document).on('input', '#sm-sales-cat-search', function () {
            var q = $(this).val().trim().toLowerCase();
            $('#sm-sales-cat-dropdown li').each(function () {
                var id = $(this).data('id');
                if (selectedCats.some(function (c) { return c.id === id; })) { $(this).hide(); return; }
                var text = ($(this).data('name') || '').toString().toLowerCase();
                $(this).toggle(q === '' || text.indexOf(q) !== -1);
            });
            $('#sm-sales-cat-dropdown').show();
        });
        $(document).on('click', '.sm-sales-cat-option', function () {
            var id = $(this).data('id'), name = $(this).data('name');
            if (!selectedCats.some(function (c) { return c.id === id; })) {
                selectedCats.push({ id: id, name: name, stat: null }); // stat=null → چیپ لودینگ نشان می‌دهد تا پاسخ بعدی برسد
                renderCatChips();
                fetchCategoryProducts();
                fetchStats();
            }
            $('#sm-sales-cat-dropdown').hide();
            $('#sm-sales-cat-search').val('');
        });
        $(document).on('click', '.sm-sales-chip-remove', function () {
            selectedCats.splice($(this).data('idx'), 1);
            renderCatChips();
            fetchCategoryProducts();
            fetchStats();
        });
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.sm-sales-cat-picker-wrap').length) $('#sm-sales-cat-dropdown').hide();
        });
    }

})(jQuery);
