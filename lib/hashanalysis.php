<?php
// hashanalysis.php -- analyze hashes for algorithm, binary translation
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class HashAnalysis {
    /** @var string */
    private $prefix;
    /** @var ?non-empty-string */
    private $hash;
    /** @var ?bool */
    private $binary;

    /** @param ?string $hash */
    function __construct($hash = null) {
        $this->assign($hash);
    }

    /** @param ?string $hash */
    function assign($hash) {
        $len = strlen($hash ?? "");
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
        } else if ($hash === "sha256") {
            $this->prefix = "sha2-";
            $this->hash = null;
        } else if ($hash === "sha1") {
            $this->prefix = "";
            $this->hash = null;
        } else {
            $this->prefix = "xxx-";
            $this->hash = null;
        }
    }

    /** @param Conf $conf
     * @param ?string $like
     * @return HashAnalysis */
    static function make_algorithm($conf, $like = null) {
        $ha = new HashAnalysis($like);
        if ($ha->prefix === "xxx-") {
            $algo = $conf->content_hash_algorithm();
            $ha->prefix = $algo === "sha1" ? "" : "sha2-";
        }
        $ha->hash = null;
        return $ha;
    }

    /** @return bool */
    function ok() {
        return $this->hash !== null;
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

    function clear_hash() {
        $this->hash = null;
    }
    /** @param string $content */
    function set_hash($content) {
        $h = hash($this->algorithm(), $content, true);
        $this->hash = $h === false ? null : $h;
        $this->binary = true;
    }
    /** @param string $filename */
    function set_hash_file($filename) {
        $h = hash_file($this->algorithm(), $filename, true);
        $this->hash = $h === false ? null : $h;
        $this->binary = true;
    }

    /** @param string $hash
     * @return non-empty-string|false */
    static function hash_as_text($hash) {
        $ha = new HashAnalysis($hash);
        return $ha->ok() ? $ha->text() : false;
    }
    /** @param string $hash
     * @return non-empty-string|false */
    static function hash_as_binary($hash) {
        $ha = new HashAnalysis($hash);
        return $ha->ok() ? $ha->binary() : false;
    }
    /** @param string $hash
     * @return non-empty-string|false */
    static function sha1_hash_as_text($hash) {
        $ha = new HashAnalysis($hash);
        return $ha->ok() && $ha->prefix === "" ? $ha->text() : false;
    }
}
