<?php
// listsorter.php -- HotCRP list sorter information
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class ListSorter {
    public $type;
    public $reverse = false;
    public $score = null;
    public $empty = false;
    public $thenmap = null;
    public $field = null;

    public function __construct($type) {
        $this->type = $type;
    }

    static public function make_empty($truly_empty) {
        $l = new ListSorter(null);
        $l->reverse = null;
        $l->empty = $truly_empty;
        return $l;
    }

    static public function make_field($field) {
        $l = new ListSorter(null);
        $l->field = $field;
        return $l;
    }


    static public $score_sorts = array("C" => "Counts",
                                       "A" => "Average",
                                       "E" => "Median",
                                       "V" => "Variance",
                                       "D" => "Max &minus; min",
                                       "Y" => "My score");

    public static function default_score_sort($nosession = false) {
        global $Conf;
        if (!$nosession && $Conf && ($sv = $Conf->session("scoresort")))
            return $sv;
        else if ($Conf && ($s = $Conf->setting_data("scoresort_default")))
            return $s;
        else
            return opt("defaultScoreSort", "C");
    }

    public static function parse_sorter($text) {
        // parse the sorter
        $text = simplify_whitespace($text);
        if (preg_match('/\A(\d+)([a-z]*)\z/i', $text, $m)
            || preg_match('/\A([^-,+#]+)[,+#]([a-z]*)\z/i', $text, $m)) {
            $sort = new ListSorter($m[1]);
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

    public static function push(&$array, $s) {
        if (count($array)) {
            $x = $array[count($array) - 1];
            if (((!$s->type && !$s->field) || (!$x->type && !$x->field))
                && $s->thenmap === $x->thenmap) {
                foreach (array("type", "reverse", "score", "field") as $k)
                    if ($x->$k === null && $s->$k !== null) {
                        $x->$k = $s->$k;
                        $x->empty = false;
                    }
                return;
            }
        }
        $array[] = $s;
    }
}
