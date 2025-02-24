<?php
// search/st_cmtafter.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class CmtAfter_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var CountMatcher */
    private $cm;
    /** @var int|float */
    private $time;

    /** @param int|float $time */
    function __construct(Contact $user, CountMatcher $cm, $time) {
        parent::__construct("cmtafter");
        $this->user = $user;
        $this->cm = $cm;
        $this->time = $time;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (!preg_match('/\A(.++)(|(?:[!=<>]=?|≥|≤|≠)\d+)\z/', $word, $m)
            || ($t = $srch->conf->parse_time($m[1])) === false) {
            $srch->lwarning($sword, "<0>Expected ‘cmtafter:DATE’");
            return new False_SearchTerm;
        }
        $cm = new CountMatcher($m[2] !== "" ? $m[2] : ">0");
        return new CmtAfter_SearchTerm($srch->user, $cm, $t);
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_comment_signature_columns();
        if ($this->cm->test(0)) {
            return "true";
        }
        return "exists(select * from PaperComment where paperId=Paper.paperId and timeModified>={$this->time})";
    }
    function test(PaperInfo $row, $xinfo) {
        $n = 0;
        foreach ($row->viewable_comment_skeletons($this->user, true) as $crow) {
            if ($crow->mtime($this->user) >= $this->time)
                ++$n;
        }
        return $this->cm->test($n);
    }
}
