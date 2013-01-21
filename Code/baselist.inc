<?php
// baselist.inc -- HotCRP helper class for producing paper lists
// HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $scoreSorts, $defaultScoreSort;
$scoreSorts = array("C" => "Counts",
		    "A" => "Average",
		    "E" => "Median",
		    "V" => "Variance",
		    "D" => "Max &minus; min",
		    "Y" => "Your score");
$defaultScoreSort = "C";

class BaseList {

    const FIELD_SCORE = 50;
    const FIELD_NUMSCORES = 11;
    const FIELD_SCORE_OVERALL_MERIT = 50; // also used in help.php

    const FIELD_FORMULA = 100;
    const FIELD_NUMFORMULA = 100;

    const LOTSAZEROS = "00000000000000";

    function _normalizeField($fieldId) {
	if ($fieldId >= self::FIELD_SCORE && $fieldId < self::FIELD_SCORE + self::FIELD_NUMSCORES)
	    return self::FIELD_SCORE;
	else if ($fieldId >= self::FIELD_FORMULA && $fieldId < self::FIELD_FORMULA + self::FIELD_NUMFORMULA)
	    return self::FIELD_FORMULA;
	else
	    return $fieldId;
    }

    function _sortBase($a, $b) {
	return $a->paperId - $b->paperId;
    }

    public static function score_reset($row) {
        $row->_sort_info = self::LOTSAZEROS;
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
	    $x .= str_pad("", 14, chr(ord($x[strlen($x) - 1]) - 1));
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

}

class Column {
    const VIEW_COLUMN = 1;
    const VIEW_ROW = 2;

    public $name;
    public $cssname;
    public $foldnum;
    public $view;
    public $sortable;
    public $minimal;

    public function __construct($name, $view, $extra) {
        $this->name = $name;
	$this->cssname = defval($extra, "cssname", $name);
	$this->foldnum = defval($extra, "foldnum", 0);
	$this->view = $view;
	$this->sortable = defval($extra, "sortable", false);
	$this->minimal = defval($extra, "minimal", false);
    }
}
