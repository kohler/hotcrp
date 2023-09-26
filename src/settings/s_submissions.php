<?php
// settings/s_submissions.php -- HotCRP settings > submissions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Submissions_SettingParser extends SettingParser {
    static function print_open(SettingValues $sv) {
        $sv->print_checkbox("submission_open", '<strong>Open site for submissions</strong>');
    }
    static function print_deadlines(SettingValues $sv) {
        // maybe sub_reg was overridden
        $main_sr = $sv->conf->unnamed_submission_round();
        if ($main_sr->inferred_register || $main_sr->register === $main_sr->submit) {
            $sv->set_oldv("submission_registration", null);
        }
        if ($sv->conf->site_lock("paper:start") > 0) {
            echo '<div class="f-i"><label for="submission_registration">Registration deadline</label>',
                '<div id="submission_registration" class="mb-1">N/A</div>';
            $sv->msg_at("submission_registration", "<0>The site is locked for new submissions.", MessageSet::URGENT_NOTE);
            $sv->print_feedback_at("submission_registration");
            echo '</div>';
        } else {
            $sv->print_entry_group("submission_registration", "Registration deadline", [
                "hint" => "New submissions can be started until this deadline."
            ]);
        }
        $sv->print_entry_group("submission_done", "Submission deadline", [
            "hint" => "Submissions must be complete by this deadline."
        ]);
        $sv->print_entry_group("submission_grace", "Grace period");
    }
    static function print_updates(SettingValues $sv) {
        $sv->print_radio_table("submission_freeze", [0 => "Allow updates until the submission deadline (usually the best choice)", 1 => "Authors must freeze the final version of each submission"]);
    }
    static function print_blind(SettingValues $sv) {
        $sv->print_radio_table("author_visibility", [
                Conf::BLIND_ALWAYS => "Yes, submissions are anonymous",
                Conf::BLIND_NEVER => "No, author names are visible to reviewers",
                Conf::BLIND_UNTILREVIEW => "Anonymous until review: reviewers can see author names after submitting a review",
                Conf::BLIND_OPTIONAL => "Depends: authors decide whether to expose their names"
            ],
            '<strong>Submission anonymity:</strong> Are author names hidden from reviewers?');
    }
    static function print_pcseeall(SettingValues $sv) {
        $sv->print_checkbox("draft_submission_early_visibility", "PC can view incomplete submissions before submission deadline",[
            "hint" => "Check this box to collect review preferences before the submission deadline. After the submission deadline, PC members can only see completed submissions."
        ]);
    }
    static function print_pcseeallpdf(SettingValues $sv) {
        $sv->print_checkbox("submitted_document_early_visibility", "PC can view submitted PDFs before submission deadline");
    }

    function apply_req(Si $si, SettingValues $sv) {
        $v = $sv->base_parse_req($si);
        if ($v !== null) {
            $sv->save("submission_done", $v);
            $sv->save("submission_update", $v);
            $sv->check_date_before("submission_registration", "submission_done", true);
        }
        return true;
    }

    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("submission_open")
            && $sv->oldv("submission_freeze") == 0
            && $sv->oldv("submission_open") > 0
            && $sv->oldv("submission_done") <= 0) {
            $sv->warning_at(null, "<5>Authors can update their submissions until the deadline, but there is no deadline. This is sometimes unintentional. You may want to either (1) specify a " . $sv->setting_link("submission deadline", "submission_done") . ", (2) select “" . $sv->setting_link("Authors must freeze the final version of each submission", "submission_freeze") . "”, or (3) manually turn off “" . $sv->setting_link("Open site for submissions", "submission_open") . "” at the proper time.");
        }
    }
}
