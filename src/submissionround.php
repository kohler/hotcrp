<?php
// submissionround.php -- HotCRP helper class for submission rounds
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class SubmissionRound {
    /** @var bool */
    public $unnamed = false;
    /** @var string */
    public $tag = "";
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
    public $grace = 0;
    /** @var bool */
    public $freeze = false;
    /** @var bool */
    public $incomplete_viewable = false;
    /** @var bool */
    public $pdf_viewable = true;

    /** @return SubmissionRound */
    static function make_main(Conf $conf) {
        $sr = new SubmissionRound;
        $sr->unnamed = true;
        $sr->open = $conf->setting("sub_open") ?? 0;
        $sr->register = $conf->setting("sub_reg") ?? 0;
        $sr->submit = $conf->setting("sub_sub") ?? 0;
        $sr->update = $conf->setting("sub_update") ?? $sr->submit;
        $sr->grace = $conf->setting("sub_grace") ?? 0;
        $sr->freeze = $conf->setting("sub_freeze") > 0;
        $sr->initialize($conf);
        return $sr;
    }

    /** @return SubmissionRound */
    static function make_json($j, SubmissionRound $main_sr, Conf $conf) {
        $sr = new SubmissionRound;
        $sr->tag = $j->tag;
        $sr->prefix = $sr->tag . " ";
        $sr->open = $j->open ?? $main_sr->open;
        $sr->register = $j->register ?? 0;
        $sr->submit = $j->submit ?? 0;
        $sr->update = $j->update ?? $sr->submit;
        $sr->grace = $j->grace ?? $main_sr->grace;
        $sr->freeze = $j->freeze ?? $main_sr->freeze;
        $sr->initialize($conf);
        return $sr;
    }

    /** @param Conf $conf */
    private function initialize($conf) {
        if ($this->register <= 0 && $this->update > 0) {
            $this->register = $this->update;
            $this->inferred_register = true;
        }
        if ($this->time_submit(true)) {
            $this->incomplete_viewable = $conf->setting("pc_seeall") > 0;
            $this->pdf_viewable = $conf->setting("pc_seeallpdf") > 0
                || $this->submit <= 0;
        }
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

    /** @param bool $with_grace
     * @return bool */
    function time_submit($with_grace) {
        return $this->open > 0
            && $this->open <= Conf::$now
            && ($this->submit <= 0
                || $this->submit + ($with_grace ? $this->grace : 0) >= Conf::$now);
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
