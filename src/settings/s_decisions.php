<?php
// src/settings/s_decisions.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class Decisions_SettingParser extends SettingParser {
    /** @param string $suffix
     * @param bool $isnew
     * @param int $count */
    static private function render_decrow(SettingValues $sv, $suffix, $isnew, $count) {
        $editable = $sv->editable("decisions");
        echo '<div class="has-fold foldo is-decision-type mb-2', $isnew ? ' is-new-decision-type' : '', '">',
            $sv->feedback_at("dec_name_$suffix"),
            $sv->feedback_at("dec_class_$suffix"),
            $sv->entry("dec_name_$suffix", ["readonly" => !$editable, "data-submission-count" => $count]);
        if ($editable) {
            echo '<a href="" class="fx ui js-settings-remove-decision-type ml-2 btn qx need-tooltip" aria-label="Delete decision" tabindex="-1">✖</a>';
        }
        echo '<span class="ml-2 d-inline-block fx">';
        $class = $sv->curv("dec_class_$suffix");
        if ($isnew) {
            echo Ht::select("dec_class_$suffix",
                    ["a" => "Accept class", "r" => "Reject class"], $class,
                    $sv->sjs("dec_class_$suffix", ["data-default-value" => "a", "disabled" => !$editable]));
            if ($sv->has_error_at("dec_class_$suffix")) {
                echo '<label class="d-inline-block checki ml-2"><span class="checkc">',
                    Ht::checkbox("dec_classconfirm_$suffix", 1, false),
                    '</span><span class="is-error">Confirm</span></label>';
            }
        } else {
            echo $class === "a" ? "Accept class" : "Reject class";
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

    function parse_req(SettingValues $sv, Si $si) {
        $curmap = $sv->conf->decision_map();
        $map = $revmap = $newlist = [];
        foreach ($sv->req_suffixes("dec_name") as $suffix) {
            $dname = $sv->base_parse_req("dec_name$suffix");
            if ($dname === "") {
                // remove decision
            } else if (($t = Conf::decision_name_error($dname))) {
                $sv->error_at("dec_name$suffix", htmlspecialchars($t));
            } else if (isset($revmap[strtolower($dname)])) {
                $sv->error_at("dec_name$suffix", "Decision name “{$dname}” cannot be reused.");
            } else {
                $revmap[strtolower($dname)] = true;
                if (str_starts_with($suffix, "_n")) {
                    $klass = $sv->reqv("dec_class$suffix") === "r" ? "r" : "a";
                    if ($sv->has_reqv("dec_classconfirm$suffix")
                        || ($klass === "a" && stripos($dname, "reject") === false)
                        || ($klass === "r" && stripos($dname, "accept") === false)) {
                        $newlist[] = [$dname, $klass];
                    } else {
                        $n1 = $klass === "a" ? "an Accept" : "a Reject";
                        $n2 = $klass === "a" ? "reject" : "accept";
                        $sv->error_at("dec_class$suffix", "An {$n1}-class decision should not typically have “{$n2}” in its name. Either change the decision name or decision class or check the “Confirm” box to save anyway.");
                    }
                } else if (str_starts_with($suffix, "_m")) {
                    $map[-(int) substr($suffix, 2)] = $dname;
                } else {
                    $map[(int) substr($suffix, 1)] = $dname;
                }
            }
        }
        foreach ($newlist as $nl) {
            $val = $step = $nl[1] === "a" ? 1 : -1;
            while (isset($map[$val]) || isset($curmap[$val])) {
                $val += $step;
            }
            $map[$val] = $nl[0];
        }
        ksort($curmap);
        ksort($map);
        if ($curmap != $map) {
            $sv->save("outcome_map", json_encode_db($map));
            $sv->request_write_lock("Paper");
            $sv->request_store_value($si);
        }
    }

    function store_value(SettingValues $sv, Si $si) {
        $curmap = $sv->conf->decision_map();
        $map = json_decode($sv->newv("outcome_map"), true);
        $dels = [];
        foreach ($curmap as $k => $v) {
            if ($k && !isset($map[$k]))
                $dels[] = $k;
        }
        if (!empty($dels)) {
            $sv->conf->qe("update Paper set outcome=0 where outcome?a", $dels);
        }
    }
}
