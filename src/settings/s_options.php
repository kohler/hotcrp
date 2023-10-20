<?php
// settings/s_options.php -- HotCRP settings > submission form page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Options_SettingParser extends SettingParser {
    /** @var Conf
     * @readonly */
    public $conf;

    // shared
    /** @var array<int,object> */
    private $_basic_ijmap;
    /** @var ?array<int,int> */
    private $_use_count;
    /** @var ?associative-array<int,true> */
    private $_known_optionids;
    /** @var ?int */
    private $_next_optionid;

    // for rendering
    /** @var PaperTable
     * @readonly */
    private $pt;
    /** @var int|string */
    public $ctr;
    /** @var Sf_Setting */
    public $sfs;
    /** @var object */
    public $typej;
    /** @var int|string */
    private $_last_ctr;

    // for parsing
    /** @var array<int,object> */
    private $_intermediate_ijmap;
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

    /** @var int */
    static private $sample_index = 1;

    const ID_PLACEHOLDER = 999999;


    /** @param SettingValues $sv */
    function __construct($sv) {
        $this->conf = $sv->conf;
        $this->pt = new PaperTable($sv->user, new Qrequest("GET"), PaperInfo::make_new($sv->user, null));
        $this->pt->settings_mode = true;
    }


    /** @return array<int,object> */
    function basic_intrinsic_json_map() {
        if ($this->_basic_ijmap === null) {
            $this->_basic_ijmap = PaperOptionList::make_intrinsic_json_map($this->conf, null, false);
        }
        return $this->_basic_ijmap;
    }

    /** @param int $id
     * @return object */
    function basic_intrinsic_json($id) {
        return ($this->basic_intrinsic_json_map())[$id] ?? null;
    }

    /** @param int $id
     * @param SettingValues $sv
     * @return PaperOption */
    function intermediate_intrinsic_option($id, $sv) {
        if ($this->_intermediate_ijmap === null) {
            $this->_intermediate_ijmap = PaperOptionList::make_intrinsic_json_map($this->conf, $sv->make_svconf(), false);
        }
        return PaperOption::make($this->conf, $this->_intermediate_ijmap[$id]);
    }


    /** @param int $id
     * @return int */
    function option_use_count($id) {
        if ($id <= 0 || $id === self::ID_PLACEHOLDER) {
            return 0;
        }
        if ($this->_use_count === null) {
            $this->_use_count = [];
            foreach ($this->conf->fetch_rows("select optionId, count(distinct paperId) from PaperOption group by optionId") as $row) {
                $this->_use_count[(int) $row[0]] = (int) $row[1];
            }
        }
        return $this->_use_count[$id] ?? 0;
    }


    /** @return int */
    function next_unused_option_id(SettingValues $sv) {
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
            $this->_known_optionids[self::ID_PLACEHOLDER] = true;
            $this->_next_optionid = 1;
        }
        while (isset($this->_known_optionids[$this->_next_optionid])) {
            ++$this->_next_optionid;
        }
        ++$this->_next_optionid;
        return $this->_next_optionid - 1;
    }


    /** @param object $sampj
     * @param bool $is_view
     * @return PaperOption */
    function make_sample_option($sampj, $is_view) {
        $args = clone $sampj;
        if ($is_view && isset($args->sample_view)) {
            foreach ((array) $args->sample_view as $k => $v) {
                $args->$k = $v;
            }
        }
        unset($args->sample_view);
        $args->id = self::ID_PLACEHOLDER;
        $args->name = $args->name ?? ($is_view ? "Field name" : "");
        $args->type = $args->type ?? "none";
        $args->order = $args->order ?? 1;
        $args->display = $args->display ?? "rest";
        $args->json_key = "__sample_" . self::$sample_index . "__";
        ++self::$sample_index;
        return PaperOption::make($this->conf, $args);
    }


    /** @return list<PaperOption> */
    static function configurable_options(Conf $conf) {
        $opts = [];
        foreach ($conf->options()->form_fields(null, true) as $opt) {
            if ($opt->configurable)
                $opts[] = $opt;
        }
        return $opts;
    }

    function set_oldv(Si $si, SettingValues $sv) {
        if ($si->name_matches("sf/", "*")) {
            $sj = null;
            if (($s = $sv->reqstr("sf/{$si->name1}/template"))) {
                $sj = json_decode($s);
            }
            if (!is_object($sj) && ($s = $sv->reqstr("sf/{$si->name1}/type"))) {
                $sj = (object) ["type" => $s];
            }
            if (!is_object($sj)) {
                $sj = (object) [];
            }
            $eopt = $this->make_sample_option($sj, false);
            $sfs = $eopt->export_setting();
            $sfs->existed = false;
            $sv->set_oldv($si, $sfs);
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

    function prepare_oblist(Si $si, SettingValues $sv) {
        if ($si->name === "sf") {
            $sfss = [];
            foreach (self::configurable_options($sv->conf) as $i => $opt) {
                $sfs = $opt->export_setting();
                $sfs->order = $i + 1;
                $sfss[] = $sfs;
            }
            $sv->append_oblist("sf", $sfss, "name");
        } else if ($si->name2 === "/values") {
            $sfs = $sv->oldv("sf/{$si->name1}");
            $sv->append_oblist($si->name, $sfs->xvalues ?? [], "name");
        }
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

    function member_list(Si $si, SettingValues $sv) {
        // JSON for intrinsic options only lists configurable properties
        if (!$si->name_matches("sf/", "*")) {
            return null;
        }
        $sfs = $sv->oldv($si);
        if ($sfs->option_id > 0) {
            return null;
        }
        $mlist = [$sv->si("sf/{$si->name1}/id")];
        if ($sfs->option_id !== PaperOption::TITLEID) {
            $mlist[] = $sv->si("sf/{$si->name1}/order");
        }
        $ij = $this->basic_intrinsic_json($sfs->option_id);
        foreach ($ij->properties as $prop) {
            if (($msi = $sv->conf->si("sf/{$si->name1}/{$prop}")))
                $mlist[] = $msi;
        }
        return $mlist;
    }


    function print_name(SettingValues $sv) {
        echo '<div class="', $sv->control_class("sf/{$this->ctr}/name", "entryi mb-3"), '">',
            '<div class="entry">',
            $sv->feedback_at("sf/{$this->ctr}/name");
        $sv->print_entry("sf/{$this->ctr}/name", [
            "class" => "need-tooltip font-weight-bold want-focus want-delete-marker",
            "aria-label" => "Field name",
            "data-tooltip-info" => "settings-sf",
            "data-tooltip-type" => "focus",
            "placeholder" => $this->typej->placeholders->name ?? null
        ]);
        echo '</div></div>';
    }

    function print_type(SettingValues $sv) {
        if ($this->sfs->option_id <= 0) {
            $ij = $this->basic_intrinsic_json($this->sfs->option_id);
            $content = "Intrinsic field (" . htmlspecialchars($ij->name) . ")";
        } else {
            $types = $this->conf->option_type_map();
            $curt = $types[$this->sfs->type];
            $conversions = (array) ($curt->convert_from_functions ?? []);
            if (empty($conversions)) {
                $content = htmlspecialchars($curt->title) . Ht::hidden("sf/{$this->ctr}/type", $curt->name);
            } else {
                $sel = [$curt->name => $curt->title];
                foreach ($conversions as $k => $v) {
                    $sel[$k] = $types[$k]->title;
                }
                $content = $sv->select("sf/{$this->ctr}/type", $sel);
            }
        }
        $sv->print_control_group("sf/{$this->ctr}/type", "Type", $content, ["horizontal" => true]);
    }

    function print_values(SettingValues $sv) {
        $sv->print_textarea_group("sf/{$this->ctr}/values_text", "Choices", [
            "horizontal" => true,
            "class" => "w-entry-text need-tooltip",
            "data-tooltip-info" => "settings-sf",
            "data-tooltip-type" => "focus",
            "feedback_items" => make_array(
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
            "data-tooltip-type" => "focus"
        ]);
    }

    function print_required(SettingValues $sv) {
        $klass = null;
        if ($this->sfs->type === "checkbox") {
            $klass = "uich js-settings-sf-checkbox-required";
        } else if ($this->sfs->option_id === PaperOption::ABSTRACTID
                   || $this->sfs->option_id === DTYPE_SUBMISSION
                   || $this->sfs->option_id === DTYPE_FINAL) {
            $klass = "uich js-settings-sf-wizard";
        }
        $sv->print_select_group("sf/{$this->ctr}/required", "Required", [
            "0" => "No", "1" => "At registration", "2" => "At submission"
        ], [
            "horizontal" => true,
            "group_open" => true,
            "class" => $klass
        ]);
        if ($this->sfs->type === "checkbox") {
            echo MessageSet::feedback_html([
                MessageItem::marked_note("<5>Submitters must check this field before <span class=\"verb\">completing</span> their submissions.")
            ], ["class" => "hidden mt-1"]);
        }
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
            "group_open" => true,
            "fold_values" => ["review"]
        ]);
        echo '<div class="hint fx">The field will be visible to reviewers who have submitted a review, and to PC members who can see all reviews.</div>';
        $sv->print_close_control_group(["horizontal" => true]);
    }

    function print_display(SettingValues $sv) {
        $sv->print_select_group("sf/{$this->ctr}/display", "Display", [
            "right" => "Normal", "rest" => "Grouped with topics", "top" => "Near submission"
        ], [
            "horizontal" => true,
        ]);
    }

    function print_actions(SettingValues $sv) {
        $isnew = !$this->sfs->existed;
        echo '<div class="entryi mb-0"><label></label><div class="btnp entry">',
            Ht::hidden("sf/{$this->ctr}/id", $isnew ? "new" : $this->sfs->id, ["data-default-value" => $isnew ? "" : $this->sfs->id]),
            Ht::hidden("sf/{$this->ctr}/order", $sv->newv("sf/{$this->ctr}/order") ?? "", ["class" => "is-order", "data-default-value" => $sv->oldv("sf/{$this->ctr}/order")]);
        if ($this->sfs->option_id === PaperOption::TITLEID) {
            echo MessageSet::feedback_html([MessageItem::marked_note("<0>This field always appears first on the submission form and cannot be deleted.")]);
        } else {
            echo '<span class="btnbox">',
                Ht::button(Icons::ui_use("movearrow0"), ["class" => "btn-licon ui js-settings-sf-move moveup need-tooltip", "aria-label" => "Move up in display order"]),
                Ht::button(Icons::ui_use("movearrow2"), ["class" => "btn-licon ui js-settings-sf-move movedown need-tooltip", "aria-label" => "Move down in display order"]),
                '</span>';
            if ($this->sfs->option_id > 0) {
                echo Ht::button(Icons::ui_use("trash"), ["class" => "btn-licon ui js-settings-sf-move delete need-tooltip", "aria-label" => "Delete", "data-exists-count" => $this->option_use_count($this->sfs->option_id)]);
            } else {
                echo Ht::button(Icons::ui_use("trash"), ["class" => "btn-licon disabled need-tooltip", "aria-label" => "This intrinsic field cannot be deleted.", "tabindex" => -1]);
            }
        }
        echo "</div></div>";
    }


    private function print_one_option_view(PaperOption $io, SettingValues $sv, $ctr) {
        $disabled = $this->sfs->exists_disabled;
        echo '<div id="sf/', $ctr, '/view" class="settings-xf-viewbox fn2 ui js-foldup">',
            '<div class="settings-xf-viewport"><div class="settings-xf-view">';
        if ($disabled) {
            $this->pt->msg_at($io->formid, "<0>This field is currently disabled", MessageSet::URGENT_NOTE);
        } else if (strcasecmp($this->sfs->exists_if, "all") !== 0
                   && strcasecmp($this->sfs->exists_if, "phase:final") !== 0) {
            $this->pt->msg_at($io->formid, "<0>Present on submissions matching ‘" . $this->sfs->exists_if . "’", MessageSet::WARNING_NOTE);
        }
        if ($io->is_final()) {
            $this->pt->msg_at($io->formid, "<0>Present in the final-version phase", MessageSet::WARNING_NOTE);
        }
        if (strcasecmp($this->sfs->editable_if, "all") === 0) {
            // no editable comment
        } else if (strcasecmp($this->sfs->editable_if, "none") === 0) {
            $this->pt->msg_at($io->formid, "<0>Frozen on all submissions (not editable)", MessageSet::WARNING_NOTE);
        } else if (strcasecmp($this->sfs->editable_if, "phase:review") === 0) {
            $this->pt->msg_at($io->formid, "<0>Editable in the review phase", MessageSet::WARNING_NOTE);
        } else if (strcasecmp($this->sfs->editable_if, "phase:final") !== 0) {
            $this->pt->msg_at($io->formid, "<0>Editable in the final-version phase", MessageSet::WARNING_NOTE);
        } else {
            $this->pt->msg_at($io->formid, "<0>Editable on submissions matching ‘" . $this->sfs->editable_if . "’", MessageSet::WARNING_NOTE);
        }
        foreach ($sv->message_list_at_prefix("sf/{$ctr}/") as $mi) {
            $this->pt->msg_at($io->formid, $mi->message, $mi->status > 0 ? MessageSet::WARNING_NOTE : $mi->status);
        }
        $ei = $io->editable_condition();
        $io->set_editable_condition(true);
        $io->override_exists_condition(true);
        $ov = $this->pt->prow->force_option($io);
        $io->print_web_edit($this->pt, $ov, $ov);
        $io->set_editable_condition($ei);
        $io->override_exists_condition(null);
        echo '</div></div></div>';
    }

    const MODE_LIVE = 0;
    const MODE_TEMPLATE = 1;
    const MODE_SAMPLE = 2;

    /** @param 0|1|2 $mode */
    function print_one_option(SettingValues $sv, $ctr, $mode) {
        $this->ctr = $ctr;
        $this->sfs = $sv->oldv("sf/{$ctr}");
        if ($this->sfs->option_id > 0) {
            $this->typej = ($sv->conf->option_type_map())[$this->sfs->type];
        } else {
            $this->typej = $this->basic_intrinsic_json($this->sfs->option_id);
        }

        echo '<div id="sf/', $ctr, '" class="settings-xf settings-sf ',
            $this->sfs->existed ? '' : 'is-new ',
            $this->sfs->exists_disabled ? 'settings-xf-disabled ' : '',
            $this->sfs->source_option->is_final() ? 'settings-sf-final ' : '',
            'has-fold fold2o ui-fold js-fold-focus hidden">';
        if ($this->sfs->option_id !== PaperOption::TITLEID) {
            echo '<div class="settings-draghandle ui-drag js-settings-drag" draggable="true" title="Drag to reorder fields">',
                Icons::ui_use("move_handle_horizontal"),
                '</div>';
        }

        if ($this->sfs->existed && $mode !== self::MODE_SAMPLE) {
            $this->print_one_option_view($this->sfs->source_option, $sv, $ctr);
        }

        echo '<div id="sf/', $ctr, '/edit" class="settings-xf-edit fx2">';
        if ($sv->has_req("sf/{$ctr}/template")) {
            echo Ht::hidden("sf/{$ctr}/template", $sv->reqstr("sf/{$ctr}/template"));
        }
        $props = $this->typej->properties ?? ["common"];
        $has_common = in_array("common", $props);
        foreach ($sv->cs()->members("submissionfield/properties") as $j) {
            $prop = substr($j->name, strlen($j->group) + 1);
            if (in_array($prop, ["name", "type", "actions"])
                || ($has_common && ($j->common ?? false))
                || in_array($prop, $props)) {
                $sv->print($j->name);
            }
        }
        echo '</div>';

        if ($mode !== self::MODE_SAMPLE) {
            $this->print_js_trigger($ctr);
        }
        echo '</div>';
    }

    private function print_js_trigger($ctr) {
        if ($this->_last_ctr !== null && $this->_last_ctr !== "\$") {
            echo Ht::unstash_script('$("#sf\\\\/' . $this->_last_ctr . '").trigger("hotcrpsettingssf")');
        }
        $this->_last_ctr = $ctr;
    }

    function print(SettingValues $sv) {
        echo "<hr class=\"g\">\n",
            Ht::hidden("has_sf", 1),
            Ht::hidden("options_version", (int) $sv->conf->setting("options")),
            "\n\n";
        Icons::stash_defs("movearrow0", "movearrow2", "trash", "move_handle_horizontal");
        Ht::stash_html('<div id="settings-sf-caption-name" class="hidden"><p>Field names should be short and memorable (they are used as search keywords).</p></div>', 'settings-sf-caption-name');
        Ht::stash_html('<div id="settings-sf-caption-values" class="hidden"><p>Enter choices one per line.</p></div>', 'settings-sf-caption-values');
        Ht::stash_html('<div id="settings-sf-caption-description" class="hidden"><p>Enter an HTML description for the submission form.</p></div>', 'settings-sf-caption-description');
        echo Ht::unstash();

        if ($sv->oblist_keys("sf")) {
            echo '<div class="feedback is-note mb-4">Click on a field to edit it.</div>';
        }

        // configurable option types
        $sftypes = $sv->conf->option_type_map();
        foreach ($this->basic_intrinsic_json_map() as $oj) {
            if ($oj->configurable ?? false) {
                $sftypes[$oj->type] = (object) ["name" => $oj->type, "title" => $oj->name];
            }
        }

        echo '<div id="settings-sform" class="c">';
        // NB: div#settings-sform must ONLY contain fields
        foreach ($sv->oblist_keys("sf") as $ctr) {
            $this->print_one_option($sv, $ctr, self::MODE_LIVE);
        }
        echo "</div>";
        $this->print_js_trigger(null);

        echo '<div class="mt-5">',
            Ht::button("Add submission field", ["class" => "ui js-settings-sf-add"]),
            "</div>\n";
    }


    function make_sample_json(SettingValues $sv, $sampj) {
        $ret = clone $sampj;
        unset($ret->sample_view, $ret->value, $ret->__source_order);
        $template = json_encode($ret);

        ob_start();
        $vopt = $this->make_sample_option($sampj, true);
        $ov = $vopt->parse_json($this->pt->prow, $sampj->value ?? null)
            ?? PaperValue::make($this->pt->prow, $vopt);
        $vopt->print_web_edit($this->pt, $ov, $ov);
        $ret->sf_view_html = ob_get_clean();

        ob_start();
        $eopt = $this->make_sample_option($sampj, false);
        $sfs = $eopt->export_setting();
        foreach ((array) ($sampj->instantiate ?? []) as $k => $v) {
            $sfs->$k = $v;
        }
        $sfs->existed = false;
        $sv->set_oldv("sf/\$", $sfs);
        $sv->set_oldv("sf/\$/template", $template);
        $this->print_one_option($sv, '$', self::MODE_SAMPLE);
        $ret->sf_edit_html = ob_get_clean();

        return $ret;
    }

    /** @return list<array> */
    static function make_types_json($tmap) {
        $cvts = ReviewForm_SettingParser::make_convertible_to_map($tmap);
        $typelist = [];
        foreach ($tmap as $sf) {
            $j = [
                "name" => $sf->name, "title" => $sf->title,
                "convertible_to" => $cvts[$sf->name]
            ];
            if (!empty($sf->placeholders)) {
                $j["placeholders"] = $sf->placeholders;
            }
            $typelist[] = $j;
        }
        return $typelist;
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
        } else if (preg_match('/\A(?:paper|submission|title|authors?|final|none|any|all|true|false|opt(?:ion)?[-:_ ]?\d+)\z/i', $n)) {
            $sfs = $sv->oldv("sf/{$si->name1}");
            if ((strcasecmp($n, "paper") !== 0 || $sfs->option_id !== DTYPE_SUBMISSION)
                && (strcasecmp($n, "submission") !== 0 || $sfs->option_id !== DTYPE_SUBMISSION)
                && (strcasecmp($n, "final") !== 0 || $sfs->option_id !== DTYPE_FINAL)
                && (strcasecmp($n, "title") !== 0 || $sfs->option_id !== PaperOption::TITLEID)
                && ((strcasecmp($n, "author") !== 0 && strcasecmp($n, "authors") !== 0) || $sfs->option_id !== PaperOption::AUTHORSID)) {
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
        if (!$jtype || !in_array("values", $jtype->properties ?? [])) {
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

    /** @param Sf_Setting $sfs */
    private function _apply_new_id(SettingValues $sv, $ctr, $sfs) {
        $idstr = $sv->reqstr("sf/{$ctr}/id") ?? "new";
        if ($idstr !== "" && $idstr !== "new") {
            if (($idnum = stoi($idstr)) !== null
                && $idnum > 0
                && $idnum !== self::ID_PLACEHOLDER
                && !$this->conf->option_by_id($idnum)) {
                $sfs->id = $sfs->option_id = $idnum;
                return;
            } else {
                $sv->error_at("sf/{$ctr}/id", "<0>This option is not configurable");
            }
        }
        if (!$sfs->deleted) {
            $sv->error_if_missing("sf/{$ctr}/name");
            $sfs->id = $sfs->option_id = $this->next_unused_option_id($sv);
        }
    }

    // SETTING INTERACTION RULES
    // Some broad settings, called here “wizard” settings, namely `sf_abstract`
    // and `sf_pdf_submission`, exist to set a combination of submission field
    // settings: `sf_abstract -> sf/abstract/condition + sf/abstract/required`;
    // `sf_pdf_submission -> sf/submission/condition + sf/submission/required`.
    // This causes a ton of irritating complexity.
    // If both are specified, the submission field setting wins.
    // If only a wizard setting is specified, it devolves to submission field
    // settings.

    /** @param Sf_Setting $sfs */
    private function _assign_wizard_settings(SettingValues $sv, $ctr, $sfs) {
        if ($sfs->option_id === PaperOption::ABSTRACTID) {
            $wizkey = "sf_abstract";
            $default_required = PaperOption::REQ_REGISTER;
        } else {
            $wizkey = "sf_pdf_submission";
            $default_required = PaperOption::REQ_SUBMIT;
        }
        if ($sv->has_req("sf/{$ctr}/condition")
            || $sv->has_req("sf/{$ctr}/required")) {
            if (strcasecmp($sfs->exists_if, "none") === 0) {
                $sv->save($wizkey, 1);
            } else if ($sfs->required === $default_required) {
                $sv->save($wizkey, 0);
            } else if ($sfs->required === 0) {
                $sv->save($wizkey, 2);
            } else {
                $sv->save($wizkey, -1);
            }
        } else if ($sv->has_req($wizkey)) {
            $wizval = $sv->newv($wizkey);
            if ($wizval === 0) {
                $sv->save("sf/{$ctr}/required", $default_required);
            } else if ($wizval === 1) {
                $sv->save("sf/{$ctr}/condition", "NONE");
            } else {
                $sv->save("sf/{$ctr}/required", 0);
            }
            if ($wizval !== 1 && $sv->newv("sf/{$ctr}/condition") === "NONE") {
                $sv->save("sf/{$ctr}/condition", "ALL");
            }
        }
    }

    /** @param Sf_Setting $sfs */
    private function _assign_topics_wizard_settings(SettingValues $sv, $ctr, $sfs) {
        if ($sv->has_req("sf/{$ctr}/required")
            && !$sv->has_req("sf/{$ctr}/min")) {
            if ($sfs->required === 0) {
                $sv->save("sf/{$ctr}/min", 0);
            } else if ($sfs->required !== 0 && $sfs->min === 0) {
                $sv->save("sf/{$ctr}/min", 1);
            }
        } else if ($sv->has_req("sf/{$ctr}/min")
                   && !$sv->has_req("sf/{$ctr}/required")) {
            if ($sfs->min === 0) {
                $sv->save("sf/{$ctr}/required", 0);
            } else if ($sfs->required === 0) {
                $sv->save("sf/{$ctr}/required", PaperOption::REQ_REGISTER);
            }
        }
        $sv->save("topic_min", $sv->newv("sf/{$ctr}/min") ?? 0);
        $sv->save("topic_max", $sv->newv("sf/{$ctr}/max") ?? 0);
    }

    /** @param SettingValues $sv
     * @param string $mname
     * @param PaperOption $oopt
     * @param PaperOption $nopt
     * @param Sf_Setting $isfs
     * @param object $isfsj
     * @return list<mixed> */
    private function _intrinsic_member_defaults($sv, $mname, $oopt, $nopt, $isfs, $isfsj) {
        // check other defaults for description and title, which can change
        // based on conditions (e.g. required or not, min/max)
        assert($oopt->formid === $nopt->formid);
        $odefault = [];
        if ($mname === "name") {
            $t1 = $this->conf->_i("sf_{$oopt->formid}", ...$oopt->edit_field_fmt_context());
            $t2 = $this->conf->_i("sf_{$oopt->formid}", ...$nopt->edit_field_fmt_context());
            return [$t1 ?? $isfsj->name, $t2 ?? $isfsj->name];
        } else if ($mname === "description") {
            $fr = new FieldRender(FieldRender::CFHTML);
            $oopt->render_default_description($fr);
            $d1 = $fr->value_html();
            $fr->clear();
            $nopt->render_default_description($fr);
            return [$d1, $fr->value_html()];
        } else {
            return [$isfs->$mname];
        }
    }

    /** @param Sf_Setting $sfs
     * @param PaperOption $nopt
     * @return ?object */
    private function _check_intrinsic_difference(SettingValues $sv, $ctr,
                                                 $sfs, $nopt,
                                                 &$last_form_order,
                                                 &$last_page_order) {
        // Enumerate difference between the expected intrinsic and the new value
        $isfsj = $this->basic_intrinsic_json($sfs->option_id);
        $iopt = $this->intermediate_intrinsic_option($sfs->option_id, $sv);
        $isfs = $iopt->export_setting();
        $osfs = $sv->oldv("sf/{$ctr}");
        $oopt = $sfs->source_option;
        $diffprop = [];
        foreach ($sv->req_member_list("sf/{$ctr}") as $msi) {
            if ($msi->internal
                || $msi->storage_type !== Si::SI_MEMBER
                || $msi->name2 === "/order") {
                continue;
            }
            $mname = $msi->storage_name();
            if (!in_array($mname, $isfsj->properties)) {
                if ($sfs->$mname !== $osfs->$mname) {
                    $sv->error_at($msi, "<0>This property cannot be configured");
                }
                continue;
            }
            // check other defaults for description and title, which can change
            // based on conditions (e.g. required or not, min/max)
            $odefault = $this->_intrinsic_member_defaults($sv, $mname, $oopt, $nopt, $isfs, $isfsj);
            //$is_in_array = in_array($sfs->$mname, $odefault, true); error_log(($is_in_array ? "=" : "≠") . " {$sfs->id} {$mname} " . json_encode($odefault) . " " . json_encode($sfs->$mname));
            if (in_array($sfs->$mname, $odefault, true)) {
                continue;
            }
            // if we get this far, there is a difference
            $diffprop[] = $mname;
        }
        $form_order = $page_order = $isfsj->form_order;
        if (empty($diffprop)
            && $form_order > $last_form_order
            && $form_order > $last_page_order) {
            $last_form_order = $last_page_order = $form_order;
            return null;
        }

        // Check for errors
        if ($nopt->id === DTYPE_FINAL
            && $nopt->test_can_exist()
            && !$nopt->is_final()) {
            $sv->error_at("sf/{$ctr}/condition", "<0>The final version field is restricted to being accessible on final versions.");
        }

        // Produce the difference
        $ij = $iopt->jsonSerialize();
        $nj = $nopt->jsonSerialize();
        $dj = ["id" => $sfs->option_id, "merge" => true];
        foreach ($diffprop as $prop) {
            if (($ij->$prop ?? null) !== ($nj->$prop ?? null))
                $dj[$prop] = $nj->$prop ?? null;
        }
        if ($form_order <= $last_form_order) {
            ++$last_form_order;
            $dj["form_order"] = $form_order = $last_form_order;
        } else {
            $last_form_order = $form_order;
        }
        if ($form_order <= $last_page_order) {
            ++$last_page_order;
            $dj["page_order"] = $last_page_order;
        } else {
            $last_page_order = $form_order;
        }
        return count($dj) === 2 ? null : (object) $dj;
    }

    // FIELD ORDER RULES
    // * Title field order is fixed: it always comes first
    // * Other fields can be reordered
    // * If any non-title intrinsic field is reordered relative to the default,
    //   then all of them are

    /** @return bool */
    private function _apply_req_sf(Si $si, SettingValues $sv) {
        if ($sv->has_req("options_version")
            && (int) $sv->reqstr("options_version") !== (int) $sv->conf->setting("options")) {
            $sv->error_at("sf", "<0>You modified submission field settings in another tab. Please reload.");
        }

        // ensure that the title option is first in the order
        $ctrs = $sv->oblist_keys("sf");
        $i = 0;
        while ($sv->oldv("sf/{$ctrs[$i]}")->option_id !== PaperOption::TITLEID) {
            ++$i;
        }
        if ($i !== 0) {
            $tctr = $ctrs[$i];
            array_splice($ctrs, $i, 1);
            array_unshift($ctrs, $tctr);
        }

        // assign wizard settings for intrinsic fields
        // (must do first, before creating any “intermediate” options)
        foreach ($ctrs as $i => $ctr) {
            $sfs = $sv->newv("sf/{$ctr}");
            if ($sfs->option_id === PaperOption::ABSTRACTID
                || $sfs->option_id === DTYPE_SUBMISSION) {
                $this->_assign_wizard_settings($sv, $ctr, $sfs);
            } else if ($sfs->option_id === PaperOption::TOPICSID) {
                $this->_assign_topics_wizard_settings($sv, $ctr, $sfs);
            }
        }

        // process options in order
        $last_form_order = 0;
        $last_page_order = PaperOption::$display_form_order;
        $nsfss = $insfss = [];
        foreach ($ctrs as $i => $ctr) {
            $sfs = $sv->newv("sf/{$ctr}");
            $sfs->order = $i + 1; // canonicalize order
            if (!$sfs->existed) {
                $this->_apply_new_id($sv, $ctr, $sfs);
            }
            if ($sfs->deleted) {
                if ($sfs->option_id <= 0) {
                    $sv->error_at("sf/{$ctr}", "<0>Intrinsic fields cannot be deleted");
                } else if ($sfs->existed) {
                    $this->_delete_optionids[] = $sfs->id;
                }
                continue;
            }
            if ($sfs->display === "title"
                && $sfs->id !== "title") {
                $sv->warning_at("sf/{$ctr}/display", "<0>The ‘title’ position is reserved for the intrinsic title field");
                $sfs->display = "top";
            }
            $this->_new_options[$sfs->id] = $opt = PaperOption::make($sv->conf, $sfs);
            $this->option_id_to_ctr[$opt->id] = $ctr;
            $my_last_page_order = &$last_page_order[$opt->display()];
            if ($opt->id > 0) {
                $nsfss[] = $oj = $opt->jsonSerialize();
                $computed_order = PaperOption::$display_form_order[$opt->display()] + 100 + $i + 1;
                if ($computed_order <= $last_form_order) {
                    ++$last_form_order;
                    $oj->form_order = $last_form_order;
                } else {
                    $last_form_order = $computed_order;
                }
                if ($computed_order <= $my_last_page_order) {
                    ++$my_last_page_order;
                    $oj->page_order = $my_last_page_order;
                } else {
                    $my_last_page_order = $computed_order;
                }
            } else {
                $diff = $this->_check_intrinsic_difference($sv, $ctr, $sfs, $opt, $last_form_order, $my_last_page_order);
                if ($diff) {
                    $insfss[] = $diff;
                }
            }
        }

        // update settings database
        if ($sv->update("options", empty($nsfss) ? "" : json_encode_db($nsfss))) {
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
        $sv->save("ioptions", empty($insfss) ? "" : json_encode_db($insfss));
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
            $opts = self::configurable_options($sv->conf);
            foreach ($opts as $ctrz => $f) {
                if ($f->visibility() === PaperOption::VIS_AUTHOR
                    && $f->id > 0) {
                    $visname = "sf/" . ($ctrz + 1) . "/visibility";
                    $sv->warning_at($visname, "<5>" . $sv->setting_link("All submissions are anonymous", "author_visibility") . ", so this field is always hidden from reviewers");
                }
            }
        }
    }
}
