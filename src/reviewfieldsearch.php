<?php
// reviewfieldsearch.php -- HotCRP classes for searching review fields
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

/** @template T */
abstract class ReviewFieldSearch {
    /** @var T
     * @readonly */
    public $rf;
    /** @var int */
    public $finished;

    /** @param T $rf */
    function __construct($rf) {
        $this->rf = $rf;
    }

    /** @return ?string */
    abstract function sqlexpr();

    function prepare() {
        $this->finished = 0;
    }

    /** @param Contact $user
     * @param PaperInfo $prow
     * @param ReviewInfo $rrow
     * @return bool */
    abstract function test_review($user, $prow, $rrow);

    /** @return ?ReviewFieldSearch */
    static function parse(SearchWord $sword, ReviewField $rf,
                          ReviewSearchMatcher $rsm, PaperSearch $srch) {
        if ($sword->cword === "any") {
            return new Present_ReviewFieldSearch($rf, true);
        } else if ($sword->cword === "none") {
            return new Present_ReviewFieldSearch($rf, false);
        } else {
            $nmsg = $srch->message_set()->message_count();
            if (($st = $rf->parse_search($sword, $rsm, $srch))) {
                return $st;
            } else {
                if ($srch->message_set()->message_count() === $nmsg) {
                    $srch->lwarning($sword, "<0>Review field ‘" . $rf->name . "’ does not support this search");
                }
                return null;
            }
        }
    }
}

/** @inherits ReviewFieldSearch<ReviewField> */
class Present_ReviewFieldSearch extends ReviewFieldSearch {
    /** @var bool */
    public $present;

    /** @param bool $present */
    function __construct(ReviewField $rf, $present) {
        parent::__construct($rf);
        $this->present = $present;
    }

    function sqlexpr() {
        if (!$this->present) {
            return null;
        } else if ($this->rf->main_storage) {
            return "{$this->rf->main_storage}>0";
        } else if ($this->rf->is_sfield) {
            return "sfields is not null";
        } else {
            return "tfields is not null";
        }
    }

    function test_review($user, $prow, $rrow) {
        $fv = $rrow->fval($this->rf);
        return $this->rf->value_present($fv) === $this->present;
    }
}
