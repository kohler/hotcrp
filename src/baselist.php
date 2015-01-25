<?php
// baselist.php -- HotCRP helper class for producing paper lists
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class BaseList {

    static public $score_sorts = array("C" => "Counts",
                                       "A" => "Average",
                                       "E" => "Median",
                                       "V" => "Variance",
                                       "D" => "Max &minus; min",
                                       "Y" => "My score");

    public static function default_score_sort($nosession = false) {
        global $Conf, $Opt;
        if (!$nosession && $Conf && ($sv = $Conf->session("scoresort")))
            return $sv;
        else if ($Conf && ($s = $Conf->setting_data("scoresort_default")))
            return $s;
        else
            return defval($Opt, "defaultScoreSort", "C");
    }

    public static function parse_sorter($text) {
        // parse the sorter
        $text = simplify_whitespace($text);
        if (preg_match('/\A(\d+)([a-z]*)\z/i', $text, $m)
            || preg_match('/\A([^,+#]+)[,+#]([a-z]*)\z/i', $text, $m)) {
            $sort = (object) array("type" => $m[1], "reverse" => false,
                                   "score" => null, "empty" => false);
            foreach (str_split(strtoupper($m[2])) as $x)
                if ($x === "R" || $x === "N")
                    $sort->reverse = $x === "R";
                else if ($x === "M")
                    $sort->score = "C";
                else if (isset(self::$score_sorts[$x]))
                    $sort->score = $x;
        } else
            $sort = PaperSearch::parse_sorter($text);

        if ($sort->score === null)
            $sort->score = self::default_score_sort();
        return $sort;
    }

}
