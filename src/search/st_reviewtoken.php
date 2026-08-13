<?php
// search/st_reviewtoken.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ReviewToken_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    private $any;
    private $token;

    function __construct(Contact $user, $token, $any) {
        parent::__construct("token");
        $this->user = $user;
        $this->token = $token;
        $this->any = $any;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (strcasecmp($word, "any") == 0) {
            return new ReviewToken_SearchTerm($srch->user, 0, true);
        } else if (strcasecmp($word, "none") == 0) {
            return new ReviewToken_SearchTerm($srch->user, 0, false);
        } else if (($token = decode_token($word, "V"))) {
            return new ReviewToken_SearchTerm($srch->user, $token, null);
        }
        $srch->lwarning($sword, "<0>Invalid review token");
        return new False_SearchTerm;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_review_signature_columns();
        if ($this->any === false) {
            return "true";
        }
        $thistab = "ReviewTokens_" . $this->token;
        $where = "reviewToken" . ($this->token ? "={$this->token}" : "!=0");
        $sqi->add_table($thistab, ["left join", "(select r.paperId, count(r.reviewId) count from PaperReview r where {$where} and reviewType>0 group by paperId)"]);
        return "coalesce({$thistab}.count,0)>0";
    }
    function test(PaperInfo $prow, $xinfo) {
        $nr = $nt = 0;
        if ($xinfo && $xinfo instanceof ReviewInfo) {
            $rrows = [$xinfo];
        } else {
            $rrows = $prow->all_reviews();
        }
        if ($this->token) {
            foreach ($rrows as $rrow) {
                if ($rrow->reviewToken == $this->token
                    && $this->user->can_view_review_assignment($prow, $rrow))
                    return true;
            }
            return false;
        }
        foreach ($rrows as $rrow) {
            if ($rrow->reviewToken
                && $this->user->can_view_review_identity($prow, $rrow))
                return $this->any;
        }
        return !$this->any;
    }
    function about() {
        return self::ABOUT_REVIEW;
    }
}
