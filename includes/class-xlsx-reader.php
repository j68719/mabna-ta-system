<?php
defined( 'ABSPATH' ) || exit;

/**
 * خواندن فایل‌های XLSX بدون کتابخانه خارجی
 * XLSX = ZIP حاوی XML
 */
class TA_Xlsx_Reader {

    /**
     * خواندن شیت اول فایل XLSX
     * @return array آرایه دوبُعدی از ردیف‌ها (شامل هدر)
     */
    public static function read( string $file_path ): array {
        if ( ! file_exists( $file_path ) ) {
            return [];
        }

        $zip = new ZipArchive();
        if ( $zip->open( $file_path ) !== true ) {
            return [];
        }

        // خواندن shared strings
        $shared_strings = [];
        $ss_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( $ss_xml ) {
            $ss_doc = new SimpleXMLElement( $ss_xml );
            foreach ( $ss_doc->si as $si ) {
                // هر si ممکن است t مستقیم یا r/t داشته باشد
                if ( isset( $si->t ) ) {
                    $shared_strings[] = (string) $si->t;
                } else {
                    $text = '';
                    foreach ( $si->r as $r ) {
                        $text .= (string) $r->t;
                    }
                    $shared_strings[] = $text;
                }
            }
        }

        // پیدا کردن اولین شیت
        $sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
        if ( ! $sheet_xml ) {
            // بررسی workbook برای پیدا کردن شیت اول
            $wb_xml = $zip->getFromName( 'xl/workbook.xml' );
            if ( $wb_xml ) {
                $wb_doc = new SimpleXMLElement( $wb_xml );
                $ns = $wb_doc->getNamespaces( true );
                foreach ( $wb_doc->sheets->sheet as $s ) {
                    $r_attrs = $s->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
                    if ( $r_attrs && isset( $r_attrs['id'] ) ) {
                        $rId = (string) $r_attrs['id'];
                        // پیدا کردن مسیر از rels
                        $rels_xml = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
                        if ( $rels_xml ) {
                            $rels = new SimpleXMLElement( $rels_xml );
                            foreach ( $rels->Relationship as $rel ) {
                                if ( (string) $rel['Id'] === $rId ) {
                                    $sheet_path = 'xl/' . ltrim( (string) $rel['Target'], '/' );
                                    $sheet_xml = $zip->getFromName( $sheet_path );
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
        }

        $zip->close();

        if ( ! $sheet_xml ) {
            return [];
        }

        return self::parse_sheet( $sheet_xml, $shared_strings );
    }

    private static function parse_sheet( string $xml, array $shared_strings ): array {
        $doc = new SimpleXMLElement( $xml );
        $ns  = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        $rows      = [];
        $max_col   = 0;

        // جمع‌آوری همه سلول‌ها
        $cell_data = [];
        foreach ( $doc->sheetData->row as $row ) {
            $row_num = (int) $row['r'];
            foreach ( $row->c as $cell ) {
                $ref  = (string) $cell['r'];
                $type = (string) $cell['t'];
                $val  = isset( $cell->v ) ? (string) $cell->v : '';

                if ( $type === 's' ) {
                    $val = $shared_strings[ (int) $val ] ?? '';
                } elseif ( $type === 'inlineStr' ) {
                    $val = isset( $cell->is->t ) ? (string) $cell->is->t : '';
                }

                [ $col_idx, $r ] = self::parse_ref( $ref );
                if ( $col_idx > $max_col ) $max_col = $col_idx;
                $cell_data[ $r ][ $col_idx ] = trim( $val );
            }
        }

        if ( empty( $cell_data ) ) return [];

        $max_row = max( array_keys( $cell_data ) );

        for ( $r = 1; $r <= $max_row; $r++ ) {
            $row_arr = [];
            for ( $c = 1; $c <= $max_col; $c++ ) {
                $row_arr[] = $cell_data[ $r ][ $c ] ?? '';
            }
            $rows[] = $row_arr;
        }

        return $rows;
    }

    /** تبدیل رفرنس مثل "C5" به [col_index, row_index] */
    private static function parse_ref( string $ref ): array {
        preg_match( '/^([A-Z]+)(\d+)$/', strtoupper( $ref ), $m );
        if ( ! $m ) return [ 1, 1 ];
        $col = 0;
        foreach ( str_split( $m[1] ) as $ch ) {
            $col = $col * 26 + ( ord( $ch ) - ord( 'A' ) + 1 );
        }
        return [ $col, (int) $m[2] ];
    }
}
