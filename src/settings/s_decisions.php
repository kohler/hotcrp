<?php
// src/settings/s_decisions.php -- HotCRP settings > decisions page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SettingRenderer_Decisions extends SettingRenderer {
function render(SettingValues $sv) {
    echo "<h3 class=\"settings\">Review sharing and responses</h3>\n";
    echo "Can <b>authors see reviews and author-visible comments</b> for their papers?<br />";
    if ($sv->conf->setting("resp_active"))
        $no_text = "No, unless responses are open";
    else
        $no_text = "No";
    if (!$sv->conf->setting("au_seerev", 0)
        && $sv->conf->timeAuthorViewReviews())
        $no_text .= '<div class="hint">Authors are currently able to see reviews since responses are open.</div>';
    $opts = array(Conf::AUSEEREV_NO => $no_text,
                  Conf::AUSEEREV_YES => "Yes");
    if ($sv->newv("au_seerev") == Conf::AUSEEREV_UNLESSINCOMPLETE
        && !$sv->conf->opt("allow_auseerev_unlessincomplete"))
        $sv->conf->save_setting("opt.allow_auseerev_unlessincomplete", 1);
    if ($sv->conf->opt("allow_auseerev_unlessincomplete"))
        $opts[Conf::AUSEEREV_UNLESSINCOMPLETE] = "Yes, after completing any assigned reviews for other papers";
    $opts[Conf::AUSEEREV_TAGS] = "Yes, for papers with any of these tags:&nbsp; " . $sv->render_entry("tag_au_seerev", ["onfocus" => "$('#au_seerev_" . Conf::AUSEEREV_TAGS . "').click()"]);
    $sv->echo_radio_table("au_seerev", $opts);
    echo Ht::hidden("has_tag_au_seerev", 1);

    // Authors' response
    echo '<div class="g"></div><table id="foldauresp" class="fold2o">';
    $sv->echo_checkbox_row('resp_active', "<b>Collect authors’ responses to the reviews<span class='fx2'>:</span></b>", "resp_active_change()");
    Ht::stash_script('function resp_active_change() { fold("auresp",!$$("cbresp_active").checked,2); } $(resp_active_change);');
    echo '<tr class="fx2"><td></td><td><div id="auresparea">',
        Ht::hidden("has_resp_rounds", 1);

    // Response rounds
    if ($sv->use_req()) {
        $rrounds = array(1);
        for ($i = 1; isset($sv->req["resp_roundname_$i"]); ++$i)
            $rrounds[$i] = $sv->req["resp_roundname_$i"];
    } else
        $rrounds = $sv->conf->resp_round_list();
    $rrounds["n"] = "";
    foreach ($rrounds as $i => $rname) {
        $isuf = $i ? "_$i" : "";
        $rname_si = $sv->si("resp_roundname$isuf");
        if (!$i) {
            $rname = $rname == "1" ? "none" : $rname;
            $rname_si->placeholder = "none";
        }
        $sv->set_oldv("resp_roundname$isuf", $rname);

        echo '<div id="response', $isuf;
        if ($i)
            echo '" style="padding-top:1em';
        if ($i === "n")
            echo ';display:none';
        echo '"><table><tbody class="secondary-settings">';
        $sv->echo_entry_row("resp_roundname$isuf", "Response name");
        if ($sv->curv("resp_open$isuf") === 1 && ($x = $sv->curv("resp_done$isuf")))
            $sv->conf->settings["resp_open$isuf"] = $x - 7 * 86400;
        $sv->echo_entry_row("resp_open$isuf", "Start time");
        $sv->echo_entry_row("resp_done$isuf", "Hard deadline");
        $sv->echo_entry_row("resp_grace$isuf", "Grace period");
        $sv->echo_entry_row("resp_words$isuf", "Word limit", $i ? null : "This is a soft limit: authors may submit longer responses. 0 means no limit.");
        echo '</tbody></table><div style="padding-top:4px">';
        $sv->echo_message_minor("msg.resp_instrux$isuf", "Instructions");
        echo '</div></div>', "\n";
    }

    echo '</div><div style="padding-top:1em">',
        '<button type="button" onclick="settings_add_resp_round()">Add response round</button>',
        '</div></td></tr></table>';

    echo "<h3 class=\"settings g\">Decisions</h3>\n";

    echo "Who can see paper <b>decisions</b> (accept/reject)?<br />\n";
    $sv->echo_radio_table("seedec", array(Conf::SEEDEC_ADMIN => "Only administrators",
                            Conf::SEEDEC_NCREV => "Reviewers and non-conflicted PC members",
                            Conf::SEEDEC_REV => "Reviewers and <em>all</em> PC members",
                            Conf::SEEDEC_ALL => "<b>Authors</b>, reviewers, and all PC members (and reviewers can see accepted papers’ author lists)"));

    echo "<div class='g'></div>\n";
    echo "<table>\n";
    $decs = $sv->conf->decision_map();
    krsort($decs);

    // count papers per decision
    $decs_pcount = array();
    $result = $sv->conf->qe_raw("select outcome, count(*) from Paper where timeSubmitted>0 group by outcome");
    while (($row = edb_row($result)))
        $decs_pcount[$row[0]] = $row[1];

    // real decisions
    $n_real_decs = 0;
    foreach ($decs as $k => $v)
        $n_real_decs += ($k ? 1 : 0);
    $caption = "<td class='lcaption' rowspan='$n_real_decs'>Current decision types</td>";
    foreach ($decs as $k => $v)
        if ($k) {
            if ($sv->use_req())
                $v = defval($sv->req, "dec$k", $v);
            echo "<tr>", $caption, '<td class="lentry nw">',
                Ht::entry("dec$k", $v, array("size" => 35)),
                " &nbsp; ", ($k > 0 ? "Accept class" : "Reject class"), "</td>";
            if (isset($decs_pcount[$k]) && $decs_pcount[$k])
                echo '<td class="lentry nw">', plural($decs_pcount[$k], "paper"), "</td>";
            echo "</tr>\n";
            $caption = "";
        }

    // new decision
    $v = "";
    $vclass = 1;
    if ($sv->use_req()) {
        $v = defval($sv->req, "decn", $v);
        $vclass = defval($sv->req, "dtypn", $vclass);
    }
    echo '<tr><td class="lcaption">',
        $sv->label("decn", "New decision type"),
        '<br /></td>',
        '<td class="lentry nw">',
        Ht::hidden("has_decisions", 1),
        Ht::entry("decn", $v, array("id" => "decn", "size" => 35)), ' &nbsp; ',
        Ht::select("dtypn", array(1 => "Accept class", -1 => "Reject class"), $vclass),
        "<br /><small>Examples: “Accepted as short paper”, “Early reject”</small>",
        "</td></tr>";
    if ($sv->has_error_at("decn"))
        echo '<tr><td></td><td class="lentry nw">',
            Ht::checkbox("decn_confirm", 1, false),
            '&nbsp;<span class="error">', Ht::label("Confirm"), "</span></td></tr>";
    echo "</table>\n";

    // Final versions
    echo "<h3 id=\"finalversions\" class=\"settings g\">Final versions</h3>\n";
    $sv->echo_messages_near("final_open");
    echo '<div class="fold2o" data-fold="true">';
    echo '<table>';
    $sv->echo_checkbox_row('final_open', '<b>Collect final versions of accepted papers<span class="fx2">:</span></b>', "void foldup(this,event,{f:'c'})");
    echo '<tr class="fx2"><td></td><td><table><tbody class="secondary-settings">';
    $sv->echo_entry_row("final_soft", "Deadline");
    $sv->echo_entry_row("final_done", "Hard deadline");
    $sv->echo_entry_row("final_grace", "Grace period");
    echo "</tbody></table><div class='g'></div>";
    $sv->echo_message_minor("msg.finalsubmit", "Instructions");
    echo '<div class="g"></div>';
    BanalSettings::render("_m1", $sv);
    echo "</td></tr></table>",
        "<p class=\"settingtext\">To collect <em>multiple</em> final versions, such as one in 9pt and one in 11pt, add “Alternate final version” options via <a href='", hoturl("settings", "group=opt"), "'>Settings &gt; Submission options</a>.</p>",
        "</div>\n\n";
    Ht::stash_script("foldup(\$\$('cbfinal_open'),null,{f:\"c\"})");
}

    function crosscheck(SettingValues $sv) {
        global $Now;

        if ($sv->has_interest("final_open")
            && $sv->newv("final_open")
            && ($sv->newv("final_soft") || $sv->newv("final_done"))
            && (!$sv->newv("final_done") || $sv->newv("final_done") > $Now)
            && $sv->newv("seedec") != Conf::SEEDEC_ALL)
            $sv->warning_at(null, "The system is set to collect final versions, but authors cannot submit final versions until they know their papers have been accepted. You may want to update the the “Who can see paper decisions” setting.");

        if ($sv->has_interest("seedec")
            && $sv->newv("seedec") == Conf::SEEDEC_ALL
            && $sv->newv("au_seerev") == Conf::AUSEEREV_NO)
            $sv->warning_at(null, "Authors can see decisions, but not reviews. This is sometimes unintentional.");

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


class Decision_SettingParser extends SettingParser {
    public function parse(SettingValues $sv, Si $si) {
        $dec_revmap = array();
        foreach ($sv->req as $k => &$dname)
            if (str_starts_with($k, "dec")
                && ($k === "decn" || ($dnum = cvtint(substr($k, 3), 0)))
                && ($k !== "decn" || trim($dname) !== "")) {
                $dname = simplify_whitespace($dname);
                if ($dname === "")
                    /* remove decision */;
                else if (($derror = Conf::decision_name_error($dname)))
                    $sv->error_at($k, htmlspecialchars($derror));
                else if (isset($dec_revmap[strtolower($dname)]))
                    $sv->error_at($k, "Decision name “{$dname}” was already used.");
                else
                    $dec_revmap[strtolower($dname)] = true;
            }
        unset($dname);

        if (get($sv->req, "decn") && !get($sv->req, "decn_confirm")) {
            $delta = (defval($sv->req, "dtypn", 1) > 0 ? 1 : -1);
            $match_accept = (stripos($sv->req["decn"], "accept") !== false);
            $match_reject = (stripos($sv->req["decn"], "reject") !== false);
            if ($delta > 0 && $match_reject)
                $sv->error_at("decn", "You are trying to add an Accept-class decision that has “reject” in its name, which is usually a mistake.  To add the decision anyway, check the “Confirm” box and try again.");
            else if ($delta < 0 && $match_accept)
                $sv->error_at("decn", "You are trying to add a Reject-class decision that has “accept” in its name, which is usually a mistake.  To add the decision anyway, check the “Confirm” box and try again.");
        }

        $sv->need_lock["Paper"] = true;
        return true;
    }

    public function save(SettingValues $sv, Si $si) {
        // mark all used decisions
        $decs = $sv->conf->decision_map();
        $update = false;
        foreach ($sv->req as $k => $v)
            if (str_starts_with($k, "dec") && ($k = cvtint(substr($k, 3), 0))) {
                if ($v == "") {
                    $sv->conf->qe_raw("update Paper set outcome=0 where outcome=$k");
                    unset($decs[$k]);
                    $update = true;
                } else if ($v != $decs[$k]) {
                    $decs[$k] = $v;
                    $update = true;
                }
            }

        if (defval($sv->req, "decn", "") != "") {
            $delta = (defval($sv->req, "dtypn", 1) > 0 ? 1 : -1);
            for ($k = $delta; isset($decs[$k]); $k += $delta)
                /* skip */;
            $decs[$k] = $sv->req["decn"];
            $update = true;
        }

        if ($update)
            $sv->save("outcome_map", json_encode($decs));
    }
}

class RespRound_SettingParser extends SettingParser {
    function parse(SettingValues $sv, Si $si) {
        if (!$sv->newv("resp_active"))
            return false;
        $old_roundnames = $sv->conf->resp_round_list();
        $roundnames = array(1);
        $roundnames_set = array();

        if (isset($sv->req["resp_roundname"])) {
            $rname = trim(get_s($sv->req, "resp_roundname"));
            if ($rname === "" || $rname === "none" || $rname === "1")
                /* do nothing */;
            else if (($rerror = Conf::resp_round_name_error($rname)))
                $sv->error_at("resp_roundname", $rerror);
            else {
                $roundnames[0] = $rname;
                $roundnames_set[strtolower($rname)] = 0;
            }
        }

        for ($i = 1; isset($sv->req["resp_roundname_$i"]); ++$i) {
            $rname = trim(get_s($sv->req, "resp_roundname_$i"));
            if ($rname === "" && get($old_roundnames, $i))
                $rname = $old_roundnames[$i];
            if ($rname === "")
                continue;
            else if (($rerror = Conf::resp_round_name_error($rname)))
                $sv->error_at("resp_roundname_$i", $rerror);
            else if (get($roundnames_set, strtolower($rname)) !== null)
                $sv->error_at("resp_roundname_$i", "Response round name “" . htmlspecialchars($rname) . "” has already been used.");
            else {
                $roundnames[] = $rname;
                $roundnames_set[strtolower($rname)] = $i;
            }
        }

        foreach ($roundnames_set as $i) {
            $isuf = $i ? "_$i" : "";
            if (($v = parse_value($sv, Si::get("resp_open$isuf"))) !== null)
                $sv->save("resp_open$isuf", $v <= 0 ? null : $v);
            if (($v = parse_value($sv, Si::get("resp_done$isuf"))) !== null)
                $sv->save("resp_done$isuf", $v <= 0 ? null : $v);
            if (($v = parse_value($sv, Si::get("resp_grace$isuf"))) !== null)
                $sv->save("resp_grace$isuf", $v <= 0 ? null : $v);
            if (($v = parse_value($sv, Si::get("resp_words$isuf"))) !== null)
                $sv->save("resp_words$isuf", $v < 0 ? null : $v);
            if (($v = parse_value($sv, Si::get("msg.resp_instrux$isuf"))) !== null)
                $sv->save("msg.resp_instrux$isuf", $v);
        }

        if (count($roundnames) > 1 || $roundnames[0] !== 1)
            $sv->save("resp_rounds", join(" ", $roundnames));
        else
            $sv->save("resp_rounds", null);
        return false;
    }
}


SettingGroup::register("decisions", "Decisions", 800, new SettingRenderer_Decisions);
SettingGroup::register_synonym("dec", "decisions");
