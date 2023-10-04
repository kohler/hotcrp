<?php
// search/st_editfinal.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
            $srch->lwarning($sword, "<0>Only “editfinal:yes” and “editfinal:no” are allowed");
            return new False_SearchTerm;
        }
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        return "(Paper.outcome>0)";
    }
    function test(PaperInfo $row, $xinfo) {
        return $row->author_edit_state() === 2;
    }
    function about() {
        return self::ABOUT_PAPER;
    }
}
