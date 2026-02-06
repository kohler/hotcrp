<?php
// hashanalysis.php -- analyze hashes for algorithm, binary translation
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class HashAnalysis {
    /** @var string
     * @readonly */
    private $prefix = "xxx-";
    /** @var ?non-empty-string
     * @readonly */
    private $hash;
    /** @var ?non-empty-string
     * @readonly */
    private $shorthash;
    /** @var ?bool
     * @readonly */
    private $binary;

    /** @param ?string $hash */
    function __construct($hash = null) {
        if ($hash === null || $hash === "") {
            return;
        }
        $len = strlen($hash);
        $dprefix = null;
        if ($len >= 5 && $hash[4] === "-") {
            if (substr_compare($hash, "sha2", 0, 4, true) === 0) {
                $dprefix = "sha2-";
            } else if (substr_compare($hash, "sha1", 0, 4, true) === 0) {
                $dprefix = "sha1-";
            }
        }
        if ($len === 37
            && $dprefix === "sha2-") {
            $this->prefix = "sha2-";
            $this->hash = substr($hash, 5);
            $this->binary = true;
        } else if ($len === 20) {
            $this->prefix = "";
            $this->hash = $hash;
            $this->binary = true;
        } else if ($len === 69
                   && $dprefix === "sha2-"
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
                   && $dprefix === "sha1-") {
            $this->prefix = "";
            $this->hash = substr($hash, 5);
            $this->binary = true;
        } else if ($len === 45
                   && $dprefix === "sha1-"
                   && ctype_xdigit(substr($hash, 5))) {
            $this->prefix = "";
            $this->hash = substr($hash, 5);
            $this->binary = false;
        } else if (strcasecmp($hash, "sha256") === 0) {
            $this->prefix = "sha2-";
        } else if (strcasecmp($hash, "sha1") === 0) {
            $this->prefix = "";
        }
    }

    /** @param ?string $hash
     * @suppress PhanAccessReadOnlyProperty
     * @deprecated */
    function assign($hash) {
        $h = new HashAnalysis($hash);
        $this->prefix = $h->prefix;
        $this->hash = $h->hash;
        $this->shorthash = $h->shorthash;
        $this->binary = $h->binary;
    }

    /** @param Conf $conf
     * @param ?string $like
     * @return HashAnalysis
     * @suppress PhanAccessReadOnlyProperty */
    static function make_algorithm($conf, $like = null) {
        $ha = new HashAnalysis($like);
        if ($ha->prefix === "xxx-") {
            $algo = $conf->content_hash_algorithm();
            $ha->prefix = $algo === "sha1" ? "" : "sha2-";
        }
        $ha->hash = null;
        return $ha;
    }

    /** @param ?string $hash
     * @return HashAnalysis
     * @suppress PhanAccessReadOnlyProperty */
    static function make_partial($hash) {
        $ha = new HashAnalysis;
        if ($hash === null || $hash === "") {
            return $ha;
        }
        $len = strlen($hash);
        $dprefix = "";
        if ($len >= 5 && $hash[4] === "-") {
            if (substr_compare($hash, "sha2", 0, 4, true) === 0) {
                $dprefix = "sha2-";
            } else if (substr_compare($hash, "sha1", 0, 4, true) === 0) {
                $dprefix = "sha1-";
            }
        }
        $dplen = strlen($dprefix);
        $hlen = $len - $dplen;
        if ($hlen < 7
            || !ctype_xdigit(substr($hash, strlen($dprefix)))) {
            return $ha;
        }
        if ($hlen <= 64
            && ($dprefix === "sha2-" || $hlen > 40)) {
            $ha->prefix = "sha2-";
            if ($hlen === 64) {
                $ha->hash = substr($hash, $dplen);
            } else {
                $ha->shorthash = substr($hash, $dplen);
            }
            $ha->binary = false;
        } else if ($hlen <= 40) {
            $ha->prefix = "";
            if ($hlen === 40) {
                $ha->hash = substr($hash, $dplen);
            } else {
                $ha->shorthash = substr($hash, $dplen);
            }
            $ha->binary = false;
        }
        return $ha;
    }

    /** @return bool
     * @deprecated */
    function ok() {
        return $this->hash !== null;
    }
    /** @return bool */
    function complete() {
        return $this->hash !== null;
    }
    /** @return bool */
    function partial() {
        return $this->hash !== null || $this->shorthash !== null;
    }
    /** @return string */
    function algorithm() {
        if ($this->prefix === "sha2-") {
            return "sha256";
        } else if ($this->prefix === "") {
            return "sha1";
        }
        return "xxx";
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
    function partial_text() {
        $h = $this->hash ?? $this->shorthash;
        return $this->prefix . ($this->binary ? bin2hex($h) : strtolower($h));
    }
    /** @return non-empty-string */
    function binary() {
        return $this->prefix . ($this->binary ? $this->hash : hex2bin($this->hash));
    }
    /** @return non-empty-string */
    function text_data() {
        return $this->binary ? bin2hex($this->hash) : strtolower($this->hash);
    }
    /** @return non-empty-string */
    function partial_text_data() {
        $h = $this->hash ?? $this->shorthash;
        return $this->binary ? bin2hex($this->hash) : strtolower($this->hash);
    }

    /** @suppress PhanAccessReadOnlyProperty */
    function clear_hash() {
        $this->hash = $this->shorthash = null;
    }
    /** @param string $content
     * @suppress PhanAccessReadOnlyProperty */
    function set_hash($content) {
        $h = hash($this->algorithm(), $content, true);
        $this->hash = $h === false ? null : $h;
        $this->shorthash = null;
        $this->binary = true;
    }
    /** @param string $filename
     * @suppress PhanAccessReadOnlyProperty */
    function set_hash_file($filename) {
        $h = hash_file($this->algorithm(), $filename, true);
        $this->hash = $h === false ? null : $h;
        $this->shorthash = null;
        $this->binary = true;
    }

    /** @param string $hash
     * @return non-empty-string|false */
    static function hash_as_text($hash) {
        $ha = new HashAnalysis($hash);
        return $ha->complete() ? $ha->text() : false;
    }
    /** @param string $hash
     * @return non-empty-string|false */
    static function hash_as_binary($hash) {
        $ha = new HashAnalysis($hash);
        return $ha->complete() ? $ha->binary() : false;
    }
    /** @param string $hash
     * @return non-empty-string|false */
    static function sha1_hash_as_text($hash) {
        $ha = new HashAnalysis($hash);
        return $ha->complete() && $ha->prefix === "" ? $ha->text() : false;
    }
}
