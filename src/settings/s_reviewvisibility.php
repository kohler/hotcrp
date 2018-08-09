<?php
// src/settings/s_reviewvisibility.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class ReviewVisibility_SettingParser extends SettingParser {
    static function render(SettingValues $sv) {
        $opts = array(Conf::AUSEEREV_NO => "No, unless authors can edit responses",
                      Conf::AUSEEREV_YES => "Yes");
        if ($sv->newv("au_seerev") == Conf::AUSEEREV_UNLESSINCOMPLETE
            && !$sv->conf->opt("allow_auseerev_unlessincomplete"))
            $sv->conf->save_setting("opt.allow_auseerev_unlessincomplete", 1);
        if ($sv->conf->opt("allow_auseerev_unlessincomplete"))
            $opts[Conf::AUSEEREV_UNLESSINCOMPLETE] = "Yes, after completing any assigned reviews for other papers";
        $opts[Conf::AUSEEREV_TAGS] = "Yes, for papers with any of these tags:&nbsp; " . $sv->render_entry("tag_au_seerev");

        $hint = '<p class="settingtext f-h if-response-active';
        if (!$sv->conf->setting("resp_active"))
            $hint .= ' hidden';
        $hint .= '">';
        if ($sv->conf->any_response_open)
            $hint .= 'Currently, <strong>some authors can edit responses and therefore see reviews</strong> independent of this setting.';
        else
            $hint .= 'Authors who can edit responses can see reviews independent of this setting.';
        $hint .= '</p>';

        $sv->echo_radio_table("au_seerev", $opts,
            'Can <strong>authors see reviews and author-visible comments</strong> for their papers?' . $hint);
        echo Ht::hidden("has_tag_au_seerev", 1);
        Ht::stash_script('$("#tag_au_seerev").on("input", function () { $("#au_seerev_' . Conf::AUSEEREV_TAGS . '").click(); })');

        echo '<div class="settings-g has-fold fold', $sv->newv("cmt_author") ? "o" : "c", '">';
        $sv->echo_checkbox("cmt_author", "Authors can <strong>exchange comments</strong> with reviewers when reviews are visible", ["class" => "uich js-foldup", "hint_class" => "fx"], "Reviewers’ comments will be identified by “Reviewer A”, “Reviewer B”, etc.");
        echo "</div>\n";
    }

    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("au_seerev")
            && $sv->newv("au_seerev") == Conf::AUSEEREV_TAGS
            && !$sv->newv("tag_au_seerev")
            && !$sv->has_error_at("tag_au_seerev"))
            $sv->warning_at("tag_au_seerev", "You haven’t set any review visibility tags.");

        if (($sv->has_interest("au_seerev") || $sv->has_interest("tag_chair"))
            && $sv->newv("au_seerev") == Conf::AUSEEREV_TAGS
            && $sv->newv("tag_au_seerev")
            && !$sv->has_error_at("tag_au_seerev")) {
            $ct = [];
            foreach (TagInfo::split_unpack($sv->newv("tag_chair")) as $ti)
                $ct[$ti[0]] = true;
            foreach (explode(" ", $sv->newv("tag_au_seerev")) as $t)
                if ($t !== "" && !isset($ct[$t])) {
                    $sv->warning_at("tag_au_seerev", "PC members can change the tag “" . htmlspecialchars($t) . "”, which affects whether authors can see reviews. Such tags should usually be <a href=\"" . hoturl("settings", "group=tags") . "\">read-only</a>.");
                    $sv->warning_at("tag_chair");
                }
        }
    }
}
