<?php
// gmpshim.php -- HotCRP GMP shim functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.
/** @phan-file-suppress PhanRedefineFunctionInternal */

const GMPSHIM_INT_SHIFT = (PHP_INT_SIZE >= 8 ? 6 : 5);
const GMPSHIM_INT_SIZE = 1 << GMPSHIM_INT_SHIFT;

class GMPShim {
    static function init($v) {
        return [(int) $v];
    }

    static function clrbit(&$a, $index) {
        assert($index >= 0);
        $i = $index >> GMPSHIM_INT_SHIFT;
        if ($i < count($a)) {
            $j = $index & (GMPSHIM_INT_SIZE - 1);
            $a[$i] &= ~(1 << $j);
        }
    }

    static function setbit(&$a, $index, $bit_on = true) {
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

    static function testbit($a, $index) {
        assert($index >= 0);
        $i = $index >> GMPSHIM_INT_SHIFT;
        $j = $index & (GMPSHIM_INT_SIZE - 1);
        return $i < count($a) && ($a[$i] & (1 << $j)) !== 0;
    }

    static function scan1($a, $start) {
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
}
