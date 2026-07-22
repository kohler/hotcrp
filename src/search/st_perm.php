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
        $word = str_replace("-", "_", $word);
        if (strcasecmp($word, "author_edit") === 0
            || strcasecmp($word, "author_write") === 0) {
            return new Perm_SearchTerm($srch->user, "author_write");
        } else if (strcasecmp($word, "author_edit_final") === 0
                   || strcasecmp($word, "author_write_final") === 0) {
            return new Perm_SearchTerm($srch->user, "author_write_final");
        }
        $srch->lwarning($sword, "<0>Permission not found");
        return new False_SearchTerm;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if (!$this->user->is_admin()) {
            return "false";
        } else if ($this->perm === "author_write_final") {
            return "(Paper.timeWithdrawn<=0 and Paper.outcome>0)";
        }
        return "(Paper.timeWithdrawn<=0)";
    }
    function test(PaperInfo $row, $xinfo) {
        if (!$this->user->allow_admin($row)) {
            // Only administrators can use `perm:`, since it can expose
            // information about the underlying paper
            return false;
        } else if ($this->perm === "author_write") {
            return $row->author_edit_state() !== 0;
        } else if ($this->perm === "author_write_final") {
            return $row->author_edit_state() === 2
                && $this->user->can_view_decision($row);
        }
        return false;
    }
}
