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
            $pl->parse_view($qreq->display, PaperList::VIEWORIGIN_MAX);
            $parsed_view = $pl->unparse_view(PaperList::VIEWORIGIN_REPORT, true);
            // check for errors
            $pl->table_html();
            if ($pl->message_set()->has_error()) {
                return new JsonResult(["ok" => false, "message_list" => $pl->message_set()->message_list()]);
            }

            $want = join(" ", $parsed_view);
            if ($want !== $pl->unparse_baseline_view()) {
                $user->conf->save_setting("{$report}display_default", 1, join(" ", $parsed_view));
            } else {
                $user->conf->save_setting("{$report}display_default", null);
            }
            $qreq->unset_csession("{$report}display");
            if ($report === "pl") {
                $qreq->unset_csession("uldisplay");
            }
        }

        $pl = new PaperList($report, $search, ["sort" => ""], $qreq);
        $pl->apply_view_report_default();
        $vd = $pl->unparse_view(PaperList::VIEWORIGIN_REPORT, true);

        $search = new PaperSearch($user, $qreq->q ?? "NONE");
        $pl = new PaperList($report, $search, ["sort" => true], $qreq);
        $pl->apply_view_report_default();
        $pl->apply_view_session($qreq);
        $vr = $pl->unparse_view(PaperList::VIEWORIGIN_REPORT, true);
        $vrx = $pl->unparse_view(PaperList::VIEWORIGIN_DEFAULT_DISPLAY, true);

        return new JsonResult([
            "ok" => true, "report" => $report,
            "display_current" => join(" ", $vr),
            "display_default" => join(" ", $vd),
            "display_difference" => join(" ", $vrx)
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

    static private function translate_qreq(Qrequest $qreq) { // XXX backward compat
        for ($fidx = 1; isset($qreq["formulaid_{$fidx}"]); ++$fidx) {
            $qreq["formula/{$fidx}/name"] = $qreq["formulaname_{$fidx}"];
            $qreq["formula/{$fidx}/expression"] = $qreq["formulaexpression_{$fidx}"];
            $qreq["formula/{$fidx}/id"] = $qreq["formulaid_{$fidx}"];
            $qreq["formula/{$fidx}/delete"] = $qreq["formuladeleted_{$fidx}"];
        }
    }

    static function save_namedformula(Contact $user, Qrequest $qreq) {
        // NB permissions handled in loop

        // capture current formula set
        $new_formula_by_id = $formula_by_id = $user->conf->named_formulas();
        $max_id = array_reduce($formula_by_id, function ($max, $f) {
            return max($max, $f->formulaId);
        }, 0);

        if (!isset($qreq["formula/1/id"])) {
            self::translate_qreq($qreq);
        }

        // determine new formula set from request
        $id2idx = [];
        $msgset = new MessageSet;
        for ($fidx = 1; isset($qreq["formula/{$fidx}/id"]); ++$fidx) {
            $name = $qreq["formula/{$fidx}/name"];
            $expr = $qreq["formula/{$fidx}/expression"];
            $id = $qreq["formula/{$fidx}/id"];
            $deleted = $qreq["formula/{$fidx}/delete"];
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
                    $msgset->error_at("formula/{$fidx}", "{$pfx}This formula has been deleted.");
                    continue;
                }
            }
            $id2idx[$id] = $fidx;

            if (!$user->can_edit_formula($fdef)
                && (!$fdef || $name !== $fdef->name || $expr !== $fdef->expression || $deleted)) {
                $msgset->error_at("formula/{$fidx}", $fdef ? "{$pfx}You can’t change this named formula." : "You can’t create named formulas.");
                continue;
            }

            if ($deleted) {
                unset($new_formula_by_id[$id]);
                continue;
            } else if ($expr === "") {
                $msgset->error_at("formula/{$fidx}/expression", "{$pfx}Expression required.");
                continue;
            }

            if ($lname === "") {
                $msgset->error_at("formula/{$fidx}/name", "Missing formula name.");
            } else if (preg_match('/\A(?:formula[:\d].*|f:.*|[-+]?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d*)?|none|any|all|unknown)\z/', $lname)) {
                $msgset->error_at("formula/{$fidx}/name", "{$pfx}This formula name is reserved. Please pick another name.");
            } else if (preg_match_all('/[()\[\]\{\}\\\\\"\']/', $lname, $m)) {
                $msgset->error_at("formula/{$fidx}/name", "{$pfx}Characters like “" . htmlspecialchars(join("", $m[0])) . "” cannot be used in formula names. Please pick another name.");
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
                $msgset->error_at("formula/" . $id2idx[$f->formulaId] . "/name", htmlspecialchars($f->name ? $f->name . ": " : "") . "Formula names must be distinct");
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
                    $msgset->error_at("formula/" . $id2idx[$f->formulaId] . "/expression", $pfx . "This expression refers to properties you can’t access");
                }
            } else {
                foreach ($f->message_list() as $mi) {
                    $msgset->append_item($mi->with_field("formula/" . $id2idx[$f->formulaId] . "/expression"));
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

    /** @param object $v
     * @return bool */
    static function can_edit_search(Contact $user, $v) {
        return $user->privChair
            || !($v->owner ?? false)
            || $v->owner === $user->contactId;
    }

    static function namedsearch(Contact $user, Qrequest $qreq) {
        $fjs = [];
        $ms = new MessageSet;
        foreach ($user->conf->named_searches() as $sj) {
            if (($sj->qt ?? "n") === "n" && ($sj->t ?? "s") === "s") {
                $q = $sj->q;
            } else {
                $q = PaperSearch::canonical_query($sj->q, "", "", $sj->qt ?? "n", $user->conf, $sj->t ?? "s");
            }
            $nsj = ["name" => $sj->name, "q" => $q];
            if (self::can_edit_search($user, $sj)) {
                $nsj["editable"] = true;
            }
            $fjs[] = $nsj;
            $ps = new PaperSearch($user, $q);
            foreach ($ps->message_list() as $mi) {
                $ms->append_item_at("named_search/" . count($fjs) . "/q", $mi);
            }
        }
        usort($fjs, function ($a, $b) {
            return strnatcasecmp($a["name"], $b["name"]);
        });
        $j = ["ok" => true, "searches" => $fjs];
        if ($ms->has_message()) {
            $j["message_list"] = $ms->message_list();
        }
        return new JsonResult($j);
    }

    static function save_namedsearch(Contact $user, Qrequest $qreq) {
        // NB permissions handled in loop

        // capture current named searches set
        $ssjs = $user->conf->named_searches();
        foreach ($ssjs as $sj) {
            $sj->id = $sj->name;
        }
        $tagger = new Tagger($user);

        // determine new formula set from request
        $id2idx = [];
        $msgset = new MessageSet;
        for ($fidx = 1; isset($qreq["named_search/{$fidx}/id"]); ++$fidx) {
            $id = $qreq["named_search/{$fidx}/id"];
            $name = $qreq["named_search/{$fidx}/name"];
            $q = $qreq["named_search/{$fidx}/q"];
            $deleted = $qreq["named_search/{$fidx}/delete"];
            if ($id === "" || (!isset($name) && !isset($q) && !isset($deleted))) {
                continue;
            }

            $name = simplify_whitespace($name ?? "");
            $q = simplify_whitespace($q ?? "");
            $pfx = $name === "" ? "" : "{$name}: ";

            // find matching search
            $sidx = 0;
            while ($sidx !== count($ssjs)
                   && strcasecmp($id, $ssjs[$sidx]->id) !== 0) {
                ++$sidx;
            }
            if ($sidx === count($ssjs)) {
                if ($id !== "new") {
                    $msgset->error_at("named_search/{$fidx}/name", "<0>{$pfx}This search has been deleted");
                    continue;
                } else if ($deleted || ($name === "" && $q === "")) {
                    continue;
                }
            }

            // check if search is editable
            $fdef = $ssjs[$sidx] ?? null;
            if (!self::can_edit_search($user, $fdef)
                && (!$fdef
                    || strcasecmp($name, $fdef->name) !== 0
                    || $q !== $fdef->q
                    || $deleted)) {
                $msgset->error_at("named_search/{$fidx}/name", $fdef ? "<0>{$pfx}You can’t change this named search" : "<0>You can’t create named searches");
                continue;
            }

            // maybe delete search
            if ($deleted) {
                array_splice($ssjs, $sidx, 1);
                continue;
            }

            // complain about stuff
            if ($q === "") {
                $msgset->error_at("named_search/{$fidx}/q", "<0>{$pfx}Query required");
            } else if ($name === "") {
                $msgset->error_at("named_search/{$fidx}/name", "<0>Search name required");
            } else if (preg_match('/\A(?:formula[:\d].*|f:.*|ss:.*|search:.*|[-+]?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d*)?|none|any|all|unknown|new)\z/', $name)) {
                $msgset->error_at("named_search/{$fidx}/name", "<0>{$pfx}Search name reserved; please pick another name");
            } else if (!$tagger->check($name, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE)) {
                $msgset->error_at("named_search/{$fidx}/name", "<0>‘{$name}’ contains characters not allowed in search names. Stick to letters, digits, and simple punctuation.");
            } else {
                if (!$fdef) {
                    $ssjs[] = $fdef = (object) ["id" => ""];
                }
                $fdef->name = $name;
                $fdef->q = $q;
                $fdef->owner = $user->privChair ? "chair" : $user->contactId;
                $fdef->fidx = $fidx;
            }
        }

        // check for duplicate names
        $names = [];
        foreach ($ssjs as $sj) {
            $name = strtolower($sj->name);
            if (isset($names[$name])) {
                $msgset->error_at("named_search/{$sj->fidx}/name", "<0>Search name ‘{$sj->name}’ is not unique");
                $msgset->error_at("named_search/" . $names[$name] . "/name");
            } else {
                $names[$name] = $sj->fidx;
            }
        }

        // XXX should validate saved searches using new search set

        if ($msgset->has_error()) {
            return ["ok" => false, "message_list" => $msgset->message_list()];
        }

        // save result
        foreach ($ssjs as $sj) {
            unset($sj->id, $sj->fidx);
        }
        usort($ssjs, "NamedSearch_Setting::compare");
        if (!empty($ssjs)) {
            $user->conf->save_setting("named_searches", 1, json_encode_db($ssjs));
        } else {
            $user->conf->save_setting("named_searches", null);
        }
        return self::namedsearch($user, $qreq);
    }
}
