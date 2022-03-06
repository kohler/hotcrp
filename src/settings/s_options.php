<?php
// settings/s_options.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Options_SettingRenderer {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var PaperTable
     * @readonly */
    private $pt;
    /** @var PaperOption */
    public $io;
    /** @var int|string */
    public $ctr;
    /** @var int|string */
    private $_last_ctr;
    /** @var ?array<int,int> */
    private $_paper_count;

    function __construct(SettingValues $sv) {
        $this->conf = $sv->conf;
        $this->pt = new PaperTable($sv->user, new Qrequest("GET"));
        $this->pt->edit_show_all_visibility = true;
    }


    /** @return int */
    function paper_count(PaperOption $io = null) {
        if ($this->_paper_count === null && $io) {
            $this->_paper_count = [];
            foreach ($this->conf->fetch_rows("select optionId, count(distinct paperId) from PaperOption group by optionId") as $row) {
                $this->_paper_count[(int) $row[0]] = (int) $row[1];
            }
        }
        return $io ? $this->_paper_count[$io->id] ?? 0 : 0;
    }


    function print_name(SettingValues $sv) {
        echo '<div class="', $sv->control_class("sf__{$this->ctr}__name", "entryi mb-3"), '">',
            '<div class="entry">',
            $sv->feedback_at("sf__{$this->ctr}__name");
        $sv->print_entry("sf__{$this->ctr}__name", [
            "class" => "need-tooltip font-weight-bold want-focus",
            "aria-label" => "Field name",
            "data-tooltip-info" => "settings-sf",
            "data-tooltip-type" => "focus"
        ]);
        echo '</div></div>';
    }

    function print_type(SettingValues $sv) {
        $curt = $sv->oldv("sf__{$this->ctr}__type");
        $jtypes = $sv->conf->option_type_map();
        uasort($jtypes, "Conf::xt_order_compare");

        $otypes = [];
        foreach ($sv->conf->option_type_map() as $uf) {
            if (($uf->name === $curt
                 || !isset($uf->display_if)
                 || $sv->conf->xt_check($uf->display_if, $uf, $sv->user))
                && ($uf->name === $curt
                    || $curt === "none"
                    || ($uf->convert_from_functions->{$curt} ?? false))) {
                $otypes[$uf->name] = $uf->title;
            }
        }

        if (count($otypes) === 1) {
            $sv->print_control_group("sf__{$this->ctr}__type", "Type",
                Ht::hidden("sf__{$this->ctr}__type", $curt) . $otypes[$curt], [
                "horizontal" => true
            ]);
        } else {
            $sv->print_select_group("sf__{$this->ctr}__type", "Type", $otypes, [
                "horizontal" => true, "class" => "uich js-settings-sf-type"
            ]);
        }
    }

    function print_choices(SettingValues $sv) {
        $type = $sv->vstr("sf__{$this->ctr}__type");
        $wanted = ["selector", "radio"];
        $sv->print_textarea_group("sf__{$this->ctr}__choices", "Choices", [
            "horizontal" => true,
            "class" => "w-entry-text need-tooltip",
            "data-tooltip-info" => "settings-sf",
            "data-tooltip-type" => "focus",
            "group_class" => "has-type-condition" . (in_array($type, $wanted) ? "" : " hidden"),
            "group_attr" => ["data-type-condition" => join(" ", $wanted)]
        ]);
    }

    function print_description(SettingValues $sv) {
        $sv->print_textarea_group("sf__{$this->ctr}__description", "Description", [
            "horizontal" => true,
            "class" => "w-entry-text settings-sf-description need-tooltip",
            "data-tooltip-info" => "settings-sf",
            "data-tooltip-type" => "focus",
            "group_class" => "is-property-description"
        ]);
    }

    function print_presence(SettingValues $sv) {
        $sv->print_select_group("sf__{$this->ctr}__presence", "Present on", [
            "all" => "All submissions", "final" => "Final versions only"
        ], [
            "horizontal" => true, "group_class" => "is-property-editing"
        ]);
    }

    function print_required(SettingValues $sv) {
        $sv->print_select_group("sf__{$this->ctr}__required", "Required", [
            "0" => "No", "1" => "Yes"
        ], [
            "horizontal" => true, "group_class" => "is-property-editing"
        ]);
    }

    function print_visibility(SettingValues $sv) {
        $options = [
            "all" => "Visible to reviewers",
            "nonblind" => "Hidden on blind submissions",
            "conflict" => "Hidden until conflicts are visible",
            "review" => "Hidden until review",
            "admin" => "Hidden from reviewers"
        ];
        if ($sv->oldv("sf__{$this->ctr}__visibility") !== "conflict") {
            unset($options["conflict"]);
        }
        $sv->print_select_group("sf__{$this->ctr}__visibility", "Visibility", $options, [
            "horizontal" => true, "group_class" => "is-property-visibility", "group_open" => true,
            "class" => "settings-sf-visibility", "fold_values" => ["review"]
        ]);
        echo '<div class="hint fx">The field will be visible to reviewers who have submitted a review, and to PC members who can see all reviews.</div>',
            '</div></div>';
    }

    function print_display(SettingValues $sv) {
        $sv->print_select_group("sf__{$this->ctr}__display", "Display", [
            "prominent" => "Normal", "topics" => "Grouped with topics", "submission" => "Near submission"
        ], [
            "horizontal" => true, "group_class" => "is-property-display", "class" => "settings-sf-display"
        ]);
    }

    function print_actions(SettingValues $sv) {
        echo '<div class="f-i entryi"><label></label><div class="btnp entry"><span class="btnbox">',
            Ht::button(Icons::ui_use("movearrow0"), ["class" => "btn-licon ui js-settings-sf-move moveup need-tooltip", "aria-label" => "Move up in display order"]),
            Ht::button(Icons::ui_use("movearrow2"), ["class" => "btn-licon ui js-settings-sf-move movedown need-tooltip", "aria-label" => "Move down in display order"]),
            '</span>',
            Ht::button(Icons::ui_use("trash"), ["class" => "btn-licon ui js-settings-sf-move delete need-tooltip", "aria-label" => "Delete", "data-sf-exists" => $this->paper_count($this->io)]),
            "</div></div>";
    }


    private function print_one_option_view(PaperOption $io, $ctr) {
        echo '<div id="sf__', $ctr, '__view" class="settings-sf-view fn2 ui js-foldup">';
        if ($io->exists_condition()) {
            $this->pt->msg_at($io->formid, "<0>Present on submissions matching ‘" . $io->exists_condition() . "’", MessageSet::WARNING_NOTE);
        }
        if ($io->final) {
            $this->pt->msg_at($io->formid, "<0>Present on final versions", MessageSet::WARNING_NOTE);
        }
        if ($io->editable_condition()) {
            $this->pt->msg_at($io->formid, "<0>Editable on submissions matching ‘" . $io->editable_condition() . "’", MessageSet::WARNING_NOTE);
        }
        $ei = $io->editable_condition();
        $xi = $io->exists_condition();
        $io->set_editable_condition(true);
        $io->set_exists_condition(true);
        $ov = $this->pt->prow->force_option($io);
        $io->print_web_edit($this->pt, $ov, $ov);
        $io->set_editable_condition($ei);
        $io->set_exists_condition($xi);
        echo '</div>';
    }

    private function print_one_option(SettingValues $sv, $ctr) {
        $this->ctr = $ctr;
        $fid = $sv->reqstr("sf__{$ctr}__id");
        $this->io = is_numeric($fid) ? $sv->conf->option_by_id(intval($fid)) : null;

        echo '<div id="sf__', $ctr, '" class="settings-sf ',
            $this->io ? '' : 'is-new ',
            'has-fold fold2o ui-unfold js-unfold-focus hidden">';

        if ($this->io) {
            $this->print_one_option_view($this->io, $ctr);
        }

        echo '<div id="sf__', $ctr, '__edit" class="settings-sf-edit fx2">',
            Ht::hidden("sf__{$ctr}__id", $this->io ? $this->io->id : "\$", ["class" => "settings-sf-id", "data-default-value" => $this->io ? $this->io->id : ""]),
            Ht::hidden("sf__{$ctr}__order", $ctr, ["class" => "settings-sf-order", "data-default-value" => $this->io ? $this->io->order : ""]);
        $sv->print_group("submissionfield/properties");
        echo '</div>';

        $this->print_js_trigger($ctr);
        echo '</div>';
    }

    private function print_js_trigger($ctr) {
        if ($this->_last_ctr !== null && $this->_last_ctr !== "\$") {
            echo Ht::unstash_script('$("#sf__' . $this->_last_ctr . '").trigger("hotcrpsettingssf")');
        }
        $this->_last_ctr = $ctr;
    }

    function print(SettingValues $sv) {
        echo "<hr class=\"g\">\n",
            Ht::hidden("has_options", 1),
            Ht::hidden("options_version", (int) $sv->conf->setting("options")),
            "\n\n";
        Icons::stash_defs("movearrow0", "movearrow2", "trash");
        Ht::stash_html('<div id="settings-sf-caption-name" class="hidden"><p>Field names should be short and memorable (they are used as search keywords).</p></div>', 'settings-sf-caption-name');
        Ht::stash_html('<div id="settings-sf-caption-choices" class="hidden"><p>Enter choices one per line.</p></div>', 'settings-sf-caption-choices');
        echo Ht::unstash();

        if ($sv->enumerate("sf__")) {
            echo '<div class="feedback is-note mb-4">Click on a field to edit it.</div>';
        }
        echo '<div id="settings-sform" class="c">'; // must ONLY contain fields
        foreach ($sv->enumerate("sf__") as $ctr) {
            $this->print_one_option($sv, $ctr);
        }
        echo "</div>";
        $this->print_js_trigger(null);

        // render sample options
        echo '<template id="settings-sf-samples" class="hidden">';
        $jtypes = $sv->conf->option_type_map();
        uasort($jtypes, "Conf::xt_order_compare");

        $otypes = [];
        foreach ($jtypes as $uf) {
            if (!isset($uf->display_if)
                || $sv->conf->xt_check($uf->display_if, $uf, $sv->user)) {
                $args = [
                    "id" => 1000,
                    "name" => $uf->title . " example",
                    "type" => $uf->name,
                    "order" => 1,
                    "display" => "prominent",
                    "json_key" => "__demo_{$uf->name}__"
                ];
                if ($uf->sample ?? null) {
                    $args = array_merge((array) $uf->sample, $args);
                }
                $o = PaperOption::make($sv->conf, (object) $args);
                $ov = $o->parse_json($this->pt->prow, $args["value"] ?? null)
                    ?? PaperValue::make($this->pt->prow, $o);
                echo '<div data-name="', htmlspecialchars($uf->name), '" data-title="', htmlspecialchars($uf->title), '">';
                $o->print_web_edit($this->pt, $ov, $ov);
                echo '</div>';
            }
        }
        echo '</template>';

        // render new options
        echo '<template id="settings-sf-new" class="hidden">';
        $this->print_one_option($sv, '$');
        echo '</template>';

        echo '<div class="mt-5">',
            Ht::button("Add submission field", ["class" => "ui js-settings-sf-add"]),
            "</div>\n";
    }

}

class Options_SettingParser extends SettingParser {
    /** @var ?associative-array<int,true> */
    private $_known_optionids;
    /** @var ?int */
    private $_next_optionid;
    /** @var list<int> */
    private $_delete_optionids = [];
    /** @var list<array{string,PaperOption}> */
    private $_conversions = [];
    /** @var array<int,PaperOption> */
    private $_new_options = [];
    /** @var list<array{int,string}> */
    private $_choice_renumberings = [];
    /** @var array<int,int> */
    public $option_id_to_ctr = [];

    /** @param PaperOption $f
     * @return object */
    static function unparse_json($f) {
        $exists_if = $f->exists_condition();
        $presence = $exists_if ? "custom" : ($f->final ? "final" : "all");
        $j = (object) [
            "id" => $f->id,
            "type" => $f->type,
            "name" => $f->name,
            "description" => $f->configured_description(),
            "display" => $f->display_name(),
            "order" => $f->order,
            "visibility" => $f->unparse_visibility(),
            "required" => $f->required,
            "exists_if" => $exists_if,
            "presence" => $presence,
            "selector" => ""
        ];
        if ($f instanceof Selector_PaperOption
            && ($choices = $f->selector_options())) {
            $j->selector = join("\n", $choices) . "\n";
        } else if ($f->type === "text"
                   && $f instanceof Text_PaperOption
                   && $f->display_space > 3) {
            $j->type = "mtext";
        }
        return $j;
    }

    /** @return PaperOption */
    static function make_placeholder_option(SettingValues $sv) {
        return PaperOption::make($sv->conf, (object) [
            "id" => DTYPE_INVALID,
            "name" => "",
            "description" => "",
            "type" => "none",
            "order" => 1000,
            "display" => "prominent"
        ]);
    }

    function set_oldv(SettingValues $sv, Si $si) {
        if ($si->name === "options") {
            return;
        }
        assert($si->part0 === "sf__");
        if ($si->part2 === "") {
            $fid = intval($sv->vstr("{$si->name}__id"));
            if ($fid <= 0 || !($f = $sv->conf->option_by_id($fid))) {
                $f = self::make_placeholder_option($sv);
            }
            $sv->set_oldv($si->name, self::unparse_json($f));
        }
    }

    /** @return array<int,PaperOption> */
    static function configurable_options(Conf $conf) {
        $opts = array_filter($conf->options()->normal(), function ($opt) {
            return $opt->configurable;
        });
        uasort($opts, "Conf::xt_order_compare");
        return $opts;
    }

    function prepare_enumeration(SettingValues $sv, Si $si) {
        $sv->map_enumeration("sf__", self::configurable_options($sv->conf));
    }

    /** @return bool */
    private function _apply_req_name(SettingValues $sv, Si $si) {
        if (($n = $sv->base_parse_req($si)) !== null) {
            if (preg_match('/\A(?:paper|submission|final|none|any|all|true|false|opt(?:ion)?[-:_ ]?\d+)\z/i', $n)) {
                $sv->error_at($si, "<0>Field name ‘{$n}’ is reserved");
                $sv->inform_at($si, "<0>Please pick another name.");
            }
            $sv->save($si, $n);
        }
        $sv->error_if_duplicate_member($si->part0, $si->part1, $si->part2, "Field name");
        return true;
    }

    /** @return bool */
    private function _apply_req_type(SettingValues $sv, Si $si) {
        if (($nj = $sv->conf->option_type($sv->reqstr($si->name)))) {
            $of = $sv->oldv($si->part0 . $si->part1);
            if ($nj->name !== $of->type && $of->type !== "none") {
                $conversion = $nj->convert_from_functions->{$of->type} ?? null;
                if (!$conversion) {
                    $oj = $sv->conf->option_type($of->type);
                    $sv->error_at($si, "<0>Cannot convert " . ($oj ? $oj->title : $of->type) . " fields to {$nj->title} type");
                } else if ($conversion !== true) {
                    $this->_conversions[] = [$conversion, $sv->conf->option_by_id($of->id)];
                }
            }
            $sv->save($si, $nj->name);
        } else {
            $sv->error_at($si, "<0>Field type not found");
        }
        return true;
    }

    /** @return bool */
    private function _apply_req_choices(SettingValues $sv, Si $si) {
        $selector = [];
        $cleanreq = cleannl($sv->reqstr($si->name));
        foreach (explode("\n", $cleanreq) as $t) {
            if ($t !== "")
                $selector[] = simplify_whitespace($t);
        }
        if (($jtype = $sv->conf->option_type($sv->vstr("sf__{$si->part1}__type")))
            && ($jtype->has_selector ?? false)) {
            if (empty($selector)) {
                $sv->error_at($si, "<0>Entry required (one choice per line)");
            } else {
                $collator = $sv->conf->collator();
                for ($i = 0; $i !== count($selector); ++$i) {
                    for ($j = $i + 1; $j !== count($selector); ++$j) {
                        if ($collator->compare($selector[$i], $selector[$j]) === 0) {
                            $sv->error_at($si, "<0>Choice ‘{$selector[$i]}’ is duplicated");
                        }
                    }
                }
                if (($of = $sv->oldv($si->part0 . $si->part1))
                    && $of->type !== "none"
                    && $of->selector !== $cleanreq) {
                    $this->_check_choices_renumbering($sv, $si, $selector, $of);
                }
            }
        }
        $sv->save($si, $selector);
        return true;
    }

    private function _check_choices_renumbering(SettingValues $sv, Si $si, $selector, $of) {
        $sqlmap = [];
        foreach ($sv->unambiguous_renumbering(explode("\n", trim($of->selector)), $selector) as $i => $j) {
            $sqlmap[] = "when " . ($i+1) . " then " . ($j+1);
        }
        if (count($sqlmap)) {
            $this->_choice_renumberings[] = [$of->id, "case value " . join(" ", $sqlmap) . " else value end"];
        }
    }

    /** @param object $sfj */
    private function _assign_new_id(Conf $conf, $sfj) {
        if (!$this->_next_optionid) {
            $this->_known_optionids = [];
            $result = $conf->qe("select distinct optionId from PaperOption where optionId>0 union select distinct documentType from PaperStorage where documentType>0");
            while (($row = $result->fetch_row())) {
                $this->_known_optionids[(int) $row[0]] = true;
            }
            Dbl::free($result);
            foreach ($conf->options()->universal() as $o) {
                $this->_known_optionids[$o->id] = true;
            }
            $this->_next_optionid = 1;
        }
        while (isset($this->_known_optionids[$this->_next_optionid])) {
            ++$this->_next_optionid;
        }
        $sfj->id = $this->_next_optionid;
        ++$this->_next_optionid;
    }

    /** @param object $sfj */
    private function _fix_req_condition(SettingValues $sv, $sfj) {
        $sfj->final = $sfj->presence === "final";
        if ($sfj->presence !== "custom"
            || trim($sfj->exists_if ?? "") === "") {
            unset($sfj->exists_if);
        }
    }

    /** @return bool */
    private function _apply_req_options(SettingValues $sv, Si $si) {
        if ($sv->has_req("options_version")
            && (int) $sv->reqstr("options_version") !== (int) $sv->conf->setting("options")) {
            $sv->error_at("options", "<0>You modified options settings in another tab. Please reload.");
        }
        $nsfj = [];
        foreach ($sv->enumerate("sf__") as $ctr) {
            $sfj = $sv->parse_members("sf__{$ctr}");
            if ($sv->reqstr("sf__{$ctr}__delete")) {
                if ($sfj->id !== DTYPE_INVALID) {
                    $this->_delete_optionids[] = $sfj->id;
                }
            } else {
                if ($sfj->id === DTYPE_INVALID) {
                    $sv->error_if_missing("sf__{$ctr}__name");
                    $this->_assign_new_id($sv->conf, $sfj);
                }
                $this->_fix_req_condition($sv, $sfj);
                $this->_new_options[$sfj->id] = $opt = PaperOption::make($sv->conf, $sfj);
                $nsfj[] = $opt->jsonSerialize();
                $this->option_id_to_ctr[$opt->id] = $ctr;
            }
        }
        usort($nsfj, "Conf::xt_order_compare");
        if ($sv->update("options", empty($nsfj) ? "" : json_encode_db($nsfj))) {
            $this->_validate_consistency($sv);
            $sv->update("options_version", (int) $sv->conf->setting("options") + 1);
            $sv->request_store_value($si);
            $sv->mark_invalidate_caches(["options" => true]);
            if (!empty($this->_delete_optionids)
                || !empty($this->_conversions)
                || !empty($this->_choice_renumberings)) {
                $sv->request_write_lock("PaperOption");
            }
        }
        return true;
    }

    private function _validate_consistency(SettingValues $sv) {
        $old_oval = $sv->conf->setting("options");
        $old_options = $sv->conf->setting_data("options");
        if (($new_options = $sv->savedv("options") ?? "") === "") {
            $sv->conf->change_setting("options", null);
        } else {
            $sv->conf->change_setting("options", $old_oval + 1, $new_options);
        }
        $sv->conf->refresh_settings();

        foreach ($sv->cs()->members("__validate/submissionfields", "validate_function") as $gj) {
            $sv->cs()->call_function($gj, $gj->validate_function, $gj);
        }

        $sv->conf->change_setting("options", $old_oval, $old_options);
        $sv->conf->refresh_settings();
    }

    function apply_req(SettingValues $sv, Si $si) {
        if ($si->name === "options") {
            return $this->_apply_req_options($sv, $si);
        } else if ($si->part2 === "__name") {
            return $this->_apply_req_name($sv, $si);
        } else if ($si->part2 === "__type") {
            return $this->_apply_req_type($sv, $si);
        } else if ($si->part2 === "__choices") {
            return $this->_apply_req_choices($sv, $si);
        } else {
            return false;
        }
    }

    function store_value(SettingValues $sv, Si $si) {
        if (!empty($this->_delete_optionids)) {
            $sv->conf->qe("delete from PaperOption where optionId?a", $this->_delete_optionids);
        }
        foreach ($this->_conversions as $conv) {
            call_user_func($conv[0], $this->_new_options[$conv[1]->id], $conv[1]);
        }
        foreach ($this->_choice_renumberings as $idcase) {
            $sv->conf->qe("update PaperOption set value={$idcase[1]} where optionId={$idcase[0]}");
        }
        $sv->mark_invalidate_caches(["autosearch" => true]);
    }


    static function crosscheck(SettingValues $sv) {
        $conf = $sv->conf;
        if (($sv->has_interest("options") || $sv->has_interest("sub_blind"))
            && $sv->conf->setting("sub_blind") == Conf::BLIND_ALWAYS) {
            $opts = Options_SettingParser::configurable_options($sv->conf);
            foreach (array_values($opts) as $ctrz => $f) {
                if ($f->visibility() === PaperOption::VIS_AUTHOR) {
                    $visname = "sf__" . ($ctrz + 1) . "__visibility";
                    $sv->warning_at($visname, "<5>" . $sv->setting_link("All submissions are blind", "sub_blind") . ", so this field is always hidden");
                    $sv->inform_at($visname, "<0>Would “hidden until review” visibility be better?");
                }
            }
        }
    }
}
