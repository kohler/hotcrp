<?php
// search/st_revpref.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class RevprefSearchMatcher extends ContactCountMatcher {
    /** @var ?CountMatcher */
    public $preference_match;
    /** @var ?CountMatcher */
    public $expertise_match;
    public $safe_contacts;
    public $is_any = false;

    /** @param string $countexpr */
    function __construct($countexpr, $contacts, $safe_contacts) {
        parent::__construct($countexpr, $contacts);
        $this->safe_contacts = $safe_contacts;
    }
    function preference_expertise_match() {
        if ($this->is_any) {
            return "(preference!=0 or expertise is not null)";
        } else {
            $where = [];
            if ($this->preference_match) {
                $where[] = "preference" . $this->preference_match->comparison();
            }
            if ($this->expertise_match) {
                $where[] = "expertise" . $this->expertise_match->comparison();
            }
            return join(" and ", $where);
        }
    }
    /** @param PaperReviewPreference $pf */
    function test_preference($pf) {
        if ($this->is_any) {
            return $pf->exists();
        } else {
            return (!$this->preference_match
                    || $this->preference_match->test($pf->preference))
                && (!$this->expertise_match
                    || ($pf->expertise !== null
                        && $this->expertise_match->test($pf->expertise)));
        }
    }
}

class Revpref_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var RevprefSearchMatcher */
    private $rpsm;

    function __construct(Contact $user, RevprefSearchMatcher $rpsm) {
        parent::__construct("revpref");
        $this->user = $user;
        $this->rpsm = $rpsm;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (!$srch->user->isPC) { // PC only
            return null;
        }

        if (preg_match('/\A((?:(?!≠|≤|≥)[^:=!<>])+)(.*)\z/s', $word, $m)
            && !ctype_digit($m[1])) {
            $contacts = $srch->matching_special_uids($m[1], $sword->quoted, true);
            if ($contacts !== null) {
                $safe_contacts = 1;
            } else {
                $safe_contacts = -1;
                $contacts = $srch->matching_uids($m[1], $sword->quoted, true);
            }
            $word = str_starts_with($m[2], ":") ? substr($m[2], 1) : $m[2];
            if ($word === "")
                $word = "any";
        } else if ($srch->user->can_view_pc()) {
            $safe_contacts = 0;
            $contacts = array_keys($srch->conf->pc_members());
        } else {
            $safe_contacts = 1;
            $contacts = [$srch->user->contactXid];
        }

        $count = "";
        if (preg_match('/\A:?\s*((?:[=!<>]=?|≠|≤|≥|)\s*\d+|any|none)\s*((?:[:=!<>]|≠|≤|≥).*)\z/si', $word, $m)) {
            if (strcasecmp($m[1], "any") == 0) {
                $count = ">0";
            } else if (strcasecmp($m[1], "none") == 0) {
                $count = "=0";
            } else if (ctype_digit($m[1])) {
                $count = (int) $m[1] ? ">=$m[1]" : "=0";
            } else {
                $count = $m[1];
            }
            $word = str_starts_with($m[2], ":") ? substr($m[2], 1) : $m[2];
        }

        if ($count === "") {
            if ($safe_contacts === 0) {
                $contacts = [$srch->user->contactXid];
                $safe_contacts = 1;
            }
            $count = ">0";
        }

        $value = new RevprefSearchMatcher($count, $contacts, $safe_contacts >= 0);
        if (strcasecmp($word, "any") == 0 || strcasecmp($word, "none") == 0) {
            $value->is_any = true;
        } else if (preg_match('/\A\s*([=!<>]=?|≠|≤|≥|)\s*(-?\d*)\s*([xyz]?)\z/is', $word, $m)
                   && ($m[2] !== "" || $m[3] !== "")) {
            if ($m[2] !== "") {
                $value->preference_match = new CountMatcher($m[1] . $m[2]);
            }
            if ($m[3] !== "") {
                $value->expertise_match = new CountMatcher(($m[2] === "" ? $m[1] : "") . (121 - ord(strtolower($m[3]))));
            }
        } else {
            return new False_SearchTerm;
        }

        return (new Revpref_SearchTerm($srch->user, $value))->negate_if(strcasecmp($word, "none") === 0);
    }

    function paper_requirements(&$options) {
        $options["allReviewerPreference"] = true;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->rpsm->test(0)
            || ($this->rpsm->preference_match
                && $this->rpsm->preference_match->test(0)
                && !$this->rpsm->expertise_match)) {
            return "true";
        } else {
            $where = ["paperId=Paper.paperId", $this->rpsm->contact_match_sql("contactId")];
            if (($match = $this->rpsm->preference_expertise_match())) {
                $where[] = $match;
            }
            return "coalesce((select count(*) from PaperReviewPreference where " . join(" and ", $where) . "),0)" . $this->rpsm->comparison();
        }
    }
    function test(PaperInfo $row, $xinfo) {
        $can_view = $this->user->can_view_preference($row, $this->rpsm->safe_contacts);
        $n = 0;
        foreach ($this->rpsm->contact_set() as $cid) {
            if (($cid == $this->user->contactXid || $can_view)
                && $this->rpsm->test_preference($row->preference($cid)))
                ++$n;
        }
        return $this->rpsm->test($n);
    }
    function about() {
        return self::ABOUT_PAPER;
    }
}
