<?php
// settings/s_basics.php -- HotCRP settings > info page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Basics_SettingParser extends SettingParser {
    static function print_names(SettingValues $sv) {
        $sv->print_entry_group("conference_abbreviation", null, null, "Examples: “HotOS XIV”, “NSDI '14”");
        $sv->print_entry_group("conference_name", null, null, "Example: “14th Workshop on Hot Topics in Operating Systems”");
        $sv->print_entry_group("conference_url", null, null, "Example: “https://yourconference.org/”");
    }

    static function print_email(SettingValues $sv) {
        $sv->print_entry_group("email_default_cc", null);
        $sv->print_entry_group("email_default_reply_to", null);
    }

    function apply_req(SettingValues $sv, Si $si) {
        if (($v = $sv->base_parse_req($si)) !== null
            && $sv->update($si->name, $v)
            && $sv->conf->contactdb()) {
            $sv->register_cleanup_function("update_shortName", function () use ($sv) {
                $conf = $sv->conf;
                Dbl::ql($conf->contactdb(), "update Conferences set shortName=?, longName=? where dbName=?", $conf->short_name, $conf->long_name, $conf->dbname);
            });
        }
        return true;
    }
}
