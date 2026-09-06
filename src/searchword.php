<?php
// searchword.php -- HotCRP class holding information about search words
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class SearchWord {
    /** @var string */
    public $qword;
    /** @var string */
    public $word;
    /** @var bool */
    public $quoted;
    /** @var ?bool */
    public $kwexplicit;
    /** @var ?object */
    public $kwdef;
    /** @var ?string */
    public $compar;
    /** @var ?string */
    public $cword;
    /** @var ?int */
    public $kwpos1;
    /** @var ?int */
    public $pos1;
    /** @var ?int */
    public $pos2;
    /** @var ?SearchStringContext */
    public $string_context;

    /** @param string $word
     * @return SearchWord */
    static function make_simple($word) {
        $sw = new SearchWord;
        $sw->qword = $sw->word = $word;
        $sw->quoted = false;
        return $sw;
    }

    /** @param string $word
     * @return SearchWord */
    static function make_maybe_quoted($qword) {
        $sw = new SearchWord;
        $sw->qword = $qword;
        [$sw->word, $sw->quoted] = self::maybe_unquote($qword);
        return $sw;
    }

    /** @param string $kwarg
     * @param int $kwpos1
     * @param int $pos1
     * @param int $pos2
     * @param ?SearchStringContext $string_context
     * @return SearchWord */
    static function make_kwarg($kwarg, $kwpos1, $pos1, $pos2, $string_context) {
        $sw = new SearchWord;
        $sw->qword = $kwarg;
        [$sw->word, $sw->quoted] = self::maybe_unquote($kwarg);
        $sw->kwpos1 = $kwpos1;
        $sw->pos1 = $pos1;
        $sw->pos2 = $pos2;
        $sw->string_context = $string_context;
        return $sw;
    }

    /** @param string $str
     * @return string */
    static function quote($str) {
        if ($str === ""
            || preg_match('/\A[-A-Za-z0-9_.@\/]+\z/', $str)) {
            return $str;
        }
        return "\"" . preg_replace_callback('/\\\\.?+|"/', function ($m) {
            return strlen($m[0]) === 1 ? "\\" . $m[0] : $m[0];
        }, $str) . "\"";
    }

    /** @param string $str
     * @return string */
    static function unquote($str) {
        $len = strlen($str);
        if ($len === 0) {
            return "";
        }
        $ch = ord($str[0]);
        if ($ch === 34) {
            $pos1 = 1;
        } else if ($ch === 0xE2
                   && $len > 3
                   && ord($str[1]) === 0x80
                   && (ord($str[2]) | 1) === 0x9D) { // i.e., “”
            $pos1 = 3;
        } else {
            return $str;
        }
        $ch = ord($str[$len - 1]);
        if ($ch === 34) {
            $pos2 = $len - 1;
        } else if (($ch | 1) === 0x9D
                   && $len >= $pos1 + 3
                   && ord($str[$len - 2]) === 0x80
                   && ord($str[$len - 3]) === 0xE2) {
            $pos2 = $len - 3;
        } else {
            $pos2 = $len;
        }
        return preg_replace('/\\\\([\\\\"])/', '$1', substr($str, $pos1, $pos2 - $pos1));
    }

    /** @param string $str
     * @return array{string,bool} */
    static function maybe_unquote($str) {
        $uq = self::unquote($str);
        return strlen($str) === strlen($uq) ? [$str, false] : [$uq, true];
    }

    /** @param ?string $cword */
    function set_compar_word($cword) {
        $cword = $cword ?? $this->word;
        if ($this->quoted) {
            $this->compar = "";
            $this->cword = $cword;
        } else {
            preg_match('/\A(?:[=!<>]=?|≠|≤|≥)?/', $cword, $m);
            $this->compar = $m[0] === "" ? "" : CountMatcher::canonical_relation($m[0]);
            $this->cword = ltrim(substr($cword, strlen($m[0])));
        }
    }

    /** @param int $max
     * @return list<SearchWord> */
    function split($max = 0) {
        $s = $this->qword;
        $pos = 0;
        $l = [];
        while (preg_match('/\G:?+\s*+(?:[=!<>]=?|≠|≤|≥)?+[^":=!<>\xE2]*+(?:"[^\\\\"]*+(?:\\\\.|[^\\\\"]*+)*+(?:"|\z)|(?:“|”)[^\\\\"\xE2]*+(?:\\\\.|(?!“|”)\xE2|[^\\\\"\xE2]*+)*+(?:"|“|”|\z)|(?!“|”)\xE2|[^":=!<>\xE2]++)*+/', $s, $m, 0, $pos)
               && $m[0] !== "") {
            $p1 = $pos + strspn($s, ": \t\n\r\x0B\x0C", $pos);
            if ($max > 0 && count($l) + 1 === $max) {
                $p2 = $pos = strlen($s);
            } else {
                $p2 = $pos = $pos + strlen($m[0]);
            }
            while ($p2 > $p1 && ctype_space($s[$p2 - 1])) {
                --$p2;
            }
            if ($p1 === $p2) {
                /* do nothing */
            } else if ($p1 === 0 && $p2 === strlen($this->qword)) {
                $l[] = $this;
            } else if ($p1 < $p2) {
                $l[] = $sw = SearchWord::make_kwarg(
                    substr($this->qword, $p1, $p2 - $p1), $this->kwpos1,
                    $this->pos1 + $p1, $this->pos1 + $p2, $this->string_context
                );
            }
        }
        return $l;
    }

    /** @return array{?SearchWord,int,int} */
    function pop_comparison() {
        // see also CountMatcher::unpack_search_comparison
        $s = $this->qword;
        $r = strlen($s);
        if ($s === "" || $s === "any" || $s === "yes") {
            return [null, 4, 0];
        } else if ($s === "none" || $s === "no") {
            return [null, 2, 0];
        } else if (preg_match('/(?::\s*|(?=[=!<>\xE2]))(|[=!<>]=?|≤|≥|≠)\s*([-+]?\d+)\s*\z/', $s, $m)) {
            $r -= strlen($m[0]);
            $op = CountMatcher::$opmap[$m[1]];
            $v = (int) $m[2];
        } else if (preg_match('/:\s*(any|none)\s*\z/', $s, $m)) {
            $r -= strlen($m[0]);
            $op = $m[1] === "any" ? 4 : 2;
            $v = 0;
        } else if (ctype_digit($s)) {
            return [null, 4, (int) $s];
        } else {
            $op = 4;
            $v = 0;
        }
        while ($r > 0 && ctype_space($s[$r - 1])) {
            --$r;
        }
        $ns = substr($this->qword, 0, $r);
        if ($ns === "" || strcasecmp($ns, "any") === 0) {
            $nw = null;
        } else {
            $nw = SearchWord::make_kwarg(
                $ns, $this->kwpos1, $this->pos1,
                $this->pos1 + $r, $this->string_context
            );
        }
        return [$nw, $op, $v];
    }
}
