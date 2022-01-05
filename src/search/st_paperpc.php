<?php
// search/st_paperpc.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class PaperPC_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    private $kind;
    private $fieldname;
    private $match;

    function __construct(Contact $user, $kind, $match) {
        parent::__construct("paperpc");
        $this->user = $user;
        $this->kind = $kind;
        $this->fieldname = $kind . "ContactId";
        $this->match = $match;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (($word === "any" || $word === "" || $word === "yes") && !$sword->quoted) {
            $match = "!=0";
        } else if (($word === "none" || $word === "no") && !$sword->quoted) {
            $match = "=0";
        } else {
            $match = $srch->matching_uids($word, $sword->quoted, true);
        }
        // XXX what about track admin privilege?
        $qt = [new PaperPC_SearchTerm($srch->user, $sword->kwdef->pcfield, $match)];
        if ($sword->kwdef->pcfield === "manager"
            && $word === "me"
            && !$sword->quoted
            && $srch->user->privChair) {
            $qt[] = new PaperPC_SearchTerm($srch->user, $qt[0]->kind, "=0");
        }
        return $qt;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_column($this->fieldname, "Paper.{$this->fieldname}");
        return "(Paper.{$this->fieldname}" . CountMatcher::sqlexpr_using($this->match) . ")";
    }
    function test(PaperInfo $row, $rrow) {
        $can_view = "can_view_{$this->kind}";
        return $this->user->$can_view($row)
            && CountMatcher::compare_using($row->{$this->fieldname}, $this->match);
    }
}
