<?php
// formulagraph.php -- HotCRP class for drawing graphs
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class FormulaGraph {
    const SCATTER = 0;
    const CDF = 1;
    const BARCHART = 2;

    public $type = self::SCATTER;
    public $fx;
    public $fy;
    private $queries = array();
    private $query_styles = array();
    private $papermap = array();
    public $error_html = array();
    public $errf = array();

    public function __construct($fx, $fy) {
        $fx = simplify_whitespace($fx);
        $fy = simplify_whitespace($fy);
        if (preg_match('/\Abar(?:|s|chart)\z/i', $fx) && false) {
            $this->type = self::BARCHART;
            $this->fx = new Formula("1", true);
        } else
            $this->fx = new Formula($fx, true);
        if (strcasecmp($fy, "cdf") == 0 && $this->type != self::BARCHART) {
            $this->type = self::CDF;
            $this->fy = new Formula("1", true);
        } else if (strcasecmp($fy, "count") == 0 && $this->type == self::BARCHART)
            $this->fy = new Formula("1", true);
        else
            $this->fy = new Formula($fy, true);

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

    public function data() {
        global $Conf, $Me;
        $fxf = $this->fx->compile_function($Me);
        $fyf = $this->fy->compile_function($Me);
        if ($this->fx->needs_review)
            $reviewf = $this->fx;
        else if ($this->fy->needs_review)
            $reviewf = $this->fy;
        else
            $reviewf = null;

        $defaultstyles = array();

        // load data
        $paperIds = array_keys($this->papermap);
        $queryOptions = array("paperId" => $paperIds, "tags" => true);
        if ($reviewf)
            $queryOptions["reviewOrdinals"] = true;
        $this->fx->add_query_options($queryOptions, $Me);
        $this->fy->add_query_options($queryOptions, $Me);
        $result = Dbl::qe_raw($Conf->paperQuery($Me, $queryOptions));
        $data = array();
        while (($prow = PaperInfo::fetch($result, $Me)))
            if ($Me->can_view_paper($prow)) {
                if ($reviewf)
                    $revs = $reviewf($prow, null, $Me, "loop");
                else
                    $revs = array(null);
                $d = array(0, 0, $prow->paperId);
                $style = @$this->papermap[$prow->paperId];
                if ($this->type != self::SCATTER) {
                    foreach ($style as $s)
                        if (@$defaultstyles[$s] !== "") {
                            $c = "";
                            if (@$prow->paperTags && $Me->can_view_tags($prow))
                                $c = TagInfo::color_classes($prow->paperTags, true);
                            if ($c !== "" && (@$defaultstyles[$s] ? : $c) !== $c)
                                $c = "";
                            $defaultstyles[$s] = $c;
                        }
                } else {
                    $s = @$this->query_styles[(int) $style[0]];
                    if ($s && $s !== "default" && $s !== "plain")
                        $d[] = $s;
                    else if ($s !== "plain"
                             && @$prow->paperTags && $Me->can_view_tags($prow)
                             && ($color = TagInfo::color_classes($prow->paperTags, true)))
                        $d[] = $color;
                }
                foreach ($revs as $rcid) {
                    $d[0] = $fxf($prow, $rcid, $Me);
                    $d[1] = $fyf($prow, $rcid, $Me);
                    if ($reviewf)
                        $d[2] = $prow->paperId . unparseReviewOrdinal($prow->review_ordinal($rcid));
                    if ($d[0] === null || $d[1] === null)
                        /* skip */;
                    else if ($this->type == self::CDF) {
                        foreach ($style as $s)
                            $data[$s][] = $d[0];
                    } else if ($this->type == self::BARCHART) {
                        foreach ($style as $s)
                            $data[$s][] = array($d[0], @$d[2]);
                    } else
                        $data[] = $d;
                }
            }
        Dbl::free($result);

        if ($this->type == self::CDF) {
            foreach ($data as $style => &$d) {
                $d = (object) array("d" => $d);
                $s = @$this->query_styles[$style];
                if ($s && $s !== "default" && $s !== "plain")
                    $d->className = $s;
                else if ($s && @$defaultstyles[$style])
                    $d->className = $defaultstyles[$style];
                if (@$this->queries[$style])
                    $d->label = $this->queries[$style];
            }
            unset($d);
        }

        return $data;
    }

    public function axis_info_settings($axis) {
        global $Conf;
        $f = $axis == "x" ? $this->fx : $this->fy;
        $t = "{$axis}label:" . json_encode($f->expression);
        $format = $f->result_format();
        $rticks = ($type == "y" ? ",yaxis_setup:hotcrp_graphs.rotate_ticks(-90)" : "");
        if ($format instanceof ReviewField && $format->option_letter) {
            $t .= "," . $type . "flip:true"
                . "," . $type . "tick_setup:hotcrp_graphs.option_letter_ticks("
                    . count($format->options) . ",\"" . chr($format->option_letter - 1) . "\")";
        } else if ($format === "dec")
            $t .= "," . $type . "tick_setup:hotcrp_graphs.named_integer_ticks("
                    . json_encode($Conf->decision_map()) . ")" . $rticks;
        else if ($format === "bool")
            $t .= "," . $type . "tick_setup:hotcrp_graphs.named_integer_ticks({0:\"no\",1:\"yes\"})" . $rticks;
        else if ($format instanceof PaperOption && $format->has_selector())
            $t .= "," . $type . "tick_setup:hotcrp_graphs.named_integer_ticks(" . json_encode($format->selector) . ")" . $rticks;
        return $t;
    }
}
