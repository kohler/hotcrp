<?php
// search/st_perm.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Perm_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var string */
    private $perm;
    /** @param string $perm */
    function __construct(Contact $user, $perm) {
        parent::__construct("perm");
        $this->user = $user;
        $this->perm = $perm;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (strcasecmp($word, "author-edit") === 0
            || strcasecmp($word, "author-write") === 0) {
            return new Perm_SearchTerm($srch->user, "author-write");
        } else if (strcasecmp($word, "author-edit-final") === 0
                   || strcasecmp($word, "author-write-final") === 0) {
            return new Perm_SearchTerm($srch->user, "author-write-final");
        } else {
            $srch->lwarning($sword, "<0>Permission not found");
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
    function test(PaperInfo $row, $rrow) {
        if ($this->perm === "author-write") {
            return $row->can_author_edit_paper();
        } else if ($this->perm === "author-write-final") {
            return $row->can_author_edit_final_paper()
                && $this->user->can_view_decision($row);
        } else {
            return false;
        }
    }
}
