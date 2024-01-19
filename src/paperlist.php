<?php
// paperlist.php -- HotCRP helper class for producing paper lists
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class PaperListTableRender {
    /** @var string */
    public $thead = "";
    /** @var ?string */
    public $tbody_class;
    /** @var list<string> */
    public $rows;
    /** @var ?string */
    public $tfoot;
    /** @var ?string */
    public $error;

    /** @var int */
    public $ncol = 0;

    /** @var int */
    public $titlecol = -1;
    /** @var int */
    public $selector_col = -1;

    /** @var int */
    public $colorindex = 0;
    /** @var bool */
    public $hascolors = false;
    /** @var string */
    public $last_trclass = "";
    /** @var list<int> */
    public $groupstart = [0];

    /** @param list<PaperColumn> $vcolumns */
    function __construct($vcolumns) {
        foreach ($vcolumns as $fdef) {
            if (!$fdef->has_content || $fdef->as_row) {
                continue;
            }
            if ($fdef->name === "title") {
                $this->titlecol = $this->ncol;
            } else if ($fdef->name === "sel") {
                $this->selector_col = $this->ncol;
            }
            ++$this->ncol;
        }
    }
    /** @param string $error
     * @return PaperListTableRender */
    static function make_error($error) {
        $tr = new PaperListTableRender([]);
        $tr->error = $error;
        return $tr;
    }
    /** @return int */
    function group_count() {
        return count($this->groupstart) - 1;
    }
    /** @param int $groupno
     * @param string $heading
     * @param array<string,mixed> $attr
     * @return string */
    function heading_row($groupno, $heading, $attr) {
        if (!$heading) {
            return "  <tr class=\"plheading\"><td class=\"plheading-blank\" colspan=\"{$this->ncol}\"></td></tr>\n";
        }
        $x = "  <tr class=\"plheading\"";
        foreach ($attr as $k => $v) {
            if ($k !== "no_titlecol" && $k !== "tdclass")
                $x .= " {$k}=\"" . htmlspecialchars($v) . "\"";
        }
        $x .= ">";
        $tdclass = Ht::add_tokens("plheading", $attr["tdclass"] ?? null);
        $colpos = 0;
        if (!($attr["no_titlecol"] ?? false)) {
            if ($this->selector_col >= 0
                && ($this->titlecol < 0 || $this->titlecol > $this->selector_col)) {
                if ($this->selector_col > 0) {
                    $x .= "<td class=\"plheading-spacer\" colspan=\"{$this->selector_col}\"></td>";
                }
                $x .= "<td class=\"pl plheading pl_sel\">" . Selector_PaperColumn::group_content($groupno) . "</td>";
                $colpos = $this->selector_col + 1;
            }
            if ($this->titlecol >= 0) {
                if ($colpos < $this->titlecol) {
                    $n = $this->titlecol - $colpos;
                    $x .= "<td class=\"plheading-spacer\" colspan=\"{$n}\"></td>";
                }
                $colpos = $this->titlecol;
            }
        }
        $n = $this->ncol - $colpos;
        return "{$x}<td class=\"{$tdclass}\" colspan=\"{$n}\">{$heading}</td></tr>\n";
    }
    /** @return string */
    function heading_separator_row() {
        return "  <tr class=\"plheading\"><td class=\"plheading-separator\" colspan=\"{$this->ncol}\"></td></tr>\n";
    }
    /** @param array $attr */
    static function print_attributes($attr) {
        foreach ($attr as $k => $v) {
            if (is_array($v) || is_object($v)) {
                $v = $k === "class" ? join(" ", $v) : json_encode_browser($v);
            }
            if ($k === "data-fields" || $k === "data-groups" || $k === "data-columns" /* XXX backwards compat */) {
                $v = str_replace("'", "&apos;", htmlspecialchars($v, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5));
                echo " ", $k, "='", $v, "'";
            } else {
                echo " ", $k, "=\"", htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5), "\"";
            }
        }
    }
    /** @param array<string,mixed> $attr
     * @param bool $unstash */
    function print_table_start($attr, $unstash) {
        echo '<table';
        self::print_attributes($attr);
        echo '>', $unstash ? Ht::unstash() : "", $this->thead, $this->tbody_start();
    }
    /** @return string */
    function tbody_start() {
        return "  <tbody class=\"{$this->tbody_class}\">\n";
    }
    /** @param int $gi
     * @param int $gj */
    function print_tbody_rows($gi, $gj) {
        $j = $this->groupstart[$gj];
        for ($i = $this->groupstart[$gi]; $i !== $j; ++$i) {
            echo $this->rows[$i];
        }
    }
    function print_table_end() {
        echo "  </tbody>\n", $this->tfoot ?? "", "</table>";
    }
    /** @return bool */
    function is_empty() {
        return empty($this->rows);
    }
}

class PaperListReviewAnalysis {
    /** @var PaperInfo */
    private $prow;
    /** @var ReviewInfo */
    public $rrow;
    /** @param ReviewInfo $rrow */
    function __construct($rrow, PaperInfo $prow) {
        $this->prow = $prow;
        $this->rrow = $rrow;
    }
    /** @param bool $includeLink
     * @return string */
    function icon_html($includeLink) {
        $t = $this->rrow->icon_h();
        if ($includeLink) {
            $t = $this->wrap_link($t);
        }
        return $t . $this->rrow->round_h();
    }
    /** @return string */
    function icon_text() {
        $x = "";
        if ($this->rrow->reviewType) {
            $x = ReviewForm::$revtype_names[$this->rrow->reviewType] ?? "";
        }
        if ($x !== "" && $this->rrow->reviewRound > 0) {
            $x .= ":" . $this->rrow->conf->round_name($this->rrow->reviewRound);
        }
        return $x;
    }
    /** @param string $html
     * @param ?string $klass
     * @return string */
    function wrap_link($html, $klass = null) {
        if ($this->rrow->reviewStatus >= ReviewInfo::RS_COMPLETED) {
            $href = $this->prow->hoturl(["#" => "r" . $this->rrow->unparse_ordinal_id()]);
        } else {
            $href = $this->prow->reviewurl(["r" => $this->rrow->unparse_ordinal_id()]);
        }
        $k = $klass ? " class=\"{$klass}\"" : "";
        return "<a{$k} href=\"{$href}\">{$html}</a>";
    }
}

class PaperList {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var XtParams
     * @readonly */
    public $xtp;
    /** @var Tagger
     * @readonly */
    public $tagger;
    /** @var PaperSearch
     * @readonly */
    public $search;
    /** @var Qrequest
     * @readonly */
    private $qreq;
    /** @var Contact */
    private $_reviewer_user;
    /** @var ?PaperInfoSet */
    private $_rowset;
    /** @var ?array<int,int> */
    private $_then_map;
    /** @var ?array<int,list<string>> */
    private $_highlight_map;
    /** @var list<TagAnno> */
    private $_groups;

    /** @var bool */
    private $_sortable;
    /** @var ?string */
    private $_paper_linkto;
    /** @var bool */
    private $_view_facets = false;
    /** @var bool */
    private $_view_force = false;
    /** @var int */
    private $_view_hide_all = 0;
    /** @var array<string,int> */
    private $_viewf = [];
    /** @var array<string,?list<string>> */
    private $_view_decorations = [];
    /** @var array<string,int> */
    private $_view_order = [];
    /** @var int */
    private $_view_order_next = 1;

    const VIEWORIGIN_NONE = -1;
    const VIEWORIGIN_REPORT = 0;
    const VIEWORIGIN_DEFAULT_DISPLAY = 1;
    const VIEWORIGIN_SESSION = 2;
    const VIEWORIGIN_SEARCH = 3;
    const VIEWORIGIN_REQUEST = 4;
    const VIEWORIGIN_MAX = 5;
    const VIEW_ORIGINMASK = 15;
    const VIEW_ORIGINSHIFT = 4;
    const VIEW_SHOW = 0x10000;
    // bits 0-3: maximum known origin
    // bit 4 + 2o: set iff origin `o` defined a view
    // bit 5 + 2o: set iff origin `o` wanted show
    // bit 16: whether to show according to maximum known origin

    /** @var ?string */
    private $_table_id;
    /** @var ?string */
    private $_table_class;
    /** @var string */
    private $_report_id;
    /** @var ?string */
    private $_row_id_pattern;
    /** @var ?SearchSelection */
    private $_selection;
    /** @var callable(PaperList,PaperInfo):bool */
    private $_row_filter;
    /** @var int */
    private $_table_decor = 0;
    /** @var ?string */
    private $_table_fold_session;
    /** @var ?callable */
    private $_footer_filter;
    /** @var ?array<string,mixed> */
    private $_action_submit_attr;

    /** @var list<PaperColumn> */
    private $_vcolumns = [];
    /** @var array<string,list<PaperColumn>> */
    private $_columns_by_name;
    /** @var ?string */
    private $_finding_column;
    /** @var ?list<MessageItem> */
    private $_finding_column_errors;

    /** @var list<PaperColumn> */
    private $_sortcol = [];
    /** @var list<int> */
    private $_sort_origin = [];
    /** @var int */
    private $_sortcol_fixed = 0;
    /** @var ?string */
    private $_sort_etag;
    /** @var ?string */
    private $_score_sort;

    // columns access
    public $qopts; // set by PaperColumn::prepare
    /** @var int
     * @readonly */
    public $render_context;
    /** @var array<string,string|list<string>> */
    public $table_attr;
    /** @var array */
    public $row_attr;
    /** @var bool */
    public $row_overridable;
    /** @var 0|1|2 */
    public $overriding = 0;
    /** @var string */
    public $row_tags;
    /** @var string */
    public $row_tags_override;
    /** @var ?string */
    public $column_class;
    /** @var bool */
    public $need_render;
    /** @var bool */
    public $has_editable_tags = false;
    /** @var ?CheckFormat */
    public $check_format;

    // collected during render
    /** @var int */
    public $count; // exported to caller and columns; equals 1 more than row index
    /** @var ?array<string,bool> */
    private $_has;
    /** @var int */
    private $_bulkwarn_count;

    /** @var bool */
    static public $include_stash = true;

    static private $stats = [ScoreInfo::SUM, ScoreInfo::MEAN, ScoreInfo::MEDIAN, ScoreInfo::STDDEV_P, ScoreInfo::COUNT];

    /** @param array{sort?:true|string} $args
     * @param null|array|Qrequest $qreq */
    function __construct(string $report, PaperSearch $search, $args = [], $qreq = null) {
        $this->conf = $search->conf;
        $this->user = $search->user;
        $this->xtp = new XtParams($this->conf, $this->user);
        $this->xtp->primitive_checkers[] = [$this, "list_checker"];
        $this->xtp->reflags = "i";
        $this->xtp->paper_list = $this;
        if (!$qreq || !($qreq instanceof Qrequest)) {
            $qreq = new Qrequest("GET", $qreq);
            $qreq->set_user($this->user);
        }
        $this->qreq = $qreq;
        $this->search = $search;
        $this->_reviewer_user = $search->reviewer_user();
        $this->_rowset = $args["rowset"] ?? null;

        if (in_array($qreq->linkto, ["paper", "assign", "paperedit", "finishreview"])) {
            $this->set_view("linkto", true, self::VIEWORIGIN_REQUEST, [$qreq->linkto]);
        }

        $this->tagger = new Tagger($this->user);

        $this->qopts = $this->search->simple_search_options();
        if ($this->qopts === false) {
            $this->qopts = ["paperId" => $this->search->paper_ids()];
        }
        $this->qopts["scores"] = [];

        $this->_report_id = $report;
        $this->parse_view($this->_list_columns(), self::VIEWORIGIN_REPORT);

        $sortarg = $args["sort"] ?? false;
        if (($this->_sortable = $sortarg !== false)) {
            // XXX `"sort" => true` imports sort information from qreq
            if (is_string($sortarg)) {
                $st = trim($sortarg);
            } else if ($sortarg === true) {
                if (isset($qreq->scoresort)
                    && ($ss = ScoreInfo::parse_score_sort($qreq->scoresort))) {
                    $this->parse_view("sort:score[{$ss}]", self::VIEWORIGIN_REQUEST);
                }
                $st = trim($qreq->sort ?? "");
            } else {
                $st = "";
            }
            if ($st !== "") {
                $this->parse_view("sort:[{$st}]", self::VIEWORIGIN_REQUEST);
            }
        }

        if ($this->search->then_term()) {
            $this->_then_map = $this->search->groups_by_paper_id();
            $this->_highlight_map = $this->search->highlights_by_paper_id();
        }
        $qe = $this->search->main_term();
        foreach (PaperSearch::view_generator($qe->view_anno()) as $sve) {
            if (($show_action = $sve->show_action())) {
                $this->set_view($sve->keyword, $show_action, self::VIEWORIGIN_SEARCH, $sve->decorations);
            }
        }

        if ($qreq->forceShow !== null) {
            $this->set_view("force", !!$qreq->forceShow, self::VIEWORIGIN_REQUEST);
        }
        if ($qreq->selectall && !isset($this->_view_decorations["sel"])) {
            $this->_view_decorations["sel"] = ["selected"];
        }

        $this->_columns_by_name = ["anonau" => [], "aufull" => [], "rownum" => [], "statistics" => []];
    }

    /** @return ?bool */
    function list_checker($e, $xt, $xtp) {
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
            return "id title status revtype linkto[finishreview]";
        case "pl":
            return "sel id title status revtype revstat";
        case "reqrevs":
            return "sel[selected] id title status revdelegation revstat";
        case "reviewAssignment":
            return "id title desirability topicscore mypref assignment potentialconflict topics reviewers linkto[assign]";
        case "conflictassign":
            return "id title authors aufull potentialconflict revtype[simple] editconf[simple,pin=conflicted] linkto[assign]";
        case "conflictassign:neg":
            return "id title authors aufull potentialconflict revtype[simple] editconf[simple,pin=unconflicted] linkto[assign]";
        case "pf":
            return "sel id title status revtype topicscore editmypref[topicscore]";
        case "reviewers":
            return "sel[selected] id title status linkto[assign]";
        case "reviewersSel":
            return "sel id title status linkto[assign]";
        default:
            return "";
        }
    }


    /** @return ?string */
    function table_id() {
        return $this->_table_id;
    }

    /** @param ?string $table_id
     * @param ?string $table_class
     * @param ?string $row_id_pattern
     * @return $this */
    function set_table_id_class($table_id, $table_class, $row_id_pattern = null) {
        $this->_table_id = $table_id;
        $this->_table_class = $table_class;
        $this->_row_id_pattern = $row_id_pattern;
        return $this;
    }

    const DECOR_NONE = 0;
    const DECOR_HEADER = 1;
    const DECOR_EVERYHEADER = 2;
    const DECOR_FOOTER = 4;
    const DECOR_STATISTICS = 8;
    const DECOR_LIST = 16;
    const DECOR_FULLWIDTH = 32;
    /** @return int */
    function table_decor() {
        return $this->_table_decor;
    }

    /** @param int $decor
     * @return $this */
    function set_table_decor($decor) {
        $this->_table_decor = $decor;
        return $this;
    }

    /** @param ?string $prefix
     * @return $this */
    function set_table_fold_session($prefix) {
        $this->_table_fold_session = $prefix;
        return $this;
    }

    /** @param callable(PaperList,PaperInfo):bool $filter
     * @return $this */
    function set_row_filter($filter) {
        $this->_row_filter = $filter;
        return $this;
    }

    /** @param callable $action_filter
     * @return $this */
    function set_footer_filter($action_filter) {
        $this->_footer_filter = $action_filter;
        return $this;
    }

    /** @param ?array $attr
     * @return $this */
    function set_action_submit_attr($attr) {
        $this->_action_submit_attr = $attr;
        return $this;
    }

    /** @return MessageSet */
    function message_set() {
        return $this->search->message_set();
    }

    /** @return string */
    function siteurl() {
        return $this->qreq->navigation()->siteurl();
    }

    /** @param PaperColumn $col
     * @param ?string $default_name */
    function add_column(PaperColumn $col, $default_name = null) {
        $decor = $this->_view_decorations[$col->name] ?? null;
        $col->view_order = $this->_view_order[$col->name] ?? null;
        if ($default_name) {
            $decor = $decor ?? $this->_view_decorations[$default_name] ?? null;
            $col->view_order = $col->view_order ?? $this->_view_order[$default_name] ?? null;
        }
        if ($decor) {
            $col->add_decorations($decor);
        }
        $this->_columns_by_name[$col->name][] = $col;
    }

    static private $view_synonym = [
        "au" => "authors",
        "author" => "authors",
        "kanban" => "facets",
        "rownumbers" => "rownum",
        "stat" => "statistics",
        "stats" => "statistics",
        "totals" => "statistics"
    ];

    static private $view_fake = [
        "anonau" => 150, "aufull" => 150, "force" => 180, "score" => 190,
        "facets" => -2, "rownum" => -1, "statistics" => -1,
        "all" => -4, "linkto" => -4,
    ];


    /** @param string $fname
     * @return bool */
    function viewing($fname) {
        $fname = self::$view_synonym[$fname] ?? $fname;
        return ($this->_viewf[$fname] ?? 0) >= self::VIEW_SHOW;
    }

    /** @param string $k
     * @return 0|1|2|3|4|5 */
    function view_origin($k) {
        $k = self::$view_synonym[$k] ?? $k;
        return ($this->_viewf[$k] ?? 0) & self::VIEW_ORIGINMASK;
    }

    /** @param string $k
     * @return bool */
    function want_column_errors($k) {
        $origin = $this->view_origin($k);
        return $origin === self::VIEWORIGIN_SEARCH
            || $origin === self::VIEWORIGIN_MAX;
    }

    /** @param int $v
     * @param 0|1|2|3|4|5 $origin
     * @return bool */
    static private function view_showing_at($v, $origin) {
        assert($origin >= self::VIEWORIGIN_NONE && $origin <= self::VIEWORIGIN_MAX);
        if ($origin < 0) {
            return false;
        } else if ($origin >= self::VIEWORIGIN_MAX) {
            return ($v & self::VIEW_SHOW) !== 0;
        } else {
            $originmask = 1 << (self::VIEW_ORIGINSHIFT + 2 * $origin);
            while ($origin >= 0 && ($v & $originmask) === 0) {
                --$origin;
                $originmask >>= 2;
            }
            return $origin >= 0 && ($v & ($originmask << 1)) !== 0;
        }
    }

    /** @param 0|1|2|3|4|5 $origin */
    private function _set_view_hide_all($origin) {
        $views = array_keys($this->_viewf);
        foreach ($views as $k) {
            if ($k !== "sel" && $k !== "statistics") {
                $this->set_view($k, false, $origin, null);
            }
        }
        $this->_view_hide_all = $origin;
        $this->_view_order = [];
        $this->_view_order_next = 1;
    }

    /** @param string $k
     * @param 'show'|'hide'|'edit'|bool $v
     * @param 0|1|2|3|4|5 $origin
     * @param ?list<string> $decorations */
    function set_view($k, $v, $origin, $decorations = null) {
        $origin = $origin ?? self::VIEWORIGIN_MAX;
        assert($origin >= self::VIEWORIGIN_REPORT && $origin <= self::VIEWORIGIN_MAX);
        if ($v === "show" || $v === "hide") {
            $v = $v === "show";
        }
        assert(is_bool($v) || $v === "edit");

        if ($k !== "" && $k[0] === "\"" && $k[strlen($k) - 1] === "\"") {
            $k = substr($k, 1, -1);
        }
        $k = self::$view_synonym[$k] ?? $k;

        // process `hide:all`
        if ($k === "all") {
            if ($v === false && $origin >= $this->_view_hide_all) {
                $this->_set_view_hide_all($origin);
            }
            return;
        }

        // ignore session values of `force`
        if ($k === "force" && $origin === self::VIEWORIGIN_SESSION) {
            return;
        }

        // track view order
        if ($origin === $this->_view_hide_all) {
            if (!$v) {
                unset($this->_view_order[$k]);
            } else if (!isset($this->_view_order[$k])) {
                $this->_view_order[$k] = $this->_view_order_next;
                ++$this->_view_order_next;
            }
        }

        $flags = &$this->_viewf[$k];
        $flags = $flags ?? 0;
        $originbit = self::VIEW_ORIGINSHIFT + 2 * $origin;
        $flags = ($flags & ~(2 << $originbit)) | (($v ? 3 : 1) << $originbit);
        if (($flags & self::VIEW_ORIGINMASK) > $origin
            || ($v && $this->_view_hide_all > $origin)) {
            return;
        }
        $flags = ($flags & ~(self::VIEW_ORIGINMASK | self::VIEW_SHOW))
            | $origin
            | ($v ? self::VIEW_SHOW : 0);
        if ($v === "edit") {
            $decorations[] = "edit";
        }
        if (!empty($decorations)) {
            $this->_view_decorations[$k] = $decorations;
        } else {
            unset($this->_view_decorations[$k]);
        }

        if ($k === "force") {
            $this->_view_force = $v;
        } else if ($k === "facets") {
            $this->_view_facets = $v;
        } else if ($k === "linkto") {
            if (!empty($decorations)
                && in_array($decorations[0], ["paper", "paperedit", "assign", "finishreview"])) {
                $this->_paper_linkto = $decorations[0];
            }
        } else if (($k === "aufull" || $k === "anonau")
                   && $origin >= self::VIEWORIGIN_SEARCH
                   && $v
                   && $this->view_origin("authors") < $origin) {
            $this->set_view("authors", true, $origin, null);
        }
    }


    /** @param PaperColumn $col
     * @param 0|1|2|3|4|5 $origin */
    private function _append_sortcol($col, $origin) {
        $i = count($this->_sortcol);
        while ($i > 0 && $this->_sort_origin[$i - 1] < $origin) {
            --$i;
        }
        array_splice($this->_sortcol, $i, 0, [$col]);
        array_splice($this->_sort_origin, $i, 0, [$origin]);
    }

    /** @param string $k
     * @param 0|1|2|3|4|5 $origin
     * @param ?list<string> $decorations
     * @param ?list<int> $sort_subset
     * @param ?int $pos1
     * @param ?int $pos2 */
    private function _add_sorter($k, $origin, $decorations,
                                 $sort_subset, $pos1, $pos2) {
        // `sort:score` is a special case.
        if ($k === "score") {
            $flags = &$this->_viewf[$k];
            $flags = $flags ?? 0;
            if (($flags & self::VIEW_ORIGINMASK) <= $origin
                && !empty($decorations)
                && ($x = ScoreInfo::parse_score_sort($decorations[0])) !== null) {
                $flags = ($flags & ~self::VIEW_ORIGINMASK) | $origin;
                $this->_score_sort = $x;
            }
            return;
        }

        assert($this->_sortcol_fixed < 2);
        // Do not use ensure_columns_by_name(), because decorations for sorters
        // might differ.
        $fs = $this->conf->paper_columns($k, $this->xtp);
        $mi = null;
        if (count($fs) === 1) {
            $col = PaperColumn::make($this->conf, $fs[0])->add_decorations($decorations);
            if ($col->prepare($this, PaperColumn::PREP_SORT)
                && $col->sort) {
                $col->sort_subset = $sort_subset;
                $this->_append_sortcol($col, $origin);
            } else {
                $mi = $this->search->warning("<0>‘{$k}’ cannot be sorted");
            }
        } else if (empty($fs)) {
            if ($this->user->can_view_tags(null)
                && ($tagger = new Tagger($this->user))
                && ($tag = $tagger->check($k))
                && ($ps = new PaperSearch($this->user, ["q" => "#{$tag}", "t" => "vis"]))
                && $ps->paper_ids()) {
                $mi = $this->search->warning("<0>‘{$k}’ cannot be sorted; did you mean “sort:#{$k}”?");
            } else {
                $mi = $this->search->warning("<0>‘{$k}’ cannot be sorted");
            }
        } else {
            $mi = $this->search->warning("<0>Sort ‘{$k}’ is ambiguous");
        }
        if ($mi) {
            $mi->pos1 = $pos1;
            $mi->pos2 = $pos2;
            $mi->context = $this->search->q;
        }
    }

    /** @param ?string $str
     * @param 0|1|2|3|4|5 $origin */
    function parse_view($str, $origin) {
        $groups = SearchSplitter::split_balanced_parens($str ?? "");
        foreach (PaperSearch::view_generator($groups) as $sve) {
            if (($show_action = $sve->show_action())) {
                $this->set_view($sve->keyword, $show_action, $origin, $sve->decorations);
            }
            if ($sve->sort_action()) {
                $this->_add_sorter($sve->keyword, $origin, $sve->decorations,
                                   null, $sve->pos1, $sve->pos2);
            }
        }
    }

    /** @return string */
    function unparse_baseline_view() {
        if ($this->_report_id === "pl"
            && ($f = $this->conf->review_form()->default_highlighted_score())) {
            return "show:" . $f->search_keyword();
        }
        return "";
    }

    /** @param 0|1|2|3|4|5 $origin */
    function apply_view_report_default($origin = self::VIEWORIGIN_DEFAULT_DISPLAY) {
        $s = null;
        if ($this->_report_id === "pl" || $this->_report_id === "pf") {
            $s = $this->conf->setting_data("{$this->_report_id}display_default");
        }
        $this->parse_view($s ?? $this->unparse_baseline_view(), $origin);
    }

    function apply_view_session(Qrequest $qreq) {
        if ($this->_report_id === "pl" || $this->_report_id === "pf") {
            $s = $qreq->csession("{$this->_report_id}display");
            $this->parse_view($s, self::VIEWORIGIN_SESSION);
        }
    }

    function apply_view_qreq(Qrequest $qreq) {
        $x = [];
        foreach ($qreq as $k => $v) {
            if (str_starts_with($k, "show")) {
                $x[substr($k, 4)] = !!$v;
            } else if (str_starts_with($k, "has_show")) {
                $x[substr($k, 8)] = $x[substr($k, 8)] ?? false;
            } else if ($k === "forceShow") {
                $x["force"] = !!$v;
            }
        }
        foreach ($x as $name => $show) {
            $this->set_view($name, $show, self::VIEWORIGIN_REQUEST, $this->_view_decorations[$name] ?? null);
        }
    }

    /** @param -1|0|1|2|3|4|5 $base_origin
     * @param bool $include_sort
     * @return list<string> */
    function unparse_view($base_origin = self::VIEWORIGIN_NONE, $include_sort = true) {
        // show/hide
        $res = [];
        $nextpos = 1000000;
        foreach ($this->_viewf as $name => $v) {
            if (($v >= self::VIEW_SHOW) === self::view_showing_at($v, $base_origin)) {
                continue;
            }
            $pos = self::$view_fake[$name] ?? null;
            if ($pos === null) {
                $fs = $this->conf->paper_columns($name, $this->xtp);
                if (count($fs) && isset($fs[0]->order)) {
                    $pos = $fs[0]->order;
                    $name = $fs[0]->name;
                } else {
                    $pos = $nextpos++;
                }
            }
            $key = "{$pos} {$name}";
            $kw = $v >= self::VIEW_SHOW ? "show" : "hide";
            $res[$key] = PaperSearch::unparse_view($kw, $name, $this->_view_decorations[$name] ?? null);
        }
        if (((($this->_viewf["anonau"] ?? 0) >= self::VIEW_SHOW && $this->conf->submission_blindness() == Conf::BLIND_OPTIONAL)
             || ($this->_viewf["aufull"] ?? 0) >= self::VIEW_SHOW)
            && ($this->_viewf["authors"] ?? 0) < self::VIEW_SHOW) {
            $res["150 authors"] = "hide:authors";
        }
        ksort($res, SORT_NATURAL);
        $res = array_values($res);

        // sorters
        if ($include_sort) {
            foreach ($this->sorters() as $i => $s) {
                if ($this->_sort_origin[$i] <= $base_origin) {
                    break;
                }
                $res[] = PaperSearch::unparse_view("sort", $s->name, $s->decorations());
                if ($s->name === "id") {
                    break;
                }
            }
            while (!empty($res) && $res[count($res) - 1] === "sort:id") {
                array_pop($res);
            }
        }

        // score sort
        if ($this->_score_sort
            && ($base_origin < 0 || $this->_score_sort !== self::default_score_sort($this->conf))) {
            $res[] = PaperSearch::unparse_view("sort", "score", [$this->_score_sort]);
        }

        return $res;
    }


    /** @return PaperInfoSet|Iterable<PaperInfo> */
    function unordered_rowset() {
        if ($this->_rowset === null) {
            $this->_rowset = $this->conf->paper_set($this->qopts, $this->user);
        }
        return $this->_rowset;
    }

    /** @return PaperInfoSet|Iterable<PaperInfo> */
    function rowset() {
        $rowset = $this->unordered_rowset();
        if ($this->_groups === null) {
            $this->_sort($rowset);
        }
        return $rowset;
    }

    /** @param PaperInfo $a
     * @param PaperInfo $b
     * @return int */
    function _sort_compare($a, $b) {
        foreach ($this->_sortcol as $s) {
            if (($x = $s->compare($a, $b, $this))) {
                return ($x < 0) === $s->sort_descending ? 1 : -1;
            }
        }
        return $a->paperId <=> $b->paperId;
    }

    /** @param PaperInfo $a
     * @param PaperInfo $b
     * @return int */
    function _then_sort_compare($a, $b) {
        if (($x = $a->_search_group <=> $b->_search_group)) {
            return $x;
        }
        foreach ($this->_sortcol as $s) {
            if (($s->sort_subset === null || in_array($a->_search_group, $s->sort_subset))
                && ($x = $s->compare($a, $b, $this))) {
                return ($x < 0) === $s->sort_descending ? 1 : -1;
            }
        }
        return $a->paperId <=> $b->paperId;
    }

    /** @param ?list<int> $sort_subset */
    private function _add_search_sorters(SearchTerm $qe, $sort_subset) {
        $nsortcol = count($this->_sortcol);
        foreach (PaperSearch::view_generator($qe->view_anno()) as $sve) {
            if ($sve->sort_action()) {
                $this->_add_sorter($sve->keyword, PaperList::VIEWORIGIN_SEARCH, $sve->decorations, $sort_subset, $sve->pos1, $sve->pos2);
            }
        }
        if (count($this->_sortcol) === $nsortcol
            && ($dspc = $qe->default_sort_column(true, $this))
            && $dspc->prepare($this, PaperColumn::PREP_SORT)) {
            assert($dspc->sort > 0);
            $dspc->sort_subset = $sort_subset;
            $this->_append_sortcol($dspc, PaperList::VIEWORIGIN_SEARCH);
        }
    }

    /** @return non-empty-list<PaperColumn> */
    function sorters() {
        assert($this->_sortcol_fixed !== 1);
        if ($this->_sortcol_fixed === 0) {
            $this->_sortcol_fixed = 1;
            // apply sorters from search terms
            if (($thenqe = $this->search->then_term())) {
                foreach ($thenqe->subset_terms() as $chrange) {
                    $this->_add_search_sorters($chrange[0], $chrange[1]);
                }
            }
            $this->_add_search_sorters($this->search->main_term(), null);
            // final default sorter
            if (empty($this->_sortcol)) {
                $idcol = ($this->ensure_columns_by_name("id"))[0];
                $this->_append_sortcol($idcol, self::VIEWORIGIN_REPORT);
            }
            // default editable tag
            $this->_sort_etag = "";
            if (!$thenqe
                && $this->_sortcol[0] instanceof Tag_PaperColumn
                && !$this->_sortcol[0]->sort_descending) {
                $this->_sort_etag = $this->_sortcol[0]->etag();
            }
            // done
            $this->_sortcol_fixed = 2;
        }
        return $this->_sortcol;
    }

    /** @return string */
    function sort_etag() {
        if ($this->_sortcol_fixed === 0) {
            $this->sorters();
        }
        return $this->_sort_etag;
    }

    /** @param Conf $conf
     * @return string */
    static function default_score_sort($conf) {
        return $conf->opt("defaultScoreSort") ?? "counts";
    }

    /** @return string */
    function score_sort() {
        return $this->_score_sort ?? self::default_score_sort($this->conf);
    }

    private function _sort(PaperInfoSet $rowset) {
        $this->_groups = []; // `_groups === null` means _sort has not been called

        // actually sort
        $overrides = $this->user->add_overrides($this->_view_force ? Contact::OVERRIDE_CONFLICT : 0);
        if ($this->_then_map) {
            foreach ($rowset as $row) {
                $row->_search_group = $this->_then_map[$row->paperId];
            }
        }
        foreach ($this->sorters() as $i => $s) {
            $s->prepare_sort($this, $i);
        }
        $rowset->sort_by([$this, $this->_then_map ? "_then_sort_compare" : "_sort_compare"]);
        $this->user->set_overrides($overrides);

        // clean up, assign groups
        if ($this->_sort_etag !== "") {
            $groups = $this->_sort_etag_anno_groups();
        } else {
            $groups = $this->search->paper_groups();
        }
        if (!empty($groups)) {
            $this->_collect_groups($rowset->as_list(), $groups);
        }
    }


    /** @return list<TagAnno> */
    private function _sort_etag_anno_groups() {
        assert($this->_then_map === null);
        $etag = $this->_sort_etag;
        if (str_starts_with($etag, $this->user->contactId . "~")) {
            $alt_etag = substr($etag, strlen((string) $this->user->contactId));
        } else {
            $alt_etag = "~~~";
        }
        $dt = $this->conf->tags()->ensure(Tagger::tv_tag($etag));
        if (!$dt->has_order_anno()) {
            $any = false;
            foreach (["#{$etag}", "#{$alt_etag}", "tagval:{$etag}", "tagval:{$alt_etag}"] as $x) {
                $any = $any || in_array("edit", $this->_view_decorations[$x] ?? []);
            }
            if (!$any) {
                return [];
            }
        }
        $this->_then_map = [];
        $groups = [];
        $aidx = $pidx = 0;
        $plist = $this->_rowset->as_list();
        $alist = $dt->order_anno_list();
        $ptagval = $pidx !== count($plist) ? $plist[$pidx]->tag_value($etag) : null;
        while ($aidx !== count($alist) || $pidx !== count($plist)) {
            if ($aidx !== count($alist)
                && $alist[$aidx]->tagIndex <= ($ptagval ?? TAG_INDEXBOUND)) {
                $groups[] = $alist[$aidx];
                ++$aidx;
            } else if (empty($groups)) {
                $groups[] = $ta = new TagAnno;
                $ta->tag = $dt->tag;
                $ta->heading = "none";
            } else {
                $this->_then_map[$plist[$pidx]->paperId] = count($groups) - 1;
                ++$pidx;
                $ptagval = $pidx !== count($plist) ? $plist[$pidx]->tag_value($etag) : null;
            }
        }
        return $groups;
    }

    /** @param list<PaperInfo> $plist
     * @param list<TagAnno> $groups */
    private function _collect_groups($plist, $groups) {
        $thenmap = $this->_then_map ?? [];
        $pidx = 0;
        for ($grouppos = 0;
             $pidx < count($plist) || $grouppos < count($groups);
             ++$grouppos) {
            $first_pidx = $pidx;
            while ($pidx < count($plist)
                   && ($thenmap[$plist[$pidx]->paperId] ?? 0) === $grouppos) {
                ++$pidx;
            }
            $ginfo = $groups[$grouppos] ?? null;
            assert($ginfo !== null);
            $ginfo = $ginfo ? clone $ginfo : TagAnno::make_empty();
            $ginfo->pos = $first_pidx;
            $ginfo->count = $pidx - $first_pidx;
            if ($ginfo->count === 0) {
                // leave off an empty “Untagged” section unless editing
                if ($ginfo->tag
                    && $ginfo->is_fencepost()
                    && !$this->has_editable_tags) {
                    continue;
                }
                // leave off empty blank sections
                if ($ginfo->is_blank()) {
                    continue;
                }
            }
            $this->_groups[] = $ginfo;
        }
    }

    /** @param bool $always
     * @return string */
    function sortdef($always = false) {
        $s0 = ($this->sorters())[0];
        if ($s0->sort_subset === null
            && ($always || (string) $this->qreq->sort != "")
            && ($sn = $s0->full_sort_name()) !== "id") {
            return $sn;
        } else {
            return "";
        }
    }

    /** @return string */
    function encoded_search_params() {
        $qp = $this->search->encoded_query_params();
        $s0 = ($this->sorters())[0];
        $sn = $s0->sort_subset === null ? $s0->full_sort_name() : "none";
        $sp = urlencode($sn);
        $rp = urlencode($this->_report_id);
        $fsp = $this->_view_force ? 1 : "";
        return "{$qp}&sort={$sp}&forceShow={$fsp}&report={$rp}";
    }


    /** @return $this */
    function set_selection(SearchSelection $ssel) {
        $this->_selection = $ssel;
        return $this;
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

    /** @param string $key
     * @return bool */
    private function _compute_has($key) {
        if ($key === "paper" || $key === "submission" || $key === "final") {
            $opt = $this->conf->options()->find($key);
            return $this->user->can_view_some_option($opt)
                && $this->unordered_rowset()->any(function ($row) use ($opt) {
                    return ($opt->id == DTYPE_SUBMISSION ? $row->paperStorageId : $row->finalPaperStorageId) > 1
                        && $this->user->can_view_option($row, $opt);
                });
        } else if (str_starts_with($key, "opt")
                   && ($opt = $this->conf->options()->find($key))) {
            return $this->user->can_view_some_option($opt)
                && $this->unordered_rowset()->any(function ($row) use ($opt) {
                    return ($ov = $row->option($opt))
                        && (!$opt->has_document() || $ov->value > 1)
                        && $this->user->can_view_option($row, $opt);
                });
        } else if ($key === "abstract") {
            $opt = $this->conf->option_by_id(PaperOption::ABSTRACTID);
            return $opt->test_can_exist()
                && $this->unordered_rowset()->any(function ($row) {
                    return $row->abstract() !== "";
                });
        } else if ($key === "authors") {
            return $this->unordered_rowset()->any(function ($row) {
                    return $this->user->allow_view_authors($row);
                });
        } else if ($key === "anonau") {
            return $this->has("authors")
                && $this->user->is_manager()
                && $this->unordered_rowset()->any(function ($row) {
                        return $this->user->allow_view_authors($row)
                           && !$this->user->can_view_authors($row);
                    });
        } else if ($key === "tags") {
            $overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
            $answer = $this->user->can_view_tags(null)
                && $this->unordered_rowset()->any(function ($row) {
                        return $this->user->can_view_tags($row)
                            && $row->sorted_viewable_tags($this->user) !== "";
                    });
            $this->user->set_overrides($overrides);
            return $answer;
        } else if ($key === "lead") {
            return $this->conf->has_any_lead_or_shepherd()
                && $this->unordered_rowset()->any(function ($row) {
                        return $row->leadContactId > 0
                            && $this->user->can_view_lead($row);
                    });
        } else if ($key === "shepherd") {
            return $this->conf->has_any_lead_or_shepherd()
                && $this->unordered_rowset()->any(function ($row) {
                        return $row->shepherdContactId > 0
                            && $this->user->can_view_shepherd($row);
                    });
        } else if ($key === "collab") {
            return $this->unordered_rowset()->any(function ($row) {
                return $row->has_nonempty_collaborators()
                    && $this->user->can_view_authors($row);
            });
        } else if ($key === "accepted") {
            return $this->unordered_rowset()->any(function ($row) {
                return $row->outcome > 0 && $this->user->can_view_decision($row);
            });
        } else if ($key === "need_final") {
            return $this->has("accepted")
                && $this->unordered_rowset()->any(function ($row) {
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


    /** @param string|MessageItem $message */
    function column_error($message) {
        if (!($name = $this->_finding_column)
            || !$this->want_column_errors($name)) {
            return;
        }
        if (is_string($message)) {
            $mi = new MessageItem($name, $message, MessageSet::WARNING);
        } else {
            $mi = $message;
        }
        if (($sve = $this->search->main_term()->view_anno_element($name))
            && ($mi->status !== MessageSet::INFORM || empty($this->_finding_column_errors))) {
            if ($mi->pos1 !== null) {
                $mis = $this->search->expand_message_context($mi, $mi->pos1 + $sve->pos1, $mi->pos2 + $sve->pos2, $sve->string_context);
            } else {
                $mis = $this->search->expand_message_context($mi, $sve->kwpos1, $sve->pos2, $sve->string_context);
            }
        } else {
            $mis = [$mi];
            $mi->pos1 = $mi->pos2 = null;
        }
        $this->_finding_column_errors = $this->_finding_column_errors ?? [];
        array_push($this->_finding_column_errors, ...$mis);
    }

    /** @param string $name
     * @return list<PaperColumn> */
    private function ensure_columns_by_name($name) {
        if (!array_key_exists($name, $this->_columns_by_name)) {
            $this->_finding_column = $name;
            $this->_columns_by_name[$name] = [];
            foreach ($this->conf->paper_columns($name, $this->xtp) as $fdef) {
                if ($fdef->name === $name
                    || !array_key_exists($fdef->name, $this->_columns_by_name)) {
                    $this->add_column(PaperColumn::make($this->conf, $fdef), $name);
                }
                if ($fdef->name !== $name) {
                    array_push($this->_columns_by_name[$name], ...$this->_columns_by_name[$fdef->name]);
                }
            }
            if (empty($this->_columns_by_name[$name])
                && $this->want_column_errors($name)) {
                if (empty($this->_finding_column_errors)) {
                    $this->column_error("<0>Field ‘{$name}’ not found");
                }
                foreach ($this->_finding_column_errors as $mi) {
                    $this->message_set()->append_item($mi);
                }
            }
            $this->_finding_column = $this->_finding_column_errors = null;
        }
        return $this->_columns_by_name[$name];
    }

    /** @param string $k
     * @return list<PaperColumn> */
    private function _expand_view_column($k) {
        if (!isset(self::$view_fake[$k])
            && ($this->_viewf[$k] ?? 0) >= self::VIEW_SHOW) {
            return $this->ensure_columns_by_name($k);
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
    function vcolumns() {
        return $this->_vcolumns;
    }

    /** @return -1|0|1 */
    static function vcolumn_order_compare($f1, $f2) {
        // see also script.js:vcolumn_order_compare
        if ($f1->as_row !== $f2->as_row) {
            return $f1->as_row ? 1 : -1;
        }
        $o1 = $f1->order ?? PHP_INT_MAX;
        $o2 = $f2->order ?? PHP_INT_MAX;
        if ($o1 !== $o2) {
            return $o1 <=> $o2;
        }
        return strnatcasecmp($f1->name, $f2->name);
    }

    /** @param int $context */
    private function _reset_vcolumns($context) {
        // reset
        $this->_has = [];
        $this->count = 0;
        $this->_bulkwarn_count = 0;
        $this->need_render = false;
        $this->_vcolumns = [];
        $this->table_attr = [];
        /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
        $this->render_context = $context;
        assert(empty($this->row_attr));

        // extract columns from _viewf
        $fs1 = $viewf = [];
        foreach ($this->_viewf as $k => $v) {
            foreach ($this->_expand_view_column($k) as $f) {
                assert($v >= self::VIEW_SHOW);
                $fs1[$f->name] = $fs1[$f->name] ?? $f;
                $viewf[$f->name] = $this->_viewf[$f->name] ?? $v;
            }
        }

        // update _viewf, prepare, mark fields editable
        $vcols1 = $vcols2 = [];
        foreach ($fs1 as $k => $f) {
            $this->_viewf[$k] = $viewf[$k];
            $f->is_visible = true;
            $f->has_content = false;
            $this->_finding_column = $k;
            if ($f->prepare($this, PaperColumn::PREP_VISIBLE)) {
                if ($f->view_order !== null) {
                    $vcols1[] = $f;
                } else {
                    $vcols2[] = $f;
                }
            }
        }

        // report `prepare` errors
        foreach ($this->_finding_column_errors ?? [] as $mi) {
            $this->message_set()->append_item($mi);
        }
        $this->_finding_column_errors = null;

        // arrange by view_order, then insert unordered elements
        usort($vcols1, function ($a, $b) {
            if ($a->as_row !== $b->as_row) {
                return $a->as_row ? 1 : -1;
            }
            return $a->view_order <=> $b->view_order
                ? : strnatcasecmp($a->name, $b->name);
        });
        foreach ($vcols2 as $vc) {
            $i1 = count($vcols1);
            while ($i1 > 0 && self::vcolumn_order_compare($vc, $vcols1[$i1-1]) < 0) {
                --$i1;
            }
            array_splice($vcols1, $i1, 0, [$vc]);
        }
        $this->_vcolumns = $vcols1;

        // analyze rows
        foreach ($this->_vcolumns as $f) {
            $f->reset($this);
        }
    }


    /** @param PaperInfo $row
     * @return string */
    function _contentDownload($row) {
        if ($row->paperStorageId > 1
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

    /** @param int $uid
     * @param ?PaperInfo $prow
     * @return string */
    function user_content($uid, $prow = null) {
        $u = $uid > 0 ? $this->conf->user_by_id($uid, USER_SLICE) : null;
        if (!$u) {
            return "";
        }
        $h = $this->user->reviewer_html_for($u);
        if ($prow && ($rrow = $prow->review_by_user($u))) {
            $h .= " " . $this->make_review_analysis($rrow, $prow)->icon_html(false);
        }
        return $h;
    }

    /** @param int $uid
     * @return string */
    function user_text($uid) {
        $u = $uid > 0 ? $this->conf->user_by_id($uid, USER_SLICE) : null;
        return $u ? $this->user->reviewer_text_for($u) : "";
    }

    /** @param int $uid1
     * @param int $uid2
     * @param int $ianno */
    function user_compare($uid1, $uid2, $ianno) {
        if ($uid1 <= 0 || $uid2 <= 0) {
            return ($uid2 > 0) <=> ($uid1 > 0);
        }
        $u1 = $this->conf->user_by_id($uid1, USER_SLICE);
        $u2 = $this->conf->user_by_id($uid2, USER_SLICE);
        if ($u1 && $u2 && $u1 !== $u2) {
            $as = Contact::get_sorter($u1, $ianno);
            $bs = Contact::get_sorter($u2, $ianno);
            return $this->conf->collator()->compare($as, $bs);
        } else {
            return $uid1 <=> $uid2;
        }
    }

    /** @param ReviewInfo $rrow
     * @return PaperListReviewAnalysis */
    function make_review_analysis($rrow, PaperInfo $row) {
        return new PaperListReviewAnalysis($rrow, $row);
    }


    /** @return 0|1|2|3 */
    function viewable_author_types() {
        // Bit 2: If set, then some authors may be plainly visible.
        // Bit 1: If set, then some authors may be visible through deblinding.
        $sb = $this->conf->submission_blindness();
        if ($this->search->limit_term()->is_author()
            || $sb === Conf::BLIND_NEVER
            || ($this->search->limit_term()->is_accepted()
                && $this->conf->time_all_author_view_decision()
                && !$this->conf->setting("seedec_hideau"))) {
            return 2;
        } else {
            $bits = $this->user->is_manager() ? 1 : 0;
            if ($this->user->is_reviewer()
                && $this->conf->time_some_reviewer_view_authors($this->user->isPC)) {
                $bits |= 2;
            }
            return $bits;
        }
    }

    /** @param string $main_content
     * @param string $override_content
     * @param 'div'|'span' $tag
     * @return string */
    function wrap_conflict($main_content, $override_content, $tag = "span") {
        if ($main_content === $override_content) {
            return $main_content;
        }
        if ((string) $main_content !== "") {
            $main_content = "<{$tag} class=\"fn5\">{$main_content}</{$tag}>";
        }
        if ((string) $override_content !== "") {
            $override_content = "<{$tag} class=\"fx5\">{$override_content}</{$tag}>";
        }
        return $main_content . $override_content;
    }

    /** @return string */
    private function _column_html(PaperColumn $fdef, PaperInfo $row) {
        assert(!!$fdef->is_visible);
        $override = $fdef->override;
        if ($override & PaperColumn::OVERRIDE_NONCONFLICTED) {
            $override &= ~PaperColumn::OVERRIDE_NONCONFLICTED;
        } else if (!$this->row_overridable) {
            $override = 0;
        }
        $this->column_class = null;
        $content = "";
        $content2 = null;
        if ($override <= 0) {
            if (!$fdef->content_empty($this, $row)) {
                $this->overriding = 0;
                $content = $fdef->content($this, $row);
            }
        } else if ($override === PaperColumn::OVERRIDE_BOTH) {
            if (!$fdef->content_empty($this, $row)) {
                $this->overriding = 1;
                $content = $fdef->content($this, $row);
            }
            $overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
            $content2 = "";
            if (!$fdef->content_empty($this, $row)) {
                $this->overriding = 2;
                $content2 = $fdef->content($this, $row);
            }
            $this->user->set_overrides($overrides);
        } else if ($override === PaperColumn::OVERRIDE_FORCE) {
            $overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
            if (!$fdef->content_empty($this, $row)) {
                $this->overriding = 0;
                $content = $fdef->content($this, $row);
            }
            $this->user->set_overrides($overrides);
        } else { // $override > 0
            if (!$fdef->content_empty($this, $row)) {
                $this->overriding = 0;
                $content = $fdef->content($this, $row);
            } else {
                $overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
                if (!$fdef->content_empty($this, $row)) {
                    $this->overriding = 2;
                    if ($override === PaperColumn::OVERRIDE_IFEMPTY_LINK) {
                        $content = '<em>Hidden for conflict</em> · <button type="button" class="link ui js-override-conflict">Override</button>';
                    }
                    $content2 = $fdef->content($this, $row);
                }
                $this->user->set_overrides($overrides);
            }
        }
        if ($content2 !== null && $content !== $content2) {
            if ($content === "") {
                $this->column_class = Ht::add_tokens($this->column_class, "fx5");
                $content = $content2;
            } else {
                $content = $this->wrap_conflict($content, $content2, $fdef->as_row ? "div" : "span");
            }
        }
        return $content;
    }

    private function _row_setup(PaperInfo $row) {
        ++$this->count;
        $this->row_attr = [];
        $this->row_overridable = $this->user->has_overridable_conflict($row);

        $this->row_tags = $this->row_tags_override = "";
        if (isset($row->paperTags) && $row->paperTags !== "") {
            if ($this->row_overridable) {
                $overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
                $this->row_tags_override = $row->sorted_viewable_tags($this->user);
                $this->user->remove_overrides(Contact::OVERRIDE_CONFLICT);
                $this->row_tags = $row->sorted_viewable_tags($this->user);
                $this->user->set_overrides($overrides);
            } else {
                $this->row_tags = $row->sorted_viewable_tags($this->user);
            }
        }
        $this->mark_has("tags", $this->row_tags !== "" || $this->row_tags_override !== "");
    }

    /** @param PaperListTableRender $rstate
     * @return string */
    private function _row_html($rstate, PaperInfo $row) {
        // filter
        if ($this->_row_filter
            && !call_user_func($this->_row_filter, $this, $row)) {
            --$this->count;
            return "";
        }

        // main columns
        $tm = [];
        foreach ($this->_vcolumns as $fdef) {
            if ($fdef->as_row || !$fdef->has_content) {
                continue;
            }
            $content = $this->_column_html($fdef, $row);
            if ($content !== "") {
                $k = Ht::add_tokens("pl", $fdef->className, $fdef->fold ? "fx{$fdef->fold}" : null);
                if ($this->column_class !== null) {
                    $content = "<div class=\"{$this->column_class}\">{$content}</div>";
                }
                $tm[] = "<td class=\"{$k}\">{$content}</td>";
                $fdef->has_content = true;
            } else {
                $k = $fdef->fold ? "pl fx{$fdef->fold}" : "pl";
                $tm[] = "<td class=\"{$k}\"></td>";
            }
        }

        // extension columns
        $tt = [];
        foreach ($this->_vcolumns as $fdef) {
            if (!$fdef->as_row || !$fdef->has_content) {
                continue;
            }
            $content = $this->_column_html($fdef, $row);
            if ($content !== "") {
                $ch = $fdef->header($this, false);
                $chx = $ch ? "{$ch}:" : "";
                $k = Ht::add_tokens("ple", $fdef->className, $fdef->fold ? "fx{$fdef->fold}" : null, $this->column_class);
                $tt[] = "<div class=\"{$k}\"><em class=\"plet\">{$chx}</em><div class=\"pled\">{$content}</div></div>";
                $fdef->has_content = true;
            } else {
                $k = Ht::add_tokens("ple", $fdef->className, $fdef->fold ? "fx{$fdef->fold}" : null);
                $tt[] = "<div class=\"{$k}\"></div>";
            }
        }

        // tags
        if ($this->row_tags_override !== ""
            && $this->row_tags_override !== $this->row_tags) {
            $this->row_attr["data-tags"] = trim($this->row_tags_override);
            $this->row_attr["data-tags-conflicted"] = trim($this->row_tags);
        } else if ($this->row_tags !== "") {
            $this->row_attr["data-tags"] = trim($this->row_tags);
        }

        // warn about download?
        if (!$this->user->privChair
            && $this->user->isPC
            && $this->user->needs_bulk_download_warning($row)) {
            $this->row_attr["data-bulkwarn"] = "";
            ++$this->_bulkwarn_count;
        }

        // row classes
        $trclass = [];
        $cc = "";
        if ($row->paperTags ?? null) {
            if ($this->row_tags_override !== ""
                && ($cco = $row->conf->tags()->color_classes($this->row_tags_override))) {
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
        if ($this->_highlight_map !== null
            && ($highlightclass = $this->_highlight_map[$row->paperId] ?? null)) {
            $trclass[] = "highlightmark";
            if ($highlightclass[0] !== "") {
                $trclass[] = "highlightmark-" . $highlightclass[0];
            }
        }
        $want_plx = !empty($tt) || $this->table_id();
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
        $t .= " class=\"pl {$trclass}\" data-pid=\"{$row->paperId}";
        foreach ($this->row_attr as $k => $v) {
            $t .= "\" {$k}=\"" . htmlspecialchars($v);
        }
        $t .= "\">" . join("", $tm) . "</tr>";

        // NB if plx row exists, it immediately follows the pl row w/o space
        if ($want_plx) {
            $t .= "<tr class=\"plx {$trclass}\" data-pid=\"{$row->paperId}\"><td class=\"plx\" colspan=\"{$rstate->ncol}\">" . join("", $tt) . "</td></tr>";
        }

        return $t . "\n";
    }

    /** @param int $grouppos
     * @param PaperListTableRender $rstate
     * @param list<string> &$body
     * @param bool $last
     * @return int */
    private function _mark_groups_html($grouppos, $rstate, &$body, $last) {
        while ($grouppos !== count($this->_groups)
               && ($last || $this->count > $this->_groups[$grouppos]->pos)) {
            if ($grouppos !== 0) {
                $rstate->groupstart[] = count($body);
            } else {
                assert(count($body) === 0);
            }
            $ginfo = $this->_groups[$grouppos];
            if ($ginfo->is_blank()) {
                // elide heading row for initial blank section
                if ($grouppos !== 0) {
                    $body[] = $rstate->heading_row($grouppos, "", []);
                }
            } else {
                $attr = [];
                if ($ginfo->tag) {
                    $attr["data-anno-tag"] = $ginfo->tag;
                }
                if ($ginfo->annoId) {
                    $attr["data-anno-id"] = $ginfo->annoId;
                    $attr["data-tags"] = "{$ginfo->tag}#{$ginfo->tagIndex}";
                }
                $x = "<span class=\"plheading-group";
                if ($ginfo->heading !== "") {
                    $x .= " pr-2";
                    if (($format = $this->conf->check_format(null, $ginfo->heading))) {
                        $x .= " need-format\" data-format=\"{$format}";
                        $this->need_render = true;
                    }
                }
                $x .= "\" data-title=\"" . htmlspecialchars($ginfo->heading)
                    . "\">" . htmlspecialchars($ginfo->heading)
                    . "</span><span class=\"plheading-count\">"
                    . plural($ginfo->count, "submission") . "</span>";
                $body[] = $rstate->heading_row($grouppos, $x, $attr);
                $rstate->colorindex = 0;
            }
            ++$grouppos;
        }
        return $grouppos;
    }

    /** @param PaperColumn $fdef
     * @return string */
    private function _field_th($fdef) {
        $sort_name = $fdef->sort_name();
        $sort_name_h = htmlspecialchars($sort_name);
        // empty header
        if (!$fdef->has_content
            && ($this->_table_decor & self::DECOR_EVERYHEADER) === 0) {
            $class = $fdef->fold ? " class=\"fx{$fdef->fold}\"" : "";
            return "<th{$class} data-pc=\"{$sort_name_h}\"></th>";
        }

        // non-sortable header
        $thclass = "pl plh {$fdef->className}" . ($fdef->fold ? " fx{$fdef->fold}" : "");
        $title = $fdef->header($this, false);
        if (!$fdef->sort
            || !$this->_sortable
            || !($sort_url = $this->search->url_site_relative_raw())) {
            return "<th class=\"{$thclass}\" data-pc=\"{$sort_name_h}\">{$title}</th>";
        }

        // sortable header
        $thclass .= " sortable";
        $sortattr = $fdef->default_sort_descending() ? "descending" : "ascending";
        $aria_sort = "";
        $aclass = "pl_sort";
        $s0 = ($this->sorters())[0];
        if ($s0->sort_subset === null && $sort_name === $s0->sort_name()) {
            $active_sort = $s0->sort_descending ? "descending" : "ascending";
            $aria_sort = " aria-sort=\"{$active_sort}\"";
            $thclass .= " sort-{$active_sort}";
        }


        $sort_url = htmlspecialchars($this->siteurl() . $sort_url)
            . (strpos($sort_url, "?") ? "&amp;" : "?")
            . "sort=" . urlencode($sort_name);
        if ($aria_sort && $s0->sort_descending === $s0->default_sort_descending()) {
            $sort_url .= $s0->sort_descending ? "+asc" : "+desc";
        }
        if (($this->user->overrides() & Contact::OVERRIDE_CONFLICT) !== 0) {
            $sort_url .= "&amp;forceShow=1";
        }

        return "<th class=\"{$thclass}\" data-pc=\"{$sort_name_h}\" data-pc-sort=\"{$sortattr}\"{$aria_sort}><a class=\"{$aclass}\" href=\"{$sort_url}\" rel=\"nofollow\">{$title}</a></th>";
    }

    private function _analyze_folds() {
        $classes = &$this->table_attr["class"];
        $jscol = [];
        $has_sel = $has_statistics = false;
        foreach ($this->_vcolumns as $fdef) {
            assert(!!$fdef->is_visible);
            if (!$fdef->has_content) {
                continue;
            }
            $jscol[] = $j = $fdef->field_json($this);
            if ($fdef->fold) {
                $classes[] = "fold{$fdef->fold}o";
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
        $this->table_attr["data-fields"] = $jscol;
        $this->table_attr["data-columns"] = $jscol; /* XXX backward compat */
    }

    /** @param PaperListTableRender $rstate
     * @return string */
    private function _statistics_rows($rstate) {
        $t = '  <tr class="pl_statheadrow fx8">';
        if ($rstate->titlecol > 0) {
            $t .= "<td colspan=\"{$rstate->titlecol}\" class=\"plstat\"></td>";
        }
        $t .= "<td colspan=\"" . ($rstate->ncol - max($rstate->titlecol, 0)) . "\" class=\"plstat\">" . foldupbutton(7, "Statistics") . "</td></tr>\n";
        foreach (self::$stats as $stat) {
            $t .= '  <tr';
            if ($this->_row_id_pattern) {
                $t .= " id=\"" . str_replace("#", "stat_" . ScoreInfo::$stat_keys[$stat], $this->_row_id_pattern) . "\"";
            }
            $t .= ' class="pl_statrow fx7 fx8" data-statistic="' . ScoreInfo::$stat_keys[$stat] . '">';
            $col = 0;
            foreach ($this->_vcolumns as $fdef) {
                if ($fdef->as_row || !$fdef->has_content) {
                    continue;
                }
                $class = "plstat " . $fdef->className;
                if ($fdef->has_statistics()) {
                    $content = $fdef->statistic_html($this, $stat);
                } else if ($col === $rstate->titlecol) {
                    $content = ScoreInfo::$stat_names[$stat];
                    $class = "plstat pl_statheader";
                } else {
                    $content = "";
                }
                if ($fdef->fold) {
                    $class .= " fx{$fdef->fold}";
                }
                $t .= "<td class=\"{$class}\">{$content}</td>";
                ++$col;
            }
            $t .= "</tr>\n";
        }
        return $t;
    }

    /** @param int $arrow_ncol
     * @param int $ncol */
    static function render_footer_row($arrow_ncol, $ncol, $header,
                            $lllgroups, $activegroup = -1) {
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
                $foot .= " {$k}=\"" . htmlspecialchars($v) . "\"";
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
        return $foot . "<hr class=\"c\"></td>\n </tr>";
    }

    /** @param string $fn
     * @param array<string,mixed> $js
     * @return string */
    function action_submit($fn, $js = []) {
        $js["value"] = $fn;
        $js["class"] = rtrim("uic js-submit-list ml-2 " . ($js["class"] ?? ""));
        return Ht::submit("fn", "Go", $js + ($this->_action_submit_attr ?? []));
    }

    /** @param int $ncol
     * @return string */
    private function _footer($ncol) {
        if ($this->count == 0) {
            return "";
        }
        $qreq = $this->qreq;
        $atab = null;
        if (($selfhref = !!$qreq->page())) {
            $atab = $qreq->fn ?? $qreq->atab;
        }

        $gex = ListAction::grouped_extensions($this->user);
        $gex->add_xt_checker([$this, "list_checker"]);
        $gex->apply_key_filter("display_if");
        if ($this->_footer_filter) {
            $gex->apply_filter($this->_footer_filter);
        }
        $lllgroups = [];
        $whichlll = -1;
        foreach ($gex->members("") as $rf) {
            if (isset($rf->render_function)
                && !str_starts_with($rf->name, "__")
                && Conf::xt_resolve_require($rf)
                && ($lllg = call_user_func($rf->render_function, $this, $qreq, $gex, $rf))) {
                if (is_string($lllg)) {
                    $lllg = [$lllg];
                }
                array_unshift($lllg, $rf->name, $rf->title);
                if ($selfhref) {
                    $lllg[0] = $this->conf->selfurl($qreq, ["atab" => $lllg[0], "#" => "plact"]);
                }
                $lllgroups[] = $lllg;
                if ($atab === $rf->name) {
                    $whichlll = count($lllgroups) - 1;
                }
            }
        }

        $footsel_ncol = $this->_view_facets ? 0 : 1;
        return self::render_footer_row($footsel_ncol, $ncol - $footsel_ncol,
            "<b>Select papers</b> (or <a class=\"ui js-select-all\" href=\""
            . ($selfhref ? $this->conf->selfurl($this->qreq, ["selectall" => 1, "#" => "plact"]) : "")
            . '">select all ' . $this->count . "</a>), then&nbsp;",
            $lllgroups, $whichlll);
    }

    /** @return bool */
    function is_empty() {
        return $this->unordered_rowset()->is_empty();
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

    /** @return ?string */
    private function _list_description() {
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
        $args = [];
        if (($sort = $this->sortdef()) !== "") {
            $args["sort"] = $sort;
        }
        if ($this->_view_force) {
            $args["forceShow"] = 1;
        }
        return $this->search->create_session_list_object($this->paper_ids(), $this->_list_description(), $args);
    }

    private function _stash_render() {
        Ht::stash_script('$(hotcrp.render_list)', 'plist_render_needed');
    }

    /** @return ?string */
    private function _drag_action() {
        if ($this->_sort_etag !== "") {
            return "tagval:{$this->_sort_etag}";
        }
        $thenqe = $this->search->then_term();
        $groups = $thenqe ? $thenqe->group_terms() : [];
        if (count($groups) < 2) {
            return null;
        }
        $assign = [];
        foreach ($groups as $i => $qe) {
            $a = $qe->drag_assigners($this->user);
            if (!empty($a)) {
                $assign[] = $a;
            } else if ($a === null
                       || ($a === [] && $i !== count($groups) - 1)) {
                return null;
            }
        }
        return empty($assign) ? null : "assign:" . json_encode_browser($assign);
    }

    /** @return PaperListTableRender */
    private function _table_render() {
        // need tags for row coloring
        if ($this->user->can_view_tags(null)) {
            $this->qopts["tags"] = true;
        }

        // get column list
        $this->_reset_vcolumns(FieldRender::CFLIST | FieldRender::CFHTML);
        if (empty($this->_vcolumns)) {
            return PaperListTableRender::make_error("Nothing to show");
        }

        $rows = $this->rowset();
        if ($rows->is_empty()) {
            if (($altq = $this->search->alternate_query())) {
                $altqh = htmlspecialchars($altq);
                $url = $this->search->url_site_relative_raw(["q" => $altq]);
                if (substr($url, 0, 5) == "search") {
                    $altqh = "<a href=\"" . htmlspecialchars($this->siteurl() . $url) . "\">" . $altqh . "</a>";
                }
                return PaperListTableRender::make_error("No matches. Did you mean ‘{$altqh}’?");
            } else {
                return PaperListTableRender::make_error("No matches");
            }
        }

        // analyze columns and folds
        // folds: anonau:2, fullrow:3, aufull:4, force:5, rownum:6, statistics:7,
        // statistics-exist:8, [fields]
        $next_fold = 9;
        foreach ($this->_vcolumns as $fdef) {
            foreach ($rows as $row) {
                $this->row_overridable = $this->user->has_overridable_conflict($row);
                if ($this->_column_html($fdef, $row) !== "") {
                    $fdef->has_content = true;
                    break;
                }
            }
            $fdef->reset($this);
            if (!$fdef->has_content) {
                continue;
            }
            if ($this->view_origin($fdef->name) !== self::VIEWORIGIN_REPORT) {
                $fdef->fold = $next_fold;
                ++$next_fold;
            }
        }

        // create render state
        $rstate = new PaperListTableRender($this->_vcolumns);

        // prepare table attributes
        $this->table_attr["class"] = ["pltable need-plist has-fold"];
        if ($this->_table_class) {
            $this->table_attr["class"][] = $this->_table_class;
        }
        if ($this->_table_id) {
            $this->table_attr["id"] = $this->_table_id;
        }
        $this->table_attr["data-search-params"] = $this->encoded_search_params();
        if ($this->_table_fold_session) {
            $this->table_attr["data-fold-session-prefix"] = $this->_table_fold_session;
            $this->table_attr["data-fold-session"] = json_encode_browser([
                "2" => "anonau", "4" => "aufull", "5" => "force",
                "6" => "rownum", "7" => "statistics"
            ]);
        }
        if ($this->_groups) {
            $this->table_attr["data-groups"] = json_encode_browser($this->_groups);
        }
        if (($this->_table_decor & self::DECOR_LIST) !== 0) {
            $this->table_attr["class"][] = "has-hotlist";
            $this->table_attr["data-hotlist"] = $this->session_list_object()->info_string();
        }
        if (($this->_table_decor & self::DECOR_FULLWIDTH) !== 0) {
            $this->table_attr["class"][] = "pltable-fullw remargin-left remargin-right";
        }
        if ($this->_sortable
            && ($url = $this->search->url_site_relative_raw())) {
            $url = $this->siteurl() . $url . (strpos($url, "?") ? "&" : "?") . "sort={sort}";
            $this->table_attr["data-sort-url-template"] = $url;
        }
        if (!$this->_view_facets
            && ($da = $this->_drag_action())) {
            $this->table_attr["class"][] = "pltable-draggable";
            $this->table_attr["data-drag-action"] = $da;
        }

        // collect row data
        $body = [];
        $grouppos = empty($this->_groups) ? -1 : 0;
        $need_render = false;
        foreach ($rows as $row) {
            $this->_row_setup($row);
            if ($grouppos >= 0) {
                $grouppos = $this->_mark_groups_html($grouppos, $rstate, $body, false);
            }
            $body[] = $this->_row_html($rstate, $row);
            if ($this->need_render && !$need_render) {
                $this->_stash_render();
                $need_render = true;
            }
            if ($this->need_render && $this->count % 16 === 15) {
                $body[count($body) - 1] .= "  " . Ht::script('hotcrp.render_list()') . "\n";
                $this->need_render = false;
            }
        }
        if ($grouppos >= 0) {
            $grouppos = $this->_mark_groups_html($grouppos, $rstate, $body, true);
        }
        assert(count($rstate->groupstart) === max(count($this->_groups), 1));
        $rstate->groupstart[] = count($body);
        if ($rstate->group_count() === 1) {
            $this->_view_facets = false;
        }
        if ($this->count === 0) {
            return PaperListTableRender::make_error("No matching papers");
        }

        // analyze `has`, including authors
        foreach ($this->_vcolumns as $fdef) {
            $this->mark_has($fdef->name, $fdef->has_content);
        }
        if ($this->_bulkwarn_count >= 4
            && !isset($this->table_attr["data-bulkwarn-ftext"])
            && ($m = $this->conf->_i("submission_bulk_warning")) !== "") {
            $this->table_attr["data-bulkwarn-ftext"] = Ftext::ensure($m, 5);
        }

        // statistics rows
        $tfoot = "";
        if (!$this->_view_facets && ($this->_table_decor & self::DECOR_STATISTICS) !== 0) {
            $tfoot = $this->_statistics_rows($rstate);
        }

        // analyze folds
        $this->_analyze_folds();

        // header cells
        if (($this->_table_decor & (self::DECOR_HEADER | self::DECOR_EVERYHEADER)) !== 0) {
            $ths = "";
            foreach ($this->_vcolumns as $fdef) {
                if (!$fdef->as_row && $fdef->has_content) {
                    $ths .= $this->_field_th($fdef);
                }
            }

            $t = " <thead class=\"pltable-thead\">\n  <tr class=\"pl_headrow\">" . $ths . "</tr>\n";

            if ($this->_sort_etag
                && $this->user->can_edit_tag_anno($this->_sort_etag)) {
                $t .= "  <tr class=\"pl_headrow pl_annorow\" data-anno-tag=\"{$this->_sort_etag}\">";
                if ($rstate->titlecol > 0) {
                    $t .= "<td class=\"plh\" colspan=\"{$rstate->titlecol}\"></td>";
                }
                $t .= "<td class=\"plh\" colspan=\"" . ($rstate->ncol - max($rstate->titlecol, 0)) . "\"><a class=\"ui js-annotate-order\" data-anno-tag=\"{$this->_sort_etag}\" href=\"\">Annotate order</a></td></tr>\n";
                Icons::stash_defs("trash");
            }

            $rstate->thead = "{$t} </thead>\n";
        }

        $rstate->tbody_class = "pltable-tbody";
        if ($rstate->hascolors) {
            $rstate->tbody_class .= " pltable-colored";
        }
        if ($this->has_editable_tags) {
            $this->need_render = true;
        }

        // ensure render_list call occurs
        if ($this->need_render) {
            $this->_stash_render();
        }

        // footer
        if ($this->_vcolumns[0] instanceof Selector_PaperColumn
            && ($this->_table_decor & self::DECOR_FOOTER) !== 0
            && !$this->_view_facets) {
            $tfoot .= $this->_footer($rstate->ncol);
        }
        if ($tfoot) {
            $rstate->tfoot = ' <tfoot class="pltable-tfoot' . ($rstate->hascolors ? " pltable-colored" : "") . '">' . $tfoot . "</tfoot>\n";
        }

        $rstate->rows = $body;
        return $rstate;
    }

    /** @return PaperListTableRender */
    function table_render() {
        $overrides = $this->user->remove_overrides(Contact::OVERRIDE_CONFLICT);
        $rstate = $this->_table_render();
        $this->user->set_overrides($overrides);
        return $rstate;
    }

    function print_table_html() {
        $rstate = $this->table_render();
        if ($rstate->error) {
            if (($this->_table_decor & self::DECOR_FULLWIDTH) !== 0) {
                echo '<div class="msg in-demargin remargin-left remargin-right"><div class="mx-auto"><ul class="inline"><li>',
                    $rstate->error,
                    '</li></ul></div></div>';
            } else {
                echo $rstate->error;
            }
            return;
        }
        $facets = $this->_view_facets && $rstate->group_count() > 1;
        if ($facets) {
            $this->table_attr["class"][] = "pltable-facets";
            echo '<div';
            PaperListTableRender::print_attributes($this->table_attr);
            echo '>';
            $attr = ["class" => "pltable-facet"];
        } else {
            $attr = $this->table_attr;
        }
        $i = 0;
        $n = $rstate->group_count();
        while ($i !== $n) {
            $j = $facets ? $i + 1 : $n;
            $rstate->print_table_start($attr, self::$include_stash);
            $rstate->print_tbody_rows($i, $j);
            $rstate->print_table_end();
            $i = $j;
        }
        if ($facets) {
            echo '</div>';
        }
    }

    /** @return string */
    function table_html() {
        ob_start();
        $this->print_table_html();
        return ob_get_clean();
    }

    /** @return array{fields:array<string,array>,data:array<int,array{id:int}>,attr?:array,stat?:array} */
    function table_html_json() {
        // get column list, check sort
        $this->_reset_vcolumns(FieldRender::CFLIST | FieldRender::CFHTML);
        if (empty($this->_vcolumns)) {
            return ["fields" => [], "data" => []];
        }

        // turn off forceShow
        $overrides = $this->user->remove_overrides(Contact::OVERRIDE_CONFLICT);

        // output field data
        $data = $attr = $classes = [];
        foreach ($this->rowset() as $row) {
            $this->_row_setup($row);
            $p = ["id" => $row->paperId];
            foreach ($this->_vcolumns as $fdef) {
                if (($content = $this->_column_html($fdef, $row)) !== "") {
                    $p[$fdef->name] = $content;
                    if ($this->column_class !== null) {
                        $classes[$row->paperId][$fdef->name] = $this->column_class;
                    }
                }
            }
            $data[$row->paperId] = $p;
            foreach ($this->row_attr as $k => $v) {
                $attr[$row->paperId][$k] = $v;
            }
        }

        // analyze `has`, including authors
        foreach ($this->_vcolumns as $fdef) {
            $this->mark_has($fdef->name, $fdef->has_content);
        }

        // output fields and statistics
        $fields = $stats = [];
        foreach ($this->_vcolumns as $fdef) {
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
        if (!empty($classes)) {
            $result["classes"] = $classes;
        }
        if (!empty($attr)) {
            $result["attr"] = $attr;
        }
        if (!empty($stats)) {
            $result["stat"] = $stats;
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    function text_json() {
        // get column list, check sort
        $this->_reset_vcolumns(FieldRender::CFTEXT | FieldRender::CFCSV | FieldRender::CFVERBOSE);
        $data = [];
        if (!empty($this->_vcolumns)) {
            foreach ($this->rowset() as $row) {
                $this->_row_setup($row);
                $p = ["id" => $row->paperId];
                foreach ($this->_vcolumns as $fdef) {
                    if (!$fdef->content_empty($this, $row)
                        && ($text = $fdef->text($this, $row)) !== "") {
                        $p[$fdef->name] = $text;
                    }
                }
                $data[$row->paperId] = $p;
            }
        }
        return $data;
    }

    /** @return array<string,string> */
    private function _row_text_csv_data(PaperInfo $row) {
        $csv = [];
        foreach ($this->_vcolumns as $fdef) {
            $t = "";
            if (!$fdef->content_empty($this, $row)) {
                $t = $fdef->text($this, $row);
            }
            $csv[$fdef->name] = $t;
            $fdef->has_content = $fdef->has_content || $t !== "";
        }
        return $csv;
    }

    /** @param int $grouppos
     * @param list<array<string,string>> &$body */
    private function _mark_groups_csv($grouppos, &$body) {
        $ginfo = null;
        while ($grouppos !== count($this->_groups)
               && $this->_groups[$grouppos]->pos < $this->count) {
            $ginfo = $this->_groups[$grouppos];
            ++$grouppos;
        }
        if ($ginfo
            && (!$ginfo->is_blank() || $this->count > 1)
            && $this->viewing("title")) {
            $body[] = ["id" => "N/A", "title" => $ginfo->is_blank() ? "none" : $ginfo->heading];
        }
        return $grouppos;
    }

    /** @return array{array<string,string>,list<array<string,string>>} */
    function text_csv() {
        // get column list, check sort
        $this->_reset_vcolumns(FieldRender::CFTEXT | FieldRender::CFCSV | FieldRender::CFVERBOSE);

        // collect row data
        $body = [];
        $grouppos = empty($this->_groups) ? -1 : 0;
        foreach ($this->rowset() as $row) {
            $this->_row_setup($row);
            if ($grouppos >= 0) {
                $grouppos = $this->_mark_groups_csv($grouppos, $body);
            }
            $body[] = $this->_row_text_csv_data($row);
        }

        // header cells
        $header = [];
        foreach ($this->_vcolumns as $fdef) {
            if ($fdef->has_content) {
                $header[$fdef->name] = $fdef->header($this, true);
            }
        }

        return [$header, $body];
    }
}
