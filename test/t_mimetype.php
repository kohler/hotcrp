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

    function xxx_test_mp4() {
        $mt = ISOVideoMimetype::make_file("/Users/kohler/Downloads/sigcomm23-paper130-10_minute_presentation_video.mp4");
        $mt->analyze();
        error_log(json_encode($mt->content_info()));

        $mt = ISOVideoMimetype::make_file("/Users/kohler/Downloads/sigcomm23-paper1037-10_minute_presentation_video/MoMA v1.0.3.mp4");
        $mt->analyze();
        error_log(json_encode($mt->content_info()));
    }
}
