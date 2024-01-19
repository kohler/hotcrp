<?php
// searchword.php -- HotCRP class holding information about search words
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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

    /** @param string $kwarg
     * @param int $kwpos1
     * @param int $pos1
     * @param int $pos2
     * @param ?SearchStringContext $string_context
     * @return SearchWord */
    static function make_kwarg($kwarg, $kwpos1, $pos1, $pos2, $string_context) {
        $sw = new SearchWord;
        $sw->qword = $kwarg;
        list($sw->word, $sw->quoted) = self::maybe_unquote($kwarg);
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
            || !preg_match('/\A[-A-Za-z0-9_.@\/]+\z/', $str)) {
            $str = "\"" . str_replace("\"", "\\\"", $str) . "\"";
        }
        return $str;
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
            $len1 = 1;
        } else if ($ch === 0xE2
                   && $len > 3
                   && ord($str[1]) === 0x80
                   && (ord($str[2]) | 1) === 0x9D) { // i.e., “”
            $len1 = 3;
        } else {
            $len1 = 0;
        }
        if ($len1 === 0 || $len === $len1) {
            $len2 = 0;
        } else {
            $ch = ord($str[$len - 1]);
            if ($ch === 34) {
                $len2 = 1;
            } else if (($ch | 1) === 0x9D
                       && $len >= $len1 + 3
                       && ord($str[$len - 2]) === 0x80
                       && ord($str[$len - 3]) === 0xE2) {
                $len2 = 3;
            } else {
                $len2 = 0;
            }
        }
        return substr($str, $len1, $len - $len1 - $len2);
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
}
