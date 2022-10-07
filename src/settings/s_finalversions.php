<?php
// settings/s_finalversions.php -- HotCRP settings > final versions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class FinalVersions_SettingParser extends SettingParser {
    static function print(SettingValues $sv) {
        if ($sv->oldv("final_soft") === $sv->oldv("final_done")) {
            $sv->set_oldv("final_soft", null);
        }
        echo '<div class="has-fold fold2o">';
        $sv->print_checkbox('final_open', '<strong>Collect final versions of accepted submissions</strong>', ["class" => "uich js-foldup", "group_class" => "form-g", "group_open" => true]);
        echo '<div class="fx2 mt-3">';
        $sv->print_entry_group("final_soft", "Deadline", ["horizontal" => true]);
        $sv->print_entry_group("final_done", "Hard deadline", ["horizontal" => true]);
        $sv->print_entry_group("final_grace", "Grace period", ["horizontal" => true]);
        echo '<hr class="form-sep">';
        $sv->print_message_minor("final_edit_message", "Instructions");
        Banal_SettingParser::print("final", $sv);
        echo "</div></div></div>\n\n";
    }

    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("final_open")
            && $sv->oldv("final_open")
            && ($sv->oldv("final_soft") || $sv->oldv("final_done"))
            && (!$sv->oldv("final_done") || $sv->oldv("final_done") > Conf::$now)
            && !$sv->conf->time_all_author_view_decision()) {
            $sv->warning_at(null, "<5>The system is set to collect final versions, but authors cannot submit final versions until they can see decisions. You may want to update the " . $sv->setting_link("“Can authors see decisions” setting", "decision_visibility_author") . ".");
        }
    }
}
