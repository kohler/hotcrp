<?php
// uconvertershim.php -- PHP UConverter polyfill
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class UConverterShim {
    const UTF8 = 0;
    const WINDOWS_1252 = 1;
    const UTF16_BigEndian = 2;
    const UTF16_LittleEndian = 3;
    /** @var int */
    private $src;

    const UTF8_FROM_WINDOWS_1252 = "\xE2\x82\xAC\xC2\x81\x20\xE2\x80\x9A\xC6\x92\x20\xE2\x80\x9E\xE2\x80\xA6\xE2\x80\xA0\xE2\x80\xA1\xCB\x86\x20\xE2\x80\xB0\xC5\xA0\x20\xE2\x80\xB9\xC5\x92\x20\xC2\x8D\x20\xC5\xBD\x20\xC2\x8F\x20\xC2\x90\x20\xE2\x80\x98\xE2\x80\x99\xE2\x80\x9C\xE2\x80\x9D\xE2\x80\xA2\xE2\x80\x93\xE2\x80\x94\xCB\x9C\x20\xE2\x84\xA2\xC5\xA1\x20\xE2\x80\xBA\xC5\x93\x20\xC2\x9D\x20\xC5\xBE\x20\xC5\xB8\x20\xC2\xA0\x20\xC2\xA1\x20\xC2\xA2\x20\xC2\xA3\x20\xC2\xA4\x20\xC2\xA5\x20\xC2\xA6\x20\xC2\xA7\x20\xC2\xA8\x20\xC2\xA9\x20\xC2\xAA\x20\xC2\xAB\x20\xC2\xAC\x20\xC2\xAD\x20\xC2\xAE\x20\xC2\xAF\x20\xC2\xB0\x20\xC2\xB1\x20\xC2\xB2\x20\xC2\xB3\x20\xC2\xB4\x20\xC2\xB5\x20\xC2\xB6\x20\xC2\xB7\x20\xC2\xB8\x20\xC2\xB9\x20\xC2\xBA\x20\xC2\xBB\x20\xC2\xBC\x20\xC2\xBD\x20\xC2\xBE\x20\xC2\xBF\x20\xC3\x80\x20\xC3\x81\x20\xC3\x82\x20\xC3\x83\x20\xC3\x84\x20\xC3\x85\x20\xC3\x86\x20\xC3\x87\x20\xC3\x88\x20\xC3\x89\x20\xC3\x8A\x20\xC3\x8B\x20\xC3\x8C\x20\xC3\x8D\x20\xC3\x8E\x20\xC3\x8F\x20\xC3\x90\x20\xC3\x91\x20\xC3\x92\x20\xC3\x93\x20\xC3\x94\x20\xC3\x95\x20\xC3\x96\x20\xC3\x97\x20\xC3\x98\x20\xC3\x99\x20\xC3\x9A\x20\xC3\x9B\x20\xC3\x9C\x20\xC3\x9D\x20\xC3\x9E\x20\xC3\x9F\x20\xC3\xA0\x20\xC3\xA1\x20\xC3\xA2\x20\xC3\xA3\x20\xC3\xA4\x20\xC3\xA5\x20\xC3\xA6\x20\xC3\xA7\x20\xC3\xA8\x20\xC3\xA9\x20\xC3\xAA\x20\xC3\xAB\x20\xC3\xAC\x20\xC3\xAD\x20\xC3\xAE\x20\xC3\xAF\x20\xC3\xB0\x20\xC3\xB1\x20\xC3\xB2\x20\xC3\xB3\x20\xC3\xB4\x20\xC3\xB5\x20\xC3\xB6\x20\xC3\xB7\x20\xC3\xB8\x20\xC3\xB9\x20\xC3\xBA\x20\xC3\xBB\x20\xC3\xBC\x20\xC3\xBD\x20\xC3\xBE\x20\xC3\xBF\x20";

    /** @param ?string $destination_encoding
     * @param ?string $source_encoding */
    function __construct($destination_encoding, $source_encoding) {
        assert($destination_encoding === "UTF-8");
        if ($source_encoding === "UTF-8") {
            $this->src = self::UTF8;
        } else if ($source_encoding === "Windows-1252"
                   || $source_encoding === "ibm-5348_P100-1997") {
            $this->src = self::WINDOWS_1252;
        } else if ($source_encoding === "UTF-16BE") {
            $this->src = self::UTF16_BigEndian;
        } else if ($source_encoding === "UTF-16LE") {
            $this->src = self::UTF16_LittleEndian;
        } else {
            assert($source_encoding === ("Invalid source encoding" && false));
        }
    }

    /** @param string $s
     * @return string */
    function convert($s) {
        if ($this->src === self::UTF8) {
            // sanitize UTF-8 input
            $t = "";
            $pos = 0;
            $len = strlen($s);
            while ($pos < $len) {
                if (preg_match('/\G[\x00-\x7f]+/', $s, $m, 0, $pos)
                    || preg_match('/\G(?:[\xc2-\xdf][\x80-\xbf]|\xe0[\xa0-\xbf][\x80-\xbf]|[\xe1-\xec\xee\xef][\x80-\xbf][\x80-\xbf]|\xed[\x80-\x9f][\x80-\xbf]|\xf0[\x90-\xbf][\x80-\xbf][\x80-\xbf]|[\xf1-\xf3][\x80-\xbf][\x80-\xbf][\x80-\xbf]|\xf4[\x80-\x8f][\x80-\xbf][\x80-\xbf])+/', $s, $m, 0, $pos)) {
                    $t .= $m[0];
                    $pos += strlen($m[0]);
                    continue;
                }
                $c = ord($s[$pos]);
                ++$pos;
                if ($c !== 0) {
                    $t .= "\xEF\xBF\xBD";
                    if ($c >= 0xF0 && $pos < $len && ord($s[$pos]) >= 0x80 && ord($s[$pos]) < 0xC0) {
                        ++$pos;
                    }
                    if ($c >= 0xE0 && $pos < $len && ord($s[$pos]) >= 0x80 && ord($s[$pos]) < 0xC0) {
                        ++$pos;
                    }
                    if ($c >= 0xC0 && $pos < $len && ord($s[$pos]) >= 0x80 && ord($s[$pos]) < 0xC0) {
                        ++$pos;
                    }
                }
            }
            return $t;
        }

        if ($this->src === self::WINDOWS_1252) {
            // translate high characters to their UTF-8 equivalents
            return preg_replace_callback('/[\200-\377]/', function ($m) {
                return rtrim(substr(self::UTF8_FROM_WINDOWS_1252, 3 * (ord($m[0]) - 128), 3));
            }, $s);
        }

        // otherwise, UTF-16 conversion
        $format = $this->src === self::UTF16_BigEndian ? "n*" : "v*";
        $t = "";
        $pos = 0;
        $len = strlen($s) & ~1;
        $ch = null;
        while ($pos < $len) {
            $n = min(8192, $len - $pos);
            foreach (unpack($format, substr($s, $pos, $n)) as $c) {
                if ($ch !== null) {
                    if ($c >= 0xDC00 && $c <= 0xDFFF) {
                        $t .= UnicodeHelper::utf8_chr(0x10000 | (($ch - 0xD800) << 10) | ($c - 0xDC00));
                        $ch = null;
                        continue;
                    }
                    $t .= "\xEF\xBF\xBD";
                    $ch = null;
                }
                if ($c < 0x80) {
                    $t .= chr($c);
                } else if ($c < 0xD800 || $c >= 0xE000) {
                    $t .= UnicodeHelper::utf8_chr($c);
                } else if ($c < 0xDC00) {
                    $ch = $c;
                } else {
                    $t .= "\xEF\xBF\xBD";
                }
            }
            $pos += $n;
        }
        if ($ch !== null || (strlen($s) & 1) !== 0) {
            $t .= "\xEF\xBF\xBD";
        }
        return $t;
    }

    /** @param string $s
     * @param string $toEncoding
     * @param string $fromEncoding
     * @return string */
    static function transcode($s, $toEncoding, $fromEncoding) {
        return (new UConverterShim($toEncoding, $fromEncoding))->convert($s);
    }
}

if (!class_exists("UConverter", false)) {
    class_alias("UConverterShim", "UConverter");
}
