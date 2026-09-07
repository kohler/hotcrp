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

    function xxx_test_mp4() {
        foreach (glob("/Users/kohler/Downloads/sigcomm23-10_minute_presentation_video/*.mp4") as $f) {
            $mt = ISOVideoMimetype::make_file($f)->set_verbose(true);
            $mt->analyze();
            error_log($f. ": " . json_encode($mt->content_info()));
        }
    }
}
