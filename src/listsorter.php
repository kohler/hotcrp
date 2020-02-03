<?php
// listsorter.php -- HotCRP list sorter information
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class ListSorter {
    public $type;
    public $reverse = false;
    public $score;
    public $anno;
    public $empty = false;
    public $thenmap;
    public $field;
    public $uid;
    public $pl;

    static private $next_uid = 1;

    function __construct($type) {
        $this->type = $type;
    }

    static function make_empty($truly_empty) {
        $l = new ListSorter(null);
        $l->reverse = null;
        $l->empty = $truly_empty;
        return $l;
    }

    static function make_field($field) {
        $l = new ListSorter(null);
        $l->field = $field;
        return $l;
    }

    function assign_uid() {
        $this->uid = "__sortf__" . self::$next_uid;
        ++self::$next_uid;
    }


    static private $score_sort_map = [
        "C" => "C", "M" => "C", "counts" => "C", "count" => "C",
        "A" => "A", "average" => "A", "avg" => "A", "av" => "A", "ave" => "A",
        "E" => "E", "median" => "E", "med" => "E",
        "V" => "V", "variance" => "V", "var" => "V",
        "D" => "D", "maxmin" => "D", "max-min" => "D",
        "Y" => "Y", "my" => "Y", "myscore" => "Y"
    ];

    static private $score_sort_long_map = [
        "C" => "counts", "A" => "average", "E" => "median", "V" => "variance",
        "D" => "maxmin", "Y" => "my"
    ];

    static function canonical_short_score_sort($x) {
        return get(self::$score_sort_map, $x, null);
    }

    static function canonical_long_score_sort($x) {
        $x = get(self::$score_sort_map, $x, null);
        return $x ? self::$score_sort_long_map[$x] : $x;
    }

    static function score_sort_selector_options() {
        return ["counts" => "Counts", "average" => "Average",
                "median" => "Median", "variance" => "Variance",
                "maxmin" => "Max &minus; min", "my" => "My score"];
    }

    static function default_score_sort(Contact $user, $nosession = false) {
        if (!$nosession && ($x = $user->session("scoresort")))
            return $x;
        else
            return $user->conf->opt("defaultScoreSort", "C");
    }

    static function compress($sorters) {
        $cur = null;
        $output = [];
        foreach ($sorters as $s) {
            if ($cur
                && ((!$cur->type && !$cur->field) || (!$s->type && !$s->field))
                && $cur->thenmap === $s->thenmap) {
                foreach (["type", "reverse", "score", "field"] as $k) {
                    if ($cur->$k === null && $s->$k !== null) {
                        $cur->$k = $s->$k;
                        $cur->empty = false;
                    }
                }
                foreach ($s->anno ? : [] as $anno) {
                    $cur->anno[] = $anno;
                }
            } else {
                if ($cur)
                    $output[] = $cur;
                $cur = $s;
            }
        }
        if ($cur)
            $output[] = $cur;
        return $output;
    }
    static function append(&$output, $sinput) {
        $cur = null;
        foreach ($sinput as $s) {
            if ($cur
                && ((!$cur->type && !$cur->field) || (!$s->type && !$s->field))
                && $cur->thenmap === $s->thenmap) {
                foreach (["type", "reverse", "score", "field"] as $k)
                    if ($cur->$k === null && $s->$k !== null) {
                        $cur->$k = $s->$k;
                        $cur->empty = false;
                    }
                foreach ($s->anno ? : [] as $anno)
                    $cur->anno[] = $anno;
            } else {
                if ($cur)
                    $output[] = $cur;
                $cur = $s;
            }
        }
        if ($cur)
            $output[] = $cur;
    }
}
