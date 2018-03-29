<?php
// formatspec.php -- spec for HotCRP PDF analysis
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class FormatSpec {
    public $papersize = []; // [DIMEN, ...]
    public $pagelimit;      // [MIN, MAX]
    public $columns;        // NCOLUMNS
    public $textblock;      // [WIDTH, HEIGHT]
    public $bodyfontsize;   // [MIN, MAX, GRACE]
    public $bodylineheight; // [MIN, MAX, GRACE]
    public $quietpages;     // {ERRORTYPE => IGNOREARRAY}
    public $checkers;
    public $timestamp = 0;
    private $_is_banal_empty = true;

    function __construct(/* ... */) {
        foreach (func_get_args() as $str)
            $this->merge($str);
    }

    function merge($x, $timestamp = 0) {
        if (is_string($x) && substr($x, 0, 1) === "{")
            $x = json_decode($x);
        if ($x && is_string($x)) {
            if (($gt = strpos($x, ">")) !== false)
                $x = substr($x, 0, $gt);
            $x = explode(";", $x);
            $this->merge1("papersize", get($x, 0, ""));
            $this->merge1("pagelimit", get($x, 1, ""));
            $this->merge1("columns", get($x, 2));
            $this->merge1("textblock", get($x, 3));
            $this->merge1("bodyfontsize", get($x, 4));
            $this->merge1("bodylineheight", get($x, 5));
        } else if ($x && (is_object($x) || is_array($x))) {
            foreach ($x as $k => $v)
                $this->merge1($k, $v);
        }
        $this->_is_banal_empty = empty($this->papersize) && !$this->pagelimit
            && !$this->columns && !$this->textblock && !$this->bodyfontsize
            && !$this->bodylineheight;
        $this->timestamp = max($this->timestamp, $timestamp);
    }
    private function merge1($k, $v) {
        if ($k === "bodyleading")
            $k = "bodylineheight";
        if ($k === "papersize" && (is_string($v) || is_numeric($v))) {
            $this->papersize = [];
            foreach (explode(" OR ", $v) as $d)
                if (($dx = self::parse_dimen($d, 2)))
                    $this->papersize[] = $dx;
        } else if ($k === "pagelimit" && (is_string($v) || is_numeric($v))) {
            if (preg_match('/\A(\d+)(?:\s*(?:-|–)\s*(\d+))?\z/', $v, $m))
                $this->pagelimit = isset($m[2]) && $m[2] !== "" ? [$m[1], $m[2]] : [0, $m[1]];
        } else if ($k === "columns" && is_string($v))
            $this->columns = cvtint($v, null);
        else if ($k === "textblock" && is_string($v))
            $this->textblock = self::parse_dimen($v, 2);
        else if (($k === "bodyfontsize" || $k === "bodylineheight") && (is_string($v) || is_numeric($v)))
            $this->$k = self::parse_range($v);
        else
            $this->$k = $v;
    }

    function clear_banal() {
        $this->papersize = [];
        $this->pagelimit = $this->columns = $this->textblock =
            $this->bodyfontsize = $this->bodylineheight = null;
        $this->_is_banal_empty = true;
    }

    function is_empty() {
        return !$this->checkers && $this->is_banal_empty();
    }

    function is_banal_empty() {
        return $this->_is_banal_empty;
    }

    function is_checkable($pageno, $k) {
        if (!$this->quietpages || !isset($this->quietpages->$k))
            return true;
        $pages = $this->quietpages->$k;
        if (is_object($pages) || is_associative_array($pages))
            return !get($pages, $pageno, false);
        else if (is_array($pages))
            return !in_array($pageno, $pages);
        else if (is_int($pages))
            return $pages != $pageno;
        else
            return is_bool($pages) ? !$pages : true;
    }

    function unparse_key($k) {
        if (!$this->$k)
            return "";
        if ($k == "papersize")
            return join(" OR ", array_map(function ($x) { return self::unparse_dimen($x, "basic"); }, $this->papersize));
        if ($k == "pagelimit")
            return $this->pagelimit[0] ? $this->pagelimit[0] . "-" . $this->pagelimit[1] : $this->pagelimit[1];
        if ($k == "columns")
            return $this->columns;
        if ($k == "textblock")
            return self::unparse_dimen($this->textblock, "basic");
        if ($k == "bodyfontsize")
            return self::unparse_range($this->bodyfontsize);
        if ($k == "bodylineheight")
            return self::unparse_range($this->bodylineheight);
        return "";
    }

    function unparse() {
        if ($this->checkers) {
            $a = [];
            foreach (get_object_vars($this) as $k => $v)
                if (substr($k, 0, 1) !== "_"
                    && $v !== null && $v !== "" && (!empty($v) || $k !== "papersize"))
                    $a[$k] = $v;
            return empty($a) ? "" : json_encode($a);
        } else {
            return $this->unparse_banal();
        }
    }

    function unparse_banal() {
        $x = array_fill(0, 6, "");
        foreach (["papersize", "pagelimit", "columns", "textblock", "bodyfontsize", "bodylineheight"] as $i => $k)
            $x[$i] = $this->unparse_key($k);
        while (!empty($x) && !$x[count($x) - 1])
            array_pop($x);
        return join(";", $x);
    }


    static function parse_range($s) {
        $x1 = $x2 = 0;
        if (preg_match(',\A([\d.]+)(?:\s*(?:-|–)\s*([\d.]+))?(?:\s*(?:[dD]|Δ|\+\/?-|±)\s*([\d.]+))?\z,', $s, $m)
            && ($x0 = cvtnum($m[1], null)) !== null
            && (!isset($m[2]) || $m[2] === "" || ($x1 = cvtnum($m[2], null)) !== null)
            && (!isset($m[3]) || $m[3] === "" || ($x2 = cvtnum($m[3], null)) !== null))
            return [$x0, $x1, $x2];
        return null;
    }

    static private function unparse_range($r) {
        if ($r[1] && $r[2])
            return "$r[0]-$r[1]±$r[2]";
        else if ($r[2])
            return "$r[0]±$r[2]";
        else if ($r[1])
            return "$r[0]-$r[1]";
        else
            return $r[0];
    }

    static function parse_dimen($text, $ndimen = -1) {
        // replace \xC2\xA0 (utf-8 for U+00A0 NONBREAKING SPACE) with ' '
        $text = trim(str_replace("\xC2\xA0", " ", strtolower($text)));
        $n = $text;
        $a = array();
        $unit = array();
        while (preg_match('/^\s*(\d+\.?\d*|\.\d+)\s*(in?|cm?|mm|pt|)\s*(.*)$/', $n, $m)) {
            $a[] = $m[1];
            if ($m[2] == "i" || $m[2] == "in")
                $unit[] = 72;
            else if ($m[2] == "c" || $m[2] == "cm")
                $unit[] = 72 * 0.393700787;
            else if ($m[2] == "mm")
                $unit[] = 72 * 0.0393700787;
            else if ($m[2] == "pt")
                $unit[] = 1;
            else
                $unit[] = 0;
            if ($m[3] == "") {  // end of string
                // fail on bad number of dimensions
                if ($ndimen > 0 && count($a) != $ndimen)
                    return false;

                // spread known units to unknown positions, using two passes
                $unitrep = 0;
                for ($i = count($unit) - 1; $i >= 0; --$i)
                    $unit[$i] = $unitrep = ($unit[$i] ? $unit[$i] : $unitrep);
                $unitrep = 0;
                for ($i = 0; $i < count($unit); ++$i)
                    $unit[$i] = $unitrep = ($unit[$i] ? $unit[$i] : $unitrep);

                // multiply dimensions by units, fail on unknown units
                for ($i = 0; $i < count($unit); ++$i)
                    if ($unit[$i])
                        $a[$i] *= $unit[$i];
                    else
                        return false;

                return (count($a) == 1 ? $a[0] : $a);
            } else if ($m[3][0] == "x")
                $n = substr($m[3], 1);
            else if ($m[3][0] == 0xC3 && $m[3][1] == 0x97)
                // \xC3\x97 is utf-8 for MULTIPLICATION SIGN
                $n = substr($m[3], 2);
            else
                return false;
        }
        if ($text == "letter")
            return self::parse_dimen("8.5in x 11in", $ndimen);
        else if ($text == "a4")
            return self::parse_dimen("210mm x 297mm", $ndimen);
        else
            return false;
    }

    static function unparse_dimen($n, $to = null) {
        if (is_array($n) && count($n) == 2 && ($to == "basic" || $to == "paper")) {
            if (abs($n[0] - 612) <= 5 && abs($n[1] - 792) <= 5)
                return $to == "basic" ? "letter" : "letter paper (8.5in x 11in)";
            else if (abs($n[0] - 595.27) <= 5 && abs($n[1] - 841.89) <= 5)
                return $to == "basic" ? "A4" : "A4 paper (210mm x 297mm)";
        }
        if (is_array($n)) {
            // \xC2\xA0 is utf-8 for U+00A0 NONBREAKING SPACE
            $ex = $to == "paper" ? "\xC2\xA0x\xC2\xA0" : " x ";
            $t = "";
            foreach ($n as $v)
                $t .= ($t == "" ? "" : $ex) . self::unparse_dimen($v, $to);
            return $t;
        }
        if ($to == "basic" || $to == "paper")
            $to = null;
        if (!$to && $n < 18)
            $to = "pt";
        else if (!$to && abs($n - 18 * (int) (($n + 9) / 18)) <= 0.5)
            $to = "in";
        else if (!$to)
            $to = "mm";
        if ($to == "pt")
            return $n . $to;
        else if ($to == "in" || $to == "i")
            return ((int) (100 * $n / 72 + 0.5) / 100) . $to;
        else if ($to == "cm")
            return ((int) (100 * $n / 72 / 0.393700787 + 0.5) / 100) . $to;
        else if ($to == "mm")
            return (int) ($n / 72 / 0.0393700787 + 0.5) . $to;
        else
            return "??" . $to;
    }
}

interface FormatChecker {
    function error_kinds(FormatSpec $spec);
    function check(CheckFormat $cf, FormatSpec $spec, PaperInfo $prow, $doc);
    function report(CheckFormat $cf, FormatSpec $spec, PaperInfo $prow, $doc);
}
