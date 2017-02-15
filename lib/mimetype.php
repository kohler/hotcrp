<?php
// mimetype.php -- HotCRP helper file for MIME types
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Mimetype {
    const TXT = 1;
    const PDF = 2;
    const PS = 3;
    const PPT = 4;
    const JSON = 8;
    const JPG = 9;
    const PNG = 10;
    const MAX_BUILTIN = 10;

    const PDF_TYPE = "application/pdf";
    const PS_TYPE = "application/postscript";
    const PPT_TYPE = "application/vnd.ms-powerpoint";
    const JPG_TYPE = "image/jpeg";
    const PNG_TYPE = "image/png";
    const GIF_TYPE = "image/gif";
    const TAR_TYPE = "application/x-tar";
    const ZIP_TYPE = "application/zip";
    const RAR_TYPE = "application/x-rar-compressed";

    public $mimetypeid;
    public $mimetype;
    public $extension;
    public $description;
    public $inline;

    private static $tmap = [];

    const F_INLINE = 1;
    private static $tinfo = [
        "text/plain" => [self::TXT, 1, "text", ".txt"],
        self::PDF_TYPE => [self::PDF, 1, "PDF", ".pdf"],
        self::PS_TYPE => [self::PS, 0, "PostScript", ".ps"],
        self::PPT_TYPE => [self::PPT, 0, "PowerPoint", ".ppt", "application/mspowerpoint", "application/powerpoint", "application/x-mspowerpoint"],
        "application/vnd.openxmlformats-officedocument.presentationml.presentation" => [5, 0, "PowerPoint", ".pptx"],
        "video/mp4" => [6, 0, ".mp4"],
        "video/x-msvideo" => [7, 0, ".avi"],
        "application/json" => [self::JSON, 0, "JSON", ".json"],
        self::JPG_TYPE => [self::JPG, 1, "JPEG", ".jpg", ".jpeg"],
        self::PNG_TYPE => [self::PNG, 1, "PNG", ".png"]
    ];

    private static $mime_types = null;
    private static $finfo = null;

    static function lookup($type, $nocreate = false) {
        if (!$type)
            return null;
        if (is_object($type))
            return $type;
        if (empty(self::$tmap))
            foreach (self::$tinfo as $xtype => $data) {
                $m = new Mimetype;
                $m->mimetypeid = $data[0];
                $m->mimetype = $xtype;
                $m->inline = ($data[1] & self::F_INLINE) != 0;
                self::$tmap[$xtype] = self::$tmap[$data[0]] = $m;
                for ($i = 2; $i < count($data); ++$i)
                    if ($data[$i][0] == ".") {
                        if (!$m->extension)
                            $m->extension = $data[$i];
                        self::$tmap[$data[$i]] = $m;
                    } else if (strpos($data[$i], "/") !== false)
                        self::$tmap[$data[$i]] = $m;
                    else
                        $m->description = $data[$i];
            }
        if (array_key_exists($type, self::$tmap))
            return self::$tmap[$type];
        $extension = false;
        while (1) {
            $result = Dbl::qe("select * from Mimetype where mimetype=?", $type);
            $m = $result ? $result->fetch_object("Mimetype") : null;
            Dbl::free($result);
            if ($m || $nocreate)
                break;
            if ($extension === false)
                $extension = self::mime_types_extension($type);
            Dbl::qe("insert into Mimetype (mimetypeid, mimetype, extension) select max(greatest(1000,1+mimetypeid)), ?, ? from Mimetype", $type, $extension);
        }
        if ($m) {
            self::$tmap[$m->mimetype] = self::$tmap[$m->mimetypeid] = $m;
            if ($m->extension)
                self::$tmap[$m->extension] = $m;
        }
        return $m;
    }

    static private function load_mime_types() {
        global $ConfSitePATH;
        if (self::$mime_types === null) {
            self::$mime_types = [];
            $t = (string) @file_get_contents("$ConfSitePATH/lib/mime.types");
            preg_match_all('{^(|#!!\s+)([-a-z0-9]+/\S+)[ \t]*(\S*)}m', $t, $m, PREG_SET_ORDER);
            foreach ($m as $x)
                if ($x[1] === "" && $x[3])
                    self::$mime_types[$x[2]] = "." . $x[3];
                else if ($x[1])
                    self::$mime_types[$x[2]] = $x[3] ? : "application/octet-stream";
        }
        return self::$mime_types;
    }

    static function mime_types_extension($type) {
        $mt = self::load_mime_types();
        while (($x = get($mt, $type)) && $x[0] !== ".")
            $type = $x;
        return $x;
    }


    static function type($type) {
        if (($x = self::lookup($type, true)))
            return $x->mimetype;
        else
            return $type;
    }

    static function type_equals($typea, $typeb) {
        return self::type($typea) == self::type($typeb);
    }

    static function extension($type) {
        $x = self::lookup($type);
        return $x && $x->extension ? $x->extension : "";
    }

    static function description($type) {
        if (is_array($type)) {
            $a = array();
            foreach ($type as $x)
                if (($x = self::description($x)))
                    $a[$x] = $x;
            return commajoin($a, "or");
        } else {
            $x = self::lookup($type);
            if ($x && $x->description)
                return $x->description;
            else if ($x && $x->extension)
                return $x->extension;
            else if ($x)
                return $x->mimetype;
            else
                return $type;
        }
    }

    static function disposition_inline($type) {
        $x = self::lookup($type, true);
        return $x && $x->inline;
    }

    static function builtins() {
        self::lookup(self::PDF_TYPE);
        $x = [];
        for ($i = 1; $i <= self::MAX_BUILTIN; ++$i)
            $x[] = self::$tmap[$i];
        return $x;
    }


    static function pdf_content($content) {
        return $content && strncmp("%PDF-", $content, 5) == 0;
    }

    static function content_type($content, $type = null) {
        $content_exists = (string) $content !== "";
        // reliable sniffs
        if ($content_exists) {
            if (strncmp("%PDF-", $content, 5) == 0)
                return self::PDF_TYPE;
            if (substr($content, 512, 4) === "\x00\x6E\x1E\xF0")
                return self::PPT_TYPE;
            if (strncmp($content, "\xFF\xD8\xFF\xD8", 4) == 0
                || (strncmp($content, "\xFF\xD8\xFF\xE0", 4) == 0 && substr($content, 6, 6) == "JFIF\x00\x01")
                || (strncmp($content, "\xFF\xD8\xFF\xE1", 4) == 0 && substr($content, 6, 6) == "Exif\x00\x00"))
                return self::JPG_TYPE;
            if (strncmp($content, "\x89PNG\r\n\x1A\x0A", 8) == 0)
                return self::PNG_TYPE;
            if ((strncmp($content, "GIF87a", 6) == 0
                 || strncmp($content, "GIF89a", 6) == 0)
                && str_ends_with($content, "\x00;"))
                return self::GIF_TYPE;
            if (strncmp($content, "Rar!\x1A\x07\x00", 7) == 0
                || strncmp($content, "Rar!\x1A\x07\x01\x00", 8) == 0)
                return self::RAR_TYPE;
        }
        // eliminate invalid types, canonicalize
        if ($type && !isset(self::$tinfo[$type])
            && ($tx = get(self::load_mime_types(), $type))
            && $tx[0] !== ".")
            $type = $tx;
        // unreliable sniffs
        if ($content_exists
            && (!$type || $type === "application/octet-stream")) {
            if (strncmp("%!PS-", $content, 5) == 0)
                return self::PS_TYPE;
            if (strncmp($content, "ustar\x0000", 8) == 0
                || strncmp($content, "ustar  \x00", 8) == 0)
                return self::TAR_TYPE;
            if (!self::$finfo)
                self::$finfo = new finfo(FILEINFO_MIME_TYPE);
            $type = self::$finfo->buffer($content);
            // canonicalize
            if ($type && !isset(self::$tinfo[$type])
                && ($tx = get(self::load_mime_types(), $type))
                && $tx[0] !== ".")
                $type = $tx;
        }
        // type obtained, or octet-stream if nothing else works
        return self::type($type ? : "application/octet-stream");
    }
}
