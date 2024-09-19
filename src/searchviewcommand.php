<?php
// searchviewcommand.php -- HotCRP class for searching for papers
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class SearchViewCommand {
    /** @var string
     * @readonly */
    public $command;
    /** @var int */
    private $flags;
    /** @var string */
    public $keyword;
    /** @var list<string> */
    public $decorations = [];
    /** @var ?int
     * @readonly */
    public $kwpos1;
    /** @var ?int
     * @readonly */
    public $pos1;
    /** @var ?int
     * @readonly */
    public $pos2;
    /** @var ?SearchStringContext
     * @readonly */
    public $string_context;
    /** @var bool */
    private $_complete = false;

    // NB see show_action()
    const AF_SHOW = 1;
    const AF_HIDE = 2;
    const AF_EDIT = 4;
    const AF_SORT = 8;

    /** @param string $command
     * @param ?SearchWord $sword */
    function __construct($command, $sword = null) {
        $this->command = $command;
        if ($sword) {
            $this->kwpos1 = $sword->kwpos1;
            $this->pos1 = $sword->pos1;
            $this->pos2 = $sword->pos2;
            $this->string_context = $sword->string_context;
        }
    }

    /** @return null|'show'|'hide'|'edit' */
    function show_action() {
        assert($this->flags !== null);
        return [null, "show", "hide", null, null, "edit"][$this->flags & 7];
    }

    /** @return bool */
    function sort_action() {
        assert($this->flags !== null);
        return ($this->flags & self::AF_SORT) !== 0;
    }

    /** @return bool */
    function nondefault_sort_action() {
        assert($this->flags !== null);
        return ($this->flags & self::AF_SORT) !== 0
            && ($this->keyword !== "id" || !empty($this->decorations));
    }

    /** @suppress PhanAccessReadOnlyProperty */
    private function complete() {
        $this->_complete = true;
        $colon = strpos($this->command, ":");

        $action = $colon === false ? "show" : substr($this->command, 0, $colon);
        if ($action === "show") {
            $this->flags = self::AF_SHOW;
        } else if ($action === "sort") {
            $this->flags = self::AF_SORT;
        } else if ($action === "showsort") {
            $this->flags = self::AF_SHOW | self::AF_SORT;
        } else if ($action === "editsort") {
            $this->flags = self::AF_SHOW | self::AF_EDIT | self::AF_SORT;
        } else if ($action === "edit") {
            $this->flags = self::AF_SHOW | self::AF_EDIT;
        } else if ($action === "hide") {
            $this->flags = self::AF_HIDE;
        } else {
            $this->flags = self::AF_SHOW;
            $colon = false;
        }

        $d = $colon === false ? $this->command : substr($this->command, $colon + 1);
        $keyword = null;
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
            if ($this->pos1 !== null) {
                $this->pos1 += $ltrim;
            }
            if ($this->pos2 !== null) {
                $this->pos2 -= $dlen - $rtrim;
            }
            $d = substr($d, $ltrim, $rtrim - $ltrim);
        } else if (str_ends_with($d, "]")
                   && ($lbrack = strrpos($d, "[")) !== false) {
            $keyword = substr($d, 0, $lbrack);
            $d = substr($d, $lbrack + 1, strlen($d) - $lbrack - 2);
        }

        if ($d !== "") {
            $splitter = new SearchParser($d);
            while ($splitter->skip_span(" \n\r\t\v\f,")) {
                $this->decorations[] = $splitter->shift_balanced_parens(" \n\r\t\v\f,");
            }
        }

        $keyword = $keyword ?? array_shift($this->decorations) ?? "";
        if ($keyword !== "") {
            if ($keyword[0] === "-") {
                array_unshift($this->decorations, "reverse");
            }
            if ($keyword[0] === "-" || $keyword[0] === "+") {
                $keyword = substr($keyword, 1);
                if ($this->pos1 !== null) {
                    ++$this->pos1;
                }
            }
        }
        $this->keyword = $keyword;
    }

    /** @param iterable<string>|iterable<SearchViewCommand> $list
     * @return list<SearchViewCommand> */
    static function analyze($list) {
        $res = [];
        foreach ($list as $x) {
            $svc = is_string($x) ? new SearchViewCommand($x) : $x;
            if (!$svc->_complete) {
                $svc->complete();
            }
            if ($svc->keyword !== "") {
                $res[] = $svc;
            }
        }
        return $res;
    }

    /** @param list<SearchViewCommand> $list
     * @return list<SearchViewCommand>
     * @suppress PhanAccessReadOnlyProperty */
    static function strip_sorts($list) {
        $res = [];
        foreach ($list as $svc) {
            if (!$svc->_complete && strpos($svc->command, "sort") !== false) {
                $svc->complete();
            }
            if (!$svc->_complete || ($svc->flags & self::AF_SORT) === 0) {
                $res[] = $svc;
            } else if ($svc->flags !== self::AF_SORT) {
                $colon = strpos($svc->command, ":");
                assert($colon !== false);
                $svcx = new SearchViewCommand($svc->show_action() . substr($svc->command, $colon));
                $svcx->kwpos1 = $svc->kwpos1;
                $svcx->pos1 = $svc->pos1;
                $svcx->pos2 = $svc->pos2;
                $svcx->string_context = $svc->string_context;
                $res[] = $svcx;
            }
        }
        return $res;
    }
}
