<?php
// src/settings/s_sub.php -- HotCRP settings > submissions page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SettingRenderer_Submissions extends SettingRenderer {
function render(SettingValues $sv) {
    $sv->echo_checkbox('sub_open', '<b>Open site for submissions</b>');

    echo "<div class='g'></div>\n";
    echo "<strong>Blind submission:</strong> Are author names hidden from reviewers?<br />\n";
    $sv->echo_radio_table("sub_blind", array(Conf::BLIND_ALWAYS => "Yes—submissions are anonymous",
                               Conf::BLIND_NEVER => "No—author names are visible to reviewers",
                               Conf::BLIND_UNTILREVIEW => "Blind until review—reviewers can see author names after submitting a review",
                               Conf::BLIND_OPTIONAL => "Depends—authors decide whether to expose their names"));

    echo "<div class='g'></div>\n<table>\n";
    // maybe sub_reg was overridden
    if (($sub_reg = $sv->conf->setting("__sub_reg", false)) !== false)
        $sv->set_oldv("sub_reg", $sub_reg);
    $sv->echo_entry_row("sub_reg", "Registration deadline");
    $sv->echo_entry_row("sub_sub", "Submission deadline");
    $sv->echo_entry_row("sub_grace", "Grace period");
    echo "</table>\n";

    $sv->echo_radio_table("sub_freeze", array(0 => "Allow updates until the submission deadline (usually the best choice)", 1 => "Authors must freeze the final version of each submission"));


    echo "<div class=\"g\"></div><table id=\"foldpc_seeall\" class=\"foldo\"><tbody>\n";
    $sv->echo_checkbox_row("pc_seeall", "PC can see <i>all registered papers</i> until submission deadline<br /><small>Check this box if you want to collect review preferences before most papers are submitted. After the submission deadline, PC members can only see submitted papers.</small>", "fold('pc_seeall',!this.checked)");
    echo "</tbody><tbody class=\"fx\">\n";
    $sv->echo_checkbox_row("pc_seeallpdf", "PC can see submitted PDFs before submission deadline");
    echo "</tbody></table>\n";
    Ht::stash_script("fold('pc_seeall',!$('#cbpc_seeall').is(':checked'))");
}
    function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("sub_open")
            && $sv->newv("sub_freeze", -1) == 0
            && $sv->newv("sub_open") > 0
            && $sv->newv("sub_sub") <= 0)
            $sv->warning_at(null, "Authors can update their submissions until the deadline, but there is no deadline. This is sometimes unintentional. You probably should (1) specify a paper submission deadline; (2) select “Authors must freeze the final version of each submission”; or (3) manually turn off “Open site for submissions” when submissions complete.");
    }
}

SettingGroup::register("sub", "Submissions", 300, new SettingRenderer_Submissions);
