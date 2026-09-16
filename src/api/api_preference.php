<?php
// api_preference.php -- HotCRP preference API call
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Preference_API {
    static function pref_api(Contact $viewer, Qrequest $qreq, ?PaperInfo $prow) {
        $overrides = $viewer->add_overrides(Contact::OVERRIDE_CONFLICT);
        $u = APIHelpers::parse_reviewer_for($qreq->u ?? $qreq->reviewer, $viewer, $prow);
        $viewer->set_overrides($overrides);
        if (!$u->isPC) {
            return JsonResult::make_permission_error();
        }
        $scope = $qreq->is_post() ? ($u !== $viewer ? TokenScope::S_PREF_ADMIN : TokenScope::S_PREF_WRITE) : TokenScope::S_PREF_READ;
        if ($prow ? !$viewer->scope_allows_some($scope) : !$viewer->scope_allows($scope, $prow)) {
            return JsonResult::make_scope_error($qreq, $scope);
        }

        // parse preference if POST, return error if incorrect
        $postpref = null;
        if ($qreq->is_post()) {
            if (!isset($qreq->pref)) {
                return JsonResult::make_missing_error("pref")->set_response_code(200);
            }
            $postpref = Preference_AssignmentParser::parse_check($qreq->pref, $viewer->conf);
            if (is_string($postpref)) {
                return JsonResult::make_parameter_error("pref", $postpref)->set_response_code(200);
            }
        }

        // PC members may enter preferences for any paper.
        // It is better to save these preferences than not; otherwise preference changes
        // are lost as papers change state (e.g., settings changes, a submission deadline
        // occurs). However, to avoid leaking information about the existence of
        // non-viewable papers, we only return the entered preference for viewable papers.
        if (!$prow) {
            $fr = $qreq->annex("paper_whynot");
            if (!$fr
                || isset($fr["invalidId"])
                || isset($fr["noPaper"])
                || !$fr->prow
                || !$viewer->can_edit_preference_for($fr->prow, $u)) {
                return Conf::paper_error_json_result($fr);
            }
            $prow = $fr->prow;
        }

        $jr = null;
        if ($postpref && $viewer->can_edit_preference_for($prow, $u)) {
            $postpref->save($prow->paperId, $u->contactId, [$viewer->conf, "qe"]);
            $prow->load_preferences();
        } else if ($postpref) {
            $jr = JsonResult::make_error(200, Preference_AssignmentParser::cannot_edit_preference_message($viewer, $prow, $u));
        }
        $jr = $jr ?? JsonResult::make_ok();
        if ($viewer->view_preference_state($prow) >= ($u === $viewer ? Contact::VIEWPREF_OWN : Contact::VIEWPREF_ALL)) {
            $pf = $prow->preference($u);
            $jr->set("value", $pf->exists() ? $pf->unparse() : "");
            $jr->set("pref", $pf->preference);
            if ($pf->expertise !== null) {
                $jr->set("prefexp", unparse_expertise($pf->expertise));
            }
        }
        if ($prow->conf->has_topics()) {
            $jr->set("topic_score", $prow->topic_interest_score($u));
        }
        return $jr;
    }
}
