<?php
// src/settings/s_options.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Options_SettingRenderer {
    /** @var list<string> */
    private $option_classes = [];
    /** @var ?array<int,int> */
    private $reqv_id_to_pos;
    /** @var ?array<int,int> */
    private $have_options;

    /** @param string $class */
    function add_option_class($class) {
        $this->option_classes[] = $class;
    }

    static function render_type_property(SettingValues $sv, PaperOption $o, $xpos, $self, $gj) {
        $optvt = $o->type;
        if ($o instanceof Text_PaperOption && $o->display_space > 3) {
            $optvt .= ":ds_" . $o->display_space;
        }

        $self->add_option_class("fold4" . ($o instanceof Selector_PaperOption ? "o" : "c"));

        $jtypes = $sv->conf->option_type_map();
        if (!isset($jtypes[$optvt])
            && ($made_type = $sv->conf->option_type($optvt))) {
            $jtypes[$optvt] = $made_type;
        }
        uasort($jtypes, "Conf::xt_position_compare");

        $otypes = [];
        foreach ($jtypes as $uf) {
            if (!isset($uf->display_if)
                || $sv->conf->xt_check($uf->display_if, $uf, $sv->user)
                || $uf->name === $optvt)
                $otypes[$uf->name] = $uf->title ?? $uf->name;
        }

        $t = '<div class="' . $sv->control_class("optvt_$xpos", "entryi")
            . '">' . $sv->label("optvt_$xpos", "Type")
            . '<div class="entry">'
            . Ht::select("optvt_$xpos", $otypes, $optvt, $sv->sjs("optvt_$xpos", ["class" => "uich js-settings-option-type", "id" => "optvt_$xpos"]))
            . $sv->render_feedback_at("optvt_$xpos")
            . "</div></div>\n";

        $rows = 3;
        $value = "";
        if ($o instanceof Selector_PaperOption && count($o->selector_options())) {
            $value = join("\n", $o->selector_options()) . "\n";
            $rows = max(count($o->selector_options()), 3);
        }
        return $t . '<div class="' . $sv->control_class("optv_$xpos", "entryi fx4")
            . '">' . $sv->label("optv_$xpos", "Choices")
            . '<div class="entry">'
            . Ht::textarea("optv_$xpos", $value, $sv->sjs("optv_$xpos", ["rows" => $rows, "cols" => 50, "id" => "optv_$xpos", "class" => "w-text need-autogrow need-tooltip", "data-tooltip-info" => "settings-option", "data-tooltip-type" => "focus"]))
            . $sv->render_feedback_at("optv_$xpos")
            . "</div></div>\n";
    }
    static function render_description_property(SettingValues $sv, PaperOption $o, $xpos, $self, $gj) {
        return '<div class="' . $sv->control_class("optd_$xpos", "entryi is-option-description" . ($o->id && (string) $o->description === "" ? " hidden" : ""))
            . '">' . $sv->label("optd_$xpos", "Description")
            . '<div class="entry">'
            . Ht::textarea("optd_$xpos", $o->description, $sv->sjs("optd_$xpos", ["rows" => 2, "cols" => 80, "id" => "optd_$xpos", "class" => "w-text settings-opt-description need-autogrow"]))
            . $sv->render_feedback_at("optd_$xpos")
            . '</div></div>';
    }
    static function render_presence_property(SettingValues $sv, PaperOption $o, $xpos, $self, $gj) {
        return '<div class="' . $sv->control_class("optec_$xpos", "entryi is-option-editing" . ($o->id && !$o->final ? " hidden" : ""))
            . '">' . $sv->label("optec_$xpos", "Present on")
            . '<div class="entry">'
            . '<span class="sep">'
            . Ht::select("optec_$xpos", ["all" => "All submissions", "final" => "Final versions only"], $o->final ? "final" : "all", $sv->sjs("optec_$xpos", ["id" => "optec_$xpos"]))
            . $sv->render_feedback_at("optec_$xpos")
            . "</span></div></div>";
    }
    static function render_required_property(SettingValues $sv, PaperOption $o, $xpos, $self, $gj) {
        return '<div class="' . $sv->control_class("optreq_$xpos", "entryi is-option-editing" . ($o->id && !$o->required ? " hidden" : ""))
            . '">' . $sv->label("optreq_$xpos", "Required")
            . '<div class="entry">'
            . Ht::select("optreq_$xpos", ["0" => "No", "1" => "Yes"], $o->required ? "1" : "0", $sv->sjs("optreq_$xpos", ["id" => "optreq_$xpos"]))
            . $sv->render_feedback_at("optreq_$xpos")
            . "</div></div>";
    }
    static function render_visibility_property(SettingValues $sv, PaperOption $o, $xpos, $self, $gj) {
        return '<div class="' . $sv->control_class("optp_$xpos", "entryi is-option-visibility" . ($o->id && $o->visibility === "rev" ? " hidden" : "") . " short")
            . '">' . $sv->label("optp_$xpos", "Visible to")
            . '<div class="entry">'
            . Ht::select("optp_$xpos", ["rev" => "PC and reviewers", "nonblind" => "PC and reviewers, if authors are visible", "admin" => "Administrators only"], $o->visibility, $sv->sjs("optp_$xpos", ["id" => "optp_$xpos", "class" => "settings-opt-visibility"]))
            . $sv->render_feedback_at("optp_$xpos")
            . '</div></div>';
    }
    static function render_display_property(SettingValues $sv, PaperOption $o, $xpos, $self, $gj) {
        return '<div class="' . $sv->control_class("optdt_$xpos", "entryi is-option-display" . ($o->id && $o->display() === PaperOption::DISP_PROMINENT ? " hidden" : "") . " short")
            . '">' . $sv->label("optdt_$xpos", "Display")
            . '<div class="entry">'
            . Ht::select("optdt_$xpos", ["prominent" => "Normal",
                                         "topics" => "Grouped with topics",
                                         "submission" => "Near submission"],
                         $o->display_name(),
                         $sv->sjs("optdt_$xpos", ["id" => "optdt_$xpos", "class" => "settings-opt-display"]))
            . $sv->render_feedback_at("optdt_$xpos")
            . "</div></div>";
    }
    static function configurable_options(SettingValues $sv) {
        $o = [];
        foreach ($sv->conf->options() as $opt) {
            if ($opt->configurable)
                $o[$opt->id] = $opt;
        }
        return $o;
    }

    static function make_requested_option(SettingValues $sv, PaperOption $io = null, $ipos) {
        $io = $io ?? PaperOption::make((object) [
            "id" => -999,
            "name" => "Field name",
            "description" => "",
            "type" => "checkbox",
            "position" => count(self::configurable_options($sv)) + 1,
            "display" => "prominent",
            "json_key" => "__fake__"
        ], $sv->conf);

        if ($ipos !== null) {
            $optec = $sv->reqv("optec_$ipos");
            $optecs = $optec === "search" ? $sv->reqv("optecs_$ipos") : null;
            $optreq = $sv->reqv("optreq_$ipos");
            $args = [
                "id" => $io->id,
                "name" => $sv->reqv("optn_$ipos") ?? $io->name,
                "description" => $sv->reqv("optd_$ipos") ?? $io->description,
                "type" => $sv->reqv("optvt_$ipos") ?? $io->type,
                "visibility" => $sv->reqv("optp_$ipos") ?? $io->visibility,
                "position" => $sv->reqv("optfp_$ipos") ?? $io->position,
                "display" => $sv->reqv("optdt_$ipos") ?? $io->display_name(),
                "required" => ($optreq ?? ($io->required ? "1" : "0")) == "1",
                "final" => $optec === null ? $io->final : $optec === "final",
                "exists_if" => $optecs ?? $io->exists_condition(),
                "json_key" => $io->id > 0 ? null : "__fake__"
            ];
            if ($sv->has_reqv("optv_$ipos")) {
                $args["selector"] = explode("\n", rtrim($sv->reqv("optv_$ipos")));
            } else if ($io instanceof Selector_PaperOption) {
                $args["selector"] = $io->selector_options();
            }
            return PaperOption::make((object) $args, $sv->conf);
        } else {
            return $io;
        }
    }

    private function render_option(SettingValues $sv, PaperOption $io = null, $ipos, $xpos) {
        $o = self::make_requested_option($sv, $io, $ipos);
        if ($io) {
            $sv->set_oldv("optn_$xpos", $io->name);
            $sv->set_oldv("optd_$xpos", $io->description);
            $sv->set_oldv("optvt_$xpos", $io->type);
            $sv->set_oldv("optp_$xpos", $io->visibility);
            $sv->set_oldv("optdt_$xpos", $io->display_name());
            $sv->set_oldv("optreq_$xpos", $io->required ? "1" : "0");
            $sv->set_oldv("optec_$xpos", $io->exists_condition() ? "search" : ($io->final ? "final" : "all"));
            $sv->set_oldv("optecs_$xpos", $io->exists_condition());
        }

        $this->option_classes = ["settings-opt", "has-fold", "fold2o"];

        $t = "";
        foreach ($sv->group_members("options/properties") as $gj) {
            if (isset($gj->render_option_property_callback)) {
                Conf::xt_resolve_require($gj);
                $t .= call_user_func($gj->render_option_property_callback, $sv, $o, $xpos, $this, $gj);
            }
        }

        echo '<div class="', join(" ", $this->option_classes),
            '"><a href="" class="q ui settings-field-folder"><span class="expander"><span class="in0 fx2">▼</span></span></a>';

        echo '<div class="', $sv->control_class("optn_$xpos", "f-i"), '">',
            Ht::entry("optn_$xpos", $o->name, $sv->sjs("optn_$xpos", ["placeholder" => "Field name", "size" => 50, "id" => "optn_$xpos", "style" => "font-weight:bold", "class" => "need-tooltip", "data-tooltip-info" => "settings-option", "data-tooltip-type" => "focus", "aria-label" => "Field name"])),
            $sv->render_feedback_at("optn_$xpos"),
            Ht::hidden("optid_$xpos", $o->id > 0 ? $o->id : "new", ["class" => "settings-opt-id"]),
            Ht::hidden("optfp_$xpos", $xpos, ["class" => "settings-opt-fp", "data-default-value" => $xpos]),
            '</div>';

        Ht::stash_script('$(settings_option_move_enable)', 'settings_optvt');
        Ht::stash_html('<div id="option_caption_name" class="hidden"><p>Field names should be short and memorable (they are used as search keywords).</p></div><div id="option_caption_options" class="hidden"><p>Enter choices one per line.</p></div>', 'settings_option_caption');

        echo $t;

        if ($o->id && $this->have_options === null) {
            $this->have_options = [];
            foreach ($sv->conf->fetch_rows("select optionId, count(distinct paperId) from PaperOption group by optionId") as $row) {
                $this->have_options[(int) $row[0]] = (int) $row[1];
            }
        }

        echo '<div class="f-i entryi"><label></label><div class="btnp entry">',
            '<span class="btnbox">',
            Ht::button(Icons::ui_description(), ["class" => "btn-licon ui js-settings-show-option-property need-tooltip", "aria-label" => "Description", "data-option-property" => "description"]),
            Ht::button(Icons::ui_edit_hide(), ["class" => "btn-licon ui js-settings-show-option-property need-tooltip", "aria-label" => "Editing", "data-option-property" => "editing"]),
            Ht::button(Icons::ui_visibility_hide(), ["class" => "btn-licon ui js-settings-show-option-property need-tooltip", "aria-label" => "Reviewer visibility", "data-option-property" => "visibility"]),
            Ht::button(Icons::ui_display(), ["class" => "btn-licon ui js-settings-show-option-property need-tooltip", "aria-label" => "Display type", "data-option-property" => "display"]),
            '</span>',
            '<span class="btnbox">',
            Ht::button(Icons::ui_movearrow(0), ["class" => "btn-licon ui js-settings-option-move moveup need-tooltip", "aria-label" => "Move up in display order"]),
            Ht::button(Icons::ui_movearrow(2), ["class" => "btn-licon ui js-settings-option-move movedown need-tooltip", "aria-label" => "Move down in display order"]),
            '</span>',
            Ht::button(Icons::ui_trash(), ["class" => "btn-licon ui js-settings-option-move delete need-tooltip", "aria-label" => "Delete", "data-option-exists" => $this->have_options[$o->id] ?? false]),
            "</div></div>\n";

        echo '</div>';
    }

    static function render(SettingValues $sv) {
        $self = new Options_SettingRenderer;
        echo "<h3 class=\"form-h\">Submission fields</h3>\n";
        echo "<hr class=\"g\">\n",
            Ht::hidden("has_options", 1), "\n\n";

        $self->reqv_id_to_pos = [];
        if ($sv->use_req()) {
            for ($pos = 1; $sv->has_reqv("optid_$pos"); ++$pos) {
                $id = (int) $sv->reqv("optid_$pos");
                if ($id > 0 && !isset($self->reqv_id_to_pos[$id])) {
                    $self->reqv_id_to_pos[$id] = $pos;
                }
            }
        }

        echo '<div id="settings_opts" class="c">';
        $pos = 0;
        $all_options = self::configurable_options($sv); // get our own iterator
        foreach ($all_options as $o) {
            $self->render_option($sv, $o, $self->reqv_id_to_pos[$o->id] ?? null, ++$pos);
        }
        if ($sv->use_req()) {
            for ($xpos = 1; $sv->has_reqv("optid_$xpos"); ++$xpos) {
                if ($sv->reqv("optid_$xpos") === "new") {
                    $self->render_option($sv, null, $xpos, ++$pos);
                }
            }
        }
        echo "</div>\n";

        ob_start();
        $self->render_option($sv, null, null, 0);
        $newopt = ob_get_clean();

        echo '<div style="margin-top:2em" id="settings_newopt" data-template="',
            htmlspecialchars($newopt), '">',
            Ht::button("Add submission field", ["class" => "ui js-settings-option-new"]),
            "</div>\n";
    }

    static function crosscheck(SettingValues $sv) {
        if (($sv->has_interest("options") || $sv->has_interest("sub_blind"))
            && $sv->newv("options")
            && $sv->newv("sub_blind") == Conf::BLIND_ALWAYS) {
            $options = (array) json_decode($sv->newv("options"));
            usort($options, function ($a, $b) { return $a->position - $b->position; });
            foreach ($options as $pos => $o) {
                if (($o->visibility ?? null) === "nonblind") {
                    $sv->warning_at("optp_" . ($pos + 1), "The “" . htmlspecialchars($o->name) . "” field is “visible if authors are visible,” but authors are not visible. You may want to change " . $sv->setting_link("Settings &gt; Submissions &gt; Blind submission", "sub_blind") . " to “Blind until review.”");
                }
            }
        }
    }
}

class Options_SettingParser extends SettingParser {
    private $known_optionids;
    private $next_optionid;
    private $req_optionid;
    private $stashed_options = false;
    private $fake_prow;

    function option_request_to_json(SettingValues $sv, $xpos) {
        $name = simplify_whitespace($sv->reqv("optn_$xpos") ?? "");
        if (preg_match('/\A(?:paper|submission|final|none|any|all|true|false|opt(?:ion)?[-:_ ]?\d+)\z/i', $name)) {
            $sv->error_at("optn_$xpos", "Option name “" . htmlspecialchars($name) . "” is reserved. Please pick another name.");
        } else if ($name === "" || $name === "Field name") {
            $sv->error_at("optn_$xpos", "Option name required.");
        }

        $idname = $sv->reqv("optid_$xpos") ?? "new";
        if ($idname === "new") {
            if (!$this->next_optionid) {
                $this->known_optionids = [];
                $result = $sv->conf->qe("select distinct optionId from PaperOption where optionId>0 union select distinct documentType from PaperStorage where documentType>0");
                while (($row = $result->fetch_row())) {
                    $this->known_optionids[(int) $row[0]] = true;
                }
                $this->next_optionid = 1;
                foreach ($sv->conf->options()->universal() as $o) {
                    $this->known_optionids[$o->id] = true;
                }
            }
            while (isset($this->known_optionids[$this->next_optionid])) {
                ++$this->next_optionid;
            }
            $id = $this->next_optionid;
            ++$this->next_optionid;
        } else {
            $id = cvtint($idname);
        }
        $oarg = ["name" => $name, "id" => $id, "final" => false];

        if ($sv->reqv("optd_$xpos") && trim($sv->reqv("optd_$xpos")) != "") {
            $t = CleanHTML::basic_clean($sv->reqv("optd_$xpos"), $err);
            if ($t !== false) {
                $oarg["description"] = $t;
            } else {
                $sv->error_at("optd_$xpos", $err);
            }
        }

        if (($optvt = $sv->reqv("optvt_$xpos"))) {
            if (($pos = strpos($optvt, ":")) !== false) {
                $oarg["type"] = substr($optvt, 0, $pos);
                if (preg_match('/:ds_(\d+)/', $optvt, $m)) {
                    $oarg["display_space"] = (int) $m[1];
                }
            } else {
                $oarg["type"] = $optvt;
            }
        } else {
            $oarg["type"] = "checkbox";
        }

        if (($optec = $sv->reqv("optec_$xpos"))) {
            if ($optec === "final") {
                $oarg["final"] = true;
            } else if ($optec === "search") {
                $optecs = (string) $sv->reqv("optecs_$xpos");
                if ($optecs !== "" && $optecs !== "(All)") {
                    $ps = new PaperSearch($sv->conf->root_user(), $optecs);
                    if (!$this->fake_prow) {
                        $this->fake_prow = new PaperInfo(null, null, $sv->conf);
                    }
                    if ($ps->term()->script_expression($this->fake_prow, $ps) === null) {
                        $sv->error_at("optecs_$xpos", "Search too complex for field condition. (Not all search keywords are supported for field conditions.)");
                    } else {
                        $oarg["exists_if"] = $optecs;
                    }
                    if ($ps->has_problem()) {
                        $sv->warning_at("optecs_$xpos", join("<br>", $ps->problem_texts()));
                    }
                }
            }
        }

        $jtype = $sv->conf->option_type($oarg["type"]);
        if ($jtype && ($jtype->has_selector ?? false)) {
            $oarg["selector"] = array();
            $seltext = trim(cleannl($sv->reqv("optv_$xpos") ?? ""));
            if ($seltext != "") {
                foreach (explode("\n", $seltext) as $t) {
                    $oarg["selector"][] = $t;
                }
            } else {
                $sv->error_at("optv_$xpos", "Enter selectors one per line.");
            }
        }

        $oarg["visibility"] = $sv->reqv("optp_$xpos") ?? "rev";
        $oarg["position"] = (int) $sv->reqv("optfp_$xpos") ?? 1;
        $oarg["display"] = $sv->reqv("optdt_$xpos");
        $oarg["required"] = !!$sv->reqv("optreq_$xpos");

        return PaperOption::make((object) $oarg, $sv->conf);
    }

    function parse(SettingValues $sv, Si $si) {
        $new_opts = Options_SettingRenderer::configurable_options($sv);

        // consider option ids
        $optids = array_map(function ($o) { return $o->id; }, $new_opts);
        for ($i = 1; $sv->has_reqv("optid_$i"); ++$i) {
            $optids[] = intval($sv->reqv("optid_$i"));
        }
        $optids[] = 0;
        $this->req_optionid = max($optids) + 1;

        // convert request to JSON
        for ($i = 1; $sv->has_reqv("optid_$i"); ++$i) {
            if ($sv->reqv("optfp_$i") === "deleted") {
                unset($new_opts[cvtint($sv->reqv("optid_$i"))]);
            } else if (($o = $this->option_request_to_json($sv, $i))) {
                $new_opts[$o->id] = $o;
            }
        }

        if (!$sv->has_error()) {
            uasort($new_opts, "PaperOption::compare");
            $this->stashed_options = $new_opts;
            $sv->request_write_lock("PaperOption");
            return true;
        }
    }

    function unparse_json(SettingValues $sv, Si $si, $j) {
        $oj = [];
        foreach (Options_SettingRenderer::configurable_options($sv) as $o) {
            $oj[] = $o->jsonSerialize();
        }
        $j->options = $oj;
    }

    function save(SettingValues $sv, Si $si) {
        $newj = [];
        foreach ($this->stashed_options as $o) {
            $newj[] = $o->jsonSerialize();
        }
        $sv->save("next_optionid", null);
        if ($sv->update("options", empty($newj) ? null : json_encode_db($newj))) {
            $deleted_ids = array();
            foreach (Options_SettingRenderer::configurable_options($sv) as $o) {
                $newo = $this->stashed_options[$o->id] ?? null;
/*                if (!$newo
                    || ($newo->type !== $o->type
                        && !$newo->change_type($o, true, true)
                        && !$o->change_type($newo, false, true))) {
                    $deleted_ids[] = $o->id;
                } */
            }
            if (!empty($deleted_ids)) {
                $sv->conf->qe("delete from PaperOption where optionId?a", $deleted_ids);
            }
            $sv->mark_invalidate_caches(["options" => true, "autosearch" => true]);
        }
    }
}
