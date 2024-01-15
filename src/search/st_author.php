<?php
// search/st_author.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Author_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var ContactCountMatcher */
    private $csm;
    /** @var ?TextPregexes */
    private $regex;

    function __construct(Contact $user, $countexpr, $contacts, $match, $quoted) {
        parent::__construct("au");
        $this->user = $user;
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
        return new Author_SearchTerm($srch->user, $count, $cids, $word, $sword->quoted);
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
        } else {
            $sqi->add_column("authorInformation", "Paper.authorInformation");
            return $this->csm->test(0) ? "true" : "Paper.authorInformation!=''";
        }
    }
    function is_sqlexpr_precise() {
        return $this->csm->single_cid() === $this->user->contactId
            && !$this->csm->test(0);
    }
    function test(PaperInfo $row, $xinfo) {
        $n = 0;
        $can_view = $this->user->allow_view_authors($row);
        if ($this->csm->has_contacts()) {
            foreach ($this->csm->contact_set() as $cid) {
                if (($cid === $this->user->contactXid || $can_view)
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
    function prepare_visit($param, PaperSearch $srch) {
        if ($param->want_field_highlighter() && $this->regex) {
            $srch->add_field_highlighter("au", $this->regex);
        }
    }
    function script_expression(PaperInfo $row, $about) {
        if ($this->csm->has_contacts()
            || $this->regex
            || $about !== self::ABOUT_PAPER) {
            return $this->test($row, null);
        } else {
            return ["type" => "compar", "compar" => $this->csm->relation(), "child" => [
                ["type" => "author_count"],
                $this->csm->value()
            ]];
        }
    }
    function about() {
        return self::ABOUT_PAPER;
    }
}
