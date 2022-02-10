<?php
// settings/s_tracks.php -- HotCRP settings > tracks page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class TrackPerm_Setting {
    /** @var ''|'+'|'-'|'none' */
    public $type;
    /** @var string */
    public $tag;

    function __construct($type, $tag) {
        $this->type = $type;
        $this->tag = $tag;
    }
}

class Track_Setting {
    /** @var string */
    public $tag;
    /** @var bool */
    public $is_default;
    /** @var bool */
    public $is_new;
    /** @var list<TrackPerm_Setting> */
    public $perm = [];
    /** @var object */
    public $j;

    public function __construct(Track $tr, $j) {
        $this->tag = $tr->tag;
        $this->is_default = $tr->is_default;
        $this->is_new = $tr->tag === "" && !$tr->is_default;
        $this->j = $j;
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
                $this->perm[] = new TrackPerm_Setting("none", "");
            } else if ($p === null || $p === "") {
                $this->perm[] = new TrackPerm_Setting("", "");
            } else {
                $this->perm[] = new TrackPerm_Setting(substr($p, 0, 1), substr($p, 1));
            }
        }
    }

    /** @return bool */
    function is_empty() {
        foreach ($this->perm as $perm => $p) {
            if ($p->type !== (Track::perm_required($perm) ? "none" : ""))
                return false;
        }
        return !$this->is_new;
    }
}

class Tracks_SettingParser extends SettingParser {
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

    function set_oldv(SettingValues $sv, Si $si) {
        if (count($si->parts) === 3 && $si->part2 === "") {
            $id = $sv->reqstr("{$si->part0}{$si->part1}__id") ?? "";
            $tr = $id !== "" ? $sv->conf->track($id === "none" ? "" : $id) : null;
            if (!$tr && $id === "none") {
                $tr = new Track("");
                $tr->is_default = true;
            }
            if ($tr) {
                $this->settings_json = $this->settings_json ?? $sv->conf->setting_json("tracks");
                $k = $tr->is_default ? "_" : $tr->tag;
                $sv->set_oldv($si->name, new Track_Setting($tr, $this->settings_json->{$k} ?? (object) []));
            } else {
                $sv->set_oldv($si->name, new Track_Setting(new Track(""), (object) []));
            }
        } else if (count($si->parts) === 3 && $si->part2 === "__title") {
            $id = $sv->reqstr("{$si->part0}{$si->part1}__id") ?? "";
            if ($id === "none") {
                $sv->set_oldv($si->name, "Default track");
            } else if (($tag = $sv->vstr("{$si->part0}{$si->part1}__tag") ?? "") !== "") {
                $sv->set_oldv($si->name, "Track ‘{$tag}’");
            } else {
                $sv->set_oldv($si->name, "Unnamed track");
            }
        } else if (count($si->parts) === 5 && $si->parts[2] === "__perm__" && $si->part2 === "") {
            $trx = $sv->oldv("{$si->parts[0]}{$si->parts[1]}");
            if ($trx && ($perm = Track::$perm_name_map[$si->part1] ?? null) !== null) {
                $sv->set_oldv($si->name, clone $trx->perm[$perm]);
            } else {
                $sv->set_oldv($si->name, new TrackPerm_Setting("", ""));
            }
        } else if (count($si->parts) === 5 && $si->parts[2] === "__perm__" && $si->part2 === "__title") {
            $sv->set_oldv($si->name, self::permission_title(Track::$perm_name_map[$si->part1] ?? null));
        }
    }

    function prepare_enumeration(SettingValues $sv, Si $si) {
        if (count($si->parts) === 3) {
            $trs = [];
            foreach ($sv->conf->track_tags() as $tag) {
                $trs[$tag] = true;
            }
            $trs["none"] = true;
            $sv->map_enumeration("track__", $trs);
        }
    }

    const PERM_DEFAULT_UNFOLDED = 1;

    /** @param SettingValues $sv
     * @param string $permname
     * @param string|array{string,string} $label
     * @param int $flags */
    function print_perm($sv, $permname, $label, $flags = 0) {
        $perm = Track::$perm_name_map[$permname];
        $deftype = Track::perm_required($perm) ? "none" : "";
        $trx = $sv->oldv("track__{$this->ctr}");
        $pfx = "track__{$this->ctr}__perm__{$permname}";
        $reqtype = $sv->reqstr("{$pfx}__type") ?? $trx->perm[$perm]->type;
        $reqtag = $sv->reqstr("{$pfx}__tag") ?? $trx->perm[$perm]->tag;

        $unfolded = $trx->perm[$perm]->type !== $deftype
            || $reqtype !== $deftype
            || (($flags & self::PERM_DEFAULT_UNFOLDED) !== 0 && $trx->is_empty())
            || $sv->problem_status_at("track__{$this->ctr}");
        if (!$unfolded) {
            ++$this->nfolded;
        }

        $permts = ["" => "Whole PC", "+" => "PC members with tag", "-" => "PC members without tag", "none" => "Administrators only"];
        if (Track::perm_required($perm)) {
            $permts = ["none" => $permts["none"], "+" => $permts["+"], "-" => $permts["-"]];
        }

        $hint = "";
        if (is_array($label)) {
            list($label, $hint) = $label;
        }

        echo '<div class="', $sv->control_class("{$pfx}__type", "entryi wide"),
            ' has-fold fold', $reqtype === "" || $reqtype === "none" ? "c" : "o",
            $unfolded ? "" : " fx3",
            '" data-fold-values="+ -">',
            $sv->label(["{$pfx}__type", "{$pfx}__tag"], $label),
            '<div class="entry">',
            Ht::select("{$pfx}__type", $permts, $reqtype, $sv->sjs("{$pfx}__type", ["class" => "uich js-foldup"])),
            " &nbsp;",
            Ht::entry("{$pfx}__tag", $reqtag, $sv->sjs("{$pfx}__tag", ["class" => "fx need-suggest pc-tags"]));
        $sv->print_feedback_at("{$pfx}__type");
        $sv->print_feedback_at("{$pfx}__tag");
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
        $trx = $sv->oldv("track__{$ctr}");
        echo '<div id="track__', $ctr, '" class="mg has-fold ',
            $trx->is_new ? "fold3o" : "fold3c", '">',
            Ht::hidden("track__{$ctr}__id",
                $trx->is_default ? "none" : ($trx->is_new ? "\$" : $trx->tag),
                ["data-default-value" => $trx->is_new ? "" : null]),
            '<div class="settings-tracks"><div class="entryg">';
        if ($trx->is_default) {
            echo "For submissions not on other tracks:";
        } else {
            echo $sv->label("track__{$ctr}__tag", "For submissions with tag", ["class" => "mr-2"]),
                $sv->entry("track__{$ctr}__tag", ["class" => "settings-track-name need-suggest tags", "spellcheck" => false]),
                ':';
        }
        echo '</div>';

        $sv->print_group("tracks/permissions");

        if ($this->nfolded) {
            echo '<div class="entryi wide fn3">',
                '<label><a href="" class="ui js-foldup q" data-fold-target="3">',
                expander(true, 3), 'More…</a></label>',
                '<div class="entry"><a href="" class="ui js-foldup q" data-fold-target="3">',
                $sv->conf->_("(%d more permissions have default values)", $this->nfolded),
                '</a></div></div>';
        }
        echo "</div></div>\n\n";
    }

    private function print_cross_track(SettingValues $sv) {
        echo "<div class=\"settings-tracks\"><div class=\"entryg\">General permissions:</div>";
        $this->ctr = $sv->search_enumeration("track__", "__id", "none");
        $this->print_perm($sv, "viewtracker", "Who can see the <a href=\"" . $sv->conf->hoturl("help", "t=chair#meeting") . "\">meeting tracker</a>?", self::PERM_DEFAULT_UNFOLDED);
        echo "</div>\n\n";
    }

    function print(SettingValues $sv) {
        echo "<p>Tracks control the PC members allowed to view or review different sets of submissions. <span class=\"nw\">(<a href=\"" . $sv->conf->hoturl("help", "t=tracks") . "\">Help</a>)</span></p>",
            Ht::hidden("has_tracks", 1);

        foreach ($sv->enumerate("track__") as $ctr) {
            $this->print_track($sv, $ctr);
        }
        $this->print_cross_track($sv);

        if ($sv->editable("tracks")) {
            echo '<template id="settings-track-new" class="hidden">';
            $this->print_track($sv, '$');
            echo '</template>',
                Ht::button("Add track", ["class" => "ui js-settings-track-add", "id" => "settings_track_add"]);
        }
    }


    private function _apply_req_perm(SettingValues $sv, Si $si) {
        $pfx = $si->part0 . $si->part1;
        $type = $sv->base_parse_req("{$pfx}__type");
        $tagsi = $sv->si("{$pfx}__tag");
        $tag = $type === "+" || $type === "-" ? $tagsi->parse_vstr($sv->vstr($tagsi), $sv) : "";
        $perm = Track::$perm_name_map[$si->part1];
        if ($type === "" || ($type === "+" && $tag === "")) {
            $pv = null;
        } else if ($type === "none" || ($type === "-" && $tag === "")) {
            $pv = "+none";
        } else {
            $pv = $type . $tag;
        }
        if (Track::perm_required($perm) && $pv === "+none") {
            $pv = null;
        }
        if ($pv === null) {
            unset($this->cur_trx->j->{$si->part1});
        } else {
            $this->cur_trx->j->{$si->part1} = $pv;
        }
    }

    function apply_req(SettingValues $sv, Si $si) {
        if (count($si->parts) === 5
            && $si->parts[2] === "__perm__"
            && ($si->part2 === "__type" || $si->part2 === "__tag")) {
            if ($si->part2 === "__type" || !$sv->has_req("{$si->part0}{$si->part1}__type")) {
                $this->_apply_req_perm($sv, $si);
            }
            return true;
        } else if ($si->name === "tracks") {
            $j = [];
            foreach ($sv->enumerate("track__") as $ctr) {
                $this->cur_trx = $sv->parse_members("track__{$ctr}");
                if (!$sv->reqstr("track__{$ctr}__delete")) {
                    if (!$this->cur_trx->is_default) {
                        $sv->error_if_missing("track__{$ctr}__tag");
                        $sv->error_if_duplicate_member("track__", $ctr, "__tag", "Track tag");
                        if ($this->cur_trx->tag === "_") {
                            $sv->error_at("track__{$ctr}__tag", "<0>Track name ‘_’ is reserved");
                        }
                    }
                    foreach ($sv->si_req_members("track__{$ctr}__perm__", true) as $permsi) {
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
            }
            $sv->update("tracks", empty($j) ? "" : json_encode_db($j));
            return true;
        } else {
            return false;
        }
    }

    static function crosscheck(SettingValues $sv) {
        $conf = $sv->conf;
        $tracks_interest = $sv->has_interest("tracks");
        if (($tracks_interest || $sv->has_interest("pcrev_any"))
            && $conf->has_tracks()) {
            foreach ($sv->enumerate("track__") as $ctr) {
                if (($id = $sv->reqstr("track__{$ctr}__id")) === "") {
                    continue;
                }
                $tr = $conf->track($id === "none" ? "" : $id);
                if ($tr->perm[Track::VIEWPDF]
                    && $tr->perm[Track::VIEWPDF] !== $tr->perm[Track::UNASSREV]
                    && $tr->perm[Track::UNASSREV] !== "+none"
                    && $tr->perm[Track::VIEWPDF] !== $tr->perm[Track::VIEW]
                    && $conf->setting("pcrev_any")) {
                    $sv->warning_at("track__{$ctr}__perm__unassrev__type", "<0>A track that restricts who can see documents should generally restrict review self-assignment in the same way.");
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
                        $sv->warning_at("track__{$ctr}__perm__assrev__type");
                        $sv->warning_at("track__{$ctr}__perm__unassrev__type", "<0>No PC members match both review assignment permissions, so no PC members can self-assign reviews.");
                    }
                }
                foreach ($tr->perm as $perm => $pv) {
                    if ($pv !== null
                        && $pv !== "+none"
                        && ($perm !== Track::VIEWPDF || $pv !== $tr->perm[Track::VIEW])
                        && !$conf->pc_tag_exists(substr($pv, 1))
                        && $tracks_interest)
                        $sv->warning_at("track__{$ctr}__perm__" . Track::perm_name($perm) . "__tag", "<0>No PC member has tag ‘" . substr($pv, 1) . "’. You might want to check your spelling.");
                }
            }
        }
    }
}
