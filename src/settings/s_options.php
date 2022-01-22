<?php
// src/settings/s_options.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

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
        uasort($jtypes, "Conf::xt_order_compare");

        $otypes = [];
        foreach ($jtypes as $uf) {
            if (!isset($uf->display_if)
                || $sv->conf->xt_check($uf->display_if, $uf, $sv->user)
                || $uf->name === $optvt)
                $otypes[$uf->name] = $uf->title ?? $uf->name;
        }

        $id = "sf__{$xpos}__type";
        echo '<div class="', $sv->control_class($id, "entryi"),
            '">', $sv->label($id, "Type"),
            '<div class="entry">', $sv->feedback_at($id),
            Ht::select($id, $otypes, $optvt, $sv->sjs($id, ["class" => "uich js-settings-sf-type", "id" => $id])),
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
        $id = "sf__{$xpos}__choices";
        echo '<div class="', $sv->control_class($id, "entryi has-optvt-condition$k"),
            '" data-optvt-condition="selector radio">', $sv->label($id, "Choices"),
            '<div class="entry">', $sv->feedback_at($id),
            Ht::textarea($id, $value, $sv->sjs($id, ["rows" => $rows, "cols" => 50, "id" => $id, "class" => "w-entry-text need-autogrow need-tooltip", "data-tooltip-info" => "settings-sf", "data-tooltip-type" => "focus"])),
            "</div></div>\n";
    }
    static function render_description_property(SettingValues $sv, PaperOption $o, $xpos, $self, $gj) {
        $id = "sf__{$xpos}__description";
        echo '<div class="', $sv->control_class($id, "entryi is-property-description"),
            '">', $sv->label($id, "Description"),
            '<div class="entry">', $sv->feedback_at($id),
            Ht::textarea($id, $o->description, $sv->sjs($id, ["rows" => 2, "cols" => 80, "id" => $id, "class" => "w-entry-text settings-sf-description need-autogrow"])),
            '</div></div>';
    }
    static function render_presence_property(SettingValues $sv, PaperOption $o, $xpos, $self, $gj) {
        $id = "sf__{$xpos}__presence";
        echo '<div class="', $sv->control_class($id, "entryi is-property-editing"),
            '">', $sv->label($id, "Present on"),
            '<div class="entry">', $sv->feedback_at($id),
            Ht::select($id, ["all" => "All submissions", "final" => "Final versions only"], $o->final ? "final" : "all", $sv->sjs($id, ["id" => $id])),
            "</div></div>";
    }
    static function render_required_property(SettingValues $sv, PaperOption $o, $xpos, $self, $gj) {
        $id = "sf__{$xpos}__required";
        echo '<div class="', $sv->control_class($id, "entryi is-property-editing"),
            '">', $sv->label($id, "Required"),
            '<div class="entry">', $sv->feedback_at($id),
            Ht::select($id, ["0" => "No", "1" => "Yes"], $o->required ? "1" : "0", $sv->sjs($id, ["id" => $id])),
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
        $id = "sf__{$xpos}__visibility";
        echo '<div class="', $sv->control_class($id, "entryi is-property-visibility short has-fold fold" . ($vis === "review" ? "o" : "c")),
            '" data-fold-values="review">', $sv->label($id, "Visibility"),
            '<div class="entry">', $sv->feedback_at($id),
            Ht::select($id, $options, $vis, $sv->sjs($id, ["id" => $id, "class" => "settings-sf-visibility uich js-foldup"])),
            '<div class="hint fx">The field will be visible to reviewers who have submitted a review, and to PC members who can see all reviews.</div>',
            '</div></div>';
    }
    static function render_display_property(SettingValues $sv, PaperOption $o, $xpos, $self, $gj) {
        $id = "sf__{$xpos}__display";
        echo '<div class="', $sv->control_class($id, "entryi is-property-display short"),
            '">', $sv->label($id, "Display"),
            '<div class="entry">', $sv->feedback_at($id),
            Ht::select($id, ["prominent" => "Normal",
                             "topics" => "Grouped with topics",
                             "submission" => "Near submission"],
                       $o->display_name(),
                       $sv->sjs($id, ["id" => $id, "class" => "settings-sf-display"])),
            "</div></div>";
    }

    /** @return array<int,PaperOption> */
    static function configurable_options(Conf $conf) {
        $o = [];
        foreach ($conf->options() as $opt) {
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
            "order" => 1000,
            "display" => "prominent",
            "json_key" => "__fake__"
        ]);
    }
    /** @param string $expr
     * @param int $pos
     * @param bool $is_error */
    static function validate_condition(SettingValues $sv, $expr, $pos, $is_error) {
        $id = "sf__{$pos}__condition";
        $ps = new PaperSearch($sv->conf->root_user(), $expr);
        foreach ($ps->message_list() as $mi) {
            $sv->append_item_at($id, $mi);
            $sv->msg_at("sf__{$pos}__presence", "", $mi->status);
        }
        $fake_prow = new PaperInfo(null, null, $sv->conf);
        if ($ps->term()->script_expression($fake_prow) === null) {
            $method = $is_error ? "error_at" : "warning_at";
            $sv->$method($id, "<0>Search too complex for field condition");
            $sv->inform_at($id, "<0>Not all search keywords are supported for field conditions.");
            $sv->$method("sf__{$pos}__presence", "");
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

        if ($sv->has_reqv("sf__{$ipos}__name")) {
            $name = simplify_whitespace($sv->reqv("sf__{$ipos}__name") ?? "");
            if ($name === "" || strcasecmp($name, "Field name") === 0) {
                $sv->error_at("sf__{$ipos}__name", "<0>Entry required");
            } if (preg_match('/\A(?:paper|submission|final|none|any|all|true|false|opt(?:ion)?[-:_ ]?\d+)\z/i', $name)) {
                $sv->error_at("sf__{$ipos}__name", "<0>Field name ‘{$name}’ is reserved");
                $sv->inform_at("sf__{$ipos}__name", "Please pick another name.");
            }
            $args->name = $name;
        }

        if ($sv->has_reqv("sf__{$ipos}__type")) {
            $vt = $sv->reqv("sf__{$ipos}__type");
            if (($pos = strpos($vt, ":")) !== false) {
                $args->type = substr($vt, 0, $pos);
                if (preg_match('/:ds_(\d+)/', $vt, $m)) {
                    $args->display_space = (int) $m[1];
                }
            } else {
                $args->type = $vt;
            }
        }

        if ($sv->has_reqv("sf__{$ipos}__description")) {
            $ch = CleanHTML::basic();
            if (($t = $ch->clean($sv->reqv("sf__{$ipos}__description"))) !== false) {
                $args->description = $t;
            } else {
                $sv->error_at("sf__{$ipos}__description", "<5>" . $ch->last_error);
                $args->description = $sv->reqv("sf__{$ipos}__description");
            }
        }

        if ($sv->has_reqv("sf__{$ipos}__visibility")) {
            $args->visibility = $sv->reqv("sf__{$ipos}__visibility");
        }

        if ($sv->has_reqv("sf__{$ipos}__order")) {
            $args->order = (int) $sv->reqv("sf__{$ipos}__order");
        }

        if ($sv->has_reqv("sf__{$ipos}__display")) {
            $args->display = $sv->reqv("sf__{$ipos}__display");
        }

        if ($sv->has_reqv("sf__{$ipos}__required")) {
            $args->required = $sv->reqv("sf__{$ipos}__required") == "1";
        }

        if ($sv->has_reqv("sf__{$ipos}__presence")) {
            $ec = $sv->reqv("sf__{$ipos}__presence");
            $args->final = $ec === "final";
            $ecs = $ec === "search" ? simplify_whitespace($sv->reqv("sf__{$ipos}__condition")) : "";
            if ($ecs === "" || $ecs === "(All)") {
                unset($args->exists_if);
            } else if ($ecs !== null) {
                self::validate_condition($sv, $ecs, $ipos, $ecs !== $args->exists_if);
                $args->exists_if = $ecs;
            }
        }

        if ($sv->has_reqv("sf__{$ipos}__choices")) {
            $args->selector = [];
            foreach (explode("\n", trim(cleannl($sv->reqv("sf__{$ipos}__choices")))) as $t) {
                if ($t !== "")
                    $args->selector[] = $t;
            }
            if (empty($args->selector)
                && ($jtype = $sv->conf->option_type($args->type))
                && ($jtype->has_selector ?? false)) {
                $sv->error_at("sf__{$ipos}__choices", "<0>Entry required (one choice per line)");
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
            $sv->set_oldv("sf__{$xpos}__name", $io->name);
            $sv->set_oldv("sf__{$xpos}__description", $io->description);
            $sv->set_oldv("sf__{$xpos}__type", $io->type);
            $sv->set_oldv("sf__{$xpos}__visibility", $io->unparse_visibility());
            $sv->set_oldv("sf__{$xpos}__display", $io->display_name());
            $sv->set_oldv("sf__{$xpos}__required", $io->required ? "1" : "0");
            $sv->set_oldv("sf__{$xpos}__presence", $io->exists_condition() ? "search" : ($io->final ? "final" : "all"));
            $sv->set_oldv("sf__{$xpos}__condition", $io->exists_condition());
            $config_open = json_encode($io) !== json_encode($o)
                || $sv->has_problem_at("sf__{$xpos}");
        } else {
            $config_open = true;
        }

        echo '<div class="settings-sf has-fold fold2', $config_open ? "o" : "c", '">',
            '<a href="" class="q ui js-settings-field-unfold">', expander(null, 2), '</a>';

        // field rendering
        if ($io) {
            echo '<div class="settings-sf-view fn2 ui js-foldup">';
            if ($io->exists_condition()) {
                $this->pt->msg_at($io->formid, "<0>Present on submissions matching ‘" . $io->exists_condition() . "’", MessageSet::WARNING_NOTE);
            }
            if ($io->final) {
                $this->pt->msg_at($io->formid, "<0>Present on final versions", MessageSet::WARNING_NOTE);
            }
            if ($io->editable_condition()) {
                $this->pt->msg_at($io->formid, "<0>Editable on submisisons matching ‘" . $io->editable_condition() . "’", MessageSet::WARNING_NOTE);
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
        echo '<div class="fx2"><div class="', $sv->control_class("sf__{$xpos}__name", "f-i"), '">',
            $sv->feedback_at("sf__{$xpos}__name"),
            Ht::entry("sf__{$xpos}__name", $o->name, $sv->sjs("sf__{$xpos}__name", ["placeholder" => "Field name", "size" => 50, "id" => "sf__{$xpos}__name", "class" => "need-tooltip font-weight-bold", "data-tooltip-info" => "settings-sf", "data-tooltip-type" => "focus", "aria-label" => "Field name"])),
            Ht::hidden("has_sf__{$xpos}__name", 1),
            Ht::hidden("sf__{$xpos}__id", $o->id > 0 ? $o->id : "new", ["class" => "settings-sf-id", "data-default-value" => $o->id > 0 ? $o->id : ""]),
            Ht::hidden("sf__{$xpos}__order", count($this->rendered_options), ["class" => "settings-sf-order", "data-default-value" => count($this->rendered_options)]),
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
            Ht::button(Icons::ui_use("movearrow0"), ["class" => "btn-licon ui js-settings-sf-move moveup need-tooltip", "aria-label" => "Move up in display order"]),
            Ht::button(Icons::ui_use("movearrow2"), ["class" => "btn-licon ui js-settings-sf-move movedown need-tooltip", "aria-label" => "Move down in display order"]),
            '</span>',
            Ht::button(Icons::ui_use("trash"), ["class" => "btn-licon ui js-settings-sf-move delete need-tooltip", "aria-label" => "Delete", "data-option-exists" => $this->have_options[$o->id] ?? false]),
            "</div></div>\n",
            '</div>';

        // close option
        echo '</div>';
    }

    static function render(SettingValues $sv) {
        $self = new Options_SettingRenderer;
        $self->pt = new PaperTable($sv->user, new Qrequest("GET"));
        $self->pt->edit_show_all_visibility = true;
        echo "<hr class=\"g\">\n",
            Ht::hidden("has_options", 1),
            Ht::hidden("options_version", (int) $sv->conf->setting("options")),
            "\n\n";
        Icons::stash_defs("movearrow0", "movearrow2", "trash");
        echo Ht::unstash();

        echo '<div id="settings-sform" class="c">';
        $iposl = [];
        if ($sv->use_req()) {
            for ($ipos = 1; $sv->has_reqv("sf__{$ipos}__id"); ++$ipos) {
                $iposl[] = $ipos;
            }
            usort($iposl, function ($a, $b) use ($sv) {
                return (int) $sv->reqv("sf__{$a}__order") <=> (int) $sv->reqv("sf__{$b}__order") ? : $a <=> $b;
            });
        }
        $self->rendered_options = [];
        $self->max_xpos = 0;
        foreach ($iposl as $ipos) {
            $id = $sv->reqv("sf__{$ipos}__id");
            $o = $id === "new" ? null : $sv->conf->option_by_id((int) $id);
            if ($id === "new" || $o) {
                $self->render_option($sv, $o, $ipos);
            }
        }
        $all_options = self::configurable_options($sv->conf); // get our own iterator
        foreach ($all_options as $o) {
            $self->render_option($sv, $o, null);
        }
        echo "</div>\n";

        // render sample options
        echo '<div id="settings-sform-samples" class="hidden">';
        $jtypes = $sv->conf->option_type_map();
        uasort($jtypes, "Conf::xt_order_compare");

        $otypes = [];
        foreach ($jtypes as $uf) {
            if (!isset($uf->display_if)
                || $sv->conf->xt_check($uf->display_if, $uf, $sv->user)) {
                $args = [
                    "id" => 1000,
                    "name" => $uf->title ?? $uf->name,
                    "type" => $uf->type ?? $uf->name,
                    "order" => 1,
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
        $conf = $sv->conf;
        $options = array_values(Options_SettingRenderer::configurable_options($conf));
        if (($sv->has_interest("options") || $sv->has_interest("sub_blind"))
            && $conf->setting("sub_blind") == Conf::BLIND_ALWAYS) {
            foreach ($options as $pos => $o) {
                if ($o->visibility() === PaperOption::VIS_AUTHOR) {
                    $field = "sf__" . ($pos + 1) . "__visibility";
                    $sv->warning_at($field, "<5>" . $sv->setting_link("All submissions are blind", "sub_blind") . ", so this field is always hidden");
                    $sv->inform_at($field, "<0>Would “hidden until review” visibility be better?");
                    $sv->warning_at("sf__" . ($pos + 1));
                }
            }
        }
        if ($sv->has_interest("options")) {
            foreach ($options as $pos => $o) {
                if (($q = $o->exists_condition())) {
                    self::validate_condition($sv, $q, $pos + 1, false);
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
        $idname = $sv->reqv("sf__{$xpos}__id") ?? "new";
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

    function set_oldv(SettingValues $sv, Si $si) {
        if (preg_match('/\Asf__(\d*)__name\z/', $si->name, $m)) {
            $options = array_values(Options_SettingRenderer::configurable_options($sv->conf));
            if ($m[1] >= 1 && $m[1] <= count($options)) {
                $sv->set_oldv($si->name, $options[intval($m[1]) - 1]->name);
                return true;
            }
        }
        return false;
    }

    function parse_req(SettingValues $sv, Si $si) {
        if ($sv->has_reqv("options_version")
            && (int) $sv->reqv("options_version") !== (int) $sv->conf->setting("options")) {
            $sv->error_at("options", "<0>You modified options settings in another tab. Please reload.");
        }

        $new_opts = Options_SettingRenderer::configurable_options($sv->conf);

        // consider option ids
        $optids = array_map(function ($o) { return $o->id; }, $new_opts);
        for ($i = 1; $sv->has_reqv("sf__{$i}__id"); ++$i) {
            $optids[] = intval($sv->reqv("sf__{$i}__id"));
        }
        $optids[] = 0;
        $this->req_optionid = max($optids) + 1;

        // convert request to JSON
        for ($i = 1; $sv->has_reqv("sf__{$i}__id"); ++$i) {
            if ($sv->reqv("sf__{$i}__order") === "deleted") {
                unset($new_opts[cvtint($sv->reqv("sf__{$i}__id"))]);
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
        foreach (Options_SettingRenderer::configurable_options($sv->conf) as $o) {
            $oj[] = $o->jsonSerialize();
        }
        return $oj;
    }

    function store_value(SettingValues $sv, Si $si) {
        $deleted_ids = [];
        foreach (Options_SettingRenderer::configurable_options($sv->conf) as $o) {
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
