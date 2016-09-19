<?php
// countmatcher.php -- HotCRP helper class for textual comparators
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CountMatcher {
    private $_countexpr;
    private $compar = 0;
    private $allowed = 0;

    static public $opmap = array("" => 2, "#" => 2, "=" => 2, "==" => 2,
                                 "!" => 5, "!=" => 5, "≠" => 5,
                                 "<" => 1, "<=" => 3, "≤" => 3,
                                 "≥" => 6, ">=" => 6, ">" => 4);
    static public $oparray = array(false, "<", "=", "<=", ">", "!=", ">=", false);

    function __construct($countexpr) {
        $this->_countexpr = $countexpr;
        if (preg_match('/\A([=!<>]=?|≠|≤|≥)\s*([-+]?(?:\.\d+|\d+\.?\d*))\z/', $countexpr, $m)) {
            $this->allowed = self::$opmap[$m[1]];
            $this->compar = (float) $m[2];
        } else
            error_log(caller_landmark() . ": bogus countexpr $countexpr");
    }
    function test($n) {
        return self::compare($n, $this->allowed, $this->compar);
    }
    static function compare($x, $compar, $y) {
        if (!is_int($compar))
            $compar = self::$opmap[$compar];
        if ($x > $y)
            return ($compar & 4) != 0;
        else if ($x == $y)
            return ($compar & 2) != 0;
        else
            return ($compar & 1) != 0;
    }
    static function compare_string($x, $compar_y) {
        if (preg_match('/\A([=!<>]=?|≠|≤|≥)\s*(-?(?:\.\d+|\d+\.?\d*))\z/', $compar_y, $m))
            return self::compare($x, $m[1], $m[2]);
        else
            return false;
    }
    public function countexpr() {
        if ($this->allowed)
            return self::$oparray[$this->allowed] . $this->compar;
        else
            return $this->_countexpr;
    }
    public function conservative_countexpr() {
        if ($this->allowed & 1)
            return ">=0";
        else
            return ($this->allowed & 2 ? ">=" : ">") . $this->compar;
    }
    static function negate_countexpr_string($str) {
        $t = new CountMatcher($str);
        if ($t->allowed)
            return self::$oparray[$t->allowed ^ 7] . $t->compar;
        else
            return $str;
    }
    static function canonical_comparator($str) {
        if (($x = self::$opmap[trim($str)]))
            return self::$oparray[$x];
        else
            return false;
    }
    static function canonicalize($countexpr) {
        $x = new CountMatcher($countexpr);
        return $x->allowed ? $x->countexpr() : false;
    }
}
