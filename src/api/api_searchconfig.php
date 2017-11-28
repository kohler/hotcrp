<?php
// api_searchconfig.php -- HotCRP search configuration API calls
// HotCRP is Copyright (c) 2008-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SearchConfig_API {
    static function viewoptions(Contact $user, Qrequest $qreq) {
        $report = get($qreq, "report", "pl");
        if ($report !== "pl" && $report !== "pf")
            return new JsonResult(400, "Parameter error.");
        if ($qreq->method() !== "GET" && $user->privChair) {
            if (!isset($qreq->display))
                return new JsonResult(400, "Parameter error.");
            $base_display = "";
            if ($report === "pl")
                $base_display = $user->conf->review_form()->default_display();
            $display = simplify_whitespace($qreq->display);
            if ($display === $base_display)
                $user->conf->save_setting("{$report}display_default", null);
            else
                $user->conf->save_setting("{$report}display_default", 1, $display);
        }
        $s1 = new PaperSearch($user, get($qreq, "q", "NONE"));
        $l1 = new PaperList($s1, ["sort" => get($qreq, "sort", true), "report" => $report]);
        $s2 = new PaperSearch($user, "NONE");
        $l2 = new PaperList($s2, ["sort" => true, "report" => $report, "no_session_display" => true]);
        return new JsonResult(["ok" => true, "report" => $report, "display_current" => $l1->display("s"), "display_default" => $l2->display("s")]);
    }

    static function namedformula(Contact $user, Qrequest $qreq) {
        $fjs = [];
        foreach ($user->conf->viewable_named_formulas($user, false) as $f) {
            $fj = ["name" => $f->name, "expression" => $f->expression, "id" => $f->formulaId];
            if ($user->can_edit_formula($f))
                $fj["editable"] = true;
            $fjs[] = $fj;
        }
        return new JsonResult(["ok" => true, "formulas" => $fjs]);
    }
}
