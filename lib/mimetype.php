<?php
// mimetype.php -- HotCRP helper file for MIME types
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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

    const FLAG_INLINE = 1;
    const FLAG_UTF8 = 2;
    const FLAG_COMPRESSIBLE = 4;
    const FLAG_INCOMPRESSIBLE = 8;
    const FLAG_TEXTUAL = 16;

    /** @var string */
    public $mimetype;
    /** @var string */
    public $extension;
    /** @var ?string */
    public $description;
    /** @var int */
    public $flags;

    private static $tmap = [];

    /** @var array<string,array{0:string,1:?string,2:int,3?:string,4?:string,5?:string}> */
    private static $tinfo = [
        self::TXT_TYPE =>     [".txt", "text", self::FLAG_INLINE | self::FLAG_COMPRESSIBLE | self::FLAG_TEXTUAL],
        self::PDF_TYPE =>     [".pdf", "PDF", self::FLAG_INLINE],
        self::PS_TYPE =>      [".ps", "PostScript", self::FLAG_COMPRESSIBLE],
        self::PPT_TYPE =>     [".ppt", "PowerPoint", self::FLAG_INCOMPRESSIBLE, "application/mspowerpoint", "application/powerpoint", "application/x-mspowerpoint"],
        "application/vnd.openxmlformats-officedocument.presentationml.presentation" =>
                              [".pptx", "PowerPoint", self::FLAG_INCOMPRESSIBLE],
        "video/mp4" =>        [".mp4", null, self::FLAG_INCOMPRESSIBLE],
        "video/x-msvideo" =>  [".avi", null, self::FLAG_INCOMPRESSIBLE],
        self::JSON_TYPE =>    [".json", "JSON", self::FLAG_UTF8 | self::FLAG_COMPRESSIBLE | self::FLAG_TEXTUAL],
        self::JPG_TYPE =>     [".jpg", "JPEG", self::FLAG_INLINE, ".jpeg"],
        self::PNG_TYPE =>     [".png", "PNG", self::FLAG_INLINE]
    ];

    private static $mime_types = null;
    private static $finfo = null;

    /** @param string $mimetype
     * @param string $extension
     * @param ?string $description
     * @param int $flags */
    function __construct($mimetype, $extension,
                         $description = null, $flags = 0) {
        $this->mimetype = $mimetype;
        $this->extension = $extension;
        $this->description = $description;
        $this->flags = $flags;
    }

    /** @param string|Mimetype $type
     * @return ?Mimetype */
    static function lookup($type, $nocreate = false) {
        if (!$type) {
            return null;
        }
        if (is_object($type)) {
            return $type;
        }
        if (empty(self::$tmap)) {
            foreach (self::$tinfo as $xtype => $data) {
                $m = new Mimetype($xtype, $data[0], $data[1], $data[2]);
                self::$tmap[$xtype] = self::$tmap[$m->extension] = $m;
                for ($i = 3; $i < count($data); ++$i) {
                    self::$tmap[$data[$i]] = $m;
                }
            }
        }
        if (array_key_exists($type, self::$tmap)) {
            return self::$tmap[$type];
        }
        if (self::$mime_types === null) {
            self::$mime_types = true;
            $t = (string) @file_get_contents(SiteLoader::find("lib/mime.types"));
            preg_match_all('/^(|#!!\s+)([-a-z0-9]+\/\S+)[ \t]*(.*)/m', $t, $ms, PREG_SET_ORDER);
            foreach ($ms as $mm) {
                if (isset(self::$tmap[$mm[2]])) {
                    continue;
                }
                if ($mm[1] === "") {
                    $exts = [""];
                    if ($mm[3]) {
                        $exts = array_map(function ($x) { return ".$x"; },
                                          preg_split('/\s+/', $mm[3]));
                    }
                    $m = new Mimetype($mm[2], $exts[0]);
                    self::$tmap[$m->mimetype] = $m;
                    foreach ($exts as $ext) {
                        if ($ext && !isset(self::$tmap[$ext]))
                            self::$tmap[$ext] = $m;
                    }
                } else {
                    $m = self::$tmap[trim($mm[3]) ? : self::BIN_TYPE] ?? null;
                    if ($m) {
                        self::$tmap[$mm[2]] = $m;
                    }
                }
            }
        }
        return self::$tmap[$type] ?? null;
    }

    /** @param string $type
     * @return Mimetype */
    static function checked_lookup($type) {
        if (($m = self::lookup($type))) {
            return $m;
        } else {
            throw new Exception("Unknown mimetype “{$type}”");
        }
    }

    /** @param string|Mimetype $type */
    static function type($type) {
        if (($x = self::lookup($type, true))) {
            return $x->mimetype;
        } else {
            return $type;
        }
    }

    /** @param string|Mimetype $type
     * @return string */
    static function type_with_charset($type) {
        if (($x = self::lookup($type, true))) {
            if ($x->flags & self::FLAG_UTF8) {
                return $x->mimetype . "; charset=utf-8";
            } else {
                return $x->mimetype;
            }
        } else {
            return "";
        }
    }

    /** @param string|Mimetype $typea
     * @param string|Mimetype $typeb
     * @return bool */
    static function type_equals($typea, $typeb) {
        $ta = self::type($typea);
        $tb = self::type($typeb);
        return ($typea && $typea === $typeb)
            || ($ta !== null && $ta === $tb);
    }

    /** @param string|Mimetype $type
     * @return string */
    static function extension($type) {
        $x = self::lookup($type);
        return $x ? $x->extension : "";
    }

    /** @param string|Mimetype $type
     * @return string */
    static function description($type) {
        if (($x = self::lookup($type))) {
            if ($x->description) {
                return $x->description;
            } else if ($x->extension !== "") {
                return $x->extension;
            } else {
                return $x->mimetype;
            }
        } else {
            return $type;
        }
    }

    /** @param list<string|Mimetype> $types
     * @return string */
    static function list_description($types) {
        if (count($types) === 0) {
            return "any file";
        } else if (count($types) === 1) {
            return Mimetype::description($types[0]);
        } else {
            $m = array_unique(array_map("Mimetype::description", $types));
            return commajoin($m, "or");
        }
    }

    /** @param string|Mimetype $type
     * @return bool */
    static function disposition_inline($type) {
        $x = self::lookup($type, true);
        return $x && ($x->flags & self::FLAG_INLINE) !== 0;
    }

    /** @param string|Mimetype $type
     * @return bool */
    static function textual($type) {
        $x = self::lookup($type, true);
        if ($x && $x->flags !== 0) {
            return ($x->flags & self::FLAG_TEXTUAL) !== 0;
        } else {
            return str_starts_with($x ? $x->mimetype : $type, "text/");
        }
    }

    /** @param string|Mimetype $type
     * @return bool */
    static function compressible($type) {
        $x = self::lookup($type, true);
        if ($x && $x->flags !== 0) {
            return ($x->flags & self::FLAG_COMPRESSIBLE) !== 0;
        } else {
            return str_starts_with($x ? $x->mimetype : $type, "text/");
        }
    }


    /** @return list<Mimetype> */
    static function builtins() {
        return array_map(function ($t) { return Mimetype::lookup($t); },
                         array_keys(self::$tinfo));
    }


    /** @return bool */
    static function pdf_content($content) {
        return $content && strncmp("%PDF-", $content, 5) == 0;
    }

    /** @return string */
    static function content_type($content, $type = null) {
        // reliable sniffs
        if ($content !== null && $content !== "") {
            if (strncmp("%PDF-", $content, 5) == 0) {
                return self::PDF_TYPE;
            } else if (substr($content, 512, 4) === "\x00\x6E\x1E\xF0") {
                return self::PPT_TYPE;
            } else if (strncmp($content, "\xFF\xD8\xFF\xD8", 4) == 0
                       || (strncmp($content, "\xFF\xD8\xFF\xE0", 4) == 0 && substr($content, 6, 6) == "JFIF\x00\x01")
                       || (strncmp($content, "\xFF\xD8\xFF\xE1", 4) == 0 && substr($content, 6, 6) == "Exif\x00\x00")) {
                return self::JPG_TYPE;
            } else if (strncmp($content, "\x89PNG\r\n\x1A\x0A", 8) == 0) {
                return self::PNG_TYPE;
            } else if ((strncmp($content, "GIF87a", 6) == 0
                        || strncmp($content, "GIF89a", 6) == 0)
                       && str_ends_with($content, "\x00;")) {
                return self::GIF_TYPE;
            } else if (strncmp($content, "Rar!\x1A\x07\x00", 7) == 0
                       || strncmp($content, "Rar!\x1A\x07\x01\x00", 8) == 0) {
                return self::RAR_TYPE;
            }
        }
        // eliminate invalid types, canonicalize
        if ($type
            && !isset(self::$tinfo[$type])
            && ($tx = self::type($type))) {
            $type = $tx;
        }
        // unreliable sniffs
        if ($content !== null
            && $content !== ""
            && (!$type || $type === self::BIN_TYPE)) {
            if (strncmp("%!PS-", $content, 5) == 0) {
                return self::PS_TYPE;
            } else if (strncmp($content, "ustar\x0000", 8) == 0
                       || strncmp($content, "ustar  \x00", 8) == 0) {
                return self::TAR_TYPE;
            }
            if (!self::$finfo) {
                self::$finfo = new finfo(FILEINFO_MIME_TYPE);
            }
            $type = self::$finfo->buffer(substr($content, 0, 2048));
            // canonicalize
            if ($type
                && !isset(self::$tinfo[$type])
                && ($tx = self::type($type))) {
                $type = $tx;
            }
        }
        // type obtained, or octet-stream if nothing else works
        return self::type($type ? : self::BIN_TYPE);
    }
}
