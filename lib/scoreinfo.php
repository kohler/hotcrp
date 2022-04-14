<?php
// scoreinfo.php -- HotCRP score analysis helper.
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ScoreInfo {
    /** @var list<int|float> */
    private $_scores = [];
    /** @var null|int|float */
    private $_my_score;
    /** @var bool */
    private $_sorted = false;
    /** @var int|float */
    private $_sum = 0;
    /** @var int|float */
    private $_sumsq = 0;
    /** @var int|float */
    private $_min = 0;
    /** @var int|float */
    private $_max = 0;
    /** @var int */
    private $_n = 0;
    /** @var bool */
    private $_positive;

    const COUNT = 0;
    const MEAN = 1;
    const MEDIAN = 2;
    const SUM = 3;
    const VARIANCE_P = 4;
    const STDDEV_P = 5;
    const MIN = 6;
    const MAX = 7;

    static public $stat_names = ["Count", "Mean", "Median", "Total", "Variance", "Standard deviation", "Minimum", "Maximum"];
    static public $stat_keys = ["count", "mean", "median", "total", "var_p", "stddev_p", "min", "max"];

    /** @param int $stat
     * @return bool */
    static function statistic_is_int($stat) {
        return $stat === self::COUNT;
    }

    /** @param int $stat
     * @return bool */
    static function statistic_is_sample($stat) {
        return $stat === self::MEDIAN || $stat === self::MIN || $stat === self::MAX;
    }

    /** @param null|list<int|float>|string $data
     * @param bool $positive */
    function __construct($data = null, $positive = false) {
        $this->_positive = $positive;
        if (is_array($data)) {
            foreach ($data as $x) {
                $this->add($x);
            }
        } else if (is_string($data) && $data !== "") {
            foreach (preg_split('/[\s,]+/', $data) as $x) {
                if (is_numeric($x))
                    $this->add(+$x);
            }
        }
    }

    /** @param list<int|float>|string $data
     * @param bool $positive
     * @return ?float */
    static function mean_of($data, $positive = false) {
        $n = $sum = 0;
        if (is_array($data)) {
            foreach ($data as $x) {
                if ($x !== null && (!$positive || $x > 0)) {
                    ++$n;
                    $sum += $x;
                }
            }
        } else if (is_string($data) && $data !== "") {
            foreach (preg_split('/[\s,]+/', $data) as $x) {
                if ($x !== "" && is_numeric($x)) {
                    $x = +$x;
                    if (!$positive || $x > 0) {
                        ++$n;
                        $sum += $x;
                    }
                }
            }
        }
        return $n ? $sum / $n : null;
    }

    /** @param int|float $x */
    function add($x) {
        if (is_bool($x)) {
            $x = $x ? 1 : 0;
        }
        if ($x !== null && (!$this->_positive || $x > 0)) {
            $this->_scores[] = $x;
            $this->_sum += $x;
            $this->_sumsq += $x * $x;
            ++$this->_n;
            $this->_sorted = false;
            if ($this->_n === 1 || $this->_min > $x) {
                $this->_min = $x;
            }
            if ($this->_n === 1 || $this->_max < $x) {
                $this->_max = $x;
            }
        }
    }

    /** @param null|int|float $s */
    function set_my_score($s) {
        $this->_my_score = $s;
    }

    /** @return bool */
    function is_empty() {
        return $this->_n === 0;
    }

    /** @return int */
    function count() {
        return $this->_n;
    }

    /** @return null|int|float */
    function my_score() {
        return $this->_my_score;
    }

    /** @return int */
    function n() {
        return $this->_n;
    }

    /** @return int|float */
    function sum() {
        return $this->_sum;
    }

    /** @return float */
    function mean() {
        return $this->_n > 0 ? $this->_sum / $this->_n : 0.0;
    }

    /** @return float */
    function variance_s() {
        return $this->_n > 1 ? ($this->_sumsq - $this->_sum * $this->_sum / $this->_n) / ($this->_n - 1) : 0.0;
    }

    /** @return float */
    function stddev_s() {
        return sqrt($this->variance_s());
    }

    /** @return float */
    function variance_p() {
        return $this->_n > 1 ? ($this->_sumsq - $this->_sum * $this->_sum / $this->_n) / $this->_n : 0.0;
    }

    /** @return float */
    function stddev_p() {
        return sqrt($this->variance_p());
    }

    /** @return list<int> */
    function counts($max = 0) {
        $counts = $max ? array_fill(0, $max, 0) : [];
        foreach ($this->_scores as $i) {
            while ($i > count($counts)) {
                $counts[] = 0;
            }
            if ($i > 0) {
                ++$counts[$i - 1];
            }
        }
        return $counts;
    }

    private function sort() {
        if (!$this->_sorted) {
            sort($this->_scores);
            $this->_sorted = true;
        }
    }

    /** @return int|float */
    function median() {
        $this->sort();
        if ($this->_n % 2) {
            return $this->_scores[($this->_n - 1) >> 1];
        } else if ($this->_n) {
            return ($this->_scores[($this->_n - 2) >> 1] + $this->_scores[$this->_n >> 1]) / 2;
        } else {
            return 0;
        }
    }

    /** @return int|float */
    function max() {
        return $this->_max;
    }

    /** @return int|float */
    function min() {
        return $this->_min;
    }

    /** @param int $stat
     * @return int|float */
    function statistic($stat) {
        if ($stat === self::COUNT) {
            return $this->_n;
        } else if ($stat === self::MEAN) {
            return $this->mean();
        } else if ($stat === self::MEDIAN) {
            return $this->median();
        } else if ($stat === self::SUM) {
            return $this->_sum;
        } else if ($stat === self::VARIANCE_P) {
            return $this->variance_p();
        } else if ($stat === self::STDDEV_P) {
            return $this->stddev_p();
        } else if ($stat === self::MIN) {
            return $this->min();
        } else if ($stat === self::MAX) {
            return $this->max();
        }
    }

    /** @return list<int|float> */
    function as_sorted_list() {
        $this->sort();
        return $this->_scores;
    }

    /** @param 'C'|'M'|'E'|'V'|'D'|'A'|'Y' $sorter
     * @return null|int|float|list<int> */
    function sort_data($sorter) {
        if ($sorter === "Y") {
            return $this->_my_score ?? -1000000;
        } else if ($sorter === "C" || $sorter === "M") {
            $this->sort();
            return $this->_scores ? array_values($this->_scores) : null;
        } else if ($sorter === "E") {
            return $this->median();
        } else if ($sorter === "V") {
            return $this->variance_p();
        } else if ($sorter === "D") {
            return $this->max() - $this->min();
        } else {
            return $this->mean();
        }
    }

    /** @param null|int|float|list<int> $av
     * @param null|int|float|list<int> $bv
     * @param -1|1 $null_direction
     * @return -1|0|1 */
    static function compare($av, $bv, $null_direction = 1) {
        if ($av === null || $bv === null) {
            return $av !== null ? -$null_direction : ($bv !== null ? $null_direction : 0);
        }
        if (is_array($av)) {
            $ap = count($av);
            $bp = count($bv);
            $ax = $bx = -999999;
            do {
                --$ap;
                if ($ap >= 0) {
                    $ax = $av[$ap];
                } else if ($ap == -1) {
                    --$ax;
                }
                --$bp;
                if ($bp >= 0) {
                    $bx = $bv[$bp];
                } else if ($bp == -1) {
                    --$bx;
                }
                if ($ax != $bx) {
                    return $ax < $bx ? -1 : 1;
                }
            } while ($ap >= 0 || $bp >= 0);
        } else if ($av != $bv) {
            return $av < $bv ? -1 : 1;
        }
        return 0;
    }

    /** @param 'C'|'M'|'E'|'V'|'D'|'A'|'Y' $sorter
     * @return -1|0|1 */
    function compare_by(ScoreInfo $b, $sorter) {
        return self::compare($this->sort_data($sorter), $b->sort_data($sorter));
    }
}
