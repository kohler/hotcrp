<?php
// papersearch.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class SearchWord {
    /** @var string */
    public $qword;
    /** @var string */
    public $word;
    /** @var bool */
    public $quoted;
    public $keyword;
    /** @var ?bool */
    public $kwexplicit;
    public $kwdef;
    /** @var ?string */
    public $compar;
    /** @var ?string */
    public $cword;
    /** @param string $qword */
    function __construct($qword) {
        $this->qword = $this->word = $qword;
        $this->quoted = $qword !== "" && $qword[0] === "\""
            && strpos($qword, "\"", 1) === strlen($qword) - 1;
        if ($this->quoted) {
            $this->word = substr($qword, 1, -1);
        }
    }
    /** @param string $text
     * @return string */
    static function quote($text) {
        if ($text === ""
            || !preg_match('{\A[-A-Za-z0-9_.@/]+\z}', $text)) {
            $text = "\"" . str_replace("\"", "\\\"", $text) . "\"";
        }
        return $text;
    }
    /** @param string $text
     * @return string */
    static function unquote($text) {
        if ($text !== ""
            && $text[0] === "\""
            && strpos($text, "\"", 1) === strlen($text) - 1) {
            return substr($text, 1, -1);
        } else {
            return $text;
        }
    }
}

class SearchOperator {
    /** @var string */
    public $op;
    /** @var bool */
    public $unary;
    /** @var int */
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
            self::$list["("] = new SearchOperator("(", true, 0);
            self::$list[")"] = new SearchOperator(")", true, 0);
            self::$list["NOT"] = new SearchOperator("not", true, 8);
            self::$list["-"] = new SearchOperator("not", true, 8);
            self::$list["!"] = new SearchOperator("not", true, 8);
            self::$list["+"] = new SearchOperator("+", true, 8);
            self::$list["SPACE"] = new SearchOperator("space", false, 7);
            self::$list["AND"] = new SearchOperator("and", false, 6);
            self::$list["XOR"] = new SearchOperator("xor", false, 5);
            self::$list["OR"] = new SearchOperator("or", false, 4);
            self::$list["SPACEOR"] = new SearchOperator("or", false, 3);
            self::$list["THEN"] = new SearchOperator("then", false, 2);
            self::$list["HIGHLIGHT"] = new SearchOperator("highlight", false, 1, "");
        }
        return self::$list[$name] ?? null;
    }
}

class SearchScope {
    /** @var SearchOperator */
    public $op;
    /** @var ?SearchTerm */
    public $leftqe;
    /** @var array{int,int} */
    public $strspan;

    /** @param SearchOperator $op
     * @param ?SearchTerm $leftqe
     * @param array{int,int} $strspan */
    function __construct($op, $leftqe, $strspan) {
        $this->op = $op;
        $this->leftqe = $leftqe;
        $this->strspan = $strspan;
    }
}

class CanonicalizeScope {
    /** @var SearchOperator */
    public $op;
    /** @var list<string> */
    public $qe;

    /** @param SearchOperator $op
     * @param list<string> $qe */
    function __construct($op, $qe) {
        $this->op = $op;
        $this->qe = $qe;
    }
}

class SearchTerm {
    /** @var string */
    public $type;
    /** @var array<string,mixed> */
    public $float = [];

    /** @param string $type */
    function __construct($type) {
        $this->type = $type;
    }
    /** @param string|SearchOperator $op
     * @param list<SearchTerm> $terms
     * @return SearchTerm */
    static function combine($op, $terms) {
        $name = is_string($op) ? $op : $op->op;
        if ($name === "not") {
            $qr = new Not_SearchTerm;
        } else if (count($terms) === 1) {
            return $terms[0];
        } else if ($name === "and" || $name === "space") {
            $qr = new And_SearchTerm($name);
        } else if ($name === "or") {
            $qr = new Or_SearchTerm;
        } else if ($name === "xor") {
            $qr = new Xor_SearchTerm;
        } else {
            $qr = new Then_SearchTerm($op);
        }
        foreach (is_array($terms) ? $terms : [$terms] as $qt) {
            $qr->append($qt);
        }
        return $qr->finish();
    }
    /** @return SearchTerm */
    function negate() {
        $qr = new Not_SearchTerm;
        return $qr->append($this)->finish();
    }
    /** @return SearchTerm */
    function negate_if($negate) {
        return $negate ? $this->negate() : $this;
    }
    /** @return True_SearchTerm */
    static function make_float($float) {
        $qe = new True_SearchTerm;
        $qe->float = $float;
        return $qe;
    }

    /** @return bool */
    function is_false() {
        return false;
    }
    /** @return bool */
    function is_true() {
        return false;
    }
    /** @return bool */
    function is_uninteresting() {
        return false;
    }
    /** @param string $k */
    final function set_float($k, $v) {
        $this->float[$k] = $v;
    }
    /** @param string $k */
    function get_float($k) {
        return $this->float[$k] ?? null;
    }
    function apply_strspan($span) {
        $span1 = $this->float["strspan"] ?? null;
        if ($span && $span1) {
            $spanx = [min($span[0], $span1[0]), max($span[1], $span1[1])];
        } else {
            $spanx = $span ?? $span1;
        }
        $this->float["strspan"] = $spanx;
    }
    /** @param string $str */
    function set_strspan_owner($str) {
        if (!isset($this->float["strspan_owner"])) {
            $this->float["strspan_owner"] = $str;
        }
    }


    function debug_json() {
        return $this->type;
    }


    // apply rounds to reviewer searches
    function adjust_reviews(ReviewAdjustment_SearchTerm $revadj = null, PaperSearch $srch) {
        if ($this->get_float("used_revadj") && $revadj) {
            $revadj->used_revadj = true;
        }
        return $this;
    }


    /** @return bool */
    function trivial_rights(Contact $user, PaperSearch $srch) {
        return false;
    }


    static function andjoin_sqlexpr($ff) {
        if (empty($ff) || in_array("false", $ff, true)) {
            return "false";
        }
        $ff = array_filter($ff, function ($f) { return $f !== "true"; });
        if (empty($ff)) {
            return "true";
        } else if (count($ff) === 1) {
            return join("", $ff);
        } else {
            return "(" . join(" and ", $ff) . ")";
        }
    }
    static function orjoin_sqlexpr($q, $default = "false") {
        if (empty($q)) {
            return $default;
        } else if (in_array("true", $q, true)) {
            return "true";
        } else {
            return "(" . join(" or ", $q) . ")";
        }
    }

    /** @return string */
    function sqlexpr(SearchQueryInfo $sqi) {
        error_log("invalid SearchTerm::sqlexpr");
        return "false";
    }

    /** @return bool */
    function exec(PaperInfo $row, PaperSearch $srch) {
        error_log("invalid SearchTerm::exec");
        return false;
    }

    /** @return null|bool|array{type:string} */
    function script_expression(PaperInfo $row, PaperSearch $srch) {
        return $this->exec($row, $srch);
    }


    function extract_metadata($top, PaperSearch $srch) {
        if ($top && ($x = $this->get_float("contradiction_warning"))) {
            $srch->contradictions[$x] = true;
        }
    }
    /** @param bool $top
     * @return ?PaperColumn */
    function default_sort_column($top, PaperSearch $srch) {
        return null;
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
    /** @var list<SearchTerm> */
    public $child = [];

    function __construct($type) {
        parent::__construct($type);
    }
    static private function strip_sort($v) {
        $r = [];
        $copy = true;
        foreach ($v as $w) {
            if (preg_match('/\A([a-z]*)sort(:.*)\z/', $w, $m)) {
                $copy = $m[1] !== "";
                if ($copy) {
                    $r[] = $m[1] . $m[2];
                }
            } else if (!str_starts_with($w, "as:") || $copy) {
                $copy = true;
                $r[] = $w;
            }
        }
        return $r;
    }
    protected function append($term) {
        if ($term) {
            foreach ($term->float as $k => $v) {
                $v1 = $this->float[$k] ?? null;
                if ($k === "view" && $this->type === "then") {
                    $v = self::strip_sort($v);
                }
                if (($k === "view" || $k === "tags") && $v1) {
                    array_splice($this->float[$k], count($v1), 0, $v);
                } else if ($k === "strspan" && $v1) {
                    $this->apply_strspan($v);
                } else if (is_array($v1) && is_array($v)) {
                    $this->float[$k] = array_replace_recursive($v1, $v);
                } else if ($k !== "opinfo" || $v1 === null) {
                    $this->float[$k] = $v;
                }
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
        foreach ($this->child ? : array() as $qv) {
            if ($qv->type === $this->type) {
                assert($qv instanceof Op_SearchTerm);
                $qvs = array_merge($qvs, $qv->child);
            } else {
                $qvs[] = $qv;
            }
        }
        return $qvs;
    }
    /** @param list<SearchTerm> $newchild
     * @param bool $any */
    protected function _finish_combine($newchild, $any) {
        $qr = null;
        if (!$newchild) {
            $qr = $any ? new True_SearchTerm : new False_SearchTerm;
        } else if (count($newchild) == 1) {
            $qr = clone $newchild[0];
        }
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
            foreach ($this->child as $qv) {
                $qv->set_strspan_owner($str);
            }
        }
    }
    function debug_json() {
        $a = [];
        foreach ($this->child as $qv) {
            $a[] = $qv->debug_json();
        }
        return ["type" => $this->type, "child" => $a];
    }
    function adjust_reviews(ReviewAdjustment_SearchTerm $revadj = null, PaperSearch $srch) {
        foreach ($this->child as &$qv) {
            $qv = $qv->adjust_reviews($revadj, $srch);
        }
        return $this;
    }
    function trivial_rights(Contact $user, PaperSearch $srch) {
        foreach ($this->child as $ch) {
            if (!$ch->trivial_rights($user, $srch))
                return false;
        }
        return true;
    }
}

class Not_SearchTerm extends Op_SearchTerm {
    function __construct() {
        parent::__construct("not");
    }
    protected function finish() {
        unset($this->float["tags"]);
        $qv = $this->child ? $this->child[0] : null;
        $qr = null;
        if (!$qv || $qv->is_false()) {
            $qr = new True_SearchTerm;
        } else if ($qv->is_true()) {
            $qr = new False_SearchTerm;
        } else if ($qv instanceof Not_SearchTerm) {
            $qr = clone $qv->child[0];
        } else if ($qv instanceof ReviewAdjustment_SearchTerm) {
            $qr = clone $qv;
            $qr->negated = !$qr->negated;
        }
        if ($qr) {
            $qr->float = $this->float;
            return $qr;
        } else {
            return $this;
        }
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->negated = !$sqi->negated;
        $top = $sqi->top;
        $sqi->top = false;
        $ff = $this->child[0]->sqlexpr($sqi);
        if ($sqi->negated
            && !$this->child[0]->trivial_rights($sqi->user, $sqi->srch)) {
            $ff = "false";
        }
        $sqi->negated = !$sqi->negated;
        $sqi->top = $top;
        if ($ff === "false") {
            return "true";
        } else if ($ff === "true") {
            return "false";
        } else {
            return "not coalesce($ff,0)";
        }
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return !$this->child[0]->exec($row, $srch);
    }
    function script_expression(PaperInfo $row, PaperSearch $srch) {
        $x = $this->child[0]->script_expression($row, $srch);
        if ($x === null) {
            return null;
        } else if ($x === false || $x === true) {
            return !$x;
        } else {
            return ["type" => "not", "child" => [$x]];
        }
    }
}

class And_SearchTerm extends Op_SearchTerm {
    /** @param string $type */
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
            } else if ($qv->is_true()) {
                $any = true;
            } else if ($qv instanceof ReviewAdjustment_SearchTerm) {
                $revadj = $qv->apply($revadj, false);
            } else if ($qv->type === "pn" && $this->type === "space") {
                if (!$pn) {
                    $newchild[] = $pn = $qv;
                } else {
                    $pn->merge($qv);
                }
            } else {
                $newchild[] = $qv;
            }
        }
        if ($revadj) { // must come first
            array_unshift($newchild, $revadj);
        }
        return $this->_finish_combine($newchild, $any);
    }

    function adjust_reviews(ReviewAdjustment_SearchTerm $revadj = null, PaperSearch $srch) {
        $myrevadj = $used_revadj = null;
        if ($this->child[0] instanceof ReviewAdjustment_SearchTerm) {
            $myrevadj = $this->child[0];
            $used_revadj = $myrevadj->merge($revadj);
        }
        foreach ($this->child as &$qv) {
            if (!($qv instanceof ReviewAdjustment_SearchTerm))
                $qv = $qv->adjust_reviews($myrevadj ? : $revadj, $srch);
        }
        if ($myrevadj && !$myrevadj->used_revadj) {
            $this->child[0] = $myrevadj->promote($srch);
            if ($used_revadj) {
                $revadj->used_revadj = true;
            }
        }
        return $this;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $ff = array();
        foreach ($this->child as $subt) {
            $ff[] = $subt->sqlexpr($sqi);
        }
        return self::andjoin_sqlexpr($ff);
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        foreach ($this->child as $subt) {
            if (!$subt->exec($row, $srch))
                return false;
        }
        return true;
    }
    function script_expression(PaperInfo $row, PaperSearch $srch) {
        $ch = [];
        $ok = true;
        foreach ($this->child as $subt) {
            $x = $subt->script_expression($row, $srch);
            if ($x === null) {
                return null;
            } else if ($x === false) {
                $ok = false;
            } else if ($x !== true) {
                $ch[] = $x;
            }
        }
        if (!$ok || empty($ch)) {
            return $ok;
        } else if (count($ch) === 1) {
            return $ch[0];
        } else {
            return ["type" => "and", "child" => $ch];
        }
    }
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        foreach ($this->child as $qv) {
            $qv->extract_metadata($top, $srch);
        }
    }
    function default_sort_column($top, PaperSearch $srch) {
        $s = null;
        foreach ($this->child as $qv) {
            $s1 = $qv->default_sort_column($top, $srch);
            if ($s && $s1) {
                return null;
            }
            $s = $s ?? $s1;
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
            if ($qv->is_true()) {
                return self::make_float($this->float);
            } else if ($qv->is_false()) {
                // skip
            } else if ($qv instanceof ReviewAdjustment_SearchTerm) {
                $revadj = $qv->apply($revadj, true);
            } else if ($qv->type === "pn") {
                if (!$pn) {
                    $newchild[] = $pn = $qv;
                } else {
                    $pn->merge($qv);
                }
            } else {
                $newchild[] = $qv;
            }
        }
        if ($revadj) {
            array_unshift($newchild, $revadj);
        }
        return $this->_finish_combine($newchild, false);
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $top = $sqi->top;
        $sqi->top = false;
        $ff = [];
        foreach ($this->child as $subt) {
            $ff[] = $subt->sqlexpr($sqi);
        }
        $sqi->top = $top;
        return self::orjoin_sqlexpr($ff);
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        foreach ($this->child as $subt)
            if ($subt->exec($row, $srch))
                return true;
        return false;
    }
    static function make_script_expression($child, PaperInfo $row, PaperSearch $srch) {
        $ch = [];
        $ok = false;
        foreach ($child as $subt) {
            $x = $subt->script_expression($row, $srch);
            if ($x === null) {
                return null;
            } else if ($x === true) {
                $ok = true;
            } else if ($x !== false) {
                $ch[] = $x;
            }
        }
        if ($ok || empty($ch)) {
            return $ok;
        } else if (count($ch) === 1) {
            return $ch[0];
        } else {
            return ["type" => "or", "child" => $ch];
        }
    }
    function script_expression(PaperInfo $row, PaperSearch $srch) {
        return self::make_script_expression($this->child, $row, $srch);
    }
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        foreach ($this->child as $qv) {
            $qv->extract_metadata(false, $srch);
        }
    }
}

class Xor_SearchTerm extends Op_SearchTerm {
    function __construct() {
        parent::__construct("xor");
    }
    protected function finish() {
        $pn = $revadj = null;
        $newchild = [];
        foreach ($this->_flatten_children() as $qv) {
            if ($qv->is_false()) {
                // skip
            } else if ($qv instanceof ReviewAdjustment_SearchTerm) {
                $revadj = $qv->apply($revadj, true);
            } else if ($qv->type === "pn") {
                if (!$pn) {
                    $newchild[] = $pn = $qv;
                } else {
                    $pn->merge($qv);
                }
            } else {
                $newchild[] = $qv;
            }
        }
        if ($revadj) {
            array_unshift($newchild, $revadj);
        }
        return $this->_finish_combine($newchild, false);
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $top = $sqi->top;
        $sqi->top = false;
        $ff = [];
        foreach ($this->child as $subt) {
            $ff[] = "coalesce(" . $subt->sqlexpr($sqi) . ",0)";
        }
        $sqi->top = $top;
        return empty($ff) ? "false" : "(" . join(" xor ", $ff) . ")";
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $x = false;
        foreach ($this->child as $subt) {
            if ($subt->exec($row, $srch))
                $x = !$x;
        }
        return $x;
    }
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        foreach ($this->child as $qv) {
            $qv->extract_metadata(false, $srch);
        }
    }
}

class Then_SearchTerm extends Op_SearchTerm {
    /** @var bool */
    private $is_highlight;
    /** @var int */
    public $nthen = 0;
    /** @var list<int> */
    public $highlights = [];
    /** @var list<string> */
    public $highlight_types = [];

    function __construct(SearchOperator $op) {
        assert($op->op === "then" || $op->op === "highlight");
        parent::__construct("then");
        $this->is_highlight = $op->op === "highlight";
        if (isset($op->opinfo)) {
            $this->set_float("opinfo", $op->opinfo);
        }
    }
    protected function finish() {
        $opinfo = strtolower($this->get_float("opinfo") ?? "");
        $newvalues = $newhvalues = $newhmasks = $newhtypes = [];

        foreach ($this->child as $qvidx => $qv) {
            if ($qv && $qvidx && $this->is_highlight) {
                if ($qv instanceof Then_SearchTerm) {
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
            } else if ($qv && $qv instanceof Then_SearchTerm) {
                $pos = count($newvalues);
                for ($i = 0; $i < $qv->nthen; ++$i) {
                    $newvalues[] = $qv->child[$i];
                }
                for ($i = $qv->nthen; $i < count($qv->child); ++$i) {
                    $newhvalues[] = $qv->child[$i];
                }
                foreach ($qv->highlights as $hlmask) {
                    $newhmasks[] = $hlmask << $pos;
                }
                foreach ($qv->highlight_types as $hltype) {
                    $newhtypes[] = $hltype;
                }
            } else if ($qv) {
                $newvalues[] = $qv;
            }
        }

        $this->nthen = count($newvalues);
        $this->highlights = $newhmasks;
        $this->highlight_types = $newhtypes;
        array_splice($newvalues, $this->nthen, 0, $newhvalues);
        $this->child = $newvalues;
        return $this;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $top = $sqi->top;
        $sqi->top = false;
        $ff = [];
        foreach ($this->child as $subt) {
            $ff[] = $subt->sqlexpr($sqi);
        }
        $sqi->top = $top;
        return self::orjoin_sqlexpr(array_slice($ff, 0, $this->nthen), "true");
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        for ($i = 0; $i < $this->nthen; ++$i) {
            if ($this->child[$i]->exec($row, $srch))
                return true;
        }
        return false;
    }
    function script_expression(PaperInfo $row, PaperSearch $srch) {
        return Or_SearchTerm::make_script_expression(array_slice($this->child, 0, $this->nthen), $row, $srch);
    }
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        foreach ($this->child as $qv) {
            $qv->extract_metadata(false, $srch);
        }
    }
}

class Limit_SearchTerm extends SearchTerm {
    public $limit;
    public $lflag;

    const LFLAG_ACTIVE = 1;
    const LFLAG_SUBMITTED = 2;

    function __construct($conf, $limit) {
        parent::__construct("in");
        $this->limit = $limit;
        $this->lflag = 0;
        if (!in_array($limit, ["a", "ar", "viewable", "all"], true)) {
            if (in_array($limit, ["r", "act", "unsub"], true)
                || ($conf->can_pc_see_active_submissions()
                    && !in_array($limit, ["s", "acc"], true))) {
                $this->lflag = self::LFLAG_ACTIVE;
            } else {
                $this->lflag = self::LFLAG_SUBMITTED;
            }
        }
    }

    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        $word = PaperSearch::canonical_search_type($word);
        if ($word === "reviewable") {
            $u = $srch->reviewer_user();
            if ($srch->user->privChair || $srch->user === $u) {
                if ($u->can_accept_review_assignment_ignore_conflict(null)) {
                    if ($srch->conf->can_pc_see_active_submissions()) {
                        $word = "act";
                    } else {
                        $word = "s";
                    }
                } else if (!$u->isPC) {
                    $word = "r";
                }
            }
        }
        return new Limit_SearchTerm($srch->conf, $word);
    }

    function trivial_rights(Contact $user, PaperSearch $srch) {
        if ($user->has_hidden_papers()) {
            return false;
        } else if (in_array($this->limit, ["undec", "acc", "viewable"], true)) {
            return $user->privChair;
        } else if (in_array($this->limit, ["reviewable", "admin", "alladmin"], true)) {
            return false;
        } else {
            return true;
        }
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $ff = ["true"];
        if ($this->lflag === self::LFLAG_SUBMITTED) {
            $ff[] = "Paper.timeSubmitted>0";
        } else if ($this->lflag === self::LFLAG_ACTIVE) {
            $ff[] = "Paper.timeWithdrawn<=0";
        }

        if (in_array($this->limit, ["a", "ar", "alladmin", "admin"], true)) {
            $sqi->add_conflict_table();
        }
        if (in_array($this->limit, ["ar", "r", "rout"], true)) {
            $sqi->add_reviewer_columns();
            $act_reviewer_sql = $sqi->user->act_reviewer_sql("MyReviews");
            if ($sqi->top && $act_reviewer_sql !== "false") {
                $sqi->add_table("MyReviews", [$this->limit === "ar" ? "left join" : "join", "PaperReview", $act_reviewer_sql]);
            }
        } else {
            $act_reviewer_sql = "error";
        }

        switch ($this->limit) {
        case "all":
        case "viewable":
        case "s":
        case "act":
        case "reviewable":
            break;
        case "a":
            $ff[] = $sqi->user->act_author_view_sql("PaperConflict");
            break;
        case "ar":
            if ($act_reviewer_sql === "false") {
                $r = "false";
            } else if ($sqi->top) {
                $r = "MyReviews.reviewType is not null";
            } else {
                $r = "exists (select * from PaperReview where paperId=Paper.paperId and $act_reviewer_sql)";
            }
            $ff[] = "(" . $sqi->user->act_author_view_sql("PaperConflict") . " or (Paper.timeWithdrawn<=0 and $r))";
            break;
        case "r":
            // if top, the straight join suffices
            if ($act_reviewer_sql === "false") {
                $ff[] = "false";
            } else if (!$sqi->top) {
                $ff[] = "exists (select * from PaperReview where paperId=Paper.paperId and $act_reviewer_sql)";
            }
            break;
        case "rout":
            if ($act_reviewer_sql === "false") {
                $ff[] = "false";
            } else if ($sqi->top) {
                $ff[] = "MyReviews.reviewNeedsSubmit!=0";
            } else {
                $ff[] = "exists (select * from PaperReview where paperId=Paper.paperId and $act_reviewer_sql and reviewNeedsSubmit!=0)";
            }
            break;
        case "acc":
            $ff[] = "Paper.outcome>0";
            break;
        case "undec":
            $ff[] = "Paper.outcome=0";
            break;
        case "unsub":
            $ff[] = "Paper.timeSubmitted<=0";
            $ff[] = "Paper.timeWithdrawn<=0";
            break;
        case "lead":
            $ff[] = "Paper.leadContactId=" . $sqi->srch->cxid;
            break;
        case "alladmin":
            if ($sqi->user->privChair) {
                break;
            }
            /* FALLTHRU */
        case "admin":
            if ($sqi->user->is_track_manager()) {
                $ff[] = "(Paper.managerContactId=" . $sqi->srch->cxid . " or Paper.managerContactId=0)";
            } else {
                $ff[] = "Paper.managerContactId=" . $sqi->srch->cxid;
            }
            break;
        case "req":
            $ff[] = "exists (select * from PaperReview where paperId=Paper.paperId and reviewType=" . REVIEW_EXTERNAL . " and requestedBy=" . $sqi->srch->cxid . ")";
            break;
        default:
            $ff[] = "false";
            break;
        }

        return self::andjoin_sqlexpr($ff);
    }

    function exec(PaperInfo $row, PaperSearch $srch) {
        if (!$srch->user->can_view_paper($row)
            || ($this->lflag === self::LFLAG_SUBMITTED && $row->timeSubmitted <= 0)
            || ($this->lflag === self::LFLAG_ACTIVE && $row->timeWithdrawn > 0)) {
            return false;
        }
        switch ($this->limit) {
        case "all":
        case "viewable":
        case "s":
        case "act":
            return true;
        case "a":
            return $srch->user->act_author_view($row);
        case "ar":
            return $srch->user->act_author_view($row)
                || ($row->timeWithdrawn <= 0 && $row->has_reviewer($srch->user));
        case "r":
            return $row->has_reviewer($srch->user);
        case "rout":
            foreach ($row->reviews_of_user($srch->user, $srch->user->review_tokens()) as $rrow) {
                if ($rrow->reviewNeedsSubmit != 0)
                    return true;
            }
            return false;
        case "acc":
            return $row->outcome > 0
                && $srch->user->can_view_decision($row);
        case "undec":
            return $row->outcome == 0
                || !$srch->user->can_view_decision($row);
        case "reviewable":
            $user = $srch->reviewer_user();
            return $user->can_accept_review_assignment_ignore_conflict($row)
                && ($srch->user === $user
                    || $srch->user->allow_administer($row));
        case "unsub":
            return $row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0;
        case "lead":
            return $row->leadContactId === $srch->cxid;
        case "admin":
            return $srch->user->is_primary_administrator($row);
        case "alladmin":
            return $srch->user->allow_administer($row);
        case "req":
            foreach ($row->reviews_by_id() as $rrow) {
                if ($rrow->reviewType == REVIEW_EXTERNAL
                    && $rrow->requestedBy == $srch->cxid)
                    return true;
            }
            return false;
        default:
            return false;
        }
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
            if ($word === "any") {
                $word = true;
            } else if ($word === "none") {
                $word = false;
            }
        }
        return new TextMatch_SearchTerm($sword->kwdef->name, $word, $sword->quoted);
    }

    function trivial_rights(Contact $user, PaperSearch $srch) {
        return $this->trivial && !$this->authorish;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_column($this->field, "Paper.{$this->field}");
        if ($this->trivial && !$this->authorish) {
            return "Paper.{$this->field}!=''";
        } else {
            return "true";
        }
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        $data = $row->{$this->field};
        if ($this->authorish && !$srch->user->allow_view_authors($row)) {
            $data = "";
        }
        if ($data === "") {
            return $this->trivial === false;
        } else if ($this->trivial !== null) {
            return $this->trivial;
        } else {
            return $row->field_match_pregexes($this->regex, $this->field);
        }
    }
    function script_expression(PaperInfo $row, PaperSearch $srch) {
        if (!$this->trivial || $this->field === "authorInformation") {
            return null;
        } else {
            return ["type" => $this->field, "match" => $this->trivial];
        }
    }
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        if ($this->regex) {
            $srch->regex[$this->type][] = $this->regex;
        }
    }
}

class ReviewRating_SearchAdjustment {
    /** @var int|string */
    private $type;
    /** @var list<ReviewRating_SearchAdjustment> */
    private $child;
    /** @var ?CountMatcher */
    private $matcher;

    function __construct($type, $child = []) {
        $this->type = $type;
        $this->child = $child;
    }
    static function make_atom($type, CountMatcher $matcher) {
        $self = new ReviewRating_SearchAdjustment($type);
        $self->matcher = $matcher;
        return $self;
    }
    function must_exist() {
        if ($this->type === "and") {
            return $this->child[0]->must_exist() || $this->child[1]->must_exist();
        } else if ($this->type === "or") {
            return $this->child[0]->must_exist() && $this->child[1]->must_exist();
        } else if ($this->type === "not") {
            return false;
        } else {
            return !$this->matcher->test(0);
        }
    }
    private function _test($ratings) {
        if ($this->type === "and") {
            return $this->child[0]->_test($ratings) && $this->child[1]->_test($ratings);
        } else if ($this->type === "or") {
            return $this->child[0]->_test($ratings) || $this->child[1]->_test($ratings);
        } else if ($this->type === "not") {
            return !$this->child[0]->_test($ratings);
        } else {
            $n = count(array_filter($ratings, function ($r) { return ($r & $this->type) !== 0; }));
            return $this->matcher->test($n);
        }
    }
    function test(Contact $user, PaperInfo $prow, ReviewInfo $rrow) {
        if ($user->can_view_review_ratings($prow, $rrow, $user->privChair)) {
            $ratings = $rrow->ratings();
        } else {
            $ratings = [];
        }
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
        if (!$srch->user->isPC) {
            $rounds = null;
        } else if (strcasecmp($word, "none") == 0 || strcasecmp($word, "unnamed") == 0) {
            $rounds = [0];
        } else if (strcasecmp($word, "any") == 0) {
            $rounds = range(1, count($srch->conf->round_list()) - 1);
        } else {
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
            if ($srch->user->isPC && $srch->conf->setting("rev_ratings") == REV_RATINGS_NONE) {
                $srch->warn("Review ratings are disabled.");
            }
            return new False_SearchTerm;
        }
        $rate = null;
        $compar = "=0";
        if (strcasecmp($word, "none") == 0) {
            $rate = "any";
        } else if (preg_match('/\A(.+?)\s*(:?|[=!<>]=?|≠|≤|≥)\s*(\d*)\z/', $word, $m)
                   && ($m[3] !== "" || $m[2] === "")) {
            if ($m[3] === "") {
                $compar = ">0";
            } else if ($m[2] === "" || $m[2] === ":") {
                $compar = ($m[3] == 0 ? "=0" : ">=" . $m[3]);
            } else {
                $compar = $m[2] . $m[3];
            }
            $rate = self::parse_rate_name($m[1]);
        }
        if ($rate === null) {
            $srch->warn("Bad review rating query “" . htmlspecialchars($word) . "”.");
            return new False_SearchTerm;
        } else {
            $srch->_has_review_adjustment = true;
            $qv = new ReviewAdjustment_SearchTerm($srch->conf);
            $qv->ratings = ReviewRating_SearchAdjustment::make_atom($rate, new CountMatcher($compar));
            return $qv;
        }
    }
    static private function parse_rate_name($s) {
        if (strcasecmp($s, "any") == 0) {
            return ReviewInfo::RATING_GOODMASK | ReviewInfo::RATING_BADMASK;
        } else if ($s === "+" || strcasecmp($s, "good") == 0 || strcasecmp($s, "yes") == 0) {
            return ReviewInfo::RATING_GOODMASK;
        } else if ($s === "-" || strcasecmp($s, "bad") == 0 || strcasecmp($s, "no") == 0
                   || $s === "\xE2\x88\x92" /* unicode MINUS */) {
            return ReviewInfo::RATING_BADMASK;
        }
        foreach (ReviewInfo::$rating_bits as $bit => $name) {
            if (strcasecmp($s, $name) === 0)
                return $bit;
        }
        $x = Text::simple_search($s, ReviewInfo::$rating_options);
        unset($x[0]); // can't search for “average”
        if (count($x) == 1) {
            reset($x);
            return key($x);
        } else {
            return null;
        }
    }

    function merge(ReviewAdjustment_SearchTerm $x = null) {
        $changed = false;
        if ($x && $this->round === null && $x->round !== null) {
            $this->round = $x->round;
            $changed = true;
        }
        if ($x && $this->ratings === null && $x->ratings !== null) {
            $this->ratings = $x->ratings;
            $changed = true;
        }
        return $changed;
    }
    function promote(PaperSearch $srch) {
        $rsm = new ReviewSearchMatcher(">0");
        if (in_array($srch->limit(), ["r", "rout", "reviewable"], true)) {
            $rsm->add_contact($srch->cxid);
        } else if ($srch->limit() === "req") {
            $rsm->apply_requester($srch->cxid);
            $rsm->apply_review_type("external"); // XXX optional PC reviews?
        }
        $this->promote_matcher($rsm);
        $term = new Review_SearchTerm($rsm);
        return $term->negate_if($this->negated);
    }
    function promote_matcher(ReviewSearchMatcher $rsm) {
        if ($this->round !== null) {
            $rsm->adjust_round_list($this->round);
        }
        if ($this->ratings !== null) {
            $rsm->adjust_ratings($this->ratings);
        }
        $this->used_revadj = true;
    }
    function adjust_reviews(ReviewAdjustment_SearchTerm $revadj = null, PaperSearch $srch) {
        if ($revadj || $this->get_float("used_revadj")) {
            return $this;
        } else {
            return $this->promote($srch);
        }
    }
    function apply_negation() {
        if ($this->negated) {
            if ($this->round !== null) {
                $this->round = array_diff(array_keys($this->conf->round_list()), $this->round);
            }
            if ($this->ratings !== null) {
                $this->ratings = new ReviewRating_SearchAdjustment("not", [$this->ratings]);
            }
            $this->negated = false;
        }
    }
    function apply(ReviewAdjustment_SearchTerm $revadj = null, $is_or = false) {
        // XXX this is probably not right in fully general cases
        if (!$revadj) {
            return $this;
        }
        if ($revadj->negated !== $this->negated || ($revadj->negated && $is_or)) {
            $revadj->apply_negation();
            $this->apply_negation();
        }
        if ($is_or || $revadj->negated) {
            if ($this->round !== null) {
                $revadj->round = array_unique(array_merge($revadj->round, $this->round));
            }
            if ($this->ratings !== null && $revadj->ratings !== null) {
                $revadj->ratings = new ReviewRating_SearchAdjustment("or", [$this->ratings, $revadj->ratings]);
            } else if ($this->ratings !== null) {
                $revadj->ratings = $this->ratings;
            }
        } else {
            if ($revadj->round !== null && $this->round !== null) {
                $revadj->round = array_intersect($revadj->round, $this->round);
            } else if ($this->round !== null) {
                $revadj->round = $this->round;
            }
            if ($this->ratings !== null && $revadj->ratings !== null) {
                $revadj->ratings = new ReviewRating_SearchAdjustment("and", [$this->ratings, $revadj->ratings]);
            } else {
                $revadj->ratings = $this->ratings;
            }
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
        return SearchTerm::make_float(["view" => [$sword->kwdef->name . ":" . $sword->qword]]);
    }
    static function parse_heading($word, SearchWord $sword) {
        return SearchTerm::make_float(["heading" => simplify_whitespace($word)]);
    }
}

class PaperID_SearchTerm extends SearchTerm {
    /** @var list<array{int,int,int,bool,bool}> */
    private $r = [];
    /** @var int */
    private $n = 0;
    private $in_order = true;

    function __construct() {
        parent::__construct("pn");
    }
    /** @param int $p
     * @return int */
    private function lower_bound($p) {
        $l = 0;
        $r = count($this->r);
        while ($l < $r) {
            $m = $l + (($r - $l) >> 1);
            $x = $this->r[$m];
            if ($p < $x[0]) {
                $r = $m;
            } else if ($p >= $x[1]) {
                $l = $m + 1;
            } else {
                $l = $r = $m;
            }
        }
        return $l;
    }
    /** @return int|false */
    function position($p) {
        $i = $this->lower_bound($p);
        if ($i < count($this->r) && $p >= $this->r[$i][0]) {
            $d = $p - $this->r[$i][0];
            return $this->r[$i][2] + ($this->r[$i][3] ? -$d : $d);
        } else {
            return false;
        }
    }
    /** @param int $p0
     * @param int $p1
     * @param bool $rev */
    private function add_drange($p0, $p1, $rev, $explicit) {
        while ($p0 < $p1) {
            $i = $this->lower_bound($p0);
            if ($i < count($this->r) && $p0 >= $this->r[$i][0]) {
                $p0 = $this->r[$i][1];
                ++$i;
            }
            $p1x = $p1;
            if ($i < count($this->r) && $p1 >= $this->r[$i][0]) {
                $p1x = $this->r[$i][0];
            }
            if ($p0 < $p1x) {
                if ($rev || $i < count($this->r)) {
                    $this->in_order = false;
                }
                if ($i > 0
                    && $this->in_order
                    && $p0 === $this->r[$i - 1][1]) {
                    $this->r[$i - 1][1] = $p1x;
                    $this->r[$i - 1][4] = $this->r[$i - 1][4] && $explicit;
                } else {
                    $n = $this->n + ($rev ? $p1x - $p0 - 1 : 0);
                    array_splice($this->r, $i, 0, [[$p0, $p1x, $n, $rev, $explicit]]);
                }
                $this->n += $p1x - $p0;
            }
            $p0 = max($p0, $p1x);
        }
    }
    /** @param int $p0
     * @param int $p1 */
    function add_range($p0, $p1) {
        if ($p0 <= $p1) {
            $this->add_drange($p0, $p1 + 1, false, $p1 - $p0 <= 4);
        } else {
            $this->add_drange($p1, $p0 + 1, true, false);
        }
    }
    function merge(PaperID_SearchTerm $st) {
        $rs = $st->r;
        if (!$st->in_order) {
            usort($rs, function ($a, $b) { return $a[2] - $b[2]; });
        }
        foreach ($rs as $r) {
            $this->add_drange($r[0], $r[1], $r[3], $r[4]);
        }
    }
    /** @return list<int>|false */
    function paper_ids() {
        if ($this->n <= 1000) {
            $a = [];
            foreach ($this->r as $r) {
                for ($i = $r[0]; $i < $r[1]; ++$i) {
                    $a[] = $i;
                }
            }
            return $a;
        } else {
            return false;
        }
    }
    /** @return list<array{int,int,int,bool,bool}> */
    function ranges() {
        return $this->r;
    }
    /** @return bool */
    function is_empty() {
        return empty($this->r);
    }
    /** @param string $field
     * @return string */
    function sql_predicate($field) {
        if (empty($this->r)) {
            return "false";
        } else if ($this->n <= 8 * count($this->r)
                   && ($pids = $this->paper_ids()) !== false) {
            return "$field in (" . join(",", $pids) . ")";
        } else {
            $s = [];
            foreach ($this->r as $r) {
                $s[] = "({$field}>={$r[0]} and {$field}<{$r[1]})";
            }
            return "(" . join(" or ", $s) . ")";
        }
    }

    function trivial_rights(Contact $user, PaperSearch $srch) {
        return true;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        return $this->sql_predicate("Paper.paperId");
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return $this->position($row->paperId) !== false;
    }
    function default_sort_column($top, PaperSearch $srch) {
        if ($top && !$this->in_order) {
            return new PaperIDOrder_PaperColumn($srch->conf, $this);
        } else {
            return null;
        }
    }
}


class ContactCountMatcher extends CountMatcher {
    /** @var ?list<int> */
    private $_contacts = null;

    function __construct($countexpr, $contacts) {
        parent::__construct($countexpr);
        $this->set_contacts($contacts);
    }
    /** @return ?list<int> */
    function contact_set() {
        return $this->_contacts;
    }
    /** @return bool */
    function has_contacts() {
        return $this->_contacts !== null;
    }
    /** @param int $cid
     * @return bool */
    function has_sole_contact($cid) {
        return $this->_contacts !== null
            && count($this->_contacts) === 1
            && $this->_contacts[0] === $cid;
    }
    /** @param string $fieldname
     * @return string */
    function contact_match_sql($fieldname) {
        if ($this->_contacts === null) {
            return "true";
        } else {
            return $fieldname . sql_in_int_list($this->_contacts);
        }
    }
    /** @param int $cid
     * @return bool */
    function test_contact($cid) {
        return $this->_contacts === null || in_array($cid, $this->_contacts, true);
    }
    /** @param int $cid */
    function add_contact($cid) {
        $this->_contacts = $this->_contacts ?? [];
        if (!in_array($cid, $this->_contacts, true)) {
            $this->_contacts[] = $cid;
        }
    }
    /** @param null|int|list<int> $contacts */
    function set_contacts($contacts) {
        assert($contacts === null || is_array($contacts) || is_int($contacts));
        $this->_contacts = is_int($contacts) ? [$contacts] : $contacts;
    }
}

class SearchQueryInfo {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var PaperSearch
     * @readonly */
    public $srch;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var array<string,mixed> */
    public $tables = [];
    /** @var array<string,string> */
    public $columns = [];
    /** @var bool */
    public $negated = false;
    /** @var bool */
    public $top = true;
    private $_has_my_review = false;
    private $_has_review_signatures = false;
    private $_review_scores;

    function __construct(PaperSearch $srch) {
        $this->conf = $srch->conf;
        $this->srch = $srch;
        $this->user = $srch->user;
    }
    function add_table($table, $joiner = false) {
        // All added tables must match at most one Paper row each,
        // except MyReviews.
        assert($joiner || !count($this->tables));
        if (!isset($this->tables[$table])) {
            $this->tables[$table] = $joiner;
        } else if ($joiner && $joiner[0] === "join") {
            $this->tables[$table][0] = "join";
        }
    }
    function add_column($name, $expr) {
        assert(!isset($this->columns[$name]) || $this->columns[$name] === $expr);
        $this->columns[$name] = $expr;
    }
    function add_conflict_table() {
        if (!isset($this->tables["PaperConflict"])) {
            $this->add_table("PaperConflict", ["left join", "PaperConflict", "PaperConflict.contactId=" . ($this->user->contactId ? : -100)]);
        }
    }
    function add_conflict_columns() {
        $this->add_conflict_table();
        $this->columns["conflictType"] = "PaperConflict.conflictType";
    }
    function add_options_columns() {
        $this->columns["optionIds"] = "coalesce((select group_concat(PaperOption.optionId, '#', value) from PaperOption where paperId=Paper.paperId), '')";
    }
    function add_reviewer_columns() {
        $this->_has_my_review = true;
    }
    function finish_reviewer_columns() {
        if ($this->_has_review_signatures) {
            $this->add_column("reviewSignatures", "coalesce((select " . ReviewInfo::review_signature_sql($this->conf, $this->_review_scores) . " from PaperReview r where r.paperId=Paper.paperId), '')");
        }
        if ($this->_has_my_review) {
            $this->add_conflict_columns();
            $this->_add_review_permissions();
        }
    }
    private function _add_review_permissions() {
        if ($this->_has_review_signatures
            || isset($this->columns["myReviewPermissions"])) {
            // nada
        } else if (($act_reviewer_sql = $this->user->act_reviewer_sql("PaperReview")) === "false") {
            $this->add_column("myReviewPermissions", "''");
        } else if (isset($this->tables["MyReviews"])) {
            $this->add_column("myReviewPermissions", "coalesce(" . PaperInfo::my_review_permissions_sql("MyReviews.") . ", '')");
        } else {
            $this->add_column("myReviewPermissions", "coalesce((select " . PaperInfo::my_review_permissions_sql() . " from PaperReview where PaperReview.paperId=Paper.paperId and $act_reviewer_sql group by paperId), '')");
        }
    }
    function add_review_signature_columns() {
        $this->_has_review_signatures = true;
    }
    function add_score_columns($fid) {
        $this->add_review_signature_columns();
        if (($f = $this->conf->review_field($fid))
            && $f->main_storage
            && (!$this->_review_scores || !in_array($fid, $this->_review_scores))) {
            $this->_review_scores[] = $fid;
        }
    }
    function add_review_word_count_columns() {
        $this->add_review_signature_columns();
        if (!isset($this->columns["reviewWordCountSignature"])) {
            $this->add_column("reviewWordCountSignature", "coalesce((select group_concat(coalesce(reviewWordCount,'.') order by reviewId) from PaperReview where PaperReview.paperId=Paper.paperId), '')");
        }
    }
    function add_rights_columns() {
        // XXX could avoid the following if user is privChair for everything:
        $this->add_conflict_columns();
        $this->add_reviewer_columns();
    }
    function add_allConflictType_column() {
        if (!isset($this->columns["allConflictType"])) {
            $this->add_column("allConflictType", "coalesce((select group_concat(contactId, ' ', conflictType) from PaperConflict where PaperConflict.paperId=Paper.paperId), '')");
        }
    }
}

class PaperSearch {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var int
     * @readonly
     * @deprecated */
    public $cid;
    /** @var int
     * @readonly */
    public $cxid;

    /** @var Contact|null|false */
    private $_reviewer_user = false;
    /** @var string */
    private $_named_limit;
    /** @var SearchTerm */
    private $_limit_qe;

    /** @var ?string */
    private $_urlbase;
    /** @var bool
     * @readonly */
    public $expand_automatic = false;
    /** @var bool */
    private $_allow_deleted = false;

    /** @deprecated */
    public $warnings = [];
    private $_quiet_count = 0;

    /** @var string */
    public $q;
    /** @var string */
    private $_qt;
    /** @var list<string> */
    private $_qt_fields;
    /** @var ?SearchTerm */
    private $_qe;
    /** @var ?ReviewInfo */
    public $test_review;

    public $regex = [];
    public $contradictions = [];
    /** @var ?array<string,TextPregexes> */
    private $_match_preg;
    /** @var ?string */
    private $_match_preg_query;

    private $contact_match = [];
    public $_query_options = [];
    public $_has_review_adjustment = false;
    private $_ssRecursion = [];
    /** @var ?array<int,int> */
    public $thenmap;
    /** @var ?array<int,TagAnno> */
    public $groupmap;
    /** @var ?array<int,list<string>> */
    public $highlightmap;
    private $_default_sort; // XXX should be used more often
    /** @var ?list<string> */
    private $_highlight_tags;

    /** @var list<int> */
    private $_matches;

    static public $search_type_names = [
        "a" => "Your submissions",
        "acc" => "Accepted submissions",
        "act" => "Active submissions",
        "admin" => "Submissions you administer",
        "all" => "Everything",
        "alladmin" => "Submissions you’re allowed to administer",
        "lead" => "Your discussion leads",
        "admin" => "Submissions you administer",
        "r" => "Your reviews",
        "reviewable" => "Reviewable submissions",
        "req" => "Your review requests",
        "rout" => "Your incomplete reviews",
        "s" => "Submissions",
        "undec" => "Undecided submissions",
        "viewable" => "Submissions you can view"
    ];

    const LFLAG_SUBMITTED = 1;
    const LFLAG_ACTIVE = 2;


    // NB: `$options` can come from an unsanitized user request.
    /** @param string|array|Qrequest $options */
    function __construct(Contact $user, $options) {
        if (is_string($options)) {
            $options = ["q" => $options];
        }

        // contact facts
        $this->conf = $user->conf;
        $this->user = $user;
        /** @phan-suppress-next-line PhanDeprecatedProperty */
        $this->cid = $user->contactId;
        $this->cxid = $user->contactXid;

        // query fields
        // NB: If a complex query field, e.g., "re", "tag", or "option", is
        // default, then it must be the only default or query construction
        // will break.
        $this->_qt = self::_canonical_qt($options["qt"] ?? null);
        if ($this->_qt === "n") {
            $this->_qt_fields = ["ti", "ab"];
            if ($this->user->can_view_some_authors())
                $this->_qt_fields[] = "au";
        } else {
            $this->_qt_fields = [$this->_qt];
        }

        // the query itself
        $this->q = trim($options["q"] ?? "");
        $this->_default_sort = $options["sort"] ?? null;

        // reviewer
        if (($reviewer = $options["reviewer"] ?? null)) {
            if (is_string($reviewer)) {
                if (strcasecmp($reviewer, $user->email) == 0) {
                    $reviewer = $user;
                } else if ($user->can_view_pc()) {
                    $reviewer = $this->conf->pc_member_by_email($reviewer);
                } else {
                    $reviewer = null;
                }
            } else if (!is_object($reviewer) || !($reviewer instanceof Contact)) {
                $reviewer = null;
            }
            if ($reviewer) {
                assert($reviewer->contactId > 0);
                $this->_reviewer_user = $reviewer;
            }
        }

        // paper selection
        $limit = self::canonical_search_type($options["t"] ?? "");
        if (in_array($limit, ["a", "r", "ar", "rout", "viewable"], true)
            || ($user->privChair && in_array($limit, ["all", "unsub", "alladmin"], true))
            || ($user->isPC && in_array($limit, ["acc", "req", "lead", "reviewable",
                                                 "admin", "alladmin", "undec"], true))) {
            /* ok */
        } else if ($user->privChair && !$limit && $this->conf->time_edit_paper()) {
            $limit = "all";
        } else if (($user->privChair && $limit === "act")
                   || ($user->isPC
                       && in_array($limit, ["", "act", "all"], true)
                       && $this->conf->can_pc_see_active_submissions())) {
            $limit = "act";
        } else if ($user->isPC && in_array($limit, ["", "s", "act", "all"], true)) {
            $limit = "s";
        } else if ($limit === "reviewable") {
            $limit = "r";
        } else if (!$user->is_reviewer()) {
            $limit = "a";
        } else if (!$user->is_author()) {
            $limit = "r";
        } else {
            $limit = "ar";
        }
        $this->_named_limit = $limit;
        $lword = new SearchWord($limit);
        $this->_limit_qe = Limit_SearchTerm::parse($limit, $lword, $this);
    }

    /** @param bool $x
     * @return $this */
    function set_allow_deleted($x) {
        assert($this->_qe === null);
        $this->_allow_deleted = $x;
        return $this;
    }

    /** @param string $base
     * @param array $args
     * @return $this */
    function set_urlbase($base, $args = []) {
        assert($this->_urlbase === null);
        if (!isset($args["t"])) {
            $args["t"] = $this->_named_limit;
        }
        if (!isset($args["qt"]) && $this->_qt !== "n") {
            $args["qt"] = $this->_qt;
        }
        if (!isset($args["reviewer"])
            && $this->_reviewer_user
            && $this->_reviewer_user->contactId !== $this->cxid) {
            $args["reviewer"] = $this->_reviewer_user->email;
        }
        $this->_urlbase = $this->conf->hoturl_site_relative_raw($base, $args);
        return $this;
    }

    /** @param bool $x
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_expand_automatic($x) {
        assert($this->_qe === null);
        $this->expand_automatic = $x;
        return $this;
    }

    /** @return string */
    function limit() {
        return $this->_limit_qe->limit;
    }
    /** @return bool */
    function limit_submitted() {
        return $this->_limit_qe->lflag === Limit_SearchTerm::LFLAG_SUBMITTED;
    }
    /** @return bool */
    function limit_author() {
        return $this->_limit_qe->limit === "a";
    }
    /** @return bool */
    function limit_expect_nonsubmitted() {
        return in_array($this->_limit_qe->limit, ["a", "act", "all"])
            && $this->q !== "re:me";
    }
    /** @return bool */
    function limit_accepted() {
        return $this->_limit_qe->limit === "acc";
    }

    /** @return Contact */
    function reviewer_user() {
        return $this->_reviewer_user ? : $this->user;
    }

    /** @param string $text
     * @suppress PhanDeprecatedProperty */
    function warn($text) {
        if (!$this->_quiet_count) {
            $this->warnings[] = $text;
        }
    }

    /** @return bool
     * @suppress PhanDeprecatedProperty  */
    function has_messages() {
        return !empty($this->warnings);
    }
    /** @return int
     * @suppress PhanDeprecatedProperty */
    function message_count() {
        return count($this->warnings ?? []);
    }
    /** @return bool
     * @suppress PhanDeprecatedProperty */
    function has_warning() {
        return !empty($this->warnings);
    }
    /** @return bool
     * @suppress PhanDeprecatedProperty */
    function has_problem() {
        return !empty($this->warnings);
    }
    /** @return list<string>
     * @suppress PhanDeprecatedProperty */
    function warning_texts() {
        return $this->warnings;
    }
    /** @return list<string>
     * @suppress PhanDeprecatedProperty */
    function problem_texts() {
        return $this->warnings;
    }

    /** @return string */
    function urlbase() {
        if ($this->_urlbase === null) {
            $this->set_urlbase("search");
        }
        return $this->_urlbase;
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
        if (($text === "any" || $text === "" || $text === "yes") && !$quoted) {
            return ["", $compar ? : ">0"];
        } else if (($text === "none" || $text === "no") && !$quoted) {
            return ["", "=0"];
        } else if (!$compar && ctype_digit($text)) {
            return ["", "=" . $text];
        } else {
            return [$text, $compar ? : ">0"];
        }
    }

    static function check_tautology($compar) {
        if ($compar === "<0") {
            return new False_SearchTerm;
        } else if ($compar === ">=0") {
            return new True_SearchTerm;
        } else {
            return null;
        }
    }

    private function make_contact_match($type, $text) {
        foreach ($this->contact_match as $i => $cm) {
            if ($cm->type === $type && $cm->text === $text)
                return $cm;
        }
        return $this->contact_match[] = new ContactSearch($type, $text, $this->user);
    }

    private function matching_contacts_base($type, $word, $quoted, $pc_only) {
        if ($pc_only) {
            $type |= ContactSearch::F_PC;
        }
        if ($quoted) {
            $type |= ContactSearch::F_QUOTED;
        }
        if (!$quoted && $this->user->isPC) {
            $type |= ContactSearch::F_TAG;
        }
        $scm = $this->make_contact_match($type, $word);
        if ($scm->warn_html) {
            $this->warn($scm->warn_html);
        }
        return $scm;
    }
    function matching_uids($word, $quoted, $pc_only) {
        $scm = $this->matching_contacts_base(ContactSearch::F_USER, $word, $quoted, $pc_only);
        return $scm->user_ids();
    }
    function matching_contacts($word, $quoted, $pc_only) {
        $scm = $this->matching_contacts_base(ContactSearch::F_USER, $word, $quoted, $pc_only);
        return $scm->users();
    }
    function matching_special_uids($word, $quoted, $pc_only) {
        $scm = $this->matching_contacts_base(0, $word, $quoted, $pc_only);
        return $scm->has_error() ? null : $scm->user_ids();
    }

    static function decision_matchexpr(Conf $conf, $word, $quoted) {
        if (!$quoted) {
            if (strcasecmp($word, "yes") === 0) {
                return ">0";
            } else if (strcasecmp($word, "no") === 0) {
                return "<0";
            } else if (strcasecmp($word, "any") === 0) {
                return "!=0";
            }
        }
        return $conf->find_all_decisions($word);
    }

    static function status_field_matcher(Conf $conf, $word, $quoted = null) {
        if (strlen($word) >= 3
            && ($k = Text::simple_search($word, ["w0" => "withdrawn", "s0" => "submitted", "s1" => "ready", "s2" => "complete", "u0" => "in progress", "u1" => "unsubmitted", "u2" => "not ready", "u3" => "incomplete", "a0" => "active", "x0" => "no submission"]))) {
            $k = array_map(function ($x) { return $x[0]; }, array_keys($k));
            $k = array_unique($k);
            if (count($k) === 1) {
                if ($k[0] === "w") {
                    return ["timeWithdrawn", ">0"];
                } else if ($k[0] === "s") {
                    return ["timeSubmitted", ">0"];
                } else if ($k[0] === "u") {
                    return ["timeSubmitted", "<=0", "timeWithdrawn", "<=0"];
                } else if ($k[0] === "x") {
                    return ["timeSubmitted", "<=0", "timeWithdrawn", "<=0", "paperStorageId", "<=1"];
                } else {
                    return ["timeWithdrawn", "<=0"];
                }
            }
        }
        return ["outcome", self::decision_matchexpr($conf, $word, $quoted)];
    }

    static function parse_has($word, SearchWord $sword, PaperSearch $srch) {
        $lword = strtolower($word);
        if (($kwdef = $srch->conf->search_keyword($lword, $srch->user))) {
            if ($kwdef->parse_has_callback ?? null) {
                $qe = call_user_func($kwdef->parse_has_callback, $word, $sword, $srch);
            } else if ($kwdef->has ?? null) {
                $sword2 = new SearchWord($kwdef->has);
                $sword2->kwexplicit = true;
                $sword2->keyword = $lword;
                $sword2->kwdef = $kwdef;
                $qe = call_user_func($kwdef->parse_callback, $kwdef->has, $sword2, $srch);
            } else {
                $qe = null;
            }
            if ($qe && $sword->keyword === "no") {
                $qe = SearchTerm::combine("or", $qe)->negate();
            }
            if ($qe) {
                return $qe;
            }
        }
        $srch->warn("Unknown search “" . $sword->keyword . ":" . htmlspecialchars($word) . "”.");
        return new False_SearchTerm;
    }

    private function _expand_saved_search($word, $recursion) {
        if (isset($recursion[$word])) {
            return false;
        }
        $t = $this->conf->setting_data("ss:" . $word) ?? "";
        $search = json_decode($t);
        if ($search && is_object($search) && isset($search->q)) {
            return $search->q;
        } else {
            return null;
        }
    }

    static function parse_saved_search($word, SearchWord $sword, PaperSearch $srch) {
        if (!$srch->user->isPC) {
            return null;
        }
        if (($nextq = $srch->_expand_saved_search($word, $srch->_ssRecursion))) {
            $srch->_ssRecursion[$word] = true;
            $qe = $srch->_search_expression($nextq);
            unset($srch->_ssRecursion[$word]);
        } else {
            $qe = null;
        }
        if (!$qe) {
            if ($nextq === false) {
                $srch->warn("Saved search “" . htmlspecialchars($word) . "” is defined in terms of itself.");
            } else if (!$srch->conf->setting_data("ss:$word")) {
                $srch->warn("There is no “" . htmlspecialchars($word) . "” saved search.");
            } else {
                $srch->warn("The “" . htmlspecialchars($word) . "” saved search is defined incorrectly.");
            }
            $qe = new False_SearchTerm;
        }
        if ($nextq) {
            $qe->set_strspan_owner($nextq);
        }
        return $qe;
    }

    /** @param string $keyword
     * @param bool $kwexplicit */
    private function _search_keyword(&$qt, SearchWord $sword, $keyword, $kwexplicit) {
        $word = $sword->word;
        $sword->keyword = $keyword;
        $sword->kwexplicit = $kwexplicit;
        $sword->kwdef = $this->conf->search_keyword($keyword, $this->user);
        if ($sword->kwdef && ($sword->kwdef->parse_callback ?? null)) {
            $qx = call_user_func($sword->kwdef->parse_callback, $word, $sword, $this);
            if ($qx && !is_array($qx)) {
                $qt[] = $qx;
            } else if ($qx) {
                $qt = array_merge($qt, $qx);
            }
        } else {
            $this->warn("Unrecognized keyword “" . htmlspecialchars($keyword) . "”.");
        }
    }

    static private function _search_word_breakdown($word) {
        $ch = substr($word, 0, 1);
        if ($ch !== ""
            && (ctype_digit($ch) || ($ch === "#" && ctype_digit((string) substr($word, 1, 1))))
            && preg_match('/\A(?:#?\d+(?:(?:-|–|—)#?\d+)?(?:\s*,\s*|\z))+\z/', $word)) {
            return ["=", $word];
        } else if ($ch === "#") {
            return ["#", substr($word, 1)];
        } else if (preg_match('/\A([-_.a-zA-Z0-9]+|"[^"]")((?:[=!<>]=?|≠|≤|≥)[^:]+|:.*)\z/', $word, $m)) {
            return [$m[1], $m[2]];
        } else {
            return [false, $word];
        }
    }

    private function _search_word($word, $defkw) {
        $wordbrk = self::_search_word_breakdown($word);
        $keyword = $defkw;

        if ($wordbrk[0] === "=") {
            // paper numbers
            $st = new PaperID_SearchTerm;
            while (preg_match('/\A#?(\d+)(?:(?:-|–|—)#?(\d+))?\s*,?\s*(.*)\z/', $word, $m)) {
                $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
                $st->add_range(intval($m[1]), intval($m[2]));
                $word = $m[3];
            }
            return $st;
        } else if ($wordbrk[0] === "#") {
            // `#TAG`
            ++$this->_quiet_count;
            $qe = $this->_search_word("hashtag:" . $wordbrk[1], $defkw);
            --$this->_quiet_count;
            if (!$qe->is_false())
                return $qe;
        } else if ($wordbrk[0] !== false) {
            // `keyword:word` or (potentially) `keyword>word`
            if ($wordbrk[1][0] === ":") {
                $keyword = $wordbrk[0];
                $word = ltrim((string) substr($wordbrk[1], 1));
            } else {
                // Allow searches like "ovemer>2"; parse as "ovemer:>2".
                ++$this->_quiet_count;
                $qe = $this->_search_word($wordbrk[0] . ":" . $wordbrk[1], $defkw);
                --$this->_quiet_count;
                if (!$qe->is_false())
                    return $qe;
            }
        }
        if ($keyword && $keyword[0] === '"') {
            $keyword = trim(substr($keyword, 1, strlen($keyword) - 2));
        }

        $qt = [];
        $sword = new SearchWord($word);
        if ($keyword) {
            $this->_search_keyword($qt, $sword, $keyword, true);
        } else {
            // Special-case unquoted "*", "ANY", "ALL", "NONE", "".
            if ($word === "*" || $word === "ANY" || $word === "ALL"
                || $word === "") {
                return new True_SearchTerm;
            } else if ($word === "NONE") {
                return new False_SearchTerm;
            }
            // Otherwise check known keywords.
            foreach ($this->_qt_fields as $kw) {
                $this->_search_keyword($qt, $sword, $kw, false);
            }
        }
        return SearchTerm::combine("or", $qt);
    }

    static function escape_word($str) {
        $pos = SearchSplitter::span_balanced_parens($str);
        if ($pos === strlen($str)) {
            return $str;
        } else {
            return "\"" . str_replace("\"", "\\\"", $str) . "\"";
        }
    }

    static private function _shift_keyword(SearchSplitter $splitter, $curqe) {
        if (!$splitter->match('/\G(?:[-+!()]|(?:AND|and|OR|or|NOT|not|XOR|xor|THEN|then|HIGHLIGHT(?::\w+)?)(?=[\s\(]))/s', $m)) {
            return null;
        }
        $op = SearchOperator::get(strtoupper($m[0]));
        if (!$op) {
            $colon = strpos($m[0], ":");
            $op = clone SearchOperator::get(strtoupper(substr($m[0], 0, $colon)));
            $op->opinfo = substr($m[0], $colon + 1);
        }
        if ($curqe && $op->unary) {
            return null;
        }
        $splitter->shift_past($m[0]);
        return $op;
    }

    static private function _shift_word(SearchSplitter $splitter, Conf $conf) {
        if (($x = $splitter->shift()) === "") {
            return $x;
        }
        // some keywords may be followed by parentheses
        if (strpos($x, ":")
            && preg_match('/\A([-_.a-zA-Z0-9]+:|"[^"]+":)(?=[^"]|\z)/', $x, $m)) {
            if ($m[1][0] === "\"") {
                $kw = substr($m[1], 1, strlen($m[1]) - 2);
            } else {
                $kw = substr($m[1], 0, strlen($m[1]) - 1);
            }
            if (($kwdef = $conf->search_keyword($kw))
                && ($kwdef->allow_parens ?? false)
                && ($splitter->starts_with("(") || $splitter->starts_with("["))) {
                $lspan = $splitter->strspan[0];
                $x .= $splitter->shift_balanced_parens();
                $splitter->strspan[0] = $lspan;
            }
        }
        return $x;
    }

    /** @param ?SearchTerm $curqe
     * @param list<SearchScope> &$stack
     * @return ?SearchTerm */
    static private function _pop_expression_stack($curqe, &$stack) {
        $x = array_pop($stack);
        if ($curqe) {
            if ($x->leftqe) {
                $curqe = SearchTerm::combine($x->op, [$x->leftqe, $curqe]);
            } else if ($x->op->op !== "+" && $x->op->op !== "(") {
                $curqe = SearchTerm::combine($x->op, [$curqe]);
            }
            $curqe->apply_strspan($x->strspan);
            return $curqe;
        } else {
            return $x->leftqe;
        }
    }

    /** @param string $str
     * @return ?SearchTerm */
    private function _search_expression($str) {
        $stack = array();
        '@phan-var list<SearchScope> $stack';
        $defkwstack = array();
        $defkw = $next_defkw = null;
        $parens = 0;
        $curqe = null;
        $splitter = new SearchSplitter($str);

        while (!$splitter->is_empty()) {
            $op = self::_shift_keyword($splitter, $curqe);
            if ($curqe && !$op) {
                $op = SearchOperator::get("SPACE");
            }
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
                    && $splitter->match('/\G(?:|(?:THEN|then|HIGHLIGHT(?::\w+)?)(?:\s|\().*)\z/')) {
                    $word = $uword;
                }
                // Search like "ti:(foo OR bar)" adds a default keyword.
                if ($word[strlen($word) - 1] === ":"
                    && preg_match('/\A(?:[-_.a-zA-Z0-9]+:|"[^"]+":)\z/', $word)
                    && $splitter->starts_with("(")) {
                    $next_defkw = [substr($word, 0, strlen($word) - 1), $splitter->strspan[0]];
                } else {
                    // The heart of the matter.
                    $curqe = $this->_search_word($word, $defkw);
                    if (!$curqe->is_uninteresting()) {
                        $curqe->set_float("strspan", $splitter->strspan);
                    }
                }
            } else if ($op->op === ")") {
                while (!empty($stack)
                       && $stack[count($stack) - 1]->op->op !== "(") {
                    $curqe = self::_pop_expression_stack($curqe, $stack);
                }
                if (!empty($stack)) {
                    $stack[count($stack) - 1]->strspan[1] = $splitter->strspan[1];
                    $curqe = self::_pop_expression_stack($curqe, $stack);
                    --$parens;
                    $defkw = array_pop($defkwstack);
                }
            } else if ($op->op === "(") {
                assert(!$curqe);
                $stkelem = new SearchScope($op, null, $splitter->strspan);
                $defkwstack[] = $defkw;
                if ($next_defkw) {
                    $defkw = $next_defkw[0];
                    $stkelem->strspan[0] = $next_defkw[1];
                    $next_defkw = null;
                }
                $stack[] = $stkelem;
                ++$parens;
            } else if ($op->unary || $curqe) {
                $end_precedence = $op->precedence - ($op->precedence <= 1 ? 1 : 0);
                while (!empty($stack)
                       && $stack[count($stack) - 1]->op->precedence > $end_precedence) {
                    $curqe = self::_pop_expression_stack($curqe, $stack);
                }
                $stack[] = new SearchScope($op, $curqe, $splitter->strspan);
                $curqe = null;
            }
        }

        while (!empty($stack)) {
            $curqe = self::_pop_expression_stack($curqe, $stack);
        }
        return $curqe;
    }


    static private function _canonical_qt($qt) {
        if (in_array($qt, ["ti", "ab", "au", "ac", "co", "re", "tag"])) {
            return $qt;
        } else {
            return "n";
        }
    }

    /** @param string $curqe
     * @param list<CanonicalizeScope> &$stack
     * @return ?string */
    static private function _pop_canonicalize_stack($curqe, &$stack) {
        $x = array_pop($stack);
        if ($curqe) {
            $x->qe[] = $curqe;
        }
        if (empty($x->qe)) {
            return null;
        } else if ($x->op->unary) {
            $qe = $x->qe[0];
            if ($x->op->op === "not") {
                if (preg_match('/\A(?:[(-]|NOT )/i', $qe)) {
                    $qe = "NOT $qe";
                } else {
                    $qe = "-$qe";
                }
            }
            return $qe;
        } else if (count($x->qe) === 1) {
            return $x->qe[0];
        } else if ($x->op->op === "space") {
            return "(" . join(" ", $x->qe) . ")";
        } else {
            return "(" . join(" " . $x->op->unparse() . " ", $x->qe) . ")";
        }
    }

    static private function _canonical_expression($str, $type, $qt, Conf $conf) {
        $str = trim((string) $str);
        if ($str === "") {
            return "";
        }

        $stack = [];
        '@phan-var list<CanonicalizeScope> $stack';
        $parens = 0;
        $defaultop = $type === "all" ? "SPACE" : "SPACEOR";
        $curqe = null;
        $t = "";
        $splitter = new SearchSplitter($str);

        while (!$splitter->is_empty()) {
            $op = self::_shift_keyword($splitter, $curqe);
            if ($curqe && !$op) {
                $op = SearchOperator::get($parens ? "SPACE" : $defaultop);
            }
            if (!$op) {
                $curqe = self::_shift_word($splitter, $conf);
                if ($qt !== "n") {
                    $wordbrk = self::_search_word_breakdown($curqe);
                    if (!$wordbrk[0]) {
                        $curqe = ($qt === "tag" ? "#" : $qt . ":") . $curqe;
                    } else if ($wordbrk[1] === ":") {
                        $curqe .= $splitter->shift_balanced_parens();
                    }
                }
            } else if ($op->op === ")") {
                while (count($stack)
                       && $stack[count($stack) - 1]->op->op !== "(") {
                    $curqe = self::_pop_canonicalize_stack($curqe, $stack);
                }
                if (count($stack)) {
                    array_pop($stack);
                    --$parens;
                }
            } else if ($op->op === "(") {
                assert(!$curqe);
                $stack[] = new CanonicalizeScope($op, []);
                ++$parens;
            } else {
                $end_precedence = $op->precedence - ($op->precedence <= 1 ? 1 : 0);
                while (count($stack)
                       && $stack[count($stack) - 1]->op->precedence > $end_precedence) {
                    $curqe = self::_pop_canonicalize_stack($curqe, $stack);
                }
                $top = count($stack) ? $stack[count($stack) - 1] : null;
                if ($top && !$op->unary && $top->op->op === $op->op) {
                    $top->qe[] = $curqe;
                } else {
                    $stack[] = new CanonicalizeScope($op, [$curqe]);
                }
                $curqe = null;
            }
        }

        if ($type === "none") {
            array_unshift($stack, new CanonicalizeScope(SearchOperator::get("NOT"), []));
        }
        while (!empty($stack)) {
            $curqe = self::_pop_canonicalize_stack($curqe, $stack);
        }
        return $curqe;
    }

    static function canonical_query($qa, $qo, $qx, $qt, Conf $conf) {
        $qt = self::_canonical_qt($qt);
        $x = [];
        if (($qa = self::_canonical_expression($qa, "all", $qt, $conf)) !== "") {
            $x[] = $qa;
        }
        if (($qo = self::_canonical_expression($qo, "any", $qt, $conf)) !== "") {
            $x[] = $qo;
        }
        if (($qx = self::_canonical_expression($qx, "none", $qt, $conf)) !== "") {
            $x[] = $qx;
        }
        if (count($x) == 1) {
            return preg_replace('/\A\((.*)\)\z/', '$1', $x[0]);
        } else {
            return join(" AND ", $x);
        }
    }


    // CLEANING
    // Clean an input expression series into clauses.  The basic purpose of
    // this step is to combine all paper numbers into a single group, and to
    // assign review adjustments (rates & rounds).


    // QUERY CONSTRUCTION
    // Build a database query corresponding to an expression.
    // The query may be liberal (returning more papers than actually match);
    // QUERY EVALUATION makes it precise.

    static function unusable_ratings(Contact $user) {
        if ($user->privChair || $user->conf->setting("pc_seeallrev")) {
            return [];
        }
        $noratings = [];
        $rateset = $user->conf->setting("rev_rating");
        if ($rateset == REV_RATINGS_PC) {
            $npr_constraint = "reviewType>" . REVIEW_EXTERNAL;
        } else {
            $npr_constraint = "true";
        }
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


    /** @param SearchTerm $qe */
    private function _add_deleted_papers($qe) {
        if ($qe->type === "or" || $qe->type === "then") {
            assert($qe instanceof Op_SearchTerm);
            foreach ($qe->child as $subt) {
                $this->_add_deleted_papers($subt);
            }
        } else if ($qe->type === "pn") {
            assert($qe instanceof PaperID_SearchTerm);
            foreach ($qe->paper_ids() ? : [] as $p) {
                if (array_search($p, $this->_matches) === false)
                    $this->_matches[] = (int) $p;
            }
        }
    }

    /** @param SearchTerm $qe */
    private function _check_missing_papers($qe) {
        $ps = [];
        if ($qe->type === "or" || $qe->type === "then") {
            assert($qe instanceof Op_SearchTerm);
            foreach ($qe->child as $subt) {
                $ps = array_merge($ps, $this->_check_missing_papers($subt));
            }
        } else if ($qe->type === "pn") {
            assert($qe instanceof PaperID_SearchTerm);
            foreach ($qe->ranges() as $r) {
                for ($p = $r[0]; $p < $r[1] && $r[4]; ++$p) {
                    if (array_search($p, $this->_matches) === false) {
                        $ps[] = $p;
                    }
                }
            }
        }
        return $ps;
    }


    // BASIC QUERY FUNCTION

    /** @return SearchTerm */
    function term() {
        if ($this->_qe === null) {
            if ($this->q === "re:me") {
                $this->_qe = new Limit_SearchTerm($this->conf, "r");
            } else {
                // parse and clean the query
                $this->_qe = $this->_search_expression($this->q) ?? new True_SearchTerm;
            }
            //Conf::msg_debugt(json_encode($this->_qe->debug_json()));

            // apply review rounds (top down, needs separate step)
            if ($this->_has_review_adjustment) {
                $this->_qe = $this->_qe->adjust_reviews(null, $this);
            }

            // extract regular expressions and set _reviewer if the query is
            // about exactly one reviewer, and warn about contradictions
            $this->_qe->extract_metadata(true, $this);
            foreach ($this->contradictions as $contradiction => $garbage) {
                $this->warn($contradiction);
            }
        }
        return $this->_qe;
    }

    private function _prepare_result($qe) {
        $sqi = new SearchQueryInfo($this);
        $sqi->add_table("Paper");
        $sqi->add_column("paperId", "Paper.paperId");
        // always include columns needed by rights machinery
        $sqi->add_column("timeSubmitted", "Paper.timeSubmitted");
        $sqi->add_column("timeWithdrawn", "Paper.timeWithdrawn");
        $sqi->add_column("outcome", "Paper.outcome");
        $sqi->add_column("leadContactId", "Paper.leadContactId");
        $sqi->add_column("managerContactId", "Paper.managerContactId");
        if ($this->conf->submission_blindness() === Conf::BLIND_OPTIONAL) {
            $sqi->add_column("blind", "Paper.blind");
        }

        $filter = SearchTerm::andjoin_sqlexpr([
            $this->_limit_qe->sqlexpr($sqi), $qe->sqlexpr($sqi)
        ]);
        //Conf::msg_debugt($filter);
        if ($filter === "false") {
            return [Dbl_Result::make_empty(), false];
        }

        // add permissions tables if we will filter the results
        $need_filter = !$qe->trivial_rights($this->user, $this)
            || !$this->_limit_qe->trivial_rights($this->user, $this)
            || $this->conf->has_tracks() /* XXX probably only need check_track_view_sensitivity */
            || $qe->type === "then"
            || $qe->get_float("heading");

        if ($need_filter) {
            $sqi->add_rights_columns();
        }
        // XXX some of this should be shared with paperQuery
        if (($need_filter && $this->conf->rights_need_tags())
            || ($this->_query_options["tags"] ?? false)
            || ($this->user->privChair
                && $this->conf->has_any_manager()
                && $this->conf->tags()->has_sitewide)) {
            $sqi->add_column("paperTags", "coalesce((select group_concat(' ', tag, '#', tagIndex separator '') from PaperTag where PaperTag.paperId=Paper.paperId), '')");
        }
        if ($this->_query_options["reviewSignatures"] ?? false) {
            $sqi->add_review_signature_columns();
        }
        foreach ($this->_query_options["scores"] ?? [] as $f) {
            $sqi->add_score_columns($f);
        }
        if ($this->_query_options["reviewWordCounts"] ?? false) {
            $sqi->add_review_word_count_columns();
        }
        if ($this->_query_options["authorInformation"] ?? false) {
            $sqi->add_column("authorInformation", "Paper.authorInformation");
        }
        if ($this->_query_options["pdfSize"] ?? false) {
            $sqi->add_column("size", "Paper.size");
        }

        // create query
        $sqi->finish_reviewer_columns();
        $q = "select ";
        foreach ($sqi->columns as $colname => $value) {
            $q .= $value . " " . $colname . ", ";
        }
        $q = substr($q, 0, strlen($q) - 2) . "\n    from ";
        foreach ($sqi->tables as $tabname => $value) {
            if (!$value) {
                $q .= $tabname;
            } else {
                $joiners = array("$tabname.paperId=Paper.paperId");
                for ($i = 2; $i < count($value); ++$i) {
                    if ($value[$i])
                        $joiners[] = "(" . $value[$i] . ")";
                }
                $q .= "\n    " . $value[0] . " " . $value[1] . " as " . $tabname
                    . " on (" . join("\n        and ", $joiners) . ")";
            }
        }
        $q .= "\n    where $filter\n    group by Paper.paperId";

        //Conf::msg_debugt($q);
        //error_log($q);

        // actually perform query
        return [$this->conf->qe_raw($q), $need_filter];
    }

    private function _prepare() {
        if ($this->_matches !== null) {
            return;
        }

        if ($this->limit() === "x") {
            $this->_matches = [];
            return true;
        }

        $qe = $this->term();
        //Conf::msg_debugt(json_encode($qe->debug_json()));

        // collect papers
        list($result, $need_filter) = $this->_prepare_result($qe);
        $rowset = new PaperInfoSet;
        while (($row = PaperInfo::fetch($result, $this->user))) {
            $rowset->add($row);
        }
        Dbl::free($result);

        // correct query, create thenmap, groupmap, highlightmap
        $thqe = null;
        if ($qe instanceof Then_SearchTerm) {
            $thqe = $qe;
        }
        $this->thenmap = $thqe && $thqe->nthen > 1 ? [] : null;
        $this->highlightmap = [];
        $this->_matches = [];
        if ($need_filter) {
            $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
            foreach ($rowset as $row) {
                if (!$this->_limit_qe->exec($row, $this)) {
                    $x = false;
                } else if ($thqe) {
                    $x = false;
                    for ($i = 0; $i < $thqe->nthen && $x === false; ++$i) {
                        if ($thqe->child[$i]->exec($row, $this))
                            $x = $i;
                    }
                } else {
                    $x = !!$qe->exec($row, $this);
                }
                if ($x === false) {
                    continue;
                }
                $this->_matches[] = $row->paperId;
                if ($this->thenmap !== null) {
                    assert(is_int($x));
                    $this->thenmap[$row->paperId] = $x;
                }
                if ($thqe) {
                    for ($j = $thqe->nthen; $j < count($thqe->child); ++$j) {
                        if ($thqe->child[$j]->exec($row, $this)
                            && ($thqe->highlights[$j - $thqe->nthen] & (1 << $x))) {
                            $this->highlightmap[$row->paperId][] = $thqe->highlight_types[$j - $thqe->nthen];
                        }
                    }
                }
            }
            $this->user->set_overrides($old_overrides);
        } else {
            $this->_matches = $rowset->paper_ids();
        }

        // add deleted papers explicitly listed by number (e.g. action log)
        if ($this->_allow_deleted) {
            $this->_add_deleted_papers($qe);
        } else if ($this->_named_limit === "s"
                   && $this->user->privChair
                   && ($ps = $this->_check_missing_papers($qe))
                   && $this->conf->fetch_ivalue("select exists (select * from Paper where paperId?a)", $ps)) {
            $this->warn("Some incomplete or withdrawn submissions also match this search. " . Ht::link("Show all matching submissions", $this->conf->hoturl("search", ["t" => "all", "q" => $this->q])));
        }

        // group information
        $this->groupmap = [];
        $sole_qe = $qe;
        if ($thqe) {
            $sole_qe = $thqe->nthen == 1 ? $thqe->child[0] : null;
        }
        if ($thqe && !$sole_qe) {
            for ($i = 0; $i < $thqe->nthen; ++$i) {
                $h = $thqe->child[$i]->get_float("heading");
                if ($h === null) {
                    $span = $thqe->child[$i]->get_float("strspan") ?? [0, 0];
                    $spanstr = $thqe->child[$i]->get_float("strspan_owner") ?? $this->q;
                    $h = rtrim(substr($spanstr, $span[0], $span[1] - $span[0]));
                }
                $this->groupmap[$i] = TagAnno::make_heading($h);
            }
        } else if (($h = $sole_qe->get_float("heading"))) {
            $this->groupmap[0] = TagAnno::make_heading($h);
        }
    }

    /** @return list<int> */
    function paper_ids() {
        $this->_prepare();
        return $this->_matches;
    }

    /** @return list<int> */
    function sorted_paper_ids() {
        $this->_prepare();
        if ($this->_default_sort || $this->sort_field_list()) {
            $pl = new PaperList("empty", $this, ["sort" => $this->_default_sort]);
            return $pl->paper_ids();
        } else {
            return $this->paper_ids();
        }
    }

    /** @return array<int,list<string>> */
    function paper_highlights() {
        $this->_prepare();
        return $this->highlightmap ?? [];
    }

    /** @param iterable<string> $words
     * @return Generator<array{string,string,list<string>}> */
    static function view_generator($words) {
        $action = $keyword = "";
        $decorations = [];
        foreach ($words as $w) {
            $colon = strpos($w, ":");
            if ($colon === false
                || !in_array(substr($w, 0, $colon), ["show", "sort", "edit", "as", "hide", "showsort", "editsort"])) {
                $w = "show:" . $w;
                $colon = 4;
            }
            $a = substr($w, 0, $colon);
            $d = substr($w, $colon + 1);
            if (str_starts_with($d, "[")) {
                $d = substr($d, 1, strlen($d) - (str_ends_with($d, "]") ? 2 : 1));
            }
            if ($a === "as" || in_array($d, ["reverse", "forward", "up", "down", "by", "row", "column"])) {
                $decorations[] = $d;
            } else {
                if ($keyword !== "") {
                    yield [$action, $keyword, $decorations];
                    $decorations = [];
                }
                $action = $a;
                if (($k = SearchSplitter::split_balanced_parens($d))) {
                    $keyword = $k[0];
                    if (str_starts_with($keyword, "-")) {
                        $keyword = substr($keyword, 1);
                        $decorations[] = "reverse";
                    } else if (str_starts_with($keyword, "+")) {
                        $keyword = substr($keyword, 1);
                    }
                    if (count($k) > 1) {
                        $decorations = array_merge($decorations, array_slice($k, 1));
                    }
                } else {
                    $keyword = "";
                }
            }
        }
        if ($keyword !== "") {
            yield [$action, $keyword, $decorations];
        }
    }

    /** @param string|bool $action
     * @param string $keyword
     * @param ?list<string> $decorations
     * @return string */
    static function unparse_view($action, $keyword, $decorations) {
        if (is_bool($action)) {
            $action = $action ? "show" : "hide";
        }
        if (!ctype_alnum($keyword)
            && SearchSplitter::span_balanced_parens($keyword) !== strlen($keyword)) {
            $keyword = "\"" . $keyword . "\"";
        }
        if ($decorations) {
            return $action . ":[" . $keyword . " " . join(" ", $decorations) . "]";
        } else {
            return $action . ":" . $keyword;
        }
    }

    /** @return list<string> */
    private function sort_field_list() {
        $r = [];
        foreach (self::view_generator($this->term()->get_float("view") ?? []) as $akd) {
            if (str_ends_with($akd[0], "sort")) {
                $r[] = $akd[1];
            }
        }
        return $r;
    }

    function restrict_match($callback) {
        $m = [];
        foreach ($this->paper_ids() as $pid) {
            if (call_user_func($callback, $pid)) {
                $m[] = $pid;
            }
        }
        $this->_matches = $m;
    }

    /** @return bool */
    function test(PaperInfo $prow) {
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $qe = $this->term();
        $x = $this->_limit_qe->exec($prow, $this)
            && $qe->exec($prow, $this);
        $this->user->set_overrides($old_overrides);
        return $x;
    }

    /** @param PaperInfoSet|Iterable<PaperInfo> $prows
     * @return list<PaperInfo> */
    function filter($prows) {
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $qe = $this->term();
        $results = [];
        foreach ($prows as $prow) {
            if ($this->_limit_qe->exec($prow, $this)
                && $qe->exec($prow, $this)) {
                $results[] = $prow;
            }
        }
        $this->user->set_overrides($old_overrides);
        return $results;
    }

    /** @return bool */
    function test_review(PaperInfo $prow, ReviewInfo $rrow) {
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $qe = $this->term();
        $this->test_review = $rrow;
        $x = $this->_limit_qe->exec($prow, $this) && $qe->exec($prow, $this);
        $this->test_review = null;
        $this->user->set_overrides($old_overrides);
        return $x;
    }

    /** @return array<string,mixed>|false */
    function simple_search_options() {
        $limit = $xlimit = $this->limit();
        if ($this->q === "re:me"
            && in_array($xlimit, ["s", "act", "rout", "reviewable"])) {
            $xlimit = "r";
        }
        if ($this->_matches !== null
            || ($this->q !== ""
                && ($this->q !== "re:me" || $xlimit !== "r"))
            || (!$this->user->privChair
                && $this->reviewer_user() !== $this->user)
            || ($this->conf->has_tracks()
                && !$this->user->privChair
                && !in_array($xlimit, ["a", "r", "ar"], true))
            || ($this->conf->has_tracks()
                && $limit === "reviewable")
            || $this->user->has_hidden_papers()) {
            return false;
        }
        if ($limit === "reviewable") {
            if ($this->reviewer_user()->isPC) {
                $limit = $this->conf->can_pc_see_active_submissions() ? "act" : "s";
            } else {
                $limit = "r";
            }
        }
        $queryOptions = [];
        if ($limit === "s") {
            $queryOptions["finalized"] = 1;
        } else if ($limit === "unsub") {
            $queryOptions["unsub"] = 1;
            $queryOptions["active"] = 1;
        } else if ($limit === "acc") {
            if ($this->user->privChair || $this->conf->can_all_author_view_decision()) {
                $queryOptions["accepted"] = 1;
                $queryOptions["finalized"] = 1;
            } else {
                return false;
            }
        } else if ($limit === "undec") {
            $queryOptions["undecided"] = 1;
            $queryOptions["finalized"] = 1;
        } else if ($limit === "r") {
            $queryOptions["myReviews"] = 1;
        } else if ($limit === "rout") {
            $queryOptions["myOutstandingReviews"] = 1;
        } else if ($limit === "a") {
            // If complex author SQL, always do search the long way
            if ($this->user->act_author_view_sql("%", true)) {
                return false;
            }
            $queryOptions["author"] = 1;
        } else if ($limit === "req") {
            $queryOptions["myReviewRequests"] = 1;
        } else if ($limit === "act") {
            $queryOptions["active"] = 1;
        } else if ($limit === "lead") {
            $queryOptions["myLead"] = 1;
        } else if ($limit !== "all"
                   && ($limit !== "viewable" || !$this->user->privChair)) {
            return false; /* don't understand limit */
        }
        if ($this->q === "re:me" && $limit !== "rout") {
            $queryOptions["myReviews"] = 1;
        }
        return $queryOptions;
    }

    /** @return string|false */
    function alternate_query() {
        if ($this->q !== ""
            && $this->q[0] !== "#"
            && preg_match('/\A' . TAG_REGEX . '\z/', $this->q)
            && $this->user->can_view_tags(null)
            && in_array($this->limit(), ["s", "all", "r"], true)) {
            if ($this->q[0] === "~"
                || $this->conf->fetch_ivalue("select exists(select * from PaperTag where tag=?) from dual", $this->q)) {
                return "#" . $this->q;
            }
        }
        return false;
    }

    /** @return string */
    function url_site_relative_raw($q = null) {
        $url = $this->urlbase();
        $q = $q ?? $this->q;
        if ($q !== "" || substr($url, 0, 6) === "search") {
            $url .= (strpos($url, "?") === false ? "?" : "&")
                . "q=" . urlencode($q);
        }
        return $url;
    }

    /** @return string */
    function description($listname) {
        if ($listname) {
            $lx = $this->conf->_($listname);
        } else {
            $limit = $this->limit();
            if ($this->q === "re:me" && ($limit === "s" || $limit === "act")) {
                $limit = "r";
            }
            $lx = self::search_type_description($this->conf, $limit);
        }
        if ($this->q === ""
            || ($this->q === "re:me" && $this->limit() === "s")
            || ($this->q === "re:me" && $this->limit() === "act")) {
            return $lx;
        } else if (str_starts_with($this->q, "au:")
                   && strlen($this->q) <= 36
                   && $this->term() instanceof Author_SearchTerm) {
            return "$lx by " . htmlspecialchars(ltrim(substr($this->q, 3)));
        } else if (strlen($this->q) <= 24
                   || $this->term() instanceof Tag_SearchTerm) {
            return htmlspecialchars($this->q) . " in $lx";
        } else {
            return "$lx search";
        }
    }

    /** @param ?string $sort
     * @return string */
    function listid($sort = null) {
        $rest = [];
        if ($this->_reviewer_user
            && $this->_reviewer_user->contactXid !== $this->cxid) {
            $rest[] = "reviewer=" . urlencode($this->_reviewer_user->email);
        }
        if ($sort !== null && $sort !== "") {
            $rest[] = "sort=" . urlencode($sort);
        }
        return "p/" . $this->_named_limit . "/" . urlencode($this->q)
            . ($rest ? "/" . join("&", $rest) : "");
    }

    /** @param string $listid
     * @return ?array<string,string> */
    static function unparse_listid($listid) {
        if (preg_match('/\Ap\/([^\/]+)\/([^\/]*)(?:|\/([^\/]*))\z/', $listid, $m)) {
            $args = ["t" => $m[1], "q" => urldecode($m[2])];
            if (isset($m[3]) && $m[3] !== "") {
                foreach (explode("&", $m[3]) as $arg) {
                    if (str_starts_with($arg, "sort=")) {
                        $args["sort"] = urldecode(substr($arg, 5));
                    } else {
                        // XXX `reviewer`
                        error_log(caller_landmark() . ": listid includes $arg");
                    }
                }
            }
            return $args;
        } else {
            return null;
        }
    }

    /** @param list<int> $ids
     * @param ?string $listname
     * @param ?string $sort
     * @return SessionList */
    function create_session_list_object($ids, $listname, $sort = null) {
        $sort = $sort !== null ? $sort : $this->_default_sort;
        $l = new SessionList($this->listid($sort), $ids,
                             $this->description($listname), $this->urlbase());
        if ($this->field_highlighters()) {
            $l->highlight = $this->_match_preg_query ? : true;
        }
        return $l;
    }

    /** @return SessionList */
    function session_list_object() {
        return $this->create_session_list_object($this->sorted_paper_ids(), null);
    }

    /** @return list<string> */
    function highlight_tags() {
        if ($this->_highlight_tags === null) {
            $this->_prepare();
            $ht = $this->term()->float["tags"] ?? [];
            foreach ($this->sort_field_list() as $s) {
                if (($tag = Tagger::check_tag_keyword($s, $this->user)))
                    $ht[] = $tag;
            }
            $this->_highlight_tags = array_values(array_unique($ht));
        }
        return $this->_highlight_tags;
    }


    /** @param string $q */
    function set_field_highlighter_query($q) {
        $ps = new PaperSearch($this->user, ["q" => $q]);
        $this->_match_preg = $ps->field_highlighters();
        $this->_match_preg_query = $q;
    }

    /** @return array<string,TextPregexes> */
    function field_highlighters() {
        if ($this->_match_preg === null) {
            $this->_match_preg = [];
            $this->term();
            if (!empty($this->regex)) {
                foreach (TextMatch_SearchTerm::$map as $k => $v) {
                    if (isset($this->regex[$k])
                        && ($preg = Text::merge_pregexes($this->regex[$k])))
                        $this->_match_preg[$v] = $preg;
                }
            }
        }
        return $this->_match_preg;
    }

    /** @return string */
    function field_highlighter($field) {
        return ($this->field_highlighters())[$field] ?? "";
    }


    /** @return string */
    static function search_type_description(Conf $conf, $t) {
        return $conf->_c("search_type", self::$search_type_names[$t] ?? "Submissions");
    }

    /** @return string */
    static function canonical_search_type($reqtype) {
        if ($reqtype === 0 || $reqtype === "0") {
            return "";
        } else if ($reqtype === "manager") {
            return "admin";
        } else if ($reqtype === "vis" || $reqtype === "visible") {
            return "viewable";
        } else if ($reqtype === "und") {
            return "undec";
        } else if ($reqtype === "reqrevs") {
            return "req";
        } else if ($reqtype === "rable" || $reqtype === "editpref") {
            return "reviewable";
        } else {
            return $reqtype;
        }
    }

    /** @param ?string $reqtype
     * @return array<string,string> */
    static function search_types(Contact $user, $reqtype = null) {
        $ts = [];
        if ($reqtype === "viewable") {
            $ts[] = "viewable";
        }
        if ($user->isPC) {
            if ($user->conf->can_pc_see_active_submissions()) {
                $ts[] = "act";
            }
            $ts[] = "s";
            if ($user->conf->time_pc_view_decision(false)
                && $user->conf->has_any_accepted()) {
                $ts[] = "acc";
            }
        }
        if ($user->is_reviewer()) {
            $ts[] = "r";
        }
        if ($user->has_outstanding_review()
            || ($user->is_reviewer() && $reqtype === "rout")) {
            $ts[] = "rout";
        }
        if ($user->isPC) {
            if ($user->is_requester() || $reqtype === "req") {
                $ts[] = "req";
            }
            if ($user->is_discussion_lead() || $reqtype === "lead") {
                $ts[] = "lead";
            }
            if (($user->privChair ? $user->conf->has_any_manager() : $user->is_manager())
                || $reqtype === "admin") {
                $ts[] = "admin";
            }
            if ($reqtype === "alladmin") {
                $ts[] = "alladmin";
            }
        }
        if ($user->is_author() || $reqtype === "a") {
            $ts[] = "a";
        }
        if ($user->privChair
            && !$user->conf->can_pc_see_active_submissions()
            && $reqtype === "act") {
            $ts[] = "act";
        }
        if ($user->privChair) {
            $ts[] = "all";
        }
        return self::expand_search_types($user->conf, $ts);
    }

    /** @return array<string,string> */
    static function manager_search_types(Contact $user) {
        if ($user->privChair) {
            if ($user->conf->has_any_manager()) {
                $ts = ["admin", "alladmin", "s"];
            } else {
                $ts = ["s"];
            }
            array_push($ts, "acc", "undec", "all");
        } else {
            $ts = ["admin"];
        }
        return self::expand_search_types($user->conf, $ts);
    }

    /** @param list<string> $ts
     * @return array<string,string> */
    static private function expand_search_types(Conf $conf, $ts) {
        $topt = [];
        foreach ($ts as $t) {
            $topt[$t] = self::search_type_description($conf, $t);
        }
        return $topt;
    }

    static function searchTypeSelector($tOpt, $type, $extra = []) {
        if (count($tOpt) > 1) {
            $sel_opt = [];
            foreach ($tOpt as $k => $v) {
                if (count($sel_opt)
                    && $k === "a") {
                    $sel_opt["xxxa"] = null;
                } else if (count($sel_opt) > 2
                           && ($k === "lead" || $k === "r")
                           && !isset($sel_opt["xxxa"])) {
                    $sel_opt["xxxb"] = null;
                }
                $sel_opt[$k] = $v;
            }
            if (!isset($extra["aria-label"])) {
                $extra["aria-label"] = "Search collection";
            }
            return Ht::select("t", $sel_opt, $type, $extra);
        } else if (isset($extra["id"])) {
            return '<span id="' . htmlspecialchars($extra["id"]) . '">' . current($tOpt) . '</span>';
        } else {
            return current($tOpt);
        }
    }

    private static function simple_search_completion($prefix, $map, $flags = 0) {
        $x = array();
        foreach ($map as $id => $str) {
            $match = null;
            foreach (preg_split('/[^a-z0-9_]+/', strtolower($str)) as $word)
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

    /** @return list<string> */
    function search_completion($category = "") {
        $res = [];
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);

        if ($this->user->isPC && (!$category || $category === "ss")) {
            foreach ($this->conf->saved_searches() as $k => $v) {
                $res[] = "ss:" . $k;
            }
        }

        array_push($res, "has:submission", "has:abstract");
        if ($this->user->isPC && $this->conf->has_any_manager()) {
            $res[] = "has:admin";
        }
        if ($this->conf->has_any_lead_or_shepherd()
            && $this->user->can_view_lead(null)) {
            $res[] = "has:lead";
        }
        if ($this->user->can_view_some_decision()) {
            $res[] = "has:decision";
            if (!$category || $category === "dec") {
                $res[] = array("pri" => -1, "nosort" => true, "i" => array("dec:any", "dec:none", "dec:yes", "dec:no"));
                foreach ($this->conf->decision_map() as $d => $dname) {
                    if ($d !== 0) {
                        $res[] = "dec:" . SearchWord::quote($dname);
                    }
                }
            }
            if ($this->conf->setting("final_open")) {
                $res[] = "has:final";
            }
        }
        if ($this->conf->has_any_lead_or_shepherd()
            && $this->user->can_view_shepherd(null)) {
            $res[] = "has:shepherd";
        }
        if ($this->user->is_reviewer()) {
            array_push($res, "has:review", "has:creview", "has:ireview", "has:preview", "has:primary", "has:secondary", "has:external", "has:comment", "has:aucomment");
        } else if ($this->user->can_view_some_review()) {
            array_push($res, "has:review", "has:comment");
        }
        if ($this->user->isPC
            && $this->conf->ext_subreviews > 1
            && $this->user->is_requester()) {
            array_push($res, "has:pending-my-approval");
        }
        if ($this->user->is_manager()) {
            array_push($res, "has:proposal");
        }
        foreach ($this->conf->resp_rounds() as $rrd) {
            if (!in_array("has:response", $res, true)) {
                $res[] = "has:response";
            }
            if ($rrd->number) {
                $res[] = "has:{$rrd->name}response";
            }
        }
        if ($this->user->can_view_some_draft_response()) {
            foreach ($this->conf->resp_rounds() as $rrd) {
                if (!in_array("has:draftresponse", $res, true)) {
                    $res[] = "has:draftresponse";
                }
                if ($rrd->number) {
                    $res[] = "has:draft{$rrd->name}response";
                }
            }
        }
        if ($this->user->can_view_tags()) {
            array_push($res, "has:color", "has:style");
            if ($this->conf->tags()->has_badges) {
                $res[] = "has:badge";
            }
        }
        foreach ($this->user->user_option_list() as $o) {
            if ($this->user->can_view_some_option($o)
                && $o->search_keyword() !== false) {
                foreach ($o->search_examples($this->user, PaperOption::EXAMPLE_COMPLETION) as $sex) {
                    $res[] = $sex->q;
                }
            }
        }
        if ($this->user->is_reviewer() && $this->conf->has_rounds()
            && (!$category || $category === "round")) {
            $res[] = array("pri" => -1, "nosort" => true, "i" => ["round:any", "round:none"]);
            $rlist = array();
            foreach ($this->conf->round_list() as $rnum => $round) {
                if ($rnum && $round !== ";") {
                    $rlist[$rnum] = $round;
                }
            }
            $res = array_merge($res, self::simple_search_completion("round:", $rlist));
        }
        if ($this->conf->has_topics() && (!$category || $category === "topic")) {
            $topics = $this->conf->topic_set();
            foreach ($topics->group_list() as $tg) {
                if (count($tg) >= 4) {
                    $res[] = "topic:" . SearchWord::quote($tg[0]);
                }
                for ($i = 1; $i !== count($tg); ++$i) {
                    if (count($tg) < 3 || $topics[$tg[$i]] !== $tg[0]) {
                        $res[] = "topic:" . SearchWord::quote($topics[$tg[$i]]);
                    }
                }
            }
        }
        if ((!$category || $category === "style") && $this->user->can_view_tags()) {
            $res[] = array("pri" => -1, "nosort" => true, "i" => array("style:any", "style:none", "color:any", "color:none"));
            foreach ($this->conf->tags()->canonical_colors() as $t) {
                $res[] = "style:$t";
                if ($this->conf->tags()->is_known_style($t, TagMap::STYLE_BG)) {
                    $res[] = "color:$t";
                }
            }
        }
        if (!$category || $category === "show" || $category === "hide") {
            $cats = array();
            $pl = new PaperList("empty", $this);
            foreach ($this->conf->paper_column_map() as $cname => $cjj) {
                if (!($cjj[0]->deprecated ?? false)
                    && ($cj = $this->conf->basic_paper_column($cname, $this->user))
                    && isset($cj->completion)
                    && $cj->completion
                    && !str_starts_with($cj->name, "?")
                    && ($c = PaperColumn::make($this->conf, $cj))
                    && ($cat = $c->completion_name())
                    && $c->prepare($pl, 0)) {
                    $cats[$cat] = true;
                }
            }
            foreach ($this->conf->paper_column_factories() as $fxj) {
                if (!$this->conf->xt_allowed($fxj, $this->user)
                    || !Conf::xt_enabled($fxj)) {
                    continue;
                }
                if (isset($fxj->completion_callback)) {
                    Conf::xt_resolve_require($fxj);
                    foreach (call_user_func($fxj->completion_callback, $this->user, $fxj) as $c)
                        $cats[$c] = true;
                } else if (isset($fxj->completion) && is_string($fxj->completion)) {
                    $cats[$fxj->completion] = true;
                }
            }
            foreach (array_keys($cats) as $cat) {
                array_push($res, "show:$cat", "hide:$cat");
            }
            array_push($res, "show:compact", "show:statistics", "show:rownumbers");
        }

        $this->user->set_overrides($old_overrides);
        return $res;
    }
}
