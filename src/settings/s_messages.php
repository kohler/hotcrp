<?php
// settings/s_messages.php -- HotCRP settings > messages page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Messages_SettingParser extends SettingParser {
    static function print_submissions(SettingValues $sv) {
        $sv->print_message("home_message", "Home page message");
        $sv->print_message("submission_terms", "Clickthrough submission terms",
                   "<div class=\"f-h fx\">Users must “accept” these terms to edit a submission. Use HTML and include a headline, such as “&lt;h2&gt;Submission terms&lt;/h2&gt;”.</div>");
        $sv->print_message("submission_edit_message", "Submission message",
                   "<div class=\"f-h fx\">This message will appear on submission editing pages.</div>");
    }
    static function print_reviews(SettingValues $sv) {
        $sv->print_message("review_terms", "Clickthrough reviewing terms",
                   "<div class=\"f-h fx\">Users must “accept” these terms to edit a review. Use HTML and include a headline, such as “&lt;h2&gt;Submission terms&lt;/h2&gt;”.</div>");
        $sv->print_message("conflict_description", "Definition of conflict of interest");
        $sv->print_message("preference_instructions", "Review preference instructions");
    }
}
