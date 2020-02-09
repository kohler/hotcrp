<?php
// src/settings/s_basics.php -- HotCRP settings > info page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Basics_SettingParser extends SettingParser {
    static function render_names(SettingValues $sv) {
        $sv->echo_entry_group("opt.shortName", null, null, "Examples: “HotOS XIV”, “NSDI '14”");

        if ($sv->oldv("opt.longName") == $sv->oldv("opt.shortName")) {
            $sv->set_oldv("opt.longName", "");
        }
        $sv->echo_entry_group("opt.longName", null, null, "Example: “14th Workshop on Hot Topics in Operating Systems”");

        $sv->echo_entry_group("opt.conferenceSite", null, null, "Example: “https://yourconference.org/”");
    }
    static function render_site_contact(SettingValues $sv) {
        $site_user = $sv->conf->site_contact();
        $sv->set_oldv("opt.contactName", Text::name_text($site_user));
        $sv->set_oldv("opt.contactEmail", $site_user->email);
        $sv->echo_entry_group("opt.contactName", null);
        $sv->echo_entry_group("opt.contactEmail", null, null, "The site contact is the contact point for users if something goes wrong. It defaults to the chair.");
    }
    static function render_email(SettingValues $sv) {
        $sv->echo_entry_group("opt.emailReplyTo", null);
        $sv->echo_entry_group("opt.emailCc", null, null, 'This applies to email sent to reviewers and email sent using the <a href="' . $sv->conf->hoturl("mail") . '">mail tool</a>. It doesn’t apply to account-related email or email sent to submitters.');
    }

    function validate(SettingValues $sv, Si $si) {
        if ($si->name === "opt.contactEmail") {
            $default_contact = $sv->conf->default_site_contact();
            if ($default_contact
                && $sv->newv("opt.contactName") === Text::name_text($default_contact)
                && $sv->newv("opt.contactEmail") === $default_contact->email
                && get($sv->conf->opt_override, "contactName") === null
                && get($sv->conf->opt_override, "contactEmail") === null) {
                $sv->save("opt.contactName", null);
                $sv->save("opt.contactEmail", null);
            }
        } else if ($si->name === "opt.shortName"
                   || $si->name === "opt.longName") {
            if ($sv->oldv($si->name) !== $sv->newv($si->name)) {
                $sv->cleanup_callback("update_shortName", "Basics_SettingParser::update_shortName");
            }
        }
        return false;
    }
    static function update_shortName(SettingValues $sv) {
        if (($cdb = $sv->conf->contactdb())) {
            Dbl::ql($cdb, "update Conferences set shortName=?, longName=? where dbName=?", $sv->conf->short_name, $sv->conf->long_name, $sv->conf->dbname);
        }
    }
}
