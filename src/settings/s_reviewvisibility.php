<?php
// settings/s_reviewvisibility.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class ReviewVisibility_SettingParser extends SettingParser {
    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name === "review_visibility_author_condition") {
            if (($q = $sv->conf->setting_data("au_seerev")) !== null) {
                $sv->set_oldv($si->name, $q);
            } else if (($tagstr = $sv->conf->setting_data("tag_au_seerev")) !== null) {
                $tags = [];
                foreach (explode(" ", $tagstr) as $tag) {
                    if ($tag !== "")
                        $tags[] = "#{$tag}";
                }
                if (empty($tags)) {
                    $sv->set_oldv($si->name, "NONE");
                } else {
                    $sv->set_oldv($si->name, join(" OR ", $tags));
                }
            }
        }
    }

    /** @param SettingValues $sv
     * @param string $name
     * @param string $q
     * @param 1|2 $status */
    static function validate_condition($sv, $name, $q, $status) {
        if (($q ?? "") === "") {
            return;
        }
        $parent_setting = str_ends_with($name, "_condition") ? substr($name, 0, -10) : false;
        $srch = new PaperSearch($sv->conf->root_user(), $q);
        foreach ($srch->message_list() as $mi) {
            $sv->append_item_at($name, $mi);
            $parent_setting && $sv->msg_at($parent_setting, "", $mi->status);
        }
        foreach ($srch->main_term()->preorder() as $qe) {
            if ($qe instanceof Tag_SearchTerm) {
                foreach ($qe->tsm->tag_patterns() as $tag) {
                    if (strpos($tag, "*") === false
                        && !$sv->conf->tags()->is_readonly($tag)) {
                        $sv->warning_at($name, "<5>PC members can change the tag ‘" . htmlspecialchars($tag) . "’. Tags referenced in visibility conditions should usually be " . $sv->setting_link("read-only", "tag_readonly") . ".");
                        $sv->warning_at("tag_readonly");
                        $parent_setting && $sv->msg_at($parent_setting, "", 1);
                    }
                }
            }
        }
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "review_visibility_author_condition"
            && $sv->has_req($si->name)) {
            $q = $sv->reqstr($si->name);
            self::validate_condition($sv, $si->name, $q, 2);
            $sv->save($si, $q);
            $sv->save("review_visibility_author_tags", "");
            return true;
        } else if ($si->name === "review_visibility_author_tags"
                   && $sv->has_req($si->name)
                   && !$sv->has_req("review_visibility_author_condition")) {
            $sv->save("review_visibility_author_condition", "");
        }
        return false;
    }

    static function print_review_author_visibility(SettingValues $sv) {
        $opts = [Conf::AUSEEREV_NO => "No, unless authors can edit responses",
                 Conf::AUSEEREV_YES => "Yes"];
        $opts[Conf::AUSEEREV_SEARCH] = '<div class="d-inline-flex flex-wrap">'
            . '<label for="review_visibility_author_condition" class="mr-2 uic js-settings-radioitem-click">Yes, for submissions matching this search:</label>'
            . '<div>' . $sv->feedback_at("review_visibility_author_condition")
            . $sv->feedback_at("review_visibility_author_tags")
            . $sv->entry("review_visibility_author_condition", ["class" => "uii js-settings-radioitem-click papersearch need-suggest"])
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
        echo Ht::hidden("has_review_visibility_author_condition", 1);
    }

    static function print_author_exchange_comments(SettingValues $sv) {
        echo '<div class="has-fold fold', $sv->vstr("comment_allow_author") ? "o" : "c", '">';
        if ((int) $sv->vstr("review_blind") === Conf::BLIND_NEVER) {
            $hint = "";
        } else {
            $hint = "Visible reviewer comments will be identified by “Reviewer A”, “Reviewer B”, etc.";
        }
        $sv->print_checkbox("comment_allow_author", "Authors can <strong>exchange comments</strong> with reviewers", [
            "class" => "uich js-foldup",
            "hint_class" => "fx",
            "hint" => $hint
        ]);
        echo "</div>\n";
    }

    static function crosscheck(SettingValues $sv) {
        if (($sv->has_interest("review_visibility_author") || $sv->has_interest("tag_readonly"))
            && $sv->oldv("review_visibility_author") == Conf::AUSEEREV_SEARCH
            && $sv->oldv("review_visibility_author_condition")
            && !$sv->has_error_at("review_visibility_author_condition")) {
            self::validate_condition($sv, "review_visibility_author_condition", $sv->oldv("review_visibility_author_condition"), 1);
        }
    }
}
