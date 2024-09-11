<?php
// searchexpr.php -- HotCRP class holding information about search words
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class SearchExpr {
    /** @var ?string */
    public $kword;
    /** @var string */
    public $text;
    /** @var ?int */
    public $kwpos1;
    /** @var ?int */
    public $pos1;
    /** @var ?int */
    public $pos2;
    /** @var ?SearchOperator */
    public $op;
    /** @var ?list<SearchExpr> */
    public $child;
    /** @var ?SearchExpr */
    public $parent;

    /** @param string $text
     * @param int $pos1
     * @param ?SearchExpr $parent
     * @return SearchExpr */
    static function make_simple($text, $pos1, $parent = null) {
        $sa = new SearchExpr;
        $sa->text = $text;
        $sa->kwpos1 = $sa->pos1 = $pos1;
        $sa->pos2 = $pos1 + strlen($text);
        $sa->parent = $parent;
        return $sa;
    }

    /** @param ?string $kword
     * @param string $text
     * @param int $kwpos1
     * @param int $pos1
     * @param int $pos2
     * @param ?SearchExpr $parent
     * @return SearchExpr */
    static function make_keyword($kword, $text, $kwpos1, $pos1, $pos2, $parent = null) {
        $sa = new SearchExpr;
        $sa->kword = $kword === "" ? null : $kword;
        $sa->text = $text;
        $sa->kwpos1 = $kwpos1;
        $sa->pos1 = $pos1;
        $sa->pos2 = $pos2;
        $sa->parent = $parent;
        return $sa;
    }

    /** @param SearchOperator $op
     * @param int $pos1
     * @param int $pos2
     * @param ?SearchExpr $reference
     * @return SearchExpr */
    static function make_op_start($op, $pos1, $pos2, $reference) {
        $sa = new SearchExpr;
        $sa->op = $op;
        if ($op->unary()) {
            $sa->kwpos1 = $sa->pos1 = $pos1;
            $sa->pos2 = $pos2;
            $sa->child = [];
            $sa->parent = $reference;
        } else {
            $sa->kwpos1 = $sa->pos1 = $reference->pos1;
            $sa->pos2 = $pos2;
            $sa->child = [$reference];
            $sa->parent = $reference->parent;
        }
        return $sa;
    }

    /** @param 'and'|'or'|'xor'|'not' $opname
     * @param SearchExpr ...$child
     * @return SearchExpr */
    static function combine($opname, ...$child) {
        $sa = new SearchExpr;
        $sa->op = SearchOperatorSet::simple_operator($opname);
        $sa->child = $child;
        return $sa;
    }

    /** @return bool */
    function is_complete() {
        return !$this->op || count($this->child) > ($this->op->unary() ? 0 : 1);
    }

    /** @return bool */
    function is_incomplete_paren() {
        return $this->op && $this->op->type === "(" && empty($this->child);
    }

    /** @param int $pos
     * @return SearchExpr */
    function complete($pos) {
        if (!$this->is_complete()) {
            $this->pos2 = $pos;
            $this->child[] = self::make_simple("", $pos);
        }
        if (($p = $this->parent)) {
            $p->child[] = $this;
            $p->pos2 = $this->pos2;
            return $p;
        } else {
            return $this;
        }
    }

    /** @param int $pos1
     * @param int $pos2
     * @return SearchExpr */
    function complete_paren($pos1, $pos2) {
        $a = $this;
        $first = $a->op && $a->op->type === "(" && !empty($a->child);
        while (!$a->op || $a->op->type !== "(" || $first) {
            $a = $a->complete($pos1);
            $first = false;
        }
        $a->pos2 = $pos2;
        if (empty($a->child)) {
            $a->child[] = self::make_simple("", $pos2);
        }
        return $a;
    }

    /** @return list<SearchExpr> */
    function flattened_children() {
        if (!$this->op || $this->op->unary()) {
            return $this->child ?? [];
        }
        $a = [];
        foreach ($this->child as $ch) {
            if ($ch->op
                && $ch->op->type === $this->op->type
                && $ch->op->subtype === $this->op->subtype) {
                array_push($a, ...$ch->flattened_children());
            } else {
                $a[] = $ch;
            }
        }
        return $a;
    }

    /** @param string $str
     * @param string $indent
     * @return string */
    function unparse($str, $indent = "") {
        if (!$this->op) {
            $ctx = $this->kword ? "{$this->kword}:{$this->text}" : $this->text;
            if (strlen($ctx) > 40) {
                $ctx = substr($ctx, 0, 32) . "...";
            }
        } else if (!$str) {
            $ctx = "";
        } else if ($this->pos2 - $this->kwpos1 > 40) {
            $ctx = substr($str, $this->kwpos1, 16) . "..." . substr($str, $this->pos2 - 16, 16);
        } else {
            $ctx = substr($str, $this->kwpos1, $this->pos2 - $this->kwpos1);
        }
        if ($this->op) {
            $ctx = $ctx === "" ? "" : " <<{$ctx}>>";
            $ts = ["{$indent}[[{$this->op->type}]] @{$this->kwpos1}{$ctx}\n"];
            $nindent = $indent . "  ";
            foreach ($this->child as $sa) {
                $ts[] = $sa->unparse($str, $nindent);
            }
            return join("", $ts);
        } else {
            return "{$indent}@{$this->kwpos1} {$ctx}\n";
        }
    }

    /** @param ?string $str
     * @return object|string */
    function unparse_json($str = null) {
        if (!$this->op) {
            return $this->kword ? "{$this->kword}:{$this->text}" : $this->text;
        } else {
            $a = ["op" => $this->op->type];
            foreach ($this->child as $sa) {
                $a["child"][] = $sa->unparse_json($str);
            }
            if ($str !== null) {
                $a["context"] = substr($str, $this->pos1, $this->pos2 - $this->pos1);
            }
            return (object) $a;
        }
    }

    /** @param callable(SearchExpr):bool $f
     * @return bool
     * @suppress PhanTypeArraySuspiciousNullable */
    function evaluate_simple($f) {
        if (!$this->op) {
            $ok = $f($this);
        } else if (($this->op->flags & SearchOperator::F_AND) !== 0) {
            $ok = true;
            foreach ($this->child as $ch) {
                $ok = $ok && $ch->evaluate_simple($f);
            }
        } else if (($this->op->flags & SearchOperator::F_OR) !== 0) {
            $ok = false;
            foreach ($this->child as $ch) {
                $ok = $ok || $ch->evaluate_simple($f);
            }
        } else if (($this->op->flags & SearchOperator::F_XOR) !== 0) {
            $ok = false;
            foreach ($this->child as $ch) {
                if ($ch->evaluate_simple($f))
                    $ok = !$ok;
            }
        } else if (($this->op->flags & SearchOperator::F_NOT) !== 0) {
            $ok = !$this->child[0] || !$this->child[0]->evaluate_simple($f);
        } else {
            throw new ErrorException("unknown operator");
        }
        return $ok;
    }
}
