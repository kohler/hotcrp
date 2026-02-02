<?php
// downloader.php -- download helper class
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Downloader {
    /** @var ?string
     * @readonly */
    public $etag;
    /** @var ?int
     * @readonly */
    public $last_modified;
    /** @var ?int
     * @readonly */
    public $content_length;
    /** @var ?string
     * @readonly */
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
    /** @var ?int */
    public $timestamp;
    /** @var ?Contact */
    public $log_user;

    /** @var ?Conf */
    private $conf;
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
    /** @var ?string */
    private $_boundary;
    /** @var ?int */
    private $_response_code;
    /** @var HeaderSet */
    private $_headers;

    function __construct(?Conf $conf = null) {
        $this->conf = $conf;
        $this->_headers = new HeaderSet;
    }

    /** @return $this */
    function parse_qreq(Qrequest $qreq) {
        $this->conf = $qreq->conf();

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
        $this->head = $method === "HEAD";
        if ($method === "GET") {
            $this->if_range = $qreq->header("If-Range");
            $this->_parse_range($qreq->header("Range"));
        }

        return $this;
    }

    /** @param ?string $range */
    private function _parse_range($range) {
        if ($range === null
            || !preg_match('/\Abytes\s*=\s*(?:(?:\d+-\d+|-\d+|\d+-)\s*,?\s*)+\z/', $range)) {
            $this->range = null;
            return;
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
                return;
            }
        }
    }

    /** @return bool */
    function has_content() {
        return $this->_content !== null
            || $this->_content_file !== null
            || $this->_content_redirect !== null
            || $this->_content_function !== null;
    }

    /** @param string $mimetype
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
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
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_content_length($content_length) {
        $this->content_length = $content_length;
        return $this;
    }

    /** @param string $content
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_content($content) {
        assert(!$this->has_content());
        $this->_content = $content;
        $this->content_length = strlen($content);
        return $this;
    }

    /** @param string $content_file
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
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
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_etag($etag) {
        $this->etag = $etag;
        return $this;
    }

    /** @param ?int $last_modified
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
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

    /** @param string $header
     * @param bool $replace
     * @return $this */
    function set_header($header, $replace = true) {
        $this->_headers->set($header, $replace);
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
        if (empty($rs)) {
            return false;
        }
        $this->range = $rs === [[0, $filesize]] ? null : $rs;
        return true;
    }

    /** @param Downloader $dl
     * @return ?string */
    static function content_security_policy_for($dl) {
        if ($dl->mimetype === "image/svg+xml"
            || $dl->mimetype === "text/html"
            || $dl->mimetype === "application/xhtml+xml") {
            return "default-src 'none'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://*.typekit.net; img-src 'self' data: https://*.typekit.net; font-src data: https://fonts.gstatic.com https://*.typekit.net; sandbox";
        }
        return null;
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

    /** @return int */
    function response_code() {
        if ($this->_response_code !== null) {
            return $this->_response_code;
        }
        if ($this->if_match !== null
            && $this->etag !== null
            && !$this->any_etag_match($this->if_match, true)) {
            $this->_response_code = 412; // Precondition Failed
        } else if ($this->if_none_match !== null
                   && $this->etag !== null
                   && $this->any_etag_match($this->if_none_match, false)) {
            $this->_response_code = 304; // Not Modified
        } else if ($this->if_match === null
                   && $this->if_unmodified_since !== null
                   && $this->last_modified !== null
                   && $this->last_modified > $this->if_unmodified_since) {
            $this->_response_code = 412; // Precondition Failed
        } else if ($this->if_none_match === null
                   && $this->if_modified_since !== null
                   && $this->last_modified !== null
                   && $this->last_modified <= $this->if_modified_since) {
            $this->_response_code = 304; // Not Modified
        } else if (!$this->check_ranges()) {
            $this->_response_code = 416; // Range Not Satisfiable
        } else {
            // check docstoreAccelRedirect
            // XXX Chromium issue 961617: beware of X-Accel-Redirect if you are
            // using SameSite cookies!
            if ($this->_content_file !== null
                && !$this->no_accel
                && !$this->head
                && ($dar = Conf::$main->opt("docstoreAccelRedirect"))) {
                $this->_try_content_redirect($dar);
            }
            if ($this->range !== null
                && $this->_content_redirect === null) {
                $this->_response_code = 206; // Partial Content
            } else {
                $this->_response_code = 200; // OK
            }
        }
        return $this->_response_code;
    }

    /** @param ?int $index
     * @param ?array{int,int} $range
     * @return string */
    private function _range_separator($index, $range) {
        assert($this->_boundary !== null);
        $pfx = $index === 0 ? "" : "\r\n";
        if ($range === null) {
            return "{$pfx}--{$this->_boundary}--\r\n";
        }
        $rb = $range[1] - 1;
        return "{$pfx}--{$this->_boundary}\r\nContent-Type: {$this->mimetype}\r\nContent-Range: bytes {$range[0]}-{$rb}/{$this->content_length}\r\n\r\n";
    }

    /** @return Generator<string> */
    function headers() {
        $rc = $this->response_code();
        if ($this->etag !== null) {
            yield "ETag" => $this->etag;
        }
        if ($this->last_modified !== null) {
            yield "Last-Modified" => Navigation::http_date($this->last_modified);
        }
        if ($this->cacheable) {
            $this->timestamp = $this->timestamp ?? Conf::$now;
            yield "Cache-Control" => "max-age=315576000, private";
            yield "Expires" => Navigation::http_date($this->timestamp + 315576000);
        }
        if ($rc === 416) {
            assert($this->content_length !== null);
            yield "Content-Range" => "bytes */{$this->content_length}";
        }
        if ($rc >= 300) {
            return;
        }
        assert($rc === 200 || $rc === 206);
        assert($this->content_length !== null && $this->mimetype !== null);
        if ($rc === 200) {
            yield "Content-Type" => $this->mimetype;
            if ($this->_content_redirect === null) {
                yield "Accept-Ranges" => "bytes";
            }
        } else if (count($this->range) === 1) {
            $ra = $this->range[0][0];
            $rb = $this->range[0][1] - 1;
            yield "Content-Type" => $this->mimetype;
            yield "Content-Range" => "bytes {$ra}-{$rb}/{$this->content_length}";
        } else {
            $this->_boundary = $this->_boundary ?? "hcmpb-" . base64_encode(random_bytes(32));
            yield "Content-Type" => "multipart/byteranges; boundary={$this->_boundary}";
        }
        if ($this->_filename !== null
            && !$this->_headers->has("Content-Disposition")) {
            $this->attachment = $this->attachment ?? !Mimetype::disposition_inline($this->mimetype);
            $a = $this->attachment ? "attachment" : "inline";
            yield "Content-Disposition" => "{$a}; filename=" . mime_quote_string($this->_filename);
        }
        if (!$this->_headers->has("Content-Security-Policy")
            && ($conf = $this->conf ?? Conf::$main)
            && ($cspf = $conf->opt("downloadContentSecurityPolicyFunction")) !== false) {
            $cspf = $cspf ?? "Downloader::content_security_policy_for";
            if (($csph = call_user_func($cspf, $this))) {
                yield "Content-Security-Policy" => $csph;
            }
        }
        if ($this->_content_redirect !== null
            || (!$this->head && self::skip_content_length_header())) {
            $bs = null;
        } else if ($this->range === null) {
            $bs = $this->content_length;
        } else if (count($this->range) === 1) {
            $bs = $this->range[0][1] - $this->range[0][0];
        } else {
            $bs = 0;
            foreach ($this->range as $i => $r) {
                $bs += $r[1] - $r[0] + strlen($this->_range_separator($i, $r));
            }
            $bs += strlen($this->_range_separator(count($this->range), null));
        }
        if ($bs !== null) {
            yield "Content-Length" => "{$bs}"; // require string
        }
        if ($bs !== null && $bs > 2000000) {
            yield "X-Accel-Buffering" => "no";
        }
        yield from $this->_headers->by_name();
        if ($this->_content_redirect !== null) {
            yield "X-Accel-Redirect" => $this->_content_redirect;
        }
    }

    /** @param string $name
     * @return ?string */
    function header($name) {
        foreach ($this->headers() as $n => $v) {
            if (strcasecmp($name, $n) === 0)
                return $v;
        }
        return null;
    }

    /** @param ?resource $outf
     * @return Generator<array{int,int}> */
    function output_ranges($outf = null) {
        if ($this->range === null) {
            yield [0, $this->content_length];
        } else if (count($this->range) === 1) {
            yield $this->range[0];
        } else {
            foreach ($this->range as $i => $r) {
                if ($outf) {
                    fwrite($outf, $this->_range_separator($i, $r));
                }
                yield $r;
            }
            if ($outf) {
                fwrite($outf, $this->_range_separator(count($this->range), null));
            }
        }
    }

    /** @return int */
    function emit() {
        http_response_code($this->response_code());
        // we never want the default CSP for a download
        header_remove("Content-Security-Policy");
        foreach ($this->headers() as $k => $v) {
            header($k === "" ? $v : "{$k}: {$v}");
        }
        if ($this->_response_code >= 300
            || $this->_content_redirect !== null
            || $this->head) {
            return $this->_response_code;
        }
        flush();
        while (@ob_end_flush()) {
            // do nothing
        }
        $out = fopen("php://output", "wb");
        foreach ($this->output_ranges($out) as $r) {
            if ($this->_content_function !== null) {
                call_user_func($this->_content_function, $out, $r[0], $r[1]);
            } else if ($this->_content_file !== null) {
                self::readfile_subrange($out, $r[0], $r[1], 0, $this->_content_file, $this->content_length);
            } else {
                self::print_subrange($out, $r[0], $r[1], 0, $this->_content);
            }
        }
        return $this->_response_code;
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
