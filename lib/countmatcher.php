<?php
// countmatcher.php -- HotCRP helper class for textual comparators
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class CountMatcher {
    private $_countexpr;
    private $allowed = 0;
    private $value = 0;

    static public $opmap = array("" => 2, "#" => 2, "=" => 2, "==" => 2,
                                 "!" => 5, "!=" => 5, "≠" => 5,
                                 "<" => 1, "<=" => 3, "≤" => 3,
                                 "≥" => 6, ">=" => 6, ">" => 4);
    static public $oparray = array(false, "<", "=", "<=", ">", "!=", ">=", false);

    function __construct($countexpr) {
        if ((string) $countexpr !== "" && !$this->set_countexpr($countexpr))
            error_log(caller_landmark() . ": bogus countexpr $countexpr");
    }
    function set_countexpr($countexpr) {
        if (preg_match('/\A(|[=!<>]=?|≠|≤|≥)\s*([-+]?(?:\.\d+|\d+\.?\d*))\z/', $countexpr, $m)) {
            $this->_countexpr = $countexpr;
            $this->allowed = self::$opmap[$m[1]];
            $this->value = (float) $m[2];
            return true;
        } else
            return false;
    }
    function ok() {
        return $this->allowed !== 0;
    }
    function test($n) {
        return self::compare($n, $this->allowed, $this->value);
    }
    function filter($x) {
        return array_filter($x, [$this, "test"]);
    }
    static function compare($x, $compar, $y) {
        if (!is_int($compar))
            $compar = self::$opmap[$compar];
        if ($x > $y)
            return ($compar & 4) !== 0;
        else if ($x == $y)
            return ($compar & 2) !== 0;
        else
            return ($compar & 1) !== 0;
    }
    static function sqlexpr_using($compar_y) {
        if (is_array($compar_y)) {
            if (empty($compar_y))
                return "=NULL";
            else
                return " in (" . join(",", $compar_y) . ")";
        } else
            return $compar_y;
    }
    static function compare_using($x, $compar_y) {
        if (is_array($compar_y))
            return in_array($x, $compar_y);
        else if (preg_match('/\A([=!<>]=?|≠|≤|≥)\s*(-?(?:\.\d+|\d+\.?\d*))\z/', $compar_y, $m))
            return self::compare($x, $m[1], $m[2]);
        else
            return false;
    }
    static function filter_using($x, $compar_y) {
        if (is_array($compar_y))
            return array_intersect($x, $compar_y);
        else {
            $cm = new CountMatcher($compar_y);
            return $cm->filter($x);
        }
    }
    function test_explicit_zero() {
        return $this->value == 0 && ($this->allowed & 2);
    }
    function countexpr() {
        assert(!!$this->allowed);
        return self::$oparray[$this->allowed] . $this->value;
    }
    function simplified_nonnegative_countexpr() {
        if ($this->value == 1 && $this->allowed === 6)
            return ">0";
        else if (($this->value == 1 && $this->allowed === 1)
                 || ($this->value == 0 && $this->allowed === 3))
            return "=0";
        else
            return $this->countexpr();

    }
    function conservative_nonnegative_countexpr() {
        if ($this->allowed & 1)
            return ">=0";
        else
            return ($this->allowed & 2 ? ">=" : ">") . $this->value;
    }
    static function negate_countexpr_string($str) {
        $t = new CountMatcher($str);
        if ($t->allowed)
            return self::$oparray[$t->allowed ^ 7] . $t->value;
        else
            return $str;
    }
    static function flip_countexpr_string($str) {
        $t = new CountMatcher($str);
        if ($t->allowed & 5)
            return self::$oparray[$t->allowed ^ 5] . $t->value;
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
