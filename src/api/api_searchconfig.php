<?php
// api_searchconfig.php -- HotCRP search configuration API calls
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class SearchConfig_API {
    static function viewoptions(Contact $user, Qrequest $qreq) {
        $report = $qreq->report ?? "pl";
        if ($report !== "pl" && $report !== "pf") {
            return JsonResult::make_error(404, "<0>Report not found");
        }
        $search = new PaperSearch($user, "NONE");

        if ($qreq->method() === "POST" && $user->privChair) {
            if (!isset($qreq->display)) {
                return JsonResult::make_error(400, "<0>Bad request");
            }

            $pl = new PaperList($report, $search, ["sort" => true]);
            $pl->parse_view($qreq->display, PaperList::VIEWORIGIN_EXPLICIT);
            $parsed_view = $pl->unparse_view(true);
            // check for errors
            $pl->table_html();
            if ($pl->message_set()->has_error()) {
                return new JsonResult(["ok" => false, "message_list" => $pl->message_set()->message_list()]);
            }

            $pl = new PaperList($report, $search, ["sort" => true]);
            $pl->apply_view_report_default(true);
            $baseline_view = $pl->unparse_view(true);

            if ($parsed_view === $baseline_view) {
                $user->conf->save_setting("{$report}display_default", null);
            } else {
                $user->conf->save_setting("{$report}display_default", 1, join(" ", $parsed_view));
            }
            $user->save_session("{$report}display", null);
            if ($report === "pl") {
                $user->save_session("uldisplay", null);
            }
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
                $fj["error_html"] = MessageSet::feedback_html($f->message_list());
            }
            $fjs[] = $fj;
        }
        return new JsonResult(["ok" => true, "formulas" => $fjs]);
    }

    static function save_namedformula(Contact $user, Qrequest $qreq) {
        // NB permissions handled in loop

        // capture current formula set
        $new_formula_by_id = $formula_by_id = $user->conf->named_formulas();
        $max_id = array_reduce($formula_by_id, function ($max, $f) {
            return max($max, $f->formulaId);
        }, 0);

        // determine new formula set from request
        $id2idx = [];
        $msgset = new MessageSet;
        for ($fidx = 1; isset($qreq["formulaid_$fidx"]); ++$fidx) {
            $name = $qreq["formulaname_$fidx"];
            $expr = $qreq["formulaexpression_$fidx"];
            $id = $qreq["formulaid_$fidx"];
            $deleted = $qreq["formuladeleted_$fidx"];
            if (!isset($name) && !isset($expr) && !isset($deleted)) {
                continue;
            }

            $name = simplify_whitespace($name ?? "");
            $lname = strtolower($name);
            $expr = simplify_whitespace($expr ?? "");
            $pfx = $name === "" ? "" : htmlspecialchars($name) . ": ";

            if ($id === "new") {
                if (($name === "" && $expr === "") || $deleted) {
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

            if (!$user->can_edit_formula($fdef)
                && (!$fdef || $name !== $fdef->name || $expr !== $fdef->expression || $deleted)) {
                $msgset->error_at("formula$fidx", $fdef ? "{$pfx}You can’t change this named formula." : "You can’t create named formulas.");
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
                $msgset->error_at("formulaname_" . $id2idx[$f->formulaId], htmlspecialchars($f->name ? $f->name . ": " : "") . "Formula names must be distinct");
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
                    && !$user->can_view_formula($f))  {
                    $msgset->error_at("formulaexpression_" . $id2idx[$f->formulaId], $pfx . "This expression refers to properties you can’t access");
                }
            } else {
                foreach ($f->message_list() as $mi) {
                    $msgset->append_item($mi->with_field("formulaexpression_" . $id2idx[$f->formulaId]));
                }
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
            return ["ok" => false, "message_list" => $msgset->message_list()];
        }
    }

    static function can_edit_search(Contact $user, $v) {
        return $user->privChair
            || !($v->owner ?? false)
            || $v->owner === $user->contactId;
    }

    static function namedsearch(Contact $user, Qrequest $qreq) {
        $fjs = [];
        foreach ($user->conf->named_searches() as $n => $v) {
            if (($v->qt ?? "n") === "n" && ($v->t ?? "s") === "s") {
                $q = $v->q;
            } else {
                $q = PaperSearch::canonical_query($v->q, "", "", $v->qt ?? "n", $user->conf, $v->t ?? "s");
            }
            $j = ["name" => $n, "q" => $q];
            if (self::can_edit_search($user, $v)) {
                $j["editable"] = true;
            }
            $fjs[] = $j;
        }
        usort($fjs, function ($a, $b) {
            return strnatcasecmp($a["name"], $b["name"]);
        });
        return new JsonResult(["ok" => true, "searches" => $fjs]);
    }

    static function save_namedsearch(Contact $user, Qrequest $qreq) {
        // NB permissions handled in loop

        // capture current formula set
        $saved_searches = $search_names = [];
        foreach ($user->conf->named_searches() as $n => $j) {
            $ln = strtolower($n);
            $saved_searches[$ln] = $j;
            $search_names[$ln] = $n;
        }
        $new_saved_searches = $saved_searches;
        $tagger = new Tagger($user);

        // determine new formula set from request
        $id2idx = [];
        $msgset = new MessageSet;
        for ($fidx = 1; isset($qreq["searchid_$fidx"]); ++$fidx) {
            $name = $qreq["searchname_$fidx"];
            $q = $qreq["searchq_$fidx"];
            $id = $qreq["searchid_$fidx"];
            $deleted = $qreq["searchdeleted_$fidx"];
            if (!isset($name) && !isset($q) && !isset($deleted)) {
                continue;
            }

            $name = simplify_whitespace($name ?? "");
            $lname = strtolower($name);
            $q = simplify_whitespace($q ?? "");
            $lid = strtolower($id);
            $pfx = $name === "" ? "" : htmlspecialchars($name) . ": ";

            if ($id === "new") {
                if (($name === "" && $q === "") || $deleted) {
                    continue;
                }
                $fdef = null;
            } else if (!($fdef = $saved_searches[$lid] ?? null)) {
                $msgset->error_at("search$fidx", "{$pfx}This search has been deleted.");
                continue;
            }

            if ((!self::can_edit_search($user, $fdef)
                 && (!$fdef || strcasecmp($name, $lid) !== 0 || $q !== $fdef->q || $deleted))
                || (isset($saved_searches[$lname])
                    && !self::can_edit_search($user, $saved_searches[$lname]))) {
                $msgset->error_at("search$fidx", $fdef ? "{$pfx}You can’t change this named search." : "You can’t create named searches.");
                continue;
            }

            if ($deleted) {
                unset($new_saved_searches[$id]);
                continue;
            } else if ($q === "") {
                $msgset->error_at("searchq_$fidx", "{$pfx}Query missing.");
                continue;
            }

            if ($lname === "") {
                $msgset->error_at("searchname_$fidx", "Missing search name.");
            } else if (preg_match('/\A(?:formula[:\d].*|f:.*|ss:.*|search:.*|[-+]?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d*)?|none|any|all|unknown|new)\z/', $lname)) {
                $msgset->error_at("searchname_$fidx", "{$pfx}This search name is reserved. Please pick another name.");
            } else if (!$tagger->check($name, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE)) {
                $msgset->error_at("searchname_$fidx", "{$pfx}“" . htmlspecialchars($name) . "” contains characters not allowed in search names. Stick to letters, digits, and simple punctuation.");
            }

            unset($search_names[$lid], $new_saved_searches[$lid]);
            $search_names[$lname] = $name;
            $new_saved_searches[$lname] = (object) ["q" => $q, "owner" => $user->privChair ? "chair" : $user->contactId];
        }

        // XXX should validate saved searches using new search set

        // save
        if (!$msgset->has_error()) {
            $user->conf->qe("delete from Settings where name LIKE 'ss:%' AND name?A", $search_names);
            $qv = [];
            foreach ($new_saved_searches as $lname => $q) {
                $qv[] = ["ss:" . $search_names[$lname], Conf::$now, json_encode_db($q)];
            }
            if (!empty($qv)) {
                $user->conf->qe("insert into Settings (name, value, data) values ?v ?U on duplicate key update value=?U(value), data=?U(data)", $qv);
            }
            $user->conf->replace_named_searches();
            return self::namedsearch($user, $qreq);
        } else {
            return ["ok" => false, "message_list" => $msgset->message_list()];
        }
    }
}
