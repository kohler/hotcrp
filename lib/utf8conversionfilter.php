<?php
// utf8conversionfilter.php -- HotCRP UTF-8 conversion helper
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class UTF8ConversionFilter extends php_user_filter {
    /** @var int */
    private $state;
    /** @var string */
    private $buf;

    const INITIAL = 0;
    const ASCII = 1;
    const UTF8 = 2;
    const WINDOWS = 3;
    const UTF16BE = 4;
    const UTF16LE = 5;

    private static $registered = false;

    #[\ReturnTypeWillChange]
    function filter($in, $out, &$consumed, $closing) {
        $nc = $no = 0;
        while (($bucket = stream_bucket_make_writeable($in))) {
            $this->buf .= $bucket->data;
            $nc += $bucket->datalen;
            if ($this->state === self::INITIAL) {
                if (strlen($this->buf) < 128) {
                    continue;
                }
                $this->analyze_initial();
            } else if ($this->state === self::ASCII) {
                $pfx = UnicodeHelper::utf8_truncate_invalid($bucket->data);
                if (!is_usascii($pfx)) {
                    $this->state = is_valid_utf8($pfx) ? self::UTF8 : self::WINDOWS;
                }
            }
            if (strlen($this->buf) > 4096) {
                $no += $this->transfer($out);
            }
        }
        if ($this->state === self::INITIAL && $closing) {
            $this->analyze_initial();
        }
        if ($this->state !== self::INITIAL && $this->buf !== "") {
            $no += $this->transfer($out);
        }
        $consumed += $nc;
        return $no > 0 ? PSFS_PASS_ON : PSFS_FEED_ME;
    }

    private function analyze_initial() {
        $len = strlen($this->buf);
        if ($len === 0) {
            $this->state = self::UTF8;
        } else if (str_starts_with($this->buf, "\xEF\xBB\xBF")) {
            $this->buf = substr($this->buf, 3);
            $this->state = self::UTF8;
        } else if (str_starts_with($this->buf, "\xFF\xFE")) {
            $this->buf = substr($this->buf, 2);
            $this->state = self::UTF16BE;
        } else if (str_starts_with($this->buf, "\xFE\xFF")) {
            $this->buf = substr($this->buf, 2);
            $this->state = self::UTF16LE;
        } else if (substr_count($this->buf, "\0", 0, min($len, 128)) >= min(5, $len / 2)) {
            $zp = strpos($this->buf, "\0");
            $this->state = $zp & 1 ? self::UTF16LE : self::UTF16BE;
        } else {
            $pfx = UnicodeHelper::utf8_truncate_invalid($this->buf);
            if (is_usascii($pfx)) {
                $this->state = self::ASCII;
            } else {
                $this->state = is_valid_utf8($pfx) ? self::UTF8 : self::WINDOWS;
            }
        }
    }

    private function transfer($out) {
        $len = strlen($this->buf);
        if ($len === 0) {
            return 0;
        } else if ($this->state <= self::UTF8) {
            $keep = UnicodeHelper::utf8_incomplete_suffix_length($this->buf);
            $str = $keep ? substr($this->buf, 0, $len - $keep) : $this->buf;
            if (!is_valid_utf8($str)) {
                $str = UnicodeHelper::utf8_replace_invalid($str);
            }
        } else if ($this->state === self::WINDOWS) {
            $keep = 0;
            $str = UnicodeHelper::to_utf8("Windows-1252", $this->buf);
        } else {
            $is_le = $this->state === self::UTF16LE;
            $keep = UnicodeHelper::utf16_incomplete_suffix_length($this->buf, $is_le);
            $str = $keep ? substr($this->buf, 0, $len - $keep) : $this->buf;
            $str = UnicodeHelper::to_utf8($is_le ? "UTF-16LE" : "UTF-16BE", $str);
        }
        stream_bucket_append($out, stream_bucket_new($this->stream, $str));
        $this->buf = $keep ? substr($this->buf, $len - $keep) : "";
        return $len - $keep;
    }

    #[\ReturnTypeWillChange]
    function onClose() {
    }

    #[\ReturnTypeWillChange]
    function onCreate() {
        $this->state = 0;
        $this->buf = "";
        return true;
    }

    static function append($stream, ...$params) {
        if (!self::$registered) {
            stream_filter_register("utf8conversion", "UTF8ConversionFilter");
            self::$registered = true;
        }
        return stream_filter_append($stream, "utf8conversion", ...$params);
    }
}
