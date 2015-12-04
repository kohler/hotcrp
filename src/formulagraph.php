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
            $this->fy = new Formula("0", true);
        } else if (preg_match('/\A(?:frac|fraction)\z/i', $fy)) {
            $this->type = self::FBARCHART;
            $this->fy = new Formula("0", true);
        } else {
            if (preg_match('/\A(?:box|boxplot)\s+(.*)\z/i', $fy, $m)) {
                $this->type = self::BOXPLOT;
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
        }
    }

    public function add_query($q, $style, $fieldname = false) {
        global $Me;
        $qn = count($this->queries);
        $this->queries[] = $q;
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

    public static function barchart_compare($a, $b) {
        if ($a[0] != $b[0])
            return $a[0] < $b[0] ? -1 : 1;
        if ($a[1] != $b[1])
            return $a[1] < $b[1] ? -1 : 1;
        if (($cmp = @strcmp($a[3], $b[3])))
            return $cmp;
        return $a[2] - $b[2];
    }

    private function _cdf_data($result, $fxf, $reviewf) {
        global $Me;
        $data = [];
        $query_color_classes = [];
        $tagger = new Tagger($Me);

        while (($prow = PaperInfo::fetch($result, $Me))) {
            if (!$Me->can_view_paper($prow))
                continue;
            if ($reviewf)
                $revs = $reviewf($prow, null, $Me, "loop");
            else
                $revs = array(null);
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
            if ($s && $s !== "default" && $s !== "plain")
                $d->className = $s;
            else if ($s && @$query_color_classes[$style])
                $d->className = $query_color_classes[$style];
            if (@$this->queries[$q])
                $d->label = $this->queries[$q];
        }
        unset($d);

        return $data;
    }

    private function _scatter_data($result, $fxf, $fyf, $reviewf) {
        global $Me;
        $data = [];
        $tagger = new Tagger($Me);
        $want_reviewer_color = $this->fx->result_format() === "reviewer"
            && ($this->type & self::BOXPLOT);
        if ($want_reviewer_color)
            $pcm = pcMembers();

        while (($prow = PaperInfo::fetch($result, $Me))) {
            if (!$Me->can_view_paper($prow))
                continue;
            $queries = @$this->papermap[$prow->paperId];
            $s = @$this->query_styles[(int) $queries[0]];
            $reviewer_color = false;
            if ($want_reviewer_color && (!$s || $s === "default")
                && $Me->can_view_reviewer_tags($prow))
                $reviewer_color = true;
            if (!$s || $s === "default") {
                $s = "";
                if (@$prow->paperTags && $Me->can_view_tags($prow)
                    && ($color = TagInfo::color_classes($tagger->viewable($prow->paperTags), 2)))
                    $s = $color;
            } else if ($s === "plain")
                $s = "";
            $d = array(0, 0, $prow->paperId);
            if ($reviewf)
                $revs = $reviewf($prow, null, $Me, "loop");
            else
                $revs = array(null);
            foreach ($revs as $rcid) {
                $d[0] = $fxf($prow, $rcid, $Me);
                $d[1] = $fyf($prow, $rcid, $Me);
                if ($d[0] === null || $d[1] === null)
                    continue;
                if ($reviewer_color) {
                    $s = "";
                    if (($p = $pcm[$d[0]]))
                        $s = TagInfo::color_classes($tagger->viewable($p->contactTags));
                }
                if ($reviewf)
                    $d[2] = $prow->paperId . unparseReviewOrdinal($prow->review_ordinal($rcid));
                if (($this->type & self::BARCHART) || $this->fx_query) {
                    foreach ($queries as $q) {
                        $d[$this->fx_query ? 0 : 1] = $q;
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
        $yi = !($this->type & self::BARCHART)
            && $this->fy->result_format() === "reviewer";
        $reviewer_cids = [];
        foreach ($data as $dx)
            foreach ($dx as $d) {
                $xi && $d[0] && ($reviewer_cids[$d[0]] = true);
                $yi && $d[1] && ($reviewer_cids[$d[1]] = true);
            }

        $result = Dbl::qe("select contactId, firstName, lastName, email, roles, contactTags from ContactInfo where contactId ?a", array_keys($reviewer_cids));
        $this->reviewers = [];
        while ($result && ($c = $result->fetch_object("Contact")))
            $this->reviewers[$c->contactId] = $c;
        Dbl::free($result);
        uasort($this->reviewers, "Contact::compare");
        $i = 0;
        foreach ($this->reviewers as $c)
            $c->graph_index = ++$i;

        foreach ($data as &$dx) {
            foreach ($dx as &$d) {
                $xi && $d[0] && ($d[0] = $this->reviewers[$d[0]]->graph_index);
                $yi && $d[1] && ($d[1] = $this->reviewers[$d[1]]->graph_index);
            }
            unset($d);
        }
    }

    public function data() {
        global $Conf, $Me;
        $fxf = $this->fx->compile_function($Me);
        $fyf = $this->fy->compile_function($Me);
        if ($this->fx->needs_review())
            $reviewf = $fxf;
        else if ($this->fy->needs_review())
            $reviewf = $fyf;
        else
            $reviewf = null;

        // load data
        $paperIds = array_keys($this->papermap);
        $queryOptions = array("paperId" => $paperIds, "tags" => true);
        if ($reviewf)
            $queryOptions["reviewOrdinals"] = true;
        $this->fx->add_query_options($queryOptions, $Me);
        $this->fy->add_query_options($queryOptions, $Me);
        $result = Dbl::qe_raw($Conf->paperQuery($Me, $queryOptions));

        if ($this->type == self::CDF)
            $data = $this->_cdf_data($result, $fxf, $reviewf);
        else
            $data = $this->_scatter_data($result, $fxf, $fyf, $reviewf);

        Dbl::free($result);
        return $data;
    }

    public function axis_info_settings($axis) {
        global $Conf, $Me, $reviewTypeName;
        $f = $axis == "x" ? $this->fx : $this->fy;
        $t = array();
        if ($axis == "y" && $this->type == self::FBARCHART)
            $t[] = "ylabel:\"fraction of papers\",yfraction:true";
        else if ($axis == "y" && $this->type == self::BARCHART)
            $t[] = "ylabel:\"# papers\"";
        else if ($axis != "x" || !$this->fx_query)
            $t[] = "{$axis}label:" . json_encode($f->expression);
        $format = $f->result_format();
        $rticks = ($axis == "y" ? ",yaxis_setup:hotcrp_graphs.rotate_ticks(-90)" : "");
        if ($axis == "x" && $this->fx_query) {
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks(" . json_encode($this->queries) . ")";
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
                $name = $r->name_html(); // XXX should be text
                if ($Me->can_view_reviewer_tags()
                    && ($colors = $tagger->viewable_color_classes($r->contactTags)))
                    $x[$r->graph_index] = ["text" => $name, "color_classes" => $colors];
                else
                    $x[$r->graph_index] = $name;
            }
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks("
                    . json_encode($x) . ")" . $rticks;
        } else if ($format === "dec")
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks("
                    . json_encode($Conf->decision_map()) . ")" . $rticks;
        else if ($format === "bool")
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks({0:\"no\",1:\"yes\"})" . $rticks;
        else if ($format instanceof PaperOption && $format->has_selector())
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks(" . json_encode($format->selector) . ")" . $rticks;
        else if ($format === "revround")
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks(" . json_encode($Conf->defined_round_list()) . ")" . $rticks;
        else if ($format === "revtype")
            $t[] = $axis . "ticks:hotcrp_graphs.named_integer_ticks(" . json_encode($reviewTypeName) . ")" . $rticks;
        return join(",", $t);
    }
}
