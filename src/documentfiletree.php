<?php
// documentfiletree.php -- document helper class for trees of HotCRP papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class DocumentFileTree implements JsonSerializable {
    /** @var int
     * @readonly */
    public $treeid;
    /** @var list<string> */
    private $_components = [];
    /** @var list<string> */
    private $_pregs = [];
    /** @var int */
    private $_n;
    /** @var int */
    private $_filecount;

    /** @var ?string */
    private $_algo;
    /** @var ?string */
    private $_hash;
    /** @var ?string */
    private $_extension;
    /** @var bool */
    private $_need_hash;
    /** @var bool */
    private $_complete;

    /** @var array<string,DocumentFileTreeDir> */
    private $_dirinfo = [];
    /** @var string */
    private $_extension_regex;

    /** @param string $dp
     * @param int $treeid */
    function __construct($dp, DocumentHashMatcher $matcher, $treeid = 0) {
        assert(is_string($dp) && $dp[0] === "/");
        $this->treeid = $treeid;

        foreach (preg_split("/\/+/", $dp) as $fdir) {
            if ($fdir !== "") {
                if (preg_match('/%\d*[%hHjaAwx]/', $fdir)) {
                    if (count($this->_components) % 2 === 0) {
                        $this->_components[] = "";
                    }
                    $this->_components[] = "/{$fdir}";
                } else if (count($this->_components) % 2 === 0) {
                    $this->_components[] = "/{$fdir}";
                } else {
                    $this->_components[count($this->_components) - 1] .= "/{$fdir}";
                }
            }
        }

        foreach ($this->_components as $fp) {
            $this->_pregs[] = $matcher->make_preg($fp);
        }

        $this->_n = count($this->_components);
        $this->_filecount = $this->populate_dirinfo("", 0);
    }

    /** @param string $dir
     * @param int $pos
     * @return int */
    private function populate_dirinfo($dir, $pos) {
        if ($pos < $this->_n && $pos % 1 === 0) {
            $dir .= $this->_components[$pos];
            ++$pos;
        }
        if ($pos >= $this->_n) {
            return 1;
        }
        $di = [];
        $preg = $this->_pregs[$pos];
        $n = 0;
        $isdir = $pos + 1 < $this->_n;
        foreach (is_dir($dir) ? scandir($dir, SCANDIR_SORT_NONE) : [] as $x) {
            $x = "/{$x}";
            if ($x !== "/." && $x !== "/.." && preg_match($preg, $x)) {
                $c = $isdir ? $this->populate_dirinfo("{$dir}{$x}", $pos + 1) : 1;
                if ($c) {
                    $di[] = $n;
                    $di[] = $x;
                    $n += $c;
                }
            }
        }
        if (!empty($di)) {
            $di[] = $n;
            $this->_dirinfo[$dir] = new DocumentFileTreeDir($dir, $di);
        }
        return $n;
    }

    private function clear() {
        $this->_algo = null;
        $this->_hash = "";
        $this->_extension = null;
        $this->_need_hash = $this->_complete = false;
    }

    /** @param string $text
     * @param int $i
     * @return ?string */
    function match_component($text, $i) {
        $match = $this->_components[$i];
        $xalgo = $this->_algo;
        $xhash = $this->_hash;
        $xext = $this->_extension;

        $build = "";
        while (preg_match('/\A(.*?)%(\d*)([%hHjaAwx])(.*)\z/', $match, $m)) {
            if ($m[1] !== "") {
                if (!str_starts_with($text, $m[1])) {
                    return null;
                }
                $build .= $m[1];
                $text = substr($text, strlen($m[1]));
            }

            list($fwidth, $fn, $match) = [$m[2], $m[3], $m[4]];
            if ($fn === "%") {
                if (!str_starts_with($text, "%")) {
                    return null;
                }
                $build .= "%";
                $text = substr($text, 1);
            } else if ($fn === "x") {
                if ($xext !== null) {
                    if (!str_starts_with($text, $xext)) {
                        return null;
                    }
                    $build .= $xext;
                    $text = substr($text, strlen($xext));
                } else if (!str_starts_with($text, ".")) {
                    $xext = "";
                } else {
                    $n = $text === "" ? strlen($text) : min(Mimetype::max_extension_length(), strlen($text));
                    $mt = Mimetype::lookup(substr($text, 0, $n));
                    while (!$mt && $text !== "" && $n > 2) {
                        --$n;
                        $mt = Mimetype::lookup(substr($text, 0, $n));
                    }
                    if ($mt) {
                        $xext = substr($text, 0, $n);
                        $build .= $xext;
                        $text = substr($text, $n);
                    } else {
                        $xext = "";
                    }
                }
            } else if ($fn === "w") {
                preg_match('/\A([^%\/]*)(.*)\z/', $match, $mm);
                if (str_starts_with($mm[2], "%x")) {
                    $mm[1] .= ".";
                }
                $re = '/\A([^\/]+?)' . ($mm[1] === "" ? '\z' : preg_quote($mm[1], "/")) . '/';
                if (preg_match($re, $text, $m)) {
                    $build .= $m[1];
                    $text = substr($text, strlen($m[1]));
                } else {
                    return null;
                }
            } else if ($fn === "j") {
                $this->_need_hash = true;
                $l = min(strlen($xhash), 2);
                if (substr($text, 0, $l) !== (string) substr($xhash, 0, $l)) {
                    return null;
                }
                if (preg_match('/\A([0-9a-f]{2,3})/', $text, $mm)) {
                    if (strlen($mm[1]) > strlen($xhash)) {
                        $xhash = $mm[1];
                    }
                    if (strlen($mm[1]) === 2 && $xalgo === null) {
                        $xalgo = "";
                    }
                    // XXX don't track that algo *cannot* be SHA-1
                    if (strlen($mm[1]) === 2 ? $xalgo !== "" : $xalgo === "") {
                        return null;
                    }
                    $build .= $mm[1];
                    $text = substr($text, strlen($mm[1]));
                } else {
                    return null;
                }
            } else if ($fn === "a") {
                $this->_need_hash = true;
                if (preg_match('/\A(sha1|sha256)/', $text, $mm)) {
                    $malgo = $mm[1] === "sha1" ? "" : "sha2-";
                    if ($xalgo === null) {
                        $xalgo = $malgo;
                    }
                    if ($xalgo !== $malgo) {
                        return null;
                    }
                    $build .= $mm[1];
                    $text = substr($text, strlen($mm[1]));
                } else {
                    return null;
                }
            } else {
                $this->_need_hash = true;
                if ($fn === "A" || $fn === "h") {
                    if ($xalgo !== null) {
                        if (!str_starts_with($text, $xalgo)) {
                            return null;
                        }
                    } else if (str_starts_with($text, "sha2-")) {
                        $xalgo = "sha2-";
                    } else {
                        $xalgo = "";
                    }
                    $build .= $xalgo;
                    $text = substr($text, strlen($xalgo));
                    if ($fn === "A") {
                        continue;
                    }
                }
                if (!str_starts_with($text, $xhash)) {
                    return null;
                }
                if ($fwidth === "") {
                    if ($xalgo === "") {
                        $fwidth = "40";
                    } else if ($xalgo === "sha2-") {
                        $fwidth = "64";
                    } else {
                        $fwidth = "40,64";
                    }
                }
                if (preg_match('/\A([0-9a-f]{' . $fwidth . '})/', $text, $mm)) {
                    if (strlen($mm[1]) > strlen($xhash)) {
                        $xhash = $mm[1];
                    }
                    $build .= $mm[1];
                    $text = substr($text, strlen($mm[1]));
                } else {
                    return null;
                }
            }
        }
        if ((string) $text !== $match) {
            error_log("fail {$build}, have `{$text}`, expected `{$match}`");
            return null;
        }
        $this->_algo = $xalgo;
        $this->_hash = $xhash;
        $this->_extension = $xext;
        $this->_complete = !$this->_need_hash
            || ($this->_algo === "" && strlen($this->_hash) === 40)
            || ($this->_algo !== null && strlen($this->_hash) === 64);
        return $build . $text;
    }

    /** @return int */
    static function random_index($di) {
        $l = 0;
        $r = count($di) - 1;
        $val = mt_rand(0, $di[$r] - 1);
        if ($di[$r] === ($r >> 1)) {
            $l = $r = $val << 1;
            //$verbose && error_log("*$val ?{$l}[" . $di[$l] . "," . $di[$l + 2] . ")");
        }
        while ($l + 2 < $r) {
            $m = $l + (($r - $l) >> 1) & ~1;
            //$verbose && error_log("*$val ?{$m}[" . $di[$m] . "," . $di[$m + 2] . ") @[$l,$r)");
            if ($val < $di[$m]) {
                $r = $m;
            } else {
                $l = $m;
            }
        }
        return $l;
    }

    /** @return DocumentFileTreeMatch */
    function first_match(DocumentFileTreeMatch $after = null) {
        $this->clear();
        $fm = new DocumentFileTreeMatch($this->treeid);
        for ($i = 0; $i < $this->_n; ++$i) {
            if ($i % 2 === 0) {
                $fm->fname .= $this->_components[$i];
            } else {
                $di = $this->_dirinfo[$fm->fname];
                if (!$di->append_first_component($this, $i, $fm, $after))
                    break;
            }
        }
        if ($this->_complete) {
            $fm->algohash = $this->_need_hash ? $this->_algo . $this->_hash : "none";
            $fm->extension = $this->_extension;
        }
        return $fm;
    }

    /** @return DocumentFileTreeMatch */
    function random_match() {
        $this->clear();
        $fm = new DocumentFileTreeMatch($this->treeid);
        for ($i = 0; $i < $this->_n; ++$i) {
            if ($i % 2 === 0) {
                $fm->fname .= $this->_components[$i];
            } else {
                $di = $this->_dirinfo[$fm->fname];
                if (!$di->append_random_component($this, $i, $fm))
                    break;
            }
        }
        if ($this->_complete) {
            $fm->algohash = $this->_need_hash ? $this->_algo . $this->_hash : "none";
            $fm->extension = $this->_extension;
        }
        return $fm;
    }

    function hide(DocumentFileTreeMatch $fm) {
        // account for removal
        assert($fm->treeid === $this->treeid);
        for ($i = count($fm->idxes) - 1; $i >= 0; --$i) {
            $this->_dirinfo[$fm->bdirs[$i]]->hide_component_index($fm->idxes[$i]);
        }
        $fm->idxes = $fm->bdirs = [];
        --$this->_filecount;
    }

    /** @return bool */
    function is_empty() {
        return $this->_filecount === 0;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $answer = ["__treeid__" => $this->treeid];
        $dirs = $this->_dirinfo;
        ksort($dirs);
        foreach ($dirs as $dname => $di) {
            $answer[$dname] = $di->jsonSerialize();
        }
        return $answer;
    }
}

class DocumentFileTreeMatch {
    /** @var int */
    public $treeid;
    /** @var list<string> */
    public $bdirs = [];
    /** @var list<int> */
    public $idxes = [];
    /** @var string */
    public $fname = "";
    /** @var ?string */
    public $algohash;
    /** @var ?string */
    public $extension;
    /** @var null|int|false */
    private $_atime;
    /** @var null|int|false */
    private $_mtime;

    /** @param int $treeid */
    function __construct($treeid) {
        $this->treeid = $treeid;
    }
    /** @param int $idx
     * @param string $suffix */
    function append_component($idx, $suffix) {
        $this->bdirs[] = $this->fname;
        $this->idxes[] = $idx;
        $this->fname .= $suffix;
    }
    /** @return bool */
    function is_complete() {
        return $this->algohash !== null;
    }
    /** @return int|false */
    function atime() {
        if ($this->_atime === null) {
            $this->_atime = fileatime($this->fname);
        }
        return $this->_atime;
    }
    /** @return int|false */
    function mtime() {
        if ($this->_mtime === null) {
            $this->_mtime = filemtime($this->fname);
        }
        return $this->_mtime;
    }
}

class DocumentFileTreeDir implements JsonSerializable {
    /** @var string */
    private $_dir;
    /** @var list<int|string> */
    private $_di;
    /** @var array<int,true> */
    private $_used = [];
    /** @var bool */
    private $_sorted = false;

    /** @param string $dir
     * @param list<int> $di */
    function __construct($dir, $di) {
        $this->_dir = $dir;
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

    /** @return int|false */
    private function random_index() {
        $l = 0;
        $r = count($this->_di) - 1;
        $nused = count($this->_used);
        if ($nused >= ($this->_di[$r] >> 2)) {
            $this->clean();
            $r = count($this->_di) - 1;
        }
        if ($r === 0) {
            return false;
        }
        do {
            $val = mt_rand(0, $this->_di[$r] - 1);
        } while (isset($this->_used[$val]));
        if ($this->_di[$r] === ($r >> 1)) {
            $l = $r = $val << 1;
        }
        while ($l + 2 < $r) {
            $m = $l + (($r - $l) >> 1) & ~1;
            //$verbose && error_log("*$val ?{$m}[" . $di[$m] . "," . $di[$m + 2] . ") @[$l,$r)");
            if ($val < $this->_di[$m]) {
                $r = $m;
            } else {
                $l = $m;
            }
        }
        return $l;
    }

    /** @param int $idx
     * @return bool */
    private function index_used($idx) {
        for ($i = $this->_di[$idx];
             $idx + 2 < count($this->_di) && $i < $this->_di[$idx + 2];
             ++$i) {
            if (!isset($this->_used[$i]))
                return false;
        }
        return true;
    }

    /** @param int $idx
     * @return int|false */
    function next_index($idx) {
        for ($tries = count($this->_di) >> 1; $tries > 0; --$tries) {
            $idx = $idx + 2;
            if ($idx === count($this->_di) - 1) {
                $idx = 0;
            }
            if (!$this->index_used($idx)) {
                return $idx;
            }
        }
        return false;
    }

    /** @param int $position
     * @param DocumentFileTreeMatch $fm
     * @return bool */
    function append_first_component(DocumentFileTree $ftree, $position, $fm,
                                    DocumentFileTreeMatch $after = null) {
        if (!$this->_sorted) {
            if (!empty($this->_used)) {
                $this->clean();
            }
            $dix = [];
            for ($i = 1; $i < count($this->_di); $i += 2) {
                $dix[$this->_di[$i]] = $this->_di[$i + 1] - $this->_di[$i - 1];
            }
            ksort($dix, SORT_STRING);
            $this->_di = [];
            $n = 0;
            foreach ($dix as $f => $c) {
                $this->_di[] = $n;
                $this->_di[] = $f;
                $n += $c;
            }
            $this->_di[] = $n;
            $this->_sorted = true;
        }

        $idx = 0;
        while (true) {
            if (!$this->index_used($idx)
                && (!$after || strcmp($after->fname, $fm->fname . $this->_di[$idx + 1]) < 0)
                && ($build = $ftree->match_component($this->_di[$idx + 1], $position)) !== null) {
                $fm->append_component($idx, $build);
                return true;
            }
            $next = $this->next_index($idx);
            if ($next === false || $next <= $idx) {
                return false;
            }
            $idx = $next;
        }
    }

    /** @param int $position
     * @return bool */
    function append_random_component(DocumentFileTree $ftree, $position, $fm) {
        if (($idx = $this->random_index()) === false) {
            return false;
        }
        for ($tries = count($this->_di) >> 1; $tries >= 0; --$tries) {
            if (($build = $ftree->match_component($this->_di[$idx + 1], $position)) !== null) {
                $fm->append_component($idx, $build);
                return true;
            }
            if (($idx = $this->next_index($idx)) === false) {
                return false;
            }
        }
        return false;
    }

    /** @param int $idx */
    function hide_component_index($idx) {
        assert($idx >= 0 && $idx < count($this->_di) - 1 && $idx % 2 === 0);
        $i = $this->_di[$idx];
        while (isset($this->_used[$i])) {
            ++$i;
        }
        assert($i < $this->_di[$idx + 2]);
        $this->_used[$i] = true;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return $this->_di;
    }
}
