<?php
// settings/s_fieldconversions.php -- HotCRP review form definition page
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class FieldConversions_Setting {
    /** @var list<object> */
    private $cvts;

    /** @param array<string,object> $fieldmap */
    function __construct($fieldmap, Conf $conf) {
        $conversions = [];
        foreach ($fieldmap as $name => $fj) {
            $cjo = 0;
            foreach ($fj->conversions ?? [] as $cj) {
                $cj = clone $cj;
                $cj->from = $cj->from ?? $fj->name;
                $cj->to = $cj->to ?? $fj->name;
                $cj->__source_order = ($fj->__source_order ?? 0) + $cjo;
                $conversions[] = $cj;
                $cjo += 0.0001;
            }
        }
        usort($conversions, function ($a, $b) {
            return $a->from <=> $b->from
                ? : $a->to <=> $b->to
                ? : Conf::xt_priority_compare($a, $b);
        });
        $xtp = new XtParams($conf, null);
        for ($i = 0; $i !== count($conversions); ++$i) {
            $ci = $conversions[$i];
            $j = $i + 1;
            while ($j !== count($conversions)
                   && $conversions[$j]->from === $ci->from
                   && $conversions[$j]->to === $ci->to) {
                ++$j;
            }
            if (($cf = $xtp->search_slice($conversions, $i, $j))) {
                $this->cvts[] = $cf;
            }
        }
    }

    /** @param string $from
     * @param string $to
     * @return ?object */
    function find($from, $to) {
        foreach ($this->cvts as $cvt) {
            if ($cvt->from === $from && $cvt->to === $to)
                return $cvt;
        }
        return null;
    }

    /** @param string $from
     * @return list<object> */
    function find_from($from) {
        $cvts = [];
        foreach ($this->cvts as $cvt) {
            if ($cvt->from === $from)
                $cvts[] = $cvt;
        }
        return $cvts;
    }

    /** @param string $to
     * @return list<object> */
    function find_to($to) {
        $cvts = [];
        foreach ($this->cvts as $cvt) {
            if ($cvt->to === $to)
                $cvts[] = $cvt;
        }
        return $cvts;
    }

    /** @param ?object $cvt
     * @return bool */
    function allow($cvt, ...$args) {
        if (!$cvt) {
            return false;
        }
        $ai = $cvt->allow_function ?? true;
        return $ai === true
            || (is_string($ai) && call_user_func($ai, ...$args));
    }
}
