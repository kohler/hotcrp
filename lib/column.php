<?php
// column.php -- HotCRP helper class for list content
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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
    /** @var 0|1|2
     * @readonly */
    public $sort = 0;
    /** @var ?int */
    public $fold;
    /** @var bool|string */
    public $completion = false;
    /** @var bool */
    public $sort_descending;
    /** @var ?list<int> */
    public $sort_subset;
    /** @var null|int|float */
    public $order;
    /** @var null|int|float */
    public $view_order;
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
        $s = $arg->sort ?? false;
        if ($s === "desc" || $s === "descending") {
            $this->sort = 2;
        } else if ($s) {
            $this->sort = 1;
        }
        $this->sort_descending = $this->default_sort_descending();
        if (isset($arg->completion)) {
            $this->completion = $arg->completion;
        }
        if (isset($arg->order)) {
            $this->order = $arg->order;
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
     * @return int|false */
    function decoration_index($decor) {
        $l = strlen($decor);
        foreach ($this->decorations ?? [] as $i => $s) {
            if (str_starts_with($s, $decor)
                && (strlen($s) === $l || $s[$l] === "=")) {
                return $i;
            }
        }
        return false;
    }

    /** @return bool */
    function has_decoration($decor) {
        return $this->decoration_index($decor) !== false;
    }

    /** @return ?string */
    function decoration_value($decor) {
        if (($i = $this->decoration_index($decor)) !== false) {
            /** @phan-suppress-next-line PhanTypeArraySuspiciousNullable */
            $s = $this->decorations[$i];
            $l = strlen($decor);
            return strlen($s) === $l ? "" : substr($s, $l + 1);
        } else {
            return null;
        }
    }

    /** @param string $decor
     * @return bool */
    function add_decoration($decor) {
        if ($decor === "row" || $decor === "column") {
            /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
            $this->as_row = $decor === "row";
            $dd = $this->prefer_row !== $this->as_row ? $decor : null;
            return $this->__add_decoration($dd, ["column", "row"]);
        }

        $sp = array_search($decor, [
            "up", "asc", "ascending", "down", "desc", "descending", "forward", "reverse"
        ]);
        if ($sp !== false) {
            if ($sp < 3) {
                $this->sort_descending = false;
            } else if ($sp < 6) {
                $this->sort_descending = true;
            } else if ($sp === 6) {
                $this->sort_descending = $this->default_sort_descending();
            } else {
                $this->sort_descending = !$this->default_sort_descending();
            }
            return $this->__add_decoration($this->sort_decoration(), ["asc", "desc"]);
        }

        if ($decor === "by") {
            return true;
        } else {
            return false;
        }
    }

    /** @param ?list<string> $decors
     * @return $this */
    function add_decorations($decors) {
        foreach ($decors ?? [] as $decor) {
            $this->add_decoration($decor);
        }
        return $this;
    }

    /** @return bool */
    function default_sort_descending() {
        return $this->sort === 2;
    }

    /** @return string */
    function sort_decoration() {
        if ($this->sort_descending === $this->default_sort_descending()) {
            return "";
        }
        return $this->sort_descending ? "desc" : "asc";
    }

    /** @param ?string $add
     * @param ?list<?string> $remove
     * @return true */
    protected function __add_decoration($add, $remove = []) {
        foreach ($remove as $s) {
            if ($s !== null && ($i = $this->decoration_index($s)) !== false) {
                array_splice($this->decorations, $i, 1);
            }
        }
        if ($add !== null && $add !== "") {
            $addx = $add;
            if (($eq = strpos($add, "=")) !== false) {
                $addx = substr($add, 0, $eq);
            }
            if (($i = $this->decoration_index($addx)) !== false) {
                $this->decorations[$i] = $add;
            } else {
                $this->decorations[] = $add;
            }
        }
        return true;
    }
}
