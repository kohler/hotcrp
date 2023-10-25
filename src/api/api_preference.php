<?php
// api_preference.php -- HotCRP preference API call
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Preference_API {
    static function pref_api(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        $overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $u = APIHelpers::parse_reviewer_for($qreq->u ?? $qreq->reviewer, $user, $prow);
        $user->set_overrides($overrides);

        // PC members may enter preferences for withdrawn papers,
        // so we must special-case the paper check
        if (!$prow) {
            $whynot = $qreq->annex("paper_whynot");
            if (!isset($whynot["withdrawn"])
                || !($prow = $user->conf->paper_by_id(intval($qreq->p), $user))) {
                return Conf::paper_error_json_result($whynot);
            }
        }
        if (!$user->can_edit_preference_for($u, $prow)) {
            return JsonResult::make_error(403, "<0>Can’t edit preference for #{$prow->paperId}");
        }

        if ($qreq->method() === "POST" || isset($qreq->pref)) {
            $aset = new AssignmentSet($user);
            $aset->set_override_conflicts(true);
            $aset->enable_papers($prow);
            $aset->parse("paper,user,preference\n{$prow->paperId}," . CsvGenerator::quote($u->email) . "," . CsvGenerator::quote($qreq->pref, true));
            if (!$aset->execute()) {
                return $aset->json_result();
            }
            $prow->load_preferences();
        }

        $pf = $prow->preference($u);
        $value = $pf->unparse();
        $jr = new JsonResult(["ok" => true, "value" => $value === "0" ? "" : $value, "pref" => $pf->preference]);
        if ($pf->expertise !== null) {
            $jr->content["prefexp"] = unparse_expertise($pf->expertise);
        }
        if ($user->conf->has_topics()) {
            $jr->content["topic_score"] = $prow->topic_interest_score($u);
        }
        if ($qreq->method() === "POST" && $prow->timeWithdrawn > 0) {
            foreach ($prow->make_whynot(["withdrawn" => 1])->message_list(null, 1) as $mi) {
                $jr->content["message_list"][] = $mi;
            }
        }
        return $jr;
    }
}
