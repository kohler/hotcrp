<?php
// src/settings/s_responses.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Responses_SettingParser extends SettingParser {
    /** @var array<string,int> */
    private $roundname_ctr = [];
    /** @var list<string> */
    private $round_transform = [];

    function set_oldv(SettingValues $sv, Si $si) {
        if (preg_match('/\Aresponse__(\d+)__title\z/', $si->name, $m)) {
            $x = $sv->oldv("response__{$m[1]}__name");
            $sv->set_oldv($si->name, $x ? "‘{$x}’ response" : "Response");
            return true;
        } else if (preg_match('/\Aresponse__(\d+)__name\z/', $si->name, $m)
                   && ($rrd = ($sv->conf->resp_rounds())[intval($m[1]) - 1] ?? null)) {
            $sv->set_oldv($si->name, $rrd->unnamed ? "" : $rrd->name);
            return true;
        }
        return false;
    }

    static function render_name_property(SettingValues $sv, $ctr) {
        $sv->echo_entry_group("response__{$ctr}__name", "Response name", [
            "horizontal" => true,
            "control_after" => Ht::button(Icons::ui_use("trash"), ["class" => "ui js-settings-resp-round-delete ml-2 need-tooltip", "aria-label" => "Delete response round", "tabindex" => "-1"])
        ]);
    }

    static function render_deadline_property(SettingValues $sv, $ctr) {
        if ($sv->curv("response__{$ctr}__open") === 1
            && ($x = $sv->curv("response__{$ctr}__done"))) {
            $sv->conf->settings["response__{$ctr}__open"] = $x - 7 * 86400;
        }
        $sv->echo_entry_group("response__{$ctr}__open", "Start time", ["horizontal" => true]);
        $sv->echo_entry_group("response__{$ctr}__done", "Hard deadline", ["horizontal" => true]);
        $sv->echo_entry_group("response__{$ctr}__grace", "Grace period", ["horizontal" => true]);
    }

    static function render_wordlimit_property(SettingValues $sv, $ctr) {
        $sv->echo_entry_group("response__{$ctr}__words", "Word limit", ["horizontal" => true], $ctr > 1 ? null : "This is a soft limit: authors may submit longer responses. 0 means no limit.");
    }

    static function render_instructions_property(SettingValues $sv, $ctr) {
        $sv->echo_message_horizontal("response__{$ctr}__instructions", "Instructions");
    }

    static function render_one(SettingValues $sv, $ctr, $isnew) {
        echo '<div id="response__', $ctr, '" class="form-g settings-response',
            $isnew ? " settings-response-new" : "", '">',
            Ht::hidden("response__{$ctr}__id", $isnew ? "new" : $sv->curv("response__{$ctr}__id")),
            Ht::checkbox("response__{$ctr}__delete", "1", false, ["class" => "hidden"]);
        foreach ($sv->group_members("responses/properties") as $gj) {
            if (isset($gj->render_response_property_function)) {
                Conf::xt_resolve_require($gj);
                call_user_func($gj->render_response_property_function, $sv, $ctr, $gj);
            }
        }
        echo '</div>';
    }

    static function render(SettingValues $sv) {
        // Authors' response
        echo '<div class="form-g">';
        $sv->echo_checkbox("response_active", '<strong>Collect authors’ responses to the reviews<span class="if-response-active">:</span></strong>', ["group_open" => true, "class" => "uich js-settings-resp-active"]);
        Icons::stash_defs("trash");
        echo Ht::unstash();
        echo '<div class="if-response-active',
            $sv->curv("response_active") ? "" : " hidden",
            '"><hr class="g">', Ht::hidden("has_responses", 1);

        // Old values
        $ctr = 1;
        foreach ($sv->conf->resp_rounds() as $i => $rrd) {
            $sv->set_oldv("response__{$ctr}__id", $i);
            if ($rrd->unnamed) {
                $si = $sv->si("response__{$ctr}__name");
                $si->placeholder = "unnamed";
                $sv->set_oldv("response__{$ctr}__name", "");
            } else {
                $sv->set_oldv("response__{$ctr}__name", $rrd->name);
            }
            $sv->set_oldv("response__{$ctr}__open", $rrd->open ?? 0);
            $sv->set_oldv("response__{$ctr}__done", $rrd->done ?? 0);
            $sv->set_oldv("response__{$ctr}__grace", $rrd->grace ?? 0);
            $sv->set_oldv("response__{$ctr}__words", $rrd->words ?? 500);
            $sv->set_oldv("response__{$ctr}__condition", $rrd->search ? $rrd->search->q : "");
            $sv->set_oldv("response__{$ctr}__instructions", $rrd->instructions);
            self::render_one($sv, $ctr, false);
            ++$ctr;
        }

        // New values
        while ($sv->use_req() && $sv->has_reqv("response__{$ctr}__id")) {
            self::render_one($sv, $ctr, true);
            ++$ctr;
        }

        echo '<template id="response__new" class="hidden">';
        self::render_one($sv, '$', true);
        echo '</template>';
        if ($sv->editable("response__0__name")) {
            echo '<div class="form-g">',
                Ht::button("Add response round", ["class" => "ui js-settings-resp-round-new"]),
                '</div>';
        }

        echo '</div></div></div>';
    }

    private function parse_req_one(SettingValues $sv, ResponseRound $rrd, $ctr) {
        if ($sv->has_reqv("response__{$ctr}__name")) {
            $rrd->name = trim($sv->reqv("response__{$ctr}__name"));
        }
        $lname = strtolower($rrd->name ?? "");
        if (in_array($lname, ["1", "unnamed", "none", ""], true)) {
            $rrd->name = $lname = "";
        } else if (($error = Conf::resp_round_name_error($rrd->name))) {
            $sv->error_at("response__{$ctr}__name", "<0>{$error}");
        }
        if (($ectr = $this->roundname_ctr[$lname] ?? null) !== null) {
            $sv->error_at("response__{$ctr}__name", "<0>Duplicate response name ‘" . ($lname ? : "unnamed") . "’");
            $sv->error_at("response__{$ectr}__name");
        } else {
            $this->roundname_ctr[$lname] = $ctr;
        }
        if (($v = $sv->base_parse_req("response__{$ctr}__open")) !== null) {
            $rrd->open = max($v, 0);
        }
        if (($v = $sv->base_parse_req("response__{$ctr}__done")) !== null) {
            $rrd->done = max($v, 0);
        }
        if (($v = $sv->base_parse_req("response__{$ctr}__grace")) !== null) {
            $rrd->grace = max($v, 0);
        }
        if (($v = $sv->base_parse_req("response__{$ctr}__words")) !== null) {
            $rrd->words = max($v, 0);
        }
        if (($v = $sv->base_parse_req("response__{$ctr}__condition")) !== null) {
            if ($v === "") {
                $rrd->search = null;
            } else {
                $rrd->search = new PaperSearch($sv->conf->root_user(), $v);
                foreach ($rrd->search->message_list() as $mi) {
                    $sv->append_item_at("response__{$ctr}__condition", $mi);
                }
            }
        }
        if (($v = $sv->base_parse_req("response__{$ctr}__instructions")) !== null) {
            $rrd->instructions = $v === "" ? null : $v;
        }
        $sv->check_date_before("response__{$ctr}__open", "response__{$ctr}__done", false);
    }

    function parse_req(SettingValues $sv, Si $si) {
        if ($si->name !== "responses"
            || !$sv->newv("response_active")) {
            return;
        }

        $rrds = [];
        foreach ($sv->conf->resp_rounds() as $rrd) {
            $rrds[] = clone $rrd;
        }

        for ($ctr = 1; $sv->has_reqv("response__{$ctr}__id"); ++$ctr) {
            $id = $sv->reqv("response__{$ctr}__id");
            $rrd = $rrds[$id] ?? new ResponseRound;
            if ($sv->reqv("response__{$ctr}__delete")) {
                $rrd->setting_status = -1;
                if ($rrd->number) {
                    $this->round_transform[] = "when {$rrd->number} then 0";
                }
            } else {
                if ($rrd->number === null) {
                    $rrds[] = $rrd;
                    $rrd->setting_status = 1;
                }
                $this->parse_req_one($sv, $rrd, $ctr);
            }
        }

        $nrds = [];
        foreach ($rrds as $rrd) {
            if ($rrd->setting_status >= 0) {
                array_splice($nrds, $rrd->name === "" ? 0 : count($nrds), 0, [$rrd]);
            }
        }

        $jrl = [];
        foreach ($nrds as $i => $rrd) {
            $jr = [];
            $rrd->name !== "" && ($jr["name"] = $rrd->name);
            $rrd->open > 0 && ($jr["open"] = $rrd->open);
            $rrd->done > 0 && ($jr["done"] = $rrd->done);
            $rrd->grace > 0 && ($jr["grace"] = $rrd->grace);
            $rrd->words !== 500 && ($jr["words"] = $rrd->words);
            $rrd->search && ($jr["condition"] = $rrd->search->q);
            $rrd->instructions !== null && ($jr["instructions"] = $rrd->instructions);
            $jrl[] = $jr;
            if (!$rrd->setting_status && $i !== $rrd->number) {
                $this->round_transform[] = "when {$rrd->number} then {$i}";
            }
        }

        $jrt = json_encode_db($jrl);
        if ($sv->update("responses", $jrt === "[{}]" ? null : $jrt)
            && !empty($this->round_transform)) {
            $sv->request_write_lock("PaperComment");
            $sv->request_store_value($si);
        }
    }

    function store_value(SettingValues $sv, Si $si) {
        $sv->conf->qe("update PaperComment set commentRound=case " . join(" ", $this->round_transform) . " else commentRound end where (commentType&" . CommentInfo::CT_RESPONSE . ")!=0");
    }
}
