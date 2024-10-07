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
    /** @var ?ViewOptionList */
    protected $decorations;

    /** @param object|array $arg */
    function __construct($arg) {
        if (is_object($arg)) {
            $arg = (array) $arg;
        }
        $this->name = $arg["name"];
        $this->title = $arg["title"] ?? null;
        $this->title_html = $arg["title_html"] ?? null;
        if (isset($arg["className"])) {
            $this->className = $arg["className"];
        } else {
            $this->className = "pl_" . $this->name;
        }
        if ($arg["prefer_row"] ?? false) {
            $this->prefer_row = true;
        }
        $this->as_row = $this->prefer_row;
        $s = $arg["sort"] ?? false;
        if ($s === "desc" || $s === "descending") {
            $this->sort = 2;
        } else if ($s) {
            $this->sort = 1;
        }
        $this->sort_descending = $this->default_sort_descending();
        $this->fold = $arg["fold"] ?? null;
        if (isset($arg["completion"])) {
            $this->completion = $arg["completion"];
        }
        $this->order = $arg["order"] ?? null;
        $this->__source_order = $arg["__source_order"] ?? null;
    }

    /** @return ?ViewOptionList */
    function decoration_set() {
        return $this->decorations;
    }

    /** @return list<string> */
    function decoration_list() {
        $decor = [];
        foreach ($this->decorations ?? [] as $n => $v) {
            $decor[] = ViewOptionList::unparse_option($n, $v);
        }
        return $decor;
    }

    /** @param string $n
     * @return ?string */
    function decoration_value($n) {
        return $this->decorations ? $this->decorations->value($n) : null;
    }

    /** @return list<string> */
    function decoration_spec() {
        return [];
    }

    static private $base_decoration_spec = [
        "display" => "=row|col|column",
        "row" => "/display",
        "col" => "/display",
        "column" => "/col",
        "sort" => "=up|asc|ascending|down|desc|descending|forward|reverse",
        "up" => "/sort",
        "asc" => "/sort",
        "ascending" => "/sort",
        "down" => "/sort",
        "desc" => "/sort",
        "descending" => "/sort",
        "forward" => "/sort",
        "reverse" => "/sort"
    ];

    /** @param ?ViewOptionlist $volist
     * @return $this */
    function add_decorations($volist) {
        if (!$volist || $volist->is_empty()) {
            return $this;
        }

        // get spec
        $dsv = self::$base_decoration_spec;
        ViewOptionlist::build_spec($dsv, $this->decoration_spec());

        // add decorations
        $this->decorations = $this->decorations ?? new ViewOptionList;
        foreach ($volist as $n => $v) {
            $this->decorations->spec_add($n, $v, $dsv);
        }

        // analyze decorations
        if (($v = $this->decoration_value("display")) !== null) {
            /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
            $this->as_row = $v === "row";
            if ($this->as_row === $this->prefer_row) {
                $this->decorations->remove("display");
            }
        }
        if (($v = $this->decoration_value("sort")) !== null) {
            $sp = array_search($v, [
                "up", "asc", "ascending", "down", "desc", "descending", "forward", "reverse"
            ]);
            if ($sp < 3) {
                $this->sort_descending = false;
            } else if ($sp < 6) {
                $this->sort_descending = true;
            } else if ($sp === 6) {
                $this->sort_descending = $this->default_sort_descending();
            } else {
                $this->sort_descending = !$this->default_sort_descending();
            }
            if (($ss = $this->sort_decoration())) {
                $this->decorations->add("sort", $ss);
            } else {
                $this->decorations->remove("sort");
            }
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
}
