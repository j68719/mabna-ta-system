<?php
defined( 'ABSPATH' ) || exit;

/**
 * ویجت انتخاب استادیار
 * شورت‌کد: [ta_select_assistant]
 * در صفحه محصول قرار دهید
 */
class TA_Product_Widget {

    public function __construct() {
        add_shortcode( 'ta_select_assistant', [ $this, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        // [PURCHASE-GATE] مخفی‌کردن کانتینر(های) الیمنتور «ناحیه خرید» برای مشتری‌هایی که قبلاً خریده‌اند
        add_action( 'wp_head', [ $this, 'output_purchase_area_hide_css' ] );
    }

    /**
     * [PURCHASE-GATE] اگر کاربر جاری این محصول را با وضعیت «تکمیل‌شده» یا
     * «پرداخت قسطی» خریده باشد، هر کانتینر الیمنتوری که کلاس CSS دلخواه
     * ta-hide-if-purchased را داشته باشد مخفی می‌شود.
     * کافی است در الیمنتور، روی کانتینر «ناحیه خرید» (چه بالای صفحه چه پایین
     * صفحه — هر تعداد که باشد) از تب Advanced → CSS Classes مقدار
     * ta-hide-if-purchased را اضافه کنید؛ نیازی به هیچ تنظیم دیگری نیست.
     */
    public function output_purchase_area_hide_css(): void {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return;
        }
        $uid = get_current_user_id();
        if ( ! $uid ) {
            return;
        }
        $product_id = get_the_ID();
        if ( ! $product_id || ! TA_Eligibility::has_valid_purchase( $uid, $product_id ) ) {
            return;
        }
        echo '<style>.ta-hide-if-purchased{display:none !important;}</style>';
    }

    public function enqueue_assets(): void {
        global $post;
        if ( ! $post ) return;

        wp_enqueue_style(
            'ta-system',
            TA_SYSTEM_URL . 'assets/css/ta-system.css',
            [],
            ta_system_asset_ver( 'assets/css/ta-system.css' )
        );
        wp_enqueue_script(
            'ta-system',
            TA_SYSTEM_URL . 'assets/js/ta-system.js',
            [ 'jquery' ],
            ta_system_asset_ver( 'assets/js/ta-system.js' ),
            true
        );

        $product_id = get_the_ID();

        wp_localize_script( 'ta-system', 'taSystem', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'ta_product_nonce' ),
            'productId'  => $product_id,
            'isLoggedIn' => is_user_logged_in() ? 1 : 0,
            'loginUrl'   => wp_login_url( get_permalink() ),
        ] );
    }

    public function render_shortcode( array $atts ): string {
        $atts = shortcode_atts( [
            'product_id' => 0,
        ], $atts );

        $product_id = (int) $atts['product_id'] ?: get_the_ID();

        if ( ! $product_id ) {
            return '';
        }

        ob_start();
        $this->render( $product_id );
        return ob_get_clean();
    }

    private function render( int $product_id ): void {
        // [PURCHASE-GATE] برای غیرمشتری (مهمانِ لاگین‌نکرده، یا کاربری که این محصول
        // را با وضعیت «تکمیل‌شده»/«پرداخت قسطی» نخریده) کل شورت‌کد چیزی نمایش نمی‌دهد.
        $uid = get_current_user_id();
        if ( ! $uid || ! TA_Eligibility::has_valid_purchase( $uid, $product_id ) ) {
            return;
        }

        $settings = TA_Eligibility::get_product_settings( $product_id );

        if ( ! $settings ) {
            return;
        }

        if ( $product_id !== get_the_ID() ) {
            echo '<script>if(typeof taSystem !== "undefined") taSystem.productId = ' . $product_id . ';</script>';
        }

        $check = TA_Eligibility::check( $uid, $product_id );

        // [MEET-ASSISTANTS-BTN] فقط در مسیرهایی که هنرجو واقعاً در حال انتخاب
        // استادیار است (نه وقتی از قبل استادیار دارد یا بلاک شده) نمایش داده شود.
        $meet_btn_paths = [ 'ta_allowed', 'normal', 'level_assess', 'assign_ladder', 'show_critique_link', 'assign_professional' ];
        // دورهٔ نقد اثر مسیر انتخاب استادیار دارد، اما لینک عمومی «دیدن استادیاران» را نشان نمی‌دهد.
        $show_meet_btn  = $check['eligible']
            && $settings->course_type !== 'critique'
            && in_array( $check['path'], $meet_btn_paths, true );
        $meet_btn_html  = $show_meet_btn ? $this->meet_assistants_button_html() : '';

        static $widget_instance = 0;
        $widget_instance++;
        $widget_dom_id = 'ta-product-widget-' . $widget_instance;
        echo '<div class="ta-widget" id="' . esc_attr( $widget_dom_id ) . '" data-ta-widget="1">';
        
        
        
        // تغییر عنوان بر اساس نوع دوره
        if( $settings->course_type === 'professional' ) {
            echo '<div class="ta-widget-header ta-widget-header-flex"><h3>🌟 ورود به دوره حرفه‌ای</h3>' . $meet_btn_html . '</div>';
        }
        elseif( $settings->course_type === 'critique' ) {
          echo '<div class="ta-widget-header ta-widget-header-flex"><h3>📝 ورود به دوره نقد اثر</h3>' . $meet_btn_html . '</div>'; // اضافه شدن این بخش
        } 
        elseif ( $settings->course_type === 'ladder' ) {
          echo '<div class="ta-widget-header ta-widget-header-flex"><h3>🪜 انتخاب استادیار نردبان حرفه‌ای</h3>' . $meet_btn_html . '</div>';
        }
        else {
          echo '<div class="ta-widget-header ta-widget-header-flex"><h3>👩‍🏫 انتخاب استادیار</h3>' . $meet_btn_html . '</div>';
        }
        
        
        
        
        
        

        if ( $check['path'] === 'blocked' && TA_Eligibility::already_assigned( $uid, $product_id ) ) {
            $this->render_already_assigned( $uid, $product_id );
            echo '</div>';
            return;
        }

        if ( ! $check['eligible'] ) {
            echo '<div class="ta-message ta-message-error">' . esc_html( $check['message'] ) . '</div>';
        } elseif ( $check['path'] === 'ta_allowed' ) {
            $this->render_ta_allowed_flow( $settings, $uid, $product_id );
        } elseif ( $check['path'] === 'normal' ) {
            $this->render_normal_flow( $settings, $uid, $product_id );
        } elseif ( $check['path'] === 'level_assess' ) {
            $this->render_level_assess_flow( $settings, $uid, $product_id );
        } elseif ( $check['path'] === 'level_assess_submit_group' ) {
            $this->render_level_assess_submit_group( $uid, $product_id );
        } elseif ( $check['path'] === 'level_assess_done' ) {
            echo '<div class="ta-message ta-message-success">✅ درخواست تعیین سطح تأیید شد. منتظر ورود استادیار به گروه باشید.</div>';
        } elseif ( $check['path'] === 'show_link' ) {
            $this->render_show_link( $settings, $uid, $product_id );
        }
        elseif ( $check['path'] === 'show_critique_link' ) {
            // الان critique از flow معمولی استفاده می‌کند
            $this->render_normal_flow( $settings, $uid, $product_id );
        }
        elseif ( $check['path'] === 'assign_professional' ) {
            $this->render_assign_professional_flow( $settings, $uid, $product_id );
        }
        elseif ( $check['path'] === 'assign_ladder' ) {
            $this->render_assign_ladder_flow( $settings, $uid, $product_id );
        }

        echo '</div>';
    }

    /**
     * HTML دکمه‌ی «دیدن استادیاران» — به‌صورت هم‌خط با تیتر ویجت نمایش داده می‌شود
     * (سمت چپ تیتر، پس‌زمینه سفید، کوچک، آیکن سمت چپ متن).
     * لینک: https://mabnaschool.ir/assistants/
     */
    private function meet_assistants_button_html(): string {
        ob_start();
        ?>
        <a class="ta-meet-assistants-link" href="https://mabnaschool.ir/assistants/" target="_blank" rel="noopener">
            <span class="ta-meet-assistants-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="30" height="31" viewBox="0 0 30 31" fill="none"><circle cx="15" cy="15.9863" r="15" fill="#E85A24"></circle><path d="M15.0531 7.36328L6.93555 15.9848M6.93555 15.9848L15.0531 24.6064M6.93555 15.9848L23.0662 15.9848" stroke="white" stroke-width="2" stroke-linejoin="round"></path></svg>
            </span>
            <span class="ta-meet-assistants-text">دیدن استادیاران</span>
        </a>
        <?php
        return ob_get_clean();
    }

private function render_already_assigned( int $uid, int $product_id ): void {
    $assignment = TA_Assignment::get_assignment( $uid, $product_id );
    if ( ! $assignment ) return;
    
    // دریافت تنظیمات محصول برای تشخیص نوع دوره
    $settings = TA_Eligibility::get_product_settings( $product_id );

    // اگر وضعیت pending_admin باشد → پیام انتظار نمایش داده شود
    if ( $assignment && in_array( $assignment->status ?? '', [ 'pending_admin', 'pending_admin_reviewed' ], true ) ) {
        echo '<div style="background:#fffbeb;border:2px solid #fcd34d;border-radius:14px;padding:24px;text-align:center;">';
        echo '<div style="font-size:2rem;margin-bottom:10px">⏳</div>';
        echo '<p style="margin:0;font-size:1rem;font-weight:700;color:#92400e;margin-bottom:8px">درخواست شما ثبت شد.</p>';
        echo '<p style="margin:0;font-size:.9rem;color:#78350f;line-height:1.7">مسئول اجرایی مبنا به زودی استادیار را برای شما تخصیص خواهد داد.</p>';
        echo '</div>';
        return;
    }

    echo '<div class="ta-message ta-message-success">';
    echo '<p>✅ استادیار شما: <strong>' . esc_html( $assignment->ta_name ?? '' ) . '</strong></p>';

    if ( ! empty( $assignment->group_link ) ) {
        // تغییر لیبل لینک در صورتی که دوره نقد اثر باشد
        $link_label = ( $settings && $settings->course_type === 'critique' ) ? 'لینک دوره نقد اثر' : 'ورود به گروه';
        
        $join_url = TA_URL_Helper::build_join_url( (int) ( $assignment->id ?? 0 ) );
        echo '<p><a href="' . esc_url( $join_url ) . '" class="ta-group-entry-btn" target="_blank" rel="noopener">' . esc_html( $link_label ) . '</a></p>';
    }

    echo '</div>';
}

  /*  private function render_already_assigned( int $uid, int $product_id ): void {
        $assignment = TA_Assignment::get_assignment( $uid, $product_id );
        if ( ! $assignment ) return;
        echo '<div class="ta-message ta-message-success">';
        echo '<p>✅ استادیار شما: <strong>' . esc_html( $assignment->ta_name ) . '</strong></p>';
        if ( $assignment->group_link ) {
            echo '<p><a href="' . esc_url( $assignment->group_link ) . '" class="ta-group-entry-btn" target="_blank" rel="noopener">ورود به گروه</a></p>';
        }
        echo '</div>';
    }
*/
   private function render_show_link( object $settings, int $uid, int $product_id ): void {
    // لینک گروه را از تخصیص ثبت‌شده بگیر
    $assignment = TA_Assignment::get_assignment( $uid, $product_id );
    $group_link = $assignment->group_link ?? '';
    $ta_name    = $assignment->ta_name   ?? '';

    echo '<div class="ta-message ta-message-success">';
    echo '<p>✅ استادیار شما: <strong>' . esc_html( $ta_name ) . '</strong></p>';

    if ( ! empty( $group_link ) ) {
        $join_url = TA_URL_Helper::build_join_url( (int) ( $assignment->id ?? 0 ) );
        echo '<p><a href="' . esc_url( $join_url ) . '" class="ta-group-entry-btn" target="_blank" rel="noopener">ورود به گروه</a></p>';
    } else {
        echo '<p style="color:#c0392b;">⚠️ لینک گروه هنوز توسط استادیار تنظیم نشده است.</p>';
    }

    echo '</div>';
}

    
  private function render_show_critique_link( object $settings, int $uid, int $product_id ): void {
        echo '<div class="ta-message ta-message-success" style="text-align: center; padding: 20px;">';
        
         
        if ( ! empty( $settings->pro_group_link ) ) {
            echo '<div style="margin-top:20px;">';
           
            //echo '<a href="' . esc_url( $settings->pro_group_link ) . '" class="ta-btn ta-btn-primary" target="_blank" style="padding: 10px 20px; font-size: 16px;">🔗 پیوستن به گروه دوره</a>';
           
           echo '<button class="ta-btn ta-btn-primary ta-assign-critique" data-product-id="' . esc_attr( $product_id ) . '">
🔗 پیوستن به نقد اثر
</button>';


            echo '</div>';
        } else {
            echo '<p class="ta-message ta-message-error" style="margin-top:15px;">لینک گروه برای این دوره هنوز توسط مدیر تنظیم نشده است.</p>';
            
             

        }
        echo '</div>';
    }


 

    // ─────────────────────────────────────────────────────────────────────────
    // جریان «تعیین سطح شده — اجازه انتخاب مستقیم استادیار»
    // هنرجو پیام‌رسان، لینک گروه و جنسیت را خودش وارد می‌کند
    // (مثل دوره خلاق ولی با فیلد group_link دستی به‌جای group از capacity)
    // ─────────────────────────────────────────────────────────────────────────

    private function render_ta_allowed_flow( object $settings, int $uid, int $product_id ): void {
        $gender = get_user_meta( $uid, 'student_gender', true ) ?: '';
        $active_messengers = (array) TA_Settings_Helper::get( 'supported_messengers', [ 'telegram', 'bale', 'eitaa' ] );
        ?>
        <div class="ta-message ta-message-info" style="margin-bottom:14px;">
            🎓 شما مجاز به انتخاب مستقیم استادیار هستید (تعیین سطح شده).
        </div>

        <div id="ta-step-1">

            <?php /* ─── پیام‌رسان ─── */ ?>
            <div class="ta-field-group">
                <label>پیام‌رسان خود را انتخاب کنید:</label>
                <select id="ta-messenger" class="ta-select">
                    <option value="">— انتخاب کنید —</option>
                    <?php foreach ( $active_messengers as $messenger ) :
                        $messenger_labels = [ 'telegram' => 'تلگرام', 'bale' => 'بله', 'eitaa' => 'ایتا' ];
                        if ( isset( $messenger_labels[ $messenger ] ) ) : ?>
                            <option value="<?= esc_attr( $messenger ) ?>"><?= esc_html( $messenger_labels[ $messenger ] ) ?></option>
                    <?php endif; endforeach; ?>
                </select>
            </div>

            <?php /* ─── لینک گروه ─── */ ?>
            <div class="ta-field-group">
                <label>لینک گروه دوره:</label>
                <input type="text" id="ta-custom-group-link" class="ta-input" dir="ltr"
                       placeholder="https://t.me/..."
                       autocomplete="off">
                <small style="color:#666;margin-top:4px;display:block;">
                    لینک گروه خود را در پیامرسان انتخابی وارد کنید.
اگر هنرجوی مبنا بوده‌اید می توانید از گروه قبلی خود استفاده و همان لینک پیوستن به گروه را اینجا وارد کنید.
                </small>
            </div>

            <?php /* ─── جنسیت ─── */ ?>
            <div class="ta-field-group" id="ta-gender-wrapper">
                <label>جنسیت خود را انتخاب کنید:</label>
                <?php if ( $gender ) : ?>
                    <div id="ta-gender-display" style="display:flex;align-items:center;gap:8px;padding:8px 0;">
                        <span><?= $gender === 'female' ? '👩 خانم' : '👨 آقا' ?></span>
                        <button type="button" id="ta-gender-edit-btn"
                                style="background:none;border:none;cursor:pointer;color:#007cba;font-size:13px;padding:0;">
                            ✏️ ویرایش
                        </button>
                        <input type="hidden" id="ta-gender" value="<?= esc_attr( $gender ) ?>">
                    </div>
                    <select id="ta-gender-select" class="ta-select" style="display:none;">
                        <option value="">— انتخاب کنید —</option>
                        <option value="female">خانم</option>
                        <option value="male">آقا</option>
                    </select>
                <?php else : ?>
                    <input type="hidden" id="ta-gender" value="">
                    <select id="ta-gender-select" class="ta-select">
                        <option value="">— انتخاب کنید —</option>
                        <option value="female">خانم</option>
                        <option value="male">آقا</option>
                    </select>
                <?php endif; ?>
            </div>

            <?php /* ─── تاییدیه ─── */ ?>
            <label style="display:flex;align-items:center;gap:6px;margin:12px 0;font-weight:normal;">
                <input type="checkbox" id="ta-allowed-confirm-checkbox">
                تایید می‌کنم پیام‌رسان و لینک گروه وارد شده صحیح است.
            </label>

        <div id="ta-error" style="display:none" class="ta-message ta-message-error"></div>

        <button class="ta-btn ta-btn-primary ta-btn-block" onclick="taSearchTAsAllowed(this)"
                    style="margin-top:4px;">
                🔍 جستجوی استادیار
            </button>
        </div>

        <div class="ta-step" id="ta-step-2" style="display:none">
            <div id="ta-ta-list"></div>
            <button class="ta-btn" onclick="taBackToStep1(this)">← بازگشت</button>
        </div>

        <div id="ta-loading" style="display:none" class="ta-loading">⏳ در حال جستجو...</div>

        <script>
        var taCourseType = '<?= esc_js( $settings->course_type ) ?>';
        var taAllowedMode = true;

        <?php if ( $gender ) : ?>
        // پشتیبانی از ویرایش جنسیت
        (function(){
            var widget = document.currentScript.closest('.ta-widget');
            var editBtn = widget.querySelector('#ta-gender-edit-btn');
            var display = widget.querySelector('#ta-gender-display');
            var select  = widget.querySelector('#ta-gender-select');
            var hidden  = widget.querySelector('#ta-gender');

            if (editBtn) {
                editBtn.addEventListener('click', function(){
                    display.style.display = 'none';
                    select.style.display  = '';
                    select.value          = hidden.value;
                });
            }
            if (select) {
                select.addEventListener('change', function(){
                    hidden.value = this.value;
                });
            }
        })();
        <?php endif; ?>

        window.taSearchTAsAllowed = function(source) {
            var widget = source && source.closest ? source.closest('[data-ta-widget="1"]') : null;
            if (!widget) return;
            var messenger = widget.querySelector('#ta-messenger')?.value || '';
            var groupLink = widget.querySelector('#ta-custom-group-link')?.value.trim() || '';
            var gender    = (widget.querySelector('#ta-gender-select')?.value ||
                             widget.querySelector('#ta-gender')?.value || '');
            var confirmed = widget.querySelector('#ta-allowed-confirm-checkbox')?.checked;

            var errEl = widget.querySelector('#ta-error');
            errEl.style.display = 'none';

            if (!messenger) {
                errEl.textContent = 'لطفاً پیام‌رسان را انتخاب کنید.';
                errEl.style.display = '';
                return;
            }
            // [FIX-GROUP-LINK-SCHEME] اگر لینک بدون http(s) وارد شده، خودکار https:// اضافه شود
            if ( groupLink && groupLink.indexOf('http://') !== 0 && groupLink.indexOf('https://') !== 0 ) {
                groupLink = 'https://' + groupLink;
                var linkInput = widget.querySelector('#ta-custom-group-link');
                if ( linkInput ) linkInput.value = groupLink;
            }
            if (!groupLink) {
                errEl.textContent = 'لطفاً لینک گروه را وارد کنید.';
                errEl.style.display = '';
                return;
            }
            if (!confirmed) {
                errEl.textContent = 'لطفاً با فعال‌کردن چک‌باکس، صحت پیام‌رسان و لینک گروه را تأیید کنید.';
                errEl.style.display = '';
                return;
            }
            if (!gender) {
                errEl.textContent = 'لطفاً جنسیت را انتخاب کنید.';
                errEl.style.display = '';
                return;
            }

            // ذخیره group_link برای استفاده در taSelectTA هنگام انتخاب استادیار
            widget.__taAllowedGroupLink = groupLink;
            window.__taActiveWidget = widget;

            widget.querySelector('#ta-step-1').style.display  = 'none';
            widget.querySelector('#ta-loading').style.display  = '';
            widget.querySelector('#ta-step-2').style.display   = 'none';

            jQuery.post(taSystem.ajaxUrl, {
                action:         'ta_get_available_tas',
                product_id:     taSystem.productId,
                messenger:      messenger,
                student_gender: gender,
                nonce:          taSystem.nonce,
            }, function(res) {
                widget.querySelector('#ta-loading').style.display = 'none';
                if (!res.success || !res.data.tas.length) {
                    widget.querySelector('#ta-step-1').style.display = '';
                    errEl.textContent = res.data?.message || 'استادیاری با این مشخصات یافت نشد.';
                    errEl.style.display = '';
                    return;
                }
                taRenderTAList(res.data.tas, widget);
                widget.querySelector('#ta-step-2').style.display = '';
            });
        };
        </script>
        <?php
    }

      private function render_normal_flow( object $settings, int $uid, int $product_id ): void {
        $simple_types = ['critique', 'professional']; // بدون پیامرسان/جنسیت — لیست مستقیم
        $is_simple    = in_array( $settings->course_type, $simple_types, true );
        $active_messengers = (array) TA_Settings_Helper::get( 'supported_messengers', [ 'telegram', 'bale', 'eitaa' ] );
        $messenger_labels = [ 'telegram' => 'تلگرام', 'bale' => 'بله', 'eitaa' => 'ایتا' ];
        ?>
        <?php if ( $is_simple ) : ?>
        <!-- ── نقد اثر و حرفه‌ای: نمایش مستقیم لیست استادیاران ── -->
        <input type="hidden" id="ta-messenger" value="any">
        <input type="hidden" id="ta-gender"    value="any">
        <div id="ta-loading" class="ta-loading">⏳ در حال بارگذاری استادیاران...</div>
        <div id="ta-ta-list" style="display:none"></div>
        <div id="ta-error"   style="display:none" class="ta-message ta-message-error"></div>
        <script>var taCourseType = '<?= esc_js( $settings->course_type ) ?>';</script>
        <script>
        (function () {
            var widget = document.currentScript ? document.currentScript.closest('.ta-widget') : null;
            function loadCritiqueAssistants() {
                if (typeof window.taAutoLoadTAs === 'function') {
                    window.taAutoLoadTAs(widget);
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', loadCritiqueAssistants, { once: true });
            } else {
                loadCritiqueAssistants();
            }
        })();
        </script>

        <?php else : ?>
        <!-- ── جریان معمولی: پیامرسان + جنسیت + جستجو ── -->
        <div class="ta-step" id="ta-step-1">
            <?php if ( in_array( $settings->course_type, ['creative','basic_writing'], true ) ) : ?>
                <div class="ta-field-group">
                    <label>پیام‌رسان خود را انتخاب کنید:</label>
                    <select id="ta-messenger" class="ta-select">
                        <option value="">— انتخاب کنید —</option>
                        <?php foreach ( $active_messengers as $messenger ) :
                            if ( isset( $messenger_labels[ $messenger ] ) ) : ?>
                                <option value="<?= esc_attr( $messenger ) ?>"><?= esc_html( $messenger_labels[ $messenger ] ) ?></option>
                        <?php endif; endforeach; ?>
                    </select>
                </div>
            <?php else : ?>
                <input type="hidden" id="ta-messenger" value="auto">
                <?php $this->render_msglink_field( $settings->course_type, $uid, $product_id ); ?>
            <?php endif; ?>

            <?php

            $user_gender = get_user_meta( get_current_user_id(), 'student_gender', true );
            if ( in_array( $user_gender, [ 'male', 'female' ], true ) ) :
                $gender_fa = ( $user_gender === 'male' ) ? 'آقا' : 'خانم';
            ?>
            <div class="ta-field-group" id="ta-gender-display-box" style="display:flex;align-items:center;gap:10px;margin-bottom:15px;">
                <span style="font-size:14px;">جنسیت شما: <strong><?= esc_html($gender_fa) ?></strong></span>
                <button type="button" id="ta-btn-edit-gender" style="background:none;border:none;cursor:pointer;color:#007cba;padding:0;font-size:13px;display:inline-flex;align-items:center;gap:4px;">✏️ ویرایش</button>
            </div>
            <div class="ta-field-group" id="ta-gender-select-box" style="display:none;">
                <label>جنسیت خود را وارد کنید:</label>
                <select id="ta-gender" class="ta-select" name="student_gender">
                    <option value="male"   <?php selected($user_gender,'male');   ?>>آقا</option>
                    <option value="female" <?php selected($user_gender,'female'); ?>>خانم</option>
                </select>
            </div>
            <script>
            (function(){
            var widget = document.currentScript.closest('.ta-widget');
            widget.querySelector('#ta-btn-edit-gender').addEventListener('click', function(e){
                e.preventDefault();
                widget.querySelector('#ta-gender-display-box').style.display = 'none';
                widget.querySelector('#ta-gender-select-box').style.display  = 'block';
            });
            })();
            </script>
            <?php else : ?>
            <div class="ta-field-group">
                <label>جنسیت خود را وارد کنید:</label>
                <select id="ta-gender" class="ta-select" name="student_gender">
                    <option value="">— انتخاب کنید —</option>
                    <option value="male">آقا</option>
                    <option value="female">خانم</option>
                </select>
            </div>
            <?php endif; ?>

            <button class="ta-btn ta-btn-secondary" onclick="taSearchTAs(this)">🔍 جستجوی استادیار</button>
        </div>

        <div class="ta-step" id="ta-step-2" style="display:none">
            <div id="ta-ta-list"></div>
            <button class="ta-btn" onclick="taBackToStep1(this)">← بازگشت</button>
        </div>
        <?php endif; ?>

        <?php if ( ! $is_simple ) : ?>
        <div id="ta-loading" style="display:none" class="ta-loading">⏳ در حال جستجو...</div>
        <div id="ta-error"   style="display:none" class="ta-message ta-message-error"></div>
        <?php endif; ?>

        <script>var taCourseType = '<?= esc_js( $settings->course_type ) ?>';</script>
        <?php
    }

    /**
     * نمایش/ویرایش پیام‌رسان و لینک گروه استخراج‌شده از دوره پیش‌نیاز
     * (مشترک بین جریان معمولی [مقدماتی/پیشرفته] و جریان نردبان حرفه‌ای)
     */
    private function render_msglink_field( string $course_type, int $uid, int $product_id ): void {
        $prereq_assignment = TA_Assignment::find_prereq_assignment( $uid, $product_id, $course_type );
        // در مسیر تکرار، محصول هم‌نوعی که نمره تکرار داده منبع اصلی اطلاعات است؛
        // حتی اگر در فهرست پیش‌نیازهای محصول فعلی نباشد.
        $repeat_assignment = TA_Eligibility::get_repeat_assignment( $uid, $product_id, $course_type );
        if ( $repeat_assignment ) {
            $prereq_assignment = $repeat_assignment;
        }
        $prereq_messenger  = $prereq_assignment->messenger  ?? '';
        $prereq_link       = $prereq_assignment->group_link ?? '';
        $prereq_found      = ! empty( $prereq_messenger ) && ! empty( $prereq_link );
        $is_repeat_source  = (bool) $repeat_assignment;
        $msg_labels        = [ 'telegram' => 'تلگرام', 'bale' => 'بله', 'eitaa' => 'ایتا' ];
        $prereq_msg_label  = $msg_labels[ $prereq_messenger ] ?? $prereq_messenger;
        $active_messengers = (array) TA_Settings_Helper::get( 'supported_messengers', [ 'telegram', 'bale', 'eitaa' ] );
        ?>
        <div class="ta-field-group" id="ta-msglink-wrap" data-repeat-source="<?= $is_repeat_source ? '1' : '0' ?>">
            <?php if ( $prereq_found ) : ?>
                <div id="ta-msglink-display-box" style="display:flex;flex-direction:column;gap:6px;margin-bottom:6px;">
                    <span style="font-size:14px;">📡 پیام‌رسان شما: <strong><?= esc_html( $prereq_msg_label ) ?></strong></span>
                    <span style="font-size:14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        🔗 لینک گروه: <a href="<?= esc_url( $prereq_link ) ?>" target="_blank" dir="ltr" style="word-break:break-all;"><?= esc_html( $prereq_link ) ?></a>
                        <button type="button" id="ta-btn-edit-msglink" style="background:none;border:none;cursor:pointer;color:#007cba;padding:0;font-size:13px;display:inline-flex;align-items:center;gap:4px;">✏️ ویرایش</button>
                    </span>
                </div>
                <div class="ta-message ta-message-info" id="ta-msglink-auto-note" style="margin-bottom:10px;">
                    این اطلاعات به‌صورت خودکار از دوره <?= $is_repeat_source ? 'قبلی' : 'پیش‌نیاز' ?> شما استخراج شده است.
                </div>
            <?php endif; ?>

            <div id="ta-msglink-edit-box" style="<?= $prereq_found ? 'display:none;' : '' ?>">
                <?php if ( ! $prereq_found ) : ?>
                    <div class="ta-message ta-message-warning" style="margin-bottom:10px;">
                        اطلاعات پیام‌رسان و لینک گروه شما از دوره پیش‌نیاز یافت نشد. لطفاً آن را وارد کنید.
                    </div>
                <?php endif; ?>
                <label>پیام‌رسان:</label>
                <select id="ta-msglink-messenger-input" class="ta-select">
                    <option value="">— انتخاب کنید —</option>
                    <?php foreach ( $active_messengers as $messenger ) :
                        if ( isset( $msg_labels[ $messenger ] ) ) : ?>
                            <option value="<?= esc_attr( $messenger ) ?>" <?= selected( $prereq_messenger, $messenger, false ); ?>><?= esc_html( $msg_labels[ $messenger ] ) ?></option>
                    <?php endif; endforeach; ?>
                </select>
                <label style="margin-top:10px;">لینک گروه:</label>
                <input type="text" id="ta-msglink-link-input" class="ta-input" dir="ltr" placeholder="https://..." value="<?= esc_attr( $prereq_link ) ?>">
                <?php if ( $prereq_found ) : ?>
                    <button type="button" id="ta-btn-cancel-msglink" class="ta-btn ta-btn-cancel" style="margin-top:8px;">انصراف از ویرایش</button>
                <?php endif; ?>
            </div>

            <label style="margin-top:10px;display:flex;align-items:center;gap:6px;font-weight:normal;">
                <input type="checkbox" id="ta-msglink-confirm-checkbox">
                تایید می‌کنم پیام‌رسان و لینک گروه وارد شده صحیح است.
            </label>
            <div id="ta-msglink-error" style="display:none" class="ta-message ta-message-error"></div>
        </div>
        <script>
        (function(){
            var widget    = document.currentScript.closest('.ta-widget');
            var editBtn   = widget.querySelector('#ta-btn-edit-msglink');
            var cancelBtn = widget.querySelector('#ta-btn-cancel-msglink');
            if (editBtn) {
                editBtn.addEventListener('click', function(e){
                    e.preventDefault();
                    widget.querySelector('#ta-msglink-display-box').style.display = 'none';
                    widget.querySelector('#ta-msglink-auto-note').style.display    = 'none';
                    widget.querySelector('#ta-msglink-edit-box').style.display      = 'block';
                });
            }
            if (cancelBtn) {
                cancelBtn.addEventListener('click', function(e){
                    e.preventDefault();
                    widget.querySelector('#ta-msglink-messenger-input').value = '<?= esc_js( $prereq_messenger ) ?>';
                    widget.querySelector('#ta-msglink-link-input').value      = '<?= esc_js( $prereq_link ) ?>';
                    widget.querySelector('#ta-msglink-confirm-checkbox').checked = false;
                    widget.querySelector('#ta-msglink-edit-box').style.display      = 'none';
                    widget.querySelector('#ta-msglink-display-box').style.display   = 'flex';
                    widget.querySelector('#ta-msglink-auto-note').style.display      = '';
                });
            }
        })();
        </script>
        <?php
    }

    private function render_level_assess_flow( object $settings, int $uid, int $product_id ): void {
        $term_id         = (int) $settings->term_id;
        $level_assessors = $this->get_all_level_assessors( $term_id, $uid );
        ?>
        <div class="ta-level-assess-form">
            <div class="ta-message ta-message-info">
                📋 شما در دوره نویسندگی خلاق شرکت نکرده‌اید یا نمره قبولی دریافت نکرده‌اید.
                برای شرکت در این دوره باید تعیین سطح شوید.
            </div>
            <div class="ta-field-group">
                <label>انتخاب استادیار تعیین سطح:</label>
                <select id="ta-level-ta-id" class="ta-select">
                    <option value="">— انتخاب کنید —</option>
                    <?php foreach ( $level_assessors as $ta ) : ?>
                        <option value="<?= $ta->id ?>"><?= esc_html( $ta->ta_name ) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ta-field-group">
                <label>بارگذاری فایل متنی (نمونه نوشته):</label>
                <input type="file" id="ta-level-file" accept=".pdf,.doc,.docx,.txt">
                <small>فرمت‌های مجاز: PDF، Word، TXT — حداکثر ۱۰ مگابایت</small>
            </div>
            <button class="ta-btn ta-btn-primary" onclick="taSubmitLevelFile()">📤 ارسال برای تعیین سطح</button>
        </div>
        <div id="ta-level-success" style="display:none" class="ta-message ta-message-success"></div>
        <div id="ta-level-error"   style="display:none" class="ta-message ta-message-error"></div>
        <?php
    }

    private function render_level_assess_submit_group( int $uid, int $product_id ): void {
        $active_messengers = (array) TA_Settings_Helper::get( 'supported_messengers', [ 'telegram', 'bale', 'eitaa' ] );
        $messenger_labels = [ 'telegram' => 'تلگرام', 'bale' => 'بله', 'eitaa' => 'ایتا' ];
        ?>
        <div class="ta-message ta-message-success">✅ تعیین سطح شما تأیید شد!</div>
        <div class="ta-field-group">
            <label>پیام‌رسان:</label>
            <select id="ta-group-messenger" class="ta-select">
                <?php foreach ( $active_messengers as $messenger ) :
                    if ( isset( $messenger_labels[ $messenger ] ) ) : ?>
                        <option value="<?= esc_attr( $messenger ) ?>"><?= esc_html( $messenger_labels[ $messenger ] ) ?></option>
                <?php endif; endforeach; ?>
            </select>
        </div>
        <div class="ta-field-group">
            <label>لینک گروه:</label>
            <input type="url" id="ta-group-link-input" class="ta-input" placeholder="https://t.me/...">
            <small>لینک گروهی که در آن عضو هستید. استادیار به این گروه اضافه خواهد شد.</small>
        </div>
        <button class="ta-btn ta-btn-primary" onclick="taSubmitGroupLink()">✅ ثبت لینک گروه</button>
        <div id="ta-group-success" style="display:none" class="ta-message ta-message-success"></div>
        <div id="ta-group-error"   style="display:none" class="ta-message ta-message-error"></div>
        <?php
    }

    private function get_all_level_assessors( int $term_id, int $student_id ): array {
        global $wpdb;
        $ta_table  = TA_Database::table( 'assistants' );
        $cap_table = TA_Database::table( 'capacities' );
        $bl_table  = TA_Database::table( 'blacklist' );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT ta.*, u.display_name AS ta_name
             FROM {$ta_table} ta
             JOIN {$cap_table} cap ON cap.ta_id = ta.id
                                  AND cap.course_type = 'level_assess'
                                  AND cap.term_id = %d
             JOIN {$wpdb->users} u ON u.ID = ta.user_id
             LEFT JOIN {$bl_table} bl ON bl.ta_id = ta.id AND bl.student_id = %d
             WHERE ta.is_active = 1
               AND bl.id IS NULL
               AND cap.used_capacity < cap.total_capacity
             ORDER BY ta.rank DESC",
            [ $term_id, $student_id ]
        ) ) ?: [];
    }
        /**
     * نمایش دکمه تخصیص به استادیار اختصاصی برای دوره حرفه‌ای
     */
    private function render_assign_professional_flow( object $settings, int $uid, int $product_id ): void {
        global $wpdb;

        // استادیار حرفه‌ای فقط از روی ظرفیت حرفه‌ای و گروه ساخته‌شده پیدا می‌شود.
        $capacity = $wpdb->get_row( $wpdb->prepare(
            'SELECT ta.user_id, u.display_name AS ta_name,
                    (c.total_capacity - c.used_capacity) AS remaining, g.group_link
             FROM ' . TA_Database::table( 'capacities' ) . ' c
             INNER JOIN ' . TA_Database::table( 'groups' ) . ' g ON g.capacity_id = c.id
             INNER JOIN ' . TA_Database::table( 'assistants' ) . ' ta ON ta.id = c.ta_id AND ta.is_active = 1
             INNER JOIN ' . $wpdb->users . ' u ON u.ID = ta.user_id
             WHERE c.term_id = %d AND c.course_type = %s
               AND c.used_capacity < c.total_capacity AND g.group_link <> %s
             ORDER BY c.id ASC, g.id ASC LIMIT 1',
            (int) $settings->term_id, 'professional', ''
        ) );

        $ta_professional_user_id = $capacity ? (int) $capacity->user_id : 0;
        $ta_info = $capacity ? (object) [ 'display_name' => $capacity->ta_name ] : null;
        $remaining  = $capacity ? (int) $capacity->remaining : null;
        $group_link = $capacity ? (string) $capacity->group_link : '';

        echo '<div class="ta-message ta-message-success" style="margin-bottom:14px;">';
        echo '<p style="margin:0 0 12px;">✅ شما مجوز ورود به این دوره حرفه‌ای را دارید.</p>';

        if ( $ta_info && $ta_professional_user_id && $remaining !== null && $group_link !== '' ) {
            echo '<div class="ta-ta-card" style="display:flex;flex-direction:column;align-items:stretch;gap:10px;">';
            echo '<div style="width:100%;">';
            echo '<div class="ta-ta-card-name">' . esc_html( $ta_info->display_name ) . '</div>';
            echo '<div class="ta-ta-card-info">ظرفیت آزاد: ' . esc_html( $remaining !== null ? (int) $remaining : 0 ) . '</div>';
            echo '</div>';
            echo '<button type="button" class="ta-group-entry-btn ta-assign-professional"
                    data-product-id="' . esc_attr( $product_id ) . '"
                    data-ta-id="' . esc_attr( $ta_professional_user_id ) . '">ورود به گروه</button>';
            echo '</div>';
        } else {
            echo '<p class="ta-message ta-message-error" style="margin-top:15px;">⚠️ برای این دوره ظرفیت حرفه‌ایِ دارای گروه ساخته‌شده پیدا نشد. لطفاً ظرفیت و گروه استادیار را بررسی کنید.</p>';
        }
        echo '</div>';
    }

    private function render_assign_ladder_flow( object $settings, int $uid, int $product_id ): void {
        global $wpdb;

        // ── بررسی وضعیت pending_admin ─────────────────────────────────────
        $assign_table   = TA_Database::table( 'assignments' );
        $existing_status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$assign_table}
             WHERE student_id = %d AND product_id = %d
             ORDER BY id DESC LIMIT 1",
            $uid, $product_id
        ) );

        if ( in_array( $existing_status, [ 'pending_admin', 'pending_admin_reviewed' ], true ) ) {
            $pid = esc_attr( $product_id );
            ?>
            <div id="ta-ladder-wrap-<?= $pid ?>" >

                <!-- هدر -->
                <div style="background:linear-gradient(135deg,#f97316,#fb923c);border-radius:16px;padding:24px;color:#fff;text-align:center;margin-bottom:20px;box-shadow:0 4px 15px rgba(249,115,22,.3)">
                    <div style="font-size:2.5rem;margin-bottom:8px">🪜</div>
                    <h3 style="margin:0 0 6px;font-size:1.3rem">دوره نردبان حرفه‌ای</h3>
                    <p style="margin:0;opacity:.9;font-size:.9rem">شما واجد شرایط ورود به این دوره هستید</p>
                </div>

                <!-- پیام انتظار تخصیص مسئول اجرایی مبنا -->
                <div style="background:#fffbeb;border:2px solid #fcd34d;border-radius:14px;padding:24px;text-align:center">
                    <div style="font-size:2.2rem;margin-bottom:10px">⏳</div>
                    <p style="margin:0;font-size:1rem;font-weight:700;color:#92400e;margin-bottom:8px">
                        درخواست شما ثبت شد.
                    </p>
                    <p style="margin:0;font-size:.9rem;color:#78350f;line-height:1.7">
                        مسئول اجرایی مبنا به زودی استادیار را برای شما تخصیص خواهد داد.
                    </p>
                </div>

            </div>
            <?php
            return;
        }

        // ── ادامه جریان عادی ─────────────────────────────────────────────
        $prereq_ids   = TA_Product_Meta::get_prerequisites( $product_id );
        $prev_ta      = null;

        foreach ( (array) $prereq_ids as $prereq_id ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT a.ta_id, u.display_name AS ta_name, u.user_email AS ta_email
                 FROM {$assign_table} a
                 LEFT JOIN " . TA_Database::table('assistants') . " ta ON ta.id = a.ta_id
                 LEFT JOIN {$wpdb->users} u ON u.ID = ta.user_id
                 WHERE a.student_id = %d AND a.product_id = %d AND a.ta_id > 0
                 ORDER BY a.id DESC LIMIT 1",
                $uid, $prereq_id
            ) );
            if ( $row && $row->ta_id ) {
                $prev_ta = $row;
                break;
            }
        }

        // بررسی ظرفیت استادیار قبل
        $cap_table      = TA_Database::table( 'capacities' );
        $student_gender = get_user_meta( $uid, 'gender', true ) ?: 'unknown';
        $has_capacity   = false;

        if ( $prev_ta ) {
            $cap = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$cap_table}
                 WHERE ta_id = %d AND course_type = 'ladder' AND term_id = %d
                   AND student_gender IN (%s, 'both') AND used_capacity < total_capacity
                 LIMIT 1",
                $prev_ta->ta_id, $settings->term_id, $student_gender
            ) );
            $has_capacity = (bool) $cap;
        }

        $pid = esc_attr( $product_id );
        ?>
        <div id="ta-ladder-wrap-<?= $pid ?>" >

            <!-- هدر -->
            <div style="background:linear-gradient(135deg,#f97316,#fb923c);border-radius:16px;padding:24px;color:#fff;text-align:center;margin-bottom:20px;box-shadow:0 4px 15px rgba(249,115,22,.3)">
                <div style="font-size:2.5rem;margin-bottom:8px">🪜</div>
                <h3 style="margin:0 0 6px;font-size:1.3rem">دوره نردبان حرفه‌ای</h3>
                <p style="margin:0;opacity:.9;font-size:.9rem">شما واجد شرایط ورود به این دوره هستید</p>
            </div>

            <?php if ( $prev_ta ) : ?>

                <!-- اطلاعات استادیار قبلی -->
                <div style="background:#fff7ed;border:2px solid #fed7aa;border-radius:12px;padding:18px;margin-bottom:16px">
                    <p style="margin:0 0 8px;font-weight:600;color:#9a3412;font-size:.95rem">👨‍🏫 استادیار پیشرفته شما:</p>
                    <div style="display:flex;align-items:center;gap:12px">
                        <div style="width:46px;height:46px;border-radius:50%;background:linear-gradient(135deg,#f97316,#fb923c);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:1.2rem;flex-shrink:0">
                            <?= mb_substr( $prev_ta->ta_name, 0, 1 ) ?>
                        </div>
                        <div>
                            <p style="margin:0;font-weight:600;color:#1f2937"><?= esc_html( $prev_ta->ta_name ) ?></p>
                            <?php if ( $has_capacity ) : ?>
                                <span style="display:inline-block;background:#d1fae5;color:#065f46;border-radius:20px;padding:2px 10px;font-size:.78rem;margin-top:4px">✅ ظرفیت دارد</span>
                            <?php else : ?>
                                <span style="display:inline-block;background:#fee2e2;color:#991b1b;border-radius:20px;padding:2px 10px;font-size:.78rem;margin-top:4px">⚠️ ظرفیت تکمیل است</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ( ! $has_capacity ) : ?>
                    <!-- ظرفیت ندارد -->
                    <div style="background:#fef9c3;border:1.5px solid #fde047;border-radius:12px;padding:16px;margin-bottom:16px;text-align:center">
                        <p style="margin:0;color:#713f12;font-size:.9rem">⚠️ متأسفانه استادیار قبلی شما در این ترم ظرفیت نردبان حرفه‌ای ندارد. با کلیک روی دکمه زیر، درخواست شما برای مسئول اجرایی مبنا ارسال می‌شود تا استادیار مناسب تعیین کند.</p>
                    </div>
                    <button class="ta-btn ta-btn-primary ta-assign-ladder-admin"
                            data-product-id="<?= $pid ?>"
                            style="width:100%;padding:14px;font-size:1rem;border-radius:10px;background:linear-gradient(135deg,#f97316,#fb923c);border:none;color:#fff;cursor:pointer;font-weight:600">
                        ⏳ درخواست تخصیص
                    </button>

                <?php else : ?>
                    <!-- ظرفیت دارد — انتخاب -->
                    <?php $this->render_msglink_field( 'ladder', $uid, $product_id ); ?>
                    <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:16px;margin-bottom:16px;display:flex;flex-direction:column;gap:10px">

                        <!-- دکمه تأیید استادیار -->
                        <button class="ta-btn ta-btn-primary ta-assign-ladder-confirm"
                                data-product-id="<?= $pid ?>"
                                style="width:100%;padding:14px;font-size:1rem;border-radius:10px;background:linear-gradient(135deg,#059669,#10b981);border:none;color:#fff;cursor:pointer;font-weight:600">
                            ✅ تأیید انتخاب استادیار (<?= esc_html( $prev_ta->ta_name ) ?>)
                        </button>

                        <!-- دکمه درخواست تخصیص بدون استادیار -->
                        <button class="ta-btn ta-assign-ladder-admin"
                                data-product-id="<?= $pid ?>"
                                style="width:100%;padding:10px;font-size:.88rem;border-radius:8px;background:#fff7ed;border:1.5px solid #fdba74;color:#1a1a1a;cursor:pointer;font-weight:500">
                            🔄 ادامه بدون استادیار قبلی — درخواست تخصیص
                        </button>
                    </div>

                <?php endif; ?>

            <?php else : ?>
                <!-- استادیار قبلی یافت نشد -->
                <div style="background:#fef9c3;border:1.5px solid #fde047;border-radius:12px;padding:16px;margin-bottom:16px;text-align:center">
                    <p style="margin:0;color:#713f12">⚠️ استادیار پیشرفته قبلی شما یافت نشد. مسئول اجرایی مبنا برای شما استادیار تعیین خواهد کرد.</p>
                </div>
                <button class="ta-btn ta-btn-primary ta-assign-ladder-admin"
                        data-product-id="<?= $pid ?>"
                        style="width:100%;padding:14px;font-size:1rem;border-radius:10px;background:linear-gradient(135deg,#f97316,#fb923c);border:none;color:#fff;cursor:pointer;font-weight:600">
                    ⏳ درخواست تخصیص استادیار
                </button>
            <?php endif; ?>

        </div>
        <?php
    }


}
