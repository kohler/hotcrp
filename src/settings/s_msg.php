<?php
// src/settings/s_msg.php -- HotCRP settings > messages page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SettingRenderer_Messages extends SettingRenderer{
    function render(SettingValues $sv) {
        $sv->echo_message("msg.home", "Home page message");
        $sv->echo_message("msg.clickthrough_submit", "Clickthrough submission terms",
                   "<div class=\"hint fx\">Users must “accept” these terms to edit or submit a paper. Use HTML and include a headline, such as “&lt;h2&gt;Submission terms&lt;/h2&gt;”.</div>");
        $sv->echo_message("msg.submit", "Submission message",
                   "<div class=\"hint fx\">This message will appear on paper editing pages.</div>");
        $sv->echo_message("msg.clickthrough_review", "Clickthrough reviewing terms",
                   "<div class=\"hint fx\">Users must “accept” these terms to edit a review. Use HTML and include a headline, such as “&lt;h2&gt;Submission terms&lt;/h2&gt;”.</div>");
        $sv->echo_message("msg.conflictdef", "Definition of conflict of interest");
        $sv->echo_message("msg.revprefdescription", "Review preference instructions");
    }
}

SettingGroup::register("msg", "Messages", 200, new SettingRenderer_Messages);
