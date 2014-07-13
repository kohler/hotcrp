<?php
// baselist.php -- HotCRP helper class for producing paper lists
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class BaseList {

    const FIELD_SCORE = 50;
    const FIELD_NUMSCORES = 11;

    static public $score_sorts = array("C" => "Counts",
                                       "A" => "Average",
                                       "E" => "Median",
                                       "V" => "Variance",
                                       "D" => "Max &minus; min",
                                       "Y" => "My score");

    function _sortBase($a, $b) {
        return $a->paperId - $b->paperId;
    }

    public static function score_reset($row) {
        // $row will compare less than all papers with analyzed scores
        $row->_sort_info = "//////////////";
        $row->_sort_average = 0;
    }

    public static function score_analyze($row, $scoreName, $scoreMax, $scoresort) {
        if ($scoresort == "Y" && strlen($scoreName) > 6
            && ($v = defval($row, substr($scoreName, 0, -6))) > 0)
            $row->_sort_info = ":" . $v;
        else if ($scoresort == "M" || $scoresort == "C" || $scoresort == "Y") {
            $x = array();
            foreach (preg_split('/[\s,]+/', $row->$scoreName) as $i)
                if (($i = cvtint($i)) > 0)
                    $x[] = chr($i + 48);
            rsort($x);
            $x = (count($x) == 0 ? "0" : implode($x));
            $x = str_pad($x, 14, chr(ord($x[strlen($x) - 1]) - 1));
            $row->_sort_info = $x;
        } else if ($scoresort == "E") {
            $x = array();
            $sum = 0;
            foreach (preg_split('/[\s,]+/', $row->$scoreName) as $i)
                if (($i = cvtint($i)) > 0) {
                    $x[] = $i;
                    $sum += $i;
                }
            sort($x);
            $n = count($x);
            if ($n % 2 == 1)
                $v = $x[($n-1)/2];
            else if ($n > 0)
                $v = ($x[$n/2 - 1] + $x[$n/2]) / 2.0;
            $row->_sort_info = $n ? $v : 0;
            $row->_sort_average = $n ? $sum / $n : 0;
        } else {
            $sum = $sum2 = $n = $max = 0;
            $min = $scoreMax;
            foreach (preg_split('/[\s,]+/', $row->$scoreName) as $i)
                if (($i = cvtint($i)) > 0) {
                    $sum += $i;
                    $sum2 += $i * $i;
                    $min = min($min, $i);
                    $max = max($max, $i);
                    $n++;
                }
            if ($n == 0)
                $row->_sort_info = 0;
            else if ($scoresort == "A")
                $row->_sort_info = $sum / $n;
            else if ($scoresort == "V") {
                if ($n == 1)
                    $row->_sort_info = 0;
                else
                    $row->_sort_info = ($sum2 / ($n - 1)) - ($sum * $sum / (($n - 1) * $n));
            } else
                $row->_sort_info = $max - $min;
            $row->_sort_average = ($n ? $sum / $n : 0);
        }
    }

    function score_compar($a, $b) {
        $x = strcmp($b->_sort_info, $a->_sort_info);
        return $x ? $x : $this->_sortBase($a, $b);
    }

    function score_numeric_compar($a, $b) {
        $x = $b->_sort_info - $a->_sort_info;
        $x = $x ? $x : $b->_sort_average - $a->_sort_average;
        return $x ? ($x < 0 ? -1 : 1) : $this->_sortBase($a, $b);
    }

    public function score_sort(&$rows, $scoresort) {
        if ($scoresort == "M" || $scoresort == "C" || $scoresort == "Y")
            usort($rows, array($this, "score_compar"));
        else
            usort($rows, array($this, "score_numeric_compar"));
    }

    public static function default_score_sort($nosession = false) {
        global $Conf, $Opt;
        if (!$nosession && $Conf && ($sv = $Conf->session("scoresort")))
            return $sv;
        else if ($Conf && ($s = $Conf->setting_data("scoresort_default")))
            return $s;
        else
            return defval($Opt, "defaultScoreSort", "C");
    }

    private static function check_sorter(&$text, &$parts, $regex, $symbol) {
        if (preg_match('/\A(|.*\s)' . $regex . '(\s.*|)\z/', $text, $m)) {
            $parts[2] .= $symbol;
            $text = simplify_whitespace($m[1] . $m[2]);
        }
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
