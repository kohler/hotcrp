<?php
// src/settings/s_reviews.php -- HotCRP settings > reviews page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Reviews_SettingRenderer {
    private static function echo_round($sv, $rnum, $nameval, $review_count, $deletable) {
        $rname = "roundname_$rnum";
        if ($sv->use_req() && $rnum !== '$')
            $nameval = (string) $sv->reqv($rname);
        $rname_si = $sv->si($rname);
        if ($nameval === "(new round)" || $rnum === '$')
            $rname_si->placeholder = "(new round)";
        $sv->set_oldv($rname, $nameval);

        echo '<div class="mg js-settings-review-round" data-review-round-number="', $rnum, '"><div>',
            $sv->label($rname, "Round"), ' &nbsp;',
            $sv->render_entry($rname);
        echo '<div class="d-inline-block" style="min-width:7em;margin-left:2em">';
        if ($rnum !== '$' && $review_count)
            echo '<a href="', $sv->conf->hoturl("search", "q=" . urlencode("round:" . ($rnum ? $sv->conf->round_name($rnum) : "none"))), '">(', plural($review_count, "review"), ')</a>';
        echo '</div>';
        if ($deletable) {
            echo '<div class="d-inline-block" style="padding-left:2em">',
                Ht::hidden("deleteround_$rnum", "", ["data-default-value" => ""]),
                Ht::button("Delete round", ["class" => "js-settings-review-round-delete"]),
                '</div>';
        }
        if ($rnum === '$')
            echo '<div class="f-h">Names like “R1” and “R2” work well.</div>';
        echo '</div>';

        // deadlines
        $entrysuf = $rnum ? "_$rnum" : "";
        if ($rnum === '$' && count($sv->conf->round_list()))
            $dlsuf = "_" . (count($sv->conf->round_list()) - 1);
        else if ($rnum !== '$' && $rnum)
            $dlsuf = "_" . $rnum;
        else
            $dlsuf = "";
        $si = $sv->si("extrev_soft$dlsuf");
        $si->date_backup = "pcrev_soft$dlsuf";
        $si = $sv->si("extrev_hard$dlsuf");
        $si->date_backup = "pcrev_hard$dlsuf";

        echo '<div class="settings-2col" style="margin-left:3em">';
        $sv->echo_entry_group("pcrev_soft$entrysuf", "PC deadline", ["horizontal" => true]);
        $sv->echo_entry_group("pcrev_hard$entrysuf", "Hard deadline", ["horizontal" => true]);
        $sv->echo_entry_group("extrev_soft$entrysuf", "External deadline", ["horizontal" => true]);
        $sv->echo_entry_group("extrev_hard$entrysuf", "Hard deadline", ["horizontal" => true]);
        echo "</div></div>\n";
    }

    static function render(SettingValues $sv) {
        echo '<div class="form-g">';
        $sv->echo_checkbox("rev_open", "<b>Open site for reviewing</b>");
        $sv->echo_checkbox("cmt_always", "Allow comments even if reviewing is closed");
        echo "</div>\n";

        $sv->echo_radio_table("rev_blind", [Conf::BLIND_ALWAYS => "Yes, reviews are anonymous",
                   Conf::BLIND_NEVER => "No, reviewer names are visible to authors",
                   Conf::BLIND_OPTIONAL => "Depends: reviewers decide whether to expose their names"],
            '<strong>Review anonymity:</strong> Are reviewer names hidden from authors?');


        // Deadlines
        echo "<h3 id=\"rounds\" class=\"form-h\">Deadlines &amp; rounds</h3>\n";
        echo '<p>Reviews are due by the deadline, but <em>cannot be modified</em> after the hard deadline. Most conferences don’t use hard deadlines for reviews.</p>';
        echo '<p class="f-h">', ($sv->type_hint("date") ? : ""), '</p>';

        $rounds = $sv->conf->round_list();
        if ($sv->use_req()) {
            for ($i = 1; $sv->has_reqv("roundname_$i"); ++$i)
                $rounds[$i] = $sv->reqv("deleteround_$i") ? ";" : trim($sv->reqv("roundname_$i"));
        }

        // prepare round selector
        $sv->set_oldv("rev_roundtag", "#" . $sv->conf->assignment_round(false));
        $round_value = $sv->oldv("rev_roundtag");
        if (preg_match('/\A\#(\d+)\z/', $sv->curv("rev_roundtag"), $m)
            && get($rounds, intval($m[1]), ";") != ";")
            $round_value = $m[0];

        $sv->set_oldv("extrev_roundtag", "#same");
        if ($sv->conf->setting_data("extrev_roundtag", null) !== null)
            $sv->set_oldv("extrev_roundtag", "#" . $sv->conf->assignment_round(true));
        $extround_value = $sv->oldv("extrev_roundtag");
        if (preg_match('/\A\#(\d+)\z/', $sv->curv("extrev_roundtag"), $m)
            && get($rounds, intval($m[1]), ";") != ";")
            $extround_value = $m[0];

        // does round 0 exist?
        $print_round0 = true;
        if ($round_value != "#0"
            && $extround_value != "#0"
            && (!$sv->use_req() || $sv->has_reqv("roundname_0"))
            && !$sv->conf->round0_defined())
            $print_round0 = false;

        $roundorder = [];
        foreach ($sv->conf->defined_round_list() as $i => $rname)
            $roundorder[$i] = $rounds[$i];
        foreach ($rounds as $i => $rname)
            $roundorder[$i] = $rounds[$i];
        if ($print_round0)
            $roundorder[0] = ";";

        // round selector
        $selector = [];
        foreach ($roundorder as $i => $rname) {
            if ($rname !== "" && $rname !== ";")
                $selector["#$i"] = (object) ["label" => $rname, "id" => "rev_roundtag_$i"];
            else if ($i === 0 && $print_round0)
                $selector["#0"] = "unnamed";
        }

        echo '<div id="roundtable">', Ht::hidden("has_tag_rounds", 1);
        $round_map = edb_map($sv->conf->ql("select reviewRound, count(*) from PaperReview group by reviewRound"));
        $num_printed = 0;
        foreach ($roundorder as $i => $rname) {
            if ($i ? $rname !== ";" : $print_round0) {
                self::echo_round($sv, $i, $i ? $rname : "", +get($round_map, $i), count($selector) !== 1);
                ++$num_printed;
            }
        }
        echo '</div><div id="newround" class="hidden">';
        self::echo_round($sv, '$', "", "", true);
        echo '</div><div class="g"></div>';
        echo Ht::button("Add round", ["id" => "settings_review_round_add"]),
            ' &nbsp; <span class="hint"><a href="', $sv->conf->hoturl("help", "t=revround"), '">What is this?</a></span>',
            Ht::hidden("oldroundcount", count($sv->conf->round_list())),
            Ht::hidden("has_rev_roundtag", 1), Ht::hidden("has_extrev_roundtag", 1);
        foreach ($roundorder as $i => $rname) {
            if ($i && $rname === ";")
                echo Ht::hidden("roundname_$i", "", array("id" => "roundname_$i")),
                    Ht::hidden("deleteround_$i", 1, ["data-default-value" => "1"]);
        }
        Ht::stash_script('review_round_settings()');

        $extselector = array_merge(["#same" => "(same as PC)"], $selector);
        echo '<div id="round_container" style="margin-top:1em', (count($selector) == 1 ? ';display:none' : ''), '">',
            $sv->label("rev_roundtag", "New PC reviews use round&nbsp; "),
            Ht::select("rev_roundtag", $selector, $round_value, $sv->sjs("rev_roundtag")),
            ' <span class="barsep">·</span> ',
            $sv->label("extrev_roundtag", "New external reviews use round&nbsp; "),
            Ht::select("extrev_roundtag", $extselector, $extround_value, $sv->sjs("extrev_roundtag")),
            '</div>';
    }


    static function render_pc(SettingValues $sv) {
        echo '<div class="has-fold fold2c">';
        echo '<div class="form-g has-fold foldo">';
        $sv->echo_checkbox('pcrev_any', "PC members can review any submission", ["class" => "uich js-foldup"]);
        if ($sv->conf->setting("pcrev_any")
            && $sv->conf->check_track_sensitivity(Track::UNASSREV))
            echo '<p class="f-h fx">', $sv->setting_link("Current track settings", "tracks"), ' may restrict self-assigned reviews.</p>';
        echo "</div>\n";

        $hint = "";
        if ($sv->conf->has_any_metareviews())
            $hint .= ' Metareviewers can always see associated reviews and reviewer names.';
        if ($sv->conf->check_track_sensitivity(Track::VIEWREV)
            || $sv->conf->check_track_sensitivity(Track::VIEWALLREV))
            $hint .= ' ' . $sv->setting_link("Current track settings", "tracks") . ' restrict review visibility.';
        if ($hint !== "")
            $hint = '<p class="settings-ag f-h">' . ltrim($hint) . '</p>';
        $sv->echo_radio_table("pc_seeallrev", [Conf::PCSEEREV_YES => "Yes",
                  Conf::PCSEEREV_UNLESSINCOMPLETE => "Yes, unless they haven’t completed an assigned review for the same submission",
                  Conf::PCSEEREV_UNLESSANYINCOMPLETE => "Yes, after completing all their assigned reviews",
                  Conf::PCSEEREV_IFCOMPLETE => "Only after completing a review for the same submission"],
            'Can PC members <strong>see all reviews<span class="fx2"> and comments</span></strong> except for conflicts?',
            ["after" => $hint, "fold" => Conf::PCSEEREV_IFCOMPLETE]);

        echo '<div class="form-nearby form-g">';
        $sv->echo_checkbox("lead_seerev", "Discussion leads can always see submitted reviews and reviewer names");
        echo '</div>';


        $hint = "";
        if ($sv->conf->check_track_sensitivity(Track::VIEWREVID))
            $hint = '<p class="settings-ag f-h">' . $sv->setting_link("Current track settings", "tracks") . ' restrict reviewer name visibility.</p>';
        $sv->echo_radio_table("pc_seeblindrev", [0 => "Yes",
                1 => "Only after completing a review for the same submission"],
            'Can PC members see <strong><span class="fn2">comments and </span>reviewer names</strong> except for conflicts?',
            ["after" => $hint, "fold" => 1]);


        echo '<div class="form-g">';
        $sv->echo_checkbox('cmt_revid', "PC can see comments when reviews are anonymous", ["class" => "uich js-foldup", "data-fold-target" => "2", "hint_class" => "fx2"], "Commenter names are hidden when reviews are anonymous.");
        echo "</div></div>\n";
    }


    static function render_external(SettingValues $sv) {
        $sv->render_group("reviews/external");
    }
    static function render_extrev_view(SettingValues $sv) {
        $sv->echo_radio_table("extrev_view", [
                0 => "No",
                1 => "Yes, but they can’t see comments or reviewer names",
                2 => "Yes"
            ], 'Can external reviewers see reviews, comments, and eventual decisions for their assigned submissions, once they’ve completed a review?');
    }
    static function render_extrev_editdelegate(SettingValues $sv) {
        echo '<div id="foldpcrev_editdelegate" class="form-g has-fold fold',
            $sv->curv("extrev_chairreq") >= 0 ? 'o' : 'c',
            ' fold2o" data-fold-values="0 1 2">';
        $sv->echo_radio_table("extrev_chairreq", [-1 => "No",
                1 => "Yes, but administrators must approve all requests",
                2 => "Yes, but administrators must approve external reviewers with potential conflicts",
                0 => "Yes"],
                "Can PC reviewers request external reviews?",
                ["fold" => true]);
        echo '<div class="fx">';
        // echo '<p>Secondary PC reviews can be delegated to external reviewers. When the external review is complete, the secondary PC reviewer need not complete a review of their own.</p>', "\n";
        $sv->echo_radio_table("pcrev_editdelegate", [
                0 => "No",
                1 => "Yes",
                2 => "Yes, and external reviews are hidden until requesters approve them",
                3 => "Yes, and external reviews are visible only to their requesters"
            ], "Can PC members edit the external reviews they requested?");
        echo "</div></div>\n";
    }
    static function render_extrev_requestmail(SettingValues $sv) {
        $t = $sv->expand_mail_template("requestreview", false);
        echo '<div id="foldmailbody_requestreview" class="form-g ',
            ($t == $sv->expand_mail_template("requestreview", true) ? "foldc" : "foldo"),
            '">';
        $sv->set_oldv("mailbody_requestreview", $t["body"]);
        echo '<div class="', $sv->control_class("mailbody_requestreview", "f-i"), '">',
            '<div class="f-c n">',
            '<a class="ui qq js-foldup" href="">', expander(null, 0),
            '<label for="mailbody_requestreview">Mail template for external review requests</label></a>',
            '<span class="fx"> (<a href="', $sv->conf->hoturl("mail"), '">keywords</a> allowed; set to empty for default)</span></div>',
            $sv->render_textarea("mailbody_requestreview", ["class" => "text-monospace fx", "cols" => 80, "rows" => 20]);
        $sv->echo_messages_at("mailbody_requestreview");
        echo "</div></div>\n";
    }

    static function render_ratings(SettingValues $sv) {
        $sv->echo_radio_table("rev_ratings", [
                REV_RATINGS_NONE => "No",
                REV_RATINGS_PC => "Yes, PC members can rate reviews",
                REV_RATINGS_PC_EXTERNAL => "Yes, PC members and external reviewers can rate reviews"
            ], 'Should HotCRP collect ratings of reviews?   <a class="hint" href="' . $sv->conf->hoturl("help", "t=revrate") . '">Learn more</a>');
    }

    static function crosscheck(SettingValues $sv) {
        global $Now;
        $errored = false;
        foreach ($sv->conf->round_list() as $i => $rname) {
            $suf = $i ? "_$i" : "";
            foreach (Conf::$review_deadlines as $deadline)
                if ($sv->has_interest($deadline . $suf)
                    && $sv->newv($deadline . $suf) > $Now
                    && $sv->newv("rev_open") <= 0
                    && !$errored) {
                    $sv->warning_at("rev_open", "A review deadline is set in the future, but the site is not open for reviewing. This is sometimes unintentional.");
                    $errored = true;
                    break;
                }
        }

        if (($sv->has_interest("au_seerev") || $sv->has_interest("pcrev_soft"))
            && $sv->newv("au_seerev") != Conf::AUSEEREV_NO
            && $sv->newv("au_seerev") != Conf::AUSEEREV_TAGS
            && $sv->newv("pcrev_soft") > 0
            && $Now < $sv->newv("pcrev_soft")
            && !$sv->has_error())
            $sv->warning_at(null, "Authors can see reviews and comments although it is before the review deadline. This is sometimes unintentional.");

        if (($sv->has_interest("rev_blind") || $sv->has_interest("extrev_view"))
            && $sv->newv("rev_blind") == Conf::BLIND_NEVER
            && $sv->newv("extrev_view") == 1)
            $sv->warning_at("extrev_view", "Reviews aren’t blind, so external reviewers can see reviewer names and comments despite your settings.");

        if ($sv->has_interest("mailbody_requestreview")
            && $sv->newv("mailbody_requestreview")
            && (strpos($sv->newv("mailbody_requestreview"), "%LOGINURL%") !== false
                || strpos($sv->newv("mailbody_requestreview"), "%LOGINURLPARTS%") !== false))
            $sv->warning_at("mailbody_requestreview", "The <code>%LOGINURL%</code> and <code>%LOGINURLPARTS%</code> keywords should no longer be used in email templates.");
    }
}


class Round_SettingParser extends SettingParser {
    private $rev_round_changes = array();

    static function clean_round_name($name) {
        $name = trim($name);
        if (!preg_match('{\A(?:\(no name\)|default|unnamed|n/a)\z}i', $name)) {
            return $name;
        } else {
            return "";
        }
    }

    function parse(SettingValues $sv, Si $si) {
        assert($si->name === "tag_rounds");

        // count number of requested rounds
        $nreqround = 1;
        while ($sv->has_reqv("roundname_$nreqround")
               || $sv->has_reqv("deleteround_$nreqround")) {
            ++$nreqround;
        }

        // fix round names
        $roundnames = array_fill(0, $nreqround, ";");
        $roundlnames = [];
        for ($i = 0; $i < $nreqround; ++$i) {
            $name = $sv->reqv("roundname_$i");
            if ($name !== null && !$sv->reqv("deleteround_$i")) {
                $name = self::clean_round_name($name);
                $lname = strtolower($name);
                if (isset($roundlnames[$lname])) {
                    $sv->error_at("roundname_$i", "Round names must be distinct. Use bulk assignment to change existing reviews’ rounds.");
                    $sv->error_at("roundname_" . $roundlnames[$lname]);
                } else if ($name !== ""
                           && ($rerror = Conf::round_name_error($name))) {
                    $sv->error_at("roundname_$i", $rerror);
                } else {
                    $roundnames[$i] = $name;
                    $roundlnames[$lname] = $i;
                }
            }
        }

        // check for round transformations
        $this->rev_round_changes = [];
        if ($roundnames[0] !== ";" && $roundnames[0] !== "") {
            $j = 1;
            while ($j < count($roundnames) && $roundnames[$j] !== ";") {
                ++$j;
            }
            if ($j === $nreqround) {
                ++$nreqround;
                $roundnames[$j] = ";";
            }
            $roundnames[$j] = $roundnames[0];
            $roundnames[0] = ";";
            $this->rev_round_changes[0] = $j;
        }
        if (isset($roundlnames[""]) && $roundlnames[""] !== 0) {
            $roundnames[$roundlnames[""]] = ";";
            $roundnames[0] = "";
            $this->rev_round_changes[$roundlnames[""]] = 0;
        }

        // delete deadlines for deleted rounds
        foreach ($roundnames as $i => $n) {
            if ($n === ";") {
                foreach (Conf::$review_deadlines as $dl) {
                    $sv->save($dl . ($i ? "_$i" : ""), null);
                }
            }
        }

        // clean up and save round names
        while (count($roundnames) > 1
               && $roundnames[count($roundnames) - 1] === ";") {
            array_pop($roundnames);
        }
        unset($roundnames[0]);
        $sv->save("tag_rounds", join(" ", $roundnames));

        // register callback if we need to change reviews' rounds
        if (!empty($this->rev_round_changes)) {
            $sv->need_lock["PaperReview"] = $sv->need_lock["ReviewRequest"] =
                $sv->need_lock["PaperReviewRefused"] = true;
            return true;
        } else {
            return false;
        }
    }

    function save(SettingValues $sv, Si $si) {
        if ($this->rev_round_changes) {
            $qx = "case";
            foreach ($this->rev_round_changes as $old => $new)
                $qx .= " when reviewRound=$old then $new";
            $qx .= " else reviewRound end";
            $sv->conf->qe_raw("update PaperReview set reviewRound=" . $qx);
            $sv->conf->qe_raw("update ReviewRequest set reviewRound=" . $qx);
            $sv->conf->qe_raw("update PaperReviewRefused set reviewRound=" . $qx);
        }
    }
}

class RoundSelector_SettingParser extends SettingParser {
    function parse(SettingValues $sv, Si $si) {
        $sv->save($si->name, null);
        if (preg_match('{\A\#(\d+)\z}', $sv->reqv($si->name), $m)) {
            $t = Round_SettingParser::clean_round_name($sv->reqv("roundname_$m[1]"));
            if ($t === "") {
                // null for extrev_roundtag means “same as PC”
                if ($si->name === "extrev_roundtag")
                    $sv->save($si->name, "unnamed");
            } else if ($t !== ";"
                       && array_search(strtolower($t), explode(" ", strtolower($sv->newv("tag_rounds")))) !== false) {
                $sv->save($si->name, $t);
            }
        }
    }
}

class ReviewDeadline_SettingParser extends SettingParser {
    function parse(SettingValues $sv, Si $si) {
        assert($sv->has_savedv("tag_rounds"));

        $rref = (int) $si->suffix();
        if ($sv->reqv("deleteround_$rref")) {
            // setting already deleted by tag_rounds parsing
            return false;
        }

        $name = Round_SettingParser::clean_round_name($sv->reqv("roundname_$rref"));
        $rounds = explode(" ", strtolower($sv->newv("tag_rounds")));
        if ($name === "") {
            $rnum = 0;
        } else {
            $rnum = array_search(strtolower($name), $rounds);
            assert($rnum !== false);
            $rnum += 1;
        }

        $deadline = $si->prefix();
        $k = $deadline . ($rnum ? "_$rnum" : "");
        $v = $sv->parse_value($si);
        $sv->save($k, $v <= 0 ? null : $v);

        if ($v > 0 && str_ends_with($deadline, "hard")) {
            $sv->check_date_before(substr($deadline, 0, -4) . "soft" . ($rnum ? "_$rnum" : ""), $si->name, true);
        }

        return false;
    }
}
