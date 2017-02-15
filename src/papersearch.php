<?php
// papersearch.php -- HotCRP helper class for searching for papers
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SearchOperator {
    public $op;
    public $unary;
    public $precedence;
    public $opinfo;

    static private $list = null;

    function __construct($what, $unary, $precedence, $opinfo = null) {
        $this->op = $what;
        $this->unary = $unary;
        $this->precedence = $precedence;
        $this->opinfo = $opinfo;
    }

    static function get($name) {
        if (!self::$list) {
            self::$list["("] = new SearchOperator("(", true, null);
            self::$list["NOT"] = new SearchOperator("not", true, 7);
            self::$list["-"] = new SearchOperator("not", true, 7);
            self::$list["!"] = new SearchOperator("not", true, 7);
            self::$list["+"] = new SearchOperator("+", true, 7);
            self::$list["SPACE"] = new SearchOperator("space", false, 6);
            self::$list["AND"] = new SearchOperator("and", false, 5);
            self::$list["OR"] = new SearchOperator("or", false, 4);
            self::$list["XAND"] = new SearchOperator("space", false, 3);
            self::$list["XOR"] = new SearchOperator("or", false, 3);
            self::$list["THEN"] = new SearchOperator("then", false, 2);
            self::$list["HIGHLIGHT"] = new SearchOperator("highlight", false, 1, "");
        }
        return get(self::$list, $name);
    }
}

class SearchWord {
    public $qword;
    public $word;
    public $quoted;
    public $keyword;
    public $kwexplicit;
    public $kwdef;
    function __construct($qword) {
        $this->qword = $this->word = $qword;
        $this->quoted = $qword[0] === "\"";
        if ($this->quoted)
            $this->word = str_replace('*', '\*', preg_replace('/(?:\A"|"\z)/', '', $qword));
    }
}

class SearchTerm {
    public $type;
    public $flags;
    public $float = [];

    function __construct($type, $flags = 0) {
        $this->type = $type;
        $this->flags = $flags;
    }
    static function make_op($op, $terms) {
        $opstr = is_object($op) ? $op->op : $op;
        if ($opstr === "not")
            $qr = new Not_SearchTerm;
        else if ($opstr === "and" || $opstr === "space")
            $qr = new And_SearchTerm($opstr);
        else if ($opstr === "or")
            $qr = new Or_SearchTerm;
        else
            $qr = new Then_SearchTerm($op);
        foreach (is_array($terms) ? $terms : [$terms] as $qt)
            $qr->append($qt);
        return $qr->finish();
    }
    static function make_not(SearchTerm $term) {
        $qr = new Not_SearchTerm;
        return $qr->append($term)->finish();
    }
    function negate_if($negate) {
        return $negate ? self::make_not($this) : $this;
    }
    static function make_float($float) {
        $qe = new True_SearchTerm;
        $qe->float = $float;
        return $qe;
    }

    function is_false() {
        return false;
    }
    function is_true() {
        return false;
    }
    function is_uninteresting() {
        return false;
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


    function export_json() {
        return $this->type;
    }


    // apply rounds to reviewer searches
    function adjust_reviews(ReviewAdjustment_SearchTerm $revadj = null, PaperSearch $srch) {
        if ($this->get_float("used_revadj") && $revadj)
            $revadj->used_revadj = true;
        return $this;
    }


    function trivial_rights(Contact $user, PaperSearch $srch) {
        return false;
    }


    protected function _set_flags(&$q, SearchQueryInfo $sqi) {
        $flags = $this->flags;
        $sqi->needflags |= $flags;

        if ($flags & PaperSearch::F_MANAGER) {
            if ($sqi->user->privChair && $sqi->conf->has_any_manager())
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
    }

    static function andjoin_sqlexpr($q, $default = "false") {
        return empty($q) ? $default : "(" . join(" and ", $q) . ")";
    }
    static function orjoin_sqlexpr($q, $default = "false") {
        return empty($q) ? $default : "(" . join(" or ", $q) . ")";
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        assert(false);
        return "false";
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
        return true;
    }

    function exec(PaperInfo $row, PaperSearch $srch) {
        assert(false);
        return false;
    }


    function extract_metadata($top, PaperSearch $srch) {
        if ($top && ($x = $this->get_float("contradiction_warning")))
            $srch->contradictions[$x] = true;
    }
}

class False_SearchTerm extends SearchTerm {
    function __construct() {
        parent::__construct("f");
    }
    function is_false() {
        return true;
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        return true;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        return "false";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return false;
    }
}

class True_SearchTerm extends SearchTerm {
    function __construct() {
        parent::__construct("t");
    }
    function is_true() {
        return true;
    }
    function is_uninteresting() {
        return count($this->float) === 1 && isset($this->float["view"]);
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        return true;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        return "true";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return true;
    }
}

class Op_SearchTerm extends SearchTerm {
    public $child = [];

    function __construct($type) {
        parent::__construct($type);
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
            $this->child[] = $term;
        }
        return $this;
    }
    protected function finish() {
        assert(false);
    }
    protected function _flatten_children() {
        $qvs = array();
        foreach ($this->child ? : array() as $qv)
            if ($qv->type === $this->type)
                $qvs = array_merge($qvs, $qv->child);
            else
                $qvs[] = $qv;
        return $qvs;
    }
    protected function _finish_combine($newchild, $any) {
        $qr = null;
        if (!$newchild)
            $qr = $any ? new True_SearchTerm : new False_SearchTerm;
        else if (count($newchild) == 1)
            $qr = clone $newchild[0];
        if ($qr) {
            $qr->float = $this->float;
            return $qr;
        } else {
            $this->child = $newchild;
            return $this;
        }
    }

    function export_json() {
        $a = [$this->type];
        foreach ($this->child as $qv)
            $a[] = $qv->export_json();
        return $a;
    }
    function adjust_reviews(ReviewAdjustment_SearchTerm $revadj = null, PaperSearch $srch) {
        foreach ($this->child as &$qv)
            $qv = $qv->adjust_reviews($revadj, $srch);
        return $this;
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        foreach ($this->child as $ch)
            if (!$ch->trivial_rights($user, $srch))
                return false;
        return true;
    }
}

class Not_SearchTerm extends Op_SearchTerm {
    function __construct() {
        parent::__construct("not");
    }
    protected function finish() {
        $qv = $this->child ? $this->child[0] : null;
        $qr = null;
        if (!$qv || $qv->is_false())
            $qr = new True_SearchTerm;
        else if ($qv->is_true())
            $qr = new False_SearchTerm;
        else if ($qv->type === "not")
            $qr = clone $qv->child[0];
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
        $ff = $this->child[0]->sqlexpr($sqi);
        if ($sqi->negated && !$this->child[0]->trivial_rights($sqi->user, $sqi->srch))
            $ff = "false";
        $sqi->negated = !$sqi->negated;
        return "not ($ff)";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return !$this->child[0]->exec($row, $srch);
    }
}

class And_SearchTerm extends Op_SearchTerm {
    function __construct($type) {
        parent::__construct($type);
    }
    protected function finish() {
        $pn = $revadj = null;
        $newchild = [];
        $any = false;
        foreach ($this->_flatten_children() as $qv) {
            if ($qv->is_false()) {
                $qr = new False_SearchTerm;
                $qr->float = $this->float;
                return $qr;
            } else if ($qv->is_true())
                $any = true;
            else if ($qv->type === "revadj")
                $revadj = $qv->apply($revadj, false);
            else if ($qv->type === "pn" && $this->type === "space") {
                if (!$pn)
                    $newchild[] = $pn = $qv;
                else
                    $pn->pids = array_merge($pn->pids, $qv->pids);
            } else
                $newchild[] = $qv;
        }
        if ($revadj) // must come first
            array_unshift($newchild, $revadj);
        return $this->_finish_combine($newchild, $any);
    }

    function adjust_reviews(ReviewAdjustment_SearchTerm $revadj = null, PaperSearch $srch) {
        $myrevadj = null;
        if ($this->child[0] instanceof ReviewAdjustment_SearchTerm) {
            $myrevadj = $this->child[0];
            $used_revadj = $myrevadj->merge($revadj);
        }
        foreach ($this->child as &$qv)
            if (!($qv instanceof ReviewAdjustment_SearchTerm))
                $qv = $qv->adjust_reviews($myrevadj ? : $revadj, $srch);
        if ($myrevadj && !$myrevadj->used_revadj) {
            $this->child[0] = $myrevadj->promote($srch);
            if ($used_revadj)
                $revadj->used_revadj = true;
        }
        return $this;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $ff = array();
        foreach ($this->child as $subt)
            $ff[] = $subt->sqlexpr($sqi);
        return self::andjoin_sqlexpr($ff);
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        foreach ($this->child as $subt)
            if (!$subt->exec($row, $srch))
                return false;
        return true;
    }
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        foreach ($this->child as $qv)
            $qv->extract_metadata($top, $srch);
    }
}

class Or_SearchTerm extends Op_SearchTerm {
    function __construct() {
        parent::__construct("or");
    }
    protected function finish() {
        $pn = $revadj = null;
        $newchild = [];
        foreach ($this->_flatten_children() as $qv) {
            if ($qv->is_true())
                return self::make_float($this->float);
            else if ($qv->is_false())
                /* skip */;
            else if ($qv->type === "revadj")
                $revadj = $qv->apply($revadj, true);
            else if ($qv->type === "pn") {
                if (!$pn)
                    $newchild[] = $pn = $qv;
                else
                    $pn->pids = array_merge($pn->pids, $qv->pids);
            } else
                $newchild[] = $qv;
        }
        if ($revadj)
            array_unshift($newchild, $revadj);
        return $this->_finish_combine($newchild, false);
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $ff = array();
        foreach ($this->child as $subt)
            $ff[] = $subt->sqlexpr($sqi);
        return self::orjoin_sqlexpr($ff);
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        foreach ($this->child as $subt)
            if ($subt->exec($row, $srch))
                return true;
        return false;
    }
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        foreach ($this->child as $qv)
            $qv->extract_metadata(false, $srch);
    }
}

class Then_SearchTerm extends Op_SearchTerm {
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

        foreach ($this->child as $qvidx => $qv) {
            if ($qv && $qvidx && $this->is_highlight) {
                if ($qv->type === "then") {
                    for ($i = 0; $i < $qv->nthen; ++$i) {
                        $newhvalues[] = $qv->child[$i];
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
                    $newvalues[] = $qv->child[$i];
                for ($i = $qv->nthen; $i < count($qv->child); ++$i)
                    $newhvalues[] = $qv->child[$i];
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
        $this->child = $newvalues;
        $this->set_float("sort", []);
        return $this;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $ff = array();
        foreach ($this->child as $subt)
            $ff[] = $subt->sqlexpr($sqi);
        return self::orjoin_sqlexpr(array_slice($ff, 0, $this->nthen), "true");
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        for ($i = 0; $i < $this->nthen; ++$i)
            if ($this->child[$i]->exec($row, $srch))
                return true;
        return false;
    }
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        foreach ($this->child as $qv)
            $qv->extract_metadata(false, $srch);
    }
}

class TextMatch_SearchTerm extends SearchTerm {
    private $field;
    private $authorish;
    private $nonempty = false;
    public $regex;
    static public $map = [
        "ti" => "title", "ab" => "abstract", "au" => "authorInformation",
        "co" => "collaborators"
    ];

    function __construct($t, $text) {
        parent::__construct($t);
        $this->field = self::$map[$t];
        $this->authorish = $t === "au" || $t === "co";
        if ($text === true)
            $this->nonempty = true;
        else
            $this->regex = Text::star_text_pregexes($text);
    }
    static function parse($word, SearchWord $sword) {
        if ($word === "any" && $sword->kwexplicit && !$sword->quoted)
            $word = true;
        return new TextMatch_SearchTerm($sword->kwdef->name, $word);
    }

    function trivial_rights(Contact $user, PaperSearch $srch) {
        return $this->nonempty && !$this->authorish;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->needflags |= $this->flags;
        $sqi->add_column($this->field, "Paper.{$this->field}");
        if ($this->nonempty && !$this->authorish)
            return "Paper.{$this->field}!=''";
        return "true";
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
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        if ($this->regex)
            $srch->regex[$this->type][] = $this->regex;
    }
}

class PaperPC_SearchTerm extends SearchTerm {
    private $kind;
    private $fieldname;
    private $match;

    function __construct($kind, $match) {
        parent::__construct("paperpc");
        $this->kind = $kind;
        $this->fieldname = $kind . "ContactId";
        $this->match = $match;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (($word === "any" || $word === "" || $word === "yes") && !$sword->quoted)
            $match = "!=0";
        else if (($word === "none" || $word === "no") && !$sword->quoted)
            $match = "=0";
        else
            $match = $srch->matching_reviewers($word, $sword->quoted, true);
        // XXX what about track admin privilege?
        $qt = [new PaperPC_SearchTerm($sword->kwdef->pcfield, $match)];
        if ($sword->kwdef->pcfield === "manager"
            && $word === "me"
            && !$sword->quoted
            && $srch->user->privChair)
            $qt[] = new PaperPC_SearchTerm($ctype, "=0");
        return $qt;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_column($this->fieldname, "Paper.{$this->fieldname}");
        return "(Paper.{$this->fieldname}" . CountMatcher::sqlexpr_using($this->match) . ")";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $can_view = "can_view_{$this->kind}";
        return $srch->user->$can_view($row, true)
            && CountMatcher::compare_using($row->{$this->fieldname}, $this->match);
    }
}

class Decision_SearchTerm extends SearchTerm {
    private $match;

    function __construct($match) {
        parent::__construct("dec");
        $this->match = $match;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $dec = PaperSearch::matching_decisions($srch->conf, $word, $sword->quoted);
        if (is_array($dec) && empty($dec)) {
            $srch->warn("“" . htmlspecialchars($word) . "” doesn’t match a decision.");
            $dec[] = -10000000;
        }
        return new Decision_SearchTerm($dec);
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_column("outcome", "Paper.outcome");
        return "(Paper.outcome" . CountMatcher::sqlexpr_using($this->match) . ")";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return $srch->user->can_view_decision($row, true)
            && CountMatcher::compare_using($row->outcome, $this->match);
    }
}

class PaperStatus_SearchTerm extends SearchTerm {
    private $match;

    function __construct($match) {
        parent::__construct("pf");
        $this->match = $match;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $fval = PaperSearch::status_field_matcher($srch->conf, $word, $sword->quoted);
        if (is_array($fval[1]) && empty($fval[1])) {
            $srch->warn("“" . htmlspecialchars($word) . "” doesn’t match a decision or status.");
            $fval[1][] = -10000000;
        }
        if ($fval[0] === "outcome")
            return new Decision_SearchTerm($fval[1]);
        else
            return new PaperStatus_SearchTerm($fval);
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        return true;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $q = array();
        for ($i = 0; $i < count($this->match); $i += 2) {
            $sqi->add_column($this->match[$i], "Paper." . $this->match[$i]);
            $q[] = "Paper." . $this->match[$i] . CountMatcher::sqlexpr_using($this->match[$i+1]);
        }
        return self::andjoin_sqlexpr($q);
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        for ($i = 0; $ans && $i < count($this->match); $i += 2) {
            $fieldname = $this->match[$i];
            if (!CountMatcher::compare_using($row->$fieldname, $this->match[$i+1]))
                return false;
        }
        return true;
    }
}

class PaperPDF_SearchTerm extends SearchTerm {
    private $dtype;
    private $fieldname;
    private $present;
    private $format;
    private $format_errf;
    private $cf;

    function __construct($dtype, $present, $format = null, $format_errf = null) {
        parent::__construct("pdf");
        $this->dtype = $dtype;
        $this->fieldname = ($dtype == DTYPE_FINAL ? "finalPaperStorageId" : "paperStorageId");
        $this->present = $present;
        $this->format = $format;
        $this->format_errf = $format_errf;
        if ($this->format !== null)
            $this->cf = new CheckFormat(CheckFormat::RUN_PREFER_NO);
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $dtype = $sword->kwdef->final ? DTYPE_FINAL : DTYPE_SUBMISSION;
        $lword = strtolower($word);
        if ($lword === "any" || $lword === "yes")
            return new PaperPDF_SearchTerm($dtype, true);
        else if ($lword === "none" || $lword === "no")
            return new PaperPDF_SearchTerm($dtype, false);
        $cf = new CheckFormat;
        $errf = $cf->spec_error_kinds($dtype, $srch->conf);
        if (empty($errf)) {
            $srch->warn("“" . htmlspecialchars($sword->keyword . ":" . $word) . "”: Format checking is not enabled.");
            return null;
        } else if ($lword === "good" || $lword === "ok")
            return new PaperPDF_SearchTerm($dtype, true, true);
        else if ($lword === "bad")
            return new PaperPDF_SearchTerm($dtype, true, false);
        else if (in_array($lword, $errf) || $lword === "error")
            return new PaperPDF_SearchTerm($dtype, true, false, $lword);
        else {
            $srch->warn("“" . htmlspecialchars($word) . "” is not a valid error type for format checking.");
            return null;
        }
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        return $this->dtype === DTYPE_SUBMISSION && $this->format === null;
    }
    static function add_columns(SearchQueryInfo $sqi) {
        $sqi->add_column("paperStorageId", "Paper.paperStorageId");
        $sqi->add_column("finalPaperStorageId", "Paper.finalPaperStorageId");
        $sqi->add_column("mimetype", "Paper.mimetype");
        $sqi->add_column("sha1", "Paper.sha1");
        $sqi->add_column("pdfFormatStatus", "Paper.pdfFormatStatus");
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if ($this->format !== null)
            $this->add_columns($sqi);
        else
            $sqi->add_column($this->fieldname, "Paper.{$this->fieldname}");
        return "Paper.{$this->fieldname}" . ($this->present ? ">1" : "<=1");
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        if (($this->dtype === DTYPE_FINAL && !$srch->user->can_view_decision($row, true))
            || ($row->{$this->fieldname} > 1) !== $this->present)
            return false;
        if ($this->format !== null) {
            if (!$srch->user->can_view_pdf($row))
                return false;
            if (($doc = $this->cf->fetch_document($row, $this->dtype)))
                $this->cf->check_document($row, $doc);
            $errf = $doc && !$this->cf->failed ? $this->cf->problem_fields() : ["error"];
            if (empty($errf) !== $this->format
                || ($this->format_errf && !in_array($this->format_errf, $errf)))
                return false;
        }
        return true;
    }
}

class Pages_SearchTerm extends SearchTerm {
    private $cf;
    private $cm;

    function __construct(CountMatcher $cm) {
        parent::__construct("pages");
        $this->cf = new CheckFormat(CheckFormat::RUN_PREFER_NO);
        $this->cm = $cm;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $cm = new CountMatcher($word);
        if ($cm->ok())
            return new Pages_SearchTerm(new CountMatcher($word));
        else {
            $srch->warn("“$keyword:” expects a page number comparison.");
            return null;
        }
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        PaperPDF_SearchTerm::add_columns($sqi);
        return "true";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $dtype = DTYPE_SUBMISSION;
        if ($srch->user->can_view_decision($row, true) && $row->outcome > 0
            && $row->finalPaperStorageId > 1)
            $dtype = DTYPE_FINAL;
        return ($doc = $row->document($dtype))
            && ($np = $doc->npages()) !== null
            && $this->cm->test($np);
    }
}

class ContactAuthor_SearchTerm extends SearchTerm {
    private $csm;

    function __construct($contacts) {
        assert(!empty($contacts));
        parent::__construct("au_cid");
        $this->csm = new ContactCountMatcher(">0", $contacts);
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $cids = null;
        if ($sword->kwexplicit && !$sword->quoted) {
            if (strcasecmp($word, "me") === 0)
                $cids = [$this->cid];
            else if ($srch->user->isPC
                     && (strcasecmp($word, "pc") === 0
                         || (str_starts_with($word, "#")
                             && $srch->conf->pc_tag_exists(substr($word, 1)))))
                $cids = $srch->matching_reviewers($word, false, true);
        }
        if ($cids !== null)
            return new ContactAuthor_SearchTerm($cids);
        else
            return new TextMatch_SearchTerm("au", $word);
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        return $this->csm->has_sole_contact($user->contactId);
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $thistab = "AuthorConflict_" . count($sqi->tables);
        $this->fieldname = "{$thistab}_ct";
        $where = "$thistab.contactId in (" . join(",", $this->csm->contact_set()) . ") and conflictType>=" . CONFLICT_AUTHOR;
        $sqi->add_table($thistab, ["left join", "PaperConflict", $where]);
        $sqi->add_column($this->fieldname, "count($thistab.contactId)");
        return "$thistab.contactId is not null";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return ($row->conflictType >= CONFLICT_AUTHOR
                && $this->csm->test_contact($srch->user->contactId))
            || ($srch->user->can_view_authors($row, true)
                && (int) $row->{$this->fieldname});
    }
}

class Conflict_SearchTerm extends SearchTerm {
    private $csm;
    private $includes_self;

    function __construct($countexpr, $contacts, Contact $user) {
        parent::__construct("conflict");
        $this->csm = new ContactCountMatcher($countexpr, $contacts);
        $this->includes_self = in_array($user->contactId, $contacts);
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $m = PaperSearch::unpack_comparison($word, $sword->quoted);
        if (($qr = PaperSearch::check_tautology($m[1])))
            return $qr;
        else {
            $contacts = $srch->matching_reviewers($m[0], $sword->quoted, $sword->kwdef->pc_only);
            return new Conflict_SearchTerm($m[1], $contacts, $srch->user);
        }
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        return $this->csm->has_sole_contact($user->contactId);
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $thistab = "Conflict_" . count($sqi->tables);
        $this->fieldname = "{$thistab}_ct";
        $where = "$thistab.contactId in (" . join(",", $this->csm->contact_set()) . ")";

        $compar = $this->csm->simplified_nonnegative_countexpr();
        if ($compar !== ">0" && $compar !== "=0") {
            $sqi->add_table($thistab, ["left join", "(select paperId, count(*) ct from PaperConflict $thistab where $where group by paperId)"]);
            $sqi->add_column($this->fieldname, "$thistab.ct");
            return "coalesce($thistab.ct,0)$compar";
        } else {
            $sqi->add_table($thistab, ["left join", "PaperConflict", $where]);
            $sqi->add_column($this->fieldname, "count($thistab.contactId)");
            if ($compar === "=0")
                return "$thistab.contactId is null";
            else
                return "$thistab.contactId is not null";
        }
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return ($row->conflictType > 0
                && $this->csm->test_contact($srch->user->contactId)
                && $this->csm->test(1))
            || ($srch->user->can_view_conflicts($row, true)
                && $this->csm->test((int) $row->{$this->fieldname}));
    }
}

class Revpref_SearchTerm extends SearchTerm {
    private $rpsm;
    private $fieldname;

    function __construct(RevprefSearchMatcher $rpsm, $flags = 0) {
        parent::__construct("revpref", $flags);
        $this->rpsm = $rpsm;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (!$srch->user->isPC) // PC only
            return null;

        $contacts = null;
        if (preg_match('/\A(.*?[^:=<>!])([:=!<>]=?|≠|≤|≥|\z)(.*)\z/s', $word, $m)
            && !ctype_digit($m[1])) {
            $contacts = $srch->matching_reviewers($m[1], $sword->quoted, true);
            $word = ($m[2] === ":" ? $m[3] : $m[2] . $m[3]);
            if ($word === "")
                $word = "!=0";
        }

        if (strcasecmp($word, "any") == 0 || strcasecmp($word, "none") == 0)
            $m = [null, "1", "=", "any", strcasecmp($word, "any") == 0];
        else if (!preg_match(',\A(\d*)\s*([=!<>]=?|≠|≤|≥|)\s*(-?\d*)\s*([xyz]?)\z,i', $word, $m)
                 || ($m[1] === "" && $m[3] === "" && $m[4] === ""))
            return new False_SearchTerm;

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
        if ($srch->user->privChair)
            $qz[] = new Revpref_SearchTerm($value, 0);
        else {
            if ($srch->user->is_manager())
                $qz[] = new Revpref_SearchTerm($value, self::F_MANAGER);
            if ($value->test_contact($srch->cid)) {
                $xvalue = clone $value;
                $xvalue->set_contacts($srch->cid);
                $qz[] = new Revpref_SearchTerm($xvalue, 0);
            }
        }
        if (empty($qz))
            $qz[] = new False_SearchTerm;
        if (strcasecmp($word, "none") == 0)
            $qz = [SearchTerm::make_not(SearchTerm::make_op("or", $qz))];
        return $qz;
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
    private static $recompleteness_map = [
        "c" => "complete", "i" => "incomplete", "p" => "partial"
    ];

    function __construct(ReviewSearchMatcher $rsm, $flags = 0) {
        parent::__construct("re", $flags);
        $this->rsm = $rsm;
    }
    function reviewer_contact_set() {
        return $this->rsm->contact_set();
    }
    static function keyword_factory($keyword, Conf $conf, $kwfj, $m) {
        $c = str_replace("-", "", $m[1]);
        return (object) [
            "name" => $keyword, "parser" => "Review_SearchTerm::parse",
            "retype" => str_replace("-", "", $m[2]),
            "recompleteness" => get(self::$recompleteness_map, $c, $c),
            "has" => ">0"
        ];
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $rsm = new ReviewSearchMatcher(">0");
        if ($sword->kwdef->retype)
            $rsm->apply_review_type($sword->kwdef->retype);
        if ($sword->kwdef->recompleteness)
            $rsm->apply_completeness($sword->kwdef->recompleteness);

        $qword = $sword->qword;
        $quoted = false;
        $contacts = null;
        $wordcount = null;
        $tailre = '(?:\z|:|(?=[=!<>]=?|≠|≤|≥))(.*)\z/s';
        while ($qword !== "") {
            if (preg_match('/\A(.+?)' . $tailre, $qword, $m)
                && ($rsm->apply_review_type($m[1])
                    || $rsm->apply_completeness($m[1])
                    || $rsm->apply_round($m[1], $srch->conf))) {
                $qword = $m[2];
            } else if (preg_match('/\A((?:[=!<>]=?|≠|≤|≥|)\d+|any|none|yes|no)' . $tailre, $qword, $m)) {
                $count = PaperSearch::unpack_comparison($m[1], false);
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

        if (($qr = PaperSearch::check_tautology($rsm->countexpr()))) {
            $qr->set_float("used_revadj", true);
            return $qr;
        }

        $rsm->wordcountexpr = $wordcount;
        if ($wordcount && $rsm->completeness === 0)
            $rsm->apply_completeness("complete");
        if ($contacts) {
            $rsm->set_contacts($srch->matching_reviewers($contacts, $quoted,
                                            $rsm->review_type >= REVIEW_PC));
            if (strcasecmp($contacts, "me") == 0)
                $rsm->tokens = $srch->reviewer_user()->review_tokens();
        }
        return new Review_SearchTerm($rsm);
    }


    function adjust_reviews(ReviewAdjustment_SearchTerm $revadj = null, PaperSearch $srch) {
        if ($revadj) {
            if ($revadj->round !== null && $this->rsm->round === null)
                $this->rsm->round = $revadj->round;
            if ($revadj->rate !== null && $this->rsm->rate === null)
                $this->rsm->rate = $revadj->rate;
            $revadj->used_revadj = true;
        }
        return $this;
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        // tokens OK
        return $this->rsm->rate === null && $this->rsm->has_sole_contact($user->contactId);
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
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        if ($top) {
            $v = $this->reviewer_contact_set();
            $srch->mark_reviewer(count($v) == 1 ? $v[0] : null);
        }
    }
}

class ReviewAdjustment_SearchTerm extends SearchTerm {
    public $conf;
    public $round;
    public $rate;
    public $negated = false;
    public $used_revadj = false;

    function __construct(Conf $conf) {
        parent::__construct("revadj");
        $this->conf = $conf;
    }
    static function make_round(Conf $conf, $round) {
        $qe = new ReviewAdjustment_SearchTerm($conf);
        $qe->round = $round;
        return $qe;
    }
    static function parse_round($word, SearchWord $sword, PaperSearch $srch) {
        $srch->_has_review_adjustment = true;
        if (!$srch->user->isPC)
            return new ReviewAdjustment_SearchTerm($srch->conf);
        else if ($word === "none" && !$sword->quoted)
            return self::make_round($srch->conf, [0]);
        else if ($word === "any" && !$sword->quoted)
            return self::make_round($srch->conf, range(1, count($srch->conf->round_list()) - 1));
        else {
            $x = simplify_whitespace($word);
            $rounds = Text::simple_search($x, $srch->conf->round_list());
            if (empty($rounds)) {
                $srch->warn("“" . htmlspecialchars($x) . "” doesn’t match a review round.");
                return new False_SearchTerm;
            } else
                return self::make_round($srch->conf, array_keys($rounds));
        }
    }
    static function make_rate(Conf $conf, $rate) {
        $qe = new ReviewAdjustment_SearchTerm($conf);
        $qe->rate = $rate;
        return $qe;
    }
    static function parse_rate($word, SearchWord $sword, PaperSearch $srch) {
        $srch->_has_review_adjustment = true;
        if (preg_match('/\A(.+?)\s*(|[=!<>]=?|≠|≤|≥)\s*(\d*)\z/', $word, $m)
            && ($m[3] !== "" || $m[2] === "")
            && $srch->conf->setting("rev_ratings") != REV_RATINGS_NONE) {
            // adjust counts
            if ($m[3] === "") {
                $m[2] = ">";
                $m[3] = "0";
            }
            if ($m[2] === "")
                $m[2] = ($m[3] == 0 ? "=" : ">=");
            else
                $m[2] = CountMatcher::canonical_comparator($m[2]);

            // resolve rating type
            if ($m[1] === "+" || $m[1] === "good") {
                $srch->_interesting_ratings["good"] = ">0";
                $term = "nrate_good";
            } else if ($m[1] === "-" || $m[1] === "bad"
                       || $m[1] === "\xE2\x88\x92" /* unicode MINUS */) {
                $srch->_interesting_ratings["bad"] = "<1";
                $term = "nrate_bad";
            } else if ($m[1] === "any") {
                $srch->_interesting_ratings["any"] = "!=100";
                $term = "nrate_any";
            } else {
                $x = Text::simple_search($m[1], ReviewForm::$rating_types);
                unset($x["n"]); /* don't allow "average" */
                if (empty($x)) {
                    $srch->warn("Unknown rating type “" . htmlspecialchars($m[1]) . "”.");
                    return new False_SearchTerm;
                }
                $type = count($srch->_interesting_ratings);
                $srch->_interesting_ratings[$type] = " in (" . join(",", array_keys($x)) . ")";
                $term = "nrate_$type";
            }

            if ($m[2][0] === "<" || $m[2] === "!="
                || ($m[2] === "=" && $m[3] == 0)
                || ($m[2] === ">=" && $m[3] == 0))
                $term = "coalesce($term,0)";
            return self::make_rate($srch->conf, $term . $m[2] . $m[3]);
        } else {
            if ($srch->conf->setting("rev_ratings") == REV_RATINGS_NONE)
                $srch->warn("Review ratings are disabled.");
            else
                $srch->warn("Bad review rating query “" . htmlspecialchars($word) . "”.");
            return new False_SearchTerm;
        }
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
        return $term->negate_if($this->negated);
    }
    function adjust_reviews(ReviewAdjustment_SearchTerm $revadj = null, PaperSearch $srch) {
        if ($revadj || $this->get_float("used_revadj"))
            return $this;
        else
            return $this->promote($srch);
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

class Show_SearchTerm {
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $word = simplify_whitespace($word);
        $action = $sword->kwdef->showtype;
        if (str_starts_with($word, "-") && !$sword->kwdef->sorting) {
            $action = false;
            $word = substr($word, 1);
        }
        $f = [];
        $viewfield = $word;
        if ($word !== "" && $sword->kwdef->sorting) {
            $f["sort"] = [$word];
            $sort = PaperSearch::parse_sorter($viewfield);
            $viewfield = $sort->type;
        }
        if ($viewfield !== "" && $action !== null)
            $f["view"] = [$viewfield => $action];
        return SearchTerm::make_float($f);
    }
    static function parse_heading($word) {
        return SearchTerm::make_float(["heading" => simplify_whitespace($word)]);
    }
}

class Comment_SearchTerm extends SearchTerm {
    private $csm;
    private $tags;
    private $type_mask = 0;
    private $type_value = 0;
    private $only_author = false;
    private $commentRound;

    function __construct(ContactCountMatcher $csm, $tags, $kwdef) {
        parent::__construct("cmt");
        $this->csm = $csm;
        $this->tags = $tags;
        if (!get($kwdef, "response"))
            $this->type_mask |= COMMENTTYPE_RESPONSE;
        if (!get($kwdef, "comment")) {
            $this->type_mask |= COMMENTTYPE_RESPONSE;
            $this->type_value |= COMMENTTYPE_RESPONSE;
        }
        if (get($kwdef, "draft")) {
            $this->type_mask |= COMMENTTYPE_DRAFT;
            $this->type_value |= COMMENTTYPE_DRAFT;
        }
        $this->only_author = get($kwdef, "only_author");
        $this->commentRound = get($kwdef, "round");
    }
    static function comment_factory($keyword, Conf $conf, $kwfj, $m) {
        $tword = str_replace("-", "", $m[1]);
        return ["name" => $keyword, "parser" => "Comment_SearchTerm::parse",
                "response" => $tword === "any", "comment" => true,
                "round" => null, "draft" => false,
                "only_author" => $tword === "au" || $tword === "author",
                "has" => ">0"];
    }
    static function response_factory($keyword, Conf $conf, $kwfj, $m) {
        $round = $conf->resp_round_number($m[2]);
        if ($round === false || ($m[1] && $m[3]))
            return null;
        return ["name" => $keyword, "parser" => "Comment_SearchTerm::parse",
                "response" => true, "comment" => false,
                "round" => $round, "draft" => ($m[1] || $m[3]),
                "only_author" => false, "has" => ">0"];
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $m = PaperSearch::unpack_comparison($word, $sword->quoted);
        if (($qr = PaperSearch::check_tautology($m[1])))
            return $qr;
        $tags = $contacts = null;
        if (str_starts_with($m[0], "#")
            && !$srch->conf->pc_tag_exists(substr($m[0], 1))) {
            $tags = Tag_SearchTerm::expand(substr($m[0], 1), false, $srch);
            if (empty($tags))
                return new False_SearchTerm;
        } else if ($m[0] !== "")
            $contacts = $srch->matching_reviewers($m[0], $sword->quoted, false);
        $csm = new ContactCountMatcher($m[1], $contacts);
        return new Comment_SearchTerm($csm, $tags, $sword->kwdef);
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if (!isset($sqi->column["commentSkeletonInfo"]))
            $sqi->add_column("commentSkeletonInfo", "(select group_concat(commentId, ';', contactId, ';', commentType, ';', commentRound, ';', coalesce(commentTags,'') separator '|') from PaperComment where paperId=Paper.paperId)");

        $where = [];
        if ($this->type_mask)
            $where[] = "(commentType&{$this->type_mask})={$this->type_value}";
        if ($this->only_author)
            $where[] = "commentType>=" . COMMENTTYPE_AUTHOR;
        if ($this->commentRound)
            $where[] = "commentRound=" . $this->commentRound;
        if ($this->csm->has_contacts())
            $where[] = $this->csm->contact_match_sql("contactId");
        if ($this->tags && $this->tags[0] !== "none")
            $where[] = "commentTags is not null"; // conservative
        $thistab = "Comments_" . count($sqi->tables);
        $sqi->add_table($thistab, ["left join", "(select paperId, count(commentId) count from PaperComment" . ($where ? " where " . join(" and ", $where) : "") . " group by paperId)"]);
        return "coalesce($thistab.count,0)" . $this->csm->conservative_countexpr();
    }
    private function _check_tags(CommentInfo $crow, Contact $user) {
        $tags = $crow->viewable_tags($user, true);
        if ($this->tags[0] === "none")
            return (string) $tags === "";
        else if ($this->tags[0] === "any")
            return (string) $tags !== "";
        else {
            foreach (TagInfo::split_unpack($tags) as $ti)
                if (in_array($ti[0], $this->tags))
                    return true;
            return false;
        }
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $n = 0;
        foreach ($row->viewable_comment_skeletons($srch->user, true) as $crow)
            if ($this->csm->test_contact($crow->contactId)
                && ($crow->commentType & $this->type_mask) == $this->type_value
                && (!$this->only_author || $crow->commentType >= COMMENTTYPE_AUTHOR)
                && (!$this->tags || $this->_check_tags($crow, $srch->user)))
                ++$n;
        return $this->csm->test($n);
    }
}

class Topic_SearchTerm extends SearchTerm {
    private $topics;
    private $fieldname;

    function __construct($topics) {
        parent::__construct("topic");
        $this->topics = $topics;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $value = null;
        $lword = strtolower($word);
        if ($lword === "none" || $lword === "any")
            $value = ($lword === "any");
        else {
            $x = simplify_whitespace($lword);
            $tids = array();
            foreach ($srch->conf->topic_map() as $tid => $tname)
                if (strstr(strtolower($tname), $x) !== false)
                    $tids[] = $tid;
            if (empty($tids))
                $srch->warn("“" . htmlspecialchars($x) . "” does not match any defined paper topic.");
            $value = $tids;
        }
        return new Topic_SearchTerm($value);
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        return true;
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
        return "$thistab.topicId is " . ($this->topics === false ? "null" : "not null");
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return $this->topics !== false
            ? $row->{$this->fieldname} != 0
            : !$row->{$this->fieldname};
    }
}

class PaperID_SearchTerm extends SearchTerm {
    public $pids;

    function __construct($pns) {
        parent::__construct("pn");
        $this->pids = $pns;
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        return true;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if (empty($this->pids))
            return "false";
        else
            return "Paper.paperId in (" . join(",", $this->pids) . ")";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return in_array($row->paperId, $this->pids);
    }
}

class Formula_SearchTerm extends SearchTerm {
    private $formula;
    private $function;
    function __construct(Formula $formula) {
        parent::__construct("formula");
        $this->formula = $formula;
        $this->function = $formula->compile_function();
    }
    static private function read_formula($word, $quoted, $is_graph, PaperSearch $srch) {
        $result = $formula = null;
        if (preg_match('/\A[^(){}\[\]]+\z/', $word) && !$quoted
            && ($result = $srch->conf->qe("select * from Formula where name=?", $word)))
            $formula = Formula::fetch($srch->user, $result);
        Dbl::free($result);
        $formula = $formula ? : new Formula($srch->user, $word, $is_graph);
        if (!$formula->check()) {
            $srch->warn($formula->error_html());
            $formula = null;
        }
        return $formula;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if (($formula = self::read_formula($word, $sword->quoted, false, $srch)))
            return new Formula_SearchTerm($formula);
        return new False_SearchTerm;
    }
    static function parse_graph($word, SearchWord $sword, PaperSearch $srch) {
        if (($formula = self::read_formula($word, $sword->quoted, true, $srch)))
            return SearchTerm::make_float(["view" => ["graph($word)" => true]]);
        return null;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $this->formula->add_query_options($sqi->srch->_query_options);
        return "true";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $formulaf = $this->function;
        return !!$formulaf($row, null, $srch->user);
    }
}


class Option_SearchTerm extends SearchTerm {
    private $om;
    private $fieldname;

    function __construct(OptionMatcher $om) {
        parent::__construct("option");
        $this->om = $om;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $thistab = "Option_" . count($sqi->tables);
        $this->fieldname = $thistab . "_x";
        $tm = $this->om->table_matcher();
        $sqi->add_table($thistab, ["left join", $tm[0]]);
        $sqi->add_column($thistab . "_x", "$thistab.paperId" . $tm[1]);
        return $sqi->columns[$this->fieldname];
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $om = $this->om;
        if (!$srch->user->can_view_paper_option($row, $om->option, true)
            || !$row->{$this->fieldname})
            return false;
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
    }
}

class OptionMatcher {
    public $option;
    public $compar;
    public $value;
    public $kind;

    function __construct($option, $compar, $value = null, $kind = 0) {
        if ($option->type === "checkbox" && $value === null)
            $value = 0;
        assert(($value !== null && !is_array($value)) || $compar === "=" || $compar === "!=");
        assert(!$kind || $value !== null);
        $this->option = $option;
        $this->compar = $compar;
        $this->value = $value;
        $this->kind = $kind;
    }
    function table_matcher() {
        $q = "(select paperId from PaperOption where optionId=" . $this->option->id;
        if (!$this->kind && $this->value !== null) {
            $q .= " and value";
            if (is_array($this->value))
                $q .= " in (" . join(",", $this->value) . ")";
            else
                $q .= ($this->compar === "!=" ? "=" : $this->compar) . $this->value;
        }
        $q .= " group by paperId)";
        if (!$this->kind && $this->compar === ($this->value === null ? "=" : "!="))
            return [$q, " is null"];
        else
            return [$q, ""];
    }
}


class Tag_SearchTerm extends SearchTerm {
    private $tsm;

    function __construct(TagSearchMatcher $tsm) {
        parent::__construct("tag");
        $this->tsm = $tsm;
    }
    static function expand($tagword, $allow_star, PaperSearch $srch) {
        // see also TagAssigner
        $ret = array("");
        $twiddle = strpos($tagword, "~");
        if ($srch->user->privChair
            && $twiddle > 0
            && !ctype_digit(substr($tagword, 0, $twiddle))) {
            $c = substr($tagword, 0, $twiddle);
            $ret = ContactSearch::make_pc($c, $srch->user)->ids;
            if (empty($ret))
                $srch->warn("“#" . htmlspecialchars($tagword) . "” doesn’t match a PC email.");
            else if (!$allow_star && count($ret) > 1) {
                $srch->warn("“#" . htmlspecialchars($tagword) . "” matches more than one PC member.");
                $ret = [];
            }
            $tagword = substr($tagword, $twiddle);
        } else if ($twiddle === 0 && ($tagword === "~" || $tagword[1] !== "~"))
            $ret[0] = $srch->user->contactId;

        $tagger = new Tagger($srch->user);
        $flags = Tagger::NOVALUE;
        if ($allow_star)
            $flags |= Tagger::ALLOWRESERVED | Tagger::ALLOWSTAR;
        if (!$tagger->check("#" . $tagword, $flags)) {
            $srch->warn($tagger->error_html);
            $ret = [];
        }
        foreach ($ret as &$x)
            $x .= $tagword;
        return $ret;
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $negated = $sword->kwdef->negated;
        $revsort = $sword->kwdef->sorting && $sword->kwdef->revsort;
        if (str_starts_with($word, "-")) {
            if ($sword->kwdef->sorting) {
                $revsort = !$revsort;
                $word = substr($word, 1);
            } else if (!$negated) {
                $negated = true;
                $word = substr($word, 1);
            }
        }
        if (str_starts_with($word, "#"))
            $word = substr($word, 1);

        // allow external reviewers to search their own rank tag
        if (!$srch->user->isPC) {
            $ranktag = "~" . $srch->conf->setting_data("tag_rank", "");
            if (!$srch->conf->setting("tag_rank")
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

        $value->tags = self::expand($tagword, !$sword->kwdef->sorting, $srch);
        if (count($value->tags) === 1 && $value->tags[0] === "none") {
            $value->tags[0] = "any";
            $negated = !$negated;
        }

        $term = $value->make_term()->negate_if($negated);
        if (!$negated && $sword->kwdef->sorting && !empty($value->tags))
            $term->set_float("sort", [($revsort ? "-#" : "#") . $value->tags[0]]);
        return $term;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $thistab = "Tag_" . count($sqi->tables);
        $tm_sql = $this->tsm->tagmatch_sql($thistab, $sqi->user);
        if ($tm_sql === false) {
            $thistab = "AnyTag";
            $tdef = ["left join", "PaperTag"];
        } else
            $tdef = ["left join", "PaperTag", $tm_sql];
        $sqi->add_table($thistab, $tdef);
        $sqi->add_column($thistab . "_ct", "count($thistab.tag)");
        return "$thistab.tag is not null";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return $this->tsm->evaluate($srch->user, $row->searchable_tags($srch->user));
    }
}

class Color_SearchTerm {
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $word = strtolower($word);
        $tm = new TagSearchMatcher;
        if ($srch->user->isPC
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
            $tm->tags = $srch->conf->tags()->tags_with_color($f);
        }
        $tm->include_twiddles($srch->user);
        return $tm->make_term()->negate_if($word === "none");
    }
    static function parse_badge($word, SearchWord $sword, PaperSearch $srch) {
        $word = strtolower($word);
        $tm = new TagSearchMatcher;
        if ($srch->user->isPC && $srch->conf->tags()->has_badges) {
            if ($word === "any" || $word === "none")
                $f = function ($t) { return !empty($t->badges); };
            else if (preg_match(',\A(black|' . TagInfo::BASIC_BADGES . ')\z,', $word)
                     && !$sword->quoted) {
                $word = $word === "black" ? "normal" : $word;
                $f = function ($t) use ($word) {
                    return !empty($t->badges) && in_array($word, $t->badges);
                };
            } else if (($tx = $srch->conf->tags()->check_base($word))
                     && $tx->badges)
                $f = function ($t) use ($tx) { return $t === $tx; };
            else
                $f = function ($t) { return false; };
            $tm->tags = array_keys($srch->conf->tags()->filter_by($f));
        }
        $tm->include_twiddles($srch->user);
        return $tm->make_term()->negate_if($word === "none");
    }
    static function parse_emoji($word, SearchWord $sword, PaperSearch $srch) {
        $tm = new TagSearchMatcher;
        if ($srch->user->isPC && $srch->conf->tags()->has_emoji) {
            $xword = $word;
            if (strcasecmp($word, "any") == 0 || strcasecmp($word, "none") == 0) {
                $xword = ":*:";
                $f = function ($t) { return !empty($t->emoji); };
            } else if (preg_match('{\A' . TAG_REGEX_NOTWIDDLE . '\z}', $word)) {
                if (!str_starts_with($xword, ":"))
                    $xword = ":$xword";
                if (!str_ends_with($xword, ":"))
                    $xword = "$xword:";
                $code = get($srch->conf->emoji_code_map(), $xword, false);
                $codes = [];
                if ($code !== false)
                    $codes[] = $code;
                else if (strpos($xword, "*") !== false) {
                    $re = "{\\A" . str_replace("\\*", ".*", preg_quote($xword)) . "\\z}";
                    foreach ($srch->conf->emoji_code_map() as $key => $code)
                        if (preg_match($re, $key))
                            $codes[] = $code;
                }
                $f = function ($t) use ($codes) {
                    return !empty($t->emoji) && array_intersect($codes, $t->emoji);
                };
            } else {
                foreach ($srch->conf->emoji_code_map() as $key => $code)
                    if ($code === $xword)
                        $tm->tags[] = ":$key:";
                $f = function ($t) use ($xword) {
                    return !empty($t->emoji) && in_array($xword, $t->emoji);
                };
            }
            $tm->tags[] = $xword;
            $tm->tags = array_merge($tm->tags, array_keys($srch->conf->tags()->filter_by($f)));
        }
        $tm->include_twiddles($srch->user);
        return $tm->make_term()->negate_if(strcasecmp($word, "none") == 0);
    }
}

class TagSearchMatcher {
    public $tags = [];
    public $index1 = null;
    public $index2 = null;
    private $_re;

    function include_twiddles(Contact $user) {
        $ntags = [];
        foreach ($this->tags as $t) {
            array_push($ntags, $t, "{$user->contactId}~$t");
            if ($user->privChair)
                $ntags[] = "~~$t";
        }
        $this->tags = $ntags;
        return $this;
    }
    function make_term() {
        if (empty($this->tags))
            return new False_SearchTerm;
        else
            return new Tag_SearchTerm($this);
    }
    function tagmatch_sql($table, Contact $user) {
        $x = [];
        foreach ($this->tags as $tm) {
            if (($starpos = strpos($tm, "*")) !== false || $tm === "any")
                return false;
            else
                $x[] = "$table.tag='" . sqlq($tm) . "'";
        }
        $q = "(" . join(" or ", $x) . ")";
        if ($this->index1)
            $q .= " and $table.tagIndex" . $this->index1->countexpr();
        if ($this->index2)
            $q .= " and $table.tagIndex" . $this->index2->countexpr();
        return $q;
    }
    function evaluate(Contact $user, $taglist) {
        if (!$this->_re) {
            $res = [];
            foreach ($this->tags as $tm)
                if (($starpos = strpos($tm, "*")) !== false)
                    $res[] = '(?!.*~)' . str_replace('\\*', '.*', preg_quote($tm));
                else if ($tm === "any" && $user->privChair)
                    $res[] = "(?:{$user->contactId}~.*|~~.*|(?!.*~).*)";
                else if ($tm === "any")
                    $res[] = "(?:{$user->contactId}~.*|(?!.*~).*)";
                else
                    $res[] = preg_quote($tm);
            $this->_re = '/\A(?:' . join("|", $res) . ')\z/i';
        }
        foreach (TagInfo::split_unpack($taglist) as $ti) {
            if (preg_match($this->_re, $ti[0])
                && (!$this->index1 || $this->index1->test($ti[1]))
                && (!$this->index2 || $this->index2->test($ti[1])))
                return true;
        }
        return false;
    }
}

class ContactCountMatcher extends CountMatcher {
    private $_contacts = null;

    function __construct($countexpr, $contacts) {
        parent::__construct($countexpr);
        $this->set_contacts($contacts);
    }
    function contact_set() {
        return $this->_contacts;
    }
    function has_contacts() {
        return $this->_contacts !== null;
    }
    function has_sole_contact($cid) {
        return $this->_contacts !== null && count($this->_contacts) == 1
            && $this->_contacts[0] == $cid;
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
        else if ($word === "draft" || $word === "inprogress" || $word === "in-progress" || $word === "partial")
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
                $name .= "Draft";
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
    public $tables = array();
    public $columns = array();
    public $negated = false;
    public $needflags = 0;

    public function __construct(PaperSearch $srch) {
        $this->conf = $srch->conf;
        $this->srch = $srch;
        $this->user = $srch->user;
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
        // XXX could avoid the following if user is privChair for everything:
        $this->add_conflict_columns();
        $this->add_reviewer_columns();
    }
}

class PaperSearch {
    const F_MANAGER = 0x0001;
    const F_NONCONFLICT = 0x0002;
    const F_AUTHOR = 0x0004;
    const F_REVIEWER = 0x0008;

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
    private $urlbase;
    public $warnings = array();
    private $_quiet_count = 0;

    var $q;

    public $regex = [];
    public $contradictions = [];
    public $overrideMatchPreg = false;
    public $matchPreg;

    private $contact_match = array();
    private $noratings = false;
    public $_query_options = array();
    public $_has_review_adjustment = false;
    public $_interesting_ratings = array();
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

    static private $_keywords = array("option" => "option", "opt" => "option");


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
        if (!$this->_quiet_count)
            $this->warnings[] = $text;
    }


    // PARSING
    // Transforms a search string into an expression object, possibly
    // including "and", "or", and "not" expressions (which point at other
    // expressions).

    static function unpack_comparison($text, $quoted) {
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

    static function check_tautology($compar) {
        if ($compar === "<0")
            return new False_SearchTerm;
        else if ($compar === ">=0")
            return new True_SearchTerm;
        else
            return null;
    }

    private function make_contact_match($type, $text, Contact $user) {
        foreach ($this->contact_match as $i => $cm)
            if ($cm->type === $type && $cm->text === $text && $cm->user === $user)
                return $cm;
        return $this->contact_match[] = new ContactSearch($type, $text, $user);
    }

    function matching_reviewers($word, $quoted, $pc_only) {
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
        if (!empty($scm->ids))
            return $scm->ids;
        else
            return array(-1);
    }

    static function matching_decisions(Conf $conf, $word, $quoted = null) {
        if ($quoted === null && ($quoted = ($word && $word[0] === '"')))
            $word = str_replace('"', '', $word);
        $lword = strtolower($word);
        if (!$quoted) {
            if ($lword === "yes")
                return ">0";
            else if ($lword === "no")
                return "<0";
            else if ($lword === "?" || $lword === "none" || $lword === "unknown")
                return [0];
            else if ($lword === "any")
                return "!=0";
        }
        $flags = $quoted ? Text::SEARCH_ONLY_EXACT : Text::SEARCH_UNPRIVILEGE_EXACT;
        return array_keys(Text::simple_search($word, $conf->decision_map(), $flags));
    }

    static function status_field_matcher(Conf $conf, $word, $quoted = null) {
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

    static function parse_reconflict($word, SearchWord $sword, PaperSearch $srch) {
        $args = array();
        while (preg_match('/\A\s*#?(\d+)(?:-#?(\d+))?\s*,?\s*(.*)\z/s', $word, $m)) {
            $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
            foreach (range($m[1], $m[2]) as $p)
                $args[$p] = true;
            $word = $m[3];
        }
        if ($word !== "" || empty($args)) {
            $srch->warn("The <code>reconflict</code> keyword expects a list of paper numbers.");
            return new False_SearchTerm;
        } else if (!$srch->user->privChair)
            return new False_SearchTerm;
        else {
            $result = $srch->conf->qe("select distinct contactId from PaperReview where paperId in (" . join(", ", array_keys($args)) . ")");
            $contacts = array_map("intval", Dbl::fetch_first_columns($result));
            return new Conflict_SearchTerm(">0", $contacts, $srch->user);
        }
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
                $rsm->set_contacts($this->matching_reviewers($m[1], $quoted, false));
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
                        $t = new False_SearchTerm;
                        $t->set_float("contradiction_warning", "No $f->name_html scores are " . ($m[2] === "=" ? "" : $warnings[$m[2][0]] . (strlen($m[2]) == 1 ? " " : " or equal to ")) . $score . ".");
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
                        $qo[] = new OptionMatcher($o, $ocompar, key($xval));
                    } else if ($ocompar !== "=" && $ocompar !== "!=")
                        $warn[] = "Submission option “" . htmlspecialchars("$oname:$oval") . "” matches multiple values, can’t use " . htmlspecialchars($ocompar) . ".";
                    else
                        $qo[] = new OptionMatcher($o, $ocompar, array_keys($xval));
                    continue;
                }

                if ($oval === "" || $oval === "yes")
                    $qo[] = new OptionMatcher($o, "!=", null);
                else if ($oval === "no")
                    $qo[] = new OptionMatcher($o, "=", null);
                else if ($o->type === "numeric") {
                    if (preg_match('/\A\s*([-+]?\d+)\s*\z/', $oval, $m))
                        $qo[] = new OptionMatcher($o, $ocompar, $m[1]);
                    else
                        $warn[] = "Submission option “" . htmlspecialchars($o->name) . "” takes integer values.";
                } else if ($o->has_attachments()) {
                    if ($oval === "any")
                        $qo[] = new OptionMatcher($o, "!=", null);
                    else if (preg_match('/\A\s*([-+]?\d+)\s*\z/', $oval, $m)) {
                        if (CountMatcher::compare(0, $ocompar, $m[1]))
                            $qo[] = new OptionMatcher($o, "=", null);
                        $qo[] = new OptionMatcher($o, $ocompar, $m[1], "attachment-count");
                    } else
                        $qo[] = new OptionMatcher($o, "~=", $oval, "attachment-name");
                } else
                    continue;
            }
        } else if (($ocompar === "=" || $ocompar === "!=") && $oval === "")
            foreach ($conf->paper_opts->option_list() as $oid => $o)
                if ($o->has_selector()) {
                    foreach (Text::simple_search($oname, $o->selector) as $xval => $text)
                        $qo[] = new OptionMatcher($o, $ocompar, $xval);
                }

        return (object) array("os" => $qo, "warn" => $warn, "negate" => $oname === "none");
    }

    function _search_options($word, &$qt, $report_error) {
        $os = self::analyze_option_search($this->conf, $word);
        foreach ($os->warn as $w)
            $this->warn($w);
        if (empty($os->os)) {
            if ($report_error && empty($os->warn))
                $this->warn("“" . htmlspecialchars($word) . "” doesn’t match a submission option.");
            if ($report_error || !empty($os->warn))
                $qt[] = new False_SearchTerm;
            return false;
        }

        // add expressions
        $qz = array();
        foreach ($os->os as $oq)
            $qz[] = new Option_SearchTerm($oq);
        if ($os->negate)
            $qz = array(SearchTerm::make_not(SearchTerm::make_op("or", $qz)));
        $qt = array_merge($qt, $qz);
        return true;
    }

    static function parse_has($word, SearchWord $sword, PaperSearch $srch) {
        $lword = strtolower($word);
        if (($kwdef = $srch->conf->search_keyword($lword))) {
            if (get($kwdef, "has_parser"))
                $qe = call_user_func($kwdef->has_parser, $word, $sword, $srch);
            else if (get($kwdef, "has")) {
                $sword2 = new SearchWord($kwdef->has);
                $sword2->kwexplicit = true;
                $sword2->keyword = $lword;
                $sword2->kwdef = $kwdef;
                $qe = call_user_func($kwdef->parser, $kwdef->has, $sword2, $srch);
            }
            if ($qe)
                return $qe;
        }

        $original_lword = $lword = strtolower($word);
        $lword = get(self::$_keywords, $lword) ? : $lword;
        $qt = [];
        if (preg_match('/\A[\w-]+\z/', $lword)
            && $srch->_search_options("$lword:yes", $qt, false))
            return $qt;
        else {
            $has = [];
            foreach ($srch->search_completion("has") as $h)
                if (str_starts_with($h, "has:"))
                    $has[] = "“" . htmlspecialchars($h) . "”";
            $srch->warn("Unknown “has:” search. I understand " . commajoin($has) . ".");
            return new False_SearchTerm;
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

    static function parse_sorter($text) {
        if (!self::$_sort_keywords)
            self::$_sort_keywords =
                ["by" => "by", "up" => "up", "down" => "down",
                 "reverse" => "down", "reversed" => "down",
                 "count" => "C", "counts" => "C", "av" => "A", "ave" => "A",
                 "average" => "A", "avg" => "A", "med" => "E", "median" => "E",
                 "var" => "V", "variance" => "V", "max-min" => "D",
                 "my" => "Y", "score" => ""];

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

    static function parse_saved_search($word, SearchWord $sword, PaperSearch $srch) {
        if (!$srch->user->isPC)
            return null;
        if (($nextq = $srch->_expand_saved_search($word, $srch->_ssRecursion))) {
            $srch->_ssRecursion[$word] = true;
            $qe = $srch->_searchQueryType($nextq);
            unset($srch->_ssRecursion[$word]);
        } else
            $qe = null;
        if (!$qe && $nextq === false)
            $srch->warn("Saved search “" . htmlspecialchars($word) . "” is defined in terms of itself.");
        else if (!$qe && !$srch->conf->setting_data("ss:$word"))
            $srch->warn("There is no “" . htmlspecialchars($word) . "” saved search.");
        else if (!$qe)
            $srch->warn("The “" . htmlspecialchars($word) . "” saved search is defined incorrectly.");
        return $qe ? : new False_SearchTerm;
    }

    function _search_keyword(&$qt, SearchWord $sword, $keyword, $kwexplicit) {
        $word = $sword->word;
        $sword->keyword = $keyword;
        $sword->kwexplicit = $kwexplicit;
        $sword->kwdef = $this->conf->search_keyword($keyword);
        if ($sword->kwdef && get($sword->kwdef, "parser")) {
            $qx = call_user_func($sword->kwdef->parser, $word, $sword, $this);
            if ($qx && !is_array($qx))
                $qt[] = $qx;
            else if ($qx)
                $qt = array_merge($qt, $qx);
            return;
        }
        if ($keyword === "option")
            $this->_search_options($word, $qt, true);
        // Finally, look for a review field.
        if ($keyword && !isset(self::$_keywords[$keyword]) && empty($qt)) {
            if (($field = $this->conf->review_field_search($keyword)))
                $this->_search_review_field($word, $field, $qt, $sword->quoted);
            else if (!$this->_search_options("$keyword:$word", $qt, false))
                $this->warn("Unrecognized keyword “" . htmlspecialchars($keyword) . "”.");
        }
    }

    function _searchQueryWord($word) {
        // check for paper number or "#TAG"
        if (preg_match('/\A#?(\d+)(?:-#?(\d+))?\z/', $word, $m)) {
            $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
            return new PaperID_SearchTerm(range((int) $m[1], (int) $m[2]));
        } else if (substr($word, 0, 1) === "#") {
            ++$this->_quiet_count;
            $qe = $this->_searchQueryWord("tag:" . substr($word, 1));
            --$this->_quiet_count;
            if (!$qe->is_false())
                return $qe;
        }

        // Allow searches like "ovemer>2"; parse as "ovemer:>2".
        if (preg_match('/\A([-_A-Za-z0-9]+)((?:[=!<>]=?|≠|≤|≥)[^:]+)\z/', $word, $m)) {
            ++$this->_quiet_count;
            $qe = $this->_searchQueryWord($m[1] . ":" . $m[2]);
            --$this->_quiet_count;
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
            return new True_SearchTerm;
        else if ($word === "NONE")
            return new False_SearchTerm;

        $qt = [];
        $sword = new SearchWord($word);
        if ($keyword)
            $this->_search_keyword($qt, $sword, $keyword, true);
        else {
            foreach ($this->fields as $kw => $x)
                $this->_search_keyword($qt, $sword, $kw, false);
        }
        return SearchTerm::make_op("or", $qt);
    }

    static public function pop_word(&$str, Conf $conf) {
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
            return self::pop_word($str, $conf);
        }

        // check for keyword
        $keyword = false;
        if (($colon = strpos($word, ":")) > 0) {
            $x = substr($word, 0, $colon);
            if (strpos($x, '"') === false)
                $keyword = $x;
        }

        // allow a space after a keyword
        if ($keyword && strlen($word) <= $colon + 1 && preg_match($wordre, $str, $m)) {
            $word .= ltrim($m[0]);
            $str = substr($str, strlen($m[0]));
        }

        // "show:" may be followed by a parenthesized expression
        if ($keyword
            && ($kwdef = $conf->search_keyword($keyword))
            && get($kwdef, "allow_parens")
            && substr($word, $colon + 1, 1) !== "\""
            && preg_match('/\A\S*\(/', $str)) {
            $pos = self::find_end_balanced_parens($str);
            $word .= substr($str, 0, $pos);
            $str = substr($str, $pos);
        }

        $str = ltrim($str);
        return $word;
    }

    static function _searchPopKeyword($str) {
        if (preg_match('/\A([-+!()]|(?:AND|and|OR|or|NOT|not|THEN|then|HIGHLIGHT(?::\w+)?)(?=[\s\(]))/s', $str, $m))
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
            $op = $opstr ? SearchOperator::get($opstr) : null;
            if ($opstr && !$op && ($colon = strpos($opstr, ":"))
                && ($op = SearchOperator::get(substr($opstr, 0, $colon)))) {
                $op = clone $op;
                $op->opinfo = substr($opstr, $colon + 1);
            }

            if ($curqe && (!$op || $op->unary)) {
                $op = SearchOperator::get("SPACE");
                $opstr = "";
                $nextstr = $str;
            }
            if (!$curqe && $op && $op->op === "highlight") {
                $curqe = new True_SearchTerm;
                $curqe->set_float("strspan", [$oppos, $oppos]);
            }

            if ($opstr === null) {
                $prevstr = $nextstr;
                $word = self::pop_word($nextstr, $this->conf);
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
                    $curqe = $this->_searchQueryWord($word);
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
        else if ($x->op->op === "space" && $x->op->precedence == 2)
            return "(" . join(" ", $x->qe) . ")";
        else
            return "(" . join(strtoupper(" " . $x->op->op . " "), $x->qe) . ")";
    }

    static private function _canonicalizeQueryType($str, $type, Conf $conf) {
        $stack = array();
        $parens = 0;
        $defaultop = ($type === "all" ? "XAND" : "XOR");
        $curqe = null;
        $t = "";

        while ($str !== "") {
            list($opstr, $nextstr) = self::_searchPopKeyword($str);
            $op = $opstr ? SearchOperator::get($opstr) : null;

            if ($curqe && (!$op || $op->unary)) {
                list($opstr, $op, $nextstr) =
                    array("", SearchOperator::get($parens ? "XAND" : $defaultop), $str);
            }

            if ($opstr === null) {
                $curqe = self::pop_word($nextstr, $conf);
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
            array_unshift($stack, (object) array("op" => SearchOperator::get("NOT"), "qe" => array()));
        while (count($stack))
            $curqe = self::_canonicalizePopStack($curqe, $stack);
        return $curqe;
    }

    static function canonical_query($qa, $qo = null, $qx = null, Conf $conf) {
        $x = array();
        if ($qa && ($qa = self::_canonicalizeQueryType(trim($qa), "all", $conf)))
            $x[] = $qa;
        if ($qo && ($qo = self::_canonicalizeQueryType(trim($qo), "any", $conf)))
            $x[] = $qo;
        if ($qx && ($qx = self::_canonicalizeQueryType(trim($qx), "none", $conf)))
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
        if ($this->noratings === false)
            $this->noratings = self::unusableRatings($this->user);
        $noratings = "";
        if (count($this->noratings) > 0)
            $noratings .= " and not (reviewId in (" . join(",", $this->noratings) . "))";

        foreach ($this->_interesting_ratings as $k => $v)
            $reviewtable .= " left join (select reviewId, count(rating) as nrate_$k from ReviewRating where rating$v$noratings group by reviewId) as Ratings_$k on (Ratings_$k.reviewId=r.reviewId)";
        $where[] = $rate;
    }


    // QUERY EVALUATION
    // Check the results of the query, reducing the possibly conservative
    // overestimate produced by the database to a precise result.

    function word_count_for(PaperInfo $row, $reviewId) {
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
            foreach ($qe->child as $subt)
                $this->_add_deleted_papers($subt);
        } else if ($qe->type === "pn") {
            foreach ($qe->pids as $p)
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
            $s = ListSorter::make_field(new NumericOrderPaperColumn(array_flip($qe->pids)));
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
        //Conf::msg_debugt(json_export($qe->export_json()));
        if (!$qe)
            $qe = new True_SearchTerm;

        // apply complex limiters (only current example: "acc" for non-chairs)
        $limit = $this->limitName;
        if ($limit === "acc" && !$this->privChair)
            $qe = SearchTerm::make_op("and", [$qe, $this->_searchQueryWord("dec:yes")]);

        // apply review rounds (top down, needs separate step)
        if ($this->_has_review_adjustment)
            $qe = $qe->adjust_reviews(null, $this);
        self::$current_search = null;

        //Conf::msg_debugt(json_export($qe->export_json()));

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
            $sole_qe = $qe->child[0];
        if ($sole_qe
            && ($sort = $sole_qe->get_float("sort"))
            && ($tag = self::_check_sort_order_anno($sort))) {
            $dt = $this->conf->tags()->add(TagInfo::base($tag));
            if (count($dt->order_anno_list()))
                $order_anno_tag = $dt;
        }

        // add permissions tables if we will filter the results
        $need_filter = !$qe->trivial_rights($this->user, $this)
            || $this->conf->has_tracks() /* XXX probably only need check_track_view_sensitivity */
            || $qe->type === "then"
            || $qe->get_float("heading")
            || $limit === "rable"
            || $order_anno_tag;
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

        //Conf::msg_debugt($q);
        //error_log($q);

        // actually perform query
        $result = $this->conf->qe_raw($q);
        if (!$result) {
            $this->_matches = false;
            return false;
        }

        // collect papers
        $rowset = new PaperInfoSet;
        while (($row = PaperInfo::fetch($result, $this->user)))
            $rowset->add($row);
        Dbl::free($result);

        // correct query, create thenmap, groupmap, highlightmap
        $need_then = $qe->type === "then";
        $this->thenmap = null;
        if ($need_then && $qe->nthen > 1)
            $this->thenmap = array();
        $this->highlightmap = array();
        $this->_matches = array();
        if ($need_filter) {
            $tag_order = [];
            foreach ($rowset->all() as $row) {
                if (!$this->user->can_view_paper($row)
                    || ($limit === "rable"
                        && !$limitcontact->can_accept_review_assignment_ignore_conflict($row))
                    || ($limit === "manager"
                        && !$this->user->can_administer($row, true)))
                    $x = false;
                else if ($need_then) {
                    $x = false;
                    for ($i = 0; $i < $qe->nthen && $x === false; ++$i)
                        if ($qe->child[$i]->exec($row, $this))
                            $x = $i;
                } else
                    $x = !!$qe->exec($row, $this);
                if ($x === false)
                    continue;
                $this->_matches[] = $row->paperId;
                if ($this->thenmap !== null)
                    $this->thenmap[$row->paperId] = $x;
                if ($need_then) {
                    for ($j = $qe->nthen; $j < count($qe->child); ++$j)
                        if ($qe->child[$j]->exec($row, $this)
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
            $this->_matches = $rowset->pids();

        // add deleted papers explicitly listed by number (e.g. action log)
        if ($this->_allow_deleted)
            $this->_add_deleted_papers($qe);

        // view and sort information
        $this->viewmap = $qe->get_float("view", array());
        $this->sorters = [];
        $this->_add_sorters($qe, null);
        if ($qe->type === "then")
            for ($i = 0; $i < $qe->nthen; ++$i)
                $this->_add_sorters($qe->child[$i], $this->thenmap ? $i : null);
        $this->groupmap = [];
        if (!$sole_qe) {
            for ($i = 0; $i < $qe->nthen; ++$i) {
                $h = $qe->child[$i]->get_float("heading");
                if ($h === null) {
                    $span = $qe->child[$i]->get_float("strspan");
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
        $qe->extract_metadata(true, $this);
        foreach ($this->contradictions as $contradiction => $garbage)
            $this->warn($contradiction);

        // set $this->matchPreg from $this->regex
        if (!$this->overrideMatchPreg) {
            $this->matchPreg = array();
            foreach (TextMatch_SearchTerm::$map as $k => $v)
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

    function mark_reviewer($cid) {
        if (!$this->_reviewer_fixed) {
            if ($cid && ($this->_reviewer
                         ? $this->_reviewer->contactId == $cid
                         : $this->_reviewer === false))
                $this->_reviewer = $this->conf->user_by_id($cid);
            else
                $this->_reviewer = null;
        }
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
