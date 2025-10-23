<?php
// settings/s_reviewvisibility.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

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
     * @param string $name */
    static function validate_condition($sv, $name) {
        $q = $sv->validating() ? $sv->newv($name) : $sv->oldv($name);
        if (($q ?? "") === "") {
            return;
        }
        $status = $sv->validating() ? 2 : 1;
        $parent_setting = str_ends_with($name, "_condition") ? substr($name, 0, -10) : false;
        $srch = new PaperSearch($sv->conf->root_user(), $q);
        foreach ($srch->message_list() as $mi) {
            $sv->append_item_at($name, $mi);
            if ($parent_setting) {
                $sv->append_item_at($parent_setting, new MessageItem($mi->status));
            }
        }
        foreach ($srch->main_term()->preorder() as $qe) {
            if ($qe instanceof Tag_SearchTerm) {
                foreach ($qe->tsm->tag_patterns() as $tag) {
                    if (strpos($tag, "*") === false
                        && !$sv->conf->tags()->is_readonly($tag)) {
                        $sv->warning_at($name, "<5>PC members can change the tag ‘" . htmlspecialchars($tag) . "’. Tags referenced in visibility conditions should usually be " . $sv->setting_link("read-only", "tag_readonly") . ".");
                        $sv->warning_at("tag_readonly");
                        if ($parent_setting) {
                            $sv->append_item_at($parent_setting, new MessageItem(1));
                        }
                    }
                }
            }
        }
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "review_visibility_author_condition"
            && $sv->has_req($si->name)) {
            $sv->save($si, $sv->reqstr($si->name));
            $sv->save("review_visibility_author_tags", "");
            $sv->request_validate($si);
            return true;
        }
        if ($si->name === "review_visibility_author_tags"
            && $sv->has_req($si->name)
            && !$sv->has_req("review_visibility_author_condition")) {
            $sv->save("review_visibility_author_condition", "");
        }
        return false;
    }

    function validate(Si $si, SettingValues $sv) {
        self::validate_condition($sv, $si->name);
    }

    static function print_review_author_visibility(SettingValues $sv) {
        $opts = [Conf::AUSEEREV_NO => "No, unless authors can edit responses",
                 Conf::AUSEEREV_YES => "Yes"];
        $opts[Conf::AUSEEREV_SEARCH] = '<div class="d-inline-flex flex-wrap">'
            . '<label for="review_visibility_author_condition" class="mr-2 uic js-settings-radioitem-click">Yes, for submissions matching this search:</label>'
            . '<div>' . $sv->feedback_at("review_visibility_author_condition")
            . $sv->feedback_at("review_visibility_author_tags")
            . $sv->entry("review_visibility_author_condition", [
                "class" => "uii js-settings-radioitem-click papersearch need-suggest",
                "spellcheck" => false, "autocomplete" => "off"
            ])
            . "</div></div>";

        $hint = '<p class="f-d mt-0 if-response-active';
        if (!$sv->conf->setting("resp_active")) {
            $hint .= ' hidden';
        }
        $hint .= '">';
        if ($sv->conf->any_response_open) {
            $hint .= 'Currently, <strong>some authors can edit responses and therefore see reviews</strong> independent of this setting.';
        } else {
            $hint .= 'Authors who can edit responses can see reviews independent of this setting.';
        }
        $hint .= '</p>';

        $sv->print_radio_table("review_visibility_author", $opts,
            'Can <strong>authors see reviews</strong> for their submissions?' . $hint);
        echo Ht::hidden("has_review_visibility_author_condition", 1);
    }

    static function crosscheck(SettingValues $sv) {
        if (($sv->has_interest("review_visibility_author") || $sv->has_interest("tag_readonly"))
            && $sv->oldv("review_visibility_author") == Conf::AUSEEREV_SEARCH
            && $sv->oldv("review_visibility_author_condition")
            && !$sv->has_error_at("review_visibility_author_condition")) {
            self::validate_condition($sv, "review_visibility_author_condition");
        }
    }
}
