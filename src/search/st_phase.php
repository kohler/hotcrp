<?php
// search/st_phase.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Phase_SearchTerm extends SearchTerm {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $user;
    /** @var bool */
    private $use_viewer;
    /** @var int */
    private $phase;

    /** @param 0|1 $phase */
    function __construct(PaperSearch $srch, $phase) {
        parent::__construct("phase");
        $this->conf = $srch->conf;
        $this->user = $srch->user;
        $this->use_viewer = $srch->use_viewer_permissions();
        $this->phase = $phase;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (strcasecmp($word, "final") === 0) {
            return new Phase_SearchTerm($srch, PaperInfo::PHASE_FINAL);
        } else if (strcasecmp($word, "review") === 0) {
            return new Phase_SearchTerm($srch, PaperInfo::PHASE_REVIEW);
        }
        $srch->lwarning($sword, "<0>Only “phase:review” and “phase:final” are allowed");
        return new False_SearchTerm;
    }
    /** @return ContactPermissions */
    function permuser() {
        if ($this->use_viewer && !$this->conf->is_updating_automatic_tags()) {
            return $this->conf->viewer() ?? $this->user;
        }
        return $this->user;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if (!$this->permuser()->can_view_some_decision()
            || $this->phase !== PaperInfo::PHASE_FINAL) {
            return "true";
        }
        return "(Paper.timeWithdrawn<=0 and Paper.outcome>0)";
    }
    function test(PaperInfo $row, $xinfo) {
        return $row->viewable_phase($this->permuser()) === $this->phase;
    }
    function about() {
        return self::ABOUT_SUB | self::ABOUT_DECISION;
    }

    /** @return ?int */
    static function term_phase(SearchTerm $st) {
        return $st->visit(function ($t, ...$vals) {
            if (empty($vals)) {
                return $t instanceof Phase_SearchTerm ? $t->phase : null;
            } else if ($t instanceof And_SearchTerm) {
                foreach ($vals as $v) {
                    if ($v !== null)
                        return $v;
                }
                return null;
            } else if ($t instanceof Not_SearchTerm) {
                return null;
            } else {
                $x = $vals[0];
                foreach ($vals as $v) {
                    if ($v !== $x)
                        return null;
                }
                return $x;
            }
        });
    }
}
