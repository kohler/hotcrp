<?php
// src/settings/s_decisions.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Decisions_SettingParser extends SettingParser {
    /** @param string $suffix
     * @param bool $isnew
     * @param int $count */
    static private function render_decrow(SettingValues $sv, $suffix, $isnew, $count) {
        $editable = $sv->editable("decisions");
        echo '<div class="has-fold foldo is-decision-type mb-2', $isnew ? ' is-new-decision-type' : '', '">',
            $sv->feedback_at("dec_name_$suffix"),
            $sv->feedback_at("dec_class_$suffix"),
            $sv->entry("dec_name_$suffix", ["data-submission-count" => $count]);
        if ($editable) {
            echo Ht::button("✖", ["class" => "fx ui js-settings-remove-decision-type ml-2 need-tooltip", "aria-label" => "Delete decision", "tabindex" => "-1"]);
        }
        echo '<span class="ml-2 d-inline-block fx">';
        $class = $sv->curv("dec_class_$suffix");
        if ($isnew) {
            echo Ht::select("dec_class_$suffix",
                    ["a" => "Accept category", "r" => "Reject category"], $class,
                    $sv->sjs("dec_class_$suffix", ["data-default-value" => "a"]));
            if ($sv->has_error_at("dec_class_$suffix")) {
                echo '<label class="d-inline-block checki ml-2"><span class="checkc">',
                    Ht::checkbox("dec_classconfirm_$suffix", 1, false),
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
        foreach ($sv->conf->decision_map() as $k => $v) {
            if ($k !== 0) {
                $suffix = $k > 0 ? $k : "m" . -$k;
                $sv->set_oldv("dec_name_$suffix", $v);
                $sv->set_oldv("dec_class_$suffix", $k > 0 ? "a" : "r");
                self::render_decrow($sv, $suffix, false, $decs_pcount[$k] ?? 0);
            }
        }
        for ($n = 1; $sv->use_req() && $sv->has_reqv("dec_name_n$n"); ++$n) {
            self::render_decrow($sv, "n$n", true, 0);
        }
        echo '</div><div id="settings-decision-type-notes" class="hidden">',
            '<div class="hint">Examples: “Accepted as short paper”, “Early reject”</div></div>';
        if ($sv->editable("decisions")) {
            echo '<div id="settings-new-decision-type" class="hidden">';
            self::render_decrow($sv, '$', true, 0);
            echo '</div><div class="mg">',
                Ht::button("Add decision type", ["class" => "ui js-settings-add-decision-type"]),
                '</div>';
        }
        echo "</div>\n";
    }

    function set_oldv(SettingValues $sv, Si $si) {
        $j = [];
        foreach ($sv->conf->decision_map() as $did => $name) {
            if ($did)
                $j[] = (object) ["id" => $did, "category" => $did > 0 ? "a" : "r", "name" => $name];
        }
        $sv->set_oldv("decisions", json_encode_db($j));
        return true;
    }

    function parse_req(SettingValues $sv, Si $si) {
        $dj = json_decode($sv->oldv("decisions")) ?? [];

        // XXX old style, would be better to parse each setting separately
        $suffixes = [];
        foreach ($sv->req as $k => $v) {
            if (str_starts_with($k, "dec_name_")) {
                $suffixes[] = substr($k, 9);
            }
        }

        // parse request
        foreach ($suffixes as $suffix) {
            $name = $sv->base_parse_req("dec_name_$suffix");
            if (str_starts_with($suffix, "n")) {
                $did = "new";
                $klass = $sv->reqv("dec_class_$suffix") === "r" ? "r" : "a";
                $i = count($dj);
            } else {
                $did = str_starts_with($suffix, "m") ? -(int) substr($suffix, 1) : (int) $suffix;
                $klass = $did < 0 ? "r" : "a";
                for ($i = 0; $i !== count($dj) && $dj[$i]->id !== $did; ++$i) {
                }
            }
            if ($i < count($dj) && $dj[$i]->name === $name) {
                // always ok
            } else if ($name === "") {
                if ($i < count($dj)) {
                    array_splice($dj, $i, 1);
                }
            } else if (($error = Conf::decision_name_error($name))) {
                $sv->error_at("dec_name_$suffix", htmlspecialchars($error));
            } else if ($i < count($dj)
                       || $sv->reqv("dec_classconfirm_$suffix")
                       || ($klass === "a" && stripos($name, "reject") === false)
                       || ($klass === "r" && stripos($name, "accept") === false)) {
                if ($i === count($dj)) {
                    $dj[] = (object) ["id" => $did, "category" => $klass, "name" => ""];
                    if ($did === "new")
                        $dj[$i]->suffix = $suffix;
                }
                $dj[$i]->name = $name;
            } else {
                $n1 = $klass === "a" ? "An Accept" : "A Reject";
                $n2 = $klass === "a" ? "reject" : "accept";
                $sv->error_at("dec_class_$suffix", "{$n1}-category decision should not typically have “{$n2}” in its name. Either change the decision name or category or check the “Confirm” box to save anyway.");
            }
        }

        // check for name reuse
        $revmap = [];
        foreach ($dj as $d) {
            $n = strtolower($d->name);
            if (isset($revmap[$n])) {
                $suffix = $d->suffix ?? ($d->id > 0 ? $d->id : "m" . -$d->id);
                $sv->error_at("dec_name_$suffix", "Decision name “" . htmlspecialchars($d->name) . "” cannot be reused.");
            } else {
                $revmap[$n] = true;
            }
        }

        // sort and save
        usort($dj, function ($a, $b) {
            if ($a->category !== $b->category) {
                return $a->category === "r" ? -1 : 1;
            } else {
                return strcasecmp($a->name, $b->name);
            }
        });
        if ($sv->update("decisions", json_encode_db($dj))) {
            $sv->request_write_lock("Paper");
            $sv->request_store_value($si);
        }
    }

    function unparse_json(SettingValues $sv, Si $si) {
        return json_decode($sv->oldv("decisions"));
    }

    function store_value(SettingValues $sv, Si $si) {
        $curmap = $sv->conf->decision_map();
        $newmap = [];
        foreach (json_decode($sv->newv("decisions")) as $d) {
            if (($did = $d->id) === "new") {
                $did = $delta = ($d->category === "r" ? -1 : 1);
                while (isset($newmap[$did]) || isset($curmap[$did])) {
                    $did += $delta;
                }
            }
            $newmap[$did] = $d->name;
        }
        $sv->save("outcome_map", json_encode_db($newmap));
        $dels = array_diff_key($curmap, $newmap);
        if (!empty($dels)) {
            $sv->conf->qe("update Paper set outcome=0 where outcome?a", array_keys($dels));
        }
    }
}
