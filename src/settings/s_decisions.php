<?php
// src/settings/s_decisions.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Decisions_SettingParser extends SettingParser {
    static private function render_row(SettingValues $sv, $ndec, $k, $v, $isnew, $count) {
        $vx = $v;
        if ($ndec && $sv->use_req())
            $vx = get($sv->req, "dec_name_$ndec", $v);
        echo '<tr><td class="lmentry nw">',
            Ht::entry("dec_name_$ndec", $vx, ["size" => 35, "placeholder" => "Decision name", "data-default-value" => $v]),
            '</td><td class="lmentry nw">',
            '<a href="" class="ui js-settings-remove-decision-type btn qx need-tooltip" data-tooltip="Delete" tabindex="-1">✖</a>',
            '</td><td>';
        if ($isnew) {
            echo Ht::select("dec_class_$ndec",
                    [1 => "Accept class", -1 => "Reject class"],
                    $k > 0 ? 1 : -1, ["data-default-value" => 1]);
            if ($sv->has_error_at("dec_class_$ndec")) {
                echo ' &nbsp; <label>', Ht::checkbox("dec_classconfirm_$ndec", 1, false),
                    '&nbsp;<span class="error">Confirm</span></label>';
            }
        } else {
            echo Ht::hidden("dec_val_$ndec", $k),
                    $k > 0 ? "Accept class" : "Reject class";
            if ($count) {
                echo ", ", plural($count, "paper");
            }
        }
        echo "</td></tr>\n";
    }

    static function render(SettingValues $sv) {
        // count papers per decision
        $decs_pcount = array();
        $result = $sv->conf->qe_raw("select outcome, count(*) from Paper where timeSubmitted>0 group by outcome");
        while (($row = edb_row($result)))
            $decs_pcount[$row[0]] = $row[1];

        // real decisions
        echo '<div class="settings-g">',
            Ht::hidden("has_decisions", 1),
            '<table><tbody id="settings-decision-types">';
        $ndec = 0;
        foreach ($sv->conf->decision_map() as $k => $v) {
            if ($k) {
                ++$ndec;
                self::render_row($sv, $ndec, $k, $v, false, get($decs_pcount, $k));
            }
        }
        if ($sv->use_req()) {
            for (++$ndec; isset($sv->req["dec_name_$ndec"]); ++$ndec) {
                self::render_row($sv, $ndec, $sv->req["dec_class_$ndec"], "", true, 0);
            }
        }
        echo '</tbody><tbody id="settings-decision-type-notes" class="hidden">',
            '<tr><td colspan="3" class="hint">Examples: “Accepted as short paper”, “Early reject”</td></tr>',
            '</tbody><tbody id="settings-new-decision-type" class="hidden">';
        self::render_row($sv, 0, 1, "", true, 0);
        echo '</tbody></table><div class="mg">',
            Ht::button("Add decision type", ["class" => "ui js-settings-add-decision-type btn"]),
            "</div></div>\n";
    }

    function parse(SettingValues $sv, Si $si) {
        $dec_revmap = array();
        for ($ndec = 1; isset($sv->req["dec_name_$ndec"]); ++$ndec) {
            $dname = simplify_whitespace($sv->req["dec_name_$ndec"]);
            if ($dname === "")
                /* remove decision */;
            else if (($derror = Conf::decision_name_error($dname)))
                $sv->error_at("dec_name_$ndec", htmlspecialchars($derror));
            else if (isset($dec_revmap[strtolower($dname)]))
                $sv->error_at("dec_name_$ndec", "Decision name “{$dname}” was already used.");
            else
                $dec_revmap[strtolower($dname)] = true;
            if (isset($sv->req["dec_class_$ndec"])
                && !isset($sv->req["dec_classconfirm_$ndec"])) {
                $match_accept = (stripos($dname, "accept") !== false);
                $match_reject = (stripos($dname, "reject") !== false);
                if ($sv->req["dec_class_$ndec"] > 0 && $match_reject)
                    $sv->error_at("dec_class_$ndec", "You are trying to add an Accept-class decision that has “reject” in its name, which is usually a mistake. To add the decision anyway, check the “Confirm” box and try again.");
                else if ($sv->req["dec_class_$ndec"] < 0 && $match_accept)
                    $sv->error_at("dec_class_$ndec", "You are trying to add a Reject-class decision that has “accept” in its name, which is usually a mistake.  To add the decision anyway, check the “Confirm” box and try again.");
            }
        }
        $sv->need_lock["Paper"] = true;
        return true;
    }

    function save(SettingValues $sv, Si $si) {
        // mark all used decisions
        $decs = $original_decs = $sv->conf->decision_map();
        $update = false;
        for ($ndec = 1; isset($sv->req["dec_name_$ndec"]); ++$ndec) {
            $dname = simplify_whitespace($sv->req["dec_name_$ndec"]);
            if (isset($sv->req["dec_val_$ndec"])
                && ($dval = intval($sv->req["dec_val_$ndec"]))
                && isset($decs[$dval])) {
                if ($dname === "") {
                    $sv->conf->qe("update Paper set outcome=0 where outcome=?", $dval);
                    unset($decs[$dval]);
                } else if ($dname !== $decs[$dval]) {
                    $decs[$dval] = $dname;
                }
            } else if (isset($sv->req["dec_class_$ndec"]) && $dname !== "") {
                $delta = $sv->req["dec_class_$ndec"] > 0 ? 1 : -1;
                for ($dval = $delta; isset($decs[$dval]); $dval += $delta) {
                }
                $decs[$dval] = $dname;
            }
        }
        if ($decs != $original_decs) {
            $sv->save("outcome_map", json_encode_db($decs));
        }
    }
}
