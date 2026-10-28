(function ($) {
    'use strict';
    function ensureToastStack() {
        var $stack = $('#sm-toast-stack');
        if (!$stack.length) $stack = $('<div id="sm-toast-stack"></div>').appendTo('body');
        return $stack;
    }
    function removeToast($toast) {
        $toast.addClass('sm-toast--leaving');
        setTimeout(function () { $toast.remove(); }, 250);
    }
    function smToast(message, type, timeout) {
        type = type || 'info'; timeout = timeout || 4200;
        var icons = {success: '✓', error: '!', info: 'i'};
        var $toast = $('<div class="sm-toast sm-toast--' + type + '"><span class="sm-toast-icon">' + (icons[type] || icons.info) + '</span><span class="sm-toast-msg"></span><button type="button" class="sm-toast-close">×</button></div>');
        $toast.find('.sm-toast-msg').text(message);
        ensureToastStack().append($toast);
        var timer = setTimeout(function () { removeToast($toast); }, timeout);
        $toast.on('click', '.sm-toast-close', function () { clearTimeout(timer); removeToast($toast); });
        return $toast;
    }
    window.smToast = smToast;
    function loadChartJs(callback) {
        if (typeof window.Chart !== 'undefined') { callback(); return; }
        var script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';
        script.onload = callback;
        script.onerror = function () { smToast('کتابخانه نمودار بارگذاری نشد.', 'error'); };
        document.head.appendChild(script);
    }
    window.smLoadChartJs = loadChartJs;
})(jQuery);
