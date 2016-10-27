<?php
// checkformat.php -- HotCRP/banal integration
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CheckFormat extends MessageSet implements FormatChecker {
    const RUN_YES = 0;
    const RUN_PREFER_NO = 1;
    const RUN_NO = 2;

    public $pages = 0;
    private $body_pages;
    public $metadata_updates = [];
    public $failed = false;
    public $banal_stdout;
    public $banal_sterr;
    public $banal_status;
    public $allow_run = self::RUN_YES;
    public $need_run = false;
    public $possible_run = false;
    private $dt_specs = [];
    private $checkers = [];
    static private $banal_args;

    function __construct($allow_run = self::RUN_YES) {
        parent::__construct();
        $this->allow_run = $allow_run;
        if (self::$banal_args === null) {
            $z = opt("banalZoom");
            self::$banal_args = $z ? "-zoom=$z" : "";
        }
    }

    function msg_fail($what) {
        $this->msg("fail", $what, self::ERROR);
        $this->failed = true;
    }

    function run_banal($filename) {
        if (($pdftohtml = opt("pdftohtml")))
            putenv("PHP_PDFTOHTML=" . $pdftohtml);
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

    protected function body_error_status($error_pages) {
        if ($this->body_pages >= 0.5 * $this->pages
            && $error_pages >= 0.16 * $this->body_pages)
            return self::ERROR;
        else
            return self::WARNING;
    }

    static function banal_page_is_body($pg) {
        return defval($pg, "pagetype", "body") == "body"
            && (!isset($pg->d) || $pg->d >= 16000 || !isset($pg->columns) || $pg->columns <= 2);
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
                $this->msg("papersize", "Paper size mismatch: expected " . commajoin(array_map(function ($d) { return FormatSpec::unparse_dimen($d, "paper"); }, $spec->papersize), "or") . ", got " . FormatSpec::unparse_dimen([$papersize[1], $papersize[0]], "paper") . ".", self::WARNING);
        }

        // number of pages
        $minpages = $maxpages = null;
        if ($spec->pagelimit) {
            if (count($bj->pages) < $spec->pagelimit[0])
                $this->msg("pagelimit", "Too few pages: expected " . plural($spec->pagelimit[0], "or more page") . ", found " . count($bj->pages) . ".", self::WARNING);
            else if (count($bj->pages) > $spec->pagelimit[1])
                $this->msg("pagelimit", "Too many pages: the limit is " . plural($spec->pagelimit[1], "page") . ", found " . count($bj->pages) . ".", self::ERROR);
        }
        $this->pages = count($bj->pages);
        $this->body_pages = count(array_filter($bj->pages, function ($pg) {
            return CheckFormat::banal_page_is_body($pg);
        }));

        // number of columns
        if ($spec->columns) {
            $px = array();
            $ncol = get($bj, "columns", 0);
            foreach ($bj->pages as $i => $pg)
                if (($pp = cvtint(get($pg, "columns", $ncol))) > 0
                    && $pp != $spec->columns
                    && self::banal_page_is_body($pg)
                    && $spec->is_checkable($i + 1, "columns"))
                    $px[] = $i + 1;
            if (count($px) > ($maxpages ? max(0, $maxpages * 0.75) : 0))
                $this->msg("columns", "Wrong number of columns: expected " . plural($spec->columns, "column") . ", different on " . pluralx($px, "page") . " " . numrangejoin($px) . ".", self::WARNING);
        }

        // text block
        if ($spec->textblock) {
            $px = array();
            $py = array();
            $maxx = $maxy = $nbadx = $nbady = 0;
            $docpsiz = get($bj, "papersize");
            $docmarg = get($bj, "margin");
            foreach ($bj->pages as $i => $pg)
                if (($psiz = defval($pg, "papersize", $docpsiz)) && is_array($psiz)
                    && ($marg = defval($pg, "margin", $docmarg)) && is_array($marg)
                    && $spec->is_checkable($i + 1, "textblock")) {
                    $pwidth = $psiz[1] - $marg[1] - $marg[3];
                    $pheight = $psiz[0] - $marg[0] - $marg[2];
                    if ($pwidth - $spec->textblock[0] >= 9) {
                        $px[] = $i + 1;
                        $maxx = max($maxx, $pwidth);
                        if ($pwidth >= 1.05 * $spec->textblock[0])
                            ++$nbadx;
                    }
                    if ($pheight - $spec->textblock[1] >= 9) {
                        $py[] = $i + 1;
                        $maxy = max($maxy, $pheight);
                        if ($pheight >= 1.05 * $spec->textblock[1])
                            ++$nbady;
                    }
                }
            if (!empty($px))
                $this->msg("textblock", "Margins too small: text width exceeds "
                    . FormatSpec::unparse_dimen($spec->textblock[0]) . " by "
                    . (count($px) > 1 ? "up to " : "")
                    . ((int) (100 * $maxx / $spec->textblock[0] + .5) - 100)
                    . "% on " . pluralx($px, "page") . " "
                    . numrangejoin($px) . ".", $this->body_error_status($nbadx));
            if (!empty($py))
                $this->msg("textblock", "Margins too small: text height exceeds "
                    . FormatSpec::unparse_dimen($spec->textblock[1]) . " by "
                    . (count($py) > 1 ? "up to " : "")
                    . ((int) (100 * $maxy / $spec->textblock[1] + .5) - 100)
                    . "% on " . pluralx($py, "page") . " "
                    . numrangejoin($py) . ".", $this->body_error_status($nbady));
        }

        // font size
        if ($spec->bodyfontsize) {
            $lopx = $hipx = [];
            $minval = 1000;
            $maxval = 0;
            $nbadsize = 0;
            $bfs = get($bj, "bodyfontsize");
            foreach ($bj->pages as $i => $pg)
                if (self::banal_page_is_body($pg)
                    && $spec->is_checkable($i + 1, "bodyfontsize")) {
                    $pp = cvtnum(get($pg, "bodyfontsize", $bfs));
                    if ($pp > 0 && $pp < $spec->bodyfontsize[0] - $spec->bodyfontsize[2]) {
                        $lopx[] = $i + 1;
                        $minval = min($minval, $pp);
                        if ($pp <= 0.97 * $spec->bodyfontsize[0])
                            ++$nbadsize;
                    }
                    if ($pp > 0 && $spec->bodyfontsize[1] > 0
                        && $pp > $spec->bodyfontsize[1] + $spec->bodyfontsize[2]) {
                        $hipx[] = $i + 1;
                        $maxval = max($maxval, $pp);
                    }
                }
            if ($this->body_pages == 0)
                $this->msg(false, "Warning: No pages seemed to contain body text; results may be off.", self::WARNING);
            else if ($this->body_pages < 0.5 * count($bj->pages))
                $this->msg(false, "Warning: Only " . plural($this->body_pages, "page") . " seemed to contain body text; results may be off.", self::WARNING);
            if (!empty($lopx))
                $this->msg("bodyfontsize", "Body font too small: minimum {$spec->bodyfontsize[0]}pt, saw values as small as {$minval}pt on " . pluralx($lopx, "page") . " " . numrangejoin($lopx) . ".", $this->body_error_status($nbadsize));
            if (!empty($hipx))
                $this->msg("bodyfontsize", "Body font too large: maximum {$spec->bodyfontsize[1]}pt, saw values as large as {$maxval}pt on " . pluralx($hipx, "page") . " " . numrangejoin($hipx) . ".", self::WARNING);
        }

        // line height
        if ($spec->bodylineheight) {
            $lopx = $hipx = [];
            $minval = 1000;
            $maxval = 0;
            $nbadsize = 0;
            $l = get($bj, "leading");
            foreach ($bj->pages as $i => $pg)
                if (self::banal_page_is_body($pg)
                    && $spec->is_checkable($i + 1, "bodylineheight")) {
                    $pp = cvtnum(get($pg, "leading", $l));
                    if ($pp > 0 && $pp < $spec->bodylineheight[0] - $spec->bodylineheight[2]) {
                        $lopx[] = $i + 1;
                        $minval = min($minval, $pp);
                        if ($pp <= 0.97 * $spec->bodylineheight[0])
                            ++$nbadsize;
                    }
                    if ($pp > 0 && $spec->bodylineheight[1] > 0
                        && $pp > $spec->bodylineheight[1] + $spec->bodylineheight[2]) {
                        $hipx[] = $i + 1;
                        $maxval = max($maxval, $pp);
                    }
                }
            if (!empty($lopx))
                $this->msg("bodylineheight", "Line height too small: minimum {$spec->bodylineheight[0]}pt, saw values as small as {$minval}pt on " . pluralx($lopx, "page") . " " . numrangejoin($lopx) . ".", $this->body_error_status($nbadsize));
            if (!empty($hipx))
                $this->msg("bodylineheight", "Line height too large: minimum {$spec->bodylineheight[1]}pt, saw values as large as {$maxval}pt on " . pluralx($hipx, "page") . " " . numrangejoin($hipx) . ".", self::WARNING);
        }
    }

    function clear() {
        parent::clear();
        $this->metadata_updates = [];
        $this->need_run = $this->possible_run = false;
        $this->failed = false;
    }

    function check_file($filename, $spec) {
        if (is_string($spec))
            $spec = new FormatSpec($spec);
        $this->clear();
        $bj = $this->run_banal($filename);
        $this->check_banal_json($bj, $spec);
    }

    function load_to_filestore($doc) {
        if ($doc->docclass->load_to_filestore($doc))
            return true;
        $cf->msg_fail(isset($doc->error_html) ? $doc->error_html : "Paper cannot be loaded.");
        return false;
    }

    function check(CheckFormat $cf, FormatSpec $spec, PaperInfo $prow, $doc) {
        global $Conf, $Now;
        $bj = null;
        if (($m = $doc->metadata()) && isset($m->banal))
            $bj = $m->banal;
        $bj_ok = $bj && $bj->at >= @filemtime("src/banal") && get($bj, "args") == self::$banal_args;
        if (!$bj_ok || $bj->at >= $Now - 86400) {
            $cf->possible_run = true;
            if ($cf->allow_run == CheckFormat::RUN_YES
                || (!$bj_ok && $cf->allow_run == CheckFormat::RUN_PREFER_NO))
                $bj = null;
        }

        if (!$bj && $cf->allow_run == CheckFormat::RUN_NO)
            $cf->need_run = true;
        else if (!$bj && $cf->load_to_filestore($doc)) {
            // constrain the number of concurrent banal executions to banalLimit
            // (counter resets every 2 seconds)
            $t = (int) (time() / 2);
            $n = ($doc->conf->setting_data("__banal_count") == $t ? $doc->conf->setting("__banal_count") + 1 : 1);
            $limit = opt("banalLimit", 8);
            if ($limit > 0 && $n > $limit)
                return $cf->msg_fail("Server too busy to check paper formats at the moment.  This is a transient error; feel free to try again.");
            if ($limit > 0)
                $doc->conf->q("insert into Settings (name,value,data) values ('__banal_count',$n,'$t') on duplicate key update value=$n, data='$t'");

            $bj = $cf->run_banal($doc->filestore);
            if ($bj && is_object($bj) && isset($bj->pages)) {
                $cf->metadata_updates["npages"] = count($bj->pages);
                $cf->metadata_updates["banal"] = $bj;
            }

            if ($limit > 0)
                $doc->conf->q("update Settings set value=value-1 where name='__banal_count' and data='$t'");
        }

        if ($bj)
            $cf->check_banal_json($bj, $spec);
        else
            $cf->msg_fail(null);
    }

    function has_spec($dtype) {
        return ($spec = $this->spec($dtype)) && !$spec->is_empty();
    }

    function spec($dtype) {
        global $Conf;
        if (!array_key_exists($dtype, $this->dt_specs)) {
            $o = $Conf->paper_opts->find_document($dtype);
            $spec = $o ? $o->format_spec() : null;
            $this->dt_specs[$dtype] = $spec ? : new FormatSpec;
        }
        return $this->dt_specs[$dtype];
    }

    function set_spec($dtype, FormatSpec $spec) {
        $this->dt_specs[$dtype] = $spec;
    }

    function fetch_document(PaperInfo $prow, $dtype, $docid = 0) {
        $doc = $prow->document($dtype, $docid, true);
        if (!$doc || $doc->paperStorageId <= 1)
            $this->msg_fail("No such document.");
        else if ($doc->paperId != $prow->paperId || $doc->documentType != $dtype)
            $this->msg_fail("The document has changed.");
        else
            return $doc;
        return null;
    }

    function check_document(PaperInfo $prow, $doc) {
        global $Conf;
        $this->clear();
        if (!$doc) {
            if (!isset($this->errf["fail"]))
                $this->msg_fail("No such document.");
            return;
        } else if ($doc->mimetype != "application/pdf")
            return $this->msg_fail("The format checker only works for PDF files.");

        $done_me = false;
        $spec = $this->spec($doc->documentType);
        foreach ($spec->checkers ? : [] as $chk) {
            if ($chk === "banal" || $chk === "CheckFormat") {
                $checker = $this;
                $done_me = true;
            } else {
                if (!isset($this->checkers[$chk]))
                    $this->checkers[$chk] = new $chk;
                $checker = $this->checkers[$chk];
            }
            $checker->check($this, $spec, $prow, $doc);
        }
        if (!$done_me)
            $this->check($this, $spec, $prow, $doc);

        // save information about the run
        if (!empty($this->metadata_updates))
            $doc->update_metadata($this->metadata_updates);
        // record check status in `Paper` table
        if ($prow->is_joindoc($doc)
            && ($specdate = $doc->conf->setting($doc->documentType == DTYPE_SUBMISSION ? "sub_banal" : "sub_banal_m1")) > 0
            && !$this->failed) {
            if (!$this->has_problem())
                $x = $specdate;
            else if ($this->has_error())
                $x = -$specdate;
            else
                $x = 0;
            if ($x != $prow->pdfFormatStatus) {
                $prow->pdfFormatStatus = $x;
                $prow->conf->qe("update Paper set pdfFormatStatus=? where paperId=?", $prow->pdfFormatStatus, $prow->paperId);
            }
        }
    }

    function report(CheckFormat $cf, FormatSpec $spec, PaperInfo $prow, $doc) {
        $t = "";
        if ($this->failed)
            $t .= Ht::xmsg("error", '<div>' . join('</div><div>', $this->messages_at("fail")) . '</div>');
        $msgs = array_filter($this->messages(true), function ($mx) { return $mx[0] != "fail" && $mx[2] > MessageSet::INFO; });
        if ($msgs) {
            $msgs = array_map(function ($m) {
                return $m[2] > self::STATUS_PROBLEM ? "<strong>$m[1]</strong>" : $m[1];
            }, $msgs);
            if ($this->has_error()) {
                $status = "error";
                $start = "This document violates the submission format requirements. The most serious errors are in bold.";
            } else {
                $status = "warning";
                $start = "This document may violate the submission format requirements.";
            }
            $t .= Ht::xmsg($status, "$start\n<ul><li>" . join("</li>\n<li>", $msgs) . "</li></ul>\nSubmissions that violate the requirements will not be considered. However, the automated format checker uses heuristics and can make mistakes, especially on figures. If you are confident that the paper already complies with all format requirements, you may submit it as is.");
        } else if (!$this->has_problem())
            $t .= Ht::xmsg("confirm", "Congratulations, this document seems to comply with the format guidelines. However, the automated checker may not verify all formatting requirements. It is your responsibility to ensure correct formatting.");
        return $t;
    }
    function document_report(PaperInfo $prow, $doc) {
        $spec = $this->spec($doc ? $doc->documentType : DTYPE_SUBMISSION);
        if ($doc) {
            foreach ($spec->checkers ? : [] as $chk)
                if ($chk !== "banal" && $chk !== "CheckFormat" && isset($this->checkers[$chk])
                    && ($report = $this->checkers[$chk]->report($this, $spec, $prow, $doc)))
                    return $report;
        }
        return $this->report($this, $spec, $prow, $doc);
    }
}
