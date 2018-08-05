<?php
// src/settings/s_options.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Options_SettingRenderer {
    private $have_options = null;
    static private function find_option_req(SettingValues $sv, PaperOption $o, $xpos) {
        if ($o->id) {
            for ($i = 1; isset($sv->req["optid_$i"]); ++$i)
                if ($sv->req["optid_$i"] == $o->id)
                    return $i;
        }
        return $xpos;
    }
    private function render_option(SettingValues $sv, PaperOption $o = null, $xpos) {
        if (!$o) {
            $o = PaperOption::make(array("id" => 0,
                    "name" => "Field name",
                    "description" => "",
                    "type" => "checkbox",
                    "position" => count($sv->conf->paper_opts->nonfixed_option_list()) + 1,
                    "display" => "prominent"), $sv->conf);
        }

        if ($sv->use_req()) {
            $oxpos = self::find_option_req($sv, $o, $xpos);
            if (isset($sv->req["optn_$oxpos"])) {
                $id = cvtint($sv->req["optid_$oxpos"]);
                $args = [
                    "id" => $id <= 0 ? 0 : $id,
                    "name" => $sv->req["optn_$oxpos"],
                    "description" => get($sv->req, "optd_$oxpos"),
                    "type" => get($sv->req, "optvt_$oxpos", "checkbox"),
                    "visibility" => get($sv->req, "optp_$oxpos", ""),
                    "position" => get($sv->req, "optfp_$oxpos", 1),
                    "display" => get($sv->req, "optdt_$oxpos")
                ];
                if (get($sv->req, "optec_$oxpos") === "final")
                    $args["final"] = true;
                $o = PaperOption::make($args, $sv->conf);
                if ($o->has_selector())
                    $o->set_selector_options(explode("\n", rtrim(get($sv->req, "optv_$oxpos", ""))));
            }
        }

        $optvt = $o->type;
        if ($optvt === "text" && $o->display_space > 3)
            $optvt .= ":ds_" . $o->display_space;
        $jtype = $sv->conf->option_type($optvt) ? : (object) [];

        echo '<div class="settings-opt has-fold fold2o',
            ' fold4', (get($jtype, "has_selector") ? "o" : "c"),
            '<a href="" class="q ui settings-field-folder"><span class="expander"><span class="in0 fx2">▼</span></span></a>';

        echo '<div class="', $sv->sclass("optn_$xpos", "f-i"), '">',
            Ht::entry("optn_$xpos", $o->name, $sv->sjs("optn_$xpos", ["placeholder" => "Field name", "size" => 50, "id" => "optn_$xpos", "style" => "font-weight:bold", "class" => "need-tooltip", "data-tooltip-info" => "settings-option", "data-tooltip-type" => "focus"])),
            Ht::hidden("optid_$xpos", $o->id ? : "new", ["class" => "settings-opt-id"]),
            Ht::hidden("optfp_$xpos", $xpos, ["class" => "settings-opt-fp", "data-default-value" => $xpos]),
            '</div>';

        echo '<div class="', $sv->sclass("optd_$xpos", "f-i"), '">',
            $sv->label("optd_$xpos", "Description"),
            Ht::textarea("optd_$xpos", $o->description, array("rows" => 2, "cols" => 80, "id" => "optd_$xpos", "class" => "reviewtext need-autogrow")),
            '</div>';

        $show_final = $sv->conf->collectFinalPapers();
        foreach ($sv->conf->paper_opts->nonfixed_option_list() as $ox)
            $show_final = $show_final || $ox->final;

        $jtypes = $sv->conf->option_type_map();
        if (isset($jtype->name)
            && (!isset($jtypes[$jtype->name])
                || Conf::xt_priority_compare($jtype, $jtypes[$jtype->name]) <= 0))
            $jtypes[$jtype->name] = $jtype;
        uasort($jtypes, "Conf::xt_position_compare");

        $otypes = [];
        foreach ($jtypes as $uf) {
            if (!isset($uf->display_if)
                || $this->conf->xt_check($uf->display_if, $uf, $sv->user))
                $otypes[$uf->name] = get($uf, "title", $uf->name);
        }

        Ht::stash_script('$(function () { settings_option_move_enable(); $(".js-settings-option-type, .js-settings-option-condition").change(); })', 'settings_optvt');
        Ht::stash_html('<div id="option_caption_name" class="hidden"><p>Field names should be short and memorable (they are used as search keywords).</p></div><div id="option_caption_options" class="hidden"><p>Enter choices one per line.</p></div>', 'settings_option_caption');

        echo '<div class="f-horizontal">';
        echo '<div class="', $sv->sclass("optvt_$xpos", "f-i"), '">',
            $sv->label("optvt_$xpos", "Type"),
            Ht::select("optvt_$xpos", $otypes, $optvt, ["class" => "uich js-settings-option-type", "id" => "optvt_$xpos"]),
            "</div>\n";

        echo '<div class="', $sv->sclass("optec_$xpos", "f-i"), '">',
            $sv->label("optec_$xpos", "Presence"),
            Ht::select("optec_$xpos", ["" => "All submissions", "final" => "Final versions"], $o->final ? "final" : "", ["class" => "uich js-settings-option-condition", "id" => "optec_$xpos"]),
            "</div>\n";

        echo '<div class="', $sv->sclass("optp_$xpos", "f-i"), '">',
            $sv->label("optp_$xpos", "Visibility"),
            Ht::select("optp_$xpos", ["admin" => "Administrators only", "rev" => "Visible to PC and reviewers", "nonblind" => "Visible if authors are visible"], $o->visibility, ["id" => "optp_$xpos"]),
            "</div>\n";

        echo '<div class="', $sv->sclass("optdt_$xpos", "f-i"), '">',
            $sv->label("optdt_$xpos", "Display"),
            Ht::select("optdt_$xpos", ["prominent" => "Normal",
                                       "submission" => "Near submission",
                                       "topics" => "Grouped with topics"],
                       $o->display_name(), ["id" => "optdt_$xpos"]),
            "</div>";

        echo "</div>\n\n";

        $rows = 3;
        if ($jtype && get($jtype, "has_selector") && count($o->selector_options())) {
            $value = join("\n", $o->selector_options()) . "\n";
            $rows = max(count($o->selector_options()), 3);
        } else
            $value = "";
        echo '<div class="', $sv->sclass("optv_$xpos", "f-i fx4"), '">',
            $sv->label("optv_$xpos", "Choices"),
            Ht::textarea("optv_$xpos", $value, $sv->sjs("optv$xpos", ["rows" => $rows, "cols" => 50, "id" => "optv_$xpos", "class" => "reviewtext need-autogrow need-tooltip", "data-tooltip-info" => "settings-option", "data-tooltip-type" => "focus"])),
            "</div>\n";

        $delete_text = "Delete from form";
        if ($o->id) {
            if ($this->have_options === null) {
                $this->have_options = [];
                foreach ($sv->conf->fetch_rows("select distinct optionId from PaperOption") as $row)
                    $this->have_options[$row[0]] = true;
            }
            if (isset($this->have_options[$o->id]))
                $delete_text = "Delete from form and submissions";
        }

        echo '<div class="f-i">',
            Ht::button("Move up", ["class" => "btn btn-sm ui js-settings-option-move moveup"]),
            Ht::button("Move down", ["class" => "btn btn-sm ui js-settings-option-move movedown", "style" => "margin-left: 1em"]),
            Ht::button($delete_text, ["class" => "btn btn-sm ui js-settings-option-move delete", "style" => "margin-left: 1em"]),
            "</div>\n";

        echo '</div>';
    }

    static function render(SettingValues $sv) {
        echo "<h3 class=\"settings\">Submission fields</h3>\n";
        echo "<div class='g'></div>\n",
            Ht::hidden("has_options", 1), "\n\n";
        $all_options = array_merge($sv->conf->paper_opts->nonfixed_option_list()); // get our own iterator
        echo '<div id="settings_opts" class="c">';
        $self = new Options_SettingRenderer;
        $pos = 0;
        foreach ($all_options as $o)
            $self->render_option($sv, $o, ++$pos);
        echo "</div>\n",
            '<div style="margin-top:2em">',
            Ht::button("Add submission field", ["class" => "btn ui js-settings-option-new"]),
            "</div>\n<div id=\"settings_newopt\" style=\"display:none\">";
        $self->render_option($sv, null, 0);
        echo "</div>\n\n";
    }
    static function crosscheck(SettingValues $sv) {
        if (($sv->has_interest("options") || $sv->has_interest("sub_blind"))
            && $sv->newv("options")
            && $sv->newv("sub_blind") == Conf::BLIND_ALWAYS) {
            $options = (array) json_decode($sv->newv("options"));
            usort($options, function ($a, $b) { return $a->position - $b->position; });
            foreach ($options as $pos => $o)
                if (get($o, "visibility") === "nonblind")
                    $sv->warning_at("optp_" . ($pos + 1), "The “" . htmlspecialchars($o->name) . "” field is “visible if authors are visible,” but authors are not visible. You may want to change <a href=\"" . hoturl("settings", "group=sub") . "\">Settings &gt; Submissions</a> &gt; Blind submission to “Blind until review.”");
        }
    }
}

class Options_SettingParser extends SettingParser {
    private $next_optionid;
    private $req_optionid;
    private $stashed_options = false;

    function option_request_to_json(SettingValues $sv, $xpos) {
        $name = simplify_whitespace(get($sv->req, "optn_$xpos", ""));
        if ($name === "" || $name === "(Enter new option)" || $name === "Field name")
            return null;
        if (preg_match('/\A(?:paper|submission|final|none|any|all|true|false|opt(?:ion)?[-:_ ]?\d+)\z/i', $name))
            $sv->error_at("optn_$xpos", "Option name “" . htmlspecialchars($name) . "” is reserved. Please pick another name.");

        $id = cvtint(get($sv->req, "optid_$xpos", "new"));
        $is_new = $id < 0;
        if ($is_new) {
            if (!$this->next_optionid) {
                $oid1 = $sv->conf->fetch_ivalue("select coalesce(max(optionId),0) + 1 from PaperOption where optionId<" . PaperOption::MINFIXEDID);
                $oid2 = $sv->conf->fetch_ivalue("select coalesce(max(documentType),0) + 1 from PaperStorage where documentType>0 and documentType<" . PaperOption::MINFIXEDID);
                $this->next_optionid = max($oid1, $oid2, $this->req_optionid);
            }
            assert($this->next_optionid > 0 && $this->next_optionid < PaperOption::MINFIXEDID);
            $id = $this->next_optionid++;
        }
        $oarg = ["name" => $name, "id" => $id, "final" => false];

        if (get($sv->req, "optd_$xpos") && trim($sv->req["optd_$xpos"]) != "") {
            $t = CleanHTML::basic_clean($sv->req["optd_$xpos"], $err);
            if ($t !== false)
                $oarg["description"] = $t;
            else
                $sv->error_at("optd_$xpos", $err);
        }

        if (($optvt = get($sv->req, "optvt_$xpos"))) {
            if (($pos = strpos($optvt, ":")) !== false) {
                $oarg["type"] = substr($optvt, 0, $pos);
                if (preg_match('/:ds_(\d+)/', $optvt, $m))
                    $oarg["display_space"] = (int) $m[1];
            } else
                $oarg["type"] = $optvt;
        } else
            $oarg["type"] = "checkbox";

        if (($optec = get($sv->req, "optec_$xpos"))) {
            if ($optec === "final")
                $oarg["final"] = true;
        }

        $jtype = $sv->conf->option_type($oarg["type"]);
        if ($jtype && get($jtype, "has_selector")) {
            $oarg["selector"] = array();
            $seltext = trim(cleannl(get($sv->req, "optv_$xpos", "")));
            if ($seltext != "") {
                foreach (explode("\n", $seltext) as $t)
                    $oarg["selector"][] = $t;
            } else
                $sv->error_at("optv_$xpos", "Enter selectors one per line.");
        }

        $oarg["visibility"] = get($sv->req, "optp_$xpos", "rev");
        $oarg["position"] = (int) get($sv->req, "optfp_$xpos", 1);
        $oarg["display"] = get($sv->req, "optdt_$xpos");

        $o = PaperOption::make($oarg, $sv->conf);
        $o->req_xpos = $xpos;
        $o->is_new = $is_new;
        return $o;
    }

    function parse(SettingValues $sv, Si $si) {
        $new_opts = $sv->conf->paper_opts->nonfixed_option_list();

        // consider option ids
        $optids = array_map(function ($o) { return $o->id; }, $new_opts);
        for ($i = 1; isset($sv->req["optid_$i"]); ++$i)
            $optids[] = intval($sv->req["optid_$i"]);
        $optids[] = 0;
        $this->req_optionid = max($optids) + 1;

        // convert request to JSON
        for ($i = 1; isset($sv->req["optid_$i"]); ++$i) {
            if (get($sv->req, "optfp_$i") === "deleted")
                unset($new_opts[cvtint(get($sv->req, "optid_$i"))]);
            else if (($o = $this->option_request_to_json($sv, $i)))
                $new_opts[$o->id] = $o;
        }

        if (!$sv->has_error()) {
            uasort($new_opts, "PaperOption::compare");
            $this->stashed_options = $new_opts;
            $sv->need_lock["PaperOption"] = true;
            return true;
        }
    }

    function save(SettingValues $sv, Si $si) {
        $newj = [];
        foreach ($this->stashed_options as $o)
            $newj[] = $o->unparse();
        $sv->save("next_optionid", null);
        $sv->save("options", empty($newj) ? null : json_encode_db($newj));

        $deleted_ids = array();
        foreach ($sv->conf->paper_opts->nonfixed_option_list() as $o) {
            $newo = get($this->stashed_options, $o->id);
            if (!$newo
                || ($newo->type !== $o->type
                    && !$newo->change_type($o, true, true)
                    && !$o->change_type($newo, false, true)))
                $deleted_ids[] = $o->id;
        }
        if (!empty($deleted_ids))
            $sv->conf->qe("delete from PaperOption where optionId?a", $deleted_ids);

        // invalidate cached option list
        $sv->conf->invalidate_caches(["options" => true]);
    }
}
