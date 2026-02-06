<?php
// settings/s_track.php -- HotCRP settings > tracks page
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Track_Setting {
    /** @var string */
    public $id;
    /** @var string */
    public $tag;
    /** @var bool */
    public $is_default;
    /** @var bool */
    public $is_new;
    /** @var array<string,string> */
    public $permmap = [];
    /** @var object */
    public $j;

    /** @var bool */
    public $deleted = false;

    function __construct(Track $tr, $j) {
        $this->id = $this->tag = $tr->is_default ? "any" : $tr->tag;
        $this->is_default = $tr->is_default;
        $this->is_new = $this->id === "";
        $this->j = $j ?? (object) [];
        foreach ($tr->perm as $right => $perm) {
            // undo defaulting
            if ($right === Track::VIEWPDF
                && $perm === $tr->perm[Track::VIEW]
                && !isset($this->j->viewpdf)) {
                $perm = null;
            }
            $this->set_perm(Track::unparse_right($right), $perm);
        }
    }

    /** @param string $right
     * @return string */
    function perm($right) {
        $perm = $this->permmap[$right] ?? null;
        if ($perm === null) {
            $perm = Track::right_name_required($right) ? "none" : "all";
        }
        return $perm;
    }

    /** @param string $right
     * @param ?string $perm
     * @return $this */
    function set_perm($right, $perm) {
        if (($perm === null && Track::right_name_required($right))
            || $perm === "+none") {
            $perm = "none";
        } else if ($perm === null || $perm === "") {
            $perm = "all";
        }
        $this->permmap[$right] = $perm;
        return $this;
    }

    /** @return bool */
    function is_empty() {
        foreach ($this->permmap as $right => $p) {
            if ($p !== (Track::right_name_required($right) ? "none" : "all"))
                return false;
        }
        return !$this->is_new;
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

    static function right_title($right) {
        $rightid = Track::parse_right($right);
        if ($rightid === Track::VIEW) {
            return "submission visibility";
        } else if ($rightid === Track::VIEWPDF) {
            return "document visibility";
        } else if ($rightid === Track::VIEWREV) {
            return "review visibility";
        } else if ($rightid === Track::VIEWREVID) {
            return "reviewer name";
        } else if ($rightid === Track::ASSREV) {
            return "assignment";
        } else if ($rightid === Track::SELFASSREV) {
            return "self-assignment";
        } else if ($rightid === Track::ADMIN) {
            return "administrator";
        } else if ($rightid === Track::HIDDENTAG) {
            return "hidden-tag visibility";
        } else if ($rightid === Track::VIEWALLREV) {
            return "review visibility";
        } else if ($right === "viewtracker") {
            return "tracker visibility";
        }
        return "unknown";
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

    function member_list(Si $si, SettingValues $sv) {
        if (!$si->name_matches("track/", "*", "/perm")) {
            return null;
        }
        $sis = [];
        for ($i = 0; $i < Track::NPERM; ++$i) {
            $sis[] = $si->conf->si("{$si->name}/" . Track::unparse_right($i));
        }
        if ($sv->oldv("{$si->name0}{$si->name1}/id") === "any") {
            $sis[] = $si->conf->si("{$si->name}/viewtracker");
            foreach ($si->conf->xtracks() as $right => $perm) {
                if ($right !== "viewtracker")
                    $sis[] = $si->conf->si("{$si->name}/{$right}");
            }
        }
        return $sis;
    }

    function default_value(Si $si, SettingValues $sv) {
        if ($si->name_matches("track/", "*", "/perm/", "*")
            || $si->name_matches("track/", "*", "/perm/", "*", "/tag")) {
            return Track::right_name_required($si->name1) ? "none" : "all";
        }
        return null;
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
            $p = $trx !== null ? $trx->perm($si->name1) : "all";
            if ($si->name2 === "") {
                $sv->set_oldv($si->name, $p);
            } else if ($si->name2 === "/type") {
                $sv->set_oldv($si->name, Track_Setting::perm_type($p));
            } else if ($si->name2 === "/tag") {
                $sv->set_oldv($si->name, Track_Setting::perm_tag($p));
            } else if ($si->name2 === "/title") {
                $sv->set_oldv($si->name, Track_Setting::right_title($si->name1));
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
            $m[] = $ts = new Track_Setting($sv->conf->track("") ?? new Track(""),
                                           $this->settings_json->_ ?? null);
            foreach ($sv->conf->xtracks() as $right => $perm) {
                $ts->set_perm($right, $perm);
            }
            $sv->append_oblist("track", $m, "tag");
        }
    }

    /** @return ?Track_Setting */
    function current_track_setting() {
        return $this->cur_trx;
    }

    const PERM_DEFAULT_UNFOLDED = 1;

    /** @param SettingValues $sv
     * @param string $right
     * @param string|array{string,string} $label
     * @param int $flags */
    function print_perm($sv, $right, $label, $flags = 0) {
        $deftype = Track::right_name_required($right) ? "none" : "all";
        $trx = $this->cur_trx;
        $pfx = "track/{$this->ctr}/perm/{$right}";
        $p = $sv->reqstr($pfx) ?? $trx->perm($right);
        $reqtype = $sv->reqstr("{$pfx}/type") ?? Track_Setting::perm_type($p);
        $reqtag = $sv->reqstr("{$pfx}/tag") ?? Track_Setting::perm_tag($p);

        $unfolded = Track_Setting::perm_type($trx->perm($right)) !== $deftype
            || $reqtype !== $deftype
            || (($flags & self::PERM_DEFAULT_UNFOLDED) !== 0 && $trx->is_empty())
            || $sv->problem_status_at("track/{$this->ctr}");
        if (!$unfolded) {
            ++$this->nfolded;
        }

        $permts = ["all" => "Whole PC", "+" => "PC members with tag", "-" => "PC members without tag", "none" => "Administrators only"];
        if ($deftype === "none") {
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
            echo str_starts_with($hint, "<") ? $hint : "<div class=\"f-d\">{$hint}</div>";
        }
        echo "</div></div>";
    }

    function print_view_perm(SettingValues $sv, $gj) {
        $this->print_perm($sv, "view", "Who can see these submissions?", self::PERM_DEFAULT_UNFOLDED);
    }

    function print_viewrev_perm(SettingValues $sv, $gj) {
        $hint = "<div class=\"f-d fx\">This setting constrains all users including co-reviewers.</div>";
        $this->print_perm($sv, "viewrev", ["Who can see reviews?", $hint]);
    }

    private function print_track(SettingValues $sv, $ctr) {
        $this->ctr = $ctr;
        $this->cur_trx = $trx = $sv->oldv("track/{$ctr}");
        $this->nfolded = 0;
        echo '<fieldset id="track/', $ctr, '" class="settings-tracks has-fold ',
            $trx->is_new ? "fold3o" : "fold3c", '">',
            '<legend class="mb-1">';
        if ($trx->is_default) {
            echo 'Submissions not on other tracks';
        } else {
            echo 'Track #', $sv->entry("track/{$ctr}/tag", ["class" => "settings-track-name need-suggest tags want-delete-marker ml-1", "spellcheck" => false, "autocomplete" => "off"]),
                Ht::button(Icons::ui_use("trash"), ["id" => "track/{$ctr}/deleter", "class" => "ui js-settings-track-delete ml-2 btn-licon-s need-tooltip", "aria-label" => "Delete track", "tabindex" => -1]),
                $sv->feedback_at("track/{$ctr}/tag");
            if ($sv->reqstr("track/{$ctr}/delete")) {
                echo Ht::hidden("track/{$ctr}/delete", "1", ["data-default-value" => ""]);
            }
        }
        echo '</legend>',
            Ht::hidden("track/{$ctr}/id", $trx->tag, ["data-default-value" => $trx->is_new ? "" : null]),
            '<div id="track/', $ctr, '/edit">';

        $sv->print_members("tracks/permissions");

        if ($this->nfolded) {
            echo '<div class="entryi wide fn3">',
                '<label><button type="button" class="q ui js-foldup" data-fold-target="3">',
                expander(true, 3), 'More…</button></label>',
                '<div class="entry"><button type="button" class="q ui js-foldup" data-fold-target="3">',
                $sv->conf->_("({} more permissions have default values)", $this->nfolded),
                '</button></div></div>';
        }
        echo "</div></fieldset>\n\n";
        $this->cur_trx = null;
    }

    private function print_cross_track(SettingValues $sv) {
        echo "<fieldset class=\"settings-tracks\"><legend class=\"mb-1\">General permissions</legend>";
        $this->ctr = $sv->search_oblist("track", "id", "any");
        $this->cur_trx = $sv->oldv("track/{$this->ctr}");
        $this->print_perm($sv, "viewtracker", "Who can see the <a href=\"" . $sv->conf->hoturl("help", "t=chair#meeting") . "\">meeting tracker</a>?", self::PERM_DEFAULT_UNFOLDED);
        echo "</fieldset>\n\n";
    }

    function print(SettingValues $sv) {
        echo "<p>Tracks offer fine-grained permission control over submissions with specific tags. <span class=\"nw\">(<a href=\"" . $sv->conf->hoturl("help", "t=tracks") . "\">Help</a>)</span></p>",
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
            $pv = Track::right_name_required($si->name1) ? null : "+none";
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
        if ($pv === null) {
            unset($this->cur_trx->j->{$si->name1});
        } else {
            $this->cur_trx->j->{$si->name1} = $pv;
        }
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name_matches("track/", "*", "/perm/", "*", "*")
            && ($si->name2 === "" || $si->name2 === "/type" || $si->name2 === "/tag")) {
            if ($si->name2 !== "/tag" || !$sv->has_req("{$si->name0}{$si->name1}/type")) {
                $this->_apply_req_perm($si, $sv);
            }
            return true;
        }
        if ($si->name === "track") {
            $j = [];
            foreach ($sv->oblist_nondeleted_keys("track") as $ctr) {
                $this->cur_trx = $sv->newv("track/{$ctr}");
                if (!$this->cur_trx->is_default) {
                    $sv->error_if_missing("track/{$ctr}/tag");
                    $sv->error_if_duplicate_member("track", $ctr, "tag", "Track tag");
                    if (!$sv->has_error_at("track/{$ctr}/tag")
                        && ($this->cur_trx->tag === "_"
                            || !$sv->tagger()->check($this->cur_trx->tag, Tagger::NOVALUE))) {
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
            if ($sv->update("tracks", empty($j) ? "" : json_encode_db($j))) {
                $sv->request_store_value($si);
            }
            return true;
        }
        return false;
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
                    && $tr->perm[Track::VIEWPDF] !== $tr->perm[Track::SELFASSREV]
                    && $tr->perm[Track::SELFASSREV] !== "+none"
                    && $tr->perm[Track::VIEWPDF] !== $tr->perm[Track::VIEW]
                    && $conf->allow_self_assignment()) {
                    $sv->warning_at("track/{$ctr}/perm/unassrev", "<0>A track that restricts who can see documents should generally restrict review self-assignment in the same way.");
                }
                if ($tr->perm[Track::ASSREV]
                    && $tr->perm[Track::SELFASSREV]
                    && $tr->perm[Track::SELFASSREV] !== "+none"
                    && $tr->perm[Track::ASSREV] !== $tr->perm[Track::SELFASSREV]
                    && $conf->allow_self_assignment()) {
                    $n = 0;
                    foreach ($conf->pc_members() as $pc) {
                        if ($pc->has_permission($tr->perm[Track::ASSREV])
                            && $pc->has_permission($tr->perm[Track::SELFASSREV]))
                            ++$n;
                    }
                    if ($n === 0) {
                        $sv->warning_at("track/{$ctr}/perm/assrev");
                        $sv->warning_at("track/{$ctr}/perm/unassrev", "<0>No PC members match both review assignment permissions, so no PC members can self-assign reviews.");
                    }
                }
                foreach ($tr->perm as $right => $p) {
                    if ($p !== null
                        && $p !== "+none"
                        && ($right !== Track::VIEWPDF || $p !== $tr->perm[Track::VIEW])
                        && !$conf->pc_tag_exists(substr($p, 1))
                        && $tracks_interest) {
                        $pn = Track::unparse_right($right);
                        $sv->warning_at("track/{$ctr}/perm/{$pn}", "<0>No PC member has tag ‘" . substr($p, 1) . "’. You might want to check your spelling.");
                        $sv->warning_at("track/{$ctr}/perm/{$pn}/tag");
                    }
                }
            }
        }
    }
}
