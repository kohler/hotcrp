<?php
// scoreinfo.php -- HotCRP score analysis helper.
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class ScoreInfo {
    private $_scores;
    private $_keyed;
    private $_sum = 0;
    private $_sumsq = 0;
    private $_n = 0;

    public function __construct($data) {
        if (($this->_keyed = is_array($data)))
            $this->_scores = $data;
        else {
            $this->_scores = array();
            if (is_string($data) && $data !== "")
                foreach (preg_split('/[\s,]+/', $data) as $i)
                    if (($i = cvtint($i)) > 0)
                        $this->_scores[] = $i;
        }
        foreach ($this->_scores as $i)
            if ($i) {
                $this->_sum += $i;
                $this->_sumsq += $i * $i;
                ++$this->_n;
            }
    }

    public function n() {
        return $this->_n;
    }

    public function average() {
        return $this->_n > 0 ? $this->_sum / $this->_n : 0;
    }

    public function variance() {
        return $this->_n > 1 ? ($this->_sumsq - $this->_sum * $this->_sum / $this->_n) / ($this->_n - 1) : 0;
    }

    public function stddev() {
        return sqrt($this->variance());
    }

    public function counts($max = 0) {
        $counts = $max ? array_fill(1, $max, 0) : array();
        foreach ($this->_scores as $i) {
            while ($i > count($counts))
                $counts[count($counts) + 1] = 0;
            ++$counts[$i];
        }
        return $counts;
    }

    public function median() {
        $p = count($this->_scores) / 2;
        $last = $below = 0;
        foreach ($this->counts() as $i => $ct)
            if ($p == $below)
                return ($last + $i) / 2;
            else if ($p < $below + $ct)
                return $i;
            else {
                $below += $ct;
                $last = $i;
            }
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
        for ($i = count($cts); $i > 0; --$i)
            if ($cts[$i]) {
                $s[] = sprintf("%c%03d", 48 + $i, $cts[$i]);
                $last = $i - 1;
            }
        $s[] = sprintf("%c999", 48 + $last);
        return join("", $s);
    }

    public function sort_data($sorter, $key = null) {
        if ($sorter == "Y" && $key !== null && $this->_keyed
            && ($v = @$this->_scores[$key]))
            return ":" . $v;
        else if ($sorter == "Y" || $sorter == "C" || $sorter == "M")
            return $this->counts_string();
        else if ($sorter == "E")
            return $this->median();
        else if ($sorter == "V")
            return $this->variance();
        else if ($sorter == "D")
            return $this->max() - $this->min();
        else
            return $this->average();
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
