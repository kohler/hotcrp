<?php
// settings/s_responses.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Responses_SettingParser extends SettingParser {
    /** @var int|'$' */
    public $ctr;
    /** @var array<string,int> */
    private $roundname_ctr = [];
    /** @var list<string> */
    private $round_transform = [];

    function set_oldv(SettingValues $sv, Si $si) {
        if ($si->part0 !== null) {
            if ($si->part2 === "") {
                $rrd = $sv->object_list_lookup($sv->conf->resp_rounds(), $si->name);
                $sv->set_oldv($si->name, $rrd ? clone $rrd : new ResponseRound);
            } else {
                $rrd = $sv->oldv($si->part0 . $si->part1);
                if ($si->part2 === "__title") {
                    $n = $sv->oldv("response__{$si->part1}__name");
                    $sv->set_oldv($si->name, $n ? "‘{$n}’ response" : "Response");
                } else if ($si->part2 === "__name") {
                    if ($rrd->number !== null && $rrd->unnamed) {
                        $si->placeholder = "unnamed";
                    }
                    $sv->set_oldv($si->name, ($rrd->unnamed ? "" : $rrd->name) ?? "");
                } else if ($si->part2 === "__condition") {
                    $sv->set_oldv($si->name, $rrd->search ? $rrd->search->q : "");
                } else if ($si->part2 === "__instructions") {
                    $sv->set_oldv($si->name, $rrd->instructions($sv->conf));
                }
            }
        }
    }

    function set_object_list_ids(SettingValues $sv, Si $si) {
        $sv->map_object_list_ids($sv->conf->resp_rounds(), "response");
    }

    function print_name(SettingValues $sv) {
        $sv->print_entry_group("response__{$this->ctr}__name", "Response name", [
            "horizontal" => true,
            "control_after" => Ht::button(Icons::ui_use("trash"), ["class" => "ui js-settings-response-delete ml-2 need-tooltip", "aria-label" => "Delete response", "tabindex" => "-1"])
        ]);
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

    function print_one(SettingValues $sv) {
        $id = $sv->vstr("response__{$this->ctr}__id") ?? "new";
        echo '<div id="response__', $this->ctr, '" class="form-g settings-response',
            $id === "new" ? " is-new" : "", '">',
            Ht::hidden("response__{$this->ctr}__id", $id);
        if ($sv->has_req("response__{$this->ctr}__delete")) {
            Ht::hidden("response__{$this->ctr}__delete", "1", ["data-default-value" => ""]);
        }
        $sv->print_group("responses/properties");
        echo '</div>';
    }

    function print(SettingValues $sv) {
        // Authors' response
        echo '<div class="form-g">';
        $sv->print_checkbox("response_active", '<strong>Collect authors’ responses to the reviews<span class="if-response-active">:</span></strong>', ["group_open" => true, "class" => "uich js-settings-resp-active"]);
        Icons::stash_defs("trash");
        echo Ht::unstash(), '<div class="if-response-active',
            $sv->vstr("response_active") ? "" : " hidden",
            '"><hr class="g">', Ht::hidden("has_responses", 1);

        foreach ($sv->object_list_counters("response") as $ctr) {
            $this->ctr = $ctr;
            $this->print_one($sv);
        }

        echo '<template id="response__new" class="hidden">';
        $this->ctr = '$';
        $this->print_one($sv);
        echo '</template>';
        if ($sv->editable("response__0__name")) {
            echo '<div class="form-g">',
                Ht::button("Add response", ["class" => "ui js-settings-response-new"]),
                '</div>';
        }

        echo '</div></div></div>';
    }

    function apply_req(SettingValues $sv, Si $si) {
        if ($si->name === "responses") {
            return $this->apply_response_req($sv, $si);
        } else if ($si->part2 === "__name") {
            $rrd = $sv->cur_object;
            $rrd->name = trim($sv->reqstr($si->name));
            $lname = strtolower($rrd->name);
            if (in_array($lname, ["1", "unnamed", "none", ""], true)) {
                $rrd->name = $lname = "";
            } else if (($error = Conf::resp_round_name_error($rrd->name))) {
                $sv->error_at($si->name, "<0>{$error}");
            }
            if (($ectr = $this->roundname_ctr[$lname] ?? null) !== null) {
                $sv->error_at($si->name, "<0>Duplicate response name ‘" . ($lname ? : "unnamed") . "’");
                $sv->error_at("response__{$ectr}__name");
            } else {
                $this->roundname_ctr[$lname] = (int) $si->part1;
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
        if (!$sv->newv("response_active")) {
            return true;
        }

        $rrds = [];
        foreach ($sv->object_list_counters("response") as $ctr) {
            $rrd = $sv->parse_components("response__{$ctr}");
            if ($sv->reqstr("response__{$ctr}__delete")) {
                if ($rrd->number) {
                    $this->round_transform[] = "when {$rrd->number} then 0";
                }
            } else {
                $sv->check_date_before("response__{$ctr}__open", "response__{$ctr}__done", false);
                array_splice($rrds, $rrd->name === "" ? 0 : count($rrds), 0, [$rrd]);
            }
        }

        $jrl = [];
        foreach ($rrds as $i => $rrd) {
            $jr = [];
            $rrd->name !== "" && ($jr["name"] = $rrd->name);
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
        $sv->conf->qe("update PaperComment set commentRound=case " . join(" ", $this->round_transform) . " else commentRound end where (commentType&" . CommentInfo::CT_RESPONSE . ")!=0");
    }

    static function crosscheck(SettingValues $sv) {
        if ($sv->has_interest("responses")) {
            foreach ($sv->conf->resp_rounds() as $i => $rrd) {
                if ($rrd->search) {
                    foreach ($rrd->search->message_list() as $mi) {
                        $sv->append_item_at("response__" . ($i + 1) . "__condition", $mi);
                    }
                }
            }
        }
    }
}
