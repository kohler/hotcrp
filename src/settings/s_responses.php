<?php
// src/settings/s_responses.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Responses_SettingParser extends SettingParser {
    static function resp_round_names(Conf $conf) {
        return explode(" ", $conf->setting_data("resp_rounds") ?? "1");
    }

    static function render_name_property(SettingValues $sv, $i) {
        $sv->echo_entry_group("response/{$i}/name", "Response name", [
            "horizontal" => true,
            "control_after" => Ht::button(Icons::ui_use("trash"), ["class" => "ui js-settings-resp-round-delete ml-2 need-tooltip", "aria-label" => "Delete response round", "tabindex" => "-1"]) . Ht::checkbox("response/{$i}/delete", "1", false, ["class" => "hidden"])
        ]);
    }

    static function render_deadline_property(SettingValues $sv, $i) {
        if ($sv->curv("response/{$i}/open") === 1
            && ($x = $sv->curv("response/{$i}/done"))) {
            $sv->conf->settings["response/{$i}/open"] = $x - 7 * 86400;
        }
        $sv->echo_entry_group("response/{$i}/open", "Start time", ["horizontal" => true]);
        $sv->echo_entry_group("response/{$i}/done", "Hard deadline", ["horizontal" => true]);
        $sv->echo_entry_group("response/{$i}/grace", "Grace period", ["horizontal" => true]);
    }

    static function render_wordlimit_property(SettingValues $sv, $i) {
        $sv->echo_entry_group("response/{$i}/words", "Word limit", ["horizontal" => true], $i ? null : "This is a soft limit: authors may submit longer responses. 0 means no limit.");
    }

    static function render_instructions_property(SettingValues $sv, $i) {
        $sv->echo_message_horizontal("response/{$i}/instructions", "Instructions");
    }

    static function render(SettingValues $sv) {
        // Authors' response
        echo '<div class="form-g">';
        $sv->echo_checkbox("response_active", '<strong>Collect authors’ responses to the reviews<span class="if-response-active">:</span></strong>', ["group_open" => true, "class" => "uich js-settings-resp-active"]);
        Icons::stash_defs("trash");
        echo Ht::unstash();
        echo '<div class="if-response-active',
            $sv->curv("response_active") ? "" : " hidden",
            '"><hr class="g">', Ht::hidden("has_response_rounds", 1);

        // Response rounds
        if ($sv->use_req()) {
            $rrounds = [1];
            for ($i = 1; $sv->has_reqv("response/{$i}/name"); ++$i) {
                $rrounds[$i] = $sv->reqv("response/{$i}/name");
            }
        } else {
            $rrounds = self::resp_round_names($sv->conf);
        }
        $rrounds["\$"] = "";
        foreach ($rrounds as $i => $rname) {
            $rname_si = $sv->si("response/{$i}/name");
            if (!$i) {
                $rname = $rname == "1" ? "none" : $rname;
                $rname_si->placeholder = "none";
            }
            $sv->set_oldv("response/{$i}/name", $rname);

            if ($i === "\$") {
                echo '<div id="response_new" class="hidden">';
            }
            echo '<div id="response_', $i, '" class="form-g settings-response',
                $i === "\$" ? " settings-response-new" : "", '" data-resp-round="', $i, '">',
                Ht::hidden("response/{$i}", 1);
            foreach ($sv->group_members("responses/properties") as $gj)
                if (isset($gj->render_response_property_function)) {
                    Conf::xt_resolve_require($gj);
                    call_user_func($gj->render_response_property_function, $sv, $i, $gj);
                }
            echo ($i === "\$" ? "</div>" : ""), "</div>\n";
        }

        if ($sv->editable("response/0/name")) {
            echo '<div class="form-g">',
                Ht::button("Add response round", ["class" => "ui js-settings-resp-round-new"]),
                '</div>';
        }
        echo '</div></div></div>';
    }

    function parse_req(SettingValues $sv, Si $si) {
        if (!$sv->newv("response_active")) {
            return;
        }
        $old_roundnames = self::resp_round_names($sv->conf);
        $roundnames = [1];
        $roundnames_set = [];

        if ($sv->has_reqv("response/0")) {
            $rname = trim($sv->reqv("response/0/name") ?? "");
            if ($rname === "" || $rname === "none" || $rname === "1") {
                /* do nothing */
            } else if (($rerror = Conf::resp_round_name_error($rname))) {
                $sv->error_at("response/0/name", "<0>{$rerror}");
            } else {
                $roundnames[0] = $rname;
                $roundnames_set[strtolower($rname)] = 0;
            }
        }

        for ($i = 1; $sv->has_reqv("response/{$i}"); ++$i) {
            $rname = trim($sv->reqv("response/{$i}/name") ?? "");
            if ($rname === "" && ($old_roundnames[$i] ?? null)) {
                $rname = $old_roundnames[$i];
            }
            if ($rname === "" || $sv->reqv("response/{$i}/delete")) {
                continue;
            } else if (($rerror = Conf::resp_round_name_error($rname))) {
                $sv->error_at("response/{$i}/name", "<0>{$rerror}");
            } else if (($roundnames_set[strtolower($rname)] ?? null) !== null) {
                $sv->error_at("response/{$i}/name", "<0>Response round name “{$rname}” has already been used.");
            } else {
                $roundnames[] = $rname;
                $roundnames_set[strtolower($rname)] = $i;
            }
        }

        foreach ($roundnames_set as $i) {
            if (($v = $sv->base_parse_req("response/{$i}/open")) !== null) {
                $sv->save("response/{$i}/open", $v <= 0 ? null : $v);
            }
            if (($v = $sv->base_parse_req("response/{$i}/done")) !== null) {
                $sv->save("response/{$i}/done", $v <= 0 ? null : $v);
            }
            if (($v = $sv->base_parse_req("response/{$i}/grace")) !== null) {
                $sv->save("response/{$i}/grace", $v <= 0 ? null : $v);
            }
            if (($v = $sv->base_parse_req("response/{$i}/words")) !== null) {
                $sv->save("response/{$i}/words", $v < 0 ? null : $v);
            }
            if (($v = $sv->base_parse_req("response/{$i}/search")) !== null) {
                $sv->save("response/{$i}/search", $v === "" ? null : $v);
            }
            if (($v = $sv->base_parse_req("response/{$i}/instructions")) !== null) {
                $sv->save("response/{$i}/instructions", $v);
            }
            $sv->check_date_before("response/{$i}/open", "response/{$i}/done", false);
        }

        if (count($roundnames) > 1 || $roundnames[0] !== 1) {
            $sv->save("response_rounds", join(" ", $roundnames));
        } else {
            $sv->save("response_rounds", null);
        }
    }
}
