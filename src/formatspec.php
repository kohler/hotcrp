<?php
// formatspec.php -- spec for HotCRP PDF analysis
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class FormatSpec {
    /** @var list<array{float,float}> */
    public $papersize = []; // [DIMEN, ...]
    /** @var ?array{int,int} */
    public $pagelimit;      // [MIN, MAX]
    /** @var ?array{int,int} */
    public $wordlimit;      // [MIN, MAX]
    /** @var ?bool */
    public $unlimitedref;
    /** @var ?int */
    public $columns;        // NCOLUMNS
    /** @var ?array{int,int} */
    public $textblock;      // [WIDTH, HEIGHT]
    /** @var ?array{int|float,int|float|null,int|float|null} */
    public $bodyfontsize;   // [MIN, MAX, GRACE]
    /** @var ?array{int|float,int|float|null,int|float|null} */
    public $bodylineheight; // [MIN, MAX, GRACE]
    public $quietpages;     // {ERRORTYPE => IGNOREARRAY}
    /** @var list<string> */
    public $checkers = [];
    /** @var int */
    public $timestamp = 0;
    /** @var bool */
    private $_is_banal_empty = true;

    static private $props1 = [
        "papersize", "pagelimit", "columns", "textblock",
        "bodyfontsize", "bodylineheight", "unlimitedref"
    ];
    static private $props2 = [
        "wordlimit"
    ];


    function __construct(...$args) {
        foreach ($args as $x) {
            $this->merge($x);
        }
    }

    function merge($x, $timestamp = 0) {
        if (is_string($x)) {
            if (str_ends_with($x, ">-zoom=1")) { // XXX old settings
                $x = substr($x, 0, -8);
            }
            $i = $p = 0;
            $l = strlen($x);
            while ($p !== $l && $x[$p] !== "{") {
                $n = strpos($x, ";", $p);
                $n = $n !== false ? $n : $l;
                if ($i !== count(self::$props1)) {
                    $this->merge1(self::$props1[$i], substr($x, $p, $n - $p));
                    ++$i;
                }
                $p = $n !== $l ? $n + 1 : $n;
            }
            if ($p !== $l) {
                $x = json_decode(substr($x, $p), true);
            }
        }
        if ($x && (is_object($x) || is_array($x))) {
            foreach ($x as $k => $v) {
                $this->merge1($k, $v);
            }
        }
        $this->_is_banal_empty = empty($this->papersize) && !$this->pagelimit
            && !$this->wordlimit && !$this->columns && !$this->textblock
            && !$this->bodyfontsize && !$this->bodylineheight;
        $this->timestamp = max($this->timestamp, $timestamp);
    }

    private function merge1($k, $v) {
        if ($k === "bodyleading") {
            $k = "bodylineheight";
        }
        if ($k === "papersize" && (is_string($v) || is_numeric($v))) {
            $this->papersize = [];
            foreach (explode(" OR ", $v) as $d) {
                if (($dx = self::parse_dimen2($d))) {
                    $this->papersize[] = $dx;
                }
            }
        } else if ($k === "pagelimit" && (is_string($v) || is_numeric($v))) {
            if (preg_match('/\A(\d+)(?:\s*(?:-|–)\s*(\d+))?\z/', $v, $m)) {
                if (isset($m[2]) && $m[2] !== "") {
                    $this->pagelimit = [(int) $m[1], (int) $m[2]];
                } else {
                    $this->pagelimit = [0, (int) $m[1]];
                }
            }
        } else if ($k === "wordlimit" && (is_string($v) || is_numeric($v))) {
            if (preg_match('/\A(\d+)(?:\s*(?:-|–)\s*(\d+))?\z/', $v, $m)) {
                if (isset($m[2]) && $m[2] !== "") {
                    $this->wordlimit = [(int) $m[1], (int) $m[2]];
                } else {
                    $this->wordlimit = [0, (int) $m[1]];
                }
            }
        } else if ($k === "unlimitedref" && (is_string($v) || is_bool($v))) {
            $this->unlimitedref = !!$v;
        } else if ($k === "columns" && is_string($v)) {
            $this->columns = stoi($v);
        } else if ($k === "textblock" && is_string($v)) {
            if (($dx = self::parse_dimen2($v))) {
                $this->textblock = $dx;
            }
        } else if (($k === "bodyfontsize" || $k === "bodylineheight")
                   && (is_string($v) || is_numeric($v))) {
            $this->$k = self::parse_range($v);
        } else {
            $this->$k = $v;
        }
    }

    function merge_banal() {
        if (($ts = @filemtime(SiteLoader::find("src/banal"))) !== false) {
            $this->timestamp = max($ts, $this->timestamp);
        }
    }

    function clear_banal() {
        $this->papersize = [];
        $this->pagelimit = $this->wordlimit = $this->unlimitedref =
            $this->columns = $this->textblock =
            $this->bodyfontsize = $this->bodylineheight = null;
        $this->_is_banal_empty = true;
    }

    function is_empty() {
        return empty($this->checkers) && $this->is_banal_empty();
    }

    /** @return bool */
    function is_banal_empty() {
        return $this->_is_banal_empty;
    }

    /** @param int $pageno
     * @param string $k
     * @return bool */
    function is_checkable($pageno, $k) {
        if (!$this->quietpages || !isset($this->quietpages->$k)) {
            return true;
        }
        $pages = $this->quietpages->$k;
        if (is_object($pages)) {
            $spageno = (string) $pageno;
            return $pages->$spageno ?? false;
        } else if (is_array($pages)) {
            if (is_associative_array($pages)) {
                return $pages[$pageno] ?? false;
            } else {
                return !in_array($pageno, $pages);
            }
        } else if (is_int($pages)) {
            return $pages != $pageno;
        } else {
            return is_bool($pages) ? !$pages : true;
        }
    }

    /** @param string $k
     * @return string */
    function unparse_key($k) {
        if ($k === "papersize" && $this->papersize) {
            return join(" OR ", array_map(function ($x) { return self::unparse_dimen($x, "basic"); }, $this->papersize));
        } else if ($k === "pagelimit" && $this->pagelimit) {
            return $this->pagelimit[0] ? $this->pagelimit[0] . "-" . $this->pagelimit[1] : (string) $this->pagelimit[1];
        } else if ($k === "wordlimit" && $this->wordlimit) {
            return $this->wordlimit[0] ? $this->wordlimit[0] . "-" . $this->wordlimit[1] : (string) $this->wordlimit[1];
        } else if ($k === "columns" && $this->columns) {
            return (string) $this->columns;
        } else if ($k === "textblock" && $this->textblock) {
            return self::unparse_dimen($this->textblock, "basic");
        } else if ($k === "bodyfontsize" && $this->bodyfontsize) {
            return self::unparse_range($this->bodyfontsize);
        } else if ($k === "bodylineheight" && $this->bodylineheight) {
            return self::unparse_range($this->bodylineheight);
        } else if ($k === "unlimitedref" && $this->unlimitedref) {
            return "1";
        } else {
            return "";
        }
    }

    /** @return string */
    function unparse() {
        if ($this->checkers) {
            $a = [];
            foreach (get_object_vars($this) as $k => $v) {
                if (substr($k, 0, 1) !== "_"
                    && $v !== null && $v !== "" && (!empty($v) || $k !== "papersize"))
                    $a[$k] = $v;
            }
            return empty($a) ? "" : json_encode($a);
        } else {
            return $this->unparse_banal();
        }
    }

    /** @return string */
    function unparse_banal() {
        $x = array_fill(0, 7, "");
        foreach (self::$props1 as $i => $k) {
            $x[$i] = $this->unparse_key($k);
        }
        while (!empty($x) && !$x[count($x) - 1]) {
            array_pop($x);
        }
        $j = [];
        foreach (self::$props2 as $k) {
            if ($this->$k)
                $j[$k] = $this->unparse_key($k);
        }
        if (!empty($j)) {
            $x[] = json_encode($j);
        }
        return join(";", $x);
    }


    /** @param string $s
     * @return ?array{int|float,int|float|null,int|float|null} */
    static function parse_range($s) {
        $x1 = $x2 = 0;
        if (preg_match('/\A([\d.]+)(?:\s*(?:-|–)\s*([\d.]+))?(?:\s*(?:[dD]|Δ|\+\/?-|±)\s*([\d.]+))?\z/', $s, $m)
            && ($x0 = cvtnum($m[1], null)) !== null
            && (!isset($m[2]) || $m[2] === "" || ($x1 = cvtnum($m[2], null)) !== null)
            && (!isset($m[3]) || $m[3] === "" || ($x2 = cvtnum($m[3], null)) !== null)) {
            return [$x0, $x1, $x2];
        } else {
            return null;
        }
    }

    /** @param array{int|float,int|float|null,int|float|null} $r
     * @return string */
    static private function unparse_range($r) {
        if ($r[1] && $r[2]) {
            return "$r[0]-$r[1]±$r[2]";
        } else if ($r[2]) {
            return "$r[0]±$r[2]";
        } else if ($r[1]) {
            return "$r[0]-$r[1]";
        } else {
            return (string) $r[0];
        }
    }

    /** @param string $text
     * @return false|float|list<float> */
    static function parse_dimen($text) {
        // replace \xC2\xA0 (utf-8 for U+00A0 NONBREAKING SPACE) with ' '
        $text = trim(str_replace("\xC2\xA0", " ", strtolower($text)));
        $n = $text;
        $a = [];
        $unit = [];
        while (preg_match('/^\s*(\d+\.?\d*|\.\d+)\s*("|″|in?|cm?|mm|pt|)\s*(.*)$/', $n, $m)) {
            $a[] = $m[1];
            if ($m[2] === "i" || $m[2] === "in" || $m[2] === "\"" || $m[2] === "″") {
                $unit[] = 72;
            } else if ($m[2] === "c" || $m[2] === "cm") {
                $unit[] = 72 * 0.393700787;
            } else if ($m[2] === "mm") {
                $unit[] = 72 * 0.0393700787;
            } else if ($m[2] === "pt") {
                $unit[] = 1;
            } else {
                $unit[] = 0;
            }
            if ($m[3] === "") {  // end of string
                // spread known units to unknown positions, using two passes
                $unitrep = 0;
                for ($i = count($unit) - 1; $i >= 0; --$i) {
                    $unit[$i] = $unitrep = ($unit[$i] ? $unit[$i] : $unitrep);
                }
                $unitrep = 0;
                for ($i = 0; $i < count($unit); ++$i) {
                    $unit[$i] = $unitrep = ($unit[$i] ? $unit[$i] : $unitrep);
                }

                // multiply dimensions by units, fail on unknown units
                for ($i = 0; $i < count($unit); ++$i) {
                    if ($unit[$i]) {
                        $a[$i] *= $unit[$i];
                    } else {
                        return false;
                    }
                }

                return (count($a) == 1 ? $a[0] : $a);
            } else if ($m[3][0] === "x") {
                $n = substr($m[3], 1);
            } else if ($m[3][0] == 0xC3 && $m[3][1] == 0x97) {
                // \xC3\x97 is utf-8 for MULTIPLICATION SIGN
                $n = substr($m[3], 2);
            } else {
                return false;
            }
        }
        if ($text === "letter") {
            return self::parse_dimen("8.5in x 11in");
        } else if ($text === "a4") {
            return self::parse_dimen("210mm x 297mm");
        } else {
            return false;
        }
    }

    /** @param string $text
     * @return false|list<float,float> */
    static function parse_dimen2($text) {
        $a = self::parse_dimen($text);
        return is_array($a) && count($a) === 2 ? $a : false;
    }

    /** @param int|float|list<int|float> $n
     * @param ?string $to */
    static function unparse_dimen($n, $to = null) {
        if (is_array($n) && count($n) == 2 && ($to == "basic" || $to == "paper")) {
            if (abs($n[0] - 612) <= 5 && abs($n[1] - 792) <= 5) {
                return $to == "basic" ? "letter" : "letter paper (8.5in x 11in)";
            } else if (abs($n[0] - 595.27) <= 5 && abs($n[1] - 841.89) <= 5) {
                return $to == "basic" ? "A4" : "A4 paper (210mm x 297mm)";
            }
        }
        if (is_array($n)) {
            // \xC2\xA0 is utf-8 for U+00A0 NONBREAKING SPACE
            $ex = $to === "paper" ? "\xC2\xA0x\xC2\xA0" : " x ";
            $t = "";
            foreach ($n as $v) {
                $t .= ($t == "" ? "" : $ex) . self::unparse_dimen($v, $to);
            }
            return $t;
        }
        if ($to === "basic" || $to === "paper" || !$to) {
            if ($n < 18) {
                $to = "pt";
            } else if (abs($n - 18 * (int) (($n + 9) / 18)) <= 0.5) {
                $to = "in";
            } else {
                $to = "mm";
            }
        }
        if ($to === "pt") {
            return $n . $to;
        } else if ($to === "in" || $to === "i") {
            return ((int) (100 * $n / 72 + 0.5) / 100) . $to;
        } else if ($to === "cm") {
            return ((int) (100 * $n / 72 / 0.393700787 + 0.5) / 100) . $to;
        } else if ($to === "mm") {
            return (int) ($n / 72 / 0.0393700787 + 0.5) . $to;
        } else {
            return "??" . $to;
        }
    }
}

interface FormatChecker {
    /** @return list<string> */
    function known_fields(FormatSpec $spec);
    /** @return void */
    function prepare(CheckFormat $cf, FormatSpec $spec);
    /** @return void */
    function check(CheckFormat $cf, FormatSpec $spec, DocumentInfo $doc);
    /** @return ?string */
    function report(CheckFormat $cf, FormatSpec $spec, DocumentInfo $doc);
}
