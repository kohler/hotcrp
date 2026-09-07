<?php
// search/st_proposal.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ReviewRequestSearchMatcher extends ContactCountMatcher {
    public $round;
    /** @var ?int */
    private $requester;

    function __construct() {
        parent::__construct(">0", null);
    }

    function apply_round($word, Conf $conf) {
        $round = $conf->round_number($word);
        if ($round === null
            || ($round === 0 && strcasecmp($word, "unnamed") !== 0)) {
            return false;
        }
        $this->round[] = $round;
        return true;
    }
    function apply_comparison($word) {
        $a = CountMatcher::unpack_search_comparison($word);
        if ($a[0] === "") {
            $this->set_relation_value($a[1], $a[2]);
            return true;
        }
        return false;
    }
    /** @param int $cid */
    function apply_requester($cid) {
        $this->requester = $cid;
    }
    function finish() {
    }

    function test_review_request(Contact $user, PaperInfo $prow, ReviewRequestInfo $rqrow) {
        if (($this->round !== null
             && !in_array($rqrow->reviewRound, $this->round, true))
            || !$user->can_view_review_identity($prow, $rqrow)
            || ($this->has_contacts()
                && !$this->test_contact($rqrow->reviewer()->contactId))
            || ($this->requester !== null
                && ($rqrow->requestedBy !== $this->requester
                    || !$user->can_view_review_requester($prow, $rqrow)))) {
            return false;
        }
        return true;
    }
}

class Proposal_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var ReviewRequestSearchMatcher */
    private $rqsm;

    function __construct(Contact $user, ReviewRequestSearchMatcher $rqsm) {
        parent::__construct("proposal");
        $this->user = $user;
        $this->rqsm = $rqsm;
        $this->rqsm->finish();
    }

    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $rqsm = new ReviewRequestSearchMatcher;

        $qword = $sword->qword;
        $contacts = null;
        $tailre = '(?:\z|:|(?=[=!<>]=?|≠|≤|≥))(.*)\z/s';
        while ($qword !== "") {
            if (preg_match('/\A:?((?:[=!<>]=?|≠|≤|≥|)\d+)' . $tailre, $qword, $m)
                && $rqsm->apply_comparison($m[1])) {
                $qword = $m[2];
            } else if (preg_match('/\A(.+?)' . $tailre, $qword, $m)
                       && ($rqsm->apply_round($m[1], $srch->conf)
                           || $rqsm->apply_comparison($m[1]))) {
                $qword = $m[2];
            } else if (preg_match('/\A(..*?|"[^"]+(?:"|\z))' . $tailre, $qword, $m)) {
                $contacts = $m[1];
                $qword = $m[2];
            } else {
                $rqsm->set_comparison("<0");
                break;
            }
        }

        if (($qr = SearchTerm::make_constant($rqsm->tautology()))) {
            return $qr;
        }

        if ($contacts) {
            $usword = SearchWord::make_kwarg($contacts, $sword->kwpos1, $sword->pos1, $sword->pos2, $sword->string_context);
            $rqsm->set_contacts($srch->user_search(ContactSearch::F_USER | ContactSearch::F_REQUIRED, $usword));
        }
        return new Proposal_SearchTerm($srch->user, $rqsm);
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        // Make the database query conservative (so change equality
        // constraints to >= constraints, and ignore <=/</!= constraints).
        // We'll do the precise query later.
        // ">=0" is a useless constraint in SQL-land.
        if ($this->rqsm->conservative_nonnegative_comparison() === ">=0") {
            return "true";
        }
        return "exists (select * from ReviewRequest where paperId=Paper.paperId)";
    }
    function test(PaperInfo $prow, $xinfo) {
        $n = 0;
        foreach ($prow->review_requests() as $rqrow) {
            $n += $this->rqsm->test_review_request($this->user, $prow, $rqrow);
        }
        return $this->rqsm->test($n);
    }
    function about() {
        return self::ABOUT_REVIEW_SET;
    }
    function debug_json() {
        return ["type" => $this->type, "count" => $this->rqsm->comparison()];
    }
}
