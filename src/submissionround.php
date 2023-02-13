<?php
// submissionround.php -- HotCRP helper class for submission rounds
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class SubmissionRound {
    /** @var bool */
    public $unnamed = false;
    /** @var string */
    public $tag = "";
    /** @var string */
    public $title1 = "";
    /** @var int */
    public $open = 0;
    /** @var int */
    public $register = 0;
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
    static function make_json($j, Conf $conf) {
        $sr = new SubmissionRound;
        $sr->tag = $j->tag;
        $sr->title1 = $sr->tag . " ";
        $sr->open = $j->open ?? 0;
        $sr->register = $j->register ?? 0;
        $sr->submit = $j->submit ?? 0;
        $sr->update = $j->update ?? $sr->submit;
        $sr->grace = $j->grace ?? 0;
        $sr->freeze = $j->freeze ?? false;
        $sr->initialize($conf);
        return $sr;
    }

    /** @param Conf $conf */
    private function initialize($conf) {
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
}
