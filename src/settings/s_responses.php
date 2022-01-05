<?php
// src/settings/s_responses.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Responses_SettingParser extends SettingParser {
    static function resp_round_names(Conf $conf) {
        return explode(" ", $conf->setting_data("resp_rounds") ?? "1");
    }

    static function render_name_property(SettingValues $sv, $i) {
        $sv->echo_entry_group("resp_roundname_$i", "Response name", ["horizontal" => true]);
    }

    static function render_deadline_property(SettingValues $sv, $i) {
        if ($sv->curv("resp_open_$i") === 1
            && ($x = $sv->curv("resp_done_$i"))) {
            $sv->conf->settings["resp_open_$i"] = $x - 7 * 86400;
        }
        $sv->echo_entry_group("resp_open_$i", "Start time", ["horizontal" => true]);
        $sv->echo_entry_group("resp_done_$i", "Hard deadline", ["horizontal" => true]);
        $sv->echo_entry_group("resp_grace_$i", "Grace period", ["horizontal" => true]);
    }

    static function render_wordlimit_property(SettingValues $sv, $i) {
        $sv->echo_entry_group("resp_words_$i", "Word limit", ["horizontal" => true], $i ? null : "This is a soft limit: authors may submit longer responses. 0 means no limit.");
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
            $rname_si = $sv->si("resp_roundname_$i");
            if (!$i) {
                $rname = $rname == "1" ? "none" : $rname;
                $rname_si->placeholder = "none";
            }
            $sv->set_oldv("resp_roundname_$i", $rname);

            echo '<div id="response_', $i, '" class="form-g';
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

        if ($sv->editable("resp_roundname_0")) {
            echo '<div class="form-g">',
                Ht::button("Add response round", ["class" => "ui js-settings-resp-round-new"]),
                '</div>';
        }
        echo '</div></div></div>';
    }

    function parse_req(SettingValues $sv, Si $si) {
        if (!$sv->newv("resp_active")) {
            return;
        }
        $old_roundnames = self::resp_round_names($sv->conf);
        $roundnames = array(1);
        $roundnames_set = array();

        if ($sv->has_reqv("resp_roundname_0")) {
            $rname = trim($sv->reqv("resp_roundname_0"));
            if ($rname === "" || $rname === "none" || $rname === "1") {
                /* do nothing */
            } else if (($rerror = Conf::resp_round_name_error($rname))) {
                $sv->error_at("resp_roundname_0", $rerror);
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
            if (($v = $sv->base_parse_req("resp_open_$i")) !== null) {
                $sv->save("resp_open_$i", $v <= 0 ? null : $v);
            }
            if (($v = $sv->base_parse_req("resp_done_$i")) !== null) {
                $sv->save("resp_done_$i", $v <= 0 ? null : $v);
            }
            if (($v = $sv->base_parse_req("resp_grace_$i")) !== null) {
                $sv->save("resp_grace_$i", $v <= 0 ? null : $v);
            }
            if (($v = $sv->base_parse_req("resp_words_$i")) !== null) {
                $sv->save("resp_words_$i", $v < 0 ? null : $v);
            }
            if (($v = $sv->base_parse_req("resp_search_$i")) !== null) {
                $sv->save("resp_search_$i", $v === "" ? null : $v);
            }
            if (($v = $sv->base_parse_req("resp_instrux_$i")) !== null) {
                $sv->save("resp_instrux_$i", $v);
            }
            $sv->check_date_before("resp_open_$i", "resp_done_$i", false);
        }

        if (count($roundnames) > 1 || $roundnames[0] !== 1) {
            $sv->save("resp_rounds", join(" ", $roundnames));
        } else {
            $sv->save("resp_rounds", null);
        }
    }
}
