<?php
// filer.php -- generic document helper class
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Filer {
    /** @var ?string */
    static public $tempdir;
    /** @var ?bool */
    static public $no_touch;

    /** @return bool
     * @deprecated */
    static function skip_content_length_header() {
        return Downloader::skip_content_length_header();
    }
    /** @return non-empty-string|false
     * @deprecated */
    static function hash_as_text($hash) {
        return HashAnalysis::hash_as_text($hash);
    }
    /** @return non-empty-string|false
     * @deprecated */
    static function hash_as_binary($hash) {
        return HashAnalysis::hash_as_binary($hash);
    }
    /** @return non-empty-string|false
     * @deprecated */
    static function sha1_hash_as_text($hash) {
        return HashAnalysis::sha1_hash_as_text($hash);
    }

    /** @param ?string $pattern
     * @param null|true|Conf $conf
     * @return ?array{resource,string} */
    static function tempfile($pattern = null, $conf = null) {
        $pattern = $pattern ?? "{}";
        $tempdir = $conf ? self::docstore_tempdir($conf) : null;
        if ($tempdir === null) {
            $tempdir = self::$tempdir = self::$tempdir ?? tempdir();
        }
        if ($tempdir === null) {
            return null;
        }
        if (!str_ends_with($tempdir, "/")) {
            $tempdir .= "/";
        }
        if (($br = strpos($pattern, "%")) === false) {
            return [fopen($tempdir . $pattern, "wb+"), $tempdir . $pattern];
        }
        for ($i = 0; $i !== 100; ++$i) {
            $fn = $tempdir . sprintf($pattern, mt_rand(0, 99999999));
            if (($f = @fopen($fn, "xb+"))) {
                return [$f, $fn];
            }
        }
        return null;
    }

    /** @param resource $f
     * @param string $s
     * @return bool
     *
     * Replace the contents of `$f` with `$s`, returning `true` on success.
     * May call `clean_tempdirs()` to clean /tmp. Assumes that `$f` was
     * just opened. */
    static function tempfile_write($f, $s) {
        return fwrite($f, $s) === strlen($s)
            || (clean_tempdirs()
                && rewind($f)
                && fwrite($f, $s) === strlen($s)
                && ftruncate($f, strlen($s)));
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
        if (($flags & self::FPATH_EXISTS) !== 0) {
            if (!is_readable($path)) {
                // clean up old files saved w/o extension
                $g = self::_expand_docstore($pattern, $doc, false);
                if ($g && $g !== $path && is_readable($g)) {
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
        if (($flags & self::FPATH_MKDIR) !== 0
            && !self::prepare_docstore(self::docstore_fixed_prefix($pattern), $path)) {
            $doc->message_set()->warning_at(null, "<0>File system storage cannot be initialized");
            return null;
        } else {
            return $path;
        }
    }

    /** @param ?string $s
     * @return ?string */
    static function docstore_fixed_prefix($s) {
        if ($s === null || $s === "") {
            return null;
        }
        $pos = 0;
        while (($pos = strpos($s, "%", $pos)) !== false) {
            if ($pos === strlen($s) - 1) {
                break;
            } else if ($s[$pos + 1] === "%") {
                $s = substr($s, 0, $pos + 1) . substr($s, $pos + 2);
                $pos = $pos + 1;
            } else if (($rpos = strrpos($s, "/", $pos - strlen($s))) !== false) {
                return substr($s, 0, $rpos + 1);
            } else {
                return null;
            }
        }
        return str_ends_with($s, "/") ? $s : "{$s}/";
    }

    /** @param string $parent
     * @param string $path */
    static private function prepare_docstore($parent, $path) {
        if (!self::_make_fpath_parents($parent, $path)) {
            return false;
        }
        // Ensure an .htaccess file exists, even if someone else made the
        // filestore directory
        $htaccess = "{$parent}/.htaccess";
        if (!is_file($htaccess)
            && @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n") === false) {
            @unlink($htaccess);
            return false;
        }
        return true;
    }

    /** @return ?non-empty-string */
    static function docstore_tempdir(Conf $conf = null) {
        $conf = $conf ?? Conf::$main;
        if ($conf && ($prefix = self::docstore_fixed_prefix($conf->docstore()))) {
            $tmpdir = "{$prefix}tmp/";
            '@phan-var non-empty-string $tmpdir';
            if (self::prepare_docstore($prefix, $tmpdir)) {
                return $tmpdir;
            }
        }
        return null;
    }

    /** @return ?non-empty-string
     * @deprecated */
    static function docstore_tmpdir(Conf $conf = null) {
        return self::docstore_tempdir($conf);
    }

    /** @param string $pattern
     * @param bool $extension
     * @return ?string */
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
                    return null;
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

    /** @param string $fdir
     * @param string $fpath
     * @return ?string */
    static private function _make_fpath_parents($fdir, $fpath) {
        $lastslash = strrpos($fpath, "/");
        $container = substr($fpath, 0, $lastslash);
        while (str_ends_with($container, "/")) {
            $container = substr($container, 0, strlen($container) - 1);
        }
        if (!is_dir($container)) {
            while (str_ends_with($fdir, "/")) {
                $fdir = substr($fdir, 0, strlen($fdir) - 1);
            }
            if (strlen($container) < strlen($fdir)
                || !($parent = self::_make_fpath_parents($fdir, $container))
                || !@mkdir($container, 0770)) {
                error_log("Cannot initialize docstore directory {$container}");
                return null;
            } else if (!@chmod($container, 02770 & fileperms($parent))) {
                @rmdir($container);
                error_log("Cannot set permissions on docstore directory {$container}");
                return null;
            }
        }
        return $container;
    }
}
