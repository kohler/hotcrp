<?php
// src/settings/s_responses.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class Responses_SettingParser extends SettingParser {
    static function resp_round_names(Conf $conf) {
        return explode(" ", $conf->setting_data("resp_rounds") ?? "1");
    }

    static function render_name_property(SettingValues $sv, $i) {
        $isuf = $i ? "_$i" : "";
        $sv->echo_entry_group("resp_roundname$isuf", "Response name", ["horizontal" => true]);
    }

    static function render_deadline_property(SettingValues $sv, $i) {
        $isuf = $i ? "_$i" : "";
        if ($sv->curv("resp_open$isuf") === 1
            && ($x = $sv->curv("resp_done$isuf"))) {
            $sv->conf->settings["resp_open$isuf"] = $x - 7 * 86400;
        }
        $sv->echo_entry_group("resp_open$isuf", "Start time", ["horizontal" => true]);
        $sv->echo_entry_group("resp_done$isuf", "Hard deadline", ["horizontal" => true]);
        $sv->echo_entry_group("resp_grace$isuf", "Grace period", ["horizontal" => true]);
    }

    static function render_wordlimit_property(SettingValues $sv, $i) {
        $isuf = $i ? "_$i" : "";
        $sv->echo_entry_group("resp_words$isuf", "Word limit", ["horizontal" => true], $i ? null : "This is a soft limit: authors may submit longer responses. 0 means no limit.");
    }

    static function render_instructions_property(SettingValues $sv, $i) {
        $sv->echo_message_horizontal("resp_instrux_$i", "Instructions");
    }

    static function render(SettingValues $sv) {
        // Authors' response
        echo '<div class="form-g">';
        $sv->echo_checkbox("resp_active", '<strong>Collect authors’ responses to the reviews<span class="if-response-active">:</span></strong>', ["group_open" => true, "class" => "uich js-settings-resp-active"]);
        echo '<div id="auresparea" class="if-response-active',
            $sv->curv("resp_active") ? "" : " hidden",
            '"><hr class="g">', Ht::hidden("has_resp_rounds", 1);

        // Response rounds
        if ($sv->use_req()) {
            $rrounds = array(1);
            for ($i = 1; $sv->has_reqv("resp_roundname_$i"); ++$i)
                $rrounds[$i] = $sv->reqv("resp_roundname_$i");
        } else {
            $rrounds = self::resp_round_names($sv->conf);
        }
        $rrounds["n"] = "";
        foreach ($rrounds as $i => $rname) {
            $isuf = $i ? "_$i" : "";
            $rname_si = $sv->si("resp_roundname$isuf");
            if (!$i) {
                $rname = $rname == "1" ? "none" : $rname;
                $rname_si->placeholder = "none";
            }
            $sv->set_oldv("resp_roundname$isuf", $rname);

            echo '<div id="response', $isuf, '" class="form-g';
            if ($i === "n")
                echo ' hidden';
            echo '">';
            foreach ($sv->group_members("responses/properties") as $gj)
                if (isset($gj->render_response_property_function)) {
                    Conf::xt_resolve_require($gj);
                    call_user_func($gj->render_response_property_function, $sv, $i, $gj);
                }
            echo "</div>\n";
        }

        if ($sv->editable("resp_roundname")) {
            echo '<div class="form-g">',
                Ht::button("Add response round", ["class" => "ui js-settings-resp-round-new"]),
                '</div>';
        }
        echo '</div></div></div>';
    }

    function parse(SettingValues $sv, Si $si) {
        if (!$sv->newv("resp_active"))
            return false;
        $old_roundnames = self::resp_round_names($sv->conf);
        $roundnames = array(1);
        $roundnames_set = array();

        if ($sv->has_reqv("resp_roundname")) {
            $rname = trim($sv->reqv("resp_roundname"));
            if ($rname === "" || $rname === "none" || $rname === "1") {
                /* do nothing */
            } else if (($rerror = Conf::resp_round_name_error($rname))) {
                $sv->error_at("resp_roundname", $rerror);
            } else {
                $roundnames[0] = $rname;
                $roundnames_set[strtolower($rname)] = 0;
            }
        }

        for ($i = 1; $sv->has_reqv("resp_roundname_$i"); ++$i) {
            $rname = trim($sv->reqv("resp_roundname_$i"));
            if ($rname === "" && ($old_roundnames[$i] ?? null)) {
                $rname = $old_roundnames[$i];
            }
            if ($rname === "") {
                continue;
            } else if (($rerror = Conf::resp_round_name_error($rname))) {
                $sv->error_at("resp_roundname_$i", $rerror);
            } else if (($roundnames_set[strtolower($rname)] ?? null) !== null) {
                $sv->error_at("resp_roundname_$i", "Response round name “" . htmlspecialchars($rname) . "” has already been used.");
            } else {
                $roundnames[] = $rname;
                $roundnames_set[strtolower($rname)] = $i;
            }
        }

        foreach ($roundnames_set as $i) {
            $isuf = $i ? "_$i" : "";
            if (($v = $sv->parse_value($sv->si("resp_open$isuf"))) !== null) {
                $sv->save("resp_open$isuf", $v <= 0 ? null : $v);
            }
            if (($v = $sv->parse_value($sv->si("resp_done$isuf"))) !== null) {
                $sv->save("resp_done$isuf", $v <= 0 ? null : $v);
            }
            if (($v = $sv->parse_value($sv->si("resp_grace$isuf"))) !== null) {
                $sv->save("resp_grace$isuf", $v <= 0 ? null : $v);
            }
            if (($v = $sv->parse_value($sv->si("resp_words$isuf"))) !== null) {
                $sv->save("resp_words$isuf", $v < 0 ? null : $v);
            }
            if (($v = $sv->parse_value($sv->si("resp_search$isuf"))) !== null) {
                $sv->save("resp_search$isuf", $v !== "" ? $v : null);
            }
            if (($v = $sv->parse_value($sv->si("resp_instrux_$i"))) !== null) {
                $sv->save("resp_instrux_$i", $v);
            }
            $sv->check_date_before("resp_open$isuf", "resp_done$isuf", false);
        }

        if (count($roundnames) > 1 || $roundnames[0] !== 1) {
            $sv->save("resp_rounds", join(" ", $roundnames));
        } else {
            $sv->save("resp_rounds", null);
        }
        return false;
    }
}
