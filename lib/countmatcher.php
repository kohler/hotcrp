<?php
// countmatcher.php -- HotCRP helper class for textual comparators
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class CountMatcher {
    /** @var string */
    private $_countexpr;
    private $allowed = 0;
    private $value = 0;

    static public $opmap = array("" => 2, "#" => 2, "=" => 2, "==" => 2,
                                 "!" => 5, "!=" => 5, "≠" => 5,
                                 "<" => 1, "<=" => 3, "≤" => 3,
                                 "≥" => 6, ">=" => 6, ">" => 4);
    static public $oparray = array(false, "<", "=", "<=", ">", "!=", ">=", false);

    /** @param string $countexpr */
    function __construct($countexpr) {
        if ((string) $countexpr !== "" && !$this->set_countexpr($countexpr)) {
            error_log(caller_landmark() . ": bogus countexpr $countexpr");
        }
    }
    function set_countexpr($countexpr) {
        if (preg_match('/\A(|[=!<>]=?|≠|≤|≥)\s*([-+]?(?:\.\d+|\d+\.?\d*))\z/', $countexpr, $m)) {
            $this->_countexpr = $countexpr;
            $this->allowed = self::$opmap[$m[1]];
            $this->value = (float) $m[2];
            return true;
        } else {
            return false;
        }
    }
    /** @return bool */
    function ok() {
        return $this->allowed !== 0;
    }
    /** @param int|float $n
     * @return bool */
    function test($n) {
        return self::compare($n, $this->allowed, $this->value);
    }
    /** @param array<mixed,int|float> $x
     * @return array<mixed,int|float> */
    function filter($x) {
        return array_filter($x, [$this, "test"]);
    }
    /** @param int|float $x
     * @param int|string $compar
     * @param int|float $y
     * @return bool */
    static function compare($x, $compar, $y) {
        if (!is_int($compar)) {
            $compar = self::$opmap[$compar];
        }
        $delta = $x - $y;
        if ($delta > 0.000001) {
            return ($compar & 4) !== 0;
        } else if ($delta > -0.000001) {
            return ($compar & 2) !== 0;
        } else {
            return ($compar & 1) !== 0;
        }
    }
    static function sqlexpr_using($compar_y) {
        if (is_array($compar_y)) {
            if (empty($compar_y)) {
                return "=NULL";
            } else {
                return " in (" . join(",", $compar_y) . ")";
            }
        } else {
            return $compar_y;
        }
    }
    /** @param int|float $x
     * @param list<int|float>|string $compar_y
     * @return bool */
    static function compare_using($x, $compar_y) {
        if (is_array($compar_y)) {
            return in_array($x, $compar_y);
        } else if (preg_match('/\A([=!<>]=?|≠|≤|≥)\s*(-?(?:\.\d+|\d+\.?\d*))\z/', $compar_y, $m)) {
            return self::compare($x, $m[1], (float) $m[2]);
        } else {
            return false;
        }
    }
    static function filter_using($x, $compar_y) {
        if (is_array($compar_y)) {
            return array_intersect($x, $compar_y);
        } else {
            $cm = new CountMatcher($compar_y);
            return $cm->filter($x);
        }
    }
    /** @return string */
    function compar() {
        assert(!!$this->allowed);
        return self::$oparray[$this->allowed];
    }
    /** @return int|float */
    function value() {
        return $this->value;
    }
    /** @return string */
    function countexpr() {
        assert(!!$this->allowed);
        return self::$oparray[$this->allowed] . $this->value;
    }
    /** @return string */
    function simplified_nonnegative_countexpr() {
        if ($this->value === 1.0 && $this->allowed === 6) {
            return ">0";
        } else if (($this->value === 1.0 && $this->allowed === 1)
                   || ($this->value === 0.0 && $this->allowed === 3)) {
            return "=0";
        } else {
            return $this->countexpr();
        }

    }
    /** @return string */
    function conservative_nonnegative_countexpr() {
        if ($this->allowed & 1) {
            return ">=0";
        } else {
            return ($this->allowed & 2 ? ">=" : ">") . $this->value;
        }
    }
    /** @param string $str
     * @return string */
    static function negate_countexpr_string($str) {
        $t = new CountMatcher($str);
        if ($t->allowed) {
            return self::$oparray[$t->allowed ^ 7] . $t->value;
        } else {
            return $str;
        }
    }
    /** @param string $str
     * @return string */
    static function flip_countexpr_string($str) {
        $t = new CountMatcher($str);
        if ($t->allowed & 5) {
            return self::$oparray[$t->allowed ^ 5] . $t->value;
        } else {
            return $str;
        }
    }
    /** @param string $str
     * @return ?int */
    static function comparator_value($str) {
        return self::$opmap[$str] ?? null;
    }
    /** @param int $compar
     * @return ?string */
    static function unparse_comparator_value($compar) {
        return self::$oparray[$compar] ?? null;
    }
    /** @param string $str
     * @return ?string */
    static function canonical_comparator($str) {
        if (($x = self::$opmap[trim($str)])) {
            return self::$oparray[$x];
        } else {
            return null;
        }
    }
    /** @param string $countexpr
     * @return ?string */
    static function canonicalize($countexpr) {
        $x = new CountMatcher($countexpr);
        return $x->allowed ? $x->countexpr() : null;
    }
}
