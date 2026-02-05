<?php
// search/st_decision.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Decision_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var list<int> */
    private $decs;

    /** @param string|list<int> $match */
    function __construct(Contact $user, $match) {
        parent::__construct("decision");
        $this->user = $user;
        if (is_string($match)) {
            $this->decs = [];
            foreach ($user->conf->decision_set()->filter_using($match) as $d) {
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
            return new Decision_SearchTerm($srch->user, [-10000000]);
        }
        $st = new Decision_SearchTerm($srch->user, $decs);
        $lim = new Limit_SearchTerm($srch, "dec:" . SearchWord::quote($word), true);
        $st->set_float("xlimit", $lim);
        return $st;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $f = ["Paper.outcome" . CountMatcher::sqlexpr_using($this->decs)];
        if (in_array(0, $this->decs, true)
            && !$this->user->allow_administer_all()) {
            $f[] = "Paper.outcome=0";
        }
        return "(" . join(" or ", $f) . ")";
    }
    function test(PaperInfo $row, $xinfo) {
        $d = $this->user->can_view_decision($row) ? $row->outcome : 0;
        return in_array($d, $this->decs, true);
    }
    function about() {
        return self::ABOUT_PAPER;
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
