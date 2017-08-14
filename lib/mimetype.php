<?php
// mimetype.php -- HotCRP helper file for MIME types
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Mimetype {
    const TXT_TYPE = "text/plain";
    const BIN_TYPE = "application/octet-stream";
    const PDF_TYPE = "application/pdf";
    const PS_TYPE = "application/postscript";
    const PPT_TYPE = "application/vnd.ms-powerpoint";
    const JSON_TYPE = "application/json";
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

    private static $tinfo = [
        self::TXT_TYPE =>     [1, 1, ".txt", "text"],
        self::PDF_TYPE =>     [2, 1, ".pdf", "PDF"],
        self::PS_TYPE =>      [3, 0, ".ps", "PostScript"],
        self::PPT_TYPE =>     [4, 0, ".ppt", "PowerPoint", "application/mspowerpoint", "application/powerpoint", "application/x-mspowerpoint"],
        "application/vnd.openxmlformats-officedocument.presentationml.presentation" =>
                              [5, 0, ".pptx", "PowerPoint"],
        "video/mp4" =>        [6, 0, ".mp4", null],
        "video/x-msvideo" =>  [7, 0, ".avi", null],
        self::JSON_TYPE =>    [8, 0, ".json", "JSON"],
        self::JPG_TYPE =>     [9, 1, ".jpg", "JPEG", ".jpeg"],
        self::PNG_TYPE =>     [10, 1, ".png", "PNG"]
    ];

    private static $mime_types = null;
    private static $finfo = null;

    function __construct($mimetype, $extension, $mimetypeid = 0,
                         $description = null, $inline = false) {
        $this->mimetype = $mimetype;
        $this->extension = $extension;
        $this->mimetypeid = $mimetypeid;
        $this->description = $description;
        $this->inline = !!$inline;
    }

    static function lookup($type, $nocreate = false) {
        global $ConfSitePATH;
        if (!$type)
            return null;
        if (is_object($type))
            return $type;
        if (empty(self::$tmap))
            foreach (self::$tinfo as $xtype => $data) {
                $m = new Mimetype($xtype, $data[2], $data[0], $data[3], $data[1]);
                self::$tmap[$xtype] = self::$tmap[$m->mimetypeid] =
                    self::$tmap[$m->extension] = $m;
                for ($i = 4; $i < count($data); ++$i)
                    self::$tmap[$data[$i]] = $m;
            }
        if (array_key_exists($type, self::$tmap))
            return self::$tmap[$type];
        if (self::$mime_types === null) {
            self::$mime_types = true;
            $t = (string) @file_get_contents("$ConfSitePATH/lib/mime.types");
            preg_match_all('{^(|#!!\s+)([-a-z0-9]+/\S+)[ \t]*(.*)}m', $t, $ms, PREG_SET_ORDER);
            foreach ($ms as $mm) {
                if (isset(self::$tmap[$mm[2]]))
                    continue;
                if ($mm[1] === "") {
                    $exts = [null];
                    if ($mm[3])
                        $exts = array_map(function ($x) { return ".$x"; }, preg_split('/\s+/', $mm[3]));
                    $m = new Mimetype($mm[2], $exts[0]);
                    self::$tmap[$m->mimetype] = $m;
                    foreach ($exts as $ext)
                        if ($ext && !isset(self::$tmap[$ext]))
                            self::$tmap[$ext] = $m;
                } else {
                    $m = get(self::$tmap, trim($mm[3]) ? : self::BIN_TYPE);
                    if ($m)
                        self::$tmap[$mm[2]] = $m;
                }
            }
        }
        return get(self::$tmap, $type);
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
        return array_map(function ($t) { return Mimetype::lookup($t); },
                         array_keys(self::$tinfo));
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
            && ($tx = self::type($type)))
            $type = $tx;
        // unreliable sniffs
        if ($content_exists
            && (!$type || $type === self::BIN_TYPE)) {
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
                && ($tx = self::type($type)))
                $type = $tx;
        }
        // type obtained, or octet-stream if nothing else works
        return self::type($type ? : self::BIN_TYPE);
    }
}
