<?php
// settings/s_decisionvisibility.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class DecisionVisibility_SettingParser extends SettingParser {
    static function print(SettingValues $sv) {
        $extrev_view = $sv->vstr("extrev_view");
        $Rtext = $extrev_view ? "Reviewers" : "PC reviewers";
        $rtext = $extrev_view ? "reviewers" : "PC reviewers";
        $accept_auview = $sv->vstr("seedec_showau")
            && $sv->vstr("sub_blind") != Conf::BLIND_NEVER;
        $sv->print_radio_table("seedec", [Conf::SEEDEC_ADMIN => "Only administrators",
                Conf::SEEDEC_NCREV => "$Rtext and non-conflicted PC members",
                Conf::SEEDEC_REV => "$Rtext and <em>all</em> PC members",
                Conf::SEEDEC_ALL => "<b>Authors</b>, $rtext, and all PC members<span class=\"fx fn2\"> (and reviewers can see accepted submissions’ author lists)</span>"
            ], 'Who can see <strong>decisions</strong> (accept/reject)?',
            ["group_class" => $accept_auview ? "fold2c" : "fold2o",
             "fold_values" => [Conf::SEEDEC_ALL],
             "item_class" => "uich js-foldup js-settings-seedec"]);
    }

    static function crosscheck(SettingValues $sv) {
        $conf = $sv->conf;
        if ($sv->has_interest("seedec")
            && $conf->setting("seedec") === Conf::SEEDEC_ALL
            && $conf->setting("au_seerev") === Conf::AUSEEREV_NO) {
            $sv->warning_at(null, "<5>Authors can " . $sv->setting_link("see decisions", "seedec") . ", but " . $sv->setting_link("not reviews", "au_seerev") . ". This is sometimes unintentional.");
        }

        if (($sv->has_interest("seedec") || $sv->has_interest("sub_sub"))
            && $conf->setting("sub_open")
            && $conf->setting("sub_sub") > Conf::$now
            && $conf->setting("seedec") !== Conf::SEEDEC_ALL
            && $conf->fetch_value("select paperId from Paper where outcome<0 limit 1") > 0) {
            $sv->warning_at(null, "<0>Updates will not be allowed for rejected submissions. As a result, authors can discover information about decisions that would otherwise be hidden.");
        }

        if ($sv->has_interest("au_seerev")
            && $conf->setting("au_seerev") !== Conf::AUSEEREV_NO
            && !array_filter($conf->review_form()->all_fields(), function ($f) {
                return $f->view_score >= VIEWSCORE_AUTHORDEC;
            })) {
            $sv->warning_at(null, "<5>" . $sv->setting_link("Authors can see reviews", "au_seerev")
                . ", but the reviews have no author-visible fields. This is sometimes unintentional; you may want to update "
                . $sv->setting_link("the review form", "rf") . ".");
            $sv->warning_at("au_seerev");
        } else if ($sv->has_interest("au_seerev")
                   && $conf->setting("au_seerev") !== Conf::AUSEEREV_NO
                   && $conf->setting("seedec") !== Conf::SEEDEC_ALL
                   && !array_filter($conf->review_form()->all_fields(), function ($f) {
                       return $f->view_score >= VIEWSCORE_AUTHOR;
                   })
                   && array_filter($conf->review_form()->all_fields(), function ($f) {
                       return $f->view_score >= VIEWSCORE_AUTHORDEC;
                   })) {
            $sv->warning_at(null, "<5>" . $sv->setting_link("Authors can see reviews", "au_seerev")
                . ", but since " . $sv->setting_link("they cannot see decisions", "seedec")
                . ", the reviews have no author-visible fields. This is sometimes unintentional; you may want to update "
                . $sv->setting_link("the review form", "rf") . ".");
            $sv->warning_at("au_seerev");
            $sv->warning_at("seedec");
        }
    }
}
