<?php
// xlsx.php -- HotCRP XLSX generator functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class XlsxGenerator {

    const PROCESSING = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n";
    const MIMETYPE = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";

    /** @var DocumentInfoSet */
    private $zip;
    private $sst = [];
    private $nsst = 0;
    private $nsheets = 0;
    private $any_headers = false;
    private $done = false;
    private $widths;

    function __construct($downloadname) {
        $this->zip = new DocumentInfoSet($downloadname);
        $this->zip->set_mimetype(self::MIMETYPE);
    }

    static function colname($col) {
        if ($col < 26) {
            return chr($col + 65);
        } else {
            $x = (int) ($col / 26);
            return chr($x + 65) . chr(($col % 26) + 65);
        }
    }

    /** @param int $row
     * @param list<null|int|float|string> $data
     * @param int $style
     * @return string */
    private function row_data($row, $data, $style) {
        $t = "";
        $style = ($style ? " s=\"$style\"" : "");
        $col = 0;
        foreach ($data as $x) {
            if ($x !== null && $x !== "") {
                $t .= "<c r=\"" . self::colname($col) . $row . "\"" . $style;
                if (is_int($x) || is_float($x)) {
                    $t .= "><v>$x</v></c>";
                } else {
                    if (!isset($this->sst[$x])) {
                        $this->sst[$x] = $this->nsst;
                        ++$this->nsst;
                    }
                    $t .= " t=\"s\"><v>" . $this->sst[$x] . "</v></c>";
                }
                $this->widths[$col] = max(strlen((string) $x), $this->widths[$col] ?? 0);
            }
            ++$col;
        }
        if ($t !== "") {
            return "<row r=\"$row\">" . $t . "</row>";
        } else {
            return "";
        }
    }

    /** @param list<null|int|float|string> $header
     * @param list<list<null|int|float|string>> $rows */
    function add_sheet($header, $rows) {
        assert(!$this->done);
        $extra = "";
        $rout = [];
        $this->widths = [];
        if ($header) {
            $rout[] = $this->row_data(count($rout) + 1, $header, 1);
            $this->any_headers = true;
            $extra = "<sheetViews><sheetView workbookViewId=\"0\"><pane topLeftCell=\"A2\" ySplit=\"1.0\" state=\"frozen\" activePane=\"bottomLeft\"/></sheetView></sheetViews>\n";
        }
        foreach ($rows as $row) {
            $rout[] = $this->row_data(count($rout) + 1, $row, 0);
        }
        for ($c = $numcol = 0; $numcol !== count($this->widths); ++$c) {
            if (isset($this->widths[$c])) {
                $w = min($this->widths[$c] + 3, 120);
                $this->widths[$c] = "<col min=\"" . ($c + 1) . "\" max=\"" . ($c + 1) . "\" bestFit=\"1\" width=\"$w\"/>";
                ++$numcol;
            }
        }
        $t = self::PROCESSING . "<worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\" xmlns:mx=\"http://schemas.microsoft.com/office/mac/excel/2008/main\" xmlns:mc=\"http://schemas.openxmlformats.org/markup-compatibility/2006\" xmlns:mv=\"urn:schemas-microsoft-com:mac:vml\" xmlns:x14=\"http://schemas.microsoft.com/office/spreadsheetml/2009/9/main\" xmlns:x14ac=\"http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac\" xmlns:xm=\"http://schemas.microsoft.com/office/excel/2006/main\">\n"
            . $extra
            . "<sheetFormatPr customHeight=\"1\" defaultRowHeight=\"15.75\"/>"
            . "<cols>" . join("", $this->widths) . "</cols>\n"
            . "<sheetData>" . join("", $rout) . "</sheetData>\n"
            . "</worksheet>\n";
        ++$this->nsheets;
        $this->zip->add_string_as($t, "xl/worksheets/sheet" . $this->nsheets . ".xml");
    }

    private function add_sst() {
        $t = [self::PROCESSING, "<sst xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" count=\"$this->nsst\" uniqueCount=\"$this->nsst\">\n"];
        foreach ($this->sst as $k => $v)
            $t[] = "<si><t>" . htmlspecialchars($k) . "</t></si>";
        $t[] = "</sst>\n";
        $this->zip->add_string_as(join("", $t), "xl/sharedStrings.xml");
    }

    private function add_workbook() {
        $t = [self::PROCESSING, "<workbook xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\" xmlns:mx=\"http://schemas.microsoft.com/office/mac/excel/2008/main\" xmlns:mc=\"http://schemas.openxmlformats.org/markup-compatibility/2006\" xmlns:mv=\"urn:schemas-microsoft-com:mac:vml\" xmlns:x14=\"http://schemas.microsoft.com/office/spreadsheetml/2009/9/main\" xmlns:x14ac=\"http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac\" xmlns:xm=\"http://schemas.microsoft.com/office/excel/2006/main\">\n", "<sheets>\n"];
        for ($i = 1; $i <= $this->nsheets; ++$i)
            $t[] = "<sheet sheetId=\"$i\" name=\"Sheet$i\""
                . ($i == 1 ? " state=\"visible\"" : "")
                . " r:id=\"rId" . ($i + 2) . "\"/>";
        $t[] = "</sheets>\n</workbook>\n";
        $this->zip->add_string_as(join("", $t), "xl/workbook.xml");
    }

    private function add_styles() {
        $t = [self::PROCESSING, "<styleSheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" xmlns:x14ac=\"http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac\" xmlns:mc=\"http://schemas.openxmlformats.org/markup-compatibility/2006\">\n"];
        if ($this->any_headers) {
            $t[] = "<fonts count=\"2\"><font><sz val=\"10.0\"/><name val=\"Arial\"/></font><font><sz val=\"10.0\"/><name val=\"Arial\"/><b/></font></fonts>\n";
            $t[] = "<fills count=\"1\"><fill><patternFill patternType=\"none\"/></fill></fills>\n";
            $t[] = "<borders count=\"1\"><border><left/><right/><top/><bottom/><diagonal/></border></borders>\n";
            $t[] = "<cellXfs count=\"2\">\n";
            $t[] = "<xf fontId=\"0\" />\n";
            $t[] = "<xf applyFont=\"1\" fontId=\"1\" />\n";
            $t[] = "</cellXfs>\n";
        }
        $t[] = "</styleSheet>\n";
        $this->zip->add_string_as(join("", $t), "xl/styles.xml");
    }

    private function add_xl_relationships() {
        $t = [self::PROCESSING, "<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">\n",
                   "<Relationship Target=\"sharedStrings.xml\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings\" Id=\"rId1\"/>\n",
                   "<Relationship Target=\"styles.xml\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Id=\"rId2\"/>\n"];
        for ($i = 1; $i <= $this->nsheets; ++$i)
            $t[] = "<Relationship Target=\"worksheets/sheet$i.xml\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Id=\"rId" . ($i + 2) . "\"/>\n";
        $t[] = "</Relationships>\n";
        $this->zip->add_string_as(join("", $t), "xl/_rels/workbook.xml.rels");
    }

    private function add_content_types() {
        $t = [self::PROCESSING, "<Types xmlns=\"http://schemas.openxmlformats.org/package/2006/content-types\">\n",
                   "<Default Extension=\"rels\" ContentType=\"application/vnd.openxmlformats-package.relationships+xml\"/>\n",
                   "<Default Extension=\"xml\" ContentType=\"application/xml\"/>\n",
                   "<Override PartName=\"/xl/sharedStrings.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml\"/>\n",
                   "<Override PartName=\"/xl/styles.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml\"/>\n",
                   "<Override PartName=\"/xl/workbook.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml\"/>\n"];
        for ($i = 1; $i <= $this->nsheets; ++$i)
            $t[] = "<Override PartName=\"/xl/worksheets/sheet$i.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>\n";
        $t[] = "</Types>\n";
        $this->zip->add_string_as(join("", $t), "[Content_Types].xml");

        $t = [self::PROCESSING, "<Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">\n",
                   "<Relationship Target=\"xl/workbook.xml\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument\" Id=\"rId1\"/>\n",
                   "</Relationships>\n"];
        $this->zip->add_string_as(join("", $t), "_rels/.rels");
    }

    function finish() {
        $this->add_content_types();
        $this->add_xl_relationships();
        $this->add_styles();
        $this->add_sst();
        $this->add_workbook();
        $this->done = true;
    }

    /** @param ?Downloader $dopt */
    function download($dopt = null) {
        if (!$this->done) {
            $this->finish();
        }
        return $this->zip->download($dopt);
    }
}
