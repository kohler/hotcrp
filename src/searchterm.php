<?php
// searchterm.php -- HotCRP paper search terms
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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
            assert($name === "then" || $name === "highlight");
            $qr = new Then_SearchTerm($op);
        }
        $qr->string_context = $string_context;
        foreach ($terms as $qt) {
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

    /** @return list<array{string,?int,?int,?int,?SearchStringContext}> */
    function view_anno() {
        return $this->float["view"] ?? [];
    }

    /** @param string $view
     * @param SearchWord $sword
     * @return $this */
    function add_view_anno($view, $sword) {
        $this->float["view"][] = [$view, $sword->kwpos1, $sword->pos1, $sword->pos2, $sword->string_context];
        return $this;
    }

    /** @param string $field
     * @return ?SearchViewElement */
    function view_anno_element($field) {
        foreach (PaperSearch::view_generator($this->float["view"] ?? []) as $sve) {
            if ($field === $sve->keyword)
                return $sve;
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
     * @param int $pos2
     * @param ?SearchStringContext $context */
    function apply_strspan($pos1, $pos2, $context) {
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


    /** @return string */
    function sqlexpr(SearchQueryInfo $sqi) {
        return "true";
    }

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


    /** @param ?ReviewInfo|?CommentInfo $xinfo
     * @return bool */
    abstract function test(PaperInfo $row, $xinfo);


    /** @param callable(SearchTerm,...):mixed $visitor
     * @return mixed */
    function visit($visitor) {
        return $visitor($this);
    }

    /** @return Generator<SearchTerm> */
    function preorder() {
        yield $this;
    }


    /** @param PaperSearchPrepareParam $param
     * @return void */
    function prepare_visit($param, PaperSearch $srch) {
    }

    /** @param bool $top
     * @param PaperList $pl
     * @return ?PaperColumn */
    function default_sort_column($top, $pl) {
        return null;
    }

    const ABOUT_PAPER = 0;
    const ABOUT_UNKNOWN = 1;
    const ABOUT_REVIEW = 2;
    const ABOUT_REVIEW_SET = 3;
    /** @return 0|1|2|3 */
    function about() {
        return self::ABOUT_PAPER;
    }


    /** @param 0|2 $about
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
        return self::ABOUT_PAPER;
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
    function is_uninteresting() {
        return count($this->float) === 1 && isset($this->float["view"]);
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
        return self::ABOUT_PAPER;
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
            if ($term->string_context === $this->string_context && $term->pos1 !== null) {
                $this->apply_strspan($term->pos1, $term->pos2, $term->string_context);
            }
        }
        return $this;
    }
    /** @return SearchTerm */
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
    function prepare_visit($param, PaperSearch $srch) {
        $param = $param->nest($this);
        foreach ($this->child as $qv) {
            $qv->prepare_visit($param, $srch);
        }
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
    function about() {
        $x = 0;
        foreach ($this->child as $qv) {
            $x = max($x, $qv->about());
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
    function test(PaperInfo $row, $xinfo) {
        return !$this->child[0]->test($row, $xinfo);
    }

    function script_expression(PaperInfo $row, $about) {
        $x = $this->child[0]->script_expression($row, $about);
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
    function test(PaperInfo $row, $xinfo) {
        foreach ($this->child as $subt) {
            if (!$subt->test($row, $xinfo))
                return false;
        }
        return true;
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
    function script_expression(PaperInfo $row, $about) {
        $ch = [];
        $ok = true;
        foreach ($this->child as $subt) {
            $x = $subt->script_expression($row, $about);
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
    function test(PaperInfo $row, $xinfo) {
        foreach ($this->child as $subt) {
            if ($subt->test($row, $xinfo))
                return true;
        }
        return false;
    }
    /** @param list<SearchTerm> $child
     * @param 0|2 $about
     * @return null|bool|array{type:string} */
    static function make_script_expression($child, PaperInfo $row, $about) {
        $ch = [];
        $ok = false;
        foreach ($child as $subt) {
            $x = $subt->script_expression($row, $about);
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
    function script_expression(PaperInfo $row, $about) {
        return self::make_script_expression($this->child, $row, $about);
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
    function test(PaperInfo $row, $xinfo) {
        $x = false;
        foreach ($this->child as $subt) {
            if ($subt->test($row, $xinfo))
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
    private $subtype;
    /** @var int */
    private $nthen = 0;
    /** @var list<Highlight_SearchInfo> */
    private $hlinfo = [];
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
        $this->subtype = $op->subtype;
    }
    protected function _finish() {
        $subtype = strtolower($this->subtype ?? "");
        $newvalues = $newhvalues = $newhinfo = [];

        foreach ($this->child as $qvidx => $qv) {
            if ($qv && $qvidx > 0 && $this->is_highlight) {
                if ($qv instanceof Then_SearchTerm) {
                    for ($i = 0; $i < $qv->nthen; ++$i) {
                        $newhvalues[] = $qv->child[$i];
                        $newhinfo[] = new Highlight_SearchInfo(0, count($newvalues), $subtype);
                    }
                } else {
                    $newhvalues[] = $qv;
                    $newhinfo[] = new Highlight_SearchInfo(0, count($newvalues), $subtype);
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
    function prepare_visit($param, PaperSearch $srch) {
        $group_offset = 0;
        foreach ($this->child as $i => $qv) {
            $param1 = $param->nest($this);
            $qv->prepare_visit($param1, $srch);
            if ($i < $this->nthen) {
                $this->_group_offsets[] = $group_offset;
                if ($param->allow_then() && $param1->then_term()) {
                    $this->_nested_thens[] = $param1->then_term();
                    $group_offset += count($param1->then_term()->group_terms());
                } else {
                    $this->_nested_thens[] = null;
                    $group_offset += 1;
                }
            }
        }
        $this->_group_offsets[] = $group_offset;
        $param->set_then_term($this);
    }
    function visit($visitor) {
        // Only visit non-highlight terms
        $x = [];
        for ($i = 0; $i !== $this->nthen; ++$i) {
            $x[] = $this->child[$i]->visit($visitor);
        }
        return $visitor($this, ...$x);
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
        ++$sqi->depth;
        $ff = [];
        foreach ($this->child as $subt) {
            $ff[] = $subt->sqlexpr($sqi);
        }
        --$sqi->depth;
        return self::orjoin_sqlexpr(array_slice($ff, 0, $this->nthen), "true");
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
    function script_expression(PaperInfo $row, $about) {
        return Or_SearchTerm::make_script_expression(array_slice($this->child, 0, $this->nthen), $row, $about);
    }

    /** @return bool */
    function has_highlight() {
        if ($this->nthen < count($this->child)) {
            return true;
        }
        foreach ($this->_nested_thens as $thench) {
            if ($thench && $thench->has_highlight()) {
                return true;
            }
        }
        return false;
    }
    /** @return int */
    function _last_group() {
        $g = $this->_last_group;
        $thench = $this->_nested_thens[$g];
        return $this->_group_offsets[$g] + ($thench ? $thench->_last_group() : 0);
    }
    /** @return list<string> */
    function _last_highlights(PaperInfo $row) {
        $g = $this->_last_group;
        $hls = [];
        foreach ($this->hlinfo as $i => $hl) {
            if ($g >= $hl->pos
                && $g < $hl->pos + $hl->count
                && $this->child[$this->nthen + $i]->test($row, null)) {
                $hls[] = $hl->color;
            }
        }
        if (($thench = $this->_nested_thens[$g])) {
            array_push($hls, ...$thench->_last_highlights($row));
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

    /* NB all named_limits must equal themselves when urlencoded */
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
        $conf = $this->user->conf;
        // optimize SQL for some limits
        if ($limit === "viewable" && $this->user->can_view_all()) {
            $limit = "all";
        } else if ($limit === "reviewable" && !$this->reviewer->isPC) {
            $limit = "r";
        }
        $this->limit = $limit;
        // mark flags
        if (in_array($limit, ["a", "ar", "r", "req", "viewable", "reviewable",
                              "all", "none"], true)) {
            $this->lflag = 0;
        } else if (in_array($limit, ["active", "unsub", "actadmin"], true)
                   || ($conf->can_pc_view_some_incomplete()
                       && !in_array($limit, ["s", "accepted"], true))) {
            $this->lflag = self::LFLAG_ACTIVE;
        } else {
            $this->lflag = self::LFLAG_SUBMITTED;
        }
    }

    /** @return bool */
    function is_submitted() {
        return ($this->lflag & self::LFLAG_SUBMITTED) !== 0;
    }

    /** @return bool */
    function is_accepted() {
        return $this->limit === "accepted";
    }

    /** @return bool */
    function is_author() {
        return $this->limit === "a";
    }

    /** @param array<string,mixed> &$options
     * @return bool */
    function simple_search(&$options) {
        // hidden papers => complex search
        if (($this->user->dangerous_track_mask() & Track::BITS_VIEW) !== 0) {
            return false;
        }
        // if tracks, nonchairs get simple search only for "a", "r", sometimes "s"
        $conf = $this->user->conf;
        if (!$this->user->privChair
            && $conf->has_tracks()
            && $this->limit !== "a"
            && $this->limit !== "r"
            && $this->limit !== "s") {
            return false;
        }
        // otherwise go by limit
        $fin = $options["finalized"] = ($this->lflag & self::LFLAG_SUBMITTED) !== 0;
        $act = $options["active"] = ($this->lflag & self::LFLAG_ACTIVE) !== 0;
        switch ($this->limit) {
        case "all":
        case "viewable":
            return $this->user->privChair;
        case "s":
            assert($fin);
            return $this->user->isPC;
        case "active":
            assert($act || $fin);
            $options["dec:active"] = true;
            return $this->user->can_view_all_incomplete()
                && $this->user->can_view_all_decision();
        case "reviewable":
            if (!$this->reviewer->isPC) {
                $options["myReviews"] = true;
                return true;
            } else {
                return false;
            }
        case "a":
            $options["author"] = true;
            // If complex author SQL, always do search the long way
            return !$this->user->act_author_view_sql("%", true);
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
            $options["dec:yes"] = true;
            return $this->user->can_view_all_decision();
        case "undecided":
            assert($fin);
            $options["dec:none"] = true;
            return $this->user->can_view_all_decision();
        case "unsub":
            assert($act);
            $options["unsub"] = true;
            return $this->user->allow_administer_all();
        case "lead":
            $options["myLead"] = true;
            return true;
        case "alladmin":
        case "actadmin":
            return $this->user->allow_administer_all();
        case "admin":
            return false;
        case "req":
            $options["myReviewRequests"] = true;
            return true;
        default:
            return false;
        }
    }

    function paper_requirements(&$options) {
        if (in_array($this->limit, ["reviewable", "ar", "r", "rout"])) {
            $options["reviewSignatures"] = true;
        }
    }

    function is_sqlexpr_precise() {
        // hidden papers, view limits => imprecise
        if (($this->user->dangerous_track_mask() & Track::BITS_VIEW) !== 0) {
            return false;
        }
        switch ($this->limit) {
        case "viewable":
        case "alladmin":
        case "actadmin":
            // broad limits are precise only if allowed to administer all
            return $this->user->allow_administer_all();
        case "active":
        case "accepted":
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
        assert($sqi->depth > 0 || $sqi->srch->user === $this->user);

        $ff = [];
        if (($this->lflag & self::LFLAG_SUBMITTED) !== 0) {
            $ff[] = "Paper.timeSubmitted>0";
        } else if (($this->lflag & self::LFLAG_ACTIVE) !== 0) {
            $ff[] = "Paper.timeWithdrawn<=0";
        }

        $act_reviewer_sql = "error";
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
        }

        switch ($this->limit) {
        case "all":
        case "viewable":
        case "s":
            break;
        case "reviewable":
            $sqi->add_reviewer_columns();
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
                $r = "exists (select * from PaperReview force index (primary) where paperId=Paper.paperId and {$act_reviewer_sql})";
            }
            $ff[] = "(" . $this->user->act_author_view_sql($sqi->conflict_table($this->user)) . " or {$r})";
            break;
        case "r":
            if ($act_reviewer_sql === "false") {
                $ff[] = "false";
            } else if ($sqi->depth === 0) {
                // the `join` with MyReviews suffices
            } else {
                $ff[] = "exists (select * from PaperReview force index (primary) where paperId=Paper.paperId and {$act_reviewer_sql})";
            }
            break;
        case "rout":
            if ($act_reviewer_sql === "false") {
                $ff[] = "false";
            } else if ($sqi->depth === 0) {
                $ff[] = "MyReviews.reviewNeedsSubmit!=0";
            } else {
                $ff[] = "exists (select * from PaperReview force index (primary) where paperId=Paper.paperId and {$act_reviewer_sql} and reviewNeedsSubmit!=0)";
            }
            break;
        case "active":
            if ($this->user->can_view_all_decision()) {
                $ff[] = "Paper." . $this->user->conf->decision_set()->sqlexpr("active");
            }
            break;
        case "accepted":
            $ff[] = "Paper.outcome>0";
            break;
        case "undecided":
            if ($this->user->can_view_all_decision()) {
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
        case "actadmin":
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

    function test(PaperInfo $row, $xinfo) {
        $user = $this->user;
        if ((($this->lflag & self::LFLAG_SUBMITTED) !== 0 && $row->timeSubmitted <= 0)
            || (($this->lflag & self::LFLAG_ACTIVE) !== 0 && $row->timeWithdrawn > 0)) {
            return false;
        }
        switch ($this->limit) {
        case "all":
        case "viewable":
        case "s":
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
        case "reviewable":
            if (($this->reviewer !== $user && !$user->allow_administer($row))
                || !$this->reviewer->can_accept_review_assignment_ignore_conflict($row)) {
                return false;
            } else if ($row->has_reviewer($this->reviewer)) {
                return true;
            } else {
                return ($row->timeSubmitted > 0
                        || ($row->timeWithdrawn <= 0
                            && $row->submission_round()->incomplete_viewable))
                    && ($row->outcome_sign !== -2
                        || !$user->can_view_decision($row));
            }
        case "active":
            return $row->outcome_sign !== -2
                || !$user->can_view_decision($row);
        case "accepted":
            return $row->outcome > 0
                && $user->can_view_decision($row);
        case "undecided":
            return $row->outcome === 0
                || !$user->can_view_decision($row);
        case "unsub":
            return $row->timeSubmitted <= 0 && $row->timeWithdrawn <= 0;
        case "lead":
            return $row->leadContactId === $user->contactXid;
        case "admin":
            return $user->is_primary_administrator($row);
        case "alladmin":
        case "actadmin":
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

    function prepare_visit($param, PaperSearch $srch) {
        if ($param->toplevel() && ($this->lflag & self::LFLAG_IMPLICIT) === 0) {
            $srch->apply_limit($this);
        }
    }
    function about() {
        if (in_array($this->limit, ["viewable", "reviewable", "ar", "r", "rout", "req"])) {
            return self::ABOUT_REVIEW_SET;
        } else {
            return self::ABOUT_PAPER;
        }
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
        $sqi->add_column("dataOverflow", "Paper.dataOverflow");
        if ($this->trivial && !$this->authorish) {
            return "(Paper.{$this->field}!='' or Paper.dataOverflow is not null)";
        } else {
            return "true";
        }
    }
    function is_sqlexpr_precise() {
        return $this->trivial && !$this->authorish;
    }
    function test(PaperInfo $row, $xinfo) {
        $data = $row->{$this->field}();
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
    function script_expression(PaperInfo $row, $about) {
        if ($about !== self::ABOUT_PAPER) {
            return parent::script_expression($row, $about);
        } else if (!$this->trivial || $this->field === "authorInformation") {
            return null;
        } else {
            return ["type" => $this->field, "match" => $this->trivial];
        }
    }
    function prepare_visit($param, PaperSearch $srch) {
        if ($param->want_field_highlighter() && $this->regex) {
            $srch->add_field_highlighter($this->type, $this->regex);
        }
    }
    function about() {
        return self::ABOUT_PAPER;
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
    function test(PaperInfo $row, $xinfo) {
        return $this->index_of($row->paperId) !== false;
    }
    function default_sort_column($top, $pl) {
        if ($top && !$this->in_order) {
            return new PaperIDOrder_PaperColumn($pl->conf, $this);
        } else {
            return null;
        }
    }
    function about() {
        return self::ABOUT_PAPER;
    }
    static function parse_pidcode($word, SearchWord $sword, PaperSearch $srch) {
        if (($ids = SessionList::decode_ids($word)) === null) {
            $srch->lwarning($sword, "<0>Invalid pidcode");
            return new False_SearchTerm;
        }
        $st = new PaperID_SearchTerm;
        foreach ($ids as $id) {
            $st->add_range($id, $id);
        }
        return $st;
    }
    /** @param string $word
     * @return PaperID_SearchTerm */
    static function parse_normal($word) {
        $st = new PaperID_SearchTerm;
        while (preg_match('/\A#?(\d+)(?:(?:-|–|—)#?(\d+))?\s*,?\s*(.*)\z/s', $word, $m)) {
            $m[2] = (isset($m[2]) && $m[2] ? $m[2] : $m[1]);
            $st->add_range(intval($m[1]), intval($m[2]));
            $word = $m[3];
        }
        return $st;
    }
}
