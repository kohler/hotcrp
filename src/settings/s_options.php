<?php
// src/settings/s_options.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2017 Eddie Kohler; see LICENSE.

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
                    "name" => "(Enter new option)",
                    "description" => "",
                    "type" => "checkbox",
                    "position" => count($sv->conf->paper_opts->nonfixed_option_list()) + 1,
                    "display" => "default"), $sv->conf);
        }

        if ($sv->use_req()) {
            $oxpos = self::find_option_req($sv, $o, $xpos);
            if (isset($sv->req["optn_$oxpos"])) {
                $id = cvtint($sv->req["optid_$oxpos"]);
                $o = PaperOption::make(array("id" => $id <= 0 ? 0 : $id,
                    "name" => $sv->req["optn_$oxpos"],
                    "description" => get($sv->req, "optd_$oxpos"),
                    "type" => get($sv->req, "optvt_$oxpos", "checkbox"),
                    "visibility" => get($sv->req, "optp_$oxpos", ""),
                    "position" => get($sv->req, "optfp_$oxpos", 1),
                    "display" => get($sv->req, "optdt_$oxpos")), $sv->conf);
                if ($o->has_selector())
                    $o->selector = explode("\n", rtrim(defval($sv->req, "optv_$oxpos", "")));
            }
        }

        $optvt = $o->type;
        if ($optvt == "text" && $o->display_space > 3)
            $optvt .= ":ds_" . $o->display_space;
        if ($o->final)
            $optvt .= ":final";

        echo '<div class="settings-opt has-fold fold2c fold3o ',
            (PaperOption::type_has_selector($optvt) ? "fold4o" : "fold4c"), '">';

        echo '<div class="f-horizontal">';

        echo '<div class="f-ig">',
            '<div class="f-i"><div class="f-c">',
            $sv->label("optn_$xpos", "Option name"),
            '</div><div class="f-e">',
            Ht::entry("optn_$xpos", $o->name, $sv->sjs("optn_$xpos", array("placeholder" => "(Enter new option)", "size" => 50, "id" => "optn_$xpos"))),
            Ht::hidden("optid_$xpos", $o->id ? : "new", ["class" => "settings-opt-id"]),
            Ht::hidden("optfp_$xpos", $xpos, ["class" => "settings-opt-fp", "data-default-value" => $xpos]),
            '</div></div>';

        echo '<div class="f-i"><div class="f-c">',
            $sv->label("optd_$xpos", "Description"),
            '</div><div class="f-e">',
            Ht::textarea("optd_$xpos", $o->description, array("rows" => 2, "cols" => 50, "id" => "optd_$xpos", "class" => "need-autogrow")),
            '</div></div>',
            '</div>';

        if ($o->id && ($examples = $o->example_searches())) {
            echo '<div class="f-i"><div class="f-c">',
                'Example ', pluralx($examples, "search"),
                '</div><div class="f-e">',
                join("<br />", array_map(function ($ex) {
                    return Ht::link(htmlspecialchars($ex[0]), hoturl("search", ["q" => $ex[0]]));
                }, $examples)),
                "</div></div>";
        }
        echo "</div>\n";

        $show_final = $sv->conf->collectFinalPapers();
        foreach ($sv->conf->paper_opts->nonfixed_option_list() as $ox)
            $show_final = $show_final || $ox->final;

        $otlist = $sv->conf->paper_opts->list_subform_options($o);

        $otypes = array();
        if ($show_final)
            $otypes["xxx1"] = array("optgroup", "Options for submissions");
        foreach ($otlist as $ot)
            $otypes[$ot[1]] = $ot[2];
        if ($show_final) {
            $otypes["xxx2"] = array("optgroup", "Options for final versions");
            foreach ($otlist as $ot)
                $otypes[$ot[1] . ":final"] = $ot[2] . " (final version)";
        }
        Ht::stash_script('$(function () { $("#settings_opts").on("change input", "select.settings-optvt", settings_option_type); $("#settings_opts").on("click", "button", settings_option_move); settings_option_move_enable(); $("select.settings-optvt").each(settings_option_type); })', 'settings_optvt');

        echo '<div class="f-horizontal">';
        echo '<div class="f-i"><div class="f-c">',
            $sv->label("optvt_$xpos", "Type"),
            '</div><div class="f-e">',
            Ht::select("optvt_$xpos", $otypes, $optvt, ["class" => "settings-optvt", "id" => "optvt_$xpos"]),
            "</div></div>\n";

        echo '<div class="f-i fn2"><div class="f-c">',
            $sv->label("optp_$xpos", "Visibility"),
            '</div><div class="f-e">',
            Ht::select("optp_$xpos", ["admin" => "Administrators only", "rev" => "Visible to PC and reviewers", "nonblind" => "Visible if authors are visible"], $o->visibility, ["id" => "optp_$xpos"]),
            "</div></div>\n";

        echo '<div class="f-i fn3"><div class="f-c">',
            $sv->label("optdt_$xpos", "Display"),
            '</div><div class="f-e">',
            Ht::select("optdt_$xpos", ["default" => "Default",
                                       "prominent" => "Prominent",
                                       "topics" => "With topics",
                                       "submission" => "Near submission"],
                       $o->display_name(), ["id" => "optdt_$xpos"]),
            "</div></div>";

        if (isset($otypes["pdf:final"]))
            echo '<hr class="c fx2"><div class="f-h fx2">Final version options are set by accepted authors during the final version submission period. They are always visible to PC and reviewers.</div>';

        echo "</div>\n\n";

        $rows = 3;
        if (PaperOption::type_has_selector($optvt) && count($o->selector)) {
            $value = join("\n", $o->selector) . "\n";
            $rows = max(count($o->selector), 3);
        } else
            $value = "";
        echo '<div class="f-i fx4"><div class="f-c">Choices</div>',
            '<div class="f-e">',
            Ht::textarea("optv_$xpos", $value, $sv->sjs("optv$xpos", array("rows" => $rows, "cols" => 50, "id" => "optv_$xpos", "class" => "need-autogrow"))),
            '</div><div class="f-h">Enter choices one per line.  The first choice will be the default.</div></div>', "\n";

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

        echo '<div class="f-i"><div class="f-e">',
            Ht::button("Move up", ["class" => "btn settings-opt-moveup"]),
            Ht::button("Move down", ["class" => "btn settings-opt-movedown", "style" => "margin-left: 1em"]),
            Ht::button($delete_text, ["class" => "btn settings-opt-delete", "style" => "margin-left: 1em"]),
            "</div></div>\n";

        echo '</div>';
    }

    static function render(SettingValues $sv) {
        echo "<h3 class=\"settings\">Options and attachments</h3>\n";
        echo "<p class=\"settingtext\">Options and attachments are additional data entered by authors at submission time. Option names should be brief (“PC paper,” “Supplemental material”). The optional HTML description can explain further.</p>\n";
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
            Ht::button("Add option", ["class" => "settings-opt-new btn"]),
            "</div>\n<div id=\"settings_newopt\" style=\"display:none\">";
        Ht::stash_script('$("button.settings-opt-new").on("click", settings_option_move)');
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
                    $sv->warning_at("optp_" . ($pos + 1), "The “" . htmlspecialchars($o->name) . "” option is “visible if authors are visible,” but authors are not visible. You may want to change <a href=\"" . hoturl("settings", "group=sub") . "\">Settings &gt; Submissions</a> &gt; Blind submission to “Blind until review.”");
        }
    }
}

class Options_SettingParser extends SettingParser {
    private $next_optionid;
    private $has_next_optionid = false;
    private $stashed_options = false;

    function option_request_to_json(SettingValues $sv, $xpos) {
        $name = simplify_whitespace(get($sv->req, "optn_$xpos", ""));
        if ($name === "" || $name === "New option" || $name === "(Enter new option)")
            return null;
        if (preg_match('/\A(?:paper|submission|final|none|any|all|true|false|opt(?:ion)?[-:_ ]?\d+)\z/i', $name))
            $sv->error_at("optn_$xpos", "Option name “" . htmlspecialchars($name) . "” is reserved. Please pick another name.");

        $id = cvtint(get($sv->req, "optid_$xpos", "new"));
        $is_new = $id < 0;
        if ($is_new) {
            if (!$this->has_next_optionid) {
                $oid = $sv->conf->fetch_ivalue("select coalesce(max(optionId),0) + 1 from PaperOption where optionId<" . PaperOption::MINFIXEDID);
                $this->next_optionid = max($oid, $this->next_optionid);
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
                if (preg_match('/:final/', $optvt))
                    $oarg["final"] = true;
                if (preg_match('/:ds_(\d+)/', $optvt, $m))
                    $oarg["display_space"] = (int) $m[1];
            } else
                $oarg["type"] = $optvt;
        } else
            $oarg["type"] = "checkbox";

        if (PaperOption::type_has_selector($oarg["type"])) {
            $oarg["selector"] = array();
            $seltext = trim(cleannl(defval($sv->req, "optv_$xpos", "")));
            if ($seltext != "") {
                foreach (explode("\n", $seltext) as $t)
                    $oarg["selector"][] = $t;
            } else
                $sv->error_at("optv_$xpos", "Enter selectors one per line.");
        }

        $oarg["visibility"] = defval($sv->req, "optp_$xpos", "rev");
        if ($oarg["final"])
            $oarg["visibility"] = "rev";

        $oarg["position"] = (int) defval($sv->req, "optfp_$xpos", 1);

        $oarg["display"] = defval($sv->req, "optdt_$xpos");
        if ($oarg["type"] === "pdf" && $oarg["final"])
            $oarg["display"] = "submission";

        $o = PaperOption::make($oarg, $sv->conf);
        $o->req_xpos = $xpos;
        $o->is_new = $is_new;
        return $o;
    }

    function parse(SettingValues $sv, Si $si) {
        $this->next_optionid = 1;
        for ($i = 1; isset($sv->req["optid_$i"]); ++$i) {
            $id = intval($sv->req["optid_$i"]);
            $this->next_optionid = max($id + 1, $this->next_optionid);
        }
        $new_opts = $sv->conf->paper_opts->nonfixed_option_list();
        foreach ($new_opts as $o)
            $this->next_optionid = max($o->id + 1, $this->next_optionid);

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
            $newj[$o->id] = $o->unparse();
        $sv->save("next_optionid", null);
        $sv->save("options", empty($newj) ? null : json_encode_db((object) $newj));

        $deleted_ids = array();
        foreach ($sv->conf->paper_opts->nonfixed_option_list() as $o)
            if (!isset($newj[$o->id]))
                $deleted_ids[] = $o->id;
        if (!empty($deleted_ids))
            $sv->conf->qe("delete from PaperOption where optionId?a", $deleted_ids);

        // invalidate cached option list
        $sv->conf->invalidate_caches(["options" => true]);
    }
}
