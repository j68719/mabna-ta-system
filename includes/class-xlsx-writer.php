<?php
defined( 'ABSPATH' ) || exit;

/**
 * نوشتن فایل XLSX بدون هیچ کتابخانه خارجی
 * XLSX = ZIP حاوی چند فایل XML (استاندارد OOXML)
 *
 * استفاده:
 *   $writer = new TA_Xlsx_Writer();
 *   $writer->set_rtl( true );
 *   $writer->add_row( ['هدر۱','هدر۲'], true );   // true = ردیف هدر
 *   $writer->add_row( ['مقدار۱','مقدار۲'] );
 *   $writer->output( 'filename.xlsx' );            // ارسال مستقیم به مرورگر
 *   // یا:
 *   $bytes = $writer->get_content();
 */
class TA_Xlsx_Writer {

    /** @var array[] آرایه‌ای از [ 'cells'=>[], 'is_header'=>bool ] */
    private array $rows = [];

    private bool $rtl = false;

    /** رنگ‌های پیش‌فرض */
    private string $header_bg  = '217346'; // سبز اکسل
    private string $header_fg  = 'FFFFFF';
    private string $stripe_bg  = 'F2F9F2'; // سبز بسیار کم‌رنگ
    private string $border_clr = 'CCCCCC';

    public function set_rtl( bool $rtl ): self {
        $this->rtl = $rtl;
        return $this;
    }

    /**
     * افزودن یک ردیف
     * @param array  $cells     مقادیر سلول‌ها
     * @param bool   $is_header آیا این ردیف هدر است؟
     * @param bool   $force_text اگر true، تمام سلول‌ها را به‌عنوان text ذخیره کن
     */
    public function add_row( array $cells, bool $is_header = false, bool $force_text = false ): self {
        $this->rows[] = [
            'cells'      => $cells,
            'is_header'  => $is_header,
            'force_text' => $force_text,
        ];
        return $this;
    }

    /**
     * ارسال مستقیم به مرورگر با header های دانلود
     */
    public function output( string $filename ): void {
        $content = $this->build_zip();
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"' );
        header( 'Content-Length: ' . strlen( $content ) );
        header( 'Cache-Control: max-age=0' );
        header( 'Pragma: public' );
        echo $content;
    }

    /**
     * برگرداندن محتوا به‌عنوان string
     */
    public function get_content(): string {
        return $this->build_zip();
    }

    // =========================================================
    // ساخت فایل ZIP
    // =========================================================

    private function build_zip(): string {
        // از یک فایل موقت برای ZipArchive استفاده می‌کنیم
        $tmp = tempnam( sys_get_temp_dir(), 'xlsx_' );

        $zip = new ZipArchive();
        $zip->open( $tmp, ZipArchive::OVERWRITE );

        // shared strings — همه متن‌ها را یک‌جا جمع می‌کنیم
        [ $ss_index, $all_strings ] = $this->collect_strings();

        $zip->addFromString( '[Content_Types].xml',      $this->xml_content_types() );
        $zip->addFromString( '_rels/.rels',               $this->xml_root_rels() );
        $zip->addFromString( 'xl/workbook.xml',           $this->xml_workbook() );
        $zip->addFromString( 'xl/_rels/workbook.xml.rels',$this->xml_workbook_rels() );
        $zip->addFromString( 'xl/styles.xml',             $this->xml_styles() );
        $zip->addFromString( 'xl/sharedStrings.xml',      $this->xml_shared_strings( $all_strings ) );
        $zip->addFromString( 'xl/worksheets/sheet1.xml',  $this->xml_sheet( $ss_index ) );

        $zip->close();

        $bytes = file_get_contents( $tmp );
        unlink( $tmp );
        return $bytes;
    }

    // =========================================================
    // جمع‌آوری shared strings
    // =========================================================

    private function collect_strings(): array {
        $index = [];   // string => idx
        $all   = [];   // idx => string

        foreach ( $this->rows as $row ) {
            foreach ( $row['cells'] as $cell ) {
                $v = (string) $cell;
                if ( $v === '' ) continue;
                // فقط مقادیر غیر‌عددی یا مقادیر text-forced را shared string کن
                if ( ! is_numeric( $cell ) || $row['force_text'] || $row['is_header'] ) {
                    if ( ! isset( $index[ $v ] ) ) {
                        $index[ $v ] = count( $all );
                        $all[]       = $v;
                    }
                }
            }
        }

        return [ $index, $all ];
    }

    // =========================================================
    // XML ها
    // =========================================================

    private function xml_content_types(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/xl/sharedStrings.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>';
    }

    private function xml_root_rels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
    Target="xl/workbook.xml"/>
</Relationships>';
    }

    private function xml_workbook(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
  xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="&#x062A;&#x062E;&#x0635;&#x06CC;&#x0635;&#x200C;&#x0647;&#x0627;" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';
    }

    private function xml_workbook_rels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"
    Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"
    Target="styles.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings"
    Target="sharedStrings.xml"/>
</Relationships>';
    }

    /**
     * styles.xml
     * xf index:
     *   0 = default
     *   1 = header  (bold, bg=217346, fg=FFFFFF, border, center)
     *   2 = normal  (border)
     *   3 = stripe  (border, bg=F2F9F2)
     *   4 = text    (border, quotePrefix برای شماره تلفن)
     */
    private function xml_styles(): string {
        $hbg = $this->header_bg;
        $hfg = $this->header_fg;
        $sbg = $this->stripe_bg;
        $bc  = $this->border_clr;

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="3">
    <font><sz val="10"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF{$hfg}"/></font>
    <font><sz val="10"/><name val="Calibri"/></font>
  </fonts>
  <fills count="4">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF{$hbg}"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF{$sbg}"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FF{$bc}"/></left>
      <right style="thin"><color rgb="FF{$bc}"/></right>
      <top style="thin"><color rgb="FF{$bc}"/></top>
      <bottom style="thin"><color rgb="FF{$bc}"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="5">
    <!-- 0: default -->
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <!-- 1: header -->
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center" readingOrder="2"/>
    </xf>
    <!-- 2: normal with border -->
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">
      <alignment readingOrder="2"/>
    </xf>
    <!-- 3: stripe row -->
    <xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment readingOrder="2"/>
    </xf>
    <!-- 4: text (quotePrefix) - برای شماره تلفن -->
    <xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1" quotePrefix="1">
      <alignment readingOrder="2"/>
    </xf>
  </cellXfs>
</styleSheet>
XML;
    }

    private function xml_shared_strings( array $strings ): string {
        $count = count( $strings );
        $xml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
               . ' count="' . $count . '" uniqueCount="' . $count . '">';

        foreach ( $strings as $s ) {
            $xml .= '<si><t xml:space="preserve">' . $this->xe( $s ) . '</t></si>';
        }
        $xml .= '</sst>';
        return $xml;
    }

    private function xml_sheet( array $ss_index ): string {
        $col_count = 0;
        foreach ( $this->rows as $row ) {
            $col_count = max( $col_count, count( $row['cells'] ) );
        }

        $last_col  = $this->col_name( $col_count );
        $total_rows = count( $this->rows );

        // شروع worksheet
        $view_attrs = $this->rtl ? ' rightToLeft="1"' : '';
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . '<sheetViews><sheetView workbookViewId="0"' . $view_attrs . '>'
             . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
             . '</sheetView></sheetViews>'
             // عرض خودکار ستون‌ها (bestFit)
             . '<cols>';

        for ( $c = 1; $c <= $col_count; $c++ ) {
            $xml .= '<col min="' . $c . '" max="' . $c . '" width="18" bestFit="1" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>';

        foreach ( $this->rows as $ri => $row ) {
            $row_num    = $ri + 1;
            $is_header  = $row['is_header'];
            $force_text = $row['force_text'];
            $is_stripe  = ( ! $is_header && $ri % 2 === 1 ); // ردیف‌های زوج (0-based)

            $row_h = $is_header ? ' ht="20" customHeight="1"' : '';
            $xml  .= '<row r="' . $row_num . '"' . $row_h . '>';

            foreach ( $row['cells'] as $ci => $val ) {
                $col_letter = $this->col_name( $ci + 1 );
                $cell_ref   = $col_letter . $row_num;
                $val_str    = (string) $val;

                if ( $is_header ) {
                    // هدر — همیشه shared string، style=1
                    $si  = $ss_index[ $val_str ] ?? 0;
                    $xml .= '<c r="' . $cell_ref . '" t="s" s="1"><v>' . $si . '</v></c>';

                } elseif ( $force_text || ( isset( $row['text_cols'][ $ci ] ) ) ) {
                    // text اجباری (مثل شماره تلفن) — style=4
                    if ( isset( $ss_index[ $val_str ] ) ) {
                        $xml .= '<c r="' . $cell_ref . '" t="s" s="4"><v>' . $ss_index[ $val_str ] . '</v></c>';
                    } else {
                        $xml .= '<c r="' . $cell_ref . '" t="inlineStr" s="4"><is><t xml:space="preserve">' . $this->xe( $val_str ) . '</t></is></c>';
                    }

                } elseif ( $val_str === '' ) {
                    $s   = $is_stripe ? '3' : '2';
                    $xml .= '<c r="' . $cell_ref . '" s="' . $s . '"/>';

                } elseif ( is_numeric( $val ) && strpos( $val_str, '0' ) !== 0 ) {
                    // عدد
                    $s   = $is_stripe ? '3' : '2';
                    $xml .= '<c r="' . $cell_ref . '" t="n" s="' . $s . '"><v>' . $this->xe( $val_str ) . '</v></c>';

                } else {
                    // متن معمولی
                    $s  = $is_stripe ? '3' : '2';
                    if ( isset( $ss_index[ $val_str ] ) ) {
                        $xml .= '<c r="' . $cell_ref . '" t="s" s="' . $s . '"><v>' . $ss_index[ $val_str ] . '</v></c>';
                    } else {
                        $xml .= '<c r="' . $cell_ref . '" t="inlineStr" s="' . $s . '"><is><t xml:space="preserve">' . $this->xe( $val_str ) . '</t></is></c>';
                    }
                }
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData>';

        // autoFilter روی هدر
        if ( $col_count > 0 && $total_rows > 1 ) {
            $xml .= '<autoFilter ref="A1:' . $last_col . '1"/>';
        }

        $xml .= '</worksheet>';
        return $xml;
    }

    // =========================================================
    // helpers
    // =========================================================

    /** تبدیل شماره ستون به نام (1→A, 27→AA, ...) */
    private function col_name( int $n ): string {
        $name = '';
        while ( $n > 0 ) {
            $n--;
            $name = chr( ord('A') + ( $n % 26 ) ) . $name;
            $n    = (int) ( $n / 26 );
        }
        return $name;
    }

    /** XML escape */
    private function xe( string $s ): string {
        return htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
    }
}
