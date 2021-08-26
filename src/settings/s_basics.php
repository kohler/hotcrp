<?php
// src/settings/s_basics.php -- HotCRP settings > info page
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class Basics_SettingParser extends SettingParser {
    static function render_names(SettingValues $sv) {
        $sv->echo_entry_group("conference_abbreviation", null, null, "Examples: “HotOS XIV”, “NSDI '14”");

        if ($sv->oldv("conference_name") == $sv->oldv("conference_abbreviation")) {
            $sv->set_oldv("conference_name", "");
        }
        $sv->echo_entry_group("conference_name", null, null, "Example: “14th Workshop on Hot Topics in Operating Systems”");

        $sv->echo_entry_group("conference_url", null, null, "Example: “https://yourconference.org/”");
    }
    static function render_site_contact(SettingValues $sv) {
        $site_user = $sv->conf->site_contact();
        $default_site_user = $sv->conf->default_site_contact();

        $si = $sv->si("site_contact_name");
        if ($default_site_user->email === $site_user->email) {
            $si->placeholder = $site_user->name();
        } else {
            $si->placeholder = "(none)";
        }
        $sv->echo_entry_group("site_contact_name", null);

        $si = $sv->si("site_contact_email");
        $si->placeholder = $default_site_user->email;
        $sv->echo_entry_group("site_contact_email", null, null, "The site contact is the contact point for users if something goes wrong. It defaults to the chair.");
    }
    static function render_email(SettingValues $sv) {
        $sv->echo_entry_group("email_default_cc", null);
        $sv->echo_entry_group("email_default_reply_to", null);
    }

    function parse_req(SettingValues $sv, Si $si) {
        $v = $sv->base_parse_req($si);
        if ($v === null) {
            // do nothing
        } else if ($si->name === "site_contact_email") {
            $default_site_user = $sv->conf->default_site_contact();
            $new_name = (string) $sv->newv("site_contact_name");
            if ($default_site_user
                && $v === $default_site_user->email
                && ($new_name === "" || $new_name === $default_site_user->name())
                && ($sv->conf->opt_override["contactName"] ?? null) === null
                && ($sv->conf->opt_override["contactEmail"] ?? null) === null) {
                $sv->save("site_contact_name", null);
                $sv->save("site_contact_email", null);
            } else {
                $sv->save("site_contact_email", $v === "" ? null : $v);
            }
        } else if ($si->name === "conference_abbreviation"
                   || $si->name === "conference_name") {
            $sv->save($si->name, $v === "" ? null : $v);
            if ($sv->oldv($si->name) !== $sv->newv($si->name)
                && $sv->conf->contactdb()) {
                $sv->register_cleanup_function("update_shortName", function () use ($sv) {
                    $conf = $sv->conf;
                    Dbl::ql($conf->contactdb(), "update Conferences set shortName=?, longName=? where dbName=?", $conf->short_name, $conf->long_name, $conf->dbname);
                });
            }
        }
        return false;
    }
}
