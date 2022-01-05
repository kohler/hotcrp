<?php
// listsorter.php -- HotCRP list sorter information
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ListSorter {
    static private $score_sort_map = [
        "C" => "C", "M" => "C", "counts" => "C", "count" => "C",
        "A" => "A", "average" => "A", "avg" => "A", "av" => "A", "ave" => "A",
        "E" => "E", "median" => "E", "med" => "E",
        "V" => "V", "variance" => "V", "var" => "V",
        "D" => "D", "maxmin" => "D", "max-min" => "D",
        "Y" => "Y", "my" => "Y", "myscore" => "Y"
    ];

    static public $score_sort_long_map = [
        "C" => "counts", "A" => "average", "E" => "median", "V" => "variance",
        "D" => "maxmin", "Y" => "my"
    ];

    static function canonical_short_score_sort($x) {
        return self::$score_sort_map[$x] ?? null;
    }

    static function canonical_long_score_sort($x) {
        $x = self::$score_sort_map[$x] ?? null;
        return $x ? self::$score_sort_long_map[$x] : $x;
    }

    static function long_score_sort_list() {
        return array_values(self::$score_sort_long_map);
    }

    static function score_sort_selector_options() {
        return ["counts" => "Counts", "average" => "Average",
                "median" => "Median", "variance" => "Variance",
                "maxmin" => "Max &minus; min", "my" => "My score"];
    }

    static function default_score_sort(Contact $user, $nosession = false) {
        if (!$nosession && ($x = $user->session("scoresort"))) {
            return $x;
        } else {
            return $user->conf->opt("defaultScoreSort") ?? "C";
        }
    }
}
