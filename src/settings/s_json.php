<?php
// settings/s_json.php -- HotCRP JSON settings
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class JSON_SettingParser extends SettingParser {
    static function print(SettingValues $sv) {
        $wantjreq = $sv->use_req() && $sv->has_req("json_settings");
        $defj = json_encode_browser($sv->json_allv(), JSON_PRETTY_PRINT);
        $mainj = $wantjreq ? cleannl($sv->reqstr("json_settings")) : $defj;
        $mainh = htmlspecialchars($mainj);
        echo '<div class="settings-json-panels">',
            '<div class="settings-json-panel-edit textarea">',
            '<div class="pw js-settings-json uii ui-beforeinput" contenteditable spellcheck="false" autocapitalization="none" data-reflect-text="json_settings"';
        $hl = $tips = [];
        foreach ($sv->message_list() as $mi) {
            if ($mi->pos1 !== null
                && $mi->context === null
                && $mi->status >= 1) {
                $hl[] = "{$mi->pos1}-{$mi->pos2}:" . ($mi->status > 1 ? 2 : 1);
                if ($mi->message) {
                    $tips[] = $mi;
                }
            }
        }
        if (!empty($hl)) {
            echo ' data-highlight-ranges="', join(" ", $hl), '"';
        }
        if (!empty($tips)) {
            echo ' data-highlight-tips="', htmlspecialchars(json_encode_browser($tips)), '"';
        }
        if (!empty($hl) || !empty($tips)) {
            echo ' data-highlight-utf8-pos';
        }
        echo ' data-reflect-highlight-api="=api/settings?dryrun=1 settings">',
            $mainh, "\n</div>",
            '</div><div class="settings-json-panel-info"><div class="settings-json-info"></div></div></div>';
        // NB On Safari, HTMLTextAreaElement.setRangeText only works on displayed elements.
        echo '<textarea name="json_settings" id="json_settings" class="position-absolute invisible"';
        if ($mainj !== $defj) {
            echo ' data-default-value="', htmlspecialchars($defj), '"';
        }
        echo '>', $mainh, "\n</textarea>";
    }
    static function crosscheck(SettingValues $sv) {
        if ($sv->canonical_page === "json") {
            $sv->set_all_interest(true)->set_link_json(true);
        }
    }
    function apply_req(Si $si, SettingValues $sv) {
        if (($v = $sv->reqstr($si->name)) !== null) {
            $sv->set_link_json(true);
            $sv->add_json_string(cleannl($v));
        }
        return true;
    }
}
