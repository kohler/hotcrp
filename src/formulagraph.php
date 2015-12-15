<?php
// formulagraph.php -- HotCRP class for drawing graphs
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class FormulaGraph {
    const SCATTER = 1;
    const CDF = 2;
    const BARCHART = 4; /* NB is bitmask */
    const FBARCHART = 5;
    const BOXPLOT = 8;

    const REVIEWER_COLOR = 1;

    public $type = 0;
    public $fx;
    public $fy;
    public $fx_query = false;
    private $queries = array();
    private $query_styles = array();
    private $papermap = array();
    private $reviewers = array();
    private $reviewer_color = false;
    private $remapped_rounds = null;
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
            } else if (preg_match('/\Abars?\s+(.+)\z/i', $fy, $m)) {
                $this->type = self::BARCHART;
                $fy = $m[1];
            } else if (preg_match('/\Ascatter\s+(.+)\z/i', $fy, $m)) {
                $this->type = self::SCATTER;
                $fy = $m[1];
            }
            $this->fy = new Formula($fy, true);
            if (!$this->type)
                $this->type = $this->fy->datatypes() ? self::SCATTER : self::BARCHART;
        }

        if ($this->fx->error_html()) {
            $this->error_html[] = "X axis formula: " . $this->fx->error_html();
            $this->errf["fx"] = true;
        }
        if ($this->fy->error_html()) {
            $this->error_html[] = "Y axis formula: " . $this->fy->error_html();
            $this->errf["fy"] = true;
        } else if (($this->type & self::BARCHART) && !$this->fy->can_combine()) {
            $this->error_html[] = "Y axis formula “" . htmlspecialchars($fy) . "” is unsuitable for bar charts, use an aggregate function like “sum(" . htmlspecialchars($fy) . ")”.";
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
        return $data;
    }

    private function _prepare_reviewer_color(Tagger $tagger) {
        $this->reviewer_color = array();
        foreach (pcMembers() as $p)
            $this->reviewer_color[$p->contactId] = TagInfo::color_classes($tagger->viewable($p->contactTags));
    }

    private function _paper_style(PaperInfo $prow, Tagger $tagger) {
        global $Me;
        $qnum = $this->papermap[$prow->paperId][0];
        $s = @$this->query_styles[(int) $qnum];
        if (!$s && $this->reviewer_color && $Me->can_view_reviewer_tags($prow))
            return self::REVIEWER_COLOR;
        else if (!$s && @$prow->paperTags && $Me->can_view_tags($prow)
                 && ($c = $tagger->viewable_color_classes($prow->paperTags)))
            return $c;
        else if ($s === "plain")
            return "";
        else
            return $s;
    }

    private function _scatter_data($result) {
        global $Me;
        $data = [];
        $tagger = new Tagger($Me);
        if ($this->fx->result_format() === Fexpr::FREVIEWER && ($this->type & self::BOXPLOT))
            $this->_prepare_reviewer_color($tagger);

        $fxf = $this->fx->compile_function($Me);
        $fyf = $this->fy->compile_function($Me);
        $reviewf = null;
        if ($this->fx->needs_review() || $this->fy->needs_review())
            $reviewf = Formula::compile_indexes_function($Me, $this->fx->datatypes | $this->fy->datatypes);

        while (($prow = PaperInfo::fetch($result, $Me))) {
            if (!$Me->can_view_paper($prow))
                continue;
            $s = $this->_paper_style($prow, $tagger);
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
                if ($s === self::REVIEWER_COLOR)
                    $s = @$this->reviewer_color[$d[0]] ? : "";
                if ($this->fx_query) {
                    foreach ($this->papermap[$prow->paperId] as $q) {
                        $d[0] = $q;
                        $data[$s][] = $d;
                    }
                } else
                    $data[$s][] = $d;
            }
        }
        return $data;
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
        if ($this->fx->result_format() === Fexpr::FREVIEWER)
            $this->_prepare_reviewer_color($tagger);

        $fxf = $this->fx->compile_function($Me);
        list($fytrack, $fycombine) = $this->fy->compile_combine_functions($Me);
        $reviewf = null;
        if ($this->fx->needs_review() || $this->fy->datatypes)
            $reviewf = Formula::compile_indexes_function($Me, ($this->fx->needs_review() ? $this->fx->datatypes : 0) | $this->fy->datatypes);

        while (($prow = PaperInfo::fetch($result, $Me))) {
            if (!$Me->can_view_paper($prow))
                continue;
            $queries = $this->papermap[$prow->paperId];
            $s = $this->_paper_style($prow, $tagger);
            $revs = $reviewf ? $reviewf($prow, $Me) : [null];
            foreach ($revs as $rcid) {
                if (($x = $fxf($prow, $rcid, $Me)) === null)
                    continue;
                if ($s === self::REVIEWER_COLOR)
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
        return $ndata;
    }

    private function _valuemap_axes($format) {
        $axes = 0;
        if (!$this->fx_query && $this->fx->result_format() === $format)
            $axes |= 1;
        if ($this->type != self::CDF && $this->fy->result_format() === $format)
            $axes |= 2;
        return $axes;
    }

    private function _valuemap_collect($data, $axes) {
        assert(!!$axes);
        $vs = [];
        if ($this->type == self::CDF) {
            foreach ($data as $dx)
                foreach ($dx->d as $d)
                    $vs[$d] = true;
        } else if ($this->type & self::BARCHART) {
            foreach ($data as $d) {
                ($axes & 1) && $d[0] !== null && ($vs[$d[0]] = true);
                ($axes & 2) && $d[1] !== null && ($vs[$d[1]] = true);
            }
        } else {
            foreach ($data as $dx)
                foreach ($dx as $d) {
                    ($axes & 1) && $d[0] !== null && ($vs[$d[0]] = true);
                    ($axes & 2) && $d[1] !== null && ($vs[$d[1]] = true);
                }
        }
        return $vs;
    }

    private function _valuemap_rewrite(&$data, $axes, $m) {
        assert(!!$axes);
        if ($this->type == self::CDF) {
            foreach ($data as $dx) {
                foreach ($dx->d as &$d)
                    array_key_exists($d, $m) && ($d = $m[$d]);
                unset($d);
            }
        } else if ($this->type & self::BARCHART) {
            foreach ($data as &$d) {
                ($axes & 1) && array_key_exists($d[0], $m) && ($d[0] = $m[$d[0]]);
                ($axes & 2) && array_key_exists($d[1], $m) && ($d[1] = $m[$d[1]]);
            }
        } else {
            foreach ($data as &$dx) {
                foreach ($dx as &$d) {
                    ($axes & 1) && array_key_exists($d[0], $m) && ($d[0] = $m[$d[0]]);
                    ($axes & 2) && array_key_exists($d[1], $m) && ($d[1] = $m[$d[1]]);
                }
                unset($d);
            }
        }
    }

    private function _reviewer_reformat(&$data) {
        if (!($axes = $this->_valuemap_axes(Fexpr::FREVIEWER))
            || !($cids = $this->_valuemap_collect($data, $axes)))
            return;
        $cids = array_filter(array_keys($cids), "is_numeric");
        $result = Dbl::qe("select contactId, firstName, lastName, email, roles, contactTags from ContactInfo where contactId ?a", $cids);
        $this->reviewers = [];
        while ($result && ($c = $result->fetch_object("Contact")))
            $this->reviewers[$c->contactId] = $c;
        Dbl::free($result);
        uasort($this->reviewers, "Contact::compare");
        $i = 0;
        $m = [];
        foreach ($this->reviewers as $c) {
            $c->graph_index = ++$i;
            $m[$c->contactId] = $i;
        }
        $this->_valuemap_rewrite($data, $axes, $m);
    }

    private function _revround_reformat(&$data) {
        global $Conf;
        if (!($axes = $this->_valuemap_axes(Fexpr::FREVIEWER))
            || !($rs = $this->_valuemap_collect($data, $axes)))
            return;
        $i = 0;
        $m = $this->remapped_rounds = [];
        foreach ($Conf->defined_round_list() as $n => $rname)
            if (in_array($n, $rs, true)) {
                $this->remapped_rounds[++$i] = $rname;
                $m[$n] = $i;
            }
        $this->_valuemap_rewrite($data, $axes, $m);
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
        $this->_reviewer_reformat($data);
        $this->_revround_reformat($data);

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
        } else if ($format === Fexpr::FREVIEWER) {
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
        } else if ($format === Fexpr::FDECISION)
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks("
                    . json_encode($Conf->decision_map()) . ")" . $rticks;
        else if ($format === Fexpr::FBOOL)
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks({0:\"no\",1:\"yes\"})" . $rticks;
        else if ($format instanceof PaperOption && $format->has_selector())
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks(" . json_encode($format->selector, JSON_UNESCAPED_UNICODE) . ")" . $rticks;
        else if ($format === Fexpr::FROUND)
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks(" . json_encode($this->remapped_rounds, JSON_UNESCAPED_UNICODE) . ")" . $rticks;
        else if ($format === Fexpr::FREVTYPE)
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks(" . json_encode($reviewTypeName) . ")" . $rticks;
        return join(",", $t);
    }
}
