<?php
// src/settings/s_basics.php -- HotCRP settings > info page
// Copyright (c) 2006-2017 Eddie Kohler; see LICENSE.

class Basics_SettingParser {
    static function render_names(SettingValues $sv) {
        echo '<div class="f-i"><div class="f-c">',
            $sv->label("opt.shortName", "Conference abbreviation"),
            '</div>';
        $sv->echo_entry("opt.shortName");
        echo '<div class="f-h">Examples: “HotOS XIV”, “NSDI \'14”</div>',
            "</div>\n";

        if ($sv->oldv("opt.longName") == $sv->oldv("opt.shortName"))
            $sv->set_oldv("opt.longName", "");
        echo '<div class="f-i"><div class="f-c">',
            $sv->label("opt.longName", "Conference name"),
            '</div>';
        $sv->echo_entry("opt.longName");
        echo '<div class="f-h">Example: “14th Workshop on Hot Topics in Operating Systems”</div>',
            "</div>\n";

        echo '<div class="f-i"><div class="f-c">',
            $sv->label("opt.conferenceSite", "Conference URL"),
            '</div>';
        $sv->echo_entry("opt.conferenceSite");
        echo '<div class="f-h">Example: “http://yourconference.org/”</div>',
            "</div>\n";
    }
    static function render_site_contact(SettingValues $sv) {
        echo '<div class="f-i"><div class="f-c">',
            $sv->label("opt.contactName", "Name of site contact"),
            '</div>';
        $sv->echo_entry("opt.contactName");
        echo "</div>\n";

        echo '<div class="f-i"><div class="f-c">',
            $sv->label("opt.contactEmail", "Email of site contact"),
            '</div>';
        $sv->echo_entry("opt.contactEmail");
        echo '<div class="f-h">The site contact is the contact point for users if something goes wrong. It defaults to the chair.</div>',
            "</div>\n";
    }
    static function render_email(SettingValues $sv) {
        echo '<div class="f-i"><div class="f-c">',
            $sv->label("opt.emailReplyTo", "Reply-To field for email"),
            '</div>';
        $sv->echo_entry("opt.emailReplyTo");
        echo "</div>\n";

        echo '<div class="f-i"><div class="f-c">',
            $sv->label("opt.emailCc", "Default Cc for reviewer email"),
            '</div>';
        $sv->echo_entry("opt.emailCc");
        echo '<div class="f-h">This applies to email sent to reviewers and email sent using the <a href="', hoturl("mail"), '">mail tool</a>. It doesn’t apply to account-related email or email sent to submitters.</div>',
            "</div>\n";
    }
}
