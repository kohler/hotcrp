<?php
// search/st_conflict.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Conflict_SearchTerm extends SearchTerm {
    private $csm;
    private $ispc;

    function __construct($countexpr, $contacts, $ispc) {
        parent::__construct("conflict");
        $this->csm = new ContactCountMatcher($countexpr, $contacts);
        $this->ispc = $ispc;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $m = PaperSearch::unpack_comparison($word, $sword->quoted);
        if (($qr = PaperSearch::check_tautology($m[1])))
            return $qr;
        else {
            $contacts = $srch->matching_uids($m[0], $sword->quoted, $sword->kwdef->pc_only);
            return new Conflict_SearchTerm($m[1], $contacts, $sword->kwdef->pc_only);
        }
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        return $this->csm->has_sole_contact($user->contactId);
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $thistab = "Conflict_" . count($sqi->tables);
        $where = $this->csm->contact_match_sql("$thistab.contactId");

        $compar = $this->csm->simplified_nonnegative_countexpr();
        if ($compar !== ">0" && $compar !== "=0") {
            $sqi->add_table($thistab, ["left join", "(select paperId, count(*) ct from PaperConflict $thistab where $where group by paperId)"]);
            return "coalesce($thistab.ct,0)$compar";
        } else {
            $sqi->add_table($thistab, ["left join", "PaperConflict", $where]);
            if ($compar === "=0")
                return "$thistab.contactId is null";
            else
                return "$thistab.contactId is not null";
        }
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $can_view = $srch->user->can_view_conflicts($row);
        $n = 0;
        foreach ($this->csm->contact_set() as $cid) {
            if (($cid == $srch->cid || $can_view)
                && $row->has_conflict($cid))
                ++$n;
        }
        return $this->csm->test($n);
    }
    function compile_condition(PaperInfo $row, PaperSearch $srch) {
        if (!$this->ispc)
            return null;
        if (!$srch->conf->setting("sub_pcconf"))
            return $this->exec($row, $srch);
        return (object) ["type" => "pc_conflict", "cids" => $this->csm->contact_set(), "compar" => $this->csm->compar(), "value" => $this->csm->value()];
    }
}
