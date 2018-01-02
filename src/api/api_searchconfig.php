<?php
// api_searchconfig.php -- HotCRP search configuration API calls
// Copyright (c) 2008-2018 Eddie Kohler; see LICENSE.

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
        foreach ($user->conf->viewable_named_formulas($user, $qreq->t === "a") as $f) {
            $fj = ["name" => $f->name, "expression" => $f->expression, "id" => $f->formulaId];
            if ($user->can_edit_formula($f))
                $fj["editable"] = true;
            $fjs[] = $fj;
        }
        return new JsonResult(["ok" => true, "formulas" => $fjs]);
    }

    static function save_namedformula(Contact $user, Qrequest $qreq) {
        global $Now;
        $formula_by_id = [];
        foreach ($user->conf->named_formulas() as $f)
            $formula_by_id[$f->formulaId] = $f;

        $ids_used = [];
        for ($fidx = 1; isset($qreq["formulaid_$fidx"]); ++$fidx) {
            $id = $qreq["formulaid_$fidx"];
            if ($id !== "new" && isset($formula_by_id[$id]))
                $ids_used[$id] = true;
        }

        $lnames_used = [];
        foreach ($user->conf->named_formulas() as $f) {
            if (!isset($ids_used[$f->formulaId]))
                $lnames_used[strtolower($f->name)] = true;
        }

        $q = $qv = [];
        $msgset = new MessageSet;
        for ($fidx = 1; isset($qreq["formulaid_$fidx"]); ++$fidx) {
            $name = simplify_whitespace((string) $qreq["formulaname_$fidx"]);
            $lname = strtolower($name);
            $expr = simplify_whitespace((string) $qreq["formulaexpression_$fidx"]);
            $id = $qreq["formulaid_$fidx"];
            $deleted = $qreq["formuladeleted_$fidx"];
            $pfx = $name === "" ? "" : htmlspecialchars($name) . ": ";

            if ($id === "new") {
                if (($name === "" && $expr === "") || $deleted)
                    continue;
                $fdef = null;
            } else if (($fdef = $formula_by_id[$id])) {
                if (!$user->can_edit_formula($fdef)
                    && ($name !== $fdef->name || $expr !== $fdef->expression || $deleted)) {
                    $msgset->error_at("formula$fidx", "You can’t change formula “" . htmlspecialchars($fdef->name) . "”.");
                    continue;
                } else if ($deleted) {
                    $q[] = "delete from Formula where formulaId=?";
                    $qv[] = $fdef->formulaId;
                    continue;
                }
            } else {
                $msgset->error_at("formula$fidx", "{$pfx}This formula has been deleted.");
                continue;
            }

            if ($expr === "") {
                $msgset->error_at("formulaexpression_$fidx", "{$pfx}Missing formula expression.");
                continue;
            }

            if ($lname === "") {
                $msgset->error_at("formulaname_$fidx", "Missing formula name.");
            } else if (preg_match('/\A(?:formula[:\d].*|f:.*|[-+]?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d*)?|none|any|all|unknown)\z/', $lname)) {
                $msgset->error_at("formulaname_$fidx", "{$pfx}This formula name is reserved. Please pick another name.");
            } else if (preg_match_all('/[()\[\]\{\}\\\\\"\']/', $lname, $m)) {
                $msgset->error_at("formulaname_$fidx", "{$pfx}Characters like “" . htmlspecialchars(join("", $m[0])) . "” cannot be used in formula names. Please pick another name.");
            } else if (isset($lnames_used[$lname])) {
                $msgset->error_at("formulaname_$fidx", "{$pfx}Formula names must be distinct.");
                if ($lnames_used[$lname] !== true)
                    $msgset->error_at("formulaname_$fidx", null);
            } else {
                $lnames_used[$lname] = $fidx;
            }

            $f = new Formula($expr);
            if ($f->check($user)) {
                $exprViewScore = $f->view_score($user);
                if ($exprViewScore <= $user->permissive_view_score_bound()) {
                    $msgset->error_at("formulaexpression_$fidx", "{$pfx}The expression “" . htmlspecialchars($expr) . "” refers to properties that you aren’t allowed to view. Please define a different expression.");
                } else if (!$fdef) {
                    $q[] = "insert into Formula set name=?, heading='', headingTitle='', expression=?, createdBy=?, timeModified=?";
                    array_push($qv, $name, $expr, ($user->privChair ? -1 : 1) * $user->contactId, $Now);
                    $q[] = "insert into Settings set name='formulas', value=1 on duplicate key update value=1";
                } else if ($name !== $fdef->name || $expr !== $fdef->expression) {
                    $q[] = "update Formula set name=?, expression=?, timeModified=? where formulaId=?";
                    array_push($qv, $name, $expr, $Now, $fdef->formulaId);
                }
            } else {
                $msgset->error_at("formulaexpression_$fidx", $pfx . $f->error_html());
            }
        }

        if (!$msgset->has_error() && !empty($q)) {
            $mresult = Dbl::multi_qe_apply($user->conf->dblink, join(";", $q), $qv);
            $mresult->free_all();
            $user->conf->invalidate_named_formulas();
        }

        $j = self::namedformula($user, $qreq);
        if ($msgset->has_error()) {
            $j->content["ok"] = false;
            $j->content["error"] = $msgset->errors();
            $j->content["errf"] = $msgset->message_field_map();
        }
        return $j;
    }
}
