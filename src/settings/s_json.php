<?php
// settings/s_json.php -- HotCRP JSON settings
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class JSON_SettingParser extends SettingParser {
    static function print(SettingValues $sv) {
        if ($sv->use_req() && $sv->has_req("json_settings")) {
            $j = $sv->reqstr("json_settings");
        } else {
            $j = json_encode_browser($sv->json_allv(), JSON_PRETTY_PRINT);
        }
        $j = htmlspecialchars($j);
        //echo '<div class="pw js-json-validate uii ui-beforeinput" contenteditable spellcheck="false" autocapitalization="none" style="min-height:10ex"><div>{"aa":"nn"</div><div>}</div></div>';
        echo '<div class="settings-json-panels">',
            '<div class="settings-json-panel-edit">',
            '<div class="textarea pw js-settings-json uii ui-beforeinput" contenteditable spellcheck="false" autocapitalization="none" data-reflect-text="json_settings">', $j, "\n</div>",
            '</div><div class="settings-json-panel-info"></div></div>',
            '<textarea name="json_settings" id="json_settings" class="hidden" readonly>', $j, "\n</textarea>";
    }
    static function crosscheck(SettingValues $sv) {
        if ($sv->canonical_page === "json") {
            $sv->set_all_interest(true)->set_link_json(true);
        }
    }
    function apply_req(Si $si, SettingValues $sv) {
        if (($v = $sv->reqstr($si->name)) !== null) {
            $sv->set_link_json(true);
            $sv->add_json_string($v);
        }
        return true;
    }
}
