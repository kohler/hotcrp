<?php
// paperlist.php -- HotCRP helper class for producing paper lists
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class PaperListTableRender {
    /** @var ?string */
    public $table_start;
    public $thead;
    public $tbody_class;
    /** @var list<string> */
    public $rows;
    public $tfoot;
    public $table_end;
    public $error;

    /** @var int */
    public $ncol;
    /** @var int */
    public $titlecol;
    /** @var int */
    public $split_ncol = 0;

    /** @var int */
    public $colorindex = 0;
    /** @var bool */
    public $hascolors = false;
    /** @var int */
    public $skipcallout;
    /** @var string */
    public $last_trclass = "";
    /** @var list<int> */
    public $groupstart = [0];

    /** @param int $ncol
     * @param int $titlecol
     * @param int $skipcallout */
    function __construct($ncol, $titlecol, $skipcallout) {
        $this->ncol = $ncol;
        $this->titlecol = $titlecol;
        $this->skipcallout = $skipcallout;
    }
    static function make_error($error) {
        $tr = new PaperListTableRender(0, 0, 0);
        $tr->error = $error;
        return $tr;
    }
    function tbody_start() {
        return "  <tbody class=\"{$this->tbody_class}\">\n";
    }
    /** @param string $heading
     * @param array<string,mixed> $attr
     * @return string */
    function heading_row($heading, $attr = []) {
        if (!$heading) {
            return "  <tr class=\"plheading\"><td class=\"plheading-blank\" colspan=\"{$this->ncol}\"></td></tr>\n";
        } else {
            $x = "  <tr class=\"plheading\"";
            foreach ($attr as $k => $v) {
                if ($k !== "no_titlecol" && $k !== "tdclass")
                    $x .= " $k=\"" . htmlspecialchars($v) . "\"";
            }
            $x .= ">";
            $titlecol = ($attr["no_titlecol"] ?? false) ? 0 : $this->titlecol;
            if ($titlecol) {
                $x .= "<td class=\"plheading-spacer\" colspan=\"{$titlecol}\"></td>";
            }
            $tdclass = $attr["tdclass"] ?? null;
            $x .= "<td class=\"plheading" . ($tdclass ? " $tdclass" : "") . "\" colspan=\"" . ($this->ncol - $titlecol) . "\">";
            return $x . $heading . "</td></tr>\n";
        }
    }
    function heading_separator_row() {
        return "  <tr class=\"plheading\"><td class=\"plheading-separator\" colspan=\"{$this->ncol}\"></td></tr>\n";
    }
    function body_rows() {
        return join("", $this->rows);
    }
    function tbody_end() {
        return "  </tbody>\n";
    }
}

class PaperListReviewAnalysis {
    /** @var PaperInfo */
    private $prow;
    /** @var ?ReviewInfo */
    public $rrow = null;
    public $round = "";
    function __construct($rrow, PaperInfo $prow) {
        $this->prow = $prow;
        if ($rrow->reviewId) {
            $this->rrow = $rrow;
            if ($rrow->reviewRound) {
                $this->round = htmlspecialchars($prow->conf->round_name($rrow->reviewRound));
            }
        }
    }
    function icon_html($includeLink) {
        $t = $this->rrow->type_icon();
        if ($includeLink) {
            $t = $this->wrap_link($t);
        }
        if ($this->round) {
            $t .= '<span class="revround" title="Review round">&nbsp;' . $this->round . "</span>";
        }
        return $t;
    }
    function icon_text() {
        $x = "";
        if ($this->rrow->reviewType) {
            $x = ReviewForm::$revtype_names[$this->rrow->reviewType] ?? "";
        }
        if ($x !== "" && $this->round) {
            $x .= ":" . $this->round;
        }
        return $x;
    }
    function wrap_link($html, $klass = null) {
        if (!$this->rrow) {
            return $html;
        }
        if ($this->rrow->reviewStatus >= ReviewInfo::RS_COMPLETED) {
            $href = $this->prow->hoturl(["anchor" => "r" . $this->rrow->unparse_ordinal()]);
        } else {
            $href = $this->prow->reviewurl(["r" => $this->rrow->unparse_ordinal()]);
        }
        $t = $klass ? "<a class=\"$klass\"" : "<a";
        return $t . ' href="' . $href . '">' . $html . '</a>';
    }
}

class PaperList implements XtContext {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly  */
    public $user;
    /** @var Tagger
     * @readonly  */
    public $tagger;
    /** @var PaperSearch
     * @readonly  */
    public $search;
    /** @var Qrequest
     * @readonly  */
    private $qreq;
    /** @var Contact */
    private $_reviewer_user;
    /** @var ?PaperInfoSet */
    private $_rowset;
    /** @var list<TagAnno> */
    private $_groups;

    /** @var bool */
    private $sortable;
    /** @var ?string */
    private $_paper_linkto;
    /** @var bool */
    private $_view_kanban = false;
    /** @var bool */
    private $_view_force = false;
    /** @var array<string,int> */
    private $_viewf = [];
    /** @var array<string,?list<string>> */
    private $_view_decorations = [];
    private $_atab;

    const VIEWORIGIN_MASK = 15;
    const VIEWORIGIN_NONE = -1;
    const VIEWORIGIN_REPORT = 0;
    const VIEWORIGIN_DEFAULT_DISPLAY = 1;
    const VIEWORIGIN_SESSION = 2;
    const VIEWORIGIN_EXPLICIT = 3;
    const VIEW_REPORTSHOW = 16;
    const VIEW_SHOW = 32;
    const VIEW_EDIT = 64;

    private $_table_id;
    private $_table_class;
    private $_report_id;
    private $_row_id_pattern;
    /** @var ?SearchSelection */
    private $_selection;

    /** @var callable(PaperList,PaperInfo):bool */
    private $_row_filter;
    /** @var array<string,list<PaperColumn>> */
    private $_columns_by_name;
    private $_column_errors_by_name = [];
    /** @var ?string */
    private $_current_find_column;

    /** @var list<PaperColumn> */
    private $_sortcol = [];
    /** @var bool */
    private $_sortcol_fixed = false;
    /** @var ?string */
    private $_sort_etag;

    // columns access
    public $qopts; // set by PaperColumn::prepare
    /** @var bool */
    public $need_tag_attr;
    /** @var array */
    public $table_attr;
    /** @var array */
    public $row_attr;
    /** @var bool */
    public $row_overridable;
    /** @var string */
    public $row_tags;
    /** @var string */
    public $row_tags_overridable;
    /** @var bool */
    public $need_render;
    /** @var bool */
    public $has_editable_tags = false;
    /** @var ?CheckFormat */
    public $check_format;

    // collected during render and exported to caller
    /** @var int */
    public $count; // also exported to columns access: 1 more than row index
    /** @var ?array<string,bool> */
    private $_has;
    /** @var ?MessageSet */
    private $_ms;

    static public $include_stash = true;

    static private $stats = [ScoreInfo::SUM, ScoreInfo::MEAN, ScoreInfo::MEDIAN, ScoreInfo::STDDEV_P, ScoreInfo::COUNT];

    function __construct(string $report, PaperSearch $search, $args = [], $qreq = null) {
        $this->conf = $search->conf;
        $this->user = $search->user;
        if (!$qreq || !($qreq instanceof Qrequest)) {
            $qreq = new Qrequest("GET", $qreq);
        }
        $this->qreq = $qreq;
        $this->search = $search;
        $this->_reviewer_user = $search->reviewer_user();
        $this->_rowset = $args["rowset"] ?? null;

        $this->sortable = isset($args["sort"]) && $args["sort"];

        if (in_array($qreq->linkto, ["paper", "assign", "paperedit", "finishreview"])) {
            $this->set_view("linkto", true, null, [$qreq->linkto]);
        }
        $this->_atab = $qreq->atab;

        $this->tagger = new Tagger($this->user);

        $this->qopts = $this->search->simple_search_options();
        if ($this->qopts === false) {
            $this->qopts = ["paperId" => $this->search->paper_ids()];
        }
        $this->qopts["scores"] = [];

        $this->_report_id = $report;
        $this->parse_view($this->_list_columns(), self::VIEWORIGIN_REPORT);
        if ($this->viewable_author_types() === 1) {
            $this->set_view("anonau", true, self::VIEWORIGIN_REPORT);
        }

        if ($this->sortable) {
            if (is_string($args["sort"])) {
                $this->parse_view("sort:[" . $args["sort"] . "]", null);
            } else if ($qreq->sort) {
                $this->parse_view("sort:[" . $qreq->sort . "]", null);
            }
        }

        $qe = $this->search->term();
        if ($qe instanceof Then_SearchTerm) {
            for ($i = 0; $i < $qe->nthen; ++$i) {
                $this->apply_view_search($qe->child[$i], $i);
            }
        }
        $this->apply_view_search($qe, -1);

        if ($qreq->forceShow !== null) {
            $this->set_view("force", !!$qreq->forceShow);
        }
        if ($qreq->selectall && !isset($this->_view_decorations["sel"])) {
            $this->_view_decorations["sel"] = ["selected"];
        }

        $this->_columns_by_name = ["anonau" => [], "aufull" => [], "rownum" => [], "statistics" => []];
    }

    function xt_check_element($e, $xt, $user, Conf $conf) {
        if (str_starts_with($e, "listhas:")) {
            return $this->has(substr($e, 8));
        } else if (str_starts_with($e, "listreport:")) {
            return $this->_report_id === substr($e, 11);
        } else {
            return null;
        }
    }

    /** @return string */
    private function _list_columns() {
        switch ($this->_report_id) {
        case "empty":
            return "";
        case "authorHome":
            return "id title status";
        case "reviewerHome":
            return "id title revtype status [linkto finishreview]";
        case "pl":
            return "sel id title revtype revstat status";
        case "reqrevs":
            return "id title revdelegation revstat status";
        case "reviewAssignment":
            return "id title mypref topicscore desirability assignment potentialconflict topics reviewers [linkto assign]";
        case "conflictassign":
            return "id title authors aufull potentialconflict [revtype basicheader] [editconf basicheader] [linkto assign]";
        case "pf":
            return "sel id title topicscore revtype [editmypref topicsort]";
        case "reviewers":
            return "[sel selected] id title status [linkto assign]";
        case "reviewersSel":
            return "sel id title status [linkto assign]";
        default:
            error_log($this->conf->dbname . ": No such report {$this->_report_id}");
            return "";
        }
    }


    /** @return ?string */
    function table_id() {
        return $this->_table_id;
    }

    /** @param ?string $table_id */
    function set_table_id_class($table_id, $table_class, $row_id_pattern = null) {
        $this->_table_id = $table_id;
        $this->_table_class = $table_class;
        $this->_row_id_pattern = $row_id_pattern;
    }

    /** @param callable(PaperList,PaperInfo):bool $filter */
    function set_row_filter($filter) {
        $this->_row_filter = $filter;
    }

    /** @return MessageSet */
    function message_set() {
        $this->_ms = $this->_ms ?? new MessageSet;
        return $this->_ms;
    }

    function add_column($name, PaperColumn $col) {
        $this->_columns_by_name[$name][] = $col;
    }

    static private $view_synonym = [
        "au" => "authors",
        "author" => "authors",
        "col" => "kanban",
        "column" => "kanban",
        "columns" => "kanban",
        "compact" => "kanban",
        "compactcolumns" => "kanban",
        "rownumbers" => "rownum",
        "stat" => "statistics",
        "stats" => "statistics",
        "totals" => "statistics"
    ];
    static private $view_fake = [
        "anonau" => 150, "aufull" => 150, "force" => 180,
        "kanban" => -2, "rownum" => -1, "statistics" => -1, "all" => -4, "linkto" => -4
    ];


    /** @param string $fname
     * @return bool */
    function viewing($fname) {
        $fname = self::$view_synonym[$fname] ?? $fname;
        return ($this->_viewf[$fname] ?? 0) >= self::VIEW_SHOW;
    }

    /** @param string $k
     * @return int */
    function view_origin($k) {
        $k = self::$view_synonym[$k] ?? $k;
        return ($this->_viewf[$k] ?? 0) & self::VIEWORIGIN_MASK;
    }


    /** @param string $k
     * @param 'show'|'hide'|'edit'|bool $v
     * @param ?int $origin
     * @param ?list<string> $decorations */
    function set_view($k, $v, $origin = null, $decorations = null) {
        $origin = $origin ?? self::VIEWORIGIN_EXPLICIT;
        assert($origin >= self::VIEWORIGIN_REPORT && $origin <= self::VIEWORIGIN_EXPLICIT);
        if ($v === "show" || $v === "hide") {
            $v = $v === "show";
        }
        assert(is_bool($v) || $v === "edit");

        if ($k !== "" && $k[0] === "\"" && $k[strlen($k) - 1] === "\"") {
            $k = substr($k, 1, -1);
        }
        if ($k === "all") {
            assert($v === false && $decorations === null);
            $views = array_keys($this->_viewf);
            foreach ($views as $k) {
                $this->set_view($k, $v, $origin, null);
            }
            return;
        }
        $k = self::$view_synonym[$k] ?? $k;

        $flags = &$this->_viewf[$k];
        $flags = $flags ?? 0;
        if ($origin === self::VIEWORIGIN_REPORT) {
            $flags = ($flags & ~self::VIEW_REPORTSHOW) | ($v ? self::VIEW_REPORTSHOW : 0);
        }
        if (($flags & self::VIEWORIGIN_MASK) <= $origin) {
            $flags = ($flags & self::VIEW_REPORTSHOW)
                | $origin
                | ($v ? self::VIEW_SHOW : 0)
                | ($v === "edit" ? self::VIEW_EDIT : 0);
            if (!empty($decorations)) {
                $this->_view_decorations[$k] = $decorations;
            } else {
                unset($this->_view_decorations[$k]);
            }

            if ($k === "force") {
                $this->_view_force = $v;
            } else if ($k === "kanban") {
                $this->_view_kanban = $v;
            } else if ($k === "linkto") {
                if (!empty($decorations)
                    && in_array($decorations[0], ["paper", "paperedit", "assign", "finishreview"])) {
                    $this->_paper_linkto = $decorations[0];
                }
            } else if (($k === "aufull" || $k === "anonau")
                       && $origin === self::VIEWORIGIN_EXPLICIT
                       && $v
                       && $this->view_origin("authors") < $origin) {
                $this->set_view("authors", true, $origin, null);
            }
        }
    }

    private function _add_sorter($name, $decorations, $sort_subset) {
        assert(!$this->_sortcol_fixed);
        // Do not use ensure_columns_by_name(), because decorations for sorters
        // might differ.
        $old_context = $this->conf->xt_swap_context($this);
        $fs = $this->conf->paper_columns($name, $this->user);
        $this->conf->xt_swap_context($old_context);
        if (count($fs) === 1) {
            $col = PaperColumn::make($this->conf, $fs[0], $decorations);
            if ($col->prepare($this, PaperColumn::PREP_SORT)
                && $col->sort) {
                $col->sort_subset = $sort_subset;
                $this->_sortcol[] = $col;
            } else {
                $this->search->warn("“" . htmlspecialchars($name) . "” cannot be sorted.");
            }
        } else if (empty($fs)) {
            if ($this->user->can_view_tags(null)
                && ($tagger = new Tagger($this->user))
                && ($tag = $tagger->check($name))
                && ($ps = new PaperSearch($this->user, ["q" => "#$tag", "t" => "vis"]))
                && $ps->paper_ids()) {
                $this->search->warn("“" . htmlspecialchars($name) . "” cannot be sorted. Did you mean “sort:#" . htmlspecialchars($name) . "”?");
            } else {
                $this->search->warn("“" . htmlspecialchars($name) . "” cannot be sorted.");
            }
        } else {
            $this->search->warn("Sort “" . htmlspecialchars($name) . "” matches more than one field, ignoring.");
        }
    }

    /** @param list<string> $groups
     * @param ?int $origin
     * @param int $sort_subset */
    private function set_view_list($groups, $origin, $sort_subset) {
        $has_sort = false;
        foreach (PaperSearch::view_generator($groups) as $akd) {
            if ($akd[0] !== "sort" && $sort_subset === -1) {
                $this->set_view($akd[1], substr($akd[0], 0, 4), $origin, $akd[2]);
            }
            if (str_ends_with($akd[0], "sort")
                && ($akd[1] !== "id" || !empty($akd[1]) || $this->_sortcol)) {
                $this->_add_sorter($akd[1], $akd[2], $sort_subset);
            }
        }
    }

    /** @param ?string $str
     * @param ?int $origin */
    function parse_view($str, $origin = null) {
        if (($str ?? "") !== "") {
            $this->set_view_list(SearchSplitter::split_balanced_parens($str), $origin, -1);
        }
    }

    /** @param int $sort_subset */
    private function apply_view_search(SearchTerm $qe, $sort_subset) {
        $nsort = count($this->_sortcol);
        $this->set_view_list($qe->get_float("view") ?? [], null, $sort_subset);
        if ($nsort === count($this->_sortcol)
            && ($sortcol = $qe->default_sort_column(true, $this->search))
            && $sortcol->prepare($this, PaperColumn::PREP_SORT)) {
            assert(!!$sortcol->sort);
            $sortcol->sort_subset = $sort_subset;
            $this->_sortcol[] = $sortcol;
        }
    }

    function apply_view_report_default() {
        if ($this->_report_id === "pl") {
            $s = $this->conf->setting_data("pldisplay_default")
                ?? $this->conf->review_form()->default_display();
        } else if ($this->_report_id === "pf") {
            $s = $this->conf->setting_data("pfdisplay_default");
        } else {
            $s = null;
        }
        $this->parse_view($s, self::VIEWORIGIN_DEFAULT_DISPLAY);
    }

    function apply_view_session() {
        if ($this->_report_id === "pl" || $this->_report_id === "pf") {
            $s = $this->user->session("{$this->_report_id}display");
            $this->parse_view($s, self::VIEWORIGIN_SESSION);
        }
    }

    function apply_view_qreq() {
        foreach ($this->qreq as $k => $v) {
            if (str_starts_with($k, "show") && $v) {
                $name = substr($k, 4);
                $this->set_view($name, true, self::VIEWORIGIN_SESSION, $this->_view_decorations[$name] ?? null);
            } else if ($k === "forceShow") {
                $this->set_view("force", !!$v, self::VIEWORIGIN_SESSION);
            }
        }
    }

    /** @param bool $report_diff
     * @return list<string> */
    function unparse_view($report_diff = false) {
        $this->_prepare();
        $res = [];
        $nextpos = 1000000;
        foreach ($this->_viewf as $k => $v) {
            if ($report_diff
                ? ($v >= self::VIEW_SHOW) !== (($v & self::VIEW_REPORTSHOW) !== 0)
                : $v >= self::VIEW_SHOW) {
                $name = $k;
                $pos = self::$view_fake[$k] ?? null;
                if ($pos === null) {
                    list($name, $decorations) = self::parse_column($k);
                    $fs = $this->conf->paper_columns($name, $this->user);
                    if (count($fs) && isset($fs[0]->position)) {
                        $pos = $fs[0]->position;
                        $name = $fs[0]->name;
                    } else {
                        $pos = $nextpos++;
                    }
                }
                $key = "$pos $name";
                if ($v >= self::VIEW_EDIT) {
                    $kw = "edit";
                } else if ($v >= self::VIEW_SHOW) {
                    $kw = "show";
                } else {
                    $kw = "hide";
                }
                $res[$key] = PaperSearch::unparse_view($kw, $name, $this->_view_decorations[$k] ?? null);
            }
        }
        if (((($this->_viewf["anonau"] ?? 0) >= self::VIEW_SHOW && $this->conf->submission_blindness() == Conf::BLIND_OPTIONAL)
             || ($this->_viewf["aufull"] ?? 0) >= self::VIEW_SHOW)
            && ($this->_viewf["authors"] ?? 0) < self::VIEW_SHOW) {
            $res["150 authors"] = "hide:authors";
        }
        ksort($res, SORT_NATURAL);
        $res = array_values($res);

        foreach ($this->sorters() as $s) {
            $res[] = PaperSearch::unparse_view("sort", $s->name, $s->decorations());
            if ($s->name === "id") {
                break;
            }
        }
        while (!empty($res) && $res[count($res) - 1] === "sort:id") {
            array_pop($res);
        }
        return $res;
    }


    /** @return PaperInfoSet */
    function rowset() {
        if ($this->_rowset === null) {
            $this->qopts["scores"] = array_keys($this->qopts["scores"]);
            if (empty($this->qopts["scores"])) {
                unset($this->qopts["scores"]);
            }
            $result = $this->conf->paper_result($this->qopts, $this->user);
            $this->_rowset = new PaperInfoSet;
            while (($row = PaperInfo::fetch($result, $this->user))) {
                assert(!$this->_rowset->get($row->paperId));
                $this->_rowset->add($row);
            }
            Dbl::free($result);
        }
        if ($this->_groups === null) {
            $this->_sort($this->_rowset);
        }
        return $this->_rowset;
    }

    function _sort_compare($a, $b) {
        foreach ($this->_sortcol as $s) {
            if (($x = $s->compare($a, $b, $this))) {
                return ($x < 0) === $s->sort_reverse ? 1 : -1;
            }
        }
        if ($a->paperId != $b->paperId) {
            return $a->paperId < $b->paperId ? -1 : 1;
        } else {
            return 0;
        }
    }
    function _then_sort_compare($a, $b) {
        if (($x = $a->_sort_subset - $b->_sort_subset)) {
            return $x < 0 ? -1 : 1;
        }
        foreach ($this->_sortcol as $s) {
            if (($s->sort_subset === -1 || $s->sort_subset === $a->_sort_subset)
                && ($x = $s->compare($a, $b, $this))) {
                return ($x < 0) === $s->sort_reverse ? 1 : -1;
            }
        }
        if ($a->paperId != $b->paperId) {
            return $a->paperId < $b->paperId ? -1 : 1;
        } else {
            return 0;
        }
    }

    /** @return non-empty-list<PaperColumn> */
    function sorters() {
        if (!$this->_sortcol_fixed) {
            $this->_sortcol_fixed = true;
            if (empty($this->_sortcol)) {
                $this->_sortcol[] = ($this->ensure_columns_by_name("id"))[0];
            }
            $this->_sort_etag = "";
            if ($this->search->thenmap === null
                && $this->_sortcol[0] instanceof Tag_PaperColumn
                && !$this->_sortcol[0]->sort_reverse) {
                $this->_sort_etag = $this->_sortcol[0]->etag();
            }
        }
        return $this->_sortcol;
    }

    /** @return string */
    function sort_etag() {
        if (!$this->_sortcol_fixed) {
            $this->sorters();
        }
        return $this->_sort_etag;
    }

    private function _sort(PaperInfoSet $rowset) {
        $this->_groups = [];

        // actually sort
        $overrides = $this->user->add_overrides($this->_view_force ? Contact::OVERRIDE_CONFLICT : 0);
        if (($thenmap = $this->search->thenmap)) {
            foreach ($rowset as $row) {
                $row->_sort_subset = $thenmap[$row->paperId];
            }
        }
        foreach ($this->sorters() as $i => $s) {
            $s->prepare_sort($this, $i);
        }
        $rowset->sort_by([$this, $thenmap ? "_then_sort_compare" : "_sort_compare"]);
        $this->user->set_overrides($overrides);

        // clean up, assign groups
        if ($this->_sort_etag !== "") {
            $this->_set_sort_etag_anno_groups();
        }
        if (!empty($this->search->groupmap)) {
            $this->_collect_groups($rowset->as_list());
        }
    }

    /** @param int $g
     * @param TagInfo $dt
     * @param int $anno_index */
    private function _assign_order_anno_group($g, $dt, $anno_index) {
        if (($ta = $dt->order_anno_entry($anno_index))) {
            $this->search->groupmap[$g] = $ta;
        } else if (!isset($this->search->groupmap[$g])) {
            $ta = new TagAnno;
            $ta->tag = $dt->tag;
            $ta->heading = "";
            $this->search->groupmap[$g] = $ta;
        }
    }

    private function _set_sort_etag_anno_groups() {
        assert($this->search->thenmap === null && empty($this->search->groupmap));
        $etag = $this->_sort_etag;
        if (str_starts_with($etag, $this->user->contactId . "~")) {
            $alt_etag = substr($etag, strlen((string) $this->user->contactId));
        } else {
            $alt_etag = "~~~";
        }
        $dt = $this->conf->tags()->add(Tagger::base($etag));
        if (!$dt->has_order_anno()
            && !(($this->_viewf["#$etag"] ?? 0) & self::VIEW_EDIT)
            && !(($this->_viewf["#$alt_etag"] ?? 0) & self::VIEW_EDIT)
            && !(($this->_viewf["tagval:$etag"] ?? 0) & self::VIEW_EDIT)
            && !(($this->_viewf["tagval:$alt_etag"] ?? 0) & self::VIEW_EDIT)) {
            return;
        }
        $srch = $this->search;
        $srch->thenmap = [];
        $this->_assign_order_anno_group(0, $dt, -1);
        $srch->groupmap[0]->heading = "none";
        $cur_then = $aidx = $pidx = 0;
        $plist = $this->_rowset->as_list();
        $alist = $dt->order_anno_list();
        $ptagval = $pidx < count($plist) ? $plist[$pidx]->tag_value($etag) : null;
        while ($aidx < count($alist) || $pidx < count($plist)) {
            if ($pidx == count($plist)
                || ($aidx < count($alist)
                    && ($ptagval === null || $alist[$aidx]->tagIndex <= $ptagval))) {
                if ($cur_then !== 0 || $pidx !== 0 || $aidx !== 0) {
                    ++$cur_then;
                }
                $this->_assign_order_anno_group($cur_then, $dt, $aidx);
                ++$aidx;
            } else {
                $srch->thenmap[$plist[$pidx]->paperId] = $cur_then;
                ++$pidx;
                $ptagval = $pidx < count($plist) ? $plist[$pidx]->tag_value($etag) : null;
            }
        }
    }

    private function _collect_groups(array $srows) {
        $groupmap = $this->search->groupmap ?? [];
        $thenmap = $this->search->thenmap ?? [];
        $rowpos = 0;
        for ($grouppos = 0;
             $rowpos < count($srows) || $grouppos < count($groupmap);
             ++$grouppos) {
            $first_rowpos = $rowpos;
            while ($rowpos < count($srows)
                   && ($thenmap[$srows[$rowpos]->paperId] ?? 0) === $grouppos) {
                ++$rowpos;
            }
            $ginfo = $groupmap[$grouppos] ?? null;
            if (($ginfo === null || $ginfo->is_empty())
                && $first_rowpos === 0) {
                continue;
            }
            $ginfo = $ginfo ? clone $ginfo : TagAnno::make_empty();
            $ginfo->pos = $first_rowpos;
            $ginfo->count = $rowpos - $first_rowpos;
            // leave off an empty “Untagged” section unless editing
            if ($ginfo->count === 0
                && $ginfo->tag && !$ginfo->annoId
                && !$this->has_editable_tags) {
                continue;
            }
            $this->_groups[] = $ginfo;
        }
    }

    /** @return string */
    function sortdef($always = false) {
        $s0 = ($this->sorters())[0];
        if ($s0->sort_subset === -1
            && ($always || (string) $this->qreq->sort != "")
            && ($s0->name !== "id" || $s0->sort_reverse)) {
            return $s0->sort_name() . ($s0->sort_reverse ? " down" : "");
        } else {
            return "";
        }
    }


    function set_selection(SearchSelection $ssel) {
        $this->_selection = $ssel;
    }

    /** @return bool */
    function is_selected($paperId, $default = false) {
        return $this->_selection ? $this->_selection->is_selected($paperId) : $default;
    }

    /** @param string $key
     * @param bool $value */
    function mark_has($key, $value = true) {
        if ($value) {
            $this->_has[$key] = true;
        } else if (!isset($this->_has[$key])) {
            $this->_has[$key] = false;
        }
    }

    /** @param string $key
     * @return bool */
    function has($key) {
        if (!isset($this->_has[$key])) {
            $this->_has[$key] = $this->_compute_has($key);
        }
        return $this->_has[$key];
    }

    private function _compute_has($key) {
        if ($key === "paper" || $key === "submission" || $key === "final") {
            $opt = $this->conf->options()->find($key);
            return $this->user->can_view_some_option($opt)
                && $this->rowset()->any(function ($row) use ($opt) {
                    return ($opt->id == DTYPE_SUBMISSION ? $row->paperStorageId : $row->finalPaperStorageId) > 1
                        && $this->user->can_view_option($row, $opt);
                });
        } else if (str_starts_with($key, "opt")
                   && ($opt = $this->conf->options()->find($key))) {
            return $this->user->can_view_some_option($opt)
                && $this->rowset()->any(function ($row) use ($opt) {
                    return ($ov = $row->option($opt))
                        && (!$opt->has_document() || $ov->value > 1)
                        && $this->user->can_view_option($row, $opt);
                });
        } else if ($key === "abstract") {
            return $this->conf->opt("noAbstract") !== 1
                && $this->rowset()->any(function ($row) {
                    return $row->abstract_text() !== "";
                });
        } else if ($key === "authors") {
            return $this->rowset()->any(function ($row) {
                    return $this->user->allow_view_authors($row);
                });
        } else if ($key === "anonau") {
            return $this->has("authors")
                && $this->user->is_manager()
                && $this->rowset()->any(function ($row) {
                        return $this->user->allow_view_authors($row)
                           && !$this->user->can_view_authors($row);
                    });
        } else if ($key === "tags") {
            $overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
            $answer = $this->user->can_view_tags(null)
                && $this->rowset()->any(function ($row) {
                        return $this->user->can_view_tags($row)
                            && $row->sorted_viewable_tags($this->user) !== "";
                    });
            $this->user->set_overrides($overrides);
            return $answer;
        } else if ($key === "lead") {
            return $this->conf->has_any_lead_or_shepherd()
                && $this->rowset()->any(function ($row) {
                        return $row->leadContactId > 0
                            && $this->user->can_view_lead($row);
                    });
        } else if ($key === "shepherd") {
            return $this->conf->has_any_lead_or_shepherd()
                && $this->rowset()->any(function ($row) {
                        return $row->shepherdContactId > 0
                            && $this->user->can_view_shepherd($row);
                    });
        } else if ($key === "collab") {
            return $this->rowset()->any(function ($row) {
                return $row->has_nonempty_collaborators()
                    && $this->user->can_view_authors($row);
            });
        } else if ($key === "need_submit") {
            return $this->rowset()->any(function ($row) {
                return $row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0;
            });
        } else if ($key === "accepted") {
            return $this->rowset()->any(function ($row) {
                return $row->outcome > 0 && $this->user->can_view_decision($row);
            });
        } else if ($key === "need_final") {
            return $this->has("accepted")
                && $this->rowset()->any(function ($row) {
                       return $row->outcome > 0
                           && $this->user->can_view_decision($row)
                           && $row->timeFinalSubmitted <= 0;
                   });
        } else {
            if (!in_array($key, ["sel", "need_review"], true)) {
                error_log("unexpected PaperList::_compute_has({$key})");
            }
            return false;
        }
    }


    function column_error($text, $is_default = false) {
        if (($name = $this->_current_find_column)
            && (!$is_default || empty($this->_column_errors_by_name[$name]))) {
            $this->_column_errors_by_name[$name][] = $text;
        }
    }

    /** @param string $str
     * @return array{string,?list<string>} */
    static private function parse_column($str) {
        if (str_starts_with($str, "[")) {
            $ws = SearchSplitter::split_balanced_parens(substr($str, 1, strlen($str) - (str_ends_with($str, "]") ? 2 : 1)));
            return [$ws[0] ?? "?", count($ws) > 1 ? array_slice($ws, 1) : null];
        } else {
            return [$str, null];
        }
    }

    /** @param string $str
     * @return list<PaperColumn> */
    private function ensure_columns_by_name($str) {
        list($name, $viewdecorations) = self::parse_column($str);
        if (!array_key_exists($name, $this->_columns_by_name)) {
            $this->_current_find_column = $name;
            $nfs = [];
            foreach ($this->conf->paper_columns($name, $this->user) as $fdef) {
                $decorations = $viewdecorations
                    ?? $this->_view_decorations[$fdef->name]
                    ?? $this->_view_decorations[$name]
                    ?? [];
                if ($fdef->name === $name) {
                    $nfs[] = PaperColumn::make($this->conf, $fdef, $decorations);
                } else {
                    if (!array_key_exists($fdef->name, $this->_columns_by_name)) {
                        $this->_columns_by_name[$fdef->name][] = PaperColumn::make($this->conf, $fdef, $decorations);
                    }
                    $nfs = array_merge($nfs, $this->_columns_by_name[$fdef->name]);
                }
            }
            $this->_columns_by_name[$name] = $nfs;
        }
        return $this->_columns_by_name[$name];
    }

    private function _expand_view_column($k) {
        if (!isset(self::$view_fake[$k])
            && ($this->_viewf[$k] ?? 0) >= self::VIEW_SHOW) {
            $fs = $this->ensure_columns_by_name($k);
            if (!$fs && $this->view_origin($k) >= self::VIEWORIGIN_EXPLICIT) {
                foreach ($this->_column_errors_by_name[$k] ?? [] as $err) {
                    $this->message_set()->error_at($k, "Can’t show " . htmlspecialchars($k) . ": " . $err);
                }
            }
            return $fs;
        } else {
            return [];
        }
    }

    /** @param string $name
     * @return ?PaperColumn */
    function column_by_name($name) {
        $cols = $this->_columns_by_name[$name];
        return count($cols) === 1 ? $cols[0] : null;
    }

    /** @return list<PaperColumn> */
    private function _columns() {
        $this->need_tag_attr = false;
        $this->table_attr = [];
        assert(empty($this->row_attr));

        // extract columns from _viewf
        $old_context = $this->conf->xt_swap_context($this);
        $fields = $viewf = [];
        foreach ($this->_viewf as $k => $v) {
            foreach ($this->_expand_view_column($k) as $f) {
                assert($v >= self::VIEW_SHOW);
                $fields[$f->name] = $fields[$f->name] ?? $f;
                $viewf[$f->name] = $this->_viewf[$f->name] ?? $v;
            }
        }
        $this->conf->xt_swap_context($old_context);

        // update _viewf, prepare, mark fields editable
        $fields2 = [];
        foreach ($fields as $k => $f) {
            $this->_viewf[$k] = $viewf[$k];
            if ($viewf[$k] >= self::VIEW_EDIT) {
                $f->mark_editable();
            }
            $f->is_visible = true;
            $f->has_content = false;
            if ($f->prepare($this, 1)) {
                $fields2[] = $f;
            }
        }

        // sort by position
        usort($fields2, "Conf::xt_position_compare");

        // analyze rows and return
        foreach ($fields2 as $f) {
            $f->analyze($this, $fields2);
        }
        return $fields2;
    }


    /** @param PaperInfo $row
     * @return string */
    function _contentDownload($row) {
        if ($row->size !== 0
            && $this->user->can_view_pdf($row)
            && ($doc = $row->primary_document())) {
            return "&nbsp;" . $doc->link_html("", DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE | DocumentInfo::L_FINALTITLE);
        } else {
            return "";
        }
    }

    /** @return string */
    function _paperLink(PaperInfo $row) {
        $pt = $this->_paper_linkto ?? "paper";
        $pm = "";
        if ($pt === "finishreview") {
            $ci = $row->contact_info($this->user);
            $pt = $ci->review_status <= PaperContactInfo::RS_UNSUBMITTED ? "review" : "paper";
        } else if ($pt === "paperedit") {
            $pt = "paper";
            $pm = "&amp;m=edit";
        }
        return $row->conf->hoturl($pt, "p=" . $row->paperId . $pm);
    }

    // content downloaders
    /** @return Contact */
    function reviewer_user() {
        return $this->_reviewer_user;
    }
    function set_reviewer_user(Contact $user) {
        $this->_reviewer_user = $user;
    }

    /** @param int $contactId
     * @return string */
    function _content_pc($contactId) {
        $pc = $this->conf->pc_member_by_id($contactId);
        return $pc ? $this->user->reviewer_html_for($pc) : "";
    }

    /** @param int $contactId
     * @return string */
    function _text_pc($contactId) {
        $pc = $this->conf->pc_member_by_id($contactId);
        return $pc ? $this->user->reviewer_text_for($pc) : "";
    }

    /** @param int $contactId1
     * @param int $contactId2
     * @param int $ianno */
    function _compare_pc($contactId1, $contactId2, $ianno) {
        assert(!!$ianno);
        $pc1 = $this->conf->pc_member_by_id($contactId1);
        $pc2 = $this->conf->pc_member_by_id($contactId2);
        if ($pc1 === $pc2) {
            return $contactId1 - $contactId2;
        } else if (!$pc1 || !$pc2) {
            return $pc1 ? -1 : 1;
        } else {
            $as = Contact::get_sorter($pc1, $ianno);
            $bs = Contact::get_sorter($pc2, $ianno);
            return $this->conf->collator()->compare($as, $bs);
        }
    }

    /** @return PaperListReviewAnalysis */
    function make_review_analysis($xrow, PaperInfo $row) {
        return new PaperListReviewAnalysis($xrow, $row);
    }


    /** @return int */
    function viewable_author_types() {
        if ($this->search->limit_author()
            || $this->conf->submission_blindness() === Conf::BLIND_NEVER) {
            return 2;
        } else if ($this->user->is_reviewer()
                   && ($this->conf->submission_blindness() === Conf::BLIND_UNTILREVIEW
                       || $this->conf->time_reviewer_view_accepted_authors())) {
            if (($this->search->limit_accepted()
                 && $this->conf->time_reviewer_view_accepted_authors())
                || !$this->user->is_manager()) {
                return 2;
            } else {
                return 3;
            }
        } else if ($this->conf->submission_blindness() === Conf::BLIND_OPTIONAL) {
            return $this->user->is_manager() ? 3 : 2;
        } else {
            return $this->user->is_manager() ? 1 : 0;
        }
    }

    private function _wrap_conflict($main_content, $override_content, PaperColumn $fdef) {
        if ($main_content === $override_content) {
            return $main_content;
        }
        $tag = $fdef->as_row ? "div" : "span";
        if ((string) $main_content !== "") {
            $main_content = "<$tag class=\"fn5\">$main_content</$tag>";
        }
        if ((string) $override_content !== "") {
            $override_content = "<$tag class=\"fx5\">$override_content</$tag>";
        }
        return $main_content . $override_content;
    }

    /** @return string */
    private function _column_html(PaperColumn $fdef, PaperInfo $row) {
        assert(!!$fdef->is_visible);
        $content = "";
        $override = $fdef->override;
        if ($override & PaperColumn::OVERRIDE_NONCONFLICTED) {
            $override &= ~PaperColumn::OVERRIDE_NONCONFLICTED;
        } else if (!$this->row_overridable) {
            $override = 0;
        }
        if ($override <= 0) {
            if (!$fdef->content_empty($this, $row)) {
                $content = $fdef->content($this, $row);
            }
        } else if ($override === PaperColumn::OVERRIDE_BOTH) {
            $content1 = $content2 = "";
            if (!$fdef->content_empty($this, $row)) {
                $content1 = $fdef->content($this, $row);
            }
            $overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
            if (!$fdef->content_empty($this, $row)) {
                $content2 = $fdef->content($this, $row);
            }
            $this->user->set_overrides($overrides);
            $content = $this->_wrap_conflict($content1, $content2, $fdef);
        } else if ($override === PaperColumn::OVERRIDE_FORCE) {
            $overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
            if (!$fdef->content_empty($this, $row)) {
                $content = $fdef->content($this, $row);
            }
            $this->user->set_overrides($overrides);
        } else { // $override > 0
            if (!$fdef->content_empty($this, $row)) {
                $content = $fdef->content($this, $row);
            } else {
                $overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
                if (!$fdef->content_empty($this, $row)) {
                    if ($override === PaperColumn::OVERRIDE_IFEMPTY_LINK) {
                        $content = '<em>Hidden for conflict</em> · <a class="ui js-override-conflict" href="">Override</a>';
                    }
                    $content = $this->_wrap_conflict($content, $fdef->content($this, $row), $fdef);
                }
                $this->user->set_overrides($overrides);
            }
        }
        return $content;
    }

    private function _row_setup(PaperInfo $row) {
        ++$this->count;
        $this->row_attr = [];
        $this->row_overridable = $this->user->has_overridable_conflict($row);

        $this->row_tags = $this->row_tags_overridable = "";
        if (isset($row->paperTags) && $row->paperTags !== "") {
            if ($this->row_overridable) {
                $overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
                $this->row_tags_overridable = $row->sorted_viewable_tags($this->user);
                $this->user->remove_overrides(Contact::OVERRIDE_CONFLICT);
                $this->row_tags = $row->sorted_viewable_tags($this->user);
                $this->user->set_overrides($overrides);
            } else {
                $this->row_tags = $row->sorted_viewable_tags($this->user);
            }
        }
        $this->mark_has("tags", $this->row_tags !== "" || $this->row_tags_overridable !== "");
    }

    static private function _prepend_row_header($content, $ch) {
        $ch = '<em class="plx">' . $ch . ':</em> ';
        if (str_starts_with($content, '<div class="fn5"')) {
            return preg_replace_callback('/(<div class="f[nx]5">)/', function ($m) use ($ch) {
                return $m[1] . $ch;
            }, $content);
        } else if (preg_match('/\A((?:<(?:div|p|ul|ol|li).*?>)*)([\s\S]*)\z/', $content, $m)) {
            return $m[1] . $ch . $m[2];
        } else {
            return $ch . $content;
        }
    }

    private function _row_html($rstate, PaperInfo $row, $fieldDef) {
        // filter
        if ($this->_row_filter
            && !call_user_func($this->_row_filter, $this, $row)) {
            --$this->count;
            return "";
        }

        // main columns
        $tm = "";
        foreach ($fieldDef as $fdef) {
            if ($fdef->as_row) {
                continue;
            }
            $content = $this->_column_html($fdef, $row);
            $tm .= '<td class="pl';
            if ($fdef->fold) {
                $tm .= " fx{$fdef->fold}";
            }
            if ($content !== "") {
                $tm .= " " . $fdef->className . '">' . $content;
            } else {
                $tm .= '">';
            }
            $tm .= '</td>';
            $fdef->has_content = $fdef->has_content || $content !== "";
        }

        // extension columns
        $tt = "";
        foreach ($fieldDef as $fdef) {
            if (!$fdef->as_row) {
                continue;
            }
            $content = $this->_column_html($fdef, $row);
            if ($content !== ""
                && ($ch = $fdef->header($this, false))) {
                if ($content[0] === "<") {
                    $content = self::_prepend_row_header($content, $ch);
                } else {
                    $content = '<em class="plx">' . $ch . ':</em> ' . $content;
                }
            }
            $tt .= "<div class=\"" . $fdef->className;
            if ($fdef->fold) {
                $tt .= " fx" . $fdef->fold;
            }
            $tt .= "\">" . $content . "</div>";
            $fdef->has_content = $fdef->has_content || $content !== "";
        }

        // tags
        if ($this->row_tags_overridable !== ""
            && $this->row_tags_overridable !== $this->row_tags) {
            $this->row_attr["data-tags"] = trim($this->row_tags_overridable);
            $this->row_attr["data-tags-conflicted"] = trim($this->row_tags);
        } else if ($this->row_tags !== "") {
            $this->row_attr["data-tags"] = trim($this->row_tags);
        }

        // row classes
        $trclass = [];
        $cc = "";
        if ($row->paperTags ?? null) {
            if ($this->row_tags_overridable !== ""
                && ($cco = $row->conf->tags()->color_classes($this->row_tags_overridable))) {
                $ccx = $row->conf->tags()->color_classes($this->row_tags);
                if ($cco !== $ccx) {
                    $this->row_attr["data-color-classes"] = $cco;
                    $this->row_attr["data-color-classes-conflicted"] = $ccx;
                    $trclass[] = "colorconflict";
                }
                $cc = $this->_view_force ? $cco : $ccx;
                $rstate->hascolors = $rstate->hascolors || str_ends_with($cco, " tagbg");
            } else if ($this->row_tags !== "") {
                $cc = $row->conf->tags()->color_classes($this->row_tags);
            }
        }
        if ($cc) {
            $trclass[] = $cc;
            $rstate->hascolors = $rstate->hascolors || str_ends_with($cc, " tagbg");
        }
        if (!$cc || !$rstate->hascolors) {
            $trclass[] = "k" . $rstate->colorindex;
        }
        if (($highlightclass = $this->search->highlightmap[$row->paperId] ?? null)) {
            $trclass[] = $highlightclass[0] . "highlightmark";
        }
        $want_plx = $tt !== "" || $this->table_id();
        if (!$want_plx) {
            $trclass[] = "plnx";
        }
        $trclass = join(" ", $trclass);
        $rstate->colorindex = 1 - $rstate->colorindex;
        $rstate->last_trclass = $trclass;

        $t = "  <tr";
        if ($this->_row_id_pattern) {
            $t .= " id=\"" . str_replace("#", (string) $row->paperId, $this->_row_id_pattern) . "\"";
        }
        $t .= " class=\"pl $trclass\" data-pid=\"$row->paperId";
        foreach ($this->row_attr as $k => $v) {
            $t .= "\" $k=\"" . htmlspecialchars($v);
        }
        $t .= "\">" . $tm . "</tr>\n";

        if ($want_plx) {
            $t .= "  <tr class=\"plx $trclass\" data-pid=\"$row->paperId\">";
            if ($rstate->skipcallout > 0) {
                $t .= "<td colspan=\"$rstate->skipcallout\"></td>";
            }
            $t .= "<td class=\"plx\" colspan=\"" . ($rstate->ncol - $rstate->skipcallout) . "\">$tt</td></tr>\n";
        }

        return $t;
    }

    private function _groups_for($grouppos, $rstate, &$body, $last) {
        for ($did_groupstart = false;
             $grouppos < count($this->_groups)
             && ($last || $this->count > $this->_groups[$grouppos]->pos);
             ++$grouppos) {
            if ($this->count !== 1 && $did_groupstart === false) {
                $rstate->groupstart[] = $did_groupstart = count($body);
            }
            $ginfo = $this->_groups[$grouppos];
            if ($ginfo->is_empty()) {
                $body[] = $rstate->heading_row(null);
            } else {
                $attr = [];
                if ($ginfo->tag) {
                    $attr["data-anno-tag"] = $ginfo->tag;
                }
                if ($ginfo->annoId) {
                    $attr["data-anno-id"] = $ginfo->annoId;
                    $attr["data-tags"] = "{$ginfo->tag}#{$ginfo->tagIndex}";
                    if (isset($this->table_attr["data-drag-tag"])) {
                        $attr["tdclass"] = "need-draghandle";
                    }
                }
                $x = "<span class=\"plheading-group";
                if ($ginfo->heading !== ""
                    && ($format = $this->conf->check_format($ginfo->annoFormat, $ginfo->heading))) {
                    $x .= " need-format\" data-format=\"$format";
                    $this->need_render = true;
                }
                $x .= "\" data-title=\"" . htmlspecialchars($ginfo->heading)
                    . "\">" . htmlspecialchars($ginfo->heading)
                    . ($ginfo->heading !== "" ? " " : "")
                    . "</span><span class=\"plheading-count\">"
                    . plural($ginfo->count, "paper") . "</span>";
                $body[] = $rstate->heading_row($x, $attr);
                $rstate->colorindex = 0;
            }
        }
        return $grouppos;
    }

    /** @param PaperColumn $fdef
     * @return string */
    private function _field_title($fdef) {
        $t = $fdef->header($this, false);
        if ($fdef->as_row
            || !$fdef->sort
            || !$this->sortable
            || !($sort_url = $this->search->url_site_relative_raw())) {
            return $t;
        }

        $sort_name = $fdef->sort_name();
        $sort_url = htmlspecialchars(Navigation::siteurl() . $sort_url)
            . (strpos($sort_url, "?") ? "&amp;" : "?") . "sort=" . urlencode($sort_name);

        $sort_class = "pl_sort";
        $s0 = ($this->sorters())[0];
        if ($s0->sort_subset === -1 && $sort_name === $s0->sort_name()) {
            $sort_class = "pl_sort pl_sorting" . ($s0->sort_reverse ? "_rev" : "_fwd");
            $sort_url .= $s0->sort_reverse ? "" : urlencode(" down");
        }

        if ($this->user->overrides() & Contact::OVERRIDE_CONFLICT) {
            $sort_url .= "&amp;forceShow=1";
        }
        return '<a class="' . $sort_class . '" rel="nofollow" href="' . $sort_url . '">' . $t . '</a>';
    }

    /** @param PaperListTableRender $rstate
     * @param list<PaperColumn> $fieldDef */
    private function _analyze_folds($rstate, $fieldDef) {
        $classes = &$this->table_attr["class"];
        $jscol = [];
        $has_sel = $has_statistics = false;
        foreach ($fieldDef as $fdef) {
            assert(!!$fdef->is_visible);
            $jscol[] = $j = $fdef->field_json($this);
            if ($fdef->fold) {
                $classes[] = "fold" . $fdef->fold . "o";
            }
            if ($fdef instanceof Selector_PaperColumn) {
                $has_sel = true;
            }
            if ($fdef->has_content && ($j["has_statistics"] ?? false)) {
                $has_statistics = true;
            }
        }
        // authorship requires special handling
        $classes[] = "fold2" . ($this->viewing("anonau") ? "o" : "c");
        $classes[] = "fold4" . ($this->viewing("aufull") ? "o" : "c");
        if ($this->user->is_track_manager()) {
            $classes[] = "fold5" . ($this->viewing("force") ? "o" : "c");
        }
        if ($has_sel) {
            $classes[] = "fold6" . ($this->viewing("rownum") ? "o" : "c");
        }
        $classes[] = "fold7" . ($this->viewing("statistics") ? "o" : "c");
        $classes[] = "fold8" . ($has_statistics ? "o" : "c");
        $this->table_attr["data-columns"] = $jscol;
    }

    /** @param PaperListTableRender $rstate */
    private function _column_split($rstate, $colhead, &$body) {
        if (count($rstate->groupstart) <= 1) {
            return false;
        }
        $rstate->groupstart[] = count($body);
        $rstate->split_ncol = count($rstate->groupstart) - 1;

        $rownum_marker = "<span class=\"pl_rownum fx6\">";
        $rownum_len = strlen($rownum_marker);
        $nbody = array("<tr>");
        $tbody_class = "pltable" . ($rstate->hascolors ? " pltable-colored" : "");
        for ($i = 1; $i < count($rstate->groupstart); ++$i) {
            $nbody[] = '<td class="plsplit_col top" width="' . (100 / $rstate->split_ncol) . '%"><div class="plsplit_col"><table width="100%">';
            $nbody[] = $colhead . "  <tbody class=\"$tbody_class\">\n";
            $number = 1;
            for ($j = $rstate->groupstart[$i - 1]; $j < $rstate->groupstart[$i]; ++$j) {
                $x = $body[$j];
                if (($pos = strpos($x, $rownum_marker)) !== false) {
                    $pos += strlen($rownum_marker);
                    $x = substr($x, 0, $pos) . preg_replace('/\A\d+/', (string) $number, substr($x, $pos));
                    ++$number;
                } else if (strpos($x, "<td class=\"plheading-blank") !== false) {
                    $x = "";
                }
                $nbody[] = $x;
            }
            $nbody[] = "  </tbody>\n</table></div></td>\n";
        }
        $nbody[] = "</tr>";

        $body = $nbody;
        $rstate->last_trclass = "plsplit_col";
        return true;
    }

    private function _prepare() {
        $this->_has = [];
        $this->count = 0;
        $this->need_render = false;
    }

    /** @param PaperListTableRender $rstate
     * @param list<PaperColumn> $fieldDef
     * @param bool $live
     * @return string */
    private function _statistics_rows($rstate, $fieldDef, $live) {
        foreach ($fieldDef as $fdef) {
            $live = $live || (!$fdef->as_row && $fdef->has_statistics());
        }
        if (!$live) {
            return "";
        }
        $t = '  <tr class="pl_statheadrow fx8">';
        if ($rstate->titlecol) {
            $t .= "<td colspan=\"{$rstate->titlecol}\" class=\"plstat\"></td>";
        }
        $t .= "<td colspan=\"" . ($rstate->ncol - $rstate->titlecol) . "\" class=\"plstat\">" . foldupbutton(7, "Statistics") . "</td></tr>\n";
        foreach (self::$stats as $stat) {
            $t .= '  <tr';
            if ($this->_row_id_pattern) {
                $t .= " id=\"" . str_replace("#", "stat_" . ScoreInfo::$stat_keys[$stat], $this->_row_id_pattern) . "\"";
            }
            $t .= ' class="pl_statrow fx7 fx8" data-statistic="' . ScoreInfo::$stat_keys[$stat] . '">';
            $col = 0;
            foreach ($fieldDef as $fdef) {
                if ($fdef->as_row) {
                    continue;
                }
                $class = "plstat " . $fdef->className;
                if ($fdef->has_statistics()) {
                    $content = $fdef->statistic_html($this, $stat);
                } else if ($col == $rstate->titlecol) {
                    $content = ScoreInfo::$stat_names[$stat];
                    $class = "plstat pl_statheader";
                } else {
                    $content = "";
                }
                $t .= '<td class="' . $class;
                if ($fdef->fold) {
                    $t .= ' fx' . $fdef->fold;
                }
                $t .= '">' . $content . '</td>';
                ++$col;
            }
            $t .= "</tr>\n";
        }
        return $t;
    }

    static function render_footer_row($arrow_ncol, $ncol, $header,
                            $lllgroups, $activegroup = -1, $extra = null) {
        $foot = "<tr class=\"pl_footrow\">\n   ";
        if ($arrow_ncol) {
            $foot .= '<td class="plf pl_footselector" colspan="' . $arrow_ncol . '">'
                . Icons::ui_upperleft() . "</td>\n   ";
        }
        $foot .= '<td id="plact" class="plf pl-footer linelinks" colspan="' . $ncol . '">';

        if ($header) {
            $foot .= "<table class=\"pl-footer-part\"><tbody><tr>\n"
                . '    <td class="pl-footer-desc">' . $header . "</td>\n"
                . '   </tr></tbody></table>';
        }

        foreach ($lllgroups as $i => $lllg) {
            $attr = ["class" => "linelink pl-footer-part"];
            if ($i === $activegroup) {
                $attr["class"] .= " active";
            }
            for ($j = 2; $j < count($lllg); ++$j) {
                if (is_array($lllg[$j])) {
                    foreach ($lllg[$j] as $k => $v) {
                        if (str_starts_with($k, "linelink-")) {
                            $k = substr($k, 9);
                            if ($k === "class") {
                                $attr["class"] .= " " . $v;
                            } else {
                                $attr[$k] = $v;
                            }
                        }
                    }
                }
            }
            $foot .= "<table";
            foreach ($attr as $k => $v) {
                $foot .= " $k=\"" . htmlspecialchars($v) . "\"";
            }
            $foot .= "><tbody><tr>\n"
                . "    <td class=\"pl-footer-desc lll\"><a class=\"ui lla\" href=\""
                . $lllg[0] . "\">" . $lllg[1] . "</a></td>\n";
            for ($j = 2; $j < count($lllg); ++$j) {
                $cell = is_array($lllg[$j]) ? $lllg[$j] : ["content" => $lllg[$j]];
                '@phan-var array{content:string} $cell';
                $attr = [];
                foreach ($cell as $k => $v) {
                    if ($k !== "content" && !str_starts_with($k, "linelink-")) {
                        $attr[$k] = $v;
                    }
                }
                if ($attr || isset($cell["content"])) {
                    $attr["class"] = rtrim("lld " . ($attr["class"] ?? ""));
                    $foot .= "    <td";
                    foreach ($attr as $k => $v) {
                        $foot .= " $k=\"" . htmlspecialchars($v) . "\"";
                    }
                    $foot .= ">";
                    if ($j === 2
                        && isset($cell["content"])
                        && !str_starts_with($cell["content"], "<b>")) {
                        $foot .= "<b>:&nbsp;</b> ";
                    }
                    if (isset($cell["content"])) {
                        $foot .= $cell["content"];
                    }
                    $foot .= "</td>\n";
                }
            }
            if ($i < count($lllgroups) - 1) {
                $foot .= "    <td>&nbsp;<span class=\"barsep\">·</span>&nbsp;</td>\n";
            }
            $foot .= "   </tr></tbody></table>";
        }
        return $foot . (string) $extra . "<hr class=\"c\" /></td>\n </tr>";
    }

    private function _footer($ncol, $extra, Qrequest $qreq) {
        if ($this->count == 0) {
            return "";
        }

        $gex = ListAction::grouped_extensions($this->user);
        $gex->add_xt_checker([$this, "xt_check_element"]);
        $gex->filter_by("display_if");
        $lllgroups = [];
        $whichlll = -1;
        foreach ($gex->members("") as $rf) {
            if (isset($rf->render_callback)
                && !str_starts_with($rf->name, "__")
                && Conf::xt_resolve_require($rf)
                && ($lllg = call_user_func($rf->render_callback, $this, $qreq, $gex, $rf))) {
                if (is_string($lllg)) {
                    $lllg = [$lllg];
                }
                array_unshift($lllg, $rf->name, $rf->title);
                $lllg[0] = $this->conf->selfurl($qreq, ["atab" => $lllg[0], "anchor" => "plact"]);
                $lllgroups[] = $lllg;
                if ($qreq->fn == $rf->name || $this->_atab == $rf->name) {
                    $whichlll = count($lllgroups) - 1;
                }
            }
        }

        $footsel_ncol = $this->_view_kanban ? 0 : 1;
        return self::render_footer_row($footsel_ncol, $ncol - $footsel_ncol,
            "<b>Select papers</b> (or <a class=\"ui js-select-all\" href=\""
            . $this->conf->selfurl($qreq, ["selectall" => 1, "anchor" => "plact"])
            . '">select all ' . $this->count . "</a>), then&nbsp;",
            $lllgroups, $whichlll, $extra);
    }

    /** @return bool */
    function is_empty() {
        return $this->rowset()->is_empty();
    }

    /** @return array{list<int>,list<TagAnno>} */
    function ids_and_groups() {
        $rows = $this->rowset();
        return [$rows->paper_ids(), $this->_groups];
    }

    /** @return list<int> */
    function paper_ids() {
        return $this->rowset()->paper_ids();
    }

    private function _listDescription() {
        switch ($this->_report_id) {
        case "reviewAssignment":
            return "Review assignments";
        case "editpref":
            return "Review preferences";
        case "reviewers":
        case "reviewersSel":
            return "Proposed assignments";
        default:
            return null;
        }
    }

    /** @return SessionList */
    function session_list_object() {
        assert($this->_groups !== null);
        return $this->search->create_session_list_object($this->paper_ids(), $this->_listDescription(), $this->sortdef());
    }

    /** @param array{list?:bool,attributes?:array,fold_session_prefix?:string,noheader?:bool,nofooter?:bool,footer_extra?:string,live?:bool} $options */
    private function _table_render($options) {
        $this->_prepare();
        // need tags for row coloring
        if ($this->user->can_view_tags(null)) {
            $this->qopts["tags"] = true;
        }

        // get column list
        $field_list = $this->_columns();
        if (empty($field_list)) {
            return PaperListTableRender::make_error("Nothing to show");
        }

        $rows = $this->rowset();
        if ($rows->is_empty()) {
            if (($altq = $this->search->alternate_query())) {
                $altqh = htmlspecialchars($altq);
                $url = $this->search->url_site_relative_raw($altq);
                if (substr($url, 0, 5) == "search") {
                    $altqh = "<a href=\"" . htmlspecialchars(Navigation::siteurl() . $url) . "\">" . $altqh . "</a>";
                }
                return PaperListTableRender::make_error("No matching papers. Did you mean “{$altqh}”?");
            } else {
                return PaperListTableRender::make_error("No matching papers");
            }
        }

        // get field array
        $fieldDef = array();
        $ncol = $titlecol = 0;
        // folds: anonau:2, fullrow:3, aufull:4, force:5, rownum:6, statistics:7,
        // statistics-exist:8, [fields]
        $next_fold = 9;
        foreach ($field_list as $fdef) {
            $fieldDef[$fdef->name] = $fdef;
            if ($this->view_origin($fdef->name) !== self::VIEWORIGIN_REPORT) {
                $fdef->fold = $next_fold;
                ++$next_fold;
            }
            if ($fdef->name == "title") {
                $titlecol = $ncol;
            }
            if (!$fdef->as_row) {
                ++$ncol;
            }
        }

        // count non-callout columns
        $skipcallout = 0;
        foreach ($fieldDef as $fdef) {
            if (!$fdef->as_row) {
                if ($fdef->position === null || $fdef->position >= 100) {
                    break;
                } else {
                    ++$skipcallout;
                }
            }
        }

        // create render state
        $rstate = new PaperListTableRender($ncol, $titlecol, $skipcallout);

        // prepare table attributes
        $this->table_attr["class"] = ["pltable has-fold"];
        if ($this->_table_class) {
            $this->table_attr["class"][] = $this->_table_class;
        }
        if ($this->_table_id) {
            $this->table_attr["id"] = $this->_table_id;
        }
        if (!empty($options["attributes"])) {
            foreach ($options["attributes"] as $n => $v) {
                $this->table_attr[$n] = $v;
            }
        }
        if (isset($options["fold_session_prefix"])) {
            $this->table_attr["data-fold-session-prefix"] = $options["fold_session_prefix"];
            $this->table_attr["data-fold-session"] = json_encode_browser([
                "2" => "anonau", "4" => "aufull", "5" => "force",
                "6" => "rownum", "7" => "statistics"
            ]);
        }
        if ($this->_groups) {
            $this->table_attr["data-groups"] = json_encode_browser($this->_groups);
        }
        if ($this->_sort_etag !== "") {
            $this->table_attr["data-order-tag"] = $this->_sort_etag;
        }
        if ($options["list"] ?? false) {
            $this->table_attr["class"][] = "has-hotlist";
            $this->table_attr["data-hotlist"] = $this->session_list_object()->info_string();
        }
        if ($this->sortable && ($url = $this->search->url_site_relative_raw())) {
            $url = Navigation::siteurl() . $url . (strpos($url, "?") ? "&" : "?") . "sort={sort}";
            $this->table_attr["data-sort-url-template"] = $url;
        }

        // collect row data
        $body = array();
        $grouppos = empty($this->_groups) ? -1 : 0;
        $need_render = false;
        foreach ($rows as $row) {
            $this->_row_setup($row);
            if ($grouppos >= 0) {
                $grouppos = $this->_groups_for($grouppos, $rstate, $body, false);
            }
            $body[] = $this->_row_html($rstate, $row, $fieldDef);
            if ($this->need_render && !$need_render) {
                Ht::stash_script('$(plinfo.render_needed)', 'plist_render_needed');
                $need_render = true;
            }
            if ($this->need_render && $this->count % 16 == 15) {
                $body[count($body) - 1] .= "  " . Ht::script('plinfo.render_needed()') . "\n";
                $this->need_render = false;
            }
        }
        if ($grouppos >= 0 && $grouppos < count($this->_groups)) {
            $this->_groups_for($grouppos, $rstate, $body, true);
        }
        if ($this->count === 0) {
            return PaperListTableRender::make_error("No matching papers");
        }

        // analyze `has`, including authors
        foreach ($fieldDef as $fdef) {
            $this->mark_has($fdef->name, $fdef->has_content);
        }

        // statistics rows
        $tfoot = "";
        if (!$this->_view_kanban) {
            $tfoot = $this->_statistics_rows($rstate, $fieldDef, $options["live"] ?? false);
        }

        // analyze folds
        $this->_analyze_folds($rstate, $fieldDef);

        // header cells
        if (!($options["noheader"] ?? false)) {
            $ths = "";
            foreach ($fieldDef as $fdef) {
                if ($fdef->as_row) {
                    continue;
                }
                if ($fdef->has_content || ($options["fullheader"] ?? false)) {
                    $ths .= "<th class=\"pl plh " . $fdef->className;
                    if ($fdef->fold) {
                        $ths .= " fx" . $fdef->fold;
                    }
                    $ths .= "\">" . $this->_field_title($fdef) . "</th>";
                } else {
                    $ths .= "<th";
                    if ($fdef->fold) {
                        $ths .= " class=\"fx{$fdef->fold}\"";
                    }
                    $ths .= "></th>";
                }
            }

            $colhead = " <thead class=\"pltable\">\n  <tr class=\"pl_headrow\">" . $ths . "</tr>\n";

            if (isset($this->table_attr["data-drag-tag"])
                && $this->user->can_change_tag_anno($this->_sort_etag)) {
                $colhead .= "  <tr class=\"pl_headrow pl_annorow\" data-anno-tag=\"{$this->_sort_etag}\">";
                if ($rstate->titlecol) {
                    $colhead .= "<td class=\"plh\" colspan=\"$rstate->titlecol\"></td>";
                }
                $colhead .= "<td class=\"plh\" colspan=\"" . ($rstate->ncol - $rstate->titlecol) . "\"><a class=\"ui js-annotate-order\" data-anno-tag=\"{$this->_sort_etag}\" href=\"\">Annotate order</a></td></tr>\n";
            }

            $colhead .= " </thead>\n";
        } else {
            $colhead = "";
        }

        // table skeleton including fold classes
        $enter = "<table";
        foreach ($this->table_attr as $k => $v) {
            if (is_array($v) || is_object($v)) {
                $v = $k === "class" ? join(" ", $v) : json_encode_browser($v);
            }
            if ($k === "data-columns" || $k === "data-groups") {
                $enter .= " $k='" . str_replace("'", "&#039;", htmlspecialchars($v, ENT_NOQUOTES)) . "'";
            } else {
                $enter .= " $k=\"" . htmlspecialchars($v) . "\"";
            }
        }
        $rstate->table_start = $enter . ">\n";
        $rstate->table_end = "</table>";

        // maybe make columns, maybe not
        if ($this->_view_kanban
            && !$this->rowset()->is_empty()
            && $this->_column_split($rstate, $colhead, $body)) {
            $rstate->table_start = '<div class="plsplit_col_ctr_ctr"><div class="plsplit_col_ctr">' . $rstate->table_start;
            $rstate->table_end .= "</div></div>";
            $ncol = $rstate->split_ncol;
            $rstate->tbody_class = "pltable-split";
        } else {
            $rstate->thead = $colhead;
            $rstate->tbody_class = "pltable" . ($rstate->hascolors ? " pltable-colored" : "");
        }
        if ($this->has_editable_tags) {
            $rstate->tbody_class .= " need-editable-tags";
        }

        // footer
        reset($fieldDef);
        if (current($fieldDef) instanceof Selector_PaperColumn
            && !($options["nofooter"] ?? false)) {
            $tfoot .= $this->_footer($ncol, $options["footer_extra"] ?? "", $this->qreq);
        }
        if ($tfoot) {
            $rstate->tfoot = ' <tfoot class="pltable' . ($rstate->hascolors ? " pltable-colored" : "") . '">' . $tfoot . "</tfoot>\n";
        }

        $rstate->rows = $body;
        return $rstate;
    }

    /** @param array{list?:bool,attributes?:array,fold_session_prefix?:string,noheader?:bool,fullheader?:bool,nofooter?:bool,footer_extra?:string,live?:bool} $options
     * @return PaperListTableRender */
    function table_render($options = []) {
        $overrides = $this->user->remove_overrides(Contact::OVERRIDE_CONFLICT);
        $rstate = $this->_table_render($options);
        $this->user->set_overrides($overrides);
        return $rstate;
    }

    /** @param array{list?:bool,attributes?:array,fold_session_prefix?:string,noheader?:bool,fullheader?:bool,nofooter?:bool,footer_extra?:string,live?:bool} $options
     * @return string */
    function table_html($options = []) {
        $render = $this->table_render($options);
        if ($render->error) {
            return $render->error;
        } else {
            return $render->table_start
                . (self::$include_stash ? Ht::unstash() : "")
                . ($render->thead ? : "")
                . $render->tbody_start()
                . $render->body_rows()
                . $render->tbody_end()
                . ($render->tfoot ? : "")
                . "</table>";
        }
    }

    /** @param string $fields
     * @return array{fields:array<string,object>,data:array<int,array{id:int}>,attr?:array,stat?:array} */
    function column_json($fields) {
        // get column list, check sort
        $this->_prepare();
        $this->parse_view($fields, null);
        $field_list = $this->_columns();
        if (empty($field_list)) {
            return ["fields" => [], "data" => []];
        }

        // turn off forceShow
        $overrides = $this->user->remove_overrides(Contact::OVERRIDE_CONFLICT);

        // output field data
        $data = $attr = [];
        foreach ($this->rowset() as $row) {
            $this->_row_setup($row);
            $p = ["id" => $row->paperId];
            foreach ($field_list as $fdef) {
                if (($content = $this->_column_html($fdef, $row)) !== "") {
                    $p[$fdef->name] = $content;
                }
            }
            $data[$row->paperId] = $p;
            foreach ($this->row_attr as $k => $v) {
                if (!isset($attr[$row->paperId])) {
                    $attr[$row->paperId] = [];
                }
                $attr[$row->paperId][$k] = $v;
            }
        }

        // analyze `has`, including authors
        foreach ($field_list as $fdef) {
            $this->mark_has($fdef->name, $fdef->has_content);
        }

        // output fields and statistics
        $fields = $stats = [];
        foreach ($field_list as $fdef) {
            $fields[$fdef->name] = $fdef->field_json($this);
            if ($fdef->has_statistics()) {
                $stat = [];
                foreach (self::$stats as $s) {
                    $stat[ScoreInfo::$stat_keys[$s]] = $fdef->statistic_html($this, $s);
                }
                $stats[$fdef->name] = $stat;
            }
        }

        // restore forceShow
        $this->user->set_overrides($overrides);

        // output
        $result = ["fields" => $fields, "data" => $data];
        if (!empty($attr)) {
            $result["attr"] = $attr;
        }
        if (!empty($stats)) {
            $result["stat"] = $stats;
        }
        return $result;
    }

    /** @param string $fields
     * @return array<int,object> */
    function text_json($fields) {
        // get column list, check sort
        $this->_prepare();
        $this->parse_view($fields, null);
        $field_list = $this->_columns();
        $data = [];
        if (!empty($field_list)) {
            foreach ($this->rowset() as $row) {
                $this->_row_setup($row);
                $p = ["id" => $row->paperId];
                foreach ($field_list as $fdef) {
                    if (!$fdef->content_empty($this, $row)
                        && ($text = $fdef->text($this, $row)) !== "") {
                        $p[$fdef->name] = $text;
                    }
                }
                $data[$row->paperId] = (object) $p;
            }
        }
        return $data;
    }

    /** @return array<string,string> */
    private function _row_text_csv_data(PaperInfo $row, $fieldDef) {
        $csv = [];
        foreach ($fieldDef as $fdef) {
            $empty = $fdef->content_empty($this, $row);
            $c = $empty ? "" : $fdef->text($this, $row);
            if ($c !== "") {
                $fdef->has_content = true;
            }
            $csv[$fdef->name] = $c;
        }
        return $csv;
    }

    private function _groups_for_csv($grouppos, &$csv) {
        for (; $grouppos < count($this->_groups)
               && $this->_groups[$grouppos]->pos < $this->count;
               ++$grouppos) {
            $ginfo = $this->_groups[$grouppos];
            $csv["__precomment__"] = $ginfo->is_empty() ? "none" : $ginfo->heading;
        }
        return $grouppos;
    }

    /** @return array{array<string,string>,list<array<string,string>>} */
    function text_csv($options = []) {
        // get column list, check sort
        $this->_prepare();
        $field_list = $this->_columns(); /* XXX */

        // get field array
        $fieldDef = [];
        foreach ($field_list as $fdef) {
            if ($fdef->header($this, true) != "") {
                $fieldDef[] = $fdef;
            }
        }

        // collect row data
        $body = [];
        $grouppos = empty($this->_groups) ? -1 : 0;
        foreach ($this->rowset() as $row) {
            $this->_row_setup($row);
            $csv = $this->_row_text_csv_data($row, $fieldDef);
            if ($grouppos >= 0) {
                $grouppos = $this->_groups_for_csv($grouppos, $csv);
            }
            $body[] = $csv;
        }

        // header cells
        $header = [];
        foreach ($fieldDef as $fdef) {
            if ($fdef->has_content) {
                $header[$fdef->name] = $fdef->header($this, true);
            }
        }

        return [$header, $body];
    }
}
