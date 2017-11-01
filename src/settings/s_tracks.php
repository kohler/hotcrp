<?php
// src/settings/s_tracks.php -- HotCRP settings > tracks page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Tracks_SettingRenderer {
    static private function do_track_permission($sv, $type, $question, $tnum, $thistrack) {
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

    static private function do_track($sv, $trackname, $tnum) {
        echo "<div id=\"trackgroup$tnum\" class=\"mg\"",
            ($tnum ? "" : " style=\"display:none\""),
            "><table style=\"margin-bottom:0.5em\">";
        echo "<tr><td colspan=\"4\" style=\"padding-bottom:3px\">";
        if ($trackname === "_")
            echo "For papers not on other tracks:", Ht::hidden("name_track$tnum", "_");
        else
            echo $sv->label("name_track$tnum", "For papers with tag &nbsp;"),
                Ht::entry("name_track$tnum", $trackname, $sv->sjs("name_track$tnum", array("placeholder" => "(tag)"))), ":";
        echo "</td></tr>\n";

        $t = $sv->conf->setting_json("tracks");
        $t = $t && $trackname !== "" ? get($t, $trackname) : null;
        self::do_track_permission($sv, "view", "Who can see these papers?", $tnum, $t);
        self::do_track_permission($sv, "viewpdf", ["Who can see PDFs?", "Assigned reviewers can always see PDFs."], $tnum, $t);
        $hint = "";
        if ($sv->conf->setting("pc_seeallrev") == 0)
            $hint = "Regardless of this setting, PC members can’t see reviews until they’ve completed a review for the same paper (see " . Ht::link("Settings &gt; Reviews &gt; PC reviews", hoturl("settings", "group=reviews#pcreviews")) . ").";
        self::do_track_permission($sv, "viewrev", ["Who can see reviews?", $hint], $tnum, $t);
        $hint = "";
        if ($sv->conf->setting("pc_seeblindrev"))
            $hint = "Regardless of this setting, PC members can’t see reviewer names until they’ve completed a review for the same paper (see " . Ht::link("Settings &gt; Reviews &gt; PC reviews", hoturl("settings", "group=reviews#pcreviews")) . ").";
        self::do_track_permission($sv, "viewrevid", ["Who can see reviewer names?", $hint], $tnum, $t);
        self::do_track_permission($sv, "assrev", "Who can be assigned a review?", $tnum, $t);
        self::do_track_permission($sv, "unassrev", "Who can self-assign a review?", $tnum, $t);
        self::do_track_permission($sv, "admin", "Who can administer these papers?", $tnum, $t);
        if ($trackname === "_")
            self::do_track_permission($sv, "viewtracker", "Who can see the <a href=\"" . hoturl("help", "t=chair#meeting") . "\">meeting tracker</a>?", $tnum, $t);
        echo "</table></div>\n\n";
    }

    static function render(SettingValues $sv) {
        echo '<h3 class="settings g">Tracks</h3>', "\n";
        echo "<p class=\"settingtext\">Tracks control the PC members allowed to view or review different sets of papers. <span class=\"nw\">(<a href=\"" . hoturl("help", "t=tracks") . "\">Help</a>)</span></p>",
            Ht::hidden("has_tracks", 1),
            "<div class=\"smg\"></div>\n";
        self::do_track($sv, "", 0);
        $tracknum = 2;
        $trackj = $sv->conf->setting_json("tracks") ? : (object) array();
        // existing tracks
        foreach ($trackj as $trackname => $x)
            if ($trackname !== "_") {
                self::do_track($sv, $trackname, $tracknum);
                ++$tracknum;
            }
        // new tracks (if error prevented saving)
        if ($sv->use_req())
            for ($i = 1; isset($sv->req["name_track$i"]); ++$i) {
                $trackname = trim($sv->req["name_track$i"]);
                if (!isset($trackj->$trackname) && $trackname !== "_") {
                    self::do_track($sv, $trackname, $tracknum);
                    ++$tracknum;
                }
            }
        // catchall track
        self::do_track($sv, "_", 1);
        echo Ht::js_button("Add track", "settings_add_track()", ["class" => "btn btn-sm"]);

        Ht::stash_script('suggest($(".need-tagcompletion"), taghelp_tset)', "taghelp_tset");
    }

    static function crosscheck(SettingValues $sv) {
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

class Tracks_SettingParser extends SettingParser {
    function parse(SettingValues $sv, Si $si) {
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
        $sv->save("tracks", count((array) $tracks) ? json_encode_db($tracks) : null);
        return false;
    }
}
