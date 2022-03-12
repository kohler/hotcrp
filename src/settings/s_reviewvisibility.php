<?php
// settings/s_reviewvisibility.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ReviewVisibility_SettingParser extends SettingParser {
    static function print_review_author_visibility(SettingValues $sv) {
        $opts = [Conf::AUSEEREV_NO => "No, unless authors can edit responses",
                 Conf::AUSEEREV_YES => "Yes"];
        $opts[Conf::AUSEEREV_TAGS] = '<div class="d-inline-flex flex-wrap">'
            . "<label for=\"au_seerev_" . Conf::AUSEEREV_TAGS . "\" class=\"mr-2\">Yes, for submissions with any of these tags:</label>"
            . "<div>" . $sv->feedback_at("tag_au_seerev")
            . $sv->entry("tag_au_seerev", ["class" => "uii js-settings-au-seerev-tag"])
            . "</div></div>";

        $hint = '<div class="f-hx if-response-active';
        if (!$sv->conf->setting("resp_active")) {
            $hint .= ' hidden';
        }
        $hint .= '">';
        if ($sv->conf->any_response_open) {
            $hint .= 'Currently, <strong>some authors can edit responses and therefore see reviews</strong> independent of this setting.';
        } else {
            $hint .= 'Authors who can edit responses can see reviews independent of this setting.';
        }
        $hint .= '</div>';

        $sv->print_radio_table("au_seerev", $opts,
            'Can <strong>authors see reviews</strong> for their submissions?' . $hint);
        echo Ht::hidden("has_tag_au_seerev", 1);
    }

    static function print_author_exchange_comments(SettingValues $sv) {
        echo '<div class="has-fold fold', $sv->vstr("cmt_author") ? "o" : "c", '">';
        if ((int) $sv->vstr("rev_blind") === Conf::BLIND_NEVER) {
            $hint = "";
        } else {
            $hint = "Visible reviewer comments will be identified by “Reviewer A”, “Reviewer B”, etc.";
        }
        $sv->print_checkbox("cmt_author", "Authors can <strong>exchange comments</strong> with reviewers", ["class" => "uich js-foldup", "hint_class" => "fx"], $hint);
        echo "</div>\n";
    }

    static function crosscheck(SettingValues $sv) {
        $conf = $sv->conf;
        if ($sv->has_interest("au_seerev")
            && $conf->setting("au_seerev") == Conf::AUSEEREV_TAGS
            && !$conf->setting("tag_au_seerev")
            && !$sv->has_error_at("tag_au_seerev")) {
            $sv->warning_at("tag_au_seerev", "<0>You haven’t set any review visibility tags.");
        }
        if (($sv->has_interest("au_seerev") || $sv->has_interest("tag_chair"))
            && $conf->setting("au_seerev") == Conf::AUSEEREV_TAGS
            && $conf->setting("tag_au_seerev")
            && !$sv->has_error_at("tag_au_seerev")) {
            foreach ($conf->tag_au_seerev as $t) {
                if (!$conf->tags()->is_chair($t)) {
                    $sv->warning_at("tag_au_seerev", "<5>PC members can change the tag ‘" . htmlspecialchars($t) . "’, which affects whether authors can see reviews. Such tags should usually be " . $sv->setting_link("read-only", "tag_chair") . ".");
                    $sv->warning_at("tag_chair");
                }
            }
        }
    }
}
