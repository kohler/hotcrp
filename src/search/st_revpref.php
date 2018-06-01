<?php
// search/st_revpref.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class RevprefSearchMatcher extends ContactCountMatcher {
    public $preference_match = null;
    public $expertise_match = null;
    public $safe_contacts;
    public $is_any = false;

    function __construct($countexpr, $contacts, $safe_contacts) {
        parent::__construct($countexpr, $contacts);
        $this->safe_contacts = $safe_contacts;
    }
    function preference_expertise_match() {
        if ($this->is_any)
            return "(preference!=0 or expertise is not null)";
        $where = [];
        if ($this->preference_match)
            $where[] = "preference" . $this->preference_match->countexpr();
        if ($this->expertise_match)
            $where[] = "expertise" . $this->expertise_match->countexpr();
        return join(" and ", $where);
    }
    function test_preference($pref) {
        if ($this->is_any)
            return $pref[0] != 0 || $pref[1] !== null;
        else
            return (!$this->preference_match
                    || $this->preference_match->test($pref[0]))
                && (!$this->expertise_match
                    || ($pref[1] !== null
                        && $this->expertise_match->test($pref[1])));
    }
}

class Revpref_SearchTerm extends SearchTerm {
    private $rpsm;

    function __construct(RevprefSearchMatcher $rpsm) {
        parent::__construct("revpref");
        $this->rpsm = $rpsm;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (!$srch->user->isPC) // PC only
            return null;

        if (preg_match('/\A((?:(?!≠|≤|≥)[^:=!<>])+)(.*)\z/s', $word, $m)
            && !ctype_digit($m[1])) {
            $contacts = $srch->matching_special_contacts($m[1], $sword->quoted, true);
            if ($contacts !== null)
                $safe_contacts = 1;
            else {
                $safe_contacts = -1;
                $contacts = $srch->matching_users($m[1], $sword->quoted, true);
            }
            $word = str_starts_with($m[2], ":") ? substr($m[2], 1) : $m[2];
            if ($word === "")
                $word = "any";
        } else if ($srch->user->can_view_pc()) {
            $safe_contacts = 0;
            $contacts = array_keys($srch->conf->pc_members());
        } else {
            $safe_contacts = 1;
            $contacts = [$srch->cid];
        }

        $count = "";
        if (preg_match('/\A:?\s*((?:[=!<>]=?|≠|≤|≥|)\s*\d+|any|none)\s*((?:[:=!<>]|≠|≤|≥).*)\z/si', $word, $m)) {
            if (strcasecmp($m[1], "any") == 0)
                $count = ">0";
            else if (strcasecmp($m[1], "none") == 0)
                $count = "=0";
            else if (ctype_digit($m[1]))
                $count = (int) $m[1] ? ">=$m[1]" : "=0";
            else
                $count = $m[1];
            $word = str_starts_with($m[2], ":") ? substr($m[2], 1) : $m[2];
        }

        if ($count === "") {
            if ($safe_contacts === 0) {
                $contacts = [$srch->cid];
                $safe_contacts = 1;
            }
            $count = ">0";
        }

        $value = new RevprefSearchMatcher($count, $contacts, $safe_contacts >= 0);
        if (strcasecmp($word, "any") == 0 || strcasecmp($word, "none") == 0)
            $value->is_any = true;
        else if (preg_match(',\A\s*([=!<>]=?|≠|≤|≥|)\s*(-?\d*)\s*([xyz]?)\z,i', $word, $m)
                 && ($m[2] !== "" || $m[3] !== "")) {
            if ($m[2] !== "")
                $value->preference_match = new CountMatcher($m[1] . $m[2]);
            if ($m[3] !== "")
                $value->expertise_match = new CountMatcher(($m[2] === "" ? $m[1] : "") . (121 - ord(strtolower($m[3]))));
        } else
            return new False_SearchTerm;

        $qz = new Revpref_SearchTerm($value);
        if (strcasecmp($word, "none") == 0)
            $qz = SearchTerm::make_not($qz);
        return $qz;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->rpsm->preference_match
            && $this->rpsm->preference_match->test(0)
            && !$this->rpsm->expertise_match)
            return "true";
        $where = [$this->rpsm->contact_match_sql("contactId")];
        if (($match = $this->rpsm->preference_expertise_match()))
            $where[] = $match;
        $q = "select paperId, count(PaperReviewPreference.preference) as count"
            . " from PaperReviewPreference";
        if (count($where))
            $q .= " where " . join(" and ", $where);
        $q .= " group by paperId";
        $thistab = "Revpref_" . count($sqi->tables);
        $sqi->add_table($thistab, array("left join", "($q)"));
        return "coalesce($thistab.count,0)" . $this->rpsm->countexpr();
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $can_view = $srch->user->allow_administer($row)
            || ($this->rpsm->safe_contacts && $srch->user->act_pc($row));
        $n = 0;
        foreach ($this->rpsm->contact_set() as $cid) {
            if (($cid == $srch->cid || $can_view)
                && $this->rpsm->test_preference($row->reviewer_preference($cid)))
                ++$n;
        }
        return $this->rpsm->test($n);
    }
}
