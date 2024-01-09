<?php
// searchoperator.php -- HotCRP helper class for search operators
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class SearchOperator {
    /** @var string
     * @readonly */
    public $type;
    /** @var ?string
     * @readonly */
    public $subtype;
    /** @var bool
     * @readonly */
    public $unary;
    /** @var int
     * @readonly */
    public $precedence;

    /** @var ?array<string,SearchOperator> */
    static private $list = null;

    /** @param string $type
     * @param bool $unary
     * @param int $precedence
     * @param ?string $subtype */
    function __construct($type, $unary, $precedence, $subtype = null) {
        $this->type = $type;
        $this->subtype = $subtype;
        $this->unary = $unary;
        $this->precedence = $precedence;
    }

    /** @return ?SearchOperator */
    static function get($name) {
        if (!self::$list) {
            self::$list["("] = new SearchOperator("(", true, 0);
            self::$list[")"] = new SearchOperator(")", true, 0);
            self::$list["NOT"] = self::$list["-"] = self::$list["!"] =
                new SearchOperator("not", true, 8);
            self::$list["+"] = new SearchOperator("+", true, 8);
            self::$list["SPACE"] = new SearchOperator("space", false, 7);
            self::$list["AND"] = self::$list["&&"] =
                new SearchOperator("and", false, 6);
            self::$list["XOR"] = self::$list["^^"] =
                new SearchOperator("xor", false, 5);
            self::$list["OR"] = self::$list["||"] =
                new SearchOperator("or", false, 4);
            self::$list["SPACEOR"] = new SearchOperator("or", false, 3);
            self::$list["THEN"] = new SearchOperator("then", false, 2);
            self::$list["HIGHLIGHT"] = new SearchOperator("highlight", false, 1);
        }
        return self::$list[$name] ?? null;
    }
}
