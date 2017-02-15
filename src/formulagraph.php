<?php
// formulagraph.php -- HotCRP class for drawing graphs
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class FormulaGraph {
    const SCATTER = 1;
    const CDF = 2;
    const BARCHART = 4; /* NB is bitmask */
    const FBARCHART = 5;
    const BOXPLOT = 8;

    const REVIEWER_COLOR = 1;

    const X_QUERY = 1;
    const X_TAG = 2;

    public $conf;
    public $user;
    public $type = 0;
    public $fx;
    public $fy;
    public $fx_type = 0;
    private $queries = [];
    private $query_styles = [];
    private $papermap = [];
    private $reviewers = [];
    private $reviewer_color = false;
    private $remapped_rounds = [];
    private $tags = [];
    public $error_html = [];
    public $errf = [];

    function __construct(Contact $user, $fx, $fy) {
        $this->conf = $user->conf;
        $this->user = $user;
        $fx = simplify_whitespace($fx);
        $fy = simplify_whitespace($fy);
        if (strcasecmp($fx, "query") == 0 || strcasecmp($fx, "search") == 0) {
            $this->fx = new Formula($this->user, "0", true);
            $this->fx_type = self::X_QUERY;
        } else if (strcasecmp($fx, "tag") == 0) {
            $this->fx = new Formula($this->user, "0", true);
            $this->fx_type = self::X_TAG;
        } else
            $this->fx = new Formula($this->user, $fx, true);
        if (strcasecmp($fy, "cdf") == 0) {
            $this->type = self::CDF;
            $this->fy = new Formula($this->user, "0", true);
        } else if (preg_match('/\A(?:count|bar|bars|barchart)\z/i', $fy)) {
            $this->type = self::BARCHART;
            $this->fy = new Formula($this->user, "sum(1)", true);
        } else if (preg_match('/\A(?:stack|frac|fraction)\z/i', $fy)) {
            $this->type = self::FBARCHART;
            $this->fy = new Formula($this->user, "sum(1)", true);
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
            $this->fy = new Formula($this->user, $fy, true);
            if (!$this->type) {
                $this->type = self::SCATTER;
                if (!$this->fy->datatypes() && $this->fy->can_combine())
                    $this->type = self::BARCHART;
            }
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
            $this->fy = new Formula($this->user, "sum(0)", true);
        } else if ($this->type === self::CDF && $this->fx_type === self::X_TAG) {
            $this->error_html[] = "CDFs by tag don’t make sense.";
            $this->errf["fy"] = true;
        }
    }

    function add_query($q, $style, $fieldname = false) {
        $qn = count($this->queries);
        $this->queries[] = $q;
        if ($style === "by-tag" || $style === "default")
            $style = "";
        $this->query_styles[] = $style;
        $psearch = new PaperSearch($this->user, array("q" => $q));
        foreach ($psearch->paperList() as $pid)
            $this->papermap[$pid][] = $qn;
        if (!empty($psearch->warnings)) {
            $this->error_html = array_merge($this->error_html, $psearch->warnings);
            if ($fieldname)
                $this->errf[$fieldname] = true;
        }
    }

    private function _cdf_data(PaperInfoSet $rowset) {
        $data = [];
        $query_color_classes = [];

        $fxf = $this->fx->compile_function();
        $reviewf = null;
        if ($this->fx->is_indexed())
            $reviewf = Formula::compile_indexes_function($this->user, $this->fx->datatypes());

        foreach ($rowset->all() as $prow) {
            $revs = $reviewf ? $reviewf($prow, $this->user) : [null];
            $queries = get($this->papermap, $prow->paperId);
            foreach ($queries as $q)
                if (get($query_color_classes, $q) !== "") {
                    $c = "";
                    if ($prow->paperTags)
                        $c = $this->conf->tags()->color_classes($prow->viewable_tags($this->user), 2);
                    if ($c !== "" && (get($query_color_classes, $q) ? : $c) !== $c)
                        $c = "";
                    $query_color_classes[$q] = $c;
                }
            foreach ($revs as $rcid)
                if (($x = $fxf($prow, $rcid, $this->user)) !== null) {
                    if ($this->fx_type === self::X_QUERY) {
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
            $s = get($this->query_styles, $q);
            if ($s && $s !== "plain")
                $d->className = $s;
            else if ($s && get($query_color_classes, $s))
                $d->className = $query_color_classes[$s];
            if (get($this->queries, $q))
                $d->label = $this->queries[$q];
        }
        unset($d);
        return $data;
    }

    private function _prepare_reviewer_color(Contact $user) {
        $this->reviewer_color = array();
        foreach ($this->conf->pc_members() as $p)
            $this->reviewer_color[$p->contactId] = $this->conf->tags()->color_classes($p->viewable_tags($user));
    }

    private function _paper_style(PaperInfo $prow) {
        $qnum = $this->papermap[$prow->paperId][0];
        $s = get($this->query_styles, (int) $qnum);
        if (!$s && $this->reviewer_color && $this->user->can_view_reviewer_tags($prow))
            return self::REVIEWER_COLOR;
        else if (!$s && $prow->paperTags && ($c = $prow->viewable_tags($this->user)))
            return trim($prow->conf->tags()->color_classes($c));
        else if ($s === "plain")
            return "";
        else
            return $s;
    }

    private function _add_tag_data(&$data, $d, PaperInfo $prow) {
        assert($this->fx_type === self::X_TAG);
        $tags = TagInfo::split_unpack($prow->viewable_tags($this->user));
        foreach ($tags as $ti) {
            if (!isset($this->tags[$ti[0]]))
                $this->tags[$ti[0]] = count($this->tags);
            $d[0] = $this->tags[$ti[0]];
            $data[] = $d;
        }
    }

    private function _scatter_data(PaperInfoSet $rowset) {
        $data = [];
        if ($this->fx->result_format() === Fexpr::FREVIEWER && ($this->type & self::BOXPLOT))
            $this->_prepare_reviewer_color($this->user);

        $fxf = $this->fx->compile_function();
        $fyf = $this->fy->compile_function();
        $reviewf = null;
        if ($this->fx->is_indexed() || $this->fy->is_indexed())
            $reviewf = Formula::compile_indexes_function($this->user, $this->fx->datatypes() | $this->fy->datatypes());

        foreach ($rowset->all() as $prow) {
            $s = $ps = $this->_paper_style($prow);
            $d = [0, 0, 0];
            $revs = $reviewf ? $reviewf($prow, $this->user) : [null];
            foreach ($revs as $rcid) {
                $d[0] = $fxf($prow, $rcid, $this->user);
                $d[1] = $fyf($prow, $rcid, $this->user);
                if ($d[0] === null || $d[1] === null)
                    continue;
                $d[2] = $prow->paperId;
                if ($rcid && ($o = $prow->review_ordinal($rcid)))
                    $d[2] .= unparseReviewOrdinal($o);
                if ($ps === self::REVIEWER_COLOR)
                    $s = get($this->reviewer_color, $d[0]) ? : "";
                if ($this->fx_type === self::X_QUERY) {
                    foreach ($this->papermap[$prow->paperId] as $q) {
                        $d[0] = $q;
                        $data[$s][] = $d;
                    }
                } else if ($this->fx_type === self::X_TAG)
                    $this->_add_tag_data($data[$s], $d, $prow);
                else
                    $data[$s][] = $d;
            }
        }
        return $data;
    }

    // combine data: [x, y, pids, style, [query]]

    static function barchart_compare($a, $b) {
        if (get_i($a, 4) != get_i($b, 4))
            return get_i($a, 4) - get_i($b, 4);
        if ($a[0] != $b[0])
            return $a[0] < $b[0] ? -1 : 1;
        return strcmp($a[3], $b[3]);
    }

    private function _combine_data(PaperInfoSet $rowset) {
        $data = [];
        if ($this->fx->result_format() === Fexpr::FREVIEWER)
            $this->_prepare_reviewer_color($this->user);

        $fxf = $this->fx->compile_function();
        list($fytrack, $fycombine) = $this->fy->compile_combine_functions();
        $reviewf = null;
        if ($this->fx->is_indexed() || $this->fy->datatypes())
            $reviewf = Formula::compile_indexes_function($this->user, ($this->fx->is_indexed() ? $this->fx->datatypes() : 0) | $this->fy->datatypes());

        foreach ($rowset->all() as $prow) {
            $queries = $this->papermap[$prow->paperId];
            $s = $ps = $this->_paper_style($prow);
            $revs = $reviewf ? $reviewf($prow, $this->user) : [null];
            foreach ($revs as $rcid) {
                if (($x = $fxf($prow, $rcid, $this->user)) === null)
                    continue;
                if ($ps === self::REVIEWER_COLOR)
                    $s = get($this->reviewer_color, $d[0]) ? : "";
                $d = [$x, $fytrack($prow, $rcid, $this->user), $prow->paperId, $s];
                if ($rcid && ($o = $prow->review_ordinal($rcid)))
                    $d[2] .= unparseReviewOrdinal($o);
                foreach ($queries as $q) {
                    $q && ($d[4] = $q);
                    if ($this->fx_type === self::X_TAG)
                        $this->_add_tag_data($data, $d, $prow);
                    else
                        $data[] = $d;
                }
            }
        }

        $is_sum = $this->fy->is_sum();
        usort($data, "FormulaGraph::barchart_compare");
        $ndata = [];
        for ($i = 0; $i != count($data); $i = $j) {
            $d = [$data[$i][0], [$data[$i][1]], [$data[$i][2]], $data[$i][3],
                  get($data[$i], 4)];
            for ($j = $i + 1;
                 $j != count($data) && $data[$j][0] == $d[0]
                 && get($data[$j], 4) == $d[4]
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
        if ((!$this->fx_type && $this->fx->result_format() === $format)
            || ($this->fx_type == self::X_TAG && $format === Fexpr::FTAG))
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
        while ($result && ($c = Contact::fetch($result)))
            $this->reviewers[$c->contactId] = $c;
        Dbl::free($result);
        uasort($this->reviewers, "Contact::compare");
        $i = 0;
        $m = [];
        foreach ($this->reviewers as $c) {
            $c->sort_position = ++$i;
            $m[$c->contactId] = $i;
        }
        $this->_valuemap_rewrite($data, $axes, $m);
    }

    private function _revround_reformat(&$data) {
        if (!($axes = $this->_valuemap_axes(Fexpr::FROUND))
            || !($rs = $this->_valuemap_collect($data, $axes)))
            return;
        $i = 0;
        $m = [];
        foreach ($this->conf->defined_round_list() as $n => $rname)
            if (get($rs, $n)) {
                $this->remapped_rounds[++$i] = $rname;
                $m[$n] = $i;
            }
        $this->_valuemap_rewrite($data, $axes, $m);
    }

    private function _tag_reformat(&$data) {
        if (!($axes = $this->_valuemap_axes(Fexpr::FTAG))
            || !($rs = $this->_valuemap_collect($data, $axes)))
            return;
        $tagger = new Tagger($this->user);
        uksort($this->tags, [$tagger, "tag_compare"]);
        $i = -1;
        $m = [];
        foreach ($this->tags as $tag => $ri)
            $m[$ri] = ++$i;
        $this->_valuemap_rewrite($data, $axes, $m);
    }

    function data() {
        // load data
        $paperIds = array_keys($this->papermap);
        $queryOptions = array("paperId" => $paperIds, "tags" => true);
        $this->fx->add_query_options($queryOptions);
        $this->fy->add_query_options($queryOptions);
        if ($this->fx->is_indexed() || $this->fy->is_indexed())
            $queryOptions["reviewOrdinals"] = true;

        $result = $this->user->paper_result($queryOptions);
        $rowset = new PaperInfoSet;
        while (($prow = PaperInfo::fetch($result, $this->user)))
            if ($this->user->can_view_paper($prow))
                $rowset->add($prow);
        Dbl::free($result);

        if ($this->type == self::CDF)
            $data = $this->_cdf_data($rowset);
        else if ($this->type & self::BARCHART)
            $data = $this->_combine_data($rowset);
        else
            $data = $this->_scatter_data($rowset);
        $this->_reviewer_reformat($data);
        $this->_revround_reformat($data);
        $this->_tag_reformat($data);

        return $data;
    }

    function axis_info_settings($axis) {
        $isx = $axis === "x";
        $f = $isx ? $this->fx : $this->fy;
        $t = array();
        $counttype = $this->fx->is_indexed() ? "reviews" : "papers";
        if (!$isx && $this->type == self::FBARCHART)
            $t[] = "label:\"fraction of $counttype\",fraction:true";
        else if (!$isx && $this->type == self::BARCHART
                 && $f->expression === "sum(1)")
            $t[] = "label:\"# $counttype\"";
        else if (!$isx && $this->type == self::CDF)
            $t[] = "label:\"CDF of $counttype\"";
        else if (!$isx || !$this->fx_type)
            $t[] = "label:" . json_encode($f->expression);
        $format = $f->result_format();
        $rticks = (!$isx ? ",axis_setup:hotcrp_graphs.rotate_ticks(-90)" : "");
        if ($isx && $this->fx_type == self::X_QUERY) {
            $t[] = "ticks:hotcrp_graphs.named_integer_ticks(" . json_encode($this->queries) . ")";
        } else if ($isx && $this->fx_type == self::X_TAG) {
            $tagger = new Tagger($this->user);
            $tags = array_map(function ($t) use ($tagger) {
                return $tagger->unparse($t);
            }, array_keys($this->tags));
            $t[] = "ticks:hotcrp_graphs.named_integer_ticks(" . json_encode($tags) . ")";
        } else if ($format instanceof ReviewField) {
            if ($format->option_letter)
                $t[] = "flip:true";
            $n = count($format->options);
            $ol = $format->option_letter ? chr($format->option_letter - $n) : null;
            $t[] = "ticks:hotcrp_graphs.option_letter_ticks("
                    . $n . "," . json_encode($ol) . "," . json_encode($format->option_class_prefix) . ")";
        } else if ($format === Fexpr::FREVIEWER) {
            $x = [];
            foreach ($this->reviewers as $r) {
                $rd = ["text" => $this->user->name_text_for($r),
                       "search" => "re:" . $r->email];
                if ($this->user->can_view_reviewer_tags()
                    && ($colors = $r->viewable_color_classes($this->user)))
                    $rd["color_classes"] = $colors;
                $x[$r->sort_position] = $rd;
            }
            $t[] = "ticks:hotcrp_graphs.named_integer_ticks("
                    . json_encode($x) . ")" . $rticks;
        } else if ($format === Fexpr::FDECISION)
            $t[] = "ticks:hotcrp_graphs.named_integer_ticks("
                    . json_encode($this->conf->decision_map()) . ")" . $rticks;
        else if ($format === Fexpr::FBOOL)
            $t[] = "ticks:hotcrp_graphs.named_integer_ticks({0:\"no\",1:\"yes\"})" . $rticks;
        else if ($format instanceof PaperOption && $format->has_selector())
            $t[] = "ticks:hotcrp_graphs.named_integer_ticks(" . json_encode($format->selector) . ")" . $rticks;
        else if ($format === Fexpr::FROUND)
            $t[] = "ticks:hotcrp_graphs.named_integer_ticks(" . json_encode($this->remapped_rounds) . ")" . $rticks;
        else if ($format === Fexpr::FREVTYPE)
            $t[] = "ticks:hotcrp_graphs.named_integer_ticks(" . json_encode(ReviewForm::$revtype_names) . ")" . $rticks;
        return "\"{$axis}\":{" . join(",", $t) . "}";
    }
}
