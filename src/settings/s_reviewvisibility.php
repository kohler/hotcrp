<?php
// src/settings/s_reviewvisibility.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2017 Eddie Kohler; see LICENSE.

class ReviewVisibility_SettingParser extends SettingParser {
    static function render(SettingValues $sv) {
        $no_text = "No, unless authors can edit responses";
        if (!$sv->conf->setting("au_seerev", 0)) {
            if ($sv->conf->timeAuthorViewReviews())
                $no_text .= '<div class="hint">Authors can edit responses and see reviews now.</div>';
            else if ($sv->conf->setting("resp_active"))
                $no_text .= '<div class="hint">Authors cannot edit responses now.</div>';
        }
        $opts = array(Conf::AUSEEREV_NO => $no_text,
                      Conf::AUSEEREV_YES => "Yes");
        if ($sv->newv("au_seerev") == Conf::AUSEEREV_UNLESSINCOMPLETE
            && !$sv->conf->opt("allow_auseerev_unlessincomplete"))
            $sv->conf->save_setting("opt.allow_auseerev_unlessincomplete", 1);
        if ($sv->conf->opt("allow_auseerev_unlessincomplete"))
            $opts[Conf::AUSEEREV_UNLESSINCOMPLETE] = "Yes, after completing any assigned reviews for other papers";
        $opts[Conf::AUSEEREV_TAGS] = "Yes, for papers with any of these tags:&nbsp; " . $sv->render_entry("tag_au_seerev");
        $sv->echo_radio_table("au_seerev", $opts,
            'Can <strong>authors see reviews and author-visible comments</strong> for their papers?');
        echo Ht::hidden("has_tag_au_seerev", 1);
        Ht::stash_script('$("#tag_au_seerev").on("input", function () { $("#au_seerev_' . Conf::AUSEEREV_TAGS . '").click(); })');

        echo '<div class="settings-g">';
        $sv->echo_checkbox("cmt_author", "Authors can <strong>exchange comments</strong> with reviewers when reviews are visible");
        echo "</div>\n";

        $sv->echo_radio_table("seedec", array(Conf::SEEDEC_ADMIN => "Only administrators",
                                Conf::SEEDEC_NCREV => "Reviewers and non-conflicted PC members",
                                Conf::SEEDEC_REV => "Reviewers and <em>all</em> PC members",
                                Conf::SEEDEC_ALL => "<b>Authors</b>, reviewers, and all PC members (and reviewers can see accepted papers’ author lists)"),
            'Who can see paper <strong>decisions</strong> (accept/reject)?');

        echo '<div class="settings-g">';
        $sv->echo_checkbox("shepherd_hide", "Hide shepherd names from authors");
        echo "</div>\n";
    }

    static function crosscheck(SettingValues $sv) {
        global $Now;

        if ($sv->has_interest("seedec")
            && $sv->newv("seedec") == Conf::SEEDEC_ALL
            && $sv->newv("au_seerev") == Conf::AUSEEREV_NO)
            $sv->warning_at(null, "Authors can see decisions, but not reviews. This is sometimes unintentional.");

        if (($sv->has_interest("seedec") || $sv->has_interest("sub_sub"))
            && $sv->newv("sub_open")
            && $sv->newv("sub_sub") > $Now
            && $sv->newv("seedec") != Conf::SEEDEC_ALL
            && $sv->conf->fetch_value("select paperId from Paper where outcome<0 limit 1") > 0)
            $sv->warning_at(null, "Updates will not be allowed for rejected submissions. This exposes decision information that would otherwise be hidden from authors.");

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
                    $sv->warning_at("tag_au_seerev", "PC members can change the tag “" . htmlspecialchars($t) . "”, which affects whether authors can see reviews. Such tags should usually be <a href=\"" . hoturl("settings", "group=tags") . "\">chair-only</a>.");
                    $sv->warning_at("tag_chair");
                }
        }
    }
}
