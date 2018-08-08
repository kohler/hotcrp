<?php
// src/settings/s_submissions.php -- HotCRP settings > submissions page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Submissions_SettingRenderer {
    static function render_open(SettingValues $sv) {
        echo '<div class="settings-g">';
        $sv->echo_checkbox('sub_open', '<b>Open site for submissions</b>');
        echo "</div>\n";
    }
    static function render_deadlines(SettingValues $sv) {
        echo '<div class="settings-g">';
        // maybe sub_reg was overridden
        if (($sub_reg = $sv->conf->setting("__sub_reg", false)) !== false)
            $sv->set_oldv("sub_reg", $sub_reg);
        $sv->echo_entry_group("sub_reg", "Registration deadline");
        $sv->echo_entry_group("sub_sub", "Submission deadline");
        $sv->echo_entry_group("sub_grace", "Grace period");
        echo "</div>\n";
    }
    static function render_updates(SettingValues $sv) {
        $sv->echo_radio_table("sub_freeze", [0 => "Allow updates until the submission deadline (usually the best choice)", 1 => "Authors must freeze the final version of each submission"]);
    }
    static function render_blind(SettingValues $sv) {
        $sv->echo_radio_table("sub_blind", [Conf::BLIND_ALWAYS => "Yes—submissions are anonymous",
                                   Conf::BLIND_NEVER => "No—author names are visible to reviewers",
                                   Conf::BLIND_UNTILREVIEW => "Blind until review—reviewers can see author names after submitting a review",
                                   Conf::BLIND_OPTIONAL => "Depends—authors decide whether to expose their names"],
            '<strong>Blind submission:</strong> Are author names hidden from reviewers?');
    }
    static function render_pcseeall(SettingValues $sv) {
        echo '<div class="settings-g foldo" id="foldpc_seeall">';
        $sv->echo_checkbox("pc_seeall", "PC can see <i>all registered papers</i> until submission deadline", ["class" => "uich js-foldup"], "Check this box to collect review preferences before most papers are submitted. After the submission deadline, PC members can only see submitted papers.");
        echo '<div class="fx">';
        $sv->echo_checkbox("pc_seeallpdf", "PC can see submitted PDFs before submission deadline");
        echo "</div></div>\n";
    }
    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("sub_open")
            && $sv->newv("sub_freeze", -1) == 0
            && $sv->newv("sub_open") > 0
            && $sv->newv("sub_sub") <= 0)
            $sv->warning_at(null, "Authors can update their submissions until the deadline, but there is no deadline. This is sometimes unintentional. You probably should (1) specify a paper submission deadline; (2) select “Authors must freeze the final version of each submission”; or (3) manually turn off “Open site for submissions” when submissions complete.");
    }
}
