<?php
// search/st_author.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2017 Eddie Kohler; see LICENSE.

class Author_SearchTerm extends SearchTerm {
    private $csm;
    private $regex;

    function __construct($countexpr, $contacts, $match, $quoted) {
        parent::__construct("au");
        $this->csm = new ContactCountMatcher($countexpr, $contacts);
        if (!$contacts && $match)
            $this->regex = Text::star_text_pregexes($match, $quoted);
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $count = ">0";
        if (preg_match('/\A(.*?)(?::|\A|(?=[^\d]))((?:[=!<>]=?|≠|≤|≥|)\d+)\z/s', $word, $m)) {
            $word = $m[1];
            $count = $m[2];
        }
        $cids = null;
        if ($sword->kwexplicit && !$sword->quoted) {
            if ($word === "any")
                $word = null;
            else if ($word === "none" && $count === ">0") {
                $word = null;
                $count = "=0";
            } else if (trim($word) !== "")
                $cids = $srch->matching_special_contacts($word, false, false);
        }
        return new Author_SearchTerm($count, $cids, $word, $sword->quoted);
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        return $this->csm->has_sole_contact($user->contactId);
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->csm->has_contacts() && $this->csm->countexpr() === ">0") {
            $thistab = "AuthorConflict_" . count($sqi->tables);
            $sqi->add_table($thistab, ["left join", "(select paperId, 1 present from PaperConflict where " . $this->csm->contact_match_sql("contactId") . " and conflictType>=" . CONFLICT_AUTHOR . " group by paperId)"]);
            return "$thistab.present is not null";
        } else if ($this->csm->has_contacts()) {
            $sqi->add_allConflictType_column();
            if ($this->csm->test(0))
                return "true";
            else
                return "AllConflict.allConflictType is not null";
        } else {
            $sqi->add_column("authorInformation", "Paper.authorInformation");
            if ($this->csm->test(0))
                return "true";
            else
                return "Paper.authorInformation!=''";
        }
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $n = 0;
        $can_view = $srch->user->allow_view_authors($row);
        if ($this->csm->has_contacts()) {
            foreach ($this->csm->contact_set() as $cid) {
                if (($cid === $srch->cid || $can_view)
                    && $row->has_author($cid))
                    ++$n;
            }
        } else if ($can_view) {
            foreach ($row->author_list() as $au) {
                if ($this->regex) {
                    $text = $au->name_email_aff_text();
                    if (!Text::match_pregexes($this->regex, $text,
                                              UnicodeHelper::deaccent($text)))
                        continue;
                }
                ++$n;
            }
        }
        return $this->csm->test($n);
    }
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        if ($this->regex)
            $srch->regex["au"][] = $this->regex;
    }
}

class AuthorMatch_SearchTerm extends SearchTerm {
    private $field;
    private $matcher;

    function __construct($type, $matcher) {
        parent::__construct($type);
        $this->field = TextMatch_SearchTerm::$map[substr($type, 0, 2)];
        $this->matcher = $matcher;
    }
    static function parse($word, SearchWord $sword) {
        $type = $sword->kwdef->name;
        if ($word === "any" && $sword->kwexplicit && !$sword->quoted)
            return new TextMatch_SearchTerm(substr($type, 0, 2), true, false);
        $matcher = new PaperInfo_AuthorMatcher($word);
        if ($matcher->general_pregexes)
            return new AuthorMatch_SearchTerm($type, $matcher);
        else
            return new False_SearchTerm;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_column($this->field, "Paper.{$this->field}");
        return "Paper.{$this->field}!=''";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $field = $this->field;
        if ($row->$field === ""
            || !$srch->user->allow_view_authors($row))
            return false;
        if (!$row->field_match_pregexes($this->matcher->general_pregexes, $field))
            return false;
        $l = $this->type === "aumatch" ? $row->author_list() : $row->collaborator_list();
        foreach ($l as $au)
            if ($this->matcher->test($au))
                return true;
        return false;
    }
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        $srch->regex[substr($this->type, 0, 2)][] = $this->matcher->general_pregexes;
    }
}
