<?php
// settings/s_json.php -- HotCRP JSON settings
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class JSON_SettingParser extends SettingParser {
    static function print(SettingValues $sv) {
        $wantjreq = $sv->use_req() && $sv->has_req("json_settings");
        $defj = json_encode_browser($sv->all_jsonv(), JSON_PRETTY_PRINT);
        $mainj = $wantjreq ? $sv->reqstr("json_settings") : $defj;
        $mainh = htmlspecialchars($mainj);
        echo '<div class="settings-json-panels">',
            '<div class="settings-json-panel-edit textarea">',
            '<div class="pw need-settings-json uii ui-beforeinput" contenteditable spellcheck="false" autocapitalization="none" data-reflect-text="json_settings"';
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
            '</div><div class="settings-json-panel-info"><div class="settings-json-info">',
            '<h3 class="form-h">Selected settings</h3>',
            '<ul class="x">',
            '<li><a href="#path=sf"><code class="settings-jpath">sf</code></a>: Submission form</li>',
            '<li><a href="#path=rf"><code class="settings-jpath">rf</code></a>: Review form</li>',
            '<li><a href="#path=review"><code class="settings-jpath">review</code></a>: Review rounds and deadlines</li>',
            '<li><a href="#path=track"><code class="settings-jpath">track</code></a>: Submission tracks</li>',
            '<li><a href="#path=tag_style"><code class="settings-jpath">tag_style</code></a>: Tag colors and styles</li>',
            '</ul>',
            '</div></div></div>';
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
            $v = cleannl($v);
            $sv->set_req($si->name, $v);
            if (($v2 = $sv->reqstr("json_settings:copy")) !== null
                && $v !== cleannl($v2)) {
                $sv->error_at($si, "<0>Internal error: Inconsistent JSON submitted by browser");
                $sv->inform_at($si, "<0>Please report this problem to the HotCRP.com maintainer.");
                $sv->inform_at($si, "<0>Lengths: " . json_encode([strlen($v), strlen($v2)]));
            } else {
                $sv->set_link_json(true);
                $sv->add_json_string($v);
            }
        }
        return true;
    }
}
