<?php
// src/settings/s_reviews.php -- HotCRP settings > reviews page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Reviews_SettingRenderer {
    private static function echo_round($sv, $rnum, $nameval, $review_count, $deletable) {
        $rname = "roundname_$rnum";
        if ($sv->use_req() && $rnum !== '$')
            $nameval = (string) get($sv->req, $rname);
        $rname_si = $sv->si($rname);
        if ($nameval === "(new round)" || $rnum === '$')
            $rname_si->placeholder = "(new round)";
        $sv->set_oldv($rname, $nameval);

        echo '<div class="mg js-settings-review-round" data-review-round-number="', $rnum, '"><div>',
            $sv->label($rname, "Round"), ' &nbsp;',
            $sv->render_entry($rname);
        echo '<div class="inb" style="min-width:7em;margin-left:2em">';
        if ($rnum !== '$' && $review_count)
            echo '<a href="', hoturl("search", "q=" . urlencode("round:" . ($rnum ? $sv->conf->round_name($rnum) : "none"))), '">(', plural($review_count, "review"), ')</a>';
        echo '</div>';
        if ($deletable) {
            echo '<div class="inb" style="padding-left:2em">',
                Ht::hidden("deleteround_$rnum", "", ["data-default-value" => ""]),
                Ht::button("Delete round", ["class" => "btn js-settings-review-round-delete"]),
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
    echo '<div class="settings-g">';
    $sv->echo_checkbox("rev_open", "<b>Open site for reviewing</b>");
    $sv->echo_checkbox("cmt_always", "Allow comments even if reviewing is closed");
    echo "</div>\n";

    $sv->echo_radio_table("rev_blind", array(Conf::BLIND_ALWAYS => "Yes, reviews are anonymous",
                               Conf::BLIND_NEVER => "No, reviewer names are visible to authors",
                               Conf::BLIND_OPTIONAL => "Depends: reviewers decide whether to expose their names"),
        '<strong>Review anonymity:</strong> Are reviewer names hidden from authors?');


    // Deadlines
    echo "<h3 id=\"rounds\" class=\"settings g\">Deadlines &amp; rounds</h3>\n";
    echo '<p class="settingtext">Reviews are due by the deadline, but <em>cannot be modified</em> after the hard deadline. Most conferences don’t use hard deadlines for reviews.</p>';
    echo '<p class="f-h">', ($sv->type_hint("date") ? : ""), '</p>';

    $rounds = $sv->conf->round_list();
    if ($sv->use_req()) {
        for ($i = 1; isset($sv->req["roundname_$i"]); ++$i)
            $rounds[$i] = get($sv->req, "deleteround_$i") ? ";" : trim(get_s($sv->req, "roundname_$i"));
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

    $print_round0 = true;
    if ($round_value != "#0" && $extround_value != "#0"
        && (!$sv->use_req() || isset($sv->req["roundname_0"]))
        && !$sv->conf->round0_defined())
        $print_round0 = false;

    $selector = array();
    if ($print_round0)
        $selector["#0"] = "unnamed";
    for ($i = 1; $i < count($rounds); ++$i)
        if ($rounds[$i] !== ";")
            $selector["#$i"] = (object) array("label" => $rounds[$i], "id" => "rev_roundtag_$i");

    echo '<div id="roundtable">';
    $round_map = edb_map($sv->conf->ql("select reviewRound, count(*) from PaperReview group by reviewRound"));
    $num_printed = 0;
    for ($i = 0; $i < count($rounds); ++$i)
        if ($i ? $rounds[$i] !== ";" : $print_round0) {
            self::echo_round($sv, $i, $i ? $rounds[$i] : "", +get($round_map, $i), count($selector) !== 1);
            ++$num_printed;
        }
    echo '</div><div id="newround" class="hidden">';
    self::echo_round($sv, '$', "", "", true);
    echo '</div><div class="g"></div>';
    echo Ht::button("Add round", ["id" => "settings_review_round_add"]),
        ' &nbsp; <span class="hint"><a href="', hoturl("help", "t=revround"), '">What is this?</a></span>',
        Ht::hidden("oldroundcount", count($sv->conf->round_list())),
        Ht::hidden("has_rev_roundtag", 1), Ht::hidden("has_extrev_roundtag", 1);
    for ($i = 1; $i < count($rounds); ++$i)
        if ($rounds[$i] === ";")
            echo Ht::hidden("roundname_$i", "", array("id" => "roundname_$i")),
                Ht::hidden("deleteround_$i", 1, ["data-default-value" => "1"]);
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
        echo '<div class="settings-g has-fold foldo">';
        $sv->echo_checkbox('pcrev_any', "PC members can review any submission", ["class" => "uich js-foldup"]);
        if ($sv->conf->setting("pcrev_any")
            && $sv->conf->check_track_sensitivity(Track::UNASSREV))
            echo '<p class="f-h fx">', Ht::link("Current track settings", hoturl("settings", "group=tracks")), ' may restrict self-assigned reviews.</p>';
        echo "</div>\n";

        $hint = "";
        if ($sv->conf->has_any_metareviews())
            $hint .= ' Metareviewers can always see associated reviews and reviewer names.';
        if ($sv->conf->check_track_sensitivity(Track::VIEWREV)
            || $sv->conf->check_track_sensitivity(Track::VIEWALLREV))
            $hint .= ' ' . Ht::link("Current track settings", hoturl("settings", "group=tracks")) . ' may restrict review visibility.';
        if ($hint !== "")
            $hint = '<p class="settings-ag f-h">' . ltrim($hint) . '</p>';
        $sv->echo_radio_table("pc_seeallrev", array(Conf::PCSEEREV_YES => "Yes",
                                      Conf::PCSEEREV_UNLESSINCOMPLETE => "Yes, unless they haven’t completed an assigned review for the same submission",
                                      Conf::PCSEEREV_UNLESSANYINCOMPLETE => "Yes, after completing all their assigned reviews",
                                      Conf::PCSEEREV_IFCOMPLETE => ["Only after completing a review for the same submission", '<div class="f-hx fx">Discussion leads can also see reviews.</div>']),
            'Can PC members <strong>see all reviews<span class="fx2"> and comments</span></strong> except for conflicts?',
            $hint);


        $hint = "";
        if ($sv->conf->setting("pc_seeblindrev") == 0
            && $sv->conf->check_track_sensitivity(Track::VIEWREVID))
            $hint = '<p class="settings-ag f-h">' . Ht::link("Current track settings", hoturl("settings", "group=tracks")) . ' may restrict reviewer name visibility.</p>';
        $sv->echo_radio_table("pc_seeblindrev", array(0 => "Yes",
                                        1 => ["Only after completing a review for the same submission", '<div class="f-hx fx">Discussion leads can also see reviewer names.</div>']),
            'Can PC members see <strong><span class="fn2">comments and </span>reviewer names</strong> except for conflicts?',
            $hint);


        echo '<div class="settings-g">';
        $sv->echo_checkbox('cmt_revid', "PC can see comments when reviews are anonymous", ["class" => "uich js-foldup", "data-fold-target" => "2", "hint_class" => "fx2"], "Commenter names are hidden when reviews are anonymous.");
        echo "</div></div>\n";
    }


    static function render_external(SettingValues $sv) {
        echo '<p class="settingtext">Any PC reviewer can propose an external review. Secondary PC reviewers can delegate their reviews to an external reviewer; when the external review is complete, the PC reviewer need not complete a review of their own.</p>', "\n";
        $sv->echo_radio_table("extrev_chairreq", [0 => "No, external reviewers are contacted right away",
                1 => "Administrators must approve all external reviewers",
                2 => "Administrators must approve external reviewers with potential conflicts"],
                "Do external reviewers need administrator approval?");
        echo '<div id="foldpcrev_editdelegate" class="settings-g fold2o">';
        $sv->echo_checkbox("pcrev_editdelegate", "PC members can edit delegated external reviews (and other external reviews they requested)");
        Ht::stash_script('function pcrev_editdelegate_change() { fold("pcrev_editdelegate",!$$("cbpcrev_editdelegate").checked,2); } $("#cbpcrev_editdelegate").on("change", pcrev_editdelegate_change); $(pcrev_editdelegate_change)');
        $sv->echo_checkbox("extrev_approve", "Requesters must approve external reviews after they are submitted", ["item_class" => "fx2"]);
        echo "</div>\n";

        $t = $sv->expand_mail_template("requestreview", false);
        echo '<div id="foldmailbody_requestreview" class="settings-g ',
            ($t == $sv->expand_mail_template("requestreview", true) ? "foldc" : "foldo"),
            '">';
        echo '<div class="f-i"><div class="f-c n">',
            '<a class="ui q js-foldup" href="">', expander(null, 0),
            'Mail template for external review requests</a>',
            '<span class="fx"> (<a href="', hoturl("mail"), '">keywords</a> allowed; set to empty for default)</span></div>',
            '<textarea class="tt fx need-autogrow" name="mailbody_requestreview" cols="80" rows="20">', htmlspecialchars($t["body"]), "</textarea>",
            "</div></div>\n";

        $sv->echo_radio_table("extrev_view", array(2 => "Yes", 1 => "Yes, but they can’t see comments or reviewer names", 0 => "No"),
            'Can external reviewers see reviews and comments for their assigned submissions, once they’ve completed a review?');
    }

    static function render_ratings(SettingValues $sv) {
        $sv->echo_radio_table("rev_ratings", array(REV_RATINGS_PC => "Yes, PC members can rate reviews", REV_RATINGS_PC_EXTERNAL => "Yes, PC members and external reviewers can rate reviews", REV_RATINGS_NONE => "No"),
            'Should HotCRP collect ratings of reviews?   <a class="hint" href="' . hoturl("help", "t=revrate") . '">Learn more</a>');
    }

    static function crosscheck(SettingValues $sv) {
        global $Now;
        $errored = false;
        foreach ($sv->conf->round_list() as $i => $rname) {
            $suffix = $i ? "_$i" : "";
            foreach (Conf::$review_deadlines as $deadline)
                if ($sv->has_interest($deadline . $suffix)
                    && $sv->newv($deadline . $suffix) > $Now
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
    }
}


class Round_SettingParser extends SettingParser {
    private $rev_round_changes = array();

    function parse(SettingValues $sv, Si $si) {
        if (!isset($sv->req["rev_roundtag"])) {
            $sv->save("rev_roundtag", null);
            $sv->save("extrev_roundtag", null);
            return false;
        } else if ($si->name != "rev_roundtag")
            return false;
        // round names
        $roundnames = $roundnames_set = array();
        $roundname0 = $round_deleted = null;
        for ($i = 0;
             isset($sv->req["roundname_$i"]) || isset($sv->req["deleteround_$i"]) || !$i;
             ++$i) {
            $rname = trim(get_s($sv->req, "roundname_$i"));
            if ($rname === "(no name)" || $rname === "default" || $rname === "unnamed")
                $rname = "";
            if ((get($sv->req, "deleteround_$i") || $rname === "") && $i) {
                $roundnames[] = ";";
                if ($sv->conf->fetch_ivalue("select reviewId from PaperReview where reviewRound=$i limit 1"))
                    $this->rev_round_changes[] = array($i, 0);
                if ($round_deleted === null && !isset($sv->req["roundname_0"])
                    && $i < $sv->req["oldroundcount"])
                    $round_deleted = $i;
            } else if ($rname === "")
                /* ignore */;
            else if (($rerror = Conf::round_name_error($rname)))
                $sv->error_at("roundname_$i", $rerror);
            else if ($i == 0)
                $roundname0 = $rname;
            else if (get($roundnames_set, strtolower($rname))) {
                $roundnames[] = ";";
                $this->rev_round_changes[] = array($i, $roundnames_set[strtolower($rname)]);
            } else {
                $roundnames[] = $rname;
                $roundnames_set[strtolower($rname)] = $i;
            }
        }
        if ($roundname0 && !get($roundnames_set, strtolower($roundname0))) {
            $roundnames[] = $roundname0;
            $roundnames_set[strtolower($roundname0)] = count($roundnames);
        }
        if ($roundname0)
            array_unshift($this->rev_round_changes, array(0, $roundnames_set[strtolower($roundname0)]));

        // round deadlines
        foreach ($sv->conf->round_list() as $i => $rname) {
            $suffix = $i ? "_$i" : "";
            foreach (Conf::$review_deadlines as $k)
                $sv->save($k . $suffix, null);
        }
        $rtransform = array();
        if ($roundname0 && ($ri = $roundnames_set[strtolower($roundname0)])
            && !isset($sv->req["pcrev_soft_$ri"])) {
            $rtransform[0] = "_$ri";
            $rtransform[$ri] = false;
        }
        if ($round_deleted) {
            $rtransform[$round_deleted] = "";
            if (!isset($rtransform[0]))
                $rtransform[0] = false;
        }
        for ($i = 0; $i < count($roundnames) + 1; ++$i)
            if ((isset($rtransform[$i])
                 || ($i ? $roundnames[$i - 1] !== ";" : !isset($sv->req["deleteround_0"])))
                && get($rtransform, $i) !== false) {
                $isuffix = $i ? "_$i" : "";
                if (($osuffix = get($rtransform, $i)) === null)
                    $osuffix = $isuffix;
                $ndeadlines = 0;
                foreach (Conf::$review_deadlines as $k) {
                    $v = $sv->parse_value($sv->si($k . $isuffix));
                    $sv->save($k . $osuffix, $v <= 0 ? null : $v);
                    $ndeadlines += $v > 0;
                }
                if ($ndeadlines == 0 && $osuffix)
                    $sv->save("pcrev_soft$osuffix", 0);
                foreach (array("pcrev_", "extrev_") as $k) {
                    list($soft, $hard) = ["{$k}soft$osuffix", "{$k}hard$osuffix"];
                    list($softv, $hardv) = [$sv->savedv($soft), $sv->savedv($hard)];
                    if (!$softv && $hardv)
                        $sv->save($soft, $hardv);
                    else if ($hardv && $softv > $hardv) {
                        $desc = $i ? ", round " . htmlspecialchars($roundnames[$i - 1]) : "";
                        $sv->error_at($soft, $sv->si("{$k}soft")->title . $desc . ": Must come before " . $sv->si("{$k}hard")->title . ".");
                        $sv->error_at($hard);
                    }
                }
            }

        // round list (save after deadlines processing)
        while (count($roundnames) && $roundnames[count($roundnames) - 1] === ";")
            array_pop($roundnames);
        $sv->save("tag_rounds", join(" ", $roundnames));

        // default rounds
        array_unshift($roundnames, $roundname0);
        $sv->save("rev_roundtag", null);
        if (preg_match('/\A\#(\d+)\z/', trim($sv->req["rev_roundtag"]), $m)
            && ($rname = get($roundnames, intval($m[1]))) && $rname !== ";")
            $sv->save("rev_roundtag", $rname);
        if (isset($sv->req["extrev_roundtag"])) {
            $sv->save("extrev_roundtag", null);
            if (preg_match('/\A\#(\d+)\z/', trim($sv->req["extrev_roundtag"]), $m)
                && ($rname = get($roundnames, intval($m[1]))) !== ";")
                $sv->save("extrev_roundtag", $rname ? : "unnamed");
        }

        if (count($this->rev_round_changes)) {
            $sv->need_lock["PaperReview"] = true;
            return true;
        } else
            return false;
    }
    public function save(SettingValues $sv, Si $si) {
        // remove references to deleted rounds
        foreach ($this->rev_round_changes as $x)
            $sv->conf->qe_raw("update PaperReview set reviewRound=$x[1] where reviewRound=$x[0]");
    }
}
