<?php
// search/st_author.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Author_SearchTerm extends SearchTerm {
    private $csm;
    private $regex;

    function __construct($countexpr, $contacts, $match, $quoted) {
        parent::__construct("au");
        $this->csm = new ContactCountMatcher($countexpr, $contacts);
        if (!$contacts && $match) {
            $this->regex = Text::star_text_pregexes($match, $quoted);
        }
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
            } else if (trim($word) !== "") {
                $cids = $srch->matching_special_uids($word, false, false);
            }
        }
        return new Author_SearchTerm($count, $cids, $word, $sword->quoted);
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        return $this->csm->has_sole_contact($user->contactId)
            && !$this->csm->test(0);
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->csm->has_contacts() && !$this->csm->test(0)) {
            return "exists (select * from PaperConflict where PaperConflict.paperId=Paper.paperId and " . $this->csm->contact_match_sql("contactId") . " and conflictType>=" . CONFLICT_AUTHOR . ")";
        } else if ($this->csm->has_contacts()) {
            $sqi->add_allConflictType_column();
            return "true";
        } else {
            $sqi->add_column("authorInformation", "Paper.authorInformation");
            return $this->csm->test(0) ? "true" : "Paper.authorInformation!=''";
        }
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $n = 0;
        $can_view = $srch->user->allow_view_authors($row);
        if ($this->csm->has_contacts()) {
            foreach ($this->csm->contact_set() as $cid) {
                if (($cid === $srch->cxid || $can_view)
                    && $row->has_author($cid))
                    ++$n;
            }
        } else if ($can_view) {
            foreach ($row->author_list() as $au) {
                if ($this->regex) {
                    $text = $au->name(NAME_E|NAME_A);
                    if (!Text::match_pregexes($this->regex, $text,
                                              UnicodeHelper::deaccent($text))) {
                        continue;
                    }
                }
                ++$n;
            }
        }
        return $this->csm->test($n);
    }
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        if ($this->regex) {
            $srch->regex["au"][] = $this->regex;
        }
    }
}
