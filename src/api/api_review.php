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
    static private function run(Contact $user, Qrequest $qreq, ?PaperInfo $prow, $mode) {
        $old_overrides = $user->overrides();
        if (friendly_boolean($qreq->forceShow) !== false) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }
        if ($qreq->is_get()) {
            $jr = self::run_get_one($user, $qreq, $prow);
        } else {
            $jr = JsonResult::make_not_found_error();
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
    static function run_multi(Contact $user, Qrequest $qreq) {
        return self::run($user, $qreq, null, self::M_MULTI | self::M_MATCH);
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
