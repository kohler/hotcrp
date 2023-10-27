<?php
// collatorshim.php -- PHP Collator polyfill
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Collator {
    const ALTERNATE_HANDLING = 1;
    const NON_IGNORABLE = 20;
    const SHIFTED = 21;
    const DEFAULT_VALUE = -1;
    const STRENGTH = 5;
    const NUMERIC_COLLATION = 7;
    const ON = 17;
    const PRIMARY = 0;
    const SECONDARY = 1;
    const TERTIARY = 2;
    const QUATERNARY = 3;
    private $numeric = 0;
    function __construct($locale) {
    }
    /** @param int $strength */
    function setStrength($strength) {
        $this->setAttribute(self::STRENGTH, $strength); // which does nothing
    }
    /** @param int $name
     * @param int $value */
    function setAttribute($name, $value) {
        if ($name === self::NUMERIC_COLLATION) {
            $this->numeric = $value;
        }
    }
    /** @param string $a
     * @param string $b
     * @return -1|0|1 */
    function compare($a, $b) {
        if ($this->numeric) {
            return strnatcasecmp($a, $b);
        } else {
            return strcasecmp($a, $b);
        }
    }
    /** @param list<string> &$v */
    function sort(&$v) {
        if ($this->numeric) {
            sort($v, SORT_NATURAL | SORT_FLAG_CASE);
        } else {
            sort($v, SORT_FLAG_CASE);
        }
    }
    /** @param array<string> &$v */
    function asort(&$v) {
        if ($this->numeric) {
            asort($v, SORT_NATURAL | SORT_FLAG_CASE);
        } else {
            asort($v, SORT_FLAG_CASE);
        }
    }
}
