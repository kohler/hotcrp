<?php
// paperoption.php -- HotCRP helper class for paper options
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class PaperOption implements JsonSerializable {
    const TITLEID = -1000;
    const AUTHORSID = -1001;
    const ANONYMITYID = -1002;
    const CONTACTSID = -1003;
    const ABSTRACTID = -1004;
    const TOPICSID = -1005;
    const PCCONFID = -1006;
    const COLLABORATORSID = -1007;
    const SUBMISSION_VERSION_ID = -1008;

    /** @var Conf
     * @readonly */
    public $conf;
    /** @var int
     * @readonly */
    public $id;
    /** @var string
     * @readonly */
    public $name;
    /** @var string
     * @readonly */
    public $formid;
    /** @var ?string */
    private $_readable_formid;
    /** @var ?string
     * @readonly */
    private $title;
    /** @var string
     * @readonly */
    public $type; // checkbox, selector, radio, numeric, realnumber, text,
                  // pdf, slides, video, attachments, ...
    private $_json_key;
    /** @var null|string|false */
    public $_search_keyword;
    /** @var string */
    protected $description;
    public $order;
    /** @var bool
     * @readonly */
    public $nonpaper;
    /** @var 0|1|2
     * @readonly */
    public $required;
    /** @var null|0|1
     * @readonly */
    private $_phase;
    /** @var bool
     * @readonly */
    public $configurable;
    /** @var bool
     * @readonly */
    public $include_empty;
    /** @var int
     * @readonly */
    private $_visibility;
    /** @var 0|1|2|3|4|5
     * @readonly */
    private $_display;
    /** @var bool
     * @readonly */
    public $page_expand;
    /** @var ?string
     * @readonly */
    public $page_group;
    private $form_order;
    private $page_order;
    /** @var int */
    private $render_contexts;
    /** @var list<string> */
    public $classes;
    /** @var ?string */
    private $exists_if;
    /** @var -1|0|1 */
    private $_exists_state;
    /** @var ?SearchTerm */
    private $_exists_term;
    /** @var ?string */
    private $editable_if;
    /** @var ?SearchTerm */
    private $_editable_term;
    /** @var int */
    private $_recursion = 0;
    public $max_size;

    const DISP_TITLE = 0;
    const DISP_TOP = 1;
    const DISP_LEFT = 2;
    const DISP_RIGHT = 3;
    const DISP_REST = 4;
    const DISP_NONE = 5;
    /** @var list<string>
     * @readonly */
    static private $display_map = ["title", "top", "left", "right", "rest", "none"];
    /** @var list<int>
     * @readonly */
    static public $display_form_order = [0, 1000, 2000, 3000, 3500, 5000];

    const REQ_NO = 0;
    const REQ_REGISTER = 1;
    const REQ_SUBMIT = 2;

    const VIS_SUB = 0;         // visible if paper is visible (= all)
    const VIS_AUTHOR = 1;      // visible if authors are visible
    const VIS_CONFLICT = 2;    // visible if conflicts are visible
    const VIS_REVIEW = 3;      // visible after submitted review or reviews visible
    const VIS_ADMIN = 4;       // visible only to admins
    static private $visibility_map = ["all", "nonblind", "conflict", "review", "admin"];

    /** @var array<string,class-string> */
    static private $callback_map = [
        "separator" => "+Separator_PaperOption",
        "checkbox" => "+Checkbox_PaperOption",
        "radio" => "+Selector_PaperOption",
        "dropdown" => "+Selector_PaperOption",
        "selector" => "+Selector_PaperOption", /* XXX backward compat */
        "checkboxes" => "+Checkboxes_PaperOption",
        "numeric" => "+Numeric_PaperOption",
        "realnumber" => "+RealNumber_PaperOption",
        "text" => "+Text_PaperOption",
        "mtext" => "+Text_PaperOption",
        "pdf" => "+Document_PaperOption",
        "slides" => "+Document_PaperOption",
        "video" => "+Document_PaperOption",
        "document" => "+Document_PaperOption",
        "attachments" => "+Attachments_PaperOption",
        "topics" => "+Topics_PaperOption"
    ];

    /** @param stdClass|Sf_Setting $args
     * @param string $default_className */
    function __construct(Conf $conf, $args, $default_className = "") {
        $oid = $args->option_id ?? $args->id;
        assert($oid > 0 || $oid === -4 || isset($args->json_key));

        $this->conf = $conf;
        $this->id = (int) $oid;
        $this->name = $args->name ?? "";
        $this->title = $args->title ?? ($this->id > 0 ? $this->name : null);
        $this->type = $args->type ?? null;
        if ($this->type === "selector") { /* XXX backward compat */
            $this->type = "dropdown";
        }

        $this->_json_key = $this->_readable_formid = $args->json_key ?? null;
        $this->_search_keyword = $args->search_keyword ?? $this->_json_key;
        $this->formid = $this->id > 0 ? "opt{$this->id}" : $this->_json_key;

        $this->nonpaper = ($args->nonpaper ?? false) === true;
        $this->configurable = ($args->configurable ?? null) !== false;
        $this->include_empty = ($args->include_empty ?? false) === true;

        $this->description = $args->description ?? "";

        $req = $args->required ?? false;
        if (!$req) {
            $this->required = self::REQ_NO;
        } else if ($req === "submit" || $req === self::REQ_SUBMIT) {
            $this->required = self::REQ_SUBMIT;
        } else {
            $this->required = self::REQ_REGISTER;
        }

        $vis = $args->visibility ?? /* XXX */ $args->view_type ?? null;
        if ($vis !== null) {
            if (($x = array_search($vis, self::$visibility_map)) !== false) {
                $this->_visibility = $x;
            } else if ($vis === "rev" /* XXX */) {
                $this->_visibility = self::VIS_SUB;
            }
        }
        if ($vis === null) {
            $this->_visibility = self::VIS_SUB;
        }

        $disp = $args->display ?? null;
        if ($disp !== null) {
            if (($x = array_search($disp, self::$display_map)) !== false) {
                $this->_display = $x;
            } else if ($disp === "submission" /* XXX */) {
                $this->_display = self::DISP_TOP;
            } else if ($disp === "prominent" /* XXX */) {
                $this->_display = self::DISP_RIGHT;
            } else if ($disp === "topics" /* XXX */) {
                $this->_display = self::DISP_REST;
            }
        }

        $fo = $args->form_order ?? $args->form_position /* XXX */ ?? null;
        if ($this->_display === null) {
            if ($fo === null || $fo >= 3500) {
                $this->_display = self::DISP_REST;
            } else if ($fo >= 3000) {
                $this->_display = self::DISP_RIGHT;
            } else {
                $this->_display = self::DISP_TOP;
            }
        }

        $order = $args->order ?? $args->position /* XXX */ ?? null;
        if ((is_int($order) || is_float($order))
            && ($this->id <= 0 || $order > 0)) {
            $this->order = $order;
            $cfo = self::$display_form_order[$this->_display] + 100 + $order;
        } else {
            $this->order = 399;
            $cfo = $fo ?? self::$display_form_order[$this->_display] + 499;
        }

        $this->form_order = $fo ?? $cfo;
        $this->page_order = $args->page_order ?? $cfo;

        $this->page_expand = !!($args->page_expand ?? false);
        $this->page_group = $args->page_group ?? null;
        if ($this->page_group === null && $this->_display === self::DISP_REST) {
            $this->page_group = "topics";
        }

        $this->set_exists_condition($args->exists_if ?? (($args->final ?? false) ? "phase:final" : null));
        $this->set_editable_condition($args->editable_if ?? null);

        $this->classes = explode(" ", $args->className ?? $default_className);
        $this->set_render_contexts();

        $this->max_size = $args->max_size ?? null;
    }

    private function set_render_contexts() {
        $this->render_contexts = FieldRender::CFPAGE | FieldRender::CFLIST | FieldRender::CFSUGGEST | FieldRender::CFMAIL | FieldRender::CFFORM;
        foreach ($this->classes as $k) {
            if ($k === "hidden") {
                $this->render_contexts = 0;
            } else if ($k === "no-page") {
                $this->render_contexts &= ~FieldRender::CFPAGE;
            } else if ($k === "no-form") {
                $this->render_contexts &= ~FieldRender::CFFORM;
            } else if ($k === "only-page") {
                $this->render_contexts &= FieldRender::CFPAGE;
            } else if ($k === "only-form") {
                $this->render_contexts &= FieldRender::CFFORM;
            } else if ($k === "no-list") {
                $this->render_contexts &= ~(FieldRender::CFLIST | FieldRender::CFSUGGEST);
            } else if ($k === "no-suggest") {
                $this->render_contexts &= ~FieldRender::CFSUGGEST;
            }
        }
        if ($this->form_order === false) {
            $this->render_contexts &= ~FieldRender::CFFORM;
        }
        if ($this->page_order === false) {
            $this->render_contexts &= ~FieldRender::CFPAGE;
        }
        if ($this->_exists_state < 0) {
            $this->render_contexts &= ~(FieldRender::CFFORM | FieldRender::CFPAGE);
        }
    }

    /** @param stdClass|Sf_Setting $args
     * @return PaperOption */
    static function make(Conf $conf, $args) {
        assert(is_object($args));
        Conf::xt_resolve_require($args);
        $fn = $args->function ?? null;
        if (!$fn) {
            $fn = self::$callback_map[$args->type ?? ""] ?? null;
        }
        if (!$fn) {
            $fn = "+Unknown_PaperOption";
        }
        if ($fn[0] === "+") {
            $class = substr($fn, 1);
            /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName */
            return new $class($conf, $args);
        } else {
            return call_user_func($fn, $conf, $args);
        }
    }

    /** @param PaperOption $a
     * @param PaperOption $b */
    static function compare($a, $b) {
        return $a->_display <=> $b->_display
            ? : $a->page_order <=> $b->page_order
            ? : $a->id <=> $b->id;
    }

    /** @param PaperOption $a
     * @param PaperOption $b */
    static function form_compare($a, $b) {
        $ap = $a->form_order;
        $ap = $ap !== false ? $ap : INF;
        $bp = $b->form_order;
        $bp = $bp !== false ? $bp : INF;
        return $ap <=> $bp ? : $a->id <=> $b->id;
    }

    /** @param string $s
     * @return string */
    static function make_readable_formid($s) {
        $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', "-", UnicodeHelper::deaccent($s)));
        if (str_ends_with($s, "-")) {
            $s = substr($s, 0, -1);
        }
        if (!preg_match('/\A(?:title|paper|submission|final|authors|blind|nonblind|contacts|abstract|topics|pc_conflicts|pcconf|collaborators|reviews|sclass|status.*|submit.*|fold.*|[a-z]?[a-z]?[-_].*|has[-_].*|)\z/', $s)) {
            return $s;
        } else {
            return "sf-" . $s;
        }
    }

    /** @return list<FmtArg> */
    function field_fmt_context() {
        return [];
    }
    /** @param ?PaperInfo $prow
     * @return list<FmtArg> */
    final function edit_field_fmt_context($prow = null) {
        $req = $this->required > 0 && (!$prow || $this->test_required($prow));
        return make_array(new FmtArg("edit", true), new FmtArg("required", $req), ...$this->field_fmt_context());
    }
    /** @param FmtArg ...$context
     * @return string */
    function title(...$context) {
        return $this->title
            ?? $this->conf->_i("sf_{$this->formid}", ...$context, ...$this->field_fmt_context())
            ?? $this->name;
    }
    /** @param FmtArg ...$context
     * @return string */
    function title_html(...$context) {
        return htmlspecialchars($this->title(...$context));
    }
    /** @return string */
    function plural_title() {
        return $this->title(new FmtArg("plural", true));
    }
    /** @param ?PaperInfo $prow
     * @return string */
    function edit_title($prow = null) {
        // see also Options_SettingParser::_intrinsic_member_defaults
        return $this->title
            ?? $this->conf->_i("sf_{$this->formid}", ...$this->edit_field_fmt_context($prow))
            ?? $this->name;
    }
    /** @return string */
    function missing_title() {
        return $this->title(new FmtArg("edit", true), new FmtArg("missing", true));
    }

    /** @param FieldRender $fr */
    function render_description($fr) {
        if ($this->description !== "") {
            if (strcasecmp($this->description, "none") !== 0) {
                list($fmt, $fr->value) = Ftext::parse($this->description);
                $fr->value_format = $fmt ?? 5;
            }
        } else {
            $this->render_default_description($fr);
        }
    }
    /** @param FieldRender $fr */
    function render_default_description($fr) {
        $this->conf->fmt()->render_ci($fr, null, "sfdescription_{$this->formid}", ...$this->field_fmt_context());
    }

    /** @return AbbreviationMatcher */
    private function abbrev_matcher() {
        if ($this->nonpaper) {
            return $this->conf->options()->nonpaper_abbrev_matcher();
        } else {
            return $this->conf->abbrev_matcher();
        }
    }
    /** @return string|false */
    function search_keyword() {
        if ($this->_search_keyword === null) {
            $this->abbrev_matcher();
            assert($this->_search_keyword !== null);
        }
        return $this->_search_keyword;
    }
    /** @return string */
    function field_key() {
        return $this->formid;
    }
    /** @return string */
    function readable_formid() {
        if ($this->_readable_formid === null) {
            $used = [];
            foreach ($this->conf->options() as $o) {
                if ($o->_readable_formid !== null) {
                    $used[$o->_readable_formid] = true;
                }
            }
            foreach ($this->conf->options() as $o) {
                if ($o->_readable_formid === null
                    && $o->id > 0
                    && $o->title) {
                    $s = self::make_readable_formid($o->title);
                    $o->_readable_formid = isset($used[$s]) ? $o->formid : $s;
                    $used[$o->_readable_formid] = true;
                }
            }
        }
        return $this->_readable_formid;
    }
    /** @return string */
    function json_key() {
        if ($this->_json_key === null) {
            if ($this->name !== "") {
                $am = $this->abbrev_matcher();
                $e = AbbreviationEntry::make_lazy($this->name, [$this->conf->options(), "option_by_id"], [$this->id], Conf::MFLAG_OPTION);
                $this->_json_key = $am->find_entry_keyword($e, AbbreviationMatcher::KW_UNDERSCORE | AbbreviationMatcher::KW_FULLPHRASE);
            }
            $this->_json_key = $this->_json_key ?? $this->formid;
        }
        return $this->_json_key;
    }
    /** @return string */
    function dtype_name() {
        return $this->id ? $this->json_key() : "paper";
    }
    /** @return string */
    function uid() {
        return $this->json_key();
    }

    /** @return string */
    function description() {
        return $this->description;
    }
    /** @return int|float|false */
    function page_order() {
        return $this->page_order;
    }
    /** @return int */
    function visibility() {
        return $this->_visibility;
    }
    /** @return string */
    function unparse_visibility() {
        return self::$visibility_map[$this->_visibility];
    }
    /** @return bool */
    function always_visible() {
        return $this->_visibility === self::VIS_SUB
            && !$this->_phase
            && $this->_exists_state > 0;
    }

    /** @param null|bool|string $expr
     * @return ?string */
    static private function clean_condition($expr) {
        if ($expr === false) {
            return "NONE";
        } else if ($expr === null
                   || $expr === true
                   || $expr === ""
                   || strcasecmp($expr, "all") === 0) {
            return null;
        } else if (strcasecmp($expr, "none") === 0) {
            return "NONE";
        } else {
            return $expr;
        }
    }

    /** @return ?string */
    final function exists_condition() {
        if ($this->exists_if !== null) {
            return $this->exists_if;
        } else if ($this->_phase === PaperInfo::PHASE_FINAL) {
            return "phase:final";
        } else {
            return null;
        }
    }
    /** @param null|bool|string|SearchTerm $x
     * @suppress PhanAccessReadOnlyProperty */
    final function set_exists_condition($x) {
        // NB It is important to NOT instantiate the search term yet!
        // Parsing the search term requires access to all options, but not
        // all options have been constructed when this is called.
        $this->_phase = null;
        if ($x instanceof SearchTerm) {
            $this->exists_if = $x instanceof False_SearchTerm ? "NONE" : "<special>";
            $this->_exists_term = $x;
            $this->_phase = Phase_SearchTerm::term_phase($x);
        } else {
            $x = self::clean_condition($x);
            if ($x === null) {
                $this->exists_if = null;
                $this->_exists_term = null;
            } else if (strcasecmp($x, "none") === 0) {
                $this->exists_if = "NONE";
                $this->_exists_term = new False_SearchTerm;
            } else if (strcasecmp($x, "phase:final") === 0) {
                $this->exists_if = null;
                $this->_exists_term = null;
                $this->_phase = PaperInfo::PHASE_FINAL;
            } else {
                $this->exists_if = self::clean_condition($x);
                $this->_exists_term = null;
            }
        }
        if ($this->exists_if === null) {
            $this->_exists_state = 1;
        } else if ($this->exists_if === "NONE") {
            $this->_exists_state = -1;
        } else {
            $this->_exists_state = 0;
        }
        if ($this->render_contexts !== null) {
            $this->set_render_contexts();
        }
    }
    /** @param ?bool $x */
    final function override_exists_condition($x) {
        if ($x === true || ($x === null && $this->exists_if === null)) {
            $this->_exists_state = 1;
            $this->_exists_term = null;
        } else if ($x === false || ($x === null && $this->exists_if === "NONE")) {
            $this->_exists_state = -1;
            $this->_exists_term = new False_SearchTerm;
        } else {
            $this->_exists_state = 0;
            $this->_exists_term = null;
        }
        if ($this->render_contexts !== null) {
            $this->set_render_contexts();
        }
    }
    /** @return SearchTerm */
    private function exists_term() {
        if ($this->_exists_state === 0 && $this->_exists_term === null) {
            $s = new PaperSearch($this->conf->root_user(), $this->exists_if);
            $s->set_expand_automatic(true);
            $this->_exists_term = $s->full_term();
            /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
            $this->_phase = Phase_SearchTerm::term_phase($this->_exists_term);
        }
        return $this->_exists_term;
    }
    /** @return bool */
    final function test_can_exist() {
        return $this->_exists_state >= 0;
    }
    /** @return bool */
    final function has_complex_exists_condition() {
        return $this->_exists_state === 0;
    }
    /** @return bool */
    final function is_final() {
        // compute phase of complex exists condition
        $this->exists_term();
        return $this->_phase === PaperInfo::PHASE_FINAL;
    }
    /** @param bool $use_script_expression
     * @return bool */
    final function test_exists(PaperInfo $prow, $use_script_expression = false) {
        if ($this->_phase !== null
            && $prow->phase() !== $this->_phase) {
            return false;
        } else if ($this->_exists_state !== 0) {
            return $this->_exists_state > 0;
        } else if (++$this->_recursion > 5) {
            throw new ErrorException("Recursion in {$this->name}::test_exists");
        } else {
            if ($use_script_expression) {
                $x = !!($this->exists_script_expression($prow) ?? true);
            } else {
                $x = $this->exists_term()->test($prow, null);
            }
            --$this->_recursion;
            return $x;
        }
    }
    final function exists_script_expression(PaperInfo $prow) {
        if ($this->_exists_state > 0) {
            return null;
        } else {
            return $this->exists_term()->script_expression($prow, SearchTerm::ABOUT_PAPER);
        }
    }

    /** @return ?string */
    final function editable_condition() {
        return $this->editable_if;
    }
    /** @param $x null|bool|string|SearchTerm */
    final function set_editable_condition($x) {
        if ($x instanceof SearchTerm) {
            $this->editable_if = $x instanceof False_SearchTerm ? "NONE" : "<special>";
            $this->_editable_term = $x;
        } else {
            $this->editable_if = self::clean_condition($x);
            $this->_editable_term = null;
        }
    }
    /** @return bool */
    final function test_editable(PaperInfo $prow) {
        if ($this->editable_if === null) {
            return true;
        }
        if ($this->_editable_term === null) {
            $s = new PaperSearch($this->conf->root_user(), $this->editable_if);
            $this->_editable_term = $s->full_term();
        }
        if (++$this->_recursion > 5) {
            throw new ErrorException("Recursion in {$this->name}::test_editable");
        } else {
            $x = $this->_editable_term->test($prow, null);
            --$this->_recursion;
            return $x;
        }
    }

    /** @return bool */
    function test_required(PaperInfo $prow) {
        // Invariant: `$o->test_required($prow)` implies `$o->required > 0`.
        return $this->required > 0;
    }
    /** @param 0|1|2 $req */
    protected function set_required($req) {
        /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
        $this->required = $req;
    }

    /** @return bool */
    function is_document() {
        return false;
    }
    /** @return bool */
    function has_document() {
        return false;
    }
    /** @return bool */
    function allow_empty_document() {
        return false;
    }
    /** @return ?list<Mimetype|string> */
    function mimetypes() {
        return null;
    }
    /** @return bool */
    function has_attachments() {
        return false;
    }
    /** @return bool */
    function is_value_present_trivial() {
        return !$this->include_empty;
    }

    function value_force(PaperValue $ov) {
    }
    /** @return bool */
    function value_present(PaperValue $ov) {
        return $ov->value !== null;
    }
    /** @param PaperValue $av
     * @param PaperValue $bv */
    function value_compare($av, $bv) {
        return 0;
    }
    /** @param PaperValue $av
     * @param PaperValue $bv */
    static function basic_value_compare($av, $bv) {
        $av = $av ? $av->value : null;
        $bv = $bv ? $bv->value : null;
        if ($av === null || $bv === null) {
            return ($av === null ? 0 : 1) <=> ($bv === null ? 0 : 1);
        }
        return $av <=> $bv;
    }
    /** @return bool */
    function value_check_required(PaperValue $ov) {
        if ($this->test_required($ov->prow)
            && !$this->value_present($ov)
            && !$ov->prow->allow_absent()) {
            if ($this->required === self::REQ_SUBMIT) {
                $m = $this->conf->_("<0>Entry required to complete submission");
                $ov->msg($m, $ov->prow->want_submitted() ? MessageSet::ERROR : MessageSet::WARNING);
            } else {
                $ov->error("<0>Entry required");
            }
            return false;
        } else {
            return true;
        }
    }
    function value_check(PaperValue $ov, Contact $user) {
        $this->value_check_required($ov);
    }
    /** @return list<int> */
    function value_dids(PaperValue $ov) {
        return [];
    }
    /** @return mixed */
    function value_export_json(PaperValue $ov, PaperExport $pex) {
        return null;
    }
    /** @return void */
    function value_store(PaperValue $ov, PaperStatus $ps) {
    }
    /** @return bool */
    function value_save(PaperValue $ov, PaperStatus $ps) {
        return false;
    }

    /** @param string $name
     * @return ?DocumentInfo */
    function attachment(PaperValue $ov, $name) {
        return null;
    }

    /** @return 0|1|2|3|4|5 */
    function display() {
        return $this->_display;
    }
    /** @return string */
    function display_name() {
        if ($this->page_order === false) {
            return "none";
        } else {
            return self::$display_map[$this->_display];
        }
    }

    #[\ReturnTypeWillChange]
    /** @return object */
    function jsonSerialize() {
        $j = (object) ["id" => (int) $this->id];
        if ($this->name !== "") {
            $j->name = $this->name;
        }
        if ($this->type !== null) {
            $j->type = $this->type;
        }
        $j->order = (int) $this->order;
        if ($this->description !== "") {
            $j->description = $this->description;
        }
        if ($this->configurable !== true) {
            $j->configurable = $this->configurable;
        }
        $this->exists_term(); // to set `_phase`
        if ($this->_phase === PaperInfo::PHASE_FINAL) {
            $j->final = true;
        }
        $j->display = $this->display_name();
        if ($this->_visibility !== self::VIS_SUB) {
            $j->visibility = self::$visibility_map[$this->_visibility];
        }
        if ($this->exists_if !== null) {
            $j->exists_if = $this->exists_if === "NONE" ? false : $this->exists_if;
        }
        if ($this->editable_if !== null) {
            $j->editable_if = $this->editable_if;
        }
        if ($this->required === self::REQ_SUBMIT) {
            $j->required = "submit";
        } else if ($this->required) {
            $j->required = true;
        }
        if ($this->max_size !== null) {
            $j->max_size = $this->max_size;
        }
        return $j;
    }

    /** @return Sf_Setting */
    function export_setting() {
        $sfs = new Sf_Setting;
        $sfs->option_id = $this->id;
        if ($this->id <= 0 && $this->id !== DTYPE_INVALID) {
            $sfs->id = $sfs->json_key = $this->_json_key;
            $sfs->name = $this->edit_title();
            $sfs->function = "+" . get_class($this);
        } else {
            $sfs->id = $this->id;
            $sfs->name = $this->name;
        }
        $sfs->type = $this->type;

        if (strcasecmp($this->description, "none") === 0) {
            $sfs->description = "none";
        } else {
            $fr = new FieldRender(FieldRender::CFHTML);
            $this->render_description($fr);
            $sfs->description = $fr->value_html();
        }

        $sfs->display = $this->display_name();
        $sfs->order = $this->order;
        $sfs->visibility = $this->unparse_visibility();
        $sfs->required = $this->required;
        $sfs->exists_if = $this->exists_if
            ?? ($this->_phase === PaperInfo::PHASE_FINAL ? "phase:final" : "ALL");
        $sfs->exists_disabled = $this->_exists_state < 0;
        $sfs->editable_if = $this->editable_if ?? "ALL";
        $sfs->source_option = $this;
        return $sfs;
    }


    /** @return ?PaperValue */
    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        return null;
    }
    /** @return ?PaperValue */
    function parse_json(PaperInfo $prow, $j) {
        return null;
    }
    const PARSE_STRING_EMPTY = 1;
    const PARSE_STRING_TRIM = 2;
    const PARSE_STRING_SIMPLIFY = 4;
    const PARSE_STRING_CONVERT = 8;
    /** @return ?PaperValue */
    function parse_json_string(PaperInfo $prow, $j, $flags = 0) {
        if (is_string($j)) {
            if ($flags & self::PARSE_STRING_CONVERT) {
                $j = convert_to_utf8($j);
            }
            if ($flags & self::PARSE_STRING_SIMPLIFY) {
                $j = simplify_whitespace($j);
            } else if ($flags & self::PARSE_STRING_TRIM) {
                $j = rtrim($j);
                if ($j !== "" && ctype_space($j[0])) {
                    $j = preg_replace('/\A(?: {0,3}[\r\n]*)*/', "", $j);
                }
            }
            if ($j !== "" || ($flags & self::PARSE_STRING_EMPTY) !== 0) {
                return PaperValue::make($prow, $this, 1, $j);
            } else {
                return PaperValue::make($prow, $this);
            }
        } else if ($j === null) {
            return null;
        } else {
            return PaperValue::make_estop($prow, $this, "<0>Expected string");
        }
    }

    /** @param PaperValue $ov
     * @param PaperValue $reqov */
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
    }
    /** @param PaperValue $ov
     * @param PaperValue $reqov */
    function print_web_edit_text(PaperTable $pt, $ov, $reqov, $extra = []) {
        $default_value = null;
        $od = $ov->data();
        $reqd = $reqov->data();
        if ($od !== $reqd
            && trim($od ?? "") !== trim(cleannl($reqd ?? ""))) {
            $default_value = $od ?? "";
        }
        $pt->print_editable_option_papt($this);
        echo '<div class="papev">';
        if (($fi = $ov->prow->edit_format())
            && !($extra["no_format_description"] ?? false)) {
            echo $fi->description_preview_html();
        }
        echo Ht::textarea($this->formid, $reqd, [
                "id" => $this->readable_formid(),
                "class" => $pt->control_class($this->formid, "w-text need-autogrow"),
                "rows" => max($extra["rows"] ?? 1, 1),
                "cols" => 60,
                "spellcheck" => ($extra["no_spellcheck"] ?? null ? null : "true"),
                "data-default-value" => $default_value
            ]),
            "</div></div>\n\n";
    }
    /** @param PaperInfo $prow
     * @return string */
    function web_edit_html(PaperInfo $prow, Contact $user) {
        ob_start();
        $pt = new PaperTable($user, new Qrequest("POST"), $prow);
        $ov = $prow->force_option($this);
        $this->print_web_edit($pt, $ov, $ov);
        return ob_get_clean();
    }

    function validate_document(DocumentInfo $doc) {
        return true;
    }

    /** @param FieldChangeSet $fcs */
    function strip_unchanged_qreq(PaperInfo $prow, Qrequest $qreq, $fcs) {
    }

    /** @param list<DocumentInfo|int> $docids */
    function save_document_links($docids, PaperInfo $prow) {
        $qv = [];
        foreach ($docids as $doc) {
            $doc = is_object($doc) ? $doc->paperStorageId : $doc;
            assert($doc > 0);
            if ($doc > 0) {
                $qv[] = [$prow->paperId, $this->id, $doc, null];
            }
        }
        $this->conf->qe("delete from PaperOption where paperId=? and optionId=?", $prow->paperId, $this->id);
        if (!empty($qv)) {
            for ($i = 0; count($qv) > 1 && $i < count($qv); ++$i) {
                $qv[$i][3] = $i + 1;
            }
            $this->conf->qe("insert into PaperOption (paperId, optionId, value, `data`) values ?v", $qv);
            $this->conf->qe("update PaperStorage set inactive=0 where paperId=? and paperStorageId?a", $prow->paperId, array_map(function ($qvx) { return $qvx[2]; }, $qv));
        }
        $prow->invalidate_options();
        $prow->mark_inactive_documents();
    }

    /** @return bool */
    function on_form() {
        return ($this->render_contexts & FieldRender::CFFORM) !== 0;
    }
    /** @return bool */
    function on_page() {
        return ($this->render_contexts & FieldRender::CFPAGE) !== 0;
    }
    /** @param int $context
     * @return bool */
    function on_render_context($context) {
        return ($this->render_contexts & $context) !== 0;
    }

    function render(FieldRender $fr, PaperValue $ov) {
    }

    /** @return ?FormatSpec */
    function format_spec() {
        return null;
    }

    /** @param int $context
     * @return list<SearchExample> */
    function search_examples(Contact $viewer, $context) {
        return [];
    }
    /** @return SearchExample */
    function has_search_example() {
        assert($this->search_keyword() !== false);
        return new SearchExample(
            $this, "has:" . $this->search_keyword(),
            "<0>submission’s {title} field is set"
        );
    }
    /** @return SearchExample */
    function text_search_example() {
        assert($this->search_keyword() !== false);
        return new SearchExample(
            $this, $this->search_keyword() . ":{text}",
            "<0>submission’s {title} field contains ‘{text}’",
            new FmtArg("text", "words")
        );
    }
    /** @return ?SearchTerm */
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        return null;
    }
    /** @return ?SearchTerm */
    final function parse_boolean_search(SearchWord $sword, PaperSearch $srch) {
        $lword = strtolower($sword->cword);
        if (in_array($sword->compar, ["", "=", "!="], true)
            && in_array($lword, ["yes", "no", "y", "n", "true", "false"], true)) {
            $v = $lword[0] === "y" || $lword[0] === "t";
            return (new OptionPresent_SearchTerm($srch->user, $this))->negate_if($v !== ($sword->compar !== "!="));
        } else {
            return null;
        }
    }
    /** @return array|bool|null */
    function present_script_expression() {
        return null;
    }
    /** @return array|bool|null */
    function value_script_expression() {
        return null;
    }
    /** @param list $values
     * @return array|bool|null */
    function match_script_expression($values) {
        $se = $this->value_script_expression();
        if (is_array($se)) {
            return ["type" => "in", "child" => [$se], "values" => $values];
        } else {
            return $se;
        }
    }

    /** @param string &$t
     * @return ?Fexpr */
    function parse_fexpr(FormulaCall $fcall, &$t) {
        return null;
    }
    /** @return OptionPresent_Fexpr */
    final function present_fexpr() {
        return new OptionPresent_Fexpr($this);
    }

    /** @param bool $allow_ambiguous
     * @return ?SearchTerm */
    function parse_topic_set_search(SearchWord $sword, PaperSearch $srch, TopicSet $ts,
                                    $allow_ambiguous) {
        if ($sword->cword === "") {
            $srch->lwarning($sword, "<0>Subject missing");
            return null;
        }

        $vs = $allow_ambiguous ? $ts->find_all($sword->cword) : $ts->findp($sword->cword);
        if (empty($vs)) {
            if (($vs2 = $ts->find_all($sword->cword, ~TopicSet::MFLAG_SPECIAL))) {
                $srch->lwarning($sword, $this->conf->_("<0>{title} ‘{0}’ is ambiguous", $sword->cword, new FmtArg("title", $this->title()), new FmtArg("id", $this->readable_formid())));
                $txts = array_map(function ($x) use ($ts) { return "‘{$ts[$x]}’"; }, $vs2);
                $srch->msg_at(null, "<0>Try " . commajoin($txts, " or ") . ", or use ‘{$sword->cword}*’ to match them all.", MessageSet::INFORM);
            } else {
                $srch->lwarning($sword, $this->conf->_("<0>{title} ‘{0}’ not found", $sword->cword, new FmtArg("title", $this->title()), new FmtArg("id", $this->formid)));
                if ($ts->count() <= 10) {
                    $txts = array_map(function ($t) { return "‘{$t}’"; }, $ts->as_array());
                    $srch->msg_at(null, "<0>Choices are " . commajoin($txts, " and ") . ".", MessageSet::INFORM);
                }
            }
            return null;
        }

        if (!in_array($sword->compar, ["", "=", "!="], true)) {
            return null;
        }
        $negate = ($sword->compar === "!=") !== ($vs[0] === 0);
        if ($vs === [-1] || $vs === [0]) {
            return (new OptionPresent_SearchTerm($srch->user, $this))->negate_if($negate);
        } else {
            $vs = array_slice($vs, $vs[0] === 0 ? 1 : 0);
            return (new OptionValueIn_SearchTerm($srch->user, $this, $vs))->negate_if($negate);
        }
    }
}

class Separator_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args, "only-form");
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        echo '<div class="pf pfe pf-separator">';
        if (($h = $pt->edit_title_html($this))) {
            echo '<h3 class="pfehead">', $h, '</h3>';
        }
        $pt->print_field_description($this);
        echo '</div>';
    }
}

class Checkbox_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args, "plc");
    }

    function value_compare($av, $bv) {
        return ($av && $av->value ? 1 : 0) <=> ($bv && $bv->value ? 1 : 0);
    }

    function value_export_json(PaperValue $ov, PaperExport $pex) {
        return $ov->value ? true : false;
    }

    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $x = (string) $qreq[$this->formid];
        return PaperValue::make($prow, $this, $x !== "" && $x !== "0" ? 1 : null);
    }
    function parse_json(PaperInfo $prow, $j) {
        if (is_bool($j) || $j === null) {
            return PaperValue::make($prow, $this, $j ? 1 : null);
        } else {
            return PaperValue::make_estop($prow, $this, "<0>Option should be ‘true’ or ‘false’");
        }
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $cb = Ht::checkbox($this->formid, 1, !!$reqov->value, [
            "id" => $this->readable_formid(),
            "data-default-checked" => !!$ov->value
        ]);
        $pt->print_editable_option_papt($this,
            '<span class="checkc">' . $cb . '</span>' . $pt->edit_title_html($this),
            ["for" => "checkbox", "tclass" => "ui js-click-child"]);
        echo "</div>\n\n";
    }

    function render(FieldRender $fr, PaperValue $ov) {
        if ($ov->value || $fr->verbose()) {
            $fr->set_bool(!!$ov->value);
            if ($fr->want(FieldRender::CFPAGE)) {
                $fr->title = "";
                $th = $this->title_html();
                $fr->set_html($fr->value_html() . " <span class=\"pavfn\">{$th}</span>");
            }
        }
    }

    function search_examples(Contact $viewer, $context) {
        return [$this->has_search_example()];
    }
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        return $this->parse_boolean_search($sword, $srch);
    }
    function present_script_expression() {
        return ["type" => "checkbox", "formid" => $this->formid];
    }
    function value_script_expression() {
        return $this->present_script_expression();
    }

    function parse_fexpr(FormulaCall $fcall, &$t) {
        return $this->present_fexpr();
    }
}

trait Multivalue_OptionTrait {
    /** @var list<string> */
    public $values;
    /** @var ?list<int> */
    public $ids;
    /** @var ?TopicSet */
    private $_values_ts;

    /** @param list<string> $values
     * @param ?list<int> $ids */
    function assign_values($values, $ids) {
        $this->values = $values;
        if ($ids !== null && count($ids) === count($values)) {
            $this->ids = $ids;
        } else {
            $this->ids = null;
        }
    }

    /** @return list<string> */
    function values() {
        return $this->values;
    }

    /** @return list<int> */
    function ids() {
        return $this->ids ?? range(1, count($this->values));
    }

    /** @return bool */
    function is_ids_nontrivial() {
        return $this->ids !== null && $this->ids !== range(1, count($this->values));
    }

    /** @return TopicSet */
    protected function values_topic_set() {
        if ($this->_values_ts === null) {
            /** @phan-suppress-next-line PhanUndeclaredProperty */
            $this->_values_ts = new TopicSet($this->conf);
            foreach ($this->values as $idx => $name) {
                $this->_values_ts->__add($idx + 1, $name);
            }
        }
        return $this->_values_ts;
    }

    /** @param Sf_Setting $sfs */
    function unparse_values_setting($sfs) {
        $sfs->values = $this->values;
        $sfs->ids = $this->ids();

        $sfs->xvalues = [];
        foreach ($this->values as $idx => $s) {
            if ($s !== null) {
                $sfs->xvalues[] = $sfv = new SfValue_Setting;
                $sfv->id = $sfs->ids[$idx];
                $sfv->order = $sfv->old_value = count($sfs->xvalues);
                $sfv->name = $s;
            }
        }
    }

    /** @param int $v
     * @return ?string */
    function value_search_keyword($v) {
        if ($v <= 0) {
            return "none";
        } else if (($vx = $this->values[$v - 1] ?? null) === null) {
            return null;
        } else {
            $e = new AbbreviationEntry($vx, $v, TopicSet::MFLAG_TOPIC);
            return $this->values_topic_set()->abbrev_matcher()->find_entry_keyword($e, AbbreviationMatcher::KW_DASH);
        }
    }
}

class Selector_PaperOption extends PaperOption {
    use Multivalue_OptionTrait;

    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->assign_values($args->values ?? /* XXX */ $args->selector ?? [], $args->ids ?? null);
    }

    function jsonSerialize() {
        $j = parent::jsonSerialize();
        $j->values = $this->values();
        if ($this->is_ids_nontrivial()) {
            $j->ids = $this->ids();
        }
        return $j;
    }
    function export_setting() {
        $sfs = parent::export_setting();
        $this->unparse_values_setting($sfs);
        return $sfs;
    }

    function value_compare($av, $bv) {
        return PaperOption::basic_value_compare($av, $bv);
    }
    function value_export_json(PaperValue $ov, PaperExport $pex) {
        return $this->values[$ov->value - 1] ?? null;
    }

    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $v = trim((string) $qreq[$this->formid]);
        if ($v === "" || $v === "0") {
            return PaperValue::make($prow, $this);
        } else {
            $iv = ctype_digit($v) ? intval($v) : -1;
            if ($iv > 0 && isset($this->values[$iv - 1])) {
                return PaperValue::make($prow, $this, $iv);
            } else if (($idx = array_search($v, $this->values)) !== false) {
                return PaperValue::make($prow, $this, $idx + 1);
            } else {
                return PaperValue::make_estop($prow, $this, "<0>Value doesn’t match any of the options");
            }
        }
    }

    function parse_json(PaperInfo $prow, $j) {
        $v = false;
        if ($j === null || $j === 0) {
            return PaperValue::make($prow, $this);
        } else if (is_string($j)) {
            $v = array_search($j, $this->values);
        } else if (is_int($j) && isset($this->values[$j - 1])) {
            $v = $j - 1;
        }
        if ($v !== false) {
            return PaperValue::make($prow, $this, $v + 1);
        } else {
            return PaperValue::make_estop($prow, $this, "<0>Value doesn’t match any of the options");
        }
    }

    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $pt->print_editable_option_papt($this, null,
            $this->type === "dropdown"
            ? ["for" => $this->readable_formid()]
            : ["id" => $this->readable_formid(), "for" => false]);
        echo '<div class="papev">';
        if ($this->type === "dropdown") {
            $sel = [];
            if (!$ov->value) {
                $sel[0] = "(Choose one)";
            }
            foreach ($this->values() as $i => $s) {
                if ($s !== null)
                    $sel[$i + 1] = $s;
            }
            echo Ht::select($this->formid, $sel, $reqov->value,
                ["id" => $this->readable_formid(),
                 "data-default-value" => $ov->value ?? 0]);
        } else {
            foreach ($this->values() as $i => $s) {
                if ($s !== null) {
                    echo '<div class="checki"><label><span class="checkc">',
                        Ht::radio($this->formid, $i + 1, $i + 1 == $reqov->value,
                            ["data-default-checked" => $i + 1 == $ov->value]),
                        '</span>', htmlspecialchars($s), '</label></div>';
                }
            }
        }
        echo "</div></div>\n\n";
    }

    function render(FieldRender $fr, PaperValue $ov) {
        $fr->set_text($this->values[$ov->value - 1] ?? "");
    }

    /** @return ?string
     * @deprecated */
    function selector_option_search($idx) {
        return $this->value_search_keyword($idx);
    }

    function search_examples(Contact $viewer, $context) {
        $a = [$this->has_search_example()];
        if ($context === SearchExample::HELP) {
            if (($q = $this->value_search_keyword(2))) {
                $a[] = new SearchExample(
                    $this, $this->search_keyword() . ":{value}",
                    "<0>submission’s {title} field has value ‘{value}’",
                    new FmtArg("value", $this->values[1])
                );
            }
        } else {
            foreach ($this->values as $s) {
                $a[] = new SearchExample(
                    $this, $this->search_keyword() . ":" . SearchWord::quote($s)
                );
            }
        }
        return $a;
    }

    function parse_search(SearchWord $sword, PaperSearch $srch) {
        return $this->parse_topic_set_search($sword, $srch, $this->values_topic_set(), false);
    }

    function present_script_expression() {
        return ["type" => "dropdown", "formid" => $this->formid];
    }

    function value_script_expression() {
        return $this->present_script_expression();
    }

    function parse_fexpr(FormulaCall $fcall, &$t) {
        $fex = new OptionValue_Fexpr($this);
        $fex->set_format(Fexpr::FSUBFIELD, $this);
        return $fex;
    }
}

class Document_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
    }

    function is_document() {
        return true;
    }

    function has_document() {
        return true;
    }

    function mimetypes() {
        if ($this->type === "pdf" || $this->id <= 0) {
            return [Mimetype::checked_lookup(".pdf")];
        } else if ($this->type === "slides") {
            return [Mimetype::checked_lookup(".pdf"),
                    Mimetype::checked_lookup(".ppt"),
                    Mimetype::checked_lookup(".pptx"),
                    Mimetype::checked_lookup(".key")];
        } else if ($this->type === "video") {
            return [Mimetype::checked_lookup(".mp4"),
                    Mimetype::checked_lookup(".avi")];
        } else {
            return null;
        }
    }

    function value_force(PaperValue $ov) {
        if ($this->id === DTYPE_SUBMISSION && $ov->prow->paperStorageId > 1) {
            $ov->set_value_data([$ov->prow->paperStorageId], [null]);
        } else if ($this->id === DTYPE_FINAL && $ov->prow->finalPaperStorageId > 1) {
            $ov->set_value_data([$ov->prow->finalPaperStorageId], [null]);
        } else {
            $ov->set_value_data([], []);
        }
    }
    function is_value_present_trivial() {
        return $this->id > 0;
    }
    function value_present(PaperValue $ov) {
        return ($ov->value ?? 0) > 1 || $ov->value === PaperValue::NEWDOC_VALUE;
    }
    function value_compare($av, $bv) {
        return (int) ($av && $this->value_present($av))
            <=> (int) ($bv && $this->value_present($bv));
    }
    function value_dids(PaperValue $ov) {
        if (($ov->value ?? 0) > 1) {
            /** @phan-suppress-next-line ParamTypeMismatchReturn */
            return [$ov->value];
        } else {
            return [];
        }
    }
    function value_export_json(PaperValue $ov, PaperExport $pex) {
        if (!$this->value_present($ov)) {
            return null;
        }
        return $pex->document_json($ov->document(0)) ?? false;
    }
    function value_check(PaperValue $ov, Contact $user) {
        parent::value_check($ov, $user);
        if ($this->value_present($ov)
            && ($doc = $ov->document(0))
            && $doc->mimetype === "application/pdf"
            && ($spec = $this->conf->format_spec($this->id))
            && !$spec->is_empty()) {
            $cf = new CheckFormat($this->conf, CheckFormat::RUN_NEVER);
            $cf->check_document($doc);
            if ($cf->has_problem() && $cf->check_ok()) {
                $ov->message_set()->append_item($cf->front_report_item()->with_field($this->field_key()));
            }
        }
    }
    function value_store(PaperValue $ov, PaperStatus $ps) {
        if ($ov->value === PaperValue::NEWDOC_VALUE) {
            if (($fup = $ov->anno("document"))
                && ($doc = $ps->upload_document($fup, $this))) {
                $ov->set_value_data([$doc->paperStorageId], [null]);
            } else {
                $ov->estop(null);
            }
        }
    }
    function value_save(PaperValue $ov, PaperStatus $ps) {
        if ($this->id === DTYPE_SUBMISSION || $this->id === DTYPE_FINAL) {
            $ps->change_at($this);
            $ov->prow->set_prop($this->id ? "finalPaperStorageId" : "paperStorageId", $ov->value ?? 0);
            return true;
        } else {
            return false;
        }
    }

    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $fk = $this->field_key();
        if (($doc = DocumentInfo::make_request($qreq, $fk, $prow->paperId, $this->id, $this->conf))) {
            $ov = PaperValue::make($prow, $this, PaperValue::NEWDOC_VALUE);
            $ov->set_anno("document", $doc);
            if ($doc->has_error()) {
                foreach ($doc->message_list() as $mi) {
                    $ov->message_set()->append_item($mi->with_landmark($doc->error_filename()));
                }
            }
            return $ov;
        } else if ($qreq["{$fk}:remove"]) {
            return PaperValue::make($prow, $this);
        } else {
            return null;
        }
    }
    function parse_json(PaperInfo $prow, $j) {
        if ($j === false) {
            return PaperValue::make($prow, $this);
        } else if ($j === null) {
            return null;
        } else if (DocumentInfo::check_json_upload($j)) {
            $ov = PaperValue::make($prow, $this, PaperValue::NEWDOC_VALUE);
            $ov->set_anno("document", $j);
            if (isset($j->error_html)) {
                $ov->error("<5>" . $j->error_html);
            }
            return $ov;
        } else {
            return PaperValue::make_estop($prow, $this, "<0>Format error");
        }
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        if (($this->id === DTYPE_SUBMISSION || $this->id === DTYPE_FINAL)
            && ($this->id === DTYPE_FINAL) !== ($pt->user->edit_paper_state($ov->prow) === 2)
            && !$pt->settings_mode) {
            return;
        }

        // XXX
        if ($this->id === DTYPE_SUBMISSION) {
            assert(($ov->value ?? 0) === ($ov->prow->paperStorageId > 1 ? $ov->prow->paperStorageId : 0));
        } else if ($this->id === DTYPE_FINAL) {
            assert(($ov->value ?? 0) === ($ov->prow->finalPaperStorageId > 1 ? $ov->prow->finalPaperStorageId : 0));
        }

        if ($ov->value > 1 && $pt->user->can_view_pdf($ov->prow)) {
            $doc = $ov->document_set(true)->document_by_index(0);
        } else {
            $doc = null;
        }

        $max_size = $this->max_size ?? $this->conf->upload_max_filesize(true);
        $fk = $this->field_key();

        // heading
        $msgs = [];
        $mimetypes = $this->mimetypes();
        if ($mimetypes) {
            $msgs[] = htmlspecialchars(Mimetype::list_description($mimetypes));
        }
        if ($max_size > 0) {
            $msgs[] = "max " . unparse_byte_size($max_size);
        }
        $heading = $pt->edit_title_html($this);
        if (!empty($msgs)) {
            $heading .= ' <span class="n">(' . join(", ", $msgs) . ')</span>';
        }
        $pt->print_editable_option_papt($this, $heading, ["for" => $doc ? false : "{$fk}:uploader", "id" => $this->readable_formid()]);

        echo '<div class="papev has-document" data-dtype="', $this->id,
            '" data-document-name="', $fk, '"';
        if ($doc) {
            echo ' data-docid="', $doc->paperStorageId, '"';
        }
        if (($accept = Mimetype::list_accept($mimetypes))) {
            echo ' data-document-accept="', htmlspecialchars($accept), '"';
        }
        if ($this->max_size > 0) {
            echo ' data-document-max-size="', (int) $this->max_size, '"';
        }
        echo '>';

        // current version, if any
        $has_cf = false;
        if ($doc) {
            if ($doc->mimetype === "application/pdf"
                && ($spec = $this->conf->format_spec($this->id))
                && !$spec->is_empty()) {
                $has_cf = true;
                $pt->cf = $pt->cf ?? new CheckFormat($this->conf, CheckFormat::RUN_NEVER);
                $pt->cf->check_document($doc);
            }

            echo '<div class="document-file">',
                Ht::hidden($this->formid, $doc->paperStorageId),
                $doc->link_html(htmlspecialchars($doc->filename ?? $doc->export_filename())),
                '</div><div class="document-stamps">';
            if (($stamps = PaperTable::pdf_stamps_html($doc))) {
                echo $stamps;
            }
            echo '</div><div class="document-actions">';
            if ($this->id > 0) {
                echo '<button type="button" class="link ui js-remove-document">Delete</button>';
            }
            if ($has_cf && $pt->cf->allow_recheck()) {
                echo '<button type="button" class="link ui js-check-format">',
                    ($pt->cf->need_recheck() ? "Check format" : "Recheck format"),
                    '</button>';
            } else if ($has_cf && !$pt->cf->has_problem()) {
                echo '<span class="js-check-format dim">Format OK</span>';
            }
            echo '</div>';
            if ($has_cf) {
                echo '<div class="document-format">';
                if ($pt->cf->has_problem() && $pt->cf->check_ok()) {
                    echo $pt->cf->document_report($doc);
                }
                echo '</div>';
            }
        }

        echo '<div class="document-replacer">', Ht::button($doc ? "Replace" : "Upload", ["class" => "ui js-replace-document", "id" => "{$fk}:uploader"]), '</div>',
            "</div></div>\n\n";
    }

    function validate_document(DocumentInfo $doc) {
        $mimetypes = $this->mimetypes();
        if (empty($mimetypes)) {
            return true;
        }
        for ($i = 0; $i < count($mimetypes); ++$i) {
            if ($mimetypes[$i]->matches($doc->mimetype))
                return true;
        }
        $desc = Mimetype::list_description($mimetypes);
        $doc->message_set()->error_at(null, "<0>File type {$desc} required");
        $doc->message_set()->inform_at(null, "<0>Your file has MIME type ‘{$doc->mimetype}’ and " . $doc->content_text_signature() . ". Please convert it to " . (count($mimetypes) > 3 ? "a supported type" : $desc) . " and try again.");
        return false;
    }

    /** @param ?DocumentInfo $d */
    static function render_document(FieldRender $fr, PaperOption $opt, $d) {
        if (!$d) {
            if ($fr->verbose()) {
                $fr->set_text("None");
            }
            return;
        }
        if ($fr->want(FieldRender::CFFORM)) {
            $fr->set_html($d->link_html(htmlspecialchars($d->filename ?? ""), 0));
        } else if ($fr->want(FieldRender::CFPAGE)) {
            $th = $opt->title_html();
            $dif = $opt->display() === PaperOption::DISP_TOP ? 0 : DocumentInfo::L_SMALL;
            $fr->title = "";
            $fr->set_html($d->link_html("<span class=\"pavfn\">{$th}</span>", $dif));
        } else {
            $want_mimetype = $fr->column && $fr->column->has_decoration("type");
            if ($want_mimetype) {
                $t = $d->mimetype;
            } else if (!$fr->want(FieldRender::CFLIST | FieldRender::CFCOLUMN)
                       || $fr->verbose()) {
                $t = $d->export_filename();
            } else {
                $t = "";
            }
            if ($fr->want(FieldRender::CFTEXT) || $want_mimetype) {
                $fr->set_text($t);
            } else {
                $fr->set_html($d->link_html(htmlspecialchars($t), DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE));
            }
        }
    }

    function render(FieldRender $fr, PaperValue $ov) {
        if ($this->id <= 0 && $fr->want(FieldRender::CFPAGE)) {
            if ($this->id === 0) {
                $fr->table->render_submission($fr, $this);
            }
        } else {
            if ($fr->want(FieldRender::CFFORM)) {
                $ov->document_set(true);
            }
            self::render_document($fr, $this, $ov->document(0));
        }
    }

    function format_spec() {
        $speckey = "sub_banal";
        if ($this->id) {
            $speckey .= ($this->id < 0 ? "_m" : "_") . abs($this->id);
        }
        $fspec = new FormatSpec;
        if (($xspec = $this->conf->opt($speckey))) {
            $fspec->merge($xspec, $this->conf->opt_timestamp());
        }
        if (($spects = $this->conf->setting($speckey)) > 0) {
            $fspec->merge($this->conf->setting_data($speckey) ?? "", $spects);
        } else if ($spects < 0) {
            $fspec->clear_banal();
        }
        if (!$fspec->is_banal_empty()) {
            $fspec->merge_banal();
        }
        return $fspec;
    }

    function search_examples(Contact $viewer, $context) {
        return [$this->has_search_example()];
    }
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        return $this->parse_boolean_search($sword, $srch);
    }
    function present_script_expression() {
        return ["type" => "document_count", "formid" => $this->formid, "dtype" => $this->id];
    }
}

class Text_PaperOption extends PaperOption {
    /** @var int */
    public $display_space;

    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args, "prefer-row pl_text");
        $bspace = $this->type === "mtext" ? 5 : 0;
        $this->display_space = $args->display_space ?? $bspace;
    }

    function value_present(PaperValue $ov) {
        return (string) $ov->data() !== "";
    }
    function value_compare($av, $bv) {
        $av = $av ? (string) $av->data() : "";
        $bv = $bv ? (string) $bv->data() : "";
        if ($av === "" || $bv === "") {
            return ($av === "" ? 1 : 0) <=> ($bv === "" ? 1 : 0);
        }
        return $this->conf->collator()->compare($av, $bv);
    }

    function value_export_json(PaperValue $ov, PaperExport $pex) {
        $x = $ov->data();
        return $x !== "" ? $x : null;
    }

    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        return $this->parse_json_string($prow, convert_to_utf8($qreq[$this->formid] ?? ""));
    }
    function parse_json(PaperInfo $prow, $j) {
        return $this->parse_json_string($prow, $j);
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $this->print_web_edit_text($pt, $ov, $reqov, ["rows" => $this->display_space]);
    }

    function render(FieldRender $fr, PaperValue $ov) {
        $d = $ov->data();
        if ($d !== null && $d !== "") {
            $fr->value = $d;
            $fr->value_format = $ov->prow->format_of($d);
            $fr->value_long = true;
        }
    }

    function search_examples(Contact $viewer, $context) {
        return [$this->has_search_example(), $this->text_search_example()];
    }
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        if ($sword->compar === "") {
            return new OptionText_SearchTerm($srch->user, $this, $sword->cword);
        } else {
            return null;
        }
    }
    function present_script_expression() {
        return ["type" => "text_present", "formid" => $this->formid];
    }

    function jsonSerialize() {
        $j = parent::jsonSerialize();
        if ($this->display_space !== ($this->type === "mtext" ? 5 : 0)) {
            $j->display_space = $this->display_space;
        }
        return $j;
    }
    function export_setting() {
        $sfs = parent::export_setting();
        if ($this->display_space > 3) {
            $sfs->type = "mtext";
        }
        return $sfs;
    }
}

class Unknown_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        $args->type = "none";
        parent::__construct($conf, $args, "hidden");
    }
}
