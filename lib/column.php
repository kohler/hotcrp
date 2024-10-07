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
    protected $view_options;

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
    function view_options() {
        return $this->view_options;
    }

    /** @param string $n
     * @return mixed */
    function view_option($n) {
        return $this->view_options ? $this->view_options->get($n) : null;
    }

    /** @return list<string|object> */
    function view_option_schema() {
        return [];
    }

    /** @var ViewOptionSchema */
    static private $base_schema;

    /** @param ?ViewOptionlist $volist
     * @return $this */
    function add_view_options($volist) {
        if (!$volist || $volist->is_empty()) {
            return $this;
        }

        // get schema
        if (self::$base_schema === null) {
            self::$base_schema = new ViewOptionSchema;
            self::$base_schema->define("display=row col,column");
            self::$base_schema->define("sort=asc,ascending,up desc,descending,down forward reverse");
        }
        $schema = self::$base_schema;
        foreach ($this->view_option_schema() as $x) {
            if ($schema === self::$base_schema) {
                $schema = clone $schema;
            }
            $schema->define($x);
        }

        // add options
        $this->view_options = $this->view_options ?? new ViewOptionList;
        $this->view_options->append_validate($volist, $schema);

        // analyze options
        if (($v = $this->view_option("display")) !== null) {
            /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
            $this->as_row = $v === "row";
            if ($this->as_row === $this->prefer_row) {
                $this->view_options->remove("display");
            }
        }
        if (($v = $this->view_option("sort")) !== null) {
            if ($v === "asc") {
                $this->sort_descending = false;
            } else if ($v === "desc") {
                $this->sort_descending = true;
            } else if ($v === "forward") {
                $this->sort_descending = $this->default_sort_descending();
            } else {
                $this->sort_descending = !$this->default_sort_descending();
            }
            if (($ss = $this->sort_option())) {
                $this->view_options->add("sort", $ss);
            } else {
                $this->view_options->remove("sort");
            }
        }

        return $this;
    }

    /** @return bool */
    function default_sort_descending() {
        return $this->sort === 2;
    }

    /** @return string */
    function sort_option() {
        if ($this->sort_descending === $this->default_sort_descending()) {
            return "";
        }
        return $this->sort_descending ? "desc" : "asc";
    }
}
