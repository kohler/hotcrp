<?php
// api_searchconfig.php -- HotCRP search configuration API calls
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class SearchConfig_API {
    static function viewoptions(Contact $user, Qrequest $qreq) {
        $report = get($qreq, "report", "pl");
        if ($report !== "pl" && $report !== "pf") {
            return new JsonResult(400, "Bad request.");
        }
        $search = new PaperSearch($user, "NONE");

        if ($qreq->method() === "POST" && $user->privChair) {
            if (!isset($qreq->display)) {
                return new JsonResult(400, "Bad request.");
            }
            $pl = new PaperList($report, $search, ["sort" => true]);
            $pl->apply_view_report_default();
            $default_view = $pl->unparse_view(true);
            $pl->parse_view($qreq->display, PaperList::VIEWORIGIN_EXPLICIT);
            $parsed_view = $pl->unparse_view(true);

            // check for errors
            $pl->table_html();
            if ($pl->message_set()->has_error()) {
                return new JsonResult([
                    "ok" => false,
                    "errors" => $pl->message_set()->error_texts()
                ]);
            }

            if ($parsed_view === $default_view) {
                $user->conf->save_setting("{$report}display_default", null);
            } else {
                $user->conf->save_setting("{$report}display_default", 1, join(" ", $parsed_view));
            }
            $user->save_session("{$report}display", null);
        }

        $pl = new PaperList($report, $search, ["sort" => true]);
        $pl->apply_view_report_default();
        $vd = $pl->unparse_view(true);

        $search = new PaperSearch($user, $qreq->q ?? "NONE");
        $pl = new PaperList($report, $search, ["sort" => $qreq->sort ?? true]);
        $pl->apply_view_report_default();
        $pl->apply_view_session();
        $vr = $pl->unparse_view(true);

        return new JsonResult([
            "ok" => true, "report" => $report,
            "display_current" => join(" ", $vr),
            "display_default" => join(" ", $vd)
        ]);
    }

    static function namedformula(Contact $user, Qrequest $qreq) {
        $fjs = [];
        foreach ($user->conf->viewable_named_formulas($user) as $f) {
            $fj = ["name" => $f->name, "expression" => $f->expression, "id" => $f->formulaId];
            if ($user->can_edit_formula($f)) {
                $fj["editable"] = true;
            }
            if (!$f->check()) {
                $fj["error_html"] = $f->error_html();
            }
            $fjs[] = $fj;
        }
        return new JsonResult(["ok" => true, "formulas" => $fjs]);
    }

    static function save_namedformula(Contact $user, Qrequest $qreq) {
        // capture current formula set
        $new_formula_by_id = $formula_by_id = $user->conf->named_formulas();
        $max_id = array_reduce($formula_by_id, function ($max, $f) {
            return max($max, $f->formulaId);
        }, 0);

        // determine new formula set from request
        $id2idx = [];
        $msgset = new MessageSet;
        for ($fidx = 1; isset($qreq["formulaid_$fidx"]); ++$fidx) {
            $name = simplify_whitespace((string) $qreq["formulaname_$fidx"]);
            $lname = strtolower($name);
            $expr = simplify_whitespace((string) $qreq["formulaexpression_$fidx"]);
            $id = $qreq["formulaid_$fidx"];
            $deleted = $qreq["formuladeleted_$fidx"];
            $pfx = $name === "" ? "" : htmlspecialchars($name) . ": ";

            if ($id === "new") {
                if ($name === "" && $expr === "") {
                    continue;
                }
                $fdef = null;
                $id = ++$max_id;
            } else {
                $id = (int) $id;
                if (!($fdef = $formula_by_id[$id] ?? null)) {
                    $msgset->error_at("formula$fidx", "{$pfx}This formula has been deleted.");
                    continue;
                }
            }
            $id2idx[$id] = $fidx;

            if ($fdef
                && !$user->can_edit_formula($fdef)
                && ($name !== $fdef->name || $expr !== $fdef->expression || $deleted)) {
                $msgset->error_at("formula$fidx", "You can’t change formula “" . htmlspecialchars($fdef->name) . "”.");
                continue;
            }

            if ($deleted) {
                unset($new_formula_by_id[$id]);
                continue;
            } else if ($expr === "") {
                $msgset->error_at("formulaexpression_$fidx", "{$pfx}Expression required.");
                continue;
            }

            if ($lname === "") {
                $msgset->error_at("formulaname_$fidx", "Missing formula name.");
            } else if (preg_match('/\A(?:formula[:\d].*|f:.*|[-+]?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d*)?|none|any|all|unknown)\z/', $lname)) {
                $msgset->error_at("formulaname_$fidx", "{$pfx}This formula name is reserved. Please pick another name.");
            } else if (preg_match_all('/[()\[\]\{\}\\\\\"\']/', $lname, $m)) {
                $msgset->error_at("formulaname_$fidx", "{$pfx}Characters like “" . htmlspecialchars(join("", $m[0])) . "” cannot be used in formula names. Please pick another name.");
            }

            $f = new Formula($expr);
            $f->name = $name;
            $f->formulaId = $id;
            $new_formula_by_id[$id] = $f;
        }

        // check name reuse
        $lnames_used = [];
        foreach ($new_formula_by_id as $f) {
            $lname = strtolower($f->name);
            if (isset($lnames_used[$lname]))  {
                $msgset->error_at("formulaname_" . $id2idx[$f->formulaId], htmlspecialchars($f->name ? $f->name . ": " : "") . "Formula names should be distinct.");
            }
            $lnames_used[$lname] = true;
        }

        // validate formulas using new formula set
        $user->conf->replace_named_formulas($new_formula_by_id);
        foreach ($new_formula_by_id as $f) {
            $fdef = $formula_by_id[$f->formulaId] ?? null;
            $pfx = $f->name ? htmlspecialchars($f->name) . ": " : "";
            if ($f->check($user)) {
                if ((!$fdef || $fdef->expression !== $f->expression)
                    && $f->view_score($user) <= $user->permissive_view_score_bound())  {
                    $msgset->error_at("formulaexpression_" . $id2idx[$f->formulaId], $pfx . "This expression refers to properties you can’t access.");
                }
            } else {
                $msgset->error_at("formulaexpression_" . $id2idx[$f->formulaId], $pfx . "Formula error: " . $f->error_html());
            }
        }

        // save
        if (!$msgset->has_error()) {
            $q = $qv = [];
            foreach ($formula_by_id as $f) {
                if (!isset($new_formula_by_id[$f->formulaId])) {
                    $q[] = "delete from Formula where formulaId=?";
                    $qv[] = $f->formulaId;
                }
            }
            foreach ($new_formula_by_id as $f) {
                $fdef = $formula_by_id[$f->formulaId] ?? null;
                if (!$fdef) {
                    $q[] = "insert into Formula set name=?, expression=?, createdBy=?, timeModified=?";
                    array_push($qv, $f->name, $f->expression, $user->privChair ? -$user->contactId : $user->contactId, Conf::$now);
                } else if ($f->name !== $fdef->name || $f->expression !== $fdef->expression) {
                    $q[] = "update Formula set name=?, expression=?, timeModified=? where formulaId=?";
                    array_push($qv, $f->name, $f->expression, Conf::$now, $f->formulaId);
                }
            }
            if (empty($new_formula_by_id)) {
                $q[] = "delete from Settings where name='formulas'";
            } else {
                $q[] = "insert into Settings set name='formulas', value=1 on duplicate key update value=1";
            }
            $mresult = Dbl::multi_qe_apply($user->conf->dblink, join(";", $q), $qv);
            $mresult->free_all();

            $user->conf->replace_named_formulas(null);
            return self::namedformula($user, $qreq);
        } else {
            return ["ok" => false, "error" => $msgset->error_texts(), "errf" => $msgset->message_field_map()];
        }
    }
}
