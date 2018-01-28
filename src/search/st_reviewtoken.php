<?php
// search/st_reviewtoken.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class ReviewToken_SearchTerm extends SearchTerm {
    private $any;
    private $token;

    function __construct($token, $any) {
        parent::__construct("token");
        $this->token = $token;
        $this->any = $any;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (strcasecmp($word, "any") == 0)
            return new ReviewToken_SearchTerm(0, true);
        else if (strcasecmp($word, "none") == 0)
            return new ReviewToken_SearchTerm(0, false);
        else if (($token = decode_token($word, "V")))
            return new ReviewToken_SearchTerm($token, null);
        else {
            $srch->warn("“" . htmlspecialchars($word) . "” is not a valid review token.");
            return new False_SearchTerm;
        }
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_review_signature_columns();
        $thistab = "ReviewTokens_" . $this->token;
        $where = "reviewToken" . ($this->token ? "={$this->token}" : "!=0");
        $sqi->add_table($thistab, ["left join", "(select r.paperId, count(r.reviewId) count from PaperReview r where $where group by paperId)"]);
        if ($this->any !== false)
            return "{$thistab}.count>0";
        else
            return "coalesce({$thistab}.count,0)=0";
    }
    function exec(PaperInfo $prow, PaperSearch $srch) {
        $nr = $nt = 0;
        foreach ($prow->reviews_by_id() as $rrow)
            if ($srch->user->can_view_review_assignment($prow, $rrow)) {
                ++$nr;
                if ($this->token
                    ? $rrow->reviewToken == $this->token
                    : !$rrow->reviewToken && $srch->user->can_view_review_identity($prow, $rrow))
                    ++$nt;
            }
        if ($this->any === false)
            return $nt === $nr;
        else if ($this->any === true)
            return $nt !== $nr;
        else
            return $nt !== 0;
    }
}
