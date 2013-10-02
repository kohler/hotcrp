<?php
// column.php -- HotCRP helper class for list content
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Column {
    const VIEW_NONE = 0;
    const VIEW_COLUMN = 1;
    const VIEW_ROW = 2;
    const VIEWMASK = 3;

    const FOLDABLE = 16;

    public $name;
    public $cssname;
    public $foldable;
    public $view;
    public $sorter;
    public $minimal;

    public function __construct($name, $flags, $extra) {
        $this->name = $name;
	$this->cssname = defval($extra, "cssname", $name);
        $this->foldable = ($flags & self::FOLDABLE) != 0;
	$this->view = $flags & self::VIEWMASK;
        $this->sorter = defval($extra, "sorter", false);
	$this->minimal = defval($extra, "minimal", false);
    }
}
