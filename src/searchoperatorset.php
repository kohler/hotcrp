<?php
// searchoperatorset.php -- HotCRP helper class for search operators
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class SearchOperatorSet {
    /** @var array<string,SearchOperator> */
    private $a = [];
    /** @var ?string */
    private $regex;

    /** @var ?SearchOperatorSet */
    static private $simpleops = null;
    /** @var ?SearchOperatorSet */
    static private $psops = null;


    /** @param array<string,SearchOperator> $a */
    function __construct($a = []) {
        $this->a = $a;
    }

    /** @param string $name
     * @param SearchOperator $op */
    function define($name, $op) {
        // XXX no backslashes allowed
        // XXX $name should be ctype_punct or contain no ctype_punct
        $this->a[$name] = $op;
        $this->regex = null;
    }

    /** @param string $name
     * @return ?SearchOperator */
    function lookup($name) {
        $op = $this->a[$name] ?? null;
        if ($op === null
            && ($colon = strpos($name, ":")) !== false
            && ($xop = $this->a[substr($name, 0, $colon)]) !== null
            && ($xop->flags & SearchOperator::F_ALLOW_SUBTYPE) !== 0) {
            $op = $xop->make_subtype(substr($name, $colon + 1));
            $this->a[$name] = $op;
        }
        return $op;
    }

    /** @return string */
    function regex() {
        if ($this->regex !== null) {
            return $this->regex;
        }
        $ch = "";
        $br = $alnum = [];
        foreach ($this->a as $name => $op) {
            // XXX need more careful handling of longest-match
            if (($op->flags & (SearchOperator::F_SUBTYPE | SearchOperator::F_UNNAMED)) !== 0) {
                continue;
            }
            if (ctype_punct($name)) {
                assert(($op->flags & SearchOperator::F_ALLOW_SUBTYPE) === 0);
                if (strlen($name) === 1) {
                    $ch .= preg_quote($name, "/");
                } else {
                    $br[] = preg_quote($name, "/");
                }
            } else {
                $x = preg_quote($name, "/");
                if (($op->flags & SearchOperator::F_ALLOW_SUBTYPE) !== 0) {
                    $x .= '(?::\w+)?';
                }
                $alnum[] = $x;
            }
        }
        if ($ch !== "") {
            $br[] = "[{$ch}]";
        }
        if (!empty($alnum)) {
            $br[] = '(?:' . join("|", $alnum) . ')(?=[\s\(\)\[\]\{\}]|\z)';
        }
        $this->regex = '/\G(?:' . join("|", $br) . ')/s';
        return $this->regex;
    }

    /** @param string $str
     * @param int $pos
     * @return bool */
    static function safe_terminator($str, $pos = 0) {
        return preg_match('/\G[\s\(\)\[\]\{\}]/', $str, $m, 0, $pos);
    }


    /** @return SearchOperatorSet */
    static function simple_operators() {
        if (self::$simpleops !== null) {
            return self::$simpleops;
        }
        $not = new SearchOperator("not", 8, SearchOperator::F_UNARY | SearchOperator::F_NOT);
        $space = new SearchOperator("space", 7, SearchOperator::F_UNNAMED | SearchOperator::F_AND);
        $and = new SearchOperator("and", 6, SearchOperator::F_AND);
        $xor = new SearchOperator("xor", 5, SearchOperator::F_XOR);
        $or = new SearchOperator("or", 4, SearchOperator::F_OR);
        self::$simpleops = new SearchOperatorSet([
            "(" => new SearchOperator("(", 0, SearchOperator::F_UNARY | SearchOperator::F_AND),
            ")" => new SearchOperator(")", 0, SearchOperator::F_UNARY),
            "NOT" => $not,
            "-" => $not,
            "!" => $not,
            "SPACE" => new SearchOperator("space", 7, SearchOperator::F_UNNAMED | SearchOperator::F_AND),
            "AND" => $and,
            "&&" => $and,
            "XOR" => $xor,
            "^^" => $xor,
            "OR" => $or,
            "||" => $or
        ]);
        return self::$simpleops;
    }

    /** @param 'and'|'or'|'not'|'xor' $name
     * @return SearchOperator */
    static function simple_operator($name) {
        return self::paper_search_operators()->lookup($name);
    }


    /** @return SearchOperatorSet */
    static function paper_search_operators() {
        if (self::$psops !== null) {
            return self::$psops;
        }
        self::$psops = $psops = new SearchOperatorSet(self::simple_operators()->a);
        $psops->a["not"] = $psops->a["NOT"];
        $psops->a["+"] = new SearchOperator("+", 8, SearchOperator::F_UNARY | SearchOperator::F_AND);
        $psops->a["and"] = $psops->a["AND"];
        $psops->a["xor"] = $psops->a["XOR"];
        $psops->a["or"] = $psops->a["OR"];
        $psops->a["SPACEOR"] = new SearchOperator("or", 3, SearchOperator::F_UNNAMED | SearchOperator::F_OR);
        $psops->a["THEN"] = $psops->a["then"] = new SearchOperator("then", 2, SearchOperator::F_OR);
        $psops->a["HIGHLIGHT"] = new SearchOperator("highlight", 1, SearchOperator::F_ALLOW_SUBTYPE);
        return $psops;
    }
}
