<?php
// search/st_conflict.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

final class Conflict_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var ContactCountMatcher */
    private $ccm;
    /** @var bool */
    private $ispc;
    /** @var bool */
    private $self;

    /** @param ContactCountMatcher $ccm
     * @param bool $ispc */
    function __construct(Contact $user, $ccm, $ispc) {
        assert($ccm->has_contacts() && count($ccm->contact_set()) > 0);
        parent::__construct("conflict");
        $this->user = $user;
        $this->ccm = $ccm;
        $this->ispc = $ispc;
        $this->self = $ccm->single_cid() === $user->contactXid;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $a = CountMatcher::unpack_search_comparison($sword->qword);
        $contacts = $srch->matching_uids($a[0], $sword->quoted, $sword->kwdef->pc_only);
        $ccm = new ContactCountMatcher(CountMatcher::unparse_comparison($a[1], $a[2]), $contacts);
        if (($qr = SearchTerm::make_constant($ccm->tautology()))) {
            return $qr;
        } else {
            return new Conflict_SearchTerm($srch->user, $ccm, $sword->kwdef->pc_only);
        }
    }
    function merge(SearchTerm $st) {
        if ($st instanceof Conflict_SearchTerm
            && $this->ccm->simplified_nonnegative_comparison() === ">0"
            && $st->ccm->simplified_nonnegative_comparison() === ">0"
            && $this->user === $st->user) {
            foreach ($st->ccm->contact_set() as $cid) {
                $this->ccm->add_contact($cid);
            }
            $this->ispc = $this->ispc && $st->ispc;
            $this->self = $this->self && $st->self;
            return true;
        } else {
            return false;
        }
    }
    function paper_requirements(&$options) {
        if (!$this->self) {
            $options["allConflictType"] = true;
        }
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if (!$this->self) {
            $sqi->add_allConflictType_column();
        }
        $snc = $this->ccm->simplified_nonnegative_comparison();
        $cidsql = $this->ccm->contact_match_sql("contactId");
        if ($snc === ">0" || $snc === "=0") {
            $n = $snc === "=0" ? "not exists" : "exists";
            return "{$n} (select * from PaperConflict where paperId=Paper.paperId and {$cidsql})";
        } else {
            return "coalesce((select count(*) from PaperConflict where paperId=Paper.paperId and {$cidsql}),0){$snc}";
        }
    }
    function is_sqlexpr_precise() {
        return $this->self;
    }
    function test(PaperInfo $row, $xinfo) {
        if ($this->self) {
            $n = $row->has_conflict($this->user->contactXid) ? 1 : 0;
        } else {
            $n = 0;
            $can_view = $this->user->can_view_conflicts($row);
            foreach ($this->ccm->contact_set() as $cid) {
                if (($cid === $this->user->contactXid || $can_view)
                    && $row->has_conflict($cid))
                    ++$n;
            }
        }
        return $this->ccm->test($n);
    }
    function script_expression(PaperInfo $row, $about) {
        if ($about !== self::ABOUT_PAPER) {
            return $this->test($row, null);
        } else if (!$this->ispc) {
            return null;
        }
        $opt = $row->conf->option_by_id(PaperOption::PCCONFID);
        '@phan-var-force PCConflicts_PaperOption $opt';
        if ($opt->test_visible($row)) {
            return [
                "type" => "pc_conflict",
                "uids" => $this->ccm->contact_set(),
                "compar" => $this->ccm->relation(),
                "value" => $this->ccm->value()
            ];
        } else {
            return $this->test($row, null);
        }
    }
    function about() {
        return self::ABOUT_PAPER;
    }
}
