<?php
// search/st_conflict.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Conflict_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var ContactCountMatcher */
    private $csm;
    private $ispc;

    function __construct(Contact $user, $countexpr, $contacts, $ispc) {
        parent::__construct("conflict");
        $this->user = $user;
        $this->csm = new ContactCountMatcher($countexpr, $contacts);
        $this->ispc = $ispc;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $a = CountMatcher::unpack_search_comparison($sword->qword);
        $compar = CountMatcher::unparse_comparison($a[1], $a[2]);
        if (($qr = PaperSearch::check_tautology($compar))) {
            return $qr;
        } else {
            $contacts = $srch->matching_uids($a[0], $sword->quoted, $sword->kwdef->pc_only);
            return new Conflict_SearchTerm($srch->user, $compar, $contacts, $sword->kwdef->pc_only);
        }
    }
    function is_sqlexpr_precise() {
        return $this->csm->has_sole_contact($this->user->contactId);
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $thistab = "Conflict_" . count($sqi->tables);
        $where = $this->csm->contact_match_sql("$thistab.contactId");

        $compar = $this->csm->simplified_nonnegative_comparison();
        if ($compar !== ">0" && $compar !== "=0") {
            $sqi->add_table($thistab, ["left join", "(select paperId, count(*) ct from PaperConflict $thistab where $where group by paperId)"]);
            return "coalesce($thistab.ct,0)$compar";
        } else {
            $sqi->add_table($thistab, ["left join", "PaperConflict", $where]);
            if ($compar === "=0") {
                return "$thistab.contactId is null";
            } else {
                return "$thistab.contactId is not null";
            }
        }
    }
    function test(PaperInfo $row, $rrow) {
        $can_view = $this->user->can_view_conflicts($row);
        $n = 0;
        foreach ($this->csm->contact_set() as $cid) {
            if (($cid == $this->user->contactXid || $can_view)
                && $row->has_conflict($cid))
                ++$n;
        }
        return $this->csm->test($n);
    }
    function script_expression(PaperInfo $row) {
        if (!$this->ispc) {
            return null;
        } else if (!$this->user->conf->setting("sub_pcconf")) {
            return $this->test($row, null);
        } else {
            return ["type" => "pc_conflict", "cids" => $this->csm->contact_set(), "compar" => $this->csm->relation(), "value" => $this->csm->value()];
        }
    }
    function about_reviews() {
        return self::ABOUT_NO;
    }
}
