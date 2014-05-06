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
        if (isset($_SESSION["scoresort"]) && !$nosession)
            return $_SESSION["scoresort"];
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
        $sort = (object) array("type" => null,
                               "reverse" => false,
                               "score" => self::default_score_sort(),
                               "empty" => $text == "");

        // parse the sorter
        $text = simplify_whitespace($text);
        if (preg_match('/\A(\d+)([a-z]*)\z/i', $text, $parts)
            || preg_match('/\A([^,+#]+)[,+#]([a-z]*)\z/i', $text, $parts))
            /* ok */;
        else {
            $parts = array("", $text, "");
            self::check_sorter($text, $parts, "reversed?", "R");
            if (preg_match('/\A(|.*\s)by (.*)\z/', $text, $m))
                list($text, $bytext, $hasby) = array($m[1], $m[2], "by ");
            else
                list($text, $bytext, $hasby) = array("", $text, "");
            self::check_sorter($bytext, $parts, "counts?", "C");
            self::check_sorter($bytext, $parts, "(?:av|ave|average)", "A");
            self::check_sorter($bytext, $parts, "(?:med|median)", "E");
            self::check_sorter($bytext, $parts, "(?:var|variance)", "V");
            self::check_sorter($bytext, $parts, "(?:max-min)", "D");
            self::check_sorter($bytext, $parts, "(?:my|my score)", "Y");
            if ($bytext)
                $text = simplify_whitespace($text . $hasby . $bytext);
            $parts[1] = $text;
        }

        // generate the sorter
        if (isset($parts[1]))
            $sort->type = $parts[1];
        if (isset($parts[2]) && $parts[2] != "")
            for ($i = 0; $i < strlen($parts[2]); ++$i) {
                $x = strtoupper($parts[2][$i]);
                if ($x == "R")
                    $sort->reverse = true;
                else if ($x == "N")
                    $sort->reverse = false;
                else if ($x == "M")
                    $sort->score = "C";
                else if (isset(self::$score_sorts[$x]))
                    $sort->score = $x;
            }
        return $sort;
    }

}
