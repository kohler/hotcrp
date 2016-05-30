<?php
// checkformat.php -- HotCRP/banal integration
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CheckFormat implements FormatChecker {
    const STATUS_ERROR = 0;
    const STATUS_PROBLEM = 1;
    const STATUS_OK = 2;

    const RUN_YES = 0;
    const RUN_PREFER_NO = 1;
    const RUN_NO = 2;

    private $msgs = [];
    public $errf = [];
    public $field_status = [];
    public $pages = 0;
    public $metadata_updates = [];
    public $status;
    public $banal_stdout;
    public $banal_sterr;
    public $banal_status;
    public $allow_run = self::RUN_YES;
    public $need_run = false;
    public $possible_run = false;
    private $dt_specs = [];
    private $checkers = [];
    static private $banal_args;

    public function __construct($allow_run = self::RUN_YES) {
        $this->allow_run = $allow_run;
        if (self::$banal_args === null) {
            $z = opt("banalZoom");
            self::$banal_args = $z ? "-zoom=$z" : "";
        }
    }

    public function has_error($field = null) {
        return $field ? isset($this->errf[$field]) : !empty($this->errf);
    }

    public function msg_fail($what) {
        if ($what)
            $this->msgs[] = ["fail", $what];
        return $this->status = self::STATUS_ERROR;
    }

    public function msg_format($field, $what) {
        if ($field)
            $this->errf[$field] = true;
        if ($what)
            $this->msgs[] = [$field ? : "", $what];
        if ($this->status == self::STATUS_OK)
            $this->status = self::STATUS_PROBLEM;
        return $this->status;
    }

    public function run_banal($filename) {
        global $Opt;
        if (isset($Opt["pdftohtml"]))
            putenv("PHP_PDFTOHTML=" . $Opt["pdftohtml"]);
        $banal_run = "perl src/banal -no_app -json ";
        if (self::$banal_args)
            $banal_run .= self::$banal_args . " ";
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
            return $this->msg_fail("Analysis failure: no pages or paper size.");

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
            if (!$ok)
                $this->msg_format("papersize", "Paper size mismatch: expected " . commajoin(array_map(function ($d) { return FormatSpec::unparse_dimen($d, "paper"); }, $spec->papersize), "or") . ", got " . FormatSpec::unparse_dimen([$papersize[1], $papersize[0]], "paper") . ".");
        }

        // number of pages
        $minpages = $maxpages = null;
        if ($spec->pagelimit) {
            if (count($bj->pages) < $spec->pagelimit[0])
                $this->msg_format("pagelimit", "Too few pages: expected " . plural($spec->pagelimit[0], "or more page") . ", found " . count($bj->pages) . ".");
            else if (count($bj->pages) > $spec->pagelimit[1])
                $this->msg_format("pagelimit", "Too many pages: the limit is " . plural($spec->pagelimit[1], "page") . ", found " . count($bj->pages) . ".");
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
            if (count($px) > ($maxpages ? max(0, $maxpages * 0.75) : 0))
                $this->msg_format("columns", "Wrong number of columns: expected " . plural($spec->columns, "column") . ", different on " . pluralx($px, "page") . " " . numrangejoin($px) . ".");
        }

        // text block
        if ($spec->textblock) {
            $px = array();
            $py = array();
            $maxx = $maxy = 0;
            $docpsiz = get($bj, "papersize");
            $docmarg = get($bj, "margin");
            foreach ($bj->pages as $i => $pg)
                if (($psiz = defval($pg, "papersize", $docpsiz)) && is_array($psiz)
                    && ($marg = defval($pg, "margin", $docmarg)) && is_array($marg)) {
                    $pwidth = $psiz[1] - $marg[1] - $marg[3];
                    $pheight = $psiz[0] - $marg[0] - $marg[2];
                    if ($pwidth - $spec->textblock[0] >= 9) {
                        $px[] = $i + 1;
                        $maxx = max($maxx, $pwidth);
                    }
                    if ($pheight - $spec->textblock[1] >= 9) {
                        $py[] = $i + 1;
                        $maxy = max($maxy, $pheight);
                    }
                }
            if (count($px) > 0)
                $this->msg_format("textblock", "Margins too small: text width exceeds "
                    . FormatSpec::unparse_dimen($spec->textblock[0]) . " by "
                    . (count($px) > 1 ? "up to " : "")
                    . ((int) (100 * $maxx / $spec->textblock[0] + .5) - 100)
                    . "% on " . pluralx($px, "page") . " "
                    . numrangejoin($px) . ".");
            if (count($py) > 0)
                $this->msg_format("textblock", "Margins too small: text height exceeds "
                    . FormatSpec::unparse_dimen($spec->textblock[1]) . " by "
                    . (count($py) > 1 ? "up to " : "")
                    . ((int) (100 * $maxy / $spec->textblock[1] + .5) - 100)
                    . "% on " . pluralx($py, "page") . " "
                    . numrangejoin($py) . ".");
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
                $this->msg_format(false, "Warning: No pages seemed to contain body text; results may be off.");
            else if ($bodypages <= 0.5 * count($bj->pages))
                $this->msg_format(false, "Warning: Only " . plural($bodypages, "page") . " seemed to contain body text; results may be off.");
            if (!empty($lopx))
                $this->msg_format("bodyfontsize", "Body font too small: minimum {$spec->bodyfontsize[0]}pt, saw values as small as {$minval}pt on " . pluralx($lopx, "page") . " " . numrangejoin($lopx) . ".");
            if (!empty($hipx))
                $this->msg_format("bodyfontsize", "Body font too large: maximum {$spec->bodyfontsize[1]}pt, saw values as large as {$maxval}pt on " . pluralx($hipx, "page") . " " . numrangejoin($hipx) . ".");
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
            if (!empty($lopx))
                $this->msg_format("bodyleading", "<a href=\"http://en.wikipedia.org/wiki/Leading\">Leading</a> (line spacing) too small: minimum {$spec->bodyleading[0]}pt, saw values as small as {$minval}pt on " . pluralx($lopx, "page") . " " . numrangejoin($lopx) . ".");
            if (!empty($hipx))
                $this->msg_format("bodyleading", "<a href=\"http://en.wikipedia.org/wiki/Leading\">Leading</a> (line spacing) too large: minimum {$spec->bodyleading[1]}pt, saw values as large as {$maxval}pt on " . pluralx($hipx, "page") . " " . numrangejoin($hipx) . ".");
        }
    }

    static public function document_spec($dtype) {
        global $Conf;
        $suffix = "";
        if ($dtype)
            $suffix = $dtype < 0 ? "_m" . -$dtype : "_" . $dtype;
        $spec = "";
        if ($Conf->setting("sub_banal$suffix"))
            $spec = $Conf->setting_data("sub_banal$suffix", "");
        $fspec = new FormatSpec($spec);
        if (($xspec = opt("sub_banal$suffix")))
            $fspec->merge($xspec);
        return $fspec;
    }

    private function clear() {
        $this->errf = $this->metadata_updates = [];
        $this->status = self::STATUS_OK;
        $this->need_run = $this->possible_run = false;
        $this->msgs = [];
    }

    public function check_file($filename, $spec) {
        if (is_string($spec))
            $spec = new FormatSpec($spec);
        $this->clear();
        $bj = $this->run_banal($filename);
        $this->check_banal_json($bj, $spec);
        return $this->status;
    }

    public function load_document($doc) {
        if (!$doc->docclass->load_to_filestore($doc))
            return $cf->msg_fail(isset($doc->error_html) ? $doc->error_html : "Paper cannot be loaded.");
        return true;
    }

    public function check(CheckFormat $cf, FormatSpec $spec, PaperInfo $prow, $doc) {
        global $Conf, $Opt, $Now;
        $bj = null;
        if ($doc->infoJson && isset($doc->infoJson->banal))
            $bj = $doc->infoJson->banal;
        $bj_ok = $bj && $bj->at >= @filemtime("src/banal") && get($bj, "args") == self::$banal_args;
        if (!$bj_ok || $bj->at >= $Now - 86400) {
            $cf->possible_run = true;
            if ($cf->allow_run == CheckFormat::RUN_YES
                || (!$bj_ok && $cf->allow_run == CheckFormat::RUN_PREFER_NO))
                $bj = null;
        }

        if (!$bj && $cf->allow_run == CheckFormat::RUN_NO)
            $cf->need_run = true;
        else if (!$bj && $cf->load_document($doc)) {
            // constrain the number of concurrent banal executions to banalLimit
            // (counter resets every 2 seconds)
            $t = (int) (time() / 2);
            $n = ($Conf->setting_data("__banal_count") == $t ? $Conf->setting("__banal_count") + 1 : 1);
            $limit = get($Opt, "banalLimit", 8);
            if ($limit > 0 && $n > $limit)
                return $cf->msg_fail("Server too busy to check paper formats at the moment.  This is a transient error; feel free to try again.");
            if ($limit > 0)
                Dbl::q("insert into Settings (name,value,data) values ('__banal_count',$n,'$t') on duplicate key update value=$n, data='$t'");

            $bj = $cf->run_banal($doc->filestore);
            if ($bj && is_object($bj) && isset($bj->pages)) {
                $cf->metadata_updates["npages"] = count($bj->pages);
                $cf->metadata_updates["banal"] = $bj;
            }

            if ($limit > 0)
                Dbl::q("update Settings set value=value-1 where name='__banal_count' and data='$t'");
        }

        if ($bj)
            $cf->check_banal_json($bj, $spec);
        else
            $cf->status = CheckFormat::STATUS_ERROR;
    }

    public function has_spec($dtype) {
        return ($spec = $this->spec($dtype)) && !$spec->is_empty();
    }

    public function spec($dtype) {
        if (!array_key_exists($dtype, $this->dt_specs))
            $this->dt_specs[$dtype] = self::document_spec($dtype);
        return $this->dt_specs[$dtype];
    }

    public function set_spec($dtype, FormatSpec $spec) {
        $this->dt_specs[$dtype] = $spec;
    }

    public function check_document(PaperInfo $prow, $dtype, $doc = 0) {
        global $Conf, $Opt;
        $this->clear();
        if (is_object($dtype)) {
            $doc = $dtype;
            $dtype = $doc->documentType;
        }
        if (!is_object($doc))
            $doc = $prow->document($dtype, $doc, true);
        if (!$doc || $doc->paperStorageId <= 1)
            return $this->msg_fail("No such document.");
        if ($doc->paperId != $prow->paperId || $doc->documentType != $dtype)
            return $this->msg_fail("The document has changed.");
        if ($doc->mimetype != "application/pdf")
            return $this->msg_fail("The format checker only works for PDF files.");

        $done_banal = false;
        $spec = $this->spec($dtype);
        foreach ($spec->checkers ? : [] as $chk) {
            if ($chk === "banal" || $chk === "CheckFormat") {
                $checker = $this;
                $done_banal = true;
            } else {
                if (!isset($this->checkers[$chk]))
                    $this->checkers[$chk] = new $chk;
                $checker = $this->checkers[$chk];
            }
            $checker->check($this, $spec, $prow, $doc);
        }
        if (!$done_banal)
            $this->check($this, $spec, $prow, $doc);

        if (!empty($this->metadata_updates))
            $doc->update_metadata($this->metadata_updates);
        return $this->status;
    }

    private function field_status($f) {
        return get($this->field_status, $f, $f === "fail" ? CheckFormat::STATUS_ERROR : CheckFormat::STATUS_PROBLEM);
    }
    public function errors() {
        $x = [];
        foreach ($this->msgs as $m)
            if ($this->field_status($m[0]) === CheckFormat::STATUS_ERROR)
                $x[] = $m[1];
        return $x;
    }
    public function warnings() {
        $x = [];
        foreach ($this->msgs as $m)
            if ($this->field_status($m[0]) === CheckFormat::STATUS_PROBLEM)
                $x[] = $m[1];
        return $x;
    }
    public function messages_html() {
        $t = [];
        if (($x = $this->errors()))
            $t[] = Ht::xmsg("error", '<div>' . join('</div><div>', $x) . '</div>');
        if (($x = $this->warnings())) {
            $t[] = Ht::xmsg("warning", "This document may violate the submission format requirements.  Errors are:\n<ul><li>" . join("</li>\n<li>", $x) . "</li></ul>\nOnly submissions that comply with the requirements will be considered.  However, the automated format checker uses heuristics and can make mistakes, especially on figures.  If you are confident that the paper already complies with all format requirements, you may submit it as is.");
        } else if ($this->status == self::STATUS_OK)
            $t[] = Ht::xmsg("confirm", "Congratulations, this document seems to comply with the basic submission format guidelines. However, the automated checker may not verify all formatting requirements. It is your responsibility to ensure correct formatting.");
        return $t;
    }
}
