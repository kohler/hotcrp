<?php
// settings/s_track.php -- HotCRP settings > tracks page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Track_Setting {
    /** @var string */
    public $id;
    /** @var string */
    public $tag;
    /** @var bool */
    public $is_default;
    /** @var bool */
    public $is_new;
    /** @var list<string> */
    public $perms = [];
    /** @var object */
    public $j;

    /** @var bool */
    public $deleted = false;

    function __construct(Track $tr, $j) {
        $this->id = $this->tag = $tr->is_default ? "any" : $tr->tag;
        $this->is_default = $tr->is_default;
        $this->is_new = $this->id === "";
        $this->j = $j ?? (object) [];
        foreach ($tr->perm as $perm => $p) {
            // undo defaulting
            if ($perm === Track::VIEWPDF
                && $p === $tr->perm[Track::VIEW]
                && !isset($this->j->viewpdf)) {
                $p = null;
            }
            if ((Track::perm_required($perm) && $p === null)
                || $p === "none"
                || $p === "+none") {
                $this->perms[] = "none";
            } else if ($p === null || $p === "" || $p === "all") {
                $this->perms[] = "all";
            } else {
                $this->perms[] = $p;
            }
        }
    }

    /** @param string $p
     * @return string */
    static function perm_type($p) {
        return $p === "all" || $p === "none" ? $p : $p[0];
    }

    /** @param string $p
     * @return string */
    static function perm_tag($p) {
        return $p === "all" || $p === "none" ? "" : substr($p, 1);
    }

    /** @return bool */
    function is_empty() {
        foreach ($this->perms as $perm => $p) {
            if ($p !== (Track::perm_required($perm) ? "none" : "all"))
                return false;
        }
        return !$this->is_new;
    }
}

class Track_SettingParser extends SettingParser {
    /** @var int|'$' */
    public $ctr;
    /** @var int */
    public $nfolded;
    /** @var object */
    private $settings_json;
    /** @var Track_Setting */
    private $cur_trx;

    static function permission_title($perm) {
        if ($perm === Track::VIEW) {
            return "submission visibility";
        } else if ($perm === Track::VIEWPDF) {
            return "document visibility";
        } else if ($perm === Track::VIEWREV) {
            return "review visibility";
        } else if ($perm === Track::VIEWREVID) {
            return "reviewer name";
        } else if ($perm === Track::ASSREV) {
            return "assignment";
        } else if ($perm === Track::UNASSREV) {
            return "self-assignment";
        } else if ($perm === Track::VIEWTRACKER) {
            return "tracker visibility";
        } else if ($perm === Track::ADMIN) {
            return "administrator";
        } else if ($perm === Track::HIDDENTAG) {
            return "hidden-tag visibility";
        } else if ($perm === Track::VIEWALLREV) {
            return "review visibility";
        } else {
            return "unknown";
        }
    }

    function member_list(Si $si, SettingValues $sv) {
        if ($si->name_matches("track/", "*", "/perm")) {
            $sis = [];
            foreach (Track::$perm_name_map as $pn => $perm) {
                $sis[] = $si->conf->si("{$si->name}/{$pn}");
            }
            return $sis;
        } else {
            return null;
        }
    }

    function default_value(Si $si, SettingValues $sv) {
        if (($si->name_matches("track/", "*", "/perm/", "*")
             || $si->name_matches("track/", "*", "/perm/", "*", "/tag"))
            && ($perm = Track::$perm_name_map[$si->name1] ?? null) !== null) {
            return Track::perm_required($perm) ? "none" : "all";
        } else {
            return null;
        }
    }

    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name_matches("track/", "*")) {
            $sv->set_oldv($si, new Track_Setting(new Track, null));
        } else if ($si->name_matches("track/", "*", "/title")) {
            $id = $sv->reqstr("{$si->name0}{$si->name1}/id") ?? "";
            if ($id === "any") {
                $sv->set_oldv($si->name, "Default track");
            } else if (($tag = $sv->vstr("{$si->name0}{$si->name1}/tag") ?? "") !== "") {
                $sv->set_oldv($si->name, "Track ‘{$tag}’");
            } else {
                $sv->set_oldv($si->name, "Unnamed track");
            }
        } else if ($si->name_matches("track/", "*", "/perm/", "*", "*")) {
            $trx = $sv->oldv($si->name_prefix(2));
            $perm = Track::$perm_name_map[$si->name1] ?? null;
            $p = $trx !== null && $perm !== null ? $trx->perms[$perm] : "all";
            if ($si->name2 === "") {
                $sv->set_oldv($si->name, $p);
            } else if ($si->name2 === "/type") {
                $sv->set_oldv($si->name, Track_Setting::perm_type($p));
            } else if ($si->name2 === "/tag") {
                $sv->set_oldv($si->name, Track_Setting::perm_tag($p));
            } else if ($si->name2 === "/title") {
                $sv->set_oldv($si->name, self::permission_title($perm));
            }
        }
    }

    function prepare_oblist(Si $si, SettingValues $sv) {
        if ($si->name === "track") {
            $this->settings_json = $this->settings_json ?? $sv->conf->setting_json("tracks");
            $m = [];
            foreach ($sv->conf->track_tags() as $tag) {
                $m[] = new Track_Setting($sv->conf->track($tag),
                                         $this->settings_json->{$tag} ?? null);
            }
            $m[] = new Track_Setting($sv->conf->track("") ?? new Track(""),
                                     $this->settings_json->_ ?? null);
            $sv->append_oblist("track", $m, "tag");
        }
    }

    const PERM_DEFAULT_UNFOLDED = 1;

    /** @param SettingValues $sv
     * @param string $permname
     * @param string|array{string,string} $label
     * @param int $flags */
    function print_perm($sv, $permname, $label, $flags = 0) {
        $perm = Track::$perm_name_map[$permname];
        $deftype = Track::perm_required($perm) ? "none" : "all";
        $trx = $sv->oldv("track/{$this->ctr}");
        $pfx = "track/{$this->ctr}/perm/{$permname}";
        $p = $sv->reqstr($pfx) ?? $trx->perms[$perm];
        $reqtype = $sv->reqstr("{$pfx}/type") ?? Track_Setting::perm_type($p);
        $reqtag = $sv->reqstr("{$pfx}/tag") ?? Track_Setting::perm_tag($p);

        $unfolded = Track_Setting::perm_type($trx->perms[$perm]) !== $deftype
            || $reqtype !== $deftype
            || (($flags & self::PERM_DEFAULT_UNFOLDED) !== 0 && $trx->is_empty())
            || $sv->problem_status_at("track/{$this->ctr}");
        if (!$unfolded) {
            ++$this->nfolded;
        }

        $permts = ["all" => "Whole PC", "+" => "PC members with tag", "-" => "PC members without tag", "none" => "Administrators only"];
        if (Track::perm_required($perm)) {
            $permts = ["none" => $permts["none"], "+" => $permts["+"], "-" => $permts["-"]];
        }

        $hint = "";
        if (is_array($label)) {
            list($label, $hint) = $label;
        }

        echo '<div class="', $sv->control_class($pfx, "entryi wide"),
            ' has-fold fold', $reqtype === "all" || $reqtype === "none" ? "c" : "o",
            $unfolded ? "" : " fx3",
            '" data-fold-values="+ -" id="', $pfx, '">',
            Ht::hidden("has_{$pfx}", 1),
            $sv->label(["{$pfx}/type", "{$pfx}/tag"], $label),
            '<div class="entry">',
            Ht::select("{$pfx}/type", $permts, $reqtype, $sv->sjs("{$pfx}/type", ["class" => "uich js-foldup"])),
            " &nbsp;",
            Ht::entry("{$pfx}/tag", $reqtag, $sv->sjs("{$pfx}/tag", ["class" => "fx need-suggest pc-tags", "spellcheck" => false, "autocomplete" => "off"]));
        $sv->print_feedback_at($pfx);
        $sv->print_feedback_at("{$pfx}/type");
        $sv->print_feedback_at("{$pfx}/tag");
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

    function print_view_perm(SettingValues $sv, $gj) {
        $this->print_perm($sv, "view", "Who can see these submissions?", self::PERM_DEFAULT_UNFOLDED);
    }

    function print_viewrev_perm(SettingValues $sv, $gj) {
        $hint = "<div class=\"fx\">This setting constrains all users including co-reviewers.</div>";
        $this->print_perm($sv, "viewrev", ["Who can see reviews?", $hint]);
    }

    private function print_track(SettingValues $sv, $ctr) {
        $this->ctr = $ctr;
        $this->nfolded = 0;
        $trx = $sv->oldv("track/{$ctr}");
        echo '<div id="track/', $ctr, '" class="has-fold ',
            $trx->is_new ? "fold3o" : "fold3c", '">',
            Ht::hidden("track/{$ctr}/id", $trx->tag,
                ["data-default-value" => $trx->is_new ? "" : null]),
            '<div class="settings-tracks"><div class="entryg">';
        if ($trx->is_default) {
            echo "For submissions not on other tracks:";
        } else {
            echo $sv->label("track/{$ctr}/tag", "For submissions with tag", ["class" => "mr-2"]),
                $sv->entry("track/{$ctr}/tag", ["class" => "settings-track-name need-suggest tags", "spellcheck" => false, "autocomplete" => "off"]),
                ':';
        }
        echo '</div>';

        $sv->print_members("tracks/permissions");

        if ($this->nfolded) {
            echo '<div class="entryi wide fn3">',
                '<label><button type="button" class="q ui js-foldup" data-fold-target="3">',
                expander(true, 3), 'More…</button></label>',
                '<div class="entry"><button type="button" class="q ui js-foldup" data-fold-target="3">',
                $sv->conf->_("({} more permissions have default values)", $this->nfolded),
                '</button></div></div>';
        }
        echo "</div></div>\n\n";
    }

    private function print_cross_track(SettingValues $sv) {
        echo "<div class=\"settings-tracks\"><div class=\"entryg\">General permissions:</div>";
        $this->ctr = $sv->search_oblist("track", "id", "any");
        $this->print_perm($sv, "viewtracker", "Who can see the <a href=\"" . $sv->conf->hoturl("help", "t=chair#meeting") . "\">meeting tracker</a>?", self::PERM_DEFAULT_UNFOLDED);
        echo "</div>\n\n";
    }

    function print(SettingValues $sv) {
        echo "<p>Tracks control the PC members allowed to view or review different sets of submissions. <span class=\"nw\">(<a href=\"" . $sv->conf->hoturl("help", "t=tracks") . "\">Help</a>)</span></p>",
            Ht::hidden("has_track", 1);

        foreach ($sv->oblist_keys("track") as $ctr) {
            $this->print_track($sv, $ctr);
        }
        $this->print_cross_track($sv);

        if ($sv->editable("track")) {
            echo '<template id="settings-track-new" class="hidden">';
            $this->print_track($sv, '$');
            echo '</template>',
                Ht::button("Add track", ["class" => "ui js-settings-track-add", "id" => "settings_track_add"]);
        }
    }


    private function _apply_req_perm(Si $si, SettingValues $sv) {
        $pfx = $si->name0 . $si->name1;
        $perm = Track::$perm_name_map[$si->name1];

        // parse request
        if ($sv->reqstr($pfx) !== null) {
            $s = trim($sv->reqstr($pfx));
            if ($s !== "" && ($s[0] === "+" || $s[0] === "-")) {
                $type = $s[0];
                $tag = substr($s, 1);
            } else if ($s === "") {
                $type = "all";
                $tag = "";
            } else {
                $type = "+";
                $tag = $s;
            }
        } else {
            $type = $sv->base_parse_req("{$pfx}/type");
            $tag = $type === "+" || $type === "-" ? trim($sv->vstr("{$pfx}/tag")) : "";
        }

        // canonicalize
        if ($tag === "" || strcasecmp($tag, "all") === 0 || strcasecmp($tag, "any") === 0) {
            if ($type === "+") {
                $type = "all";
            } else if ($type === "-") {
                $type = "none";
            }
        } else if (strcasecmp($tag, "none") === 0) {
            if ($type === "+") {
                $type = "none";
            } else if ($type === "-") {
                $type = "all";
            }
        }

        // check
        if ($type === "" || $type === "all" || $type === "any") {
            $pv = null;
        } else if ($type === "none") {
            $pv = Track::perm_required($perm) ? null : "+none";
        } else {
            if (($t = $sv->tagger()->check($tag, Tagger::NOVALUE | Tagger::NOPRIVATE))) {
                $pv = $type . $t;
            } else {
                $sv->error_at($pfx, $sv->tagger()->error_ftext());
                $sv->error_at("{$pfx}/tag");
                return;
            }
        }

        // store
        $pn = Track::perm_name($perm);
        if ($pv === null) {
            unset($this->cur_trx->j->{$pn});
        } else {
            $this->cur_trx->j->{$pn} = $pv;
        }
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name_matches("track/", "*", "/perm/", "*", "*")
            && ($si->name2 === "" || $si->name2 === "/type" || $si->name2 === "/tag")) {
            if ($si->name2 !== "/tag" || !$sv->has_req("{$si->name0}{$si->name1}/type")) {
                $this->_apply_req_perm($si, $sv);
            }
            return true;
        } else if ($si->name === "track") {
            $j = [];
            foreach ($sv->oblist_nondeleted_keys("track") as $ctr) {
                $this->cur_trx = $sv->newv("track/{$ctr}");
                if (!$this->cur_trx->is_default) {
                    $sv->error_if_missing("track/{$ctr}/tag");
                    $sv->error_if_duplicate_member("track", $ctr, "tag", "Track tag");
                    if ($this->cur_trx->tag === "_"
                        || !$sv->tagger()->check($this->cur_trx->tag, Tagger::NOVALUE)) {
                        $sv->error_at("track/{$ctr}/tag", "<0>Track name ‘{$this->cur_trx->tag}’ is reserved");
                    }
                }
                foreach ($sv->req_member_list("track/{$ctr}/perm") as $permsi) {
                    $sv->apply_req($permsi);
                }
                if ($this->cur_trx->is_default) {
                    if (!empty((array) $this->cur_trx->j)) {
                        $j["_"] = $this->cur_trx->j;
                    }
                } else {
                    $j[$this->cur_trx->tag] = $this->cur_trx->j;
                }
            }
            $sv->update("tracks", empty($j) ? "" : json_encode_db($j));
            return true;
        } else {
            return false;
        }
    }

    static function crosscheck(SettingValues $sv) {
        $conf = $sv->conf;
        $tracks_interest = $sv->has_interest("track");
        if (($tracks_interest || $sv->has_interest("review_self_assign"))
            && $conf->has_tracks()) {
            foreach ($sv->oblist_keys("track") as $ctr) {
                if (($id = $sv->reqstr("track/{$ctr}/id")) === "") {
                    continue;
                }
                $tr = $conf->track($id === "any" ? "" : $id);
                if ($tr->perm[Track::VIEWPDF]
                    && $tr->perm[Track::VIEWPDF] !== $tr->perm[Track::UNASSREV]
                    && $tr->perm[Track::UNASSREV] !== "+none"
                    && $tr->perm[Track::VIEWPDF] !== $tr->perm[Track::VIEW]
                    && $conf->setting("pcrev_any")) {
                    $sv->warning_at("track/{$ctr}/perm/unassrev", "<0>A track that restricts who can see documents should generally restrict review self-assignment in the same way.");
                }
                if ($tr->perm[Track::ASSREV]
                    && $tr->perm[Track::UNASSREV]
                    && $tr->perm[Track::UNASSREV] !== "+none"
                    && $tr->perm[Track::ASSREV] !== $tr->perm[Track::UNASSREV]
                    && $conf->setting("pcrev_any")) {
                    $n = 0;
                    foreach ($conf->pc_members() as $pc) {
                        if ($pc->has_permission($tr->perm[Track::ASSREV])
                            && $pc->has_permission($tr->perm[Track::UNASSREV]))
                            ++$n;
                    }
                    if ($n === 0) {
                        $sv->warning_at("track/{$ctr}/perm/assrev");
                        $sv->warning_at("track/{$ctr}/perm/unassrev", "<0>No PC members match both review assignment permissions, so no PC members can self-assign reviews.");
                    }
                }
                foreach ($tr->perm as $perm => $pv) {
                    if ($pv !== null
                        && $pv !== "+none"
                        && ($perm !== Track::VIEWPDF || $pv !== $tr->perm[Track::VIEW])
                        && !$conf->pc_tag_exists(substr($pv, 1))
                        && $tracks_interest) {
                        $pn = Track::perm_name($perm);
                        $sv->warning_at("track/{$ctr}/perm/{$pn}", "<0>No PC member has tag ‘" . substr($pv, 1) . "’. You might want to check your spelling.");
                        $sv->warning_at("track/{$ctr}/perm/{$pn}/tag");
                    }
                }
            }
        }
    }
}
