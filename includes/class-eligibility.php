<?php
defined( 'ABSPATH' ) || exit;

/**
 * بررسی صلاحیت هنرجو برای هر نوع دوره
 */
class TA_Eligibility {


    public static function check( int $student_id, int $product_id ): array {
    // بررسی فعال بودن سیستم
    if ( ! TA_Settings_Helper::system_active() ) {
        return self::result( false, 'blocked', 'سیستم انتخاب استادیار در حال حاضر غیرفعال است.' );
    }
    $settings = self::get_product_settings( $product_id );
    if ( ! $settings ) {
        return self::result( false, 'blocked', 'تنظیمات این محصول ناقص است. با پشتیبانی تماس بگیرید.' );
    }

    // ── بررسی خرید ─────────────────────────────────────────────────────
if ( TA_Settings_Helper::is_enabled( 'require_purchase' ) && ! self::student_has_purchased( $student_id, $product_id ) ) {
    
    // تعریف شناسه‌های دوره‌هایی که پیام متفاوتی دارند
    // بر اساس ساختار افزونه، 'professional' برای حرفه‌ای و احتمالاً 'advanced' یا 'creative' برای نقد اثر است
    $custom_message_courses = ['professional', 'critique']; 

    if ( in_array( $settings->course_type, $custom_message_courses, true ) ) {
        // پیام برای دوره‌های نقد اثر و نویسندگی حرفه‌ای
        $error_message = 'برای ورود به دوره، ابتدا ثبت نام کنید سپس از اینجا وارد گروه یا کانال دوره شوید.';
    } else {
        // پیام پیش‌فرض برای سایر دوره‌ها
        $error_message = TA_Settings_Helper::get_text('text_not_purchased');
    }

    return self::result(
        false,
        'blocked',
        $error_message
    );
}












    // ── بررسی اگر قبلاً استادیار انتخاب کرده (برای همه دوره‌ها به‌جز نردبان) ──
    if ( $settings->course_type !== 'ladder' && self::already_assigned( $student_id, $product_id ) ) {
        // اگر تغییر استادیار مجاز باشد، بررسی می‌کنیم
        if ( TA_Settings_Helper::is_enabled( 'allow_reassignment' ) ) {
            $limit_days = (int) TA_Settings_Helper::get( 'reassignment_limit_days', 0 );
            if ( $limit_days > 0 ) {
                $assign_table = TA_Database::table( 'assignments' );
                $row = TA_Database::get_row(
                    "SELECT created_at FROM {$assign_table} WHERE student_id = %d AND product_id = %d ORDER BY id DESC LIMIT 1",
                    [ $student_id, $product_id ]
                );
                if ( $row ) {
                    $elapsed = ( time() - strtotime( $row->created_at ) ) / DAY_IN_SECONDS;
                    if ( $elapsed > $limit_days ) {
                        return self::result( false, 'blocked', sprintf(
                            'مهلت تغییر استادیار (%d روز) به پایان رسیده است.',
                            $limit_days
                        ) );
                    }
                }
            }
            // مهلت هنوز باقی است — اجازه ادامه
        } else {
            return self::result( false, 'blocked', TA_Settings_Helper::get_text('text_already_assigned') );
        }
    }

    // ── مجوز شرکت مجدد بر اساس نمره تکرار در هر محصول هم‌نوع ───────────────
    // این قاعده به رابطه پیش‌نیاز وابسته نیست؛ نوع دوره از تنظیمات محصول
    // فعلی و نمره از هر محصول قبلیِ همان نوع خوانده می‌شود.
    if ( self::has_repeat_grade_for_course_type( $student_id, $product_id, $settings->course_type ) ) {
        if ( $settings->course_type === 'professional' ) {
            return self::result( true, 'assign_professional', 'مجوز شرکت مجدد بر اساس نمره تکرار.' );
        }
        if ( $settings->course_type === 'ladder' ) {
            return self::result( true, 'assign_ladder', 'مجوز شرکت مجدد بر اساس نمره تکرار.' );
        }
        return self::result( true, 'normal', 'مجوز شرکت مجدد بر اساس نمره تکرار.' );
    }

    // ── بررسی اجازه انتخاب مستقیم استادیار (تعیین سطح شده) ────────────────
    if ( TA_Order_Allowed::course_type_eligible( $settings->course_type ) ) {
        if ( TA_Order_Allowed::is_allowed_for_student( $student_id, $product_id ) ) {
            // نردبان حرفه‌ای هرگز وارد فرم عمومی انتخاب استادیار نمی‌شود.
            // تیک تعیین سطح فقط اجازه می‌دهد استادیار پیشرفته قبلی و ظرفیت نردبان او بررسی شود؛
            // در نبود شرایط، جریان اختصاصی نردبان درخواست تخصیص ادمین را نمایش می‌دهد.
            if ( $settings->course_type === 'ladder' ) {
                return self::result( true, 'assign_ladder', '' );
            }
            if ( $settings->course_type === 'professional' ) {
                return self::result( true, 'assign_professional', '' );
            }
            return self::result( true, 'ta_allowed', '' );
        }
    }

    switch ( $settings->course_type ) {
        case 'creative':
            return self::check_creative( $student_id, $product_id, $settings );
        case 'intro':
            return self::check_intro( $student_id, $product_id, $settings );
        case 'basic_writing':
            return self::check_basic_writing( $student_id, $product_id, $settings );
        case 'advanced':
            return self::check_advanced( $student_id, $product_id, $settings );
        case 'ladder':
            return self::check_ladder( $student_id, $product_id, $settings );
        case 'professional':
            return self::check_professional( $student_id, $product_id, $settings );
        case 'critique':
            return self::check_critique( $student_id, $product_id, $settings );
        default:
            return self::result( false, 'blocked', 'نوع دوره ناشناخته است.' );
    }
}


    // ── نقد ───────────────────────────────────────────────────────
    private static function check_critique( int $student_id, int $product_id, object $settings ): array {
       return self::result( true, 'normal', '' );
    }
    
    // ── نویسندگی خلاق ───────────────────────────────────────────────────────
    private static function check_creative( int $student_id, int $product_id, object $settings ): array {
        return self::result( true, 'normal', '' );
    }
    
    
  // ── نویسندگی پایه ───────────────────────────────────────────────────────
    private static function check_basic_writing( int $student_id, int $product_id, object $settings ): array {
        // دوره نویسندگی پایه — مثل خلاق، پیش‌نیاز خاصی ندارد
        return self::result( true, 'normal', '' );
    }


    // ── نویسندگی مقدماتی ────────────────────────────────────────────────────
/*    private static function check_intro( int $student_id, int $product_id, object $settings ): array {
        $prereq_ids = TA_Product_Meta::get_prerequisites( $product_id );

        // اگر پیش‌نیازی تعریف نشده
        if ( empty( $prereq_ids ) ) {
            return self::result( false, 'blocked', 'پیش‌نیاز این دوره تعریف نشده است. با پشتیبانی تماس بگیرید.' );
        }

        $best_grade     = null;
        $has_pass       = false;
        $has_critique   = false; // نمره «نقد اثر» — مجاز برای ورود
        $has_repeat     = false;

        foreach ( $prereq_ids as $prereq_id ) {
            $grade_obj = TA_Grade::get_grade( $student_id, $prereq_id );
            if ( ! $grade_obj ) { continue; }

            if ( $grade_obj->grade === TA_Grade::GRADE_PASS ) {
                $has_pass   = true;
                $best_grade = $grade_obj;
                break;
            } elseif ( $grade_obj->grade === 'critique' ) {
                // نمره «نقد اثر» در دوره مقدماتی — مجاز
                $has_critique = true;
                $best_grade   = $grade_obj;
            } elseif ( $grade_obj->grade === TA_Grade::GRADE_REPEAT ) {
                $has_repeat = true;
            }
        }

        // قبول یا نقد اثر → مجاز
        if ( $has_pass || $has_critique ) {
            return self::result( true, 'normal', '', $best_grade );
        }

        // تکرار دوره → مسدود
        if ( $has_repeat ) {
            return self::result( false, 'blocked', 'شما در دوره پیش‌نیاز نتیجه «تکرار دوره» دریافت کرده‌اید. لطفاً از طریق چت سایت با پشتیبانی در تماس باشید.' );
        }

        return self::result( false, 'blocked', 'برای شرکت در این دوره، باید ابتدا در دوره نویسندگی خلاق نمره قبولی دریافت کنید.' );
}
*/
      private static function check_intro( int $student_id, int $product_id, object $settings ): array {
    $prereq_ids = TA_Product_Meta::get_prerequisites( $product_id );

    if ( empty( $prereq_ids ) ) {
        return self::result( false, 'blocked', 'پیش‌نیاز این دوره تعریف نشده است. با پشتیبانی تماس بگیرید.' );
    }

    $best_grade              = null;
    $has_pass                = false;
    $has_repeat_from_intro   = false;

    foreach ( $prereq_ids as $prereq_id ) {
        $grade_obj = TA_Grade::get_grade( $student_id, $prereq_id );
        if ( ! $grade_obj ) {
            continue;
        }

        // دریافت نوع دورهٔ پیش‌نیاز
        $prereq_settings = self::get_product_settings( $prereq_id );
        $course_type = $prereq_settings ? $prereq_settings->course_type : '';

        // ── مجوز مقدماتی از دوره پایه ──
        if ( $grade_obj->grade === 'intro_permission' && $course_type === 'basic_writing' ) {
            $has_pass   = true;
            $best_grade = $grade_obj;
            break;
        }

        // ── نمرهٔ قبولی (از هر نوع دوره‌ای معتبر است) ──
        if ( $grade_obj->grade === TA_Grade::GRADE_PASS ) {
            $has_pass   = true;
            $best_grade = $grade_obj;
            break; // اولویت با قبولی، دیگر نیازی به بررسی نیست
        }

        // ── نمرهٔ تکرار (فقط از دورهٔ مقدماتی معتبر است) ──
        if ( $grade_obj->grade === TA_Grade::GRADE_REPEAT && $course_type === 'intro' ) {
            $has_repeat_from_intro = true;
            $best_grade = $grade_obj;
            // ادامه می‌دهیم تا شاید نمرهٔ PASS هم پیدا شود
        }
    }

    // مجوز در صورت وجود قبولی یا تکرار معتبر از مقدماتی
    if ( $has_pass || $has_repeat_from_intro ) {
        return self::result( true, 'normal', '', $best_grade );
    }

   

return self::result( false, 'blocked', 'برای شرکت در این دوره، باید در دوره نویسندگی خلاق نمره «قبولی» دریافت کنید.' );

}


    // ── نویسندگی پیشرفته ────────────────────────────────────────────────────
/*    private static function check_advanced( int $student_id, int $product_id, object $settings ): array {
        $prereq_ids = TA_Product_Meta::get_prerequisites( $product_id );

        if ( empty( $prereq_ids ) ) {
            return self::result( false, 'blocked', 'پیش‌نیاز این دوره تعریف نشده است. با پشتیبانی تماس بگیرید.' );
        }

        $best_grade    = null;
        $has_critique  = false;

        foreach ( $prereq_ids as $prereq_id ) {
            $grade = TA_Grade::get_grade( $student_id, $prereq_id );
            if ( ! $grade ) { continue; }

            if ( $grade->grade === TA_Grade::GRADE_PASS ) {
                $best_grade = $grade;
                break;
            }
            // نمره 'critique' (نقد اثر) از دوره مقدماتی — مجاز برای پیشرفته
            if ( $grade->grade === 'critique' ) {
                $has_critique = true;
                $best_grade   = $grade;
            }
        }

        // قبول یا نقد اثر → مجاز
        if ( $best_grade ) {
            return self::result( true, 'normal', '', $best_grade );
        }

        return self::result( false, 'blocked', 'برای ورود به این دوره، باید در دوره نویسندگی مقدماتی نمره «قبول» یا «نقد اثر» دریافت کرده باشید.' );
    }
*/
     private static function check_advanced( int $student_id, int $product_id, object $settings ): array {
    $prereq_ids = TA_Product_Meta::get_prerequisites( $product_id );

    if ( empty( $prereq_ids ) ) {
        return self::result( false, 'blocked', 'پیش‌نیاز این دوره تعریف نشده است. با پشتیبانی تماس بگیرید.' );
    }

    // زیرمجموعه‌ای از پیش‌نیازهای «مقدماتی» که قبولی‌شان به‌تنهایی برای ورود کافی است.
    // اگر برای محصول مشخص نشده باشد (آرایه پیش‌فرض)، یعنی هیچ محدودیتی اعمال نمی‌شود
    // و قبولی هر کدام از مقدماتی‌های موجود در پیش‌نیاز کافی است (رفتار قبلی).
    $pass_eligible_ids = TA_Product_Meta::get_pass_eligible_prerequisites( $product_id );

    $has_valid_non_critique = false; // نمره قبول از مقدماتیِ مجاز، یا از هر دوره غیر نقد دیگر، یا تکرار از advanced
    $has_critique_pass      = false; // نمره قبولی از یک دوره نقد اثر
    $has_intro_critique     = false; // نمره «نقد اثر» از یک دوره مقدماتی (بدون محدودیت زیرمجموعه)
    $best_grade             = null;

    foreach ( $prereq_ids as $prereq_id ) {
        $grade = TA_Grade::get_grade( $student_id, $prereq_id );
        if ( ! $grade ) {
            continue;
        }

        // دریافت تنظیمات دورهٔ پیش‌نیاز
        $prereq_settings = self::get_product_settings( $prereq_id );
        $course_type = $prereq_settings ? $prereq_settings->course_type : '';

        // ── دوره نقد اثر ──────────────────────────────
        if ( $course_type === 'critique' ) {
            // فقط نمرهٔ قبولی را به‌عنوان شرط ثبت می‌کنیم (تکرار نادیده گرفته می‌شود)
            if ( $grade->grade === TA_Grade::GRADE_PASS ) {
                $has_critique_pass = true;
                $best_grade = $grade;
            }
            // نمرهٔ تکرار از نقد اثر: هیچ کاری نمی‌کنیم (مجاز نیست)
            continue;
        }

        // ── دوره مقدماتی یا پایه با نمرهٔ قبولی ──────────────
        if ( in_array( $course_type, [ 'intro', 'basic_writing' ], true ) && $grade->grade === TA_Grade::GRADE_PASS ) {
            // اگر زیرمجموعهٔ «قبولی مستقیم» تعریف شده و این پیش‌نیاز در آن نیست، نادیده گرفته می‌شود
            if ( ! empty( $pass_eligible_ids ) && ! in_array( $prereq_id, $pass_eligible_ids, true ) ) {
                continue;
            }
            $has_valid_non_critique = true;
            $best_grade = $grade;
            break;
        }

        // ── سایر دوره‌های غیر از نقد و غیر از مقدماتی (مثلاً تکرار از advanced) ──
        // اگر نمرهٔ قبولی باشد → مجوز فوری (از هر نوع دوره‌ای غیر از نقد و غیر از مقدماتی)
        if ( ! in_array( $course_type, [ 'intro', 'basic_writing' ], true ) && $grade->grade === TA_Grade::GRADE_PASS ) {
            $has_valid_non_critique = true;
            $best_grade = $grade;
            break;
        }

        // اگر نمرهٔ تکرار باشد → فقط در صورتی مجاز که دوره از نوع 'advanced' باشد
        if ( $grade->grade === TA_Grade::GRADE_REPEAT ) {
            if ( $course_type === 'advanced' ) {
                $has_valid_non_critique = true;
                $best_grade = $grade;
                break;
            }
            // تکرار از سایر دوره‌ها (مقدماتی، پایه، حرفه‌ای، نردبان و ...) مجاز نیست
            continue;
        }

        // اگر دوره مقدماتی و نمرهٔ آن «نقد اثر» باشد (حالت خاص) — بدون محدودیت زیرمجموعه،
        // هر کدام از ۴ مقدماتی می‌تواند این شرط را تأمین کند
        if ( $course_type === 'intro' && $grade->grade === 'critique' ) {
            $has_intro_critique = true;
            $best_grade = $grade;
        }
    }

    // ── ① مجوز از طریق دوره‌های غیر نقد (قبولی از مقدماتیِ مجاز یا هر نوع دیگر، یا تکرار از advanced) ──
    if ( $has_valid_non_critique ) {
        return self::result( true, 'normal', '', $best_grade );
    }

    // ── ② مجوز از طریق ترکیب نقد اثر + مقدماتی با نقد اثر ──
    if ( $has_critique_pass && $has_intro_critique ) {
        return self::result( true, 'normal', '', $best_grade );
    }

    // ── ③ هیچ شرطی برآورده نشد ──
   
return self::result( false, 'blocked', 'برای ورود به این دوره، باید در دوره نویسندگی مقدماتی یا نقد اثر نمره «قبولی» دریافت کرده باشید.' );


}

    // ── نویسندگی حرفه‌ای ────────────────────────────────────────────────────
/*   private static function check_professional( int $student_id, int $product_id, object $settings ): array {
        
        // ── ① اگر قبلاً تخصیص فعال دارد، مستقیم به show_link ──────────────
    if ( self::already_assigned( $student_id, $product_id ) ) {
        return self::result(
            true,
            'show_link',
            'شما قبلاً در این دوره حرفه‌ای استادیار دارید.'
        );
    }
    // ────────────────────────────────────────────────────────────────────
        
        $prereq_ids = TA_Product_Meta::get_prerequisites( $product_id );

        if ( empty( $prereq_ids ) ) {
            return self::result( false, 'blocked', 'پیش‌نیاز این دوره تعریف نشده است. با پشتیبانی تماس بگیرید.' );
        }

        $best_grade = null;

        foreach ( $prereq_ids as $prereq_id ) {
            $grade = TA_Grade::get_grade( $student_id, $prereq_id );
            if ( ! $grade ) {
                continue;
            }
            if ( $grade->grade === TA_Grade::GRADE_PASS ) {
                $best_grade = $grade;
                break; 
            }
        }

        if ( $best_grade ) {
              return self::result( true, 'normal', '', $best_grade );
        }

        return self::result( false, 'blocked', 'شما مجوز شرکت در دوره حرفه‌ای را ندارید. ابتدا باید پیش‌نیازها را با موفقیت بگذرانید.' );
    }
*/
      private static function check_professional( int $student_id, int $product_id, object $settings ): array {
    // اگر قبلاً تخصیص فعال دارد، مستقیم به show_link
    if ( self::already_assigned( $student_id, $product_id ) ) {
        return self::result(
            true,
            'show_link',
            'شما قبلاً در این دوره حرفه‌ای استادیار دارید.'
        );
    }

    $prereq_ids = TA_Product_Meta::get_prerequisites( $product_id );

    if ( empty( $prereq_ids ) ) {
        return self::result( false, 'blocked', 'پیش‌نیاز این دوره تعریف نشده است. با پشتیبانی تماس بگیرید.' );
    }

    $best_grade                           = null;
    $has_valid_repeat_from_professional   = false;

    foreach ( $prereq_ids as $prereq_id ) {
        $grade = TA_Grade::get_grade( $student_id, $prereq_id );
        if ( ! $grade ) {
            continue;
        }

        // دریافت نوع دورهٔ پیش‌نیاز
        $prereq_settings = self::get_product_settings( $prereq_id );
        $course_type = $prereq_settings ? $prereq_settings->course_type : '';

        // ── نمرهٔ قبولی: از هر نوع دوره‌ای مجاز است ──
        if ( $grade->grade === TA_Grade::GRADE_PASS ) {
            $best_grade = $grade;
            break; // اولویت با قبولی
        }

        // ── نمرهٔ تکرار: فقط از دورهٔ حرفه‌ای مجاز است ──
        if ( $grade->grade === TA_Grade::GRADE_REPEAT && $course_type === 'professional' ) {
            $has_valid_repeat_from_professional = true;
            $best_grade = $grade;
            // ادامه می‌دهیم تا شاید قبولی پیدا شود (اولویت با قبولی)
        }
    }

    // مجوز در صورت وجود قبولی (از هر نوع) یا تکرار معتبر از حرفه‌ای
    if ( $best_grade && $best_grade->grade === TA_Grade::GRADE_PASS ) {
        return self::result( true, 'normal', '', $best_grade );
    }

    if ( $has_valid_repeat_from_professional ) {
        return self::result( true, 'normal', '', $best_grade );
    }

    return self::result( false, 'blocked', 'شما مجوز شرکت در دوره نویسندگی حرفه‌ای را ندارید. ابتدا باید پیش‌نیازها را با موفقیت بگذرانید.' );

    
}
  

    // ── نردبان حرفه‌ای ────────────────────────────────────────────────────
/*   private static function check_ladder( int $student_id, int $product_id, object $settings ): array {
        // بررسی تخصیص موجود
        if ( self::already_assigned( $student_id, $product_id ) ) {
            // اگر وضعیت pending_admin باشد، هنرجو را به جریان نردبان بفرست تا پیام انتظار نمایش داده شود
            $table  = TA_Database::table( 'assignments' );
            global $wpdb;
            $status = $wpdb->get_var( $wpdb->prepare(
                "SELECT status FROM {$table} WHERE student_id = %d AND product_id = %d ORDER BY id DESC LIMIT 1",
                $student_id, $product_id
            ) );
            if ( in_array( $status, [ 'pending_admin', 'pending_admin_reviewed' ], true ) ) {
                return self::result( true, 'assign_ladder', '' );
            }
            return self::result( true, 'show_link', 'شما قبلاً در این دوره استادیار دارید.' );
        }

        $prereq_ids = TA_Product_Meta::get_prerequisites( $product_id );
        if ( empty( $prereq_ids ) ) {
            return self::result( false, 'blocked', 'پیش‌نیاز این دوره تعریف نشده است. با پشتیبانی تماس بگیرید.' );
        }

        // هنرجو باید از دوره پیشرفته نمره «نردبان حرفه‌ای» گرفته باشد
        $best_grade = null;
        foreach ( $prereq_ids as $prereq_id ) {
            $grade = TA_Grade::get_grade( $student_id, $prereq_id );
            if ( ! $grade ) { continue; }
            if ( $grade->grade === TA_Grade::GRADE_ADVANCED_LADDER ) {
                $best_grade = $grade;
                break;
            }
        }

        if ( $best_grade ) {
            return self::result( true, 'assign_ladder', '', $best_grade );
        }

        return self::result( false, 'blocked', 'برای ورود به دوره نردبان حرفه‌ای، ابتدا باید در دوره پیشرفته نمره «نردبان حرفه‌ای» دریافت کنید.' );
    }
*/    
     private static function check_ladder( int $student_id, int $product_id, object $settings ): array {
    // بررسی تخصیص موجود
    if ( self::already_assigned( $student_id, $product_id ) ) {
        $table  = TA_Database::table( 'assignments' );
        global $wpdb;
        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$table} WHERE student_id = %d AND product_id = %d ORDER BY id DESC LIMIT 1",
            $student_id, $product_id
        ) );
        if ( in_array( $status, [ 'pending_admin', 'pending_admin_reviewed' ], true ) ) {
            return self::result( true, 'assign_ladder', '' );
        }
        return self::result( true, 'show_link', 'شما قبلاً در این دوره استادیار دارید.' );
    }

    $prereq_ids = TA_Product_Meta::get_prerequisites( $product_id );
    if ( empty( $prereq_ids ) ) {
        return self::result( false, 'blocked', 'پیش‌نیاز این دوره تعریف نشده است. با پشتیبانی تماس بگیرید.' );
    }

    $best_grade = null;

    foreach ( $prereq_ids as $prereq_id ) {
        $grade = TA_Grade::get_grade( $student_id, $prereq_id );
        if ( ! $grade ) {
            continue;
        }

        // شرط اول: نمره «نردبان حرفه‌ای» – بدون محدودیت نوع
        if ( $grade->grade === TA_Grade::GRADE_ADVANCED_LADDER ) {
            $best_grade = $grade;
            break;
        }

        // شرط دوم: نمره «تکرار دوره» – فقط در صورتی مجاز است که پیش‌نیاز، خودش لادر باشد
        if ( $grade->grade === TA_Grade::GRADE_LADDER_REPEAT ) {
            if ( TA_Product_Meta::is_ladder( $prereq_id ) ) {
                $best_grade = $grade;
                break;
            }
        }
    }

    if ( $best_grade ) {
        return self::result( true, 'assign_ladder', '', $best_grade );
    }

    return self::result( false, 'blocked', 'برای ورود به دوره نردبان حرفه‌ای، باید در دوره نویسندگی پیشرفته نمره «نردبان حرفه‌ای» دریافت کرده باشید یا دوره های قبلی نردبان حرفه ای را «تکرار دوره» شده باشید.' );
    

}
   
   
   
   
   
   // ── Helpers ─────────────────────────────────────────────────────────────

    private static function has_repeat_grade_for_course_type( int $student_id, int $product_id, string $course_type ): bool {
        $repeat_grades = match ( $course_type ) {
            'intro', 'professional' => [ TA_Grade::GRADE_REPEAT ],
            'advanced'              => [ TA_Grade::GRADE_ADVANCED_REPEAT, TA_Grade::GRADE_REPEAT ],
            'ladder'                => [ TA_Grade::GRADE_LADDER_REPEAT ],
            default                 => [],
        };

        if ( empty( $repeat_grades ) ) {
            return false;
        }

        global $wpdb;
        $grades_table = TA_Database::table( 'grades' );
        $placeholders = implode( ',', array_fill( 0, count( $repeat_grades ), '%s' ) );
        $params = array_merge( [ $student_id, $product_id, $course_type ], $repeat_grades );
        $sql = $wpdb->prepare(
            "SELECT id FROM {$grades_table}
             WHERE student_id = %d AND product_id <> %d AND course_type = %s
               AND grade IN ({$placeholders})
             ORDER BY id DESC LIMIT 1",
            ...$params
        );

        return (bool) $wpdb->get_var( $sql );
    }

    public static function get_repeat_assignment( int $student_id, int $product_id, string $course_type ): ?object {
        $repeat_grades = match ( $course_type ) {
            'intro', 'professional' => [ TA_Grade::GRADE_REPEAT ],
            'advanced'              => [ TA_Grade::GRADE_ADVANCED_REPEAT, TA_Grade::GRADE_REPEAT ],
            'ladder'                => [ TA_Grade::GRADE_LADDER_REPEAT ],
            default                 => [],
        };
        if ( empty( $repeat_grades ) ) return null;

        global $wpdb;
        $grades_table = TA_Database::table( 'grades' );
        $placeholders = implode( ',', array_fill( 0, count( $repeat_grades ), '%s' ) );
        $params = array_merge( [ $student_id, $product_id, $course_type ], $repeat_grades );
        $grade_product_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT product_id FROM {$grades_table}
             WHERE student_id = %d AND product_id <> %d AND course_type = %s
               AND grade IN ({$placeholders})
             ORDER BY id DESC LIMIT 1",
            ...$params
        ) );
        return $grade_product_id ? TA_Assignment::get_assignment( $student_id, (int) $grade_product_id ) : null;
    }

    private static function result(
        bool    $eligible,
        string  $path,
        string  $message,
        ?object $grade    = null,
        int     $order_id = 0
    ): array {
        return compact( 'eligible', 'path', 'message', 'grade', 'order_id' );
    }

    public static function get_product_settings( int $product_id ): ?object {
        $table = TA_Database::table( 'product_settings' );
        return TA_Database::get_row(
            "SELECT * FROM {$table} WHERE product_id = %d",
            [ $product_id ]
        );
    }

    /**
     * [PURCHASE-GATE] بررسی «مشتری بودن واقعی» طبق ملاک دقیقی که کارفرما خواسته:
     * فقط سفارش با وضعیت «تکمیل‌شده» (wc-completed) یا «پرداخت قسطی» (wc-partial-payment).
     * برخلاف student_has_purchased() که wc-processing را هم می‌پذیرد، این متد
     * سخت‌گیرانه‌تر است و برای گیت کردن نمایش شورت‌کد/کانتینر خرید استفاده می‌شود.
     */
    public static function has_valid_purchase( int $student_id, int $product_id ): bool {
        if ( ! $student_id || ! function_exists( 'wc_get_orders' ) ) {
            return false;
        }

        $statuses = [ 'wc-completed', 'wc-partial-payment' ];

        // ۱) جست‌وجوی استاندارد بر اساس حساب کاربری (customer_id / _customer_user)
        $orders = wc_get_orders( [
            'customer_id' => $student_id,
            'status'      => $statuses,
            'limit'       => -1,
        ] );

        // [PURCHASE-GATE-FIX] ۲) جست‌وجوی مکمل بر اساس ایمیل کاربر.
        // بعضی سفارش‌ها (مخصوصاً آن‌هایی که خودِ ادمین از پیشخوان ووکامرس
        // به‌صورت دستی ثبت کرده) درست به customer_id حساب کاربری وصل نمی‌شوند
        // (customer_id آن‌ها صفر یا اشتباه می‌ماند)، در حالی که ایمیل ثبت‌شده روی
        // سفارش با ایمیل حساب کاربری یکی است. بدون این بخش، همچین سفارش‌هایی
        // اصلاً «خریداری‌شده» تشخیص داده نمی‌شدند.
        $user = get_userdata( $student_id );
        if ( $user && $user->user_email ) {
            $email_orders = wc_get_orders( [
                'billing_email' => $user->user_email,
                'status'        => $statuses,
                'limit'         => -1,
            ] );
            if ( $email_orders ) {
                $orders = array_merge( $orders, $email_orders );
            }
        }

        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                if ( (int) $item->get_product_id() === $product_id
                     || (int) $item->get_variation_id() === $product_id ) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function student_has_purchased( int $student_id, int $product_id ): bool {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return false;
        }
        $orders = wc_get_orders( [
            'customer_id' => $student_id,
            'status'      => [ 'wc-completed', 'wc-processing', 'wc-partial-payment' ],
            'limit'       => -1,
        ] );

        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                if ( (int) $item->get_product_id() === $product_id ) {
                    return true;
                }
                if ( (int) $item->get_variation_id() === $product_id ) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function already_assigned( int $student_id, int $product_id ): bool {
        $table = TA_Database::table( 'assignments' );
        $row = TA_Database::get_row(
            "SELECT id FROM {$table} WHERE student_id = %d AND product_id = %d",
            [ $student_id, $product_id ]
        );
        return (bool) $row;
    }

    private static function get_level_assessment( int $student_id, int $product_id ): ?object {
        $table = TA_Database::table( 'level_assessments' );
        return TA_Database::get_row(
            "SELECT * FROM {$table} WHERE student_id = %d AND product_id = %d",
            [ $student_id, $product_id ]
        );
    }

    public static function get_previous_group_link( int $student_id, int $product_id ): ?string {
        $prereq_ids = TA_Product_Meta::get_prerequisites( $product_id );
        if ( empty( $prereq_ids ) ) {
            return null;
        }

        $assign_table = TA_Database::table( 'assignments' );
        foreach ( $prereq_ids as $prereq_id ) {
            $row = TA_Database::get_row(
                "SELECT group_link FROM {$assign_table}
                 WHERE student_id = %d AND product_id = %d AND group_link IS NOT NULL",
                [ $student_id, $prereq_id ]
            );
            if ( $row && $row->group_link ) {
                return $row->group_link;
            }
        }
        return null;
    }
}
