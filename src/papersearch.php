<?php
// papersearch.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

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
        if ($this->quoted) {
            $this->word = substr($qword, 1, -1);
        }
    }
    static function quote($text) {
        if ($text === ""
            || !preg_match('{\A[-A-Za-z0-9_.@/]+\z}', $text)) {
            $text = "\"" . str_replace("\"", "\\\"", $text) . "\"";
        }
        return $text;
    }
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

class SearchSplitter {
    /** @var string */
    private $str;
    /** @var bool */
    private $utf8q;
    /** @var int */
    public $pos;
    /** @var array{int, int} */
    public $strspan;
    /** @param string $str */
    function __construct($str) {
        $this->str = $str;
        $this->utf8q = strpos($str, chr(0xE2)) !== false;
        $this->pos = 0;
        $this->set_span_and_pos("");
    }
    /** @return bool */
    function is_empty() {
        return $this->str === "";
    }
    /** @return string */
    function shift() {
        if ($this->utf8q
            && preg_match('/\A([-_.a-zA-Z0-9]+:|["“”][^"“”]+["“”]:|)\s*((?:["“”][^"“”]*(?:["“”]|\z)|[^"“”\s()]*)*)/su', $this->str, $m)) {
            $result = preg_replace('/[“”]/u', "\"", $m[1] . $m[2]);
        } else if (!$this->utf8q
                   && preg_match('/\A([-_.a-zA-Z0-9]+:|"[^"]+":|)\s*((?:"[^"]*(?:"|\z)|[^"\s()]*)*)/s', $this->str, $m)) {
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
    /** @param string $str */
    function shift_past($str) {
        assert(str_starts_with($this->str, $str));
        $this->set_span_and_pos($str);
    }
    /** @return string */
    function shift_balanced_parens() {
        $result = substr($this->str, 0, self::span_balanced_parens($this->str));
        $this->set_span_and_pos($result);
        return $result;
    }
    /** @param string $re
     * @param list<string> &$m @phan-output-reference */
    function match($re, &$m = null) {
        return preg_match($re, $this->str, $m);
    }
    /** @param string $substr */
    function starts_with($substr) {
        return str_starts_with($this->str, $substr);
    }
    private function set_span_and_pos($prefix) {
        $this->strspan = [$this->pos, $this->pos + strlen($prefix)];
        $next = substr($this->str, strlen($prefix));
        if ($this->utf8q) {
            $next = preg_replace('/\A\s+/u', "", $next);
        } else {
            $next = ltrim($next);
        }
        $this->pos += strlen($this->str) - strlen($next);
        $this->str = $next;
    }
    /** @param string $str
     * @param int $pos
     * @param ?callable(string):bool $endf
     * @return int */
    static function span_balanced_parens($str, $pos = 0, $endf = null) {
        $pstack = "";
        $plast = "";
        $quote = 0;
        $len = strlen($str);
        while ($pos < $len) {
            $ch = $str[$pos];
            // stop when done
            if ($plast === ""
                && !$quote
                && ($endf === null ? ctype_space($ch) : call_user_func($endf, $ch))) {
                break;
            }
            // translate “” -> "
            if ($ch === "\xE2"
                && $pos + 2 < $len
                && $str[$pos + 1] === "\x80"
                && (ord($str[$pos + 2]) & 0xFE) === 0x9C) {
                $ch = "\"";
                $pos += 2;
            }
            if ($quote) {
                if ($ch === "\\" && $pos + 1 < strlen($str)) {
                    ++$pos;
                } else if ($ch === "\"") {
                    $quote = 0;
                }
            } else if ($ch === "(") {
                $pstack .= $plast;
                $plast = ")";
            } else if ($ch === "[") {
                $pstack .= $plast;
                $plast = "]";
            } else if ($ch === "{") {
                $pstack .= $plast;
                $plast = "}";
            } else if ($ch === ")" || $ch === "]" || $ch === "}") {
                do {
                    $pcleared = $plast;
                    $plast = (string) substr($pstack, -1);
                    $pstack = (string) substr($pstack, 0, -1);
                } while ($ch !== $pcleared && $pcleared !== "");
                if ($pcleared === "") {
                    break;
                }
            } else if ($ch === "\"") {
                $quote = 1;
            }
            ++$pos;
        }
        return $pos;
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
    /** @return SearchTerm */
    static function make_op($op, $terms) {
        $opstr = is_object($op) ? $op->op : $op;
        if ($opstr === "not") {
            $qr = new Not_SearchTerm;
        } else if ($opstr === "and" || $opstr === "space") {
            $qr = new And_SearchTerm($opstr);
        } else if ($opstr === "or") {
            $qr = new Or_SearchTerm;
        } else if ($opstr === "xor") {
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
    function get_float($k, $defval = null) {
        return $this->float[$k] ?? $defval;
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

    function sqlexpr(SearchQueryInfo $sqi) {
        error_log("invalid SearchTerm::sqlexpr");
        return "false";
    }

    function exec(PaperInfo $row, PaperSearch $srch) {
        error_log("invalid SearchTerm::exec");
        return false;
    }

    function compile_condition(PaperInfo $row, PaperSearch $srch) {
        return null;
    }


    function extract_metadata($top, PaperSearch $srch) {
        if ($top && ($x = $this->get_float("contradiction_warning"))) {
            $srch->contradictions[$x] = true;
        }
    }
    /** @param int $thenval
     * @return ?ListSorter */
    function default_sorter($top, $thenval, PaperSearch $srch) {
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
    function compile_condition(PaperInfo $row, PaperSearch $srch) {
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
    function compile_condition(PaperInfo $row, PaperSearch $srch) {
        return true;
    }
}

class Op_SearchTerm extends SearchTerm {
    /** @var list<SearchTerm> */
    public $child = [];

    function __construct($type) {
        parent::__construct($type);
    }
    protected function append($term) {
        if ($term) {
            foreach ($term->float as $k => $v) {
                $v1 = $this->float[$k] ?? null;
                if (($k === "sort" || $k === "view" || $k === "tags") && $v1) {
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
        $a = [$this->type];
        foreach ($this->child as $qv) {
            $a[] = $qv->debug_json();
        }
        return $a;
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
    function compile_condition(PaperInfo $row, PaperSearch $srch) {
        $x = $this->child[0]->compile_condition($row, $srch);
        if ($x === null) {
            return null;
        } else if ($x === false || $x === true) {
            return !$x;
        } else {
            return (object) ["type" => "not", "child" => [$x]];
        }
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
        if ($revadj) // must come first
            array_unshift($newchild, $revadj);
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
    function compile_condition(PaperInfo $row, PaperSearch $srch) {
        $ch = [];
        $ok = true;
        foreach ($this->child as $subt) {
            $x = $subt->compile_condition($row, $srch);
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
            return (object) ["type" => "and", "child" => $ch];
        }
    }
    function extract_metadata($top, PaperSearch $srch) {
        parent::extract_metadata($top, $srch);
        foreach ($this->child as $qv) {
            $qv->extract_metadata($top, $srch);
        }
    }
    function default_sorter($top, $thenval, PaperSearch $srch) {
        $s = null;
        foreach ($this->child as $qv) {
            $s1 = $qv->default_sorter($top, $thenval, $srch);
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
    static function compile_or_condition($child, PaperInfo $row, PaperSearch $srch) {
        $ch = [];
        $ok = false;
        foreach ($child as $subt) {
            $x = $subt->compile_condition($row, $srch);
            if ($x === null)
                return null;
            else if ($x === true)
                $ok = true;
            else if ($x !== false)
                $ch[] = $x;
        }
        if ($ok || empty($ch)) {
            return $ok;
        } else if (count($ch) === 1) {
            return $ch[0];
        } else {
            return (object) ["type" => "or", "child" => $ch];
        }
    }
    function compile_condition(PaperInfo $row, PaperSearch $srch) {
        return self::compile_or_condition($this->child, $row, $srch);
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
        $opinfo = strtolower($this->get_float("opinfo", ""));
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
        $this->set_float("sort", []);
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
    function compile_condition(PaperInfo $row, PaperSearch $srch) {
        return Or_SearchTerm::compile_or_condition(array_slice($this->child, 0, $this->nthen), $row, $srch);
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
            $ff[] = "Paper.leadContactId=" . $sqi->srch->cid;
            break;
        case "alladmin":
            if ($sqi->user->privChair) {
                break;
            }
            /* FALLTHRU */
        case "admin":
            if ($sqi->user->is_track_manager()) {
                $ff[] = "(Paper.managerContactId=" . $sqi->srch->cid . " or Paper.managerContactId=0)";
            } else {
                $ff[] = "Paper.managerContactId=" . $sqi->srch->cid;
            }
            break;
        case "req":
            $ff[] = "exists (select * from PaperReview where paperId=Paper.paperId and reviewType=" . REVIEW_EXTERNAL . " and requestedBy=" . $sqi->srch->cid . ")";
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
            return $row->leadContactId == $srch->cid;
        case "admin":
            return $srch->user->is_primary_administrator($row);
        case "alladmin":
            return $srch->user->allow_administer($row);
        case "req":
            foreach ($row->reviews_by_id() as $rrow) {
                if ($rrow->reviewType == REVIEW_EXTERNAL
                    && $rrow->requestedBy == $srch->cid)
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
    function compile_condition(PaperInfo $row, PaperSearch $srch) {
        if (!$this->trivial || $this->field === "authorInformation") {
            return null;
        } else {
            return (object) ["type" => $this->field, "match" => $this->trivial];
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
            $rsm->add_contact($srch->cid);
        } else if ($srch->limit() === "req") {
            $rsm->apply_requester($srch->cid);
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
        $word = simplify_whitespace($word);
        $action = $sword->kwdef->view_action;
        if (str_starts_with($word, "-") && !$sword->kwdef->sorting) {
            $action = "hide";
            $word = substr($word, 1);
        }
        $f = [];
        $viewfield = $word;
        if ($word !== "" && $sword->kwdef->sorting) {
            $f["sort"] = [$word];
            $sort = PaperSearch::parse_sorter($viewfield);
            $viewfield = $sort->type;
        }
        if ($viewfield !== "" && $action) {
            $f["view"] = [[$viewfield, $action]];
        }
        return SearchTerm::make_float($f);
    }
    static function parse_heading($word, SearchWord $sword) {
        return SearchTerm::make_float(["heading" => simplify_whitespace($word)]);
    }
}

class PaperID_SearchTerm extends SearchTerm {
    private $r = [];
    private $n = 0;
    private $in_order = true;

    function __construct() {
        parent::__construct("pn");
    }
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
    function position($p) {
        $i = $this->lower_bound($p);
        if ($i < count($this->r) && $p >= $this->r[$i][0]) {
            $d = $p - $this->r[$i][0];
            return $this->r[$i][2] + ($this->r[$i][3] ? -$d : $d);
        } else {
            return false;
        }
    }
    private function add_drange($p0, $p1, $rev) {
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
                } else {
                    $n = $this->n + ($rev ? $p1x - $p0 - 1 : 0);
                    array_splice($this->r, $i, 0, [[$p0, $p1x, $n, $rev]]);
                }
                $this->n += $p1x - $p0;
            }
            $p0 = max($p0, $p1x);
        }
    }
    function add_range($p0, $p1) {
        if ($p0 <= $p1) {
            $this->add_drange($p0, $p1 + 1, false);
        } else {
            $this->add_drange($p1, $p0 + 1, true);
        }
    }
    function merge(PaperID_SearchTerm $st) {
        $rs = $st->r;
        if (!$st->in_order) {
            usort($rs, function ($a, $b) { return $a[2] - $b[2]; });
        }
        foreach ($rs as $r) {
            $this->add_drange($r[0], $r[1], $r[3]);
        }
    }
    function paper_ids() {
        if ($this->n <= 1000) {
            $a = [];
            foreach ($this->r as $r) {
                for ($i = $r[0]; $i < $r[1]; ++$i)
                    $a[] = $i;
            }
            return $a;
        } else {
            return false;
        }
    }

    function trivial_rights(Contact $user, PaperSearch $srch) {
        return true;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        if (empty($this->r)) {
            return "false";
        } else if (($pids = $this->paper_ids()) !== false) {
            return "Paper.paperId in (" . join(",", $pids) . ")";
        } else {
            $s = [];
            foreach ($this->r as $r) {
                $s[] = "(Paper.paperId>={$r[0]} and Paper.paperId<{$r[1]})";
            }
            return "(" . join(" or ", $s) . ")";
        }
    }
    function exec(PaperInfo $row, PaperSearch $srch) {
        return $this->position($row->paperId) !== false;
    }
    function compile_condition(PaperInfo $row, PaperSearch $srch) {
        return $this->exec($row, $srch);
    }
    function default_sorter($top, $thenval, PaperSearch $srch) {
        if ($top && !$this->in_order) {
            $s = ListSorter::make_field(new PaperIDOrder_PaperColumn($srch->conf, $this));
            $s->thenval = $thenval;
            return $s;
        } else {
            return null;
        }
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
        if ($this->_contacts === null) {
            return "true";
        } else {
            return $fieldname . sql_in_int_list($this->_contacts);
        }
    }
    function test_contact($cid) {
        return $this->_contacts === null || in_array($cid, $this->_contacts);
    }
    function add_contact($cid) {
        if ($this->_contacts === null) {
            $this->_contacts = [];
        }
        if (!in_array($cid, $this->_contacts)) {
            $this->_contacts[] = $cid;
        }
    }
    function set_contacts($contacts) {
        assert($contacts === null || is_array($contacts) || is_int($contacts));
        $this->_contacts = is_int($contacts) ? [$contacts] : $contacts;
    }
}

class SearchQueryInfo {
    public $conf;
    public $srch;
    public $user;
    public $tables = [];
    public $columns = [];
    public $negated = false;
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
    function add_reviewer_columns() {
        $this->_has_my_review = true;
    }
    function finish_reviewer_columns() {
        if ($this->_has_review_signatures) {
            $this->add_column("reviewSignatures", "(select " . ReviewInfo::review_signature_sql($this->conf, $this->_review_scores) . " from PaperReview r where r.paperId=Paper.paperId)");
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
            $this->add_column("myReviewPermissions", PaperInfo::my_review_permissions_sql("MyReviews."));
        } else {
            $this->add_column("myReviewPermissions", "(select " . PaperInfo::my_review_permissions_sql() . " from PaperReview where PaperReview.paperId=Paper.paperId and $act_reviewer_sql  group by paperId)");
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
            $this->add_column("reviewWordCountSignature", "(select group_concat(coalesce(reviewWordCount,'.') order by reviewId) from PaperReview where PaperReview.paperId=Paper.paperId)");
        }
    }
    function add_rights_columns() {
        if (!isset($this->columns["managerContactId"])) {
            $this->columns["managerContactId"] = "Paper.managerContactId";
        }
        if (!isset($this->columns["leadContactId"])) {
            $this->columns["leadContactId"] = "Paper.leadContactId";
        }
        // XXX could avoid the following if user is privChair for everything:
        $this->add_conflict_columns();
        $this->add_reviewer_columns();
    }
    function add_allConflictType_column() {
        if (!isset($this->columns["allConflictType"])) {
            $this->add_column("allConflictType", "(select group_concat(contactId, ' ', conflictType) from PaperConflict where PaperConflict.paperId=Paper.paperId)");
        }
    }
}

class PaperSearch {
    /** @var Conf
     * @phan-read-only */
    public $conf;
    /** @var Contact
     * @phan-read-only */
    public $user;
    /** @var int
     * @phan-read-only */
    public $cid;

    /** @var Contact|null|false */
    private $_reviewer_user = false;
    private $_named_limit;
    /** @var SearchTerm */
    private $_limit_qe;
    private $_urlbase;
    public $warnings = array();
    private $_quiet_count = 0;

    /** @var string */
    public $q;
    private $_qt;
    private $_qt_fields;
    /** @var ?SearchTerm */
    private $_qe;
    public $test_review;

    public $regex = [];
    public $contradictions = [];
    /** @var ?array<string,TextPregexes> */
    private $_match_preg;
    /** @var ?string */
    private $_match_preg_query;

    private $contact_match = array();
    public $_query_options = array();
    public $_has_review_adjustment = false;
    private $_ssRecursion = array();
    private $_allow_deleted = false;
    /** @var ?array<int,int> */
    public $thenmap;
    /** @var ?array<int,TagAnno> */
    public $groupmap;
    /** @var false|string */
    public $is_order_anno = false;
    /** @var ?array<int,list<string>> */
    public $highlightmap;
    /** @var list<ListSorter> */
    private $_sorters = [];
    private $_default_sort; // XXX should be used more often
    /** @var ?list<string> */
    private $_highlight_tags;

    /** @var list<int> */
    private $_matches;

    static private $_sort_keywords = ["by" => "by", "up" => "up", "down" => "down",
                "rev" => "down", "reverse" => "down", "reversed" => "down", "score" => ""];

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
    function __construct(Contact $user, $options) {
        if (is_string($options)) {
            $options = array("q" => $options);
        }

        // contact facts
        $this->conf = $user->conf;
        $this->user = $user;
        $this->cid = $user->contactId;

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
        } else if ($user->privChair && !$limit && $this->conf->timeUpdatePaper()) {
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

        // URL base
        $this->_urlbase = $options["pageurl"] ?? null;
        if ($this->_urlbase === null) {
            $this->_urlbase = $this->conf->hoturl_site_relative_raw("search");
        }
        $this->_urlbase = hoturl_add_raw($this->_urlbase, "t=" . urlencode($limit));
        if ($this->_qt !== "n") {
            $this->_urlbase = hoturl_add_raw($this->_urlbase, "qt=" . urlencode($this->_qt));
        }
        if ($this->_reviewer_user
            && $this->_reviewer_user->contactId !== $user->contactId) {
            assert(strpos($this->_urlbase, "reviewer=") === false);
            $this->_urlbase = hoturl_add_raw($this->_urlbase, "reviewer=" . urlencode($this->_reviewer_user->email));
        }
        assert(strpos($this->_urlbase, "&amp;") === false);

        $this->_named_limit = $limit;
        $lword = new SearchWord($limit);
        $this->_limit_qe = Limit_SearchTerm::parse($limit, $lword, $this);
    }

    function set_allow_deleted($x) {
        assert($this->_qe === null);
        $this->_allow_deleted = $x;
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

    /** @return Contact */
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
        if (!$quoted && $this->user->isPC)
            $type |= ContactSearch::F_TAG;
        $scm = $this->make_contact_match($type, $word);
        if ($scm->warn_html)
            $this->warn($scm->warn_html);
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
        return ["outcome", self::decision_matchexpr($conf, $word, $quoted)];
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
            return new Conflict_SearchTerm(">0", $contacts, false);
        }
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
                if (is_array($qe)) {
                    $qe = SearchTerm::make_op("or", $qe);
                }
                $qe = $qe->negate();
            }
            if ($qe) {
                return $qe;
            }
        }
        $srch->warn("Unknown search “" . $sword->keyword . ":" . htmlspecialchars($word) . "”.");
        return new False_SearchTerm;
    }

    /** @return ListSorter */
    static function parse_sorter($text) {
        $text = str_replace("\"", "", simplify_whitespace($text));
        $sort = ListSorter::make_empty($text === "");
        if (($ch1 = substr($text, 0, 1)) === "-" || $ch1 === "+") {
            $sort->reverse = $ch1 === "-";
            $text = ltrim(substr($text, 1));
        }

        // separate text into words
        $words = [];
        $bypos = false;
        while (true) {
            preg_match('{\A[,\s]*([^\s\(,]*)(.*)\z}s', $text, $m);
            if ($m[1] === "" && $m[2] === "") {
                break;
            }
            if (substr($m[2], 0, 1) === "(") {
                $pos = SearchSplitter::span_balanced_parens($m[2]);
                $m[1] .= substr($m[2], 0, $pos);
                $m[2] = substr($m[2], $pos);
            }
            $words[] = $m[1];
            $text = ltrim($m[2]);
            if ($m[1] === "by" && $bypos === false) {
                $bypos = count($words) - 1;
            }
        }

        // go over words
        $next_words = array();
        for ($i = 0; $i != count($words); ++$i) {
            $w = $words[$i];
            if ($bypos === false || $i > $bypos) {
                if (($x = self::$_sort_keywords[$w] ?? null) !== null) {
                    if ($x === "up") {
                        $sort->reverse = false;
                    } else if ($x === "down") {
                        $sort->reverse = true;
                    }
                    continue;
                } else if (($x = ListSorter::canonical_short_score_sort($w))) {
                    $sort->score = $x;
                    continue;
                } else if ($i > $bypos) {
                    $sort->anno[] = $w;
                    continue;
                }
            }
            if ($bypos === false || $i < $bypos) {
                $next_words[] = $w;
            }
        }

        if (!empty($next_words)) {
            $sort->type = join(" ", $next_words);
        }
        return $sort;
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
        return SearchTerm::make_op("or", $qt);
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
        if (!$splitter->match('/\A(?:[-+!()]|(?:AND|and|OR|or|NOT|not|XOR|xor|THEN|then|HIGHLIGHT(?::\w+)?)(?=[\s\(]))/s', $m)) {
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
            if ($m[1][0] === "\"") {
                $kw = substr($m[1], 1, strlen($m[1]) - 2);
            } else {
                $kw = substr($m[1], 0, strlen($m[1]) - 1);
            }
            if (($kwdef = $conf->search_keyword($kw))
                && $splitter->starts_with("(")
                && ($kwdef->allow_parens ?? false)) {
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
                $curqe = SearchTerm::make_op($x->op, [$x->leftqe, $curqe]);
            } else if ($x->op->op !== "+" && $x->op->op !== "(") {
                $curqe = SearchTerm::make_op($x->op, [$curqe]);
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
                    && $splitter->match('/\A(?:|(?:THEN|then|HIGHLIGHT(?::\w+)?)(?:\s|\().*)\z/')) {
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


    // QUERY EVALUATION
    // Check the results of the query, reducing the possibly conservative
    // overestimate produced by the database to a precise result.

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


    // BASIC QUERY FUNCTION

    /** @param SearchTerm $qe
     * @param int $thenval */
    private function _add_sorters($qe, $thenval) {
        if (($sorters = $qe->get_float("sort"))) {
            foreach ($sorters as $s) {
                if (($sx = self::parse_sorter($s))) {
                    $sx->thenval = $thenval;
                    $this->_sorters[] = $sx;
                }
            }
        } else if (($sx = $qe->default_sorter(true, $thenval, $this))) {
            $this->_sorters[] = $sx;
        }
    }

    /** @param int $g
     * @param TagInfo $dt
     * @param int $anno_index */
    private function _assign_order_anno_group($g, $dt, $anno_index) {
        if (($ta = $dt->order_anno_entry($anno_index))) {
            $this->groupmap[$g] = $ta;
        } else if (!isset($this->groupmap[$g])) {
            $ta = new TagAnno;
            $ta->tag = $dt->tag;
            $ta->heading = "";
            $this->groupmap[$g] = $ta;
        }
    }

    /** @param SearchTerm $qe
     * @return ?TagInfo */
    private function _find_order_anno_tag($qe) {
        $thetag = null;
        foreach ($this->_sorters as $sorter) {
            $tag = $sorter->type ? Tagger::check_tag_keyword($sorter->type, $this->user, Tagger::NOVALUE | Tagger::ALLOWCONTACTID) : false;
            $ok = $tag && ($thetag === null || $thetag === $tag);
            $thetag = $ok ? $tag : false;
        }
        if (!$thetag) {
            return null;
        }
        $dt = $this->conf->tags()->add(Tagger::base($thetag));
        if ($dt->has_order_anno()) {
            return $dt;
        }
        foreach ($qe->get_float("view", []) as $vv) {
            if ($vv[1] === "edit"
                && ($t = Tagger::check_tag_keyword($vv[0], $this->user, Tagger::NOVALUE | Tagger::ALLOWCONTACTID | Tagger::NOTAGKEYWORD))
                && strcasecmp($t, $dt->tag) == 0)
                return $dt;
        }
        return null;
    }

    /** @param SearchTerm $qe
     * @param PaperInfoSet $rowset */
    private function _check_order_anno($qe, $rowset) {
        if (!($dt = $this->_find_order_anno_tag($qe))) {
            return false;
        }
        $this->is_order_anno = $dt->tag;
        assert(!$this->is_order_anno || $this->is_order_anno[0] !== "~" || $this->is_order_anno[1] === "~");

        $tag_order = [];
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);
        foreach ($this->_matches as $pid) {
            $row = $rowset->get($pid);
            if ($row->has_viewable_tag($dt->tag, $this->user)) {
                $tag_order[] = [$row->paperId, $row->tag_value($dt->tag)];
            } else {
                $tag_order[] = [$row->paperId, TAG_INDEXBOUND];
            }
        }
        $this->user->set_overrides($old_overrides);
        usort($tag_order, "Tagger::id_index_compar");

        $this->thenmap = [];
        $this->_assign_order_anno_group(0, $dt, -1);
        $this->groupmap[0]->heading = "none";
        $cur_then = $aidx = $tidx = 0;
        $alist = $dt->order_anno_list();
        while ($aidx < count($alist) || $tidx < count($tag_order)) {
            if ($tidx == count($tag_order)
                || ($aidx < count($alist) && $alist[$aidx]->tagIndex <= $tag_order[$tidx][1])) {
                if ($cur_then != 0 || $tidx != 0 || $aidx != 0) {
                    ++$cur_then;
                }
                $this->_assign_order_anno_group($cur_then, $dt, $aidx);
                ++$aidx;
            } else {
                $this->thenmap[$tag_order[$tidx][0]] = $cur_then;
                ++$tidx;
            }
        }
    }

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
        if ($this->conf->has_any_lead_or_shepherd()) {
            $sqi->add_column("leadContactId", "Paper.leadContactId");
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
        if (($need_filter && $this->conf->has_track_tags())
            || ($this->_query_options["tags"] ?? false)
            || ($this->user->privChair
                && $this->conf->has_any_manager()
                && $this->conf->tags()->has_sitewide)) {
            $sqi->add_column("paperTags", "(select group_concat(' ', tag, '#', tagIndex separator '') from PaperTag where PaperTag.paperId=Paper.paperId)");
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
        if ($this->conf->submission_blindness() === Conf::BLIND_OPTIONAL) {
            $sqi->add_column("blind", "Paper.blind");
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
        }

        // sort information
        $this->_add_sorters($qe, -1);
        if ($thqe) {
            for ($i = 0; $i < $thqe->nthen; ++$i) {
                $this->_add_sorters($thqe->child[$i], $this->thenmap ? $i : -1);
            }
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
                    $spanstr = $thqe->child[$i]->get_float("strspan_owner", $this->q);
                    $h = rtrim(substr($spanstr, $span[0], $span[1] - $span[0]));
                }
                $this->groupmap[$i] = TagAnno::make_heading($h);
            }
        } else if (($h = $sole_qe->get_float("heading"))) {
            $this->groupmap[0] = TagAnno::make_heading($h);
        } else {
            $this->_check_order_anno($sole_qe, $rowset);
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
        if ($this->_default_sort || $this->_sorters) {
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

    /** @return list<array{string,string}> */
    function view_list() {
        return $this->term()->get_float("view", []);
    }

    /** @return list<ListSorter> */
    function sorter_list() {
        $this->_prepare();
        return $this->_sorters;
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
        $url = $this->_urlbase;
        if ($q === null) {
            $q = $this->q;
        }
        if ($q !== "" || substr($this->_urlbase, 0, 6) === "search") {
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
            && $this->_reviewer_user->contactId !== $this->cid) {
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
                             $this->description($listname), $this->_urlbase);
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
            foreach ($this->_sorters as $s) {
                if ($s->type && $s->type[0] === "#")
                    $ht[] = substr($s->type, 1);
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
            array_push($res, "has:pending-approval");
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
            if ($this->user->can_view_some_option($o)) {
                $o->add_search_completion($res);
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
            foreach ($this->conf->paper_column_map() as $cname => $cj) {
                $cj = $this->conf->basic_paper_column($cname, $this->user);
                if ($cj
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
