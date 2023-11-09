<?php
// mimetype.php -- HotCRP helper file for MIME types
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Mimetype {
    // NB types listed here must also be present in `lib/mime.types`
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
    const KEYNOTE_TYPE = "application/vnd.apple.keynote";

    const FLAG_INLINE = 1;
    const FLAG_UTF8 = 2;
    const FLAG_COMPRESSIBLE = 4;
    const FLAG_INCOMPRESSIBLE = 8;
    const FLAG_TEXTUAL = 16;
    const FLAG_REQUIRE_SNIFF = 32;
    const FLAG_ZIPLIKE = 64;

    /** @var string */
    public $mimetype;
    /** @var string */
    public $extension;
    /** @var ?string */
    public $description;
    /** @var int */
    public $flags;

    /** @var associative-array<string,Mimetype> */
    private static $tmap = [];

    /** @var array<string,array{0:string,1:?string,2:int,3?:string,4?:string,5?:string}>
     * @readonly */
    public static $tinfo = [
        self::TXT_TYPE =>     [".txt", "text", self::FLAG_INLINE | self::FLAG_COMPRESSIBLE | self::FLAG_TEXTUAL],
        self::PDF_TYPE =>     [".pdf", "PDF", self::FLAG_INLINE | self::FLAG_REQUIRE_SNIFF],
        self::PS_TYPE =>      [".ps", "PostScript", self::FLAG_COMPRESSIBLE],
        self::PPT_TYPE =>     [".ppt", "PowerPoint", self::FLAG_INCOMPRESSIBLE, "application/mspowerpoint", "application/powerpoint", "application/x-mspowerpoint"],
        self::KEYNOTE_TYPE => [".key", "Keynote", self::FLAG_INCOMPRESSIBLE | self::FLAG_ZIPLIKE, "application/x-iwork-keynote-sffkey"],
        "application/vnd.openxmlformats-officedocument.presentationml.presentation" =>
                              [".pptx", "PowerPoint", self::FLAG_INCOMPRESSIBLE],
        "video/mp4" =>        [".mp4", null, self::FLAG_INCOMPRESSIBLE],
        "video/x-msvideo" =>  [".avi", null, self::FLAG_INCOMPRESSIBLE],
        self::JSON_TYPE =>    [".json", "JSON", self::FLAG_UTF8 | self::FLAG_COMPRESSIBLE | self::FLAG_TEXTUAL],
        self::JPG_TYPE =>     [".jpg", "JPEG", self::FLAG_INLINE, ".jpeg"],
        self::PNG_TYPE =>     [".png", "PNG", self::FLAG_INLINE | self::FLAG_REQUIRE_SNIFF],
        self::GIF_TYPE =>     [".gif", "GIF", self::FLAG_INLINE | self::FLAG_REQUIRE_SNIFF]
    ];

    /** @var bool */
    private static $mime_types_loaded = false;
    /** @var ?int */
    private static $max_extension_length;
    /** @var ?\finfo */
    private static $finfo;

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

    /** @param 0|1|2 $type */
    static function load_mime_types($type = 0) {
        if ($type === 2) {
            self::$tmap = [];
            self::$mime_types_loaded = false;
        } else if (empty(self::$tmap)) {
            foreach (self::$tinfo as $xtype => $data) {
                $mt = new Mimetype($xtype, $data[0], $data[1], $data[2]);
                self::$tmap[$xtype] = self::$tmap[$mt->extension] = $mt;
                for ($i = 3; $i < count($data); ++$i) {
                    self::$tmap[$data[$i]] = $mt;
                }
            }
        }
        if (!self::$mime_types_loaded && $type !== 1) {
            self::$mime_types_loaded = true;
            $t = (string) @file_get_contents(SiteLoader::find("lib/mime.types"));
            $t = preg_replace('/[ \t]+/', " ", $t);
            foreach (explode("\n", $t) as $l) {
                $a = explode(" ", trim($l));
                if ($a[0] === "#!!" && count($a) >= 2) {
                    if (!isset(self::$tmap[$a[1]])) {
                        self::$tmap[$a[1]] = self::$tmap[$a[2] ?? self::BIN_TYPE];
                    }
                } else if (strpos($a[0], "/") !== false && $a[0][0] !== "#") {
                    $x = $a[1] ?? "";
                    $mt = new Mimetype($a[0], $x !== "" ? ".{$x}" : "");
                    if (!isset(self::$tmap[$a[0]])) {
                        self::$tmap[$a[0]] = $mt;
                    }
                    for ($i = 1; $i !== count($a); ++$i) {
                        $x = $a[$i];
                        if ($x !== "" && !isset(self::$tmap[".{$x}"]))
                            self::$tmap[".{$x}"] = $mt;
                    }
                }
            }
        }
        if ($type === 2) {
            foreach (self::$tinfo as $xtype => $data) {
                $mt = self::$tmap[$xtype];
                $mt->description = $data[1];
                $mt->flags = $data[2];
            }
        }
    }

    /** @return int */
    static function max_extension_length() {
        if (self::$max_extension_length === null) {
            self::$mime_types_loaded || self::load_mime_types();
            $n = 0;
            foreach (self::$tmap as $x => $mt) {
                if (str_starts_with($x, "."))
                    $n = max($n, strlen($x));
            }
            self::$max_extension_length = $n;
        }
        return self::$max_extension_length;
    }

    /** @param string|Mimetype $type
     * @return ?Mimetype */
    static function lookup($type) {
        if (!$type) {
            return null;
        }
        if (is_object($type)) {
            return $type;
        }
        self::$tmap || self::load_mime_types(1);
        if (array_key_exists($type, self::$tmap)) {
            return self::$tmap[$type];
        }
        $space = strpos($type, " ");
        $semi = strpos($type, ";");
        if ($space || $semi) {
            $type = substr($type, 0, min($space ? : strlen($type), $semi ? : strlen($type)));
            if (array_key_exists($type, self::$tmap)) {
                return self::$tmap[$type];
            }
        }
        self::$mime_types_loaded || self::load_mime_types();
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

    /** @param string|Mimetype $type
     * @return string */
    static function type($type) {
        if ($type instanceof Mimetype) {
            return $type->mimetype;
        } else if (isset(self::$tinfo[$type])) {
            return $type;
        } else if (($x = self::lookup($type))) {
            return $x->mimetype;
        } else {
            return $type;
        }
    }

    /** @param string|Mimetype $type
     * @return string */
    static function type_with_charset($type) {
        if (($x = self::lookup($type))) {
            if (($x->flags & self::FLAG_UTF8) !== 0) {
                return $x->mimetype . "; charset=utf-8";
            } else {
                return $x->mimetype;
            }
        } else {
            return "";
        }
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

    /** @param list<Mimetype> $types
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

    /** @param list<Mimetype> $types
     * @return ?string */
    static function list_accept($types) {
        $mta = [];
        foreach ($types ?? [] as $mt) {
            if ($mt->mimetype === self::BIN_TYPE
                || ($mt->flags & self::FLAG_ZIPLIKE) !== 0) {
                return null;
            } else {
                $mta[] = $mt->mimetype;
            }
        }
        return empty($mta) ? null : join(",", $mta);
    }

    /** @param string|Mimetype $type
     * @return bool */
    static function disposition_inline($type) {
        $x = self::lookup($type);
        return $x && ($x->flags & self::FLAG_INLINE) !== 0;
    }

    /** @param string|Mimetype $type
     * @return bool */
    static function textual($type) {
        $x = self::lookup($type);
        if ($x && $x->flags !== 0) {
            return ($x->flags & self::FLAG_TEXTUAL) !== 0;
        } else {
            return str_starts_with($x ? $x->mimetype : $type, "text/");
        }
    }

    /** @param string|Mimetype $type
     * @return bool */
    static function compressible($type) {
        $x = self::lookup($type);
        if ($x && $x->flags !== 0) {
            return ($x->flags & self::FLAG_COMPRESSIBLE) !== 0;
        } else {
            return str_starts_with($x ? $x->mimetype : $type, "text/");
        }
    }

    /** @param string|Mimetype $type
     * @return bool */
    function matches($type) {
        $xt = self::type($type);
        return $xt === $this->mimetype
            || (($this->flags & self::FLAG_ZIPLIKE) !== 0
                && $xt === self::ZIP_TYPE);
    }


    /** @return list<Mimetype> */
    static function builtins() {
        return array_map(function ($t) { return Mimetype::lookup($t); },
                         array_keys(self::$tinfo));
    }


    /** @param ?string $content
     * @return bool */
    static function pdf_content($content) {
        return $content && str_starts_with($content, "%PDF-");
    }

    /** @param ?string $content
     * @param ?string $type
     * @return string */
    static function content_type($content, $type = null) {
        // null content must use type
        if ($content === null || $content === "") {
            return self::type($type ? : self::BIN_TYPE);
        }
        // reliable sniffs
        if (strlen($content) < 16) {
            // do not sniff
        } else if (str_starts_with($content, "%PDF-")) {
            return self::PDF_TYPE;
        } else if (strlen($content) > 516
                   && substr($content, 512, 4) === "\x00\x6E\x1E\xF0") {
            return self::PPT_TYPE;
        } else if (substr($content, 0, 4) === "\xFF\xD8\xFF\xD8"
                   || (substr($content, 0, 4) === "\xFF\xD8\xFF\xE0"
                       && substr($content, 6, 6) === "JFIF\x00\x01")
                   || (substr($content, 0, 4) === "\xFF\xD8\xFF\xE1"
                       && substr($content, 6, 6) === "Exif\x00\x00")) {
            return self::JPG_TYPE;
        } else if (substr($content, 0, 8) === "\x89PNG\r\n\x1A\x0A") {
            return self::PNG_TYPE;
        } else if ((substr($content, 0, 6) === "GIF87a"
                    || substr($content, 0, 6) === "GIF89a")
                   && str_ends_with($content, "\x00;")) {
            return self::GIF_TYPE;
        } else if (substr($content, 0, 7) === "Rar!\x1A\x07\x00"
                   || substr($content, 0, 8) === "Rar!\x1A\x07\x01\x00") {
            return self::RAR_TYPE;
        }
        // canonicalize
        if ($type && ($mt = self::lookup($type))) {
            if (($mt->flags & self::FLAG_REQUIRE_SNIFF) !== 0) {
                $type = self::BIN_TYPE;
            } else {
                $type = $mt->mimetype;
            }
        }
        // unreliable sniffs
        if (!$type || $type === self::BIN_TYPE) {
            if (substr($content, 0, 5) === "%!PS-") {
                return self::PS_TYPE;
            } else if (substr($content, 0, 8) === "ustar\x0000"
                       || substr($content, 0, 8) === "ustar  \x00") {
                return self::TAR_TYPE;
            }
            self::$finfo = self::$finfo ?? new finfo(FILEINFO_MIME_TYPE);
            $type = self::$finfo->buffer(substr($content, 0, 2048));
        }
        // type obtained, or octet-stream if nothing else works
        return self::type($type ? : self::BIN_TYPE);
    }

    /** @param ?string $content
     * @param ?string $type
     * @param ?DocumentInfo $doc
     * @return ?array{type:string,width?:int,height?:int} */
    static function content_info($content, $type = null, $doc = null) {
        if ($content === null && $doc) {
            if ($doc->has_memory_content()) {
                $content = $doc->content();
            } else {
                $content = $doc->content_prefix(4096);
            }
            if ($content === false) {
                return null;
            }
        }
        $content = $content ?? "";
        $type = self::content_type($content, $type);
        if ($type === self::JPG_TYPE) {
            return self::jpeg_content_info($content);
        } else if ($type === self::PNG_TYPE) {
            return self::png_content_info($content);
        } else if ($type === self::GIF_TYPE) {
            return self::gif_content_info($content);
        } else if ($type === "video/mp4") {
            if ($doc
                && strlen($content) !== $doc->size()
                && ($file = $doc->content_file())) {
                $ivm = ISOVideoMimetype::make_file($file, $content);
            } else {
                $ivm = ISOVideoMimetype::make_string($content);
            }
            return $ivm->content_info();
        } else {
            return ["type" => $type];
        }
    }

    /** @param string $s
     * @param int $off
     * @return int */
    static function be16at($s, $off) {
        return (unpack("n", $s, $off))[1];
    }

    /** @param string $s
     * @param int $off
     * @return int */
    static function le16at($s, $off) {
        return (unpack("v", $s, $off))[1];
    }

    /** @param string $s
     * @param int $off
     * @return array{int,int} */
    static function le16at_x2($s, $off) {
        $a = unpack("v2", $s, $off);
        return [$a[1], $a[2]];
    }

    /** @param string $s
     * @param int $off
     * @return int */
    static function be32at($s, $off) {
        return (unpack("N", $s, $off))[1];
    }

    /** @param string $s
     * @param int $off
     * @return array{int,int} */
    static function be32at_x2($s, $off) {
        $a = unpack("N2", $s, $off);
        return [$a[1], $a[2]];
    }

    /** @param string $s
     * @param int $off
     * @return int */
    static function be64at($s, $off) {
        return (unpack("J", $s, $off))[1];
    }

    /** @param string $s
     * @return array{type:string,width?:int,height?:int} */
    static private function jpeg_content_info($s) {
        $info = ["type" => self::JPG_TYPE];
        $pos = 0;
        $len = strlen($s);
        while ($pos + 2 <= $len && ord($s[$pos]) === 0xFF) {
            $ch = ord($s[$pos + 1]);
            if ($ch === 0xFF) {
                ++$pos;
                continue;
            } else if (($ch >= 0xD0 && $ch <= 0xD8) || $ch === 0x01) {
                $pos += 2;
                continue;
            } else if ($ch === 0xD9 || $pos + 4 > $len) {
                break;
            }
            $blen = self::be16at($s, $pos + 2);
            if (($ch >= 0xC0 && $ch <= 0xCF) && $ch !== 0xC8) {
                // SOF
                if ($blen < 8 || $pos + 6 > $len) {
                    break;
                }
                $x = $pos + 8 <= $len ? self::be16at($s, $pos + 7) : 0;
                $y = self::be16at($s, $pos + 5);
                if ($x !== 0) {
                    $info["width"] = $x;
                }
                if ($y !== 0) {
                    $info["height"] = $y;
                }
                if ($x === 0 || $y !== 0) {
                    break;
                }
            } else if ($ch === 0xDC) {
                // DNL
                if ($blen !== 4 || $pos + 5 > $len) {
                    break;
                }
                $y = self::be16at($s, $pos + 4);
                if ($y !== 0) {
                    $info["height"] = $y;
                }
                break;
            }
            $pos += 2 + $blen;
        }
        return $info;
    }

    /** @param string $s
     * @return array{type:string,width?:int,height?:int} */
    static private function gif_content_info($s) {
        $info = ["type" => self::GIF_TYPE];
        $pos = 6;
        $len = strlen($s);
        if ($pos + 3 > $len) {
            return $info;
        }
        list($lw, $lh) = self::le16at_x2($s, $pos);
        if ($lw !== 0) {
            $info["width"] = $lw;
        }
        if ($lh !== 0) {
            $info["height"] = $lh;
        }
        if (($lw !== 0 && $lh !== 0) || $pos + 6 > $len) {
            return $info;
        }
        $flags = ord($s[$pos + 4]);
        $pos += 6;
        if (($flags & 0x80) !== 0) {
            $pos += 3 * (1 << (($flags & 7) + 1));
        }
        while ($pos + 8 <= $len) {
            $ch = ord($s[$pos]);
            if ($ch === 0x21) {
                // extension
                $pos += 2;
                $blen = 1;
                while ($pos < $len && $blen !== 0) {
                    $blen = ord($s[$pos]);
                    $pos += $blen + 1;
                }
            } else if ($ch === 0x2C) {
                // image
                list($left, $top) = self::le16at_x2($s, $pos + 1);
                list($w, $h) = self::le16at_x2($s, $pos + 5);
                if ($w !== 0 && $left + $w > ($info["width"] ?? 0)) {
                    $info["width"] = $left + $w;
                }
                if ($h !== 0 && $top + $h > ($info["height"] ?? 0)) {
                    $info["height"] = $top + $h;
                }
                break;
            } else {
                // trailer/unknown
                break;
            }
        }
        return $info;
    }

    /** @param string $s
     * @return array{type:string,width?:int,height?:int} */
    static private function png_content_info($s) {
        $info = ["type" => self::PNG_TYPE];
        $pos = 8;
        $len = strlen($s);
        while ($pos + 8 <= $len) {
            $blen = self::be32at($s, $pos);
            $chunk = self::be32at($s, $pos + 4);
            if ($chunk === 0x49484452 /* IHDR */) {
                $w = $pos + 11 <= $len ? self::be32at($s, $pos + 8) : 0;
                $h = $pos + 15 <= $len ? self::be32at($s, $pos + 12) : 0;
                if ($w !== 0) {
                    $info["width"] = $w;
                }
                if ($h !== 0) {
                    $info["height"] = $h;
                }
                break;
            }
            $min = min($chunk >> 24, ($chunk >> 16) & 0xFF, ($chunk >> 8) & 0xFF, $chunk & 0xFF);
            $max = max($chunk >> 24, ($chunk >> 16) & 0xFF, ($chunk >> 8) & 0xFF, $chunk & 0xFF);
            if (($min | 0x20) < 0x61 || ($max | 0x20) > 0x7A) {
                break;
            }
            $pos += 12 + $blen;
        }
        return $info;
    }
}
