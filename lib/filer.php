<?php
// filer.php -- generic document helper class
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class HashAnalysis {
    /** @var string */
    private $prefix;
    /** @var ?non-empty-string */
    private $hash;
    /** @var ?bool */
    private $binary;

    /** @param string $hash */
    function __construct($hash) {
        $len = strlen($hash);
        if ($len === 37
            && strcasecmp(substr($hash, 0, 5), "sha2-") === 0) {
            $this->prefix = "sha2-";
            $this->hash = substr($hash, 5);
            $this->binary = true;
        } else if ($len === 20) {
            $this->prefix = "";
            $this->hash = $hash;
            $this->binary = true;
        } else if ($len === 69
                   && strcasecmp(substr($hash, 0, 5), "sha2-") === 0
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
                   && strcasecmp(substr($hash, 0, 5), "sha1-") === 0) {
            $this->prefix = "";
            $this->hash = substr($hash, 5);
            $this->binary = true;
        } else if ($len === 45
                   && strcasecmp(substr($hash, 0, 5), "sha1-") === 0
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
    /** @return non-empty-string */
    function text() {
        return $this->prefix . ($this->binary ? bin2hex($this->hash) : strtolower($this->hash));
    }
    /** @return non-empty-string */
    function binary() {
        return $this->prefix . ($this->binary ? $this->hash : hex2bin($this->hash));
    }
    /** @return non-empty-string */
    function text_data() {
        return $this->binary ? bin2hex($this->hash) : strtolower($this->hash);
    }
}

class Filer {
    static public $tempdir;
    static public $tempcounter = 0;
    static public $no_touch;

    /** @return bool
     * @deprecated */
    static function skip_content_length_header() {
        return Downloader::skip_content_length_header();
    }

    // hash helpers
    /** @return non-empty-string|false */
    static function hash_as_text($hash) {
        assert(is_string($hash));
        $ha = new HashAnalysis($hash);
        return $ha->ok() ? $ha->text() : false;
    }
    /** @return non-empty-string|false */
    static function hash_as_binary($hash) {
        assert(is_string($hash));
        $ha = new HashAnalysis($hash);
        return $ha->ok() ? $ha->binary() : false;
    }
    /** @return non-empty-string|false */
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
        $htaccess = "{$parent}/.htaccess";
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
            $tmpdir = "{$prefix}tmp/";
            '@phan-var non-empty-string $tmpdir';
            if (self::prepare_docstore($prefix, $tmpdir)) {
                return $tmpdir;
            }
        }
        return null;
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
        while (str_ends_with($fdir, "/")) {
            $container = substr($fdir, 0, strlen($fdir) - 1);
        }
        if (!is_dir($container)) {
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
