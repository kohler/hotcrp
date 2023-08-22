<?php
// settings/s_options.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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

    /** @var int */
    static private $sample_index = 1;

    /** @param SettingValues|XtParams $sv */
    function __construct($sv) {
        $this->conf = $sv->conf;
        $this->pt = new PaperTable($sv->user, new Qrequest("GET"), PaperInfo::make_new($sv->user, null));
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
        echo '<div class="', $sv->control_class("sf/{$this->ctr}/name", "entryi mb-3"), '" data-property="name">',
            '<div class="entry">',
            $sv->feedback_at("sf/{$this->ctr}/name");
        $sv->print_entry("sf/{$this->ctr}/name", [
            "class" => "need-tooltip font-weight-bold want-focus want-delete-marker",
            "aria-label" => "Field name",
            "data-tooltip-info" => "settings-sf",
            "data-tooltip-type" => "focus"
        ]);
        echo '</div></div>';
    }

    function print_type(SettingValues $sv) {
        $curt = $sv->vstr("sf/{$this->ctr}/type");
        $sv->print_select_group("sf/{$this->ctr}/type", "Type", [$curt => $curt], [
            "horizontal" => true,
            "group_attr" => ["data-property" => "type"]
        ]);
    }

    static private function make_array(...$x) {
        // This works around a syntax error in PHP 7.0/7.1
        return $x;
    }

    function print_values(SettingValues $sv) {
        $sv->print_textarea_group("sf/{$this->ctr}/values_text", "Choices", [
            "horizontal" => true,
            "class" => "w-entry-text need-tooltip",
            "data-tooltip-info" => "settings-sf",
            "data-tooltip-type" => "focus",
            "group_attr" => ["data-property" => "values"],
            "group_class" => "property-optional",
            "feedback_items" => self::make_array(
                ...$sv->message_list_at("sf/{$this->ctr}/values_text"),
                ...$sv->message_list_at("sf/{$this->ctr}/values"),
                ...$sv->message_list_at_prefix("sf/{$this->ctr}/values/")
            )
        ]);
    }

    function print_description(SettingValues $sv) {
        $sv->print_textarea_group("sf/{$this->ctr}/description", "Description", [
            "horizontal" => true,
            "class" => "w-entry-text settings-sf-description need-tooltip",
            "data-tooltip-info" => "settings-sf",
            "data-tooltip-type" => "focus",
            "group_attr" => ["data-property" => "description"]
        ]);
    }

    function print_presence(SettingValues $sv) {
        $sv->print_select_group("sf/{$this->ctr}/presence", "Present on", [
            "all" => "All submissions", "final" => "Final versions only"
        ], [
            "horizontal" => true,
            "group_attr" => ["data-property" => "presence"]
        ]);
    }

    function print_required(SettingValues $sv) {
        $sv->print_select_group("sf/{$this->ctr}/required", "Required", [
            "0" => "No", "1" => "At registration", "2" => "At submission"
        ], [
            "horizontal" => true,
            "group_class" => "has-fold foldc",
            "group_attr" => [
                "data-property" => "required",
                "data-fold-values" => "1 2"
            ],
            "group_open" => true,
            "class" => "uich js-foldup"
        ]);
        echo '<ul class="fx mt-1 feedback-list if-property" data-property="checkbox"><li>',
            join("", MessageSet::feedback_html_items([
                MessageItem::marked_note("Submitters will be required to check this field to complete their submissions.")
            ])), '</li></ul>';
        $sv->print_close_control_group(["horizontal" => true]);
    }

    function print_visibility(SettingValues $sv) {
        $options = [
            "all" => "Visible to reviewers",
            "nonblind" => "Hidden on anonymous submissions",
            "conflict" => "Hidden until conflicts are visible",
            "review" => "Hidden until review",
            "admin" => "Hidden from reviewers"
        ];
        if ($sv->oldv("sf/{$this->ctr}/visibility") !== "conflict") {
            unset($options["conflict"]);
        }
        $sv->print_select_group("sf/{$this->ctr}/visibility", "Visibility", $options, [
            "horizontal" => true,
            "group_attr" => ["data-property" => "visibility"],
            "group_open" => true,
            "class" => "settings-sf-visibility",
            "fold_values" => ["review"]
        ]);
        echo '<div class="hint fx">The field will be visible to reviewers who have submitted a review, and to PC members who can see all reviews.</div>',
            '</div></div>';
    }

    function print_display(SettingValues $sv) {
        $sv->print_select_group("sf/{$this->ctr}/display", "Display", [
            "prominent" => "Normal", "topics" => "Grouped with topics", "submission" => "Near submission"
        ], [
            "horizontal" => true,
            "group_attr" => ["data-property" => "display"],
            "class" => "settings-sf-display"
        ]);
    }

    function print_actions(SettingValues $sv) {
        echo '<div class="entryi mb-0" data-property="actions"><label></label><div class="btnp entry">',
            Ht::hidden("sf/{$this->ctr}/id", $this->io ? $this->io->id : "new", ["class" => "settings-sf-id", "data-default-value" => $this->io ? $this->io->id : ""]),
            Ht::hidden("sf/{$this->ctr}/order", $sv->newv("sf/{$this->ctr}/order"), ["class" => "is-order", "data-default-value" => $this->io ? $sv->oldv("sf/{$this->ctr}/order") : ""]),
            '<span class="btnbox">',
            Ht::button(Icons::ui_use("movearrow0"), ["class" => "btn-licon ui js-settings-sf-move moveup need-tooltip", "aria-label" => "Move up in display order"]),
            Ht::button(Icons::ui_use("movearrow2"), ["class" => "btn-licon ui js-settings-sf-move movedown need-tooltip", "aria-label" => "Move down in display order"]),
            '</span>',
            Ht::button(Icons::ui_use("trash"), ["class" => "btn-licon ui js-settings-sf-move delete need-tooltip", "aria-label" => "Delete", "data-exists-count" => $this->paper_count($this->io)]),
            "</div></div>";
    }


    private function print_one_option_view(PaperOption $io, SettingValues $sv, $ctr) {
        echo '<div id="sf/', $ctr, '/view" class="settings-sf-view fn2 ui js-foldup">';
        if ($io->exists_condition()) {
            $this->pt->msg_at($io->formid, "<0>Present on submissions matching ‘" . $io->exists_condition() . "’", MessageSet::WARNING_NOTE);
        }
        if ($io->final) {
            $this->pt->msg_at($io->formid, "<0>Present on final versions", MessageSet::WARNING_NOTE);
        }
        if ($io->editable_condition()) {
            $this->pt->msg_at($io->formid, "<0>Editable on submissions matching ‘" . $io->editable_condition() . "’", MessageSet::WARNING_NOTE);
        }
        foreach ($sv->message_list_at_prefix("sf/{$ctr}/") as $mi) {
            $this->pt->msg_at($io->formid, $mi->message, $mi->status > 0 ? MessageSet::WARNING_NOTE : $mi->status);
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
        $fid = $sv->reqstr("sf/{$ctr}/id");
        $this->io = is_numeric($fid) ? $sv->conf->option_by_id(intval($fid)) : null;

        echo '<div id="sf/', $ctr, '" class="settings-sf ',
            $this->io ? '' : 'is-new ',
            'has-fold fold2o ui-fold js-fold-focus hidden">',
            '<div class="settings-draghandle ui-drag js-settings-drag" draggable="true" title="Drag to reorder fields">',
            Icons::ui_move_handle_horizontal(),
            '</div>';

        if ($this->io) {
            $this->print_one_option_view($this->io, $sv, $ctr);
        }

        echo '<div id="sf/', $ctr, '/edit" class="settings-sf-edit fx2">';
        $sv->print_group("submissionfield/properties");
        echo '</div>';

        $this->print_js_trigger($ctr);
        echo '</div>';
    }

    private function print_js_trigger($ctr) {
        if ($this->_last_ctr !== null && $this->_last_ctr !== "\$") {
            echo Ht::unstash_script('$("#sf\\\\/' . $this->_last_ctr . '").trigger("hotcrpsettingssf")');
        }
        $this->_last_ctr = $ctr;
    }

    function sample_sf_view_html($sampj) {
        $sampj = clone $sampj;
        $sampj->id = 1000;
        $sampj->name = $sampj->name ?? "Field name";
        $sampj->order = $sampj->order ?? 1;
        $sampj->display = $sampj->display ?? "prominent";
        $sampj->json_key = "__sample_" . self::$sample_index . "__";
        ++self::$sample_index;
        $o = PaperOption::make($this->pt->conf, $sampj);
        $ov = $o->parse_json($this->pt->prow, $sampj->value ?? null)
            ?? PaperValue::make($this->pt->prow, $o);
        ob_start();
        $o->print_web_edit($this->pt, $ov, $ov);
        return ob_get_clean();
    }

    function print(SettingValues $sv) {
        echo "<hr class=\"g\">\n",
            Ht::hidden("has_sf", 1),
            Ht::hidden("options_version", (int) $sv->conf->setting("options")),
            "\n\n";
        Icons::stash_defs("movearrow0", "movearrow2", "trash");
        Ht::stash_html('<div id="settings-sf-caption-name" class="hidden"><p>Field names should be short and memorable (they are used as search keywords).</p></div>', 'settings-sf-caption-name');
        Ht::stash_html('<div id="settings-sf-caption-values" class="hidden"><p>Enter choices one per line.</p></div>', 'settings-sf-caption-values');
        Ht::stash_html('<div id="settings-sf-caption-description" class="hidden"><p>Enter an HTML description for the submission form.</p></div>', 'settings-sf-caption-description');
        echo Ht::unstash();

        if ($sv->oblist_keys("sf")) {
            echo '<div class="feedback is-note mb-4">Click on a field to edit it.</div>';
        }

        // initialize JS for samples and option types
        Ht::stash_script("hotcrp.settings.submission_form(" . json_encode_browser([
            "types" => ReviewForm_SettingParser::make_types_json($sv->conf->option_type_map())
        ]) . ")");

        echo '<div id="settings-sform" class="c">';
        // NB: div#settings-sform must ONLY contain fields
        foreach ($sv->oblist_keys("sf") as $ctr) {
            $this->print_one_option($sv, $ctr);
        }
        echo "</div>";
        $this->print_js_trigger(null);

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
    /** @var Conf
     * @readonly */
    public $conf;
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
    /** @var array<int,array<int,int>> */
    private $_value_renumberings = [];
    /** @var array<int,int> */
    public $option_id_to_ctr = [];

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

    function __construct(SettingValues $sv) {
        $this->conf = $sv->conf;
    }

    function values(Si $si, SettingValues $sv) {
        if ($si->name_matches("sf/", "*", "/type")) {
            $xtp = new XtParams($sv->conf, $sv->user);
            $ot = [];
            foreach ($sv->conf->option_type_map() as $uf) {
                if ($xtp->check($uf->display_if ?? null, $uf))
                    $ot[] = $uf->name;
            }
            return $ot;
        } else {
            return null;
        }
    }

    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name_matches("sf/", "*")) {
            $opt = self::make_placeholder_option($sv);
            $sfj = $opt->export_setting();
            $sfj->existed = false;
            $sv->set_oldv($si, $sfj);
        } else if ($si->name_matches("sf/", "*", "/title")) {
            $n = $sv->vstr("sf/{$si->name1}/name");
            $sv->set_oldv($si, $n === "" ? "[Submission field]" : $n);
        } else if ($si->name_matches("sf/", "*", "/values_text")) {
            $sfs = $sv->oldv("sf/{$si->name1}");
            $vs = [];
            foreach ($sfs->xvalues ?? [] as $sfv) {
                $vs[] = $sfv->name;
            }
            $sv->set_oldv($si, empty($vs) ? "" : join("\n", $vs) . "\n");
        } else if ($si->name_matches("sf/", "*", "/values/", "*")) {
            $sv->set_oldv($si, new SfValue_Setting);
        }
    }

    /** @return list<PaperOption> */
    static function configurable_options(Conf $conf) {
        $opts = [];
        foreach ($conf->options()->form_fields() as $opt) {
            if ($opt->configurable)
                $opts[] = $opt;
        }
        return $opts;
    }

    function prepare_oblist(Si $si, SettingValues $sv) {
        if ($si->name === "sf") {
            $sfss = [];
            foreach (self::configurable_options($sv->conf) as $f) {
                $sfss[] = $f->export_setting();
            }
            $sv->append_oblist("sf", $sfss, "name");
        } else if ($si->name2 === "/values") {
            $sfs = $sv->oldv("sf/{$si->name1}");
            $sv->append_oblist($si->name, $sfs->xvalues ?? [], "name");
        }
    }

    /** @return bool */
    private function _apply_req_name(Si $si, SettingValues $sv) {
        $n = $sv->base_parse_req($si);
        if ($n === "") {
            $tname = $sv->vstr("sf/{$si->name1}/type");
            $t = $sv->conf->option_type($tname ?? "");
            if ($t && ($t->require_name ?? true)) {
                $sv->error_at($si, "<0>Entry required");
            }
        } else {
            if (preg_match('/\A(?:paper|submission|final|none|any|all|true|false|opt(?:ion)?[-:_ ]?\d+)\z/i', $n)) {
                $sv->error_at($si, "<0>Field name ‘{$n}’ is reserved");
                $sv->inform_at($si, "<0>Please pick another name.");
            }
        }
        $sv->save($si, $n);
        if ($n !== "") {
            $sv->error_if_duplicate_member($si->name0, $si->name1, $si->name2, "Field name");
        }
        return true;
    }

    /** @return bool */
    private function _apply_req_type(Si $si, SettingValues $sv) {
        if (($nj = $sv->conf->option_type($sv->reqstr($si->name)))) {
            $of = $sv->oldv($si->name0 . $si->name1);
            if ($nj->name !== $of->type && $of->type !== "none") {
                $conversion = $nj->convert_from_functions->{$of->type} ?? null;
                if (!$conversion) {
                    $oj = $sv->conf->option_type($of->type);
                    $sv->error_at($si, "<0>Cannot convert " . ($oj ? $oj->title : $of->type) . " field to {$nj->title}");
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
    private function _apply_req_values_text(Si $si, SettingValues $sv) {
        $cleanreq = cleannl($sv->reqstr($si->name));
        $i = 1;
        $vpfx = "sf/{$si->name1}/values";
        foreach (explode("\n", $cleanreq) as $t) {
            if ($t !== "" && ($t = simplify_whitespace($t)) !== "") {
                $sv->set_req("{$vpfx}/{$i}/name", $t);
                $sv->set_req("{$vpfx}/{$i}/order", (string) $i);
                ++$i;
            }
        }
        if (!$sv->has_req($vpfx)) {
            $sv->set_req("sf/{$si->name1}/values_reset", "1");
            $this->_apply_req_values($sv->si($vpfx), $sv);
        }
        return true;
    }

    /** @return bool */
    private function _apply_req_values(Si $si, SettingValues $sv) {
        $jtype = $sv->conf->option_type($sv->vstr("sf/{$si->name1}/type"));
        if (!$jtype || !($jtype->has_values ?? false)) {
            return true;
        }
        $fpfx = "sf/{$si->name1}";
        $vpfx = "sf/{$si->name1}/values";

        // check values
        $newsfv = [];
        $error = false;
        foreach ($sv->oblist_nondeleted_keys($vpfx) as $ctr) {
            $sfv = $sv->newv("{$vpfx}/{$ctr}");
            if ($sfv->name !== "") {
                $newsfv[] = $sfv;
                if ($sv->error_if_duplicate_member($vpfx, $ctr, "name", "Field value")) {
                    $error = true;
                }
            }
        }
        if (empty($newsfv)) {
            $sv->error_at($si, "<0>Entry required");
        }
        if ($error || empty($newsfv)) {
            return;
        }

        // mark deleted values, account for known ids
        $renumberings = $known_ids = [];
        foreach ($sv->oblist_keys($vpfx) as $ctr) {
            if (($sfv = $sv->oldv("{$vpfx}/{$ctr}"))
                && $sfv->old_value !== null) {
                $known_ids[] = $sfv->id;
                if ($sv->reqstr("{$vpfx}/{$ctr}/delete")) {
                    $renumberings[$sfv->old_value] = 0;
                }
            }
        }

        // assign ids to new values
        $values = $ids = [];
        foreach ($newsfv as $idx => $sfv) {
            $values[] = $sfv->name;
            $want_value = $idx + 1;
            if ($sfv->old_value !== null) {
                $id = $sfv->id;
                if ($sfv->old_value !== $want_value) {
                    $renumberings[$sfv->old_value] = $want_value;
                }
            } else if (!in_array($want_value, $known_ids)) {
                $id = $want_value;
                $known_ids[] = $id;
            } else {
                for ($id = 1; in_array($id, $known_ids); ++$id) {
                }
                $known_ids[] = $id;
            }
            $ids[] = $id;
        }

        // record renumberings
        if (!empty($renumberings)
            && ($of = $sv->oldv($fpfx))
            && $of->type !== "none") {
            $this->_value_renumberings[$of->id] = $renumberings;
        }

        // save values
        $sv->save("{$fpfx}/values_storage", $values);
        $sv->save("{$fpfx}/ids", $ids);
        return true;
    }

    /** @param Sf_Setting $sfj */
    private function _apply_new_id(SettingValues $sv, $ctr, $sfj) {
        $idstr = $sv->reqstr("sf/{$ctr}/id") ?? "new";
        if ($idstr !== "" && $idstr !== "new") {
            if (($idnum = stoi($idstr)) !== null
                && $idnum > 0
                && !$this->conf->option_by_id($idnum)) {
                $sfj->id = $idnum;
                return;
            } else {
                $sv->error_at("sf/{$ctr}/id", "<0>This option is not configurable");
            }
        }
        if ($sfj->deleted) {
            return;
        }
        $sv->error_if_missing("sf/{$ctr}/name");
        if (!$this->_next_optionid) {
            $this->_known_optionids = [];
            $result = $this->conf->qe("select distinct optionId from PaperOption where optionId>0 union select distinct documentType from PaperStorage where documentType>0");
            while (($row = $result->fetch_row())) {
                $this->_known_optionids[(int) $row[0]] = true;
            }
            Dbl::free($result);
            foreach ($this->conf->options()->universal() as $o) {
                $this->_known_optionids[$o->id] = true;
            }
            foreach ($sv->oblist_keys("sf") as $ctr) {
                $idstr = $sv->reqstr("sf/{$ctr}/id");
                if (($idnum = stoi($idstr)) !== null) {
                    $this->_known_optionids[$idnum] = true;
                }
            }
            $this->_next_optionid = 1;
        }
        while (isset($this->_known_optionids[$this->_next_optionid])) {
            ++$this->_next_optionid;
        }
        $sfj->id = $this->_next_optionid;
        ++$this->_next_optionid;
    }

    /** @return bool */
    private function _apply_req_sf(Si $si, SettingValues $sv) {
        if ($sv->has_req("options_version")
            && (int) $sv->reqstr("options_version") !== (int) $sv->conf->setting("options")) {
            $sv->error_at("sf", "<0>You modified submission field settings in another tab. Please reload.");
        }
        $nsfj = [];
        foreach ($sv->oblist_keys("sf") as $ctr) {
            $sfj = $sv->newv("sf/{$ctr}");
            if (!$sfj->existed) {
                assert($sfj->id === DTYPE_INVALID);
                $this->_apply_new_id($sv, $ctr, $sfj);
            }
            if ($sfj->deleted) {
                if ($sfj->existed) {
                    $this->_delete_optionids[] = $sfj->id;
                }
                continue;
            }
            $this->_new_options[$sfj->id] = $opt = PaperOption::make($sv->conf, $sfj);
            $nsfj[] = $opt->jsonSerialize();
            $this->option_id_to_ctr[$opt->id] = $ctr;
        }
        usort($nsfj, "Conf::xt_order_compare");
        if ($sv->update("options", empty($nsfj) ? "" : json_encode_db($nsfj))) {
            $this->_validate_consistency($sv);
            $sv->update("options_version", (int) $sv->conf->setting("options") + 1);
            $sv->request_store_value($si);
            $sv->mark_invalidate_caches(["options" => true]);
            if (!empty($this->_delete_optionids)
                || !empty($this->_conversions)
                || !empty($this->_value_renumberings)) {
                $sv->request_write_lock("PaperOption");
            }
        }
        return true;
    }

    private function _validate_consistency(SettingValues $sv) {
        $old_oval = $sv->conf->setting("options");
        $old_options = $sv->conf->setting_data("options");
        if (($new_options = $sv->newv("options") ?? "") === "") {
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

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "sf") {
            return $this->_apply_req_sf($si, $sv);
        } else if ($si->name2 === "/name") {
            return $this->_apply_req_name($si, $sv);
        } else if ($si->name2 === "/type") {
            return $this->_apply_req_type($si, $sv);
        } else if ($si->name2 === "/values_text") {
            return $this->_apply_req_values_text($si, $sv);
        } else if ($si->name2 === "/values") {
            return $this->_apply_req_values($si, $sv);
        } else {
            return false;
        }
    }

    function store_value(Si $si, SettingValues $sv) {
        if (!empty($this->_delete_optionids)) {
            $sv->conf->qe("delete from PaperOption where optionId?a", $this->_delete_optionids);
        }
        foreach ($this->_conversions as $conv) {
            call_user_func($conv[0], $this->_new_options[$conv[1]->id], $conv[1]);
        }
        foreach ($this->_value_renumberings as $oid => $renumberings) {
            $delete = $modify = [];
            foreach ($renumberings as $old => $new) {
                if ($new === 0) {
                    $delete[] = $old;
                } else {
                    $modify[] = "when {$old} then {$new} ";
                }
            }
            if (!empty($delete)) {
                $sv->conf->qe("delete from PaperOption where optionId=? and value?a", $oid, $delete);
            }
            if (!empty($modify)) {
                $sv->conf->qe("update PaperOption set value=case value " . join("", $modify) . "else value end where optionId=?", $oid);
            }
        }
        $sv->mark_invalidate_caches(["autosearch" => true]);
    }


    static function crosscheck(SettingValues $sv) {
        if (($sv->has_interest("sf") || $sv->has_interest("author_visibility"))
            && $sv->oldv("author_visibility") == Conf::BLIND_ALWAYS) {
            $opts = Options_SettingParser::configurable_options($sv->conf);
            foreach ($opts as $ctrz => $f) {
                if ($f->visibility() === PaperOption::VIS_AUTHOR) {
                    $visname = "sf/" . ($ctrz + 1) . "/visibility";
                    $sv->warning_at($visname, "<5>" . $sv->setting_link("All submissions are anonymous", "author_visibility") . ", so this field is always hidden from reviewers");
                }
            }
        }
    }
}
