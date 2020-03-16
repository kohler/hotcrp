<?php
// formulagraph.php -- HotCRP class for drawing graphs
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class FormulaGraph extends MessageSet {
    // bitmasks
    const SCATTER = 1;
    const CDF = 2;
    const BARCHART = 4;
    const BOXPLOT = 8;

    const FBARCHART = 132; // 128 | BARCHART
    const RAWCDF = 130;    // 128 | CDF

    const REVIEWER_COLOR = 1;

    public $conf;
    public $user;
    public $type = 0;
    public $fx;
    private $fxs;
    private $fx_expression;
    public $fy;
    private $fx_type = 0;
    private $queries = [];
    private $_qstyles = [];
    private $_qstyles_bytag = [];
    private $_qstyle_index = 0;
    private $searches = [];
    private $papermap = [];
    private $reviewers = [];
    private $reviewer_color = false;
    private $remapped_rounds = [];
    private $tags = [];
    private $fxorder;
    private $_data;
    private $_xorder_data;
    private $_xorder_map;
    private $_axis_remapped = 0;

    function __construct(Contact $user, $gtype, $fx, $fy) {
        $this->conf = $user->conf;
        $this->user = $user;

        $gtype = simplify_whitespace($gtype);
        $fx = simplify_whitespace($fx);
        $fy = simplify_whitespace($fy);

        // graph type
        $fy_guess = false;
        $fy_gtype = $fy;
        if ($gtype === "") {
            $gtype = preg_replace('/\s+.*/', "", $fy);
            $fy_guess = true;
            $fy_gtype = ltrim(substr($fy, strlen($gtype)));
        }

        // Y axis expression
        if (strcasecmp($gtype, "cdf") == 0) {
            $this->type = self::CDF;
            $fy = "0";
        } else if (preg_match('/\A(?:raw-?cdf|cdf-?count|count-?cdf|cum-?count|cumulative-?count)\z/i', $gtype)) {
            $this->type = self::RAWCDF;
            $fy = "0";
        } else if (preg_match('/\A(?:count|bar|bars|barchart)\z/i', $gtype)) {
            $this->type = self::BARCHART;
            $fy = $fy_gtype ? : "sum(1)";
        } else if (preg_match('/\A(?:full-?area|full-?stack|stack|frac|fraction)\z/i', $gtype)) {
            $this->type = self::FBARCHART;
            $fy = "sum(1)";
        } else if (preg_match('/\A(?:box|boxplot|candlestick)\z/i', $gtype)) {
            $this->type = self::BOXPLOT;
            $fy = $fy_gtype;
        } else if (strcasecmp($gtype, "scatter") == 0) {
            $this->type = self::SCATTER;
            $fy = $fy_gtype;
        }

        $this->fy = new Formula($fy, Formula::ALLOW_INDEXED);
        $this->fy->check($this->user);
        if (!$this->type) {
            $this->type = self::SCATTER;
            if (!$this->fy->index_type() && $this->fy->support_combiner()) {
                $this->type = self::BARCHART;
            }
        }

        // X axis expression(s)
        $this->fx_expression = $fx;
        if (strcasecmp($fx, "query") == 0 || strcasecmp($fx, "search") == 0) {
            $this->fx = new Formula("0", Formula::ALLOW_INDEXED);
            $this->fx_type = Fexpr::FSEARCH;
        } else if (strcasecmp($fx, "tag") == 0) {
            $this->fx = new Formula("0", Formula::ALLOW_INDEXED);
            $this->fx_type = Fexpr::FTAG;
        } else if (!($this->type & self::CDF)) {
            $this->fx = new Formula($fx, Formula::ALLOW_INDEXED);
            if (!$this->fx->check($this->user)) {
                $this->error_at("fx", "X axis formula error: " . $this->fx->error_html());
            }
        } else {
            $this->fxs = [];
            while (true) {
                $fx = preg_replace('/\A\s*;*\s*/', '', $fx);
                if ($fx === "") {
                    break;
                }
                $pos = Formula::span_maximal_formula($fx);
                $this->fxs[] = $f = new Formula(substr($fx, 0, $pos), Formula::ALLOW_INDEXED);
                if (!$f->check($this->user)) {
                    $this->error_at("fx", "X axis formula error: " . $f->error_html());
                }
            }
        }

        if ($this->fy->error_html()) {
            $this->error_at("fy", "Y axis formula error: " . $this->fy->error_html());
        } else if (($this->type & self::BARCHART) && !$this->fy->support_combiner()) {
            $this->error_at("fy", "Y axis formula “" . htmlspecialchars($fy) . "” is unsuitable for bar charts, use an aggregate function like “sum(" . htmlspecialchars($fy) . ")”.");
            $this->fy = new Formula("sum(0)", Formula::ALLOW_INDEXED);
            $this->fy->check($this->user);
        } else if (($this->type & self::CDF) && $this->fx_type === Fexpr::FTAG) {
            $this->error_at("fy", "CDFs by tag don’t make sense.");
        }
    }

    static function parse_queries(Qrequest $qreq) {
        $queries = $styles = [];
        for ($i = 1; isset($qreq["q$i"]); ++$i) {
            $q = trim($qreq["q$i"]);
            $queries[] = $q === "" || $q === "(All)" ? "all" : $q;
            $styles[] = trim((string) $qreq["s$i"]);
        }
        if (empty($queries) && isset($qreq->q)) {
            $q = trim($qreq->q);
            $queries[] = $q === "" || $q === "(All)" ? "all" : $q;
            $styles[] = trim((string) $qreq->s);
        } else if (empty($queries))
            $queries[] = $styles[] = "";
        while (count($queries) > 1
               && $queries[count($queries) - 1] === $queries[count($queries) - 2]) {
            array_pop($queries);
            array_pop($styles);
        }
        if (count($queries) === 1 && $queries[0] === "all")
            $queries[0] = "";
        return [$queries, $styles];
    }

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
            if (($n = $this->_qstyle_index % 4))
                $style = "color" . $n;
            ++$this->_qstyle_index;
        }
        $this->_qstyles[] = $style;
        $psearch = new PaperSearch($this->user, array("q" => $q));
        foreach ($psearch->paper_ids() as $pid) {
            $this->papermap[$pid][] = $qn;
        }
        if (!empty($psearch->warnings)) {
            $this->error_at($fieldname, $psearch->warnings);
        }
        $this->searches[] = $q !== "" ? $psearch : null;
    }

    function set_xorder($xorder) {
        $this->fxorder = null;
        $xorder = simplify_whitespace($xorder);
        if ($xorder !== "" && $this->type !== self::SCATTER) {
            $fxorder = new Formula($xorder, Formula::ALLOW_INDEXED);
            $fxorder->check($this->user);
            if ($fxorder->error_html()) {
                $this->error_at("xorder", "X order formula error: " . $fxorder->error_html());
            } else if (!$fxorder->support_combiner()) {
                $this->error_at("xorder", "X order formula “" . htmlspecialchars($xorder) . "” is unsuitable, use an aggregate function.");
            } else {
                $this->fxorder = $fxorder;
            }
        }
    }

    function fx_expression() {
        return $this->fx_expression;
    }

    function fx_format() {
        return $this->fx_type ? : $this->fx->result_format();
    }

    function fx_combinable() {
        $format = $this->fx->result_format();
        return !$this->fx_type;
    }

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

    private function _cdf_data_one_fx($fx, $qcolors, $dashp, PaperInfoSet $rowset) {
        $data = [];

        $fxf = $fx->compile_json_function();
        $reviewf = null;
        if ($fx->indexed()) {
            $reviewf = Formula::compile_indexes_function($this->user, $this->fx->index_type());
        }

        foreach ($rowset->all() as $prow) {
            $revs = $reviewf ? $reviewf($prow, $this->user) : [null];
            $queries = $this->papermap[$prow->paperId];
            foreach ($revs as $rcid) {
                if (($x = $fxf($prow, $rcid, $this->user)) !== null) {
                    if ($rcid) {
                        $queries = $this->_filter_queries($prow, $prow->review_of_user($rcid));
                    }
                    if ($this->fx_type === Fexpr::FSEARCH) {
                        foreach ($queries as $q)
                            $data[0][] = $q;
                    } else {
                        foreach ($queries as $q)
                            $data[$q][] = $x;
                    }
                }
            }
        }

        $fxlabel = count($this->fxs) > 1 ? $fx->expression : "";
        foreach ($data as $q => &$d) {
            $d = (object) ["d" => $d];
            if (($s = $qcolors[$q])) {
                $d->className = $s;
            }
            $dlabel = "";
            if (get($this->queries, $q) && count($this->queries) > 1) {
                $dlabel = $this->queries[$q];
            }
            if ($dlabel || $fxlabel) {
                $d->label = rtrim("$fxlabel $dlabel");
            }
            if ($dashp) {
                $d->dashpattern = $dashp;
            }
        }
        unset($d);
        return $data;
    }
    private function _cdf_data(PaperInfoSet $rowset) {
        // calculate query styles
        $qcolors = $this->_qstyles;
        $need_anal = array_fill(0, count($qcolors), false);
        $nneed_anal = 0;
        foreach ($qcolors as $qi => $q) {
            if ($this->_qstyles_bytag[$qi]) {
                $qcolors[$qi] = [];
                $need_anal[$qi] = true;
                ++$nneed_anal;
            }
        }
        foreach ($rowset->all() as $prow) {
            if ($nneed_anal === 0) {
                break;
            }
            foreach ($this->papermap[$prow->paperId] as $qi) {
                if ($need_anal[$qi]) {
                    $c = [];
                    if ($prow->paperTags) {
                        $c = $this->conf->tags()->styles($prow->viewable_tags($this->user), TagMap::STYLE_BG);
                    }
                    if ($qcolors[$qi] !== null && !empty($c)) {
                        $c = array_values(array_intersect($qcolors[$qi], $c));
                    }
                    if (empty($c)) {
                        $qcolors[$qi] = $this->_qstyles[$qi];
                        $need_anal[$qi] = false;
                        --$nneed_anal;
                    } else {
                        $qcolors[$qi] = $c;
                    }
                }
            }
        }
        if ($nneed_anal !== 0) {
            foreach ($need_anal as $qi => $na) {
                if ($na)
                    $qcolors[$qi] = join(" ", $qcolors[$qi]);
            }
        }

        // compute data
        $this->_data = [];
        $dashps = [null, [10,5], [5,5], [1,1]];
        foreach ($this->fxs as $i => $fx) {
            $dashp = $dashps[$i % count($dashps)];
            $this->_data = array_merge($this->_data,
                $this->_cdf_data_one_fx($fx, $qcolors, $dashp, $rowset));
        }
    }

    private function _prepare_reviewer_color(Contact $user) {
        $this->reviewer_color = array();
        foreach ($this->conf->pc_members() as $p) {
            $this->reviewer_color[$p->contactId] = $this->conf->tags()->color_classes($p->viewable_tags($user), true);
        }
    }

    private function _paper_style(PaperInfo $prow) {
        $qnum = $this->papermap[$prow->paperId][0];
        if ($this->_qstyles_bytag[$qnum]) {
            if ($this->reviewer_color && $this->user->can_view_user_tags()) {
                return self::REVIEWER_COLOR;
            } else if ($prow->paperTags
                       && ($c = $prow->viewable_tags($this->user))
                       && ($c = $prow->conf->tags()->styles($c, TagMap::STYLE_BG))) {
                return join(" ", $c);
            }
        }
        return $this->_qstyles[$qnum];
    }

    private function _add_tag_data(&$data, $d, PaperInfo $prow) {
        assert($this->fx_type === Fexpr::FTAG);
        $tags = TagInfo::split_unpack($prow->viewable_tags($this->user));
        foreach ($tags as $ti) {
            if (!isset($this->tags[$ti[0]])) {
                $this->tags[$ti[0]] = count($this->tags);
            }
            $d[0] = $this->tags[$ti[0]];
            $data[] = $d;
        }
    }

    private function _scatter_data(PaperInfoSet $rowset) {
        if ($this->fx->result_format() === Fexpr::FREVIEWER
            && ($this->type & self::BOXPLOT)) {
            $this->_prepare_reviewer_color($this->user);
        }

        $fxf = $this->fx->compile_json_function();
        $fyf = $this->fy->compile_json_function();
        $reviewf = null;
        if ($this->fx->indexed() || $this->fy->indexed()) {
            $reviewf = Formula::compile_indexes_function($this->user, $this->fx->index_type() | $this->fy->index_type());
        }
        $orderf = $order_data = null;
        if ($this->fxorder) {
            $orderf = $this->fxorder->compile_extractor_function();
            $ordercf = $this->fxorder->compile_combiner_function();
            $order_data = [];
        }

        $data = [];
        foreach ($rowset->all() as $prow) {
            $s = $ps = $this->_paper_style($prow);
            $d = [0, 0, 0];
            $revs = $reviewf ? $reviewf($prow, $this->user) : [null];
            foreach ($revs as $rcid) {
                $rrow = $rcid ? $prow->review_of_user($rcid) : null;
                $d[0] = $fxf($prow, $rcid, $this->user);
                $d[1] = $fyf($prow, $rcid, $this->user);
                if ($d[0] === null || $d[1] === null) {
                    continue;
                }
                $d[2] = $prow->paperId;
                if ($rrow && $rrow->reviewOrdinal) {
                    $d[2] .= unparseReviewOrdinal($rrow->reviewOrdinal);
                }
                if ($orderf) {
                    $order_data[$d[0]] = get($order_data, $d[0], []);
                    $order_data[$d[0]][] = $orderf($prow, $rcid, $this->user);
                }
                if ($ps === self::REVIEWER_COLOR) {
                    $s = get($this->reviewer_color, $d[0]) ? : "";
                }
                if ($this->fx_type === Fexpr::FSEARCH) {
                    foreach ($this->_filter_queries($prow, $rrow) as $q) {
                        $d[0] = $q;
                        $data[$s][] = $d;
                    }
                } else if ($this->fx_type === Fexpr::FTAG) {
                    $this->_add_tag_data($data[$s], $d, $prow);
                } else {
                    $data[$s][] = $d;
                }
            }
        }
        $this->_data = $data;

        if ($orderf) {
            $this->_xorder_data = [];
            foreach ($order_data as $x => $vs) {
                $this->_xorder_data[] = [$x, $ordercf($vs)];
            }
        }
    }

    // combine data: [x, y, pids, style, [query...]]

    static function barchart_compare($a, $b) {
        if (get_i($a, 4) != get_i($b, 4)) {
            return get_i($a, 4) - get_i($b, 4);
        }
        if ($a[0] != $b[0]) {
            return $a[0] < $b[0] ? -1 : 1;
        }
        return strcmp($a[3], $b[3]);
    }

    private function _combine_data(PaperInfoSet $rowset) {
        $data = [];
        if ($this->fx->result_format() === Fexpr::FREVIEWER) {
            $this->_prepare_reviewer_color($this->user);
        }

        $fxf = $this->fx->compile_json_function();
        $fytrack = $this->fy->compile_extractor_function();
        $fycombine = $this->fy->compile_combiner_function();
        $reviewf = null;
        if ($this->fx->indexed() || $this->fy->index_type()) {
            $reviewf = Formula::compile_indexes_function($this->user, ($this->fx->indexed() ? $this->fx->index_type() : 0) | $this->fy->index_type());
        }

        foreach ($rowset->all() as $prow) {
            $queries = $this->papermap[$prow->paperId];
            $s = $ps = $this->_paper_style($prow);
            $revs = $reviewf ? $reviewf($prow, $this->user) : [null];
            foreach ($revs as $rcid) {
                if (($x = $fxf($prow, $rcid, $this->user)) === null) {
                    continue;
                }
                $rrow = $rcid ? $prow->review_of_user($rcid) : null;
                if ($rrow) {
                    $queries = $this->_filter_queries($prow, $rrow);
                }
                if ($ps === self::REVIEWER_COLOR) {
                    $s = get($this->reviewer_color, $x) ? : "";
                }
                $d = [$x, $fytrack($prow, $rcid, $this->user), $prow->paperId, $s];
                if ($rrow && $rrow->reviewOrdinal && $this->fx->indexed()) {
                    $d[2] .= unparseReviewOrdinal($rrow->reviewOrdinal);
                }
                foreach ($queries as $q) {
                    $q && ($d[4] = $q);
                    if ($this->fx_type === Fexpr::FTAG) {
                        $this->_add_tag_data($data, $d, $prow);
                    } else {
                        $data[] = $d;
                    }
                }
            }
        }

        $is_sum = $this->fy->is_sum();
        usort($data, "FormulaGraph::barchart_compare");
        $newdata = [];
        $ndata = count($data);
        for ($i = 0; $i != $ndata; $i = $j) {
            $d = [$data[$i][0], [$data[$i][1]], [$data[$i][2]], $data[$i][3],
                  get($data[$i], 4)];
            for ($j = $i + 1;
                 $j != $ndata
                   && $data[$j][0] == $d[0]
                   && get($data[$j], 4) == $d[4]
                   && (!$is_sum || $data[$j][3] == $d[3]);
                 ++$j) {
                $d[1][] = $data[$j][1];
                $d[2][] = $data[$j][2];
                if ($d[3] && $d[3] != $data[$j][3]) {
                    $d[3] = "";
                }
            }
            $d[1] = $fycombine($d[1]);
            if (!$d[4]) {
                array_pop($d);
                $d[3] || array_pop($d);
            }
            if ($reviewf && !$this->fx->indexed()) {
                $d[2] = array_values(array_unique($d[2]));
            }
            $newdata[] = $d;
        }
        $this->_data = $newdata;
    }

    private function _valuemap_axes($format) {
        $axes = 0;
        if ((!$this->fx_type && $this->fx->result_format() === $format)
            || ($this->fx_type == Fexpr::FTAG && $format === Fexpr::FTAG)) {
            $axes |= 1;
        }
        if (!($this->type & self::CDF) && $this->fy->result_format() === $format) {
            $axes |= 2;
        }
        return $axes;
    }

    private function _valuemap_collect($axes) {
        assert(!!$axes);
        $vs = [];
        if ($this->type & self::CDF) {
            foreach ($this->_data as $dx) {
                foreach ($dx->d as $d)
                    $vs[$d] = true;
            }
        } else if ($this->type & self::BARCHART) {
            foreach ($this->_data as $d) {
                ($axes & 1) && $d[0] !== null && ($vs[$d[0]] = true);
                ($axes & 2) && $d[1] !== null && ($vs[$d[1]] = true);
            }
        } else {
            foreach ($this->_data as $dx) {
                foreach ($dx as $d) {
                    ($axes & 1) && $d[0] !== null && ($vs[$d[0]] = true);
                    ($axes & 2) && $d[1] !== null && ($vs[$d[1]] = true);
                }
            }
        }
        return $vs;
    }

    private function _valuemap_rewrite($axes, $m) {
        assert(!!$axes);
        if ($this->type & self::CDF) {
            foreach ($this->_data as $dx) {
                foreach ($dx->d as &$d) {
                    array_key_exists($d, $m) && ($d = $m[$d]);
                }
                unset($d);
            }
        } else if ($this->type & self::BARCHART) {
            foreach ($this->_data as &$d) {
                ($axes & 1) && array_key_exists($d[0], $m) && ($d[0] = $m[$d[0]]);
                ($axes & 2) && array_key_exists($d[1], $m) && ($d[1] = $m[$d[1]]);
            }
        } else {
            foreach ($this->_data as &$dx) {
                foreach ($dx as &$d) {
                    ($axes & 1) && array_key_exists($d[0], $m) && ($d[0] = $m[$d[0]]);
                    ($axes & 2) && array_key_exists($d[1], $m) && ($d[1] = $m[$d[1]]);
                }
                unset($d);
            }
        }
        if (($axes & 1) && $this->_xorder_data) {
            foreach ($this->_xorder_data as &$d) {
                array_key_exists($d[0], $m) && ($d[0] = $m[$d[0]]);
            }
            unset($d);
        }
        $this->_axis_remapped |= $axes;
    }

    private function _reviewer_reformat() {
        if (!($axes = $this->_valuemap_axes(Fexpr::FREVIEWER))
            || !($cids = $this->_valuemap_collect($axes)))
            return;
        $cids = array_filter(array_keys($cids), "is_numeric");
        $result = $this->conf->qe("select contactId, firstName, lastName, email, roles, contactTags from ContactInfo where contactId ?a", $cids);
        $this->reviewers = [];
        while (($c = Contact::fetch($result, $this->conf)))
            $this->reviewers[$c->contactId] = $c;
        Dbl::free($result);
        uasort($this->reviewers, "Contact::compare");
        $i = 0;
        $m = [];
        foreach ($this->reviewers as $c) {
            $c->sort_position = ++$i;
            $m[$c->contactId] = $i;
        }
        $this->_valuemap_rewrite($axes, $m);
    }

    private function _revround_reformat() {
        if (!($axes = $this->_valuemap_axes(Fexpr::FROUND))
            || !($rs = $this->_valuemap_collect($axes)))
            return;
        $i = 0;
        $m = [];
        foreach ($this->conf->defined_round_list() as $n => $rname)
            if (get($rs, $n)) {
                $this->remapped_rounds[++$i] = $rname;
                $m[$n] = $i;
            }
        $this->_valuemap_rewrite($axes, $m);
    }

    private function _tag_reformat() {
        if (!($axes = $this->_valuemap_axes(Fexpr::FTAG))
            || !($rs = $this->_valuemap_collect($axes))) {
            return;
        }
        uksort($this->tags, [$this->conf->collator(), "compare"]);
        $i = -1;
        $m = [];
        foreach ($this->tags as $tag => $ri) {
            $m[$ri] = ++$i;
        }
        $this->_valuemap_rewrite($axes, $m);
    }

    private function _xorder_rewrite() {
        if (!$this->_xorder_data)
            return;
        usort($this->_xorder_data, function ($x, $y) {
            if ($x[1] != $y[1])
                return $x[1] < $y[1] ? -1 : 1;
            else if ($x[0] != $y[0])
                return $x[0] < $y[0] ? -1 : 1;
            else
                return 0;
        });
        $xo = [];
        foreach ($this->_xorder_data as $i => $d)
            $xo[$d[0]] = $i + 1;
        $this->_xorder_map = $xo;
        if ($this->type & self::CDF) {
            foreach ($this->_data as $dx) {
                foreach ($dx->d as &$d) {
                    $d = get($xo, $d);
                }
                unset($d);
            }
        } else if ($this->type & self::BARCHART) {
            foreach ($this->_data as &$d) {
                $d[0] = get($xo, $d[0]);
            }
        } else {
            foreach ($this->_data as &$dx) {
                foreach ($dx as &$d) {
                    $d[0] = get($xo, $d[0]);
                }
                unset($d);
            }
        }
    }

    function data() {
        if ($this->_data !== null)
            return $this->_data;

        // load data
        $paperIds = array_keys($this->papermap);
        $queryOptions = array("paperId" => $paperIds, "tags" => true);
        $this->fx->add_query_options($queryOptions);
        $this->fy->add_query_options($queryOptions);
        if ($this->fx->indexed() || $this->fy->indexed())
            $queryOptions["reviewSignatures"] = true;

        $result = $this->conf->paper_result($queryOptions, $this->user);
        $rowset = new PaperInfoSet;
        while (($prow = PaperInfo::fetch($result, $this->user)))
            if ($this->user->can_view_paper($prow))
                $rowset->add($prow);
        Dbl::free($result);

        if ($this->type & self::CDF)
            $this->_cdf_data($rowset);
        else if ($this->type & self::BARCHART)
            $this->_combine_data($rowset);
        else
            $this->_scatter_data($rowset);
        $this->_reviewer_reformat();
        $this->_revround_reformat();
        $this->_tag_reformat();
        $this->_xorder_rewrite();

        return $this->_data;
    }

    function axis_json($axis) {
        $isx = $axis === "x";
        $f = $isx ? $this->fx : $this->fy;
        $j = array();

        $counttype = $this->fx->indexed() ? "reviews" : "papers";
        if ($isx) {
            $j["label"] = $this->fx_expression;
        } else if ($this->type === self::FBARCHART) {
            $j["label"] = "fraction of $counttype";
            $j["fraction"] = true;
        } else if ($this->type === self::BARCHART
                   && $f->expression === "sum(1)") {
            $j["label"] = "# $counttype";
        } else if ($this->type === self::RAWCDF) {
            $j["label"] = "Cumulative count of $counttype";
            $j["raw"] = true;
        } else if ($this->type & self::CDF) {
            $j["label"] = "CDF of $counttype";
        } else if (!$this->fx_type) {
            $j["label"] = $f->expression;
        }

        $format = $f->result_format();
        if ($isx && $this->fxs && $format) {
            foreach ($this->fxs as $fx) {
                if ($fx->result_format() !== $format) {
                    $format = 0;
                    break;
                }
            }
        }
        if ($isx && $this->fx_type == Fexpr::FSEARCH) {
            $j["ticks"] = ["named", $this->queries];
        } else if ($isx && $this->fx_type == Fexpr::FTAG) {
            $tagger = new Tagger($this->user);
            $j["ticks"] = ["named", array_map(function ($t) use ($tagger) {
                return $tagger->unparse($t);
            }, array_keys($this->tags))];
        } else if ($format instanceof ReviewField) {
            $n = count($format->options);
            $ol = $format->option_letter ? chr($format->option_letter - $n) : null;
            $j["ticks"] = ["score", $n, $ol, $format->option_class_prefix];
            if ($format->option_letter && $isx) {
                $j["flip"] = true;
            }
        } else {
            if ($format === Fexpr::FREVIEWER) {
                $x = [];
                foreach ($this->reviewers as $r) {
                    $rd = ["text" => $this->user->name_text_for($r),
                           "search" => "re:" . $r->email];
                    if ($this->user->can_view_user_tags()
                        && ($colors = $r->viewable_color_classes($this->user))) {
                        $rd["color_classes"] = $colors;
                    }
                    $rd["id"] = $r->contactId;
                    $x[$r->sort_position] = $rd;
                }
                $j["ticks"] = ["named", $x];
            } else if ($format === Fexpr::FDECISION) {
                $j["ticks"] = ["named", $this->conf->decision_map()];
            } else if ($format === Fexpr::FBOOL) {
                $j["ticks"] = ["named", ["no", "yes"]];
            } else if ($format instanceof PaperOption && $format->has_selector()) {
                $j["ticks"] = ["named", $format->selector];
            } else if ($format === Fexpr::FROUND) {
                $j["ticks"] = ["named", $this->remapped_rounds];
            } else if ($format === Fexpr::FREVTYPE) {
                $j["ticks"] = ["named", ReviewForm::$revtype_names];
            } else if (is_int($format) && $format >= Fexpr::FDATE && $format <= Fexpr::FTIMEDELTA) {
                $j["ticks"] = ["time"];
            } else if ($isx && $this->_xorder_map) {
                if (isset($j["label"]))
                    $j["label"] .= " order";
                else
                    $j["label"] = "order";
            }
            if (!$isx && isset($j["ticks"])) {
                $j["rotate_ticks"] = -90;
            }
        }

        if ($isx && $this->_xorder_map && isset($j["ticks"])
            && $j["ticks"][0] === "named") {
            $newticks = [];
            foreach ($j["ticks"][1] as $n => $x) {
                if (isset($this->_xorder_map[$n]))
                    $newticks[$this->_xorder_map[$n]] = $x;
            }
            $j["ticks"][1] = $newticks;
        }

        if ($this->_axis_remapped & ($isx ? 1 : 2)) {
            $j["reordered"] = true;
        }
        return $j;
    }

    function type_json() {
        $tj = [self::SCATTER => "scatter", self::CDF => "cdf",
            self::RAWCDF => "cumulative-count", self::BARCHART => "bar",
            self::FBARCHART => "full-stack", self::BOXPLOT => "box"];
        return get($tj, $this->type);
    }

    function graph_json() {
        $j = [
            "type" => $this->type_json(),
            "data" => $this->data(),
            "x" => $this->axis_json("x"),
            "y" => $this->axis_json("y")
        ];
        if ($this->type & self::CDF) {
            $j["cdf_tooltip_position"] = true;
        }
        return $j;
    }
}
