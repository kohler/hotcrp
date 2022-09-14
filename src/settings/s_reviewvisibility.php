<?php
// settings/s_reviewvisibility.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ReviewVisibility_SettingParser extends SettingParser {
    static function print_review_author_visibility(SettingValues $sv) {
        $opts = [Conf::AUSEEREV_NO => "No, unless authors can edit responses",
                 Conf::AUSEEREV_YES => "Yes"];
        $opts[Conf::AUSEEREV_TAGS] = '<div class="d-inline-flex flex-wrap">'
            . "<label for=\"review_visibility_author_" . Conf::AUSEEREV_TAGS . "\" class=\"mr-2\">Yes, for submissions with any of these tags:</label>"
            . "<div>" . $sv->feedback_at("review_visibility_author_tags")
            . $sv->entry("review_visibility_author_tags", ["class" => "uii js-settings-au-seerev-tag"])
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

        $sv->print_radio_table("review_visibility_author", $opts,
            'Can <strong>authors see reviews</strong> for their submissions?' . $hint);
        echo Ht::hidden("has_review_visibility_author_tags", 1);
    }

    static function print_author_exchange_comments(SettingValues $sv) {
        echo '<div class="has-fold fold', $sv->vstr("comment_allow_author") ? "o" : "c", '">';
        if ((int) $sv->vstr("review_blind") === Conf::BLIND_NEVER) {
            $hint = "";
        } else {
            $hint = "Visible reviewer comments will be identified by “Reviewer A”, “Reviewer B”, etc.";
        }
        $sv->print_checkbox("comment_allow_author", "Authors can <strong>exchange comments</strong> with reviewers", ["class" => "uich js-foldup", "hint_class" => "fx"], $hint);
        echo "</div>\n";
    }

    static function crosscheck(SettingValues $sv) {
        $conf = $sv->conf;
        if ($sv->has_interest("review_visibility_author")
            && $sv->oldv("review_visibility_author") == Conf::AUSEEREV_TAGS
            && !$sv->oldv("review_visibility_author_tags")
            && !$sv->has_error_at("review_visibility_author_tags")) {
            $sv->warning_at("review_visibility_author_tags", "<0>You haven’t set any review visibility tags.");
        }
        if (($sv->has_interest("review_visibility_author") || $sv->has_interest("tag_readonly"))
            && $sv->oldv("review_visibility_author") == Conf::AUSEEREV_TAGS
            && $sv->oldv("review_visibility_author_tags")
            && !$sv->has_error_at("review_visibility_author_tags")) {
            foreach (explode(" ", $sv->oldv("review_visibility_author_tags")) as $t) {
                if ($t !== "" && !$conf->tags()->is_chair($t)) {
                    $sv->warning_at("review_visibility_author_tags", "<5>PC members can change the tag ‘" . htmlspecialchars($t) . "’, which affects whether authors can see reviews. Such tags should usually be " . $sv->setting_link("read-only", "tag_readonly") . ".");
                    $sv->warning_at("tag_readonly");
                }
            }
        }
    }
}
