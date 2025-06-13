<?php
// docstore.php -- class for managing document store
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Docstore {
    /** @var non-empty-string */
    private $_root;
    /** @var non-empty-string */
    private $_pattern;
    /** @var ?Docstore */
    private $_backup;

    /** @var ?string */
    static public $tempdir;
    /** @var bool */
    static public $no_touch = false;

    private function __construct($root, $pattern) {
        $this->_root = $root;
        $this->_pattern = $pattern;
    }

    /** @param Docstore $x
     * @return $this */
    function append_backup($x) {
        assert($x->_backup === null);
        $ds = $this;
        while ($ds->_backup) {
            $ds = $ds->_backup;
        }
        $ds->_backup = $x;
        return $this;
    }

    /** @param ?string $s
     * @param mixed $subdir
     * @return ?Docstore */
    static function make($s, $subdir = null) {
        if ($s === true) {
            $s = "docs";
        }
        if (!is_string($s) || $s === "" || str_contains($s, "//")) {
            return null;
        }
        $root = $s[0] === "/" ? "" : SiteLoader::$root . "/";
        $dpct = false;
        $pct = 0;
        while (($pct = strpos($s, "%", $pct)) !== false) {
            if ($pct === strlen($s) - 1) {
                break;
            } else if ($s[$pct + 1] === "%") {
                $dpct = true;
                $pct += 2;
                continue;
            }
            $slash = strrpos($s, "/", $pct - strlen($s));
            if ($slash === false) {
                assert($root !== "");
                return new Docstore($root, $s);
            } else {
                $rest = substr($s, 0, $slash + 1);
                $erest = $dpct ? str_replace("%%", "%", $rest) : $rest;
                return new Docstore($root . $erest, substr($s, $slash + 1));
            }
        }
        $dir = $root . ($dpct ? str_replace("%%", "%", $s) : $s);
        if ($subdir === true || (is_int($subdir) && $subdir > 0)) {
            $pattern = "%" . ($subdir === true ? 2 : $subdir) . "h/%h%x";
        } else {
            $pattern = "%h%x";
        }
        return new Docstore(str_ends_with($dir, "/") ? $dir : "{$dir}/", $pattern);
    }

    /** @return non-empty-string */
    function root() {
        return $this->_root;
    }

    /** @return non-empty-string */
    function pattern() {
        return $this->_pattern;
    }

    /** @return non-empty-string */
    function root_pattern() {
        return str_replace("%", "%%", $this->_root);
    }

    /** @return non-empty-string */
    function full_pattern() {
        return str_replace("%", "%%", $this->_root) . $this->_pattern;
    }

    /** @return ?string */
    function expand(DocumentInfo $doc) {
        $x = "";
        $hash = false;
        $offset = 0;
        while (preg_match('/\G(.*?)%(\d*)([%hxHjaA])/', $this->_pattern, $m, 0, $offset)) {
            $x .= $m[1];
            $fwidth = $m[2];
            $fn = $m[3];
            $offset += strlen($m[0]);
            if ($fn === "%") {
                $x .= "%";
            } else if ($fn === "x") {
                $x .= Mimetype::extension($doc->mimetype);
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
                    $lp = $rp = 0;
                    if (strlen($hash) !== 40) {
                        $rp = strpos($hash, "-") + 1;
                        if ($fn !== "h") {
                            $lp = $rp;
                        }
                    }
                    if ($fwidth === "") {
                        $rp = strlen($hash);
                    } else {
                        $rp = min(strlen($hash), $rp + intval($fwidth));
                    }
                    $x .= substr($hash, $lp, $rp - $lp);
                }
            }
        }
        return $x . substr($this->_pattern, $offset);
    }

    // filestore path functions
    const FPATH_EXISTS = 1;
    const FPATH_MKDIR = 2;
    /** @param string $file
     * @param int $flags
     * @return ?string */
    function path($file, $flags = 0) {
        $path = $this->root() . $file;
        if (($flags & self::FPATH_EXISTS) !== 0) {
            if (is_readable($path)) {
                $rpath = $path;
            } else if ($this->_backup) {
                $rpath = $this->_backup->path($file, $flags);
            } else {
                $rpath = null;
            }
            if ($rpath === null) {
                return null;
            }
            $path = $rpath;
            if (!self::$no_touch
                && filemtime($path) < Conf::$now - 172800) {
                @touch($path, Conf::$now);
            }
        }
        if (($flags & self::FPATH_MKDIR) !== 0
            && !$this->prepare_parent($file)) {
            return null;
        }
        return $path;
    }

    /** @param int $flags
     * @return ?string */
    function path_for(DocumentInfo $doc, $flags = 0) {
        if ($doc->has_error()
            || !($file = $this->expand($doc))) {
            return null;
        }
        $path = $this->path($file, $flags);
        if ($path === null && ($flags & self::FPATH_MKDIR) !== 0) {
            $doc->message_set()->warning_at(null, "<0>File system storage cannot be initialized");
        }
        return $path;
    }

    /** @return ?string */
    function tempdir() {
        return $this->prepare("tmp") ? $this->_root . "tmp/" : null;
    }

    /** @param string $name
     * @param string $template
     * @return ?resource */
    function open_tempfile($name, $template) {
        if ($name === null
            || !preg_match('/\A[-_A-Za-z0-9]*%s(?:\.[A-Za-z0-9]+|)\z/', $template)
            || !preg_match('/\A' . str_replace(".", '\\.', str_replace("%s", '\w{10,}', $template)) . '\z/', $name)) {
            return null;
        }
        if ($this->prepare("tmp")
            && ($f = @fopen("{$this->_root}tmp/{$name}", "rb"))) {
            return $f;
        }
        if ($this->_backup
            && ($fn = $this->_backup->path("tmp/{$name}", self::FPATH_EXISTS))
            && ($f = @fopen($fn, "rb"))) {
            return $f;
        }
        return null;
    }

    /** @param string $subdir
     * @return bool */
    private function prepare($subdir) {
        $path = $this->_root . $subdir;
        if (is_dir($path)) {
            return true;
        }
        if (($slash = strrpos($subdir, "/")) !== false
            && !$this->prepare(substr($subdir, 0, $slash))) {
            return false;
        }
        while (str_ends_with($path, "/")) {
            $path = substr($path, 0, -1);
        }
        if (!@mkdir($path, 0770)) {
            error_log("Cannot initialize docstore directory {$path}");
            return false;
        } else if (!@chmod($path, 02770 & fileperms($this->_root))) {
            @rmdir($path);
            error_log("Cannot set permissions on docstore directory {$path}");
            return false;
        } else if ($subdir === ""
                   && @file_put_contents("{$path}/.htaccess", "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n") === false) {
            @unlink("{$path}/.htaccess");
            @rmdir($path);
            return false;
        }
        return true;
    }

    /** @param string $file
     * @return bool */
    private function prepare_parent($file) {
        $slash = strrpos($file, "/");
        return $this->prepare($slash > 0 ? substr($file, 0, $slash) : "");
    }
}
