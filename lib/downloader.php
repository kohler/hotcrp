<?php
// downloader.php -- download helper class
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Downloader {
    /** @var ?string */
    public $etag;
    /** @var ?int */
    public $last_modified;
    /** @var ?int */
    public $content_length;
    /** @var ?string */
    public $mimetype;

    /** @var ?string */
    public $if_match;
    /** @var ?string */
    public $if_none_match;
    /** @var ?int */
    public $if_modified_since;
    /** @var ?int */
    public $if_unmodified_since;
    /** @var ?string */
    public $if_range;
    /** @var ?list<array{int,int}> */
    public $range;
    /** @var bool */
    public $head = false;
    /** @var ?bool */
    public $attachment;
    /** @var bool */
    public $single = false;
    /** @var bool */
    public $cacheable = false;
    /** @var bool */
    public $no_accel = false;
    /** @var ?Contact */
    public $log_user;

    /** @var ?string */
    private $_content;
    /** @var ?string */
    private $_content_file;
    /** @var ?string */
    private $_content_redirect;
    /** @var ?callable(resource,int,int) */
    private $_content_function;
    /** @var ?string */
    private $_filename;
    /** @var list<string> */
    private $_headers = [];

    /** @return $this */
    function parse_qreq(Qrequest $qreq) {
        $this->if_match = $qreq->header("If-Match");
        if ($this->if_match === null
            && ($s = $qreq->header("If-Unmodified-Since"))) {
            $this->if_unmodified_since = Navigation::parse_http_date($s);
        }

        $this->if_none_match = $qreq->header("If-None-Match");
        if ($this->if_none_match === null
            && ($s = $qreq->header("If-Modified-Since"))) {
            $this->if_modified_since = Navigation::parse_http_date($s);
        }

        $method = $qreq->method();
        if ($method !== "GET") {
            if ($method === "HEAD") {
                $this->head = true;
            }
            return $this;
        }

        $this->if_range = $qreq->header("If-Range");
        $range = $qreq->header("Range");
        if ($range === null
            || !preg_match('/\Abytes\s*=\s*(?:(?:\d+-\d+|-\d+|\d+-)\s*,?\s*)+\z/', $range)) {
            return $this;
        }
        $this->range = [];
        $lastr = null;
        preg_match_all('/\d+-\d+|-\d+|\d+-/', $range, $m);
        foreach ($m[0] as $t) {
            $dash = strpos($t, "-");
            $r1 = $dash === 0 ? null : stoi(substr($t, 0, $dash));
            $r2 = $dash === strlen($t) - 1 ? null : stoi(substr($t, $dash + 1));
            if ($r1 === null && $r2 !== 0) {
                $this->range[] = $lastr = [$r1, $r2];
            } else if ($r2 === null || ($r1 !== null && $r1 <= $r2)) {
                if ($lastr !== null
                    && $lastr[0] !== null
                    && $lastr[1] !== null
                    && $r1 >= $lastr[0]
                    && $r1 - $lastr[1] <= 100) {
                    $nr = count($this->range);
                    $this->range[$nr - 1][1] = $lastr[1] = $r2;
                } else {
                    $this->range[] = $lastr = [$r1, $r2];
                }
            } else {
                $this->range = null;
                break;
            }
        }
        return $this;
    }

    /** @return bool */
    function has_content() {
        return $this->_content !== null
            || $this->_content_file !== null
            || $this->_content_redirect !== null
            || $this->_content_function !== null;
    }

    /** @param string $mimetype
     * @return $this */
    function set_mimetype($mimetype) {
        $this->mimetype = $mimetype;
        return $this;
    }

    /** @param bool $attachment
     * @return $this */
    function set_attachment($attachment) {
        $this->attachment = $attachment;
        return $this;
    }

    /** @param string $filename
     * @return $this */
    function set_filename($filename) {
        $this->_filename = $filename;
        return $this;
    }

    /** @param int $content_length
     * @return $this */
    function set_content_length($content_length) {
        $this->content_length = $content_length;
        return $this;
    }

    /** @param string $content
     * @return $this */
    function set_content($content) {
        assert(!$this->has_content());
        $this->_content = $content;
        $this->content_length = strlen($content);
        return $this;
    }

    /** @param string $content_file
     * @return $this */
    function set_content_file($content_file) {
        assert(!$this->has_content());
        $this->_content_file = $content_file;
        $this->content_length = @filesize($content_file);
        return $this;
    }

    /** @param string $redirect
     * @return $this */
    function set_content_redirect($redirect) {
        assert(!$this->has_content());
        $this->_content_redirect = $redirect;
        return $this;
    }

    /** @param callable(resource,int,int) $function
     * @return $this */
    function set_content_function($function) {
        assert(!$this->has_content());
        $this->_content_function = $function;
        return $this;
    }

    /** @param bool $cacheable
     * @return $this */
    function set_cacheable($cacheable) {
        $this->cacheable = $cacheable;
        return $this;
    }

    /** @param ?string $etag
     * @return $this */
    function set_etag($etag) {
        $this->etag = $etag;
        return $this;
    }

    /** @param ?int $last_modified
     * @return $this */
    function set_last_modified($last_modified) {
        $this->last_modified = $last_modified;
        return $this;
    }

    /** @param ?Contact $log_user
     * @return $this */
    function set_log_user($log_user) {
        $this->log_user = $log_user;
        return $this;
    }

    /** @param string $h */
    private function _remove_matching_headers($h) {
        $n = strpos($h, ":") ? : strlen($h) - 1;
        $delta = 0;
        foreach ($this->_headers as $i => &$s) {
            if (substr_compare($s, $h, 0, $n + 1, true) === 0) {
                ++$delta;
            } else if ($delta > 0) {
                $this->_headers[$i - $delta] = $s;
            }
        }
        if ($delta > 0) {
            array_splice($this->_headers, count($this->_headers) - $delta);
        }
    }

    /** @param string $header
     * @param bool $replace
     * @return $this */
    function header($header, $replace = true) {
        if ($replace) {
            $this->_remove_matching_headers($header);
        }
        $this->_headers[] = $header;
        return $this;
    }


    /** @param ?string $e1
     * @param ?string $e2
     * @param bool $strong
     * @return bool */
    static function etag_equals($e1, $e2, $strong) {
        if ($e1 === null || $e2 === null) {
            return false;
        }
        $w1 = str_starts_with($e1, "W/");
        $w2 = str_starts_with($e2, "W/");
        return (!$w1 || !$w2 || !$strong)
            && ($w1 ? substr($e1, 2) : $e1) === ($w2 ? substr($e2, 2) : $e2);
    }

    /** @param string $s
     * @param bool $strong */
    private function any_etag_match($s, $strong) {
        $pos = 0;
        while (preg_match('/\G[,\s]*((?:W\/|)".*?"|\*)/', $s, $m, 0, $pos)) {
            if ($m[1] === "*" || self::etag_equals($m[1], $this->etag, $strong)) {
                return true;
            }
            $pos += strlen($m[1]);
        }
        return false;
    }

    /** @return bool */
    function check_match() {
        if ($this->if_match !== null) {
            if ($this->etag !== null
                && !$this->any_etag_match($this->if_match, true)) {
                return false;
            }
        } else if ($this->if_unmodified_since !== null) {
            if ($this->last_modified !== null
                && $this->last_modified > $this->if_unmodified_since) {
                return false;
            }
        }
        if ($this->if_none_match !== null) {
            if ($this->etag !== null
                && $this->any_etag_match($this->if_none_match, false)) {
                return false;
            }
        } else if ($this->if_modified_since !== null) {
            if ($this->last_modified !== null
                && $this->last_modified <= $this->if_modified_since) {
                return false;
            }
        }
        return true;
    }

    /** @param int $first
     * @param int $last
     * @return bool */
    function range_overlaps($first, $last) {
        assert($first < $last);
        $length = $this->content_length ?? ($first + 1);
        foreach ($this->range ?? [[0, null]] as $r) {
            $r1 = $r[0] ?? 0;
            $r2 = $r[1] ?? ($length - 1);
            if ($last > $r1 && $first < $r2 + 1) {
                return true;
            }
        }
        return false;
    }

    /** @return bool */
    function check_ranges() {
        if ($this->range === null) {
            return true;
        }
        if ($this->if_range !== null
            && ($this->etag === null
                || !self::etag_equals($this->if_range, $this->etag, true))) {
            $this->range = null;
            return true;
        }
        $rs = [];
        $filesize = $this->content_length;
        foreach ($this->range as $r) {
            list($r0, $r1) = $r;
            if ($r0 === null) {
                $r0 = max($filesize - $r1, 0);
                $r1 = $filesize;
            } else if ($r1 === null) {
                $r1 = $filesize;
            } else {
                $r1 = min($filesize, $r1 + 1);
            }
            if ($r0 < $r1) {
                $rs[] = [$r0, $r1];
            }
        }
        $this->range = $rs;
        return !empty($this->range);
    }

    /** @param int $status */
    static private function emit_response_code($status) {
        http_response_code($status);
    }

    /** @param string $h */
    static private function emit_header($h) {
        header($h);
    }

    private function emit_main_headers() {
        if ($this->etag !== null) {
            self::emit_header("ETag: {$this->etag}");
        }
        if ($this->last_modified !== null) {
            self::emit_header("Last-Modified: " . Navigation::http_date($this->last_modified));
        }
        if ($this->_filename !== null) {
            $attachment = $this->attachment ?? !Mimetype::disposition_inline($this->mimetype);
            self::emit_header("Content-Disposition: " . ($attachment ? "attachment" : "inline") . "; filename=" . mime_quote_string($this->_filename));
        }
        if ($this->cacheable) {
            self::emit_cacheable_headers();
        }
        foreach ($this->_headers as $h) {
            self::emit_header($h);
        }
    }

    static private function emit_cacheable_headers() {
        self::emit_header("Cache-Control: max-age=315576000, private");
        self::emit_header("Expires: " . Navigation::http_date(Conf::$now + 315576000));
    }

    /** @return int */
    function execute_conditions() {
        if ($this->if_match !== null
            && $this->etag !== null
            && !$this->any_etag_match($this->if_match, true)) {
            $status = 412;
        } else if ($this->if_none_match !== null
                   && $this->etag !== null
                   && $this->any_etag_match($this->if_none_match, false)) {
            $status = 304;
        } else if ($this->if_match === null
                   && $this->if_unmodified_since !== null
                   && $this->last_modified !== null
                   && $this->last_modified > $this->if_unmodified_since) {
            $status = 412;
        } else if ($this->if_none_match === null
                   && $this->if_modified_since !== null
                   && $this->last_modified !== null
                   && $this->last_modified <= $this->if_modified_since) {
            $status = 304;
        } else if (!$this->check_ranges()) {
            $status = 416;
        } else {
            return 200;
        }
        http_response_code($status);
        if ($status !== 412 && $this->etag !== null) {
            self::emit_header("ETag: {$this->etag}");
        }
        if ($status !== 412 && $this->cacheable) {
            self::emit_cacheable_headers();
        }
        if ($status === 416) {
            self::emit_header("Content-Range: bytes */{$this->content_length}");
        }
        return $status;
    }

    /** @return Generator<array{int,int}> */
    private function run_output_ranges() {
        assert($this->content_length !== null && $this->mimetype !== null);
        $range = $this->range;
        $rangeheader = [];
        $clen = $this->content_length;
        if ($this->head) {
            self::emit_response_code(204 /* No Content */);
            self::emit_header("Content-Type: {$this->mimetype}");
            self::emit_header("Content-Length: {$clen}");
            self::emit_header("Accept-Ranges: bytes");
            $this->emit_main_headers();
            return;
        }

        if (!isset($range)) {
            $outsize = $clen;
            self::emit_header("Content-Type: {$this->mimetype}");
            self::emit_header("Accept-Ranges: bytes");
        } else if (count($range) === 1) {
            $outsize = $range[0][1] - $range[0][0];
            self::emit_response_code(206 /* Partial Content */);
            self::emit_header("Content-Type: {$this->mimetype}");
            self::emit_header("Content-Range: bytes {$range[0][0]}-" . ($range[0][1] - 1) . "/{$clen}");
        } else {
            $boundary = "HotCRP-" . base64_encode(random_bytes(18));
            $outsize = 0;
            foreach ($range as $r) {
                $rangeheader[] = "--{$boundary}\r\nContent-Type: {$this->mimetype}\r\nContent-Range: bytes {$r[0]}-" . ($r[1] - 1) . "/{$clen}\r\n\r\n";
                $outsize += $r[1] - $r[0];
            }
            $rangeheader[] = "--{$boundary}--\r\n";
            self::emit_response_code(206 /* Partial Content */);
            self::emit_header("Content-Type: multipart/byteranges; boundary={$boundary}");
            $outsize += strlen(join("", $rangeheader));
        }
        if (!self::skip_content_length_header()) {
            self::emit_header("Content-Length: {$outsize}");
        }
        if ($outsize > 2000000) {
            self::emit_header("X-Accel-Buffering: no");
        }
        $this->emit_main_headers();
        flush();
        while (@ob_end_flush()) {
            // do nothing
        }
        if (!isset($range)) {
            yield [0, $clen];
        } else if (count($range) === 1) {
            yield [$range[0][0], $range[0][1]];
        } else {
            for ($i = 0; $i !== count($range); ++$i) {
                echo $rangeheader[$i];
                yield [$range[$i][0], $range[$i][1]];
            }
            echo $rangeheader[count($range)];
        }
    }

    /** @param ?Qrequest $qreq
     * @return int */
    function emit($qreq = null) {
        $status = $this->execute_conditions();
        if ($status !== 200) {
            return $status;
        }
        // if docstoreAccelRedirect, output X-Accel-Redirect header
        // XXX Chromium issue 961617: beware of X-Accel-Redirect if you are
        // using SameSite cookies!
        if ($this->_content_file !== null
            && !$this->no_accel
            && !$this->head
            && ($dar = Conf::$main->opt("docstoreAccelRedirect"))) {
            $this->_try_content_redirect($dar);
        }
        // check for X-Accel-Redirect
        if ($this->_content_redirect !== null) {
            self::emit_header("Content-Type: {$this->mimetype}");
            $this->emit_main_headers();
            self::emit_header("X-Accel-Redirect: {$this->_content_redirect}");
            return 200;
        }
        // write length header, flush output buffers
        $out = fopen("php://output", "wb");
        foreach ($this->run_output_ranges() as $r) {
            if ($this->_content_function !== null) {
                call_user_func($this->_content_function, $out, $r[0], $r[1]);
            } else if ($this->_content_file !== null) {
                self::readfile_subrange($out, $r[0], $r[1], 0, $this->_content_file, $this->content_length);
            } else {
                self::print_subrange($out, $r[0], $r[1], 0, $this->_content);
            }
        }
        return 200;
    }

    /** @param string|list<string> $dars */
    private function _try_content_redirect($dars) {
        $ds = Conf::$main->docstore();
        foreach (is_string($dars) ? [$dars] : $dars as $dar) {
            if (($sp = strpos($dar, " "))) {
                $root = substr($dar, $sp + 1);
                $dar = substr($dar, 0, $sp);
            } else if ($ds) {
                $root = $ds->root();
            } else {
                continue;
            }
            if (str_starts_with($this->_content_file, $root)
                && strlen($this->_content_file) > strlen($root)
                && $this->_content_file[strlen($root)] !== "/") {
                $this->_content_redirect = $dar . substr($this->_content_file, strlen($root));
                $this->_content_file = null;
                return;
            }
        }
    }


    // range handling
    /** @param resource $out
     * @param int $r0 - start of desired range
     * @param int $r1 - end of desired range
     * @param int $p0 - start of object
     * @param string $s - object
     * @return int */
    static function print_subrange($out, $r0, $r1, $p0, $s) {
        $sz = strlen($s);
        $p1 = $p0 + $sz;
        if ($p1 <= $r0 || $r1 <= $p0 || $p0 === $p1) {
            return $sz;
        } else if ($r0 <= $p0 && $p1 <= $r1) {
            return fwrite($out, $s);
        }
        $off = max(0, $r0 - $p0);
        $len = min($sz, $r1 - $p0) - $off;
        return $off + fwrite($out, substr($s, $off, $len));
    }

    /** @param resource $out
     * @param int $r0 - start of desired range
     * @param int $r1 - end of desired range
     * @param int $p0 - start of object
     * @param string $fn - object
     * @param int $sz - length of object
     * @return int */
    static function readfile_subrange($out, $r0, $r1, $p0, $fn, $sz) {
        $p1 = $p0 + $sz; // - end of object
        if ($p1 <= $r0 || $r1 <= $p0 || $p0 === $p1) {
            return $sz;
        } else if ($r0 <= $p0 && $p1 <= $r1 && $sz < 20000000) {
            return readfile($fn);
        } else if (($f = fopen($fn, "rb"))) {
            $off = max(0, $r0 - $p0);
            $len = min($sz, $r1 - $p0) - $off;
            $off += stream_copy_to_stream($f, $out, $len, $off);
            fclose($f);
            return $off;
        }
        return 0;
    }

    /** @return bool */
    static function skip_content_length_header() {
        // see also Cacheable_Page::skip_content_length_header
        return zlib_get_coding_type() !== false;
    }
}
