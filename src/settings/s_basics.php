<?php
// src/settings/s_basics.php -- HotCRP settings > info page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Basics_SettingParser {
    static function render_names(SettingValues $sv) {
        $sv->echo_entry_group("opt.shortName", null, null, "Examples: “HotOS XIV”, “NSDI '14”");

        if ($sv->oldv("opt.longName") == $sv->oldv("opt.shortName"))
            $sv->set_oldv("opt.longName", "");
        $sv->echo_entry_group("opt.longName", null, null, "Example: “14th Workshop on Hot Topics in Operating Systems”");

        $sv->echo_entry_group("opt.conferenceSite", null, null, "Example: “http://yourconference.org/”");
    }
    static function render_site_contact(SettingValues $sv) {
        $sv->echo_entry_group("opt.contactName", null);
        $sv->echo_entry_group("opt.contactEmail", null, null, "The site contact is the contact point for users if something goes wrong. It defaults to the chair.");
    }
    static function render_email(SettingValues $sv) {
        $sv->echo_entry_group("opt.emailReplyTo", null);
        $sv->echo_entry_group("opt.emailCc", null, null, 'This applies to email sent to reviewers and email sent using the <a href="' . hoturl("mail") . '">mail tool</a>. It doesn’t apply to account-related email or email sent to submitters.');
    }
}
