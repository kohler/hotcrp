<?php
// scoreinfo.php -- HotCRP score analysis helper.
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class ScoreInfo {
    private $_scores = array();
    private $_keyed = true;
    private $_sorted = false;
    private $_sum = 0;
    private $_sumsq = 0;
    private $_n = 0;

    const COUNT = 0;
    const MEAN = 1;
    const MEDIAN = 2;
    const SUM = 3;
    const VARIANCE_P = 4;
    const STDDEV_P = 5;

    function __construct($data = null) {
        if (is_array($data)) {
            foreach ($data as $key => $value)
                $this->add($value, $key);
        } else if (is_string($data) && $data !== "") {
            foreach (preg_split('/[\s,]+/', $data) as $s)
                if (($i = cvtint($s)) > 0)
                    $this->add($i);
        }
    }

    function add($score, $key = null) {
        if ($score !== null) {
            if ($this->_keyed && $key === null)
                $this->_keyed = false;
            if (is_bool($score))
                $score = (int) $score;
            if ($this->_keyed)
                $this->_scores[$key] = $score;
            else
                $this->_scores[] = $score;
            $this->_sum += $score;
            $this->_sumsq += $score * $score;
            ++$this->_n;
            $this->_sorted = false;
        }
    }

    function count() {
        return $this->_n;
    }

    function n() {
        return $this->_n;
    }

    function mean() {
        return $this->_n > 0 ? $this->_sum / $this->_n : 0;
    }

    function variance_s() {
        return $this->_n > 1 ? ($this->_sumsq - $this->_sum * $this->_sum / $this->_n) / ($this->_n - 1) : 0;
    }

    function stddev_s() {
        return sqrt($this->variance_s());
    }

    function variance_p() {
        return $this->_n > 1 ? ($this->_sumsq - $this->_sum * $this->_sum / $this->_n) / $this->_n : 0;
    }

    function stddev_p() {
        return sqrt($this->variance_p());
    }

    function counts($max = 0) {
        $counts = $max ? array_fill(0, $max + 1, 0) : array();
        foreach ($this->_scores as $i) {
            while ($i >= count($counts))
                $counts[] = 0;
            ++$counts[$i];
        }
        return $counts;
    }

    private function sort() {
        if (!$this->_sorted) {
            $this->_keyed ? asort($this->_scores) : sort($this->_scores);
            $this->_sorted = true;
        }
    }

    function median() {
        $this->sort();
        $a = $this->_keyed ? array_values($this->_scores) : $this->_scores;
        if ($this->_n % 2)
            return $a[($this->_n - 1) >> 1];
        else if ($this->_n)
            return ($a[($this->_n - 2) >> 1] + $a[$this->_n >> 1]) / 2;
        else
            return 0;
    }

    function max() {
        return empty($this->_scores) ? 0 : max($this->_scores);
    }

    function min() {
        return empty($this->_scores) ? 0 : min($this->_scores);
    }

    function sort_data($sorter, $key = null) {
        if ($sorter == "Y" && $key !== null && $this->_keyed)
            return get($this->_scores, $key, -1000000);
        else if ($sorter == "Y" || $sorter == "C" || $sorter == "M") {
            $this->sort();
            return $this->_scores ? array_values($this->_scores) : null;
        } else if ($sorter == "E")
            return $this->median();
        else if ($sorter == "V")
            return $this->variance_p();
        else if ($sorter == "D")
            return $this->max() - $this->min();
        else
            return $this->mean();
    }

    function compare_by(ScoreInfo $b, $sorter, $key = null) {
        return self::compare($this->sort_data($sorter, $key),
                             $b->sort_data($sorter, $key));
    }

    function statistic($what) {
        if ($what == self::COUNT)
            return $this->_n;
        else if ($what == self::MEAN)
            return $this->mean();
        else if ($what == self::MEDIAN)
            return $this->median();
        else if ($what == self::SUM)
            return $this->_sum;
        else if ($what == self::VARIANCE_P)
            return $this->variance_p();
        else if ($what == self::STDDEV_P)
            return $this->stddev_p();
    }

    static function compare($av, $bv, $null_direction = 1) {
        if ($av === null || $bv === null)
            return $av !== null ? -$null_direction : ($bv !== null ? $null_direction : 0);
        if (is_array($av)) {
            $ap = count($av);
            $bp = count($bv);
            $ax = $bx = -999999;
            do {
                --$ap;
                if ($ap >= 0)
                    $ax = $av[$ap];
                else if ($ap == -1)
                    --$ax;
                --$bp;
                if ($bp >= 0)
                    $bx = $bv[$bp];
                else if ($bp == -1)
                    --$bx;
                if ($ax != $bx)
                    return $ax < $bx ? -1 : 1;
            } while ($ap >= 0 || $bp >= 0);
        } else if ($av != $bv)
            return $av < $bv ? -1 : 1;
        return 0;
    }
}
