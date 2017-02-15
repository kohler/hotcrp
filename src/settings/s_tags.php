<?php
// src/settings/s_tags.php -- HotCRP settings > tags page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SettingRenderer_Tags extends SettingRenderer {
    private function do_track_permission($sv, $type, $question, $tnum, $thistrack) {
        $tclass = $ttag = "";
        if ($sv->use_req()) {
            $tclass = defval($sv->req, "${type}_track$tnum", "");
            $ttag = defval($sv->req, "${type}tag_track$tnum", "");
        } else if ($thistrack && get($thistrack, $type)) {
            if ($thistrack->$type == "+none")
                $tclass = "none";
            else {
                $tclass = substr($thistrack->$type, 0, 1);
                $ttag = substr($thistrack->$type, 1);
            }
        }

        $perm = ["" => "Whole PC", "+" => "PC members with tag", "-" => "PC members without tag", "none" => "Administrators only"];
        if ($type === "admin") {
            $perm[""] = (object) ["label" => "Whole PC", "disabled" => true];
            if ($tclass === "")
                $tclass = "none";
        }

        $hint = "";
        if (is_array($question))
            list($question, $hint) = [$question[0], '<p class="hint" style="margin:0;max-width:480px">' . $question[1] . '</p>'];

        echo "<tr data-fold=\"true\" class=\"fold", ($tclass == "" || $tclass == "none" ? "c" : "o"), "\">";
        if ($type === "viewtracker")
            echo "<td class=\"lxcaption\" colspan=\"2\" style=\"padding-top:0.5em\">";
        else
            echo "<td style=\"width:2em\"></td><td class=\"lxcaption\">";
        echo $sv->label(["{$type}_track$tnum", "{$type}tag_track$tnum"],
                        $question, "{$type}_track$tnum"),
            "</td><td>",
            Ht::select("{$type}_track$tnum", $perm, $tclass,
                       $sv->sjs("{$type}_track$tnum", array("onchange" => "void foldup(this,event,{f:this.selectedIndex==0||this.selectedIndex==3})"))),
            " &nbsp;</td><td style=\"min-width:120px\">",
            Ht::entry("${type}tag_track$tnum", $ttag,
                      $sv->sjs("{$type}tag_track$tnum", array("class" => "fx", "placeholder" => "(tag)"))),
            "</td></tr>";
        if ($hint)
            echo "<tr><td></td><td colspan=\"3\" style=\"padding-bottom:2px\">", $hint, "</td></tr>";
    }

    private function do_track($sv, $trackname, $tnum) {
        echo "<div id=\"trackgroup$tnum\"",
            ($tnum ? "" : " style=\"display:none\""),
            "><table style=\"margin-bottom:0.5em\">";
        echo "<tr><td colspan=\"3\" style=\"padding-bottom:3px\">";
        if ($trackname === "_")
            echo "For papers not on other tracks:", Ht::hidden("name_track$tnum", "_");
        else
            echo $sv->label("name_track$tnum", "For papers with tag &nbsp;"),
                Ht::entry("name_track$tnum", $trackname, $sv->sjs("name_track$tnum", array("placeholder" => "(tag)"))), ":";
        echo "</td></tr>\n";

        $t = $sv->conf->setting_json("tracks");
        $t = $t && $trackname !== "" ? get($t, $trackname) : null;
        $this->do_track_permission($sv, "view", "Who can see these papers?", $tnum, $t);
        $this->do_track_permission($sv, "viewpdf", ["Who can see PDFs?", "Assigned reviewers can always see PDFs."], $tnum, $t);
        $this->do_track_permission($sv, "viewrev", "Who can see reviews?", $tnum, $t);
        $hint = "";
        if ($sv->conf->setting("pc_seeblindrev"))
            $hint = "Regardless of this setting, PC members can’t see reviewer names until they’ve completed a review for the same paper (<a href=\"" . hoturl("settings", "group=reviews") . "\">Settings &gt; Reviews &gt; Visibility</a>).";
        $this->do_track_permission($sv, "viewrevid", ["Who can see reviewer names?", $hint], $tnum, $t);
        $this->do_track_permission($sv, "assrev", "Who can be assigned a review?", $tnum, $t);
        $this->do_track_permission($sv, "unassrev", "Who can self-assign a review?", $tnum, $t);
        $this->do_track_permission($sv, "admin", "Who can administer these papers?", $tnum, $t);
        if ($trackname === "_")
            $this->do_track_permission($sv, "viewtracker", "Who can see the <a href=\"" . hoturl("help", "t=chair#meeting") . "\">meeting tracker</a>?", $tnum, $t);
        echo "</table></div>\n\n";
    }

function render(SettingValues $sv) {
    $dt_renderer = function ($tl) {
        return join(" ", array_map(function ($t) { return $t->tag; },
                                   array_filter($tl, function ($t) { return !$t->pattern_instance; })));
    };

    // Tags
    $tagger = new Tagger;
    $tagmap = $sv->conf->tags();
    echo "<h3 class=\"settings\">Tags</h3>\n";
    echo "<table><tbody class=\"secondary-settings\">";
    $sv->set_oldv("tag_chair", $dt_renderer($tagmap->filter("chair")));
    $sv->echo_entry_row("tag_chair", "Chair-only tags", "PC members can view these tags, but only administrators can change them.", ["class" => "need-tagcompletion"]);

    $sv->set_oldv("tag_sitewide", $dt_renderer($tagmap->filter("sitewide")));
    if ($sv->newv("tag_sitewide") || $sv->conf->has_any_manager())
        $sv->echo_entry_row("tag_sitewide", "Site-wide tags", "Administrators can view and change these tags for every paper.", ["class" => "need-tagcompletion"]);

    $sv->set_oldv("tag_approval", $dt_renderer($tagmap->filter("approval")));
    $sv->echo_entry_row("tag_approval", "Approval voting tags", "<a href=\"" . hoturl("help", "t=votetags") . "\">What is this?</a>", ["class" => "need-tagcompletion"]);

    $x = [];
    foreach ($tagmap->filter("vote") as $t)
        $x[] = "{$t->tag}#{$t->vote}";
    $sv->set_oldv("tag_vote", join(" ", $x));
    $sv->echo_entry_row("tag_vote", "Allotment voting tags", "“vote#10” declares an allotment of 10 votes per PC member. <span class=\"barsep\">·</span> <a href=\"" . hoturl("help", "t=votetags") . "\">What is this?</a>", ["class" => "need-tagcompletion"]);

    $sv->set_oldv("tag_rank", $sv->conf->setting_data("tag_rank", ""));
    $sv->echo_entry_row("tag_rank", "Ranking tag", "The <a href='" . hoturl("offline") . "'>offline reviewing page</a> will expose support for uploading rankings by this tag. <span class='barsep'>·</span> <a href='" . hoturl("help", "t=ranking") . "'>What is this?</a>");
    echo "</tbody></table>";

    echo "<div class='g'></div>\n";
    $sv->echo_checkbox('tag_seeall', "PC can see tags for conflicted papers");

    $tag_color_data = $sv->conf->setting_data("tag_color", "");
    $tag_colors_rows = array();
    foreach (explode("|", TagInfo::BASIC_COLORS) as $k) {
        preg_match_all("{\\b(\\S+)=$k\\b}", $tag_color_data, $m);
        $sv->set_oldv("tag_color_$k", join(" ", get($m, 1, [])));
        $tag_colors_rows[] = "<tr class=\"{$k}tag\"><td class=\"lxcaption\"></td>"
            . "<td class=\"lxcaption taghl\">$k</td>"
            . "<td class=\"lentry\" style=\"font-size:10.5pt\">" . $sv->render_entry("tag_color_$k", ["class" => "need-tagcompletion"]) . "</td></tr>"; /* MAINSIZE */
    }

    $tag_badge_data = $sv->conf->setting_data("tag_badge", "");
    foreach (["normal" => "black badge", "red" => "red badge",
              "yellow" => "yellow badge", "green" => "green badge",
              "blue" => "blue badge", "white" => "white badge",
              "pink" => "pink badge", "gray" => "gray badge"]
             as $k => $desc) {
        preg_match_all("{\\b(\\S+)=$k\\b}", $tag_badge_data, $m);
        $sv->set_oldv("tag_badge_$k", join(" ", get($m, 1, [])));
        $tag_colors_rows[] = "<tr><td class=\"lxcaption\"></td>"
            . "<td class=\"lxcaption\"><span class=\"badge {$k}badge\" style=\"margin:0\">$desc</span></td>"
            . "<td class=\"lentry\" style=\"font-size:10.5pt\">" . $sv->render_entry("tag_badge_$k", ["class" => "need-tagcompletion"]) . "</td></tr>"; /* MAINSIZE */
    }

    echo Ht::hidden("has_tag_color", 1), Ht::hidden("has_tag_badge", 1),
        '<h3 class="settings g">Styles and colors</h3>',
        "<div class='hint'>Papers and PC members tagged with a style name, or with one of the associated tags, will appear in that style in lists.</div>",
        "<div class='smg'></div>",
        "<table id='foldtag_color'><tr><th colspan='2'>Style name</th><th>Tags</th></tr>",
        join("", $tag_colors_rows), "</table>\n";


    echo '<h3 class="settings g">Tracks</h3>', "\n";
    echo "<div class='hint'>Tracks control the PC members allowed to view or review different sets of papers. <span class='barsep'>·</span> <a href=\"" . hoturl("help", "t=tracks") . "\">What is this?</a></div>",
        Ht::hidden("has_tracks", 1),
        "<div class=\"smg\"></div>\n";
    $this->do_track($sv, "", 0);
    $tracknum = 2;
    $trackj = $sv->conf->setting_json("tracks") ? : (object) array();
    // existing tracks
    foreach ($trackj as $trackname => $x)
        if ($trackname !== "_") {
            $this->do_track($sv, $trackname, $tracknum);
            ++$tracknum;
        }
    // new tracks (if error prevented saving)
    if ($sv->use_req())
        for ($i = 1; isset($sv->req["name_track$i"]); ++$i) {
            $trackname = trim($sv->req["name_track$i"]);
            if (!isset($trackj->$trackname)) {
                $this->do_track($sv, $trackname, $tracknum);
                ++$tracknum;
            }
        }
    // catchall track
    $this->do_track($sv, "_", 1);
    echo Ht::button("Add track", array("onclick" => "settings_add_track()"));

    Ht::stash_script('suggest($(".need-tagcompletion"), taghelp_tset)');
}

    function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("tracks")
            && $sv->newv("tracks")) {
            $tracks = json_decode($sv->newv("tracks"), true);
            $tracknum = 2;
            foreach ($tracks as $trackname => $t) {
                $unassrev = get($t, "unassrev");
                if (get($t, "viewpdf") && $t["viewpdf"] !== $unassrev
                    && $unassrev !== "+none" && $t["viewpdf"] !== get($t, "view")) {
                    $tnum = ($trackname === "_" ? 1 : $tnum);
                    $tdesc = ($trackname === "_" ? "Default track" : "Track “{$trackname}”");
                    $sv->warning_at("unassrev_track$tnum", "$tdesc: Generally, a track that restricts who can see PDFs should restrict who can self-assign papers in the same way.");
                }
                $tracknum += ($trackname === "_" ? 0 : 1);
            }
        }
    }
}


class Tag_SettingParser extends SettingParser {
    private $tagger;
    public function __construct() {
        $this->tagger = new Tagger;
    }
    private function parse_list(SettingValues $sv, Si $si, $checkf, $min_idx) {
        $ts = array();
        foreach (preg_split('/\s+/', $sv->req[$si->name]) as $t)
            if ($t !== "" && ($tx = $this->tagger->check($t, $checkf))) {
                list($tag, $idx) = TagInfo::unpack($tx);
                if ($min_idx)
                    $tx = $tag . "#" . max($min_idx, (float) $idx);
                $ts[$tag] = $tx;
            } else if ($t !== "")
                $sv->error_at($si->name, $si->short_description . ": " . $this->tagger->error_html);
        return array_values($ts);
    }
    public function parse(SettingValues $sv, Si $si) {
        if ($si->name == "tag_chair" && isset($sv->req["tag_chair"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE | Tagger::ALLOWSTAR, false);
            $sv->update($si->name, join(" ", $ts));
        }

        if ($si->name == "tag_sitewide" && isset($sv->req["tag_sitewide"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE | Tagger::ALLOWSTAR, false);
            $sv->update($si->name, join(" ", $ts));
        }

        if ($si->name == "tag_vote" && isset($sv->req["tag_vote"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR, 1);
            if ($sv->update("tag_vote", join(" ", $ts)))
                $sv->need_lock["PaperTag"] = true;
        }

        if ($si->name == "tag_approval" && isset($sv->req["tag_approval"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false);
            if ($sv->update("tag_approval", join(" ", $ts)))
                $sv->need_lock["PaperTag"] = true;
        }

        if ($si->name == "tag_rank" && isset($sv->req["tag_rank"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false);
            if (count($ts) > 1)
                $sv->error_at("tag_rank", "At most one rank tag is currently supported.");
            else
                $sv->update("tag_rank", join(" ", $ts));
        }

        if ($si->name == "tag_color") {
            $ts = array();
            foreach (explode("|", TagInfo::BASIC_COLORS) as $k)
                if (isset($sv->req["tag_color_$k"])) {
                    foreach ($this->parse_list($sv, $sv->si("tag_color_$k"), Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE | Tagger::ALLOWSTAR, false) as $t)
                        $ts[] = $t . "=" . $k;
                }
            $sv->update("tag_color", join(" ", $ts));
        }

        if ($si->name == "tag_badge") {
            $ts = array();
            foreach (explode("|", TagInfo::BASIC_BADGES) as $k)
                if (isset($sv->req["tag_badge_$k"])) {
                    foreach ($this->parse_list($sv, $sv->si("tag_badge_$k"), Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE | Tagger::ALLOWSTAR, false) as $t)
                        $ts[] = $t . "=" . $k;
                }
            $sv->update("tag_badge", join(" ", $ts));
        }

        if ($si->name == "tag_au_seerev" && isset($sv->req["tag_au_seerev"])) {
            $ts = $this->parse_list($sv, $si, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE, false);
            $sv->update("tag_au_seerev", join(" ", $ts));
        }

        return true;
    }

    public function save(SettingValues $sv, Si $si) {
        if ($si->name == "tag_vote" && $sv->has_savedv("tag_vote")) {
            // check allotments
            $pcm = $sv->conf->pc_members();
            foreach (preg_split('/\s+/', $sv->savedv("tag_vote")) as $t) {
                if ($t === "")
                    continue;
                $base = substr($t, 0, strpos($t, "#"));
                $allotment = substr($t, strlen($base) + 1);
                $sqlbase = sqlq_for_like($base);

                $result = $sv->conf->q("select paperId, tag, tagIndex from PaperTag where tag like '%~{$sqlbase}'");
                $pvals = array();
                $cvals = array();
                $negative = false;
                while (($row = edb_row($result))) {
                    $who = substr($row[1], 0, strpos($row[1], "~"));
                    if ($row[2] < 0) {
                        $sv->error_at(null, "Removed " . Text::user_html($pcm[$who]) . "’s negative “{$base}” vote for paper #$row[0].");
                        $negative = true;
                    } else {
                        $pvals[$row[0]] = defval($pvals, $row[0], 0) + $row[2];
                        $cvals[$who] = defval($cvals, $who, 0) + $row[2];
                    }
                }

                foreach ($cvals as $who => $what)
                    if ($what > $allotment)
                        $sv->error_at("tag_vote", Text::user_html($pcm[$who]) . " already has more than $allotment votes for tag “{$base}”.");

                $q = ($negative ? " or (tag like '%~{$sqlbase}' and tagIndex<0)" : "");
                $sv->conf->qe_raw("delete from PaperTag where tag='" . sqlq($base) . "'$q");

                $q = array();
                foreach ($pvals as $pid => $what)
                    $q[] = "($pid, '" . sqlq($base) . "', $what)";
                if (count($q) > 0)
                    $sv->conf->qe_raw("insert into PaperTag values " . join(", ", $q));
            }
        }

        if ($si->name == "tag_approval" && $sv->has_savedv("tag_approval")) {
            $pcm = $sv->conf->pc_members();
            foreach (preg_split('/\s+/', $sv->savedv("tag_approval")) as $t) {
                if ($t === "")
                    continue;
                $result = $sv->conf->q_raw("select paperId, tag, tagIndex from PaperTag where tag like '%~" . sqlq_for_like($t) . "'");
                $pvals = array();
                $negative = false;
                while (($row = edb_row($result))) {
                    $who = substr($row[1], 0, strpos($row[1], "~"));
                    if ($row[2] < 0) {
                        $sv->error_at(null, "Removed " . Text::user_html($pcm[$who]) . "’s negative “{$t}” approval vote for paper #$row[0].");
                        $negative = true;
                    } else
                        $pvals[$row[0]] = defval($pvals, $row[0], 0) + 1;
                }

                $q = ($negative ? " or (tag like '%~" . sqlq_for_like($t) . "' and tagIndex<0)" : "");
                $sv->conf->qe_raw("delete from PaperTag where tag='" . sqlq($t) . "'$q");

                $q = array();
                foreach ($pvals as $pid => $what)
                    $q[] = "($pid, '" . sqlq($t) . "', $what)";
                if (count($q) > 0)
                    $sv->conf->qe_raw("insert into PaperTag values " . join(", ", $q));
            }
        }

        $sv->conf->invalidate_caches(["taginfo" => true]);
    }
}


class Track_SettingParser extends SettingParser {
    public function parse(SettingValues $sv, Si $si) {
        $tagger = new Tagger;
        $tracks = (object) array();
        $missing_tags = false;
        for ($i = 1; isset($sv->req["name_track$i"]); ++$i) {
            $trackname = trim($sv->req["name_track$i"]);
            if ($trackname === "" || $trackname === "(tag)")
                continue;
            else if (!$tagger->check($trackname, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE)
                     || ($trackname === "_" && $i != 1)) {
                if ($trackname !== "_")
                    $sv->error_at("name_track$i", "Track name: " . $tagger->error_html);
                else
                    $sv->error_at("name_track$i", "Track name “_” is reserved.");
                $sv->error_at("tracks");
                continue;
            }
            $t = (object) array();
            foreach (Track::$map as $type => $value)
                if (($ttype = get($sv->req, "${type}_track$i", "")) === "+"
                    || $ttype === "-") {
                    $ttag = trim(get($sv->req, "${type}tag_track$i", ""));
                    if ($ttag === "" || $ttag === "(tag)") {
                        $sv->error_at("{$type}_track$i", "Tag missing for track setting.");
                        $sv->error_at("tracks");
                    } else if (($ttype == "+" && strcasecmp($ttag, "none") == 0)
                               || $tagger->check($ttag, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE))
                        $t->$type = $ttype . $ttag;
                    else {
                        $sv->error_at("{$type}_track$i", $tagger->error_html);
                        $sv->error_at("tracks");
                    }
                } else if ($ttype == "none" && $type !== "admin")
                    $t->$type = "+none";
            if (count((array) $t) || get($tracks, "_"))
                $tracks->$trackname = $t;
        }
        $sv->save("tracks", count((array) $tracks) ? json_encode($tracks) : null);
        return false;
    }
}


SettingGroup::register("tags", "Tags &amp; tracks", 700, new SettingRenderer_Tags);
SettingGroup::register_synonym("tracks", "tags");
