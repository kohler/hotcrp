<?php
// src/settings/s_messages.php -- HotCRP settings > messages page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Messages_SettingRenderer {
    static function render(SettingValues $sv) {
        $sv->echo_message("msg.home", "Home page message");
        $sv->echo_message("msg.clickthrough_submit", "Clickthrough submission terms",
                   "<div class=\"f-h fx\">Users must “accept” these terms to edit a submission. Use HTML and include a headline, such as “&lt;h2&gt;Submission terms&lt;/h2&gt;”.</div>");
        $sv->echo_message("msg.submit", "Submission message",
                   "<div class=\"f-h fx\">This message will appear on submission editing pages.</div>");
        $sv->echo_message("msg.clickthrough_review", "Clickthrough reviewing terms",
                   "<div class=\"f-h fx\">Users must “accept” these terms to edit a review. Use HTML and include a headline, such as “&lt;h2&gt;Submission terms&lt;/h2&gt;”.</div>");
        $sv->echo_message("msg.conflictdef", "Definition of conflict of interest");
        $sv->echo_message("msg.revprefdescription", "Review preference instructions");
    }
}
