<?php
// t_mimetype.php -- HotCRP tests
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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

    function xxx_test_mp4() {
        foreach (glob("/Users/kohler/Downloads/sigcomm23-10_minute_presentation_video/*.mp4") as $f) {
            $mt = ISOVideoMimetype::make_file($f)->set_verbose(true);
            $mt->analyze();
            error_log($f. ": " . json_encode($mt->content_info()));
        }
    }
}
