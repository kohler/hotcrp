<?php
// src/settings/s_basics.php -- HotCRP settings > info page
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class Basics_SettingParser extends SettingParser {
    static function render_names(SettingValues $sv) {
        $sv->echo_entry_group("conference_abbreviation", null, null, "Examples: “HotOS XIV”, “NSDI '14”");
        $sv->echo_entry_group("conference_name", null, null, "Example: “14th Workshop on Hot Topics in Operating Systems”");
        $sv->echo_entry_group("conference_url", null, null, "Example: “https://yourconference.org/”");
    }

    static function render_email(SettingValues $sv) {
        $sv->echo_entry_group("email_default_cc", null);
        $sv->echo_entry_group("email_default_reply_to", null);
    }

    function parse_req(SettingValues $sv, Si $si) {
        $v = $sv->base_parse_req($si);
        if ($v !== null
            && $sv->update($si->name, $v === "" ? null : $v)
            && $sv->conf->contactdb()) {
            $sv->register_cleanup_function("update_shortName", function () use ($sv) {
                $conf = $sv->conf;
                Dbl::ql($conf->contactdb(), "update Conferences set shortName=?, longName=? where dbName=?", $conf->short_name, $conf->long_name, $conf->dbname);
            });
        }
    }

    function unparse_json(SettingValues $sv, Si $si) {
        return $si->base_unparse_json($sv->newv($si->name));
    }
}
