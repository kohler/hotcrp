<?php
// gmpshim.php -- HotCRP GMP shim functions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

define("GMPSHIM_INT_SHIFT", PHP_INT_SIZE >= 8 ? 6 : 5);
define("GMPSHIM_INT_SIZE", 1 << GMPSHIM_INT_SHIFT);

function gmpshim_init($v) {
    return [(int) $v];
}

function gmpshim_clrbit(&$a, $index) {
    assert($index >= 0);
    $i = $index >> GMPSHIM_INT_SHIFT;
    if ($i < count($a)) {
        $j = $index & (GMPSHIM_INT_SIZE - 1);
        $a[$i] &= ~(1 << $j);
    }
}

function gmpshim_setbit(&$a, $index, $bit_on = true) {
    assert($index >= 0);
    $i = $index >> GMPSHIM_INT_SHIFT;
    if ($bit_on || $i < count($a)) {
        while ($i >= count($a)) {
            $a[] = 0;
        }
        $j = $index & (GMPSHIM_INT_SIZE - 1);
        if ($bit_on) {
            $a[$i] |= 1 << $j;
        } else {
            $a[$i] &= ~(1 << $j);
        }
    }
}

function gmpshim_testbit($a, $index) {
    assert($index >= 0);
    $i = $index >> GMPSHIM_INT_SHIFT;
    $j = $index & (GMPSHIM_INT_SIZE - 1);
    return $i < count($a) && ($a[$i] & (1 << $j)) !== 0;
}

function gmpshim_scan1($a, $start) {
    assert($start >= 0);
    $i = $start >> GMPSHIM_INT_SHIFT;
    $j = $start & (GMPSHIM_INT_SIZE - 1);
    while ($i < count($a)) {
        $v = $a[$i];
        while ($j < GMPSHIM_INT_SIZE) {
            if ($v & (1 << $j)) {
                return ($i << GMPSHIM_INT_SHIFT) | $j;
            }
            ++$j;
        }
        $j = 0;
        ++$i;
    }
    return -1;
}

if (!function_exists("gmp_init")) {
    function gmp_init($v) {
        return gmpshim_init($v);
    }
    function gmp_clrbit(&$a, $index) {
        return gmpshim_clrbit($a, $index);
    }
    function gmp_setbit(&$a, $index, $bit_on = true) {
        return gmpshim_setbit($a, $index, $bit_on);
    }
    function gmp_testbit($a, $index) {
        return gmpshim_testbit($a, $index);
    }
    function gmp_scan1($a, $start) {
        return gmpshim_scan1($a, $start);
    }
}
