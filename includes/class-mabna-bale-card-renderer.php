<?php
defined( 'ABSPATH' ) || exit;

/** ساخت کارت نتیجهٔ شخصی‌سازی‌شدهٔ آزمون بله. */
class Mabna_Bale_Card_Renderer {
    public static function render( string $image_url, string $full_name, string $key = '', string $event_name = '' ): string {
        if ( $image_url === '' || $full_name === '' || ! class_exists( 'Imagick' ) ) return '';
        $font = TA_SYSTEM_PATH . 'assets/fonts/Kahroba-VF-FD.ttf';
        if ( ! file_exists( $font ) ) {
            $font = TA_SYSTEM_PATH . 'assets/fonts/Kahroba-VF-FD.otf';
        }
        if ( ! file_exists( $font ) ) return '';

        $response = wp_remote_get( esc_url_raw( $image_url ), [ 'timeout' => 20, 'redirection' => 3 ] );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) return '';
        $bytes = wp_remote_retrieve_body( $response );
        if ( $bytes === '' ) return '';

        try {
            $image = new Imagick();
            $image->readImageBlob( $bytes );
            $image->setImageFormat( 'jpeg' );
            $image->setImageCompressionQuality( 92 );
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            if ( $width < 10 || $height < 10 ) return '';

            $draw = new ImagickDraw();
            $draw->setFont( $font );
            $draw->setFontSize( 46 );
            $draw->setFillColor( '#E86B45' );
            $draw->setTextAlignment( Imagick::ALIGN_CENTER );
            $draw->setFontWeight( 900 );
            $draw->setStrokeColor( '#E86B45' );
            $draw->setStrokeWidth( 1.2 );
            if ( method_exists( $draw, 'setTextDirection' ) && defined( 'Imagick::DIRECTION_RIGHT_TO_LEFT' ) ) {
                $draw->setTextDirection( Imagick::DIRECTION_RIGHT_TO_LEFT );
            }

            // کارت‌های مرجع ۱۰۸۰×۱۴۴۰ هستند؛ نسبت‌ها برای اندازه‌های هم‌سبک نیز حفظ می‌شود.
            $baseline = (int) round( $height * 0.748 ) - 20;
            $image->annotateImage( $draw, (int) round( $width / 2 ), $baseline, 0, trim( $full_name ) );
            $draw->clear();
            $draw->destroy();

            $uploads = wp_upload_dir();
            if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) return '';
            // همهٔ کارت‌های تولیدشده در یک پوشهٔ ثابت و قابل مدیریت نگهداری می‌شوند.
            $folder = 'mabna-bot';
            $directory = trailingslashit( $uploads['basedir'] ) . $folder;
            if ( ! wp_mkdir_p( $directory ) || ! is_dir( $directory ) || ! is_writable( $directory ) ) return '';
            $filename = 'bale-result-card-' . sanitize_key( $key ?: 'author' ) . '-' . wp_generate_password( 8, false, false ) . '.jpg';
            $filename = wp_unique_filename( $directory, $filename );
            $path = trailingslashit( $directory ) . $filename;
            $written = file_put_contents( $path, $image->getImagesBlob(), LOCK_EX );
            $image->clear();
            $image->destroy();
            return false !== $written ? trailingslashit( $uploads['baseurl'] ) . rawurlencode( $folder ) . '/' . rawurlencode( $filename ) : '';
        } catch ( Throwable $e ) {
            mabna_bale_log( 'card_render_error', [ 'error' => $e->getMessage(), 'key' => $key ] );
            return '';
        }
    }
}

if ( ! class_exists( 'ImagickDraw' ) ) {
    // فقط برای جلوگیری از ارجاع مستقیم به کلاس در محیط‌های بدون Imagick.
}
