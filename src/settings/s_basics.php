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
        $sv->set_oldv("site_contact_name", $site_user->name());
        $sv->set_oldv("site_contact_email", $site_user->email);
        $sv->echo_entry_group("site_contact_name", null);
        $sv->echo_entry_group("site_contact_email", null, null, "The site contact is the contact point for users if something goes wrong. It defaults to the chair.");
    }
    static function render_email(SettingValues $sv) {
        $sv->echo_entry_group("email_default_cc", null);
        $sv->echo_entry_group("email_default_reply_to", null);
    }

    function validate(SettingValues $sv, Si $si) {
        if ($si->name === "site_contact_email") {
            $default_contact = $sv->conf->default_site_contact();
            if ($default_contact
                && $sv->newv("site_contact_name") === Text::name($default_contact->firstName, $default_contact->lastName, "", 0)
                && $sv->newv("site_contact_email") === $default_contact->email
                && ($sv->conf->opt_override["contactName"] ?? null) === null
                && ($sv->conf->opt_override["contactEmail"] ?? null) === null) {
                $sv->save("site_contact_name", null);
                $sv->save("site_contact_email", null);
            }
        } else if ($si->name === "conference_abbreviation"
                   || $si->name === "conference_name") {
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
