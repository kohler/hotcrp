<?php
// checkformat.php -- HotCRP/banal integration
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CheckFormat {

    const ERR_PAPERSIZE = 1;
    const ERR_PAGELIMIT = 2;
    const ERR_COLUMNS = 4;
    const ERR_TEXTBLOCK = 8;
    const ERR_BODYFONTSIZE = 16;
    const ERR_BODYLEADING = 32;

    public static $error_types = array(self::ERR_PAPERSIZE => "papersize",
                           self::ERR_PAGELIMIT => "pagelimit",
                           self::ERR_COLUMNS => "columns",
                           self::ERR_TEXTBLOCK => "textblock",
                           self::ERR_BODYFONTSIZE => "bodyfontsize",
                           self::ERR_BODYLEADING => "bodyleading");

    var $msgs = array();
    public $errors = 0;
    public $pages = 0;
    public $banal_stdout;
    public $banal_sterr;
    public $banal_status;
    private $tempdir = null;

    function __construct() {
        $this->msgs = array();
        $this->errors = 0;
        $this->pages = 0;
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
        if ($to == "paper") {
            if (is_array($n) && count($n) == 2
                && abs($n[0] - 612) <= 5 && abs($n[1] - 792) <= 5)
                return "letter paper (8.5in x 11in)";
            else if (is_array($n) && count($n) == 2
                     && abs($n[0] - 595.27) <= 5 && abs($n[1] - 841.89) <= 5)
                return "A4 paper (210mm x 297mm)";
            else
                $to = null;
        }
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
        return 0;
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
        if (!$bj || !isset($bj->pages) || !isset($bj->papersize)
            || !is_array($bj->pages) || !is_array($bj->papersize)
            || count($bj->papersize) != 2)
            return $this->msg("error", "Analysis failure: no pages or paper size.");

        // report results
        $banal_desired = explode(";", $spec);
        $pie = array();

        // paper size
        if (count($banal_desired) > 0 && $banal_desired[0]) {
            $papersize = $bj->papersize;
            $psdefs = array();
            $ok = false;
            foreach (explode(" OR ", $banal_desired[0]) as $p) {
                if (!($p = self::parse_dimen($p, 2)))
                    continue;
                if (abs($p[0] - $papersize[1]) < 9
                    && abs($p[1] - $papersize[0]) < 9) {
                    $ok = true;
                    break;
                }
                $psdefs[] = self::unparse_dimen($p, "paper");
            }
            if (!$ok && count($psdefs)) {
                $pie[] = "Paper size mismatch: expected " . commajoin($psdefs, "or") . ", got " . self::unparse_dimen([$papersize[1], $papersize[0]], "paper");
                $this->errors |= self::ERR_PAPERSIZE;
            }
        }

        // number of pages
        $minpages = $maxpages = null;
        if (count($banal_desired) > 1 && $banal_desired[1]
            && preg_match('/\A(\d+)(?:-(\d+))?\z/', $banal_desired[1], $m)) {
            $m[2] = (isset($m[2]) ? $m[2] : 0);
            $minpages = ($m[2] ? $m[1] : null);
            $maxpages = ($m[2] ? $m[2] : $m[1]);
            if ($m[2] && count($bj->pages) < $m[1]) {
                $pie[] = "Too few pages: expected " . plural($m[1], "or more page") . ", found " . count($bj->pages);
                $this->errors |= self::ERR_PAGELIMIT;
            } else if (count($bj->pages) > ($m[2] ? $m[2] : $m[1])) {
                $pie[] = "Too many pages: the limit is " . plural($m[2] ? $m[2] : $m[1], "page") . ", found " . count($bj->pages);
                $this->errors |= self::ERR_PAGELIMIT;
            }
        }
        $this->pages = count($bj->pages);

        // number of columns
        if (count($banal_desired) > 2 && $banal_desired[2]
            && ($p = cvtint($banal_desired[2])) > 0) {
            $px = array();
            $ncol = get($bj, "columns", 0);
            foreach ($bj->pages as $i => $pg)
                if (($pp = cvtint(get($pg, "columns", $ncol))) > 0
                    && $pp != $p
                    && defval($pg, "pagetype", "body") == "body")
                    $px[] = $i + 1;
            if (count($px) > ($maxpages ? max(0, $maxpages * 0.75) : 0)) {
                $pie[] = "Wrong number of columns: expected " . plural($p, "column") . ", different on " . pluralx($px, "page") . " " . numrangejoin($px);
                $this->errors |= self::ERR_COLUMNS;
            }
        }

        // text block
        if (count($banal_desired) > 3 && $banal_desired[3]
            && ($p = self::parse_dimen($banal_desired[3], 2))) {
            $px = array();
            $py = array();
            $maxx = $maxy = 0;
            $tb = get($bj, "textblock");
            foreach ($bj->pages as $i => $pg)
                if (($pp = defval($pg, "textblock", $tb)) && is_array($pp)) {
                    if ($pp[1] - $p[0] >= 9) {
                        $px[] = $i + 1;
                        $maxx = max($maxx, $pp[0]);
                    }
                    if ($pp[0] - $p[1] >= 9) {
                        $py[] = $i + 1;
                        $maxy = max($maxy, $pp[1]);
                    }
                }
            if (count($px) > 0) {
                $pie[] = "Margins too small: text width exceeds "
                    . self::unparse_dimen($p[0]) . " by "
                    . (count($px) > 1 ? "up to " : "")
                    . ((int) (100 * $maxx / $p[0] + .5) - 100)
                    . "% on " . pluralx($px, "page") . " "
                    . numrangejoin($px);
                $this->errors |= self::ERR_TEXTBLOCK;
            }
            if (count($py) > 0) {
                $pie[] = "Margins too small: text height exceeds "
                    . self::unparse_dimen($p[1]) . " by "
                    . (count($py) > 1 ? "up to " : "")
                    . ((int) (100 * $maxy / $p[1] + .5) - 100)
                    . "% on " . pluralx($py, "page") . " "
                    . numrangejoin($py);
                $this->errors |= self::ERR_TEXTBLOCK;
            }
        }

        // font size
        if (count($banal_desired) > 4 && $banal_desired[4]
            && preg_match('/\A([\d.]+)(?:-([\d.]+))?\z/', $banal_desired[4], $m)) {
            $minptsize = cvtnum($m[1]);
            $maxptsize = isset($m[2]) ? cvtnum($m[2], 0) : 0;
            $lopx = $hipx = [];
            $bodypages = 0;
            $minval = 1000;
            $maxval = 0;
            $bfs = get($bj, "bodyfontsize");
            foreach ($bj->pages as $i => $pg)
                if (get($pg, "pagetype", "body") == "body") {
                    $pp = cvtnum(get($pg, "bodyfontsize", $bfs));
                    ++$bodypages;
                    if ($pp > 0 && $pp < $minptsize) {
                        $lopx[] = $i + 1;
                        $minval = min($minval, $pp);
                    }
                    if ($pp > 0 && $maxptsize > 0 && $pp > $maxptsize) {
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
                    $pie[] = "Body font too small: minimum {$minptsize}pt, saw values as small as {$minval}pt on " . pluralx($lopx, "page") . " " . numrangejoin($lopx);
                if (!empty($hipx))
                    $pie[] = "Body font too large: maximum {$maxptsize}pt, saw values as large as {$maxval}pt on " . pluralx($hipx, "page") . " " . numrangejoin($hipx);
                $this->errors |= self::ERR_BODYFONTSIZE;
            }
        }

        // leading
        if (count($banal_desired) > 5 && $banal_desired[5]
            && ($p = cvtnum($banal_desired[5])) > 0) {
            $px = array();
            $minval = 1000;
            $l = get($bj, "leading");
            foreach ($bj->pages as $i => $pg)
                if (($pp = cvtnum(get($pg, "leading", $l))) > 0
                    && $pp < $p
                    && get($pg, "pagetype", "body") == "body") {
                    $px[] = $i + 1;
                    $minval = min($minval, $pp);
                }
            if (count($px) > 0) {
                $pie[] = "<a href='http://en.wikipedia.org/wiki/Leading'>Leading</a> (line spacing) too small: minimum ${p}pt, saw values as small as ${minval}pt on " . pluralx($px, "page") . " " . numrangejoin($px);
                $this->errors |= self::ERR_BODYLEADING;
            }
        }

        // results
        if (count($pie) > 0) {
            $this->msg("warn", "This paper may violate the submission format requirements.  Errors are:\n<ul><li>" . join("</li>\n<li>", $pie) . "</li></ul>\nOnly submissions that comply with the requirements will be considered.  However, the automated format checker uses heuristics and can make mistakes, especially on figures.  If you are confident that the paper already complies with all format requirements, you may submit it as is.");
            return 1;
        } else {
            $this->msg("confirm", "Congratulations, this paper seems to comply with the basic submission format guidelines. However, the automated checker may not verify all formatting requirements. It is your responsibility to ensure that the paper is correctly formatted.");
            return 2;
        }
    }

    public function check_file($filename, $spec) {
        list($spec, $args) = self::split_spec($spec);
        $bj = $this->run_banal($filename, $args);
        return $this->check_banal_json($bj, $spec);
    }

    function check_document(PaperInfo $prow, $dtype, $did = 0) {
        global $Conf, $Opt;

        $suffix = "";
        if ($dtype)
            $suffix = $dtype < 0 ? "_m" . -$dtype : "_" . $dtype;
        if ($Conf->setting("sub_banal$suffix"))
            $formatspec = $Conf->setting_data("sub_banal$suffix", "");
        else
            $formatspec = $Conf->setting_data("sub_banal", "");
        if ($formatspec === "") {
            $this->msg("confirm", "There are no formatting requirements defined for this document.");
            return 2;
        }
        if (!($doc = $prow->document($dtype, $did, true)) || $doc->paperStorageId <= 1)
            return $this->msg("error", "No such document.");
        if ($doc->mimetype != "application/pdf")
            return $this->msg("error", "The format checker only works for PDF files.");

        list($spec, $banal_args) = self::split_spec($formatspec);
        if ($doc->infoJson && isset($doc->infoJson->banal)
            && $doc->infoJson->banal->at >= @filemtime("src/banal")
            && get($doc->infoJson->banal, "args") == $banal_args)
            $bj = $doc->infoJson->banal;
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
            $bj = $this->run_banal($doc->filestore, $banal_args);
            if ($bj && is_object($bj) && isset($bj->pages))
                $Conf->update_document_metadata($doc, ["npages" => count($bj->pages), "banal" => $bj]);

            if ($limit > 0)
                Dbl::q("update Settings set value=value-1 where name='__banal_count' and data='$t'");
        }

        return $this->check_banal_json($bj, $spec);
    }

    function reportMessages() {
        global $Conf;
        foreach ($this->msgs as $m)
            if ($m[0] == "error")
                Conf::msg_error($m[1]);
            else if ($m[0] == "warn")
                $Conf->warnMsg($m[1]);
            else if ($m[0] == "confirm")
                $Conf->confirmMsg($m[1]);
            else if ($m[0] == "info")
                $Conf->infoMsg($m[1]);
    }
}
