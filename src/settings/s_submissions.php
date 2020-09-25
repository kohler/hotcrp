<?php
// src/settings/s_submissions.php -- HotCRP settings > submissions page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Submissions_SettingRenderer {
    static function render_open(SettingValues $sv) {
        echo '<div class="form-g">';
        $sv->echo_checkbox('sub_open', '<b>Open site for submissions</b>');
        echo "</div>\n";
    }
    static function render_deadlines(SettingValues $sv) {
        echo '<div class="form-g">';
        // maybe sub_reg was overridden
        if (($sub_reg = $sv->conf->setting("__sub_reg", false)) !== false) {
            $sv->set_oldv("sub_reg", $sub_reg);
        }
        $sv->echo_entry_group("sub_reg", "Registration deadline", null, "New submissions can be started until this deadline.");
        $sv->echo_entry_group("sub_sub", "Submission deadline", null, "Submissions must be complete by this deadline.");
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
        echo '<div class="form-g foldo" id="foldpc_seeall">';
        $sv->echo_checkbox("pc_seeall", "PC can view incomplete submissions before submission deadline", ["class" => "uich js-foldup"], "Check this box to collect review preferences before the submission deadline. After the submission deadline, PC members can only see completed submissions.");
        echo '<div class="fx">';
        $sv->echo_checkbox("pc_seeallpdf", "PC can view submitted PDFs before submission deadline");
        echo "</div></div>\n";
    }
    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("sub_open")
            && $sv->newv("sub_freeze") == 0
            && $sv->newv("sub_open") > 0
            && $sv->newv("sub_sub") <= 0)
            $sv->warning_at(null, "Authors can update their submissions until the deadline, but there is no deadline. This is sometimes unintentional. You may want to either (1) specify a submission deadline, (2) select “Authors must freeze the final version of each submission”, or (3) manually turn off “Open site for submissions” at the proper time.");
    }
}

class Submissions_SettingParser extends SettingParser {
    function validate(SettingValues $sv, Si $si) {
        $d1 = $sv->newv($si->name);
        if ($si->name === "sub_open") {
            if ($d1 <= 0
                && $sv->oldv("sub_open") > 0
                && $sv->newv("sub_sub") <= 0) {
                $sv->save("sub_close", Conf::$now);
            }
        } else if ($si->name === "sub_sub") {
            $sv->check_date_before("sub_reg", "sub_sub", true);
            $sv->save("sub_update", $d1);
        } else if ($si->name === "final_done") {
            $sv->check_date_before("final_soft", "final_done", true);
        }
        return false;
    }
}
