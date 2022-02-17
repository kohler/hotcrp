<?php
// settings/s_decisions.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Decisions_SettingParser extends SettingParser {
    function set_oldv(SettingValues $sv, Si $si) {
        assert($si->part0 === "decision__" && $si->part2 === "");
        $did = $sv->vstr("{$si->name}__id") ?? "\$";
        if (is_numeric($did)
            && ($dnum = intval($did)) !== 0
            && ($dname = ($sv->conf->decision_map())[$dnum] ?? null)) {
            $v = (object) ["id" => $dnum, "name" => $dname, "category" => $dnum > 0 ? "a" : "r"];
        } else {
            $v = (object) ["id" => null, "name" => "", "category" => "a"];
        }
        $sv->set_oldv($si, $v);
    }

    function prepare_enumeration(SettingValues $sv, Si $si) {
        $dmap = $sv->conf->decision_map();
        unset($dmap[0]);
        $sv->map_enumeration("decision__", $dmap);
    }

    /** @param int|'$' $ctr
     * @param array<int> $countmap */
    static private function print_decrow(SettingValues $sv, $ctr, $countmap) {
        $did = $sv->vstr("decision__{$ctr}__id");
        $isnew = $did === "" || $did === "\$";
        $count = $countmap[$did] ?? 0;
        $editable = $sv->editable("decisions");
        echo '<div id="decision__', $ctr, '" class="has-fold foldo settings-decision',
            $isnew ? ' is-new' : '', '"><div class="entryi">',
            $sv->feedback_at("decision__{$ctr}__name"),
            $sv->feedback_at("decision__{$ctr}__category"),
            Ht::hidden("decision__{$ctr}__id", $isnew ? "\$" : $did, ["data-default-value" => $isnew ? "" : null]),
            $sv->entry("decision__{$ctr}__name", ["data-submission-count" => $count, "class" => $isnew ? "uii js-settings-decision-new-name" : ""]);
        if ($sv->reqstr("decision__{$ctr}__delete")) {
            echo Ht::hidden("decision__{$ctr}__delete", "1", ["data-default-value" => ""]);
        }
        Icons::stash_defs("trash");
        echo Ht::unstash();
        if ($editable) {
            echo Ht::button(Icons::ui_use("trash"), ["class" => "fx ui js-settings-decision-delete ml-2 need-tooltip", "name" => "decision__{$ctr}__deleter", "aria-label" => "Delete decision", "tabindex" => "-1"]);
        }
        echo '<span class="ml-2 d-inline-block fx">';
        $class = $sv->vstr("decision__{$ctr}__category");
        if ($isnew) {
            echo Ht::select("decision__{$ctr}__category",
                    ["a" => "Accept category", "r" => "Reject category"], $class,
                    $sv->sjs("decision__{$ctr}__category", ["data-default-value" => "a"]));
        } else {
            echo $class === "a" ? "<span class=\"pstat_decyes\">Accept</span> category" : "<span class=\"pstat_decno\">Reject</span> category";
            if ($count) {
                echo ", ", plural($count, "submission");
            }
        }
        if ($sv->has_error_at("decision__{$ctr}__category")) {
            echo '<label class="d-inline-block checki ml-2"><span class="checkc">',
                Ht::checkbox("decision__{$ctr}__name_force", 1, false),
                '</span><span class="is-error">Confirm</span></label>';
        }
        echo "</span></div></div>";
    }

    static function print(SettingValues $sv) {
        // count papers per decision
        $decs_pcount = [];
        $result = $sv->conf->qe_raw("select outcome, count(*) from Paper where timeSubmitted>0 group by outcome");
        while (($row = $result->fetch_row())) {
            $decs_pcount[(int) $row[0]] = (int) $row[1];
        }

        echo '<div class="form-g">',
            Ht::hidden("has_decisions", 1),
            '<div id="settings-decision-types">';
        foreach ($sv->enumerate("decision__") as $ctr) {
            self::print_decrow($sv, $ctr, $decs_pcount);
        }
        echo '</div>';
        foreach ($sv->use_req() ? $sv->enumerate("decision__") : [] as $ctr) {
            if ($sv->reqstr("decision__{$ctr}__delete"))
                echo Ht::unstash_script("\$(\"#settingsform\")[0].elements.decision__{$ctr}__deleter.click()");
        }
        echo '<div id="settings-decision-type-notes" class="hidden">',
            '<div class="hint">Examples: “Accepted as short paper”, “Early reject”</div></div>';
        if ($sv->editable("decisions")) {
            echo '<template id="settings-new-decision-type" class="hidden">';
            self::print_decrow($sv, '$', $decs_pcount);
            echo '</template><div class="mg">',
                Ht::button("Add decision type", ["class" => "ui js-settings-decision-add"]),
                '</div>';
        }
        echo "</div>\n";
    }

    /** @param SettingValues $sv
     * @param object $dsr
     * @param int $ctr */
    private function _check_req_name($sv, $dsr, $ctr) {
        if ($dsr->id === null || $dsr->name !== $sv->conf->decision_name($dsr->id)) {
            if (($error = Conf::decision_name_error($dsr->name))) {
                $sv->error_at("decision__{$ctr}__name", "<0>{$error}");
            }
            if (!$sv->reqstr("decision__{$ctr}__name_force")
                && stripos($dsr->name, $dsr->category === "a" ? "reject" : "accept") !== false) {
                $n1 = $dsr->category === "a" ? "An Accept" : "A Reject";
                $n2 = $dsr->category === "a" ? "reject" : "accept";
                $sv->error_at("decision__{$ctr}__name", "<0>{$n1}-category decision has “{$n2}” in its name");
                $sv->inform_at("decision__{$ctr}__name", "<0>Either change the decision name or category or check the “Confirm” box to save anyway.");
                $sv->error_at("decision__{$ctr}__category");
            }
        }
        $sv->error_if_duplicate_member("decision__", $ctr, "__name", "Decision name");
    }

    function apply_req(SettingValues $sv, Si $si) {
        if ($si->name !== "decisions") {
            return false;
        }

        $djs = [];
        $hasid = [];
        foreach ($sv->enumerate("decision__") as $ctr) {
            $dsr = $sv->parse_members("decision__{$ctr}");
            if (!$sv->reqstr("decision__{$ctr}__delete")) {
                $this->_check_req_name($sv, $dsr, $ctr);
                $djs[] = $dsr;
                $hasid[$dsr->id ?? ""] = true;
            }
        }

        // name reuse, new ids
        foreach ($djs as $dj) {
            if ($dj->id === null) {
                $idstep = $dj->id = $dj->category === "a" ? 1 : -1;
                while (isset($hasid[$dj->id])) {
                    $dj->id += $idstep;
                }
                $hasid[$dj->id] = true;
            }
        }

        // sort and save
        $collator = $sv->conf->collator();
        usort($djs, function ($a, $b) use ($collator) {
            if ($a->category !== $b->category) {
                return $a->category === "a" ? -1 : 1;
            } else {
                return $collator->compare($a->name, $b->name);
            }
        });

        $dm = [];
        foreach ($djs as $dj) {
            $dm[$dj->id] = $dj->name;
        }
        $tx = json_encode_db($dm);

        $olddm = $sv->conf->decision_map();
        unset($olddm[0]);
        if ($tx !== json_encode_db($olddm)) {
            $sv->save("outcome_map", $tx);
            $sv->request_write_lock("Paper");
            $sv->request_store_value($si);
        }
        return true;
    }

    function store_value(SettingValues $sv, Si $si) {
        $curmap = $sv->conf->decision_map();
        $newmap = json_decode($sv->newv("outcome_map"), true);
        $newmap[0] = "Unspecified";
        $dels = array_diff_key($curmap, $newmap);
        if (!empty($dels)
            && ($pids = Dbl::fetch_first_columns($sv->conf->dblink, "select paperId from Paper where outcome?a", array_keys($dels)))) {
            $sv->conf->qe("update Paper set outcome=0 where outcome?a", array_keys($dels));
            $sv->user->log_activity("Set decision: Unspecified", $pids);
        }
    }
}
