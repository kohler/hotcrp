<?php
// scoreinfo.php -- HotCRP score analysis helper.
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
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

    public function __construct($data = null) {
        if (is_array($data)) {
            foreach ($data as $key => $value)
                $this->add($value, $key);
        } else if (is_string($data) && $data !== "") {
            foreach (preg_split('/[\s,]+/', $data) as $s)
                if (($i = cvtint($s)) > 0)
                    $this->add($i);
        }
    }

    public function add($score, $key = null) {
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

    public function count() {
        return $this->_n;
    }

    public function n() {
        return $this->_n;
    }

    public function mean() {
        return $this->_n > 0 ? $this->_sum / $this->_n : 0;
    }

    public function variance_s() {
        return $this->_n > 1 ? ($this->_sumsq - $this->_sum * $this->_sum / $this->_n) / ($this->_n - 1) : 0;
    }

    public function stddev_s() {
        return sqrt($this->variance_s());
    }

    public function variance_p() {
        return $this->_n > 1 ? ($this->_sumsq - $this->_sum * $this->_sum / $this->_n) / $this->_n : 0;
    }

    public function stddev_p() {
        return sqrt($this->variance_p());
    }

    public function counts($max = 0) {
        $counts = $max ? array_fill(0, $max + 1, 0) : array();
        foreach ($this->_scores as $i) {
            while ($i >= count($counts))
                $counts[] = 0;
            ++$counts[$i];
        }
        return $counts;
    }

    public function median() {
        if (!$this->_sorted) {
            $this->_keyed ? asort($this->_scores) : sort($this->_scores);
            $this->_sorted = true;
        }
        $a = $this->_keyed ? array_values($this->_scores) : $this->_scores;
        if ($this->_n % 2)
            return $a[($this->_n - 1) >> 1];
        else if ($this->_n)
            return ($a[($this->_n - 2) >> 1] + $a[$this->_n >> 1]) / 2;
        else
            return 0;
    }

    public function max() {
        return count($this->_scores) ? max($this->_scores) : 0;
    }

    public function min() {
        return count($this->_scores) ? min($this->_scores) : 0;
    }

    public function counts_string() {
        $cts = $this->counts();
        $s = array();
        $last = 0;
        for ($i = count($cts) - 1; $i > 0; --$i)
            if ($cts[$i]) {
                $s[] = sprintf("%c%03d", 48 + $i, $cts[$i]);
                $last = $i - 1;
            }
        $s[] = sprintf("%c999", 48 + $last);
        return join("", $s);
    }

    public function sort_data($sorter, $key = null) {
        if ($sorter == "Y" && $key !== null && $this->_keyed
            && ($v = get($this->_scores, $key)))
            return ":" . $v;
        else if ($sorter == "Y" || $sorter == "C" || $sorter == "M")
            return $this->counts_string();
        else if ($sorter == "E")
            return $this->median();
        else if ($sorter == "V")
            return $this->variance_p();
        else if ($sorter == "D")
            return $this->max() - $this->min();
        else
            return $this->mean();
    }

    public function statistic($what) {
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

    static public function empty_sort_data($sorter) {
        if ($sorter == "E" || $sorter == "V" || $sorter == "D")
            return -1;
        else
            return "/";
    }

    static public function sort_by_strcmp($sorter) {
        return $sorter != "E" && $sorter != "V" && $sorter != "D";
    }
}
