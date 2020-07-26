<?php
// column.php -- HotCRP helper class for list content
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Column {
    public $name;
    /** @var ?string */
    public $title;
    /** @var ?string */
    public $title_html;
    public $className;
    /** @var bool */
    public $column = false;
    /** @var bool */
    public $row = false;
    /** @var ?int */
    public $fold;
    /** @var bool */
    public $sort = false;
    /** @var bool */
    public $completion = false;
    /** @var bool */
    public $minimal = false;
    public $position;
    public $__subposition;
    /** @var bool */
    public $is_visible = false;
    /** @var bool */
    public $has_content = false;

    static private $keys = [
        "name" => true, "title" => true, "title_html" => true,
        "className" => true, "column" => true, "row" => true,
        "sort" => true, "completion" => true, "minimal" => true,
        "position" => true
    ];

    function __construct($arg) {
        foreach ((array) $arg as $k => $v) {
            if (isset(self::$keys[$k]))
                $this->$k = $v;
        }
        if ($this->className === null) {
            $this->className = "pl_" . $this->name;
        }
        if (isset($arg->options)) {
            $row = null;
            foreach ($arg->options as $k) {
                if ($k === "row") {
                    $row = true;
                } else if ($k === "col" || $k === "column") {
                    $row = false;
                } else if (str_starts_with($k, "title:")) {
                    $this->title = SearchWord::unquote(substr($k, 6));
                    $this->title_html = null;
                }
            }
            if ($row !== null) {
                $this->row = $row;
                $this->column = !$row;
            }
        }
        assert(!$this->row || !$this->column);
    }

    /** @return bool */
    function viewable() {
        return $this->column || $this->row;
    }
    /** @return bool */
    function viewable_column() {
        return $this->column;
    }
    /** @return bool */
    function viewable_row() {
        return $this->row;
    }
    function column_json() {
        return array_intersect_key(get_object_vars($this), self::$keys);
    }
}
