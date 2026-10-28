/**
 * TA System — Frontend JavaScript
 */

(function ($) {
    'use strict';

    const ajax  = taSystem.ajaxUrl;
    const nonce = taSystem.nonce;

    // ─────────────────────────────────────────────────────────────────────────
    // Helper utilities
    // ─────────────────────────────────────────────────────────────────────────

    // هر ویجت (مثلاً نسخه دسکتاپ یا موبایل) باید DOM مستقل خودش را داشته باشد.
    function widgetRoot(source) {
        if (source && source.nodeType === 1) return source.closest('[data-ta-widget="1"]') || source;
        return window.__taActiveWidget || null;
    }
    function eachEl(id, callback, source) {
        var root = widgetRoot(source);
        (root ? root.querySelectorAll('#' + id) : document.querySelectorAll('#' + id)).forEach(callback);
    }
    function showEl(id, source)  { eachEl(id, function (el) { el.style.display = ''; }, source); }
    function hideEl(id, source)  { eachEl(id, function (el) { el.style.display = 'none'; }, source); }
    function setHtml(id, html, source) { eachEl(id, function (el) { el.innerHTML = html; }, source); }

    function showMsg(id, msg, type, source) {
        eachEl(id, function (el) {
            el.className = 'ta-message ta-message-' + (type || 'info');
            el.innerHTML = msg;
            el.style.display = '';
        }, source);
    }

    function taValidMessengerLink(messenger, link) {
        var value = String(link || '').trim();
        if (messenger === 'bale') return /^https:\/\/ble\.ir\//i.test(value);
        if (messenger === 'eitaa') return /^https:\/\/eitaa\.com\//i.test(value);
        return true;
    }

    function taResolvedMessenger(root, fallback) {
        var messenger = root?.querySelector('#ta-messenger')?.value || '';
        if (!messenger || messenger === 'auto') {
            messenger = root?.querySelector('#ta-msglink-messenger-input')?.value || '';
        }
        return messenger || fallback || 'auto';
    }

    function post(data, callback) {
        data.nonce = nonce;
        var debugAvailability = data.action === 'ta_get_available_tas';
        var started = performance.now();
        $.ajax({ url: ajax, type: 'POST', data: data, dataType: 'json', timeout: debugAvailability ? 15000 : 0 })
            .done(function (res, textStatus, jqXHR) {
                callback(res);
            })
            .fail(function (jqXHR, textStatus, errorThrown) {
                callback({ success: false, data: { message: 'خطای اتصال. لطفاً دوباره تلاش کنید.' } });
            });
    }

    /**
     * نمایش باکس موفقیت پس از تخصیص — به جای ریدایرکت
     * پیام و لینک گروه را نشان می‌دهد، ریدایرکت نمی‌کند
     */
    window.taShowAssignSuccess = function(message, groupLink, $btn) {
        // ساخت HTML دقیقاً مطابق با render_already_assigned در PHP
        var successHtml = '<div class="ta-message ta-message-success">';
        successHtml += '<p>' + (message || '✅ استادیار با موفقیت ثبت شد.') + '</p>';
        if (groupLink) {
            successHtml += '<p><a href="' + groupLink + '" class="ta-group-entry-btn" target="_blank" rel="noopener">ورود به گروه</a></p>';
        }
        successHtml += '</div>';

        // پیدا کردن widget — همیشه باید وجود داشته باشد
        var source = $btn && $btn.jquery ? $btn[0] : $btn;
        var widget = widgetRoot(source) || document.querySelector('[data-ta-widget="1"]') || document.querySelector('.ta-widget');
        if (!widget) {
            // آخرین fallback اگر widget پیدا نشد
            $('body').prepend('<div style="position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:99999;min-width:320px;">' + successHtml + '</div>');
            return;
        }

        // حذف همه محتوای widget به جز header
        var header = widget.querySelector('.ta-widget-header');
        widget.innerHTML = '';
        if (header) widget.appendChild(header);

        // اضافه کردن پیام موفقیت داخل widget
        widget.insertAdjacentHTML('beforeend', successHtml);
    };

    function postForm(formData, callback) {
        formData.append('nonce', nonce);
        $.ajax({
            url: ajax,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: callback,
            error: function () {
                callback({ success: false, data: { message: 'خطای اتصال.' } });
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // صفحه محصول — جستجوی استادیار
    // ─────────────────────────────────────────────────────────────────────────

    window.taCancelGroupForm = function (capId) {
    var form = document.getElementById('add-group-form-' + capId);
    if (!form) return;

    // پاک کردن فیلدها
    var messengerEl = document.getElementById('group-messenger-' + capId);
    var linkEl      = document.getElementById('group-link-' + capId);
    if (messengerEl) messengerEl.value = '';
    if (linkEl)      linkEl.value      = '';

    // برگرداندن دکمه به حالت «افزودن»
    var btn = form.querySelector('button');
    btn.textContent = 'افزودن';
    btn.className   = 'ta-btn ta-btn-primary ta-btn-sm';
    btn.onclick     = function () { taAddGroupSlot(capId); };

    // برگرداندن استایل فرم به حالت اولیه
    form.style.border  = '';
    form.style.padding = '';
    form.style.display = 'none';
};

    
    
    // ── در صورتی که هنرجو پیام‌رسان/لینک گروه پیش‌نیاز را ویرایش کرده باشد،
    //    پیش از جستجو/تأیید آن را روی تخصیص دوره پیش‌نیاز ذخیره می‌کند ──
    // cb(true) برای ادامه، cb(false) برای توقف (خطا قبلاً نمایش داده شده)
    function taMaybeSavePrereqMsgLink(productId, cb, source) {
        var root = widgetRoot(source);
        var wrap = root?.querySelector('#ta-msglink-wrap');
        var editBox = root?.querySelector('#ta-msglink-edit-box');
        if (!wrap) { cb(true); return; }
        hideEl('ta-msglink-error', root);
        var confirmed = root?.querySelector('#ta-msglink-confirm-checkbox')?.checked || false;
        if (wrap.getAttribute('data-repeat-source') === '1' && (!editBox || editBox.style.display === 'none')) {
            if (!confirmed) {
                showMsg('ta-msglink-error', 'لطفاً با فعال‌کردن چک‌باکس، صحت پیام‌رسان و لینک گروه را تأیید کنید.', 'error', root);
                cb(false);
                return;
            }
            cb(true);
            return;
        }
        if (editBox && editBox.style.display !== 'none') {
            var messenger = root?.querySelector('#ta-msglink-messenger-input')?.value || '';
            var link = root?.querySelector('#ta-msglink-link-input')?.value.trim() || '';
            if (!messenger) { showMsg('ta-msglink-error', 'لطفاً پیام‌رسان را انتخاب کنید.', 'error', root); cb(false); return; }
            if (link && !/^https?:\/\//i.test(link)) {
                link = 'https://' + link;
                var linkInput = root?.querySelector('#ta-msglink-link-input');
                if (linkInput) linkInput.value = link;
            }
            if (!link) { showMsg('ta-msglink-error', 'لطفاً لینک گروه را وارد کنید.', 'error', root); cb(false); return; }
            if (!taValidMessengerLink(messenger, link)) {
                showMsg('ta-msglink-error', 'لینک گروه با پیام‌رسان انتخاب‌شده مطابقت ندارد.', 'error', root);
                cb(false);
                return;
            }
            if (!confirmed) { showMsg('ta-msglink-error', 'لطفاً با فعال‌کردن چک‌باکس، صحت پیام‌رسان و لینک گروه را تأیید کنید.', 'error', root); cb(false); return; }
            post({ action:'ta_update_prereq_group_info', product_id:productId, messenger:messenger, group_link:link }, function(res) {
                if (!res.success) { showMsg('ta-msglink-error', res.data?.message || 'خطا در ذخیره اطلاعات پیام‌رسان/لینک گروه.', 'error', root); cb(false); return; }
                hideEl('ta-msglink-error', root); cb(true);
            });
        } else {
            if (!confirmed) { showMsg('ta-msglink-error', 'لطفاً با فعال‌کردن چک‌باکس، صحت پیام‌رسان و لینک گروه را تأیید کنید.', 'error', root); cb(false); return; }
            hideEl('ta-msglink-error', root); cb(true);
        }
    }

    // ── جستجو با پیامرسان/جنسیت (خلاق، پایه، سایر) ──
    window.taSearchTAs = function (source) {
        var root = widgetRoot(source);
        window.__taActiveWidget = root;
        var messenger = taResolvedMessenger(root, 'auto');
        var gender    = root?.querySelector('#ta-gender')?.value;

        if ((taCourseType === 'creative' || taCourseType === 'basic_writing') && !messenger) {
            showMsg('ta-error', 'لطفاً پیام‌رسان را انتخاب کنید.', 'error', root);
            return;
        }
        if (!gender) {
            showMsg('ta-error', 'لطفاً جنسیت را انتخاب کنید.', 'error', root);
            return;
        }

        taMaybeSavePrereqMsgLink(taSystem.productId, function (ok) {
            if (!ok) return;
            hideEl('ta-error', root);
            showEl('ta-loading', root);
            hideEl('ta-step-1', root);
            taFetchTAs(messenger || 'auto', gender, function(res) {
                hideEl('ta-loading', root);
                if (!res || !res.success || !res.data || !Array.isArray(res.data.tas) || !res.data.tas.length) {
                    showEl('ta-step-1', root);
                    showMsg('ta-error', res?.data?.message || 'استادیاری با این مشخصات یافت نشد.', 'error', root);
                    return;
                }
                taRenderTAList(res.data.tas, root);
                showEl('ta-step-2', root);
            });
        }, root);
    };

    // ── لود خودکار برای نقد اثر و حرفه‌ای (بدون پیامرسان/جنسیت) ──
    window.taAutoLoadTAs = function (source) {
        var root = widgetRoot(source);
        if (!root || root.__taAutoLoadStarted) return;
        root.__taAutoLoadStarted = true;
        showEl('ta-loading', root);
        hideEl('ta-error', root);
        taFetchTAs('any', 'any', function(res) {
            hideEl('ta-loading', root);
            if (!res || !res.success || !res.data || !Array.isArray(res.data.tas) || !res.data.tas.length) {
                showMsg('ta-error', res?.data?.message || 'در حال حاضر ظرفیتی موجود نیست.', 'error', root);
                return;
            }
            taRenderTAList(res.data.tas, root);
            showEl('ta-ta-list', root);
        });
    };

    // ── توابع مشترک ──
    function taFetchTAs(messenger, gender, cb) {
        post({ action: 'ta_get_available_tas', product_id: taSystem.productId, messenger: messenger, student_gender: gender }, cb);
    }

    function taRenderTAList(tas, root) {
        var html = '';
        tas.forEach(function (ta) {
            html += '<div class="ta-ta-card"><div><div class="ta-ta-card-name">' + ta.name + '</div><div class="ta-ta-card-info">ظرفیت آزاد: ' + ta.remaining + '</div></div>' +
                '<button type="button" class="ta-group-entry-btn" onclick="taSelectTA(' + ta.id + ', \'\', this)">ورود به گروه</button></div>';
        });
        setHtml('ta-ta-list', html, root);
    }

    window.taRenderTAList = taRenderTAList;

    window.taBackToStep1 = function (source) {
        var root = widgetRoot(source);
        window.__taActiveWidget = root;
        hideEl('ta-step-2', root);
        showEl('ta-step-1', root);
    };

       //-------------------------
    // تخصیص استادیار دوره حرفه‌ای
    //-------------------------
    $(document).on('click', '.ta-assign-professional', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var productId = $btn.data('product-id');
        var taId = $btn.data('ta-id');

        if (taSystem.isLoggedIn == 0) {
            window.location.href = taSystem.loginUrl;
            return;
        }

        $btn.prop('disabled', true).css('opacity', '0.6').text('در حال بررسی و تخصیص...');

        $.ajax({
            url: taSystem.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ta_assign_professional',
                product_id: productId,
                ta_id: taId,
                nonce: taSystem.nonce
            },
            success: function(response) {
                if (response.success) {
                    $btn.closest('form, .ta-widget, .ta-product-box, .ta-step, div').first().find('.ta-assign-professional').prop('disabled', true);
                    taShowAssignSuccess(response.data.message, response.data.group_link, $btn);
                } else {
                    alert(response.data.message || 'خطا در تخصیص استادیار.');
                    $btn.prop('disabled', false).css('opacity', '1').text('ورود به گروه');
                }
            },
            error: function() {
                alert('خطا در ارتباط با سرور. لطفاً مجدداً تلاش کنید.');
                $btn.prop('disabled', false).css('opacity', '1').text('ورود به گروه');
            }
        });
    });

//-------------------------
$(document).on('click', '.ta-assign-critique', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var productId = $btn.data('product-id');


    if (taSystem.isLoggedIn == 0) {
        window.location.href = taSystem.loginUrl;
        return;
    }

    $btn.prop('disabled', true).css('opacity', '0.6').text('در حال ارتباط با سرور...');
   
    $.ajax({
    url: ajax,
    type: 'POST',
    data: {
        action: 'ta_assign_critique',
        product_id: productId,
        nonce: taSystem.nonce  // 👈 این خط باید حتماً اضافه شود
    },
            success: function(response) {
            if (response.success) {
                taShowAssignSuccess(response.data.message || 'با موفقیت به نقد اثر پیوستید.', response.data.group_link, $btn);
            } else {
                alert(response.data.message || 'خطا در پیوستن به نقد اثر.');
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {            
            alert('خطا در برقراری ارتباط با سرور. لطفاً مجدداً تلاش کنید.');
        },
        complete: function() {
            $btn.prop('disabled', false).css('opacity', '1').text('🔗 پیوستن به نقد اثر');
        }
    });
});








    // ─────────────────────────────────────────────────────────────────────────
    // صفحه محصول — انتخاب استادیار
    // ─────────────────────────────────────────────────────────────────────────
       window.taSelectTA = function (taId, customLink = '', source) {
        var root = widgetRoot(source);
        window.__taActiveWidget = root;
        var messenger = taResolvedMessenger(root, 'auto');
        var displayedGroupLink = root?.querySelector('#ta-msglink-link-input')?.value.trim() || '';
        var gender    = (root?.querySelector('#ta-gender-select')?.value || root?.querySelector('#ta-gender')?.value || '');

        if (!gender) {
            showMsg('ta-error', 'لطفاً جنسیت را انتخاب کنید.', 'error', root);
            return;
        }

        hideEl('ta-step-2', root);
        showEl('ta-loading', root);

        // ── مسیر ta_allowed: group_link قبلاً در taSearchTAsAllowed روی window ذخیره شده ──
        if (root && root.__taAllowedGroupLink) {
            var groupLink = root.__taAllowedGroupLink;
            post({
                action:         'ta_assign_with_group',
                product_id:     taSystem.productId,
                ta_id:          taId,
                messenger:      messenger,
                group_link:     groupLink,
                student_gender: gender,
            }, function (res) {
                hideEl('ta-loading', root)
                if (!res.success) {
                    showEl('ta-step-2', root);
                    showMsg('ta-error', res.data?.message || 'خطا در تخصیص.', 'error', root);
                    return;
                }
                taShowAssignSuccess(
                    res.data.message   || 'استادیار با موفقیت انتخاب شد.',
                    res.data.group_link || groupLink,
                    root
                );
            });
            return;
        }

        // ── مسیر عادی ──
        var actionName = (taCourseType === 'creative' || taCourseType === 'basic_writing') ? 'ta_assign_creative' : 'ta_assign_with_group';

        var params = {
            action:         actionName,
            product_id:     taSystem.productId,
            ta_id:          taId,
            messenger:      messenger,
            student_gender: gender,
            custom_group_link: customLink || displayedGroupLink // ارسال لینک در صورت وجود
        };

        post(params, function (res) {
            hideEl('ta-loading', root)

            if (!res.success) {
                // اگر بک‌اند درخواست لینک کرد (نیاز به لینک اختصاصی)
                if (res.data && res.data.needs_link) {
                    var _msgLabels = (typeof taSystem !== 'undefined' && taSystem.messengerLabels) ? taSystem.messengerLabels : {telegram:'تلگرام',bale:'بله',eitaa:'ایتا'};
                    var msgName = _msgLabels[messenger] || 'پیام‌رسان';

                    // فرم اینلاین دریافت لینک
                    var formHtml = `
                        <div id="ta-custom-link-box" style="margin-bottom: 20px; padding: 15px; border: 2px dashed #0073aa; border-radius: 8px; background: #f0f8ff; text-align: center;">
                            <p style="margin-bottom: 10px; font-weight: bold; color: #d9534f;">${res.data.message}</p>
                            <label style="display: block; margin-bottom: 10px; font-weight: bold;">لینک گروه ${msgName} خود را وارد کنید:</label>
                            <input type="text" id="ta-custom-link-input" style="width: 100%; padding: 8px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; text-align: left;" placeholder="https://..." dir="ltr">
                            <button type="button" class="ta-btn ta-btn-primary" onclick="window.taSubmitCustomLink('${taId}', this)">ثبت لینک و تایید</button>
                            <button type="button" class="ta-btn" onclick="window.taCancelCustomLink(this)" style="margin-right: 5px;">انصراف</button>
                        </div>
                    `;

                    var existingBox = document.getElementById('ta-custom-link-box');
                    if (existingBox) existingBox.remove();

                    hideEl('ta-ta-list');

                    // برای نقد اثر/حرفه‌ای: ta-step-2 وجود ندارد — از ta-ta-list به عنوان container استفاده می‌کنیم
                    var step2 = document.getElementById('ta-step-2');
                    if (step2) {
                        showEl('ta-step-2');
                        step2.insertAdjacentHTML('afterbegin', formHtml);
                    } else {
                        // fallback برای critique/professional که ta-step-2 ندارند
                        var container = document.getElementById('ta-ta-list') ||
                                        document.getElementById('ta-product-widget') ||
                                        document.querySelector('.ta-widget');
                        if (container) {
                            container.style.display = '';
                            container.innerHTML = formHtml;
                        } else {
                            // آخرین fallback
                            document.querySelector('.ta-widget-body, .ta-widget, #ta-product-widget')
                                ?.insertAdjacentHTML('beforeend', formHtml);
                        }
                    }
                    
                    return;
                }

                // نمایش خطا — ta-step-2 ممکن است در critique/professional وجود نداشته باشد
                var step2El = document.getElementById('ta-step-2');
                if (step2El) showEl('ta-step-2');
                showMsg('ta-error', res.data?.message || 'خطا در ثبت استادیار.', 'error');
                return;
            }

            // نمایش نام استادیار و لینک گروه به جای ریدایرکت
            var successMsg  = res.data?.message || 'استادیار با موفقیت ثبت شد.';
            var successLink = res.data?.group_link || '';
            window.taShowAssignSuccess(successMsg, successLink, root);
            hideEl('ta-step-2');
            hideEl('ta-ta-list');
        });
    };

    window.taSubmitCustomLink = function(taId, source) {
        var root = widgetRoot(source);
        window.__taActiveWidget = root;
        var linkInput = root?.querySelector('#ta-custom-link-input');
        var link = linkInput ? linkInput.value.trim() : '';
        if (!link) { showMsg('ta-error', 'لطفاً لینک گروه را وارد کنید.', 'error', root); return; }
        var box = root?.querySelector('#ta-custom-link-box');
        if (box) box.remove();
        hideEl('ta-error', root);
        var step2 = root?.querySelector('#ta-step-2');
        if (step2) showEl('ta-ta-list', root); else showEl('ta-loading', root);
        window.taSelectTA(taId, link, root);
    };
    window.taCancelCustomLink = function(source) {
        var root = widgetRoot(source);
        var box = root?.querySelector('#ta-custom-link-box');
        if (box) box.remove();
        hideEl('ta-error', root);
        showEl('ta-ta-list', root);
    };

    // ─────────────────────────────────────────────────────────────────────────
    // تعیین سطح — آپلود فایل
    // ─────────────────────────────────────────────────────────────────────────

    window.taSubmitLevelFile = function () {
        var taId     = document.getElementById('ta-level-ta-id')?.value;
        var fileEl   = document.getElementById('ta-level-file');

        if (!taId)               { showMsg('ta-level-error', 'استادیار تعیین سطح را انتخاب کنید.', 'error'); return; }
        if (!fileEl?.files[0])   { showMsg('ta-level-error', 'فایل را انتخاب کنید.', 'error'); return; }

        var fd = new FormData();
        fd.append('action',     'ta_submit_level_file');
        fd.append('product_id', taSystem.productId);
        fd.append('ta_id',      taId);
        fd.append('ta_level_file', fileEl.files[0]);

        hideEl('ta-level-error');
        postForm(fd, function (res) {
            if (res.success) {
                showMsg('ta-level-success', '✅ ' + res.data.message, 'success');
            } else {
                showMsg('ta-level-error', res.data?.message || 'خطا.', 'error');
            }
        });
    };

    // تعیین سطح — ثبت لینک گروه
    window.taSubmitGroupLink = function () {
        var link      = document.getElementById('ta-group-link-input')?.value;
        var messenger = document.getElementById('ta-group-messenger')?.value;

        if (!link) { showMsg('ta-group-error', 'لینک گروه را وارد کنید.', 'error'); return; }
        if (!taValidMessengerLink(messenger, link)) {
            showMsg('ta-group-error', 'لینک گروه با پیام‌رسان انتخاب‌شده مطابقت ندارد.', 'error');
            return;
        }

        post({
            action:     'ta_submit_level_group',
            product_id: taSystem.productId,
            group_link: link,
            messenger:  messenger,
        }, function (res) {
            if (res.success) {
                showMsg('ta-group-success', '✅ ' + res.data.message, 'success');
            } else {
                showMsg('ta-group-error', res.data?.message || 'خطا.', 'error');
            }
        });
    };

    // ─────────────────────────────────────────────────────────────────────────
    // داشبورد استادیار
    // ─────────────────────────────────────────────────────────────────────────

    // ── ظرفیت ──
    window.taSaveCapacity = function () {
        var term   = document.getElementById('cap-term')?.value;
        var course = document.getElementById('cap-course')?.value;
        var gender = document.getElementById('cap-gender')?.value;
        var total  = document.getElementById('cap-total')?.value;

        if (!term || !course || !gender || !total) {
            showMsg('cap-result', 'همه فیلدها را پر کنید.', 'error');
            return;
        }

        post({
            action:          'ta_save_capacity',
            term_id:         term,
            course_type:     course,
            student_gender:  gender,
            total_capacity:  total,
        }, function (res) {
            showMsg('cap-result', res.data?.message || (res.success ? 'ذخیره شد.' : 'خطا.'), res.success ? 'success' : 'error');
            if (res.success) { setTimeout(function() { location.reload(); }, 1200); }
        });
    };

    // ── گروه ──
    window.taShowAddGroup = function (capId) {
        var formEl = document.getElementById('add-group-form-' + capId);
        if (formEl) formEl.style.display = formEl.style.display === 'none' ? '' : 'none';
    };

    window.taAddGroupSlot = function (capId) {
        var messenger = document.getElementById('group-messenger-' + capId)?.value;
        var link      = document.getElementById('group-link-' + capId)?.value;

        if (!link) { alert('لینک گروه را وارد کنید.'); return; }

        post({
            action:          'ta_add_group_slot',
            capacity_id:     capId,
            messenger_type:  messenger,
            group_link:      link,
        }, function (res) {
            if (res.success) { location.reload(); }
            else { alert(res.data?.message || 'خطا.'); }
        });
    };

    // ── نمره ──
    window.taShowGradeForm = function (studentId, productId, courseType) {
        showEl('grade-form-' + studentId + '-' + productId);
    };
    window.taHideGradeForm = function (studentId, productId) {
        hideEl('grade-form-' + studentId + '-' + productId);
    };

    window.taSubmitGrade = function (studentId, productId) {
    var grade        = document.getElementById('grade-select-' + studentId + '-' + productId)?.value;
    var notes        = document.getElementById('grade-notes-'  + studentId + '-' + productId)?.value;
    var assignmentId = document.getElementById('assignment-id-' + studentId + '-' + productId)?.value;
    var markComplete = document.getElementById('grade-complete-' + studentId + '-' + productId)?.checked ? 1 : 0;

    $.ajax({
        url: taSystem.ajaxUrl,
        type: 'POST',
        dataType: 'json',
        data: {
            action:        'ta_grade_student',
            nonce:         taSystem.nonce,
            student_id:    studentId,
            product_id:    productId,
            grade:         grade,
            notes:         notes,
            assignment_id: assignmentId,
            mark_complete: markComplete,
        },
        success: function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data?.message || 'خطا در ثبت نمره.');
            }
        },
        error: function (jqXHR, textStatus, errorThrown) {

        }
    });
};

     
    // ── لیست سیاه ──
    window.taBlacklist = function (studentId, studentName) {
        if (!confirm('آیا می‌خواهید ' + studentName + ' را به لیست سیاه اضافه کنید؟')) return;
        var reason = prompt('دلیل (اختیاری):') || '';

        post({
            action:     'ta_blacklist_student',
            student_id: studentId,
            reason:     reason,
        }, function (res) {
            if (res.success) { alert('✅ ' + res.data.message); location.reload(); }
            else { alert('❌ ' + (res.data?.message || 'خطا.')); }
        });
    };

    window.taRemoveBlacklist = function (studentId) {
        if (!confirm('از لیست سیاه حذف شود؟')) return;

        post({
            action:     'ta_remove_from_blacklist',
            student_id: studentId,
        }, function (res) {
            if (res.success) { location.reload(); }
            else { alert(res.data?.message || 'خطا.'); }
        });
    };








// متغیر سراسری برای ذخیره ID ظرفیت در حال ویرایش
// راهنمای نوع دوره
var courseHints = {
    'creative':      '✍️ دوره خلاق — امکان ساخت و مدیریت گروه دارید',
    'intro':         '🌱 دوره مقدماتی — ظرفیت پذیرش هنرجوی مبتدی',
    'basic_writing': '📝 نویسندگی پایه — امکان ساخت و مدیریت گروه دارید',
    'advanced':      '🚀 دوره پیشرفته — ظرفیت پذیرش هنرجوی پیشرفته',
    'professional':  '🏆 دوره حرفه‌ای — ظرفیت پذیرش هنرجوی حرفه‌ای',
    'ladder':        '🪜 نردبان حرفه‌ای — ظرفیت پذیرش هنرجوی نردبان حرفه‌ای',
    'critique':      '🔍 نقد اثر — ظرفیت بررسی و نقد آثار هنرجویان',
};
window.taUpdateCourseHint = function(val) {
    var hint = document.getElementById('cap-course-hint');
    if (!hint) return;
    var groupTypes = ['creative', 'basic_writing', 'ladder'];
    var msg = courseHints[val] || '';
    hint.textContent = msg;
    hint.style.display = msg ? '' : 'none';
    hint.className = 'ta-cap-course-hint ' + (groupTypes.indexOf(val) >= 0 ? 'ta-cap-hint-group' : '');
};

var editingCapacityId = null;

// بارگذاری ظرفیت برای ویرایش
window.taLoadCapacityForEdit = function(capId, termId, courseType, gender, totalCap, messenger) {
    editingCapacityId = capId;
    
    document.getElementById('cap-term').value = termId;
    document.getElementById('cap-course').value = courseType;
    document.getElementById('cap-gender').value = gender;
    document.getElementById('cap-total').value = totalCap;
     // مقداردهی پیام‌رسان برای ویرایش
    var msgField = document.getElementById('cap-messenger');
    if (msgField) {
        msgField.value = messenger || 'both';
    }

    var form = document.getElementById('ta-capacity-form');
    var btn = form.querySelector('button');
    
    form.classList.add('ta-form-edit-mode');
    btn.innerHTML = '<span class="ta-cap-btn-icon">✏️</span> ویرایش ظرفیت';
    btn.classList.add('ta-btn-edit-mode');
    // نمایش راهنمای نوع دوره
    if (window.taUpdateCourseHint) { taUpdateCourseHint(courseType); }
    btn.onclick = taSaveOrUpdateCapacity;
    
    // اضافه کردن دکمه لغو
    if (!document.getElementById('ta-cancel-edit-btn')) {
        var cancelBtn = document.createElement('button');
        cancelBtn.id = 'ta-cancel-edit-btn';
        cancelBtn.className = 'ta-btn ta-btn-cancel';
        cancelBtn.textContent = 'لغو';
        cancelBtn.onclick = taCancelEdit;
        btn.parentElement.appendChild(cancelBtn);
    }
    
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
};

// لغو ویرایش
window.taCancelEdit = function() {
    editingCapacityId = null;
    
    var form = document.getElementById('ta-capacity-form');
    var btn = form.querySelector('button');
    
    form.classList.remove('ta-form-edit-mode');
    btn.textContent = 'ذخیره ظرفیت';
    btn.classList.remove('ta-btn-edit-mode');
    btn.onclick = taSaveOrUpdateCapacity;
    
    var cancelBtn = document.getElementById('ta-cancel-edit-btn');
    if (cancelBtn) cancelBtn.remove();
    
    document.getElementById('cap-total').value = '';
    // ریست کردن پیام‌رسان
    var msgField = document.getElementById('cap-messenger');
    if (msgField) { msgField.value = 'both'; }
    // ریست دکمه
    var submitBtn = document.getElementById('ta-cap-submit-btn');
    if (submitBtn) { submitBtn.innerHTML = '<span class="ta-cap-btn-icon">💾</span> ذخیره ظرفیت'; }
};

// ذخیره یا ویرایش ظرفیت
window.taSaveOrUpdateCapacity = function() {
    var term = document.getElementById('cap-term')?.value;
    var course = document.getElementById('cap-course')?.value;
    var gender = document.getElementById('cap-gender')?.value;
    var total = document.getElementById('cap-total')?.value;
    var messenger = document.getElementById('cap-messenger')?.value || 'both'; // دریافت مقدار پیام‌رسان

    if (!term || !course || !gender || !total|| !messenger) {
        showMsg('cap-result', 'همه فیلدها را پر کنید.', 'error');
        return;
    }

    var data = {
        term_id: term,
        course_type: course,
        student_gender: gender,
        total_capacity: total,
        messenger: messenger // اضافه شدن پیام‌رسان به دیتای ارسالی

    };

    if (editingCapacityId) {
        data.action = 'ta_update_capacity';
        data.capacity_id = editingCapacityId;
    } else {
        data.action = 'ta_save_capacity';
    }

    post(data, function(res) {
        showMsg(
            'cap-result',
            res.data?.message || (res.success ? 'عملیات موفق.' : 'خطا.'),
            res.success ? 'success' : 'error'
        );
        if (res.success) {
            setTimeout(function() { location.reload(); }, 1000);
        }
    });
};

// حذف ظرفیت
window.taDeleteCapacity = function(capId) {
    if (!confirm('آیا از حذف این ظرفیت اطمینان دارید؟')) return;

    post({
        action: 'ta_delete_capacity',
        capacity_id: capId,
    }, function(res) {
        showMsg(
            'cap-result',
            res.data?.message || (res.success ? 'حذف شد.' : 'خطا.'),
            res.success ? 'success' : 'error'
        );
        if (res.success) {
            setTimeout(function() { location.reload(); }, 1000);
        }
    });
};

// ویرایش گروه
window.taEditGroup = function (groupId, capId, messenger, link) {
    var form = document.getElementById('add-group-form-' + capId);
    if (!form) return;

    // پر کردن فیلدها
    document.getElementById('group-messenger-' + capId).value = messenger;
    document.getElementById('group-link-' + capId).value = link || '';

    // تغییر دکمه به حالت ویرایش
    var btn = form.querySelector('button');
    btn.textContent = 'ذخیره ویرایش';
    btn.className = 'ta-btn ta-btn-warning ta-btn-sm';
    btn.onclick = function () { taUpdateGroupSlot(groupId, capId); };

    // نمایش فرم
    form.style.display = '';
    form.style.border = '2px solid #f39c12';
    form.style.borderRadius = '6px';
    form.style.padding = '10px';
};


window.taUpdateGroupSlot = function (groupId, capId) {
    var messenger = document.getElementById('group-messenger-' + capId)?.value;
    var link      = document.getElementById('group-link-' + capId)?.value;

    post({
        action:         'ta_update_group_slot',
        group_id:       groupId,
        messenger_type: messenger,
        group_link:     link,
    }, function (res) {
        showMsg(
            'cap-result',
            res.data?.message || (res.success ? 'گروه ویرایش شد.' : 'خطا.'),
            res.success ? 'success' : 'error'
        );
        if (res.success) {
            // ریست فرم به حالت افزودن
            var form = document.getElementById('add-group-form-' + capId);
            var btn  = form.querySelector('button');
            btn.textContent = 'افزودن';
            btn.className   = 'ta-btn ta-btn-primary ta-btn-sm';
            btn.onclick     = function () { taAddGroupSlot(capId); };
            form.style.border  = '';
            form.style.padding = '';
            form.style.display = 'none';
            setTimeout(() => location.reload(), 800);
        }
    });
};


// حذف گروه
window.taDeleteGroup = function(groupId) {
    if (!confirm('آیا از حذف این گروه اطمینان دارید؟')) return;

    post({
        action: 'ta_delete_group_slot',
        group_id: groupId,
    }, function(res) {
        showMsg(
            'cap-result',
            res.data?.message || (res.success ? 'گروه حذف شد.' : 'خطا.'),
            res.success ? 'success' : 'error'
        );
        if (res.success) {
            setTimeout(function() { location.reload(); }, 1000);
        }
    });
};

// تغییر تابع اصلی ذخیره
window.taSaveCapacity = taSaveOrUpdateCapacity;







/**
 * باز کردن فرم ویرایش assignment (دوره‌های غیرخلاق)
 */
function taEditAssignment(assignmentId, messenger, link) {
    document.getElementById('edit-assignment-id').value = assignmentId;

    const messengerEl = document.getElementById('edit-assignment-messenger');
    if (messengerEl) messengerEl.value = messenger;

    const linkEl = document.getElementById('edit-assignment-link');
    if (linkEl) linkEl.value = link;

    document.getElementById('edit-assignment-form').style.display = 'block';
}

/**
 * ذخیره تغییرات assignment (پیام‌رسان + لینک گروه)
 */
function taSaveAssignment() {
    const assignmentId = document.getElementById('edit-assignment-id').value;
    const messenger    = document.getElementById('edit-assignment-messenger').value;
    const groupLink    = document.getElementById('edit-assignment-link').value;

    jQuery.post(taSystem.ajaxUrl, {
        action:        'ta_update_assignment_group',
        nonce:         taSystem.nonce,
        assignment_id: assignmentId,
        messenger:     messenger,
        group_link:    groupLink,
    }, function(res) {
        if (res.success) {
            alert(res.data.message || 'ذخیره شد.');
            setTimeout(() => location.reload(), 800);
        } else {
            alert(res.data.message || 'خطا در ذخیره.');
        }
    });
}

// ── انتخاب همه ردیف‌ها ──────────────────────────────────────────
window.taToggleSelectAll = function (checked) {
    document.querySelectorAll('.ta-row-check').forEach(function (cb) {
        cb.checked = checked;
    });
    taOnRowCheck();
};

// ── به‌روزرسانی شمارنده و نمایش/پنهان دکمه‌های bulk ──────────────
window.taOnRowCheck = function () {
    var selected = document.querySelectorAll('.ta-row-check:checked');
    var count    = selected.length;
    var toolbar  = document.getElementById('ta-bulk-actions');
    var counter  = document.getElementById('ta-selected-count');

    if (toolbar)  toolbar.style.display  = count > 0 ? 'flex' : 'none';
    if (counter)  counter.textContent    = count + ' انتخاب‌شده';
};

// ── تغییر وضعیت گروهی ───────────────────────────────────────────
window.taBulkUpdateStatus = function (newStatus) {
    var selected = document.querySelectorAll('.ta-row-check:checked');
    if (selected.length === 0) return;

    var ids = Array.from(selected).map(function (cb) { return cb.dataset.id; });
    var label = newStatus === 'completed' ? 'تکمیل‌شده' : 'فعال';

    if (!confirm('وضعیت ' + ids.length + ' تخصیص به «' + label + '» تغییر کند؟')) return;

    $.ajax({
        url: taSystem.ajaxUrl,
        type: 'POST',
        dataType: 'json',
        data: {
            action:     'ta_bulk_update_assignment_status',
            nonce:      taSystem.nonce,
            ids:        ids,
            new_status: newStatus,
        },
        success: function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data?.message || 'خطا در تغییر وضعیت.');
            }
        },
        error: function () {
            alert('خطا در ارتباط با سرور.');
        }
    });
};


/**
 * هندلر دکمه «انتخاب» در صفحه تخصیص بر اساس ظرفیت (پنل ادمین)
 */
/**
 * هندلر دکمه «انتخاب» در صفحه تخصیص بر اساس ظرفیت (پنل ادمین)
 */
jQuery(document).on('click', '.ta-capacity-assign-btn', function (e) {
    e.preventDefault();
    var $btn = jQuery(this);
    var $row = $btn.closest('tr, .ta-capacity-row');

    var orderId       = $btn.data('order-id');
    var taId          = $btn.data('ta-id');
    var capacityId    = $btn.data('capacity-id');
    var messengerType = $btn.data('messenger') || $row.find('.ta-messenger-select').val();

    var data = {
        action:         'ta_admin_assign_by_capacity',
        nonce:          ta_system_vars.nonce,
        order_id:       orderId,
        ta_user_id:     taId,
        capacity_id:    capacityId,
        messenger_type: messengerType
    };

    $btn.prop('disabled', true).text('در حال پردازش...');

    jQuery.post(ta_system_vars.ajax_url, data, function (res) {

        if (res.success) {
            // بررسی needs_link → نمایش فرم اینلاین دریافت لینک
            if (res.data && res.data.needs_link) {
                taShowAdminLinkForm($btn, $row, res.data.message, orderId, taId, capacityId, messengerType);
                return;
            }

            alert(res.data.message || '✅ تخصیص انجام شد.');
            $btn.prop('disabled', true).text('تخصیص‌شده ✅').addClass('ta-assigned');
            if (res.data.group_link) {
                $row.find('.ta-group-link-cell').html(
                    '<a href="' + res.data.group_link + '" target="_blank">مشاهده گروه</a>'
                );
            }
        } else {
            // بررسی needs_link در حالت error هم (چون assign_creative با success=false برمی‌گرداند)
            if (res.data && res.data.needs_link) {
                taShowAdminLinkForm($btn, $row, res.data.message, orderId, taId, capacityId, messengerType);
                return;
            }

            alert(res.data.message || '⚠️ خطا در تخصیص');
            $btn.prop('disabled', false).text('انتخاب');
        }

    }).fail(function () {
        alert('⚠️ خطا در ارتباط با سرور');
        $btn.prop('disabled', false).text('انتخاب');
    });
});

/**
 * نمایش فرم اینلاین دریافت لینک گروه (مشابه صفحه دانشجو)
 */
function taShowAdminLinkForm($btn, $row, message, orderId, taId, capacityId, messengerType) {
    // حذف فرم قبلی اگر وجود داشت
    jQuery('.ta-admin-link-box').remove();

    var msgName = 'پیام‌رسان';
    var _msgLabels2 = (typeof taSystem !== 'undefined' && taSystem.messengerLabels) ? taSystem.messengerLabels : {telegram:'تلگرام',bale:'بله',eitaa:'ایتا'};
    if (_msgLabels2[messengerType]) msgName = _msgLabels2[messengerType];

    var formHtml = '<div class="ta-admin-link-box" style="margin:10px 0;padding:15px;border:2px dashed #0073aa;border-radius:8px;background:#f0f8ff;text-align:center;">'
        + '<p style="margin-bottom:10px;font-weight:bold;color:#d9534f;">' + (message || '⚠️ لطفا لینک گروه را وارد کنید.') + '</p>'
        + '<label style="display:block;margin-bottom:10px;font-weight:bold;">لینک گروه ' + msgName + ' را وارد کنید:</label>'
        + '<input type="text" class="ta-admin-link-input" style="width:80%;padding:8px;margin-bottom:15px;border:1px solid #ccc;border-radius:4px;text-align:left;" placeholder="https://..." dir="ltr">'
        + '<br>'
        + '<button type="button" class="button button-primary ta-admin-link-submit">ثبت لینک و تایید</button> '
        + '<button type="button" class="button ta-admin-link-cancel" style="margin-right:5px;">انصراف</button>'
        + '</div>';

    // نمایش فرم بعد از ردیف یا دکمه
    $row.after('<tr class="ta-admin-link-row"><td colspan="99">' + formHtml + '</td></tr>');

    $btn.prop('disabled', true).text('در انتظار لینک...');

    // هندلر دکمه «ثبت لینک و تایید»
    jQuery(document).one('click', '.ta-admin-link-submit', function () {
        var $form = jQuery(this).closest('.ta-admin-link-box');
        var link = $form.find('.ta-admin-link-input').val().trim();

        // [FIX-GROUP-LINK-SCHEME] اگر لینک بدون http(s) وارد شده، خودکار https:// اضافه شود
        if (link && link.indexOf('http') !== 0) {
            link = 'https://' + link;
        }
        if (!link) {
            alert('لطفاً یک لینک معتبر وارد کنید.');
            return;
        }

        jQuery(this).prop('disabled', true).text('در حال ارسال...');

        jQuery.post(ta_system_vars.ajax_url, {
            action:            'ta_admin_assign_with_custom_link',
            nonce:             ta_system_vars.nonce,
            order_id:          orderId,
            ta_user_id:        taId,
            capacity_id:       capacityId,
            messenger_type:    messengerType,
            custom_group_link: link
        }, function (res) {
            jQuery('.ta-admin-link-row').remove();

            if (res.success) {
                alert(res.data.message || '✅ تخصیص انجام شد.');
                $btn.prop('disabled', true).text('تخصیص‌شده ✅').addClass('ta-assigned');
                if (res.data.group_link) {
                    $row.find('.ta-group-link-cell').html(
                        '<a href="' + res.data.group_link + '" target="_blank">مشاهده گروه</a>'
                    );
                }
            } else {
                alert(res.data.message || '⚠️ خطا در تخصیص');
                $btn.prop('disabled', false).text('انتخاب');
            }
        }).fail(function () {
            jQuery('.ta-admin-link-row').remove();
            alert('⚠️ خطا در ارتباط با سرور');
            $btn.prop('disabled', false).text('انتخاب');
        });
    });

    // هندلر دکمه «انصراف»
    jQuery(document).one('click', '.ta-admin-link-cancel', function () {
        jQuery('.ta-admin-link-row').remove();
        $btn.prop('disabled', false).text('انتخاب');
    });
}

/**
 * ارسال تخصیص با لینک سفارشی (برای دوره خلاق بدون گروه آزاد)
 */
function taCapacityAssignWithLink(originalData, groupLink, $btn) {
    var data = jQuery.extend({}, originalData, {
        action:            'ta_admin_assign_with_custom_link',
        custom_group_link: groupLink
    });

    jQuery.post(ta_system_vars.ajax_url, data, function (res) {
        if (res.success) {
            alert(res.data.message || '✅ تخصیص انجام شد.');
            $btn.prop('disabled', true).text('تخصیص‌شده ✅');
        } else {
            alert(res.data.message || '⚠️ خطا در تخصیص');
            $btn.prop('disabled', false).text('انتخاب');
        }
    }).fail(function () {
        alert('⚠️ خطا در ارتباط با سرور');
        $btn.prop('disabled', false).text('انتخاب');
    });
}



    // ─────────────────────────────────────────────────────────────────────────
    // نردبان حرفه‌ای — انتخاب ادامه با استادیار قبلی یا درخواست از ادمین
    // ─────────────────────────────────────────────────────────────────────────

    // تأیید ادامه با استادیار قبلی
    $(document).on('click', '.ta-assign-ladder-confirm', function (e) {
        e.preventDefault();
        var $btn       = $(this);
        var productId  = $btn.data('product-id');
        var origHtml   = $btn.html();

        taMaybeSavePrereqMsgLink(productId, function (ok) {
            if (!ok) return;

            $btn.prop('disabled', true).html('⏳ در حال ثبت...');

            $.post(taSystem.ajaxUrl, {
                action:      'ta_assign_ladder',
                product_id:  productId,
                messenger:   'inherit',
                use_prev_ta: 1,
                decline_prev: 0,
                nonce:       taSystem.nonce
            }, function (res) {
                var $wrap = $('#ta-ladder-wrap-' + productId);
                if (res.success) {
                    var html = '<div class="ta-message ta-message-success" style="padding:20px;text-align:center;">' +
                        '<div style="font-size:2rem;margin-bottom:10px;">✅</div>' +
                        '<p>' + (res.data.message || 'تخصیص با موفقیت انجام شد.') + '</p>';
                    if (res.data.status === 'pending_admin') {
                        html = '<div class="ta-message ta-message-info" style="padding:20px;text-align:center;">' +
                            '<div style="font-size:2rem;margin-bottom:10px;">⏳</div>' +
                            '<p>' + res.data.message + '</p></div>';
                    } else {
                        var groupUrl = res.data.group_link || '';
                        if (groupUrl) {
                            html += '<a href="' + groupUrl + '" target="_blank" class="ta-btn ta-btn-primary" style="margin-top:12px;">🔗 ورود به گروه</a>';
                        }
                    }
                    html += '</div>';
                    $wrap.html(html);
                } else {
                    $btn.prop('disabled', false).html(origHtml);
                    $wrap.find('.ta-ladder-error').remove();
                    $wrap.append('<div class="ta-ladder-error ta-message ta-message-error" style="margin-top:10px;">' + (res.data.message || 'خطا در ثبت') + '</div>');
                }
            });
        });
    });

    // درخواست تخصیص از ادمین (بدون استادیار قبلی)
    $(document).on('click', '.ta-assign-ladder-admin', function (e) {
        e.preventDefault();
        var $btn      = $(this);
        var productId = $btn.data('product-id');
        var origHtml  = $btn.html();
        var $wrap     = $('#ta-ladder-wrap-' + productId);

        $btn.prop('disabled', true).html('⏳ در حال ثبت...');

        $.post(taSystem.ajaxUrl, {
            action:      'ta_assign_ladder',
            product_id:  productId,
            messenger:   'inherit',
            use_prev_ta: 0,
            decline_prev: 1,
            nonce:       taSystem.nonce
        }, function (res) {
            if (res.success) {
                var msg = (res.data && res.data.message) ? res.data.message : 'درخواست شما ثبت شد. مسئول اجرایی مبنا به زودی استادیار را برای شما تعیین خواهد کرد.';
                if ($wrap.length) {
                    $wrap.html('<div style="padding:20px;text-align:center;background:#f0f9ff;border:1.5px solid #7dd3fc;border-radius:12px;">' +
                        '<div style="font-size:2rem;margin-bottom:10px;">⏳</div>' +
                        '<p style="margin:0;color:#0c4a6e;font-size:.95rem;">' + msg + '</p></div>');
                } else {
                    alert(msg);
                }
            } else {
                $btn.prop('disabled', false).html(origHtml);
                var errMsg = (res.data && res.data.message) ? res.data.message : 'خطا در ثبت درخواست. لطفاً دوباره تلاش کنید.';
                if ($wrap.length) {
                    $wrap.find('.ta-ladder-error').remove();
                    $wrap.append('<div class="ta-ladder-error" style="margin-top:10px;padding:12px;background:#fee2e2;border:1.5px solid #fca5a5;border-radius:10px;color:#991b1b;text-align:center;">' + errMsg + '</div>');
                } else {
                    alert(errMsg);
                }
            }
        }).fail(function () {
            $btn.prop('disabled', false).html(origHtml);
            var errMsg = 'خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.';
            if ($wrap.length) {
                $wrap.find('.ta-ladder-error').remove();
                $wrap.append('<div class="ta-ladder-error" style="margin-top:10px;padding:12px;background:#fee2e2;border:1.5px solid #fca5a5;border-radius:10px;color:#991b1b;text-align:center;">' + errMsg + '</div>');
            } else {
                alert(errMsg);
            }
        });
    });


})(jQuery);
