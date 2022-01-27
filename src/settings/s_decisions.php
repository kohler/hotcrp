<?php
// src/settings/s_decisions.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Decisions_SettingParser extends SettingParser {
    /** @param int|'$' $ctr
     * @param bool $isnew
     * @param int $count */
    static private function render_decrow(SettingValues $sv, $ctr, $isnew, $count) {
        $editable = $sv->editable("decisions");
        echo '<div id="decision__', $ctr, '" class="has-fold foldo settings-decision mb-2', $isnew ? ' settings-decision-new' : '', '">',
            $sv->feedback_at("decision__{$ctr}__name"),
            $sv->feedback_at("decision__{$ctr}__category"),
            Ht::hidden("decision__{$ctr}__id", $isnew ? "new" : $sv->vstr("decision__{$ctr}__id")),
            Ht::hidden("decision__{$ctr}__delete", "", ["data-default-value" => $isnew ? "1" : ""]),
            $sv->entry("decision__{$ctr}__name", ["data-submission-count" => $count, "class" => $isnew ? "uii js-settings-new-decision-name" : ""]);
        Icons::stash_defs("trash");
        echo Ht::unstash();
        if ($editable) {
            echo Ht::button(Icons::ui_use("trash"), ["class" => "fx ui js-settings-remove-decision-type ml-2 need-tooltip", "aria-label" => "Delete decision", "tabindex" => "-1"]);
        }
        echo '<span class="ml-2 d-inline-block fx">';
        $class = $sv->vstr("decision__{$ctr}__category");
        if ($isnew) {
            echo Ht::select("decision__{$ctr}__category",
                    ["a" => "Accept category", "r" => "Reject category"], $class,
                    $sv->sjs("decision__{$ctr}__category", ["data-default-value" => "a"]));
            if ($sv->has_error_at("decision__{$ctr}__category")) {
                echo '<label class="d-inline-block checki ml-2"><span class="checkc">',
                    Ht::checkbox("decision__{$ctr}__categoryforce", 1, false),
                    '</span><span class="is-error">Confirm</span></label>';
            }
        } else {
            echo $class === "a" ? "Accept category" : "Reject category";
            if ($count) {
                echo ", ", plural($count, "submission");
            }
        }
        echo "</span></div>";
    }

    static function render(SettingValues $sv) {
        // count papers per decision
        $decs_pcount = [];
        $result = $sv->conf->qe_raw("select outcome, count(*) from Paper where timeSubmitted>0 group by outcome");
        while (($row = $result->fetch_row())) {
            $decs_pcount[(int) $row[0]] = (int) $row[1];
        }

        echo '<div class="form-g">',
            Ht::hidden("has_decisions", 1),
            '<div id="settings-decision-types">';
        $ctr = 1;
        foreach ($sv->conf->decision_map() as $did => $v) {
            if ($did !== 0) {
                $sv->set_oldv("decision__{$ctr}__name", $v);
                $sv->set_oldv("decision__{$ctr}__category", $did > 0 ? "a" : "r");
                $sv->set_oldv("decision__{$ctr}__id", $did);
                self::render_decrow($sv, $ctr, false, $decs_pcount[$did] ?? 0);
                ++$ctr;
            }
        }
        while ($sv->use_req() && $sv->has_req("decision__{$ctr}__id")) {
            self::render_decrow($sv, $ctr, true, 0);
            ++$ctr;
        }
        echo '</div><div id="settings-decision-type-notes" class="hidden">',
            '<div class="hint">Examples: “Accepted as short paper”, “Early reject”</div></div>';
        if ($sv->editable("decisions")) {
            echo '<template id="settings-new-decision-type" class="hidden">';
            self::render_decrow($sv, '$', true, 0);
            echo '</template><div class="mg">',
                Ht::button("Add decision type", ["class" => "ui js-settings-add-decision-type"]),
                '</div>';
        }
        echo "</div>\n";
    }

    function set_oldv(SettingValues $sv, Si $si) {
        $j = [];
        foreach ($sv->conf->decision_map() as $did => $name) {
            if ($did)
                $j[] = ["id" => $did, "accept" => $did > 0, "name" => $name];
        }
        $sv->set_oldv("decisions", json_encode_db($j));
    }

    /** @param SettingValues $sv
     * @param int $ctr
     * @param list<object> &$dj */
    private function parse_req_row($sv, $ctr, &$dj) {
        $did = $sv->reqstr("decision__{$ctr}__id");
        for ($idx = 0; $idx !== count($dj) && (string) $dj[$idx]->id !== $did; ++$idx) {
        }
        if ($sv->reqstr("decision__{$ctr}__delete")) {
            if ($idx < count($dj)) {
                array_splice($dj, $idx, 1);
            }
            return;
        } else if (!$sv->has_req("decision__{$ctr}__name")) {
            return;
        }

        $dname = $sv->base_parse_req("decision__{$ctr}__name");
        $dx = $dj[$idx] ?? (object) [
            "id" => "new", "accept" => $sv->reqstr("decision__{$ctr}__category") === "a", "name" => $dname
        ];
        $dx->ctr = $ctr;
        if ($dname === "") {
            $sv->error_at("decision__{$ctr}__name", "<0>Entry required");
        } else if ($dx->id !== "new" && $dx->name === $dname) {
            // ok
        } else if (($error = Conf::decision_name_error($dname))) {
            $sv->error_at("decision__{$ctr}__name", "<0>{$error}");
        } else if (!$sv->reqstr("decision__{$ctr}__categoryforce")
                   && stripos($dname, $dx->accept ? "reject" : "accept") !== false) {
            $n1 = $dx->accept ? "An Accept" : "A Reject";
            $n2 = $dx->accept ? "reject" : "accept";
            $sv->error_at("decision__{$ctr}__category", "<0>{$n1}-category decision has “{$n2}” in its name");
            $sv->inform_at("decision__{$ctr}__category", "<0>Either change the decision name or category or check the “Confirm” box to save anyway.");
        } else if ($dx->id !== "new") {
            $dj[$idx]->name = $dname;
        } else {
            $dx->id = $dx->accept ? 1 : -1;
            for ($j = 0; $j !== count($dj); ++$j) {
                if ($dj[$j]->id === $dx->id) {
                    $dx->id += $dx->accept ? 1 : -1;
                    $j = -1;
                }
            }
            $dj[] = $dx;
        }
    }

    function apply_req(SettingValues $sv, Si $si) {
        $dj = json_decode($sv->oldv("decisions")) ?? [];
        for ($ctr = 1; $sv->has_req("decision__{$ctr}__id"); ++$ctr) {
            $this->parse_req_row($sv, $ctr, $dj);
        }

        // check for name reuse
        for ($i = 0; $i !== count($dj); ++$i) {
            for ($j = 0; $j !== $i; ++$j) {
                if (strcasecmp($dj[$i]->name, $dj[$j]->name) === 0
                    && ($ctr = $dj[$i]->ctr ?? $dj[$j]->ctr ?? null) !== null) {
                    $sv->error_at("decision__{$ctr}__name", "<0>Decision name ‘" . $dj[$i]->name . "’ reused");
                    if (isset($dj[$j]->ctr)) {
                        $sv->error_at("decision__" . $dj[$j]->ctr . "__name", "");
                    }
                    break;
                }
            }
        }

        // sort and save
        $collator = $sv->conf->collator();
        usort($dj, function ($a, $b) use ($collator) {
            if ($a->accept !== $b->accept) {
                return $a->accept ? -1 : 1;
            } else {
                return $collator->compare($a->name, $b->name);
            }
        });
        foreach ($dj as $dx) {
            unset($dx->ctr);
        }
        if ($sv->update("decisions", json_encode_db($dj))) {
            $sv->request_write_lock("Paper");
            $sv->request_store_value($si);
        }
        return true;
    }

    function store_value(SettingValues $sv, Si $si) {
        $curmap = $sv->conf->decision_map();
        $newmap = [0 => "Unspecified"];
        foreach (json_decode($sv->newv("decisions")) as $d) {
            $newmap[$d->id] = $d->name;
        }
        $sv->save("outcome_map", json_encode_db($newmap));
        $dels = array_diff_key($curmap, $newmap);
        if (!empty($dels)
            && ($pids = Dbl::fetch_first_columns($sv->conf->dblink, "select paperId from Paper where outcome?a", array_keys($dels)))) {
            $sv->conf->qe("update Paper set outcome=0 where outcome?a", array_keys($dels));
            $sv->user->log_activity("Set decision: Unspecified", $pids);
        }
    }
}
