<?php
// documentfiletree.php -- document helper class for trees of HotCRP papers
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

class DocumentFileTree {
    private $_components = [];
    private $_pregs = [];
    private $n;

    private $_algo;
    private $_hash;
    private $_extension;

    private $_dirinfo = [];

    function __construct($dp, DocumentHashMatcher $matcher) {
        assert(is_string($dp) && $dp[0] === "/");
        $this->_matcher = $matcher;

        foreach (preg_split("{/+}", $dp) as $fdir) {
            if ($fdir !== "") {
                if (preg_match('/%\d*[%hxHjaA]/', $fdir)) {
                    if (count($this->_components) % 2 == 0)
                        $this->_components[] = "";
                    $this->_components[] = "/$fdir";
                } else if (count($this->_components) % 2 == 0)
                    $this->_components[] = "/$fdir";
                else
                    $this->_components[count($this->_components) - 1] .= "/$fdir";
            }
        }

        foreach ($this->_components as $fp)
            $this->_pregs[] = $matcher->make_preg($fp);

        $this->n = count($this->_components);
        $this->populate_dirinfo("", 0);
    }

    private function populate_dirinfo($dir, $pos) {
        if ($pos < $this->n && $pos % 1 == 0) {
            $dir .= $this->_components[$pos];
            ++$pos;
        }
        if ($pos >= $this->n)
            return 1;
        $di = [];
        $preg = $this->_pregs[$pos];
        $n = 0;
        $isdir = $pos + 1 < $this->n;
        foreach (scandir($dir, SCANDIR_SORT_NONE) as $x) {
            $x = "/$x";
            if ($x !== "/." && $x !== "/.." && preg_match($preg, $x)) {
                $c = $isdir ? $this->populate_dirinfo("$dir$x", $pos + 1) : 1;
                if ($c) {
                    $di[] = $n;
                    $di[] = $x;
                    $n += $c;
                }
            }
        }
        $di[] = $n;
        $this->_dirinfo[$dir] = new DocumentFileTreeDir($di);
        return $n;
    }

    private function clear() {
        $this->_algo = null;
        $this->_hash = "";
        $this->_extension = null;
    }

    function match_component($text, $i) {
        $match = $this->_components[$i];
        $xalgo = $this->_algo;
        $xhash = $this->_hash;
        $xext = $this->_extension;

        $build = "";
        while (preg_match('{\A(.*?)%(\d*)([%hxHjaA])(.*)\z}', $match, $m)) {
            if ($m[1] !== "") {
                if (substr($text, 0, strlen($m[1])) !== $m[1])
                    return false;
                $build .= $m[1];
                $text = substr($text, strlen($m[1]));
            }

            list($fwidth, $fn, $match) = [$m[2], $m[3], $m[4]];
            if ($fn === "%") {
                if (substr($text, 0, 1) !== "%")
                    return false;
                $build .= "%";
                $text = substr($text, 1);
            } else if ($fn === "x") {
                if ($xext !== null) {
                    if (substr($text, 0, strlen($xext)) != $xext)
                        return false;
                    $build .= $xext;
                    $text = substr($text, strlen($xext));
                } else if (preg_match('{\A(\.(?:avi|bib|bin|bz2|csv|docx?|gif|gz|html|jpg|json|md|mp4|pdf|png|pptx?|ps|rtf|smil|svgz?|tar|tex|tiff|txt|webm|xlsx?|xz|zip))}', $text, $m)) {
                    $xext = $m[1];
                    $build .= $m[1];
                    $text = substr($text, strlen($m[1]));
                } else
                    $xext = "";
            } else if ($fn === "j") {
                $l = min(strlen($xhash), 2);
                if (substr($text, 0, $l) !== (string) substr($xhash, 0, $l))
                    return false;
                if (preg_match('{\A([0-9a-f]{2,3})}', $text, $mm)) {
                    if (strlen($mm[1]) > strlen($xhash))
                        $xhash = $mm[1];
                    if (strlen($mm[1]) == 2 && $xalgo === null)
                        $xalgo = "";
                    // XXX don't track that algo *cannot* be SHA-1
                    if (strlen($mm[1]) == 2 ? $xalgo !== "" : $xalgo === "")
                        return false;
                    $build .= $mm[1];
                    $text = substr($text, strlen($mm[1]));
                } else
                    return false;
            } else if ($fn === "a") {
                if (preg_match('{\A(sha1|sha256)}', $text, $mm)) {
                    $malgo = $mm[1] === "sha1" ? "" : "sha2-";
                    if ($xalgo === null)
                        $xalgo = $malgo;
                    if ($xalgo !== $malgo)
                        return false;
                    $build .= $mm[1];
                    $text = substr($text, strlen($mm[1]));
                } else
                    return false;
            } else {
                if ($fn === "A" || $fn === "h") {
                    if ($xalgo !== null) {
                        if ($xalgo !== (string) substr($text, 0, strlen($xalgo)))
                            return false;
                    } else if (preg_match('{\A((?:sha2-)?)}', $text, $mm))
                        $xalgo = $mm[1];
                    else
                        return false;
                    $build .= $xalgo;
                    $text = substr($text, strlen($xalgo));
                    if ($fn === "A")
                        continue;
                }
                if (substr($text, 0, strlen($xhash)) !== $xhash)
                    return false;
                if ($fwidth === "") {
                    if ($xalgo === "")
                        $fwidth = "40";
                    else if ($xalgo === "sha2-")
                        $fwidth = "64";
                    else
                        $fwidth = "40,64";
                }
                if (preg_match('{\A([0-9a-f]{' . $fwidth . '})}', $text, $mm)) {
                    if (strlen($mm[1]) > strlen($xhash))
                        $xhash = $mm[1];
                    $build .= $mm[1];
                    $text = substr($text, strlen($mm[1]));
                } else
                    return false;
            }
        }
        if ((string) $text !== $match) {
            error_log("fail $build, have `$text`, expected `$match`");
            return false;
        }
        $this->_algo = $xalgo;
        $this->_hash = $xhash;
        $this->_extension = $xext;
        return $build . $text;
    }

    function match_complete() {
        return $this->_algo !== null
            && strlen($this->_hash) === ($this->_algo === "" ? 40 : 64);
    }

    static function random_index($di) {
        global $verbose;
        $l = 0;
        $r = count($di) - 1;
        $val = mt_rand(0, $di[$r] - 1);
        if ($di[$r] == ($r >> 1)) {
            $l = $r = $val << 1;
            //$verbose && error_log("*$val ?{$l}[" . $di[$l] . "," . $di[$l + 2] . ")");
        }
        while ($l + 2 < $r) {
            $m = $l + (($r - $l) >> 1) & ~1;
            //$verbose && error_log("*$val ?{$m}[" . $di[$m] . "," . $di[$m + 2] . ") @[$l,$r)");
            if ($val < $di[$m])
                $r = $m;
            else
                $l = $m;
        }
        return $l;
    }

    function random_match() {
        $this->clear();
        $fm = new DocumentFileTreeMatch;
        for ($i = 0; $i < $this->n; ++$i) {
            if ($i % 2 == 0) {
                $fm->fname .= $this->_components[$i];
            } else {
                $di = $this->_dirinfo[$fm->fname];
                if (!$di->append_random_component($this, $i, $fm))
                    break;
            }
        }
        if ($this->match_complete()) {
            $fm->algohash = $this->_algo . $this->_hash;
            $fm->extension = $this->_extension;
        }
        return $fm;
    }

    function hide(DocumentFileTreeMatch $fm) {
        // account for removal
        $delta = null;
        for ($i = count($fm->idxes) - 1; $i >= 0; --$i) {
            $this->_dirinfo[$fm->bdirs[$i]]->hide_component_index($fm->idxes[$i]);
        }
        $fm->idxes = $fm->bdirs = [];
    }
}

class DocumentFileTreeMatch {
    public $bdirs = [];
    public $idxes = [];
    public $fname = "";
    public $algohash;
    public $extension;
    private $_atime;

    function append_component($idx, $suffix) {
        $this->bdirs[] = $this->fname;
        $this->idxes[] = $idx;
        $this->fname .= $suffix;
    }
    function is_complete() {
        return $this->algohash !== null;
    }
    function atime() {
        if ($this->_atime === null)
            $this->_atime = fileatime($this->fname);
        return $this->_atime;
    }
}

class DocumentFileTreeDir {
    private $_di;
    private $_used = [];

    function __construct($di) {
        $this->_di = $di;
    }

    private function clean() {
        $di = [];
        for ($idx = 1, $i = $n = 0; $idx < count($this->_di); $idx += 2) {
            $c = 0;
            for (; $i < $this->_di[$idx + 1]; ++$i) {
                if (!isset($this->_used[$i])) {
                    ++$c;
                }
            }
            if ($c !== 0) {
                $di[] = $n;
                $di[] = $this->_di[$idx];
                $n += $c;
            }
        }
        $di[] = $n;
        $this->_di = $di;
        $this->_used = [];
    }

    private function random_index() {
        $l = 0;
        $r = count($this->_di) - 1;
        if (count($this->_used) >= ($this->_di[$r] >> 2)) {
            $this->clean();
            $r = count($this->_di) - 1;
        }
        if ($r === 0)
            return false;
        do {
            $val = mt_rand(0, $this->_di[$r] - 1);
        } while (isset($this->_used[$val]));
        if ($this->_di[$r] == ($r >> 1)) {
            $l = $r = $val << 1;
        }
        while ($l + 2 < $r) {
            $m = $l + (($r - $l) >> 1) & ~1;
            //$verbose && error_log("*$val ?{$m}[" . $di[$m] . "," . $di[$m + 2] . ") @[$l,$r)");
            if ($val < $this->_di[$m])
                $r = $m;
            else
                $l = $m;
        }
        return $l;
    }

    private function index_used($idx) {
        for ($i = $this->_di[$idx];
             $idx + 2 < count($this->_di) && $i < $this->_di[$idx + 2];
             ++$i) {
            if (!isset($this->_used[$i]))
                return false;
        }
        return true;
    }

    function next_index($idx) {
        for ($tries = count($this->_di) >> 1; $tries > 0; --$tries) {
            $idx = $idx + 2;
            if ($idx === count($this->_di) - 1)
                $idx = 0;
            if (!$this->index_used($idx))
                return $idx;
        }
        return false;
    }

    function append_random_component(DocumentFileTree $ftree, $position, $fm) {
        if (($idx = $this->random_index()) === false)
            return false;
        for ($tries = count($this->_di) >> 1; $tries >= 0; --$tries) {
            if (($build = $ftree->match_component($this->_di[$idx + 1], $position))) {
                $fm->append_component($idx, $build);
                return true;
            }
            if (($idx = $this->next_index($idx)) === false)
                return false;
        }
        return false;
    }

    function hide_component_index($idx) {
        assert($idx >= 0 && $idx < count($this->_di) - 1 && $idx % 2 === 0);
        $i = $this->_di[$idx];
        while (isset($this->_used[$i])) {
            ++$i;
        }
        assert($i < $this->_di[$idx + 2]);
        $this->_used[$i] = true;
    }
}
