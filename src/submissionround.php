<?php
// submissionround.php -- HotCRP helper class for submission rounds
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class SubmissionRound {
    /** @var bool */
    public $unnamed = false;
    /** @var string */
    public $tag = "";
    /** @var string */
    public $label = "";
    /** @var string */
    public $prefix = "";
    /** @var int */
    public $open = 0;
    /** @var int */
    public $register = 0;
    /** @var bool */
    public $inferred_register = false;
    /** @var int */
    public $update = 0;
    /** @var int */
    public $submit = 0;
    /** @var int */
    public $resubmit = 0;
    /** @var bool */
    public $inferred_resubmit = false;
    /** @var int */
    public $grace = 0;
    /** @var bool */
    public $freeze = false;
    /** @var bool */
    public $incomplete_viewable = false;
    /** @var bool */
    public $pdf_viewable = true;
    /** @var int */
    public $final_open = 0;
    /** @var int */
    public $final_soft = 0;
    /** @var int */
    public $final_done = 0;
    /** @var int */
    public $final_grace = 0;

    /** @return SubmissionRound */
    static function make_main(Conf $conf) {
        $sr = new SubmissionRound;
        $sr->unnamed = true;
        $sr->open = $conf->setting("sub_open") ?? 0;
        $sr->register = $conf->setting("sub_reg") ?? 0;
        $sr->submit = $conf->setting("sub_sub") ?? 0;
        $sr->update = $conf->setting("sub_update") ?? $sr->submit;
        $sr->resubmit = $conf->setting("sub_resub") ?? 0;
        $sr->grace = $conf->setting("sub_grace") ?? 0;
        $sr->freeze = $conf->setting("sub_freeze") > 0;
        $sr->final_open = $conf->setting("final_open") ?? 0;
        $sr->final_soft = $conf->setting("final_soft") ?? 0;
        $sr->final_done = $conf->setting("final_done") ?? 0;
        $sr->final_grace = $conf->setting("final_grace") ?? 0;
        $sr->initialize($conf);
        return $sr;
    }

    /** @return SubmissionRound */
    static function make_json($j, SubmissionRound $main_sr, Conf $conf) {
        $sr = new SubmissionRound;
        $sr->tag = $j->tag;
        $sr->label = $j->label ?? $j->tag;
        $sr->prefix = $sr->label . " ";
        $sr->open = $j->open ?? $main_sr->open;
        $sr->register = $j->register ?? 0;
        $sr->submit = $j->submit ?? 0;
        $sr->update = $j->update ?? $sr->submit;
        $sr->resubmit = $j->resubmit ?? 0;
        $sr->grace = $j->grace ?? $main_sr->grace;
        $sr->freeze = $j->freeze ?? $main_sr->freeze;
        $sr->final_open = $j->final_open ?? $main_sr->final_open;
        $sr->final_soft = $j->final_soft ?? $main_sr->final_soft;
        $sr->final_done = $j->final_done ?? $main_sr->final_done;
        $sr->final_grace = $j->final_grace ?? $main_sr->final_grace;
        $sr->initialize($conf);
        return $sr;
    }

    /** @param Conf $conf */
    private function initialize($conf) {
        if ($this->register <= 0 && $this->update > 0) {
            $this->register = $this->update;
            $this->inferred_register = true;
        }
        if ($this->resubmit <= 0 && $this->update > 0) {
            $this->resubmit = $this->update;
            $this->inferred_resubmit = true;
        }
        if ($this->submit + $this->grace >= Conf::$now) {
            $this->incomplete_viewable = $conf->setting("pc_seeall") > 0;
            $this->pdf_viewable = $conf->setting("pc_seeallpdf") > 0
                || $this->submit <= 0;
        }
    }

    function time_open() {
        return $this->open > 0
            && $this->open <= Conf::$now;
    }

    /** @param bool $with_grace
     * @return bool */
    function time_register($with_grace) {
        return $this->open > 0
            && $this->open <= Conf::$now
            && ($this->register <= 0
                || $this->register + ($with_grace ? $this->grace : 0) >= Conf::$now);
    }

    /** @param bool $with_grace
     * @return bool */
    function time_update($with_grace) {
        return $this->open > 0
            && $this->open <= Conf::$now
            && ($this->update <= 0
                || $this->update + ($with_grace ? $this->grace : 0) >= Conf::$now);
    }

    /** @param bool $submitted
     * @param bool $with_grace
     * @return bool */
    function time_edit($submitted, $with_grace) {
        if ($submitted && $this->freeze) {
            return false;
        }
        $t = $submitted ? $this->resubmit : $this->update;
        return $this->open > 0
            && $this->open <= Conf::$now
            && ($t <= 0
                || $t + ($with_grace ? $this->grace : 0) >= Conf::$now);
    }

    /** @param bool $with_grace
     * @return bool */
    function time_submit($with_grace) {
        return $this->open > 0
            && $this->open <= Conf::$now
            && ($this->submit <= 0
                || $this->submit + ($with_grace ? $this->grace : 0) >= Conf::$now);
    }

    /** @param bool $with_grace
     * @return bool */
    function time_unsubmit($with_grace) {
        return !$this->freeze
            && $this->time_edit(false, $with_grace);
    }

    /** @param bool $with_grace
     * @return bool */
    function time_edit_final($with_grace) {
        return $this->final_open > 0
            && $this->final_open <= Conf::$now
            && ($this->final_done <= 0
                || $this->final_done + ($with_grace ? $this->final_grace : 0) >= Conf::$now);
    }

    /** @return int */
    function final_deadline_for_display() {
        if ($this->final_done > 0
            && ($this->final_soft <= 0
                || $this->final_done + $this->final_grace < Conf::$now
                || $this->final_soft + $this->final_grace < $this->final_done)) {
            return $this->final_done;
        }
        return $this->final_soft;
    }

    /** @return bool */
    function relevant(Contact $user, ?PaperInfo $prow = null) {
        if ($user->isPC) {
            return true;
        }
        if ($this->open <= 0 || $this->open > Conf::$now + 604800) {
            return false;
        }
        if ($this->register > 0 && $this->register <= Conf::$now + 604800) {
            return true;
        }
        $rv = ($this->submit > 0 && $this->submit <= Conf::$now + 604800 ? 1 : 0)
            | ($this->resubmit > 0 && $this->resubmit <= Conf::$now + 604800 ? 2 : 0);
        if ($rv === 0) {
            return false;
        }
        foreach ($prow ? [$prow] : $user->authored_papers() as $row) {
            if ($row->submission_round() === $this
                && (($rv & 1) !== 0 || $row->timeSubmitted > 0))
                return true;
        }
        return false;
    }

    /** @param SubmissionRound|Sround_Setting $a
     * @param SubmissionRound|Sround_Setting $b
     * @return -1|0|1 */
    static function compare($a, $b) {
        $as = $a->open > 0 && ($a->submit <= 0 || $a->submit >= Conf::$now);
        $bs = $b->open > 0 && ($b->submit <= 0 || $b->submit >= Conf::$now);
        if ($as !== $bs) {
            return $as ? -1 : 1;
        }
        if ($a->submit > 0
            && $b->submit > 0
            && abs($a->submit - $b->submit) >= 86400) {
            return $a->submit > $b->submit ? 1 : -1;
        }
        return strcasecmp($a->tag, $b->tag);
    }
}
