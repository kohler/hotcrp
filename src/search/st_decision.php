<?php
// search/st_decision.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Decision_SearchTerm extends SearchTerm {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $user;
    /** @var bool */
    private $use_viewer;
    /** @var list<int> */
    private $decs;

    /** @param string|list<int> $match */
    function __construct(PaperSearch $srch, $match) {
        parent::__construct("decision");
        $this->conf = $srch->conf;
        $this->user = $srch->user;
        $this->use_viewer = $srch->use_viewer_permissions();
        if (is_string($match)) {
            $this->decs = [];
            foreach ($this->conf->decision_set()->filter_using($match) as $d) {
                $this->decs[] = $d->id;
            }
        } else {
            $this->decs = $match;
        }
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $decs = $srch->conf->decision_set()->match($word);
        if (empty($decs)) {
            $srch->lwarning($sword, "<0>Decision not found");
            return new Decision_SearchTerm($srch, [-10000000]);
        }
        $lim = new Limit_SearchTerm($srch, "dec:" . SearchWord::quote($word));
        $lim->set_implicit();
        $st = new Decision_SearchTerm($srch, $decs);
        $st->set_float("xlimit", $lim);
        return $st;
    }
    /** @return ContactPermissions */
    function permuser() {
        if ($this->use_viewer && !$this->conf->is_updating_automatic_tags()) {
            return $this->conf->viewer() ?? $this->user;
        }
        return $this->user;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $pu = $this->permuser();
        if (!$pu->can_view_some_decision()) {
            return in_array(0, $this->decs, true) ? "true" : "false";
        } else if (in_array(0, $this->decs, true)
                   && !$pu->can_view_all_decision()) {
            return "true";
        }
        return "Paper.outcome" . CountMatcher::sqlexpr_using($this->decs);
    }
    function is_sqlexpr_precise() {
        return $this->permuser()->can_view_all_decision();
    }
    function test(PaperInfo $row, $xinfo) {
        $d = $this->permuser()->can_view_decision($row) ? $row->outcome : 0;
        return in_array($d, $this->decs, true);
    }
    function about() {
        return self::ABOUT_DECISION;
    }
    function drag_assigners(Contact $user) {
        if (count($this->decs) !== 1 || !$user->can_set_some_decision()) {
            return null;
        }
        $d = $user->conf->decision_set()->get($this->decs[0]);
        return [
            ["action" => "decision", "decision" => $d->name, "ondrag" => "enter"],
            ["action" => "decision", "decision" => "none", "ondrag" => "leave"]
        ];
    }
}
