<?php
// collatorshim.php -- PHP Collator polyfill
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Collator {
    const NUMERIC_COLLATION = 7;
    const ON = 17;
    const PRIMARY = 0;
    const SECONDARY = 1;
    const TERTIARY = 2;
    const QUATERNARY = 3;
    private $numeric = 0;
    function __construct($locale) {
    }
    function setStrength($strength) {
    }
    function setAttribute($name, $value) {
        if ($name === self::NUMERIC_COLLATION) {
            $this->numeric = $value;
        }
    }
    function compare($a, $b) {
        if ($this->numeric) {
            return strnatcasecmp($a, $b);
        } else {
            return strcasecmp($a, $b);
        }
    }
    function sort(&$v) {
        if ($this->numeric) {
            sort($v, SORT_NATURAL | SORT_FLAG_CASE);
        } else {
            sort($v, SORT_FLAG_CASE);
        }
    }
    function asort(&$v) {
        if ($this->numeric) {
            asort($v, SORT_NATURAL | SORT_FLAG_CASE);
        } else {
            asort($v, SORT_FLAG_CASE);
        }
    }
}
