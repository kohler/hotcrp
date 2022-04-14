<?php
// settings/s_subform.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class SubForm_SettingRenderer {
    static function print_abstract(SettingValues $sv) {
        echo '<div id="foldpdfupload" class="fold2o fold3o">';
        echo '<div class="f-i">',
            $sv->label("sub_noabstract", "Abstract requirement", ["class" => "n"]),
            $sv->select("sub_noabstract", [0 => "Abstract required to register submission", 2 => "Abstract optional", 1 => "No abstract"]),
            '</div>';

        echo '<div class="f-i">',
            $sv->label("sub_nopapers", "PDF requirement", ["class" => "n"]),
            $sv->select("sub_nopapers", [0 => "PDF required to complete submission", 2 => "PDF optional", 1 => "No PDF allowed"], ["class" => "uich js-settings-sub-nopapers"]),
            '<div class="f-h fx3">Registering a submission never requires a PDF.</div></div>';

        if (is_executable("src/banal")) {
            echo '<div class="g fx2">';
            Banal_SettingParser::print("0", $sv);
            echo '</div>';
        }
        echo '</div>';
    }

    static function print_conflicts(SettingValues $sv) {
        echo '<div id="foldpcconf" class="fold', $sv->vstr("sub_pcconf") ? "o" : "c", "\">\n";
        $sv->print_checkbox("sub_pcconf", "Collect authors’ PC conflicts", ["class" => "uich js-foldup"]);
        $cflt = [];
        $confset = $sv->conf->conflict_types();
        foreach ($confset->basic_conflict_types() as $ct) {
            $cflt[] = "“" . $confset->unparse_html_description($ct) . "”";
        }
        $sv->print_checkbox("sub_pcconfsel", "Collect PC conflict descriptions (" . commajoin($cflt, "or") . ")", ["group_class" => "fx"]);
        $sv->print_checkbox("sub_collab", "Collect authors’ other conflicts and collaborators as text");
        echo "</div>\n";

        echo '<hr class="form-sep">';
        $sv->print_message_minor("conflict_description", "Definition of conflict of interest");

        echo '<hr class="form-sep">',
            $sv->label("sub_pcconfvis", "When can reviewers see conflict information?"),
            '&nbsp; ',
            $sv->select("sub_pcconfvis", [1 => "Never", 0 => "When authors or tracker are visible", 2 => "Always"]);
    }
}
