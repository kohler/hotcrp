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
    public $pos1w;
    /** @var ?int */
    public $pos1;
    /** @var ?int */
    public $pos2;

    /** @param string $word
     * @return SearchWord */
    static function make_simple($word) {
        $sw = new SearchWord;
        $sw->qword = $sw->word = $word;
        $sw->quoted = false;
        return $sw;
    }

    /** @param string $kwarg
     * @param int $pos1w
     * @param int $pos1
     * @param int $pos2 */
    static function make_kwarg($kwarg, $pos1w, $pos1, $pos2) {
        $sw = new SearchWord;
        $sw->qword = $kwarg;
        $sw->quoted = self::is_quoted($kwarg);
        $sw->word = $sw->quoted ? substr($kwarg, 1, -1) : $kwarg;
        $sw->pos1w = $pos1w;
        $sw->pos1 = $pos1;
        $sw->pos2 = $pos2;
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
     * @return bool */
    static function is_quoted($str) {
        return $str !== ""
            && $str[0] === "\""
            && strpos($str, "\"", 1) === strlen($str) - 1;
    }

    /** @param string $str
     * @return string */
    static function unquote($str) {
        return self::is_quoted($str) ? substr($str, 1, -1) : $str;
    }

    /** @param string $str
     * @return array{string,bool} */
    static function maybe_unquote($str) {
        return self::is_quoted($str) ? [substr($str, 1, -1), true] : [$str, false];
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
