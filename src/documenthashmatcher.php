<?php
// documenthashmatcher.php -- document helper class for HotCRP papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class DocumentHashMatcher {
    /** @var string */
    public $algo_preg = "(?:sha1|sha256)";
    /** @var string */
    public $algo_pfx_preg = "(?:|sha2-)";
    /** @var string */
    public $fixed_hash = "";
    /** @var string */
    public $hash_preg = "(?:[0-9a-f]{40}|[0-9a-f]{64})";
    /** @var bool */
    public $has_hash_preg = false;
    /** @var ?string */
    public $extension = null;
    /** @var string */
    public $extension_preg = ".*";

    /** @param ?string $match */
    function __construct($match = null) {
        if ($match === null || $match === "") {
            return;
        }
        $dot = strpos($match, ".");
        if ($dot !== false) {
            $this->set_extension(substr($match, $dot));
            $match = substr($match, 0, $dot);
        }

        $match = strtolower($match);
        if (!preg_match('/\A(?:sha[123]-?)?(?:[0-9a-f*]|\[\^?[-0-9a-f]+\])*\z/', $match)) {
            fwrite(STDERR, "* bad `--match`, expected `[sha[123]-][0-9a-f*]*`\n");
            exit(1);
        }
        if (preg_match('{\Asha([12])-?(.*)\z}', $match, $m)) {
            if ($m[1] === "1") {
                $this->algo_preg = "sha1";
                $this->algo_pfx_preg = "";
            } else {
                $this->algo_preg = "sha256";
                $this->algo_pfx_preg = "sha2-";
            }
            $match = $m[2];
        }
        if (preg_match('/\A([0-9a-f]+)/', $match, $m)) {
            $this->fixed_hash = $m[1];
        }
        if ($match != "") {
            $this->hash_preg = str_replace("*", "[0-9a-f]*", $match) . "[0-9a-f]*";
            $this->has_hash_preg = true;
        }
    }
    /** @param ?string $extension */
    function set_extension($extension) {
        if ($extension !== null && $extension !== "") {
            if (str_starts_with($extension, ".")) {
                $this->extension = $extension;
            } else {
                $this->extension = "." . $extension;
            }
            $this->extension_preg = preg_quote($this->extension);
        } else {
            $this->extension = null;
            $this->extension_preg = ".*";
        }
    }
    /** @param string $entrypat
     * @return string */
    function make_preg($entrypat) {
        $preg = "";
        $entrypat = preg_quote($entrypat);
        while ($entrypat !== ""
               && preg_match('/\A(.*?)%(\d*)([%hHjaAxw])(.*)\z/', $entrypat, $m)) {
            $preg .= preg_quote($m[1]);
            $fwidth = $m[2];
            $fn = $m[3];
            $entrypat = $m[4];
            if ($fn === "%") {
                $preg .= "%";
            } else if ($fn === "x") {
                $preg .= $this->extension_preg;
            } else if ($fn === "w") {
                if (str_ends_with($preg, "/")) {
                    $preg .= "[^.\\/][^\\/]*";
                } else {
                    $preg .= "[^\\/]+";
                }
            } else if ($fn === "a") {
                $preg .= $this->algo_preg;
            } else if ($fn === "A") {
                $preg .= $this->algo_pfx_preg;
            } else if ($fn === "j") {
                $l = min(strlen($this->fixed_hash), 3);
                $preg .= substr($this->fixed_hash, 0, $l);
                for (; $l < 3; ++$l) {
                    $preg .= "[0-9a-f]";
                }
                $preg .= "?";
            } else {
                if ($fn === "h") {
                    $preg .= $this->algo_pfx_preg;
                }
                if ($fwidth === "") {
                    $preg .= $this->hash_preg;
                } else {
                    $fwidth = intval($fwidth);
                    $l = min(strlen($this->fixed_hash), $fwidth);
                    $preg .= substr($this->fixed_hash, 0, $l);
                    if ($l < $fwidth) {
                        $preg .= "[0-9a-f]{" . ($fwidth - $l) . "}";
                    }
                }
            }
        }
        return "{\\A" . $preg . $entrypat . "\\z}";
    }
    /** @param null|false|string $hash
     * @return bool */
    function test_hash($hash) {
        return (string) $hash !== ""
            && preg_match('{\A' . $this->algo_pfx_preg . $this->hash_preg . '\z}', $hash);
    }
}
