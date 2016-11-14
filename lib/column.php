<?php
// column.php -- HotCRP helper class for list content
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Column {
    const VIEW_NONE = 0;
    const VIEW_COLUMN = 1;
    const VIEW_ROW = 2;
    const VIEWMASK = 3;

    const FOLDABLE = 16;
    const COMPLETABLE = 32;

    public $name;
    public $className;
    public $foldable = false;
    public $completable = false;
    public $view = 0;
    public $comparator = false;
    public $minimal = false;
    public $is_folded = false;
    public $has_content = false;

    public function __construct(/* $name, $flags, $extra */) {
        foreach (func_get_args() as $arg) {
            if (is_string($arg))
                $this->name = $arg;
            else if (is_int($arg)) {
                if ($arg & self::FOLDABLE)
                    $this->foldable = true;
                if ($arg & self::COMPLETABLE)
                    $this->completable = true;
                $this->view = $arg & self::VIEWMASK;
            } else
                foreach ((array) $arg as $k => $v) {
                    if ($k === "name" && is_string($v))
                        $this->name = $v;
                    else if ($k === "className" && is_string($v))
                        $this->className = $v;
                    else if ($k === "fold" && is_bool($v))
                        $this->foldable = $v;
                    else if ($k === "complete" && is_bool($v))
                        $this->completable = $v;
                    else if ($k === "view") {
                        if ($v === "column")
                            $this->view = self::VIEW_COLUMN;
                        else if ($v === "row")
                            $this->view = self::VIEW_ROW;
                        else
                            $this->view = self::VIEW_NONE;
                    } else if ($k === "minimal" && is_bool($v))
                        $this->minimal = $v;
                    else if ($k === "comparator")
                        $this->comparator = $v;
                }
        }
        if ($this->className === null)
            $this->className = "pl_" . $this->name;
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
