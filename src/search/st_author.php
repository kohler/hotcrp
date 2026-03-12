<?php
// search/st_author.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Author_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var ContactCountMatcher */
    private $csm;
    /** @var ?TextPregexes */
    private $regex;
    /** @var bool */
    private $listed;

    function __construct(Contact $user, $countexpr, $contacts) {
        parent::__construct("au");
        $this->user = $user;
        $this->csm = new ContactCountMatcher($countexpr, $contacts);
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $count = ">0";
        if (preg_match('/\A(.*?)(?::|\A|(?=[^\d]))((?:[=!<>]=?|≠|≤|≥|)\d+)\z/s', $word, $m)) {
            $word = $m[1];
            $count = $m[2];
        }
        $cids = null;
        if ($sword->kwexplicit && !$sword->quoted) {
            if ($word === "any") {
                $word = null;
            } else if ($word === "none" && $count === ">0") {
                $word = null;
                $count = "=0";
            } else if ($word !== "") {
                $cids = $srch->matching_special_uids($word, false, false);
            }
        }
        $aust = new Author_SearchTerm($srch->user, $count, $cids);
        if (!$cids && $word !== "") {
            $aust->regex = Text::star_text_pregexes($word, $sword->quoted);
            $aust->set_float("fhl:au", $aust->regex);
        }
        if ($sword->kwexplicit && ($sword->kwdef->listed ?? false)) {
            $aust->listed = true;
        }
        return $aust;
    }
    function paper_requirements(&$options) {
        if ($this->csm->has_contacts()) {
            $options["allConflictType"] = true;
        }
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->csm->has_contacts() && !$this->csm->test(0)) {
            return "exists (select * from PaperConflict where PaperConflict.paperId=Paper.paperId and " . $this->csm->contact_match_sql("contactId") . " and conflictType>=" . CONFLICT_AUTHOR . ")";
        } else if ($this->csm->has_contacts()) {
            $sqi->add_allConflictType_column();
            return "true";
        }
        $sqi->add_column("authorInformation", "Paper.authorInformation");
        return $this->csm->test(0) ? "true" : "Paper.authorInformation!=''";
    }
    function is_sqlexpr_precise() {
        return $this->csm->single_cid() === $this->user->contactId
            && !$this->csm->test(0);
    }
    /** @param null|Author|Contact $au
     * @return bool */
    private function regex_match($au) {
        return $au !== null && $this->regex->match($au->name(NAME_E|NAME_A));
    }
    function test(PaperInfo $row, $xinfo) {
        // XXX presence condition
        $n = 0;
        $can_view = $this->user->allow_view_authors($row);
        $listed = $this->listed;
        if ($this->csm->has_contacts()) {
            foreach ($this->csm->contact_set() as $cid) {
                if ((!$can_view && $cid !== $this->user->contactXid)
                    || ($listed ? !$row->has_listed_author($cid) : !$row->has_author($cid))) {
                    continue;
                }
                ++$n;
            }
        } else if (!$can_view) {
            // $n is always 0
        } else if (!$this->regex) {
            $n = count($row->author_list());
        } else {
            foreach ($row->conflict_list() as $co) {
                if ($co->conflictType < CONFLICT_AUTHOR
                    || (($listed || !$this->regex_match($co->user))
                        && !$this->regex_match($co->author($row)))) {
                    continue;
                }
                ++$n;
            }
        }
        return $this->csm->test($n);
    }
    function script_expression(PaperInfo $row, $about) {
        if ($this->csm->has_contacts()
            || $this->regex
            || ($about & self::ABOUT_PAPER) === 0) {
            return $this->test($row, null);
        }
        return ["type" => "compar", "compar" => $this->csm->relation(), "child" => [
            ["type" => "author_count"],
            $this->csm->value()
        ]];
    }
}
