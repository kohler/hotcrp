<?php
// papersearch.php -- HotCRP helper class for searching for papers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class SearchWord {
    /** @var string */
    public $source;
    /** @var string */
    public $qword;
    /** @var string */
    public $word;
    /** @var bool */
    public $quoted;
    /** @var ?bool */
    public $kwexplicit;
    public $kwdef;
    /** @var ?string */
    public $compar;
    /** @var ?string */
    public $cword;
    /** @var ?int */
    public $pos1;
    /** @var ?int */
    public $pos1w;
    /** @var ?int */
    public $pos2;
    /** @param string $qword
     * @param string $source */
    function __construct($qword, $source) {
        $this->source = $source;
        $this->qword = $qword;
        $this->word = self::unquote($qword);
        $this->quoted = strlen($qword) !== strlen($this->word);
    }
    /** @param string $text
     * @return string */
    static function quote($text) {
        if ($text === ""
            || !preg_match('/\A[-A-Za-z0-9_.@\/]+\z/', $text)) {
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
    /** @return string */
    function source_html() {
        return htmlspecialchars($this->source);
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

    /** @param string $op
     * @param bool $unary
     * @param int $precedence */
    function __construct($op, $unary, $precedence, $opinfo = null) {
        $this->op = $op;
        $this->unary = $unary;
        $this->precedence = $precedence;
        $this->opinfo = $opinfo;
    }

    /** @return string */
    function unparse() {
        $x = strtoupper($this->op);
        return $this->opinfo === null ? $x : $x . ":" . $this->opinfo;
    }

    /** @return ?SearchOperator */
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
    /** @var ?SearchOperator */
    public $op;
    /** @var ?SearchTerm */
    public $leftqe;
    /** @var int */
    public $pos1;
    /** @var int */
    public $pos2;
    /** @var ?SearchScope */
    public $next;
    /** @var ?string */
    public $defkw;
    /** @var ?int */
    public $defkw_pos1;
    /** @var ?SearchScope */
    public $defkw_scope;
    /** @var bool */
    public $defkw_error = false;

    /** @param ?SearchOperator $op
     * @param ?SearchTerm $leftqe
     * @param int $pos1
     * @param int $pos2
     * @param ?SearchScope $next */
    function __construct($op, $leftqe, $pos1, $pos2, $next) {
        $this->op = $op;
        $this->leftqe = $leftqe;
        $this->pos1 = $pos1;
        $this->pos2 = $pos2;
        if (($this->next = $next)) {
            $this->defkw = $next->defkw;
            $this->defkw_pos1 = $next->defkw_pos1;
            $this->defkw_scope = $next->defkw_scope;
        }
    }
    /** @param ?SearchTerm $curqe
     * @return array{?SearchTerm,SearchScope} */
    function pop($curqe) {
        assert(!!$this->op);
        if ($curqe) {
            if ($this->leftqe) {
                $curqe = SearchTerm::combine($this->op, [$this->leftqe, $curqe]);
            } else if ($this->op->op !== "+" && $this->op->op !== "(") {
                $curqe = SearchTerm::combine($this->op, [$curqe]);
            }
            $curqe->apply_strspan($this->pos1, $this->pos2);
        } else {
            $curqe = $this->leftqe;
        }
        return [$curqe, $this->next];
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

abstract class SearchTerm {
    /** @var string
     * @readonly */
    public $type;
    /** @var array<string,mixed> */
    protected $float = [];
    /** @var ?int */
    public $pos1;
    /** @var ?int */
    public $pos2;

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
        return $qr->_finish();
    }

    /** @return SearchTerm */
    function negate() {
        $qr = new Not_SearchTerm;
        return $qr->append($this)->_finish();
    }

    /** @param bool $negate
     * @return SearchTerm */
    function negate_if($negate) {
        return $negate ? $this->negate() : $this;
    }

    /** @return list<array{string,?int,?int,?int}> */
    function view_anno() {
        return $this->float["view"] ?? [];
    }

    /** @param string $view
     * @param SearchWord $sword
     * @return $this */
    function add_view_anno($view, $sword) {
        if ($sword->pos1 !== null) {
            $pos1x = $sword->pos1 + strpos($sword->source, ":") + 1;
        } else {
            $pos1x = null;
        }
        $this->float["view"][] = [$view, $sword->pos1, $pos1x, $sword->pos2];
        return $this;
    }

    /** @param string $field
     * @return ?array{int,int,int} */
    function view_anno_pos($field) {
        foreach ($this->float["view"] ?? [] as $vx) {
            foreach (PaperSearch::view_generator([$vx[0]]) as $akd) {
                if ($field === $akd[1])
                    return [$vx[1], $vx[2], $vx[3]];
            }
        }
        return null;
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

    /** @param int $pos1
     * @param int $pos2 */
    function set_strspan($pos1, $pos2) {
        $this->pos1 = $pos1;
        $this->pos2 = $pos2;
    }

    /** @param int $pos1
     * @param int $pos2 */
    function apply_strspan($pos1, $pos2) {
        if ($this->pos1 === null || $this->pos1 > $pos1) {
            $this->pos1 = $pos1;
        }
        if ($this->pos2 === null || $this->pos2 < $pos2) {
            $this->pos2 = $pos2;
        }
    }

    /** @param string $str */
    function set_strspan_owner($str) {
        if (!isset($this->float["strspan_owner"])) {
            $this->float["strspan_owner"] = $str;
        }
    }

    /** @return bool */
    function merge(SearchTerm $st) {
        return false;
    }


    /** @return mixed */
    function debug_json() {
        return $this->type;
    }


    /** @param array<string,true> &$options
     * @return bool */
    function simple_search(&$options) {
        return false;
    }


    /** @return string */
    abstract function sqlexpr(SearchQueryInfo $sqi);

    /** @param ?bool $b
     * @return null|False_SearchTerm|True_SearchTerm */
    static function make_constant($b) {
        if ($b === true) {
            return new True_SearchTerm;
        } else if ($b === false) {
            return new False_SearchTerm;
        } else {
            return null;
        }
    }

    /** @return string */
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

    /** @param list<string> $q
     * @param 'false'|'true' $default
     * @return string */
    static function orjoin_sqlexpr($q, $default) {
        if (empty($q)) {
            return $default;
        } else if (in_array("true", $q, true)) {
            return "true";
        } else {
            return "(" . join(" or ", $q) . ")";
        }
    }

    /** @return bool */
    function is_sqlexpr_precise() {
        return false;
    }


    /** @param ?ReviewInfo $rrow
     * @return bool */
    abstract function test(PaperInfo $row, $rrow);


    /** @param callable(SearchTerm,...):mixed $visitor
     * @return mixed */
    function visit($visitor) {
        return $visitor($this);
    }

    /** @return Generator<SearchTerm> */
    function preorder() {
        yield $this;
    }


    /** @return null|bool|array{type:string} */
    function script_expression(PaperInfo $row) {
        return $this->test($row, null);
    }


    /** @param bool $top
     * @return void */
    function configure_search($top, PaperSearch $srch) {
    }

    /** @param bool $top
     * @return ?PaperColumn */
    function default_sort_column($top, PaperSearch $srch) {
        return null;
    }

    const ABOUT_NO = 0;
    const ABOUT_MAYBE = 1;
    const ABOUT_SELF = 2;
    const ABOUT_MANY = 3;
    /** @return 0|1|2|3 */
    function about_reviews() {
        return self::ABOUT_MAYBE;
    }
}

class False_SearchTerm extends SearchTerm {
    /** @var ?MessageItem */
    public $score_warning;
    function __construct() {
        parent::__construct("f");
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        return "false";
    }
    function is_sqlexpr_precise() {
        return true;
    }
    function test(PaperInfo $row, $rrow) {
        return false;
    }
    function about_reviews() {
        return self::ABOUT_NO;
    }
}

class True_SearchTerm extends SearchTerm {
    function __construct() {
        parent::__construct("t");
    }
    function is_uninteresting() {
        return count($this->float) === 1 && isset($this->float["view"]);
    }
    function simple_search(&$options) {
        return true;
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        return "true";
    }
    function is_sqlexpr_precise() {
        return true;
    }
    function test(PaperInfo $row, $rrow) {
        return true;
    }
    function about_reviews() {
        return self::ABOUT_NO;
    }
}

abstract class Op_SearchTerm extends SearchTerm {
    /** @var list<SearchTerm> */
    public $child = [];

    function __construct($type) {
        parent::__construct($type);
    }
    /** @param list<string> $vxs
     * @return list<string> */
    private static function strip_sort($vxs) {
        $res = [];
        foreach ($vxs as $vx) {
            if (preg_match('/\A([a-z]*)sort(:.*)\z/s', $vx[0], $m)) {
                if ($m[1] !== "") {
                    $res[] = [$m[1] . $m[2], $vx[1], $vx[2]];
                }
            } else {
                $res[] = $vx;
            }
        }
        return $res;
    }
    /** @param SearchTerm $term */
    protected function append($term) {
        if ($term) {
            foreach ($term->float as $k => $v) {
                if ($k === "view" && $this->type === "then") {
                    $v = self::strip_sort($v);
                }
                if ($k === "view" || $k === "tags") {
                    if (!isset($this->float[$k])) {
                        $this->float[$k] = $v;
                    } else {
                        array_splice($this->float[$k], count($this->float[$k]), 0, $v);
                    }
                } else {
                    $this->float[$k] = $v;
                }
            }
            $this->child[] = $term;
            if ($term->pos1 !== null && !isset($term->float["strspan_owner"])) {
                $this->apply_strspan($term->pos1, $term->pos2);
            }
        }
        return $this;
    }
    abstract protected function _finish();
    /** @return list<SearchTerm> */
    protected function _flatten_children() {
        $qvs = [];
        foreach ($this->child as $qv) {
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
    function is_sqlexpr_precise() {
        foreach ($this->child as $ch) {
            if (!$ch->is_sqlexpr_precise())
                return false;
        }
        return true;
    }
    function visit($visitor) {
        $x = [];
        foreach ($this->child as $ch) {
            $x[] = $ch->visit($visitor);
        }
        return $visitor($this, ...$x);
    }
    function preorder() {
        yield $this;
        foreach ($this->child as $ch) {
            foreach ($ch->preorder() as $chx) {
                yield $chx;
            }
        }
    }
    function configure_search($top, PaperSearch $srch) {
        $top = $top && $this instanceof And_SearchTerm;
        foreach ($this->child as $qv) {
            $qv->configure_search($top, $srch);
        }
    }
    function about_reviews() {
        $x = 0;
        foreach ($this->child as $qv) {
            $x = max($x, $qv->about_reviews());
        }
        return $x;
    }
}

class Not_SearchTerm extends Op_SearchTerm {
    function __construct() {
        parent::__construct("not");
    }
    protected function _finish() {
        unset($this->float["tags"]);
        $qv = $this->child ? $this->child[0] : null;
        $qr = null;
        if (!$qv || $qv instanceof False_SearchTerm) {
            $qr = new True_SearchTerm;
        } else if ($qv instanceof True_SearchTerm) {
            $qr = new False_SearchTerm;
        } else if ($qv instanceof Not_SearchTerm) {
            $qr = clone $qv->child[0];
        }
        if ($qr) {
            $qr->float = $this->float;
            return $qr;
        } else {
            return $this;
        }
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        ++$sqi->depth;
        $ff = $this->child[0]->sqlexpr($sqi);
        --$sqi->depth;
        if ($this->child[0]->is_sqlexpr_precise()) {
            if ($ff === "false") {
                return "true";
            } else if ($ff === "true") {
                return "false";
            } else {
                return "not coalesce({$ff},0)";
            }
        } else {
            return "true";
        }
    }
    function test(PaperInfo $row, $rrow) {
        return !$this->child[0]->test($row, $rrow);
    }

    function script_expression(PaperInfo $row) {
        $x = $this->child[0]->script_expression($row);
        if ($x === null) {
            return null;
        } else if ($x === false || $x === true) {
            return !$x;
        } else {
            return ["type" => "not", "child" => [$x]];
        }
    }
    function configure_search($top, PaperSearch $srch) {
    }
}

class And_SearchTerm extends Op_SearchTerm {
    /** @param string $type */
    function __construct($type) {
        parent::__construct($type);
    }
    protected function _finish() {
        $pn = null;
        $newchild = [];
        $any = false;
        foreach ($this->_flatten_children() as $qv) {
            if ($qv instanceof False_SearchTerm) {
                $qr = new False_SearchTerm;
                $qr->float = $this->float;
                return $qr;
            } else if ($qv instanceof True_SearchTerm) {
                $any = true;
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
        return $this->_finish_combine($newchild, $any);
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $ff = [];
        foreach ($this->child as $subt) {
            $ff[] = $subt->sqlexpr($sqi);
        }
        return self::andjoin_sqlexpr($ff);
    }
    function test(PaperInfo $row, $rrow) {
        foreach ($this->child as $subt) {
            if (!$subt->test($row, $rrow))
                return false;
        }
        return true;
    }
    function script_expression(PaperInfo $row) {
        $ch = [];
        $ok = true;
        foreach ($this->child as $subt) {
            $x = $subt->script_expression($row);
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
    protected function _finish() {
        $pn = $lastqv = null;
        $newchild = [];
        foreach ($this->_flatten_children() as $qv) {
            if ($qv instanceof True_SearchTerm) {
                $qe = new True_SearchTerm;
                $qe->float = $this->float;
                return $qe;
            } else if ($qv instanceof False_SearchTerm) {
                // skip
            } else if ($qv->type === "pn") {
                if (!$pn) {
                    $newchild[] = $pn = $qv;
                } else {
                    $pn->merge($qv);
                }
            } else if (!$lastqv || !$lastqv->merge($qv)) {
                $newchild[] = $lastqv = $qv;
            }
        }
        return $this->_finish_combine($newchild, false);
    }

    /** @param list<SearchTerm> $child
     * @return list<string> */
    static function or_sqlexprs($child, SearchQueryInfo $sqi) {
        ++$sqi->depth;
        $ff = $tsf = [];
        foreach ($child as $subt) {
            if ($subt instanceof Tag_SearchTerm) {
                $tsf[] = $subt->sqlexpr($sqi);
            } else {
                $ff[] = $subt->sqlexpr($sqi);
            }
        }
        if ($tsf) {
            $ff[] = Tag_SearchTerm::combine_sqlexpr($tsf);
        }
        --$sqi->depth;
        return $ff;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        return self::orjoin_sqlexpr(self::or_sqlexprs($this->child, $sqi), "false");
    }
    function test(PaperInfo $row, $rrow) {
        foreach ($this->child as $subt) {
            if ($subt->test($row, $rrow))
                return true;
        }
        return false;
    }
    static function make_script_expression($child, PaperInfo $row) {
        $ch = [];
        $ok = false;
        foreach ($child as $subt) {
            $x = $subt->script_expression($row);
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
    function script_expression(PaperInfo $row) {
        return self::make_script_expression($this->child, $row);
    }
}

class Xor_SearchTerm extends Op_SearchTerm {
    function __construct() {
        parent::__construct("xor");
    }
    protected function _finish() {
        $negate = false;
        $newchild = [];
        foreach ($this->_flatten_children() as $qv) {
            if ($qv instanceof False_SearchTerm) {
                // skip
            } else if ($qv instanceof True_SearchTerm) {
                $negate = !$negate;
            } else {
                $newchild[] = $qv;
            }
        }
        return $this->_finish_combine($newchild, false)->negate_if($negate);
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $ff = Or_SearchTerm::or_sqlexprs($this->child, $sqi);
        if (empty($ff)) {
            return "false";
        } else if ($this->is_sqlexpr_precise()) {
            return "(coalesce(" . join(",0) xor coalesce(", $ff) . ",0))";
        } else {
            return self::orjoin_sqlexpr($ff, "false");
        }
    }
    function test(PaperInfo $row, $rrow) {
        $x = false;
        foreach ($this->child as $subt) {
            if ($subt->test($row, $rrow))
                $x = !$x;
        }
        return $x;
    }
}

class Highlight_SearchInfo {
    /** @var int */
    public $pos;
    /** @var int */
    public $count;
    /** @var string */
    public $color;

    function __construct($pos, $count, $color) {
        $this->pos = $pos;
        $this->count = $count;
        $this->color = $color;
    }
}

class Then_SearchTerm extends Op_SearchTerm {
    /** @var bool */
    private $is_highlight;
    /** @var ?string */
    private $opinfo;
    /** @var int */
    public $nthen = 0;
    /** @var list<Highlight_SearchInfo> */
    private $hlinfo = [];
    /** @var ?int */
    private $_last_group;

    function __construct(SearchOperator $op) {
        assert($op->op === "then" || $op->op === "highlight");
        parent::__construct("then");
        $this->is_highlight = $op->op === "highlight";
        $this->opinfo = $op->opinfo ?? null;
    }
    protected function _finish() {
        $opinfo = strtolower($this->opinfo ?? "");
        $newvalues = $newhvalues = $newhinfo = [];

        foreach ($this->child as $qvidx => $qv) {
            if ($qv && $qvidx && $this->is_highlight) {
                if ($qv instanceof Then_SearchTerm) {
                    for ($i = 0; $i < $qv->nthen; ++$i) {
                        $newhvalues[] = $qv->child[$i];
                        $newhinfo[] = new Highlight_SearchInfo(0, count($newvalues), $opinfo);
                    }
                } else {
                    $newhvalues[] = $qv;
                    $newhinfo[] = new Highlight_SearchInfo(0, count($newvalues), $opinfo);
                }
            } else if ($qv && $qv instanceof Then_SearchTerm) {
                $pos = count($newvalues);
                for ($i = 0; $i < $qv->nthen; ++$i) {
                    $newvalues[] = $qv->child[$i];
                }
                for ($i = $qv->nthen; $i < count($qv->child); ++$i) {
                    $newhvalues[] = $qv->child[$i];
                }
                foreach ($qv->hlinfo as $hinfo) {
                    $newhinfo[] = new Highlight_SearchInfo($pos, $hinfo->count, $hinfo->color);
                }
            } else if ($qv) {
                $newvalues[] = $qv;
            }
        }

        $this->child = $newvalues;
        $this->nthen = count($newvalues);
        array_splice($this->child, $this->nthen, 0, $newhvalues);
        $this->hlinfo = $newhinfo;
        return $this;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        ++$sqi->depth;
        $ff = [];
        foreach ($this->child as $subt) {
            $ff[] = $subt->sqlexpr($sqi);
        }
        --$sqi->depth;
        return self::orjoin_sqlexpr(array_slice($ff, 0, $this->nthen), "true");
    }
    function test(PaperInfo $row, $rrow) {
        for ($i = 0; $i !== $this->nthen; ++$i) {
            if ($this->child[$i]->test($row, $rrow)) {
                $this->_last_group = $i;
                return true;
            }
        }
        return false;
    }
    function script_expression(PaperInfo $row) {
        return Or_SearchTerm::make_script_expression(array_slice($this->child, 0, $this->nthen), $row);
    }

    /** @return bool */
    function has_highlight() {
        return $this->nthen < count($this->child);
    }
    /** @return int */
    function _last_group() {
        return $this->_last_group;
    }
    /** @return list<string> */
    function _last_highlights(PaperInfo $row) {
        $hls = [];
        foreach ($this->hlinfo as $i => $hl) {
            if ($this->_last_group >= $hl->pos
                && $this->_last_group < $hl->pos + $hl->count
                && $this->child[$this->nthen + $i]->test($row, null)) {
                $hls[] = $hl->color;
            }
        }
        return $hls;
    }

    function debug_json() {
        $a = [];
        foreach ($this->child as $qv) {
            $a[] = $qv->debug_json();
        }
        if ($this->nthen === count($this->child)) {
            return ["type" => $this->type, "child" => $a];
        } else {
            assert(count($this->child) === $this->nthen + count($this->hlinfo));
            $j = [
                "type" => $this->type,
                "child" => array_slice($a, 0, $this->nthen),
                "highlights" => []
            ];
            foreach ($this->hlinfo as $i => $hl) {
                $h = get_object_vars($hl);
                $h["search"] = $a[$this->nthen + $i];
                $j["highlights"][] = $h;
            }
            return $j;
        }
    }
}

class Limit_SearchTerm extends SearchTerm {
    /** @var string
     * @readonly */
    public $limit;
    /** @var string
     * @readonly */
    public $named_limit;
    /** @var int
     * @readonly */
    public $lflag;
    /** @var Contact */
    private $user;
    /** @var Contact */
    private $reviewer;

    static public $reqtype_map = [
        "a" => ["a", "author"],
        "acc" => ["acc", "accepted"],
        "accepted" => ["acc", "accepted"],
        "act" => ["act", "active"],
        "active" => ["act", "active"],
        "admin" => "admin",
        "administrator" => "admin",
        "all" => "all",
        "alladmin" => "alladmin",
        "ar" => "ar",
        "author" => ["a", "author"],
        "editpref" => "reviewable",
        "lead" => "lead",
        "manager" => "admin",
        "none" => "none",
        "outstandingreviews" => ["rout", "outstandingreviews"],
        "r" => ["r", "reviews"],
        "rable" => "reviewable",
        "req" => "req",
        "reqrevs" => "req",
        "reviewable" => "reviewable",
        "reviews" => ["r", "reviews"],
        "rout" => ["rout", "outstandingreviews"],
        "s" => ["s", "submitted"],
        "submitted" => ["s", "submitted"],
        "und" => ["undec", "undecided"],
        "undec" => ["undec", "undecided"],
        "undecided" => ["undec", "undecided"],
        "unsub" => ["unsub", "unsubmitted"],
        "unsubmitted" => ["unsub", "unsubmitted"],
        "vis" => "viewable",
        "visible" => "viewable",
    ];

    const LFLAG_ACTIVE = 1;
    const LFLAG_SUBMITTED = 2;
    const LFLAG_IMPLICIT = 4;

    function __construct(Contact $user, Contact $reviewer, $limit, $implicit = false) {
        parent::__construct("in");
        $this->user = $user;
        $this->reviewer = $reviewer;
        $this->set_limit($limit);
        if ($implicit) {
            $this->lflag |= self::LFLAG_IMPLICIT;
        }
    }

    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        return new Limit_SearchTerm($srch->user, $srch->reviewer_user(), $word);
    }

    /** @param string $limit
     * @suppress PhanAccessReadOnlyProperty */
    function set_limit($limit) {
        $limit = PaperSearch::canonical_limit($limit) ?? "none";
        $this->named_limit = $limit;
        // optimize SQL for some limits
        if ($limit === "reviewable") {
            if ($this->user->privChair || $this->user === $this->reviewer) {
                if ($this->reviewer->can_accept_some_review_assignment()) {
                    if ($this->user->conf->time_pc_view_active_submissions()) {
                        $limit = "act";
                    } else {
                        $limit = "s";
                    }
                } else if (!$this->reviewer->isPC) {
                    $limit = "r";
                }
            }
        } else if ($limit === "viewable") {
            if ($this->user->can_view_all()) {
                $limit = "all";
            }
        }
        $this->limit = $limit;
        // mark flags
        if (in_array($limit, ["a", "ar", "viewable", "all", "none"], true)) {
            $this->lflag = 0;
        } else if (in_array($limit, ["r", "rout", "req"], true)) {
            $this->lflag = $this->reviewer_lflag();
        } else if (in_array($limit, ["act", "unsub"], true)
                   || ($this->user->conf->time_pc_view_active_submissions()
                       && !in_array($limit, ["s", "acc"], true))) {
            $this->lflag = self::LFLAG_ACTIVE;
        } else {
            $this->lflag = self::LFLAG_SUBMITTED;
        }
    }

    /** @return int */
    function reviewer_lflag() {
        if ($this->user->isPC && $this->user->conf->time_pc_view_active_submissions()) {
            return self::LFLAG_ACTIVE;
        } else {
            return self::LFLAG_SUBMITTED;
        }
    }

    function simple_search(&$options) {
        $conf = $this->user->conf;
        if (($conf->has_tracks()
             && !in_array($this->limit, ["a", "r", "ar"], true)
             && !$this->user->privChair)
            || $this->user->has_hidden_papers()) {
            return false;
        }
        if ($this->lflag & self::LFLAG_SUBMITTED) {
            $options["finalized"] = true;
        } else if ($this->lflag & self::LFLAG_ACTIVE) {
            $options["active"] = true;
        }
        switch ($this->limit) {
        case "all":
        case "viewable":
            return $this->user->can_view_all();
        case "s":
            assert(!!($options["finalized"] ?? false));
            return $this->user->isPC;
        case "act":
            assert(!!($options["active"] ?? false));
            return $this->user->privChair
                || ($this->user->isPC && $conf->time_pc_view_active_submissions());
        case "reviewable":
            assert(($options["active"] ?? false) || ($options["finalized"] ?? false));
            if (($this->user !== $this->reviewer && !$this->user->allow_administer_all())
                || $conf->has_tracks()) {
                return false;
            }
            if (!$this->reviewer->isPC) {
                $options["myReviews"] = true;
            }
            return true;
        case "a":
            $options["author"] = true;
            // If complex author SQL, always do search the long way
            return !$this->user->act_author_view_sql("%", true);
        case "ar":
            return false;
        case "r":
            assert(($options["active"] ?? false) || ($options["finalized"] ?? false));
            $options["myReviews"] = true;
            return true;
        case "rout":
            assert(($options["active"] ?? false) || ($options["finalized"] ?? false));
            $options["myOutstandingReviews"] = true;
            return true;
        case "acc":
            assert($options["finalized"] ?? false);
            $options["accepted"] = true;
            return $this->user->allow_administer_all()
                || ($this->user->isPC && $conf->time_pc_view_decision(true));
        case "undec":
            assert($options["finalized"] ?? false);
            $options["undecided"] = true;
            return $this->user->allow_administer_all()
                || ($this->user->isPC && $conf->time_pc_view_decision(true));
        case "unsub":
            assert($options["active"] ?? false);
            $options["unsub"] = true;
            return $this->user->allow_administer_all();
        case "lead":
            $options["myLead"] = true;
            return true;
        case "alladmin":
            return $this->user->allow_administer_all();
        case "admin":
            return false;
        case "req":
            assert(($options["active"] ?? false) || ($options["finalized"] ?? false));
            $options["myReviewRequests"] = true;
            return true;
        default:
            return false;
        }
    }

    function is_sqlexpr_precise() {
        if ($this->user->has_hidden_papers()) {
            return false;
        } else if (in_array($this->limit, ["undec", "acc", "viewable", "alladmin"], true)) {
            return $this->user->allow_administer_all();
        } else {
            return $this->limit !== "reviewable" && $this->limit !== "admin";
        }
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        assert($sqi->depth > 0 || $sqi->srch->user === $this->user);

        $ff = [];
        if (($this->lflag & self::LFLAG_SUBMITTED) !== 0) {
            $ff[] = "Paper.timeSubmitted>0";
        } else if (($this->lflag & self::LFLAG_ACTIVE) !== 0) {
            $ff[] = "Paper.timeWithdrawn<=0";
        }

        if (in_array($this->limit, ["ar", "r", "rout"], true)) {
            $sqi->add_reviewer_columns();
            if ($sqi->depth === 0) {
                $act_reviewer_sql = $this->user->act_reviewer_sql("MyReviews");
                if ($act_reviewer_sql !== "false") {
                    $sqi->add_table("MyReviews", [$this->limit === "ar" ? "left join" : "join", "PaperReview", $act_reviewer_sql]);
                }
            } else {
                $act_reviewer_sql = $this->user->act_reviewer_sql("PaperReview");
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
            $ff[] = $this->user->act_author_view_sql($sqi->conflict_table($this->user));
            break;
        case "ar":
            if ($act_reviewer_sql === "false") {
                $r = "false";
            } else if ($sqi->depth === 0) {
                $r = "MyReviews.reviewType is not null";
            } else {
                $r = "exists (select * from PaperReview force index (primary) where paperId=Paper.paperId and $act_reviewer_sql)";
            }
            $ff[] = "(" . $this->user->act_author_view_sql($sqi->conflict_table($this->user)) . " or (Paper.timeWithdrawn<=0 and $r))";
            break;
        case "r":
            // if top, the straight join suffices
            if ($act_reviewer_sql === "false") {
                $ff[] = "false";
            } else if ($sqi->depth === 0) {
                // the `join` with MyReviews suffices
            } else {
                $ff[] = "exists (select * from PaperReview force index (primary) where paperId=Paper.paperId and $act_reviewer_sql)";
            }
            break;
        case "rout":
            if ($act_reviewer_sql === "false") {
                $ff[] = "false";
            } else if ($sqi->depth === 0) {
                $ff[] = "MyReviews.reviewNeedsSubmit!=0";
            } else {
                $ff[] = "exists (select * from PaperReview force index (primary) where paperId=Paper.paperId and $act_reviewer_sql and reviewNeedsSubmit!=0)";
            }
            break;
        case "acc":
            $ff[] = "Paper.outcome>0";
            break;
        case "undec":
            if ($this->user->allow_administer_all()) {
                $ff[] = "Paper.outcome=0";
            }
            break;
        case "unsub":
            $ff[] = "Paper.timeSubmitted<=0";
            $ff[] = "Paper.timeWithdrawn<=0";
            break;
        case "lead":
            $ff[] = "Paper.leadContactId={$this->user->contactXid}";
            break;
        case "alladmin":
            if ($this->user->privChair) {
                break;
            }
            /* FALLTHRU */
        case "admin":
            if ($this->user->is_track_manager()) {
                $ff[] = "(Paper.managerContactId={$this->user->contactXid} or Paper.managerContactId=0)";
            } else {
                $ff[] = "Paper.managerContactId={$this->user->contactXid}";
            }
            break;
        case "req":
            $ff[] = "exists (select * from PaperReview force index (primary) where paperId=Paper.paperId and reviewType=" . REVIEW_EXTERNAL . " and requestedBy={$this->user->contactXid})";
            break;
        default:
            $ff[] = "false";
            break;
        }

        return empty($ff) ? "true" : self::andjoin_sqlexpr($ff);
    }

    function test(PaperInfo $row, $rrow) {
        $user = $this->user;
        if ((($this->lflag & self::LFLAG_SUBMITTED) !== 0 && $row->timeSubmitted <= 0)
            || (($this->lflag & self::LFLAG_ACTIVE) !== 0 && $row->timeWithdrawn > 0)) {
            return false;
        }
        switch ($this->limit) {
        case "all":
        case "viewable":
        case "s":
        case "act":
            return true;
        case "a":
            return $row->has_author_view($user);
        case "ar":
            return $row->has_author_view($user)
                || ($row->timeWithdrawn <= 0 && $row->has_reviewer($user));
        case "r":
            return $row->has_reviewer($user);
        case "rout":
            foreach ($row->reviews_by_user($user, $user->review_tokens()) as $rrow) {
                if ($rrow->reviewNeedsSubmit != 0)
                    return true;
            }
            return false;
        case "acc":
            return $row->outcome > 0
                && $user->can_view_decision($row);
        case "undec":
            return $row->outcome == 0
                || !$user->can_view_decision($row);
        case "reviewable":
            return $this->reviewer->can_accept_review_assignment_ignore_conflict($row)
                && ($this->reviewer === $user
                    || $user->allow_administer($row));
        case "unsub":
            return $row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0;
        case "lead":
            return $row->leadContactId === $user->contactXid;
        case "admin":
            return $user->is_primary_administrator($row);
        case "alladmin":
            return $user->allow_administer($row);
        case "req":
            foreach ($row->all_reviews() as $rrow) {
                if ($rrow->reviewType == REVIEW_EXTERNAL
                    && $rrow->requestedBy == $user->contactXid)
                    return true;
            }
            return false;
        default:
            return false;
        }
    }

    function configure_search($top, PaperSearch $srch) {
        if ($top && ($this->lflag & self::LFLAG_IMPLICIT) === 0) {
            $srch->apply_limit($this);
        }
    }
    function about_reviews() {
        if (in_array($this->limit, ["viewable", "reviewable", "ar", "r", "rout", "req"])) {
            return self::ABOUT_MANY;
        } else {
            return self::ABOUT_NO;
        }
    }
}

class TextMatch_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var string */
    private $field;
    /** @var bool */
    private $authorish;
    /** @var ?bool */
    private $trivial;
    /** @var ?TextPregexes */
    public $regex;
    static public $map = [ // NB see field_highlighters()
        "ti" => "title", "ab" => "abstract",
        "au" => "authorInformation", "co" => "collaborators"
    ];

    function __construct(Contact $user, $t, $text, $quoted) {
        parent::__construct($t);
        $this->user = $user;
        $this->field = self::$map[$t];
        $this->authorish = $t === "au" || $t === "co";
        if (is_bool($text)) {
            $this->trivial = $text;
        } else {
            $this->regex = Text::star_text_pregexes($text, $quoted);
        }
    }
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        if ($sword->kwexplicit && !$sword->quoted) {
            if ($word === "any") {
                $word = true;
            } else if ($word === "none") {
                $word = false;
            }
        }
        return new TextMatch_SearchTerm($srch->user, $sword->kwdef->name, $word, $sword->quoted);
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_column($this->field, "Paper.{$this->field}");
        if ($this->trivial && !$this->authorish) {
            return "Paper.{$this->field}!=''";
        } else {
            return "true";
        }
    }
    function is_sqlexpr_precise() {
        return $this->trivial && !$this->authorish;
    }
    function test(PaperInfo $row, $rrow) {
        $data = $row->{$this->field};
        if ($this->authorish && !$this->user->allow_view_authors($row)) {
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
    function script_expression(PaperInfo $row) {
        if (!$this->trivial || $this->field === "authorInformation") {
            return null;
        } else {
            return ["type" => $this->field, "match" => $this->trivial];
        }
    }
    function configure_search($top, PaperSearch $srch) {
        if ($this->regex) {
            $srch->add_field_highlighter($this->type, $this->regex);
        }
    }
    function about_reviews() {
        return self::ABOUT_NO;
    }
}

class Show_SearchTerm {
    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        return (new True_SearchTerm)->add_view_anno("{$sword->kwdef->name}:{$sword->qword}", $sword);
    }
    static function parse_legend($word, SearchWord $sword) {
        $qe = new True_SearchTerm;
        $qe->set_float("legend", simplify_whitespace($word));
        return $qe;
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
    function index_of($p) {
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
    function merge(SearchTerm $st) {
        if ($st instanceof PaperID_SearchTerm) {
            $rs = $st->r;
            if (!$st->in_order) {
                usort($rs, function ($a, $b) { return $a[2] <=> $b[2]; });
            }
            foreach ($rs as $r) {
                $this->add_drange($r[0], $r[1], $r[3], $r[4]);
            }
            return true;
        } else {
            return false;
        }
    }
    /** @return ?list<int> */
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
            return null;
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
                   && ($pids = $this->paper_ids()) !== null) {
            return "$field in (" . join(",", $pids) . ")";
        } else {
            $s = [];
            foreach ($this->r as $r) {
                $s[] = "({$field}>={$r[0]} and {$field}<{$r[1]})";
            }
            return "(" . join(" or ", $s) . ")";
        }
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        return $this->sql_predicate("Paper.paperId");
    }
    function is_sqlexpr_precise() {
        return true;
    }
    function test(PaperInfo $row, $rrow) {
        return $this->index_of($row->paperId) !== false;
    }
    function default_sort_column($top, PaperSearch $srch) {
        if ($top && !$this->in_order) {
            return new PaperIDOrder_PaperColumn($srch->conf, $this);
        } else {
            return null;
        }
    }
    function about_reviews() {
        return self::ABOUT_NO;
    }
    static function parse_pidcode($word, SearchWord $sword, PaperSearch $srch) {
        if (($ids = SessionList::decode_ids($word)) === null) {
            $srch->lwarning($sword, "<0>Invalid pidcode");
            return new False_SearchTerm;
        } else {
            $pt = new PaperID_SearchTerm;
            foreach ($ids as $id) {
                $pt->add_range($id, $id);
            }
            return $pt;
        }
    }
}


class SearchQueryInfo {
    /** @var PaperSearch
     * @readonly */
    public $srch;
    /** @var array<string,?list<string>> */
    public $tables = [];
    /** @var array<string,string> */
    public $columns = [];
    /** @var array<string,mixed> */
    public $query_options = [];
    /** @var int */
    public $depth = 0;
    private $_has_my_review = false;
    private $_has_review_signatures = false;
    /** @var list<ReviewField> */
    private $_review_scores;

    function __construct(PaperSearch $srch) {
        $this->srch = $srch;
        if (!$srch->user->allow_administer_all()) {
            $this->add_reviewer_columns();
        }
        $this->tables["Paper"] = [];
    }
    /** @param string $table
     * @param list<string> $joiner
     * @param bool $required
     * @return ?string */
    function try_add_table($table, $joiner, $required = false) {
        // All added tables must match at most one Paper row each,
        // except MyReviews.
        if (str_ends_with($table, "_")) {
            $table .= count($this->tables);
        }
        if (!isset($this->tables[$table])) {
            if (!$required && count($this->tables) > 32) {
                return null;
            }
            $this->tables[$table] = $joiner;
        } else if ($joiner[0] === "join") {
            $this->tables[$table][0] = "join";
        }
        return $table;
    }
    /** @param string $table
     * @param list<string> $joiner
     * @return string */
    function add_table($table, $joiner = null) {
        return $this->try_add_table($table, $joiner, true);
    }
    function add_column($name, $expr) {
        assert(!isset($this->columns[$name]) || $this->columns[$name] === $expr);
        $this->columns[$name] = $expr;
    }
    /** @return ?string */
    function conflict_table(Contact $user) {
        if ($user->contactXid > 0) {
            return $this->add_table("PaperConflict{$user->contactXid}", ["left join", "PaperConflict", "{}.contactId={$user->contactXid}"]);
        } else {
            return null;
        }
    }
    function add_options_columns() {
        $this->columns["optionIds"] = "coalesce((select group_concat(PaperOption.optionId, '#', value) from PaperOption force index (primary) where paperId=Paper.paperId), '')";
    }
    function add_reviewer_columns() {
        $this->_has_my_review = true;
    }
    function add_review_signature_columns() {
        $this->_has_review_signatures = true;
    }
    function finish_reviewer_columns() {
        $user = $this->srch->user;
        if ($this->_has_my_review) {
            $ct = $this->conflict_table($user);
            $this->add_column("conflictType", $ct ? "{$ct}.conflictType" : "null");
        }
        if ($this->_has_review_signatures) {
            $this->add_column("reviewSignatures", "coalesce((select " . ReviewInfo::review_signature_sql($user->conf, $this->_review_scores) . " from PaperReview r force index (primary) where r.paperId=Paper.paperId), '')");
        } else if ($this->_has_my_review) {
            $act_reviewer_sql = $user->act_reviewer_sql("PaperReview");
            if ($act_reviewer_sql === "false") {
                $this->add_column("myReviewPermissions", "''");
            } else if (isset($this->tables["MyReviews"])) {
                $this->add_column("myReviewPermissions", "coalesce(" . PaperInfo::my_review_permissions_sql("MyReviews.") . ", '')");
            } else {
                $this->add_column("myReviewPermissions", "coalesce((select " . PaperInfo::my_review_permissions_sql() . " from PaperReview force index (primary) where PaperReview.paperId=Paper.paperId and $act_reviewer_sql group by paperId), '')");
            }
        }
    }
    /** @param ReviewField $f */
    function add_score_column($f) {
        if (is_string($f)) {
            error_log("add_score_column error: " . debug_string_backtrace());
            $f = $this->srch->conf->review_field($f);
        }
        $this->add_review_signature_columns();
        if ($f && $f->main_storage && !in_array($f, $this->_review_scores ?? [])) {
            $this->_review_scores[] = $f;
        }
    }
    function add_review_word_count_columns() {
        $this->add_review_signature_columns();
        if (!isset($this->columns["reviewWordCountSignature"])) {
            $this->add_column("reviewWordCountSignature", "coalesce((select group_concat(coalesce(reviewWordCount,'.') order by reviewId) from PaperReview force index (primary) where PaperReview.paperId=Paper.paperId), '')");
        }
    }
    function add_allConflictType_column() {
        if (!isset($this->columns["allConflictType"])) {
            $this->add_column("allConflictType", "coalesce((select group_concat(contactId, ' ', conflictType) from PaperConflict force index (paperId) where PaperConflict.paperId=Paper.paperId), '')");
        }
    }
}

class PaperSearch extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var string
     * @readonly */
    public $q;
    /** @var string
     * @readonly */
    private $_qt;
    /** @var ?Contact
     * @readonly */
    private $_reviewer_user;

    /** @var ?SearchTerm */
    private $_qe;
    /** @var Limit_SearchTerm
     * @readonly */
    private $_limit_qe;
    /** @var bool */
    private $_limit_explicit = false;

    /** @var bool
     * @readonly */
    public $expand_automatic = false;
    /** @var bool */
    private $_allow_deleted = false;
    /** @var ?string */
    private $_urlbase;
    /** @var ?string
     * @readonly */
    private $_default_sort; // XXX should be used more often

    /** @var ?array<string,TextPregexes> */
    private $_match_preg;
    /** @var ?string */
    private $_match_preg_query;
    /** @var ?list<ContactSearch> */
    private $_contact_searches;
    /** @var list<int> */
    private $_matches;
    /** @var ?array<int,int> */
    private $_then_map;
    /** @var ?array<int,list<string>> */
    private $_highlight_map;

    /** @var ?ReviewInfo */
    public $test_review;

    static public $search_type_names = [
        "a" => "Your submissions",
        "acc" => "Accepted",
        "act" => "Active",
        "admin" => "Submissions you administer",
        "all" => "All",
        "alladmin" => "Submissions you’re allowed to administer",
        "lead" => "Your discussion leads",
        "r" => "Your reviews",
        "reviewable" => "Reviewable",
        "req" => "Your review requests",
        "rout" => "Your incomplete reviews",
        "s" => "Submitted",
        "undec" => "Undecided",
        "viewable" => "Submissions you can view"
    ];

    static private $ss_recursion = 0;

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

        // query fields
        // NB: If a complex query field, e.g., "re", "tag", or "option", is
        // default, then it must be the only default or query construction
        // will break.
        $this->_qt = self::_canonical_qt($options["qt"] ?? null);

        // the query itself
        $this->q = trim($options["q"] ?? "");
        $this->_default_sort = $options["sort"] ?? null;
        $this->set_want_ftext(true);

        // reviewer
        if (($reviewer = $options["reviewer"] ?? null)) {
            $ruser = null;
            if (is_string($reviewer)) {
                if (strcasecmp($reviewer, $user->email) === 0) {
                    $ruser = $user;
                } else if ($user->can_view_pc()) {
                    $ruser = $this->conf->pc_member_by_email($reviewer);
                }
            } else if (is_object($reviewer) && ($reviewer instanceof Contact)) {
                $ruser = $reviewer;
            }
            if ($ruser && $ruser !== $this->user) {
                assert($ruser->contactId > 0);
                $this->_reviewer_user = $ruser;
            }
        }

        // paper selection
        $limit = self::canonical_limit($options["t"] ?? "") ?? "";
        if ($limit === "") {
            // Empty limit should be the plausible limit for a default search,
            // as in entering text into a quicksearch box.
            if ($user->privChair
                && ($user->is_root_user() || $this->conf->time_edit_paper())) {
                $limit = "all";
            } else if ($user->isPC) {
                $limit = $this->conf->time_pc_view_active_submissions() ? "act" : "s";
            } else if (!$user->is_reviewer()) {
                $limit = "a";
            } else if (!$user->is_author()) {
                $limit = "r";
            } else {
                $limit = "ar";
            }
        }
        $lword = new SearchWord($limit, "in:{$limit}");
        $this->_limit_qe = Limit_SearchTerm::parse($limit, $lword, $this);
    }

    private function clear_compilation() {
        $this->clear_messages();
        $this->_qe = null;
        $this->_match_preg = null;
        $this->_match_preg_query = null;
        $this->_contact_searches = null;
        $this->_matches = null;
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
            $args["t"] = $this->_limit_qe->named_limit;
        }
        if (!isset($args["qt"]) && $this->_qt !== "n") {
            $args["qt"] = $this->_qt;
        }
        if (!isset($args["reviewer"])
            && $this->_reviewer_user
            && $this->_reviewer_user->contactId !== $this->user->contactXid) {
            $args["reviewer"] = $this->_reviewer_user->email;
        }
        $this->_urlbase = $this->conf->hoturl_raw($base, $args, Conf::HOTURL_SITEREL);
        return $this;
    }

    /** @param bool $x
     * @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_expand_automatic($x) {
        $this->_qe === null || $this->clear_compilation();
        $this->expand_automatic = $x;
        return $this;
    }

    /** @return string */
    function limit() {
        return $this->_limit_qe->limit;
    }
    /** @return bool */
    function limit_submitted() {
        return ($this->_limit_qe->lflag & self::LFLAG_SUBMITTED) !== 0;
    }
    /** @return bool */
    function limit_author() {
        return $this->_limit_qe->limit === "a";
    }
    /** @return bool */
    function show_submitted_status() {
        return in_array($this->_limit_qe->limit, ["a", "act", "all"])
            && $this->q !== "re:me";
    }
    /** @return bool */
    function limit_accepted() {
        return $this->_limit_qe->limit === "acc";
    }
    /** @return bool */
    function limit_explicit() {
        return $this->_limit_explicit;
    }
    function apply_limit(Limit_SearchTerm $limit) {
        if (!$this->_limit_explicit) {
            $this->_limit_qe->set_limit($limit->named_limit);
            $this->_limit_explicit = true;
        }
    }

    /** @return Contact */
    function reviewer_user() {
        return $this->_reviewer_user ?? $this->user;
    }


    /** @return MessageSet */
    function message_set() {
        return $this;
    }

    /** @return bool */
    function has_problem() {
        $this->_qe || $this->term();
        return parent::has_problem();
    }

    /** @return list<MessageItem> */
    function message_list() {
        $this->_qe || $this->term();
        return parent::message_list();
    }

    /** @param string $message
     * @return MessageItem */
    function warning($message) {
        return $this->warning_at(null, $message);
    }

    /** @param SearchWord $sw
     * @param string $message
     * @return MessageItem */
    function lwarning($sw, $message) {
        $mi = $this->warning($message);
        $mi->pos1 = $sw->pos1;
        $mi->pos2 = $sw->pos2;
        $mi->context = $this->q;
        return $mi;
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

    /** @return ContactSearch */
    private function _find_contact_search($type, $word) {
        foreach ($this->_contact_searches ?? [] as $cs) {
            if ($cs->type === $type && $cs->text === $word)
                return $cs;
        }
        $this->_contact_searches[] = $cs = new ContactSearch($type, $word, $this->user);
        return $cs;
    }
    /** @return ContactSearch */
    private function _contact_search($type, $word, $quoted, $pc_only) {
        $xword = $word;
        if ($quoted === null) {
            $word = SearchWord::unquote($word);
            $quoted = strlen($word) !== strlen($xword);
        }
        $type |= ($pc_only ? ContactSearch::F_PC : 0)
            | ($quoted ? ContactSearch::F_QUOTED : 0)
            | (!$quoted && $this->user->isPC ? ContactSearch::F_TAG : 0);
        $cs = $this->_find_contact_search($type, $word);
        if ($cs->warn_html) {
            $this->warning("<5>{$cs->warn_html}");
        }
        return $cs;
    }
    /** @param string $word
     * @param ?bool $quoted
     * @param bool $pc_only
     * @return list<int> */
    function matching_uids($word, $quoted, $pc_only) {
        $scm = $this->_contact_search(ContactSearch::F_USER, $word, $quoted, $pc_only);
        return $scm->user_ids();
    }
    /** @param string $word
     * @param bool $quoted
     * @param bool $pc_only
     * @return list<Contact> */
    function matching_contacts($word, $quoted, $pc_only) {
        $scm = $this->_contact_search(ContactSearch::F_USER, $word, $quoted, $pc_only);
        return $scm->users();
    }
    /** @param string $word
     * @param bool $quoted
     * @param bool $pc_only
     * @return ?list<int> */
    function matching_special_uids($word, $quoted, $pc_only) {
        $scm = $this->_contact_search(0, $word, $quoted, $pc_only);
        return $scm->has_error() ? null : $scm->user_ids();
    }

    /** @param string $word
     * @param bool $quoted */
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
            && ($k = Text::simple_search($word, ["w0" => "withdrawn", "s0" => "submitted", "s1" => "ready", "s2" => "complete", "u0" => "in progress", "u1" => "unsubmitted", "u2" => "not ready", "u3" => "incomplete", "u4" => "draft", "a0" => "active", "x0" => "no submission"]))) {
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

    /** @return SearchTerm */
    static function parse_has($word, SearchWord $sword, PaperSearch $srch) {
        $kword = $word;
        $kwdef = $srch->conf->search_keyword($kword, $srch->user);
        if (!$kwdef && ($kword = strtolower($word)) !== $word) {
            $kwdef = $srch->conf->search_keyword($kword, $srch->user);
        }
        if ($kwdef) {
            if ($kwdef->parse_has_function ?? null) {
                $qe = call_user_func($kwdef->parse_has_function, $word, $sword, $srch);
            } else if ($kwdef->has ?? null) {
                $sword2 = new SearchWord($kwdef->has, $sword->source);
                $sword2->kwexplicit = true;
                $sword2->kwdef = $kwdef;
                $sword2->pos1 = $sword->pos1;
                $sword2->pos1w = $sword->pos1w;
                $sword2->pos2 = $sword->pos2;
                $qe = call_user_func($kwdef->parse_function, $kwdef->has, $sword2, $srch);
            } else {
                $qe = null;
            }
            if ($qe) {
                return $qe;
            }
        }
        $srch->lwarning($sword, "<0>Unknown search ‘has:{$word}’ won’t match anything");
        return new False_SearchTerm;
    }

    /** @param string $word
     * @return ?string */
    private function _expand_saved_search($word) {
        $sj = $this->conf->setting_json("ss:$word");
        if ($sj && is_object($sj) && isset($sj->q)) {
            $q = $sj->q;
            if (isset($sj->t) && $sj->t !== "" && $sj->t !== "s") {
                $q = "($q) in:{$sj->t}";
            }
            return $q;
        } else {
            return null;
        }
    }

    /** @param string $word
     * @return ?SearchTerm */
    static function parse_saved_search($word, SearchWord $sword, PaperSearch $srch) {
        if (!$srch->user->isPC) {
            return null;
        }
        $qe = null;
        ++self::$ss_recursion;
        if (!$srch->conf->setting_data("ss:$word")) {
            $srch->lwarning($sword, "<0>Saved search not found");
        } else if (self::$ss_recursion > 10) {
            $srch->lwarning($sword, "<0>Saved search defined in terms of itself");
        } else if (($nextq = $srch->_expand_saved_search($word))) {
            if (($qe = $srch->_search_expression($nextq))) {
                $qe->set_strspan_owner($nextq);
            }
        } else {
            $srch->lwarning($sword, "<0>Saved search defined incorrectly");
        }
        --self::$ss_recursion;
        return $qe ?? new False_SearchTerm;
    }

    /** @param ?string $keyword
     * @param ?SearchScope $scope */
    private function _search_keyword(&$qt, SearchWord $sword, $keyword, $scope) {
        $word = $sword->word;
        $sword->kwexplicit = !!$scope;
        $lkeyword = $keyword ?? $scope->defkw;
        $sword->kwdef = $this->conf->search_keyword($lkeyword, $this->user);
        if ($sword->kwdef && ($sword->kwdef->parse_function ?? null)) {
            $qx = call_user_func($sword->kwdef->parse_function, $word, $sword, $this);
            if ($qx && !is_array($qx)) {
                $qt[] = $qx;
            } else if ($qx) {
                $qt = array_merge($qt, $qx);
            }
        } else if ($keyword !== null) {
            $sword->pos2 = $sword->pos1 + strlen($keyword) + 1;
            $this->lwarning($sword, "<0>Unknown search ‘{$lkeyword}:’ won’t match anything");
        } else if (!$scope->defkw_scope->defkw_error) {
            $sword->pos1 = $scope->defkw_scope->defkw_pos1;
            $sword->pos2 = $sword->pos1 + strlen($scope->defkw) + 1;
            $this->lwarning($sword, "<0>Unknown search ‘{$lkeyword}:’ won’t match anything");
            $scope->defkw_scope->defkw_error = true;
        }
    }

    /** @param string $word
     * @param string $defkw
     * @return array{string,string} */
    static private function _search_word_breakdown($word, $defkw = "") {
        $ch = substr($word, 0, 1);
        if ($ch !== ""
            && $defkw === ""
            && (ctype_digit($ch) || ($ch === "#" && ctype_digit((string) substr($word, 1, 1))))
            && preg_match('/\A(?:#?\d+(?:(?:-|–|—)#?\d+)?(?:\s*,\s*|\z))+\z/s', $word)) {
            return ["=", $word];
        } else if ($ch === "#"
                   && $defkw === "") {
            return ["#", substr($word, 1)];
        } else if (preg_match('/\A([-_.a-zA-Z0-9]+|"[^"]")((?:[=!<>]=?|≠|≤|≥)[^:]+|:.*)\z/s', $word, $m)) {
            return [$m[1], $m[2]];
        } else {
            return ["", $word];
        }
    }

    /** @return list<string> */
    private function _qt_fields() {
        if ($this->_qt === "n") {
            return $this->user->can_view_some_authors() ? ["ti", "ab", "au"] : ["ti", "ab"];
        } else {
            return [$this->_qt];
        }
    }

    /** @param string $source
     * @param SearchScope $scope
     * @param int $pos1
     * @param int $pos2
     * @param int $dpos
     * @return ?SearchTerm */
    private function _search_word($source, $scope, $pos1, $pos2, $dpos) {
        $word = $source;
        $wordbrk = self::_search_word_breakdown($word, $scope->defkw ?? "");
        $keyword = null;

        if ($wordbrk[0] === "=") {
            // paper numbers
            $st = new PaperID_SearchTerm;
            while (preg_match('/\A#?(\d+)(?:(?:-|–|—)#?(\d+))?\s*,?\s*(.*)\z/s', $word, $m)) {
                $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
                $st->add_range(intval($m[1]), intval($m[2]));
                $word = $m[3];
            }
            return $st;
        } else if ($wordbrk[0] === "#") {
            // `#TAG`
            $ignored = $this->swap_ignore_messages(true);
            $qe = $this->_search_word("hashtag:{$wordbrk[1]}", $scope, $pos1, $pos2, $dpos - 7);
            $this->swap_ignore_messages($ignored);
            if (!($qe instanceof False_SearchTerm)) {
                return $qe;
            }
        } else if ($wordbrk[0] !== "") {
            // `keyword:word` or (potentially) `keyword>word`
            if ($wordbrk[1][0] === ":") {
                $keyword = $wordbrk[0];
                $word = $wordbrk[1];
                $pos = 1;
                while ($pos < strlen($word) && ctype_space($word[$pos])) {
                    ++$pos;
                }
                $word = substr($word, $pos);
                $dpos += strlen($keyword) + $pos;
            } else {
                // Allow searches like "ovemer>2"; parse as "ovemer:>2".
                $ignored = $this->swap_ignore_messages(true);
                $qe = $this->_search_word("{$wordbrk[0]}:{$wordbrk[1]}", $scope, $pos1, $pos2, $dpos - 1);
                $this->swap_ignore_messages($ignored);
                if ($qe instanceof False_SearchTerm) {
                    if ($qe->score_warning) {
                        $this->message_set()->append_item($qe->score_warning);
                        return $qe;
                    }
                } else {
                    return $qe;
                }
            }
        }

        if ($keyword !== null && str_starts_with($keyword, '"')) {
            $keyword = trim(substr($keyword, 1, strlen($keyword) - 2));
        }

        $qt = [];
        $sword = new SearchWord($word, $source);
        $sword->pos1 = $pos1;
        $sword->pos1w = $pos1 + $dpos;
        $sword->pos2 = $pos2;
        if ($keyword !== null || $scope->defkw !== null) {
            $this->_search_keyword($qt, $sword, $keyword, $scope);
        } else {
            // Special-case unquoted "*", "ANY", "ALL", "NONE", "".
            if ($word === "*" || $word === "ANY" || $word === "ALL"
                || $word === "") {
                return new True_SearchTerm;
            } else if ($word === "NONE") {
                return new False_SearchTerm;
            }
            // Otherwise check known keywords.
            foreach ($this->_qt_fields() as $kw) {
                $this->_search_keyword($qt, $sword, $kw, null);
            }
        }
        return SearchTerm::combine("or", $qt);
    }

    /** @param string $str
     * @return string */
    static function escape_word($str) {
        $pos = SearchSplitter::span_balanced_parens($str);
        if ($pos === strlen($str)) {
            return $str;
        } else {
            return "\"" . str_replace("\"", "\\\"", $str) . "\"";
        }
    }

    /** @return ?SearchOperator */
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

    /** @return string */
    static private function _shift_word(SearchSplitter $splitter, Conf $conf) {
        if (($t = $splitter->shift_keyword()) !== "") {
            $kwx = $t[0] === '"' ? substr($t, 1, -2) : substr($t, 0, -1);
            $kwd = $conf->search_keyword($kwx);
            if ($kwd && ($kwd->allow_parens ?? false)) {
                return $t . $splitter->shift_balanced_parens();
            }
        }
        return $t . $splitter->shift("()");
    }

    /** @param string $str
     * @return ?SearchTerm */
    private function _search_expression($str) {
        $scope = new SearchScope(null, null, 0, strlen($str), null);
        $next_defkw = null;
        $parens = 0;
        $curqe = null;
        $splitter = new SearchSplitter($str);

        while (!$splitter->is_empty()) {
            $pos1 = $splitter->pos;
            $op = self::_shift_keyword($splitter, $curqe);
            $pos2 = $splitter->last_pos;
            if ($curqe && !$op) {
                $op = SearchOperator::get("SPACE");
            }
            if (!$curqe && $op && $op->op === "highlight") {
                $curqe = new True_SearchTerm;
                $curqe->set_strspan($pos1, $pos1);
            }

            if (!$op) {
                $pos1 = $splitter->pos;
                $word = self::_shift_word($splitter, $this->conf);
                $pos2 = $splitter->last_pos;
                // Bare any-case "all", "any", "none" are treated as keywords.
                if (!$curqe
                    && (!$scope->op || $scope->op->precedence <= 2)
                    && ($uword = strtoupper($word))
                    && ($uword === "ALL" || $uword === "ANY" || $uword === "NONE")
                    && $splitter->match('/\G(?:|(?:THEN|then|HIGHLIGHT(?::\w+)?)(?:\s|\().*)\z/')) {
                    $word = $uword;
                }
                if ($word === "") {
                    error_log("problem: no op, str “{$str}” in “{$this->q}”");
                    break;
                }
                // Search like "ti:(foo OR bar)" adds a default keyword.
                if ($word[strlen($word) - 1] === ":"
                    && preg_match('/\A(?:[-_.a-zA-Z0-9]+:|"[^"]+":)\z/s', $word)
                    && $splitter->starts_with("(")) {
                    $next_defkw = [substr($word, 0, strlen($word) - 1), $pos1];
                } else {
                    // The heart of the matter.
                    $curqe = $this->_search_word($word, $scope, $pos1, $pos2, 0);
                    if (!$curqe->is_uninteresting()) {
                        $curqe->set_strspan($pos1, $pos2);
                    }
                }
            } else if ($op->op === ")") {
                while ($scope->op && $scope->op->op !== "(") {
                    list($curqe, $scope) = $scope->pop($curqe);
                }
                if ($scope->op) {
                    $scope->pos2 = $pos1;
                    list($curqe, $scope) = $scope->pop($curqe);
                    --$parens;
                }
            } else if ($op->op === "(") {
                assert(!$curqe);
                $scope = new SearchScope($op, null, $pos1, $pos2, $scope);
                if ($next_defkw) {
                    $scope->defkw = $next_defkw[0];
                    $scope->defkw_pos1 = $next_defkw[1];
                    $scope->defkw_scope = $scope;
                    $next_defkw = null;
                }
                ++$parens;
            } else if ($op->unary || $curqe) {
                $end_precedence = $op->precedence - ($op->precedence <= 1 ? 1 : 0);
                while ($scope->op && $scope->op->precedence > $end_precedence) {
                    list($curqe, $scope) = $scope->pop($curqe);
                }
                $scope = new SearchScope($op, $curqe, $pos1, $pos2, $scope);
                $curqe = null;
            }
        }

        while ($scope->op) {
            list($curqe, $scope) = $scope->pop($curqe);
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
        $splitter = new SearchSplitter($str);

        while (!$splitter->is_empty()) {
            $op = self::_shift_keyword($splitter, $curqe);
            if ($curqe && !$op) {
                $op = SearchOperator::get($parens ? "SPACE" : $defaultop);
            }
            if (!$op) {
                $curqe = self::_shift_word($splitter, $conf);
                if ($qt !== "n") {
                    $wordbrk = self::_search_word_breakdown($curqe, "");
                    if ($wordbrk[0] === "") {
                        $curqe = ($qt === "tag" ? "#" : "{$qt}:") . $curqe;
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

    /** @param ?string $qa
     * @param ?string $qo
     * @param ?string $qx
     * @param ?string $qt
     * @param ?string $t
     * @return string */
    static function canonical_query($qa, $qo, $qx, $qt, Conf $conf, $t = null) {
        $qt = self::_canonical_qt($qt);
        $x = [];
        if (($t ?? "") !== ""
            && ($t = self::long_canonical_limit($t)) !== null) {
            $qa = ($qa ?? "") !== "" ? "({$qa}) in:$t" : "in:$t";
        }
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
        // This query should return those reviewIds whose ratings
        // are not visible to the current querier:
        // reviews by `$user` on papers with <=2 reviews and <=2 ratings
        $rateset = $user->conf->setting("rev_rating");
        if ($rateset == REV_RATINGS_PC) {
            $npr_constraint = "reviewType>" . REVIEW_EXTERNAL;
        } else {
            $npr_constraint = "true";
        }
        $result = $user->conf->qe("select r.reviewId,
            coalesce((select count(*) from ReviewRating force index (primary) where paperId=r.paperId),0) numRatings,
            coalesce((select count(*) from PaperReview r force index (primary) where paperId=r.paperId and reviewNeedsSubmit=0 and {$npr_constraint}),0) numReviews
            from PaperReview r
            join ReviewRating rr on (rr.paperId=r.paperId and rr.reviewId=r.reviewId)
            where r.contactId={$user->contactId}
            having numReviews<=2 and numRatings<=2");
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
            foreach ($qe->paper_ids() ?? [] as $p) {
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
            if ($this->q === "re:me"
                && $this->_limit_qe->lflag === $this->_limit_qe->reviewer_lflag()) {
                $this->_qe = new Limit_SearchTerm($this->user, $this->user, "r", true);
            } else if (($qe = $this->_search_expression($this->q))) {
                $this->_qe = $qe;
            } else {
                $this->_qe = new True_SearchTerm;
            }
            //Conf::msg_debugt(json_encode($this->_qe->debug_json()));

            // extract regular expressions
            $this->_qe->configure_search(true, $this);
        }
        return $this->_qe;
    }

    private function _prepare_result(SearchTerm $qe) {
        $sqi = new SearchQueryInfo($this);
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
            return Dbl_Result::make_empty();
        }

        // add permissions tables and columns
        // XXX some of this should be shared with paperQuery
        if ($this->conf->rights_need_tags()
            || $this->conf->has_tracks() /* XXX probably only need check_track_view_sensitivity */
            || ($sqi->query_options["tags"] ?? false)
            || ($this->user->privChair
                && $this->conf->has_any_manager()
                && $this->conf->tags()->has_sitewide)) {
            $sqi->add_column("paperTags", "coalesce((select group_concat(' ', tag, '#', tagIndex separator '') from PaperTag force index (primary) where PaperTag.paperId=Paper.paperId), '')");
        }
        if ($sqi->query_options["reviewSignatures"] ?? false) {
            $sqi->add_review_signature_columns();
        }
        foreach ($sqi->query_options["scores"] ?? [] as $f) {
            $sqi->add_score_column($f);
        }
        if ($sqi->query_options["reviewWordCounts"] ?? false) {
            $sqi->add_review_word_count_columns();
        }
        if ($sqi->query_options["authorInformation"] ?? false) {
            $sqi->add_column("authorInformation", "Paper.authorInformation");
        }
        if ($sqi->query_options["pdfSize"] ?? false) {
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
                $joiners = ["{$tabname}.paperId=Paper.paperId"];
                for ($i = 2; $i < count($value); ++$i) {
                    if ($value[$i])
                        $joiners[] = "(" . str_replace("{}", $tabname, $value[$i]) . ")";
                }
                $q .= "\n    " . $value[0] . " " . $value[1] . " as " . $tabname
                    . " on (" . join("\n        and ", $joiners) . ")";
            }
        }
        $q .= "\n    where {$filter}\n    group by Paper.paperId";

        //Conf::msg_debugt($q);
        //error_log($q);

        // actually perform query
        return $this->conf->qe_raw($q);
    }

    private function _prepare() {
        if ($this->_matches !== null) {
            return;
        }
        $this->_matches = [];
        if ($this->limit() === "none") {
            return;
        }

        $qe = $this->term();
        //Conf::msg_debugt(json_encode($qe->debug_json()));
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_CONFLICT);

        // collect papers
        $result = $this->_prepare_result($qe);
        $rowset = new PaperInfoSet;
        while (($row = PaperInfo::fetch($result, $this->user))) {
            $rowset->add($row);
        }
        Dbl::free($result);

        // filter papers
        $thqe = $qe instanceof Then_SearchTerm ? $qe : null;
        $this->_then_map = [];
        if ($thqe && $thqe->has_highlight()) {
            $this->_highlight_map = [];
        }
        foreach ($rowset as $row) {
            if ($this->user->can_view_paper($row)
                && $this->_limit_qe->test($row, null)
                && $qe->test($row, null)) {
                $this->_matches[] = $row->paperId;
                $this->_then_map[$row->paperId] = $thqe ? $thqe->_last_group() : 0;
                if ($this->_highlight_map !== null
                    && ($hls = $thqe->_last_highlights($row)) !== []) {
                    $this->_highlight_map[$row->paperId] = $hls;
                }
            }
        }

        // add deleted papers explicitly listed by number (e.g. action log)
        if ($this->_allow_deleted) {
            $this->_add_deleted_papers($qe);
        } else if ($this->_limit_qe->named_limit === "s"
                   && $this->user->privChair
                   && ($ps = $this->_check_missing_papers($qe))
                   && $this->conf->fetch_ivalue("select exists (select * from Paper where paperId?a)", $ps)) {
            $this->warning("<5>Some incomplete or withdrawn submissions also match this search. " . Ht::link("Show all matching submissions", $this->conf->hoturl("search", ["t" => "all", "q" => $this->q])));
        }

        $this->user->set_overrides($old_overrides);
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

    /** @return list<TagAnno> */
    function paper_groups() {
        $this->_prepare();
        $qe1 = $this->_qe;
        if ($qe1 instanceof Then_SearchTerm) {
            if ($qe1->nthen > 1) {
                $gs = [];
                for ($i = 0; $i !== $qe1->nthen; ++$i) {
                    $ch = $qe1->child[$i];
                    $h = $ch->get_float("legend");
                    if ($h === null) {
                        $spanstr = $ch->get_float("strspan_owner") ?? $this->q;
                        $h = rtrim(substr($spanstr, $ch->pos1 ?? 0, ($ch->pos2 ?? 0) - ($ch->pos1 ?? 0)));
                    }
                    $gs[] = TagAnno::make_legend($h);
                }
                return $gs;
            } else {
                $qe1 = $qe1->child[0];
            }
        }
        if (($h = $qe1->get_float("legend"))) {
            return [TagAnno::make_legend($h)];
        } else {
            return [];
        }
    }

    /** @param int $pid
     * @return ?int */
    function paper_group_index($pid) {
        $this->_prepare();
        return $this->_then_map[$pid] ?? null;
    }

    /** @return array<int,int> */
    function groups_by_paper_id() {
        $this->_prepare();
        return $this->_then_map;
    }

    /** @return ?array<int,list<string>> */
    function highlights_by_paper_id() {
        $this->_prepare();
        return $this->_highlight_map;
    }

    /** @param iterable<string>|iterable<array{string,?int,?int,?int}> $words
     * @return Generator<array{string,string,list<string>,?int,?int}> */
    static function view_generator($words) {
        foreach ($words as $w) {
            if (is_array($w)) {
                $pos1 = $w[1];
                $pos2 = $w[3];
                $w = $w[0];
            } else {
                $pos1 = $pos2 = null;
            }

            $colon = strpos($w, ":");
            if ($colon === false
                || !in_array(substr($w, 0, $colon), ["show", "sort", "edit", "hide", "showsort", "editsort"])) {
                $w = "show:" . $w;
                $colon = 4;
            }

            $action = substr($w, 0, $colon);
            $d = substr($w, $colon + 1);
            $keyword = null;
            if (str_starts_with($d, "[")) { /* XXX backward compat */
                $d = substr($d, 1, strlen($d) - (str_ends_with($d, "]") ? 2 : 1));
            } else if (str_ends_with($d, "]")
                       && ($lbrack = strrpos($d, "[")) !== false) {
                $keyword = substr($d, 0, $lbrack);
                $d = substr($d, $lbrack + 1, strlen($d) - $lbrack - 2);
            }

            $decorations = [];
            if ($d !== "") {
                $splitter = new SearchSplitter($d);
                while ($splitter->skip_span(" \n\r\t\v\f,")) {
                    $decorations[] = $splitter->shift_balanced_parens(" \n\r\t\v\f,");
                }
            }

            $keyword = $keyword ?? array_shift($decorations) ?? "";
            if ($keyword !== "") {
                if ($keyword[0] === "-") {
                    $keyword = substr($keyword, 1);
                    array_unshift($decorations, "reverse");
                } else if ($keyword[0] === "+") {
                    $keyword = substr($keyword, 1);
                }
                if ($keyword !== "") {
                    yield [$action, $keyword, $decorations, $pos1, $pos2];
                }
            }
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
            return "{$action}:{$keyword}[" . join(" ", $decorations) . "]";
        } else {
            return "{$action}:{$keyword}";
        }
    }

    /** @return list<string> */
    private function sort_field_list() {
        $r = [];
        foreach (self::view_generator($this->term()->view_anno() ?? []) as $akd) {
            if (str_ends_with($akd[0], "sort")) {
                $r[] = $akd[1];
            }
        }
        return $r;
    }

    /** @param callable(int):bool $callback
     * @return void */
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
        $x = $this->user->can_view_paper($prow)
            && $this->_limit_qe->test($prow, null)
            && $qe->test($prow, null);
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
            if ($this->user->can_view_paper($prow)
                && $this->_limit_qe->test($prow, null)
                && $qe->test($prow, null)) {
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
        $x = $this->user->can_view_paper($prow)
            && $this->_limit_qe->test($prow, $rrow)
            && $qe->test($prow, $rrow);
        $this->user->set_overrides($old_overrides);
        return $x;
    }

    /** @return array<string,mixed>|false */
    function simple_search_options() {
        $queryOptions = [];
        if ($this->_matches === null
            && $this->_limit_qe->simple_search($queryOptions)
            && $this->term()->simple_search($queryOptions)) {
            return $queryOptions;
        } else {
            return false;
        }
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
    function default_limited_query() {
        if ($this->user->isPC
            && !$this->_limit_explicit
            && $this->limit() !== ($this->conf->time_pc_view_active_submissions() ? "act" : "s")) {
            return self::canonical_query($this->q, "", "", $this->_qt, $this->conf, $this->limit());
        } else {
            return $this->q;
        }
    }

    /** @return string */
    function url_site_relative_raw($q = null) {
        $url = $this->urlbase();
        $q = $q ?? $this->q;
        if ($q !== "" || substr($url, 0, 6) === "search") {
            $url .= (strpos($url, "?") === false ? "?q=" : "&q=") . urlencode($q);
        }
        return $url;
    }

    /** @return string */
    function description($listname) {
        if ($listname) {
            $lx = $this->conf->_($listname);
        } else {
            $limit = $this->limit();
            if ($this->q === "re:me" && in_array($limit, ["r", "s", "act"], true)) {
                $limit = "r";
            }
            $lx = self::limit_description($this->conf, $limit);
        }
        if ($this->q === ""
            || ($this->q === "re:me" && $this->limit() === "s")
            || ($this->q === "re:me" && $this->limit() === "act")) {
            return $lx;
        } else if (str_starts_with($this->q, "au:")
                   && strlen($this->q) <= 36
                   && $this->term() instanceof Author_SearchTerm) {
            return "$lx by " . ltrim(substr($this->q, 3));
        } else if (strlen($this->q) <= 24
                   || $this->term() instanceof Tag_SearchTerm) {
            return "{$this->q} in $lx";
        } else {
            return "$lx search";
        }
    }

    /** @param ?string $sort
     * @return string */
    function listid($sort = null) {
        $rest = [];
        if ($this->_reviewer_user
            && $this->_reviewer_user->contactXid !== $this->user->contactXid) {
            $rest[] = "reviewer=" . urlencode($this->_reviewer_user->email);
        }
        if ($sort !== null && $sort !== "") {
            $rest[] = "sort=" . urlencode($sort);
        }
        return "p/" . $this->_limit_qe->named_limit . "/" . urlencode($this->q)
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
        $l = (new SessionList($this->listid($sort), $ids, $this->description($listname)))
            ->set_urlbase($this->urlbase());
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
        $this->_prepare();
        $ht = $this->term()->get_float("tags") ?? [];
        foreach ($this->sort_field_list() as $s) {
            if (($tag = Tagger::check_tag_keyword($s, $this->user)))
                $ht[] = $tag;
        }
        return array_values(array_unique($ht));
    }


    /** @param string $q */
    function set_field_highlighter_query($q) {
        $ps = new PaperSearch($this->user, ["q" => $q]);
        $this->_match_preg = $ps->field_highlighters();
        $this->_match_preg_query = $q;
    }

    /** @return array<string,TextPregexes> */
    function field_highlighters() {
        $this->term();
        return $this->_match_preg ?? [];
    }

    /** @return string */
    function field_highlighter($field) {
        return ($this->field_highlighters())[$field] ?? "";
    }

    /** @param string $field */
    function add_field_highlighter($field, TextPregexes $regex) {
        if (!$this->_match_preg_query && !$regex->is_empty()) {
            $this->_match_preg[$field] = $this->_match_preg[$field] ?? TextPregexes::make_empty();
            $this->_match_preg[$field]->add_matches($regex);
        }
    }


    /** @return string */
    static function limit_description(Conf $conf, $t) {
        return $conf->_c("search_type", self::$search_type_names[$t] ?? "Submitted");
    }

    /** @param ?string $reqtype
     * @return ?string */
    static function canonical_limit($reqtype) {
        if ($reqtype !== null
            && ($x = Limit_SearchTerm::$reqtype_map[$reqtype] ?? null) !== null) {
            return is_array($x) ? $x[0] : $x;
        } else {
            return null;
        }
    }

    /** @param ?string $reqtype
     * @return ?string */
    static function long_canonical_limit($reqtype) {
        if ($reqtype !== null
            && ($x = Limit_SearchTerm::$reqtype_map[$reqtype] ?? null) !== null) {
            return is_array($x) ? $x[1] : $x;
        } else {
            return null;
        }
    }

    /** @param ?string $reqtype
     * @return list<string> */
    static function viewable_limits(Contact $user, $reqtype = null) {
        if ($reqtype !== null && $reqtype !== "") {
            $reqtype = self::canonical_limit($reqtype);
        }
        $ts = [];
        if ($reqtype === "viewable") {
            $ts[] = "viewable";
        }
        if ($user->isPC) {
            if ($user->conf->time_pc_view_active_submissions()) {
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
            && !$user->conf->time_pc_view_active_submissions()
            && $reqtype === "act") {
            $ts[] = "act";
        }
        if ($user->privChair) {
            $ts[] = "all";
        }
        return $ts;
    }

    /** @return list<string> */
    static function viewable_manager_limits(Contact $user) {
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
        return $ts;
    }

    /** @param list<string> $limits
     * @param string $selected
     * @return string */
    static function limit_selector(Conf $conf, $limits, $selected, $extra = []) {
        if ($extra["select"] ?? count($limits) > 1) {
            unset($extra["select"]);
            $sel_opt = [];
            foreach ($limits as $k) {
                $sel_opt[$k] = self::limit_description($conf, $k);
            }
            if (!isset($extra["aria-label"])) {
                $extra["aria-label"] = "Search collection";
            }
            return Ht::select("t", $sel_opt, $selected, $extra);
        } else {
            $t = self::limit_description($conf, $selected);
            if (isset($extra["id"])) {
                $t = '<span id="' . htmlspecialchars($extra["id"]) . "\">{$t}</span>";
            }
            return $t . Ht::hidden("t", $selected);
        }
    }
}
