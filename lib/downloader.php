<?php
// downloader.php -- download helper class
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Downloader {
    /** @var ?string */
    public $etag;
    /** @var ?int */
    public $content_length;
    /** @var ?string */
    public $mimetype;

    /** @var ?string */
    public $if_match;
    /** @var ?string */
    public $if_none_match;
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

    /** @return Downloader */
    static function make_server_request() {
        $dopt = new Downloader;
        $dopt->if_match = $_SERVER["HTTP_IF_MATCH"] ?? null;
        $dopt->if_none_match = $_SERVER["HTTP_IF_NONE_MATCH"] ?? null;
        $method = $_SERVER["REQUEST_METHOD"];
        if ($method === "HEAD") {
            $dopt->head = true;
        } else if ($method === "GET") {
            $dopt->if_range = $_SERVER["HTTP_IF_RANGE"] ?? null;
        }
        if ($method === "GET"
            && ($range = $_SERVER["HTTP_RANGE"] ?? null) !== null
            && preg_match('/\Abytes\s*=\s*(?:(?:\d+-\d+|-\d+|\d+-)\s*,?\s*)+\z/', $range)) {
            $dopt->range = [];
            $lastr = null;
            preg_match_all('/\d+-\d+|-\d+|\d+-/', $range, $m);
            foreach ($m[0] as $t) {
                $dash = strpos($t, "-");
                $r1 = $dash === 0 ? null : intval(substr($t, 0, $dash));
                $r2 = $dash === strlen($t) - 1 ? null : intval(substr($t, $dash + 1));
                if ($r1 === null && $r2 !== 0) {
                    $dopt->range[] = $lastr = [$r1, $r2];
                } else if ($r2 === null || ($r1 !== null && $r1 <= $r2)) {
                    if ($lastr !== null
                        && $lastr[0] !== null
                        && $lastr[1] !== null
                        && $r1 >= $lastr[0]
                        && $r1 - $lastr[1] <= 100) {
                        $nr = count($dopt->range);
                        $dopt->range[$nr - 1][1] = $lastr[1] = $r2;
                    } else {
                        $dopt->range[] = $lastr = [$r1, $r2];
                    }
                } else {
                    $dopt->range = null;
                    break;
                }
            }
        }
        return $dopt;
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
    function run_match() {
        if ($this->etag === null) {
            return false;
        } else if ($this->if_match !== null
                   && !$this->any_etag_match($this->if_match, true)) {
            header("HTTP/1.1 412 Precondition Failed");
            header("ETag: {$this->etag}");
            return true;
        } else if ($this->if_none_match !== null
                   && $this->any_etag_match($this->if_none_match, false)) {
            header("HTTP/1.1 304 Not Modified");
            header("ETag: {$this->etag}");
            return true;
        } else {
            return false;
        }
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
    function run_range_check() {
        assert($this->content_length !== null);
        $filesize = $this->content_length;
        if ($this->if_range !== null
            && !self::etag_equals($this->if_range, $this->etag, true)) {
            $this->range = null;
        }
        if ($this->range !== null) {
            $rs = [];
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
                header("HTTP/1.1 416 Range Not Satisfiable");
                header("Content-Range: bytes */{$filesize}");
                return true;
            }
            $this->range = $rs;
        }
        return false;
    }

    /** @return Generator<array{int,int}> */
    function run_output_ranges() {
        assert($this->content_length !== null && $this->mimetype !== null);
        if ($this->etag !== null) {
            header("ETag: {$this->etag}");
        }
        $range = $this->range;
        $rangeheader = [];
        $clen = $this->content_length;
        if ($this->head) {
            header("HTTP/1.1 204 No Content");
            header("Content-Type: {$this->mimetype}");
            header("Content-Length: {$clen}");
            header("Accept-Ranges: bytes");
            return;
        } else if (!isset($range)) {
            $outsize = $clen;
            header("Content-Type: {$this->mimetype}");
            header("Accept-Ranges: bytes");
        } else if (count($range) === 1) {
            $outsize = $range[0][1] - $range[0][0];
            header("HTTP/1.1 206 Partial Content");
            header("Content-Type: {$this->mimetype}");
            header("Content-Range: bytes {$range[0][0]}-" . ($range[0][1] - 1) . "/{$clen}");
        } else {
            $boundary = "HotCRP-" . base64_encode(random_bytes(18));
            $outsize = 0;
            foreach ($range as $r) {
                $rangeheader[] = "--{$boundary}\r\nContent-Type: {$this->mimetype}\r\nContent-Range: bytes {$r[0]}-" . ($r[1] - 1) . "/{$clen}\r\n\r\n";
                $outsize += $r[1] - $r[0];
            }
            $rangeheader[] = "--{$boundary}--\r\n";
            header("HTTP/1.1 206 Partial Content");
            header("Content-Type: multipart/byteranges; boundary={$boundary}");
            $outsize += strlen(join("", $rangeheader));
        }
        if (!self::skip_content_length_header()) {
            header("Content-Length: {$outsize}");
        }
        if ($outsize > 2000000) {
            header("X-Accel-Buffering: no");
        }
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

    /** @param string $filename */
    function output_file($filename) {
        // if docstoreAccelRedirect, output X-Accel-Redirect header
        // XXX Chromium issue 961617: beware of X-Accel-Redirect if you are
        // using SameSite cookies!
        $this->content_length = filesize($filename);
        if ($this->run_range_check()) {
            return;
        }
        if (($dar = Conf::$main->opt("docstoreAccelRedirect"))
            && ($dsp = Filer::docstore_fixed_prefix(Conf::$main->docstore()))
            && !$this->no_accel
            && !$this->head) {
            assert(str_ends_with($dsp, "/"));
            if (str_starts_with($filename, $dsp)
                && strlen($filename) > strlen($dsp)
                && $filename[strlen($dsp)] !== "/") {
                if (isset($this->etag)) {
                    header("ETag: {$this->etag}");
                }
                header("Content-Type: {$this->mimetype}");
                header("X-Accel-Redirect: {$dar}" . substr($filename, strlen($dsp)));
                return;
            }
        }
        // write length header, flush output buffers
        $out = fopen("php://output", "wb");
        foreach ($this->run_output_ranges() as $r) {
            self::readfile_subrange($out, $r[0], $r[1], 0, $filename, $this->content_length);
        }
    }

    /** @param string $s */
    function output_string($s) {
        $this->content_length = strlen($s);
        if ($this->run_range_check()) {
            return;
        }
        $out = fopen("php://output", "wb");
        foreach ($this->run_output_ranges() as $r) {
            self::print_subrange($out, $r[0], $r[1], 0, $s);
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
        } else {
            $off = max(0, $r0 - $p0);
            $len = min($sz, $r1 - $p0) - $off;
            return $off + fwrite($out, substr($s, $off, $len));
        }
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
        } else {
            return 0;
        }
    }

    /** @return bool */
    static function skip_content_length_header() {
        // see also Cacheable_Page::skip_content_length_header
        return zlib_get_coding_type() !== false;
    }
}
