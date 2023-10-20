<?php
// responseround.php -- HotCRP helper class for response rounds
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class ResponseRound {
    /** @var bool */
    public $unnamed = false;
    /** @var string */
    public $name;
    /** @var int */
    public $id;
    /** @var bool */
    public $active = false;
    /** @var int */
    public $open = 0;
    /** @var int */
    public $done = 0;
    /** @var int */
    public $grace = 0;
    /** @var int */
    public $wordlimit = 500;
    /** @var int */
    public $hard_wordlimit = 0;
    /** @var ?string */
    public $condition;
    /** @var ?SearchTerm */
    private $_condition_term;
    /** @var ?string */
    public $instructions;

    /** @param bool $with_grace
     * @return bool */
    function time_allowed($with_grace) {
        return $this->active
            && $this->open > 0
            && $this->open <= Conf::$now
            && ($this->done <= 0
                || $this->done + ($with_grace ? $this->grace : 0) >= Conf::$now);
    }

    /** @param bool $with_grace
     * @return bool */
    function can_author_respond(PaperInfo $prow, $with_grace) {
        return $this->time_allowed($with_grace)
            && $this->test_condition($prow);
    }

    /** @param PaperInfo $prow
     * @return bool */
    function test_condition($prow) {
        if ($this->condition === null) {
            return true;
        }
        if ($this->_condition_term === null) {
            $s = new PaperSearch($prow->conf->root_user(), $this->condition);
            $this->_condition_term = $s->full_term();
        }
        return $this->_condition_term->test($prow, null);
    }

    /** @return bool */
    function relevant(Contact $user, PaperInfo $prow = null) {
        if (($prow ? $user->allow_administer($prow) : $user->is_manager())
            && ($this->done || $this->condition !== null || $this->name !== "1")) {
            return true;
        } else if ($user->isPC) {
            return $this->open > 0;
        } else {
            return $this->active
                && $this->open > 0
                && $this->open < Conf::$now
                && ($this->condition === null || $this->_condition_relevant($user, $prow));
        }
    }

    /** @return bool */
    private function _condition_relevant(Contact $user, PaperInfo $prow = null) {
        foreach ($prow ? [$prow] : $user->authored_papers() as $row) {
            if ($this->test_condition($row))
                return true;
        }
        return false;
    }

    /** @return string */
    function tag_name() {
        return $this->unnamed ? "unnamedresponse" : $this->name . "response";
    }

    /** @return string */
    function instructions(Conf $conf) {
        $wl = new FmtArg("wordlimit", $this->wordlimit);
        if ($this->instructions !== null) {
            return $conf->_x($this->instructions, $wl);
        } else {
            return $conf->_i("resp_instrux", $wl) ?? "";
        }
    }
}
