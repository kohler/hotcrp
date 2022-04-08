<?php
// settings/s_responses.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Responses_SettingParser extends SettingParser {
    /** @var int|'$' */
    public $ctr;
    /** @var ?int */
    public $ctrid;
    /** @var array<int,int> */
    private $round_counts;
    /** @var list<string> */
    private $round_transform = [];

    function set_oldv(SettingValues $sv, Si $si) {
        if ($si->part0 !== null) {
            if ($si->part2 === "") {
                $rrd = $sv->unmap_enumeration_member($si->name, $sv->conf->response_rounds());
                $sv->set_oldv($si->name, $rrd ? clone $rrd : new ResponseRound);
            } else {
                $rrd = $sv->oldv($si->part0 . $si->part1);
                if ($si->part2 === "__title") {
                    $n = $sv->oldv("response__{$si->part1}__name");
                    $sv->set_oldv($si->name, $n ? "‘{$n}’ response" : "Response");
                } else if ($si->part2 === "__name") {
                    $sv->set_oldv($si->name, ($rrd->unnamed ? "" : $rrd->name) ?? "");
                } else if ($si->part2 === "__condition") {
                    $sv->set_oldv($si->name, $rrd->search ? $rrd->search->q : "");
                } else if ($si->part2 === "__instructions") {
                    $sv->set_oldv($si->name, $rrd->instructions ?? $si->default_value($sv));
                }
            }
        }
    }

    function prepare_enumeration(SettingValues $sv, Si $si) {
        $sv->map_enumeration("response__", $sv->conf->response_rounds());
        // set placeholder for unnamed round
        $rrd = ($sv->conf->response_rounds())[0] ?? null;
        if ($rrd && $rrd->unnamed) {
            $ctr = $sv->search_enumeration_id("response__", "0");
            assert($ctr !== null);
            $si = $sv->si("response__{$ctr}__name");
            $si->placeholder = "unnamed";
        }
    }

    private function ensure_round_counts(Conf $conf) {
        if ($this->round_counts === null) {
            $this->round_counts = Dbl::fetch_iimap($conf->dblink, "select commentRound, count(*) from PaperComment where commentType>=" . CommentInfo::CT_AUTHOR . " and (commentType&" . CommentInfo::CT_RESPONSE . ")!=0 group by commentRound");
        }
    }

    function print_name(SettingValues $sv) {
        $t = Ht::button(Icons::ui_use("trash"), ["class" => "ui js-settings-response-delete ml-2 need-tooltip", "aria-label" => "Delete response", "tabindex" => -1]);
        if ($this->ctrid !== null) {
            $this->ensure_round_counts($sv->conf);
            if (($n = $this->round_counts[$this->ctrid] ?? null)) {
                $t .= '<span class="ml-2 d-inline-block">' . plural($n, "response") . '</span>';
            }
        }
        $sv->print_entry_group("response__{$this->ctr}__name", "Response name", [
            "class" => "uii js-settings-response-name",
            "horizontal" => true, "control_after" => $t
        ], is_int($this->ctr) && $this->ctr > 1 ? null : "Use no name or a short name like ‘Rebuttal’.");
    }

    function print_deadline(SettingValues $sv) {
        if ($sv->vstr("response__{$this->ctr}__open") == 1
            && ($x = $sv->vstr("response__{$this->ctr}__done"))) {
            $sv->conf->settings["response__{$this->ctr}__open"] = intval($x) - 7 * 86400;
        }
        $sv->print_entry_group("response__{$this->ctr}__open", "Start time", ["horizontal" => true]);
        $sv->print_entry_group("response__{$this->ctr}__done", "Hard deadline", ["horizontal" => true]);
        $sv->print_entry_group("response__{$this->ctr}__grace", "Grace period", ["horizontal" => true]);
    }

    function print_wordlimit(SettingValues $sv) {
        $sv->print_entry_group("response__{$this->ctr}__words", "Word limit", ["horizontal" => true], is_int($this->ctr) && $this->ctr > 1 ? null : "This is a soft limit: authors may submit longer responses. 0 means no limit.");
    }

    function print_instructions(SettingValues $sv) {
        $sv->print_message_horizontal("response__{$this->ctr}__instructions", "Instructions");
    }

    function print_one(SettingValues $sv, $ctr) {
        $this->ctr = $ctr;
        if ($ctr !== '$' && ($id = $sv->vstr("response__{$ctr}__id")) !== '$') {
            $this->ctrid = intval($id);
        } else {
            $this->ctrid = null;
        }
        echo '<div id="response__', $ctr, '" class="form-g settings-response',
            $this->ctrid === null ? " is-new" : "", '">',
            Ht::hidden("response__{$ctr}__id", $this->ctrid ?? '$', ["data-default-value" => $this->ctrid === null ? "" : null]);
        if ($sv->has_req("response__{$ctr}__delete")) {
            Ht::hidden("response__{$ctr}__delete", "1", ["data-default-value" => ""]);
        }
        $sv->print_group("responses/properties");
        echo '</div>';
    }

    function print(SettingValues $sv) {
        // Authors' response
        $sv->print_checkbox("response_active", '<strong>Collect authors’ responses to the reviews<span class="if-response-active">:</span></strong>', ["group_open" => true, "class" => "uich js-settings-resp-active"]);
        Icons::stash_defs("trash");
        echo Ht::unstash(), '<div class="if-response-active',
            $sv->vstr("response_active") ? "" : " hidden",
            '"><hr class="g">', Ht::hidden("has_response", 1);

        foreach ($sv->enumerate("response__") as $ctr) {
            $this->print_one($sv, $ctr);
        }

        echo '<template id="response__new" class="hidden">';
        $this->print_one($sv, '$');
        echo '</template>';
        if ($sv->editable("response__0__name")) {
            echo '<hr class="form-sep">',
                Ht::button("Add response", ["class" => "ui js-settings-response-new"]);
        }

        echo '</div></div>';
    }

    function apply_req(SettingValues $sv, Si $si) {
        if ($si->name === "response") {
            return $this->apply_response_req($sv, $si);
        } else if ($si->part2 === "__name") {
            $rrd = $sv->cur_object;
            $rrd->name = trim($sv->reqstr($si->name));
            $lname = strtolower($rrd->name);
            if (in_array($lname, ["1", "unnamed", "none", ""], true)) {
                $rrd->name = $lname = "";
                $sv->set_req($si->name, $lname);
            } else if (($error = Conf::response_round_name_error($rrd->name))) {
                $sv->error_at($si->name, "<0>{$error}");
            }
            return true;
        } else if ($si->part2 === "__condition") {
            if (($v = $sv->base_parse_req($si)) === "") {
                $sv->cur_object->search = null;
            } else {
                $sv->cur_object->search = new PaperSearch($sv->conf->root_user(), $v);
                foreach ($sv->cur_object->search->message_list() as $mi) {
                    $sv->append_item_at($si->name, $mi);
                }
            }
            return true;
        } else {
            return false;
        }
    }

    function apply_response_req(SettingValues $sv, Si $si) {
        // ignore changes to response settings if active checkbox is off
        if ($sv->has_req("response_active") && !$sv->newv("response_active")) {
            return true;
        }

        $rrds = [];
        foreach ($sv->enumerate("response__") as $ctr) {
            $rrd = $sv->parse_members("response__{$ctr}");
            if ($sv->reqstr("response__{$ctr}__delete")) {
                if ($rrd->number) {
                    $this->round_transform[] = "when {$rrd->number} then 0";
                }
            } else {
                $sv->check_date_before("response__{$ctr}__open", "response__{$ctr}__done", false);
                array_splice($rrds, $rrd->name === "" ? 0 : count($rrds), 0, [$rrd]);
            }
        }

        // having parsed all names, check for duplicates
        foreach ($sv->enumerate("response__") as $ctr) {
            $sv->error_if_duplicate_member("response__", $ctr, "__name", "Response name");
        }

        $jrl = [];
        foreach ($rrds as $i => $rrd) {
            $jr = [];
            $rrd->name !== "" && $rrd->name !== "unnamed" && ($jr["name"] = $rrd->name);
            $rrd->open > 0 && ($jr["open"] = $rrd->open);
            $rrd->done > 0 && ($jr["done"] = $rrd->done);
            $rrd->grace > 0 && ($jr["grace"] = $rrd->grace);
            $rrd->words !== 500 && ($jr["words"] = $rrd->words ?? 0);
            $rrd->search && ($jr["condition"] = $rrd->search->q);
            ($rrd->instructions ?? "") !== "" && ($jr["instructions"] = $rrd->instructions);
            $jrl[] = $jr;
            if ($rrd->number !== null && $i !== $rrd->number) {
                $this->round_transform[] = "when {$rrd->number} then {$i}";
            }
        }

        $jrt = json_encode_db($jrl);
        if ($sv->update("responses", $jrt === "[{}]" || $jrt === "[]" ? "" : $jrt)
            && !empty($this->round_transform)) {
            $sv->request_write_lock("PaperComment");
            $sv->request_store_value($si);
        }
        return true;
    }

    function store_value(SettingValues $sv, Si $si) {
        $sv->conf->qe("update PaperComment set commentRound=case commentRound " . join(" ", $this->round_transform) . " else commentRound end where commentType>=" . CommentInfo::CT_AUTHOR . " and (commentType&" . CommentInfo::CT_RESPONSE . ")!=0");
    }

    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("response")) {
            foreach ($sv->conf->response_rounds() as $i => $rrd) {
                if ($rrd->search) {
                    foreach ($rrd->search->message_list() as $mi) {
                        $sv->append_item_at("response__" . ($i + 1) . "__condition", $mi);
                    }
                }
            }
        }
    }
}
