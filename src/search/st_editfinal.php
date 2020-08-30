<?php
// search/st_editfinal.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class EditFinal_SearchTerm extends SearchTerm {
    function __construct() {
        parent::__construct("editfinal");
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (strcasecmp($word, "yes") === 0) {
            return new EditFinal_SearchTerm;
        } else if (strcasecmp($word, "no") === 0) {
            return (new EditFinal_SearchTerm)->negate();
        } else {
            $srch->warn("Only “editfinal:yes” and “editfinal:no” allowed.");
            return new False_SearchTerm;
        }
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        return "(Paper.outcome>0)";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return $row->can_author_edit_final_paper();
    }
}
