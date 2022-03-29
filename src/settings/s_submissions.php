<?php
// settings/s_submissions.php -- HotCRP settings > submissions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Submissions_SettingRenderer {
    static function print_open(SettingValues $sv) {
        $sv->print_checkbox('sub_open', '<b>Open site for submissions</b>');
    }
    static function print_deadlines(SettingValues $sv) {
        // maybe sub_reg was overridden
        if (($sub_reg = $sv->conf->setting("__sub_reg")) !== null) {
            $sv->set_oldv("sub_reg", $sub_reg);
        } else if ($sv->oldv("sub_reg") === $sv->oldv("sub_sub")) {
            $sv->set_oldv("sub_reg", null);
        }
        $sv->print_entry_group("sub_reg", "Registration deadline", null, "New submissions can be started until this deadline.");
        $sv->print_entry_group("sub_sub", "Submission deadline", null, "Submissions must be complete by this deadline.");
        $sv->print_entry_group("sub_grace", "Grace period");
    }
    static function print_updates(SettingValues $sv) {
        $sv->print_radio_table("sub_freeze", [0 => "Allow updates until the submission deadline (usually the best choice)", 1 => "Authors must freeze the final version of each submission"]);
    }
    static function print_blind(SettingValues $sv) {
        $sv->print_radio_table("sub_blind", [Conf::BLIND_ALWAYS => "Yes—submissions are anonymous",
                                   Conf::BLIND_NEVER => "No—author names are visible to reviewers",
                                   Conf::BLIND_UNTILREVIEW => "Blind until review—reviewers can see author names after submitting a review",
                                   Conf::BLIND_OPTIONAL => "Depends—authors decide whether to expose their names"],
            '<strong>Blind submission:</strong> Are author names hidden from reviewers?');
    }
    static function print_pcseeall(SettingValues $sv) {
        $sv->print_checkbox("pc_seeall", "PC can view incomplete submissions before submission deadline", null, "Check this box to collect review preferences before the submission deadline. After the submission deadline, PC members can only see completed submissions.");
    }
    static function print_pcseeallpdf(SettingValues $sv) {
        $sv->print_checkbox("pc_seeallpdf", "PC can view submitted PDFs before submission deadline");
    }
    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("sub_open")
            && $sv->conf->setting("sub_freeze") == 0
            && $sv->conf->setting("sub_open") > 0
            && $sv->conf->setting("sub_sub") <= 0)
            $sv->warning_at(null, "<5>Authors can update their submissions until the deadline, but there is no deadline. This is sometimes unintentional. You may want to either (1) specify a " . $sv->setting_link("submission deadline", "sub_sub") . ", (2) select “" . $sv->setting_link("Authors must freeze the final version of each submission", "sub_freeze") . "”, or (3) manually turn off “" . $sv->setting_link("Open site for submissions", "sub_open") . "” at the proper time.");
    }
}

class Submissions_SettingParser extends SettingParser {
    function apply_req(SettingValues $sv, Si $si) {
        $v = $sv->base_parse_req($si);
        if ($v !== null) {
            $sv->save("sub_sub", $v);
            $sv->save("sub_update", $v);
            $sv->check_date_before("sub_reg", "sub_sub", true);
        }
        return true;
    }
}
