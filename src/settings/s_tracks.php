<?php
// src/settings/s_tracks.php -- HotCRP settings > tracks page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Tracks_SettingRenderer {
    static function do_track_permission($sv, $type, $question, $tnum, $thistrack,
                                        $gj = null) {
        $tclass = $ttag = "";
        if ($sv->use_req()) {
            $tclass = defval($sv->req, "{$type}_track{$tnum}", "");
            $ttag = defval($sv->req, "{$type}tag_track{$tnum}", "");
        } else if ($thistrack && get($thistrack, $type)) {
            if ($thistrack->$type == "+none")
                $tclass = "none";
            else {
                $tclass = substr($thistrack->$type, 0, 1);
                $ttag = substr($thistrack->$type, 1);
            }
        }

        $perm = ["" => "Whole PC", "+" => "PC members with tag", "-" => "PC members without tag", "none" => "Administrators only"];
        if ($gj && get($gj, "permission_required")) {
            $perm = ["none" => $perm["none"], "+" => $perm["+"], "-" => $perm["-"]];
            if (get($gj, "permission_required") === "show_none")
                $perm["none"] = "None";
            if ($tclass === "")
                $tclass = "none";
        }

        $hint = "";
        if (is_array($question)) {
            list($question, $hint) = $question;
        }

        echo '<div class="entryi wide has-fold fold',
            ($tclass == "" || $tclass === "none" ? "c" : "o"),
            '">',
            $sv->label(["{$type}_track$tnum", "{$type}tag_track$tnum"], $question),
            '<span class="strut">',
            Ht::select("{$type}_track$tnum", $perm, $tclass,
                       $sv->sjs("{$type}_track$tnum", ["class" => "js-track-perm"])),
            "</span> &nbsp;",
            Ht::entry("${type}tag_track$tnum", $ttag,
                      $sv->sjs("{$type}tag_track$tnum", array("class" => "fx settings-track-perm-tag", "placeholder" => "(tag)")));
        if ($hint)
            echo '<div class="f-h">', $hint, '</div>';
        echo "</div>";
    }

    static function render_view_permission(SettingValues $sv, $tnum, $t) {
        self::do_track_permission($sv, "view",
            "Who can see these papers?", $tnum, $t);
    }

    static function render_viewrev_permission(SettingValues $sv, $tnum, $t) {
        $hint = "";
        if ($sv->conf->setting("pc_seeallrev") == 0)
            $hint = "In the " . Ht::link("current settings", hoturl("settings", "group=reviews#pcreviews")) . ", only PC members that have completed a review for the same paper can see reviews.";
        self::do_track_permission($sv, "viewrev",
            ["Who can see reviews?", $hint], $tnum, $t);
    }

    static private function do_track(SettingValues $sv, $trackname, $tnum) {
        echo "<div id=\"trackgroup$tnum\" class=\"mg\"",
            ($tnum ? "" : " style=\"display:none\""),
            "><div class=\"settings-tracks\">";
        if ($trackname === "_")
            echo "For papers not on other tracks:", Ht::hidden("name_track$tnum", "_");
        else
            echo $sv->label("name_track$tnum", "For papers with tag &nbsp;"),
                Ht::entry("name_track$tnum", $trackname, $sv->sjs("name_track$tnum", array("placeholder" => "(tag)"))), ":";

        $t = null;
        foreach ($sv->conf->setting_json("tracks") ? : [] as $tname => $tval)
            if ($trackname !== "" && strcasecmp($tname, $trackname) === 0)
                $t = $tval;
        foreach ($sv->group_members("tracks/permissions") as $gj) {
            if (isset($gj->render_track_permission_callback)) {
                Conf::xt_resolve_require($gj);
                call_user_func($gj->render_track_permission_callback, $sv, $tnum, $t, $gj);
            }
        }
        echo "</div></div>\n\n";
    }

    static function do_cross_track(SettingValues $sv) {
        echo "<div class=\"settings-tracks\">General permissions:";

        $t = $sv->conf->setting_json("tracks");
        $t = $t ? get($t, "_") : null;
        self::do_track_permission($sv, "viewtracker", "Who can see the <a href=\"" . hoturl("help", "t=chair#meeting") . "\">meeting tracker</a>?", 1, $t);
        echo "</div>\n\n";
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
        self::do_cross_track($sv);
        echo Ht::button("Add track", ["class" => "btn ui js-settings-add-track", "id" => "settings_track_add"]);

        Ht::stash_script('suggest($(".need-tagcompletion"), taghelp_tset)', "taghelp_tset");
        Ht::stash_script('$(document).on("change", "select.js-track-perm", function (event) { foldup.call(this, event, {f: this.selectedIndex == 0 || this.selectedIndex == 3}) })');
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
            foreach (Track::$map as $type => $perm) {
                $ttype = get($sv->req, "{$type}_track{$i}");
                if ($ttype === "+" || $ttype === "-") {
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
                } else if ($ttype === "none") {
                    if (!Track::permission_required($perm))
                        $t->$type = "+none";
                } else if ($ttype === null) {
                    // track permission not in UI; preserve current permission
                    if (($perm = $sv->conf->track_permission($trackname, $perm)))
                        $t->$type = $perm;
                }
            }
            if (count((array) $t) || get($tracks, "_"))
                $tracks->$trackname = $t;
        }
        $sv->save("tracks", count((array) $tracks) ? json_encode_db($tracks) : null);
        return false;
    }
}
