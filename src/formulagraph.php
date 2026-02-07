<?php
// formulagraph.php -- HotCRP class for drawing graphs
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Scatter_GraphData implements JsonSerializable {
    /** @var int|float|bool */
    public $x;
    /** @var int|float|bool */
    public $y;
    /** @var int|string */
    public $id;

    /** @param int|float|bool $x
     * @param int|float|bool $y
     * @param int|string $id */
    function __construct($x, $y, $id) {
        $this->x = $x;
        $this->y = $y;
        $this->id = $id;
    }
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return [$this->x, $this->y, $this->id];
    }
}

class BarElement_GraphData {
    /** @var int|float|bool */
    public $x;
    /** @var list<int|float|bool> */
    public $ys;
    /** @var int|string */
    public $id;
    /** @var ?string */
    public $style;
    /** @var int */
    public $sx;

    /** @param int|float|bool $x
     * @param list<int|float|bool> $ys
     * @param int|string $id
     * @param ?string $style
     * @param int $sx */
    function __construct($x, $ys, $id, $style, $sx) {
        $this->x = $x;
        $this->ys = $ys;
        $this->id = $id;
        $this->style = $style;
        $this->sx = $sx;
    }
    /** @param BarElement_GraphData $a
     * @param BarElement_GraphData $b
     * @return int */
    static function compare($a, $b) {
        if ($a->sx !== $b->sx) {
            return $a->sx <=> $b->sx;
        } else if ($a->x != $b->x) {
            return $a->x <=> $b->x;
        }
        return strcmp($a->style, $b->style);
    }
}

class Bar_GraphData implements JsonSerializable {
    /** @var int|float|bool */
    public $x;
    /** @var int|float|bool */
    public $y;
    /** @var list<int|string> */
    public $ids;
    /** @var ?string */
    public $style;
    /** @var int */
    public $sx;

    /** @param int|float|bool $x
     * @param int|float|bool $y
     * @param list<int|string> $ids
     * @param ?string $style
     * @param int $sx */
    function __construct($x, $y, $ids, $style, $sx) {
        $this->x = $x;
        $this->y = $y;
        $this->ids = $ids;
        $this->style = $style;
        $this->sx = $sx;
    }
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        if ($this->sx) {
            return [$this->x, $this->y, $this->ids, $this->style, $this->sx];
        } else if ($this->style) {
            return [$this->x, $this->y, $this->ids, $this->style];
        }
        return [$this->x, $this->y, $this->ids];
    }
}

class CDF_GraphData implements JsonSerializable {
    /** @var list<int|float> */
    public $d;
    /** @var ?string */
    public $className;
    /** @var ?string */
    public $label;
    /** ?list<int> */
    public $dashpattern;

    /** @param list<int|float> $d */
    function __construct($d) {
        $this->d = $d;
    }
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $x = ["d" => $this->d];
        if ($this->className !== null) {
            $x["className"] = $this->className;
        }
        if ($this->label) {
            $x["label"] = $this->label;
        }
        if ($this->dashpattern) {
            $x["dashpattern"] = $this->dashpattern;
        }
        return $x;
    }
}

class Order_GraphData {
    /** @var int|float|bool */
    public $x;
    /** @var int|float|bool */
    public $y;

    /** @param int|float|bool $x
     * @param int|float|bool $y */
    function __construct($x, $y) {
        $this->x = $x;
        $this->y = $y;
    }
    /** @param Order_GraphData $a
     * @param Order_GraphData $b */
    static function compare($a, $b) {
        return $a->y <=> $b->y ? : $a->x <=> $b->x;
    }
}

class FormulaGraph extends MessageSet {
    // bitmasks
    const SCATTER = 1;
    const CDF = 2;
    const BARCHART = 4;
    const BOXPLOT = 8;
    const DOT = 16;
    const FBARCHART = 132; // 128 | BARCHART
    const OGIVE = 130;    // 128 | CDF

    // formula class
    const DATA_PAPER = 1;
    const DATA_REVIEW = 2;

    const REVIEWER_COLOR = 1;

    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var int */
    public $type = 0;
    /** @var Formula */
    public $fx;
    /** @var list<Formula> */
    private $fxs;
    /** @var string */
    private $fx_expression;
    /** @var Formula */
    public $fy;
    /** @var int */
    private $_fx_type = 0;
    /** @var bool */
    private $_fx_combine = false;
    /** @var list<string> */
    private $queries = [];
    /** @var list<string> */
    private $_qstyles = [];
    /** @var list<bool> */
    private $_qstyles_bytag = [];
    /** @var int */
    private $_qstyle_index = 0;
    /** @var list<?PaperSearch> */
    private $searches = [];
    /** @var array<int,list<int>> */
    private $papermap = [];
    /** @var list<Contact> */
    private $reviewers = [];
    /** @var ?array<int,string> */
    private $reviewer_color;
    private $remapped_rounds = [];
    /** @var array<string,int> */
    private $tags = [];
    /** @var ?Formula */
    private $fxorder;
    /** @var array<string,list<Scatter_GraphData>> */
    private $_scatter_data;
    /** @var list<Bar_GraphData> */
    private $_bar_data;
    /** @var list<CDF_GraphData> */
    private $_cdf_data;
    /** @var list<Order_GraphData> */
    private $_xorder_data;
    /** @var array<mixed,int> */
    private $_xorder_map;
    /** @var 0|1|2|3 */
    private $_axis_remapped = 0;
    /** @var bool */
    private $_x_bool = true;
    /** @var bool */
    private $_y_bool = true;

    /** @param string $s
     * @return ?array{int,string} */
    static function graph_type_prefix($s) {
        if (!preg_match('/\A\s*+(cdf(?![-\w])|)((?:ogive|cumfreq|cumulativefrequency)(?![-\w])|)((?:count|bars?|barchart)(?![-\w])|)((?:stack|fraction)(?![-\w])|)((?:box|boxplot)(?![-\w])|)(scatter(?:plot|)(?![-\w])|)(dot(?:plot|)(?![-\w])|)(?![-\w])\s*+/', $s, $m)) {
            return null;
        } else if ($m[1]) {
            return [self::CDF, $m[0]];
        } else if ($m[2]) {
            return [self::OGIVE, $m[0]];
        } else if ($m[3]) {
            return [self::BARCHART, $m[0]];
        } else if ($m[4]) {
            return [self::FBARCHART, $m[0]];
        } else if ($m[5]) {
            return [self::BOXPLOT, $m[0]];
        } else if ($m[6]) {
            return [self::SCATTER, $m[0]];
        } else if ($m[7]) {
            return [self::DOT, $m[0]];
        }
        return null;
    }

    /** @param string $s
     * @return ?array{int,string} */
    static function data_type_prefix($s) {
        if (preg_match('/\A\s*+(paper|review)(\s++|(?=\())(?=[-+.\w(\[])/', $s, $m)) {
            return [$m[1] === "paper" ? self::DATA_PAPER : self::DATA_REVIEW, $m[0]];
        }
        return null;
    }

    /** @param int $data
     * @param Formula $f
     * @return bool */
    static private function check_data_type($data, $f) {
        if (($data === self::DATA_PAPER && $f->indexed())
            || ($data === self::DATA_REVIEW && !$f->indexed())) {
            return false;
        }
        return true;
    }

    /** @param int $data
     * @return string */
    static private function unparse_data_type($data) {
        if ($data === self::DATA_PAPER) {
            return "paper";
        } else if ($data === self::DATA_REVIEW) {
            return "review";
        } else {
            return "none";
        }
    }

    /** @param ?string $gtype
     * @param string $fx
     * @param string $fy */
    function __construct(Contact $user, $gtype, $fx, $fy) {
        $this->conf = $user->conf;
        $this->user = $user;

        // graph type
        if ($gtype !== null && trim($gtype) !== "") {
            $gtx = self::graph_type_prefix($gtype);
            if ($gtx && $gtx[1] === $gtype) {
                $this->type = $gtx[0];
            } else {
                $this->error_at("gtype", "<0>Graph type not found");
            }
        } else if (($gtx = self::graph_type_prefix($fy))) {
            $this->type = $gtx[0];
            $fy = substr($fy, strlen($gtx[1]));
        } else {
            $this->type = self::SCATTER;
        }

        // `paper`/`review` prefix
        $fx_data = $fy_data = 0;
        if (($dtx = self::data_type_prefix($fx))) {
            $fx_data = $dtx[0];
            $fx = substr($fx, strlen($dtx[1]));
        }
        if (($dtx = self::data_type_prefix($fy))) {
            $fy_data = $dtx[0];
            $fy = substr($fy, strlen($dtx[1]));
        }

        // correct Y axis expression
        if (($this->type & self::CDF) !== 0) {
            $fy = "0";
        } else if ($this->type === self::BARCHART) {
            $this->_fx_combine = true;
            if (trim($fy) === "") {
                $fy = "sum(1)";
            }
        } else if ($this->type === self::FBARCHART) {
            $this->_fx_combine = true;
            $fy = "sum(1)";
        }

        // X axis expression(s)
        $this->fx_expression = $fx;
        $this->fxs = [];
        if (preg_match('/\A(sort|order|rorder)\s+(\S.*)\z/i', $fx, $m)) {
            if (strcasecmp($m[1], "rorder") === 0) {
                $m[2] = "-($m[2])";
            }
            $this->set_xorder($m[2]);
            $this->fxs[] = Formula::make_indexed($this->user, "pid");
        } else if (strcasecmp($fx, "query") === 0 || strcasecmp($fx, "search") === 0) {
            $this->fxs[] = Formula::make_indexed($this->user, "0");
            $this->_fx_type = Fexpr::FSEARCH;
        } else if (strcasecmp($fx, "tag") === 0) {
            $this->fxs[] = Formula::make_indexed($this->user, "0");
            $this->_fx_type = Fexpr::FTAG;
        } else if (($this->type & self::CDF) === 0) {
            $this->fxs[] = Formula::make_indexed($this->user, $fx);
        } else {
            while (true) {
                $fx = preg_replace('/\A\s*;*\s*/', '', $fx);
                if ($fx === "") {
                    break;
                }
                $pos = Formula::span_maximal_formula($fx);
                $this->fxs[] = Formula::make_indexed($this->user, substr($fx, 0, $pos));
                $fx = substr($fx, $pos);
            }
        }
        foreach ($this->fxs as $i => $f) {
            foreach ($f->message_list() as $mi) {
                $this->append_item($mi->with_field("fx"));
            }
            if (!$f->ok()) {
                continue;
            }
            if ($fx_data !== 0
                && !self::check_data_type($fx_data, $f)) {
                $this->error_at("fx", $this->conf->_("<0>Formula incompatible with data type ‘{}’", self::unparse_data_type($fx_data)));
            }
            if ($i === 0 && $this->_fx_type === 0) {
                $this->_fx_type = $f->result_format();
            }
            if (($this->_fx_type !== 0
                 && $this->_fx_type !== $f->result_format())
                || ($this->_fx_type === Fexpr::FREVIEWFIELD
                    && $this->fxs[0]->result_format_detail() !== $f->result_format_detail())) {
                $this->error_at("fx", "<0>X axis formulas must all use the same units");
                $this->_fx_type = 0;
            }
        }
        $this->fx = count($this->fxs) === 1 ? $this->fxs[0] : null;

        // Y axis expression
        $this->fy = Formula::make_indexed($this->user, $fy);
        foreach ($this->fy->message_list() as $mi) {
            $this->append_item($mi->with_field("fy"));
        }
        if ($this->fy->ok()
            && $fy_data !== 0
            && !self::check_data_type($fy_data, $this->fy)) {
            $this->error_at("fy", $this->conf->_("<0>Formula incompatible with data type ‘{}’", self::unparse_data_type($fy_data)));
        }

        // infer data type
        if ($fx_data === 0
            && !empty($this->fxs)
            && $this->fxs[0]->indexed()) {
            $fx_data = self::DATA_REVIEW;
        }
        if ($this->type === self::SCATTER
            && $fx_data === self::DATA_REVIEW
            && $fy_data === 0
            && $this->fy->support_combiner()) {
            $fy_data = self::DATA_REVIEW;
        }
        if ($this->type === self::SCATTER
            && $fx_data === self::DATA_REVIEW
            && $fy_data === self::DATA_REVIEW) {
            $this->_fx_combine = true;
        }

        // check types
        if (($this->type & self::CDF) !== 0
            && $this->_fx_type === Fexpr::FTAG) {
            $this->error_at("fy", "<0>CDFs by tag don’t make sense");
        }

        if ($this->_fx_combine
            && !$this->has_error()) {
            if ($this->fy->result_format() === Fexpr::FBOOL) {
                $this->fy = Formula::make_indexed($this->user, "sum({$fy})");
            } else if (!$this->fy->support_combiner()) {
                $this->error_at("fy", "<0>Y axis formula cannot be used for this chart");
                $this->inform_at("fy", "<0>Try an aggregate function like ‘sum({$fy})’.");
                $this->fy = Formula::make_indexed($this->user, "sum(0)");
            }
        }
    }

    /** @return array{list<string>,list<string>} */
    static function parse_queries(Qrequest $qreq) {
        $queries = $styles = [];
        for ($i = 1; isset($qreq["q{$i}"]); ++$i) {
            $q = trim($qreq["q{$i}"]);
            $queries[] = $q === "" || $q === "(All)" ? "all" : $q;
            $styles[] = trim((string) $qreq["s{$i}"]);
        }
        if (empty($queries) && isset($qreq->q)) {
            $q = trim($qreq->q);
            $queries[] = $q === "" || $q === "(All)" ? "all" : $q;
            $styles[] = trim((string) $qreq->s);
        } else if (empty($queries)) {
            $queries[] = $styles[] = "";
        }
        while (count($queries) > 1
               && $queries[count($queries) - 1] === $queries[count($queries) - 2]) {
            array_pop($queries);
            array_pop($styles);
        }
        if (count($queries) === 1 && $queries[0] === "all") {
            $queries[0] = "";
        }
        return [$queries, $styles];
    }

    /** @param string $q
     * @param string $style */
    function add_query($q, $style, $fieldname = false) {
        $qn = count($this->queries);
        $this->queries[] = $q;
        if ($style === "by-tag" || $style === "default" || $style === "") {
            $style = "";
            $this->_qstyles_bytag[] = true;
        } else if ($style === "plain") {
            $style = "";
            $this->_qstyles_bytag[] = false;
        } else {
            $this->_qstyles_bytag[] = false;
        }
        if ($style === "") {
            if (($n = $this->_qstyle_index % 4)) {
                $style = "color" . $n;
            }
            ++$this->_qstyle_index;
        }
        $this->_qstyles[] = $style;
        $psearch = new PaperSearch($this->user, ["q" => $q]);
        foreach ($psearch->paper_ids() as $pid) {
            $this->papermap[$pid][] = $qn;
        }
        foreach ($psearch->message_list() as $mi) {
            $this->append_item($mi->with_field($fieldname));
        }
        $this->searches[] = $q !== "" ? $psearch : null;
    }

    function set_xorder($xorder) {
        $this->fxorder = null;
        $xorder = simplify_whitespace($xorder);
        if ($xorder === "") {
            return;
        }
        $fxorder = Formula::make_indexed($this->user, $xorder);
        foreach ($fxorder->message_list() as $mi) {
            $this->append_item($mi->with_field("xorder"));
        }
        if ($fxorder->ok()) {
            $this->fxorder = $fxorder;
        }
    }

    /** @return string */
    function fx_expression() {
        return $this->fx_expression;
    }

    /** @return int */
    function fx_format() {
        return $this->_fx_type;
    }

    /** @param PaperInfo $prow
     * @param ?ReviewInfo $rrow
     * @return list<int> */
    private function _filter_queries($prow, $rrow) {
        $queries = [];
        foreach ($this->papermap[$prow->paperId] as $q) {
            if (!$rrow
                || !$this->searches[$q]
                || $this->searches[$q]->test_review($prow, $rrow))
                $queries[] = $q;
        }
        return $queries;
    }

    /** @param bool $reviewf
     * @return bool */
    private function _compile_xorder_function($reviewf) {
        if (!$this->fxorder) {
            return false;
        }
        if ($reviewf) {
            $this->fxorder->prepare_extractor();
        } else {
            $this->fxorder->prepare_json();
        }
        return true;
    }

    /** @param list $order_data
     * @bool $reviewf */
    private function _resolve_xorder_data($order_data, $reviewf) {
        if (!$this->fxorder) {
            return null;
        }
        $this->_xorder_data = [];
        if ($reviewf) {
            foreach ($order_data as $x => $vs) {
                $v = $this->fxorder->eval_combiner($vs);
                $this->_xorder_data[] = new Order_GraphData($x, $v);
            }
        } else {
            foreach ($order_data as $x => $vs) {
                $this->_xorder_data[] = new Order_GraphData($x, $vs[0]);
            }
        }
    }

    /** @param Formula $fx
     * @return list<CDF_GraphData> */
    private function _cdf_data_one_fx($fx, $qcolors, $dashp, PaperInfoSet $rowset) {
        $fx->prepare_json();
        $reviewf = $fx->indexed() ? $fx->prepare_indexer() : null;
        $want_order = $this->_compile_xorder_function(!!$reviewf);
        $order_data = [];

        $data = [];
        foreach ($rowset as $prow) {
            $revs = $reviewf ? $reviewf->eval_indexer($prow) : [null];
            $queries = $this->papermap[$prow->paperId];
            foreach ($revs as $rcid) {
                if (($x = $fx->eval_json($prow, $rcid)) === null) {
                    continue;
                }
                if ($this->_x_bool && !is_bool($x)) {
                    $this->_x_bool = false;
                }
                if ($rcid) {
                    $queries = $this->_filter_queries($prow, $prow->review_by_user($rcid));
                }
                if ($this->_fx_type === Fexpr::FSEARCH) {
                    foreach ($queries as $q) {
                        $data[0][] = $q;
                    }
                } else {
                    foreach ($queries as $q) {
                        $data[$q][] = $x;
                    }
                }
                if ($want_order) {
                    $order_data[$x][] = $reviewf
                        ? $this->fxorder->eval_extractor($prow, $rcid)
                        : $this->fxorder->eval_json($prow, $rcid);
                }
            }
        }

        $fxlabel = count($this->fxs) > 1 ? $fx->expression : "";
        $result = [];
        foreach ($data as $q => $ds) {
            $d = new CDF_GraphData($ds);
            if (($s = $qcolors[$q])) {
                $d->className = $s;
            }
            $dlabel = "";
            if (($this->queries[$q] ?? null) && count($this->queries) > 1) {
                $dlabel = $this->queries[$q];
            }
            if ($dlabel || $fxlabel) {
                $d->label = rtrim("{$fxlabel} {$dlabel}");
            }
            if ($dashp) {
                $d->dashpattern = $dashp;
            }
            $result[] = $d;
        }
        $this->_resolve_xorder_data($order_data, !!$reviewf);
        return $result;
    }

    private function _cdf_data(PaperInfoSet $rowset) {
        // calculate query styles
        $qcolorset = array_fill(0, count($this->_qstyles), null);
        $need_anal = array_fill(0, count($this->_qstyles), false);
        $has_color = array_fill(0, count($this->_qstyles), 0);
        $no_color = array_fill(0, count($this->_qstyles), 0);
        $nneed_anal = 0;
        foreach ($qcolorset as $qi => $q) {
            if ($this->_qstyles_bytag[$qi]) {
                $need_anal[$qi] = true;
                ++$nneed_anal;
            }
        }
        foreach ($rowset as $prow) {
            if ($nneed_anal === 0) {
                break;
            }
            foreach ($this->papermap[$prow->paperId] as $qi) {
                if (!$need_anal[$qi]) {
                    continue;
                }
                $c = $this->conf->tags()->styles($prow->viewable_tags($this->user), TagStyle::BG);
                if (empty($c) && ++$no_color[$qi] <= 4) {
                    continue;
                }
                if (!empty($c) && $qcolorset[$qi] !== null) {
                    $c = array_values(array_intersect($qcolorset[$qi], $c));
                }
                if (empty($c)) {
                    $need_anal[$qi] = false;
                    --$nneed_anal;
                } else {
                    $qcolorset[$qi] = $c;
                    ++$has_color[$qi];
                }
            }
        }

        $qcolors = $this->_qstyles;
        foreach ($need_anal as $qi => $na) {
            if ($na && $has_color[$qi] && $has_color[$qi] >= 5 * $no_color[$qi]) {
                $qcolors[$qi] = join(" ", $qcolorset[$qi]);
            }
        }

        // compute data
        $this->_cdf_data = [];
        $dashps = [null, [10,5], [5,5], [1,1]];
        foreach ($this->fxs as $i => $fx) {
            $dashp = $dashps[$i % count($dashps)];
            $this->_cdf_data = array_merge($this->_cdf_data,
                $this->_cdf_data_one_fx($fx, $qcolors, $dashp, $rowset));
        }
    }

    private function _prepare_reviewer_color(Contact $user) {
        $this->reviewer_color = [];
        foreach ($this->conf->pc_members() as $p) {
            $this->reviewer_color[$p->contactId] = $this->conf->tags()->color_classes($p->viewable_tags($user), true);
        }
    }

    /** @return 1|string */
    private function _paper_style(PaperInfo $prow) {
        $qnum = $this->papermap[$prow->paperId][0];
        if ($this->_qstyles_bytag[$qnum]) {
            if ($this->reviewer_color && $this->user->can_view_user_tags()) {
                return self::REVIEWER_COLOR;
            } else if (($c = $prow->viewable_tags($this->user))
                       && ($c = $prow->conf->tags()->styles($c, TagStyle::BG))) {
                return join(" ", $c);
            }
        }
        return $this->_qstyles[$qnum];
    }

    /** @return list<int> */
    private function _account_tags(PaperInfo $prow) {
        assert($this->_fx_type === Fexpr::FTAG);
        $tags = Tagger::split_unpack($prow->viewable_tags($this->user));
        $r = [];
        foreach ($tags as $ti) {
            if (!isset($this->tags[$ti[0]])) {
                $this->tags[$ti[0]] = count($this->tags);
            }
            $r[] = $this->tags[$ti[0]];
        }
        return $r;
    }

    /** @return bool */
    private function _indexed() {
        return $this->fx->indexed()
            || $this->fy->indexed()
            || ($this->fxorder && $this->fxorder->indexed());
    }

    /** @return int */
    private function _index_type() {
        return Formula::combine_index_types(
            $this->fx->index_type(),
            $this->fxorder ? $this->fxorder->index_type() : 0,
            $this->fy->index_type()
        );
    }

    private function _scatter_data(PaperInfoSet $rowset) {
        if ($this->fx->result_format() === Fexpr::FREVIEWER
            && ($this->type & self::BOXPLOT) !== 0) {
            $this->_prepare_reviewer_color($this->user);
        }

        $this->fx->prepare_json();
        $this->fy->prepare_json();

        $reviewf = null;
        $review_id = false;
        if ($this->_indexed()) {
            $index_type = $this->_index_type();
            $reviewf = $this->fx->prepare_indexer($index_type);
            $review_id = $this->fx->indexed()
                && $this->fy->indexed()
                && ($index_type & Fexpr::IDX_PC) !== 0;
        }

        $want_order = $this->_compile_xorder_function(!!$reviewf);
        $order_data = [];
        $this->_scatter_data = [];

        foreach ($rowset as $prow) {
            $ps = $this->_paper_style($prow);
            $revs = $reviewf ? $reviewf->eval_indexer($prow) : [null];
            foreach ($revs as $rcid) {
                $rrow = $rcid ? $prow->review_by_user($rcid) : null;
                $x = $this->fx->eval_json($prow, $rcid);
                $y = $this->fy->eval_json($prow, $rcid);
                if ($x === null || $y === null) {
                    continue;
                }
                if ($this->_x_bool && !is_bool($x)) {
                    $this->_x_bool = false;
                }
                if ($this->_y_bool && !is_bool($y)) {
                    $this->_y_bool = false;
                }
                $id = $prow->paperId;
                if ($review_id && $rrow && $rrow->reviewOrdinal) {
                    $id .= unparse_latin_ordinal($rrow->reviewOrdinal);
                }
                if ($ps === self::REVIEWER_COLOR) {
                    $s = $this->reviewer_color[$x] ?? "";
                } else {
                    $s = $ps;
                }
                if ($this->_fx_type === Fexpr::FSEARCH) {
                    $xs = $this->_filter_queries($prow, $rrow);
                } else if ($this->_fx_type === Fexpr::FTAG) {
                    $xs = $this->_account_tags($prow);
                } else {
                    $xs = [$x];
                }
                if (!empty($xs)) {
                    if (!isset($this->_scatter_data[$s])) {
                        $this->_scatter_data[$s] = [];
                    }
                    $sdata =& $this->_scatter_data[$s];
                    foreach ($xs as $xv) {
                        $sdata[] = new Scatter_GraphData($xv, $y, $id);
                    }
                    if ($want_order) {
                        $order_data[$x][] = $reviewf
                            ? $this->fxorder->eval_extractor($prow, $rcid)
                            : $this->fxorder->eval_json($prow, $rcid);
                    }
                }
            }
        }

        $this->_resolve_xorder_data($order_data, !!$reviewf);
    }

    private function _combine_data(PaperInfoSet $rowset) {
        if ($this->fx->result_format() === Fexpr::FREVIEWER) {
            $this->_prepare_reviewer_color($this->user);
        }

        $this->fx->prepare_json();
        $this->fy->prepare_extractor();
        $index_type = $this->_indexed() ? $this->_index_type() : 0;
        $reviewf = null;
        if ($index_type !== 0) {
            $reviewf = $this->fx->prepare_indexer($index_type);
        }
        $order_data = null;
        if ($this->fxorder) {
            $order_data = [];
            $this->fxorder->prepare_extractor();
        }
        $review_id = $this->fx->indexed()
            && $this->fy->indexed()
            && ($index_type & Fexpr::IDX_PC) === 0;

        $data = [];
        foreach ($rowset as $prow) {
            $queries = $this->papermap[$prow->paperId];
            $ps = $this->_paper_style($prow);
            $revs = $reviewf ? $reviewf->eval_indexer($prow) : [null];
            foreach ($revs as $rcid) {
                $x = $this->fx->eval_json($prow, $rcid);
                if ($x === null) {
                    continue;
                }
                $rrow = $rcid ? $prow->review_by_user($rcid) : null;
                if ($rrow) {
                    $queries = $this->_filter_queries($prow, $rrow);
                }
                if ($ps === self::REVIEWER_COLOR) {
                    $s = $this->reviewer_color[$x] ?? "";
                } else {
                    $s = $ps;
                }
                $y = $this->fy->eval_extractor($prow, $rcid);
                $id = $prow->paperId;
                if ($review_id && $rrow && $rrow->reviewOrdinal) {
                    $id .= unparse_latin_ordinal($rrow->reviewOrdinal);
                }
                foreach ($queries as $q) {
                    if ($this->_fx_type === Fexpr::FSEARCH) {
                        $data[] = new BarElement_GraphData($q, $y, $id, $s, 0);
                    } else if ($this->_fx_type === Fexpr::FTAG) {
                        foreach ($this->_account_tags($prow) as $ta) {
                            $data[] = new BarElement_GraphData($ta, $y, $id, $s, $q);
                        }
                    } else {
                        $data[] = new BarElement_GraphData($x, $y, $id, $s, $q);
                    }
                }
                if ($order_data !== null) {
                    $order_data[$x][] = $this->fxorder->eval_extractor($prow, $rcid);
                }
            }
        }

        $is_sum = $this->fy->is_sumlike();
        usort($data, "BarElement_GraphData::compare");

        $this->_bar_data = [];
        $ndata = count($data);
        for ($i = 0; $i !== $ndata; ) {
            $d0 = $data[$i];
            $x = $d0->x;
            $ys = $ids = [];
            $s = $d0->style;
            $q = $d0->sx;
            do {
                $ys[] = $d0->ys;
                if (!$is_sum || $d0->ys[0]) {
                    $ids[] = $d0->id;
                }
                if ($s && $d0->style != $s) {
                    $s = "";
                }
                ++$i;
            } while ($i !== $ndata
                     && ($d0 = $data[$i])->x == $x
                     && (!$is_sum || $d0->style == $s)
                     && $d0->sx == $q);
            $y = $this->fy->eval_combiner($ys);
            if ($reviewf && !$this->fx->indexed()) {
                $ids = array_values(array_unique($ids));
            }
            $this->_bar_data[] = new Bar_GraphData($x, $y, $ids, $s, $q);
        }

        if ($order_data !== null) {
            $this->_xorder_data = [];
            foreach ($order_data as $x => $vs) {
                $v = $this->fxorder->eval_combiner($vs);
                $this->_xorder_data[] = new Order_GraphData($x, $v);
            }
        }
    }

    private function _valuemap_axes($format) {
        $axes = 0;
        if ((!$this->_fx_type && !$format)
            || ($this->_fx_type === Fexpr::FTAG && $format === Fexpr::FTAG)
            || ($this->_fx_type === Fexpr::FREVIEWER && $format === Fexpr::FREVIEWER)) {
            $axes |= 1;
        }
        if (($this->type & self::CDF) === 0
            && $this->fy->result_format() === $format) {
            $axes |= 2;
        }
        return $axes;
    }

    private function _valuemap_collect($axes) {
        assert(!!$axes);
        $vs = [];
        foreach ($this->_cdf_data ?? [] as $dx) {
            foreach ($dx->d as $d) {
                $vs[$d] = true;
            }
        }
        foreach ($this->_bar_data ?? [] as $d) {
            ($axes & 1) && $d->x !== null && ($vs[$d->x] = true);
            ($axes & 2) && $d->y !== null && ($vs[$d->y] = true);
        }
        foreach ($this->_scatter_data ?? [] as $dx) {
            foreach ($dx as $d) {
                ($axes & 1) && $d->x !== null && ($vs[$d->x] = true);
                ($axes & 2) && $d->y !== null && ($vs[$d->y] = true);
            }
        }
        return $vs;
    }

    private function _valuemap_rewrite($axes, $m) {
        assert(!!$axes);
        foreach ($this->_cdf_data ?? [] as $dx) {
            foreach ($dx->d as &$d) {
                array_key_exists($d, $m) && ($d = $m[$d]);
            }
            unset($d);
        }
        foreach ($this->_bar_data ?? [] as $d) {
            ($axes & 1) && array_key_exists($d->x, $m) && ($d->x = $m[$d->x]);
            ($axes & 2) && array_key_exists($d->y, $m) && ($d->y = $m[$d->y]);
        }
        foreach ($this->_scatter_data ?? [] as $dx) {
            foreach ($dx as $d) {
                ($axes & 1) && array_key_exists($d->x, $m) && ($d->x = $m[$d->x]);
                ($axes & 2) && array_key_exists($d->y, $m) && ($d->y = $m[$d->y]);
            }
        }
        if (($axes & 1) && $this->_xorder_data) {
            foreach ($this->_xorder_data as $d) {
                array_key_exists($d->x, $m) && ($d->x = $m[$d->x]);
            }
        }
        $this->_axis_remapped |= $axes;
    }

    private function _reviewer_reformat() {
        if (!($axes = $this->_valuemap_axes(Fexpr::FREVIEWER))
            || !($cids = $this->_valuemap_collect($axes))) {
            return;
        }
        $cids = array_filter(array_keys($cids), "is_numeric");
        $result = $this->conf->qe("select contactId, firstName, lastName, affiliation, email, roles, contactTags from ContactInfo where contactId ?a", $cids);
        $this->reviewers = [];
        while (($c = Contact::fetch($result, $this->conf))) {
            $this->reviewers[] = $c;
        }
        Dbl::free($result);
        usort($this->reviewers, $this->conf->user_comparator());
        $m = [];
        foreach ($this->reviewers as $i => $c) {
            $m[$c->contactId] = $i + 1;
        }
        $this->_valuemap_rewrite($axes, $m);
    }

    private function _revround_reformat() {
        if (!($axes = $this->_valuemap_axes(Fexpr::FROUND))
            || !($rs = $this->_valuemap_collect($axes)))
            return;
        $i = 0;
        $m = [];
        foreach ($this->conf->defined_rounds() as $n => $rname) {
            if ($rs[$n] ?? null) {
                $this->remapped_rounds[++$i] = $rname;
                $m[$n] = $i;
            }
        }
        $this->_valuemap_rewrite($axes, $m);
    }

    private function _tag_reformat() {
        if (!($axes = $this->_valuemap_axes(Fexpr::FTAG))
            || !$this->_valuemap_collect($axes)) {
            return;
        }
        uksort($this->tags, [$this->conf->collator(), "compare"]);
        $i = -1;
        $m = [];
        foreach ($this->tags as $ri) {
            $m[$ri] = ++$i;
        }
        $this->_valuemap_rewrite($axes, $m);
    }

    private function _xorder_rewrite() {
        if (!$this->_xorder_data) {
            return;
        }
        usort($this->_xorder_data, "Order_GraphData::compare");
        $xo = [];
        foreach ($this->_xorder_data as $i => $d) {
            $xo[$d->x] = $i + 1;
        }
        $this->_xorder_map = $xo;
        foreach ($this->_cdf_data ?? [] as $dx) {
            foreach ($dx->d as &$d) {
                $d = $xo[$d];
            }
            unset($d);
        }
        foreach ($this->_bar_data ?? [] as $d) {
            $d->x = $xo[$d->x];
        }
        foreach ($this->_scatter_data ?? [] as $dx) {
            foreach ($dx as $d) {
                $d->x = $xo[$d->x];
            }
        }
    }

    /** @return string */
    private function data_format() {
        if ($this->type & self::CDF) {
            return "cdf";
        } else if ($this->_fx_combine) {
            return "xyis";
        }
        return "style_xyi";
    }

    private function data() {
        if ($this->_cdf_data === null
            && $this->_bar_data === null
            && $this->_scatter_data === null) {
            // load data
            $paperIds = array_keys($this->papermap);
            $queryOptions = ["paperId" => $paperIds, "tags" => true];
            foreach ($this->fxs as $f) {
                $f->add_query_options($queryOptions);
            }
            $this->fy->add_query_options($queryOptions);
            if (($this->fx && $this->fx->indexed()) || $this->fy->indexed()) {
                $queryOptions["reviewSignatures"] = true;
            }

            $rowset = $this->conf->paper_set($queryOptions, $this->user);
            $rowset->apply_filter(function ($prow) {
                return $this->user->can_view_paper($prow);
            });

            if ($this->type & self::CDF) {
                $this->_cdf_data($rowset);
            } else if ($this->_fx_combine) {
                $this->_combine_data($rowset);
            } else {
                $this->_scatter_data($rowset);
            }
            $this->_reviewer_reformat();
            $this->_revround_reformat();
            $this->_tag_reformat();
            $this->_xorder_rewrite();
        }
        if ($this->type & self::CDF) {
            return $this->_cdf_data;
        } else if ($this->_fx_combine) {
            return $this->_bar_data;
        }
        return $this->_scatter_data;
    }

    /** @param 'x'|'y' $axis */
    function axis_json($axis) {
        $isx = $axis === "x";
        $j = ["orientation" => $axis];

        $counttype = $this->fx && $this->fx->indexed() ? "reviews" : "papers";
        if ($isx) {
            $j["label"] = $this->fx_expression;
        } else if ($this->type === self::FBARCHART) {
            $j["label"] = "fraction of {$counttype}";
            $j["fraction"] = true;
        } else if ($this->type === self::BARCHART
                   && $this->fy->expression === "sum(1)") {
            $j["label"] = "# {$counttype}";
        } else if ($this->type === self::OGIVE) {
            $j["label"] = "Cumulative count of {$counttype}";
            $j["raw"] = true;
        } else if ($this->type & self::CDF) {
            $j["label"] = "CDF of {$counttype}";
        } else {
            $j["label"] = $this->fy->expression;
        }

        $format = $isx ? $this->_fx_type : $this->fy->result_format();
        $ticks = $named_ticks = null;
        $rotate_y = null;
        if ($isx && $this->_fx_type === Fexpr::FSEARCH) {
            $named_ticks = [];
            foreach ($this->queries as $i => $q) {
                if ($this->searches[$i]) {
                    $named_ticks[] = $this->searches[$i]->main_term()->get_float("legend") ?? $q;
                } else {
                    $named_ticks[] = "(All)";
                }
            }
        } else if ($isx && $this->_fx_type === Fexpr::FTAG) {
            $tagger = new Tagger($this->user);
            $named_ticks = array_map(function ($t) use ($tagger) {
                return $tagger->unparse($t);
            }, array_keys($this->tags));
        } else if ($format === Fexpr::FREVIEWFIELD) {
            $field = $isx ? $this->fxs[0]->result_format_detail() : $this->fy->result_format_detail();
            if ($field instanceof Checkbox_ReviewField) {
                $named_ticks = ["no", "yes"];
            } else {
                assert($field instanceof Score_ReviewField);
                $ticks = ["score", $field->export_json(ReviewField::UJ_EXPORT)];
                if ($field->flip && $isx) {
                    $j["flip"] = true;
                }
                if ($field->is_numeric() || $field->is_single_character()) {
                    $rotate_y = false;
                }
            }
        } else if ($format === Fexpr::FSUBFIELD) {
            $field = $isx ? $this->fxs[0]->result_format_detail() : $this->fy->result_format_detail();
            assert($field instanceof Selector_PaperOption);
            $named_ticks = [];
            foreach ($field->values() as $i => $v) {
                $named_ticks[$i + 1] = $v;
            }
        } else {
            if ($format === Fexpr::FTAGVALUE
                && ($isx ? $this->_x_bool : $this->_y_bool)) {
                $format = Fexpr::FBOOL;
            }
            if ($format === Fexpr::FREVIEWER) {
                $named_ticks = [];
                foreach ($this->reviewers as $i => $r) {
                    $rd = ["text" => $this->user->name_text_for($r),
                           "search" => "re:" . $r->email];
                    if ($this->user->can_view_user_tags()
                        && ($colors = $r->viewable_color_classes($this->user))) {
                        $rd["color_classes"] = $colors;
                    }
                    $rd["id"] = $r->contactId;
                    $named_ticks[$i + 1] = $rd;
                }
            } else if ($format === Fexpr::FDECISION) {
                $named_ticks = [];
                foreach ($this->conf->decision_set() as $dec) {
                    $named_ticks[$dec->id] = $dec->name;
                }
            } else if ($format === Fexpr::FBOOL) {
                $named_ticks = ["no", "yes"];
            } else if ($format instanceof Selector_PaperOption) {
                $named_ticks = $format->values();
            } else if ($format === Fexpr::FROUND) {
                $named_ticks = $this->remapped_rounds;
            } else if ($format === Fexpr::FREVTYPE) {
                $named_ticks = ReviewForm::$revtype_names;
            } else if (is_int($format) && $format >= Fexpr::FDATE && $format <= Fexpr::FTIMEDELTA) {
                $ticks = ["time"];
            } else if ($isx && $this->_xorder_map) {
                if (isset($j["label"])) {
                    $j["label"] .= " order";
                } else {
                    $j["label"] = "order";
                }
            }
        }

        if ($isx && $this->_xorder_map && $named_ticks !== null) {
            $newticks = [];
            foreach ($named_ticks as $n => $x) {
                if (isset($this->_xorder_map[$n]))
                    $newticks[$this->_xorder_map[$n]] = $x;
            }
            $named_ticks = $newticks;
        }

        if ($ticks !== null) {
            $j["ticks"] = $ticks;
        } else if ($named_ticks !== null) {
            $j["ticks"] = ["named", $named_ticks];
            $j["discrete"] = true;
        }

        if ($this->_axis_remapped & ($isx ? 1 : 2)) {
            $j["reordered"] = true;
        }
        return $j;
    }

    function type_json() {
        $tj = [
            self::SCATTER => "scatter",
            self::DOT => "dot",
            self::CDF => "cdf",
            self::OGIVE => "cumfreq",
            self::BARCHART => "bar",
            self::FBARCHART => "fraction",
            self::BOXPLOT => "box"
        ];
        return $tj[$this->type] ?? null;
    }

    function graph_json() {
        $j = [
            "type" => $this->type_json(),
            "data_format" => $this->data_format(),
            "data" => $this->data(),
            "x" => $this->axis_json("x"),
            "y" => $this->axis_json("y")
        ];
        if ($this->type & self::CDF) {
            $j["cdf_tooltip_position"] = true;
        }
        return $j;
    }

    /** @return list<MessageItem> */
    function decorated_message_list() {
        $mis = [];
        foreach ($this->message_list() as $mi) {
            if ($mi->field === "fx") {
                $mi = $mi->with_prefix("X axis: ");
            } else if ($mi->field === "fy") {
                $mi = $mi->with_prefix("Y axis: ");
            } else if ($mi->field === "xorder") {
                $mi = $mi->with_prefix("Order: ");
            } else if (str_starts_with($mi->field ?? "", "q")) {
                $mi = $mi->with_prefix("Search: ");
            }
            $mis[] = $mi;
        }
        return $mis;
    }
}
