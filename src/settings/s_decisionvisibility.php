<?php
// src/settings/s_reviewvisibility.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class DecisionVisibility_SettingParser extends SettingParser {
    static function render(SettingValues $sv) {
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
    }
}
