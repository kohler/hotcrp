<?php
// settings/s_decisionvisibility.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class DecisionVisibility_SettingParser extends SettingParser {
    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name === "decision_visibility") {
            if ($sv->conf->time_all_author_view_decision()) {
                $sv->set_oldv($si, 2);
            } else {
                $sv->set_oldv($si, $sv->conf->setting("seedec") ?? 0);
            }
        }
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "decision_visibility"
            && ($v = $sv->base_parse_req($si)) !== null) {
            if ($v === 2) {
                $sv->save("decision_visibility_author", 2);
                $sv->save("decision_visibility_reviewer", Conf::SEEDEC_REV);
            } else {
                $sv->save("decision_visibility_author", 0);
                $sv->save("decision_visibility_reviewer", $v);
            }
            return true;
        } else if ($si->name === "decision_visibility_author_condition"
                   && $sv->has_req($si->name)) {
            $q = $sv->reqstr($si->name);
            ReviewVisibility_SettingParser::validate_condition($sv, $si->name, $q, 2);
            $sv->save($si, $q);
            return true;
        }
        return false;
    }


    static function print_author(SettingValues $sv) {
        echo '<p class="hidden feedback is-note mb-3 if-settings-decision-desk-reject">Decisions in the desk-reject category are always visible to authors and reviewers.</p>';
        $dva = '<div class="d-inline-flex flex-wrap">'
            . Ht::label("Yes, for submissions matching this search:", "decision_visibility_author_condition", ["class" => "mr-2 uic js-settings-radioitem-click"])
            . '<div>' . $sv->feedback_at("decision_visibility_author_condition")
            . $sv->entry("decision_visibility_author_condition", ["class" => "uii js-settings-radioitem-click papersearch need-suggest"])
            . '</div></div>';
        $sv->print_radio_table("decision_visibility_author", [
                0 => "No",
                2 => "Yes",
                1 => $dva
            ], 'Can <strong>authors see decisions</strong> (accept/reject) for their submissions?',
            ["fold_values" => [2, 1],
             "item_class" => "uich js-foldup js-settings-seedec"]);
    }

    static function print_reviewer(SettingValues $sv) {
        $extrev_view = $sv->vstr("review_visibility_external");
        $Rtext = $extrev_view != Conf::VIEWREV_NEVER ? "Reviewers" : "PC reviewers";
        $rtext = $extrev_view != Conf::VIEWREV_NEVER ? "reviewers" : "PC reviewers";
        $accept_auview = $sv->vstr("accepted_author_visibility")
            && $sv->vstr("author_visibility") != Conf::BLIND_NEVER;

        echo '<hr class="form-sep">';
        $sv->print_radio_table("decision_visibility_reviewer", [
                0 => "No",
                Conf::SEEDEC_REV => "Yes",
                Conf::SEEDEC_NCREV => "Yes, unless they have a conflict"
            ], "Can <strong>{$rtext}</strong> see decisions as soon as they are made?",
            ["group_class" => $accept_auview ? "fold2c" : "fold2o"]);
    }

    static function crosscheck(SettingValues $sv) {
        $conf = $sv->conf;
        if ($sv->has_interest("decision_visibility_author")
            && $conf->setting("au_seedec")
            && $sv->oldv("review_visibility_author") === Conf::AUSEEREV_NO) {
            $sv->warning_at(null, "<5>Authors can " . $sv->setting_link("see decisions", "decision_visibility_author") . ", but " . $sv->setting_link("not reviews", "review_visibility_author") . ". This is sometimes unintentional.");
        }

        if (($sv->has_interest("decision_visibility_author") || $sv->has_interest("submission_done"))
            && $sv->oldv("submission_open")
            && $sv->oldv("submission_done") > Conf::$now
            && !$conf->time_all_author_view_decision()
            && $conf->fetch_value("select paperId from Paper where outcome<0 limit 1") > 0) {
            $sv->warning_at(null, "<0>Updates will not be allowed for rejected submissions. As a result, authors can discover information about decisions that would otherwise be hidden.");
        }

        if (($sv->has_interest("decision_visibility_author") || $sv->has_interest("tag_readonly"))
            && $sv->oldv("decision_visibility_author") == 1
            && $sv->oldv("decision_visibility_author_condition")
            && !$sv->has_error_at("decision_visibility_author_condition")) {
            ReviewVisibility_SettingParser::validate_condition($sv, "decision_visibility_author_condition", $sv->oldv("decision_visibility_author_condition"), 1);
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
                   && !$conf->setting("au_seedec")
                   && !array_filter($conf->review_form()->all_fields(), function ($f) {
                       return $f->view_score >= VIEWSCORE_AUTHOR;
                   })
                   && array_filter($conf->review_form()->all_fields(), function ($f) {
                       return $f->view_score >= VIEWSCORE_AUTHORDEC;
                   })) {
            $sv->warning_at(null, "<5>" . $sv->setting_link("Authors can see reviews", "review_visibility_author")
                . ", but since "
                . $sv->setting_link("they cannot see decisions", "decision_visibility_author")
                . ", the reviews have no author-visible fields. This is sometimes unintentional; you may want to update "
                . $sv->setting_link("the review form", "rf") . ".");
            $sv->warning_at("review_visibility_author");
            $sv->warning_at("decision_visibility_author");
        }
    }
}
