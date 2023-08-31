<?php
// settings/s_subform.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class SubForm_SettingRenderer {
    static function print_abstract(SettingValues $sv) {
        $sel = [
            0 => "Abstract required to register submission",
            2 => "Abstract optional",
            1 => "No abstract"
        ];
        if ($sv->oldv("sf_abstract") == -1 || $sv->newv("sf_abstract") == -1) {
            $sel[-1] = "Other";
        }
        echo '<div class="f-i">',
            $sv->label("sf_abstract", "Abstract requirement", ["class" => "n"]),
            $sv->select("sf_abstract", $sel, ["class" => "uich js-settings-sf-wizard"]),
            '</div>';

        // see also settings.js
        $newv = $sv->newv("sf_pdf_submission");
        $fold2 = $newv == 1 ? "c" : "o";
        $fold3 = $newv != 0 ? "c" : "o";
        echo "<div id=\"foldpdfupload\" class=\"fold2{$fold2} fold3{$fold3}\">";
        $sel = [
            0 => "PDF required to complete submission",
            2 => "PDF optional",
            1 => "No PDF allowed"
        ];
        if ($sv->oldv("sf_pdf_submission") == -1 || $newv == -1) {
            $sel[-1] = "Other";
        }
        echo '<div class="f-i">',
            $sv->label("sf_pdf_submission", "PDF requirement", ["class" => "n"]),
            $sv->select("sf_pdf_submission", $sel, ["class" => "uich js-settings-sf-wizard"]),
            '<div class="f-h fx3">Registering a submission does not require a PDF upload.</div></div>';

        if (is_executable("src/banal")) {
            echo '<div class="g fx2">';
            Banal_SettingParser::print("submission", $sv);
            echo '</div>';
        }
        echo '</div>';
    }

    static function print_conflicts(SettingValues $sv) {
        echo '<div id="foldpcconf" class="fold', $sv->vstr("sf_pc_conflicts") ? "o" : "c", "\">\n";
        $sv->print_checkbox("sf_pc_conflicts", "Collect authors’ PC conflicts", ["class" => "uich js-foldup"]);
        $cflt = [];
        $confset = $sv->conf->conflict_set();
        foreach ($confset->basic_conflict_types() as $ct) {
            $cflt[] = "“" . $confset->unparse_html_description($ct) . "”";
        }
        $sv->print_checkbox("sf_pc_conflict_types", "Collect PC conflict descriptions (" . commajoin($cflt, "or") . ")", ["group_class" => "fx"]);
        $sv->print_checkbox("sf_collaborators", "Collect authors’ other conflicts and collaborators as text");
        echo "</div>\n";

        echo '<hr class="form-sep">';
        $sv->print_message_minor("conflict_description", "Definition of conflict of interest");

        echo '<hr class="form-sep">',
            $sv->label("conflict_visibility", "When can reviewers see PC conflict information?"),
            '&nbsp; ',
            $sv->select("conflict_visibility", [1 => "Never", 0 => "When authors or tracker are visible", 2 => "Always"]);
    }
}
