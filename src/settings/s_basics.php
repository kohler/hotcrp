<?php
// src/settings/s_basics.php -- HotCRP settings > info page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SettingRenderer_Basics extends SettingRenderer {
function render(SettingValues $sv) {
    echo '<div class="f-c">', $sv->label("opt.shortName", "Conference abbreviation"), "</div>\n";
    $sv->echo_entry("opt.shortName");
    echo '<div class="f-h">Examples: “HotOS XIV”, “NSDI \'14”</div>';
    echo "<div class=\"g\"></div>\n";

    if ($sv->oldv("opt.longName") == $sv->oldv("opt.shortName"))
        $sv->set_oldv("opt.longName", "");
    echo "<div class='f-c'>", $sv->label("opt.longName", "Conference name"), "</div>\n";
    $sv->echo_entry("opt.longName");
    echo '<div class="f-h">Example: “14th Workshop on Hot Topics in Operating Systems”</div>';
    echo "<div class=\"g\"></div>\n";

    echo "<div class='f-c'>", $sv->label("opt.conferenceSite", "Conference URL"), "</div>\n";
    $sv->echo_entry("opt.conferenceSite");
    echo '<div class="f-h">Example: “http://yourconference.org/”</div>';


    echo '<div class="lg"></div>', "\n";

    echo '<div class="f-c">', $sv->label("opt.contactName", "Name of site contact"), "</div>\n";
    $sv->echo_entry("opt.contactName");
    echo '<div class="g"></div>', "\n";

    echo "<div class='f-c'>", $sv->label("opt.contactEmail", "Email of site contact"), "</div>\n";
    $sv->echo_entry("opt.contactEmail");
    echo '<div class="f-h">The site contact is the contact point for users if something goes wrong. It defaults to the chair.</div>';


    echo '<div class="lg"></div>', "\n";

    echo '<div class="f-c">', $sv->label("opt.emailReplyTo", "Reply-To field for email"), "</div>\n";
    $sv->echo_entry("opt.emailReplyTo");
    echo '<div class="g"></div>', "\n";

    echo '<div class="f-c">', $sv->label("opt.emailCc", "Default Cc for reviewer email"), "</div>\n";
    $sv->echo_entry("opt.emailCc");
    echo '<div class="f-h">This applies to email sent to reviewers and email sent using the <a href="', hoturl("mail"), '">mail tool</a>. It doesn’t apply to account-related email or email sent to submitters.</div>';
}
}

SettingGroup::register("basics", "Basics", 0, new SettingRenderer_Basics);
SettingGroup::register_synonym("info", "basics");
