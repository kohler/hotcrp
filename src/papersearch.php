<?php
// papersearch.php -- HotCRP helper class for searching for papers
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SearchOperator {
    public $op;
    public $unary;
    public $precedence;
    public $opinfo;
    function __construct($what, $unary, $precedence, $opinfo = null) {
        $this->op = $what;
        $this->unary = $unary;
        $this->precedence = $precedence;
        $this->opinfo = $opinfo;
    }

    static public $list;
}

SearchOperator::$list =
        array("(" => new SearchOperator("(", true, null),
              "NOT" => new SearchOperator("not", true, 7),
              "-" => new SearchOperator("not", true, 7),
              "+" => new SearchOperator("+", true, 7),
              "SPACE" => new SearchOperator("and2", false, 6),
              "AND" => new SearchOperator("and", false, 5),
              "OR" => new SearchOperator("or", false, 4),
              "XAND" => new SearchOperator("and2", false, 3),
              "XOR" => new SearchOperator("or", false, 3),
              "THEN" => new SearchOperator("then", false, 2),
              "HIGHLIGHT" => new SearchOperator("highlight", false, 1, ""),
              ")" => null);


class SearchTerm {
    public $type;
    public $link;
    public $flags;
    public $value;
    public $float = [];

    function __construct($t, $f = 0, $v = null, $other = null) {
        if ($t instanceof SearchOperator) {
            $this->type = $t->op;
            if (isset($t->opinfo))
                $this->set_float("opinfo", $t->opinfo);
        } else
            $this->type = $t;
        $this->link = false;
        $this->flags = $f;
        $this->value = $v;
        if ($other) {
            foreach ($other as $k => $v)
                $this->$k = $v;
        }
    }
    protected function append($term) {
        if ($term) {
            foreach ($term->float as $k => $v) {
                $v1 = get($this->float, $k);
                if ($k === "sort" && $v1)
                    array_splice($this->float[$k], count($v1), 0, $v);
                else if ($k === "strspan" && $v1)
                    $this->apply_strspan($v);
                else if (is_array($v1) && is_array($v))
                    $this->float[$k] = array_replace_recursive($v1, $v);
                else if ($k !== "opinfo" || $v1 === null)
                    $this->float[$k] = $v;
            }
            $this->value[] = $term;
        }
        return $this;
    }
    protected function finish() {
        assert(false);
    }
    protected function _flatten_values() {
        $qvs = array();
        foreach ($this->value ? : array() as $qv)
            if ($qv->type === $this->type)
                $qvs = array_merge($qvs, $qv->value);
            else
                $qvs[] = $qv;
        return $qvs;
    }
    protected function _finish_combine($newvalue, $pn, $revadj, $any) {
        if (count($pn[0]) || count($pn[1]))
            $newvalue[] = new SearchTerm("pn", 0, $pn);
        if ($revadj)            // must be first
            array_unshift($newvalue, $revadj);
        $qr = null;
        if (!$newvalue)
            $qr = new SearchTerm($any ? "t" : "f");
        else if (count($newvalue) == 1)
            $qr = clone $newvalue[0];
        if ($qr) {
            $qr->float = $this->float;
            return $qr;
        } else {
            $this->value = $newvalue;
            return $this;
        }
    }
    static function make_op($op, $terms) {
        $opstr = is_object($op) ? $op->op : $op;
        if ($opstr === "not")
            $qr = new Not_SearchTerm;
        else if ($opstr === "and" || $opstr === "and2")
            $qr = new And_SearchTerm($opstr);
        else if ($opstr === "or")
            $qr = new Or_SearchTerm;
        else
            $qr = new Then_SearchTerm($op);
        foreach (is_array($terms) ? $terms : [$terms] as $qt)
            $qr->append($qt);
        return $qr->finish();
    }
    static function make_not($term) {
        $qr = new Not_SearchTerm;
        return $qr->append($term)->finish();
    }
    static function make_float($float) {
        return new SearchTerm("t", 0, null, array("float" => $float));
    }
    function is_false() {
        return $this->type === "f";
    }
    function is_true() {
        return $this->type === "t";
    }
    function is_then() {
        return false;
    }
    function is_uninteresting() {
        return $this->type === "t"
            && count($this->float) === 1
            && isset($this->float["view"]);
    }
    function set($k, $v) {
        $this->$k = $v;
    }
    function get($k, $defval = null) {
        return isset($this->$k) ? $this->$k : $defval;
    }
    function set_float($k, $v) {
        $this->float[$k] = $v;
    }
    function get_float($k, $defval = null) {
        return get($this->float, $k, $defval);
    }
    function apply_strspan($span) {
        $span1 = get($this->float, "strspan");
        if ($span && $span1)
            $span = [min($span[0], $span1[0]), max($span[1], $span1[1])];
        $this->set_float("strspan", $span ? : $span1);
    }


    protected function _set_flags(&$q, SearchQueryInfo $sqi) {
        $flags = $this->flags;
        $sqi->needflags |= $flags;

        if ($flags & PaperSearch::F_MANAGER) {
            if ($sqi->conf->has_any_manager() && $sqi->srch->privChair)
                $q[] = "(managerContactId={$sqi->user->contactId} or (managerContactId=0 and PaperConflict.conflictType is null))";
            else if ($sqi->user->is_track_manager())
                $q[] = "true";
            else if ($sqi->user->is_manager())
                $q[] = "managerContactId={$sqi->user->contactId}";
            else
                $q[] = "false";
            $sqi->add_rights_columns();
        }
        if ($flags & PaperSearch::F_NONCONFLICT)
            $q[] = "PaperConflict.conflictType is null";
        if ($flags & PaperSearch::F_AUTHOR)
            $q[] = $sqi->user->actAuthorSql("PaperConflict");
        if ($flags & PaperSearch::F_REVIEWER)
            $q[] = "MyReview.reviewNeedsSubmit=0";
        if ($flags & PaperSearch::F_XVIEW) {
            $sqi->needflags |= PaperSearch::F_NONCONFLICT | PaperSearch::F_REVIEWER;
            $sqi->add_rights_columns();
        }
        if (($flags & PaperSearch::F_FALSE)
            || ($sqi->negated && ($flags & PaperSearch::F_XVIEW)))
            $q[] = "false";
    }

    private function _clauseTermSetTable($value, $compar, $shorttab,
                                         $table, $field, $where, SearchQueryInfo $sqi) {
        // see also first "tag" case below
        $q = array();
        $this->_set_flags($q, $sqi);

        if ($value === "none" && !$compar)
            list($compar, $value) = array("=0", "");
        else if (($value === "" || $value === "any") && !$compar)
            list($compar, $value) = array(">0", "");
        else if (!$compar || $compar === ">=1")
            $compar = ">0";
        else if ($compar === "<=0" || $compar === "<1")
            $compar = "=0";

        $thistab = $shorttab . "_" . count($sqi->tables);
        if ($value === "") {
            if ($compar === ">0" || $compar === "=0")
                $thistab = "Any" . $shorttab;
            $tdef = array("left join", $table);
        } else if (is_array($value)) {
            if (count($value))
                $tdef = array("left join", $table, "$thistab.$field in (" . join(",", $value) . ")");
            else
                $tdef = array("left join", $table, "false");
        } else if (strpos($value, "\3") !== false) {
            $tdef = array("left join", $table, str_replace("\3", "$thistab.$field", $value));
        } else {
            $tdef = array("left join", $table, "$thistab.$field='" . sqlq($value) . "'");
        }
        if ($where)
            $tdef[2] .= str_replace("%", $thistab, $where);

        if ($compar !== ">0" && $compar !== "=0") {
            $tdef[1] = "(select paperId, count(*) ct from " . $tdef[1] . " as " . $thistab;
            if (count($tdef) > 2)
                $tdef[1] .= " where " . array_pop($tdef);
            $tdef[1] .= " group by paperId)";
            $sqi->add_column($thistab . "_ct", "$thistab.ct");
            $q[] = "coalesce($thistab.ct,0)$compar";
        } else {
            $sqi->add_column($thistab . "_ct", "count($thistab.$field)");
            if ($compar === "=0")
                $q[] = "$thistab.$field is null";
            else
                $q[] = "$thistab.$field is not null";
        }

        $sqi->add_table($thistab, $tdef);
        $this->link = $thistab . "_ct";
        return "(" . join(" and ", $q) . ")";
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $tt = $this->type;
        $thistab = null;

        // collect columns
        if ($tt === "au_cid") {
            return $this->_clauseTermSetTable($this->value, null, "AuthorConflict",
                                       "PaperConflict", "contactId",
                                       " and " . $sqi->user->actAuthorSql("%"),
                                       $sqi);
        } else if ($tt === "conflict") {
            return $this->_clauseTermSetTable($this->value->contact_set(),
                                       $this->value->countexpr(), "Conflict",
                                       "PaperConflict", "contactId", "",
                                       $sqi);
        } else if ($tt === "pn") {
            $q = array();
            if (count($this->value[0]))
                $q[] = "Paper.paperId in (" . join(",", $this->value[0]) . ")";
            if (count($this->value[1]))
                $q[] = "Paper.paperId not in (" . join(",", $this->value[1]) . ")";
            if (!count($q))
                $q[] = "false";
            return "(" . join(" and ", $q) . ")";
        } else if ($tt === "pf") {
            $q = array();
            $this->_set_flags($q, $sqi);
            for ($i = 0; $i < count($this->value); $i += 2) {
                $sqi->add_column($this->value[$i], "Paper." . $this->value[$i]);
                if (is_array($this->value[$i + 1]))
                    $q[] = "Paper." . $this->value[$i] . " in (" . join(",", $this->value[$i + 1]) . ")";
                else
                    $q[] = "Paper." . $this->value[$i] . $this->value[$i + 1];
            }
            return "(" . join(" and ", $q) . ")";
        } else if ($tt === "tag") {
            return $this->_clauseTermSetTable($this->value->tagmatch_sql($sqi->user), null, "Tag",
                                       "PaperTag", "tag", $this->value->index_wheresql(),
                                       $sqi);
        } else if ($tt === "option") {
            // expanded from _clauseTermSetTable
            $q = array();
            $this->_set_flags($q, $sqi);
            $thistab = "Option_" . count($sqi->tables);
            $sqi->add_table($thistab, array("left join", "(select paperId, max(value) v from PaperOption where optionId=" . $this->value->option->id . " group by paperId)"));
            $sqi->add_column($thistab . "_x", "coalesce($thistab.v,0)" . $this->value->table_matcher());
            $this->link = $thistab . "_x";
            $q[] = $sqi->columns[$this->link];
            return "(" . join(" and ", $q) . ")";
        } else if ($tt === "formula") {
            $q = array("true");
            $this->_set_flags($q, $sqi);
            $this->value->add_query_options($sqi->srch->_query_options);
            if (!$this->link)
                $this->link = $this->value->compile_function();
            return "(" . join(" and ", $q) . ")";
        } else if ($tt === "f")
            return "false";
        else if ($tt === "t")
            return "true";
        else
            return "true";
    }

    static function andjoin_sqlexpr($q, $default = "false") {
        return empty($q) ? $default : "(" . join(" and ", $q) . ")";
    }
    static function orjoin_sqlexpr($q, $default = "false") {
        return empty($q) ? $default : "(" . join(" or ", $q) . ")";
    }


    protected function _check_flags(PaperInfo $row, PaperSearch $srch) {
        $flags = $this->flags;
        if (($flags & PaperSearch::F_MANAGER)
            && !$srch->user->can_administer($row, true))
            return false;
        if (($flags & PaperSearch::F_AUTHOR)
            && !$srch->user->act_author_view($row))
            return false;
        if (($flags & PaperSearch::F_REVIEWER)
            && $row->myReviewNeedsSubmit !== 0
            && $row->myReviewNeedsSubmit !== "0")
            return false;
        if (($flags & PaperSearch::F_NONCONFLICT) && $row->conflictType)
            return false;
        if ($flags & PaperSearch::F_XVIEW) {
            if ($this->type === "tag" && !$srch->user->can_view_tags($row, true))
                return false;
            if ($this->type === "tag"
                && $srch->user->privChair
                && $row->managerContactId > 0) {
                $fieldname = $this->link;
                $row->$fieldname = $this->value->evaluate($srch->user, $row->viewable_tags($srch->user)) ? 1 : 0;
            }
            if ($this->type === "au_cid"
                && !$srch->user->can_view_authors($row, true))
                return false;
            if ($this->type === "conflict"
                && !$srch->user->can_view_conflicts($row, true))
                return false;
            if ($this->type === "pf" && $this->value[0] === "outcome"
                && !$srch->user->can_view_decision($row, true))
                return false;
            if ($this->type === "option"
                && !$srch->user->can_view_paper_option($row, $this->value->option, true))
                return false;
        }
        if ($flags & PaperSearch::F_FALSE)
            return false;
        return true;
    }

    function exec(PaperInfo $row, PaperSearch $srch) {
        $tt = $this->type;

        // collect columns
        if ($tt === "au_cid") {
            assert(is_array($this->value));
            return $this->_check_flags($row, $srch)
                && $row->{$this->link} != 0;
        } else if ($tt === "conflict") {
            if (!$this->_check_flags($row, $srch))
                return false;
            else {
                $fieldname = $this->link;
                return $this->value->test((int) $row->$fieldname);
            }
        } else if ($tt === "pn") {
            if (!empty($this->value[0])
                && array_search($row->paperId, $this->value[0]) === false)
                return false;
            else if (!empty($this->value[1])
                     && array_search($row->paperId, $this->value[1]) !== false)
                return false;
            else
                return true;
        } else if ($tt === "pf") {
            if (!$this->_check_flags($row, $srch))
                return false;
            else {
                $ans = true;
                for ($i = 0; $ans && $i < count($this->value); $i += 2) {
                    $fieldname = $this->value[$i];
                    $expr = $this->value[$i + 1];
                    if (is_array($expr))
                        $ans = in_array($row->$fieldname, $expr);
                    else
                        $ans = CountMatcher::compare_string($row->$fieldname, $expr);
                }
                return $ans;
            }
        } else if ($tt === "tag") {
            if (!$this->_check_flags($row, $srch))
                return false;
            $fieldname = $this->link;
            return $row->$fieldname != 0;
        } else if ($tt === "option") {
            if (!$this->_check_flags($row, $srch))
                return false;
            $fieldname = $this->link;
            if ($row->$fieldname == 0)
                return false;
            $om = $this->value;
            if ($om->kind) {
                $ov = $row->option($om->option->id);
                if ($om->kind === "attachment-count" && $ov)
                    return CountMatcher::compare($ov->value_count(), $om->compar, $om->value);
                else if ($om->kind === "attachment-name" && $ov) {
                    $reg = Text::star_text_pregexes($om->value);
                    foreach ($ov->documents() as $doc)
                        if (Text::match_pregexes($reg, $doc->filename, false))
                            return true;
                }
                return false;
            }
            return true;
        } else if ($tt === "formula") {
            $formulaf = $this->link;
            return !!$formulaf($row, null, $srch->user);
        } else if ($tt === "f")
            return false;
        else if ($tt === "t")
            return true;
        else {
            error_log("SearchTerm::exec: $tt defaults, correctness unlikely");
            return true;
        }
    }
}

class Not_SearchTerm extends SearchTerm {
    function __construct() {
        parent::__construct("not");
    }
    protected function finish() {
        $qv = $this->value ? $this->value[0] : null;
        $qr = null;
        if (!$qv || $qv->is_false())
            $qr = new SearchTerm("t");
        else if ($qv->is_true())
            $qr = new SearchTerm("f");
        else if ($qv->type === "not")
            $qr = clone $qv->value[0];
        else if ($qv->type === "pn")
            $qr = new SearchTerm("pn", 0, [$qv->value[1], $qv->value[0]]);
        else if ($qv->type === "revadj") {
            $qr = clone $qv;
            $qr->negated = !$qr->negated;
        }
        if ($qr) {
            $qr->float = $this->float;
            return $qr;
        } else
            return $this;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->negated = !$sqi->negated;
        $ff = $this->value[0]->sqlexpr($sqi);
        $sqi->negated = !$sqi->negated;
        return "not ($ff)";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return !$this->value[0]->exec($row, $srch);
    }
}

class And_SearchTerm extends SearchTerm {
    function __construct($type) {
        parent::__construct($type);
    }
    protected function finish() {
        $pn = array(array(), array());
        $revadj = null;
        $newvalue = array();
        $any = false;

        foreach ($this->_flatten_values() as $qv) {
            if ($qv->is_false()) {
                $qr = new SearchTerm("f");
                $qr->float = $this->float;
                return $qr;
            } else if ($qv->is_true())
                $any = true;
            else if ($qv->type === "pn" && $this->type === "and2") {
                $pn[0] = array_merge($pn[0], $qv->value[0]);
                $pn[1] = array_merge($pn[1], $qv->value[1]);
            } else if ($qv->type === "revadj")
                $revadj = $qv->apply($revadj, false);
            else
                $newvalue[] = $qv;
        }

        return $this->_finish_combine($newvalue, $pn, $revadj, $any);
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $ff = array();
        foreach ($this->value as $subt)
            $ff[] = $subt->sqlexpr($sqi);
        return self::andjoin_sqlexpr($ff);
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        foreach ($this->value as $subt)
            if (!$subt->exec($row, $srch))
                return false;
        return true;
    }
}

class Or_SearchTerm extends SearchTerm {
    function __construct() {
        parent::__construct("or");
    }
    protected function finish() {
        $pn = array(array(), array());
        $revadj = null;
        $newvalue = array();

        foreach ($this->_flatten_values() as $qv) {
            if ($qv->is_true()) {
                $qr = new SearchTerm("t");
                $qr->float = $this->float;
                return $qr;
            } else if ($qv->is_false())
                /* skip */;
            else if ($qv->type === "pn" && count($qv->value[0]))
                $pn[0] = array_merge($pn[0], array_values(array_diff($qv->value[0], $qv->value[1])));
            else if ($qv->type === "revadj")
                $revadj = $qv->apply($revadj, true);
            else
                $newvalue[] = $qv;
        }

        return $this->_finish_combine($newvalue, $pn, $revadj, false);
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $ff = array();
        foreach ($this->value as $subt)
            $ff[] = $subt->sqlexpr($sqi);
        return self::orjoin_sqlexpr($ff);
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        foreach ($this->value as $subt)
            if ($subt->exec($row, $srch))
                return true;
        return false;
    }
}

class Then_SearchTerm extends SearchTerm {
    private $is_highlight;
    public $nthen;
    public $highlights;
    public $highlight_types;

    function __construct(SearchOperator $op) {
        assert($op->op === "then" || $op->op === "highlight");
        parent::__construct("then");
        $this->is_highlight = $op->op === "highlight";
        if (isset($op->opinfo))
            $this->set_float("opinfo", $op->opinfo);
    }
    protected function finish() {
        $opinfo = strtolower($this->get_float("opinfo", ""));
        $newvalues = $newhvalues = $newhmasks = $newhtypes = [];

        foreach ($this->value as $qvidx => $qv) {
            if ($qv && $qvidx && $this->is_highlight) {
                if ($qv->type === "then") {
                    for ($i = 0; $i < $qv->nthen; ++$i) {
                        $newhvalues[] = $qv->value[$i];
                        $newhmasks[] = (1 << count($newvalues)) - 1;
                        $newhtypes[] = $opinfo;
                    }
                } else {
                    $newhvalues[] = $qv;
                    $newhmasks[] = (1 << count($newvalues)) - 1;
                    $newhtypes[] = $opinfo;
                }
            } else if ($qv && $qv->type === "then") {
                $pos = count($newvalues);
                for ($i = 0; $i < $qv->nthen; ++$i)
                    $newvalues[] = $qv->value[$i];
                for ($i = $qv->nthen; $i < count($qv->value); ++$i)
                    $newhvalues[] = $qv->value[$i];
                foreach ($qv->highlights ? : array() as $hlmask)
                    $newhmasks[] = $hlmask << $pos;
                foreach ($qv->highlight_types ? : array() as $hltype)
                    $newhtypes[] = $hltype;
            } else if ($qv)
                $newvalues[] = $qv;
        }

        $this->nthen = count($newvalues);
        $this->highlights = $newhmasks;
        $this->highlight_types = $newhtypes;
        array_splice($newvalues, $this->nthen, 0, $newhvalues);
        $this->value = $newvalues;
        $this->set_float("sort", []);
        return $this;
    }
    function is_then() {
        return true;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $ff = array();
        foreach ($this->value as $subt)
            $ff[] = $subt->sqlexpr($sqi);
        return self::orjoin_sqlexpr(array_slice($ff, 0, $this->nthen), "true");
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        for ($i = 0; $i < $this->nthen; ++$i)
            if ($this->value[$i]->exec($row, $srch))
                return true;
        return false;
    }
}

class TextMatch_SearchTerm extends SearchTerm {
    private $field;
    private $authorish;
    private $nonempty = false;
    public $regex;
    static private $map = [
        "ti" => "title", "ab" => "abstract", "au" => "authorInformation",
        "co" => "collaborators"
    ];

    function __construct($t, $text) {
        parent::__construct($t, PaperSearch::F_XVIEW);
        $this->field = self::$map[$t];
        $this->authorish = $t === "au" || $t === "co";
        if ($text === true)
            $this->nonempty = true;
        else
            $this->regex = Text::star_text_pregexes($text);
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->needflags |= $this->flags;
        $sqi->add_column($this->field, "Paper.{$this->field}");
        if ($this->nonempty && !$this->authorish)
            return "Paper.{$this->field}!=''";
        // The purpose is to match AT LEAST those
        // papers that contain "$t->value" as a word in the $field field.
        // A substring match contains at least those papers; but only if
        // the current term is positive (= not negated).  If the current
        // term is negated, we say NO paper matches this clause.  (NOT no
        // paper is every paper.)  Later code will check for a substring.
        return $sqi->negated ? "false" : "true";
    }

    function exec(PaperInfo $row, PaperSearch $srch) {
        $field = $this->field;
        if ($row->$field === ""
            || ($this->authorish && !$srch->user->can_view_authors($row, true)))
            return false;
        if ($this->nonempty)
            return true;
        $field_deaccent = $field . "_deaccent";
        if (!isset($row->$field_deaccent)) {
            if (preg_match('/[\x80-\xFF]/', $row->$field))
                $row->$field_deaccent = UnicodeHelper::deaccent($row->$field);
            else
                $row->$field_deaccent = false;
        }
        return Text::match_pregexes($this->regex, $row->$field, $row->$field_deaccent);
    }
}

class PaperPC_SearchTerm extends SearchTerm {
    private $kind;
    private $fieldname;
    private $match;

    function __construct($kind, $match) {
        parent::__construct("paperpc", PaperSearch::F_XVIEW);
        $this->kind = $kind;
        $this->fieldname = $kind . "ContactId";
        $this->match = $match;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_column($this->fieldname, "Paper.{$this->fieldname}");
        if ($sqi->negated && !$sqi->fullPrivChair)
            return "false";
        $sql = $this->match;
        if (is_array($sql))
            $sql = " in (" . (empty($sql) ? "-1" : join(",", $sql)) . ")";
        return "(Paper.{$this->fieldname} $sql)";
    }

    function exec(PaperInfo $row, PaperSearch $srch) {
        $can_view = "can_view_{$this->kind}";
        if (!$srch->user->$can_view($row, true))
            return false;
        $v = $row->{$this->fieldname};
        if (is_array($this->match))
            return in_array($v, $this->match);
        else
            return CountMatcher::compare_string($v, $this->match);
    }
}

class Revpref_SearchTerm extends SearchTerm {
    private $rpsm;
    private $fieldname;

    function __construct(RevprefSearchMatcher $rpsm, $flags = 0) {
        parent::__construct("revpref", $flags);
        $this->rpsm = $rpsm;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $thistab = "Revpref_" . count($sqi->tables);
        $this->fieldname = $thistab . "_matches";

        if ($this->rpsm->preference_match
            && $this->rpsm->preference_match->test(0)
            && !$this->rpsm->expertise_match) {
            $q = "select Paper.paperId, count(ContactInfo.contactId) as count"
                . " from Paper join ContactInfo"
                . " left join PaperReviewPreference on (PaperReviewPreference.paperId=Paper.paperId and PaperReviewPreference.contactId=ContactInfo.contactId)"
                . " where coalesce(preference,0)" . $this->rpsm->preference_match->countexpr()
                . " and " . ($this->rpsm->has_contacts() ? $this->rpsm->contact_match_sql("ContactInfo.contactId") : "roles!=0 and (roles&" . Contact::ROLE_PC . ")!=0")
                . " group by Paper.paperId";
        } else {
            $where = array();
            if ($this->rpsm->has_contacts())
                $where[] = $this->rpsm->contact_match_sql("contactId");
            if (($match = $this->rpsm->preference_expertise_match()))
                $where[] = $match;
            $q = "select paperId, count(PaperReviewPreference.preference) as count"
                . " from PaperReviewPreference";
            if (count($where))
                $q .= " where " . join(" and ", $where);
            $q .= " group by paperId";
        }
        $sqi->add_table($thistab, array("left join", "($q)"));
        $sqi->add_column($this->fieldname, "$thistab.count");

        $q = array();
        $this->_set_flags($q, $sqi);
        $q[] = "coalesce($thistab.count,0)" . $this->rpsm->countexpr();
        return self::andjoin_sqlexpr($q);
    }

    function exec(PaperInfo $row, PaperSearch $srch) {
        return $this->_check_flags($row, $srch)
            && $this->rpsm->test((int) $row->{$this->fieldname});
    }
}

class Review_SearchTerm extends SearchTerm {
    private $rsm;
    private $fieldname;
    public $type;
    public $link;
    public $flags;
    public $value;
    public $float = [];

    function __construct(ReviewSearchMatcher $rsm, $flags = 0) {
        parent::__construct("re", $flags | PaperSearch::F_XVIEW);
        $this->rsm = $rsm;
    }
    function reviewer_contact_set() {
        return $this->rsm->contact_set();
    }
    function adjust(ReviewAdjustment_SearchTerm $adj) {
        if ($adj->round !== null && $this->rsm->round === null)
            $this->rsm->round = $adj->round;
        if ($adj->rate !== null && $this->rsm->rate === null)
            $this->rsm->rate = $adj->rate;
        $adj->used_revadj = true;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        if (($thistab = $this->rsm->simple_name()))
            $thistab = "Reviews_" . $thistab;
        else
            $thistab = "Reviews_" . count($sqi->tables);
        $this->fieldname = $thistab;

        if (!isset($sqi->tables[$thistab])) {
            $where = array();
            $reviewtable = "PaperReview r";
            if ($this->rsm->review_type)
                $where[] = "reviewType=" . $this->rsm->review_type;
            $cwhere = array();
            if ($this->rsm->completeness & ReviewSearchMatcher::COMPLETE)
                $cwhere[] = "reviewSubmitted>0";
            if ($this->rsm->completeness & ReviewSearchMatcher::INCOMPLETE)
                $cwhere[] = "reviewNeedsSubmit!=0";
            if ($this->rsm->completeness & ReviewSearchMatcher::INPROGRESS)
                $cwhere[] = "(reviewSubmitted is null and reviewModified>0)";
            if ($this->rsm->completeness & ReviewSearchMatcher::APPROVABLE) {
                if ($sqi->srch->privChair)
                    $cwhere[] = "(reviewSubmitted is null and timeApprovalRequested>0)";
                else
                    $cwhere[] = "(reviewSubmitted is null and timeApprovalRequested>0 and requestedBy={$sqi->user->cid})";
            }
            if (!empty($cwhere))
                $where[] = "(" . join(" or ", $cwhere) . ")";
            if ($this->rsm->round !== null) {
                if (empty($this->rsm->round))
                    $where[] = "false";
                else
                    $where[] = "reviewRound" . sql_in_numeric_set($this->rsm->round);
            }
            if ($this->rsm->rate !== null)
                $sqi->srch->_add_rating_sql($reviewtable, $where, $this->rsm->rate);
            if ($this->rsm->has_contacts()) {
                $cm = $this->rsm->contact_match_sql("r.contactId");
                if ($this->rsm->tokens)
                    $cm = "($cm or r.reviewToken in (" . join(",", $this->rsm->tokens) . "))";
                $where[] = $cm;
            }
            if ($this->rsm->fieldsql)
                $where[] = $this->rsm->fieldsql;
            $wheretext = "";
            if (!empty($where))
                $wheretext = " where " . join(" and ", $where);
            $sqi->add_table($thistab, array("left join", "(select r.paperId, count(r.reviewId) count, group_concat(r.reviewId, ' ', r.contactId, ' ', r.reviewType, ' ', coalesce(r.reviewSubmitted,0), ' ', r.reviewNeedsSubmit, ' ', r.requestedBy, ' ', r.reviewToken, ' ', r.reviewBlind) info from $reviewtable$wheretext group by paperId)"));
            $sqi->add_column($this->fieldname . "_info", $thistab . ".info");
        }

        $q = array();
        $this->_set_flags($q, $sqi);
        // Make the database query conservative (so change equality
        // constraints to >= constraints, and ignore <=/</!= constraints).
        // We'll do the precise query later.
        $q[] = "coalesce($thistab.count,0)" . $this->rsm->conservative_countexpr();
        return "(" . join(" and ", $q) . ")";
    }

    function exec(PaperInfo $row, PaperSearch $srch) {
        if (!$this->_check_flags($row, $srch))
            return false;
        $fieldname = $this->fieldname;
        if (!isset($row->$fieldname)) {
            $row->$fieldname = 0;
            $rrow = (object) array("paperId" => $row->paperId);
            $count_only = !$this->rsm->fieldsql;
            foreach (explode(",", $row->{$fieldname . "_info"}) as $info)
                if ($info !== "") {
                    list($rrow->reviewId, $rrow->contactId, $rrow->reviewType, $rrow->reviewSubmitted, $rrow->reviewNeedsSubmit, $rrow->requestedBy, $rrow->reviewToken, $rrow->reviewBlind) = explode(" ", $info);
                    if ($count_only
                        ? !$srch->user->can_view_review_assignment($row, $rrow, true)
                        : !$srch->user->can_view_review($row, $rrow, true))
                        continue;
                    if ($this->rsm->has_contacts()
                        ? !$srch->user->can_view_review_identity($row, $rrow, true)
                        : /* don't count delegated reviews unless contacts given */
                          $rrow->reviewSubmitted <= 0 && $rrow->reviewNeedsSubmit <= 0)
                        continue;
                    if (isset($this->rsm->view_score)
                        && $this->rsm->view_score <= $srch->user->view_score_bound($row, $rrow))
                        continue;
                    if ($this->rsm->wordcountexpr
                        && !$this->rsm->wordcountexpr->test($srch->word_count_for($row, $rrow->reviewId)))
                        continue;
                    ++$row->$fieldname;
                }
        }
        return $this->rsm->test((int) $row->$fieldname);
    }
}

class ReviewAdjustment_SearchTerm extends SearchTerm {
    public $conf;
    public $round;
    public $rate;
    public $negated = false;
    public $used_revadj = false;

    function __construct(Conf $conf) {
        parent::__construct("revadj", 0);
        $this->conf = $conf;
    }
    static function make_round(Conf $conf, $round) {
        $qe = new ReviewAdjustment_SearchTerm($conf);
        $qe->round = $round;
        return $qe;
    }
    static function make_rate(Conf $conf, $rate) {
        $qe = new ReviewAdjustment_SearchTerm($conf);
        $qe->rate = $rate;
        return $qe;
    }

    function merge(ReviewAdjustment_SearchTerm $x = null) {
        $changed = null;
        if ($x && $this->round === null && $x->round !== null)
            $changed = $this->round = $x->round;
        if ($x && $this->rate === null && $x->rate !== null)
            $changed = $this->rate = $x->rate;
        return $changed !== null;
    }
    function promote(PaperSearch $srch) {
        $rsm = new ReviewSearchMatcher(">0");
        if ($srch->limitName === "r" || $srch->limitName === "rout")
            $rsm->add_contact($srch->user->contactId);
        else if ($srch->limitName === "req" || $srch->limitName === "reqrevs")
            $rsm->fieldsql = "requestedBy=" . $srch->user->contactId . " and reviewType=" . REVIEW_EXTERNAL;
        if ($this->round !== null)
            $rsm->round = $this->round;
        if ($this->rate !== null)
            $rsm->rate = $this->rate;
        $rt = $srch->user->privChair ? 0 : PaperSearch::F_NONCONFLICT;
        if (!$srch->user->isPC)
            $rt |= PaperSearch::F_REVIEWER;
        $term = new Review_SearchTerm($rsm, $rt);
        return $this->negated ? SearchTerm::make_not($term) : $term;
    }
    function apply_negation() {
        if ($this->negated) {
            if ($this->round !== null)
                $this->round = array_diff(array_keys($this->conf->round_list()), $this->round);
            if ($this->rate !== null)
                $this->rate = "not ($this->rate)";
            $this->negated = false;
        }
    }
    function apply(ReviewAdjustment_SearchTerm $revadj = null, $is_or = false) {
        // XXX this is probably not right in fully general cases
        if (!$revadj)
            return $this;
        if ($revadj->negated !== $this->negated || ($revadj->negated && $is_or)) {
            $revadj->apply_negation();
            $this->apply_negation();
        }
        if ($is_or || $revadj->negated) {
            if ($this->round !== null)
                $revadj->round = array_unique(array_merge($revadj->round, $this->round));
            if ($this->rate !== null)
                $revadj->rate = "(" . ($revadj->rate ? : "false") . ") or (" . $this->rate . ")";
        } else {
            if ($revadj->round !== null && $this->round !== null)
                $revadj->round = array_intersect($revadj->round, $this->round);
            else if ($this->round !== null)
                $revadj->round = $this->round;
            if ($this->rate !== null)
                $revadj->rate = "(" . ($revadj->rate ? : "true") . ") and (" . $this->rate . ")";
        }
        return $revadj;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        return $sqi->negated ? "false" : "true";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return true;
    }
}

class Comment_SearchTerm extends SearchTerm {
    private $csm;
    private $ctm;
    private $commentRound;
    private $fieldname;

    public function __construct(ContactCountMatcher $csm = null,
                                CommentTagMatcher $ctm = null,
                                $round, $flags) {
        parent::__construct("cmt", $flags | PaperSearch::F_XVIEW);
        $this->csm = $csm ? : new ContactCountMatcher(">0", null);
        $this->ctm = $ctm;
        $this->commentRound = $round;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->csm->has_contacts() || $this->ctm)
            $thistab = "Comments_" . count($sqi->tables);
        else {
            $rtype = $this->flags & (PaperSearch::F_ALLOWCOMMENT | PaperSearch::F_ALLOWRESPONSE | PaperSearch::F_AUTHORCOMMENT | PaperSearch::F_ALLOWDRAFT | PaperSearch::F_REQUIREDRAFT);
            $thistab = "Numcomments_" . $rtype;
            if (isset($this->commentRound))
                $thistab .= "_" . $t->commentRound;
        }

        $this->fieldname = $thistab;
        if (!isset($sqi->tables[$thistab])) {
            $where = array();
            if (!($this->flags & PaperSearch::F_ALLOWRESPONSE))
                $where[] = "(commentType&" . COMMENTTYPE_RESPONSE . ")=0";
            if (!($this->flags & PaperSearch::F_ALLOWCOMMENT))
                $where[] = "(commentType&" . COMMENTTYPE_RESPONSE . ")!=0";
            if (!($this->flags & PaperSearch::F_ALLOWDRAFT))
                $where[] = "(commentType&" . COMMENTTYPE_DRAFT . ")=0";
            else if ($this->flags & PaperSearch::F_REQUIREDRAFT)
                $where[] = "(commentType&" . COMMENTTYPE_DRAFT . ")!=0";
            if ($this->flags & PaperSearch::F_AUTHORCOMMENT)
                $where[] = "commentType>=" . COMMENTTYPE_AUTHOR;
            if (isset($this->commentRound))
                $where[] = "commentRound=" . $this->commentRound;
            if ($this->csm->has_contacts())
                $where[] = $this->csm->contact_match_sql("contactId");
            if ($this->ctm) {
                if ($this->ctm->tag === "any")
                    $where[] = "commentTags is not null";
                else
                    $where[] = "commentTags like " . Dbl::utf8ci("'% " . sqlq_for_like($this->ctm->tag) . " %'");
            }
            $wheretext = "";
            if (!empty($where))
                $wheretext = " where " . join(" and ", $where);
            $sqi->add_table($thistab, array("left join", "(select paperId, count(commentId) count, group_concat(contactId, ' ', commentType) info from PaperComment$wheretext group by paperId)"));
            $sqi->add_column($thistab . "_info", $thistab . ".info");
        }

        $q = array();
        $this->_set_flags($q, $sqi);
        $q[] = "coalesce($thistab.count,0)" . $this->csm->conservative_countexpr();
        return self::andjoin_sqlexpr($q);
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        if (!$this->_check_flags($row, $srch))
            return false;
        $fieldname = $this->fieldname;
        if (!isset($row->{$fieldname})) {
            $row->$fieldname = 0;
            $crow = (object) array("paperId" => $row->paperId);
            foreach (explode(",", $row->{$fieldname . "_info"}) as $info)
                if ($info !== "") {
                    list($crow->contactId, $crow->commentType) = explode(" ", $info);
                    if ($srch->user->can_view_comment($row, $crow, true))
                        ++$row->$fieldname;
                }
        }
        return $this->csm->test((int) $row->$fieldname);
    }
}

class Topic_SearchTerm extends SearchTerm {
    private $topics;
    private $fieldname;

    public function __construct($topics) {
        parent::__construct("topic", PaperSearch::F_XVIEW);
        $this->topics = $topics;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $thistab = "Topic_" . count($sqi->tables);
        $joiner = "";
        if (!is_array($this->topics))
            $thistab = "AnyTopic";
        else if (empty($this->topics))
            $joiner = "false";
        else
            $joiner = "topicId in (" . join(",", $this->topics) . ")";
        $sqi->add_table($thistab, ["left join", "PaperTopic", $joiner]);
        $this->fieldname = $thistab . "_id";
        $sqi->add_column($this->fieldname, "min($thistab.topicId)");

        $q = array();
        $this->_set_flags($q, $sqi);
        if ($this->topics === false)
            $q[] = "$thistab.topicId is null";
        else
            $q[] = "$thistab.topicId is not null";
        return self::andjoin_sqlexpr($q);
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return $this->_check_flags($row, $srch)
            && ($this->topics !== false
                ? $row->{$this->fieldname} != 0
                : !$row->{$this->fieldname});
    }
}


class TagSearchMatcher {
    public $tags = [];
    public $index1 = null;
    public $index2 = null;
    private $_re;

    function make_term() {
        if (empty($this->tags))
            return new SearchTerm("f");
        else
            return new SearchTerm("tag", PaperSearch::F_XVIEW, $this);
    }
    function tagmatch_sql($user) {
        $args = [];
        foreach ($this->tags as $value) {
            if (($starpos = strpos($value, "*")) !== false) {
                $arg = "(\3 like '" . str_replace("*", "%", sqlq_for_like($value)) . "'";
                if ($starpos == 0)
                    $arg .= " and \3 not like '%~%'";
                $arg .= ")";
            } else if ($value === "any" || $value === "none")
                $arg = "(\3 is not null and (\3 not like '%~%' or \3 like '{$user->contactId}~%'" . ($user->privChair ? " or \3 like '~~%'" : "") . "))";
            else
                $arg = "\3='" . sqlq($value) . "'";
            $args[] = $arg;
        }
        return join(" or ", $args);
    }
    function index_wheresql() {
        $extra = "";
        if ($this->index1)
            $extra .= " and %.tagIndex" . $this->index1->countexpr();
        if ($this->index2)
            $extra .= " and %.tagIndex" . $this->index2->countexpr();
        return $extra;
    }
    function evaluate($user, $tags) {
        if (!$this->_re) {
            $res = [];
            foreach ($this->tags as $value)
                if (($starpos = strpos($value, "*")) !== false)
                    $res[] = '(?!.*~)' . str_replace('\\*', '.*', preg_quote($value));
                else if ($value === "any" && $user->privChair)
                    $res[] = "(?:{$user->contactId}~.*|~~.*|(?!.*~).*)";
                else if ($value === "any")
                    $res[] = "(?:{$user->contactId}~.*|(?!.*~).*)";
                else
                    $res[] = preg_quote($value);
            $this->_re = '/\A(?:' . join("|", $res) . ')\z/i';
        }
        foreach (TagInfo::split($tags) as $ti) {
            list($tag, $index) = TagInfo::split_index($ti);
            if (preg_match($this->_re, $tag)
                && (!$this->index1 || $this->index1->test($index))
                && (!$this->index2 || $this->index2->test($index)))
                return true;
        }
        return false;
    }
}

class CommentTagMatcher extends CountMatcher {
    public $tag;

    function __construct($countexpr, $tag) {
        parent::__construct($countexpr);
        $this->tag = $tag;
    }
}

class ContactCountMatcher extends CountMatcher {
    private $_contacts = null;

    function __construct($countexpr, $contacts) {
        parent::__construct($countexpr);
        $this->set_contacts($contacts);
    }
    function has_contacts() {
        return $this->_contacts !== null;
    }
    function contact_set() {
        return $this->_contacts;
    }
    function contact_match_sql($fieldname) {
        if ($this->_contacts === null)
            return "true";
        else
            return $fieldname . sql_in_numeric_set($this->_contacts);
    }
    function test_contact($cid) {
        return $this->_contacts === null || in_array($cid, $this->_contacts);
    }
    function add_contact($cid) {
        if ($this->_contacts === null)
            $this->_contacts = array();
        if (!in_array($cid, $this->_contacts))
            $this->_contacts[] = $cid;
    }
    function set_contacts($contacts) {
        assert($contacts === null || is_array($contacts) || is_int($contacts));
        $this->_contacts = is_int($contacts) ? array($contacts) : $contacts;
    }
}

class ReviewSearchMatcher extends ContactCountMatcher {
    const COMPLETE = 1;
    const INCOMPLETE = 2;
    const INPROGRESS = 4;
    const APPROVABLE = 8;

    public $review_type = 0;
    public $completeness = 0;
    public $fieldsql = null;
    public $view_score = null;
    public $round = null;
    public $rate = null;
    public $tokens = null;
    public $wordcountexpr = null;

    function __construct($countexpr = null, $contacts = null) {
        parent::__construct($countexpr, $contacts);
    }
    function apply_review_type($word, $allow_pc = false) {
        if ($word === "pri" || $word === "primary")
            $this->review_type = REVIEW_PRIMARY;
        else if ($word === "sec" || $word === "secondary")
            $this->review_type = REVIEW_SECONDARY;
        else if ($word === "optional")
            $this->review_type = REVIEW_PC;
        else if ($allow_pc && ($word === "pc" || $word === "pcre" || $word === "pcrev"))
            $this->review_type = REVIEW_PC;
        else if ($word === "ext" || $word === "external")
            $this->review_type = REVIEW_EXTERNAL;
        else
            return false;
        return true;
    }
    function apply_completeness($word) {
        if ($word === "complete" || $word === "done")
            $this->completeness |= self::COMPLETE;
        else if ($word === "incomplete")
            $this->completeness |= self::INCOMPLETE;
        else if ($word === "approvable")
            $this->completeness |= self::APPROVABLE;
        else if ($word === "draft" || $word === "inprogress" || $word === "in-progress")
            $this->completeness |= self::INPROGRESS;
        else
            return false;
        return true;
    }
    function apply_round($word, Conf $conf) {
        $round = $conf->round_number($word, false);
        if ($round || $word === "unnamed") {
            $this->round[] = $round;
            return true;
        } else
            return false;
    }
    function simple_name() {
        if (!$this->has_contacts() && $this->fieldsql === null
            && $this->round === null && $this->rate === null
            && $this->wordcountexpr === null) {
            $name = $this->review_type ? ReviewForm::$revtype_names[$this->review_type] : "All";
            if ($this->completeness & self::COMPLETE)
                $name .= "Complete";
            if ($this->completeness & self::INCOMPLETE)
                $name .= "Incomplete";
            if ($this->completeness & self::INPROGRESS)
                $name .= "Inprogress";
            if ($this->completeness & self::APPROVABLE)
                $name .= "Approvable";
            return $name;
        } else
            return false;
    }
}

class RevprefSearchMatcher extends ContactCountMatcher {
    public $preference_match = null;
    public $expertise_match = null;
    public $is_any = false;

    function __construct($countexpr, $contacts) {
        parent::__construct($countexpr, $contacts);
    }
    function preference_expertise_match() {
        if ($this->is_any)
            return "(preference!=0 or expertise is not null)";
        $where = [];
        if ($this->preference_match)
            $where[] = "preference" . $this->preference_match->countexpr();
        if ($this->expertise_match)
            $where[] = "expertise" . $this->expertise_match->countexpr();
        return join(" and ", $where);
    }
}

class SearchQueryInfo {
    public $conf;
    public $srch;
    public $user;
    public $fullPrivChair;
    public $tables = array();
    public $columns = array();
    public $negated = false;
    public $needflags = 0;

    public function __construct(PaperSearch $srch) {
        $this->conf = $srch->conf;
        $this->srch = $srch;
        $this->user = $srch->user;
        $this->fullPrivChair = $this->user->privChair
            && !$this->conf->has_any_manager();
    }
    public function add_table($table, $joiner = false) {
        assert($joiner || !count($this->tables));
        $this->tables[$table] = $joiner;
    }
    public function add_column($name, $expr) {
        assert(!isset($this->columns[$name]) || $this->columns[$name] === $expr);
        $this->columns[$name] = $expr;
    }
    public function add_conflict_columns() {
        if (!isset($this->tables["PaperConflict"]))
            $this->add_table("PaperConflict", array("left join", "PaperConflict", "PaperConflict.contactId={$this->user->contactId}"));
        $this->columns["conflictType"] = "PaperConflict.conflictType";
    }
    public function add_reviewer_columns() {
        if ($this->user->conf->submission_blindness() == Conf::BLIND_OPTIONAL)
            $this->columns["paperBlind"] = "Paper.blind";
        if (!isset($this->tables["MyReview"])) {
            if (($tokens = $this->user->review_tokens()))
                $this->add_table("MyReview", ["left join", "(select paperId, max(reviewType) reviewType, max(reviewNeedsSubmit) reviewNeedsSubmit, max(reviewSubmitted) reviewSubmitted from PaperReview where contactId={$this->user->contactId} or reviewToken in (" . join(",", $tokens) . ") group by paperId)"]);
            else
                $this->add_table("MyReview", ["left join", "PaperReview", "(MyReview.contactId={$this->user->contactId})"]);
            $this->add_column("myReviewType", "MyReview.reviewType");
            $this->add_column("myReviewNeedsSubmit", "MyReview.reviewNeedsSubmit");
            $this->add_column("myReviewSubmitted", "MyReview.reviewSubmitted");
        }
    }
    public function add_rights_columns() {
        if (!isset($this->columns["managerContactId"]))
            $this->columns["managerContactId"] = "Paper.managerContactId";
        if (!isset($this->columns["leadContactId"]))
            $this->columns["leadContactId"] = "Paper.leadContactId";
        if (!$this->user->privChair) {
            $this->add_conflict_columns();
            $this->add_reviewer_columns();
        }
    }
}

class OptionMatcher {
    public $option;
    public $compar;
    public $value;
    public $kind;
    public $value_word;

    public function __construct($option, $compar, $value, $value_word, $kind = 0) {
        assert(!is_array($value) || $compar === "=" || $compar === "!=");
        $this->option = $option;
        $this->compar = $compar;
        $this->value = $value;
        $this->value_word = $value_word;
        $this->kind = $kind;
    }
    public function table_matcher() {
        if ($this->kind)
            return ">0";
        else if (is_array($this->value))
            return ($this->compar === "=" ? " in (" : " not in (")
                . join(",", $this->value) . ")";
        else
            return $this->compar . $this->value;
    }
}

class PaperSearch {
    const F_MANAGER = 0x0001;
    const F_NONCONFLICT = 0x0002;
    const F_AUTHOR = 0x0004;
    const F_REVIEWER = 0x0008;

    const F_AUTHORCOMMENT = 0x00200;
    const F_ALLOWRESPONSE = 0x00400;
    const F_ALLOWCOMMENT = 0x00800;
    const F_ALLOWDRAFT = 0x01000;
    const F_REQUIREDRAFT = 0x02000;

    const F_FALSE = 0x10000;
    const F_XVIEW = 0x20000;

    public $conf;
    public $user;
    public $contact;
    public $cid;
    private $contactId;         // for backward compatibility
    public $privChair;
    private $amPC;

    var $limitName;
    var $qt;
    var $allowAuthor;
    private $fields;
    private $_reviewer = false;
    private $_reviewer_fixed = false;
    var $matchPreg;
    private $urlbase;
    public $warnings = array();

    var $q;

    var $regex = array();
    public $overrideMatchPreg = false;
    private $contact_match = array();
    private $noratings = false;
    private $interestingRatings = array();
    public $_query_options = array();
    private $reviewAdjust = false;
    private $_reviewAdjustError = false;
    private $_ssRecursion = array();
    private $_allow_deleted = false;
    private $_reviewWordCounts = false;
    public $thenmap = null;
    public $groupmap = null;
    public $is_order_anno = false;
    public $highlightmap = null;
    public $viewmap;
    public $sorters;
    private $_highlight_tags = null;

    private $_matches = null; // list of ints

    static private $_sort_keywords = null;
    static private $current_search;

    static private $_keywords = array("ti" => "ti", "title" => "ti",
        "ab" => "ab", "abstract" => "ab",
        "au" => "au", "author" => "au",
        "co" => "co", "collab" => "co", "collaborators" => "co",
        "r" => "re", "re" => "re", "rev" => "re", "review" => "re",
        "cre" => "cre", "crev" => "cre", "creview" => "cre", "complete-review" => "cre",
        "ire" => "ire", "irev" => "ire", "ireview" => "ire", "incomplete-review" => "ire",
        "pre" => "pre", "prev" => "pre", "preview" => "pre", "partial-review" => "pre", "in-progress-review" => "pre",
        "sre" => "cre", "srev" => "cre", "sreview" => "cre", // deprecated
        "subre" => "cre", "subrev" => "cre", "subreview" => "cre", // deprecated
        "pri" => "pri", "primary" => "pri",
        "prire" => "pri", "prirev" => "pri", "prireview" => "pri", "primary-review" => "pri",
        "cpri" => "cpri", "cprimary" => "cpri",
        "cprire" => "cpri", "cprirev" => "cpri", "cprireview" => "cpri",
        "ipri" => "ipri", "iprimary" => "ipri",
        "iprire" => "ipri", "iprirev" => "ipri", "iprireview" => "ipri",
        "ppri" => "ppri", "pprimary" => "ppri",
        "pprire" => "ppri", "pprirev" => "ppri", "pprireview" => "ppri",
        "sec" => "sec", "secondary" => "sec",
        "secre" => "sec", "secrev" => "sec", "secreview" => "sec", "secondary-review" => "sec",
        "csec" => "csec", "csecondary" => "csec",
        "csecre" => "csec", "csecrev" => "csec", "csecreview" => "csec",
        "isec" => "isec", "isecondary" => "isec",
        "isecre" => "isec", "isecrev" => "isec", "isecreview" => "isec",
        "psec" => "psec", "psecondary" => "psec",
        "psecre" => "psec", "psecrev" => "psec", "psecreview" => "psec",
        "ext" => "ext", "external" => "ext",
        "extre" => "ext", "extrev" => "ext", "extreview" => "ext", "external-review" => "ext",
        "cext" => "cext", "cexternal" => "cext",
        "cextre" => "cext", "cextrev" => "cext", "cextreview" => "cext",
        "iext" => "iext", "iexternal" => "iext",
        "iextre" => "iext", "iextrev" => "iext", "iextreview" => "iext",
        "pext" => "pext", "pexternal" => "pext",
        "pextre" => "pext", "pextrev" => "pext", "pextreview" => "pext",
        "cmt" => "cmt", "comment" => "cmt",
        "aucmt" => "aucmt", "aucomment" => "aucmt",
        "resp" => "response", "response" => "response",
        "draftresp" => "draftresponse", "draftresponse" => "draftresponse",
        "draft-resp" => "draftresponse", "draft-response" => "draftresponse",
        "respdraft" => "draftresponse", "responsedraft" => "draftresponse",
        "resp-draft" => "draftresponse", "response-draft" => "draftresponse",
        "anycmt" => "anycmt", "anycomment" => "anycmt",
        "tag" => "tag",
        "notag" => "notag",
        "color" => "color", "style" => "color",
        "ord" => "order", "order" => "order",
        "rord" => "rorder", "rorder" => "rorder",
        "revord" => "rorder", "revorder" => "rorder",
        "decision" => "decision", "dec" => "decision",
        "topic" => "topic",
        "option" => "option", "opt" => "option",
        "manager" => "manager", "admin" => "manager", "administrator" => "manager",
        "lead" => "lead",
        "shepherd" => "shepherd", "shep" => "shepherd",
        "conflict" => "conflict", "conf" => "conflict",
        "reconflict" => "reconflict", "reconf" => "reconflict",
        "pcconflict" => "pcconflict", "pcconf" => "pcconflict",
        "status" => "status", "has" => "has", "is" => "is",
        "rating" => "rate", "rate" => "rate",
        "revpref" => "pref", "pref" => "pref", "repref" => "pref",
        "revprefexp" => "prefexp", "prefexp" => "prefexp", "prefexpertise" => "prefexp",
        "ss" => "ss", "search" => "ss",
        "formula" => "formula", "f" => "formula",
        "graph" => "graph", "g" => "graph",
        "HEADING" => "HEADING", "heading" => "HEADING",
        "show" => "show", "VIEW" => "show", "view" => "show",
        "hide" => "hide", "edit" => "edit",
        "sort" => "sort", "showsort" => "showsort",
        "sortshow" => "showsort", "editsort" => "editsort",
        "sortedit" => "editsort");
    static private $_noheading_keywords = array(
        "HEADING" => "HEADING", "heading" => "HEADING",
        "show" => "show", "VIEW" => "show", "view" => "show",
        "hide" => "hide", "edit" => "edit",
        "sort" => "sort", "showsort" => "showsort",
        "sortshow" => "showsort", "editsort" => "editsort",
        "sortedit" => "editsort");
    static private $_canonical_review_keywords = array(
        "re" => 1, "cre" => 1, "ire" => 1, "pre" => 1,
        "pri" => 1, "cpri" => 1, "ipri" => 1, "ppri" => 1,
        "sec" => 1, "csec" => 1, "isec" => 1, "psec" => 1,
        "ext" => 1, "cext" => 1, "iext" => 1, "pext" => 1);


    function __construct(Contact $user, $options, Contact $reviewer = null) {
        if (is_string($options))
            $options = array("q" => $options);

        // contact facts
        $this->conf = $user->conf;
        $this->user = $user;
        $this->contact = $user;
        $this->privChair = $user->privChair;
        $this->amPC = $user->isPC;
        $this->cid = $user->contactId;

        // paper selection
        $ptype = get_s($options, "t");
        if ($ptype === 0)
            $ptype = "";
        if ($this->privChair && !$ptype && $this->conf->timeUpdatePaper())
            $this->limitName = "all";
        else if (($user->privChair && $ptype === "act")
                 || ($user->isPC
                     && (!$ptype || $ptype === "act" || $ptype === "all")
                     && $this->conf->can_pc_see_all_submissions()))
            $this->limitName = "act";
        else if ($user->privChair && $ptype === "unm")
            $this->limitName = "unm";
        else if ($user->isPC && (!$ptype || $ptype === "s" || $ptype === "unm"))
            $this->limitName = "s";
        else if ($user->isPC && ($ptype === "und" || $ptype === "undec"))
            $this->limitName = "und";
        else if ($user->isPC && ($ptype === "acc" || $ptype === "revs"
                                 || $ptype === "reqrevs" || $ptype === "req"
                                 || $ptype === "lead" || $ptype === "rable"
                                 || $ptype === "manager" || $ptype === "editpref"))
            $this->limitName = $ptype;
        else if ($this->privChair && ($ptype === "all" || $ptype === "unsub"))
            $this->limitName = $ptype;
        else if ($ptype === "r" || $ptype === "rout" || $ptype === "a")
            $this->limitName = $ptype;
        else if ($ptype === "rable")
            $this->limitName = "r";
        else if (!$user->is_reviewer())
            $this->limitName = "a";
        else if (!$user->is_author())
            $this->limitName = "r";
        else
            $this->limitName = "ar";

        // track other information
        $this->allowAuthor = false;
        if ($user->privChair || $user->is_author()
            || ($this->amPC && $this->conf->submission_blindness() != Conf::BLIND_ALWAYS))
            $this->allowAuthor = true;

        // default query fields
        // NB: If a complex query field, e.g., "re", "tag", or "option", is
        // default, then it must be the only default or query construction
        // will break.
        $this->fields = array();
        $qtype = defval($options, "qt", "n");
        if ($qtype === "n" || $qtype === "ti")
            $this->fields["ti"] = 1;
        if ($qtype === "n" || $qtype === "ab")
            $this->fields["ab"] = 1;
        if ($this->allowAuthor && ($qtype === "n" || $qtype === "au" || $qtype === "ac"))
            $this->fields["au"] = 1;
        if ($this->privChair && $qtype === "ac")
            $this->fields["co"] = 1;
        if ($this->amPC && $qtype === "re")
            $this->fields["re"] = 1;
        if ($this->amPC && $qtype === "tag")
            $this->fields["tag"] = 1;
        $this->qt = ($qtype === "n" ? "" : $qtype);

        // the query itself
        $this->q = trim(get_s($options, "q"));

        // URL base
        if (isset($options["urlbase"]))
            $this->urlbase = $options["urlbase"];
        else {
            $this->urlbase = hoturl_site_relative_raw("search", "t=" . urlencode($this->limitName));
            if ($qtype !== "n")
                $this->urlbase .= "&qt=" . urlencode($qtype);
        }
        if (strpos($this->urlbase, "&amp;") !== false)
            trigger_error(caller_landmark() . " PaperSearch::urlbase should be a raw URL", E_USER_NOTICE);

        if ($reviewer) {
            $this->_reviewer = $reviewer;
            $this->_reviewer_fixed = true;
        } else if (get($options, "reviewer"))
            error_log("PaperSearch::\$reviewer set: " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));

        $this->_allow_deleted = defval($options, "allow_deleted", false);
    }

    function warn($text) {
        $this->warnings[] = $text;
    }


    // PARSING
    // Transforms a search string into an expression object, possibly
    // including "and", "or", and "not" expressions (which point at other
    // expressions).

    private function _text_match_field(&$qt, $type, $word) {
        $qt[] = new TextMatch_SearchTerm($type, $word);
    }

    private function _searchField($word, $rtype, &$qt) {
        if ($this->privChair || $this->amPC)
            $qt[] = new SearchTerm($rtype, self::F_XVIEW, $word);
        else {
            $qt[] = new SearchTerm($rtype, self::F_XVIEW | self::F_REVIEWER, $word);
            $qt[] = new SearchTerm($rtype, self::F_XVIEW | self::F_AUTHOR, $word);
        }
    }

    private function _searchAuthors($word, &$qt, $keyword, $quoted) {
        $lword = strtolower($word);
        if ($keyword && !$quoted && $lword === "me")
            $this->_searchField(array($this->cid), "au_cid", $qt);
        else if ($keyword && !$quoted && $this->amPC
                 && ($lword === "pc" || $this->conf->pc_tag_exists($lword))) {
            $cids = $this->_pcContactIdsWithTag($lword);
            $this->_searchField($cids, "au_cid", $qt);
        } else
            $this->_text_match_field($qt, "au", $word);
    }

    static function _matchCompar($text, $quoted) {
        $text = trim($text);
        $compar = null;
        if (preg_match('/\A(.*?)([=!<>]=?|≠|≤|≥)\s*(\d+)\z/s', $text, $m)) {
            $text = $m[1];
            $compar = $m[2] . $m[3];
        }
        if (($text === "any" || $text === "" || $text === "yes") && !$quoted)
            return array("", $compar ? : ">0");
        else if (($text === "none" || $text === "no") && !$quoted)
            return array("", "=0");
        else if (!$compar && ctype_digit($text))
            return array("", "=" . $text);
        else
            return array($text, $compar ? : ">0");
    }

    static private function _tautology($compar) {
        if ($compar === "<0")
            return new SearchTerm("f");
        else if ($compar === ">=0")
            return new SearchTerm("t");
        else
            return null;
    }

    private function _pcContactIdsWithTag($tag) {
        if ($tag === "pc")
            return array_keys($this->conf->pc_members());
        $a = array();
        foreach ($this->conf->pc_members() as $cid => $pc)
            if ($pc->has_tag($tag))
                $a[] = $cid;
        return $a;
    }

    private function make_contact_match($type, $text, Contact $user) {
        foreach ($this->contact_match as $i => $cm)
            if ($cm->type === $type && $cm->text === $text && $cm->user === $user)
                return $cm;
        return $this->contact_match[] = new ContactSearch($type, $text, $user);
    }

    private function _reviewerMatcher($word, $quoted, $pc_only) {
        $type = 0;
        if ($pc_only)
            $type |= ContactSearch::F_PC;
        if ($quoted)
            $type |= ContactSearch::F_QUOTED;
        if (!$quoted && $this->amPC)
            $type |= ContactSearch::F_TAG;
        $scm = $this->make_contact_match($type, $word, $this->reviewer_user());
        if ($scm->warn_html)
            $this->warn($scm->warn_html);
        if (count($scm->ids))
            return $scm->ids;
        else
            return array(-1);
    }

    private function _one_pc_matcher($text, $quoted) {
        if (($text === "any" || $text === "" || $text === "yes") && !$quoted)
            return "!=0";
        else if (($text === "none" || $text === "no") && !$quoted)
            return "=0";
        else
            return $this->_reviewerMatcher($text, $quoted, true);
    }

    private function _search_reviewer($qword, $keyword, &$qt) {
        if (preg_match('/\A(.*)(pri|sec|ext)\z/', $keyword, $m)) {
            $qword = $m[2] . ":" . $qword;
            $keyword = $m[1];
        }

        if (str_starts_with($keyword, "c"))
            $qword = "complete:" . $qword;
        else if (str_starts_with($keyword, "i"))
            $qword = "incomplete:" . $qword;
        else if (str_starts_with($keyword, "p"))
            $qword = "inprogress:" . $qword;

        $quoted = false;
        $contacts = null;
        $wordcount = null;
        $rsm = new ReviewSearchMatcher(">0");

        $tailre = '(?:\z|:|(?=[=!<>]=?|≠|≤|≥))(.*)\z/s';

        while ($qword !== "") {
            if (preg_match('/\A(.+?)' . $tailre, $qword, $m)
                && ($rsm->apply_review_type($m[1])
                    || $rsm->apply_completeness($m[1])
                    || $rsm->apply_round($m[1], $this->conf))) {
                $qword = $m[2];
            } else if (preg_match('/\A((?:[=!<>]=?|≠|≤|≥|)\d+|any|none|yes|no)' . $tailre, $qword, $m)) {
                $count = self::_matchCompar($m[1], false);
                $rsm->set_countexpr($count[1]);
                $qword = $m[2];
            } else if (preg_match('/\A(?:au)?words((?:[=!<>]=?|≠|≤|≥)\d+)(?:\z|:)(.*)\z/', $qword, $m)) {
                $wordcount = new CountMatcher($m[1]);
                $qword = $m[2];
            } else if (preg_match('/\A(..*?|"[^"]+(?:"|\z))' . $tailre, $qword, $m)) {
                if (($quoted = $m[1][0] === "\""))
                    $m[1] = str_replace(array('"', '*'), array('', '\*'), $m[1]);
                $contacts = $m[1];
                $qword = $m[2];
            } else {
                $rsm->set_countexpr("<0");
                break;
            }
        }

        if (($qr = self::_tautology($rsm->countexpr()))) {
            $qr->set_float("used_revadj", true);
            $qt[] = $qr;
            return;
        }

        $rsm->wordcountexpr = $wordcount;
        if ($wordcount && $rsm->completeness === 0)
            $rsm->apply_completeness("complete");
        if ($contacts) {
            $rsm->set_contacts($this->_reviewerMatcher($contacts, $quoted,
                                            $rsm->review_type >= REVIEW_PC));
            if (strcasecmp($contacts, "me") == 0)
                $rsm->tokens = $this->reviewer_user()->review_tokens();
        }
        $qt[] = new Review_SearchTerm($rsm);
    }

    static public function matching_decisions(Conf $conf, $word, $quoted = null) {
        if ($quoted === null && ($quoted = ($word && $word[0] === '"')))
            $word = str_replace('"', '', $word);
        $lword = strtolower($word);
        if (!$quoted) {
            if ($lword === "yes")
                return ">0";
            else if ($lword === "no")
                return "<0";
            else if ($lword === "?" || $lword === "none" || $lword === "unknown")
                return array(0);
            else if ($lword === "any")
                return "!=0";
        }
        $flags = $quoted ? Text::SEARCH_ONLY_EXACT : Text::SEARCH_UNPRIVILEGE_EXACT;
        return array_keys(Text::simple_search($word, $conf->decision_map(), $flags));
    }

    static public function status_field_matcher(Conf $conf, $word, $quoted = null) {
        if (strcasecmp($word, "withdrawn") == 0 || strcasecmp($word, "withdraw") == 0 || strcasecmp($word, "with") == 0)
            return ["timeWithdrawn", ">0"];
        else if (strcasecmp($word, "submitted") == 0 || strcasecmp($word, "submit") == 0 || strcasecmp($word, "sub") == 0)
            return ["timeSubmitted", ">0"];
        else if (strcasecmp($word, "unsubmitted") == 0 || strcasecmp($word, "unsubmit") == 0 || strcasecmp($word, "unsub") == 0)
            return ["timeSubmitted", "<=0", "timeWithdrawn", "<=0"];
        else if (strcasecmp($word, "active") == 0)
            return ["timeWithdrawn", "<=0"];
        else
            return ["outcome", self::matching_decisions($conf, $word, $quoted)];
    }

    private function _search_status($word, &$qt, $quoted, $allow_status) {
        if ($allow_status)
            $fval = self::status_field_matcher($this->conf, $word, $quoted);
        else
            $fval = ["outcome", self::matching_decisions($this->conf, $word, $quoted)];
        if (is_array($fval[1]) && count($fval[1]) == 0) {
            $this->warn("“" . htmlspecialchars($word) . "” doesn’t match a " . ($allow_status ? "decision or status." : "decision."));
            $fval[1][] = -10000000;
        }

        $flags = 0;
        if ($fval[0] === "outcome" && !($this->amPC && $this->conf->timePCViewDecision(true)))
            $flags = self::F_XVIEW;
        $qt[] = new SearchTerm("pf", $flags, $fval);
    }

    private function _search_conflict($word, &$qt, $quoted, $pc_only) {
        $m = self::_matchCompar($word, $quoted);
        if (($qr = self::_tautology($m[1]))) {
            $qt[] = $qr;
            return;
        }

        $contacts = $this->_reviewerMatcher($m[0], $quoted, $pc_only);
        $value = new ContactCountMatcher($m[1], $contacts);
        if ($this->privChair
            || (is_array($contacts) && count($contacts) == 1 && $contacts[0] == $this->cid))
            $qt[] = new SearchTerm("conflict", 0, $value);
        else {
            $qt[] = new SearchTerm("conflict", self::F_XVIEW, $value);
            if ($value->test_contact($this->cid))
                $qt[] = new SearchTerm("conflict", 0, new ContactCountMatcher($m[1], $this->cid));
        }
    }

    private function _searchReviewerConflict($word, &$qt, $quoted) {
        $args = array();
        while (preg_match('/\A\s*#?(\d+)(?:-#?(\d+))?\s*,?\s*(.*)\z/s', $word, $m)) {
            $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
            foreach (range($m[1], $m[2]) as $p)
                $args[$p] = true;
            $word = $m[3];
        }
        if ($word !== "" || count($args) == 0) {
            $this->warn("The <code>reconflict</code> keyword expects a list of paper numbers.");
            $qt[] = new SearchTerm("f");
        } else {
            $result = $this->conf->qe("select distinct contactId from PaperReview where paperId in (" . join(", ", array_keys($args)) . ")");
            $contacts = Dbl::fetch_first_columns($result);
            $qt[] = new SearchTerm("conflict", 0, new ContactCountMatcher(">0", $contacts));
        }
    }

    private function _search_comment_tag($rt, $tag, $rvalue, $round, &$qt) {
        $xtag = $tag;
        if ($xtag === "none")
            $xtag = "any";
        $term = new Comment_SearchTerm(null, new CommentTagMatcher($rvalue, $xtag), $round, $rt);
        if ($tag === "none")
            $term = SearchTerm::make_not($term);
        $qt[] = $term;
    }

    private function _search_comment($word, $ctype, &$qt, $quoted) {
        $m = self::_matchCompar($word, $quoted);
        if (($qr = self::_tautology($m[1]))) {
            $qt[] = $qr;
            return;
        }

        // canonicalize comment type
        $ctype = strtolower($ctype);
        if (str_ends_with($ctype, "resp"))
            $ctype .= "onse";
        if (str_ends_with($ctype, "-draft"))
            $ctype = "draft" . substr($ctype, 0, strlen($ctype) - 6);
        else if (str_ends_with($ctype, "draft"))
            $ctype = "draft" . substr($ctype, 0, strlen($ctype) - 5);
        if (str_starts_with($ctype, "draft-"))
            $ctype = "draft" . substr($ctype, 6);

        $rt = 0;
        $round = null;
        if (str_starts_with($ctype, "draft") && str_ends_with($ctype, "response")) {
            $rt |= self::F_REQUIREDRAFT | self::F_ALLOWDRAFT;
            $ctype = substr($ctype, 5);
        }
        if ($ctype === "response" || $ctype === "anycmt")
            $rt |= self::F_ALLOWRESPONSE;
        else if (str_ends_with($ctype, "response")) {
            $rname = substr($ctype, 0, strlen($ctype) - 8);
            $round = $this->conf->resp_round_number($rname);
            if ($round === false) {
                $this->warn("No such response round “" . htmlspecialchars($ctype) . "”.");
                $qt[] = new SearchTerm("f");
                return;
            }
            $rt |= self::F_ALLOWRESPONSE;
        }
        if ($ctype === "cmt" || $ctype === "aucmt" || $ctype === "anycmt")
            $rt |= self::F_ALLOWCOMMENT;
        if ($ctype === "aucmt")
            $rt |= self::F_AUTHORCOMMENT;
        if (substr($m[0], 0, 1) === "#") {
            $rt |= ($this->privChair ? 0 : self::F_NONCONFLICT) | self::F_XVIEW;
            $tags = $this->_expand_tag(substr($m[0], 1), false);
            foreach ($tags as $tag)
                $this->_search_comment_tag($rt, $tag, $m[1], $round, $qt);
            if (!count($tags)) {
                $qt[] = new SearchTerm("f");
                return;
            } else if (count($tags) !== 1 || $tags[0] === "none" || $tags[0] === "any"
                       || !$this->conf->pc_tag_exists($tags[0]))
                return;
        }
        $contacts = ($m[0] === "" ? null : $this->_reviewerMatcher($m[0], $quoted, false));
        $value = new ContactCountMatcher($m[1], $contacts);
        $term = new Comment_SearchTerm($value, null, $round, $rt);
        $qt[] = $term;
    }

    private function _search_review_field($word, $f, &$qt, $quoted, $noswitch = false) {
        $rsm = new ReviewSearchMatcher(">0");
        $rsm->view_score = $f->view_score;

        $contactword = "";
        while (preg_match('/\A(.+?)([:=<>!]|≠|≤|≥)(.*)\z/s', $word, $m)
               && !ctype_digit($m[1])) {
            if ($rsm->apply_review_type($m[1])
                || $rsm->apply_completeness($m[1])
                || $rsm->apply_round($m[1], $this->conf))
                /* OK */;
            else
                $rsm->set_contacts($this->_reviewerMatcher($m[1], $quoted, false));
            $word = ($m[2] === ":" ? $m[3] : $m[2] . $m[3]);
            $contactword .= $m[1] . ":";
        }

        $field = $f->id;
        if ($f->has_options) {
            if ($word === "any")
                $value = "$field>0";
            else if ($word === "none")
                $value = "$field=0";
            else if (preg_match('/\A(\d*?)([=!<>]=?|≠|≤|≥)?\s*([A-Za-z]|\d+)\z/s', $word, $m)) {
                if ($m[1] === "")
                    $m[1] = 1;
                $m[2] = CountMatcher::canonical_comparator($m[2]);
                if ($f->option_letter != (ctype_digit($m[3]) == false))
                    $value = "$field=-1"; // XXX
                else {
                    $score = $m[3];
                    if ($f->option_letter) {
                        if (!$this->conf->opt("smartScoreCompare") || $noswitch) {
                            // switch meaning of inequality
                            if ($m[2][0] === "<")
                                $m[2] = ">" . substr($m[2], 1);
                            else if ($m[2][0] === ">")
                                $m[2] = "<" . substr($m[2], 1);
                        }
                        $score = strtoupper($score);
                        $m[3] = $f->option_letter - ord($score);
                    }
                    if (($m[3] < 1 && ($m[2][0] === "<" || $m[2] === "="))
                        || ($m[3] == 1 && $m[2] === "<")
                        || ($m[3] == count($f->options) && $m[2] === ">")
                        || ($m[3] > count($f->options) && ($m[2][0] === ">" || $m[2] === "="))) {
                        if ($f->option_letter)
                            $warnings = array("<" => "worse than", ">" => "better than");
                        else
                            $warnings = array("<" => "less than", ">" => "greater than");
                        $t = new SearchTerm("f");
                        $t->set("contradiction_warning", "No $f->name_html scores are " . ($m[2] === "=" ? "" : $warnings[$m[2][0]] . (strlen($m[2]) == 1 ? " " : " or equal to ")) . $score . ".");
                        $t->set_float("used_revadj", true);
                        $qt[] = $t;
                        return false;
                    } else {
                        $rsm->set_countexpr((int) $m[1] ? ">=" . $m[1] : "=0");
                        $value = $field . $m[2] . $m[3];
                    }
                }
            } else if ($f->option_letter
                       ? preg_match('/\A\s*([A-Za-z])\s*(-?|\.\.\.?)\s*([A-Za-z])\s*\z/s', $word, $m)
                       : preg_match('/\A\s*(\d+)\s*(-|\.\.\.?)\s*(\d+)\s*\z/s', $word, $m)) {
                $qo = array();
                if ($m[2] === "-" || $m[2] === "") {
                    $this->_search_review_field($contactword . $m[1], $f, $qo, $quoted);
                    $this->_search_review_field($contactword . $m[3], $f, $qo, $quoted);
                } else
                    $this->_search_review_field($contactword . ">=" . $m[1], $f, $qo, $quoted, true);
                if ($this->_search_review_field($contactword . "<" . $m[1], $f, $qo, $quoted, true))
                    $qo[count($qo) - 1] = SearchTerm::make_not($qo[count($qo) - 1]);
                else
                    array_pop($qo);
                if ($this->_search_review_field($contactword . ">" . $m[3], $f, $qo, $quoted, true))
                    $qo[count($qo) - 1] = SearchTerm::make_not($qo[count($qo) - 1]);
                else
                    array_pop($qo);
                $qt[] = SearchTerm::make_op("and", $qo);
                return true;
            } else              // XXX
                $value = "$field=-1";
        } else {
            if ($word === "any")
                $value = "$field!=''";
            else if ($word === "none")
                $value = "$field=''";
            else
                $value = "$field like " . Dbl::utf8ci("'%" . sqlq_for_like($word) . "%'");
        }

        if (!$rsm->completeness)
            $rsm->completeness = ReviewSearchMatcher::COMPLETE;
        $rsm->fieldsql = $value;
        $qt[] = new Review_SearchTerm($rsm);
        return true;
    }

    private function _search_revpref($word, &$qt, $quoted, $isexp) {
        $contacts = null;
        if (preg_match('/\A(.*?[^:=<>!])([:=!<>]=?|≠|≤|≥|\z)(.*)\z/s', $word, $m)
            && !ctype_digit($m[1])) {
            $contacts = $this->_reviewerMatcher($m[1], $quoted, true);
            $word = ($m[2] === ":" ? $m[3] : $m[2] . $m[3]);
            if ($word === "")
                $word = "!=0";
        }

        if (strcasecmp($word, "any") == 0 || strcasecmp($word, "none") == 0)
            $m = [null, "1", "=", "any", strcasecmp($word, "any") == 0];
        else if (!preg_match(',\A(\d*)\s*([=!<>]=?|≠|≤|≥|)\s*(-?\d*)\s*([xyz]?)\z,i', $word, $m)
                 || ($m[1] === "" && $m[3] === "" && $m[4] === "")) {
            $qt[] = new SearchTerm("f");
            return;
        }

        if ($m[1] !== "" && $m[2] === "")
            $m = array($m[0], "1", "=", $m[1], "");
        if ($m[1] === "")
            $m[1] = "1";
        if ($m[2] === "")
            $m[2] = "=";

        // PC members can only search their own preferences.
        // Admins can search papers they administer.
        $value = new RevprefSearchMatcher((int) $m[1] ? ">=" . $m[1] : "=0", $contacts);
        if ($m[3] === "any")
            $value->is_any = true;
        else if ($m[3] !== "")
            $value->preference_match = new CountMatcher($m[2] . $m[3]);
        if ($m[3] !== "any" && $m[4] !== "")
            $value->expertise_match = new CountMatcher($m[2] . (121 - ord(strtolower($m[4]))));
        $qz = [];
        if ($this->privChair)
            $qz[] = new Revpref_SearchTerm($value, 0);
        else {
            if ($this->user->is_manager())
                $qz[] = new Revpref_SearchTerm($value, self::F_MANAGER);
            if ($value->test_contact($this->cid)) {
                $xvalue = clone $value;
                $xvalue->set_contacts($this->cid);
                $qz[] = new Revpref_SearchTerm($xvalue, 0);
            }
        }
        if (!count($qz))
            $qz[] = new SearchTerm("f");
        if (strcasecmp($word, "none") == 0)
            $qz = array(SearchTerm::make_not(SearchTerm::make_op("or", $qz)));
        $qt = array_merge($qt, $qz);
    }

    private function _search_formula($word, &$qt, $quoted, $is_graph) {
        $result = $formula = null;
        if (preg_match('/\A[^(){}\[\]]+\z/', $word) && !$quoted
            && ($result = $this->conf->qe("select * from Formula where name=?", $word)))
            $formula = Formula::fetch($this->user, $result);
        Dbl::free($result);
        if (!$formula)
            $formula = new Formula($this->user, $word, $is_graph);
        if ($formula->check())
            $qt[] = new SearchTerm("formula", self::F_XVIEW, $formula);
        else {
            $this->warn($formula->error_html());
            $qt[] = new SearchTerm("f");
        }
    }

    private function _expand_tag($tagword, $allow_star) {
        // see also TagAssigner
        $ret = array("");
        $twiddle = strpos($tagword, "~");
        if ($this->privChair && $twiddle > 0 && !ctype_digit(substr($tagword, 0, $twiddle))) {
            $c = substr($tagword, 0, $twiddle);
            $ret = ContactSearch::make_pc($c, $this->user)->ids;
            if (count($ret) == 0)
                $this->warn("“" . htmlspecialchars($c) . "” doesn’t match a PC email.");
            $tagword = substr($tagword, $twiddle);
        } else if ($twiddle === 0 && ($tagword === "~" || $tagword[1] !== "~"))
            $ret[0] = $this->cid;

        $tagger = new Tagger($this->user);
        if (!$tagger->check("#" . $tagword, Tagger::ALLOWRESERVED | Tagger::NOVALUE | ($allow_star ? Tagger::ALLOWSTAR : 0))) {
            $this->warn($tagger->error_html);
            $ret = array();
        }
        foreach ($ret as &$x)
            $x .= $tagword;
        return $ret;
    }

    private function _search_tags($word, $keyword, &$qt) {
        if ($word[0] === "#")
            $word = substr($word, 1);

        // allow external reviewers to search their own rank tag
        if (!$this->amPC) {
            $ranktag = "~" . $this->conf->setting_data("tag_rank");
            if (!$this->conf->setting("tag_rank")
                || substr($word, 0, strlen($ranktag)) !== $ranktag
                || (strlen($word) > strlen($ranktag)
                    && $word[strlen($ranktag)] !== "#"))
                return;
        }

        $value = new TagSearchMatcher;
        if (preg_match('/\A([^#=!<>\x80-\xFF]+)(?:#|=)(-?(?:\.\d+|\d+\.?\d*))(?:\.\.\.?|-)(-?(?:\.\d+|\d+\.?\d*))\z/', $word, $m)) {
            $tagword = $m[1];
            $value->index1 = new CountMatcher(">=$m[2]");
            $value->index2 = new CountMatcher("<=$m[3]");
        } else if (preg_match('/\A([^#=!<>\x80-\xFF]+)(#?)([=!<>]=?|≠|≤|≥|)(-?(?:\.\d+|\d+\.?\d*))\z/', $word, $m)
            && $m[1] !== "any" && $m[1] !== "none"
            && ($m[2] !== "" || $m[3] !== "")) {
            $tagword = $m[1];
            $value->index1 = new CountMatcher(($m[3] ? : "=") . $m[4]);
        } else
            $tagword = $word;

        $negated = false;
        if (substr($tagword, 0, 1) === "-" && $keyword === "tag") {
            $negated = true;
            $tagword = ltrim(substr($tagword, 1));
        }

        $value->tags = $this->_expand_tag($tagword, $keyword === "tag");
        if (empty($value->tags))
            return new SearchTerm("f");
        if (count($value->tags) === 1 && $value->tags[0] === "none") {
            $value->tags[0] = "any";
            $negated = !$negated;
        }

        $term = new SearchTerm("tag", self::F_XVIEW, $value);
        if ($negated)
            $term = SearchTerm::make_not($term);
        else if ($keyword === "order" || $keyword === "rorder" || !$keyword)
            $term->set_float("sort", array(($keyword === "rorder" ? "-" : "") . "#" . $value->tags[0]));
        $qt[] = $term;
    }

    private function _search_color($word, &$qt) {
        $word = strtolower($word);
        $value = new TagSearchMatcher;
        if ($this->amPC
            && preg_match(',\A(any|none|color|' . TagInfo::BASIC_COLORS_PLUS . ')\z,', $word)) {
            if ($word === "any" || $word === "none")
                $f = function ($t, $colors) { return !empty($colors); };
            else if ($word === "color")
                $f = function ($t, $colors) {
                    return !empty($colors)
                        && preg_match('/ (?:' . TagInfo::BASIC_COLORS_NOSTYLES . ') /',
                                      " " . join(" ", $colors) . " ");
                };
            else {
                $word = TagInfo::canonical_color($word);
                $f = function ($t, $colors) use ($word) {
                    return !empty($colors) && array_search($word, $colors) !== false;
                };
            }
            foreach ($this->conf->tags()->tags_with_color($f) as $tag) {
                array_push($value->tags, $tag, "{$this->cid}~$tag");
                if ($this->privChair)
                    $value->tags[] = "~~$tag";
            }
        }
        $qe = $value->make_term();
        if ($word === "none")
            $qe = SearchTerm::make_not($qe);
        $qt[] = $qe;
    }

    private function _search_topic($word, &$qt) {
        $value = null;
        $lword = strtolower($word);
        if ($lword === "none" || $lword === "any")
            $value = ($lword === "any");
        else {
            $x = simplify_whitespace($lword);
            $tids = array();
            foreach ($this->conf->topic_map() as $tid => $tname)
                if (strstr(strtolower($tname), $x) !== false)
                    $tids[] = $tid;
            if (empty($tids))
                $this->warn("“" . htmlspecialchars($x) . "” does not match any defined paper topic.");
            $value = $tids;
        }
        $qt[] = new Topic_SearchTerm($value);
    }

    static public function analyze_option_search(Conf $conf, $word) {
        if (preg_match('/\A(.*?)([:#](?:[=!<>]=?|≠|≤|≥|)|[=!<>]=?|≠|≤|≥)(.*)\z/', $word, $m)) {
            $oname = $m[1];
            if ($m[2][0] === ":" || $m[2][0] === "#")
                $m[2] = substr($m[2], 1);
            $ocompar = CountMatcher::canonical_comparator($m[2]);
            $oval = strtolower(simplify_whitespace($m[3]));
        } else {
            $oname = $word;
            $ocompar = "=";
            $oval = "";
        }
        $oname = strtolower(simplify_whitespace($oname));

        // match all options
        $qo = $warn = array();
        $option_failure = false;
        if ($oname === "none" || $oname === "any")
            $omatches = $conf->paper_opts->option_list();
        else
            $omatches = $conf->paper_opts->search($oname);
        // Conf::msg_info(Ht::pre_text(var_export($omatches, true)));
        if (!empty($omatches)) {
            foreach ($omatches as $oid => $o) {
                // selectors handle “yes”, “”, and “no” specially
                if ($o->has_selector()) {
                    $xval = array();
                    if ($oval === "") {
                        foreach ($o->selector as $k => $v)
                            if (strcasecmp($v, "yes") == 0)
                                $xval[$k] = $v;
                        if (count($xval) == 0)
                            $xval = $o->selector;
                    } else
                        $xval = Text::simple_search($oval, $o->selector);
                    if (count($xval) == 0)
                        $warn[] = "“" . htmlspecialchars($oval) . "” doesn’t match any " . htmlspecialchars($oname) . " values.";
                    else if (count($xval) == 1) {
                        reset($xval);
                        $qo[] = new OptionMatcher($o, $ocompar, key($xval), $oval);
                    } else if ($ocompar !== "=" && $ocompar !== "!=")
                        $warn[] = "Submission option “" . htmlspecialchars("$oname:$oval") . "” matches multiple values, can’t use " . htmlspecialchars($ocompar) . ".";
                    else
                        $qo[] = new OptionMatcher($o, $ocompar, array_keys($xval), $oval);
                    continue;
                }

                if ($oval === "" || $oval === "yes")
                    $qo[] = new OptionMatcher($o, "!=", 0, $oval);
                else if ($oval === "no")
                    $qo[] = new OptionMatcher($o, "=", 0, $oval);
                else if ($o->type === "numeric") {
                    if (preg_match('/\A\s*([-+]?\d+)\s*\z/', $oval, $m))
                        $qo[] = new OptionMatcher($o, $ocompar, $m[1], $oval);
                    else
                        $warn[] = "Submission option “" . htmlspecialchars($o->name) . "” takes integer values.";
                } else if ($o->has_attachments()) {
                    if ($oval === "any")
                        $qo[] = new OptionMatcher($o, "!=", 0, $oval);
                    else if (preg_match('/\A\s*([-+]?\d+)\s*\z/', $oval, $m)) {
                        if (CountMatcher::compare(0, $ocompar, $m[1]))
                            $qo[] = new OptionMatcher($o, "=", 0, $oval);
                        $qo[] = new OptionMatcher($o, $ocompar, $m[1], $oval, "attachment-count");
                    } else
                        $qo[] = new OptionMatcher($o, "~=", $oval, $oval, "attachment-name", $oval);
                } else
                    continue;
            }
        } else if (($ocompar === "=" || $ocompar === "!=") && $oval === "")
            foreach ($conf->paper_opts->option_list() as $oid => $o)
                if ($o->has_selector()) {
                    foreach (Text::simple_search($oname, $o->selector) as $xval => $text)
                        $qo[] = new OptionMatcher($o, $ocompar, $xval, "~val~");
                }

        return (object) array("os" => $qo, "warn" => $warn, "negate" => $oname === "none");
    }

    function _search_options($word, &$qt, $report_error) {
        $os = self::analyze_option_search($this->conf, $word);
        foreach ($os->warn as $w)
            $this->warn($w);
        if (!count($os->os)) {
            if ($report_error && !count($os->warn))
                $this->warn("“" . htmlspecialchars($word) . "” doesn’t match a submission option.");
            if ($report_error || count($os->warn))
                $qt[] = new SearchTerm("f");
            return false;
        }

        // add expressions
        $qz = array();
        foreach ($os->os as $oq)
            $qz[] = new SearchTerm("option", self::F_XVIEW, $oq);
        if ($os->negate)
            $qz = array(SearchTerm::make_not(SearchTerm::make_op("or", $qz)));
        $qt = array_merge($qt, $qz);
        return true;
    }

    private function _search_has($word, &$qt, $quoted) {
        $original_lword = $lword = strtolower($word);
        $lword = get(self::$_keywords, $lword) ? : $lword;
        if ($lword === "paper" || $lword === "sub" || $lword === "submission")
            $qt[] = new SearchTerm("pf", 0, array("paperStorageId", ">1"));
        else if ($lword === "final" || $lword === "finalcopy")
            $qt[] = new SearchTerm("pf", 0, array("finalPaperStorageId", ">1"));
        else if ($lword === "ab")
            $qt[] = new TextMatch_SearchTerm("ab", true);
        else if (preg_match('/\A(?:(?:draft-?)?\w*resp(?:onse)?|\w*resp(?:onse)(?:-?draft)?|cmt|aucmt|anycmt)\z/', $lword))
            $this->_search_comment(">0", $lword, $qt, $quoted);
        else if ($lword === "manager" || $lword === "admin" || $lword === "administrator")
            $qt[] = new PaperPC_SearchTerm("manager", "!=0");
        else if (preg_match('/\A[cip]?(?:re|pri|sec|ext)\z/', $lword))
            $this->_search_reviewer(">0", $lword, $qt);
        else if ($lword === "lead")
            $qt[] = new PaperPC_SearchTerm("lead", "!=0");
        else if ($lword === "shepherd")
            $qt[] = new PaperPC_SearchTerm("shepherd", "!=0");
        else if ($lword === "decision")
            $this->_search_status("yes", $qt, false, false);
        else if ($lword === "approvable")
            $this->_search_reviewer("approvable>0", "ext", $qt);
        else if ($original_lword === "style")
            $this->_search_color("any", $qt);
        else if ($lword === "color")
            $this->_search_color("color", $qt);
        else if ($lword === "badge") {
            $value = new TagSearchMatcher;
            if ($this->amPC && $this->conf->tags()->has_badges) {
                foreach ($this->conf->tags()->filter("badges") as $t)
                    $value->tags[] = $t->tag;
            }
            $qt[] = $value->make_term();
        } else if ($lword === "emoji") {
            $value = new TagSearchMatcher;
            if ($this->amPC && $this->conf->tags()->has_emoji) {
                $value->tags = [":*:", "{$this->cid}~:*:"];
                if ($this->privChair)
                    $value->tags[] = "~~:*:";
            }
            $qt[] = $value->make_term();
        } else if (preg_match('/\A[\w-]+\z/', $lword) && $this->_search_options("$lword:yes", $qt, false))
            /* OK */;
        else {
            $has = [];
            foreach ($this->search_completion("has") as $h)
                if (str_starts_with($h, "has:"))
                    $has[] = "“" . htmlspecialchars($h) . "”";
            $this->warn("Unknown “has:” search. I understand " . commajoin($has) . ".");
            $qt[] = new SearchTerm("f");
        }
    }

    private function _searchReviewRatings($word, &$qt) {
        $this->reviewAdjust = true;
        if (preg_match('/\A(.+?)\s*(|[=!<>]=?|≠|≤|≥)\s*(\d*)\z/', $word, $m)
            && ($m[3] !== "" || $m[2] === "")
            && $this->conf->setting("rev_ratings") != REV_RATINGS_NONE) {
            // adjust counts
            if ($m[3] === "") {
                $m[2] = ">";
                $m[3] = "0";
            }
            if ($m[2] === "")
                $m[2] = ($m[3] == 0 ? "=" : ">=");
            else
                $m[2] = CountMatcher::canonical_comparator($m[2]);
            $nqt = count($qt);

            // resolve rating type
            if ($m[1] === "+" || $m[1] === "good") {
                $this->interestingRatings["good"] = ">0";
                $term = "nrate_good";
            } else if ($m[1] === "-" || $m[1] === "bad"
                       || $m[1] === "\xE2\x88\x92" /* unicode MINUS */) {
                $this->interestingRatings["bad"] = "<1";
                $term = "nrate_bad";
            } else if ($m[1] === "any") {
                $this->interestingRatings["any"] = "!=100";
                $term = "nrate_any";
            } else {
                $x = Text::simple_search($m[1], ReviewForm::$rating_types);
                unset($x["n"]); /* don't allow "average" */
                if (count($x) == 0) {
                    $this->warn("Unknown rating type “" . htmlspecialchars($m[1]) . "”.");
                    $qt[] = new SearchTerm("f");
                } else {
                    $type = count($this->interestingRatings);
                    $this->interestingRatings[$type] = " in (" . join(",", array_keys($x)) . ")";
                    $term = "nrate_$type";
                }
            }

            if (count($qt) == $nqt) {
                if ($m[2][0] === "<" || $m[2] === "!="
                    || ($m[2] === "=" && $m[3] == 0)
                    || ($m[2] === ">=" && $m[3] == 0))
                    $term = "coalesce($term,0)";
                $qt[] = ReviewAdjustment_SearchTerm::make_rate($this->conf, $term . $m[2] . $m[3]);
            }
        } else {
            if ($this->conf->setting("rev_ratings") == REV_RATINGS_NONE)
                $this->warn("Review ratings are disabled.");
            else
                $this->warn("Bad review rating query “" . htmlspecialchars($word) . "”.");
            $qt[] = new SearchTerm("f");
        }
    }

    static private function find_end_balanced_parens($str) {
        $pcount = $quote = 0;
        for ($pos = 0; $pos < strlen($str)
                 && (!ctype_space($str[$pos]) || $pcount || $quote); ++$pos) {
            $ch = $str[$pos];
            if ($quote) {
                if ($ch === "\\" && $pos + 1 < strlen($str))
                    ++$pos;
                else if ($ch === "\"")
                    $quote = 0;
            } else if ($ch === "\"")
                $quote = 1;
            else if ($ch === "(" || $ch === "[" || $ch === "{")
                ++$pcount;
            else if ($ch === ")" || $ch === "]" || $ch === "}") {
                if (!$pcount)
                    break;
                --$pcount;
            }
        }
        return $pos;
    }

    static public function parse_sorter($text) {
        if (!self::$_sort_keywords)
            self::$_sort_keywords =
                array("by" => "by", "up" => "up", "down" => "down",
                      "reverse" => "down", "reversed" => "down",
                      "count" => "C", "counts" => "C", "av" => "A",
                      "ave" => "A", "average" => "A", "med" => "E",
                      "median" => "E", "var" => "V", "variance" => "V",
                      "max-min" => "D", "my" => "Y", "score" => "");

        $text = simplify_whitespace($text);
        $sort = ListSorter::make_empty($text === "");
        if (($ch1 = substr($text, 0, 1)) === "-" || $ch1 === "+") {
            $sort->reverse = $ch1 === "-";
            $text = ltrim(substr($text, 1));
        }

        // separate text into words
        $words = array();
        $bypos = false;
        while ($text !== "") {
            preg_match(',\A([^\s\(]*)(.*)\z,s', $text, $m);
            if (substr($m[2], 0, 1) === "(") {
                $pos = self::find_end_balanced_parens($m[2]);
                $m[1] .= substr($m[2], 0, $pos);
                $m[2] = substr($m[2], $pos);
            }
            $words[] = $m[1];
            $text = ltrim($m[2]);
            if ($m[1] === "by" && $bypos === false)
                $bypos = count($words) - 1;
        }

        // go over words
        $next_words = array();
        for ($i = 0; $i != count($words); ++$i) {
            $w = $words[$i];
            if (($bypos === false || $i > $bypos)
                && isset(self::$_sort_keywords[$w])) {
                $x = self::$_sort_keywords[$w];
                if ($x === "up")
                    $sort->reverse = false;
                else if ($x === "down")
                    $sort->reverse = true;
                else if (ctype_upper($x))
                    $sort->score = $x;
            } else if ($bypos === false || $i < $bypos)
                $next_words[] = $w;
        }

        if (count($next_words))
            $sort->type = join(" ", $next_words);
        return $sort;
    }

    private function _expand_saved_search($word, $recursion) {
        if (isset($recursion[$word]))
            return false;
        $t = $this->conf->setting_data("ss:" . $word, "");
        $search = json_decode($t);
        if ($search && is_object($search) && isset($search->q))
            return $search->q;
        else
            return null;
    }

    function _searchQueryWord($word, $report_error) {
        // check for paper number or "#TAG"
        if (preg_match('/\A#?(\d+)(?:-#?(\d+))?\z/', $word, $m)) {
            $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
            return new SearchTerm("pn", 0, array(range($m[1], $m[2]), array()));
        } else if (substr($word, 0, 1) === "#") {
            $qe = $this->_searchQueryWord("tag:" . $word, false);
            if (!$qe->is_false())
                return $qe;
        }

        // Allow searches like "ovemer>2"; parse as "ovemer:>2".
        if (preg_match('/\A([-_A-Za-z0-9]+)((?:[=!<>]=?|≠|≤|≥)[^:]+)\z/', $word, $m)) {
            $qe = $this->_searchQueryWord($m[1] . ":" . $m[2], false);
            if (!$qe->is_false())
                return $qe;
        }

        $keyword = null;
        if (($colon = strpos($word, ":")) > 0) {
            $x = substr($word, 0, $colon);
            if (strpos($x, '"') === false) {
                $keyword = get(self::$_keywords, $x) ? : $x;
                $word = substr($word, $colon + 1);
                if ($word === false)
                    $word = "";
            }
        }

        // Treat unquoted "*", "ANY", and "ALL" as special; return true.
        if ($word === "*" || $word === "ANY" || $word === "ALL" || $word === "")
            return new SearchTerm("t");
        else if ($word === "NONE")
            return new SearchTerm("f");

        $qword = $word;
        $quoted = ($word[0] === '"');
        $negated = false;
        if ($quoted)
            $word = str_replace('*', '\*', preg_replace('/(?:\A"|"\z)/', '', $word));
        if ($keyword === "notag") {
            $keyword = "tag";
            $negated = true;
        }

        $qt = array();
        if ($keyword ? $keyword === "ti" : isset($this->fields["ti"]))
            $this->_text_match_field($qt, "ti", $word);
        if ($keyword ? $keyword === "ab" : isset($this->fields["ab"]))
            $this->_text_match_field($qt, "ab", $word);
        if ($keyword ? $keyword === "au" : isset($this->fields["au"]))
            $this->_searchAuthors($word, $qt, $keyword, $quoted);
        if ($keyword ? $keyword === "co" : isset($this->fields["co"]))
            $this->_text_match_field($qt, "co", $word);
        if ($keyword ? $keyword === "re" : isset($this->fields["re"]))
            $this->_search_reviewer($qword, "re", $qt);
        else if ($keyword && get(self::$_canonical_review_keywords, $keyword))
            $this->_search_reviewer($qword, $keyword, $qt);
        if (preg_match('/\A(?:(?:draft-?)?\w*resp(?:onse)|\w*resp(?:onse)?(-?draft)?|cmt|aucmt|anycmt)\z/', $keyword))
            $this->_search_comment($word, $keyword, $qt, $quoted);
        if ($keyword === "pref" && $this->amPC)
            $this->_search_revpref($word, $qt, $quoted, false);
        if ($keyword === "prefexp" && $this->amPC)
            $this->_search_revpref($word, $qt, $quoted, true);
        foreach (array("lead", "shepherd", "manager") as $ctype)
            if ($keyword === $ctype) {
                $x = $this->_one_pc_matcher($word, $quoted);
                $qt[] = new PaperPC_SearchTerm($ctype, $x);
                if ($ctype === "manager" && $word === "me" && !$quoted && $this->privChair)
                    $qt[] = new PaperPC_SearchTerm($ctype, "=0");
            }
        if (($keyword ? $keyword === "tag" : isset($this->fields["tag"]))
            || $keyword === "order" || $keyword === "rorder")
            $this->_search_tags($word, $keyword, $qt);
        if ($keyword === "color")
            $this->_search_color($word, $qt);
        if ($keyword === "topic")
            $this->_search_topic($word, $qt);
        if ($keyword === "option")
            $this->_search_options($word, $qt, true);
        if ($keyword === "status" || $keyword === "is")
            $this->_search_status($word, $qt, $quoted, true);
        if ($keyword === "decision")
            $this->_search_status($word, $qt, $quoted, false);
        if ($keyword === "conflict" && $this->amPC)
            $this->_search_conflict($word, $qt, $quoted, false);
        if ($keyword === "pcconflict" && $this->amPC)
            $this->_search_conflict($word, $qt, $quoted, true);
        if ($keyword === "reconflict" && $this->privChair)
            $this->_searchReviewerConflict($word, $qt, $quoted);
        if ($keyword === "round" && $this->amPC) {
            $this->reviewAdjust = true;
            if ($word === "none")
                $qt[] = ReviewAdjustment_SearchTerm::make_round($this->conf, [0]);
            else if ($word === "any")
                $qt[] = ReviewAdjustment_SearchTerm::make_round($this->conf, range(1, count($this->conf->round_list()) - 1));
            else {
                $x = simplify_whitespace($word);
                $rounds = Text::simple_search($x, $this->conf->round_list());
                if (count($rounds) == 0) {
                    $this->warn("“" . htmlspecialchars($x) . "” doesn’t match a review round.");
                    $qt[] = new SearchTerm("f");
                } else
                    $qt[] = ReviewAdjustment_SearchTerm::make_round($this->conf, array_keys($rounds));
            }
        }
        if ($keyword === "rate")
            $this->_searchReviewRatings($word, $qt);
        if ($keyword === "has")
            $this->_search_has($word, $qt, $quoted);
        if ($keyword === "formula")
            $this->_search_formula($word, $qt, $quoted, false);
        if ($keyword === "graph") {
            $this->_search_formula($word, $qt, $quoted, true);
            $t = array_pop($qt);
            if ($t->type === "formula")
                $qt[] = SearchTerm::make_float(["view" => ["graph($word)" => true]]);
        }
        if ($keyword === "ss" && $this->amPC) {
            if (($nextq = $this->_expand_saved_search($word, $this->_ssRecursion))) {
                $this->_ssRecursion[$word] = true;
                $qe = $this->_searchQueryType($nextq);
                unset($this->_ssRecursion[$word]);
            } else
                $qe = null;
            if (!$qe && $nextq === false)
                $this->warn("Saved search “" . htmlspecialchars($word) . "” is incorrectly defined in terms of itself.");
            else if (!$qe && !$this->conf->setting_data("ss:$word"))
                $this->warn("There is no “" . htmlspecialchars($word) . "” saved search.");
            else if (!$qe)
                $this->warn("The “" . htmlspecialchars($word) . "” saved search is defined incorrectly.");
            $qt[] = ($qe ? : new SearchTerm("f"));
        }
        if ($keyword === "HEADING") {
            $heading = simplify_whitespace($word);
            $qt[] = SearchTerm::make_float(["heading" => $heading]);
        }
        if ($keyword === "show" || $keyword === "hide" || $keyword === "edit"
            || $keyword === "sort" || $keyword === "showsort"
            || $keyword === "editsort") {
            $editing = strpos($keyword, "edit") !== false;
            $sorting = strpos($keyword, "sort") !== false;
            $views = array();
            $a = ($keyword === "hide" ? false : ($editing ? "edit" : true));
            $word = simplify_whitespace($word);
            $ch1 = substr($word, 0, 1);
            if ($ch1 === "-" && !$sorting)
                list($a, $word) = array(false, substr($word, 1));
            $wtype = $word;
            if ($sorting) {
                $sort = self::parse_sorter($wtype);
                $wtype = $sort->type;
            }
            if ($wtype !== "" && $keyword !== "sort")
                $views[$wtype] = $a;
            $f = ["view" => $views];
            if ($sorting)
                $f["sort"] = [$word];
            $qt[] = SearchTerm::make_float($f);
        }

        // Finally, look for a review field.
        if ($keyword && !isset(self::$_keywords[$keyword]) && empty($qt)) {
            if (($field = $this->conf->review_field_search($keyword)))
                $this->_search_review_field($word, $field, $qt, $quoted);
            else if (!$this->_search_options("$keyword:$word", $qt, false)
                     && $report_error)
                $this->warn("Unrecognized keyword “" . htmlspecialchars($keyword) . "”.");
        }

        $qe = SearchTerm::make_op("or", $qt);
        return $negated ? SearchTerm::make_not($qe) : $qe;
    }

    static public function pop_word(&$str) {
        $wordre = '/\A\s*(?:"[^"]*"?|[a-zA-Z][a-zA-Z0-9]*:(?:"[^"]*(?:"|\z)|[^"\s()]*)+|[^"\s()]+)/s';

        if (!preg_match($wordre, $str, $m))
            return ($str = "");
        $str = substr($str, strlen($m[0]));
        $word = ltrim($m[0]);

        // commas in paper number strings turn into separate words
        if (preg_match('/\A(#?\d+(?:-#?\d+)?),((?:#?\d+(?:-#?\d+)?,?)*)\z/', $word, $m)) {
            $word = $m[1];
            if ($m[2] !== "")
                $str = $m[2] . $str;
        }

        // elide colon
        if ($word === "HEADING") {
            $str = $word . ":" . ltrim($str);
            return self::pop_word($str);
        }

        // check for keyword
        $keyword = false;
        if (($colon = strpos($word, ":")) > 0) {
            $x = substr($word, 0, $colon);
            if (strpos($x, '"') === false)
                $keyword = get(self::$_keywords, $x) ? : $x;
        }

        // allow a space after a keyword
        if ($keyword && strlen($word) <= $colon + 1 && preg_match($wordre, $str, $m)) {
            $word .= ltrim($m[0]);
            $str = substr($str, strlen($m[0]));
        }

        // "show:" may be followed by a parenthesized expression
        if ($keyword
            && ($keyword === "show" || $keyword === "showsort"
                || $keyword === "sort" || $keyword === "formula"
                || $keyword === "graph")
            && substr($word, $colon + 1, 1) !== "\""
            && preg_match('/\A-?[a-z]*\(/', $str)) {
            $pos = self::find_end_balanced_parens($str);
            $word .= substr($str, 0, $pos);
            $str = substr($str, $pos);
        }

        $str = ltrim($str);
        return $word;
    }

    static function _searchPopKeyword($str) {
        if (preg_match('/\A([-+()]|(?:AND|and|OR|or|NOT|not|THEN|then|HIGHLIGHT(?::\w+)?)(?=[\s\(]))/s', $str, $m))
            return array(strtoupper($m[1]), ltrim(substr($str, strlen($m[0]))));
        else
            return array(null, $str);
    }

    static private function _searchPopStack($curqe, &$stack) {
        $x = array_pop($stack);
        if (!$curqe)
            return $x->leftqe;
        if ($x->leftqe)
            $curqe = SearchTerm::make_op($x->op, [$x->leftqe, $curqe]);
        else if ($x->op->op !== "+" && $x->op->op !== "(")
            $curqe = SearchTerm::make_op($x->op, [$curqe]);
        $curqe->apply_strspan($x->strspan);
        return $curqe;
    }

    private function _searchQueryType($str) {
        $stack = array();
        $defkwstack = array();
        $defkw = $next_defkw = null;
        $parens = 0;
        $curqe = null;
        $stri = $str;

        while ($str !== "") {
            $oppos = strlen($stri) - strlen($str);
            list($opstr, $nextstr) = self::_searchPopKeyword($str);
            $op = $opstr ? get(SearchOperator::$list, $opstr) : null;
            if ($opstr && !$op && ($colon = strpos($opstr, ":"))
                && ($op = SearchOperator::$list[substr($opstr, 0, $colon)])) {
                $op = clone $op;
                $op->opinfo = substr($opstr, $colon + 1);
            }

            if ($curqe && (!$op || $op->unary)) {
                $op = SearchOperator::$list["SPACE"];
                $opstr = "";
                $nextstr = $str;
            }
            if (!$curqe && $op && $op->op === "highlight") {
                $curqe = new SearchTerm("t");
                $curqe->set_float("strspan", [$oppos, $oppos]);
            }

            if ($opstr === null) {
                $prevstr = $nextstr;
                $word = self::pop_word($nextstr);
                // Bare any-case "all", "any", "none" are treated as keywords.
                if (!$curqe
                    && (empty($stack) || $stack[count($stack) - 1]->op->precedence <= 2)
                    && ($uword = strtoupper($word))
                    && ($uword === "ALL" || $uword === "ANY" || $uword === "NONE")
                    && preg_match(',\A\s*(?:|(?:THEN|then|HIGHLIGHT(?::\w+)?)(?:\s|\().*)\z,', $nextstr))
                    $word = $uword;
                // Search like "ti:(foo OR bar)" adds a default keyword.
                if ($word[strlen($word) - 1] === ":"
                    && $nextstr !== ""
                    && $nextstr[0] === "(")
                    $next_defkw = $word;
                else {
                    // If no keyword, but default keyword exists, apply it.
                    if ($defkw !== ""
                        && !preg_match(',\A-?[a-zA-Z][a-zA-Z0-9]*:,', $word)) {
                        if ($word[0] === "-")
                            $word = "-" . $defkw . substr($word, 1);
                        else
                            $word = $defkw . $word;
                    }
                    // The heart of the matter.
                    $curqe = $this->_searchQueryWord($word, true);
                    if (!$curqe->is_uninteresting())
                        $curqe->set_float("strspan", [$oppos, strlen($stri) - strlen($nextstr)]);
                }
            } else if ($opstr === ")") {
                while (!empty($stack)
                       && $stack[count($stack) - 1]->op->op !== "(")
                    $curqe = self::_searchPopStack($curqe, $stack);
                if (!empty($stack)) {
                    $stack[count($stack) - 1]->strspan[1] = $oppos + 1;
                    $curqe = self::_searchPopStack($curqe, $stack);
                    --$parens;
                    $defkw = array_pop($defkwstack);
                }
            } else if ($opstr === "(") {
                assert(!$curqe);
                $stack[] = (object) ["op" => $op, "leftqe" => null, "strspan" => [$oppos, $oppos + 1]];
                $defkwstack[] = $defkw;
                $defkw = $next_defkw;
                $next_defkw = null;
                ++$parens;
            } else if ($op->unary || $curqe) {
                $end_precedence = $op->precedence - ($op->precedence <= 1);
                while (!empty($stack)
                       && $stack[count($stack) - 1]->op->precedence > $end_precedence)
                    $curqe = self::_searchPopStack($curqe, $stack);
                $stack[] = (object) ["op" => $op, "leftqe" => $curqe, "strspan" => [$oppos, $oppos + strlen($opstr)]];
                $curqe = null;
            }

            $str = $nextstr;
        }

        while (!empty($stack))
            $curqe = self::_searchPopStack($curqe, $stack);
        return $curqe;
    }


    static private function _canonicalizePopStack($curqe, &$stack) {
        $x = array_pop($stack);
        if ($curqe)
            $x->qe[] = $curqe;
        if (!count($x->qe))
            return null;
        if ($x->op->unary) {
            $qe = $x->qe[0];
            if ($x->op->op === "not") {
                if (preg_match('/\A(?:[(-]|NOT )/i', $qe))
                    $qe = "NOT $qe";
                else
                    $qe = "-$qe";
            }
            return $qe;
        } else if (count($x->qe) == 1)
            return $x->qe[0];
        else if ($x->op->op === "and2" && $x->op->precedence == 2)
            return "(" . join(" ", $x->qe) . ")";
        else
            return "(" . join(strtoupper(" " . $x->op->op . " "), $x->qe) . ")";
    }

    static private function _canonicalizeQueryType($str, $type) {
        $stack = array();
        $parens = 0;
        $defaultop = ($type === "all" ? "XAND" : "XOR");
        $curqe = null;
        $t = "";

        while ($str !== "") {
            list($opstr, $nextstr) = self::_searchPopKeyword($str);
            $op = $opstr ? SearchOperator::$list[$opstr] : null;

            if ($curqe && (!$op || $op->unary)) {
                list($opstr, $op, $nextstr) =
                    array("", SearchOperator::$list[$parens ? "XAND" : $defaultop], $str);
            }

            if ($opstr === null) {
                $curqe = self::pop_word($nextstr);
            } else if ($opstr === ")") {
                while (count($stack)
                       && $stack[count($stack) - 1]->op->op !== "(")
                    $curqe = self::_canonicalizePopStack($curqe, $stack);
                if (count($stack)) {
                    array_pop($stack);
                    --$parens;
                }
            } else if ($opstr === "(") {
                assert(!$curqe);
                $stack[] = (object) array("op" => $op, "qe" => array());
                ++$parens;
            } else {
                $end_precedence = $op->precedence - ($op->precedence <= 1);
                while (count($stack)
                       && $stack[count($stack) - 1]->op->precedence > $end_precedence)
                    $curqe = self::_canonicalizePopStack($curqe, $stack);
                $top = count($stack) ? $stack[count($stack) - 1] : null;
                if ($top && !$op->unary && $top->op->op === $op->op)
                    $top->qe[] = $curqe;
                else
                    $stack[] = (object) array("op" => $op, "qe" => array($curqe));
                $curqe = null;
            }

            $str = $nextstr;
        }

        if ($type === "none")
            array_unshift($stack, (object) array("op" => SearchOperator::$list["NOT"], "qe" => array()));
        while (count($stack))
            $curqe = self::_canonicalizePopStack($curqe, $stack);
        return $curqe;
    }

    static function canonical_query($qa, $qo = null, $qx = null) {
        $x = array();
        if ($qa && ($qa = self::_canonicalizeQueryType(trim($qa), "all")))
            $x[] = $qa;
        if ($qo && ($qo = self::_canonicalizeQueryType(trim($qo), "any")))
            $x[] = $qo;
        if ($qx && ($qx = self::_canonicalizeQueryType(trim($qx), "none")))
            $x[] = $qx;
        if (count($x) == 1)
            return preg_replace('/\A\((.*)\)\z/', '$1', join("", $x));
        else
            return join(" AND ", $x);
    }


    // CLEANING
    // Clean an input expression series into clauses.  The basic purpose of
    // this step is to combine all paper numbers into a single group, and to
    // assign review adjustments (rates & rounds).

    // apply rounds to reviewer searches
    private function _query_adjust_reviews(SearchTerm $qe, ReviewAdjustment_SearchTerm $revadj = null) {
        if ($qe->type === "not")
            $this->_query_adjust_reviews($qe->value[0], $revadj);
        else if ($qe->type === "and" || $qe->type === "and2") {
            $myrevadj = null;
            if ($qe->value[0] instanceof ReviewAdjustment_SearchTerm)
                $myrevadj = $qe->value[0];
            if ($myrevadj)
                $used_revadj = $myrevadj->merge($revadj);

            $rdown = $myrevadj ? : $revadj;
            for ($i = 0; $i < count($qe->value); ++$i)
                if (!($qe->value[$i] instanceof ReviewAdjustment_SearchTerm))
                    $this->_query_adjust_reviews($qe->value[$i], $rdown);

            if ($myrevadj && !$myrevadj->used_revadj) {
                $qe->value[0] = $myrevadj->promote($this);
                if ($used_revadj)
                    $revadj->used_revadj = true;
            }
        } else if ($qe->type === "or" || $qe->type === "then") {
            for ($i = 0; $i < count($qe->value); ++$i)
                $this->_query_adjust_reviews($qe->value[$i], $revadj);
        } else if ($qe->type === "re" && $revadj) {
            $qe->adjust($revadj);
        } else if ($qe->get_float("used_revadj")) {
            $revadj && ($revadj->used_revadj = true);
        } else if ($qe instanceof ReviewAdjustment_SearchTerm) {
            assert(!$revadj);
            return $qe->promote($this);
        }
        return $qe;
    }

    private function _queryExtractInfo($qe, $top, $highlight, &$contradictions) {
        if ($qe->type === "and" || $qe->type === "and2"
            || $qe->type === "or" || $qe->type === "then") {
            $isand = $qe->type === "and" || $qe->type === "and2";
            $nthen = $qe->type === "then" ? $qe->nthen : count($qe->value);
            foreach ($qe->value as $qvidx => $qv)
                $this->_queryExtractInfo($qv, $top && $isand, $qvidx >= $nthen, $contradictions);
        }
        if ($qe instanceof TextMatch_SearchTerm && $qe->regex)
            $this->regex[$qe->type][] = $qe->regex;
        if ($top && $qe->type === "re" && !$this->_reviewer_fixed && !$highlight) {
            if ($this->_reviewer === false
                && ($v = $qe->reviewer_contact_set())
                && count($v) == 1)
                $this->_reviewer = $this->conf->user_by_id($v[0]);
            else
                $this->_reviewer = null;
        }
        if ($top && ($x = $qe->get("contradiction_warning")))
            $contradictions[$x] = true;
    }


    // QUERY CONSTRUCTION
    // Build a database query corresponding to an expression.
    // The query may be liberal (returning more papers than actually match);
    // QUERY EVALUATION makes it precise.

    static function unusableRatings(Contact $user) {
        if ($user->privChair || $user->conf->timePCViewAllReviews())
            return array();
        $noratings = array();
        $rateset = $user->conf->setting("rev_rating");
        if ($rateset == REV_RATINGS_PC)
            $npr_constraint = "reviewType>" . REVIEW_EXTERNAL;
        else
            $npr_constraint = "true";
        // This query supposedly returns those reviewIds whose ratings
        // are not visible to the current querier
        $result = $user->conf->qe("select MPR.reviewId
        from PaperReview as MPR
        left join (select paperId, count(reviewId) as numReviews from PaperReview where $npr_constraint and reviewNeedsSubmit=0 group by paperId) as NPR on (NPR.paperId=MPR.paperId)
        left join (select paperId, count(rating) as numRatings from PaperReview join ReviewRating using (paperId,reviewId) group by paperId) as NRR on (NRR.paperId=MPR.paperId)
        where MPR.contactId={$user->contactId}
        and numReviews<=2
        and numRatings<=2");
        return Dbl::fetch_first_columns($result);
    }

    function _add_rating_sql(&$reviewtable, &$where, $rate) {
        $noratings = "";
        if ($this->noratings === false)
            $this->noratings = self::unusableRatings($this->user);
        if (count($this->noratings) > 0)
            $noratings .= " and not (reviewId in (" . join(",", $this->noratings) . "))";
        else
            $noratings = "";

        foreach ($this->interestingRatings as $k => $v)
            $reviewtable .= " left join (select reviewId, count(rating) as nrate_$k from ReviewRating where rating$v$noratings group by reviewId) as Ratings_$k on (Ratings_$k.reviewId=r.reviewId)";
        $where[] = $rate;
    }


    // QUERY EVALUATION
    // Check the results of the query, reducing the possibly conservative
    // overestimate produced by the database to a precise result.

    private function word_count_for(PaperInfo $row, $reviewId) {
        if ($this->_reviewWordCounts === false)
            $this->_reviewWordCounts = Dbl::fetch_iimap($this->conf->qe("select reviewId, reviewWordCount from PaperReview"));
        if (!isset($this->_reviewWordCounts[$reviewId])) {
            $cid2rid = $row->all_review_ids();
            foreach ($row->all_review_word_counts($row) as $cid => $rwc)
                $this->_reviewWordCounts[$cid2rid[$cid]] = $rwc;
        }
        return $this->_reviewWordCounts[$reviewId];
    }

    private function _add_deleted_papers($qe) {
        if ($qe->type === "or" || $qe->type === "then") {
            foreach ($qe->value as $subt)
                $this->_add_deleted_papers($subt);
        } else if ($qe->type === "pn") {
            foreach ($qe->value[0] as $p)
                if (array_search($p, $this->_matches) === false)
                    $this->_matches[] = (int) $p;
        }
    }


    // BASIC QUERY FUNCTION

    private function _add_sorters($qe, $thenmap) {
        foreach ($qe->get_float("sort", []) as $s)
            if (($s = ListSorter::parse_sorter($s))) {
                $s->thenmap = $thenmap;
                $this->sorters[] = $s;
            }
        if (!$qe->get_float("sort") && $qe->type === "pn") {
            $pn = array_diff($qe->value[0], $qe->value[1]);
            $s = ListSorter::make_field(new NumericOrderPaperColumn(array_flip($pn)));
            $s->thenmap = $thenmap;
            $this->sorters[] = $s;
        }
    }

    private function _check_sort_order_anno($sorters) {
        $thetag = null;
        $tagger = new Tagger($this->user);
        foreach ($sorters as $sorter) {
            if (!preg_match('/\A(?:#|tag:\s*|tagval:\s*)(\S+)\z/', $sorter, $m)
                || !($tag = $tagger->check($m[1]))
                || ($thetag !== null && $tag !== $thetag))
                return false;
            $thetag = $tag;
        }
        return $thetag;
    }

    private function _assign_order_anno_group($g, $order_anno_tag, $anno_index) {
        if (($e = $order_anno_tag->order_anno_entry($anno_index)))
            $this->groupmap[$g] = $e;
        else if (!isset($this->groupmap[$g]))
            $this->groupmap[$g] = (object) ["tag" => $order_anno_tag->tag, "heading" => "", "annoFormat" => 0];
    }

    private function _assign_order_anno($order_anno_tag, $tag_order) {
        $this->thenmap = [];
        $this->_assign_order_anno_group(0, $order_anno_tag, -1);
        $this->groupmap[0]->heading = "none";
        $cur_then = $aidx = $tidx = 0;
        $alist = $order_anno_tag->order_anno_list();
        usort($tag_order, "TagInfo::id_index_compar");
        while ($aidx < count($alist) || $tidx < count($tag_order)) {
            if ($tidx == count($tag_order)
                || ($aidx < count($alist) && $alist[$aidx]->tagIndex <= $tag_order[$tidx][1])) {
                if ($cur_then != 0 || $tidx != 0 || $aidx != 0)
                    ++$cur_then;
                $this->_assign_order_anno_group($cur_then, $order_anno_tag, $aidx);
                ++$aidx;
            } else {
                $this->thenmap[$tag_order[$tidx][0]] = $cur_then;
                ++$tidx;
            }
        }
    }

    function _search() {
        if ($this->_matches === false)
            return false;
        assert($this->_matches === null);

        if ($this->limitName === "x") {
            $this->_matches = array();
            return true;
        }

        // parse and clean the query
        self::$current_search = $this;
        $qe = $this->_searchQueryType($this->q);
        //Conf::msg_info(Ht::pre_text(var_export($qe, true)));
        if (!$qe)
            $qe = new SearchTerm("t");

        // apply complex limiters (only current example: "acc" for non-chairs)
        $limit = $this->limitName;
        if ($limit === "acc" && !$this->privChair)
            $qe = SearchTerm::make_op("and", [$qe, $this->_searchQueryWord("dec:yes", false)]);

        // apply review rounds (top down, needs separate step)
        if ($this->reviewAdjust) {
            $qe = $this->_query_adjust_reviews($qe, null);
            if ($this->_reviewAdjustError)
                $this->warn("Unexpected use of “round:” or “rate:” ignored.  Stick to the basics, such as “re:reviewername round:roundname”.");
        }
        self::$current_search = null;

        //Conf::msg_info(Ht::pre_text(var_export($qe, true)));

        // collect clauses into tables, columns, and filters
        $sqi = new SearchQueryInfo($this);
        $sqi->add_table("Paper");
        $sqi->add_column("paperId", "Paper.paperId");
        // always include columns needed by rights machinery
        $sqi->add_column("timeSubmitted", "Paper.timeSubmitted");
        $sqi->add_column("timeWithdrawn", "Paper.timeWithdrawn");
        $sqi->add_column("outcome", "Paper.outcome");
        $filters = array();
        $filters[] = $qe->sqlexpr($sqi);
        //Conf::msg_info(Ht::pre_text(var_export($filters, true)));

        // status limitation parts
        if ($limit === "editpref")
            $limit = "rable";
        if ($limit === "rable") {
            $limitcontact = $this->reviewer_user();
            if ($limitcontact->can_accept_review_assignment_ignore_conflict(null))
                $limit = $this->conf->can_pc_see_all_submissions() ? "act" : "s";
            else if (!$limitcontact->isPC)
                $limit = "r";
        }
        if ($limit === "s" || $limit === "req"
            || $limit === "acc" || $limit === "und"
            || $limit === "unm"
            || ($limit === "rable" && !$this->conf->can_pc_see_all_submissions()))
            $filters[] = "Paper.timeSubmitted>0";
        else if ($limit === "act" || $limit === "r" || $limit === "rable")
            $filters[] = "Paper.timeWithdrawn<=0";
        else if ($limit === "unsub")
            $filters[] = "(Paper.timeSubmitted<=0 and Paper.timeWithdrawn<=0)";
        else if ($limit === "lead")
            $filters[] = "Paper.leadContactId=" . $this->cid;
        else if ($limit === "manager") {
            if ($this->user->is_track_manager())
                $filters[] = "(Paper.managerContactId=" . $this->cid . " or Paper.managerContactId=0)";
            else
                $filters[] = "Paper.managerContactId=" . $this->cid;
            $filters[] = "Paper.timeSubmitted>0";
            $sqi->needflags |= self::F_MANAGER;
        }

        // decision limitation parts
        if ($limit === "acc")
            $filters[] = "Paper.outcome>0";
        else if ($limit === "und")
            $filters[] = "Paper.outcome=0";

        // other search limiters
        if ($limit === "a") {
            $filters[] = $this->user->actAuthorSql("PaperConflict");
            $sqi->needflags |= self::F_AUTHOR;
        } else if ($limit === "r") {
            $filters[] = "MyReview.reviewType is not null";
            $sqi->needflags |= self::F_REVIEWER;
        } else if ($limit === "ar") {
            $filters[] = "(" . $this->user->actAuthorSql("PaperConflict") . " or (Paper.timeWithdrawn<=0 and MyReview.reviewType is not null))";
            $sqi->needflags |= self::F_AUTHOR | self::F_REVIEWER;
        } else if ($limit === "rout") {
            $filters[] = "MyReview.reviewNeedsSubmit!=0";
            $sqi->needflags |= self::F_REVIEWER;
        } else if ($limit === "revs")
            $sqi->add_table("Limiter", array("join", "PaperReview"));
        else if ($limit === "req")
            $sqi->add_table("Limiter", array("join", "PaperReview", "Limiter.requestedBy=$this->cid and Limiter.reviewType=" . REVIEW_EXTERNAL));
        else if ($limit === "unm")
            $filters[] = "Paper.managerContactId=0";

        // add common tables: conflicts, my own review, paper blindness
        if ($sqi->needflags & (self::F_MANAGER | self::F_NONCONFLICT | self::F_AUTHOR))
            $sqi->add_conflict_columns();
        if ($sqi->needflags & self::F_REVIEWER)
            $sqi->add_reviewer_columns();

        // check for annotated order
        $order_anno_tag = $sole_qe = null;
        if ($qe->type !== "then")
            $sole_qe = $qe;
        else if ($qe->nthen == 1)
            $sole_qe = $qe->value[0];
        if ($sole_qe
            && ($sort = $sole_qe->get_float("sort"))
            && ($tag = self::_check_sort_order_anno($sort))) {
            $dt = $this->conf->tags()->add(TagInfo::base($tag));
            if (count($dt->order_anno_list()))
                $order_anno_tag = $dt;
        }

        // add permissions tables if we will filter the results
        $need_filter = (($sqi->needflags & self::F_XVIEW)
                        || $this->conf->has_tracks()
                        || $qe->type === "then"
                        || $qe->get_float("heading")
                        || $limit === "rable"
                        || $order_anno_tag);
        if ($need_filter) {
            $sqi->add_rights_columns();
            if ($this->conf->submission_blindness() == Conf::BLIND_OPTIONAL)
                $sqi->add_column("paperBlind", "Paper.blind");
        }

        // XXX some of this should be shared with paperQuery
        if (($need_filter && $this->conf->has_track_tags())
            || get($this->_query_options, "tags")
            || $order_anno_tag
            || ($this->user->privChair
                && $this->conf->has_any_manager()
                && $this->conf->tags()->has_sitewide))
            $sqi->add_column("paperTags", "(select group_concat(' ', tag, '#', tagIndex separator '') from PaperTag where PaperTag.paperId=Paper.paperId)");
        if (get($this->_query_options, "scores")
            || get($this->_query_options, "reviewTypes")
            || get($this->_query_options, "reviewContactIds")) {
            $j = "group_concat(contactId order by reviewId) reviewContactIds";
            $sqi->add_column("reviewContactIds", "R_submitted.reviewContactIds");
            if (get($this->_query_options, "reviewTypes")) {
                $j .= ", group_concat(reviewType order by reviewId) reviewTypes";
                $sqi->add_column("reviewTypes", "R_submitted.reviewTypes");
            }
            foreach (get($this->_query_options, "scores") ? : array() as $f) {
                $j .= ", group_concat($f order by reviewId) {$f}Scores";
                $sqi->add_column("{$f}Scores", "R_submitted.{$f}Scores");
            }
            $sqi->add_table("R_submitted", array("left join", "(select paperId, $j from PaperReview where reviewSubmitted>0 group by paperId)"));
        }

        // create query
        $q = "select ";
        foreach ($sqi->columns as $colname => $value)
            $q .= $value . " " . $colname . ", ";
        $q = substr($q, 0, strlen($q) - 2) . "\n    from ";
        foreach ($sqi->tables as $tabname => $value)
            if (!$value)
                $q .= $tabname;
            else {
                $joiners = array("$tabname.paperId=Paper.paperId");
                for ($i = 2; $i < count($value); ++$i)
                    if ($value[$i])
                        $joiners[] = "(" . $value[$i] . ")";
                $q .= "\n    " . $value[0] . " " . $value[1] . " as " . $tabname
                    . " on (" . join("\n        and ", $joiners) . ")";
            }
        if (count($filters))
            $q .= "\n    where " . join("\n        and ", $filters);
        $q .= "\n    group by Paper.paperId";

        //Conf::msg_info(Ht::pre_text_wrap($q));

        // actually perform query
        $result = $this->conf->qe_raw($q);
        if (!$result)
            return ($this->_matches = false);
        $this->_matches = array();

        // correct query, create thenmap, groupmap, highlightmap
        $need_then = $qe->type === "then";
        $this->thenmap = null;
        if ($need_then && $qe->nthen > 1)
            $this->thenmap = array();
        $this->highlightmap = array();
        if ($need_filter) {
            $tag_order = [];
            while (($row = PaperInfo::fetch($result, $this->user))) {
                if (!$this->user->can_view_paper($row)
                    || ($limit === "rable"
                        && !$limitcontact->can_accept_review_assignment_ignore_conflict($row))
                    || ($limit === "manager"
                        && !$this->user->can_administer($row, true)))
                    $x = false;
                else if ($need_then) {
                    $x = false;
                    for ($i = 0; $i < $qe->nthen && $x === false; ++$i)
                        if ($qe->value[$i]->exec($row, $this))
                            $x = $i;
                } else
                    $x = !!$qe->exec($row, $this);
                if ($x === false)
                    continue;
                $this->_matches[] = $row->paperId;
                if ($this->thenmap !== null)
                    $this->thenmap[$row->paperId] = $x;
                if ($need_then) {
                    for ($j = $qe->nthen; $j < count($qe->value); ++$j)
                        if ($qe->value[$j]->exec($row, $this)
                            && ($qe->highlights[$j - $qe->nthen] & (1 << $x)))
                            $this->highlightmap[$row->paperId][] = $qe->highlight_types[$j - $qe->nthen];
                }
                if ($order_anno_tag) {
                    if ($row->has_viewable_tag($order_anno_tag->tag, $this->user, true))
                        $tag_order[] = [$row->paperId, $row->tag_value($order_anno_tag->tag)];
                    else
                        $tag_order[] = [$row->paperId, TAG_INDEXBOUND];
                }
            }
        } else
            while (($row = $result->fetch_object()))
                $this->_matches[] = (int) $row->paperId;
        Dbl::free($result);

        // add deleted papers explicitly listed by number (e.g. action log)
        if ($this->_allow_deleted)
            $this->_add_deleted_papers($qe);

        // view and sort information
        $this->viewmap = $qe->get_float("view", array());
        $this->sorters = [];
        $this->_add_sorters($qe, null);
        if ($qe->type === "then")
            for ($i = 0; $i < $qe->nthen; ++$i)
                $this->_add_sorters($qe->value[$i], $this->thenmap ? $i : null);
        $this->groupmap = [];
        if (!$sole_qe) {
            for ($i = 0; $i < $qe->nthen; ++$i) {
                $h = $qe->value[$i]->get_float("heading");
                if ($h === null) {
                    $span = $qe->value[$i]->get_float("strspan");
                    $h = substr($this->q, $span[0], $span[1] - $span[0]);
                }
                $this->groupmap[$i] = (object) ["heading" => $h, "annoFormat" => 0];
            }
        } else if (($h = $sole_qe->get_float("heading")))
            $this->groupmap[0] = (object) ["heading" => $h, "annoFormat" => 0];
        else if ($order_anno_tag) {
            $this->_assign_order_anno($order_anno_tag, $tag_order);
            $this->is_order_anno = $order_anno_tag->tag;
        }

        // extract regular expressions and set _reviewer if the query is
        // about exactly one reviewer, and warn about contradictions
        $contradictions = array();
        $this->_queryExtractInfo($qe, true, false, $contradictions);
        foreach ($contradictions as $contradiction => $garbage)
            $this->warn($contradiction);

        // set $this->matchPreg from $this->regex
        if (!$this->overrideMatchPreg) {
            $this->matchPreg = array();
            foreach (array("ti" => "title", "au" => "authorInformation",
                           "ab" => "abstract", "co" => "collaborators")
                     as $k => $v)
                if (isset($this->regex[$k]) && count($this->regex[$k])) {
                    $a = $b = array();
                    foreach ($this->regex[$k] as $x) {
                        $a[] = $x->preg_utf8;
                        if (isset($x->preg_raw))
                            $b[] = $x->preg_raw;
                    }
                    $x = (object) array("preg_utf8" => join("|", $a));
                    if (count($a) == count($b))
                        $x->preg_raw = join("|", $b);
                    $this->matchPreg[$v] = $x;
                }
        }

        return true;
    }

    function complexSearch(&$queryOptions) {
        $limit = $this->limitName;
        if ($limit === "editpref")
            $limit = "rable";
        if (($limit === "s" || $limit === "act") && $this->q === "re:me")
            $limit = "r";
        else if ($this->q !== "")
            return true;
        if ($this->conf->has_tracks()) {
            if (!$this->privChair || $limit === "rable")
                return true;
        }
        if ($limit === "rable") {
            if ($this->reviewer_user()->isPC)
                $limit = $this->conf->can_pc_see_all_submissions() ? "act" : "s";
            else
                $limit = "r";
        }
        if ($limit === "s" || $limit === "revs")
            $queryOptions["finalized"] = 1;
        else if ($limit === "unsub") {
            $queryOptions["unsub"] = 1;
            $queryOptions["active"] = 1;
        } else if ($limit === "acc") {
            if ($this->privChair || $this->conf->can_all_author_view_decision()) {
                $queryOptions["accepted"] = 1;
                $queryOptions["finalized"] = 1;
            } else
                return true;
        } else if ($limit === "und") {
            $queryOptions["undecided"] = 1;
            $queryOptions["finalized"] = 1;
        } else if ($limit === "r")
            $queryOptions["myReviews"] = 1;
        else if ($limit === "rout")
            $queryOptions["myOutstandingReviews"] = 1;
        else if ($limit === "a") {
            // If complex author SQL, always do search the long way
            if ($this->user->actAuthorSql("%", true))
                return true;
            $queryOptions["author"] = 1;
        } else if ($limit === "req" || $limit === "reqrevs")
            $queryOptions["myReviewRequests"] = 1;
        else if ($limit === "act")
            $queryOptions["active"] = 1;
        else if ($limit === "lead")
            $queryOptions["myLead"] = 1;
        else if ($limit === "unm")
            $queryOptions["finalized"] = $queryOptions["unmanaged"] = 1;
        else if ($limit === "all")
            /* no limit */;
        else
            return true; /* don't understand limit */
        return false;
    }

    function alternate_query() {
        if ($this->q !== "" && $this->q[0] !== "#"
            && preg_match('/\A' . TAG_REGEX . '\z/', $this->q)) {
            if ($this->q[0] === "~")
                return "#" . $this->q;
            $result = $this->conf->qe("select paperId from PaperTag where tag=? limit 1", $this->q);
            if (count(Dbl::fetch_first_columns($result)))
                return "#" . $this->q;
        }
        return false;
    }

    function has_sort() {
        return $this->sorters;
    }

    function paperList() {
        if ($this->_matches === null)
            $this->_search();
        return $this->_matches ? : array();
    }

    function url_site_relative_raw($q = null) {
        $url = $this->urlbase;
        if ($q === null)
            $q = $this->q;
        if ($q !== "" || substr($this->urlbase, 0, 6) === "search")
            $url .= (strpos($url, "?") === false ? "?" : "&")
                . "q=" . urlencode($q);
        return $url;
    }

    function reviewer() {
        return $this->_reviewer ? : null;
    }

    function reviewer_user() {
        return $this->_reviewer_fixed ? $this->reviewer() : $this->user;
    }

    private function _tag_description() {
        if ($this->q === "")
            return false;
        else if (strlen($this->q) <= 24)
            return htmlspecialchars($this->q);
        else if (!preg_match(',\A(#|-#|tag:|-tag:|notag:|order:|rorder:)(.*)\z,', $this->q, $m))
            return false;
        $tagger = new Tagger($this->user);
        if (!$tagger->check($m[2]))
            return false;
        else if ($m[1] === "-tag:")
            return "no" . substr($this->q, 1);
        else
            return $this->q;
    }

    function description($listname) {
        if (!$listname) {
            $a = array("s" => "Submitted papers", "acc" => "Accepted papers",
                       "act" => "Active papers", "all" => "All papers",
                       "r" => "Your reviews", "a" => "Your submissions",
                       "rout" => "Your incomplete reviews",
                       "req" => "Your review requests",
                       "reqrevs" => "Your review requests",
                       "rable" => "Reviewable papers",
                       "editpref" => "Reviewable papers");
            if (isset($a[$this->limitName]))
                $listname = $a[$this->limitName];
            else
                $listname = "Papers";
        }
        $listname = $this->conf->_($listname);
        if ($this->q === "")
            return $listname;
        if (($td = $this->_tag_description())) {
            if ($listname === "Submitted papers") {
                if ($this->q === "re:me")
                    return $this->conf->_("Your reviews");
                else
                    return $td;
            } else
                return "$td in $listname";
        } else {
            $listname = preg_replace("/s\\z/", "", $listname);
            return "$listname search";
        }
    }

    function listId($sort = "") {
        return "p/" . $this->limitName . "/" . urlencode($this->q)
            . "/" . ($sort ? $sort : "");
    }

    function create_session_list_object($ids, $listname, $sort = "") {
        $l = SessionList::create($this->listId($sort), $ids,
                                 $this->description($listname),
                                 $this->url_site_relative_raw());
        if ($this->matchPreg)
            $l->matchPreg = $this->matchPreg;
        return $l;
    }

    function session_list_object($sort = null) {
        return $this->create_session_list_object($this->paperList(),
                                                 null, $sort);
    }

    function highlight_tags() {
        if ($this->_highlight_tags === null) {
            $this->_highlight_tags = array();
            foreach ($this->sorters ? : array() as $s)
                if ($s->type[0] === "#")
                    $this->_highlight_tags[] = substr($s->type, 1);
        }
        return $this->_highlight_tags;
    }


    static function search_types($user, $reqtype = null) {
        $tOpt = [];
        if ($user->isPC) {
            if ($user->conf->can_pc_see_all_submissions())
                $tOpt["act"] = "Active papers";
            $tOpt["s"] = "Submitted papers";
            if ($user->conf->timePCViewDecision(false) && $user->conf->has_any_accepts())
                $tOpt["acc"] = "Accepted papers";
        }
        if ($user->privChair) {
            $tOpt["all"] = "All papers";
            if (!$user->conf->can_pc_see_all_submissions() && $reqtype === "act")
                $tOpt["act"] = "Active papers";
        }
        if ($user->is_reviewer())
            $tOpt["r"] = "Your reviews";
        if ($user->has_outstanding_review()
            || ($user->is_reviewer() && $reqtype === "rout"))
            $tOpt["rout"] = "Your incomplete reviews";
        if ($user->isPC) {
            if ($user->is_requester() || $reqtype === "req")
                $tOpt["req"] = "Your review requests";
            if (($user->conf->has_any_lead_or_shepherd() && $user->is_discussion_lead())
                || $reqtype === "lead")
                $tOpt["lead"] = "Your discussion leads";
            if (($user->privChair ? $user->conf->has_any_manager() : $user->is_manager())
                || $reqtype === "manager")
                $tOpt["manager"] = "Papers you administer";
        }
        if ($user->is_author() || $reqtype === "a")
            $tOpt["a"] = "Your submissions";
        foreach ($tOpt as &$itext)
            $itext = $user->conf->_c("search_type", $itext);
        return $tOpt;
    }

    static function manager_search_types($user) {
        if ($user->privChair) {
            if ($user->conf->has_any_manager())
                $tOpt = array("manager" => "Papers you administer",
                              "unm" => "Unmanaged submissions",
                              "s" => "All submissions");
            else
                $tOpt = array("s" => "Submitted papers");
            $tOpt["acc"] = "Accepted papers";
            $tOpt["und"] = "Undecided papers";
            $tOpt["all"] = "All papers";
        } else
            $tOpt = array("manager" => "Papers you administer");
        foreach ($tOpt as &$itext)
            $itext = $user->conf->_c("search_type", $itext);
        return $tOpt;
    }

    static function searchTypeSelector($tOpt, $type, $tabindex) {
        if (count($tOpt) > 1) {
            $sel_opt = array();
            foreach ($tOpt as $k => $v) {
                if (count($sel_opt) && $k === "a")
                    $sel_opt["xxxa"] = null;
                if (count($sel_opt) > 2 && ($k === "lead" || $k === "r") && !isset($sel_opt["xxxa"]))
                    $sel_opt["xxxb"] = null;
                $sel_opt[$k] = $v;
            }
            $sel_extra = array();
            if ($tabindex)
                $sel_extra["tabindex"] = 1;
            return Ht::select("t", $sel_opt, $type, $sel_extra);
        } else
            return current($tOpt);
    }

    private static function simple_search_completion($prefix, $map, $flags = 0) {
        $x = array();
        foreach ($map as $id => $str) {
            $match = null;
            foreach (preg_split(',[^a-z0-9_]+,', strtolower($str)) as $word)
                if ($word !== ""
                    && ($m = Text::simple_search($word, $map, $flags))
                    && isset($m[$id]) && count($m) == 1
                    && !Text::is_boring_word($word)) {
                    $match = $word;
                    break;
                }
            $x[] = $prefix . ($match ? : "\"$str\"");
        }
        return $x;
    }

    function search_completion($category = "") {
        $res = array();

        if ($this->amPC && (!$category || $category === "ss")) {
            foreach ($this->conf->saved_searches() as $k => $v)
                $res[] = "ss:" . $k;
        }

        array_push($res, "has:submission", "has:abstract");
        if ($this->amPC && $this->conf->has_any_manager())
            $res[] = "has:admin";
        if ($this->amPC && $this->conf->has_any_lead_or_shepherd())
            $res[] = "has:lead";
        if ($this->user->can_view_some_decision()) {
            $res[] = "has:decision";
            if (!$category || $category === "dec") {
                $res[] = array("pri" => -1, "nosort" => true, "i" => array("dec:any", "dec:none", "dec:yes", "dec:no"));
                $dm = $this->conf->decision_map();
                unset($dm[0]);
                $res = array_merge($res, self::simple_search_completion("dec:", $dm, Text::SEARCH_UNPRIVILEGE_EXACT));
            }
            if ($this->conf->setting("final_open"))
                $res[] = "has:final";
        }
        if ($this->amPC || $this->user->can_view_some_decision())
            $res[] = "has:shepherd";
        if ($this->user->can_view_some_review())
            array_push($res, "has:review", "has:creview", "has:ireview", "has:preview", "has:external", "has:comment", "has:aucomment");
        if ($this->user->is_reviewer())
            array_push($res, "has:primary", "has:secondary", "has:external");
        if ($this->amPC && $this->conf->setting("extrev_approve") && $this->conf->setting("pcrev_editdelegate")
            && $this->user->is_requester())
            array_push($res, "has:approvable");
        foreach ($this->conf->resp_round_list() as $i => $rname) {
            if (!in_array("has:response", $res))
                $res[] = "has:response";
            if ($i)
                $res[] = "has:{$rname}response";
        }
        if ($this->user->can_view_some_draft_response())
            foreach ($this->conf->resp_round_list() as $i => $rname) {
                if (!in_array("has:draftresponse", $res))
                    $res[] = "has:draftresponse";
                if ($i)
                    $res[] = "has:draft{$rname}response";
            }
        if ($this->user->can_view_tags()) {
            array_push($res, "has:color", "has:style");
            if ($this->conf->tags()->has_badges)
                $res[] = "has:badge";
        }
        foreach ($this->user->user_option_list() as $o)
            if ($this->user->can_view_some_paper_option($o))
                $o->add_search_completion($res);
        if ($this->user->is_reviewer() && $this->conf->has_rounds()
            && (!$category || $category === "round")) {
            $res[] = array("pri" => -1, "nosort" => true, "i" => array("round:any", "round:none"));
            $rlist = array();
            foreach ($this->conf->round_list() as $rnum => $round)
                if ($rnum && $round !== ";")
                    $rlist[$rnum] = $round;
            $res = array_merge($res, self::simple_search_completion("round:", $rlist));
        }
        if ($this->conf->has_topics() && (!$category || $category === "topic")) {
            foreach ($this->conf->topic_map() as $tname)
                $res[] = "topic:\"{$tname}\"";
        }
        if (!$category || $category === "style") {
            $res[] = array("pri" => -1, "nosort" => true, "i" => array("style:any", "style:none", "color:any", "color:none"));
            foreach (explode("|", TagInfo::BASIC_COLORS) as $t)
                array_push($res, "style:$t", "color:$t");
        }
        if (!$category || $category === "show" || $category === "hide") {
            $cats = array();
            $pl = new PaperList($this);
            foreach (PaperColumn::lookup_all() as $c)
                if (($cat = $c->completion_name())
                    && $c->prepare($pl, PaperColumn::PREP_COMPLETION))
                    $cats[$cat] = true;
            foreach (PaperColumn::lookup_all_factories() as $f) {
                foreach ($f[1]->completion_instances($this->user) as $c)
                    if (($cat = $c->completion_name())
                        && (!($c instanceof PaperColumn)
                            || $c->prepare($pl, PaperColumn::PREP_COMPLETION)))
                        $cats[$cat] = true;
            }
            foreach (array_keys($cats) as $cat)
                array_push($res, "show:$cat", "hide:$cat");
            array_push($res, "show:compact", "show:statistics", "show:rownumbers");
        }

        return $res;
    }
}
