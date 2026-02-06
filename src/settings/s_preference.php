<?php
// settings/s_preference.php -- HotCRP preference settings
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Preference_SettingParser extends SettingParser {
    static function print_pref_shuffle(SettingValues $sv) {
        $sv->print_checkbox("preference_shuffle", "Shuffle submissions on " . Ht::link("review preferences page", $sv->conf->hoturl("reviewprefs")), [
            "hint" => "The page will display submissions in a reviewer-specific random order."
        ]);
    }
    static function print_pref_fuzz(SettingValues $sv) {
        echo '<div class="has-fold fold', $sv->oldv("preference_fuzz") ? 'o' : 'c', '">';
        $sv->print_checkbox("preference_fuzz_enable", "Preference fuzzing", [
            "hint" => "Fuzzing discourages reviewer collusion by reducing the fidelity of reviewer preferences.",
            "group_open" => true,
            "class" => "uich js-foldup"
        ]);
        echo '<div class="fx mt-3"><label>Fuzz width:';
        $sv->print_entry("preference_fuzz_amount", ["class" => "ml-2"]);
        echo '</label><p class="f-d">At fuzz width <em>F</em>, HotCRP’s autoassigner groups each reviewer’s preferences into bands of at least <em>F</em> submissions, treating all submissions in a band as equally preferred. This means a reviewer’s top choice will be among their first <em>N</em> assignments with chance roughly <em>N</em>/<em>F</em>.</p></div></div></div>';
    }

    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name === "preference_fuzz_enable") {
            $sv->set_oldv($si, $sv->conf->setting("pref_fuzz") > 1);
        } else if ($si->name === "preference_fuzz_amount") {
            $pf = $sv->conf->setting("pref_fuzz") ?? 0;
            $sv->set_oldv($si, $pf ? abs($pf) : 10);
        }
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "preference_min") {
            $v = $sv->base_parse_req($si);
            if ($v < -1000000) {
                $sv->error_at($si->name, "<0>Minimum preference must be at least -1000000");
            } else if ($v > 0) {
                $sv->error_at($si->name, "<0>Minimum preference cannot be greater than 0");
            }
            return false;
        }
        if ($si->name === "preference_max") {
            $v = $sv->base_parse_req($si);
            if ($v > 1000000) {
                $sv->error_at($si->name, "<0>Maximum preference must be at most 1000000");
            } else if ($v < 0) {
                $sv->error_at($si->name, "<0>Maximum preference cannot be less than 0");
            }
            return false;
        }
        if ($si->name === "preference_fuzz_enable") {
            $v = $sv->base_parse_req($si);
            if ($v && $sv->has_req("preference_fuzz_amount")) {
                $amt = $sv->base_parse_req("preference_fuzz_amount");
            } else if ($v) {
                $amt = 10;
            } else {
                $amt = abs($sv->conf->setting("pref_fuzz") ?? 0);
            }
            if ($v && $amt > 1) {
                $sv->save("preference_fuzz", $amt);
            } else {
                $sv->save("preference_fuzz", $amt === 10 || $amt <= 1 ? 0 : -$amt);
            }
            return false;
        }
        if ($si->name === "preference_fuzz_amount") {
            return $sv->has_req("preference_fuzz_enable")
                && !$sv->vstr("preference_fuzz_enable");
        }
        return false;
    }
}
