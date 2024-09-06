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

    const F_UNARY = 1;
    const F_ALLOW_SUBTYPE = 2;
    const F_SUBTYPE = 4;
    const F_UNNAMED = 8;

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
