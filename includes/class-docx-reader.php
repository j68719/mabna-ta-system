<?php
defined( 'ABSPATH' ) || exit;

/**
 * استخراج متن و شمارش کلمات از فایل‌های .docx
 * (بدون نیاز به کتابخانه‌ی خارجی — فایل docx در واقع یک ZIP حاوی XML است)
 */
class TA_Docx_Reader {

    /**
     * شمارش کلمات یک فایل docx بر اساس مسیر فایل روی دیسک.
     * در صورت خطا، false برمی‌گرداند.
     */
    public static function count_words( string $file_path ) {
        $text = self::extract_text( $file_path );
        if ( $text === false ) {
            return false;
        }
        $text = trim( $text );
        if ( $text === '' ) {
            return 0;
        }
        // شمارش بر اساس فاصله/خط‌جدید (برای متن فارسی و انگلیسی هردو کار می‌کند)
        $words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $words ) ? count( $words ) : 0;
    }

    /**
     * استخراج متن خام از document.xml داخل فایل docx
     */
    public static function extract_text( string $file_path ) {
        if ( ! class_exists( 'ZipArchive' ) || ! file_exists( $file_path ) ) {
            return false;
        }
        $zip = new ZipArchive();
        if ( $zip->open( $file_path ) !== true ) {
            return false;
        }
        $xml = $zip->getFromName( 'word/document.xml' );
        $zip->close();
        if ( $xml === false ) {
            return false;
        }

        // پاراگراف‌ها را با فاصله از هم جدا کن تا کلمات دو پاراگراف به هم نچسبند
        $xml = str_replace( '</w:p>', ' </w:p>', $xml );
        $xml = str_replace( '</w:tab>', ' ', $xml );
        $text = wp_strip_all_tags( $xml );
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

        return $text;
    }

    public static function is_docx( string $file_name ): bool {
        return (bool) preg_match( '/\.docx$/i', $file_name );
    }
}
