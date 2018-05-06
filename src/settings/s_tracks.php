<?php
// src/settings/s_tracks.php -- HotCRP settings > tracks page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Tracks_SettingRenderer {
    static public $nperm_rendered_folded;

    static function unparse_perm($perm, $type) {
        if ($perm === "none"
            || $perm === "+none"
            || ($perm === "" && Track::permission_required(Track::$map[$type])))
            return ["none", ""];
        else if ($perm !== ""
                 && ($perm[0] === "+" || $perm[0] === "-")
                 && ($perm !== "+pc" || Track::permission_required(Track::$map[$type])))
            return [$perm[0], (string) substr($perm, 1)];
        else
            return ["", ""];
    }

    static function do_track_permission($sv, $type, $question, $tnum, $trackinfo,
                                        $gj = null) {
        $track_ctl = "{$type}_track$tnum";
        $tag_ctl = "{$type}_tag_track$tnum";
        $reqv = self::unparse_perm(get_s($trackinfo["req"], $type), $type);
        $curv = self::unparse_perm(get_s($trackinfo["cur"], $type), $type);
        $defclass = Track::permission_required(Track::$map[$type]) ? "none" : "";
        $unfolded = $curv[0] !== $defclass
            || $reqv[0] !== $defclass
            || (empty($trackinfo["unfolded"]) && $gj && get($gj, "default_unfolded"))
            || $sv->problem_status_at($track_ctl);
        self::$nperm_rendered_folded += !$unfolded;

        $permts = ["" => "Whole PC", "+" => "PC members with tag", "-" => "PC members without tag", "none" => "Administrators only"];
        if (Track::permission_required(Track::$map[$type])) {
            $permts = ["none" => $permts["none"], "+" => $permts["+"], "-" => $permts["-"]];
            if ($gj && get($gj, "permission_required") === "show_none")
                $permts["none"] = "None";
        }

        $hint = "";
        if (is_array($question)) {
            list($question, $hint) = $question;
        }
        $ljs = [];
        if ($gj && ($lc = get_s($gj, "label_class")))
            $ljs["class"] = $lc;

        echo '<div class="', $sv->sclass($track_ctl, "entryi wide"),
            ' has-fold fold', ($reqv[0] == "" || $reqv[0] === "none" ? "c" : "o"),
            ($unfolded ? "" : " fx3"),
            '">',
            $sv->label([$track_ctl, $tag_ctl], $question, $ljs),
            '<span class="strut">',
            Ht::select($track_ctl, $permts, $reqv[0],
                       $sv->sjs($track_ctl, ["class" => "js-track-perm", "data-default-value" => $curv[0]])),
            "</span> &nbsp;",
            Ht::entry($tag_ctl, $reqv[1],
                      $sv->sjs($tag_ctl, ["class" => "fx settings-track-perm-tag", "placeholder" => "(tag)", "data-default-value" => $curv[1]]));
        if ($hint)
            echo '<div class="f-h">', $hint, '</div>';
        echo "</div>";
    }

    static function render_view_permission(SettingValues $sv, $tnum, $t, $gj) {
        self::do_track_permission($sv, "view",
            "Who can see these submissions?", $tnum, $t, $gj);
    }

    static function render_viewrev_permission(SettingValues $sv, $tnum, $t, $gj) {
        $hint = "<span class=\"fx\">This setting constrains all users including co-reviewers.</span>";
        self::do_track_permission($sv, "viewrev",
            ["Who can see reviews?", $hint], $tnum, $t, $gj);
    }

    static private function get_trackinfo(SettingValues $sv, $trackname, $tnum) {
        // Find current track data
        $curtrack = null;
        if ($trackname !== ""
            && ($tjson = $sv->conf->setting_json("tracks")))
            $curtrack = get($tjson, $trackname);
        // Find request track data
        $reqtrack = $curtrack;
        if ($sv->use_req()) {
            $reqtrack = (object) [];
            foreach (Track::$map as $type => $perm) {
                $tclass = get($sv->req, "{$type}_track$tnum", "");
                if ($tclass === "none") {
                    if (!Track::permission_required($perm))
                        $reqtrack->$type = "+none";
                } else if ($tclass !== "")
                    $reqtrack->$type = $tclass . get($sv->req, "{$type}_tag_track$tnum", "");
            }
        }
        // Check fold status
        $unfolded = [];
        foreach (Track::$map as $type => $perm) {
            if ($tnum === 0 || get_s($reqtrack, $type) !== "")
                $unfolded[$type] = true;
        }
        return ["cur" => $curtrack, "req" => $reqtrack, "unfolded" => $unfolded];
    }

    static private function do_track(SettingValues $sv, $trackname, $tnum) {
        $trackinfo = self::get_trackinfo($sv, $trackname, $tnum);
        $req_trackname = $trackname;
        if ($sv->use_req())
            $req_trackname = get($sv->req, "name_track$tnum", "");

        // Print track entry
        echo "<div id=\"trackgroup$tnum\" class=\"mg has-fold fold3",
            ($tnum ? "c" : "o hidden"),
            "\"><div class=\"settings-tracks\">";
        if ($trackname === "_") {
            echo "For submissions not on other tracks:", Ht::hidden("name_track$tnum", "_");
        } else {
            echo $sv->label("name_track$tnum", "For submissions with tag &nbsp;"),
                Ht::entry("name_track$tnum", $req_trackname, $sv->sjs("name_track$tnum", ["placeholder" => "(tag)", "data-default-value" => $trackname, "class" => "settings-track-name"])), ":";
        }

        self::$nperm_rendered_folded = 0;
        foreach ($sv->group_members("tracks/permissions") as $gj) {
            if (isset($gj->render_track_permission_callback)) {
                Conf::xt_resolve_require($gj);
                call_user_func($gj->render_track_permission_callback, $sv, $tnum, $trackinfo, $gj);
            }
        }

        if (self::$nperm_rendered_folded) {
            echo '<div class="entryi wide fn3"><a href="" class="ui js-foldup" data-fold-target="3">Show all permissions</a></div>';
        }
        echo "</div></div>\n\n";
    }

    static function do_cross_track(SettingValues $sv) {
        echo "<div class=\"settings-tracks\">General permissions:";

        $trackinfo = self::get_trackinfo($sv, "_", 1);
        self::do_track_permission($sv, "viewtracker", "Who can see the <a href=\"" . hoturl("help", "t=chair#meeting") . "\">meeting tracker</a>?", 1, $trackinfo);
        echo "</div>\n\n";
    }

    static function render(SettingValues $sv) {
        echo "<p class=\"settingtext\">Tracks control the PC members allowed to view or review different sets of submissions. <span class=\"nw\">(<a href=\"" . hoturl("help", "t=tracks") . "\">Help</a>)</span></p>",
            Ht::hidden("has_tracks", 1),
            "<div class=\"smg\"></div>\n";
        self::do_track($sv, "", 0);

        // old track names
        $track_names = [];
        foreach ((array) ($sv->conf->setting_json("tracks") ? : []) as $name => $x) {
            if ($name !== "_")
                $track_names[] = $name;
        }
        $tnum = 2;
        while ($tnum < count($track_names) + 2
               || ($sv->use_req() && isset($sv->req["name_track$tnum"]))) {
            self::do_track($sv, get($track_names, $tnum - 2, ""), $tnum);
            ++$tnum;
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
                if (get($t, "viewpdf")
                    && $t["viewpdf"] !== $unassrev
                    && $unassrev !== "+none"
                    && $t["viewpdf"] !== get($t, "view")
                    && $sv->newv("pcrev_any")) {
                    $tnum = ($trackname === "_" ? 1 : $tracknum);
                    $tdesc = ($trackname === "_" ? "Default track" : "Track “{$trackname}”");
                    $sv->warning_at("unassrev_track$tnum", "$tdesc: Generally, a track that restricts who can see documents should restrict review self-assignment in the same way.");
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
            $ok = true;
            if ($trackname === "" || $trackname === "(tag)")
                continue;
            else if (!$tagger->check($trackname, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE)
                     || ($trackname === "_" && $i != 1)) {
                if ($trackname !== "_")
                    $sv->error_at("name_track$i", "Track name: " . $tagger->error_html);
                else
                    $sv->error_at("name_track$i", "Track name “_” is reserved.");
                $sv->error_at("tracks");
                $ok = false;
            }
            $t = (object) array();
            foreach (Track::$map as $type => $perm) {
                $ttype = get($sv->req, "{$type}_track{$i}");
                if ($ttype === "+" || $ttype === "-") {
                    $ttag = trim(get($sv->req, "${type}_tag_track$i", ""));
                    if ($ttag === "" || $ttag === "(tag)") {
                        $sv->error_at("{$type}_track$i", "Tag missing for track setting.");
                        $sv->error_at("{$type}_tag_track$i");
                        $sv->error_at("tracks");
                    } else if (($ttype == "+" && strcasecmp($ttag, "none") == 0)
                               || $tagger->check($ttag, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE))
                        $t->$type = $ttype . $ttag;
                    else {
                        $sv->error_at("{$type}_track$i", "Track permission tag: " . $tagger->error_html);
                        $sv->error_at("{$type}_tag_track$i");
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
            if ($ok && (count((array) $t) || get($tracks, "_")))
                $tracks->$trackname = $t;
        }
        $sv->save("tracks", count((array) $tracks) ? json_encode_db($tracks) : null);
        return false;
    }
}
