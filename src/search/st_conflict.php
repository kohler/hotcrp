<?php
// search/st_conflict.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

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
        [$usword, $op, $value] = $sword->pop_comparison();
        $usrch = $usword
            ? $srch->user_search(ContactSearch::F_USER | ($sword->kwdef->pc_only ? ContactSearch::F_PC : 0) | ContactSearch::F_REQUIRED, $usword)
            : null;
        $ccm = new ContactCountMatcher(CountMatcher::unparse_comparison($op, $value), $usrch);
        if (($qr = SearchTerm::make_constant($ccm->tautology()))) {
            return $qr;
        }
        return new Conflict_SearchTerm($srch->user, $ccm, $sword->kwdef->pc_only);
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
        }
        return false;
    }
    function paper_requirements(&$options) {
        if (!$this->self) {
            $options["allConflictType"] = true;
        }
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $cidsql = $this->ccm->contact_match_sql("contactId");
        if ($this->self) {
            // The searcher always sees their own conflicts, so an exact prefilter is safe.
            $snc = $this->ccm->simplified_nonnegative_comparison();
            if ($snc === ">0" || $snc === "=0") {
                $n = $snc === "=0" ? "not exists" : "exists";
                return "{$n} (select * from PaperConflict where paperId=Paper.paperId and {$cidsql})";
            }
            return "coalesce((select count(*) from PaperConflict where paperId=Paper.paperId and {$cidsql}),0){$snc}";
        }
        // For other users the searcher may not be able to view every conflict
        // (`test()` gates each on `can_view_conflicts`), so the prefilter must be a
        // conservative superset — never a subtractive `not exists`/upper-bound that
        // would drop a paper with a hidden conflict that `test()` would keep.
        $sqi->add_allConflictType_column();
        $cnc = $this->ccm->conservative_nonnegative_comparison();
        if ($cnc === ">=0") {
            return "true";
        }
        return "coalesce((select count(*) from PaperConflict where paperId=Paper.paperId and {$cidsql}),0){$cnc}";
    }
    function is_sqlexpr_precise() {
        return $this->self;
    }
    function test(PaperInfo $row, $xinfo) {
        if ($this->self) {
            $n = $row->has_conflict($this->user->contactXid) ? 1 : 0;
        } else {
            $n = 0;
            $vc = $this->user->can_view_conflicts($row);
            $va = $this->ispc || $this->user->can_view_authors($row);
            $pcm = $va ? null : $this->user->conf->viewable_pc_members($this->user);
            foreach ($this->ccm->contact_set() as $cid) {
                if (($vc || $cid === $this->user->contactXid)
                    && ($va || isset($pcm[$cid]))
                    && $row->has_conflict($cid))
                    ++$n;
            }
        }
        return $this->ccm->test($n);
    }
    function script_expression(PaperInfo $row, $about) {
        if (($about & self::ABOUT_PAPER) === 0) {
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
        }
        return $this->test($row, null);
    }
}
