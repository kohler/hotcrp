<?php
// column.php -- HotCRP helper class for list content
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Column {
    public $name;
    public $className;
    public $column = false;
    public $row = false;
    public $fold = false;
    public $sort = false;
    public $completion = false;
    public $minimal = false;
    public $is_folded = false;
    public $has_content = false;
    public $priority = null;

    static private $keys = ["name" => true, "className" => true, "column" => true, "row" => true, "fold" => true, "sort" => true, "completion" => true, "minimal" => true, "priority" => true];

    function __construct($arg) {
        foreach ((array) $arg as $k => $v)
            if (isset(self::$keys[$k]))
                $this->$k = $v;
        if ($this->className === null)
            $this->className = "pl_" . $this->name;
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

class ColumnErrors {
    public $error_html = array();
    public $priority = null;
    public $allow_empty = false;
    function add($error_html, $priority) {
        if ($this->priority === null || $this->priority < $priority) {
            $this->error_html = array();
            $this->priority = $priority;
        }
        if ($this->priority == $priority)
            $this->error_html[] = $error_html;
    }
}
