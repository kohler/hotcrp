<?php
// settings/s_decisionvisibility.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class DecisionVisibility_SettingParser extends SettingParser {
    static function print(SettingValues $sv) {
        $extrev_view = $sv->vstr("review_visibility_external");
        $Rtext = $extrev_view ? "Reviewers" : "PC reviewers";
        $rtext = $extrev_view ? "reviewers" : "PC reviewers";
        $accept_auview = $sv->vstr("accepted_author_visibility")
            && $sv->vstr("author_visibility") != Conf::BLIND_NEVER;
        $sv->print_radio_table("decision_visibility", [Conf::SEEDEC_ADMIN => "Only administrators",
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
        if ($sv->has_interest("decision_visibility")
            && $sv->oldv("decision_visibility") === Conf::SEEDEC_ALL
            && $sv->oldv("review_visibility_author") === Conf::AUSEEREV_NO) {
            $sv->warning_at(null, "<5>Authors can " . $sv->setting_link("see decisions", "decision_visibility") . ", but " . $sv->setting_link("not reviews", "review_visibility_author") . ". This is sometimes unintentional.");
        }

        if (($sv->has_interest("decision_visibility") || $sv->has_interest("sub_sub"))
            && $sv->oldv("sub_open")
            && $sv->oldv("sub_sub") > Conf::$now
            && $sv->oldv("decision_visibility") !== Conf::SEEDEC_ALL
            && $conf->fetch_value("select paperId from Paper where outcome<0 limit 1") > 0) {
            $sv->warning_at(null, "<0>Updates will not be allowed for rejected submissions. As a result, authors can discover information about decisions that would otherwise be hidden.");
        }

        if ($sv->has_interest("review_visibility_author")
            && $sv->oldv("review_visibility_author") !== Conf::AUSEEREV_NO
            && !array_filter($conf->review_form()->all_fields(), function ($f) {
                return $f->view_score >= VIEWSCORE_AUTHORDEC;
            })) {
            $sv->warning_at(null, "<5>" . $sv->setting_link("Authors can see reviews", "review_visibility_author")
                . ", but the reviews have no author-visible fields. This is sometimes unintentional; you may want to update "
                . $sv->setting_link("the review form", "rf") . ".");
            $sv->warning_at("review_visibility_author");
        } else if ($sv->has_interest("review_visibility_author")
                   && $sv->oldv("review_visibility_author") !== Conf::AUSEEREV_NO
                   && $sv->oldv("decision_visibility") !== Conf::SEEDEC_ALL
                   && !array_filter($conf->review_form()->all_fields(), function ($f) {
                       return $f->view_score >= VIEWSCORE_AUTHOR;
                   })
                   && array_filter($conf->review_form()->all_fields(), function ($f) {
                       return $f->view_score >= VIEWSCORE_AUTHORDEC;
                   })) {
            $sv->warning_at(null, "<5>" . $sv->setting_link("Authors can see reviews", "review_visibility_author")
                . ", but since "
                . $sv->setting_link("they cannot see decisions", "decision_visibility")
                . ", the reviews have no author-visible fields. This is sometimes unintentional; you may want to update "
                . $sv->setting_link("the review form", "rf") . ".");
            $sv->warning_at("review_visibility_author");
            $sv->warning_at("decision_visibility");
        }
    }
}
