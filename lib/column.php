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
    public $foldable;
    public $completable;
    public $view;
    public $comparator;
    public $minimal;
    public $embedded_header = false;
    public $is_folded = false;
    public $has_content = false;

    public function __construct($name, $flags, $extra) {
        $this->name = $name;
        $this->className = get($extra, "className", "pl_$name");
        $this->foldable = ($flags & self::FOLDABLE) != 0;
        $this->completable = ($flags & self::COMPLETABLE) != 0;
        $this->view = $flags & self::VIEWMASK;
        $this->comparator = get($extra, "comparator", false);
        $this->minimal = get($extra, "minimal", false);
    }
}

class ColumnErrors {
    public $error_html = array();
    public $priority = null;
    public function add($error_html, $priority) {
        if ($this->priority === null || $this->priority < $priority) {
            $this->error_html = array();
            $this->priority = $priority;
        }
        if ($this->priority == $priority)
            $this->error_html[] = $error_html;
    }
}
