<?php
// search/st_authormatch.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class AuthorMatch_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var AuthorMatcher */
    private $matcher;

    function __construct(Contact $user, $type, $matcher) {
        parent::__construct($type);
        $this->user = $user;
        $this->matcher = $matcher;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $type = $sword->kwdef->name;
        if ($word === "any" && $sword->kwexplicit && !$sword->quoted) {
            $type = substr($type, 0, 2);
            return new TextMatch_SearchTerm($srch->user, $type === "co" ? "co" : "au", true, false);
        } else if (($matcher = AuthorMatcher::make_string_guess($word))) {
            return new AuthorMatch_SearchTerm($srch->user, $type, $matcher);
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
    function test(PaperInfo $row, $xinfo) {
        if (!$this->user->allow_view_authors($row)) {
            return false;
        }
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
    function prepare_visit($param, PaperSearch $srch) {
        if ($param->want_field_highlighter()) {
            if ($this->type !== "comatch") {
                $srch->add_field_highlighter("au", $this->matcher->general_pregexes());
            }
            if ($this->type !== "aumatch") {
                $srch->add_field_highlighter("co", $this->matcher->general_pregexes());
            }
        }
    }
    function about() {
        return self::ABOUT_PAPER;
    }
}
