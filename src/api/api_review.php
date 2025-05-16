<?php
// api_review.php -- HotCRP review API calls
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

/*preference for api/review

single review download
- p must be set
- r must be set

multiple review download
- p may be set
- q may be set
- one of p, q must be set
- rq may be set
- u may be set

single review upload
- p must be set
- r must be set (might be `new`)
- u may be set
- round may be set */

class Review_API extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;


    const M_ONE = 1;
    const M_MULTI = 2;
    const M_MATCH = 4;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
    }

    /** @return JsonResult */
    static function run_get_one(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        if (!isset($qreq->p)) {
            return JsonResult::make_missing_error("p");
        } else if (!isset($qreq->r)) {
            return JsonResult::make_missing_error("r");
        }
        $fr = $prow ? $user->perm_view_paper($prow) : $qreq->annex("paper_whynot");
        if (!$prow || $fr) {
            return Conf::paper_error_json_result($fr);
        }

        $rloc = $prow->parse_ordinal_id($qreq->r);
        if ($rloc === false || $rloc === 0) {
            return JsonResult::make_parameter_error("r");
        } else if ($rloc < 0) {
            $rrow = $prow->review_by_ordinal(-$rloc);
        } else {
            $rrow = $prow->review_by_id($rloc);
        }
        $fr = $user->perm_view_review($prow, $rrow);
        if (!$rrow || $fr) {
            $fr = $fr ?? $prow->failure_reason(["reviewNonexistent" => true]);
            $status = isset($fr["reviewNonexistent"]) ? 404 : 403;
            return new JsonResult($status, [
                "ok" => false, "message_list" => $fr->message_list(null, 2)
            ]);
        }

        $rj = (new PaperExport($user))->review_json($prow, $rrow);
        assert(!!$rj);
        return new JsonResult(["ok" => true, "review" => $rj]);
    }

    /** @return JsonResult */
    static function run_get_multi(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        $ml = [];
        if (!isset($qreq->q)) {
            if (!isset($qreq->p)) {
                JsonResult::make_missing_error("q")->complete();
            }
            $fr = $prow ? $user->perm_view_paper($prow) : $qreq->annex("paper_whynot");
            if (!$prow || $fr) {
                Conf::paper_error_json_result($fr)->complete();
            }
            $srch = null;
            $prows = PaperInfoSet::make_singleton($prow);
        } else {
            list($srch, $prows) = Paper_API::make_search($user, $qreq);
            $ml = $srch->message_list_with_default_field("q");
        }

        if (isset($qreq->rq) && isset($qreq->u)) {
            JsonResult::make_parameter_error("rq", "<0>Supply at most one of `rq` and `u`")->complete();
        }

        $rst = null;
        if (isset($qreq->u)) {
            if (($u = $user->conf->user_by_email($qreq->u))) {
                $rsm = new ReviewSearchMatcher;
                $rsm->add_contact($u->contactId);
                $rst = new Review_SearchTerm($user, $rsm);
            } else {
                $rst = new False_SearchTerm;
            }
        } else if (isset($qreq->rq)) {
            $query = [
                "q" => $qreq->rq,
                "reviewer" => $qreq->reviewer ?? null
            ];
            $srch = new PaperSearch($user, $query);
            array_push($ml, ...$srch->message_list_with_default_field("rq"));
            $rst = $srch->main_term();
        }

        $pex = new PaperExport($user);
        $rjs = [];
        foreach ($prows as $prow) {
            foreach ($prow->viewable_reviews_as_display($user) as $rrow) {
                if (!$rst || $rst->test($prow, $rrow))
                    $rjs[] = $pex->review_json($prow, $rrow);
            }
        }

        return new JsonResult([
            "ok" => true,
            "message_list" => $ml,
            "reviews" => $rjs
        ]);
    }

    /** @return JsonResult */
    static private function run(Contact $user, Qrequest $qreq, ?PaperInfo $prow, $mode) {
        $old_overrides = $user->overrides();
        if (friendly_boolean($qreq->forceShow) !== false) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }
        try {
            if ($qreq->is_get()) {
                if ($mode === self::M_ONE) {
                    $jr = self::run_get_one($user, $qreq, $prow);
                } else {
                    $jr = self::run_get_multi($user, $qreq, $prow);
                }
            } else {
                $jr = JsonResult::make_not_found_error();
            }
        } catch (JsonResult $jrx) {
            $jr = $jrx;
        }
        $user->set_overrides($old_overrides);
        if (($jr->content["message_list"] ?? null) === []) {
            unset($jr->content["message_list"]);
        }
        return $jr;
    }

    /** @return JsonResult */
    static function run_one(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        return self::run($user, $qreq, $prow, self::M_ONE);
    }

    /** @return JsonResult */
    static function run_multi(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        return self::run($user, $qreq, $prow, self::M_MULTI | self::M_MATCH);
    }


    /** @deprecated */
    static function reviewhistory(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        return ReviewMeta_API::reviewhistory($user, $qreq, $prow);
    }

    /** @deprecated */
    static function reviewrating(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        return ReviewMeta_API::reviewrating($user, $qreq, $prow);
    }

    /** @deprecated */
    static function reviewround(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        return ReviewMeta_API::reviewround($user, $qreq, $prow);
    }
}
