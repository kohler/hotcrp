<?php
// papersearch.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class SearchWord {
    public $qword;
    public $word;
    public $quoted;
    public $keyword;
    public $kwexplicit;
    public $kwdef;
    function __construct($qword) {
        $this->qword = $this->word = $qword;
        $this->quoted = $qword !== "" && $qword[0] === "\""
            && strpos($qword, "\"", 1) === strlen($qword) - 1;
        if ($this->quoted)
            $this->word = substr($qword, 1, -1);
    }
}

class SearchSplitter {
    private $str;
    private $utf8q;
    public $pos;
    public $strspan;
    function __construct($str) {
        $this->str = ltrim($str);
        $this->utf8q = strpos($str, chr(0xE2)) !== false;
        $this->pos = strlen($str) - strlen($this->str);
    }
    function is_empty() {
        return $this->str === "";
    }
    function shift() {
        if ($this->utf8q
            && preg_match('/\A([-_.a-zA-Z0-9]+:|["“”][^"“”]+["“”]:|)\s*((?:["“”][^"“”]*(?:["“”]|\z)|[^"“”\s()]*)*)/su', $this->str, $m)) {
            $result = preg_replace('/[“”]/u', "\"", $m[1] . $m[2]);
        } else if (preg_match('/\A([-_.a-zA-Z0-9]+:|"[^"]+":|)\s*((?:"[^"]*(?:"|\z)|[^"\s()]*)*)/s', $this->str, $m)) {
            $result = $m[1] . $m[2];
        } else {
            $this->pos += strlen($this->str);
            $this->str = "";
            $this->strspan = [$this->pos, $this->pos];
            return "";
        }
        $this->set_span_and_pos($m[0]);
        return $result;
    }
    function shift_past($str) {
        assert(str_starts_with($this->str, $str));
        $this->set_span_and_pos($str);
    }
    function shift_balanced_parens() {
        $result = substr($this->str, 0, self::span_balanced_parens($this->str));
        $this->set_span_and_pos($result);
        return $result;
    }
    function match($re, &$m = null) {
        return preg_match($re, $this->str, $m);
    }
    function starts_with($substr) {
        return str_starts_with($this->str, $substr);
    }
    private function set_span_and_pos($prefix) {
        $this->strspan = [$this->pos, $this->pos + strlen($prefix)];
        $next = ltrim(substr($this->str, strlen($prefix)));
        $this->pos += strlen($this->str) - strlen($next);
        $this->str = $next;
    }
    static function span_balanced_parens($str) {
        $pcount = $quote = 0;
        $len = strlen($str);
        for ($pos = 0; $pos < $len
                 && (!ctype_space($str[$pos]) || $pcount || $quote); ++$pos) {
            $ch = $str[$pos];
            // translate “” -> "
            if (ord($ch) === 0xE2 && $pos + 2 < $len && ord($str[$pos + 1]) === 0x80
                && (ord($str[$pos + 2]) & 0xFE) === 0x9C)
                $ch = "\"";
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
}

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
    function unparse() {
        $x = strtoupper($this->op);
        return $this->opinfo === null ? $x : $x . ":" . $this->opinfo;
    }

    static function get($name) {
        if (!self::$list) {
            self::$list["("] = new SearchOperator("(", true, null);
            self::$list[")"] = new SearchOperator(")", true, null);
            self::$list["NOT"] = new SearchOperator("not", true, 7);
            self::$list["-"] = new SearchOperator("not", true, 7);
            self::$list["!"] = new SearchOperator("not", true, 7);
            self::$list["+"] = new SearchOperator("+", true, 7);
            self::$list["SPACE"] = new SearchOperator("space", false, 6);
            self::$list["AND"] = new SearchOperator("and", false, 5);
            self::$list["OR"] = new SearchOperator("or", false, 4);
            self::$list["XOR"] = new SearchOperator("or", false, 3);
            self::$list["THEN"] = new SearchOperator("then", false, 2);
            self::$list["HIGHLIGHT"] = new SearchOperator("highlight", false, 1, "");
        }
        return get(self::$list, $name);
    }
}

class SearchTerm {
    public $type;
    public $float = [];

    function __construct($type) {
        $this->type = $type;
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
    function set_strspan_owner($str) {
        if (!isset($this->float["strspan_owner"]))
            $this->set_float("strspan_owner", $str);
    }


    function debug_json() {
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


    static function andjoin_sqlexpr($q, $default = "false") {
        if (empty($q))
            return $default;
        else if (in_array("false", $q))
            return "false";
        else
            return "(" . join(" and ", $q) . ")";
    }
    static function orjoin_sqlexpr($q, $default = "false") {
        if (empty($q))
            return $default;
        else if (in_array("true", $q))
            return "true";
        else
            return "(" . join(" or ", $q) . ")";
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        assert(false);
        return "false";
    }

    function exec(PaperInfo $row, PaperSearch $srch) {
        assert(false);
        return false;
    }

    function compile_edit_condition(PaperInfo $row, PaperSearch $srch) {
        return null;
    }


    function extract_metadata($top, PaperSearch $srch) {
        if ($top && ($x = $this->get_float("contradiction_warning")))
            $srch->contradictions[$x] = true;
    }
    function default_sorter($top, $thenmap, PaperSearch $srch) {
        return false;
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
    function compile_edit_condition(PaperInfo $row, PaperSearch $srch) {
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
    function compile_edit_condition(PaperInfo $row, PaperSearch $srch) {
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

    function set_strspan_owner($str) {
        if (!isset($this->float["strspan_owner"])) {
            parent::set_strspan_owner($str);
            foreach ($this->child as $qv)
                $qv->set_strspan_owner($str);
        }
    }
    function debug_json() {
        $a = [$this->type];
        foreach ($this->child as $qv)
            $a[] = $qv->debug_json();
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
        if ($sqi->negated
            && !$this->child[0]->trivial_rights($sqi->user, $sqi->srch))
            $ff = "false";
        $sqi->negated = !$sqi->negated;
        if ($ff === "false")
            return "true";
        else if ($ff === "true")
            return "false";
        else
            return "not ($ff)";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return !$this->child[0]->exec($row, $srch);
    }
    function compile_edit_condition(PaperInfo $row, PaperSearch $srch) {
        $x = $this->child[0]->compile_edit_condition($row, $srch);
        if ($x === null)
            return null;
        else if ($x === false || $x === true)
            return !$x;
        else
            return (object) ["type" => "not", "child" => [$x]];
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
    function compile_edit_condition(PaperInfo $row, PaperSearch $srch) {
        $ch = [];
        $ok = true;
        foreach ($this->child as $subt) {
            $x = $subt->compile_edit_condition($row, $srch);
            if ($x === null)
                return null;
            else if ($x === false)
                $ok = false;
            else if ($x !== true)
                $ch[] = $x;
        }
        if (!$ok || empty($ch))
            return $ok;
        else
            return (object) ["type" => "and", "child" => $ch];
    }
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        foreach ($this->child as $qv)
            $qv->extract_metadata($top, $srch);
    }
    function default_sorter($top, $thenmap, PaperSearch $srch) {
        $s = false;
        foreach ($this->child as $qv) {
            $s1 = $qv->default_sorter($top, $thenmap, $srch);
            if ($s && $s1)
                return false;
            $s = $s ? : $s1;
        }
        return $s;
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
    static function compile_or_edit_condition($child, PaperInfo $row, PaperSearch $srch) {
        $ch = [];
        $ok = false;
        foreach ($child as $subt) {
            $x = $subt->compile_edit_condition($row, $srch);
            if ($x === null)
                return null;
            else if ($x === true)
                $ok = true;
            else if ($x !== false)
                $ch[] = $x;
        }
        if ($ok || empty($ch))
            return $ok;
        else
            return (object) ["type" => "or", "child" => $ch];
    }
    function compile_edit_condition(PaperInfo $row, PaperSearch $srch) {
        return self::compile_or_edit_condition($this->child, $row, $srch);
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
    function compile_edit_condition(PaperInfo $row, PaperSearch $srch) {
        return Or_SearchTerm::compile_or_edit_condition(array_slice($this->child, 0, $this->nthen), $row, $srch);
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
    private $trivial = null;
    public $regex;
    static public $map = [ // NB see field_highlighters()
        "ti" => "title", "ab" => "abstract",
        "au" => "authorInformation", "co" => "collaborators"
    ];

    function __construct($t, $text, $quoted) {
        parent::__construct($t);
        $this->field = self::$map[$t];
        $this->authorish = $t === "au" || $t === "co";
        if (is_bool($text))
            $this->trivial = $text;
        else
            $this->regex = Text::star_text_pregexes($text, $quoted);
    }
    static function parse($word, SearchWord $sword) {
        if ($sword->kwexplicit && !$sword->quoted) {
            if ($word === "any")
                $word = true;
            else if ($word === "none")
                $word = false;
        }
        return new TextMatch_SearchTerm($sword->kwdef->name, $word, $sword->quoted);
    }

    function trivial_rights(Contact $user, PaperSearch $srch) {
        return $this->trivial && !$this->authorish;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_column($this->field, "Paper.{$this->field}");
        if ($this->trivial && !$this->authorish)
            return "Paper.{$this->field}!=''";
        else
            return "true";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $data = $row->{$this->field};
        if ($this->authorish && !$srch->user->allow_view_authors($row))
            $data = "";
        if ($data === "")
            return $this->trivial === false;
        else if ($this->trivial !== null)
            return $this->trivial;
        else
            return $row->field_match_pregexes($this->regex, $this->field);
    }
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        if ($this->regex)
            $srch->regex[$this->type][] = $this->regex;
    }
}

class ReviewRating_SearchAdjustment {
    private $type;
    private $arg;

    function __construct($type, $arg) {
        $this->type = $type;
        $this->arg = $arg;
    }
    function must_exist() {
        if ($this->type === "and")
            return $this->arg[0]->must_exist() || $this->arg[1]->must_exist();
        else if ($this->type === "or")
            return $this->arg[0]->must_exist() && $this->arg[1]->must_exist();
        else if ($this->type === "not")
            return false;
        else
            return !$this->arg->test(0);
    }
    private function _test($ratings) {
        if ($this->type === "and")
            return $this->arg[0]->_test($ratings) && $this->arg[1]->_test($ratings);
        else if ($this->type === "or")
            return $this->arg[0]->_test($ratings) || $this->arg[1]->_test($ratings);
        else if ($this->type === "not")
            return !$this->arg->_test($ratings);
        else {
            $n = count(array_filter($ratings, function ($r) { return ($r & $this->type) !== 0; }));
            return $this->arg->test($n);
        }
    }
    function test(Contact $user, PaperInfo $prow, ReviewInfo $rrow) {
        if ($user->can_view_review_ratings($prow, $rrow, $user->privChair))
            $ratings = $rrow->ratings();
        else
            $ratings = [];
        return $this->_test($ratings);
    }
}

class ReviewAdjustment_SearchTerm extends SearchTerm {
    private $conf;
    private $round;
    private $ratings;
    public $negated = false;
    public $used_revadj = false;

    function __construct(Conf $conf) {
        parent::__construct("revadj");
        $this->conf = $conf;
    }
    static function parse_round($word, SearchWord $sword, PaperSearch $srch) {
        $srch->_has_review_adjustment = true;
        if (!$srch->user->isPC)
            $rounds = null;
        else if (strcasecmp($word, "none") == 0 || strcasecmp($word, "unnamed") == 0)
            $rounds = [0];
        else if (strcasecmp($word, "any") == 0)
            $rounds = range(1, count($srch->conf->round_list()) - 1);
        else {
            $x = simplify_whitespace($word);
            $rounds = array_keys(Text::simple_search($x, $srch->conf->round_list()));
            if (empty($rounds)) {
                $srch->warn("“" . htmlspecialchars($x) . "” doesn’t match a review round.");
                return new False_SearchTerm;
            }
        }
        $qv = new ReviewAdjustment_SearchTerm($srch->conf);
        $qv->round = $rounds;
        return $qv;
    }
    static function parse_rate($word, SearchWord $sword, PaperSearch $srch) {
        if (!$srch->user->can_view_some_review_ratings()) {
            if ($srch->user->isPC && $srch->conf->setting("rev_ratings") == REV_RATINGS_NONE)
                $srch->warn("Review ratings are disabled.");
            return new False_SearchTerm;
        }
        $rate = null;
        if (strcasecmp($word, "none") == 0) {
            $rate = "any";
            $compar = "=0";
        } else if (preg_match('/\A(.+?)\s*(:?|[=!<>]=?|≠|≤|≥)\s*(\d*)\z/', $word, $m)
                   && ($m[3] !== "" || $m[2] === "")) {
            if ($m[3] === "")
                $compar = ">0";
            else if ($m[2] === "" || $m[2] === ":")
                $compar = ($m[3] == 0 ? "=0" : ">=" . $m[3]);
            else
                $compar = $m[2] . $m[3];
            $rate = self::parse_rate_name($m[1]);
        }
        if ($rate === null) {
            $srch->warn("Bad review rating query “" . htmlspecialchars($word) . "”.");
            return new False_SearchTerm;
        } else {
            $srch->_has_review_adjustment = true;
            $qv = new ReviewAdjustment_SearchTerm($srch->conf);
            $qv->ratings = new ReviewRating_SearchAdjustment($rate, new CountMatcher($compar));
            return $qv;
        }
    }
    static private function parse_rate_name($s) {
        if (strcasecmp($s, "any") == 0)
            return ReviewInfo::RATING_GOODMASK | ReviewInfo::RATING_BADMASK;
        if ($s === "+" || strcasecmp($s, "good") == 0 || strcasecmp($s, "yes") == 0)
            return ReviewInfo::RATING_GOODMASK;
        if ($s === "-" || strcasecmp($s, "bad") == 0 || strcasecmp($s, "no") == 0
            || $s === "\xE2\x88\x92" /* unicode MINUS */)
            return ReviewInfo::RATING_BADMASK;
        foreach (ReviewInfo::$rating_bits as $bit => $name) {
            if (strcasecmp($s, $name) === 0)
                return $bit;
        }
        $x = Text::simple_search($s, ReviewInfo::$rating_options);
        unset($x[0]); // can't search for “average”
        if (count($x) == 1) {
            reset($x);
            return key($x);
        } else
            return null;
    }

    function merge(ReviewAdjustment_SearchTerm $x = null) {
        $changed = null;
        if ($x && $this->round === null && $x->round !== null)
            $changed = $this->round = $x->round;
        if ($x && $this->ratings === null && $x->ratings !== null)
            $changed = $this->ratings = $x->ratings;
        return $changed !== null;
    }
    function promote(PaperSearch $srch) {
        $rsm = new ReviewSearchMatcher(">0");
        if (in_array($srch->limit(), ["r", "rout", "rable"]))
            $rsm->add_contact($srch->cid);
        else if ($srch->limit() === "req") {
            $rsm->apply_requester($srch->cid);
            $rsm->apply_review_type("external"); // XXX optional PC reviews?
        }
        $this->promote_matcher($rsm);
        $term = new Review_SearchTerm($rsm);
        return $term->negate_if($this->negated);
    }
    function promote_matcher(ReviewSearchMatcher $rsm) {
        if ($this->round !== null)
            $rsm->adjust_round_list($this->round);
        if ($this->ratings !== null)
            $rsm->adjust_ratings($this->ratings);
        $this->used_revadj = true;
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
            if ($this->ratings !== null)
                $this->ratings = new ReviewRating_SearchAdjustment("not", $this->ratings);
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
            if ($this->ratings !== null && $revadj->ratings !== null)
                $revadj->ratings = new ReviewRating_SearchAdjustment("or", [$this->ratings, $revadj->ratings]);
            else if ($this->ratings !== null)
                $revadj->ratings = $this->ratings;
        } else {
            if ($revadj->round !== null && $this->round !== null)
                $revadj->round = array_intersect($revadj->round, $this->round);
            else if ($this->round !== null)
                $revadj->round = $this->round;
            if ($this->ratings !== null && $revadj->ratings !== null)
                $revadj->ratings = new ReviewRating_SearchAdjustment("and", [$this->ratings, $revadj->ratings]);
            else
                $revadj->ratings = $this->ratings;
        }
        return $revadj;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        return "true";
    }
    function exec(PaperInfo $prow, PaperSearch $srch) {
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
    static function parse_heading($word, SearchWord $sword) {
        return SearchTerm::make_float(["heading" => simplify_whitespace($word)]);
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
    function in_order() {
        $pods = $this->pids;
        sort($pods, SORT_NUMERIC);
        return $pods == $this->pids;
    }
    function default_sorter($top, $thenmap, PaperSearch $srch) {
        if ($top && !$this->in_order()) {
            $s = ListSorter::make_field(new NumericOrderPaperColumn($srch->conf, array_flip($this->pids)));
            $s->thenmap = $thenmap;
            return $s;
        } else
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
        return $this->_contacts !== null
            && count($this->_contacts) == 1
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

class SearchQueryInfo {
    public $conf;
    public $srch;
    public $user;
    public $tables = array();
    public $columns = array();
    public $negated = false;
    private $_has_my_review = false;

    function __construct(PaperSearch $srch) {
        $this->conf = $srch->conf;
        $this->srch = $srch;
        $this->user = $srch->user;
    }
    function add_table($table, $joiner = false) {
        // * All added tables must match at most one Paper row each,
        //   except MyReviews and Limiter.
        assert($joiner || !count($this->tables));
        $this->tables[$table] = $joiner;
    }
    function add_column($name, $expr) {
        assert(!isset($this->columns[$name]) || $this->columns[$name] === $expr);
        $this->columns[$name] = $expr;
    }
    function add_conflict_table() {
        if (!isset($this->tables["PaperConflict"]))
            $this->add_table("PaperConflict", ["left join", "PaperConflict", "PaperConflict.contactId=" . ($this->user->contactId ? : -100)]);
    }
    function add_conflict_columns() {
        $this->add_conflict_table();
        $this->columns["conflictType"] = "PaperConflict.conflictType";
    }
    function add_reviewer_columns() {
        $this->_has_my_review = true;
    }
    function finish_reviewer_columns() {
        if ($this->_has_my_review) {
            $this->add_conflict_columns();
            if (isset($this->columns["reviewSignatures"])) {
                /* use that */
            } else if (isset($this->tables["MyReviews"])) {
                $this->add_column("myReviewPermissions", PaperInfo::my_review_permissions_sql("MyReviews."));
            } else if (!isset($this->tables["Limiter"])) {
                $this->add_table("MyReviews", ["left join", "PaperReview", $this->user->act_reviewer_sql("MyReviews")]);
                $this->add_column("myReviewPermissions", PaperInfo::my_review_permissions_sql("MyReviews."));
            } else {
                $this->add_column("myReviewPermissions", "(select " . PaperInfo::my_review_permissions_sql() . " from PaperReview where PaperReview.paperId=Paper.paperId and " . $this->user->act_reviewer_sql("PaperReview") . " group by paperId)");
            }
        }
    }
    function add_review_signature_columns() {
        if (!isset($this->columns["reviewSignatures"]))
            $this->add_column("reviewSignatures", "(select " . ReviewInfo::review_signature_sql() . " from PaperReview r where r.paperId=Paper.paperId)");
    }
    function add_score_columns($fid) {
        $this->add_review_signature_columns();
        if (!isset($this->columns["{$fid}Signature"])
            && ($f = $this->conf->review_field($fid))
            && $f->main_storage)
            $this->add_column("{$fid}Signature", "(select group_concat({$f->main_storage} order by reviewId) from PaperReview where PaperReview.paperId=Paper.paperId)");
    }
    function add_review_word_count_columns() {
        $this->add_review_signature_columns();
        if (!isset($this->columns["reviewWordCountSignature"]))
            $this->add_column("reviewWordCountSignature", "(select group_concat(coalesce(reviewWordCount,'.') order by reviewId) from PaperReview where PaperReview.paperId=Paper.paperId)");
    }
    function add_rights_columns() {
        if (!isset($this->columns["managerContactId"]))
            $this->columns["managerContactId"] = "Paper.managerContactId";
        if (!isset($this->columns["leadContactId"]))
            $this->columns["leadContactId"] = "Paper.leadContactId";
        // XXX could avoid the following if user is privChair for everything:
        $this->add_conflict_columns();
        $this->add_reviewer_columns();
    }
    function add_allConflictType_column() {
        if (!isset($this->columns["allConflictType"]))
            $this->add_column("allConflictType", "(select group_concat(contactId, ' ', conflictType) from PaperConflict where PaperConflict.paperId=Paper.paperId)");
    }
}

class PaperSearch {
    public $conf;
    public $user;
    private $contact;
    public $cid;
    public $privChair;
    private $amPC;

    private $limitName;
    private $fields;
    private $_reviewer_user = false;
    private $_active_limit;
    private $urlbase;
    public $warnings = array();
    private $_quiet_count = 0;

    public $q;
    private $_qe;
    public $test_review;

    public $regex = [];
    public $contradictions = [];
    private $_match_preg;
    private $_match_preg_query;

    private $contact_match = array();
    public $_query_options = array();
    public $_has_review_adjustment = false;
    private $_ssRecursion = array();
    private $_allow_deleted = false;
    public $thenmap;
    public $groupmap;
    public $is_order_anno = false;
    public $highlightmap;
    public $viewmap;
    public $sorters = [];
    private $_default_sort; // XXX should be used more often
    private $_highlight_tags;

    private $_matches; // list of ints

    static private $_sort_keywords = ["by" => "by", "up" => "up", "down" => "down",
                 "reverse" => "down", "reversed" => "down", "score" => ""];

    static public $search_type_names = [
        "a" => "Your submissions",
        "acc" => "Accepted papers",
        "act" => "Active papers",
        "all" => "All papers",
        "editpref" => "Reviewable papers",
        "lead" => "Your discussion leads",
        "manager" => "Papers you administer",
        "r" => "Your reviews",
        "rable" => "Reviewable papers",
        "req" => "Your review requests",
        "reqrevs" => "Your review requests",
        "rout" => "Your incomplete reviews",
        "s" => "Submitted papers",
        "und" => "Undecided papers",
        "unm" => "Unmanaged submissions"
    ];


    // NB: `$options` can come from an unsanitized user request.
    function __construct(Contact $user, $options) {
        if (is_string($options))
            $options = array("q" => $options);

        // contact facts
        $this->conf = $user->conf;
        $this->user = $user;
        $this->privChair = $user->privChair;
        $this->amPC = $user->isPC;
        $this->cid = $user->contactId;

        // paper selection
        $ptype = (string) get($options, "t");
        if ($ptype === "0")
            $ptype = "";
        if ($ptype === "vis")
            $this->limitName = "vis";
        else if ($this->privChair && !$ptype && $this->conf->timeUpdatePaper())
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
        else if ($user->isPC && ($ptype === "acc"
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

        // default query fields
        // NB: If a complex query field, e.g., "re", "tag", or "option", is
        // default, then it must be the only default or query construction
        // will break.
        $this->fields = array();
        $qtype = get($options, "qt", "n");
        if ($qtype === "n" || $qtype === "ti")
            $this->fields["ti"] = 1;
        if ($qtype === "n" || $qtype === "ab")
            $this->fields["ab"] = 1;
        if ($user->can_view_some_authors()
            && ($qtype === "n" || $qtype === "au" || $qtype === "ac"))
            $this->fields["au"] = 1;
        if ($this->privChair && $qtype === "ac")
            $this->fields["co"] = 1;
        if ($this->amPC && $qtype === "re")
            $this->fields["re"] = 1;
        if ($this->amPC && $qtype === "tag")
            $this->fields["tag"] = 1;

        // the query itself
        $this->q = trim(get_s($options, "q"));
        $this->_default_sort = get($options, "sort");

        // reviewer
        if (($reviewer = get($options, "reviewer"))) {
            if (is_string($reviewer)) {
                if (strcasecmp($reviewer, $user->email) == 0)
                    $reviewer = $user;
                else if ($user->can_view_pc())
                    $reviewer = $this->conf->pc_member_by_email($reviewer);
                else
                    $reviewer = null;
            } else if (!is_object($reviewer) || !($reviewer instanceof Contact))
                $reviewer = null;
            if ($reviewer)
                $this->_reviewer_user = $reviewer;
        }

        // URL base
        if (isset($options["urlbase"]))
            $this->urlbase = $options["urlbase"];
        else
            $this->urlbase = $this->conf->hoturl_site_relative_raw("search", "t=" . urlencode($this->limitName));
        if ($qtype !== "n")
            $this->urlbase = hoturl_add_raw($this->urlbase, "qt=" . urlencode($qtype));
        if ($this->_reviewer_user
            && $this->_reviewer_user->contactId !== $user->contactId
            && strpos($this->urlbase, "reviewer=") === false)
            $this->urlbase = hoturl_add_raw($this->urlbase, "reviewer=" . urlencode($this->_reviewer_user->email));
        if (strpos($this->urlbase, "&amp;") !== false)
            trigger_error(caller_landmark() . " PaperSearch::urlbase should be a raw URL", E_USER_NOTICE);

        $this->set_limit($this->limitName);
    }

    private function set_limit($limit) {
        assert($this->_qe === null);
        $this->_active_limit = $limit;
        if ($this->_active_limit === "editpref")
            $this->_active_limit = "rable";
        else if ($this->_active_limit === "reqrevs")
            $this->_active_limit = "req";
        if ($this->_active_limit === "rable") {
            $u = $this->reviewer_user();
            if ($this->privChair || $this->user === $u) {
                if ($u->can_accept_review_assignment_ignore_conflict(null)) {
                    if ($this->conf->can_pc_see_all_submissions())
                        $this->_active_limit = "act";
                    else
                        $this->_active_limit = "s";
                } else if (!$u->isPC)
                    $this->_active_limit = "r";
            }
        }
    }

    function set_allow_deleted($x) {
        assert($this->_qe === null);
        $this->_allow_deleted = $x;
    }

    function __get($name) {
        error_log("PaperSearch::$name " . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)));
        return $name === "contact" ? $this->user : null;
    }

    function limit() {
        return $this->_active_limit;
    }
    function limit_submitted() {
        return !in_array($this->_active_limit, ["a", "ar", "act", "all", "unsub"]);
    }
    function limit_author() {
        return $this->_active_limit === "a";
    }

    function reviewer_user() {
        return $this->_reviewer_user ? : $this->user;
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

    private function make_contact_match($type, $text) {
        foreach ($this->contact_match as $i => $cm)
            if ($cm->type === $type && $cm->text === $text)
                return $cm;
        return $this->contact_match[] = new ContactSearch($type, $text, $this->user);
    }

    private function matching_contacts_base($type, $word, $quoted, $pc_only) {
        if ($pc_only)
            $type |= ContactSearch::F_PC;
        if ($quoted)
            $type |= ContactSearch::F_QUOTED;
        if (!$quoted && $this->amPC)
            $type |= ContactSearch::F_TAG;
        $scm = $this->make_contact_match($type, $word);
        if ($scm->warn_html)
            $this->warn($scm->warn_html);
        return $scm->ids;
    }
    function matching_users($word, $quoted, $pc_only) {
        $cids = $this->matching_contacts_base(ContactSearch::F_USER, $word, $quoted, $pc_only);
        return empty($cids) ? [] : $cids;
    }
    function matching_special_contacts($word, $quoted, $pc_only) {
        $cids = $this->matching_contacts_base(0, $word, $quoted, $pc_only);
        return $cids === false ? null : (empty($cids) ? [] : $cids);
    }

    static function decision_matchexpr(Conf $conf, $word, $flag) {
        $lword = strtolower($word);
        if (!($flag & Text::SEARCH_NO_SPECIAL)) {
            if ($lword === "yes")
                return ">0";
            else if ($lword === "no")
                return "<0";
            else if ($lword === "?" || $lword === "none"
                     || $lword === "unknown" || $lword === "unspecified"
                     || $lword === "undecided")
                return [0];
            else if ($lword === "any")
                return "!=0";
        }
        return array_keys(Text::simple_search($word, $conf->decision_map(), $flag));
    }

    static function status_field_matcher(Conf $conf, $word, $quoted = null) {
        if (strlen($word) >= 3
            && ($k = Text::simple_search($word, ["w0" => "withdrawn", "s0" => "submitted", "s1" => "ready", "u0" => "in progress", "u1" => "unsubmitted", "u2" => "not ready", "a0" => "active", "x0" => "no submission"]))) {
            $k = array_map(function ($x) { return $x[0]; }, array_keys($k));
            $k = array_unique($k);
            if (count($k) === 1) {
                if ($k[0] === "w")
                    return ["timeWithdrawn", ">0"];
                else if ($k[0] === "s")
                    return ["timeSubmitted", ">0"];
                else if ($k[0] === "u")
                    return ["timeSubmitted", "<=0", "timeWithdrawn", "<=0"];
                else if ($k[0] === "x")
                    return ["timeSubmitted", "<=0", "timeWithdrawn", "<=0", "paperStorageId", "<=1"];
                else
                    return ["timeWithdrawn", "<=0"];
            }
        }
        $flag = $quoted ? Text::SEARCH_NO_SPECIAL : Text::SEARCH_UNPRIVILEGE_EXACT;
        return ["outcome", self::decision_matchexpr($conf, $word, $flag)];
    }

    static function parse_reconflict($word, SearchWord $sword, PaperSearch $srch) {
        // `reconf:` keyword, defined in `etc/searchkeywords.json`
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

    static function parse_has($word, SearchWord $sword, PaperSearch $srch) {
        $lword = strtolower($word);
        if (($kwdef = $srch->conf->search_keyword($lword, $srch->user))) {
            if (get($kwdef, "parse_has_callback"))
                $qe = call_user_func($kwdef->parse_has_callback, $word, $sword, $srch);
            else if (get($kwdef, "has")) {
                $sword2 = new SearchWord($kwdef->has);
                $sword2->kwexplicit = true;
                $sword2->keyword = $lword;
                $sword2->kwdef = $kwdef;
                $qe = call_user_func($kwdef->parse_callback, $kwdef->has, $sword2, $srch);
            } else
                $qe = null;
            if ($qe && $sword->keyword === "no") {
                if (is_array($qe))
                    $qe = SearchTerm::make_op("or", $qe);
                $qe = SearchTerm::make_not($qe);
            }
            if ($qe)
                return $qe;
        }
        $srch->warn("Unknown search “" . $sword->keyword . ":" . htmlspecialchars($word) . "”.");
        return new False_SearchTerm;
    }

    static function parse_sorter($text) {
        $text = simplify_whitespace($text);
        $sort = ListSorter::make_empty($text === "");
        if (($ch1 = substr($text, 0, 1)) === "-" || $ch1 === "+") {
            $sort->reverse = $ch1 === "-";
            $text = ltrim(substr($text, 1));
        }

        // separate text into words
        $words = array();
        $bypos = false;
        while (true) {
            preg_match('{\A[,\s]*([^\s\(,]*)(.*)\z}s', $text, $m);
            if ($m[1] === "" && $m[2] === "")
                break;
            if (substr($m[2], 0, 1) === "(") {
                $pos = SearchSplitter::span_balanced_parens($m[2]);
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
            if ($bypos === false || $i > $bypos) {
                if (($x = get(self::$_sort_keywords, $w)) !== null) {
                    if ($x === "up")
                        $sort->reverse = false;
                    else if ($x === "down")
                        $sort->reverse = true;
                    continue;
                } else if (($x = ListSorter::canonical_short_score_sort($w))) {
                    $sort->score = $x;
                    continue;
                }
            }
            if ($bypos === false || $i < $bypos)
                $next_words[] = $w;
        }

        if (!empty($next_words))
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
            $qe = $srch->_search_expression($nextq);
            unset($srch->_ssRecursion[$word]);
        } else
            $qe = null;
        if (!$qe && $nextq === false)
            $srch->warn("Saved search “" . htmlspecialchars($word) . "” is defined in terms of itself.");
        else if (!$qe && !$srch->conf->setting_data("ss:$word"))
            $srch->warn("There is no “" . htmlspecialchars($word) . "” saved search.");
        else if (!$qe)
            $srch->warn("The “" . htmlspecialchars($word) . "” saved search is defined incorrectly.");
        $qe = $qe ? : new False_SearchTerm;
        if ($nextq)
            $qe->set_strspan_owner($nextq);
        return $qe;
    }

    private function _search_keyword(&$qt, SearchWord $sword, $keyword, $kwexplicit) {
        $word = $sword->word;
        $sword->keyword = $keyword;
        $sword->kwexplicit = $kwexplicit;
        $sword->kwdef = $this->conf->search_keyword($keyword, $this->user);
        if ($sword->kwdef && get($sword->kwdef, "parse_callback")) {
            $qx = call_user_func($sword->kwdef->parse_callback, $word, $sword, $this);
            if ($qx && !is_array($qx))
                $qt[] = $qx;
            else if ($qx)
                $qt = array_merge($qt, $qx);
        } else
            $this->warn("Unrecognized keyword “" . htmlspecialchars($keyword) . "”.");
    }

    private function _search_word($word, $defkw) {
        // check for paper numbers
        if (preg_match('/\A(?:#?\d+(?:(?:-|–|—)#?\d+)?(?:\s*,\s*|\z))+\z/', $word)) {
            $range = [];
            while (preg_match('/\A#?(\d+)(?:(?:-|–|—)#?(\d+))?\s*,?\s*(.*)\z/', $word, $m)) {
                $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
                $range = array_merge($range, range(intval($m[1]), intval($m[2])));
                $word = $m[3];
            }
            return new PaperID_SearchTerm($range);
        }

        // check for `#TAG`
        if (substr($word, 0, 1) === "#") {
            ++$this->_quiet_count;
            $qe = $this->_search_word("hashtag:" . substr($word, 1), $defkw);
            --$this->_quiet_count;
            if (!$qe->is_false())
                return $qe;
        }

        $keyword = $defkw;
        if (preg_match('/\A([-_.a-zA-Z0-9]+|"[^"]+")((?:[=!<>]=?|≠|≤|≥)[^:]+|:.*)\z/', $word, $m)) {
            if ($m[2][0] === ":") {
                $keyword = $m[1];
                $word = ltrim((string) substr($m[2], 1));
            } else {
                // Allow searches like "ovemer>2"; parse as "ovemer:>2".
                ++$this->_quiet_count;
                $qe = $this->_search_word($m[1] . ":" . $m[2], $defkw);
                --$this->_quiet_count;
                if (!$qe->is_false())
                    return $qe;
            }
        }
        if ($keyword && $keyword[0] === '"')
            $keyword = trim(substr($keyword, 1, strlen($keyword) - 2));

        $qt = [];
        $sword = new SearchWord($word);
        if ($keyword)
            $this->_search_keyword($qt, $sword, $keyword, true);
        else {
            // Special-case unquoted "*", "ANY", "ALL", "NONE", "".
            if ($word === "*" || $word === "ANY" || $word === "ALL"
                || $word === "")
                return new True_SearchTerm;
            else if ($word === "NONE")
                return new False_SearchTerm;
            // Otherwise check known keywords.
            foreach ($this->fields as $kw => $x)
                $this->_search_keyword($qt, $sword, $kw, false);
        }
        return SearchTerm::make_op("or", $qt);
    }

    static function escape_word($str) {
        $pos = SearchSplitter::span_balanced_parens($str);
        if ($pos === strlen($str))
            return $str;
        else
            return "\"" . str_replace("\"", "\\\"", $str) . "\"";
    }

    static private function _shift_keyword($splitter, $curqe) {
        if (!$splitter->match('/\A(?:[-+!()]|(?:AND|and|OR|or|NOT|not|THEN|then|HIGHLIGHT(?::\w+)?)(?=[\s\(]))/s', $m))
            return null;
        $op = SearchOperator::get(strtoupper($m[0]));
        if (!$op) {
            $colon = strpos($m[0], ":");
            $op = clone SearchOperator::get(strtoupper(substr($m[0], 0, $colon)));
            $op->opinfo = substr($m[0], $colon + 1);
        }
        if ($curqe && $op->unary)
            return null;
        $splitter->shift_past($m[0]);
        return $op;
    }

    static private function _shift_word($splitter, Conf $conf) {
        if (($x = $splitter->shift()) === "")
            return $x;
        // `HEADING x` parsed as `HEADING:x`
        if ($x === "HEADING") {
            $lspan = $splitter->strspan[0];
            $x .= ":" . $splitter->shift();
            $splitter->strspan[0] = $lspan;
            return $x;
        }
        // some keywords may be followed by parentheses
        if (strpos($x, ":")
            && preg_match('/\A([-_.a-zA-Z0-9]+:|"[^"]+":)(?=[^"]|\z)/', $x, $m)) {
            if ($m[1][0] === "\"")
                $kw = substr($m[1], 1, strlen($m[1]) - 2);
            else
                $kw = substr($m[1], 0, strlen($m[1]) - 1);
            if (($kwdef = $conf->search_keyword($kw))
                && $splitter->starts_with("(")
                && get($kwdef, "allow_parens")) {
                $lspan = $splitter->strspan[0];
                $x .= $splitter->shift_balanced_parens();
                $splitter->strspan[0] = $lspan;
            }
        }
        return $x;
    }

    static private function _pop_expression_stack($curqe, &$stack) {
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

    private function _search_expression($str) {
        $stack = array();
        $defkwstack = array();
        $defkw = $next_defkw = null;
        $parens = 0;
        $curqe = null;
        $splitter = new SearchSplitter($str);

        while (!$splitter->is_empty()) {
            $op = self::_shift_keyword($splitter, $curqe);
            if ($curqe && !$op)
                $op = SearchOperator::get("SPACE");
            if (!$curqe && $op && $op->op === "highlight") {
                $curqe = new True_SearchTerm;
                $curqe->set_float("strspan", [$splitter->strspan[0], $splitter->strspan[0]]);
            }

            if (!$op) {
                $word = self::_shift_word($splitter, $this->conf);
                // Bare any-case "all", "any", "none" are treated as keywords.
                if (!$curqe
                    && (empty($stack) || $stack[count($stack) - 1]->op->precedence <= 2)
                    && ($uword = strtoupper($word))
                    && ($uword === "ALL" || $uword === "ANY" || $uword === "NONE")
                    && $splitter->match('/\A(?:|(?:THEN|then|HIGHLIGHT(?::\w+)?)(?:\s|\().*)\z/'))
                    $word = $uword;
                // Search like "ti:(foo OR bar)" adds a default keyword.
                if ($word[strlen($word) - 1] === ":"
                    && preg_match('/\A(?:[-_.a-zA-Z0-9]+:|"[^"]+":)\z/', $word)
                    && $splitter->starts_with("("))
                    $next_defkw = [substr($word, 0, strlen($word) - 1), $splitter->strspan[0]];
                else {
                    // The heart of the matter.
                    $curqe = $this->_search_word($word, $defkw);
                    if (!$curqe->is_uninteresting())
                        $curqe->set_float("strspan", $splitter->strspan);
                }
            } else if ($op->op === ")") {
                while (!empty($stack)
                       && $stack[count($stack) - 1]->op->op !== "(")
                    $curqe = self::_pop_expression_stack($curqe, $stack);
                if (!empty($stack)) {
                    $stack[count($stack) - 1]->strspan[1] = $splitter->strspan[1];
                    $curqe = self::_pop_expression_stack($curqe, $stack);
                    --$parens;
                    $defkw = array_pop($defkwstack);
                }
            } else if ($op->op === "(") {
                assert(!$curqe);
                $stkelem = (object) ["op" => $op, "leftqe" => null, "strspan" => $splitter->strspan];
                $defkwstack[] = $defkw;
                if ($next_defkw) {
                    $defkw = $next_defkw[0];
                    $stkelem->strspan[0] = $next_defkw[1];
                    $next_defkw = null;
                }
                $stack[] = $stkelem;
                ++$parens;
            } else if ($op->unary || $curqe) {
                $end_precedence = $op->precedence - ($op->precedence <= 1);
                while (!empty($stack)
                       && $stack[count($stack) - 1]->op->precedence > $end_precedence)
                    $curqe = self::_pop_expression_stack($curqe, $stack);
                $stack[] = (object) ["op" => $op, "leftqe" => $curqe, "strspan" => $splitter->strspan];
                $curqe = null;
            }
        }

        while (!empty($stack))
            $curqe = self::_pop_expression_stack($curqe, $stack);
        return $curqe;
    }


    static private function _pop_canonicalize_stack($curqe, &$stack) {
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
        else if ($x->op->op === "space")
            return "(" . join(" ", $x->qe) . ")";
        else
            return "(" . join(" " . $x->op->unparse() . " ", $x->qe) . ")";
    }

    static private function _canonical_expression($str, $type, Conf $conf) {
        $str = trim((string) $str);
        if ($str === "")
            return "";

        $stack = array();
        $parens = 0;
        $defaultop = $type === "all" ? "SPACE" : "XOR";
        $curqe = null;
        $t = "";
        $splitter = new SearchSplitter($str);

        while (!$splitter->is_empty()) {
            $op = self::_shift_keyword($splitter, $curqe);
            if ($curqe && !$op)
                $op = SearchOperator::get($parens ? "SPACE" : $defaultop);
            if (!$op) {
                $curqe = self::_shift_word($splitter, $conf);
            } else if ($op->op === ")") {
                while (count($stack)
                       && $stack[count($stack) - 1]->op->op !== "(")
                    $curqe = self::_pop_canonicalize_stack($curqe, $stack);
                if (count($stack)) {
                    array_pop($stack);
                    --$parens;
                }
            } else if ($op->op === "(") {
                assert(!$curqe);
                $stack[] = (object) array("op" => $op, "qe" => array());
                ++$parens;
            } else {
                $end_precedence = $op->precedence - ($op->precedence <= 1);
                while (count($stack)
                       && $stack[count($stack) - 1]->op->precedence > $end_precedence)
                    $curqe = self::_pop_canonicalize_stack($curqe, $stack);
                $top = count($stack) ? $stack[count($stack) - 1] : null;
                if ($top && !$op->unary && $top->op->op === $op->op)
                    $top->qe[] = $curqe;
                else
                    $stack[] = (object) array("op" => $op, "qe" => array($curqe));
                $curqe = null;
            }
        }

        if ($type === "none")
            array_unshift($stack, (object) array("op" => SearchOperator::get("NOT"), "qe" => array()));
        while (count($stack))
            $curqe = self::_pop_canonicalize_stack($curqe, $stack);
        return $curqe;
    }

    static function canonical_query($qa, $qo, $qx, Conf $conf) {
        $x = array();
        if (($qa = self::_canonical_expression($qa, "all", $conf)) !== "")
            $x[] = $qa;
        if (($qo = self::_canonical_expression($qo, "any", $conf)) !== "")
            $x[] = $qo;
        if (($qx = self::_canonical_expression($qx, "none", $conf)) !== "")
            $x[] = $qx;
        if (count($x) == 1)
            return preg_replace('/\A\((.*)\)\z/', '$1', $x[0]);
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
        if ($user->privChair || $user->conf->setting("pc_seeallrev"))
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


    // QUERY EVALUATION
    // Check the results of the query, reducing the possibly conservative
    // overestimate produced by the database to a precise result.

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
        if (($sorters = $qe->get_float("sort"))) {
            foreach ($sorters as $s)
                if (($s = self::parse_sorter($s))) {
                    $s->thenmap = $thenmap;
                    $this->sorters[] = $s;
                }
        } else if (($s = $qe->default_sorter(true, $thenmap, $this))) {
            $this->sorters[] = $s;
        }
    }

    private function _assign_order_anno_group($g, $dt, $anno_index) {
        if (($ta = $dt->order_anno_entry($anno_index)))
            $this->groupmap[$g] = $ta;
        else if (!isset($this->groupmap[$g])) {
            $ta = new TagAnno;
            $ta->tag = $dt->tag;
            $ta->heading = "";
            $this->groupmap[$g] = $ta;
        }
    }

    private function _find_order_anno_tag($qe) {
        $thetag = null;
        foreach ($this->sorters as $sorter) {
            $tag = $sorter->type ? Tagger::check_tag_keyword($sorter->type, $this->user, Tagger::NOVALUE | Tagger::ALLOWCONTACTID) : false;
            $ok = $tag && ($thetag === null || $thetag === $tag);
            $thetag = $ok ? $tag : false;
        }
        if (!$thetag)
            return false;
        $dt = $this->conf->tags()->add(TagInfo::base($tag));
        if ($dt->has_order_anno())
            return $dt;
        foreach ($qe->get_float("view", []) as $vk => $action) {
            if ($action === "edit"
                && ($t = Tagger::check_tag_keyword($vk, $this->user, Tagger::NOVALUE | Tagger::ALLOWCONTACTID | Tagger::NOTAGKEYWORD))
                && strcasecmp($t, $dt->tag) == 0)
                return $dt;
        }
        return false;
    }

    private function _check_order_anno($qe, $rowset) {
        if (!($dt = $this->_find_order_anno_tag($qe)))
            return false;
        $this->is_order_anno = $dt->tag;

        $tag_order = [];
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
        foreach ($this->_matches as $pid) {
            $row = $rowset->get($pid);
            if ($row->has_viewable_tag($dt->tag, $this->user))
                $tag_order[] = [$row->paperId, $row->tag_value($dt->tag)];
            else
                $tag_order[] = [$row->paperId, TAG_INDEXBOUND];
        }
        $this->user->set_overrides($old_overrides);
        usort($tag_order, "TagInfo::id_index_compar");

        $this->thenmap = [];
        $this->_assign_order_anno_group(0, $dt, -1);
        $this->groupmap[0]->heading = "none";
        $cur_then = $aidx = $tidx = 0;
        $alist = $dt->order_anno_list();
        while ($aidx < count($alist) || $tidx < count($tag_order)) {
            if ($tidx == count($tag_order)
                || ($aidx < count($alist) && $alist[$aidx]->tagIndex <= $tag_order[$tidx][1])) {
                if ($cur_then != 0 || $tidx != 0 || $aidx != 0)
                    ++$cur_then;
                $this->_assign_order_anno_group($cur_then, $dt, $aidx);
                ++$aidx;
            } else {
                $this->thenmap[$tag_order[$tidx][0]] = $cur_then;
                ++$tidx;
            }
        }
    }

    function term() {
        if ($this->_qe !== null)
            return $this->_qe;

        // parse and clean the query
        $qe = $this->_search_expression($this->q);
        //Conf::msg_debugt(json_encode($qe->debug_json()));
        if (!$qe)
            $qe = new True_SearchTerm;

        // apply review rounds (top down, needs separate step)
        if ($this->_has_review_adjustment)
            $qe = $qe->adjust_reviews(null, $this);

        // extract regular expressions and set _reviewer if the query is
        // about exactly one reviewer, and warn about contradictions
        $qe->extract_metadata(true, $this);
        foreach ($this->contradictions as $contradiction => $garbage)
            $this->warn($contradiction);

        return ($this->_qe = $qe);
    }

    private function _prepare_result($qe) {
        $sqi = new SearchQueryInfo($this);
        $sqi->add_table("Paper");
        $sqi->add_column("paperId", "Paper.paperId");
        // always include columns needed by rights machinery
        $sqi->add_column("timeSubmitted", "Paper.timeSubmitted");
        $sqi->add_column("timeWithdrawn", "Paper.timeWithdrawn");
        $sqi->add_column("outcome", "Paper.outcome");
        if ($this->conf->has_any_lead_or_shepherd())
            $sqi->add_column("leadContactId", "Paper.leadContactId");

        $filters = [$qe->sqlexpr($sqi)];
        //Conf::msg_debugt(var_export($filters, true));
        if ($filters[0] === "false")
            return [null, false];

        // status limitation parts
        $limit = $this->limit();
        if ($limit === "s"
            || $limit === "req"
            || $limit === "acc"
            || $limit === "und"
            || $limit === "unm"
            || ($limit === "rable" && !$this->conf->can_pc_see_all_submissions()))
            $filters[] = "Paper.timeSubmitted>0";
        else if ($limit === "act"
                 || $limit === "r"
                 || $limit === "rable")
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
            $sqi->add_conflict_table();
        }

        // decision limitation parts
        if ($limit === "acc")
            $filters[] = "Paper.outcome>0";
        else if ($limit === "und")
            $filters[] = "Paper.outcome=0";

        // other search limiters
        if ($limit === "a")
            $filters[] = $this->user->act_author_view_sql("PaperConflict");
        else if ($limit === "r")
            $sqi->add_table("MyReviews", ["join", "PaperReview", $this->user->act_reviewer_sql("MyReviews")]);
        else if ($limit === "ar") {
            $sqi->add_table("MyReviews", ["left join", "PaperReview", $this->user->act_reviewer_sql("MyReviews")]);
            $filters[] = "(" . $this->user->act_author_view_sql("PaperConflict") . " or (Paper.timeWithdrawn<=0 and MyReviews.reviewType is not null))";
        } else if ($limit === "rout")
            $sqi->add_table("Limiter", ["join", "PaperReview", $this->user->act_reviewer_sql("Limiter") . " and reviewNeedsSubmit!=0"]);
        else if ($limit === "req")
            $sqi->add_table("Limiter", ["join", "PaperReview", "Limiter.requestedBy=$this->cid and Limiter.reviewType=" . REVIEW_EXTERNAL]);
        else if ($limit === "unm")
            $filters[] = "Paper.managerContactId=0";
        else if ($this->q === "re:me")
            $sqi->add_table("MyReviews", ["join", "PaperReview", $this->user->act_reviewer_sql("MyReviews")]);

        if ($limit === "a" || $limit === "ar")
            $sqi->add_conflict_table();
        if ($limit === "r" || $limit === "ar" || $limit === "rout" || $this->q === "re:me")
            $sqi->add_reviewer_columns();

        // add permissions tables if we will filter the results
        $need_filter = !$qe->trivial_rights($this->user, $this)
            || !$this->trivial_limit()
            || $this->conf->has_tracks() /* XXX probably only need check_track_view_sensitivity */
            || $qe->type === "then"
            || $qe->get_float("heading");

        if ($need_filter)
            $sqi->add_rights_columns();
        // XXX some of this should be shared with paperQuery
        if (($need_filter && $this->conf->has_track_tags())
            || get($this->_query_options, "tags")
            || ($this->user->privChair
                && $this->conf->has_any_manager()
                && $this->conf->tags()->has_sitewide))
            $sqi->add_column("paperTags", "(select group_concat(' ', tag, '#', tagIndex separator '') from PaperTag where PaperTag.paperId=Paper.paperId)");
        if (get($this->_query_options, "reviewSignatures"))
            $sqi->add_review_signature_columns();
        foreach (get($this->_query_options, "scores", []) as $f)
            $sqi->add_score_columns($f);
        if (get($this->_query_options, "reviewWordCounts"))
            $sqi->add_review_word_count_columns();
        if ($this->conf->submission_blindness() == Conf::BLIND_OPTIONAL)
            $sqi->add_column("blind", "Paper.blind");

        // create query
        $sqi->finish_reviewer_columns();
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
        if (!empty($filters))
            $q .= "\n    where " . join("\n        and ", $filters);
        $q .= "\n    group by Paper.paperId";

        //Conf::msg_debugt($q);
        //error_log($q);

        // actually perform query
        return [$this->conf->qe_raw($q), $need_filter];
    }

    private function _prepare() {
        if ($this->_matches !== null)
            return;

        if ($this->limit() === "x") {
            $this->_matches = [];
            return true;
        }

        $qe = $this->term();
        //Conf::msg_debugt(json_encode($qe->debug_json()));

        // collect papers
        list($result, $need_filter) = $this->_prepare_result($qe);
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
            $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
            foreach ($rowset->all() as $row) {
                if (!$this->test_limit($row))
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
            }
            $this->user->set_overrides($old_overrides);
        } else {
            $this->_matches = $rowset->paper_ids();
        }

        // add deleted papers explicitly listed by number (e.g. action log)
        if ($this->_allow_deleted)
            $this->_add_deleted_papers($qe);

        // view and sort information
        $this->viewmap = $qe->get_float("view", array());
        $this->_add_sorters($qe, null);
        if ($qe->type === "then")
            for ($i = 0; $i < $qe->nthen; ++$i)
                $this->_add_sorters($qe->child[$i], $this->thenmap ? $i : null);

        // group information
        $this->groupmap = [];
        $sole_qe = $qe;
        if ($qe->type === "then")
            $sole_qe = $qe->nthen == 1 ? $qe->child[0] : null;
        if (!$sole_qe) {
            for ($i = 0; $i < $qe->nthen; ++$i) {
                $h = $qe->child[$i]->get_float("heading");
                if ($h === null) {
                    $span = $qe->child[$i]->get_float("strspan");
                    $spanstr = $qe->child[$i]->get_float("strspan_owner", $this->q);
                    $h = rtrim(substr($spanstr, $span[0], $span[1] - $span[0]));
                }
                $this->groupmap[$i] = TagAnno::make_heading($h);
            }
        } else if (($h = $sole_qe->get_float("heading")))
            $this->groupmap[0] = TagAnno::make_heading($h);
        else
            $this->_check_order_anno($sole_qe, $rowset);
    }

    function paper_ids() {
        $this->_prepare();
        return $this->_matches ? : [];
    }

    function sorted_paper_ids() {
        $this->_prepare();
        if ($this->_default_sort || $this->sorters) {
            $pl = new PaperList($this, ["sort" => $this->_default_sort]);
            return $pl->paper_ids();
        } else
            return $this->paper_ids();
    }

    function restrict_match($callback) {
        $m = [];
        foreach ($this->paper_ids() as $pid)
            if (call_user_func($callback, $pid))
                $m[] = $pid;
        if ($this->_matches !== false)
            $this->_matches = $m;
    }

    private function trivial_limit() {
        $limit = $this->limit();
        if ($this->user->has_hidden_papers())
            return false;
        else if ($limit === "und" || $limit === "acc" || $limit === "vis")
            return $this->privChair;
        else if ($limit === "rable" || $limit === "manager")
            return false;
        else
            return true;
    }

    function test_limit(PaperInfo $prow) {
        if (!$this->user->can_view_paper($prow))
            return false;
        switch ($this->limit()) {
        case "s":
            return $prow->timeSubmitted > 0;
        case "acc":
            return $prow->timeSubmitted > 0
                && $this->user->can_view_decision($prow)
                && $prow->outcome > 0;
        case "und":
            return $prow->timeSubmitted > 0
                && ($prow->outcome == 0
                    || !$this->user->can_view_decision($prow));
        case "unm":
            return $prow->timeSubmitted > 0 && $prow->managerContactId == 0;
        case "rable":
            $user = $this->reviewer_user();
            return $user->can_accept_review_assignment_ignore_conflict($prow)
                && ($this->conf->can_pc_see_all_submissions()
                    ? $prow->timeWithdrawn <= 0
                    : $prow->timeSubmitted > 0)
                && ($this->privChair
                    || $this->user === $user
                    || $this->user->can_administer($prow));
        case "act":
            return $prow->timeWithdrawn <= 0;
        case "r":
            return $prow->timeWithdrawn <= 0 && $prow->has_reviewer($this->user);
        case "unsub":
            return $prow->timeSubmitted <= 0 && $prow->timeWithdrawn <= 0;
        case "lead":
            return $prow->leadContactId == $this->cid;
        case "manager":
            return $prow->timeSubmitted > 0 && $this->user->allow_administer($prow);
        case "a":
            return $this->user->act_author_view($prow);
        case "ar":
            return $this->user->act_author_view($prow)
                || ($prow->timeWithdrawn <= 0 && $prow->has_reviewer($this->user));
        case "rout":
            foreach ($prow->reviews_of_user($this->user, $this->user->review_tokens()) as $rrow)
                if ($rrow->reviewNeedsSubmit != 0)
                    return true;
            return false;
        case "req":
            if ($prow->timeSubmitted <= 0)
                return false;
            foreach ($prow->reviews_by_id() as $rrow)
                if ($rrow->reviewType == REVIEW_EXTERNAL && $rrow->requestedBy == $this->cid)
                    return true;
            return false;
        case "all":
        case "vis":
            return true;
        default:
            return false;
        }
    }

    function test(PaperInfo $prow) {
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $qe = $this->term();
        $x = $this->test_limit($prow) && $qe->exec($prow, $this);
        $this->user->set_overrides($old_overrides);
        return $x;
    }

    function filter($prows) {
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $qe = $this->term();
        $results = [];
        foreach ($prows as $prow)
            if ($this->test_limit($prow) && $qe->exec($prow, $this))
                $results[] = $prow;
        $this->user->set_overrides($old_overrides);
        return $results;
    }

    function test_review(PaperInfo $prow, ReviewInfo $rrow) {
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $qe = $this->term();
        $this->test_review = $rrow;
        $x = $this->test_limit($prow) && $qe->exec($prow, $this);
        $this->test_review = null;
        $this->user->set_overrides($old_overrides);
        return $x;
    }

    function simple_search_options() {
        $limit = $xlimit = $this->limit();
        if ($this->q === "re:me"
            && ($xlimit === "s" || $xlimit === "act" || $xlimit === "rout" || $xlimit === "rable"))
            $xlimit = "r";
        if ($this->_matches !== null
            || ($this->q !== ""
                && ($this->q !== "re:me" || $xlimit !== "r"))
            || (!$this->privChair
                && $this->reviewer_user() !== $this->user)
            || ($this->conf->has_tracks()
                && !$this->privChair
                && !in_array($xlimit, ["a", "r", "ar"]))
            || ($this->conf->has_tracks()
                && $limit === "rable")
            || $this->user->has_hidden_papers())
            return false;
        if ($limit === "rable") {
            if ($this->reviewer_user()->isPC)
                $limit = $this->conf->can_pc_see_all_submissions() ? "act" : "s";
            else
                $limit = "r";
        }
        $queryOptions = [];
        if ($limit === "s")
            $queryOptions["finalized"] = 1;
        else if ($limit === "unsub") {
            $queryOptions["unsub"] = 1;
            $queryOptions["active"] = 1;
        } else if ($limit === "acc") {
            if ($this->privChair || $this->conf->can_all_author_view_decision()) {
                $queryOptions["accepted"] = 1;
                $queryOptions["finalized"] = 1;
            } else
                return false;
        } else if ($limit === "und") {
            $queryOptions["undecided"] = 1;
            $queryOptions["finalized"] = 1;
        } else if ($limit === "r")
            $queryOptions["myReviews"] = 1;
        else if ($limit === "rout")
            $queryOptions["myOutstandingReviews"] = 1;
        else if ($limit === "a") {
            // If complex author SQL, always do search the long way
            if ($this->user->act_author_view_sql("%", true))
                return false;
            $queryOptions["author"] = 1;
        } else if ($limit === "req" || $limit === "reqrevs")
            $queryOptions["myReviewRequests"] = 1;
        else if ($limit === "act")
            $queryOptions["active"] = 1;
        else if ($limit === "lead")
            $queryOptions["myLead"] = 1;
        else if ($limit === "unm")
            $queryOptions["finalized"] = $queryOptions["unmanaged"] = 1;
        else if ($limit !== "all"
                 && ($limit !== "vis" || !$this->privChair))
            return false; /* don't understand limit */
        if ($this->q === "re:me" && $limit !== "rout")
            $queryOptions["myReviews"] = 1;
        return $queryOptions;
    }

    function alternate_query() {
        if ($this->q !== ""
            && $this->q[0] !== "#"
            && preg_match('/\A' . TAG_REGEX . '\z/', $this->q)
            && $this->user->can_view_tags(null)
            && in_array($this->limit(), ["s", "all", "r"])) {
            if ($this->q[0] === "~")
                return "#" . $this->q;
            $result = $this->conf->qe("select paperId from PaperTag where tag=? limit 1", $this->q);
            if (count(Dbl::fetch_first_columns($result)))
                return "#" . $this->q;
        }
        return false;
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
        if ($listname)
            $lx = $this->conf->_($listname);
        else {
            $limit = $this->limit();
            if ($this->q === "re:me" && ($limit === "s" || $limit === "act"))
                $limit = "r";
            $lx = $this->conf->_c("search_types", get(self::$search_type_names, $limit, "Papers"));
        }
        if ($this->q === ""
            || ($this->q === "re:me" && $this->limit() === "s")
            || ($this->q === "re:me" && $this->limit() === "act"))
            return $lx;
        else if (($td = $this->_tag_description()))
            return "$td in $lx";
        else
            return "$lx search";
    }

    function listid($sort = null) {
        $rest = [];
        if ($this->_reviewer_user && $this->_reviewer_user->contactId !== $this->cid)
            $rest[] = "reviewer=" . urlencode($this->_reviewer_user->email);
        if ((string) $sort !== "")
            $rest[] = "sort=" . urlencode($sort);
        return "p/" . $this->limitName . "/" . urlencode($this->q)
            . ($rest ? "/" . join("&", $rest) : "");
    }

    static function unparse_listid($listid) {
        if (preg_match('{\Ap/([^/]+)/([^/]*)(?:|/([^/]*))\z}', $listid, $m)) {
            $args = ["t" => $m[1], "q" => urldecode($m[2])];
            if (isset($m[3]) && $m[3] !== "") {
                foreach (explode("&", $m[3]) as $arg) {
                    if (str_starts_with($arg, "sort="))
                        $args["sort"] = urldecode(substr($arg, 5));
                    else
                        // XXX `reviewer`
                        error_log(caller_landmark() . ": listid includes $arg");
                }
            }
            return $args;
        } else
            return null;
    }

    function create_session_list_object($ids, $listname, $sort = null) {
        $sort = $sort !== null ? $sort : $this->_default_sort;
        $l = new SessionList($this->listid($sort), $ids,
                             $this->description($listname), $this->urlbase);
        if ($this->field_highlighters())
            $l->highlight = $this->_match_preg_query ? : true;
        return $l;
    }

    function session_list_object() {
        return $this->create_session_list_object($this->sorted_paper_ids(), null);
    }

    function highlight_tags() {
        if ($this->_highlight_tags === null) {
            $this->_prepare();
            $this->_highlight_tags = array();
            foreach ($this->sorters ? : array() as $s)
                if ($s->type[0] === "#")
                    $this->_highlight_tags[] = substr($s->type, 1);
        }
        return $this->_highlight_tags;
    }


    function set_field_highlighter_query($q) {
        $ps = new PaperSearch($this->user, ["q" => $q]);
        $this->_match_preg = $ps->field_highlighters();
        $this->_match_preg_query = $q;
    }

    function field_highlighters() {
        if ($this->_match_preg === null) {
            $this->_match_preg = [];
            $this->term();
            if (!empty($this->regex)) {
                foreach (TextMatch_SearchTerm::$map as $k => $v)
                    if (isset($this->regex[$k]) && !empty($this->regex[$k]))
                        $this->_match_preg[$v] = Text::merge_pregexes($this->regex[$k]);
            }
        }
        return $this->_match_preg;
    }

    function field_highlighter($field) {
        return get($this->field_highlighters(), $field, "");
    }


    static function search_types(Contact $user, $reqtype = null) {
        $ts = [];
        if ($user->isPC) {
            if ($user->conf->can_pc_see_all_submissions())
                $ts[] = "act";
            $ts[] = "s";
            if ($user->conf->timePCViewDecision(false) && $user->conf->has_any_accepted())
                $ts[] = "acc";
        }
        if ($user->privChair) {
            $ts[] = "all";
            if (!$user->conf->can_pc_see_all_submissions() && $reqtype === "act")
                $ts[] = "act";
        }
        if ($user->is_reviewer())
            $ts[] = "r";
        if ($user->has_outstanding_review()
            || ($user->is_reviewer() && $reqtype === "rout"))
            $ts[] = "rout";
        if ($user->isPC) {
            if ($user->is_requester() || $reqtype === "req")
                $ts[] = "req";
            if (($user->conf->has_any_lead_or_shepherd() && $user->is_discussion_lead())
                || $reqtype === "lead")
                $ts[] = "lead";
            if (($user->privChair ? $user->conf->has_any_manager() : $user->is_manager())
                || $reqtype === "manager")
                $ts[] = "manager";
        }
        if ($user->is_author() || $reqtype === "a")
            $ts[] = "a";
        return self::expand_search_types($user, $ts);
    }

    static function manager_search_types(Contact $user) {
        if ($user->privChair) {
            if ($user->conf->has_any_manager())
                $ts = ["manager", "unm", "s"];
            else
                $ts = ["s"];
            array_push($ts, "acc", "und", "all");
        } else
            $ts = ["manager"];
        return self::expand_search_types($user, $ts);
    }

    static private function expand_search_types(Contact $user, $ts) {
        $topt = [];
        foreach ($ts as $t)
            $topt[$t] = $user->conf->_c("search_type", self::$search_type_names[$t]);
        return $topt;
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
                $sel_extra["tabindex"] = $tabindex;
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
        $res = [];
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);

        if ($this->amPC && (!$category || $category === "ss")) {
            foreach ($this->conf->saved_searches() as $k => $v)
                $res[] = "ss:" . $k;
        }

        array_push($res, "has:submission", "has:abstract");
        if ($this->amPC && $this->conf->has_any_manager())
            $res[] = "has:admin";
        if ($this->conf->has_any_lead_or_shepherd()
            && $this->user->can_view_lead(null))
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
        if ($this->conf->has_any_lead_or_shepherd()
            && $this->user->can_view_shepherd(null))
            $res[] = "has:shepherd";
        if ($this->user->is_reviewer())
            array_push($res, "has:review", "has:creview", "has:ireview", "has:preview", "has:primary", "has:secondary", "has:external", "has:comment", "has:aucomment");
        else if ($this->user->can_view_some_review())
            array_push($res, "has:review", "has:comment");
        if ($this->amPC && $this->conf->setting("extrev_approve") && $this->conf->setting("pcrev_editdelegate")
            && $this->user->is_requester())
            array_push($res, "has:approvable");
        foreach ($this->conf->resp_rounds() as $rrd) {
            if (!in_array("has:response", $res))
                $res[] = "has:response";
            if ($rrd->number)
                $res[] = "has:{$rrd->name}response";
        }
        if ($this->user->can_view_some_draft_response())
            foreach ($this->conf->resp_rounds() as $rrd) {
                if (!in_array("has:draftresponse", $res))
                    $res[] = "has:draftresponse";
                if ($rrd->number)
                    $res[] = "has:draft{$rrd->name}response";
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
        if ((!$category || $category === "style") && $this->user->can_view_tags()) {
            $res[] = array("pri" => -1, "nosort" => true, "i" => array("style:any", "style:none", "color:any", "color:none"));
            foreach ($this->conf->tags()->canonical_colors() as $t) {
                $res[] = "style:$t";
                if ($this->conf->tags()->is_known_style($t, TagMap::STYLE_BG))
                    $res[] = "color:$t";
            }
        }
        if (!$category || $category === "show" || $category === "hide") {
            $cats = array();
            $pl = new PaperList($this);
            foreach ($this->conf->paper_column_map() as $cname => $cj) {
                $cj = $this->conf->basic_paper_column($cname, $this->user);
                if ($cj && isset($cj->completion) && $cj->completion
                    && ($c = PaperColumn::make($this->conf, $cj))
                    && ($cat = $c->completion_name())
                    && $c->prepare($pl, 0)) {
                    $cats[$cat] = true;
                }
            }
            foreach ($this->conf->paper_column_factories() as $fxj) {
                if (!$this->conf->xt_allowed($fxj, $this->user)
                    || Conf::xt_disabled($fxj))
                    continue;
                if (isset($fxj->completion_callback)) {
                    Conf::xt_resolve_require($fxj);
                    foreach (call_user_func($fxj->completion_callback, $this->user, $fxj) as $c)
                        $cats[$c] = true;
                } else if (isset($fxj->completion) && is_string($fxj->completion))
                    $cats[$fxj->completion] = true;
            }
            foreach (array_keys($cats) as $cat)
                array_push($res, "show:$cat", "hide:$cat");
            array_push($res, "show:compact", "show:statistics", "show:rownumbers");
        }

        $this->user->set_overrides($old_overrides);
        return $res;
    }
}
