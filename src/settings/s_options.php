<?php
// src/settings/s_options.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class Options_SettingRenderer {
    /** @var PaperTable */
    private $pt;
    /** @var array<int,bool> */
    private $rendered_options = [];
    /** @var int */
    private $max_xpos = 0;
    /** @var ?array<int,int> */
    private $have_options;

    static function render_type_property(SettingValues $sv, PaperOption $o, $xpos, $self, $gj) {
        $optvt = $o->type;
        if ($o instanceof Text_PaperOption && $o->display_space > 3) {
            $optvt .= ":ds_" . $o->display_space;
        }

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

        echo '<div class="', $sv->control_class("sf_type_$xpos", "entryi"),
            '">', $sv->label("sf_type_$xpos", "Type"),
            '<div class="entry">',
            $sv->feedback_at("sf_type_$xpos"),
            Ht::select("sf_type_$xpos", $otypes, $optvt, $sv->sjs("sf_type_$xpos", ["class" => "uich js-settings-sf-type", "id" => "sf_type_$xpos"])),
            "</div></div>\n";

        if ($o instanceof Selector_PaperOption) {
            $k = "";
            if (($options = $o->selector_options())) {
                $options[] = "";
            }
            $rows = max(count($options), 3);
            $value = join("\n", $options);
        } else {
            $k = " hidden";
            $rows = 3;
            $value = "";
        }
        echo '<div class="', $sv->control_class("sf_options_$xpos", "entryi has-optvt-condition$k"),
            '" data-optvt-condition="selector radio">', $sv->label("sf_options_$xpos", "Choices"),
            '<div class="entry">',
            $sv->feedback_at("sf_options_$xpos"),
            Ht::textarea("sf_options_$xpos", $value, $sv->sjs("sf_options_$xpos", ["rows" => $rows, "cols" => 50, "id" => "sf_options_$xpos", "class" => "w-entry-text need-autogrow need-tooltip", "data-tooltip-info" => "settings-sf", "data-tooltip-type" => "focus"])),
            "</div></div>\n";
    }
    static function render_description_property(SettingValues $sv, PaperOption $o, $xpos, $self, $gj) {
        echo '<div class="', $sv->control_class("sf_description_$xpos", "entryi is-property-description"),
            '">', $sv->label("sf_description_$xpos", "Description"),
            '<div class="entry">',
            $sv->feedback_at("sf_description_$xpos"),
            Ht::textarea("sf_description_$xpos", $o->description, $sv->sjs("sf_description_$xpos", ["rows" => 2, "cols" => 80, "id" => "sf_description_$xpos", "class" => "w-entry-text settings-sf-description need-autogrow"])),
            '</div></div>';
    }
    static function render_presence_property(SettingValues $sv, PaperOption $o, $xpos, $self, $gj) {
        echo '<div class="', $sv->control_class("sf_presence_$xpos", "entryi is-property-editing"),
            '">', $sv->label("sf_presence_$xpos", "Present on"),
            '<div class="entry">',
            $sv->feedback_at("sf_presence_$xpos"),
            Ht::select("sf_presence_$xpos", ["all" => "All submissions", "final" => "Final versions only"], $o->final ? "final" : "all", $sv->sjs("sf_presence_$xpos", ["id" => "sf_presence_$xpos"])),
            "</div></div>";
    }
    static function render_required_property(SettingValues $sv, PaperOption $o, $xpos, $self, $gj) {
        echo '<div class="', $sv->control_class("sf_required_$xpos", "entryi is-property-editing"),
            '">', $sv->label("sf_required_$xpos", "Required"),
            '<div class="entry">',
            $sv->feedback_at("sf_required_$xpos"),
            Ht::select("sf_required_$xpos", ["0" => "No", "1" => "Yes"], $o->required ? "1" : "0", $sv->sjs("sf_required_$xpos", ["id" => "sf_required_$xpos"])),
            "</div></div>";
    }
    static function render_visibility_property(SettingValues $sv, PaperOption $o, $xpos, $self, $gj) {
        $vis = $o->unparse_visibility();
        $options = ["all" => "Visible to reviewers"];
        $options["nonblind"] = "Hidden on blind submissions";
        if ($vis === "conflict") {
            $options["conflict"] = "Hidden until conflicts are visible";
        }
        $options["review"] = "Hidden until review";
        $options["admin"] = "Hidden from reviewers";
        echo '<div class="', $sv->control_class("sf_visibility_$xpos", "entryi is-property-visibility short has-fold fold" . ($vis === "review" ? "o" : "c")),
            '" data-fold-values="review">', $sv->label("sf_visibility_$xpos", "Visibility"),
            '<div class="entry">',
            $sv->feedback_at("sf_visibility_$xpos"),
            Ht::select("sf_visibility_$xpos", $options, $vis, $sv->sjs("sf_visibility_$xpos", ["id" => "sf_visibility_$xpos", "class" => "settings-sf-visibility uich js-foldup"])),
            '<div class="hint fx">The field will be visible to reviewers who have submitted a review, and to PC members who can see all reviews.</div>',
            '</div></div>';
    }
    static function render_display_property(SettingValues $sv, PaperOption $o, $xpos, $self, $gj) {
        echo '<div class="', $sv->control_class("sf_display_$xpos", "entryi is-property-display short"),
            '">', $sv->label("sf_display_$xpos", "Display"),
            '<div class="entry">',
            $sv->feedback_at("sf_display_$xpos"),
            Ht::select("sf_display_$xpos", ["prominent" => "Normal",
                                       "topics" => "Grouped with topics",
                                       "submission" => "Near submission"],
                       $o->display_name(),
                       $sv->sjs("sf_display_$xpos", ["id" => "sf_display_$xpos", "class" => "settings-sf-display"])),
            "</div></div>";
    }

    /** @return array<int,PaperOption> */
    static function configurable_options(SettingValues $sv) {
        $o = [];
        foreach ($sv->conf->options() as $opt) {
            if ($opt->configurable)
                $o[$opt->id] = $opt;
        }
        return $o;
    }
    /** @return PaperOption */
    static function make_placeholder_option(SettingValues $sv, $id) {
        return PaperOption::make($sv->conf, (object) [
            "id" => $id,
            "name" => "Field name",
            "description" => "",
            "type" => "checkbox",
            "position" => 1000,
            "display" => "prominent",
            "json_key" => "__fake__"
        ]);
    }
    /** @param string $expr
     * @param string $field
     * @param bool $is_error */
    static function validate_condition(SettingValues $sv, $expr, $field, $is_error) {
        $fieldx = preg_replace('/q(_[\d$]+)\z/', '$1', $field);
        $ps = new PaperSearch($sv->conf->root_user(), $expr);
        $fake_prow = new PaperInfo(null, null, $sv->conf);
        if ($ps->term()->script_expression($fake_prow) === null) {
            $method = $is_error ? "error_at" : "warning_at";
            $sv->$method($field, "Search too complex for field condition. (Not all search keywords are supported for field conditions.)");
            $sv->$method($fieldx, "");
        }
        foreach ($ps->message_set()->message_list() as $mi) {
            $sv->add_at($field, $mi);
            $sv->msg_at($fieldx, "", $mi->status);
        }
    }
    /** @return PaperOption */
    static function make_requested_option(SettingValues $sv, PaperOption $io = null, $ipos) {
        $io = $io ?? self::make_placeholder_option($sv, -999);
        if ($ipos === null || $ipos === 0) {
            return $io;
        }

        $args = $io->jsonSerialize();
        $args->json_key = $io->id > 0 ? null : "__fake__";

        if ($sv->has_reqv("sf_name_$ipos")) {
            $name = simplify_whitespace($sv->reqv("sf_name_$ipos") ?? "");
            if ($name === "" || strcasecmp($name, "Field name") === 0) {
                $sv->error_at("sf_name_$ipos", "Submission field name required.");
            } if (preg_match('/\A(?:paper|submission|final|none|any|all|true|false|opt(?:ion)?[-:_ ]?\d+)\z/i', $name)) {
                $sv->error_at("sf_name_$ipos", "Field name “" . htmlspecialchars($name) . "” is reserved. Please pick another name.");
            }
            $args->name = $name;
        }

        if ($sv->has_reqv("sf_type_$ipos")) {
            $vt = $sv->reqv("sf_type_$ipos");
            if (($pos = strpos($vt, ":")) !== false) {
                $args->type = substr($vt, 0, $pos);
                if (preg_match('/:ds_(\d+)/', $vt, $m)) {
                    $args->display_space = (int) $m[1];
                }
            } else {
                $args->type = $vt;
            }
        }

        if ($sv->has_reqv("sf_description_$ipos")) {
            $ch = CleanHTML::basic();
            if (($t = $ch->clean($sv->reqv("sf_description_$ipos"))) !== false) {
                $args->description = $t;
            } else {
                $sv->error_at("sf_description_$ipos", $ch->last_error);
                $args->description = $sv->reqv("sf_description_$ipos");
            }
        }

        if ($sv->has_reqv("sf_visibility_$ipos")) {
            $args->visibility = $sv->reqv("sf_visibility_$ipos");
        }

        if ($sv->has_reqv("optfp_$ipos")) {
            $args->position = (int) $sv->reqv("optfp_$ipos");
        }

        if ($sv->has_reqv("sf_display_$ipos")) {
            $args->display = $sv->reqv("sf_display_$ipos");
        }

        if ($sv->has_reqv("sf_required_$ipos")) {
            $args->required = $sv->reqv("sf_required_$ipos") == "1";
        }

        if ($sv->has_reqv("sf_presence_$ipos")) {
            $ec = $sv->reqv("sf_presence_$ipos");
            $args->final = $ec === "final";
            $ecs = $ec === "search" ? simplify_whitespace($sv->reqv("sf_presenceq_$ipos")) : "";
            if ($ecs === "" || $ecs === "(All)") {
                unset($args->exists_if);
            } else if ($ecs !== null) {
                self::validate_condition($sv, $ecs, "sf_presenceq_$ipos", $ecs !== $args->exists_if);
                $args->exists_if = $ecs;
            }
        }

        if ($sv->has_reqv("sf_options_$ipos")) {
            $args->selector = [];
            foreach (explode("\n", trim(cleannl($sv->reqv("sf_options_$ipos")))) as $t) {
                if ($t !== "")
                    $args->selector[] = $t;
            }
            if (empty($args->selector)
                && ($jtype = $sv->conf->option_type($args->type))
                && ($jtype->has_selector ?? false)) {
                $sv->error_at("sf_options_$ipos", "Choices missing: enter one choice per line.");
            }
        }

        return PaperOption::make($sv->conf, (object) $args);
    }

    private function render_option(SettingValues $sv, PaperOption $io = null, $ipos) {
        if ($io && isset($this->rendered_options[$io->id])) {
            return;
        }

        $xpos = $ipos ?? $this->max_xpos + 1;
        $this->max_xpos = max($this->max_xpos, $xpos);
        $this->rendered_options[$io ? $io->id : "new_$xpos"] = true;

        $old_ignore = $sv->swap_ignore_messages(true);
        $o = self::make_requested_option($sv, $io, $ipos);
        $sv->swap_ignore_messages($old_ignore);

        if ($io) {
            $sv->set_oldv("sf_name_$xpos", $io->name);
            $sv->set_oldv("sf_description_$xpos", $io->description);
            $sv->set_oldv("sf_type_$xpos", $io->type);
            $sv->set_oldv("sf_visibility_$xpos", $io->unparse_visibility());
            $sv->set_oldv("sf_display_$xpos", $io->display_name());
            $sv->set_oldv("sf_required_$xpos", $io->required ? "1" : "0");
            $sv->set_oldv("sf_presence_$xpos", $io->exists_condition() ? "search" : ($io->final ? "final" : "all"));
            $sv->set_oldv("sf_presenceq_$xpos", $io->exists_condition());
            $config_open = json_encode($io) !== json_encode($o);
        } else {
            $config_open = true;
        }

        echo '<div class="settings-sf has-fold fold2', $config_open ? "o" : "c", '">',
            '<a href="" class="q ui js-settings-field-unfold">', expander(null, 2), '</a>';

        // field rendering
        if ($io) {
            echo '<div class="settings-sf-view fn2 ui js-foldup">';
            if ($io->exists_condition()) {
                $this->pt->msg_at($io->formid, "Present on submissions matching “" . htmlspecialchars($io->exists_condition()) . "”", MessageSet::WARNING_NOTE);
            }
            if ($io->final) {
                $this->pt->msg_at($io->formid, "Present on final versions", MessageSet::WARNING_NOTE);
            }
            if ($io->editable_condition()) {
                $this->pt->msg_at($io->formid, "Editable on submisisons matching “" . htmlspecialchars($io->editable_condition()) . "”", MessageSet::WARNING_NOTE);
            }
            $ei = $io->editable_condition();
            $xi = $io->exists_condition();
            $io->set_editable_condition(true);
            $io->set_exists_condition(true);
            $ov = $this->pt->prow->force_option($io);
            $io->echo_web_edit($this->pt, $ov, $ov);
            $io->set_editable_condition($ei);
            $io->set_exists_condition($xi);
            echo '</div>';
        }

        // field configuration
        echo '<div class="fx2"><div class="', $sv->control_class("sf_name_$xpos", "f-i"), '">',
            $sv->feedback_at("sf_name_$xpos"),
            Ht::entry("sf_name_$xpos", $o->name, $sv->sjs("sf_name_$xpos", ["placeholder" => "Field name", "size" => 50, "id" => "sf_name_$xpos", "class" => "need-tooltip font-weight-bold", "data-tooltip-info" => "settings-sf", "data-tooltip-type" => "focus", "aria-label" => "Field name"])),
            Ht::hidden("optid_$xpos", $o->id > 0 ? $o->id : "new", ["class" => "settings-sf-id", "data-default-value" => $o->id > 0 ? $o->id : ""]),
            Ht::hidden("optfp_$xpos", count($this->rendered_options), ["class" => "settings-sf-fp", "data-default-value" => count($this->rendered_options)]),
            '</div>';

        Ht::stash_html('<div id="settings-sf-caption-name" class="hidden"><p>Field names should be short and memorable (they are used as search keywords).</p></div>', 'settings-sf-caption-name');
        Ht::stash_html('<div id="settings-sf-caption-choices" class="hidden"><p>Enter choices one per line.</p></div>', 'settings-sf-caption-choices');

        foreach ($sv->group_members("options/properties") as $gj) {
            if (isset($gj->render_option_property_function)) {
                Conf::xt_resolve_require($gj);
                $t = call_user_func($gj->render_option_property_function, $sv, $o, $xpos, $this, $gj);
                if (is_string($t)) { // XXX backward compat
                    echo $t;
                }
            }
        }

        if ($o->id && $this->have_options === null) {
            $this->have_options = [];
            foreach ($sv->conf->fetch_rows("select optionId, count(distinct paperId) from PaperOption group by optionId") as $row) {
                $this->have_options[(int) $row[0]] = (int) $row[1];
            }
        }

        echo '<div class="f-i entryi"><label></label><div class="btnp entry"><span class="btnbox">',
            Ht::button(Icons::ui_movearrow(0), ["class" => "btn-licon ui js-settings-sf-move moveup need-tooltip", "aria-label" => "Move up in display order"]),
            Ht::button(Icons::ui_movearrow(2), ["class" => "btn-licon ui js-settings-sf-move movedown need-tooltip", "aria-label" => "Move down in display order"]),
            '</span>',
            Ht::button(Icons::ui_trash(), ["class" => "btn-licon ui js-settings-sf-move delete need-tooltip", "aria-label" => "Delete", "data-option-exists" => $this->have_options[$o->id] ?? false]),
            "</div></div>\n",
            '</div>';

        // close option
        echo '</div>';
    }

    static function render(SettingValues $sv) {
        $self = new Options_SettingRenderer;
        $self->pt = new PaperTable($sv->user, new Qrequest("GET"));
        $self->pt->edit_show_all_visibility = true;
        $sv->render_section("Submission fields");
        echo "<hr class=\"g\">\n",
            Ht::hidden("has_options", 1),
            Ht::hidden("options_version", (int) $sv->conf->setting("options")),
            "\n\n";

        echo '<div id="settings-sform" class="c">';
        $iposl = [];
        if ($sv->use_req()) {
            for ($ipos = 1; $sv->has_reqv("optid_$ipos"); ++$ipos) {
                $iposl[] = $ipos;
            }
            usort($iposl, function ($a, $b) use ($sv) {
                return (int) $sv->reqv("optfp_$a") <=> (int) $sv->reqv("optfp_$b") ? : $a <=> $b;
            });
        }
        $self->rendered_options = [];
        $self->max_xpos = 0;
        foreach ($iposl as $ipos) {
            $id = $sv->reqv("optid_$ipos");
            $o = $id === "new" ? null : $sv->conf->option_by_id((int) $id);
            if ($id === "new" || $o) {
                $self->render_option($sv, $o, $ipos);
            }
        }
        $all_options = self::configurable_options($sv); // get our own iterator
        foreach ($all_options as $o) {
            $self->render_option($sv, $o, null);
        }
        echo "</div>\n";

        // render sample options
        echo '<div id="settings-sform-samples" class="hidden">';
        $jtypes = $sv->conf->option_type_map();
        uasort($jtypes, "Conf::xt_position_compare");

        $otypes = [];
        foreach ($jtypes as $uf) {
            if (!isset($uf->display_if)
                || $sv->conf->xt_check($uf->display_if, $uf, $sv->user)) {
                $args = [
                    "id" => 1000,
                    "name" => $uf->title ?? $uf->name,
                    "type" => $uf->type ?? $uf->name,
                    "position" => 1,
                    "display" => "prominent",
                    "json_key" => "__demo_{$uf->name}__"
                ];
                if ($uf->sample ?? null) {
                    $args = array_merge((array) $uf->sample, $args);
                }
                $o = PaperOption::make($sv->conf, (object) $args);
                $ov = $o->parse_json($self->pt->prow, $args["value"] ?? null)
                    ?? PaperValue::make($self->pt->prow, $o);
                echo '<div class="settings-sf-view" data-name="', htmlspecialchars($uf->name), '" data-title="', htmlspecialchars($uf->title ?? $uf->name), '">';
                $o->echo_web_edit($self->pt, $ov, $ov);
                echo '</div>';
            }
        }

        echo '</div>';

        ob_start();
        $self->render_option($sv, null, 0);
        $newopt = ob_get_clean();

        echo '<div class="mt-5" id="settings-sf-new" data-template="',
            htmlspecialchars($newopt), '">',
            Ht::button("Add submission field", ["class" => "ui js-settings-sf-add"]),
            "</div>\n";
    }

    static function crosscheck(SettingValues $sv) {
        if (!$sv->newv("options")) {
            return;
        }
        $options = (array) json_decode($sv->newv("options"));
        usort($options, function ($a, $b) { return $a->position <=> $b->position; });
        if (($sv->has_interest("options") || $sv->has_interest("sub_blind"))
            && $sv->newv("sub_blind") == Conf::BLIND_ALWAYS) {
            foreach ($options as $pos => $o) {
                if (($o->visibility ?? null) === "nonblind") {
                    $sv->warning_at("sf_visibility_" . ($pos + 1), "The ‘" . htmlspecialchars($o->name) . "’ field is “hidden on blind submissions,” but all submissions are blind. You may want to change " . $sv->setting_link("Settings &gt; Submissions &gt; Blind submission", "sub_blind") . " to “Blind until review.”");
                }
            }
        }
        if ($sv->has_interest("options")) {
            foreach ($options as $pos => $o) {
                if ($o->exists_if ?? null) {
                    self::validate_condition($sv, $o->exists_if, "sf_presenceq_" . ($pos + 1), false);
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
            $io = Options_SettingRenderer::make_placeholder_option($sv, $this->next_optionid);
            ++$this->next_optionid;
        } else {
            $io = $sv->conf->option_by_id(cvtint($idname));
        }
        return Options_SettingRenderer::make_requested_option($sv, $io, $xpos);
    }

    function parse_req(SettingValues $sv, Si $si) {
        if ($sv->has_reqv("options_version")
            && (int) $sv->reqv("options_version") !== (int) $sv->conf->setting("options")) {
            $sv->error_at("options", "You modified options settings in another tab. Please reload.");
        }

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
            $newj = [];
            foreach ($new_opts as $o) {
                $newj[] = $o->jsonSerialize();
            }
            $sv->save("next_optionid", null);
            if ($sv->update("options", empty($newj) ? null : json_encode_db($newj))) {
                $sv->update("options_version", (int) $sv->conf->setting("options") + 1);
                $sv->request_write_lock("PaperOption");
                $sv->request_store_value($si);
            }
        }
    }

    function unparse_json(SettingValues $sv, Si $si) {
        $oj = [];
        foreach (Options_SettingRenderer::configurable_options($sv) as $o) {
            $oj[] = $o->jsonSerialize();
        }
        return $oj;
    }

    function store_value(SettingValues $sv, Si $si) {
        $deleted_ids = [];
        foreach (Options_SettingRenderer::configurable_options($sv) as $o) {
            $newo = $this->stashed_options[$o->id] ?? null;
            if (!$newo
                || ($newo->type !== $o->type
                    && !$newo->change_type($o, true, true)
                    && !$o->change_type($newo, false, true))) {
                $deleted_ids[] = $o->id;
            }
        }
        if (!empty($deleted_ids)) {
            $sv->conf->qe("delete from PaperOption where optionId?a", $deleted_ids);
        }
        $sv->mark_invalidate_caches(["autosearch" => true]);
    }
}
