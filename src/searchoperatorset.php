<?php
// searchoperatorset.php -- HotCRP helper class for search operators
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class SearchOperatorSet {
    /** @var array<string,SearchOperator> */
    private $a = [];
    /** @var ?string */
    private $regex;

    /** @var ?SearchOperatorSet */
    static private $psops = null;


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
            $br[] = '(?:' . join("|", $alnum) . ')(?=[\s\(\)]|\z)';
        }
        $this->regex = '/\G(?:' . join("|", $br) . ')/s';
        return $this->regex;
    }

    /** @return SearchOperatorSet */
    static function paper_search_operators() {
        if (self::$psops !== null) {
            return self::$psops;
        }
        $psops = new SearchOperatorSet;
        $psops->define("(", new SearchOperator("(", 0, SearchOperator::F_UNARY));
        $psops->define(")", new SearchOperator(")", 0, SearchOperator::F_UNARY));
        $op = new SearchOperator("not", 8, SearchOperator::F_UNARY);
        $psops->define("NOT", $op);
        $psops->define("not", $op);
        $psops->define("-", $op);
        $psops->define("!", $op);
        $psops->define("+", new SearchOperator("+", 8, SearchOperator::F_UNARY));
        $psops->define("SPACE", new SearchOperator("space", 7, SearchOperator::F_UNNAMED));
        $op = new SearchOperator("and", 6, 0);
        $psops->define("AND", $op);
        $psops->define("and", $op);
        $psops->define("&&", $op);
        $op = new SearchOperator("xor", 5, 0);
        $psops->define("XOR", $op);
        $psops->define("xor", $op);
        $psops->define("^^", $op);
        $op = new SearchOperator("or", 4, 0);
        $psops->define("OR", $op);
        $psops->define("or", $op);
        $psops->define("||", $op);
        $psops->define("SPACEOR", new SearchOperator("or", 3, SearchOperator::F_UNNAMED));
        $op = new SearchOperator("then", 2, 0);
        $psops->define("THEN", $op);
        $psops->define("then", $op);
        $psops->define("HIGHLIGHT", new SearchOperator("highlight", 1, SearchOperator::F_ALLOW_SUBTYPE));
        self::$psops = $psops;
        return $psops;
    }
}
