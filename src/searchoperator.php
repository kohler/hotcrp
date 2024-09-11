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
    /** @var int
     * @readonly */
    public $precedence;
    /** @var int
     * @readonly */
    public $flags;

    const F_UNARY = 0x1;
    const F_ALLOW_SUBTYPE = 0x2;
    const F_SUBTYPE = 0x4;
    const F_UNNAMED = 0x8;
    const F_NOT = 0x10;
    const F_AND = 0x20;
    const F_OR = 0x40;
    const F_XOR = 0x80;
    const F_SIMPLEOPS = 0xF0;

    /** @param string $type
     * @param int $precedence
     * @param int $flags
     * @param ?string $subtype */
    function __construct($type, $precedence, $flags, $subtype = null) {
        $this->type = $type;
        $this->subtype = $subtype;
        $this->precedence = $precedence;
        $this->flags = $flags;
    }

    /** @return bool */
    function unary() {
        return ($this->flags & self::F_UNARY) !== 0;
    }

    /** @param string $subtype
     * @return SearchOperator */
    function make_subtype($subtype) {
        assert(($this->flags & self::F_ALLOW_SUBTYPE) !== 0);
        return new SearchOperator($this->type, $this->precedence, ($this->flags & ~self::F_ALLOW_SUBTYPE) | self::F_SUBTYPE, $subtype);
    }
}
