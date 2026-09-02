<?php
// formulagraph.php -- HotCRP class for drawing graphs
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class FormulaGraphDataset {
    /** @var string */
    public $q;
    /** @var ?string */
    public $t;
    /** @var string */
    public $style;
    /** @var ?string */
    public $in_field_suffix;
    /** @var ?string */
    public $field_suffix;

    /** @param string $q
     * @param ?string $t
     * @param string $style
     * @param ?string $field_suffix */
    function __construct($q, $t, $style, $field_suffix) {
        $this->q = $q;
        $this->t = $t;
        $this->style = $style;
        $this->field_suffix = $this->in_field_suffix = $field_suffix;
    }
}

class FormulaGraphAxis implements JsonSerializable {
    // part of export
    /** @var string */
    public $orientation;
    /** @var string */
    public $scale_class;
    /** @var ?string */
    public $label;
    /** @var list<FormulaGraphTick> */
    public $ticks = [];
    /** @var ?Score_ReviewField */
    public $review_field;
    /** @var ?list */
    public $order;
    /** @var ?list  per-position ordering values, rendered via the xorder axis */
    public $order_values;
    /** @var bool */
    public $flip = false;
    /** @var bool */
    public $fraction = false;
    /** @var bool */
    public $left_justify = false;

    // not part of export
    /** @var ?Formula */
    private $_formula;
    /** @var int  Fexpr format code */
    public $format_code;
    /** @var mixed */
    public $format_detail;
    /** @var bool */
    public $only_bool;
    /** @var array */
    public $seen = [];

    /** @param string $orientation
     * @param int|Formula $format */
    function __construct($orientation, $format) {
        $this->orientation = $orientation;
        if (is_int($format)) {
            $this->format_code = $format;
        } else {
            $this->_formula = $format;
            $this->format_code = $format->format();
            $this->format_detail = $format->format_detail();
        }
    }

    /** @return ?FormulaGraphAxis */
    function accountant() {
        if ($this->format_code === Fexpr::FROUND
            || $this->format_code === Fexpr::FREVIEWER) {
            return $this;
        }
        // FTAG is accounted separately
        return null;
    }

    /** @param mixed $v */
    function account($v) {
        if (is_int($v) && !isset($this->seen[$v])) {
            $this->seen[$v] = null;
        }
    }

    private function _complete_reviewer(Conf $conf, Contact $viewer) {
        $conf->prefetch_users_by_id(array_keys($this->seen));
        $us = [];
        foreach (array_keys($this->seen) as $uid) {
            if (($u = $conf->user_by_id($uid, Contact::SLICE_MINIMAL)))
                $us[] = $u;
        }
        usort($us, $conf->user_comparator());
        foreach ($us as $u) {
            $name = $viewer->name_text_for($u);
            $this->ticks[] = $t = new FormulaGraphTick($u->contactId, $name);
            $t->search = "re:{$u->email}";
            if (($cc = $u->viewable_color_classes($viewer))) {
                $t->color_classes = $cc;
            }
        }
    }

    private function _complete_round(Conf $conf, Contact $viewer) {
        foreach ($conf->defined_rounds() as $n => $rname) {
            if ($this->seen[$n] ?? null) {
                $this->ticks[] = new FormulaGraphTick($n, $rname);
            }
        }
    }

    private function _complete_tag(Conf $conf) {
        $collator = $conf->collator();
        usort($this->ticks, function ($a, $b) use ($collator) {
            return $collator->compare($a->text, $b->text);
        });
    }

    private function _complete_review_field() {
        if ($this->format_detail instanceof Checkbox_ReviewField) {
            $this->_complete_bool();
        } else {
            assert($this->format_detail instanceof Score_ReviewField);
            $this->scale_class = "review_field";
            $this->review_field = $this->format_detail;
            $this->flip = $this->review_field->flip && $this->orientation === "x";
        }
    }

    private function _complete_submission_field() {
        assert($this->format_detail instanceof Selector_PaperOption);
        foreach ($this->format_detail->values() as $i => $v) {
            $this->ticks[] = new FormulaGraphTick($i + 1, $v);
        }
    }

    private function _complete_decision(Conf $conf) {
        foreach ($conf->decision_set() as $dec) {
            $this->ticks[] = new FormulaGraphTick($dec->id, $dec->name);
        }
    }

    private function _complete_bool() {
        $this->ticks[] = new FormulaGraphTick(false, "no");
        $this->ticks[] = new FormulaGraphTick(true, "yes");
    }

    function complete(Conf $conf, Contact $viewer) {
        if ($this->format_code === Fexpr::FTAG) {
            $this->_complete_tag($conf);
        } else if ($this->format_code === Fexpr::FREVIEWER) {
            $this->_complete_reviewer($conf, $viewer);
        } else if ($this->format_code === Fexpr::FROUND) {
            $this->_complete_round($conf, $viewer);
        } else if ($this->format_code === Fexpr::FREVIEWFIELD) {
            $this->_complete_review_field();
        } else if ($this->format_code === Fexpr::FSUBFIELD) {
            $this->_complete_submission_field();
        } else if ($this->format_code === Fexpr::FTAGVALUE) {
            if ($this->only_bool) {
                $this->_complete_bool();
            }
        } else if ($this->format_code === Fexpr::FDECISION) {
            $this->_complete_decision($conf);
        } else if ($this->format_code === Fexpr::FBOOL) {
            $this->_complete_bool();
        } else if (is_int($this->format_code)
                   && $this->format_code >= Fexpr::FDATE
                   && $this->format_code <= Fexpr::FTIMEDELTA) {
            $this->scale_class = "time";
        } else if ($this->format_code === Fexpr::FPID) {
            $this->scale_class = "pid";
        }
        if (!empty($this->ticks)) {
            $this->scale_class = "ordinal";
            $this->left_justify = true;
        } else if (!$this->scale_class) {
            $this->scale_class = "linear";
        }
    }

    /** @param list<Order_GraphData> */
    function apply_order($order) {
        // arrange order first by order value, then by current position
        $xmap = [];
        if (!empty($this->ticks)) {
            foreach ($this->ticks as $i => $t) {
                $xmap[(string) $t->value] = $i;
            }
        }
        usort($order, function ($a, $b) use ($xmap) {
            return $a->y <=> $b->y
                ? : ($xmap[(string) $a->x] ?? $a->x) <=> ($xmap[(string) $b->x] ?? $b->x);
        });
        if (!empty($this->ticks)) {
            $m = [];
            foreach ($order as $i => $ord) {
                $m[$ord->x] = $i;
            }
            usort($this->ticks, function ($a, $b) use ($m) {
                return ($m[$a->value] ?? INF) <=> ($m[$b->value] ?? INF);
            });
        } else {
            $this->order = array_map(function ($ord) { return $ord->x; }, $order);
        }
        // per-position ordering values (rendered via the sidecar xorder axis)
        $this->order_values = array_map(function ($ord) { return $ord->y; }, $order);
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $j = [
            "orientation" => $this->orientation,
            "scale_class" => $this->scale_class
        ];
        if ($this->label !== null) {
            $j["label"] = $this->label;
        }
        if (!empty($this->ticks)) {
            $j["ticks"] = $this->ticks;
        } else if ($this->review_field) {
            $j["review_field"] = $this->review_field->export_json(ReviewField::UJ_EXPORT);
        }
        if ($this->flip) {
            $j["flip"] = true;
        }
        if ($this->fraction) {
            $j["fraction"] = true;
        }
        if ($this->order) {
            $j["order"] = $this->order;
        }
        if ($this->order_values !== null) {
            $j["order_values"] = $this->order_values;
        }
        if ($this->_formula) {
            $j["expression"] = $this->_formula->expression;
            if (($anno = $this->_formula->expression_annotations())) {
                $j["expression_annotations"] = $anno;
            }
        }
        return $j;
    }
}

class FormulaGraphTick implements JsonSerializable {
    /** @var int|string */
    public $value;
    /** @var string */
    public $text;
    /** @var ?string */
    public $color_classes;
    /** @var ?string */
    public $search;

    /** @param int|string $value
     * @param string $text */
    function __construct($value, $text) {
        $this->value = $value;
        $this->text = $text;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $j = ["value" => $this->value, "text" => $this->text];
        if ($this->color_classes !== null) {
            $j["color_classes"] = $this->color_classes;
        }
        if ($this->search !== null) {
            $j["search"] = $this->search;
        }
        return $j;
    }
}

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
    /** @var list<int|float|bool> */
    public $vs = [];

    /** @param int|float|bool $x */
    function __construct($x) {
        $this->x = $x;
    }
}

class FormulaGraph extends MessageSet {
    // graph types
    // bitmask of basic type (data representation, collection strategy)
    const GT_SCATTER = 0x01;        // scatterplot (data points coalesce)
    const GT_CDF = 0x02;            // cdf
    const GT_BARCHART = 0x04;       // barchart
    const GT_BOXPLOT = 0x08;        // boxplot (median+mean, whiskers)
    const GT_DOT = 0x10;            // dot plot (one mark per data point, perturbed to not overlap)
    const GT_BLANK = 0x20;          // nothing
    // subtypes: one GT_ bit + uniqueifier
    const GTS_FBARCHART = 0x10004;  // fractional barchart
    const GTS_OGIVE = 0x10002;      // like CDF but Y axis is count, not fraction
    const GTS_MULTICDF = 0x20002;   // one CDF line per Y-formula value
    const GTS_LDOT = 0x10010;       // labeled dot plot

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
    /** @var int
     * @readonly */
    public $gtype = 0;
    /** @var Formula */
    public $fx;
    /** @var list<Formula> */
    private $fxs;
    /** @var string */
    private $fx_expression;
    /** @var bool */
    private $fx_annotatable;
    /** @var Formula */
    public $fy;
    /** @var ?Formula */
    public $fxorder;
    /** @var bool */
    private $_prepared = false;
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
    /** @var FormulaGraphAxis */
    private $_x_axis;
    /** @var FormulaGraphAxis */
    private $_y_axis;
    /** @var FormulaGraphAxis */
    private $_series_axis;
    /** @var int */
    private $_index_type;
    /** @var ?array<int,string> */
    private $reviewer_color;
    /** @var array<string,list<Scatter_GraphData>> */
    private $_scatter_data;
    /** @var list<Bar_GraphData> */
    private $_bar_data;
    /** @var list<CDF_GraphData> */
    private $_cdf_data;
    /** @var array<string,Order_GraphData> */
    private $_xorder_data = [];
    /** @var 0|1|2|3 */
    private $_axis_nonempty = 0;

    /** @param string $s
     * @return ?array{int,string} */
    static function graph_type_prefix($s) {
        if (!preg_match('/\A\s*+(cdf(?![-\w(])|)((?:ogive|cumfreq|cumulativefrequency)(?![-\w])|)((?:count|bars?|barchart)(?![-\w(])|)((?:stack|fraction)(?![-\w(])|)((?:box|boxplot)(?![-\w(])|)(scatter(?:plot|)(?![-\w(])|)((?:numdot|ldot|dotlabel)(?:plot|s|)(?![-\w(])|)(dot(?:plot|s|)(?![-\w(])|)(multicdf(?![-\w(])|)(?![-\w(])\s*+/', $s, $m)) {
            return null;
        } else if ($m[1]) {
            return [self::GT_CDF, $m[0]];
        } else if ($m[2]) {
            return [self::GTS_OGIVE, $m[0]];
        } else if ($m[3]) {
            return [self::GT_BARCHART, $m[0]];
        } else if ($m[4]) {
            return [self::GTS_FBARCHART, $m[0]];
        } else if ($m[5]) {
            return [self::GT_BOXPLOT, $m[0]];
        } else if ($m[6]) {
            return [self::GT_SCATTER, $m[0]];
        } else if ($m[7]) {
            return [self::GTS_LDOT, $m[0]];
        } else if ($m[8]) {
            return [self::GT_DOT, $m[0]];
        } else if ($m[9]) {
            return [self::GTS_MULTICDF, $m[0]];
        }
        return null;
    }

    /** @param ?string $gtype
     * @param string $fx
     * @param string $fy */
    function __construct(Contact $user, $gtype, $fx, $fy) {
        $this->conf = $user->conf;
        $this->user = $user;
        $fy = $fy ?? "";
        $this->set_gtype($gtype);
        $this->infer_gtype($fy);
        $this->set_x($fx);
        $this->set_y($fy);
    }

    /** @param string $gtype
     * @return $this */
    function set_gtype($gtype) {
        assert(!$this->_prepared);
        if (trim((string) $gtype) !== "") {
            $gtx = self::graph_type_prefix($gtype);
            if ($gtx && $gtx[1] === $gtype) {
                $this->gtype = $gtx[0];
            } else {
                $this->error_at("gtype", "<0>Graph type not found");
            }
        }
        return $this;
    }

    /** @param string &$fy
     * @return $this */
    function infer_gtype(&$fy) {
        assert(!$this->_prepared);
        if (($gtx = self::graph_type_prefix($fy))) {
            if ($this->gtype === 0 || $this->gtype === $gtx[0]) {
                $this->gtype = $gtx[0];
            } else {
                $this->warning_at("gtype", "<0>Graph type and inferred Y-axis graph type disagree");
                $this->warning_at("y");
            }
            $fy = ltrim(substr($fy, strlen($gtx[1])));
        }
        return $this;
    }

    /** @param ?string $s
     * @param string $field
     * @return Formula */
    private function _make_formula($s, $field) {
        $f = Formula::make_indexed($this->user, $s);
        foreach ($f->message_list() as $mi) {
            $this->append_item($mi->with_field($field));
        }
        return $f;
    }

    /** @param ?string $fx
     * @return $this */
    function set_x($fx) {
        assert(!$this->_prepared);
        $this->fx_expression = $fx;
        $this->fx_annotatable = false;
        $this->fxs = [];
        if (trim($fx ?? "") === "") {
            // no formula
        } else if (preg_match('/\A(sort|order|rorder)\s+(\S.*)\z/i', $fx, $m)) {
            if (strcasecmp($m[1], "rorder") === 0) {
                $m[2] = "-($m[2])";
            }
            $this->fxs[] = $this->_make_formula("pid", "x");
            $this->set_xorder($m[2]);
        } else if (strcasecmp($fx, "dataset") === 0
                   || strcasecmp($fx, "query") === 0
                   || strcasecmp($fx, "search") === 0) {
            $this->fxs[] = $this->_make_formula("0", "x");
            $this->_fx_type = Fexpr::FSEARCH;
        } else if (strcasecmp($fx, "tag") === 0) {
            $this->fxs[] = $this->_make_formula("0", "x");
            $this->_fx_type = Fexpr::FTAG;
        } else {
            while (true) {
                $fx = preg_replace('/\A\s*+;*+\s*+/', '', $fx);
                if ($fx === "") {
                    break;
                }
                $pos = Formula::span_maximal_formula($fx);
                $this->fxs[] = $this->_make_formula(substr($fx, 0, $pos), "x");
                $fx = substr($fx, $pos);
            }
            $this->fx_annotatable = true;
        }
        return $this;
    }

    /** @param ?string $fy
     * @return $this */
    function set_y($fy) {
        assert(!$this->_prepared);
        if (trim($fy ?? "") === "") {
            $this->fy = null;
        } else {
            $this->fy = $this->_make_formula($fy, "y");
        }
        return $this;
    }

    /** @param ?string $xorder
     * @return $this */
    function set_xorder($xorder) {
        assert(!$this->_prepared);
        if (trim($xorder ?? "") === "") {
            $this->fxorder = null;
        } else {
            $this->fxorder = $this->_make_formula($xorder, "xorder");
        }
        return $this;
    }

    /** @return list<FormulaGraphDataset> */
    static function parse_datasets(Qrequest $qreq) {
        $datasets = [];
        // The `t` (search collection) applies to every series; a per-series
        // `t{$i}` overrides it for that series.
        $t = $qreq->t ?? null;
        for ($i = 1; isset($qreq["q{$i}"]); ++$i) {
            $q = trim($qreq["q{$i}"]);
            $q = $q === "" || $q === "(All)" ? "" : $q;
            $datasets[] = new FormulaGraphDataset($q, $qreq["t{$i}"] ?? $t, (string) $qreq["s{$i}"], "{$i}");
        }
        if (empty($datasets) && isset($qreq->q)) {
            $q = trim($qreq->q);
            $q = $q === "" || $q === "(All)" ? "" : $q;
            $datasets[] = new FormulaGraphDataset($q, $t, (string) $qreq->s, "");
        } else if (empty($datasets)) {
            $datasets[] = new FormulaGraphDataset("", $t, "", "");
        }
        // remove redundant and intended-to-be-deleted queries
        $i = 0;
        while ($i < count($datasets) - 1) {
            if ($datasets[$i]->q === $datasets[$i + 1]->q) {
                array_splice($datasets, $i + 1, 1);
            } else if ($datasets[$i]->q === "" && $i !== 0) {
                array_splice($datasets, $i, 1);
            } else {
                ++$i;
            }
        }
        if (count($datasets) > 1 && $datasets[count($datasets) - 1]->q === "") {
            array_pop($datasets);
        }
        // reset field suffixes to account for that
        foreach ($datasets as $i => $ds) {
            if ($ds->field_suffix !== "" && $ds->field_suffix != $i + 1) {
                $ds->field_suffix = (string) ($i + 1);
            }
        }
        return $datasets;
    }

    /** @param FormulaGraphDataset $dataset */
    function add_dataset($dataset) {
        $qn = count($this->queries);
        $q = strcasecmp(trim($dataset->q), "all") === 0 ? "" : $dataset->q;
        $this->queries[] = $q;
        $style = $dataset->style;
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
        $psearch = new PaperSearch($this->user, ["q" => $q, "t" => $dataset->t]);
        foreach ($psearch->paper_ids() as $pid) {
            $this->papermap[$pid][] = $qn;
        }
        foreach ($psearch->message_list() as $mi) {
            if ($dataset->field_suffix !== null) {
                $mi = $mi->with_field("q" . $dataset->field_suffix);
            }
            $this->append_item($mi);
        }
        $this->searches[] = $q !== "" ? $psearch : null;
    }

    /** @return bool */
    function prepare() {
        if ($this->_prepared) {
            return !$this->has_error();
        }
        $this->_prepared = true;
        $this->_index_type = 0;

        // prepare graph type
        $this->gtype = $this->gtype ? : self::GT_SCATTER;

        // prepare X-axis formulas
        if (empty($this->fxs)) {
            $this->error_at("x", $this->conf->_("<0>Entry required"));
            $this->fxs[] = $this->_make_formula("null", "x");
        } else if (count($this->fxs) > 1 && ($this->gtype & self::GT_CDF) === 0) {
            $this->warning_at("x", $this->conf->_("<0>Surplus formulas ignored"));
            $this->fxs = [$this->fxs[0]];
        }
        foreach ($this->fxs as $i => $f) {
            if (!$f->ok()) {
                continue;
            }
            assert($f->format() !== 0);
            if ($i === 0) {
                $this->_fx_type = $this->_fx_type ? : $f->format();
            } else if ($this->_fx_type !== $f->format()
                       || ($this->_fx_type === Fexpr::FREVIEWFIELD
                           && $this->fxs[0]->format_detail() !== $f->format_detail())) {
                $this->error_at("x", "<0>X axis formulas must all use the same units");
                $this->_fx_type = 0;
            } else if ($i !== 0 && $f->index_type() !== $this->fxs[0]->index_type()) {
                $this->error_at("x", "<0>X axis formulas must all access the same kind of data");
            }
        }
        if (($this->gtype & self::GT_CDF) !== 0
            && $this->_fx_type === Fexpr::FTAG) {
            $this->error_at("x", "<0>Tag axis incompatible with this graph type");
        }
        $this->fx = count($this->fxs) === 1 ? $this->fxs[0] : null;

        // prepare Y-axis formula
        if (($this->gtype & self::GT_BARCHART) !== 0) {
            $this->_fx_combine = true;
            if ($this->gtype === self::GTS_FBARCHART && $this->fy) {
                $this->warning_at("y", "<0>Formula ignored for this graph type");
                $this->fy = null;
            }
            $this->fy = $this->fy ?? $this->_make_formula("sum(1)", "y");
        } else if ($this->gtype === self::GTS_OGIVE || $this->gtype === self::GT_CDF) {
            if ($this->fy) {
                $this->warning_at("y", "<0>Formula ignored for this graph type");
            }
            $this->fy = $this->_make_formula("null", "y");
        } else if (!$this->fy) {
            $this->error_at("y", "<0>Entry required");
            $this->fy = $this->_make_formula("null", "y");
        }

        // infer combining
        if ($this->has_error()) {
            $this->_fx_combine = false;
        } else if ($this->gtype === self::GT_SCATTER
                   && $this->fx->indexed()
                   && $this->fy->support_combiner()) {
            $this->_fx_combine = true;
        }
        if ($this->_fx_combine) {
            if ($this->fy->format() === Fexpr::FBOOL) {
                $this->fy = Formula::make_indexed($this->user, "sum({$this->fy->expression})");
            }
            if (!$this->fy->support_combiner()) {
                $this->error_at("y", "<0>Y axis formula incompatible with this chart type");
                $this->inform_at("y", "<0>Try an aggregate function like ‘sum({$this->fy->expression})’.");
                $this->fy = Formula::make_indexed($this->user, "sum(null)");
            }
        }

        // mark blank if error
        if ($this->has_error()) {
            $this->gtype = self::GT_BLANK;
            $this->fx = $this->fxs[0];
            $this->_fx_combine = false;
            return false;
        }
        // From here on, the graph will succeed.

        // set index type
        if ($this->fxs[0]->indexed()
            || $this->fy->indexed()
            || ($this->_fx_combine && $this->fy->extractor_indexed())) {
            $fxorder = $this->fxorder && $this->fxorder->support_combiner() ? $this->fxorder : null;
            $this->_index_type = Formula::combine_index_types(
                $this->user,
                $this->fxs[0]->index_type(),
                $this->_fx_combine ? $this->fy->extractor_index_type() : $this->fy->index_type(),
                $fxorder ? $fxorder->extractor_index_type() : 0
            );
        }

        // check X order
        if ($this->fxorder
            && ($this->_index_type !== 0
                ? !$this->fxorder->support_combiner()
                : $this->fxorder->indexed())) {
            $this->warning_at("xorder", "<0>Incompatible X order formula ignored");
            $this->fxorder = null;
        }

        // apply index types and return
        if ($this->_index_type !== 0) {
            foreach ($this->fxs as $fx) {
                $fx->set_external_index_type($this->_index_type);
            }
            $this->fy->set_external_index_type($this->_index_type);
            if ($this->fxorder) {
                $this->fxorder->set_external_index_type($this->_index_type);
            }
        }
        return true;
    }


    /** @return string */
    function fx_expression() {
        return $this->fx_expression;
    }

    /** @return ?string */
    function xorder_expression() {
        return $this->fxorder ? $this->fxorder->expression : null;
    }

    /** @return string */
    function annotated_fx_expression_h() {
        if (!$this->fx_annotatable) {
            return htmlspecialchars($this->fx_expression);
        }
        $x = [];
        foreach ($this->fxs as $f) {
            $x[] = $f->annotated_expression_h();
        }
        return join("; ", $x);
    }

    /** @return int */
    function fx_format() {
        return $this->_fx_type;
    }

    /** @return int */
    function index_type() {
        assert($this->_index_type !== null);
        return $this->_index_type;
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

    /** @param int $index_type
     * @return bool */
    private function _compile_xorder_function($index_type) {
        if (!$this->fxorder) {
            return false;
        }
        if ($index_type !== 0) {
            $this->fxorder->prepare_extractor();
        } else {
            $this->fxorder->prepare_json();
        }
        return true;
    }

    private function _add_xorder_data($x, $index_type, $prow, $rcid) {
        $xo = &$this->_xorder_data[(string) $x];
        $xo = $xo ?? new Order_GraphData($x);
        $xo->vs[] = $index_type
            ? $this->fxorder->eval_extractor($prow, $rcid)
            : $this->fxorder->eval_json($prow, $rcid);
    }

    /** @param int $index_type */
    private function _resolve_xorder_data($index_type) {
        if (($fxo = $this->fxorder)) {
            foreach ($this->_xorder_data as $xo) {
                $xo->y = $index_type ? $fxo->eval_combiner($xo->vs) : $xo->vs[0];
                $xo->vs = null;
            }
        }
    }

    /** @param Formula $fx
     * @return list<CDF_GraphData> */
    private function _cdf_data_one_fx($fx, $qcolors, $dashp, PaperInfoSet $rowset) {
        $index_type = $this->_index_type;
        $fx->prepare_json();
        $index_type && $fx->prepare_indexer();
        $account_x = $this->_x_axis->accountant();
        $multi = $this->gtype === self::GTS_MULTICDF;
        if ($multi) {
            $this->fy->prepare_json();
            $account_series = $this->_series_axis->accountant();
        } else {
            $account_series = null;
        }
        $want_order = $this->_compile_xorder_function($index_type);
        $x_bool = true;

        // For a plain CDF `$data[$q]` is a flat list of x values, one line per
        // dataset (or, for multicdf, dataset/split value).
        $data = [];
        $sv = null;
        foreach ($rowset as $prow) {
            $revs = $index_type ? $fx->eval_indexer($prow) : [null];
            $queries = $this->papermap[$prow->paperId];
            foreach ($revs as $rcid) {
                if (($x = $fx->eval_json($prow, $rcid)) === null) {
                    continue;
                }
                if ($multi
                    && ($sv = $this->fy->eval_json($prow, $rcid)) === null) {
                    continue;
                }
                $x_bool = $x_bool && is_bool($x);
                $account_x && $account_x->account($x);
                if ($index_type) {
                    $rrow = $fx->indexer_to_rrow($prow, $rcid);
                    $queries = $this->_filter_queries($prow, $rrow);
                }
                if ($multi) {
                    $account_series && $account_series->account($sv);
                    $svk = is_bool($sv) ? ($sv ? "yes" : "no") : (string) $sv;
                    foreach ($queries as $q) {
                        $data["{$q},{$svk}"][] = $x;
                    }
                } else if ($this->_fx_type === Fexpr::FSEARCH) {
                    foreach ($queries as $q) {
                        $data[0][] = $q;
                    }
                } else {
                    foreach ($queries as $q) {
                        $data[$q][] = $x;
                    }
                }
                if ($want_order) {
                    $this->_add_xorder_data($x, $index_type, $prow, $rcid);
                }
            }
        }
        $this->_x_axis->only_bool = $x_bool;

        $fxlabel = count($this->fxs) > 1 ? $fx->expression : "";
        $result = [];
        foreach ($data as $q => $ds) {
            if ($multi) {
                $c = strpos($q, ",");
                $sv = substr($q, $c + 1);
                $q = substr($q, 0, $c);
            }
            $d = new CDF_GraphData($ds);
            if (($multi && ($s = $this->_multicdf_group_color_classes($sv)))
                || ($s = $qcolors[$q])) {
                $d->className = $s;
            }
            $dlabel = "";
            if (($this->queries[$q] ?? null) && count($this->queries) > 1) {
                $dlabel = $this->queries[$q];
            }
            if ($dlabel && $fxlabel) {
                $dlabel = rtrim("{$fxlabel} {$dlabel}");
            }
            if ($multi) {
                $dlabel .= ($dlabel === "" ? "" : " · ")
                    . $this->_multicdf_group_label($sv);
            }
            if ($dlabel !== "") {
                $d->label = $dlabel;
            }
            if ($dashp) {
                $d->dashpattern = $dashp;
            }
            $result[] = $d;
        }
        return $result;
    }

    private function _multicdf_group_color_classes($sv) {
        $fmt = $this->fy->format();
        if ($fmt === Fexpr::FREVIEWER) {
            $cc = $this->fy->value_format()->color_classes((int) $sv);
        } else if ($fmt === Fexpr::FREVIEWFIELD) {
            $cc = $this->fy->value_format()->color_classes((float) $sv);
        } else {
            $cc = null;
        }
        return ($cc ?? "") === "" ? null : $cc;
    }

    /** Render one multicdf split value as a line label, honoring the split
     * formula's format.
     * @param string $sv
     * @return string */
    private function _multicdf_group_label($sv) {
        $fmt = $this->fy->format();
        if ($fmt === Fexpr::FREVIEWER) {
            $u = $this->conf->user_by_id((int) $sv);
            return $u ? $this->user->name_text_for($u) : "#{$sv}";
        } else if ($fmt === Fexpr::FDECISION) {
            $dec = $this->conf->decision_set()->get((int) $sv);
            return $dec ? $dec->name : (string) $sv;
        } else if ($fmt === Fexpr::FREVTYPE) {
            return ReviewForm::$revtype_names[(int) $sv] ?? (string) $sv;
        }
        return (string) $sv;
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

        // compute axes
        $this->_x_axis = new FormulaGraphAxis("x", $this->fxs[0]);
        $this->_y_axis = new FormulaGraphAxis("y", Fexpr::FNUMERIC);
        if ($this->gtype === self::GTS_MULTICDF) {
            $this->_series_axis = new FormulaGraphAxis("series", $this->fy);
        }

        // compute data
        $this->_cdf_data = [];
        $dashps = [null, [10,5], [5,5], [1,1]];
        foreach ($this->fxs as $i => $fx) {
            $dashp = $dashps[$i % count($dashps)];
            array_push($this->_cdf_data, ...$this->_cdf_data_one_fx($fx, $qcolors, $dashp, $rowset));
        }

        $this->_resolve_xorder_data($this->_index_type);
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

    /** @return list<string> */
    private function _account_tags(PaperInfo $prow) {
        assert($this->_fx_type === Fexpr::FTAG);
        $tags = Tagger::split_unpack($prow->viewable_tags($this->user));
        $r = [];
        foreach ($tags as $ti) {
            $tag = $ti[0];
            $ltag = strtolower($tag);
            $seen =& $this->_x_axis->seen[$ltag];
            if (!$seen) {
                $seen = true;
                if (($tw = strpos($tag, "~")) > 0) {
                    if (intval($tag) !== $this->user->contactId) {
                        continue;
                    }
                    $tag = substr($tag, $tw);
                }
                $this->_x_axis->ticks[] = new FormulaGraphTick($ltag, $tag);
            }
            $r[] = $ltag;
        }
        return $r;
    }

    private function _search_x_axis() {
        $axis = new FormulaGraphAxis("x", Fexpr::FSEARCH);
        foreach ($this->searches as $i => $s) {
            if ($s) {
                $axis->ticks[] = new FormulaGraphTick($i,
                    $s->main_term()->get_float("legend") ?? $this->queries[$i]);
            } else {
                $axis->ticks[] = new FormulaGraphTick($i, "(All)");
            }
        }
        return $axis;
    }

    private function _scatter_data(PaperInfoSet $rowset) {
        if ($this->fx->format() === Fexpr::FREVIEWER
            && ($this->gtype & self::GT_BOXPLOT) !== 0) {
            $this->_prepare_reviewer_color($this->user);
        }

        $index_type = $this->_index_type;
        $review_id = $index_type !== 0
            && ($index_type & Fexpr::IDXM_REVIEW) === $index_type;
        $want_rrow = $review_id || $this->_fx_type === Fexpr::FSEARCH;

        $this->fx->prepare_json();
        $this->fy->prepare_json();
        $index_type && $this->fx->prepare_indexer();
        $want_order = $this->_compile_xorder_function($index_type);
        $this->_scatter_data = [];

        if ($this->_fx_type === Fexpr::FSEARCH) {
            $this->_x_axis = $this->_search_x_axis();
        } else if ($this->_fx_type === Fexpr::FTAG) {
            $this->_x_axis = new FormulaGraphAxis("x", Fexpr::FTAG);
        } else {
            $this->_x_axis = new FormulaGraphAxis("x", $this->fx);
        }
        $this->_y_axis = new FormulaGraphAxis("y", $this->fy);
        $x_accountant = $this->_x_axis->accountant();
        $y_accountant = $this->_y_axis->accountant();
        $x_bool = $y_bool = true;

        foreach ($rowset as $prow) {
            $ps = $this->_paper_style($prow);
            $revs = $index_type ? $this->fx->eval_indexer($prow) : [null];
            foreach ($revs as $rcid) {
                $x = $this->fx->eval_json($prow, $rcid);
                $y = $this->fy->eval_json($prow, $rcid);
                if ($x === null || $y === null) {
                    $this->_axis_nonempty |= ($x === null ? 0 : 1) | ($y === null ? 0 : 2);
                    continue;
                }
                $x_bool = $x_bool && is_bool($x);
                $y_bool = $y_bool && is_bool($y);
                $rrow = $want_rrow ? $this->fx->indexer_to_rrow($prow, $rcid) : null;
                $id = $prow->paperId;
                if ($rrow && $review_id && $rrow->reviewOrdinal) {
                    $id .= unparse_latin_ordinal($rrow->reviewOrdinal);
                }
                if ($ps === self::REVIEWER_COLOR) {
                    $s = $this->reviewer_color[$x] ?? "";
                } else {
                    $s = $ps;
                }
                if ($s === "") {
                    $s = "none";
                }
                if ($this->_fx_type === Fexpr::FSEARCH) {
                    $xs = $this->_filter_queries($prow, $rrow);
                } else if ($this->_fx_type === Fexpr::FTAG) {
                    $xs = $this->_account_tags($prow);
                } else {
                    $x_accountant && $x_accountant->account($x);
                    $xs = [$x];
                }
                if (empty($xs)) {
                    continue;
                }
                $y_accountant && $y_accountant->account($y);
                $this->_axis_nonempty = 3;
                if (!isset($this->_scatter_data[$s])) {
                    $this->_scatter_data[$s] = [];
                }
                $sdata =& $this->_scatter_data[$s];
                foreach ($xs as $xv) {
                    $sdata[] = new Scatter_GraphData($xv, $y, $id);
                }
                if ($want_order) {
                    $this->_add_xorder_data($x, $index_type, $prow, $rcid);
                }
            }
        }

        $this->_x_axis->only_bool = $x_bool;
        $this->_y_axis->only_bool = $y_bool;
        $this->_resolve_xorder_data($index_type);
    }

    private function _combine_data(PaperInfoSet $rowset) {
        if ($this->fx->format() === Fexpr::FREVIEWER) {
            $this->_prepare_reviewer_color($this->user);
        }

        $index_type = $this->_index_type;
        $review_id = $index_type !== 0
            && ($index_type & Fexpr::IDXM_REVIEW) === $index_type;

        $this->fx->prepare_json();
        $this->fy->prepare_extractor();
        assert($this->fy->support_combiner());
        $index_type && $this->fx->prepare_indexer();
        $want_order = $this->_compile_xorder_function($index_type);

        if ($this->_fx_type === Fexpr::FSEARCH) {
            $this->_x_axis = $this->_search_x_axis();
        } else {
            $this->_x_axis = new FormulaGraphAxis("x", $this->fx);
        }
        $this->_y_axis = new FormulaGraphAxis("y", $this->fy);
        $x_accountant = $this->_x_axis->accountant();
        $y_accountant = $this->_y_axis->accountant();

        $data = [];
        foreach ($rowset as $prow) {
            $queries = $this->papermap[$prow->paperId];
            $ps = $this->_paper_style($prow);
            $revs = $index_type ? $this->fx->eval_indexer($prow) : [null];
            foreach ($revs as $rcid) {
                $x = $this->fx->eval_json($prow, $rcid);
                if ($x === null) {
                    continue;
                }
                $rrow = $this->fx->indexer_to_rrow($prow, $rcid);
                if ($index_type) {
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
                $x_accountant && $x_accountant->account($x);
                $y_accountant && $y_accountant->account($y);
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
                if ($want_order) {
                    $this->_add_xorder_data($x, $index_type, $prow, $rcid);
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
            if ($index_type && !$this->fx->indexed()) {
                $ids = array_values(array_unique($ids));
            }
            $this->_bar_data[] = new Bar_GraphData($x, $y, $ids, $s, $q);
        }

        $this->_resolve_xorder_data($index_type);
    }

    /** @return string */
    private function data_format() {
        if ($this->gtype & self::GT_CDF) {
            return "cdf";
        } else if ($this->_fx_combine) {
            return "xyis";
        }
        return "style_xyi";
    }

    private function _assign_data() {
        if ($this->gtype === self::GT_BLANK) {
            $this->_scatter_data(new PaperInfoSet($this->conf));
            return;
        }

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

        if ($this->gtype & self::GT_CDF) {
            $this->_cdf_data($rowset);
        } else if ($this->_fx_combine) {
            $this->_combine_data($rowset);
        } else {
            $this->_scatter_data($rowset);
        }
    }

    /** Explain why the graph will render nothing, if it will.
     *
     * The confusing case is a scatterplot whose axes each have values, but
     * never on the same datum -- for instance two review fields restricted to
     * different rounds by `exists_if`, which no single review can satisfy. */
    private function _check_empty_data() {
        if ($this->has_error()) {
            return;
        }
        if ($this->gtype & self::GT_CDF) {
            $empty = empty($this->_cdf_data);
        } else if ($this->_fx_combine) {
            $empty = empty($this->_bar_data);
        } else {
            $empty = empty($this->_scatter_data);
        }
        if (!$empty) {
            return;
        }
        if ($this->_axis_nonempty !== 3) {
            $this->warning_at(null, "<0>No data to graph");
            return;
        }
        $fx = $this->fx->expression;
        $fy = $this->fy->expression;
        if ($this->_index_type !== 0) {
            $this->warning_at(null, "<0>No review has values for both ‘{$fx}’ and ‘{$fy}’");
            if ($this->fx->indexed() && $this->fy->indexed()) {
                $this->inform_at(null, "<0>Try ‘avg({$fx})’ and ‘avg({$fy})’ to compare per-submission averages.");
            }
        } else {
            $this->warning_at(null, "<0>No submission has values for both ‘{$fx}’ and ‘{$fy}’");
        }
    }

    private function _data() {
        if ($this->_cdf_data === null
            && $this->_bar_data === null
            && $this->_scatter_data === null) {
            $this->_assign_data();
            $this->_check_empty_data();
        }
        if ($this->gtype & self::GT_CDF) {
            return $this->_cdf_data;
        } else if ($this->_fx_combine) {
            return $this->_bar_data;
        }
        return $this->_scatter_data;
    }

    /** @param FormulaGraphAxis $ax */
    private function axis_json($ax) {
        $axis = $ax->orientation;
        if ($ax->label === null) {
            $counttype = $this->fx && $this->fx->indexed()
                ? "reviews"
                : $this->conf->snouns[1];
            if ($axis === "x") {
                $ax->label = $this->fx_expression;
            } else if ($this->gtype === self::GTS_FBARCHART) {
                $ax->label = "fraction of {$counttype}";
                $ax->fraction = true;
            } else if ($this->gtype === self::GT_BARCHART
                       && $this->fy->expression === "sum(1)") {
                $ax->label = "# {$counttype}";
            } else if ($this->gtype === self::GTS_OGIVE) {
                $ax->label = "Cumulative count of {$counttype}";
            } else if ($this->gtype & self::GT_CDF) {
                $ax->label = "CDF of {$counttype}";
                $ax->fraction = true;
            } else {
                $ax->label = $this->fy->expression;
            }
            $ax->complete($this->conf, $this->user);
            if ($axis === "x" && $this->_xorder_data) {
                $ax->apply_order(array_values($this->_xorder_data));
            }
        }
        return $ax;
    }

    function gtype_json() {
        $this->prepare();
        $tj = [
            self::GT_SCATTER => "scatter",
            self::GT_DOT => "dot",
            self::GTS_LDOT => "ldot",
            self::GT_CDF => "cdf",
            self::GTS_OGIVE => "cumfreq",
            self::GT_BARCHART => "bar",
            self::GTS_FBARCHART => "fraction",
            self::GT_BOXPLOT => "box",
            self::GTS_MULTICDF => "cdf",
            self::GT_BLANK => "blank"
        ];
        return $tj[$this->gtype] ?? null;
    }

    function graph_json($j = []) {
        $this->prepare();
        $j["gtype"] = $this->gtype_json();
        $j["data_format"] = $this->data_format();
        $j["data"] = $this->_data();
        $j["x"] = $this->axis_json($this->_x_axis);
        $j["y"] = $this->axis_json($this->_y_axis);
        if ($this->_series_axis) {
            $j["series"] = $this->axis_json($this->_series_axis);
        }
        if (($this->gtype & self::GT_CDF) === 0) {
            if ($this->_index_type !== 0
                && ($this->_index_type & Fexpr::IDXM_REVIEW) === $this->_index_type) {
                $j["id_format"] = "rid";
            } else {
                $j["id_format"] = "pid";
            }
        }
        if ($this->fxorder && $this->_xorder_data) {
            // the ordering values are per-x-position, so they live on the x
            // axis; the sidecar `xorder` axis just carries their format + label
            $xoa = new FormulaGraphAxis("xorder", $this->fxorder);
            $xoa->label = $this->fxorder->expression;
            $xoa->complete($this->conf, $this->user);
            $j["xorder"] = $xoa;
        }
        if ($this->gtype & self::GT_DOT) {
            $j["mark_object_type"] = $this->index_type() ? "review" : "paper";
        } else if ($this->_fx_type === Fexpr::FREVIEWER
                   && ($this->_fx_combine
                       || ($this->gtype & self::GT_BOXPLOT))) {
            $j["mark_object_type"] = "user";
        } else if ($this->_fx_type === Fexpr::FPID) {
            $j["mark_object_type"] = "paper";
        }
        if ($this->gtype & self::GT_CDF) {
            $j["cdf_tooltip_position"] = true;
        }
        return $j;
    }

    /** @return list<MessageItem> */
    function decorated_message_list() {
        $mis = [];
        foreach ($this->message_list() as $mi) {
            if ($mi->status === MessageSet::INFORM || !$mi->message) {
                // do not decorate
            } else if ($mi->field === "x") {
                $mi = $mi->with_prefix("X axis: ");
            } else if ($mi->field === "y") {
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
