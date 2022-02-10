<?php
// filer.php -- generic document helper class
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class HashAnalysis {
    /** @var string */
    private $prefix;
    /** @var ?string */
    private $hash;
    /** @var ?bool */
    private $binary;

    /** @param string $hash */
    function __construct($hash) {
        $len = strlen($hash);
        if ($len === 37
            && strcasecmp(substr($hash, 0, 5), "sha2-") == 0) {
            $this->prefix = "sha2-";
            $this->hash = substr($hash, 5);
            $this->binary = true;
        } else if ($len === 20) {
            $this->prefix = "";
            $this->hash = $hash;
            $this->binary = true;
        } else if ($len === 69
                   && strcasecmp(substr($hash, 0, 5), "sha2-") == 0
                   && ctype_xdigit(substr($hash, 5))) {
            $this->prefix = "sha2-";
            $this->hash = substr($hash, 5);
            $this->binary = false;
        } else if ($len === 40
                   && ctype_xdigit($hash)) {
            $this->prefix = "";
            $this->hash = $hash;
            $this->binary = false;
        } else if ($len === 32) {
            $this->prefix = "sha2-";
            $this->hash = $hash;
            $this->binary = true;
        } else if ($len === 64
                   && ctype_xdigit($hash)) {
            $this->prefix = "sha2-";
            $this->hash = $hash;
            $this->binary = false;
        } else if ($len === 25
                   && strcasecmp(substr($hash, 0, 5), "sha1-") == 0) {
            $this->prefix = "";
            $this->hash = substr($hash, 5);
            $this->binary = true;
        } else if ($len === 45
                   && strcasecmp(substr($hash, 0, 5), "sha1-") == 0
                   && ctype_xdigit(substr($hash, 5))) {
            $this->prefix = "";
            $this->hash = substr($hash, 5);
            $this->binary = false;
        } else {
            if ($hash === "") {
                $this->prefix = "";
            } else if ($hash === "sha256") {
                $this->prefix = "sha2-";
            } else {
                $this->prefix = "xxx-";
            }
        }
    }

    /** @param ?string $algo
     * @return HashAnalysis */
    static function make_known_algorithm($algo) {
        $ha = new HashAnalysis("");
        if ($algo === "sha1") {
            $ha->prefix = "";
        } else {
            $ha->prefix = "sha2-";
        }
        return $ha;
    }

    /** @return bool */
    function ok() {
        return $this->hash !== null;
    }
    /** @return bool */
    function known_algorithm() {
        return $this->prefix !== "xxx-";
    }
    /** @return string */
    function algorithm() {
        if ($this->prefix === "sha2-") {
            return "sha256";
        } else if ($this->prefix === "") {
            return "sha1";
        } else {
            return "xxx";
        }
    }
    /** @return string */
    function prefix() {
        return $this->prefix;
    }
    /** @return string */
    function text() {
        return $this->prefix . ($this->binary ? bin2hex($this->hash) : strtolower($this->hash));
    }
    /** @return string */
    function binary() {
        return $this->prefix . ($this->binary ? $this->hash : hex2bin($this->hash));
    }
    /** @return string */
    function text_data() {
        return $this->binary ? bin2hex($this->hash) : strtolower($this->hash);
    }
}

class Filer {
    static public $tempdir;
    static public $tempcounter = 0;
    static public $no_touch;

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

    /** @param int $filesize
     * @param array &$opts
     * @return bool */
    static function check_download_opts($filesize, &$opts) {
        if (isset($opts["if-range"])
            && ($opts["etag"] === null || $opts["if-range"] !== $opts["etag"])) {
            unset($opts["range"]);
        }
        if (isset($opts["range"])) {
            $rs = [];
            foreach ($opts["range"] as $r) {
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
                header("Content-Range: bytes */$filesize");
                return false;
            }
            $opts["range"] = $rs;
        }
        return true;
    }

    /** @param int $filesize
     * @param string $mimetype
     * @param array $opts */
    static function download_ranges($filesize, $mimetype, $opts) {
        if (isset($opts["etag"])) {
            header("ETag: " . $opts["etag"]);
        }
        $range = $opts["range"] ?? null;
        $rangeheader = [];
        if ($opts["head"] ?? false) {
            header("HTTP/1.1 204 No Content");
            header("Content-Type: $mimetype");
            header("Content-Length: $filesize");
            header("Accept-Ranges: bytes");
            return;
        } else if (!isset($range)) {
            $outsize = $filesize;
            header("Content-Type: $mimetype");
            header("Accept-Ranges: bytes");
        } else if (count($range) === 1) {
            $outsize = $range[0][1] - $range[0][0];
            header("HTTP/1.1 206 Partial Content");
            header("Content-Type: $mimetype");
            header("Content-Range: bytes {$range[0][0]}-" . ($range[0][1] - 1) . "/$filesize");
        } else {
            $boundary = "HotCRP-" . base64_encode(random_bytes(18));
            $outsize = 0;
            foreach ($range as $r) {
                $rangeheader[] = "--$boundary\r\nContent-Type: $mimetype\r\nContent-Range: bytes {$r[0]}-" . ($r[1] - 1) . "/$filesize\r\n\r\n";
                $outsize += $r[1] - $r[0];
            }
            $rangeheader[] = "--$boundary--\r\n";
            header("HTTP/1.1 206 Partial Content");
            header("Content-Type: multipart/byteranges; boundary=$boundary");
            $outsize += strlen(join("", $rangeheader));
        }
        if (zlib_get_coding_type() === false) {
            header("Content-Length: $outsize");
        }
        if ($outsize > 2000000) {
            header("X-Accel-Buffering: no");
        }
        flush();
        while (@ob_end_flush()) {
            // do nothing
        }
        if (!isset($range)) {
            yield [0, $filesize];
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

    /** @param string $filename
     * @param string $mimetype
     * @param array $opts */
    static function download_file($filename, $mimetype, $opts = []) {
        // if docstoreAccelRedirect, output X-Accel-Redirect header
        // XXX Chromium issue 961617: beware of X-Accel-Redirect if you are
        // using SameSite cookies!
        $filesize = filesize($filename);
        if (self::check_download_opts($filesize, $opts)) {
            if (($dar = Conf::$main->opt("docstoreAccelRedirect"))
                && ($dsp = self::docstore_fixed_prefix(Conf::$main->docstore()))
                && !($opts["no_accel"] ?? false)
                && !($opts["head"] ?? false)) {
                assert(str_ends_with($dsp, "/"));
                if (str_starts_with($filename, $dsp)
                    && strlen($filename) > strlen($dsp)
                    && $filename[strlen($dsp)] !== "/") {
                    if (isset($opts["etag"])) {
                        header("ETag: " . $opts["etag"]);
                    }
                    header("Content-Type: $mimetype");
                    header("X-Accel-Redirect: $dar" . substr($filename, strlen($dsp)));
                    return;
                }
            }
            // write length header, flush output buffers
            $out = fopen("php://output", "wb");
            foreach (self::download_ranges($filesize, $mimetype, $opts) as $r) {
                Filer::readfile_subrange($out, $r[0], $r[1], 0, $filename, $filesize);
            }
        }
    }

    /** @param string $s
     * @param string $mimetype
     * @param array $opts */
    static function download_string($s, $mimetype, $opts = []) {
        if (self::check_download_opts(strlen($s), $opts)) {
            $out = fopen("php://output", "wb");
            foreach (self::download_ranges(strlen($s), $mimetype, $opts) as $r) {
                Filer::print_subrange($out, $r[0], $r[1], 0, $s);
            }
        }
    }

    // hash helpers
    static function hash_as_text($hash) {
        assert(is_string($hash));
        $ha = new HashAnalysis($hash);
        return $ha->ok() ? $ha->text() : false;
    }
    static function hash_as_binary($hash) {
        assert(is_string($hash));
        $ha = new HashAnalysis($hash);
        return $ha->ok() ? $ha->binary() : false;
    }
    static function sha1_hash_as_text($str) {
        $ha = new HashAnalysis($str);
        return $ha->ok() && $ha->prefix() === "" ? $ha->text() : false;
    }

    // filestore path functions
    const FPATH_EXISTS = 1;
    const FPATH_MKDIR = 2;
    /** @param int $flags
     * @return ?string */
    static function docstore_path(DocumentInfo $doc, $flags = 0) {
        if ($doc->has_error() || !($pattern = $doc->conf->docstore())) {
            return null;
        }
        if (!($path = self::_expand_docstore($pattern, $doc, true))) {
            return null;
        }
        if ($flags & self::FPATH_EXISTS) {
            if (!is_readable($path)) {
                // clean up presence of old files saved w/o extension
                $g = self::_expand_docstore($pattern, $doc, false);
                if ($path && $g !== $path && is_readable($g)) {
                    if (!@rename($g, $path)) {
                        $path = $g;
                    }
                } else {
                    return null;
                }
            }
            if (filemtime($path) < Conf::$now - 172800 && !self::$no_touch) {
                @touch($path, Conf::$now);
            }
        }
        if (($flags & self::FPATH_MKDIR)
            && !self::prepare_docstore(self::docstore_fixed_prefix($pattern), $path)) {
            $doc->message_set()->warning_at(null, "<0>File system storage cannot be initialized");
            return null;
        } else {
            return $path;
        }
    }

    /** @param ?string $pattern
     * @return ?string */
    static function docstore_fixed_prefix($pattern) {
        if ($pattern === null || $pattern === "") {
            return null;
        }
        $prefix = "";
        while (($pos = strpos($pattern, "%")) !== false) {
            if ($pos == strlen($pattern) - 1) {
                break;
            } else if ($pattern[$pos + 1] === "%") {
                $prefix .= substr($pattern, 0, $pos + 1);
                $pattern = substr($pattern, $pos + 2);
            } else {
                $prefix .= substr($pattern, 0, $pos);
                if (($rslash = strrpos($prefix, "/")) !== false) {
                    return substr($prefix, 0, $rslash + 1);
                } else {
                    return null;
                }
            }
        }
        $prefix .= $pattern;
        if ($prefix[strlen($prefix) - 1] !== "/") {
            $prefix .= "/";
        }
        return $prefix;
    }

    /** @param string $parent
     * @param string $path */
    static private function prepare_docstore($parent, $path) {
        if (!self::_make_fpath_parents($parent, $path)) {
            return false;
        }
        // Ensure an .htaccess file exists, even if someone else made the
        // filestore directory
        $htaccess = "$parent/.htaccess";
        if (!is_file($htaccess)
            && @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n") === false) {
            @unlink($htaccess);
            return false;
        }
        return true;
    }

    /** @return ?non-empty-string */
    static function docstore_tmpdir(Conf $conf = null) {
        $conf = $conf ?? Conf::$main;
        if (($prefix = self::docstore_fixed_prefix($conf->docstore()))) {
            $tmpdir = $prefix . "tmp/";
            '@phan-var non-empty-string $tmpdir';
            if (self::prepare_docstore($prefix, $tmpdir)) {
                return $tmpdir;
            }
        }
        return null;
    }

    static private function _expand_docstore($pattern, DocumentInfo $doc, $extension) {
        $x = "";
        $hash = false;
        while (preg_match('/\A(.*?)%(\d*)([%hxHjaA])(.*)\z/', $pattern, $m)) {
            $x .= $m[1];
            list($fwidth, $fn, $pattern) = [$m[2], $m[3], $m[4]];
            if ($fn === "%") {
                $x .= "%";
            } else if ($fn === "x") {
                if ($extension) {
                    $x .= Mimetype::extension($doc->mimetype);
                }
            } else {
                if ($hash === false
                    && ($hash = $doc->text_hash()) === false) {
                    return false;
                }
                if ($fn === "h" && $fwidth === "") {
                    $x .= $hash;
                } else if ($fn === "a") {
                    $x .= $doc->hash_algorithm();
                } else if ($fn === "A") {
                    $x .= $doc->hash_algorithm_prefix();
                } else if ($fn === "j") {
                    $x .= substr($hash, 0, strlen($hash) === 40 ? 2 : 3);
                } else {
                    $h = $hash;
                    if (strlen($h) !== 40) {
                        $pos = strpos($h, "-") + 1;
                        if ($fn === "h") {
                            $x .= substr($h, 0, $pos);
                        }
                        $h = substr($h, $pos);
                    }
                    if ($fwidth === "") {
                        $x .= $h;
                    } else {
                        $x .= substr($h, 0, intval($fwidth));
                    }
                }
            }
        }
        return $x . $pattern;
    }

    static private function _make_fpath_parents($fdir, $fpath) {
        $lastslash = strrpos($fpath, "/");
        $container = substr($fpath, 0, $lastslash);
        while (str_ends_with($container, "/")) {
            $container = substr($container, 0, strlen($container) - 1);
        }
        if (!is_dir($container)) {
            if (strlen($container) < strlen($fdir)
                || !($parent = self::_make_fpath_parents($fdir, $container))
                || !@mkdir($container, 0770)) {
                return false;
            } else if (!@chmod($container, 02770 & fileperms($parent))) {
                @rmdir($container);
                return false;
            }
        }
        return $container;
    }
}
