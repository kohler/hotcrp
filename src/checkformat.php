<?php
// checkformat.php -- HotCRP/banal integration
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CheckFormatSpec {
    public $papersize;      // [DIMEN, ...]
    public $pagelimit;      // [MIN, MAX]
    public $columns;        // NCOLUMNS
    public $textblock;      // [WIDTH, HEIGHT]
    public $bodyfontsize;   // [MIN, MAX, GRACE]
    public $bodyleading;    // [MIN, MAX, GRACE]
    public $banal_args = false;

    public function __construct($str) {
        if (($gt = strpos($str, ">")) !== false) {
            $this->banal_args = substr($str, $gt + 1);
            $str = substr($str, 0, $gt);
        }

        $x = explode(";", $str);
        $this->papersize = [];
        foreach (explode(" OR ", get($x, 0, "")) as $d)
            if (($dx = CheckFormat::parse_dimen($d, 2)))
                $this->papersize[] = $dx;
        if (preg_match('/\A(\d+)(?:-(\d+))?\z/', get($x, 1, ""), $m))
            $this->pagelimit = isset($m[2]) && $m[2] !== "" ? [$m[1], $m[2]] : [0, $m[1]];
        $this->columns = cvtint(get($x, 2), null);
        $this->textblock = CheckFormat::parse_dimen(get($x, 3), 2);
        $this->bodyfontsize = self::parse_range(get($x, 4));
        $this->bodyleading = self::parse_range(get($x, 5));
    }

    static private function parse_range($s) {
        if (preg_match(',\A([\d.]+)(?:-([\d.]+))?(?:(?:[dD]|Δ|\+\/?-|±)\s*([\d.]+))?\z,', $s, $m)
            && ($x0 = cvtnum($m[1], null)) !== null)
            return [$x0, cvtnum(get($m, 2, 0), 0), cvtnum(get($m, 3, 0), 0)];
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

    public function unparse_key($k) {
        if (!$this->$k)
            return "";
        if ($k == "papersize")
            return join(" OR ", array_map(function ($x) { return CheckFormat::unparse_dimen($x, "basic"); }, $this->papersize));
        if ($k == "pagelimit")
            return $this->pagelimit[0] ? $this->pagelimit[0] . "-" . $this->pagelimit[1] : $this->pagelimit[1];
        if ($k == "columns")
            return $this->columns;
        if ($k == "textblock")
            return CheckFormat::unparse_dimen($this->textblock, "basic");
        if ($k == "bodyfontsize")
            return self::unparse_range($this->bodyfontsize);
        if ($k == "bodyleading")
            return self::unparse_range($this->bodyleading);
        return "";
    }

    public function unparse() {
        $x = array_fill(0, 6, "");
        foreach (["papersize", "pagelimit", "columns", "textblock", "bodyfontsize", "bodyleading"] as $i => $k)
            $x[$i] = $this->unparse_key($k);
        while (!empty($x) && !$x[count($x) - 1])
            array_pop($x);
        return join(";", $x) . ($this->banal_args ? ">" . $this->banal_args : "");
    }
}

class CheckFormat {
    const ERR_PAPERSIZE = 1;
    const ERR_PAGELIMIT = 2;
    const ERR_COLUMNS = 4;
    const ERR_TEXTBLOCK = 8;
    const ERR_BODYFONTSIZE = 16;
    const ERR_BODYLEADING = 32;

    const STATUS_NONE = 0;
    const STATUS_PROBLEM = 1;
    const STATUS_OK = 2;

    public static $error_types = array(self::ERR_PAPERSIZE => "papersize",
                           self::ERR_PAGELIMIT => "pagelimit",
                           self::ERR_COLUMNS => "columns",
                           self::ERR_TEXTBLOCK => "textblock",
                           self::ERR_BODYFONTSIZE => "bodyfontsize",
                           self::ERR_BODYLEADING => "bodyleading");

    public $msgs = array();
    public $errors = 0;
    public $pages = 0;
    public $status = 0;
    public $banal_stdout;
    public $banal_sterr;
    public $banal_status;
    private $tempdir = null;
    public $no_run = false;

    function __construct($no_run = false) {
        $this->no_run = $no_run;
    }

    public static function parse_dimen($text, $ndimen = -1) {
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

    public static function unparse_dimen($n, $to = null) {
        if (is_array($n) && count($n) == 2 && ($to == "basic" || $to == "paper")) {
            if (abs($n[0] - 612) <= 5 && abs($n[1] - 792) <= 5)
                return $to == "basic" ? "letter" : "letter paper (8.5in x 11in)";
            else if (abs($n[0] - 595.27) <= 5 && abs($n[1] - 841.89) <= 5)
                return $to == "basic" ? "A4" : "A4 paper (210mm x 297mm)";
        }
        if ($to == "basic" || $to == "paper")
            $to = null;
        if (is_array($n)) {
            $t = "";
            foreach ($n as $v)
                // \xC2\xA0 is utf-8 for U+00A0 NONBREAKING SPACE
                $t .= ($t == "" ? "" : "\xC2\xA0x\xC2\xA0") . self::unparse_dimen($v, $to);
            return $t;
        }
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

    function msg($type, $what) {
        $this->msgs[] = array($type, $what);
        return self::STATUS_NONE;
    }

    static private function split_spec($spec) {
        if (($gtpos = strpos($spec, ">")) !== false)
            return [substr($spec, 0, $gtpos), substr($spec, $gtpos + 1)];
        else
            return [$spec, null];
    }

    private function run_banal($filename, $args) {
        global $Opt;
        if (isset($Opt["pdftohtml"]))
            putenv("PHP_PDFTOHTML=" . $Opt["pdftohtml"]);
        $banal_run = "perl src/banal -no_app -json ";
        if ($args)
            $banal_run .= $args . " ";
        $pipes = null;
        $banal_proc = proc_open($banal_run . escapeshellarg($filename),
                                [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
        $this->banal_stdout = stream_get_contents($pipes[1]);
        $this->banal_stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->banal_status = proc_close($banal_proc);
        return json_decode($this->banal_stdout);
    }

    private function check_banal_json($bj, $spec) {
        $this->status = self::STATUS_NONE;
        if (!$bj || !isset($bj->pages) || !isset($bj->papersize)
            || !is_array($bj->pages) || !is_array($bj->papersize)
            || count($bj->papersize) != 2)
            return $this->msg("error", "Analysis failure: no pages or paper size.");

        // report results
        $pie = array();

        // paper size
        if ($spec->papersize) {
            $papersize = $bj->papersize;
            $psdefs = array();
            $ok = false;
            foreach ($spec->papersize as $p)
                if (abs($p[0] - $papersize[1]) < 9
                    && abs($p[1] - $papersize[0]) < 9) {
                    $ok = true;
                    break;
                }
            if (!$ok) {
                $pie[] = "Paper size mismatch: expected " . commajoin(array_map(function ($d) { CheckFormat::unparse_dimen($d, "paper"); }, $spec->papersize), "or") . ", got " . self::unparse_dimen([$papersize[1], $papersize[0]], "paper");
                $this->errors |= self::ERR_PAPERSIZE;
            }
        }

        // number of pages
        $minpages = $maxpages = null;
        if ($spec->pagelimit) {
            if (count($bj->pages) < $spec->pagelimit[0]) {
                $pie[] = "Too few pages: expected " . plural($spec->pagelimit[0], "or more page") . ", found " . count($bj->pages);
                $this->errors |= self::ERR_PAGELIMIT;
            } else if (count($bj->pages) > $spec->pagelimit[1]) {
                $pie[] = "Too many pages: the limit is " . plural($spec->pagelimit[1], "page") . ", found " . count($bj->pages);
                $this->errors |= self::ERR_PAGELIMIT;
            }
        }
        $this->pages = count($bj->pages);

        // number of columns
        if ($spec->columns) {
            $px = array();
            $ncol = get($bj, "columns", 0);
            foreach ($bj->pages as $i => $pg)
                if (($pp = cvtint(get($pg, "columns", $ncol))) > 0
                    && $pp != $spec->columns
                    && defval($pg, "pagetype", "body") == "body")
                    $px[] = $i + 1;
            if (count($px) > ($maxpages ? max(0, $maxpages * 0.75) : 0)) {
                $pie[] = "Wrong number of columns: expected " . plural($spec->columns, "column") . ", different on " . pluralx($px, "page") . " " . numrangejoin($px);
                $this->errors |= self::ERR_COLUMNS;
            }
        }

        // text block
        if ($spec->textblock) {
            $px = array();
            $py = array();
            $maxx = $maxy = 0;
            $tb = get($bj, "textblock");
            foreach ($bj->pages as $i => $pg)
                if (($pp = defval($pg, "textblock", $tb)) && is_array($pp)) {
                    if ($pp[1] - $spec->textblock[0] >= 9) {
                        $px[] = $i + 1;
                        $maxx = max($maxx, $pp[1]);
                    }
                    if ($pp[0] - $spec->textblock[1] >= 9) {
                        $py[] = $i + 1;
                        $maxy = max($maxy, $pp[0]);
                    }
                }
            if (count($px) > 0) {
                $pie[] = "Margins too small: text width exceeds "
                    . self::unparse_dimen($spec->textblock[0]) . " by "
                    . (count($px) > 1 ? "up to " : "")
                    . ((int) (100 * $maxx / $spec->textblock[0] + .5) - 100)
                    . "% on " . pluralx($px, "page") . " "
                    . numrangejoin($px);
                $this->errors |= self::ERR_TEXTBLOCK;
            }
            if (count($py) > 0) {
                $pie[] = "Margins too small: text height exceeds "
                    . self::unparse_dimen($spec->textblock[1]) . " by "
                    . (count($py) > 1 ? "up to " : "")
                    . ((int) (100 * $maxy / $spec->textblock[1] + .5) - 100)
                    . "% on " . pluralx($py, "page") . " "
                    . numrangejoin($py);
                $this->errors |= self::ERR_TEXTBLOCK;
            }
        }

        // font size
        if ($spec->bodyfontsize) {
            $lopx = $hipx = [];
            $bodypages = 0;
            $minval = 1000;
            $maxval = 0;
            $bfs = get($bj, "bodyfontsize");
            foreach ($bj->pages as $i => $pg)
                if (get($pg, "pagetype", "body") == "body") {
                    $pp = cvtnum(get($pg, "bodyfontsize", $bfs));
                    ++$bodypages;
                    if ($pp > 0 && $pp < $spec->bodyfontsize[0] - $spec->bodyfontsize[2]) {
                        $lopx[] = $i + 1;
                        $minval = min($minval, $pp);
                    }
                    if ($pp > 0 && $spec->bodyfontsize[1] > 0
                        && $pp > $spec->bodyfontsize[1] + $spec->bodyfontsize[2]) {
                        $hipx[] = $i + 1;
                        $maxval = max($maxval, $pp);
                    }
                }
            if ($bodypages == 0)
                $pie[] = "Warning: No pages seemed to contain body text; results may be off";
            else if ($bodypages <= 0.5 * count($bj->pages))
                $pie[] = "Warning: Only " . plural($bodypages, "page") . " seemed to contain body text; results may be off";
            if (!empty($lopx) || !empty($hipx)) {
                if (!empty($lopx))
                    $pie[] = "Body font too small: minimum {$spec->bodyfontsize[0]}pt, saw values as small as {$minval}pt on " . pluralx($lopx, "page") . " " . numrangejoin($lopx);
                if (!empty($hipx))
                    $pie[] = "Body font too large: maximum {$spec->bodyfontsize[1]}pt, saw values as large as {$maxval}pt on " . pluralx($hipx, "page") . " " . numrangejoin($hipx);
                $this->errors |= self::ERR_BODYFONTSIZE;
            }
        }

        // leading
        if ($spec->bodyleading) {
            $lopx = $hipx = [];
            $minval = 1000;
            $maxval = 0;
            $l = get($bj, "leading");
            foreach ($bj->pages as $i => $pg)
                if (get($pg, "pagetype", "body") == "body") {
                    $pp = cvtnum(get($pg, "leading", $l));
                    if ($pp > 0 && $pp < $spec->bodyleading[0] - $spec->bodyleading[2]) {
                        $lopx[] = $i + 1;
                        $minval = min($minval, $pp);
                    }
                    if ($pp > 0 && $spec->bodyleading[1] > 0
                        && $pp > $spec->bodyleading[1] + $spec->bodyleading[2]) {
                        $hipx[] = $i + 1;
                        $maxval = max($maxval, $pp);
                    }
                }
            if (!empty($lopx) || !empty($hipx)) {
                if (!empty($lopx))
                    $pie[] = "<a href=\"http://en.wikipedia.org/wiki/Leading\">Leading</a> (line spacing) too small: minimum {$spec->bodyleading[0]}pt, saw values as small as {$minval}pt on " . pluralx($lopx, "page") . " " . numrangejoin($lopx);
                if (!empty($hipx))
                    $pie[] = "<a href=\"http://en.wikipedia.org/wiki/Leading\">Leading</a> (line spacing) too large: minimum {$spec->bodyleading[1]}pt, saw values as large as {$maxval}pt on " . pluralx($hipx, "page") . " " . numrangejoin($hipx);
                $this->errors |= self::ERR_BODYLEADING;
            }
        }

        // results
        if (count($pie) > 0) {
            $this->msg("warning", "This paper may violate the submission format requirements.  Errors are:\n<ul><li>" . join("</li>\n<li>", $pie) . "</li></ul>\nOnly submissions that comply with the requirements will be considered.  However, the automated format checker uses heuristics and can make mistakes, especially on figures.  If you are confident that the paper already complies with all format requirements, you may submit it as is.");
            $this->status = self::STATUS_PROBLEM;
        } else {
            $this->msg("confirm", "Congratulations, this paper seems to comply with the basic submission format guidelines. However, the automated checker may not verify all formatting requirements. It is your responsibility to ensure that the paper is correctly formatted.");
            $this->status = self::STATUS_OK;
        }
        return $this->status;
    }

    static public function document_spec($dtype) {
        global $Conf;
        $suffix = "";
        if ($dtype)
            $suffix = $dtype < 0 ? "_m" . -$dtype : "_" . $dtype;
        $spec = "";
        if ($Conf->setting("sub_banal$suffix"))
            $spec = $Conf->setting_data("sub_banal$suffix", "");
        return $spec === "" ? null : new CheckFormatSpec($spec);
    }

    public function check_file($filename, $spec) {
        if (is_string($spec))
            $spec = new CheckFormatSpec($spec);
        $bj = $this->run_banal($filename, $spec->banal_args);
        return $this->check_banal_json($bj, $spec);
    }

    function check_document(PaperInfo $prow, $dtype, $doc = 0) {
        global $Conf, $Opt;
        if (is_object($dtype)) {
            $doc = $dtype;
            $dtype = $doc->documentType;
        }
        if (!is_object($doc))
            $doc = $prow->document($dtype, $doc, true);
        if (!$doc || $doc->paperStorageId <= 1)
            return $this->msg("error", "No such document.");
        if ($doc->mimetype != "application/pdf")
            return $this->msg("error", "The format checker only works for PDF files.");

        $spec = self::document_spec($doc->documentType);
        if (!$spec)
            return $this->msg("error", "There are no formatting requirements defined for this document.");

        if ($doc->infoJson && isset($doc->infoJson->banal)
            && $doc->infoJson->banal->at >= @filemtime("src/banal")
            && get($doc->infoJson->banal, "args") == $spec->banal_args)
            $bj = $doc->infoJson->banal;
        else if ($this->no_run)
            return $this->msg("error", "Not running the format checker.");
        else {
            // constrain the number of concurrent banal executions to banalLimit
            // (counter resets every 2 seconds)
            $t = (int) (time() / 2);
            $n = ($Conf->setting_data("__banal_count") == $t ? $Conf->setting("__banal_count") + 1 : 1);
            $limit = get($Opt, "banalLimit", 8);
            if ($limit > 0 && $n > $limit)
                return $this->msg("error", "Server too busy to check paper formats at the moment.  This is a transient error; feel free to try again.");
            if ($limit > 0)
                Dbl::q("insert into Settings (name,value,data) values ('__banal_count',$n,'$t') on duplicate key update value=$n, data='$t'");

            if (!$doc->docclass->load($doc))
                return $this->msg("error", "Paper cannot be loaded.");
            if (!isset($doc->filestore)) {
                if (!$this->tempdir && ($this->tempdir = tempdir()) == false)
                    return $this->msg("error", "Cannot create temporary directory.");
                if (file_put_contents("$this->tempdir/paper.pdf", $doc->content) != strlen($doc->content))
                    return $this->msg("error", "Failed to save PDF to temporary file for analysis.");
                $doc->filestore = "$this->tempdir/paper.pdf";
            }
            $bj = $this->run_banal($doc->filestore, $spec->banal_args);
            if ($bj && is_object($bj) && isset($bj->pages))
                $Conf->update_document_metadata($doc, ["npages" => count($bj->pages), "banal" => $bj]);

            if ($limit > 0)
                Dbl::q("update Settings set value=value-1 where name='__banal_count' and data='$t'");
        }

        return $this->check_banal_json($bj, $spec);
    }

    public function messages() {
        $t = [];
        foreach ($this->msgs as $m)
            $t[] = Ht::xmsg($m[0], $m[1]);
        return $t;
    }
}
