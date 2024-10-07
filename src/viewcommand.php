<?php
// viewcommand.php -- HotCRP class for searching for papers
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ViewCommand {
    /** @var int
     * @readonly */
    public $flags;
    /** @var string
     * @readonly */
    public $keyword;
    /** @var ?ViewOptionlist
     * @readonly */
    public $view_options;
    /** @var ?SearchWord
     * @readonly */
    public $sword;

    const F_SHOW = 1;
    const F_HIDE = 2;
    const F_SORT = 4;
    const FM_ACTION = 7;

    const ORIGIN_REPORT = 0x000;
    const ORIGIN_DEFAULT_DISPLAY = 0x100;
    const ORIGIN_SESSION = 0x200;
    const ORIGIN_SEARCH = 0x300;
    const ORIGIN_REQUEST = 0x400;
    const ORIGIN_MAX = 0x500;
    const FM_ORIGIN = 0xF00;
    const ORIGIN_SHIFT = 8;


    /** @param int $flags
     * @param string $keyword
     * @param ?ViewOptionList $view_options
     * @param ?SearchWord $sword */
    function __construct($flags, $keyword, $view_options = null, $sword = null) {
        assert(($flags & ($flags - 1) & self::FM_ACTION) === 0);
        $this->flags = $flags;
        $this->keyword = $keyword;
        if ($view_options && !$view_options->is_empty()) {
            $this->view_options = $view_options;
        }
        $this->sword = $sword;
    }

    /** @param string $s
     * @param int $flags
     * @param ?SearchWord $sword
     * @return list<ViewCommand> */
    static function parse($s, $flags, $sword = null) {
        $keyword = null;
        $view_options = new ViewOptionList;
        $edit = $sort = false;

        $colon = strpos($s, ":");
        $as = $colon === false ? "show" : substr($s, 0, $colon);
        if ($as === "show") {
            $a = self::F_SHOW;
        } else if ($as === "sort") {
            $a = 0;
            $sort = true;
        } else if ($as === "edit") {
            $a = self::F_SHOW;
            $edit = true;
        } else if ($as === "showsort") {
            $a = self::F_SHOW;
            $sort = true;
        } else if ($as === "editsort") {
            $a = self::F_SHOW;
            $sort = $edit = true;
        } else if ($as === "hide") {
            $a = self::F_HIDE;
        } else if ($as === "viewoptions" || $as === "decor") {
            $a = 0;
        } else {
            $a = self::F_SHOW;
            $colon = false;
        }

        $d = $colon === false ? $s : substr($s, $colon + 1);
        if (str_starts_with($d, "[")) { /* XXX backward compat */
            $dlen = strlen($d);
            for ($ltrim = 1; $ltrim !== $dlen && ctype_space($d[$ltrim]); ++$ltrim) {
            }
            $rtrim = $dlen;
            if ($rtrim > $ltrim && $d[$rtrim - 1] === "]") {
                --$rtrim;
                while ($rtrim > $ltrim && ctype_space($d[$rtrim - 1])) {
                    --$rtrim;
                }
            }
            $d = substr($d, $ltrim, $rtrim - $ltrim);
        } else if (str_ends_with($d, "]")
                   && ($lbrack = strrpos($d, "[")) !== false) {
            $keyword = substr($d, 0, $lbrack);
            $d = substr($d, $lbrack + 1, strlen($d) - $lbrack - 2);
        }

        $splitter = new SearchParser($d);
        while ($splitter->skip_span(" \n\r\t\v\f,")) {
            $w = $splitter->shift_balanced_parens(" \n\r\t\v\f,");
            if ($w === "") {
                continue;
            } else if ($keyword === null) {
                $keyword = $w;
            } else if (($pair = ViewOptionList::parse_pair($w))) {
                $view_options->add($pair[0], $pair[1]);
            }
        }

        $keyword = $keyword ?? "";
        if ($sort && $keyword !== "") {
            if ($keyword[0] === "-") {
                $view_options->add("sort", "reverse");
            }
            if ($keyword[0] === "-" || $keyword[0] === "+") {
                $keyword = substr($keyword, 1);
            }
        }
        if (str_starts_with($keyword, "\"") && str_ends_with($keyword, "\"")) {
            $keyword = substr($keyword, 1, -1);
        }
        if ($keyword === "") {
            return [];
        }

        if ($edit) {
            $view_options->add("edit", true);
        }

        $svcs = [];
        if ($a !== 0 || !$sort) {
            $svcs[] = new ViewCommand($a | $flags, $keyword, $view_options, $sword);
        }
        if ($sort) {
            $svcs[] = new ViewCommand(self::F_SORT | $flags, $keyword, $view_options, $sword);
        }
        return $svcs;
    }

    /** @param string $str
     * @param int $flags
     * @return list<ViewCommand> */
    static function split_parse($str, $flags) {
        $res = [];
        foreach (SearchParser::split_balanced_parens($str) as $x) {
            foreach (self::parse($x, $flags) as $svc) {
                $res[] = $svc;
            }
        }
        return $res;
    }

    /** @param list<ViewCommand> $list
     * @return list<ViewCommand>
     * @suppress PhanAccessReadOnlyProperty */
    static function strip_sorts($list) {
        $res = [];
        foreach ($list as $svc) {
            if (($svc->flags & self::F_SORT) === 0)
                $res[] = $svc;
        }
        return $res;
    }


    /** @return bool */
    function is_show() {
        return ($this->flags & self::F_SHOW) !== 0;
    }

    /** @return bool */
    function is_hide() {
        return ($this->flags & self::F_HIDE) !== 0;
    }

    /** @return bool */
    function is_sort() {
        return ($this->flags & self::F_SORT) !== 0;
    }

    /** @return string */
    function unparse() {
        $s = (["viewoptions:", "show:", "hide:", null, "sort:"])[$this->flags & self::FM_ACTION];
        if (!ctype_alnum($this->keyword)
            && SearchParser::span_balanced_parens($this->keyword) !== strlen($this->keyword)) {
            $s .= "\"{$this->keyword}\"";
        } else {
            $s .= $this->keyword;
        }
        if ($this->view_options) {
            $s .= $this->view_options->unparse();
        }
        return $s;
    }
}
