<?php
// column.php -- HotCRP helper class for list content
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Column {
    /** @var string */
    public $name;
    /** @var ?string */
    public $title;
    /** @var ?string */
    public $title_html;
    /** @var string */
    public $className;
    /** @var bool
     * @readonly */
    public $prefer_row = false;
    /** @var bool
     * @readonly */
    public $as_row;
    /** @var ?int */
    public $fold;
    /** @var bool */
    public $sort = false;
    /** @var bool|string */
    public $completion = false;
    /** @var bool */
    public $sort_reverse = false;
    /** @var int */
    public $sort_subset = -1;
    /** @var null|int|float */
    public $order;
    /** @var ?int */
    public $__source_order;
    /** @var bool */
    public $is_visible = false;
    /** @var bool */
    public $has_content = false;
    /** @var ?list<string> */
    protected $decorations;

    /** @param object $arg */
    function __construct($arg) {
        $this->name = $arg->name;
        if (isset($arg->title)) {
            $this->title = $arg->title;
        }
        if (isset($arg->title_html)) {
            $this->title_html = $arg->title_html;
        }
        if (isset($arg->className)) {
            $this->className = $arg->className;
        } else {
            $this->className = "pl_" . $this->name;
        }
        if ($arg->prefer_row ?? false) {
            $this->prefer_row = true;
        }
        $this->as_row = $this->prefer_row;
        if ($arg->sort ?? false) {
            $this->sort = true;
        }
        if (isset($arg->completion)) {
            $this->completion = $arg->completion;
        }
        if (isset($arg->order) || isset($arg->position) /* XXX */) {
            $this->order = $arg->order ?? $arg->position;
        }
        if (isset($arg->__source_order)) {
            $this->__source_order = $arg->__source_order;
        }
    }

    /** @return list<string> */
    function decorations() {
        return $this->decorations ?? [];
    }

    /** @param string $decor
     * @return bool */
    function add_decoration($decor) {
        if ($decor === "row" || $decor === "column") {
            /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
            $this->as_row = $decor === "row";
            return $this->__add_decoration($this->prefer_row !== $this->as_row ? $decor : null, ["column", "row"]);
        } else if ($decor === "up" || $decor === "forward") {
            $this->sort_reverse = false;
            return $this->__add_decoration(null, ["down"]);
        } else if ($decor === "down" || $decor === "reverse") {
            $this->sort_reverse = true;
            return $this->__add_decoration("down");
        } else if ($decor === "by") {
            return true;
        } else {
            return false;
        }
    }

    /** @param ?string $add
     * @param ?list<string> $remove
     * @return true */
    protected function __add_decoration($add, $remove = []) {
        if (!empty($remove)) {
            $this->decorations = array_values(array_diff($this->decorations ?? [], $remove));
        }
        if ($add !== null && !in_array($add, $this->decorations ?? [])) {
            $this->decorations[] = $add;
        }
        return true;
    }
}
