<?php
// search/st_time.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Time_SearchTerm extends SearchTerm {
    /** @var int */
    private $t;
    /** @var ?string */
    private $deadline;
    /** @var int */
    private $dyr = 0;
    /** @var int */
    private $dmo = 0;
    /** @var int */
    private $dsec = 0;
    /** @var bool */
    private $before;

    /** @param int $t
     * @param bool $before */
    function __construct($t, $before) {
        parent::__construct($before ? "before" : "after");
        $this->t = $t;
        $this->before = $before;
    }

    /** @param 'register'|'submit'|'resubmit'|'final' $deadline
     * @param array{int,int,float} $delta
     * @param bool $before
     * @return Time_SearchTerm */
    static function make_deadline($deadline, $delta, $before) {
        $st = new Time_SearchTerm(0, $before);
        $st->deadline = $deadline;
        $st->dyr = $delta[0];
        $st->dmo = $delta[1];
        $st->dsec = (int) round($delta[2]);
        return $st;
    }

    /** @return bool */
    function before() {
        return $this->before;
    }

    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $before = $sword->kwdef->before ?? false;
        if (preg_match('/\A(register|submit|resubmit|final)\s*+(?:([-+])\s*+(.++))?\z/is', $word, $m)) {
            $delta = [0, 0, 0.0];
            if (($m[2] ?? "") !== "") {
                $delta = SettingParser::parse_duration_yms($m[3]);
                if ($delta[2] === null || $delta[2] < 0) {
                    $srch->lwarning($sword, "<0>Invalid duration");
                    return new False_SearchTerm;
                }
                $delta = [$delta[0] ?? 0, $delta[1] ?? 0, $delta[2]];
                if ($m[2] === "-") {
                    $delta = [-$delta[0], -$delta[1], -$delta[2]];
                }
            }
            return self::make_deadline(strtolower($m[1]), $delta, $before);
        }
        if (strlen($word) === 4
            && ctype_digit($word)) {
            $word += $before ? 0 : 1;
            $word .= "-01-01T00:00:00";
        }
        $t = $srch->conf->parse_time($word);
        if ($t === false) {
            $srch->lwarning($sword, "<0>Invalid date");
            return new False_SearchTerm;
        }
        return new Time_SearchTerm((int) $t, $before);
    }

    /** @param int $t
     * @return int */
    private function add_delta($t) {
        if ($this->dyr !== 0 || $this->dmo !== 0) {
            $t = strtotime(sprintf("%+d years %+d months", $this->dyr, $this->dmo), $t);
        }
        return $t + $this->dsec;
    }

    /** Return the deadline this term refers to for `$row`, as a pair
     * `[display, enforced]`, or null if the deadline is unset. `enforced`
     * includes the grace period.
     * @return ?array{int,int} */
    private function deadline_times(PaperInfo $row) {
        $sr = $row->submission_round();
        if ($this->deadline === "final") {
            if ($sr->final_done <= 0) {
                return null;
            }
            $display = $this->add_delta($sr->final_deadline_for_display());
            $enforced = $this->add_delta($sr->final_done) + $sr->final_grace;
            return [$display, $enforced];
        }
        $t = $sr->{$this->deadline};
        if ($t <= 0) {
            return null;
        }
        $display = $this->add_delta($t);
        $enforced = $display + $sr->grace;
        // A draft’s resubmission window never extends past its own
        // submission deadline.
        if ($this->deadline === "resubmit"
            && $row->timeSubmitted <= 0
            && $sr->submit > 0) {
            $display = min($display, $sr->submit);
            $enforced = min($enforced, $sr->submit + $sr->grace);
        }
        return [$display, $enforced];
    }

    /** Return the time this term compares against, for display, or null
     * if unknown. Deadline terms require `$row`.
     * @param ?PaperInfo $row
     * @return ?int */
    function timestamp($row = null) {
        if ($this->deadline === null) {
            return $this->t;
        } else if ($row && ($dt = $this->deadline_times($row))) {
            return $dt[0];
        }
        return null;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->deadline !== null) {
            return "true";
        }
        return ($this->before ? Conf::$now <= $this->t : Conf::$now >= $this->t) ? "true" : "false";
    }
    function is_sqlexpr_precise() {
        return $this->deadline === null;
    }
    function test(PaperInfo $row, $xinfo) {
        if ($this->deadline === null) {
            $t = $this->t;
        } else if (($dt = $this->deadline_times($row)) !== null) {
            $t = $dt[1];
        } else {
            return $this->before;
        }
        return $this->before ? Conf::$now <= $t : Conf::$now >= $t;
    }
    function about() {
        return $this->deadline === null ? 0 : self::ABOUT_SUB;
    }

    /** Return true if `$st` contains a time term.
     * @return bool */
    static function has_time_term(SearchTerm $st) {
        return $st->visit(function ($t, ...$args) {
            return $t instanceof Time_SearchTerm || in_array(true, $args, true);
        });
    }

    /** Return the last time `$st` can match for `$row`, or null if
     * `$st` has no closing time.
     * @return ?int */
    static function closing_time(SearchTerm $st, PaperInfo $row) {
        $ts = $st->visit(function ($t, ...$args) use ($row) {
            if ($t instanceof And_SearchTerm) {
                return min(...$args);
            } else if ($t instanceof Or_SearchTerm) {
                return max(...$args);
            } else if ($t instanceof Time_SearchTerm && $t->before) {
                return $t->timestamp($row) ?? INF;
            }
            return INF;
        });
        return is_int($ts) ? $ts : null;
    }
}
