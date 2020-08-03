<?php
// search/st_authormatch.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class AuthorMatch_SearchTerm extends SearchTerm {
    private $matcher;

    function __construct($type, $matcher) {
        parent::__construct($type);
        $this->matcher = $matcher;
    }
    static function parse($word, SearchWord $sword) {
        $type = $sword->kwdef->name;
        if ($word === "any" && $sword->kwexplicit && !$sword->quoted) {
            $type = substr($type, 0, 2);
            return new TextMatch_SearchTerm($type === "co" ? "co" : "au", true, false);
        } else if (($matcher = AuthorMatcher::make_string_guess($word))) {
            return new AuthorMatch_SearchTerm($type, $matcher);
        } else {
            return new False_SearchTerm;
        }
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->type !== "comatch") {
            $sqi->add_column("authorInformation", "Paper.authorInformation");
        }
        if ($this->type !== "aumatch") {
            $sqi->add_column("collaborators", "Paper.collaborators");
        }
        if ($this->type === "aumatch") {
            return "Paper.authorInformation!=''";
        } else if ($this->type === "comatch") {
            return "Paper.collaborators!=''";
        } else {
            return "(Paper.authorInformation!='' or Paper.collaborators!='')";
        }
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        if (!$srch->user->allow_view_authors($row))
            return false;
        $anymatch = false;
        if ($this->type !== "comatch"
            && $row->field_match_pregexes($this->matcher->general_pregexes(), "authorInformation")) {
            foreach ($row->author_list() as $au) {
                if ($this->matcher->test($au, true))
                    return true;
            }
        }
        if ($this->type !== "aumatch"
            && $row->field_match_pregexes($this->matcher->general_pregexes(), "collaborators")) {
            foreach ($row->collaborator_list() as $au) {
                if ($this->matcher->test($au, true))
                    return true;
            }
        }
        return false;
    }
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        if ($this->type !== "comatch") {
            $srch->regex["au"][] = $this->matcher->general_pregexes();
        }
        if ($this->type !== "aumatch") {
            $srch->regex["co"][] = $this->matcher->general_pregexes();
        }
    }
}
