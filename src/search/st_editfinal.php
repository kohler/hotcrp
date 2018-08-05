<?php
// search/st_editfinal.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class EditFinal_SearchTerm extends SearchTerm {
    function __construct() {
        parent::__construct("editfinal");
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (strcasecmp($word, "yes") === 0)
            return new EditFinal_SearchTerm;
        else if (strcasecmp($word, "no") === 0)
            return SearchTerm::make_not(new EditFinal_SearchTerm);
        else {
            $srch->warn("Only “editfinal:yes” and “editfinal:no” allowed.");
            return new False_SearchTerm;
        }
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_column("outcome", "Paper.outcome");
        return "(Paper.outcome>0)";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return $row->outcome > 0
            && $srch->conf->collectFinalPapers()
            && $srch->conf->time_submit_final_version()
            && Contact::can_some_author_view_decision($row);
    }
    function compile_edit_condition(PaperInfo $row, PaperSearch $srch) {
        return $this->exec($row, $srch);
    }
}
