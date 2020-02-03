<?php
// column.php -- HotCRP helper class for list content
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Column {
    public $name;
    public $className;
    public $column = false;
    public $row = false;
    public $fold = false;
    public $sort = false;
    public $completion = false;
    public $minimal = false;
    public $is_visible = false;
    public $has_content = false;
    public $position;
    public $__subposition;

    static private $keys = [
        "name" => true, "className" => true, "column" => true, "row" => true,
        "fold" => true, "sort" => true, "completion" => true, "minimal" => true,
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
                if ($k === "row")
                    $row = true;
                else if ($k === "col" || $k === "column")
                    $row = false;
            }
            if ($row !== null) {
                $this->row = $row;
                $this->column = !$row;
            }
        }
        assert(!$this->row || !$this->column);
    }

    function viewable() {
        return $this->column || $this->row;
    }
    function viewable_column() {
        return $this->column;
    }
    function viewable_row() {
        return $this->row;
    }
    function column_json() {
        return array_intersect_key(get_object_vars($this), self::$keys);
    }
}
