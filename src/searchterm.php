<?php
// searchterm.php -- HotCRP paper search terms
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

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
    /** @var ?SearchStringContext */
    public $string_context;

    /** @param string $type */
    function __construct($type) {
        $this->type = $type;
    }

    /** @param string|SearchOperator $op
     * @return ?Op_SearchTerm */
    static function make_op($op) {
        $name = is_string($op) ? $op : $op->type;
        if ($name === "not") {
            return new Not_SearchTerm;
        } else if ($name === "and" || $name === "space") {
            return new And_SearchTerm($name);
        } else if ($name === "or") {
            return new Or_SearchTerm;
        } else if ($name === "xor") {
            return new Xor_SearchTerm;
        } else if ($name === "then" || $name === "highlight") {
            return new Then_SearchTerm($op);
        }
        return null;
    }

    /** @param string|SearchOperator $op
     * @param SearchTerm ...$terms
     * @return SearchTerm */
    static function combine($op, ...$terms) {
        return self::combine_in($op, null, ...$terms);
    }

    /** @param string|SearchOperator $op
     * @param SearchStringContext $string_context
     * @param SearchTerm ...$terms
     * @return SearchTerm */
    static function combine_in($op, $string_context, ...$terms) {
        $name = is_string($op) ? $op : $op->type;
        if ($name !== "not" && count($terms) === 1) {
            return $terms[0];
        }
        $qr = self::make_op($op);
        foreach ($terms as $qt) {
            $qr->op_append($qt, $string_context);
        }
        return $qr->op_finish();
    }

    /** @return SearchTerm */
    function negate() {
        $qr = new Not_SearchTerm;
        return $qr->op_append($this, $this->string_context)->op_finish();
    }

    /** @param bool $negate
     * @return SearchTerm */
    final function negate_if($negate) {
        return $negate ? $this->negate() : $this;
    }

    /** @param string $command
     * @param SearchWord $sword
     * @return $this */
    final function add_view_anno($command, $sword) {
        foreach (ViewCommand::parse($command, ViewCommand::ORIGIN_SEARCH, $sword) as $svc) {
            $this->float["view"][] = $svc;
        }
        return $this;
    }

    /** @return list<ViewCommand> */
    final function view_commands() {
        return $this->float["view"] ?? [];
    }

    /** @param string $field
     * @return ?ViewCommand */
    final function find_view_command($field) {
        foreach ($this->view_commands() as $svc) {
            if ($svc->keyword === $field)
                return $svc;
        }
        return null;
    }

    /** @return array<string,mixed> */
    final function float_map() {
        return $this->float;
    }

    /** @param string $k */
    final function set_float($k, $v) {
        $this->float[$k] = $v;
    }

    /** @param string $k */
    final function get_float($k) {
        return $this->float[$k] ?? null;
    }

    /** @param string $k */
    final function unset_float($k) {
        unset($this->float[$k]);
    }

    /** @param ?SearchStringContext $context
     * @return ?array{int,int} */
    final function strspan_in($context) {
        if ($this->pos1 === null) {
            return null;
        }
        $pos1 = $this->pos1;
        $pos2 = $this->pos2;
        $tcontext = $this->string_context;
        while ($tcontext && $tcontext !== $context) {
            $pos1 = $tcontext->ppos1;
            $pos2 = $tcontext->ppos2;
            $tcontext = $tcontext->parent;
        }
        return $tcontext === $context ? [$pos1, $pos2] : null;
    }

    /** @param int $pos1
     * @param int $pos2
     * @param ?SearchStringContext $context */
    final function apply_strspan($pos1, $pos2, $context) {
        if ($this->pos1 === null) {
            $this->string_context = $context;
        }
        if ($this->string_context === $context) {
            if ($this->pos1 === null || $this->pos1 > $pos1) {
                $this->pos1 = $pos1;
            }
            if ($this->pos2 === null || $this->pos2 < $pos2) {
                $this->pos2 = $pos2;
            }
        }
    }

    /** @param SearchTerm $term
     * @param ?SearchTerm $clone_of
     * @return $this */
    protected function assign_context($term, $clone_of = null) {
        $this->pos1 = $term->pos1;
        $this->pos2 = $term->pos2;
        $this->string_context = $term->string_context;
        $this->float = $term->float;
        if ($clone_of !== null && ($this->float["ge"] ?? null) === $clone_of) {
            $this->float["ge"] = $this;
        }
        return $this;
    }

    /** @param string $q
     * @return string */
    final function source_subquery($q) {
        $q = $this->string_context ? $this->string_context->q : $q;
        return $this->pos1 !== null ? substr($q, $this->pos1, $this->pos2 - $this->pos1) : $q;
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

    /** @param array<string,mixed> &$options */
    function paper_requirements(&$options) {
    }

    /** @param array<int,true> &$oids */
    function paper_options(&$oids) {
    }


    /** @return string */
    function sqlexpr(SearchQueryInfo $sqi) {
        return "true";
    }

    /** @return string */
    function precise_sqlexpr(SearchQueryInfo $sqi) {
        // must *always* call `sqlexpr` so we add columns to $sqi
        $sql = $this->sqlexpr($sqi);
        return $this->is_sqlexpr_precise() ? $sql : "true";
    }

    /** @param ?bool $b
     * @return null|False_SearchTerm|True_SearchTerm */
    static function make_constant($b) {
        if ($b === true) {
            return new True_SearchTerm;
        } else if ($b === false) {
            return new False_SearchTerm;
        }
        return null;
    }

    /** @param list<string> $ff
     * @return string */
    static function andjoin_sqlexpr($ff) {
        if (empty($ff) || in_array("false", $ff, true)) {
            return "false";
        }
        $ff = array_filter($ff, function ($f) { return $f !== "true"; });
        if (empty($ff)) {
            return "true";
        } else if (count($ff) === 1) {
            return join("", $ff);
        }
        return "(" . join(" and ", $ff) . ")";
    }

    /** @param list<string> $ff
     * @param 'false'|'true' $default
     * @return string */
    static function orjoin_sqlexpr($ff, $default) {
        if (empty($ff)) {
            return $default;
        } else if (in_array("true", $ff, true)) {
            return "true";
        }
        $ff = array_filter($ff, function ($f) { return $f !== "false"; });
        if (empty($ff)) {
            return "false";
        } else if (count($ff) === 1) {
            return join("", $ff);
        }
        return "(" . join(" or ", $ff) . ")";
    }

    /** @return bool */
    function is_sqlexpr_precise() {
        return false;
    }


    /** @return bool */
    function need_pretest() {
        return false;
    }

    /** @param null|ReviewInfo|CommentInfo $xinfo
     * @return ?bool */
    function pretest(PaperInfo $row, $xinfo) {
        return $this->test($row, $xinfo);
    }

    /** @param null|ReviewInfo|CommentInfo $xinfo
     * @return bool */
    abstract function test(PaperInfo $row, $xinfo);

    /** @return list<string> */
    function highlight_list(PaperInfo $row) {
        return [];
    }

    /** @param list<SearchTerm> $terms
     * @param PaperInfo $row
     * @return list<string> */
    static protected function merge_highlight_lists($terms, $row) {
        $hl = [];
        foreach ($terms as $qe) {
            if (isset($qe->float["hl"])
                && ($qehl = $qe->highlight_list($row))) {
                if (empty($hl)) {
                    $hl = $qehl;
                } else {
                    foreach ($qehl as $h) {
                        if (!in_array($h, $hl, true)) {
                            $hl[] = $h;
                        }
                    }
                }
            }
        }
        return $hl;
    }


    /** @param callable(SearchTerm,...):mixed $visitor
     * @return mixed */
    function visit($visitor) {
        return $visitor($this);
    }

    /** @return Generator<SearchTerm> */
    function preorder() {
        yield $this;
    }


    /** @param int $group
     * @return SearchTerm */
    function group_slice_term($group) {
        return $this;
    }

    /** @param bool $top
     * @param PaperList $pl
     * @return ?PaperColumn */
    function default_sort_column($top, $pl) {
        return null;
    }

    // What class of information does this search concern? (bitmask)
    const ABOUT_SUB = 0x1;               // Submission information
    const ABOUT_TAGS = 0x2;              // About tags
    const ABOUT_DECISION = 0x4;          // About decision
    const ABOUT_PAPER = 0x7;             // Submission information or tags
    const ABOUT_REVIEW = 0x8;            // About a single review
    const ABOUT_REVIEW_SET = 0x10;       // About reviews as a class
    const ABOUT_REVIEWS = 0x18;          // Either ABOUT_REVIEW or ABOUT_REVIEW_SET
    const ABOUT_COMMENTS = 0x20;         // About comments
    const ABOUT_REACTIONS = 0x40;        // About reactions (e.g. review ratings)
    const ABOUT_PREFS = 0x80;            // About review preferences
    const ABOUT_OTHER = 0x8000;          // About something else (prefs, comments)
    const ABOUT_ANY = 0xFFFF;            // Who knows what it's about
    const ABOUT_NO_SHORT_CIRCUIT = 0x10000;  // script_expression only

    /** @return int */
    function about() {
        return self::ABOUT_SUB;
    }


    /** @param int $about
     * @return null|bool|array{type:string} */
    function script_expression(PaperInfo $row, $about) {
        return $this->test($row, null);
    }

    /** @return ?list<array{action:string}> */
    function drag_assigners(Contact $user) {
        return null;
    }
}

class False_SearchTerm extends SearchTerm {
    function __construct() {
        parent::__construct("false");
    }
    function sqlexpr(SearchQueryInfo $sqi) {
        return "false";
    }
    function is_sqlexpr_precise() {
        return true;
    }
    function test(PaperInfo $row, $xinfo) {
        return false;
    }
    function about() {
        return 0;
    }
    function script_expression(PaperInfo $row, $about) {
        return false;
    }
    function drag_assigners(Contact $user) {
        return null;
    }
}

class True_SearchTerm extends SearchTerm {
    function __construct() {
        parent::__construct("true");
    }
    function simple_search(&$options) {
        return true;
    }
    function is_sqlexpr_precise() {
        return true;
    }
    function test(PaperInfo $row, $xinfo) {
        return true;
    }
    function about() {
        return 0;
    }
    function script_expression(PaperInfo $row, $about) {
        return true;
    }
    function drag_assigners(Contact $user) {
        return [];
    }
}

abstract class Op_SearchTerm extends SearchTerm {
    /** @var list<SearchTerm> */
    public $child = [];
    /** @var int */
    public $height = 1;

    const SQLEXPR_HEIGHT = 100;

    function __construct($type) {
        parent::__construct($type);
    }
    /** @param SearchTerm $term
     * @param ?SearchStringContext $context
     * @return $this */
    function op_append($term, $context) {
        if (!$term) {
            return $this;
        }
        $this->op_append_floats($term, $context);
        $this->child[] = $term;
        return $this;
    }
    /** @param SearchTerm $term
     * @param ?SearchStringContext $context */
    function op_append_floats($term, $context) {
        if (($span = $term->strspan_in($context))) {
            $this->apply_strspan($span[0], $span[1], $context);
        }
        if ($term instanceof Op_SearchTerm) {
            $this->height = max($this->height, $term->height + 1);
        }
        foreach ($term->float as $k => $v) {
            if ($k === "view") {
                if ($this->type === "then") {
                    $v = ViewCommand::strip_sorts($v);
                }
                $this->float[$k] = array_merge($this->float[$k] ?? [], $v);
            } else if ($k === "tags") {
                if ($this->type !== "not") {
                    $this->float["tags"] = array_merge($this->float["tags"] ?? [], $v);
                }
            } else if ($k === "hl") {
                if ($this->type !== "not") {
                    $this->float["hl"] = $v;
                }
            } else if ($k === "ge") {
                if (($this->type === "and" || $this->type === "space" || $this->type === "then")
                    && !isset($this->float["ge"])) {
                    $this->float["ge"] = $v;
                }
            } else if (str_starts_with($k, "fhl:")) {
                '@phan-var-force TextPregexes $v';
                if ($this->type !== "not" && !$v->is_empty()) {
                    if (!isset($this->float[$k])) {
                        $this->float[$k] = $v;
                    } else {
                        $this->float[$k] = $v2 = clone $this->float[$k];
                        $v2->merge_any($v);
                    }
                }
            } else if ($k === "xlimit") {
                if (($this->type === "and" || $this->type === "space")
                    && !isset($this->float[$k])) {
                    $this->float[$k] = $v;
                }
            } else {
                $this->float[$k] = $v;
            }
        }
    }
    /** @param SearchTerm $term
     * @param list<SearchTerm> $stack
     * @param int $stackpos1
     * @param int $stackpos2
     * @return bool */
    function op_try_adopt($term, $stack, $stackpos1, $stackpos2) {
        return false;
    }
    /** @return SearchTerm */
    abstract protected function op_finish();
    /** @param ?bool $any */
    protected function op_finish_combine($any) {
        if (empty($this->child)) {
            $qe = $any ? new True_SearchTerm : new False_SearchTerm;
            return $qe->assign_context($this);
        } else if (count($this->child) > 1) {
            return $this;
        }
        return (clone $this->child[0])->assign_context($this, $this->child[0]);
    }

    function debug_json() {
        $a = [];
        foreach ($this->child as $qv) {
            $a[] = $qv->debug_json();
        }
        return ["type" => $this->type, "child" => $a];
    }
    function paper_requirements(&$options) {
        foreach ($this->child as $ch) {
            $ch->paper_requirements($options);
        }
    }
    function paper_options(&$oids) {
        foreach ($this->child as $ch) {
            $ch->paper_options($oids);
        }
    }
    function is_sqlexpr_precise() {
        if ($this->height > self::SQLEXPR_HEIGHT) {
            return false;
        }
        foreach ($this->child as $ch) {
            if (!$ch->is_sqlexpr_precise())
                return false;
        }
        return true;
    }
    function need_pretest() {
        foreach ($this->child as $ch) {
            if ($ch->need_pretest())
                return true;
        }
        return false;
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
    function about() {
        $x = 0;
        foreach ($this->child as $qv) {
            $x |= $qv->about();
        }
        return $x;
    }

    /** @param 'and'|'or'|'not'|'xor' $op
     * @param list<null|bool|array{type:string}> $sexprs
     * @param int $about
     * @return null|bool|array{type:string} */
    static function combine_script_expressions($op, $sexprs, $about = 0) {
        $ok = true;
        $bresult = $op === "and";
        $xresult = null;
        $any = false;
        $ch = [];
        foreach ($sexprs as $sexpr) {
            if ($sexpr === null) {
                $ok = false;
            } else if (is_bool($sexpr)) {
                $any = true;
                if ($sexpr ? $op === "or" : $op === "and") {
                    $xresult = $sexpr;
                } else if ($sexpr ? $op === "xor" : $op === "not") {
                    $bresult = !$bresult;
                }
            } else {
                $ch[] = $sexpr;
            }
        }
        if (!$ok && ($xresult === null || ($about & self::ABOUT_NO_SHORT_CIRCUIT) !== 0)) {
            return null;
        } else if ($xresult !== null) {
            return $xresult;
        } else if (empty($ch)) {
            return $any && $bresult;
        } else if ($op === "not" || ($bresult && $op === "xor" && count($ch) === 1)) {
            return ["type" => "not", "child" => $ch];
        }
        if ($bresult && $op === "xor") {
            $ch[] = true;
        }
        if (count($ch) === 1) {
            return $ch[0];
        }
        return ["type" => $op, "child" => $ch];
    }
    function script_expression(PaperInfo $row, $about) {
        $sexprs = [];
        foreach ($this->child as $ch) {
            $sexprs[] = $ch->script_expression($row, $about);
        }
        $type = $this->type === "space" ? "and" : $this->type;
        return self::combine_script_expressions($type, $sexprs);
    }
}

class Not_SearchTerm extends Op_SearchTerm {
    function __construct() {
        parent::__construct("not");
    }
    function op_finish() {
        $qv = $this->child ? $this->child[0] : null;
        $qr = null;
        if (!$qv || $qv instanceof False_SearchTerm) {
            $qr = new True_SearchTerm;
        } else if ($qv instanceof True_SearchTerm) {
            $qr = new False_SearchTerm;
        } else if ($qv instanceof Not_SearchTerm) {
            $qr = clone $qv->child[0];
        }
        return $qr ? $qr->assign_context($this) : $this;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $ctx = $sqi->set_context(SearchQueryInfo::CTX_ANY);
        $ff = $this->child[0]->sqlexpr($sqi);
        $sqi->set_context($ctx);
        if ($this->height > self::SQLEXPR_HEIGHT
            || !$this->child[0]->is_sqlexpr_precise()
            || $ff === "false") {
            return "true";
        } else if ($ff === "true") {
            return "false";
        }
        return "not coalesce({$ff},0)";
    }
    // parent::precise_sqlexpr is correct
    function pretest(PaperInfo $row, $xinfo) {
        $x = $this->child[0]->pretest($row, $xinfo);
        return $x === null ? null : !$x;
    }
    function test(PaperInfo $row, $xinfo) {
        return !$this->child[0]->test($row, $xinfo);
    }
    function about() {
        return $this->child[0]->about();
    }
}

class And_SearchTerm extends Op_SearchTerm {
    /** @var ?PaperID_SearchTerm */
    private $pn;
    /** @var ?bool */
    private $short_circuit;

    /** @param string $type */
    function __construct($type) {
        parent::__construct($type);
    }
    function op_append($term, $context) {
        if (!$term) {
            return $this;
        }
        $this->op_append_floats($term, $context);
        foreach ($term->type === $this->type ? $term->child : [$term] as $qv) {
            if ($qv instanceof False_SearchTerm) {
                $this->short_circuit = false;
            } else if ($qv instanceof True_SearchTerm) {
                $this->short_circuit = $this->short_circuit ?? true;
            } else if ($qv->type === "pn" && $this->type === "space") {
                if (!$this->pn) {
                    $this->child[] = $this->pn = $qv;
                } else {
                    $this->pn->merge($qv);
                }
            } else {
                $this->child[] = $qv;
            }
        }
        return $this;
    }
    function op_try_adopt($term, $stack, $stackpos1, $stackpos2) {
        return $term->type === $this->type;
    }
    function op_finish() {
        if ($this->short_circuit === false) {
            return (new False_SearchTerm)->assign_context($this);
        }
        return $this->op_finish_combine($this->short_circuit);
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $ff = [];
        foreach ($this->child as $subt) {
            $ff[] = $subt->sqlexpr($sqi);
        }
        if ($this->height > self::SQLEXPR_HEIGHT) {
            return "true";
        }
        return self::andjoin_sqlexpr($ff);
    }
    function precise_sqlexpr(SearchQueryInfo $sqi) {
        $ff = [];
        foreach ($this->child as $subt) {
            $ff[] = $subt->precise_sqlexpr($sqi);
        }
        if ($this->height > self::SQLEXPR_HEIGHT) {
            return "true";
        }
        return self::andjoin_sqlexpr($ff);
    }
    function pretest(PaperInfo $row, $xinfo) {
        $a = true;
        foreach ($this->child as $subt) {
            $x = $subt->pretest($row, $xinfo);
            if ($x === false) {
                return false;
            } else if ($x === null) {
                $a = null;
            }
        }
        return $a;
    }
    function test(PaperInfo $row, $xinfo) {
        foreach ($this->child as $subt) {
            if (!$subt->test($row, $xinfo))
                return false;
        }
        return true;
    }
    function highlight_list(PaperInfo $row) {
        $hl = [];
        foreach ($this->child as $ch) {
            if ($ch->test($row, null)) {
                $hl[] = $ch;
            } else {
                return [];
            }
        }
        return parent::merge_highlight_lists($hl, $row);
    }
    function group_slice_term($group) {
        if (!isset($this->float["ge"])) {
            return $this;
        }
        $newchild = [];
        $ft = false;
        foreach ($this->child as $ch) {
            if (isset($ch->float["ge"]) && !$ft) {
                $newchild[] = $ch->group_slice_term($group);
                $ft = true;
            } else {
                $newchild[] = $ch;
            }
        }
        return SearchTerm::combine_in($this->type, $this->string_context, ...$newchild);
    }
    function default_sort_column($top, $pl) {
        $s = null;
        foreach ($this->child as $qv) {
            $s1 = $qv->default_sort_column($top, $pl);
            if ($s && $s1) {
                return null;
            }
            $s = $s ?? $s1;
        }
        return $s;
    }
    function drag_assigners(Contact $user) {
        $ch = [];
        foreach ($this->child as $subt) {
            $x = $subt->drag_assigners($user);
            if ($x === null) {
                return null;
            }
            $ch = array_merge($ch, $x);
        }
        return $ch;
    }
}

class Or_SearchTerm extends Op_SearchTerm {
    /** @var ?PaperID_SearchTerm */
    private $pn;
    /** @var bool */
    private $short_circuit = false;

    function __construct() {
        parent::__construct("or");
    }
    function op_append($term, $context) {
        if (!$term) {
            return $this;
        }
        $this->op_append_floats($term, $context);
        foreach ($term->type === $this->type ? $term->child : [$term] as $qv) {
            if ($qv instanceof True_SearchTerm) {
                $this->short_circuit = true;
            } else if ($qv instanceof False_SearchTerm) {
                // skip
            } else if ($qv->type === "pn" && $this->type === "space") {
                if (!$this->pn) {
                    $this->child[] = $this->pn = $qv;
                } else {
                    $this->pn->merge($qv);
                }
            } else if (empty($this->child)
                       || !$this->child[count($this->child) - 1]->merge($qv)) {
                $this->child[] = $qv;
            }
        }
        return $this;
    }
    function op_try_adopt($term, $stack, $stackpos1, $stackpos2) {
        return $term->type === $this->type;
    }
    function op_finish() {
        if ($this->short_circuit) {
            return (new True_SearchTerm)->assign_context($this);
        }
        return $this->op_finish_combine(false);
    }

    /** @param list<SearchTerm> $child
     * @param 1|2 $context
     * @return list<string> */
    static function or_sqlexprs($child, SearchQueryInfo $sqi,
                                $context = SearchQueryInfo::CTX_OPTIONAL) {
        $ctx = $sqi->set_context($context);
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
        $sqi->set_context($ctx);
        return $ff;
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $sqlexprs = self::or_sqlexprs($this->child, $sqi);
        if ($this->height > self::SQLEXPR_HEIGHT) {
            return "true";
        }
        return self::orjoin_sqlexpr($sqlexprs, "false");
    }
    // parent::precise_sqlexpr is correct
    function pretest(PaperInfo $row, $xinfo) {
        $a = false;
        foreach ($this->child as $subt) {
            $x = $subt->pretest($row, $xinfo);
            if ($x === true) {
                return true;
            } else if ($x === null) {
                $a = null;
            }
        }
        return $a;
    }
    function test(PaperInfo $row, $xinfo) {
        foreach ($this->child as $subt) {
            if ($subt->test($row, $xinfo))
                return true;
        }
        return false;
    }
    function highlight_list(PaperInfo $row) {
        $hl = [];
        foreach ($this->child as $ch) {
            if ((empty($hl) || $ch->get_float("hl"))
                && $ch->test($row, null))
                $hl[] = $ch;
        }
        return empty($hl) ? [] : parent::merge_highlight_lists($hl, $row);
    }
}

class Xor_SearchTerm extends Op_SearchTerm {
    /** @var bool */
    private $negate;

    function __construct() {
        parent::__construct("xor");
    }
    function op_append($term, $context) {
        if (!$term) {
            return $this;
        }
        $this->op_append_floats($term, $context);
        foreach ($term->type === $this->type ? $term->child : [$term] as $qv) {
            if ($qv instanceof False_SearchTerm) {
                // skip
            } else if ($qv instanceof True_SearchTerm) {
                $this->negate = !$this->negate;
            } else {
                $this->child[] = $qv;
            }
        }
        return $this;
    }
    function op_try_adopt($term, $stack, $stackpos1, $stackpos2) {
        return $term->type === $this->type;
    }
    function op_finish() {
        return $this->op_finish_combine(false)->negate_if($this->negate);
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $precise = $this->is_sqlexpr_precise();
        $ctx = $precise ? SearchQueryInfo::CTX_OPTIONAL : SearchQueryInfo::CTX_ANY;
        $ff = Or_SearchTerm::or_sqlexprs($this->child, $sqi, $ctx);
        $sqi->set_context($ctx);
        if ($this->height > self::SQLEXPR_HEIGHT) {
            return "true";
        } else if (empty($ff)) {
            return "false";
        } else if ($precise) {
            return "(coalesce(" . join(",0) xor coalesce(", $ff) . ",0))";
        }
        return self::orjoin_sqlexpr($ff, "false");
    }
    // parent::precise_sqlexpr is correct
    function pretest(PaperInfo $row, $xinfo) {
        $a = false;
        foreach ($this->child as $subt) {
            $x = $subt->pretest($row, $xinfo);
            if ($x === true) {
                $a = $a === false ? true : null;
            } else if ($x === null) {
                $a = null;
            }
        }
        return $a;
    }
    function test(PaperInfo $row, $xinfo) {
        $x = false;
        foreach ($this->child as $subt) {
            if ($subt->test($row, $xinfo))
                $x = !$x;
        }
        return $x;
    }
    function highlight_list(PaperInfo $row) {
        $hl = [];
        foreach ($this->child as $ch) {
            if ($ch->test($row, null))
                $hl[] = $ch;
        }
        return count($hl) % 2 ? parent::merge_highlight_lists($hl, $row) : [];
    }
}

class Then_SearchTerm extends Op_SearchTerm {
    /** @var bool */
    private $is_highlight;
    /** @var string */
    private $color;
    /** @var int */
    private $nthen = 0;
    /** @var list<string> */
    private $_colors = [];
    /** @var list<?Then_SearchTerm> */
    private $_nested_thens = [];
    /** @var list<int> */
    private $_group_offsets = [];
    /** @var ?int */
    private $_last_group;

    function __construct(SearchOperator $op) {
        assert($op->type === "then" || $op->type === "highlight");
        parent::__construct("then");
        $this->is_highlight = $op->type === "highlight";
        $this->color = $this->is_highlight ? strtolower($op->subtype ?? "") : "";
    }
    function op_append($term, $context) {
        // Structure (which children are groups, which are highlights, and
        // their colors) is decided by op_try_adopt at compile time; here a
        // term is just one more child.
        if (!$term) {
            return $this;
        }
        if ($this->is_highlight
            && $this->nthen !== 0
            && count($this->child) >= $this->nthen
            && $term instanceof Then_SearchTerm) {
            // A highlight search is tested for matches only; its own
            // highlights are never consulted, so keep just its groups.
            $term = $term->truncate_highlight();
        }
        $this->op_append_floats($term, $context);
        $this->child[] = $term;
        if (!$this->is_highlight || $this->nthen === 0) {
            $this->nthen = count($this->child);
        }
        return $this;
    }
    /** @return SearchTerm */
    private function truncate_highlight() {
        if ($this->nthen === count($this->child)) {
            return $this;
        } else if ($this->nthen === 1) {
            return $this->child[0];
        }
        $this->child = array_slice($this->child, 0, $this->nthen);
        $this->_colors = [];
        $this->unset_float("hl");
        return $this;
    }
    function op_try_adopt($term, $stack, $stackpos1, $stackpos2) {
        if (!($term instanceof Then_SearchTerm)) {
            return false;
        }
        if (!$this->is_highlight) {
            // a THEN's compiled children are all groups; splice them in
            return !$term->is_highlight;
        }
        // a HIGHLIGHT adopts only its first child, inheriting that child's
        // groups and colors
        $n = count($stack) - $stackpos2;
        if ($stackpos1 !== $stackpos2 || $n === 0) {
            return false;
        }
        $this->nthen = $term->is_highlight ? max($term->nthen, 1) : $n;
        // take the child's colors rather than copying them (the child is
        // discarded), then color its remaining highlight terms
        $this->_colors = $term->_colors;
        while ($this->nthen + count($this->_colors) < $n) {
            $this->_colors[] = $term->color;
        }
        $term->_colors = [];
        return true;
    }
    function op_finish() {
        $this->_group_offsets[] = $go = 0;
        for ($i = 0; $i !== $this->nthen; ++$i) {
            $ge = $this->child[$i]->get_float("ge");
            '@phan-var-force ?Then_SearchTerm $ge';
            $this->_nested_thens[] = $ge;
            $go += $ge ? $ge->_group_offsets[$ge->nthen] : 1;
            $this->_group_offsets[] = $go;
        }
        if ($this->nthen > 1) {
            $this->set_float("ge", $this);
        } else if (($ge = $this->_nested_thens[0] ?? null)) {
            // group expression comes from the group child only, never
            // from a highlight child
            $this->set_float("ge", $ge);
        } else {
            $this->unset_float("ge");
        }
        if ($this->nthen < count($this->child)) {
            $this->set_float("hl", true);
        }
        while ($this->nthen + count($this->_colors) < count($this->child)) {
            $this->_colors[] = $this->color;
        }
        return $this;
    }

    function visit($visitor) {
        // Only visit non-highlight terms
        $x = [];
        for ($i = 0; $i !== $this->nthen; ++$i) {
            $x[] = $this->child[$i]->visit($visitor);
        }
        return $visitor($this, ...$x);
    }

    function paper_options(&$oids) {
        for ($i = 0; $i !== $this->nthen; ++$i) {
            $this->child[$i]->paper_options($oids);
        }
    }

    /** @return int */
    function ngroups() {
        return $this->_group_offsets[$this->nthen];
    }

    /** @return list<SearchTerm> */
    function group_terms() {
        $gt = [];
        foreach ($this->_nested_thens as $i => $thench) {
            if ($thench) {
                array_push($gt, ...$thench->group_terms());
            } else {
                $gt[] = $this->child[$i];
            }
        }
        return $gt;
    }

    /** @param $offset int
     * @return \Generator<array{SearchTerm,list<int>}> */
    function subset_terms($offset = 0) {
        foreach ($this->_nested_thens as $i => $thench) {
            if ($thench) {
                yield from $thench->subset_terms($offset + $this->_group_offsets[$i]);
            }
            yield [$this->child[$i], range($offset + $this->_group_offsets[$i], $offset + $this->_group_offsets[$i + 1] - 1)];
        }
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $ctx = $sqi->set_context(SearchQueryInfo::CTX_OPTIONAL);
        $ff = [];
        foreach ($this->child as $subt) {
            $ff[] = $subt->sqlexpr($sqi);
        }
        $sqi->set_context($ctx);
        if ($this->height > self::SQLEXPR_HEIGHT) {
            return "true";
        }
        return self::orjoin_sqlexpr(array_slice($ff, 0, $this->nthen), "true");
    }
    // parent::precise_sqlexpr is correct
    function pretest(PaperInfo $row, $xinfo) {
        $a = false;
        for ($i = 0; $i !== $this->nthen; ++$i) {
            $x = $this->child[$i]->pretest($row, $xinfo);
            if ($x === true) {
                return true;
            } else if ($x === null) {
                $a = null;
            }
        }
        return $a;
    }
    function test(PaperInfo $row, $xinfo) {
        for ($i = 0; $i !== $this->nthen; ++$i) {
            if ($this->child[$i]->test($row, $xinfo)) {
                $this->_last_group = $i;
                return true;
            }
        }
        return false;
    }
    function highlight_list(PaperInfo $row) {
        $match = null;
        for ($i = 0; $i !== $this->nthen; ++$i) {
            if ($this->child[$i]->test($row, null)) {
                $match = $this->child[$i];
                break;
            }
        }
        if (!$match) {
            return [];
        }
        $hl = isset($match->float["hl"]) ? $match->highlight_list($row) : [];
        for ($i = $this->nthen; $i !== count($this->child); ++$i) {
            if (!in_array($this->_colors[$i - $this->nthen], $hl, true)
                && $this->child[$i]->test($row, null)) {
                $hl[] = $this->_colors[$i - $this->nthen];
            }
        }
        return $hl;
    }
    function group_slice_term($group) {
        if (!isset($this->float["ge"])) {
            return $this;
        }
        $g = 0;
        while ($g !== $this->nthen && $group >= $this->_group_offsets[$g + 1]) {
            ++$g;
        }
        if ($g >= $this->nthen) {
            return new False_SearchTerm;
        }
        // XXX This loses information about HIGHLIGHTs, which is probably OK for now
        return $this->child[$g]->group_slice_term($group - $this->_group_offsets[$g]);
    }
    /** @param int $group
     * @return ?SearchTerm */
    function group_head_term($group) {
        $g = 0;
        while ($g !== $this->nthen && $group >= $this->_group_offsets[$g + 1]) {
            ++$g;
        }
        if ($g >= $this->nthen) {
            return null;
        } else if (($thench = $this->_nested_thens[$g])) {
            return $thench->group_head_term($group - $this->_group_offsets[$g]);
        }
        return $this->child[$g];
    }
    function script_expression(PaperInfo $row, $about) {
        $sexprs = [];
        for ($i = 0; $i !== $this->nthen; ++$i) {
            $sexprs[] = $this->child[$i]->script_expression($row, $about);
        }
        return self::combine_script_expressions("or", $sexprs);
    }

    /** @return int */
    function _last_group() {
        $g = $this->_last_group;
        $thench = $this->_nested_thens[$g];
        return $this->_group_offsets[$g] + ($thench ? $thench->_last_group() : 0);
    }

    function debug_json() {
        $a = [];
        foreach ($this->child as $qv) {
            $a[] = $qv->debug_json();
        }
        $j = ["type" => $this->type, "child" => array_slice($a, 0, $this->nthen)];
        for ($i = $this->nthen; $i !== count($this->child); ++$i) {
            $j["highlights"][] = ["search" => $a[$i], "color" => $this->_colors[$i - $this->nthen]];
        }
        return $j;
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
    public $lflag = 0;
    /** @var string */
    private $limit_class;
    /** @var ?list<int> */
    private $xlist;
    /** @var Contact */
    private $user;
    /** Only the `reviewable` limit selects papers relative to this reviewer;
     * every other limit selects relative to `$user`.
     * @var Contact */
    private $reviewer;

    /* NB all named_limits must equal themselves when urlencoded */
    /** @var array<string,string|array{string,string}> */
    static public $reqtype_map = [
        "a" => ["a", "author"],
        "acc" => "accepted",
        "accepted" => "accepted",
        "act" => "active",
        "active" => "active",
        "actadmin" => ["actadmin", "activeadmin"],
        "activeadmin" => ["actadmin", "activeadmin"],
        "admin" => "admin",
        "administrator" => "admin",
        "all" => "all",
        "alladmin" => "alladmin",
        "ar" => "ar",
        "author" => ["a", "author"],
        "dec:none" => "undecided",
        "dec:yes" => "accepted",
        "editpref" => "reviewable",
        "lead" => "lead",
        "manager" => "admin",
        "none" => "none",
        "outstandingreviews" => ["rout", "outstandingreviews"],
        "r" => ["r", "reviews"],
        "rable" => "reviewable",
        "req" => ["req", "requests"],
        "reqrevs" => ["req", "requests"],
        "request" => ["req", "requests"],
        "requests" => ["req", "requests"],
        "reviewable" => "reviewable",
        "review" => ["r", "reviews"],
        "reviews" => ["r", "reviews"],
        "rout" => ["rout", "outstandingreviews"],
        "s" => ["s", "submitted"],
        "sa" => ["sa", "sall"],
        "sall" => ["sa", "sall"],
        "submitted" => ["s", "submitted"],
        "und" => "undecided",
        "undec" => "undecided",
        "undecided" => "undecided",
        "unsub" => ["unsub", "unsubmitted"],
        "unsubmitted" => ["unsub", "unsubmitted"],
        "viewable" => "viewable",
        "vis" => "viewable",
        "visible" => "viewable"
    ];

    const LFLAG_ACTIVE = 1;
    const LFLAG_SUBMITTED = 2;
    const LFLAG_ACCEPTED = 4;
    const LFLAG_STDDEC = 8;
    const LFLAG_AUTHOR = 16;
    const LFLAG_REVIEWER = 32;
    const LFLAGM_TYPE = 0x3F;
    const LFLAG_IMPLICIT = 64;
    const LFLAG_BASE = 128;

    /** @param string|SearchWord $limit */
    function __construct(PaperSearch $srch, $limit, $implicit = false) {
        parent::__construct("in");
        $this->user = $srch->user;
        $this->reviewer = $srch->reviewer_user();
        $this->set_limit($limit, $srch);
        $this->set_float("xlimit", $this);
    }

    static function parse($word, SearchWord $sword, PaperSearch $srch) {
        return new Limit_SearchTerm($srch, $sword);
    }

    /** @return ?array{string,string} */
    static function canonical_names(Conf $conf, $limit) {
        if ($limit === null) {
            return null;
        } else if (($rt = self::$reqtype_map[$limit] ?? null) !== null) {
            return is_string($rt) ? [$rt, $rt] : $rt;
        } else if (str_starts_with($limit, "dec:")
                   && count($conf->decision_set()->match(SearchWord::unquote(substr($limit, 4)))) > 0) {
            return [$limit, $limit];
        }
        return null;
    }

    /** @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_implicit() {
        $this->lflag |= self::LFLAG_IMPLICIT;
        $this->unset_float("xlimit");
        return $this;
    }

    /** @return $this
     * @suppress PhanAccessReadOnlyProperty */
    function set_base() {
        $this->lflag |= self::LFLAG_BASE;
        return $this;
    }

    /** @param string|SearchWord $limit
     * @param ?PaperSearch $srch
     * @suppress PhanAccessReadOnlyProperty */
    function set_limit($limit, $srch = null) {
        $conf = $this->user->conf;
        if (is_string($limit)) {
            $limstr = $limit;
            $limword = null;
        } else {
            $limstr = $limit->word;
            $limword = $limit;
        }

        // default limit should be the plausible limit for a default search,
        // as in entering text into a quicksearch box
        if ($limstr === "default") {
            if ($this->user->privChair
                && ($this->user->is_root_user()
                    || $conf->unnamed_submission_round()->time_submit(true))) {
                $limstr = "all";
            } else if ($this->user->isPC) {
                if ($this->user->can_view_some_incomplete()
                    && $conf->can_pc_view_some_incomplete()) {
                    $limstr = "active";
                } else {
                    $limstr = "s";
                }
            } else if (!$this->user->is_reviewer()) {
                $limstr = "a";
            } else if (!$this->user->is_author()) {
                $limstr = "r";
            } else {
                $limstr = "ar";
            }
        }

        // find limit
        $limitpair = self::canonical_names($conf, $limstr);
        if (!$limitpair) {
            if ($srch && $limword) {
                if (str_starts_with($limstr, "dec:")) {
                    $xword = clone $limword;
                    $xword->pos1 += 4;
                    $srch->lwarning($xword, "<0>Decision not found");
                } else {
                    $srch->lwarning($limword, $conf->_("<0>{Submission} collection not found"));
                }
            }
            $limitpair = ["none", "none"];
        }
        $this->named_limit = $limstr = $limitpair[0];

        // optimize SQL for some limits
        if ($limstr === "viewable" && $this->user->can_view_all()) {
            $limstr = "all";
        }
        $this->limit = $this->limit_class = $limstr;

        // mark flags
        $this->lflag &= ~self::LFLAGM_TYPE;
        if (in_array($limstr, ["a", "ar", "r", "viewable", "all", "none"], true)) {
            // no additional flags
        } else if ($limstr === "reviewable") {
            if ($this->user->contactXid !== $this->reviewer->contactXid) {
                $this->lflag |= self::LFLAG_REVIEWER;
            }
        } else if (str_starts_with($limstr, "dec:")) {
            $this->limit_class = "dec";
            $this->lflag |= self::LFLAG_SUBMITTED | self::LFLAG_ACCEPTED | self::LFLAG_STDDEC;
            $decset = $this->user->conf->decision_set();
            $this->xlist = $decset->matchexpr(SearchWord::unquote(substr($limstr, 4)), true);
            foreach ($this->xlist as $dec) {
                if ($decset->get($dec)->sign === -2) {
                    $this->lflag &= ~(self::LFLAG_ACCEPTED | self::LFLAG_STDDEC);
                } else if ($dec <= 0) {
                    $this->lflag &= ~self::LFLAG_ACCEPTED;
                }
            }
        } else if ($limstr === "accepted") {
            $this->lflag |= self::LFLAG_SUBMITTED | self::LFLAG_ACCEPTED;
        } else if ($limstr === "undecided") {
            $this->lflag |= self::LFLAG_SUBMITTED | self::LFLAG_STDDEC;
        } else if (in_array($limstr, ["active", "unsub", "actadmin"], true)
                   || ($conf->can_pc_view_some_incomplete()
                       && !in_array($limstr, ["s", "accepted"], true))) {
            $this->lflag |= self::LFLAG_ACTIVE | self::LFLAG_STDDEC;
        } else if ($limstr === "sa") {
            $this->lflag |= self::LFLAG_SUBMITTED;
        } else {
            $this->lflag |= self::LFLAG_SUBMITTED | self::LFLAG_STDDEC;
        }
    }

    /** @return bool */
    function is_submitted() {
        return ($this->lflag & self::LFLAG_SUBMITTED) !== 0;
    }

    /** @return bool */
    function is_accepted() {
        return ($this->lflag & self::LFLAG_ACCEPTED) !== 0;
    }

    /** @return bool */
    function is_author() {
        return $this->limit === "a";
    }

    /** @param Limit_SearchTerm $set_limit
     * @return bool */
    function prefer_to($set_limit) {
        return ($this->lflag & self::LFLAG_IMPLICIT) === 0
            || ($this->limit_class === "dec"
                && ($this->lflag & self::LFLAG_STDDEC) === 0
                && ($set_limit->lflag & self::LFLAG_STDDEC) !== 0);
    }

    /** @param array<string,mixed> &$options
     * @return bool */
    function simple_search(&$options) {
        // hidden papers => complex search
        if (($this->user->dangerous_track_mask() & Track::FM_VIEW) !== 0) {
            return false;
        }
        // if tracks, nonchairs get simple search only for "a", "r", sometimes "s"
        $conf = $this->user->conf;
        if (!$this->user->privChair
            && $conf->has_tracks()
            && $this->limit !== "a"
            && $this->limit !== "r"
            && $this->limit !== "s"
            && $this->limit !== "sa") {
            return false;
        }
        // otherwise go by limit
        // NB cannot set values to false!
        $fin = $act = false;
        if (($this->lflag & self::LFLAG_SUBMITTED) !== 0) {
            $options["finalized"] = $fin = true;
        } else if (($this->lflag & self::LFLAG_ACTIVE) !== 0) {
            $options["active"] = $act = true;
        }
        if (($this->lflag & self::LFLAG_STDDEC) !== 0) {
            $options["decision"][] = "standard";
        }
        switch ($this->limit_class) {
        case "all":
        case "viewable":
            return $this->user->privChair;
        case "s":
        case "sa":
            assert($fin);
            return $this->user->isPC;
        case "active":
            assert($act || $fin);
            return $this->user->can_view_all_incomplete();
        case "reviewable":
            // `reviewable` is relative to `$reviewer`
            return false;
        case "a":
            $options["author"] = true;
            // If complex author SQL, always do search the long way
            return !$this->user->is_author_view_sql("%", true);
        case "ar":
            return false;
        case "r":
            $options["myReviews"] = true;
            return true;
        case "rout":
            assert($act || $fin);
            $options["myOutstandingReviews"] = true;
            return true;
        case "accepted":
            assert($fin);
            $options["decision"][] = "yes";
            return $this->user->can_view_all_decision();
        case "undecided":
            assert($fin);
            $options["decision"][] = "none";
            return $this->user->can_view_all_decision();
        case "dec":
            assert($fin);
            $options["decision"][] = $this->xlist;
            return $this->user->can_view_all_decision();
        case "unsub":
            assert($act);
            $options["unsub"] = true;
            return $this->user->allow_admin_all();
        case "lead":
            // Leading a submission does not imply permission to view it: a
            // lead may have left the PC. Since this path skips
            // `Contact::can_view_paper`, use the long way.
            return false;
        case "alladmin":
        case "actadmin":
            return $this->user->allow_admin_all();
        case "admin":
            return false;
        case "req":
            assert($act || $fin);
            $options["myReviewRequests"] = true;
            // A requester views a submission as a PC member, so restrict to
            // submissions PC members can view (see Conf::time_pc_view)
            return $this->user->isPC
                && ($fin || $this->user->can_view_all_incomplete());
        default:
            return false;
        }
    }

    function paper_requirements(&$options) {
        if (in_array($this->limit, ["reviewable", "ar", "r", "rout"], true)) {
            $options["reviewSignatures"] = true;
        }
    }

    function is_sqlexpr_precise() {
        // hidden papers, view limits => imprecise
        if (($this->user->dangerous_track_mask() & Track::FM_VIEW) !== 0) {
            return false;
        }
        switch ($this->limit_class) {
        case "viewable":
        case "alladmin":
        case "actadmin":
            // broad limits are precise only if allowed to administer all
            return $this->user->allow_admin_all();
        case "active":
        case "accepted":
        case "dec":
        case "undecided":
            // decision limits are precise only if user can see all decisions
            return $this->user->can_view_all_decision();
        case "reviewable":
        case "admin":
            // never precise
            return false;
        default:
            return true;
        }
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        assert(!$sqi->required() || $sqi->srch->user === $this->user);

        $ff = [];
        if (($this->lflag & self::LFLAG_SUBMITTED) !== 0) {
            $ff[] = "Paper.timeSubmitted>0";
        } else if (($this->lflag & self::LFLAG_ACTIVE) !== 0) {
            $ff[] = "Paper.timeWithdrawn<=0";
        }
        if (($this->lflag & self::LFLAG_STDDEC) !== 0
            && ($decs = $this->user->conf->decision_set()->desk_reject_ids())) {
            if (count($decs) === 1) {
                $ff[] = "Paper.outcome!=" . $decs[0];
            } else {
                $ff[] = "Paper.outcome not in (" . join(",", $decs) . ")";
            }
        }

        // Author/reviewer restrictions
        $need_ar = 0;
        if ($this->limit === "a") {
            $need_ar = 1;
        } else if ($this->limit === "r" || $this->limit === "rout") {
            $need_ar = 2;
        } else if ($this->limit === "ar") {
            $need_ar = 3;
        } else if (($this->lflag & self::LFLAG_BASE) !== 0
                   && !$this->user->isPC) {
            // Users other than PC members cannot search all papers.
            // Always apply their inherent restrictions to the base limit query.
            $need_ar = 3;
        }

        $arf = [];
        if ($need_ar & 1) {
            $arf[] = $this->user->is_author_view_sql($sqi->conflict_table($this->user));
        }
        if ($need_ar & 2) {
            $sqi->add_reviewer_columns();
            if ($sqi->required()
                && $this->limit !== "rout"
                && !$this->user->reviewer_capability_paper_ids()) {
                $rtable = "MyReviews";
            } else {
                $rtable = "PaperReview";
            }
            $q = $this->user->act_reviewer_sql($rtable, true);
            if ($q !== "false" && $this->limit === "rout") {
                $q .= " and reviewNeedsSubmit!=0";
            }
            if ($q === "false") {
                $arf[] = "false";
            } else if ($rtable === "PaperReview") {
                $arf[] = "exists (select * from PaperReview force index (primary) where paperId=Paper.paperId and {$q})";
            } else {
                $sqi->add_table("MyReviews", [$need_ar === 3 ? "left join" : "join", "PaperReview", $q]);
                $arf[] = ($need_ar === 3 ? "MyReviews.reviewType is not null" : "true");
            }
        }
        if (!empty($arf)) {
            $ff[] = self::orjoin_sqlexpr($arf, "false");
        }

        switch ($this->limit_class) {
        case "all":
        case "viewable":
        case "s":
        case "sa":
        case "active":
            break;
        case "reviewable":
            $sqi->add_reviewer_columns();
            if (!$this->reviewer->isPC
                && (($this->lflag & self::LFLAG_REVIEWER) === 0
                    || $this->user->is_manager())) {
                $reviewable_sql = $this->reviewer->act_reviewer_sql("PaperReview", true);
                $ff[] = "exists (select * from PaperReview force index (primary) where paperId=Paper.paperId and {$reviewable_sql})";
            }
            break;
        case "a":
        case "r":
        case "ar":
        case "rout":
            assert($need_ar !== 0 && !empty($ff));
            break;
        case "accepted":
            if ($this->user->can_view_all_decision()) {
                $ff[] = "Paper.outcome>0";
            }
            break;
        case "undecided":
            if ($this->user->can_view_all_decision()) {
                $ff[] = "Paper.outcome=0";
            }
            break;
        case "dec":
            if ($this->user->can_view_all_decision()) {
                $ff[] = "Paper.outcome" . sql_in_int_list($this->xlist);
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
        case "actadmin":
            if ($this->user->privChair) {
                break;
            }
            $fx = ["Paper.managerContactId={$this->user->contactXid}"];
            if (($mttl = $this->user->managed_track_tags()) === null) {
                // do nothing
            } else if (!empty($mttl)) {
                $tsm = (new TagSearchMatcher($this->user))->add_tag_list($mttl);
                $fx[] = $tsm->exists_sqlexpr("Paper");
            }
            $ff[] = "(" . join(" or ", $fx) . ")";
            break;
        case "admin":
            $fx = ["Paper.managerContactId={$this->user->contactXid}"];
            if (($mttl = $this->user->managed_track_tags()) === null) {
                $fx[] = "Paper.managerContactId=0";
            } else if (!empty($mttl)) {
                $tsm = (new TagSearchMatcher($this->user->conf->root_user()))->add_tag_list($mttl);
                $fx[] = "(Paper.managerContactId=0 and " . $tsm->exists_sqlexpr("Paper") . ")";
            }
            $ff[] = "(" . join(" or ", $fx) . ")";
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

    function test(PaperInfo $row, $xinfo) {
        $user = $this->user;
        if ((($this->lflag & self::LFLAG_SUBMITTED) !== 0
             && $row->timeSubmitted <= 0)
            || (($this->lflag & self::LFLAG_ACTIVE) !== 0
                && $row->timeWithdrawn > 0)
            || (($this->lflag & self::LFLAG_STDDEC) !== 0
                && $row->outcome_sign === -2)
            || (($this->lflag & self::LFLAG_REVIEWER) !== 0
                && !$this->user->allow_admin($row))) {
            return false;
        }
        switch ($this->limit_class) {
        case "all":
        case "viewable":
        case "s":
        case "sa":
        case "active":
            return true;
        case "a":
            return $row->has_author_view($user);
        case "ar":
            return $row->has_author_view($user)
                || $row->has_active_reviewer($user);
        case "r":
            return $row->has_active_reviewer($user);
        case "rout":
            foreach ($row->reviews_by_user($user, $user->review_tokens()) as $rrow) {
                if ($rrow->reviewNeedsSubmit != 0 && !$rrow->is_ghost())
                    return true;
            }
            return false;
        case "reviewable":
            if ($row->has_active_reviewer($this->reviewer)) {
                return true;
            }
            return $this->reviewer->pc_track_assignable($row)
                && !$row->has_conflict($this->reviewer)
                && ($row->timeSubmitted > 0
                    || ($row->timeWithdrawn <= 0
                        && $row->submission_round()->incomplete_viewable))
                && $row->outcome_sign !== -2;
        case "accepted":
            return $row->outcome > 0
                && $user->can_view_decision($row);
        case "undecided":
            return $row->outcome === 0
                || !$user->can_view_decision($row);
        case "dec":
            $outcome = $user->can_view_decision($row) ? $row->outcome : 0;
            return in_array($outcome, $this->xlist, true);
        case "unsub":
            return $row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0;
        case "lead":
            return $row->leadContactId === $user->contactXid;
        case "admin":
            return $user->is_primary_administrator($row);
        case "alladmin":
        case "actadmin":
            return $user->allow_admin($row);
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

    function about() {
        if (in_array($this->limit, ["viewable", "reviewable", "ar", "r", "rout", "req"], true)) {
            return self::ABOUT_REVIEW_SET;
        } else if (in_array($this->limit_class, ["accepted", "undecided", "dec"], true)) {
            return self::ABOUT_SUB | self::ABOUT_DECISION;
        }
        return self::ABOUT_SUB;
    }
}

class TextMatch_SearchTerm extends SearchTerm {
    /** @var Contact */
    private $user;
    /** @var 'title'|'abstract'|'authorInformation'|'collaborators' */
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

    function __construct(Contact $user, $t, $text) {
        parent::__construct($t);
        $this->user = $user;
        $this->field = self::$map[$t];
        $this->authorish = $t === "au" || $t === "co";
        if (is_bool($text)) {
            $this->trivial = $text;
        } else {
            $this->regex = Text::star_text_pregexes($text);
            $this->set_float("fhl:{$t}", $this->regex);
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
        return new TextMatch_SearchTerm($srch->user, $sword->kwdef->name, $word);
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        $sqi->add_column($this->field, "Paper.{$this->field}");
        $sqi->add_column("dataOverflow", "Paper.dataOverflow");
        if ($this->trivial && !$this->authorish) {
            return "(Paper.{$this->field}!='' or Paper.dataOverflow is not null)";
        }
        return "true";
    }
    function is_sqlexpr_precise() {
        return $this->trivial && !$this->authorish;
    }
    function test(PaperInfo $row, $xinfo) {
        // XXX presence conditions
        $data = $row->{$this->field}();
        if ($this->authorish && !$this->user->allow_view_authors($row)) {
            $data = "";
        }
        if ($data === "") {
            return $this->trivial === false;
        } else if ($this->trivial !== null) {
            return $this->trivial;
        }
        // XXX truncate abstract for hard wordlimit
        return $this->regex->match($row->{$this->field}());
    }
    function script_expression(PaperInfo $row, $about) {
        if ($about !== self::ABOUT_PAPER) {
            return parent::script_expression($row, $about);
        } else if (!$this->trivial || $this->field === "authorInformation") {
            return null;
        }
        return ["type" => $this->field, "match" => $this->trivial];
    }
    function debug_json() {
        if ($this->trivial !== null) {
            return ["type" => $this->type, "any" => $this->trivial];
        }
        return ["type" => $this->type, "match" => $this->regex->preg_utf8()];
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
    /** @var PaperIDSet */
    private $pidset;

    function __construct() {
        parent::__construct("pn");
        $this->pidset = new PaperIDSet;
    }
    /** @param int $p0
     * @param int $p1
     * @param bool $explicit */
    function add_range($p0, $p1, $explicit = false) {
        $this->pidset->add_range($p0, $p1, $explicit);
    }
    function merge(SearchTerm $st) {
        if (!($st instanceof PaperID_SearchTerm)) {
            return false;
        }
        $this->pidset->merge($st->pidset);
        return true;
    }
    /** @return ?list<int> */
    function paper_ids() {
        return $this->pidset->ids(1000);
    }
    /** @return list<PaperIDSetRange> */
    function ranges() {
        return $this->pidset->ranges();
    }
    /** @return bool */
    function is_empty() {
        return $this->pidset->is_empty();
    }
    /** @param string $field
     * @return string */
    function sql_predicate($field) {
        return $this->pidset->sql_predicate($field);
    }

    function sqlexpr(SearchQueryInfo $sqi) {
        return $this->sql_predicate("Paper.paperId");
    }
    function is_sqlexpr_precise() {
        return true;
    }
    function test(PaperInfo $row, $xinfo) {
        return $this->pidset->contains($row->paperId);
    }
    function default_sort_column($top, $pl) {
        if ($top && !$this->pidset->is_sorted()) {
            return new PaperIDOrder_PaperColumn($pl->conf, $this->pidset);
        }
        return null;
    }
    static function parse_pidcode($word, SearchWord $sword, PaperSearch $srch) {
        if (($ids = SessionList::decode_ids($word, true)) === null) {
            $srch->lwarning($sword, "<0>Invalid pidcode");
            return new False_SearchTerm;
        }
        $st = new PaperID_SearchTerm;
        foreach ($ids as $id) {
            if (is_array($id)) {
                $st->add_range($id[0], $id[1]);
            } else {
                $st->add_range($id, $id);
            }
        }
        return $st;
    }
    /** @param string $word
     * @return PaperID_SearchTerm */
    static function parse_normal($word) {
        $st = new PaperID_SearchTerm;
        $pos = 0;
        while (preg_match('/\G\#?(\d++)((?:-|–|—)\#?(\d++)|(?:-|–|—)|)\s*,?\s*/s', $word, $m, 0, $pos)) {
            $p1 = intval($m[1]);
            if ($m[2] === "") {
                $p2 = $p1;
            } else if (!isset($m[3]) || $m[3] === "") {
                $p2 = PHP_INT_MAX;
            } else {
                $p2 = intval($m[3]);
            }
            $st->add_range($p1, $p2, $m[2] === "");
            $pos += strlen($m[0]);
        }
        return $st;
    }
}
