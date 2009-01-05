<?php
// baselist.inc -- HotCRP helper class for producing paper lists
// HotCRP is Copyright (c) 2006-2009 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $scoreSorts, $defaultScoreSort;
$scoreSorts = array("C" => "Counts",
		    "A" => "Average",
		    "V" => "Variance",
		    "D" => "Max &minus; min",
		    "Y" => "Your score");
$defaultScoreSort = "C";

class BaseList {

    const FIELD_SCORE = 50;
    const FIELD_NUMSCORES = 11;
    const FIELD_SCORE_OVERALL_MERIT = 50; // also used in help.php

    const LOTSAZEROS = "00000000000000";

    var $sortInfo;
    var $sortAverage;
    var $scoreMax;

    function _normalizeField($fieldId) {
	if ($fieldId >= self::FIELD_SCORE && $fieldId < self::FIELD_SCORE + self::FIELD_NUMSCORES)
	    return self::FIELD_SCORE;
	else
	    return $fieldId;
    }

    function _sortBase($a, $b) {
	return $a->paperId - $b->paperId;
    }

    function _sortScore($a, $b) {
	$x = strcmp($this->sortInfo[$b->paperId], $this->sortInfo[$a->paperId]);
	return $x ? $x : $this->_sortBase($a, $b);
    }

    function _sortScoreNum($a, $b) {
	$x = $this->sortInfo[$b->paperId] - $this->sortInfo[$a->paperId];
	$x = $x ? $x : $this->sortAverage[$b->paperId] - $this->sortAverage[$a->paperId];
	return $x ? ($x < 0 ? -1 : 1) : $this->_sortBase($a, $b);
    }

    function _setSortRow($row, $scoreName, $scoreMax, $scoresort) {
	if ($scoresort == "Y" && strlen($scoreName) > 6
	    && ($v = defval($row, substr($scoreName, 0, -6))) > 0)
	    $this->sortInfo[$row->paperId] = ":" . $v;
	else if ($scoresort == "M" || $scoresort == "C" || $scoresort == "Y") {
	    $x = array();
	    foreach (preg_split('/[\s,]+/', $row->$scoreName) as $i)
		if (($i = cvtint($i)) > 0)
		    $x[] = chr($i + 48);
	    rsort($x);
	    $x = (count($x) == 0 ? "0" : implode($x));
	    $x .= str_pad("", 14, chr(ord($x[strlen($x) - 1]) - 1));
	    $this->sortInfo[$row->paperId] = $x;
	} else {
	    $x = $x2 = $n = $max = 0;
	    $min = $scoreMax;
	    foreach (preg_split('/[\s,]+/', $row->$scoreName) as $i)
		if (($i = cvtint($i)) > 0) {
		    $x += $i;
		    $x2 += $i * $i;
		    $min = min($min, $i);
		    $max = max($max, $i);
		    $n++;
		}
	    if ($n == 0)
		$this->sortInfo[$row->paperId] = 0;
	    else if ($scoresort == "A")
		$this->sortInfo[$row->paperId] = $x / $n;
	    else if ($scoresort == "V") {
		if ($n == 1)
		    $this->sortInfo[$row->paperId] = 0;
		else
		    $this->sortInfo[$row->paperId] = ($x2 / ($n - 1)) - ($x * $x / (($n - 1) * $n));
	    } else
		$this->sortInfo[$row->paperId] = $max - $min;
	    $this->sortAverage[$row->paperId] = ($n ? $x / $n : 0);
	}
    }

}
