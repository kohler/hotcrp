<?php
// src/settings/s_tracks.php -- HotCRP settings > tracks page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

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

        echo '<div class="', $sv->control_class($track_ctl, "entryi wide"),
            ' has-fold fold', ($reqv[0] == "" || $reqv[0] === "none" ? "c" : "o"),
            ($unfolded ? "" : " fx3"),
            '" data-fold-values="+ -">',
            $sv->label([$track_ctl, $tag_ctl], $question, $ljs),
            '<div class="entry">',
            Ht::select($track_ctl, $permts, $reqv[0],
                       $sv->sjs($track_ctl, ["class" => "uich js-foldup", "data-default-value" => $curv[0]])),
            " &nbsp;",
            Ht::entry($tag_ctl, $reqv[1],
                      $sv->sjs($tag_ctl, ["class" => "fx need-suggest pc-tags", "placeholder" => "(tag)", "data-default-value" => $curv[1]]));
        $sv->echo_messages_at($track_ctl);
        $sv->echo_messages_at($tag_ctl);
        if ($hint) {
            $klass = "f-h";
            if (str_starts_with($hint, '<div class="fx">')
                && str_ends_with($hint, '</div>')
                && strpos($hint, '<div', 16) === false) {
                $hint = substr($hint, 16, -6);
                $klass .= " fx";
            }
            echo '<div class="', $klass, '">', $hint, '</div>';
        }
        echo "</div></div>";
    }

    static function render_view_permission(SettingValues $sv, $tnum, $t, $gj) {
        self::do_track_permission($sv, "view",
            "Who can see these submissions?", $tnum, $t, $gj);
    }

    static function render_viewrev_permission(SettingValues $sv, $tnum, $t, $gj) {
        $hint = "<div class=\"fx\">This setting constrains all users including co-reviewers.</div>";
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
                $tclass = $sv->reqv("{$type}_track$tnum", "");
                if ($tclass === "none") {
                    if (!Track::permission_required($perm))
                        $reqtrack->$type = "+none";
                } else if ($tclass !== "")
                    $reqtrack->$type = $tclass . $sv->reqv("{$type}_tag_track$tnum", "");
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
            $req_trackname = $sv->reqv("name_track$tnum", "");

        // Print track entry
        echo "<div id=\"trackgroup$tnum\" class=\"mg has-fold fold3",
            ($tnum ? "c" : "o hidden"),
            "\"><div class=\"settings-tracks\"><div class=\"entryg\">";
        if ($trackname === "_") {
            echo "For submissions not on other tracks:", Ht::hidden("name_track$tnum", "_");
        } else {
            echo $sv->label("name_track$tnum", "For submissions with tag &nbsp;"),
                Ht::entry("name_track$tnum", $req_trackname, $sv->sjs("name_track$tnum", ["placeholder" => "(tag)", "data-default-value" => $trackname, "class" => "settings-track-name need-suggest tags"])), ":";
        }
        echo "</div>";

        self::$nperm_rendered_folded = 0;
        foreach ($sv->group_members("tracks/permissions") as $gj) {
            if (isset($gj->render_track_permission_callback)) {
                Conf::xt_resolve_require($gj);
                call_user_func($gj->render_track_permission_callback, $sv, $tnum, $trackinfo, $gj);
            }
        }

        if (self::$nperm_rendered_folded) {
            echo '<div class="entryi wide fn3">',
                '<label><a href="" class="ui js-foldup qq" data-fold-target="3">',
                expander(true, 3), 'More…</a></label>',
                '<div class="entry"><a href="" class="ui js-foldup qq" data-fold-target="3">',
                $sv->conf->_("(%d more permissions have default values)", self::$nperm_rendered_folded),
                '</a></div></div>';
        }
        echo "</div></div>\n\n";
    }

    static function do_cross_track(SettingValues $sv) {
        echo "<div class=\"settings-tracks\"><div class=\"entryg\">General permissions:</div>";

        $trackinfo = self::get_trackinfo($sv, "_", 1);
        self::do_track_permission($sv, "viewtracker", "Who can see the <a href=\"" . $sv->conf->hoturl("help", "t=chair#meeting") . "\">meeting tracker</a>?", 1, $trackinfo);
        echo "</div>\n\n";
    }

    static function render(SettingValues $sv) {
        echo "<p>Tracks control the PC members allowed to view or review different sets of submissions. <span class=\"nw\">(<a href=\"" . $sv->conf->hoturl("help", "t=tracks") . "\">Help</a>)</span></p>",
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
               || ($sv->use_req() && $sv->has_reqv("name_track$tnum"))) {
            self::do_track($sv, get($track_names, $tnum - 2, ""), $tnum);
            ++$tnum;
        }

        // catchall track
        self::do_track($sv, "_", 1);
        self::do_cross_track($sv);
        echo Ht::button("Add track", ["class" => "ui js-settings-add-track", "id" => "settings_track_add"]);
    }

    static function crosscheck(SettingValues $sv) {
        if (($sv->has_interest("tracks") || $sv->has_interest("pcrev_any"))
            && $sv->newv("tracks")) {
            $tracks = json_decode($sv->newv("tracks"), true);
            $tracknum = 2;
            foreach ($tracks as $trackname => $t) {
                $tnum = ($trackname === "_" ? 1 : $tracknum);
                $tdesc = ($trackname === "_" ? "Default track" : "Track “{$trackname}”");
                $assrev = get($t, "assrev");
                $unassrev = get($t, "unassrev");
                if (get($t, "viewpdf")
                    && $t["viewpdf"] !== $unassrev
                    && $unassrev !== "+none"
                    && $t["viewpdf"] !== get($t, "view")
                    && $sv->newv("pcrev_any")) {
                    $sv->warning_at("unassrev_track$tnum", "$tdesc: Generally, a track that restricts who can see documents should restrict review self-assignment in the same way.");
                }
                if ($assrev
                    && $unassrev
                    && $unassrev !== "+none"
                    && $assrev !== $unassrev
                    && $sv->newv("pcrev_any")) {
                    $n = 0;
                    foreach ($sv->conf->pc_members() as $pc)
                        if ($pc->has_permission($assrev) && $pc->has_permission($unassrev))
                            ++$n;
                    if ($n === 0) {
                        $sv->warning_at("assrev_track$tnum");
                        $sv->warning_at("unassrev_track$tnum", "$tdesc: No PC members match both review assignment permissions, so no PC members can self-assign reviews.");
                    }
                }
                $tracknum += ($trackname === "_" ? 0 : 1);
            }
        }
    }
}

class Tracks_SettingParser extends SettingParser {
    function parse(SettingValues $sv, Si $si) {
        $tagger = new Tagger($sv->user);
        $tracks = (object) array();
        $missing_tags = false;
        for ($i = 1; $sv->has_reqv("name_track$i"); ++$i) {
            $trackname = trim($sv->reqv("name_track$i"));
            $ok = true;
            if ($trackname === "" || $trackname === "(tag)") {
                continue;
            }
            $trackname = $tagger->check($trackname, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE);
            if (!$trackname || ($trackname === "_" && $i !== 1)) {
                if ($trackname !== "_")
                    $sv->error_at("name_track$i", $tagger->error_html);
                else
                    $sv->error_at("name_track$i", "Track name “_” is reserved.");
                $sv->error_at("tracks");
                $ok = false;
            }
            $t = (object) array();
            foreach (Track::$map as $type => $perm) {
                $ttype = $sv->reqv("{$type}_track$i");
                $ttag = trim($sv->reqv("{$type}_tag_track$i", ""));
                if ($ttype === "+" && strcasecmp($ttag, "none") === 0) {
                    $ttype = "none";
                }
                if ($ttype === "+" || $ttype === "-") {
                    if ($ttag === "" || $ttag === "(tag)") {
                        $sv->error_at("{$type}_track$i", "Tag missing for track setting.");
                        $sv->error_at("{$type}_tag_track$i");
                        $sv->error_at("tracks");
                    } else if (($ttag = $tagger->check($ttag, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE))) {
                        $t->$type = $ttype . $ttag;
                    } else {
                        $sv->error_at("{$type}_track$i", "Track permission tag: " . $tagger->error_html);
                        $sv->error_at("{$type}_tag_track$i");
                        $sv->error_at("tracks");
                    }
                } else if ($ttype === "none") {
                    if (!Track::permission_required($perm)) {
                        $t->$type = "+none";
                    }
                } else if ($ttype === null) {
                    // track permission not in UI; preserve current permission
                    if (($perm = $sv->conf->track_permission($trackname, $perm))) {
                        $t->$type = $perm;
                    }
                }
            }
            if ($ok && (count((array) $t) || get($tracks, "_"))) {
                $tracks->$trackname = $t;
            }
        }
        $sv->save("tracks", count((array) $tracks) ? json_encode_db($tracks) : null);
        return false;
    }
}
