<?php
// src/settings/s_decisionvisibility.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class DecisionVisibility_SettingParser extends SettingParser {
    static function render(SettingValues $sv) {
        $extrev_view = $sv->curv("extrev_view");
        $Rtext = $extrev_view ? "Reviewers" : "PC reviewers";
        $rtext = $extrev_view ? "reviewers" : "PC reviewers";
        $accept_auview = !$sv->curv("seedec_hideau")
            && $sv->curv("sub_blind") != Conf::BLIND_NEVER;
        $sv->echo_radio_table("seedec", [Conf::SEEDEC_ADMIN => "Only administrators",
                Conf::SEEDEC_NCREV => "$Rtext and non-conflicted PC members",
                Conf::SEEDEC_REV => "$Rtext and <em>all</em> PC members",
                Conf::SEEDEC_ALL => "<b>Authors</b>, $rtext, and all PC members<span class=\"fx fn2\"> (and reviewers can see accepted submissions’ author lists)</span>"],
            'Who can see <strong>decisions</strong> (accept/reject)?',
            ["group_class" => $accept_auview ? "fold2c" : "fold2o",
             "fold" => Conf::SEEDEC_ALL,
             "item_class" => "uich js-foldup js-settings-seedec"]);
    }

    static function crosscheck(SettingValues $sv) {
        global $Now;

        if ($sv->has_interest("seedec")
            && $sv->newv("seedec") == Conf::SEEDEC_ALL
            && $sv->newv("au_seerev") == Conf::AUSEEREV_NO) {
            $sv->warning_at(null, "Authors can see decisions, but not reviews. This is sometimes unintentional.");
        }

        if (($sv->has_interest("seedec") || $sv->has_interest("sub_sub"))
            && $sv->newv("sub_open")
            && $sv->newv("sub_sub") > $Now
            && $sv->newv("seedec") != Conf::SEEDEC_ALL
            && $sv->conf->fetch_value("select paperId from Paper where outcome<0 limit 1") > 0) {
            $sv->warning_at(null, "Updates will not be allowed for rejected submissions. This exposes decision information that would otherwise be hidden from authors.");
        }

        if ($sv->has_interest("au_seerev")
            && !array_filter($sv->conf->review_form()->all_fields(), function ($f) {
                return $f->view_score >= VIEWSCORE_AUTHORDEC;
            })) {
            $sv->warning_at(null, $sv->setting_link("Authors can see reviews", "au_seerev")
                . ", but the reviews have no author-visible fields. This is sometimes unintentional; you may want to update "
                . $sv->setting_link("the review form", "review_form") . ".");
            $sv->warning_at("au_seerev", false);
        } else if ($sv->has_interest("au_seerev")
                   && $sv->newv("seedec") != Conf::SEEDEC_ALL
                   && $sv->newv("au_seerev") != Conf::AUSEEREV_NO
                   && !array_filter($sv->conf->review_form()->all_fields(), function ($f) {
                       return $f->view_score >= VIEWSCORE_AUTHOR;
                   })
                   && array_filter($sv->conf->review_form()->all_fields(), function ($f) {
                       return $f->view_score >= VIEWSCORE_AUTHORDEC;
                   })) {
            $sv->warning_at(null, $sv->setting_link("Authors can see reviews", "au_seerev")
                . ", but since " . $sv->setting_link("they cannot see decisions", "seedec")
                . ", the reviews have no author-visible fields. This is sometimes unintentional; you may want to update "
                . $sv->setting_link("the review form", "review_form") . ".");
            $sv->warning_at("au_seerev", false);
            $sv->warning_at("seedec", false);
        }
    }
}
