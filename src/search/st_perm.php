<?php
// search/st_perm.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Perm_SearchTerm extends SearchTerm {
    /** @var string */
    private $perm;
    /** @param string $perm */
    function __construct($perm) {
        parent::__construct("perm");
        $this->perm = $perm;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (strcasecmp($word, "author-edit") === 0
            || strcasecmp($word, "author-write") === 0) {
            return new Perm_SearchTerm("author-write");
        } else if (strcasecmp($word, "author-edit-final") === 0
                   || strcasecmp($word, "author-write-final") === 0) {
            return new Perm_SearchTerm("author-write-final");
        } else {
            $srch->warn("Unknown permission.");
            return new False_SearchTerm;
        }
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->perm === "author-write-final") {
            return "(Paper.timeWithdrawn<=0 and Paper.outcome>0)";
        } else {
            return "(Paper.timeWithdrawn<=0)";
        }
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        if ($this->perm === "author-write") {
            return $row->can_author_edit_paper();
        } else if ($this->perm === "author-write-final") {
            return $row->can_author_edit_final_paper()
                && $srch->user->can_view_decision($row);
        } else {
            return false;
        }
    }
}
