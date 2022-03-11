<?php
// settings/s_reviews.php -- HotCRP settings > reviews page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Reviews_SettingRenderer {
    /** @param SettingValues $sv
     * @param int|'$' $rnum
     * @param int $review_count */
    private static function print_round($sv, $rnum, $nameval, $review_count, $deletable) {
        $rname = "roundname_$rnum";
        if ($sv->use_req() && $rnum !== '$') {
            $nameval = (string) $sv->reqstr($rname);
        }
        $rname_si = $sv->si($rname);
        if ($nameval === "(new round)" || $rnum === '$') {
            $rname_si->placeholder = "(new round)";
        }
        $sv->set_oldv($rname, $nameval);

        echo '<div class="js-settings-review-round mt-3" data-review-round-number="', $rnum, '">',
            '<div class="mb-2">';
        $sv->print_feedback_at($rname);
        echo $sv->label($rname, "Round"), ' &nbsp;',
            $sv->entry($rname);
        echo '<div class="d-inline-block" style="min-width:7em;margin-left:2em">';
        if ($rnum !== '$' && $review_count) {
            echo '<a href="', $sv->conf->hoturl("search", "q=" . urlencode("round:" . ($rnum ? $sv->conf->round_name($rnum) : "none"))), '">(', plural($review_count, "review"), ')</a>';
        }
        echo '</div>';
        if ($deletable) {
            echo '<div class="d-inline-block" style="padding-left:2em">',
                Ht::hidden("deleteround_$rnum", "", ["data-default-value" => ""]),
                Ht::button("Delete round", ["class" => "js-settings-review-round-delete"]),
                '</div>';
        }
        if ($rnum === '$') {
            echo '<div class="f-h">Names like “R1” and “R2” work well.</div>';
        }
        echo '</div>';

        // deadlines
        if ($rnum !== '$') {
            if ($sv->oldv("extrev_soft_$rnum") === $sv->oldv("pcrev_soft_$rnum")) {
                $sv->set_oldv("extrev_soft_$rnum", null);
            }
            if ($sv->oldv("extrev_hard_$rnum") === $sv->oldv("pcrev_hard_$rnum")) {
                $sv->set_oldv("extrev_hard_$rnum", null);
            }
        }

        echo '<div class="f-mcol ml-5"><div class="flex-grow-0">';
        $sv->print_entry_group("pcrev_soft_$rnum", "PC deadline", ["horizontal" => true]);
        $sv->print_entry_group("pcrev_hard_$rnum", "Hard deadline", ["horizontal" => true]);
        echo '</div><div class="flex-grow-0">';
        $sv->print_entry_group("extrev_soft_$rnum", "External deadline", ["horizontal" => true]);
        $sv->print_entry_group("extrev_hard_$rnum", "Hard deadline", ["horizontal" => true]);
        echo "</div></div></div>\n";
    }

    static function print(SettingValues $sv) {
        echo '<div class="form-g">';
        $sv->print_checkbox("rev_open", "<b>Enable review editing</b>");
        $sv->print_checkbox("cmt_always", "Allow comments even if reviewing is closed");
        echo "</div>\n";

        $sv->print_radio_table("rev_blind", [Conf::BLIND_ALWAYS => "Yes, reviews are anonymous",
                   Conf::BLIND_NEVER => "No, reviewer names are visible to authors",
                   Conf::BLIND_OPTIONAL => "Depends: reviewers decide whether to expose their names"],
            '<strong>Review anonymity:</strong> Are reviewer names hidden from authors?');
    }

    static function print_rounds(SettingValues $sv) {
        echo '<p>Reviews are due by the deadline, but <em>cannot be modified</em> after the hard deadline. Most conferences don’t use hard deadlines for reviews.</p>';
        echo '<p class="f-h">', $sv->type_hint("date"), '</p>';

        $rounds = $sv->conf->round_list();
        if ($sv->use_req()) {
            for ($i = 1; $sv->has_req("roundname_$i"); ++$i)
                $rounds[$i] = $sv->reqstr("deleteround_$i") ? ";" : trim($sv->reqstr("roundname_$i"));
        }

        // prepare round selector
        $sv->set_oldv("rev_roundtag", $sv->conf->setting_data("rev_roundtag") ?? "");
        $t = $sv->conf->setting_data("extrev_roundtag") ?? "default";
        $sv->set_oldv("extrev_roundtag", $t === "unnamed" ? "" : $t);

        // does round 0 exist?
        $print_round0 = true;
        if ($sv->vstr("rev_roundtag") !== ""
            && $sv->vstr("extrev_roundtag") !== ""
            && (!$sv->use_req() || $sv->has_req("roundname_0"))
            && !$sv->conf->round0_defined()) {
            $print_round0 = false;
        }

        $roundorder = [];
        foreach ($sv->conf->defined_round_list() as $i => $rname) {
            $roundorder[$i] = $rounds[$i];
        }
        foreach ($rounds as $i => $rname) {
            $roundorder[$i] = $rounds[$i];
        }
        if ($print_round0) {
            $roundorder[0] = ";";
        }

        // round selector
        $selector = [];
        foreach ($roundorder as $i => $rname) {
            if ($rname !== "" && $rname !== ";") {
                $selector[$rname] = (object) ["label" => $rname, "id" => "rev_roundtag_$i"];
            } else if ($i === 0 && $print_round0) {
                $selector[""] = (object) ["label" => "unnamed", "id" => "rev_roundtag_0"];
            }
        }

        echo '<div id="roundtable">', Ht::hidden("has_tag_rounds", 1);
        $round_map = Dbl::fetch_iimap($sv->conf->ql("select reviewRound, count(*) from PaperReview group by reviewRound"));
        $num_printed = 0;
        foreach ($roundorder as $i => $rname) {
            if ($i ? $rname !== ";" : $print_round0) {
                self::print_round($sv, $i, $i ? $rname : "", $round_map[$i] ?? 0,
                                 $i !== 0 && count($selector) !== 1);
                ++$num_printed;
            }
        }
        echo '</div><div id="newround" class="hidden">';
        self::print_round($sv, '$', "", 0, true);
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
        Ht::stash_script('hotcrp.settings.review_round()');

        $extselector = array_merge(["default" => "(same as PC)"], $selector);
        echo '<div id="round_container" style="margin-top:1em', (count($selector) == 1 ? ';display:none' : ''), '">',
            $sv->label("rev_roundtag", "New PC reviews use round&nbsp; "),
            Ht::select("rev_roundtag", $selector, $sv->vstr("rev_roundtag"), $sv->sjs("rev_roundtag")),
            ' <span class="barsep">·</span> ',
            $sv->label("extrev_roundtag", "New external reviews use round&nbsp; "),
            Ht::select("extrev_roundtag", $extselector, $sv->vstr("extrev_roundtag"), $sv->sjs("extrev_roundtag")),
            '</div>';
    }


    static function print_pc(SettingValues $sv) {
        echo '<div class="has-fold fold2c">';
        echo '<div class="form-g has-fold foldo">';
        $sv->print_checkbox('pcrev_any', "PC members can review any submission", ["class" => "uich js-foldup"]);
        if ($sv->conf->setting("pcrev_any")
            && $sv->conf->check_track_sensitivity(Track::UNASSREV)) {
            echo '<p class="f-h fx">', $sv->setting_link("Current track settings", "tracks"), ' may restrict self-assigned reviews.</p>';
        }
        echo "</div>\n";


        $hint = "";
        if ($sv->conf->check_track_sensitivity(Track::VIEWREVID)) {
            $hint = '<p class="settings-ag f-h">' . $sv->setting_link("Current track settings", "tracks") . ' restrict reviewer name visibility.</p>';
        }
        $sv->print_radio_table("pc_seeblindrev", [0 => "Yes",
                1 => "Only after completing a review for the same submission"],
            'Can PC members see <strong>reviewer names<span class="fn2"> and comments</span></strong> except for conflicts?',
            ["after" => $hint]);


        $hint = "";
        if ($sv->conf->has_any_metareviews()) {
            $hint .= ' Metareviewers can always see associated reviews and reviewer names.';
        }
        if ($sv->conf->check_track_sensitivity(Track::VIEWREV)
            || $sv->conf->check_track_sensitivity(Track::VIEWALLREV)) {
            $hint .= ' ' . $sv->setting_link("Current track settings", "tracks") . ' restrict review visibility.';
        }
        if ($hint !== "") {
            $hint = '<p class="settings-ag f-h">' . ltrim($hint) . '</p>';
        }
        $sv->print_radio_table("pc_seeallrev", [
                Conf::PCSEEREV_YES => "Yes",
                Conf::PCSEEREV_UNLESSINCOMPLETE => "Yes, unless they haven’t completed an assigned review for the same submission",
                Conf::PCSEEREV_UNLESSANYINCOMPLETE => "Yes, after completing all their assigned reviews",
                Conf::PCSEEREV_IFCOMPLETE => "Only after completing a review for the same submission"
            ], 'Can PC members see <strong>review contents<span class="fx2"> and comments</span></strong> except for conflicts?',
            ["after" => $hint]);

        echo '<div class="form-nearby form-g">';
        $sv->print_checkbox("lead_seerev", "Discussion leads can always see submitted reviews and reviewer names");
        echo '</div>';


        echo '<div class="form-g">';
        $sv->print_checkbox('cmt_revid', "PC can see comments when reviews are anonymous", ["class" => "uich js-foldup", "data-fold-target" => "2", "hint_class" => "fx2"], "Commenter names are hidden when reviews are anonymous.");
        echo "</div></div>\n";
    }


    static function print_extrev_view(SettingValues $sv) {
        $sv->print_radio_table("extrev_view", [
                0 => "No",
                1 => "Yes, but they can’t see comments or reviewer names",
                2 => "Yes"
            ], 'Can external reviewers see reviews, comments, and eventual decisions for their assigned submissions, once they’ve completed a review?');
    }
    static function print_extrev_editdelegate(SettingValues $sv) {
        echo '<div id="foldpcrev_editdelegate" class="form-g has-fold',
            $sv->vstr("extrev_chairreq") >= 0 ? ' fold1o' : ' fold1c',
            '" data-fold1-values="0 1 2">';
        $sv->print_radio_table("extrev_chairreq", [-1 => "No",
                1 => "Yes, but administrators must approve all requests",
                2 => "Yes, but administrators must approve external reviewers with potential conflicts",
                0 => "Yes"
            ], "Can PC reviewers request external reviews?",
            ["item_class" => "uich js-foldup"]);
        echo '<div class="fx1">';
        // echo '<p>Secondary PC reviews can be delegated to external reviewers. When the external review is complete, the secondary PC reviewer need not complete a review of their own.</p>', "\n";

        $label3 = "Yes, and external reviews are visible only to their requesters";
        if ($sv->conf->fetch_ivalue("select exists (select * from PaperReview where reviewType=" . REVIEW_EXTERNAL . " and reviewSubmitted>0)")) {
            $label3 = '<label for="pcrev_editdelegate_3">' . $label3 . '</label><div class="settings-ap f-hx fx">Existing ' . Ht::link("submitted external reviews", $sv->conf->hoturl("search", ["q" => "re:ext:submitted"]), ["target" => "_new"]) . ' will remain visible to others.</div>';
        }
        $sv->print_radio_table("pcrev_editdelegate", [
                0 => "No",
                1 => "Yes, but external reviewers still own their reviews (requesters cannot adopt them)",
                2 => "Yes, and external reviews are hidden until requesters approve or adopt them",
                3 => $label3
            ], "Can PC members edit the external reviews they requested?",
            ["fold_values" => [3]]);
        echo "</div></div>\n";
    }
    static function print_extrev_requestmail(SettingValues $sv) {
        $t = $sv->expand_mail_template("requestreview", false);
        echo '<div id="foldmailbody_requestreview" class="form-g ',
            ($t == $sv->expand_mail_template("requestreview", true) ? "foldc" : "foldo"),
            '">';
        $sv->set_oldv("mailbody_requestreview", $t["body"]);
        echo '<div class="', $sv->control_class("mailbody_requestreview", "f-i"), '">',
            '<div class="f-c n">',
            '<a class="ui q js-foldup" href="">', expander(null, 0),
            '<label for="mailbody_requestreview">Mail template for external review requests</label></a>',
            '<span class="fx"> (<a href="', $sv->conf->hoturl("mail"), '">keywords</a> allowed; set to empty for default)</span></div>',
            $sv->textarea("mailbody_requestreview", ["class" => "text-monospace fx", "cols" => 80, "rows" => 20]);
        $sv->print_feedback_at("mailbody_requestreview");
        echo "</div></div>\n";
    }

    static function print_ratings(SettingValues $sv) {
        $sv->print_radio_table("rev_ratings", [
                REV_RATINGS_NONE => "No",
                REV_RATINGS_PC => "Yes, PC members can rate reviews",
                REV_RATINGS_PC_EXTERNAL => "Yes, PC members and external reviewers can rate reviews"
            ], 'Should HotCRP collect ratings of reviews?   <a class="hint" href="' . $sv->conf->hoturl("help", "t=revrate") . '">Learn more</a>');
    }

    static function crosscheck(SettingValues $sv) {
        $conf = $sv->conf;
        $errored = false;
        foreach ($conf->round_list() as $i => $rname) {
            foreach (Conf::$review_deadlines as $deadline) {
                if ($sv->has_interest("{$deadline}_{$i}")
                    && intval($sv->vstr("{$deadline}_{$i}")) > Conf::$now
                    && intval($sv->vstr("rev_open")) <= 0
                    && !$errored) {
                    $sv->warning_at("rev_open", "<0>A review deadline is set in the future, but reviews cannot be edited now. This is sometimes unintentional.");
                    $errored = true;
                    break;
                }
            }
        }

        if (($sv->has_interest("au_seerev") || $sv->has_interest("pcrev_soft_0"))
            && $conf->setting("au_seerev") != Conf::AUSEEREV_NO
            && $conf->setting("au_seerev") != Conf::AUSEEREV_TAGS
            && intval($sv->vstr("pcrev_soft_0")) > 0
            && Conf::$now < intval($sv->vstr("pcrev_soft_0"))
            && !$sv->has_error()) {
            $sv->warning_at(null, "<5>" . $sv->setting_link("Authors can see reviews and comments", "au_seerev") . " although it is before the " . $sv->setting_link("review deadline", "pcrev_soft_0") . ". This is sometimes unintentional.");
        }

        if (($sv->has_interest("rev_blind") || $sv->has_interest("extrev_view"))
            && $conf->setting("rev_blind") == Conf::BLIND_NEVER
            && $conf->setting("extrev_view") == 1) {
            $sv->warning_at("extrev_view", "<5>" . $sv->setting_link("Reviews aren’t blind", "rev_blind") . ", so external reviewers can see reviewer names and comments despite " . $sv->setting_link("your settings", "extrev_view") . ".");
        }

        if ($sv->has_interest("mailbody_requestreview")
            && $sv->vstr("mailbody_requestreview")
            && (strpos($sv->vstr("mailbody_requestreview"), "%LOGINURL%") !== false
                || strpos($sv->vstr("mailbody_requestreview"), "%LOGINURLPARTS%") !== false)) {
            $sv->warning_at("mailbody_requestreview", "<5>The <code>%LOGINURL%</code> and <code>%LOGINURLPARTS%</code> keywords should no longer be used in email templates.");
        }
    }
}


class Round_SettingParser extends SettingParser {
    private $rev_round_changes = array();

    function apply_req(SettingValues $sv, Si $si) {
        assert($si->name === "tag_rounds");
        $this->rev_round_changes = [];

        // count number of requested rounds
        $nreqround = 1;
        while ($sv->has_req("roundname_$nreqround")
               || $sv->has_req("deleteround_$nreqround")) {
            ++$nreqround;
        }

        // fix round names
        $roundnames = array_fill(0, $nreqround, ";");
        $roundlnames = [];
        for ($i = 0; $i < $nreqround; ++$i) {
            if ($sv->reqstr("deleteround_$i")) {
                if ($i !== 0) {
                    $this->rev_round_changes[$i] = 0;
                }
            } else if (($name = $sv->reqstr("roundname_$i")) !== null) {
                $name = trim($name);
                $lname = strtolower($name);
                if ($lname === "unnamed" || $lname === "default" || $lname === "n/a") {
                    $name = $lname = "";
                }
                if (isset($roundlnames[$lname])) {
                    $sv->error_at("roundname_$i", "<0>Round names must be distinct. Use bulk assignment to change existing reviews’ rounds.");
                    $sv->error_at("roundname_" . $roundlnames[$lname]);
                } else if ($name !== ""
                           && ($rerror = Conf::round_name_error($name))) {
                    $sv->error_at("roundname_$i", "<0>{$rerror}");
                } else {
                    $roundnames[$i] = $name;
                    $roundlnames[$lname] = $i;
                }
            }
        }

        // check for round transformations
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
                    $sv->save("{$dl}_{$i}", 0);
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
            $sv->request_write_lock("PaperReview", "ReviewRequest", "PaperReviewRefused");
            $sv->request_store_value($si);
        }
        return true;
    }

    function store_value(SettingValues $sv, Si $si) {
        if ($this->rev_round_changes) {
            $qx = "case";
            foreach ($this->rev_round_changes as $old => $new) {
                $qx .= " when reviewRound=$old then $new";
            }
            $qx .= " else reviewRound end";
            $sv->conf->qe_raw("update PaperReview set reviewRound=" . $qx);
            $sv->conf->qe_raw("update ReviewRequest set reviewRound=" . $qx);
            $sv->conf->qe_raw("update PaperReviewRefused set reviewRound=" . $qx);
        }
    }
}

class RoundSelector_SettingParser extends SettingParser {
    function apply_req(SettingValues $sv, Si $si) {
        $name = trim($sv->reqstr($si->name));
        $lname = strtolower($name);
        if ($lname === "(new round)" || $lname === "n/a") {
            $name = $lname = "default";
        }
        if ($lname === "default") {
            $sv->save($si, "");
        } else if ($lname === "" || $lname === "unnamed") {
            $sv->save($si, $si->name === "rev_roundtag" ? "" : "unnamed");
        } else if (!($err = Conf::round_name_error($lname))) {
            $sv->save($si, $name);
        } else {
            $sv->error_at($si->name, "<0>{$err} ($lname)");
        }
        return true;
    }
}

class ReviewDeadline_SettingParser extends SettingParser {
    function apply_req(SettingValues $sv, Si $si) {
        assert($sv->has_savedv("tag_rounds"));
        assert($si->part0 !== null);

        $rref = intval($si->part1);
        if ($sv->reqstr("deleteround_$rref")) {
            // setting already deleted by tag_rounds parsing
            return true;
        }

        $name = trim($sv->reqstr("roundname_$rref") ?? "");
        if (strcasecmp($name, "default") === 0
            || strcasecmp($name, "unnamed") === 0
            || strcasecmp($name, "n/a") === 0) {
            $name = "";
        }

        $rounds = explode(" ", strtolower($sv->newv("tag_rounds")));
        if ($name === "") {
            $rnum = 0;
        } else {
            $rnum = array_search(strtolower($name), $rounds);
            if ($rnum !== false) {
                ++$rnum;
            }
        }

        if (($v = $sv->base_parse_req($si)) !== null
            && $rnum !== false) {
            $sv->save("{$si->part0}{$rnum}", $v);
            if ($v > 0 && str_ends_with($si->part0, "hard_")) {
                $sv->check_date_before(substr($si->part0, 0, -5) . "soft_{$rnum}", $si->name, true);
            }
        }
    }
}
