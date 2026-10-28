<?php
// t_mimetype.php -- HotCRP tests
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

#[RequireDb(false)]
class Mimetype_Tester {
    function test_mimetype() {
        xassert_eqq(Mimetype::content_type("%PDF-3.0\nwhatever\n"), Mimetype::PDF_TYPE);
        // test that we can parse lib/mime.types for file extensions
        xassert_eqq(Mimetype::extension("application/pdf"), ".pdf");
        xassert_eqq(Mimetype::extension("image/gif"), ".gif");
        xassert_eqq(Mimetype::content_type(null, "application/force"), "application/octet-stream");
        xassert_eqq(Mimetype::content_type(null, "application/x-zip-compressed"), "application/zip");
        xassert_eqq(Mimetype::content_type(null, "application/gz"), "application/gzip");
        xassert_eqq(Mimetype::extension("application/g-zip"), ".gz");
        xassert_eqq(Mimetype::type("application/download"), "application/octet-stream");
        xassert_eqq(Mimetype::extension("application/smil"), ".smil");
        xassert_eqq(Mimetype::type(".smil"), "application/smil");
        xassert_eqq(Mimetype::type(".sml"), "application/smil");
        // `fileinfo` test
        xassert_eqq(Mimetype::content_type("<html><head></head><body></body></html>"), "text/html");
        // test that non-PDFs are not mistaken for PDFs
        xassert_eqq(Mimetype::content_type("%PDF-3.0\nwhatever\n", Mimetype::PDF_TYPE), Mimetype::PDF_TYPE);
        xassert_neqq(Mimetype::content_type("PDF-3.0\nwhatever\n", Mimetype::PDF_TYPE), Mimetype::PDF_TYPE);
    }

    function test_gif() {
        $spacer = base64_decode("R0lGODlhAQABAIAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==");
        xassert_eqq(Mimetype::content_type($spacer), Mimetype::GIF_TYPE);
        $ci = Mimetype::content_info($spacer);
        xassert_eqq($ci["type"], Mimetype::GIF_TYPE);
        xassert_eqq($ci["width"] ?? null, 1);
        xassert_eqq($ci["height"] ?? null, 1);

        $icon = base64_decode("R0lGODlhVQBVAPQAAPcIWgBrMf/WtZSMSmMYKfelUggACPdSlK0AQu+MxvecUlJ7OfcQY/+1hPcYa0IAGOecWu+11oQAMfd7lLWUSiFzOecIUvc5c2uEQv+ErTl7OcYASvcha//OjPelYwAAACH5BAEAABEALAAAAABVAFUAQAU=");
        xassert_eqq(Mimetype::content_type($icon), Mimetype::GIF_TYPE);
        $ci = Mimetype::content_info($icon);
        xassert_eqq($ci["type"], Mimetype::GIF_TYPE);
        xassert_eqq($ci["width"] ?? null, 85);
        xassert_eqq($ci["height"] ?? null, 85);
    }

    function test_builtins_match() {
        Mimetype::load_mime_types(2);
        foreach (Mimetype::$tinfo as $tname => $tdata) {
            $mt = Mimetype::lookup($tname);
            xassert_eqq($mt->mimetype, $tname);
            xassert_eqq($mt->extension, $tdata[0]);
            $mt = Mimetype::lookup($tdata[0]);
            xassert_eqq($mt->mimetype, $tname);
            for ($i = 3; $i < count($tdata); ++$i) {
                $mt = Mimetype::lookup($tdata[$i]);
                xassert_eqq($mt->mimetype, $tname);
            }
        }
    }

    function test_sanitize() {
        xassert_eqq(Mimetype::sanitize("application/octet-stream"), "application/octet-stream");
        xassert_eqq(Mimetype::sanitize(null), null);
        xassert_eqq(Mimetype::sanitize(""), null);
        xassert_eqq(Mimetype::sanitize("fart"), null);
        xassert_eqq(Mimetype::sanitize("application/fdsnakjfdsnakfndskjafnkjdsnfdkjsanfkjdsnafkjdsnfkjanfkjdnsakjfndskjanfdjksanfkjdsna"), null);
        xassert_eqq(Mimetype::sanitize("text/plain; charset=utf-8"), "text/plain");
        xassert_eqq(Mimetype::sanitize("text/plain; charset=utf-8"), "text/plain");
        xassert_eqq(Mimetype::sanitize("application/vnd.openxmlformats-officedocument.presentationml.presentation"), "application/vnd.openxmlformats-officedocument.presentationml.presentation");
        xassert_eqq(Mimetype::sanitize("text/x-c++"), "text/x-c++");
        xassert_eqq(Mimetype::sanitize("APPLICATION/OCTET-stream"), "application/octet-stream");
        xassert_eqq(Mimetype::type("APPLICATION/OCTET-stream-FOO; crap=barf"), "application/octet-stream-foo");
    }

    function test_textual() {
        xassert_eqq(Mimetype::textual("Text/Fart-Stream"), true);
        xassert_eqq(Mimetype::textual("application/json"), true);
    }

    function test_pdf_xref() {
        // classic xref table
        $f = SiteLoader::resolve("etc/sample.pdf");
        $ci = Mimetype::content_info(file_get_contents($f));
        xassert_eqq($ci["type"], Mimetype::PDF_TYPE);
        xassert_eqq($ci["npages"] ?? null, 2);
        xassert_eqq(HotCRP\PDFMimetype::make_file($f)->content_info()["npages"] ?? null, 2);

        // xref stream + object streams, deep page tree
        $f = SiteLoader::resolve("test/sample50pg.pdf");
        $s = file_get_contents($f);
        xassert_eqq(HotCRP\PDFMimetype::make_string($s)->content_info()["npages"] ?? null, 50);
        xassert_eqq(HotCRP\PDFMimetype::make_file($f)->content_info()["npages"] ?? null, 50);

        // linearized file: first-page xref section chains to the main one
        $f = SiteLoader::resolve("test/sample-linearized.pdf");
        $pm = HotCRP\PDFMimetype::make_file($f);
        xassert_eqq($pm->content_info()["npages"] ?? null, 2);
        xassert_eqq(json_encode($pm), '{"size":14573,"version":"1.7","nxref":16,"trailer":{"Size":"17","Info":"5 0 R","Root":"9 0 R","ID":"[(16 bytes) (16 bytes)]","Prev":"14364"},"reads":7,"read_bytes":20449,"tree_nodes":3,"npages":2}');
        xassert_eqq(Mimetype::content_info(file_get_contents($f))["npages"] ?? null, 2);

        // truncated file: no trailer
        $ci = Mimetype::content_info(substr($s, 0, 20000));
        xassert_eqq($ci["type"], Mimetype::PDF_TYPE);
        xassert(!isset($ci["npages"]));
        // appended comments: startxref offset still right
        $ci = Mimetype::content_info($s . str_repeat("%\n", 10));
        xassert_eqq($ci["npages"] ?? null, 50);
        // corrupted startxref offset
        $s2 = preg_replace('/startxref\s+\d+/', "startxref 22000", $s);
        xassert(!isset(Mimetype::content_info($s2)["npages"]));
        // inconsistent /Count in page tree root: reject rather than guess
        $s2 = file_get_contents(SiteLoader::resolve("etc/sample.pdf"));
        xassert_str_contains($s2, "/Count 2");
        $s3 = str_replace("/Count 2", "/Count 3", $s2);
        xassert(!isset(Mimetype::content_info($s3)["npages"]));
        // damaged xref entry
        xassert(preg_match('/^(\d{10}) 00000 n/m', $s2, $m) === 1);
        $s3 = preg_replace('/^' . $m[1] . ' 00000 n/m', sprintf("%010d 00000 n", (int) $m[1] + 1), $s2, 1);
        xassert(!isset(Mimetype::content_info($s3)["npages"]));
        // not a PDF
        xassert(!isset(HotCRP\PDFMimetype::make_string("hello")->content_info()["npages"]));
        xassert(!isset(HotCRP\PDFMimetype::make_string("")->content_info()["npages"]));
    }

    /** A one-page PDF whose xref stream has `$n` rows of width `$w`.
     * @param int $n
     * @param string $w
     * @return string */
    static private function pdf_with_xref_stream_rows($n, $w) {
        $rowlen = array_sum(array_map("intval", explode(" ", $w)));
        $comp = gzcompress(str_repeat("\0", $n * $rowlen), 9);
        $s = "%PDF-1.5\n";
        $off = [];
        foreach (["<< /Type /Catalog /Pages 2 0 R >>",
                  "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
                  "<< /Type /Page /Parent 2 0 R >>"] as $i => $obj) {
            $off[] = strlen($s);
            $s .= ($i + 1) . " 0 obj\n{$obj}\nendobj\n";
        }
        $tbl = strlen($s);
        $s .= sprintf("xref\n0 4\n0000000000 65535 f \n%010d 00000 n \n%010d 00000 n \n%010d 00000 n \ntrailer\n<< /Size 4 /Root 1 0 R >>\n", ...$off);
        $offx = strlen($s);
        $s .= "4 0 obj\n<< /Type /XRef /W [{$w}] /Index [100 {$n}] /Size " . ($n + 100)
            . " /Root 1 0 R /Length " . strlen($comp) . " /Filter /FlateDecode /Prev {$tbl} >>\nstream\n"
            . $comp . "\nendstream\nendobj\nstartxref\n{$offx}\n%%EOF\n";
        return $s;
    }

    function test_pdf_xref_entry_memory() {
        // many-entry xref streams are compact
        $s = self::pdf_with_xref_stream_rows(300000, "1 4 1");
        $m0 = memory_get_usage();
        $pm = HotCRP\PDFMimetype::make_string($s);
        xassert_eqq($pm->content_info()["npages"] ?? null, 1);
        xassert_lt(memory_get_usage() - $m0, 32 << 20);
        unset($pm);

        // a few KB of PDF cannot demand unbounded entries
        $s = self::pdf_with_xref_stream_rows(4000000, "0 1 0");
        xassert_lt(strlen($s), 16384);
        $m0 = memory_get_usage();
        $pm = HotCRP\PDFMimetype::make_string($s);
        xassert(!isset($pm->content_info()["npages"]));
        xassert_lt(memory_get_usage() - $m0, 64 << 20);
    }

    function test_pdf_xref_stream_field_count() {
        // `/W` has exactly three fields; extra fields cannot multiply row cost
        $s = self::pdf_with_xref_stream_rows(1000, "1 0 0");
        xassert_eqq(HotCRP\PDFMimetype::make_string($s)->content_info()["npages"] ?? null, 1);
        $s = self::pdf_with_xref_stream_rows(1000, "1 0 0" . str_repeat(" 0", 100));
        xassert(!isset(HotCRP\PDFMimetype::make_string($s)->content_info()["npages"]));
    }

    function test_pdf_xref_object_number_range() {
        // object numbers are array keys, so their range is bounded
        $max = HotCRP\PDFMimetype::MAX_OBJECT_NUMBER;
        $s = self::pdf_with_xref_stream_rows(1, "0 1 0");
        foreach ([100 => 4, $max => 4, $max + 1 => 0, 1 << 40 => 0] as $num => $nxref) {
            $pm = HotCRP\PDFMimetype::make_string(str_replace("/Index [100 1]", "/Index [{$num} 1]", $s));
            xassert_eqq($pm->content_info()["npages"] ?? 0, $nxref ? 1 : 0);
            xassert_eqq($pm->jsonSerialize()["nxref"] ?? 0, $nxref);
        }
        $s = self::pdf_with_xref_stream_rows(2, "0 1 0");
        $pm = HotCRP\PDFMimetype::make_string(str_replace("/Index [100 2]", "/Index [{$max} 2]", $s));
        xassert_eqq($pm->jsonSerialize()["nxref"] ?? 0, 0);

        // likewise for xref table subsections
        foreach (["{$max} 1" => 2, ($max + 1) . " 1" => 0, "-1 1" => 0] as $sub => $nxref) {
            $s = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
            $s .= "xref\n{$sub}\n0000000009 00000 n \n1 1\n0000000009 00000 n \ntrailer\n<< /Root 1 0 R >>\nstartxref\n" . strlen($s) . "\n%%EOF\n";
            xassert_eqq(HotCRP\PDFMimetype::make_string($s)->jsonSerialize()["nxref"] ?? 0, $nxref);
        }
    }

    /** A PDF whose xref chain is one xref stream of width `$w` per element
     * of `$sizes`, visited in that order, each inflating to that many
     * bytes, with `/Index [0 $count]` and `$parms` as `/DecodeParms`.
     * @param list<int> $sizes
     * @param string $w
     * @param int $count
     * @param string $parms
     * @return string */
    static private function pdf_with_xref_stream_chain($sizes, $w, $count, $parms = "") {
        $s = "%PDF-1.5\n";
        $prev = "";
        $off = 0;
        foreach (array_reverse($sizes, true) as $i => $sz) {
            $comp = gzcompress(str_repeat("\0", $sz), 9);
            $off = strlen($s);
            $s .= ($i + 1) . " 0 obj\n<< /Type /XRef /W [{$w}] /Index [0 {$count}] /Size 10"
                . " /Root 9 0 R /Length " . strlen($comp) . " /Filter /FlateDecode{$parms}{$prev} >>\nstream\n"
                . $comp . "\nendstream\nendobj\n";
            $prev = " /Prev {$off}";
        }
        return $s . "startxref\n{$off}\n%%EOF\n";
    }

    function test_pdf_xref_stream_budgets() {
        // a negative `/Index` count walks no rows
        $s = self::pdf_with_xref_stream_chain([4000000], "0 1 0", -1);
        xassert_lt(strlen($s), 16384);
        $m0 = memory_get_usage();
        $pm = HotCRP\PDFMimetype::make_string($s);
        xassert(!isset($pm->content_info()["npages"]));
        xassert(!isset($pm->jsonSerialize()["nxref"]));
        xassert_lt(memory_get_usage() - $m0, 64 << 20);
        unset($pm);

        // spending the inflate budget exactly does not lift it
        $s = self::pdf_with_xref_stream_chain([16777216, 16777216, 1000], "1 0 0", 1);
        xassert_lt(strlen($s), 131072);
        $pm = HotCRP\PDFMimetype::make_string($s);
        xassert(!isset($pm->content_info()["npages"]));
        xassert_le($pm->jsonSerialize()["inflated_bytes"], 33554432);
        unset($pm);

        // PNG predictors do not keep a PHP string per row
        if (function_exists("memory_reset_peak_usage")) {
            $s = self::pdf_with_xref_stream_chain([2000000], "1 0 0", 1, " /DecodeParms << /Predictor 12 /Columns 1 >>");
            xassert_lt(strlen($s), 16384);
            memory_reset_peak_usage();
            $m0 = memory_get_usage();
            $pm = HotCRP\PDFMimetype::make_string($s);
            xassert(!isset($pm->content_info()["npages"]));
            xassert_lt(memory_get_peak_usage() - $m0, 16 << 20);
        }
    }

    /** A one-page PDF with extra entries in its page dictionary and trailer.
     * @param string $page
     * @param string $trailer
     * @return string */
    static private function pdf_with_extra_entries($page, $trailer = "") {
        $s = "%PDF-1.4\n";
        $off = [];
        foreach (["<< /Type /Catalog /Pages 2 0 R >>",
                  "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
                  "<< /Type /Page /Parent 2 0 R {$page} >>"] as $i => $obj) {
            $off[] = strlen($s);
            $s .= ($i + 1) . " 0 obj\n{$obj}\nendobj\n";
        }
        $tbl = strlen($s);
        return $s . sprintf("xref\n0 4\n0000000000 65535 f \n%010d 00000 n \n%010d 00000 n \n%010d 00000 n \n", ...$off)
            . "trailer\n<< /Size 4 /Root 1 0 R {$trailer} >>\nstartxref\n{$tbl}\n%%EOF\n";
    }

    /** Dictionary entries whose names all share a PHP hash bucket.
     * @param int $first
     * @param int $last
     * @return string */
    static private function pdf_colliding_entries($first, $last) {
        $s = "";
        for ($i = $first; $i !== $last; ++$i) {
            $s .= "/" . ($i << 17) . " 0 ";
        }
        return $s;
    }

    function test_pdf_dictionary_size() {
        // names become PHP array keys, so dictionary size is bounded
        $s = self::pdf_with_extra_entries(self::pdf_colliding_entries(0, 4000));
        xassert_eqq(Mimetype::content_info($s)["npages"] ?? null, 1);
        $s = self::pdf_with_extra_entries(self::pdf_colliding_entries(0, 5000));
        xassert(!isset(Mimetype::content_info($s)["npages"]));

        // so is the trailer merged from several xref sections
        foreach ([3000 => 1, 6000 => null] as $last => $npages) {
            $s = self::pdf_with_extra_entries("", self::pdf_colliding_entries(0, 3000));
            $tbl = strrpos($s, "xref\n0 4");
            $s .= "xref\n0 0\ntrailer\n<< /Size 4 /Root 1 0 R /Prev {$tbl} "
                . self::pdf_colliding_entries($last - 3000, $last)
                . ">>\nstartxref\n" . strlen($s) . "\n%%EOF\n";
            xassert_eqq(Mimetype::content_info($s)["npages"] ?? null, $npages);
        }
    }

    function test_pdf_number_token() {
        // number syntax
        $pm = HotCRP\PDFMimetype::make_string("");
        foreach (["1.5" => 1.5, "1." => 1.0, ".5" => 0.5, "-3.25" => -3.25, "+12345678901234567890" => 12345678901234567890.0] as $w => $v) {
            xassert_eqq((new HotCRP\PDFTokenizer("{$w} ", 0, true, $pm))->next(), $v);
        }
        foreach (["1.0.0", "1a", ".", "1.5a"] as $w) {
            xassert((new HotCRP\PDFTokenizer("{$w} ", 0, true, $pm))->next() instanceof HotCRP\PDFKeyword);
        }
    }

    #[RequirePhp("8.5")]
    function test_pdf_number_token_complexity() {
        // a long almost-number is rejected in linear time
        $s = "%PDF-1.4\n" . str_repeat("1", 100000) . "a\nstartxref\n9\n%%EOF\n";
        $t0 = microtime(true);
        xassert(!isset(HotCRP\PDFMimetype::make_string($s)->content_info()["npages"]));
        xassert_lt(microtime(true) - $t0, 0.5);
    }

    /** A PDF whose `$n` pages all start at offset 0 of one object stream,
     * which holds the single page dictionary `$page`.
     * @param int $n
     * @param string $page
     * @return string */
    static private function pdf_with_shared_object_stream_offset($n, $page) {
        $table = $kids = $rows = "";
        for ($i = 10; $i !== $n + 10; ++$i) {
            $table .= "{$i} 0 ";
            $kids .= "{$i} 0 R ";
            $rows .= pack("CNn", 2, 3, $i - 10);
        }
        $comp = gzcompress($table . $page, 9);
        $s = "%PDF-1.5\n";
        $xref = pack("CNn", 0, 0, 65535);
        foreach (["<< /Type /Catalog /Pages 2 0 R >>",
                  "<< /Type /Pages /Kids [{$kids}] /Count {$n} >>",
                  "<< /Type /ObjStm /N {$n} /First " . strlen($table) . " /Length " . strlen($comp)
                  . " /Filter /FlateDecode >>\nstream\n{$comp}\nendstream"] as $i => $obj) {
            $xref .= pack("CNn", 1, strlen($s), 0);
            $s .= ($i + 1) . " 0 obj\n{$obj}\nendobj\n";
        }
        $offx = strlen($s);
        $comp = gzcompress($xref . pack("CNn", 1, $offx, 0) . str_repeat("\0", 35) . $rows, 9);
        $s .= "4 0 obj\n<< /Type /XRef /W [1 4 2] /Size " . ($n + 10) . " /Root 1 0 R /Length " . strlen($comp)
            . " /Filter /FlateDecode >>\nstream\n{$comp}\nendstream\nendobj\nstartxref\n{$offx}\n%%EOF\n";
        return $s;
    }

    function test_pdf_tokenizing_budget() {
        $s = self::pdf_with_shared_object_stream_offset(32, "<< /Type /Page 0 0 >>");
        xassert_eqq(Mimetype::content_info($s)["npages"] ?? null, 32);

        // a few KB of PDF cannot demand unbounded tokenizing, whether of
        // many short tokens or of a few long ones
        foreach ([str_repeat("0 ", 100000),
                  "(" . str_repeat("a", 8000000) . ") 0"] as $junk) {
            $s = self::pdf_with_shared_object_stream_offset(32, "<< /Type /Page {$junk} >>");
            xassert_lt(strlen($s), 16384);
            xassert(!isset(Mimetype::content_info($s)["npages"]));
        }
    }

    /** A PDF whose `$n` uncompressed page objects each hold a string. If
     * `$overlap`, each page's string contains all the pages that follow it.
     * @param int $n
     * @param bool $overlap
     * @return string */
    static private function pdf_with_string_pages($n, $overlap) {
        $close = ")>>endobj ";
        $s = "%PDF-1.4\n";
        $off = [strlen($s)];
        $s .= "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n";
        $off[] = strlen($s);
        $s .= "2 0 obj<</Type/Pages/Count {$n}/Kids[";
        for ($i = 0; $i !== $n; ++$i) {
            $s .= ($i + 3) . " 0 R ";
        }
        $s .= "]>>endobj\n";
        for ($i = 0; $i !== $n; ++$i) {
            $off[] = strlen($s);
            $s .= ($i + 3) . " 0 obj<</Type/Page/J(" . ($overlap ? "" : $close);
        }
        $s .= ($overlap ? str_repeat($close, $n) : "") . "\n";
        $x = "xref\n0 " . ($n + 3) . "\n0000000000 65535 f \n";
        foreach ($off as $o) {
            $x .= sprintf("%010d 00000 n \n", $o);
        }
        return $s . $x . "trailer<</Size " . ($n + 3) . "/Root 1 0 R>>\nstartxref\n" . strlen($s) . "\n%%EOF\n";
    }

    function test_pdf_overlapping_objects_in_memory() {
        $s = self::pdf_with_string_pages(2000, false);
        xassert_eqq(HotCRP\PDFMimetype::make_string($s)->content_info()["npages"] ?? null, 2000);

        // overlapping objects cannot demand quadratic work from in-memory content
        $s = self::pdf_with_string_pages(2000, true);
        $m0 = memory_get_usage();
        $pm = HotCRP\PDFMimetype::make_string($s);
        xassert(!isset($pm->content_info()["npages"]));
        xassert_lt(memory_get_usage() - $m0, 32 << 20);
    }

    /** A one-page PDF whose page, in an object stream, has array `/X [$x]`.
     * @param string $x
     * @return string */
    static private function pdf_with_objstm_page_array($x) {
        $comp = gzcompress("3 0\n<< /Type /Page /Parent 2 0 R /X [{$x}] >>", 9);
        $s = "%PDF-1.5\n";
        $off = [];
        foreach ([1 => "<< /Type /Catalog /Pages 2 0 R >>",
                  2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
                  4 => "<< /Type /ObjStm /N 1 /First 4 /Length " . strlen($comp)
                       . " /Filter /FlateDecode >>\nstream\n{$comp}\nendstream"] as $i => $obj) {
            $off[] = strlen($s);
            $s .= "{$i} 0 obj\n{$obj}\nendobj\n";
        }
        $offx = strlen($s);
        $rows = pack("CNnCNnCNnCNnCNn", 1, $off[0], 0, 1, $off[1], 0, 2, 4, 0, 1, $off[2], 0, 1, $offx, 0);
        return $s . "5 0 obj\n<< /Type /XRef /W [1 4 2] /Index [1 5] /Size 6 /Root 1 0 R /Length "
            . strlen($rows) . " >>\nstream\n{$rows}\nendstream\nendobj\nstartxref\n{$offx}\n%%EOF\n";
    }

    function test_pdf_value_memory() {
        $s = self::pdf_with_objstm_page_array(str_repeat("()", 1000));
        xassert_eqq(HotCRP\PDFMimetype::make_string($s)->content_info()["npages"] ?? null, 1);

        // a few KB of PDF cannot demand unbounded parsed values (integers
        // here: hundreds of thousands of objects would raise PHP's cycle
        // collection threshold for the rest of the test run)
        $s = self::pdf_with_objstm_page_array(str_repeat("0 ", 4000000));
        xassert_lt(strlen($s), 16384);
        $m0 = memory_get_usage();
        $pm = HotCRP\PDFMimetype::make_string($s);
        xassert(!isset($pm->content_info()["npages"]));
        xassert_lt(memory_get_usage() - $m0, 32 << 20);
    }

    function xxx_test_mp4() {
        foreach (glob("/Users/kohler/Downloads/sigcomm23-10_minute_presentation_video/*.mp4") as $f) {
            $mt = ISOVideoMimetype::make_file($f)->set_verbose(true);
            $mt->analyze();
            error_log($f. ": " . json_encode($mt->content_info()));
        }
    }
}
