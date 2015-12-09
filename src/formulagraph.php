<?php
// formulagraph.php -- HotCRP class for drawing graphs
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class FormulaGraph {
    const SCATTER = 0;
    const CDF = 1;
    const BARCHART = 2; /* NB is bitmask */
    const FBARCHART = 3;
    const BOXPLOT = 4;

    public $type = self::SCATTER;
    public $fx;
    public $fy;
    public $fx_query = false;
    private $queries = array();
    private $query_styles = array();
    private $papermap = array();
    private $reviewers = array();
    private $reviewer_color = false;
    public $error_html = array();
    public $errf = array();

    public function __construct($fx, $fy) {
        $fx = simplify_whitespace($fx);
        $fy = simplify_whitespace($fy);
        if (strcasecmp($fx, "query") == 0) {
            $this->fx = new Formula("0", true);
            $this->fx_query = true;
        } else
            $this->fx = new Formula($fx, true);
        if (strcasecmp($fy, "cdf") == 0) {
            $this->type = self::CDF;
            $this->fy = new Formula("0", true);
        } else if (preg_match('/\A(?:count|bar|bars|barchart)\z/i', $fy)) {
            $this->type = self::BARCHART;
            $this->fy = new Formula("sum(1)", true);
        } else if (preg_match('/\A(?:frac|fraction)\z/i', $fy)) {
            $this->type = self::FBARCHART;
            $this->fy = new Formula("sum(1)", true);
        } else {
            if (preg_match('/\A(?:box|boxplot)\s+(.*)\z/i', $fy, $m)) {
                $this->type = self::BOXPLOT;
                $fy = $m[1];
            } else if (preg_match('/\A(?:bars?)\s+(.+)\z/i', $fy, $m)) {
                $this->type = self::BARCHART;
                $fy = $m[1];
            }
            $this->fy = new Formula($fy, true);
        }

        if ($this->fx->error_html()) {
            $this->error_html[] = "X axis formula: " . $this->fx->error_html();
            $this->errf["fx"] = true;
        }
        if ($this->fy->error_html()) {
            $this->error_html[] = "Y axis formula: " . $this->fy->error_html();
            $this->errf["fy"] = true;
        } else if (($this->type & self::BARCHART) && !$this->fy->can_combine()) {
            $this->error_html[] = "Y axis formula “" . htmlspecialchars($fy) . "” is unsuitable for bar charts, use an aggregate function.";
            $this->errf["fy"] = true;
            $this->fy = new Formula("sum(0)", true);
        }
    }

    public function add_query($q, $style, $fieldname = false) {
        global $Me;
        $qn = count($this->queries);
        $this->queries[] = $q;
        if ($style === "by-tag" || $style === "default")
            $style = "";
        $this->query_styles[] = $style;
        $psearch = new PaperSearch($Me, array("q" => $q));
        foreach ($psearch->paperList() as $pid)
            $this->papermap[$pid][] = $qn;
        if (count($psearch->warnings)) {
            $this->error_html = array_merge($this->error_html, $psearch->warnings);
            if ($fieldname)
                $this->errf[$fieldname] = true;
        }
    }

    private function _cdf_data($result) {
        global $Me;
        $data = [];
        $query_color_classes = [];
        $tagger = new Tagger($Me);

        $fxf = $this->fx->compile_function($Me);
        $reviewf = null;
        if ($this->fx->needs_review())
            $reviewf = Formula::compile_indexes_function($Me, $this->fx->datatypes);

        while (($prow = PaperInfo::fetch($result, $Me))) {
            if (!$Me->can_view_paper($prow))
                continue;
            $revs = $reviewf ? $reviewf($prow, $Me) : [null];
            $queries = @$this->papermap[$prow->paperId];
            foreach ($queries as $q)
                if (@$query_color_classes[$q] !== "") {
                    $c = "";
                    if (@$prow->paperTags && $Me->can_view_tags($prow))
                        $c = TagInfo::color_classes($tagger->viewable($prow->paperTags), 2);
                    if ($c !== "" && (@$query_color_classes[$q] ? : $c) !== $c)
                        $c = "";
                    $query_color_classes[$q] = $c;
                }
            foreach ($revs as $rcid)
                if (($x = $fxf($prow, $rcid, $Me)) !== null) {
                    if ($this->fx_query) {
                        foreach ($queries as $q)
                            $data[0][] = $q;
                    } else {
                        foreach ($queries as $q)
                            $data[$q][] = $x;
                    }
                }
        }

        foreach ($data as $q => &$d) {
            $d = (object) ["d" => $d];
            $s = @$this->query_styles[$q];
            if ($s && $s !== "plain")
                $d->className = $s;
            else if ($s && @$query_color_classes[$style])
                $d->className = $query_color_classes[$style];
            if (@$this->queries[$q])
                $d->label = $this->queries[$q];
        }
        unset($d);

        if ($this->fx->result_format() === "reviewer")
            $this->_cdf_fix_reviewers($data);

        return $data;
    }

    private function _load_reviewers($reviewer_cids) {
        $reviewer_cids = array_filter(array_keys($reviewer_cids), "is_numeric");
        $result = Dbl::qe("select contactId, firstName, lastName, email, roles, contactTags from ContactInfo where contactId ?a", $reviewer_cids);
        $this->reviewers = [];
        while ($result && ($c = $result->fetch_object("Contact")))
            $this->reviewers[$c->contactId] = $c;
        Dbl::free($result);
        uasort($this->reviewers, "Contact::compare");
        $i = 0;
        foreach ($this->reviewers as $c)
            $c->graph_index = ++$i;
    }

    private function _cdf_fix_reviewers(&$data) {
        if ($this->fx_query)
            return;
        $reviewer_cids = [];
        foreach ($data as $dx)
            foreach ($dx->d as $d)
                $reviewer_cids[$d] = true;
        $this->_load_reviewers($reviewer_cids);
        foreach ($data as $dx) {
            foreach ($dx->d as &$d)
                $d && ($d = $this->reviewers[$d]->graph_index);
            unset($d);
        }
    }

    private function _prepare_reviewer_color(Tagger $tagger) {
        $this->reviewer_color = array();
        foreach (pcMembers() as $p)
            $this->reviewer_color[$p->contactId] = TagInfo::color_classes($tagger->viewable($p->contactTags));
    }

    private function _scatter_data($result) {
        global $Me;
        $data = [];
        $tagger = new Tagger($Me);
        if ($this->fx->result_format() === "reviewer" && ($this->type & self::BOXPLOT))
            $this->_prepare_reviewer_color($tagger);

        $fxf = $this->fx->compile_function($Me);
        $fyf = $this->fy->compile_function($Me);
        $reviewf = null;
        if ($this->fx->needs_review() || $this->fy->needs_review())
            $reviewf = Formula::compile_indexes_function($Me, $this->fx->datatypes | $this->fy->datatypes);

        while (($prow = PaperInfo::fetch($result, $Me))) {
            if (!$Me->can_view_paper($prow))
                continue;
            $queries = @$this->papermap[$prow->paperId];
            $s = @$this->query_styles[(int) $queries[0]];
            $reviewer_color = false;
            if ($this->reviewer_color && !$s && $Me->can_view_reviewer_tags($prow))
                $reviewer_color = true;
            if (!$s && @$prow->paperTags && $Me->can_view_tags($prow)
                && ($color = TagInfo::color_classes($tagger->viewable($prow->paperTags), 2)))
                $s = $color;
            else if ($s === "plain")
                $s = "";
            $d = [0, 0, 0];
            $revs = $reviewf ? $reviewf($prow, $Me) : [null];
            foreach ($revs as $rcid) {
                $d[0] = $fxf($prow, $rcid, $Me);
                $d[1] = $fyf($prow, $rcid, $Me);
                if ($d[0] === null || $d[1] === null)
                    continue;
                $d[2] = $prow->paperId;
                if ($rcid && ($o = $prow->review_ordinal($rcid)))
                    $d[2] .= unparseReviewOrdinal($o);
                if ($reviewer_color)
                    $s = @$this->reviewer_color[$d[0]] ? : "";
                if ($this->fx_query) {
                    foreach ($queries as $q) {
                        $d[0] = $q;
                        $data[$s][] = $d;
                    }
                } else
                    $data[$s][] = $d;
            }
        }

        if ($this->fx->result_format() === "reviewer"
            || $this->fy->result_format() === "reviewer")
            $this->_scatter_fix_reviewers($data);

        return $data;
    }

    private function _scatter_fix_reviewers(&$data) {
        $xi = !$this->fx_query
            && $this->fx->result_format() === "reviewer";
        $yi = $this->fy->result_format() === "reviewer";
        $reviewer_cids = [];
        foreach ($data as $dx)
            foreach ($dx as $d) {
                $xi && $d[0] && ($reviewer_cids[$d[0]] = true);
                $yi && $d[1] && ($reviewer_cids[$d[1]] = true);
            }
        $this->_load_reviewers($reviewer_cids);
        foreach ($data as &$dx) {
            foreach ($dx as &$d) {
                if ($xi && $d[0] && ($r = @$this->reviewers[$d[0]]))
                    $d[0] = $r->graph_index;
                if ($yi && $d[1] && ($r = @$this->reviewers[$d[1]]))
                    $d[1] = $r->graph_index;
            }
            unset($d);
        }
    }

    // combine data: [x, y, pids, style, [query]]

    public static function barchart_compare($a, $b) {
        if ((int) @$a[4] != (int) @$b[4])
            return (int) @$a[4] - (int) @$b[4];
        if ($a[0] != $b[0])
            return $a[0] < $b[0] ? -1 : 1;
        return @strcmp($a[3], $b[3]);
    }

    private function _combine_data($result) {
        global $Me;
        $data = [];
        $tagger = new Tagger($Me);
        if ($this->fx->result_format() === "reviewer")
            $this->_prepare_reviewer_color($tagger);

        $fxf = $this->fx->compile_function($Me);
        list($fytrack, $fycombine) = $this->fy->compile_combine_functions($Me);
        $reviewf = null;
        if ($this->fx->needs_review() || $this->fy->datatypes)
            $reviewf = Formula::compile_indexes_function($Me, ($this->fx->needs_review() ? $this->fx->datatypes : 0) | $this->fy->datatypes);

        while (($prow = PaperInfo::fetch($result, $Me))) {
            if (!$Me->can_view_paper($prow))
                continue;
            $queries = @$this->papermap[$prow->paperId];
            $s = @$this->query_styles[(int) $queries[0]];
            $reviewer_color = false;
            if ($this->reviewer_color && !$s && $Me->can_view_reviewer_tags($prow))
                $reviewer_color = true;
            if (!$s && @$prow->paperTags && $Me->can_view_tags($prow)
                && ($color = TagInfo::color_classes($tagger->viewable($prow->paperTags), 2)))
                $s = $color;
            else if ($s === "plain")
                $s = "";
            $revs = $reviewf ? $reviewf($prow, $Me) : [null];
            foreach ($revs as $rcid) {
                if (($x = $fxf($prow, $rcid, $Me)) === null)
                    continue;
                if ($reviewer_color)
                    $s = @$this->reviewer_color[$d[0]] ? : "";
                $d = [$x, $fytrack($prow, $rcid, $Me), $prow->paperId, $s];
                if ($rcid && ($o = $prow->review_ordinal($rcid)))
                    $d[2] .= unparseReviewOrdinal($o);
                foreach ($queries as $q) {
                    $q && ($d[4] = $q);
                    $data[] = $d;
                }
            }
        }

        $is_sum = $this->fy->is_sum();
        usort($data, "FormulaGraph::barchart_compare");
        $ndata = [];
        for ($i = 0; $i != count($data); $i = $j) {
            $d = [$data[$i][0], [$data[$i][1]], [$data[$i][2]], $data[$i][3],
                  @$data[$i][4]];
            for ($j = $i + 1;
                 $j != count($data) && $data[$j][0] == $d[0]
                 && @$data[$j][4] == $d[4]
                 && (!$is_sum || $data[$j][3] == $d[3]);
                 ++$j) {
                $d[1][] = $data[$j][1];
                $d[2][] = $data[$j][2];
                if ($d[3] && $d[3] != $data[$j][3])
                    $d[3] = "";
            }
            $d[1] = $fycombine($d[1]);
            if (!$d[4]) {
                array_pop($d);
                $d[3] || array_pop($d);
            }
            $ndata[] = $d;
        }

        if ($this->fx->result_format() === "reviewer"
            || $this->fy->result_format() === "reviewer")
            $this->_combine_fix_reviewers($ndata);

        return $ndata;
    }

    private function _combine_fix_reviewers(&$data) {
        $xi = $this->fx->result_format() === "reviewer";
        $yi = $this->fy->result_format() === "reviewer";
        $reviewer_cids = [];
        foreach ($data as $d) {
            $xi && $d[0] && ($reviewer_cids[$d[0]] = true);
            $yi && $d[1] && ($reviewer_cids[$d[1]] = true);
        }
        $this->_load_reviewers($reviewer_cids);
        foreach ($data as &$d) {
            if ($xi && $d[0] && ($r = @$this->reviewers[$d[0]]))
                $d[0] = $r->graph_index;
            if ($yi && $d[1] && ($r = @$this->reviewers[$d[1]]))
                $d[1] = $r->graph_index;
        }
    }

    public function data() {
        global $Conf, $Me;
        // load data
        $paperIds = array_keys($this->papermap);
        $queryOptions = array("paperId" => $paperIds, "tags" => true);
        $this->fx->add_query_options($queryOptions, $Me);
        $this->fy->add_query_options($queryOptions, $Me);
        if ($this->fx->needs_review() || $this->fy->needs_review())
            $queryOptions["reviewOrdinals"] = true;
        $result = Dbl::qe_raw($Conf->paperQuery($Me, $queryOptions));

        if ($this->type == self::CDF)
            $data = $this->_cdf_data($result);
        else if ($this->type & self::BARCHART)
            $data = $this->_combine_data($result);
        else
            $data = $this->_scatter_data($result);

        Dbl::free($result);
        return $data;
    }

    public function axis_info_settings($axis) {
        global $Conf, $Me, $reviewTypeName;
        $f = $axis == "x" ? $this->fx : $this->fy;
        $t = array();
        if ($axis == "y" && $this->type == self::FBARCHART)
            $t[] = "ylabel:\"fraction of papers\",yfraction:true";
        else if ($axis == "y" && $this->type == self::BARCHART
                 && $f->expression === "sum(1)")
            $t[] = "ylabel:\"# papers\"";
        else if ($axis != "x" || !$this->fx_query)
            $t[] = "{$axis}label:" . json_encode($f->expression, JSON_UNESCAPED_UNICODE);
        $format = $f->result_format();
        $rticks = ($axis == "y" ? ",yaxis_setup:hotcrp_graphs.rotate_ticks(-90)" : "");
        if ($axis == "x" && $this->fx_query) {
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks(" . json_encode($this->queries, JSON_UNESCAPED_UNICODE) . ")";
        } else if ($format instanceof ReviewField) {
            if ($format->option_letter)
                $t[] = $axis . "flip:true";
            $n = count($format->options);
            $ol = $format->option_letter ? chr($format->option_letter - $n) : null;
            $t[] = $axis . "ticks:hotcrp_graphs.option_letter_ticks("
                    . $n . "," . json_encode($ol) . "," . json_encode($format->option_class_prefix) . ")";
        } else if ($format === "reviewer") {
            $x = [];
            $tagger = new Tagger($Me);
            foreach ($this->reviewers as $r) {
                $rd = ["text" => $r->name_html(), // XXX should be text
                       "search" => "re:" . $r->email];
                if ($Me->can_view_reviewer_tags()
                    && ($colors = $tagger->viewable_color_classes($r->contactTags)))
                    $rd["color_classes"] = $colors;
                $x[$r->graph_index] = $rd;
            }
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks("
                    . json_encode($x, JSON_UNESCAPED_UNICODE) . ")" . $rticks;
        } else if ($format === "dec")
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks("
                    . json_encode($Conf->decision_map()) . ")" . $rticks;
        else if ($format === "bool")
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks({0:\"no\",1:\"yes\"})" . $rticks;
        else if ($format instanceof PaperOption && $format->has_selector())
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks(" . json_encode($format->selector, JSON_UNESCAPED_UNICODE) . ")" . $rticks;
        else if ($format === "revround")
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks(" . json_encode($Conf->defined_round_list(), JSON_UNESCAPED_UNICODE) . ")" . $rticks;
        else if ($format === "revtype")
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks(" . json_encode($reviewTypeName) . ")" . $rticks;
        return join(",", $t);
    }
}
