<?php
// settings/s_response.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Response_Setting {
    /** @var int */
    public $id;
    /** @var string */
    public $name;
    /** @var ?int */
    public $open;
    /** @var ?int */
    public $done;
    /** @var ?int */
    public $grace;
    /** @var ?int */
    public $wordlimit;
    /** @var ?int */
    public $hard_wordlimit;
    /** @var string */
    public $condition = "all";
    /** @var string */
    public $instructions;

    private $old_wordlimit; // needed to determine correct default_instructions
    /** @var bool */
    public $deleted = false;

    /** @return string */
    function default_instructions(Conf $conf) {
        return $conf->fmt()->default_translation("resp_instrux", new FmtArg("wordlimit", $this->old_wordlimit));
    }

    /** @return Response_Setting */
    static function make(Conf $conf, ResponseRound $rrd) {
        $rs = new Response_Setting;
        $rs->id = $rrd->id;
        $rs->name = $rrd->unnamed ? "" : $rrd->name;
        $rs->open = $rrd->open;
        $rs->done = $rrd->done;
        $rs->grace = $rrd->grace;
        $rs->wordlimit = $rs->old_wordlimit = $rrd->wordlimit;
        $rs->hard_wordlimit = $rrd->hard_wordlimit;
        $rs->condition = $rrd->condition ?? "all";
        $rs->instructions = $rrd->instructions ?? $rs->default_instructions($conf);
        return $rs;
    }

    /** @return Response_Setting */
    static function make_new(Conf $conf) {
        $rs = new Response_Setting;
        $rs->name = "";
        $rs->wordlimit = $rs->old_wordlimit = 500;
        $rs->condition = "all";
        $rs->instructions = $rs->default_instructions($conf);
        return $rs;
    }

    /** @return object */
    function unparse_json(Conf $conf) {
        $j = (object) [];
        if ($this->name !== "" && $this->name !== "unnamed") {
            $j->name = $this->name;
        }
        if ($this->open > 0) {
            $j->open = $this->open;
        }
        if ($this->done > 0) {
            $j->done = $this->done;
        }
        if ($this->grace > 0) {
            $j->grace = $this->grace;
        }
        $wl = $this->wordlimit ?? 0;
        if (($this->hard_wordlimit ?? 0) > 0) {
            $j->wl = $wl <= 0 ? $this->hard_wordlimit : $wl;
            $j->hwl = $this->hard_wordlimit;
        } else if ($wl !== 500) {
            $j->wl = $wl;
        }
        if (($this->condition ?? "") !== ""
            && $this->condition !== "all") {
            $j->condition = $this->condition;
        }
        if (($this->instructions ?? "") !== ""
            && $this->instructions !== $this->default_instructions($conf)) {
            $j->instructions = $this->instructions;
        }
        return $j;
    }
}

class Response_SettingParser extends SettingParser {
    /** @var int|'$' */
    public $ctr;
    /** @var ?int */
    public $ctrid;
    /** @var array<int,int> */
    private $round_counts;
    /** @var list<string> */
    private $round_transform = [];
    /** @var list<int> */
    private $round_delete = [];

    function placeholder(Si $si, SettingValues $sv) {
        if ($si->name0 === "response/" && $si->name2 === "/name") {
            if (!ctype_digit($sv->vstr("response/{$si->name1}/id"))) {
                return "(new response)";
            } else {
                return "unnamed";
            }
        } else {
            return null;
        }
    }

    function default_value(Si $si, SettingValues $sv) {
        if ($si->name0 === "response/" && $si->name2 === "/instructions") {
            $n = $sv->oldv("response/{$si->name1}/wordlimit");
            return $sv->conf->fmt()->default_translation("resp_instrux", new FmtArg("wordlimit", $n));
        } else {
            return null;
        }
    }

    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name0 !== null) {
            if ($si->name2 === "") {
                $sv->set_oldv($si, Response_Setting::make_new($sv->conf));
            } else if ($si->name2 === "/title") {
                $n = $sv->vstr("response/{$si->name1}/name");
                $sv->set_oldv($si, $n ? "‘{$n}’ response" : "Response");
            }
        }
    }

    function prepare_oblist(Si $si, SettingValues $sv) {
        $m = [];
        foreach ($sv->conf->response_rounds() as $rrd) {
            $m[] = Response_Setting::make($sv->conf, $rrd);
        }
        $sv->append_oblist("response", $m, "name");
    }

    /** @param ?int $ctrid
     * @return ?int */
    private function exists_count(Conf $conf, $ctrid) {
        if ($ctrid === null) {
            return null;
        }
        if ($this->round_counts === null) {
            $this->round_counts = Dbl::fetch_iimap($conf->dblink, "select commentRound, count(*) from PaperComment where commentType>=" . CommentInfo::CTVIS_AUTHOR . " and (commentType&" . CommentInfo::CT_RESPONSE . ")!=0 group by commentRound");
        }
        return $this->round_counts[$ctrid] ?? null;
    }

    function print_name(SettingValues $sv) {
        $t = Ht::button(Icons::ui_use("trash"), ["class" => "ui js-settings-response-delete ml-2 need-tooltip", "name" => "response/{$this->ctr}/deleter", "aria-label" => "Delete response", "tabindex" => -1]);
        if (($n = $this->exists_count($sv->conf, $this->ctrid))) {
            $t .= '<span class="ml-3 d-inline-block">' . plural($n, "response") . '</span>';
        }
        $sv->print_entry_group("response/{$this->ctr}/name", "Response name", [
            "class" => "uii js-settings-response-name want-delete-marker",
            "horizontal" => true,
            "control_after" => $t,
            "hint" => is_int($this->ctr) && $this->ctr > 1 ? null : "Use no name or a short name like ‘Rebuttal’."
        ]);
    }

    function print_deadline(SettingValues $sv) {
        if ($sv->vstr("response/{$this->ctr}/open") == 1
            && ($x = $sv->vstr("response/{$this->ctr}/done"))) {
            $sv->conf->settings["response/{$this->ctr}/open"] = intval($x) - 7 * 86400;
        }
        $sv->print_entry_group("response/{$this->ctr}/open", "Start time", ["horizontal" => true]);
        $sv->print_entry_group("response/{$this->ctr}/done", "Hard deadline", ["horizontal" => true]);
        $sv->print_entry_group("response/{$this->ctr}/grace", "Grace period", ["horizontal" => true]);
    }

    function print_wordlimit(SettingValues $sv) {
        $sv->print_entry_group("response/{$this->ctr}/wordlimit", "Word limit", [
            "horizontal" => true,
            "hint" => is_int($this->ctr) && $this->ctr > 1 ? null : "This is a soft limit: authors may submit longer responses. 0 means no limit."
        ]);
    }

    function print_instructions(SettingValues $sv) {
        $sv->print_message_horizontal("response/{$this->ctr}/instructions", "Instructions");
    }

    function print_one(SettingValues $sv, $ctr) {
        $this->ctr = $ctr;
        if ($ctr !== "\$" && ($id = $sv->vstr("response/{$ctr}/id")) !== "new" && $id !== "\$") {
            $this->ctrid = intval($id);
        } else {
            $this->ctrid = null;
        }
        echo '<div id="response/', $ctr, '" class="form-g settings-response';
        if ($this->ctrid === null) {
            echo ' is-new';
        } else {
            echo '" data-exists-count="', $this->exists_count($sv->conf, $this->ctrid) ?? 0;
        }
        echo '">', Ht::hidden("response/{$ctr}/id", $this->ctrid ?? "new", ["data-default-value" => $this->ctrid === null ? "" : null]);
        if ($sv->has_req("response/{$ctr}/delete")) {
            Ht::hidden("response/{$ctr}/delete", "1", ["data-default-value" => ""]);
        }
        $sv->print_members("responses/properties");
        echo '</div>';
    }

    function print(SettingValues $sv) {
        // Authors' response
        $sv->print_checkbox("response_active", '<strong>Collect authors’ responses to the reviews<span class="if-response-active">:</span></strong>', ["group_open" => true, "class" => "uich js-settings-resp-active"]);
        Icons::stash_defs("trash");
        echo Ht::unstash(), Ht::hidden("response_requires_active", 1),
            '<div class="if-response-active',
            $sv->vstr("response_active") ? "" : " hidden",
            '"><hr class="g">', Ht::hidden("has_response", 1);

        foreach ($sv->oblist_keys("response") as $ctr) {
            $this->print_one($sv, $ctr);
        }

        echo '<template id="new_response" class="hidden">';
        $this->print_one($sv, '$');
        echo '</template>';
        if ($sv->editable("response/0/name")) {
            echo '<hr class="form-sep">',
                Ht::button("Add response", ["class" => "ui js-settings-response-new"]);
        }

        echo '</div></div>';
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "response") {
            return $this->apply_response_req($si, $sv);
        } else if ($si->name2 === "/name") {
            if (($v = $sv->base_parse_req($si)) !== null) {
                $lv = strtolower($v);
                if ($lv === "1" || $lv === "unnamed" || $lv === "none") {
                    $sv->set_req($si->name, "");
                } else if ($v !== "" && ($err = Conf::response_round_name_error($v))) {
                    $sv->error_at($si->name, "<0>{$err}");
                }
            }
            return false;
        } else if ($si->name2 === "/condition") {
            if (($v = $sv->base_parse_req($si)) !== "" && $v !== "all") {
                $search = new PaperSearch($sv->conf->root_user(), $v);
                foreach ($search->message_list() as $mi) {
                    $sv->append_item_at($si->name, $mi);
                }
            }
            return false;
        } else {
            return false;
        }
    }

    function apply_response_req(Si $si, SettingValues $sv) {
        // ignore changes to response settings if active checkbox is off
        if ($sv->reqstr("response_requires_active") && !$sv->newv("response_active")) {
            return true;
        }

        $rss = [];
        foreach ($sv->oblist_keys("response") as $ctr) {
            $rs = $sv->newv("response/{$ctr}");
            '@phan-var-force Response_Setting $rs';
            if ($rs->deleted) {
                $this->round_delete[] = $rs->id;
            } else {
                $sv->check_date_before("response/{$ctr}/open", "response/{$ctr}/done", false);
                array_splice($rss, $rs->name === "" ? 0 : count($rss), 0, [$rs]);
            }
        }

        // having parsed all names, check for duplicates
        foreach ($sv->oblist_keys("response") as $ctr) {
            $sv->error_if_duplicate_member("response", $ctr, "name", "Response name");
        }

        $jrl = [];
        foreach ($rss as $i => $rs) {
            $jrl[] = $rs->unparse_json($sv->conf);
            if ($rs->id !== null && $i + 1 !== $rs->id) {
                $this->round_transform[] = "when {$rs->id} then " . ($i + 1);
            }
        }

        $jrt = json_encode_db($jrl);
        $sv->update("responses", $jrt === "[{}]" || $jrt === "[]" ? "" : $jrt);
        if (!empty($this->round_transform) || !empty($this->round_delete)) {
            $sv->request_write_lock("PaperComment");
            $sv->request_store_value($si);
        }
        return true;
    }

    function store_value(Si $si, SettingValues $sv) {
        if (!empty($this->round_delete)) {
            $sv->conf->qe("update PaperComment set commentRound=0, commentType=(commentType&~?)|? where commentType>=? and (commentType&?)!=0 and commentRound?a",
                CommentInfo::CT_RESPONSE | CommentInfo::CTVIS_MASK,
                CommentInfo::CT_FROZEN | CommentInfo::CTVIS_ADMINONLY,
                CommentInfo::CTVIS_AUTHOR,
                CommentInfo::CT_RESPONSE,
                $this->round_delete);
            $sv->mark_diff("response_deleted");
        }
        if (!empty($this->round_transform)) {
            $sv->conf->qe("update PaperComment set commentRound=case commentRound " . join(" ", $this->round_transform) . " else commentRound end where commentType>=? and (commentType&?)!=0",
                CommentInfo::CTVIS_AUTHOR,
                CommentInfo::CT_RESPONSE);
        }
    }

    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("response")) {
            foreach ($sv->conf->response_rounds() as $i => $rrd) {
                $ctr = $i + 1;
                if ($rrd->hard_wordlimit > 0
                    && $rrd->wordlimit > $rrd->hard_wordlimit) {
                    $sv->error_at("response/{$ctr}/wordlimit", "<0>Word limit cannot be larger than hard word limit");
                    $sv->error_at("response/{$ctr}/hard_wordlimit", null);
                }
                if ($rrd->condition !== null) {
                    $s = new PaperSearch($sv->conf->root_user(), $rrd->condition);
                    foreach ($s->message_list() as $mi) {
                        $sv->append_item_at("response/{$ctr}/condition", $mi);
                    }
                }
            }
        }
    }
}
