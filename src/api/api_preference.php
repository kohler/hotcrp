<?php
// api_preference.php -- HotCRP preference API call
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Preference_API {
    static function pref_api(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        $overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $u = APIHelpers::parse_reviewer_for($qreq->u ?? $qreq->reviewer, $user, $prow);
        $user->set_overrides($overrides);
        if (!$u->isPC) {
            return JsonResult::make_permission_error();
        }

        // parse preference if POST, return error if incorrect
        $postpref = null;
        if ($qreq->method() === "POST") {
            if (!isset($qreq->pref)) {
                return JsonResult::make_missing_error("pref")->set_status(200);
            }
            $postpref = Preference_AssignmentParser::parse_check($qreq->pref, $user->conf);
            if (is_string($postpref)) {
                return JsonResult::make_parameter_error("pref", $postpref)->set_status(200);
            }
        }

        // PC members may enter preferences for any paper.
        // It is better to save these preferences than not; otherwise preference changes
        // are lost as papers change state (e.g., settings changes, a submission deadline
        // occurs). However, to avoid leaking information about the existence of
        // non-viewable papers, we only return the entered preference for viewable papers.
        if (!$prow) {
            $fr = $qreq->annex("paper_whynot");
            if (isset($fr["invalidId"]) || isset($fr["noPaper"])) {
                return Conf::paper_error_json_result($fr);
            }
            if ($postpref && $fr->prow) {
                $postpref->save($fr->prow->paperId, $u->contactId, [$user->conf, "qe"]);
            }
            $jr = new JsonResult(["ok" => false]);
            if ($postpref) {
                $fr->append_to($jr, "p", MessageSet::WARNING);
            }
            return $jr;
        }

        if ($postpref) {
            $postpref->save($prow->paperId, $u->contactId, [$user->conf, "qe"]);
            $prow->load_preferences();
        }
        $pf = $prow->preference($u);
        $jr = new JsonResult(["ok" => true, "value" => $pf->exists() ? $pf->unparse() : "", "pref" => $pf->preference]);
        if ($pf->expertise !== null) {
            $jr->content["prefexp"] = unparse_expertise($pf->expertise);
        }
        if ($prow->conf->has_topics()) {
            $jr->content["topic_score"] = $prow->topic_interest_score($u);
        }
        return $jr;
    }
}
