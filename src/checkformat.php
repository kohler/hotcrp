<?php
// checkformat.php -- HotCRP/banal integration
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class CheckFormat extends MessageSet implements FormatChecker {
    const RUN_ALWAYS = 0;
    const RUN_IF_NECESSARY = 1;
    const RUN_IF_NECESSARY_TIMEOUT = 2;
    const RUN_NEVER = 3;
    const TIMEOUT = 8.0;

    const RUN_STARTED = 1;
    const RUN_ALLOWED = 2;
    const RUN_DESIRED = 4;
    const RUN_ATTEMPTED = 8;

    /** @var bool */
    const DEBUG = false;

    /** @var Conf */
    private $conf;
    /** @var int */
    public $allow_run;
    private $checkers = [];
    public $banal_stdout;
    public $banal_stderr;
    public $banal_status;
    /** @var ?int */
    public $npages;
    /** @var ?int */
    private $body_pages;
    /** @var int */
    public $run_flags = 0;
    public $metadata_updates = [];

    static private $banal_args;
    /** @var int */
    static public $runcount = 0;
    /** @var float */
    static public $runtime = 0.0;

    /** @param ?int $allow_run */
    function __construct(Conf $conf, $allow_run = null) {
        parent::__construct();
        $this->allow_run = $allow_run ?? self::RUN_ALWAYS;
        $this->conf = $conf;
        if (self::$banal_args === null) {
            $z = $this->conf->opt("banalZoom");
            self::$banal_args = $z ? "-zoom=$z" : "";
        }
    }

    function error_kinds(FormatSpec $spec) {
        $ks = [];
        foreach (["papersize", "pagelimit", "columns", "textblock", "bodyfontsize", "bodylineheight"] as $k) {
            if ($spec->unparse_key($k) !== "")
                $ks[] = $k;
        }
        return $ks;
    }

    function msg_fail($what) {
        $this->error_at("error", $what);
    }

    function run_banal($filename) {
        $env = ["PATH" => getenv("PATH")];
        if (($pdftohtml = $this->conf->opt("pdftohtml"))) {
            $env["PHP_PDFTOHTML"] = $pdftohtml;
        }
        $banal_run = "perl src/banal -json ";
        if (self::$banal_args) {
            $banal_run .= self::$banal_args . " ";
        }
        $banal_run .= escapeshellarg($filename);
        $pipes = null;
        $tstart = microtime(true);
        $banal_proc = proc_open($banal_run, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes, SiteLoader::$root, $env);
        // read stderr first -- if there are warnings, we must or banal might
        // block forever!
        $this->banal_stderr = stream_get_contents($pipes[2]);
        $this->banal_stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->banal_status = proc_close($banal_proc);
        ++self::$runcount;
        $banal_time = microtime(true) - $tstart;
        self::$runtime += $banal_time;
        if (self::DEBUG && $banal_time > 0.1) {
            error_log(sprintf("%.6f: %s", $banal_time, $banal_run));
        }
        return json_decode($this->banal_stdout);
    }

    private function banal_json(DocumentInfo $doc, FormatSpec $spec) {
        if ($this->allow_run === CheckFormat::RUN_IF_NECESSARY_TIMEOUT
            && CheckFormat::$runtime >= CheckFormat::TIMEOUT) {
            $allow_run = CheckFormat::RUN_NEVER;
        } else {
            $allow_run = $this->allow_run;
        }

        $bj = null;
        if (($m = $doc->metadata()) && isset($m->banal)) {
            $bj = $m->banal;
            $this->npages = is_int($bj->npages ?? null) ? $bj->npages : count($bj->pages);
        }
        $bj_ok = $bj
            && $bj->at >= @filemtime(SiteLoader::find("src/banal"))
            && ($bj->args ?? null) == self::$banal_args
            && (!isset($bj->cfmsg)
                || ($spec->timestamp && $spec->timestamp === ($bj->spects ?? null)));
        $flags = $bj_ok ? 0 : CheckFormat::RUN_DESIRED;
        if (!$bj_ok || $bj->at < Conf::$now - 86400) {
            $flags |= CheckFormat::RUN_ALLOWED;
            if ($allow_run === CheckFormat::RUN_ALWAYS
                || (!$bj_ok && $allow_run !== CheckFormat::RUN_NEVER)) {
                $bj = null;
            }
        }

        if ($bj || $allow_run === CheckFormat::RUN_NEVER) {
            /* do nothing */;
        } else if (($path = $doc->content_file())) {
            // constrain the number of concurrent banal executions to banalLimit
            // (counter resets every 2 seconds)
            $t = (int) (time() / 2);
            $n = ($doc->conf->setting_data("__banal_count") == $t ? $doc->conf->setting("__banal_count") + 1 : 1);
            $limit = $doc->conf->opt("banalLimit") ?? 8;
            if ($limit > 0 && $n > $limit) {
                $this->msg_fail("Server too busy to check paper formats at the moment. This is a transient error; feel free to try again.");
                $this->run_flags |= $flags;
                return null;
            }
            if ($limit > 0) {
                $doc->conf->q("insert into Settings (name,value,data) values ('__banal_count',$n,'$t') on duplicate key update value=$n, data='$t'");
            }

            $flags |= CheckFormat::RUN_ATTEMPTED;
            $bj = $this->run_banal($path);
            if ($bj && is_object($bj) && isset($bj->pages) && is_array($bj->pages)) {
                $this->npages = is_int($bj->npages ?? null) ? $bj->npages : count($bj->pages);
                $this->metadata_updates["npages"] = $this->npages;
                $this->metadata_updates["banal"] = $bj;
                $flags &= ~(CheckFormat::RUN_ALLOWED | CheckFormat::RUN_DESIRED);
            } else {
                $this->msg_fail("Error processing file. The file may not be in PDF format or may be corrupted.");
            }

            if ($limit > 0) {
                $doc->conf->q("update Settings set value=value-1 where name='__banal_count' and data='$t'");
            }
        } else {
            $this->msg_fail($doc->error_html ?? "Paper cannot be loaded.");
            $flags &= ~CheckFormat::RUN_ALLOWED;
        }

        $this->run_flags |= $flags;
        return $bj;
    }

    protected function body_error_status($error_pages) {
        if ($this->body_pages >= 0.5 * $this->npages
            && $error_pages >= 0.16 * $this->body_pages) {
            return self::ERROR;
        } else {
            return self::WARNING;
        }
    }

    static function banal_page_is_body($pg) {
        return ($pg->pagetype ?? "body") === "body"
            && (isset($pg->c)
                ? $pg->c >= 800
                : (!isset($pg->d) || $pg->d >= 16000 || !isset($pg->columns) || $pg->columns <= 2));
    }

    static function page_message($px) {
        if (empty($px)) {
            return "";
        } else if (count($px) <= 20) {
            return " (" . pluralx($px, "page") . " " . numrangejoin($px) . ")";
        } else {
            return " (including pages " . numrangejoin(array_slice($px, 0, 20)) . ")";
        }
    }

    private function check_banal_json($bj, FormatSpec $spec) {
        if ($bj && isset($bj->cfmsg) && is_array($bj->cfmsg)) {
            foreach ($bj->cfmsg as $m) {
                $this->msg_at($m[0], $m[1], $m[2]);
            }
            return;
        }

        if (!$bj
            || !isset($bj->pages)
            || !isset($bj->papersize)
            || !is_array($bj->pages)
            || !is_array($bj->papersize)
            || count($bj->papersize) != 2) {
            $this->msg_fail("Analysis failure: no pages or paper size.");
            return;
        }

        if (!isset($this->npages)) {
            $this->npages = $bj->npages ?? count($bj->pages);
        }

        // paper size
        if ($spec->papersize) {
            $papersize = $bj->papersize;
            $psdefs = array();
            $ok = false;
            foreach ($spec->papersize as $p) {
                if (abs($p[0] - $papersize[1]) < 9
                    && abs($p[1] - $papersize[0]) < 9) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $this->error_at("papersize", "Paper size mismatch: expected " . commajoin(array_map(function ($d) { return FormatSpec::unparse_dimen($d, "paper"); }, $spec->papersize), "or") . ", got " . FormatSpec::unparse_dimen([$papersize[1], $papersize[0]], "paper") . ".");
            }
        }

        // number of pages
        if ($spec->pagelimit) {
            $pages = $this->npages;
            assert(is_int($pages));
            if ($pages < $spec->pagelimit[0]) {
                $this->warning_at("pagelimit", "Too few pages: expected " . plural($spec->pagelimit[0], "or more page") . ", found " . $pages . ".");
            }
            if ($pages > $spec->pagelimit[1]
                && $spec->unlimitedref
                && count($bj->pages) === $pages) {
                while ($pages > 0
                       && !CheckFormat::banal_page_is_body($bj->pages[$pages - 1])) {
                    --$pages;
                }
            }
            if ($pages > $spec->pagelimit[1]) {
                $this->error_at("pagelimit", "Too many pages: the limit is " . plural($spec->pagelimit[1], $spec->unlimitedref ? "non-reference page" : "page") . ", found " . $pages . ".");
            }
        }
        $this->body_pages = count(array_filter($bj->pages, function ($pg) {
            return CheckFormat::banal_page_is_body($pg);
        }));

        // body pages exist
        if (($spec->columns || $spec->bodyfontsize || $spec->bodylineheight)
            && $this->body_pages < 0.5 * $this->npages) {
            if ($this->body_pages == 0) {
                $this->warning_at(null, "Warning: No pages seemed to contain body text; results may be off.");
            } else {
                $this->warning_at(null, "Warning: Only " . plural($this->body_pages, "page") . " seemed to contain body text; results may be off.");
            }
            $nd0_pages = count(array_filter($bj->pages, function ($pg) {
                return (isset($pg->pagetype) && $pg->pagetype === "blank")
                    || (isset($pg->c) && $pg->c === 0)
                    || (isset($pg->d) && $pg->d === 0);
            }));
            if ($nd0_pages == $this->npages) {
                $this->error_at("notext", "This document appears to contain no text. Perhaps the PDF software used renders pages as images. PDFs like this are less efficient to transfer and harder to search.");
            }
        }

        // number of columns
        if ($spec->columns) {
            $px = array();
            $ncol = $bj->columns ?? 0;
            foreach ($bj->pages as $i => $pg) {
                if (($pp = cvtint($pg->columns ?? $ncol)) > 0
                    && $pp != $spec->columns
                    && self::banal_page_is_body($pg)
                    && $spec->is_checkable($i + 1, "columns")) {
                    $px[] = $i + 1;
                }
            }
            $maxpages = $spec->pagelimit ? $spec->pagelimit[1] : 0;
            if (count($px) > $maxpages * 0.75) {
                $this->warning_at("columns", "Wrong number of columns: expected " . plural($spec->columns, "column") . self::page_message($px) . ".");
            }
        }

        // text block
        if ($spec->textblock) {
            $px = array();
            $py = array();
            $maxx = $maxy = $nbadx = $nbady = 0;
            $docpsiz = $bj->papersize ?? null;
            $docmarg = $bj->margin ?? null;
            foreach ($bj->pages as $i => $pg)
                if (($psiz = $pg->papersize ?? $docpsiz)
                    && is_array($psiz)
                    && ($marg = $pg->margin ?? $docmarg)
                    && is_array($marg)
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
            if (!empty($px)) {
                $this->msg_at("textblock", "Margins too small: text width exceeds "
                    . FormatSpec::unparse_dimen($spec->textblock[0]) . " by "
                    . (count($px) > 1 ? "up to " : "")
                    . ((int) (100 * $maxx / $spec->textblock[0] + .5) - 100)
                    . "%" . self::page_message($px) . ".",
                    $this->body_error_status($nbadx));
            }
            if (!empty($py)) {
                $this->msg_at("textblock", "Margins too small: text height exceeds "
                    . FormatSpec::unparse_dimen($spec->textblock[1]) . " by "
                    . (count($py) > 1 ? "up to " : "")
                    . ((int) (100 * $maxy / $spec->textblock[1] + .5) - 100)
                    . "%" . self::page_message($py) . ".",
                    $this->body_error_status($nbady));
            }
        }

        // font size
        if ($spec->bodyfontsize) {
            $lopx = $hipx = [];
            $minval = 1000;
            $maxval = 0;
            $nbadsize = 0;
            $bfs = $bj->bodyfontsize ?? null;
            foreach ($bj->pages as $i => $pg) {
                if (self::banal_page_is_body($pg)
                    && $spec->is_checkable($i + 1, "bodyfontsize")) {
                    $pp = cvtnum($pg->bodyfontsize ?? $bfs);
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
            }
            if (!empty($lopx)) {
                $this->msg_at("bodyfontsize", "Body font too small: minimum {$spec->bodyfontsize[0]}pt, saw values as small as {$minval}pt" . self::page_message($lopx) . ".", $this->body_error_status($nbadsize));
            }
            if (!empty($hipx)) {
                $this->warning_at("bodyfontsize", "Body font too large: maximum {$spec->bodyfontsize[1]}pt, saw values as large as {$maxval}pt" . self::page_message($hipx) . ".");
            }
        }

        // line height
        if ($spec->bodylineheight) {
            $lopx = $hipx = [];
            $minval = 1000;
            $maxval = 0;
            $nbadsize = 0;
            $l = $bj->leading ?? null;
            foreach ($bj->pages as $i => $pg) {
                if (self::banal_page_is_body($pg)
                    && $spec->is_checkable($i + 1, "bodylineheight")) {
                    $pp = cvtnum($pg->leading ?? $l);
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
            }
            if (!empty($lopx)) {
                $this->msg_at("bodylineheight", "Line height too small: minimum {$spec->bodylineheight[0]}pt, saw values as small as {$minval}pt" . self::page_message($lopx) . ".", $this->body_error_status($nbadsize));
            }
            if (!empty($hipx)) {
                $this->warning_at("bodylineheight", "Line height too large: minimum {$spec->bodylineheight[1]}pt, saw values as large as {$maxval}pt" . self::page_message($hipx) . ".");
            }
        }
    }

    function check(CheckFormat $cf, FormatSpec $spec, PaperInfo $prow, DocumentInfo $doc) {
        if (($bj = $cf->banal_json($doc, $spec))) {
            $cf->check_banal_json($bj, $spec);
        } else {
            assert(($cf->run_flags & CheckFormat::RUN_DESIRED) !== 0);
            $cf->msg_fail(null);
        }
    }

    function report(CheckFormat $cf, FormatSpec $spec, PaperInfo $prow, DocumentInfo $doc) {
        $t = "";
        if ($this->has_problem()) {
            $msgs = [];
            foreach ($this->problem_list() as $mx) {
                $msgs[] = $mx->status > MessageSet::WARNING ? "<strong>{$mx->message}</strong>" : $mx->message;
            }
            if ($this->has_error()) {
                $status = "error";
                $start = "This document violates the submission format requirements. The most serious errors are in bold.";
            } else {
                $status = "warning";
                $start = "This document may violate the submission format requirements.";
            }
            $t .= Ht::msg("<p>$start</p>\n<ul><li>" . join("</li>\n<li>", $msgs) . "</li></ul>\n<p>Submissions that violate the requirements will not be considered. <strong>However,</strong> the automated format checker can misreport errors (for instance, it can miscalculate margins and text sizes for certain figures). If you are confident that the paper respects all format requirements, you may keep the current submission as is.</p>", $status);
        } else if (!$this->has_problem()) {
            $t .= Ht::msg("Congratulations, this document seems to comply with the format guidelines. However, the automated checker may not verify all formatting requirements. It is your responsibility to ensure correct formatting.", "confirm");
        }
        return $t;
    }


    // CHECKING ORCHESTRATION

    function clear() {
        $this->clear_messages();
        $this->npages = null;
        $this->metadata_updates = [];
        $this->run_flags = 0;
    }

    /** @param string $chk */
    private function checker($chk) {
        if ($chk === "banal" || $chk === "CheckFormat") {
            return $this;
        } else {
            if (!isset($this->checkers[$chk])) {
                $this->checkers[$chk] = new $chk;
            }
            return $this->checkers[$chk];
        }
    }

    function check_file($filename, $spec) {
        if (is_string($spec)) {
            $spec = new FormatSpec($spec);
        }
        $this->clear();
        $this->run_flags |= CheckFormat::RUN_STARTED;
        $bj = $this->run_banal($filename);
        $this->check_banal_json($bj, $spec);
    }

    private function truncate_banal_json($bj, FormatSpec $spec) {
        $bj = clone $bj;
        $bj->npages = count($bj->pages);
        $bj->pages = array_slice($bj->pages, 0, 40);
        $bj->cfmsg = [];
        foreach ($this->message_list() as $mx) {
            $bj->cfmsg[] = [$mx->field, $mx->message, $mx->status];
        }
        if ($spec->timestamp) {
            $bj->spects = $spec->timestamp;
        }
        return $bj;
    }

    function check_document(PaperInfo $prow, DocumentInfo $doc = null) {
        assert(!$doc || $doc->prow === $prow);
        $this->clear();
        $this->run_flags |= CheckFormat::RUN_STARTED;
        if (!$doc || $doc->mimetype !== "application/pdf") {
            $this->msg_fail($doc ? "The format checker only works on PDF files." : "No such document.");
            return;
        }

        $done_me = false;
        $spec = $prow->conf->format_spec($doc->documentType);
        foreach ($spec->checkers as $chk) {
            $checker = $this->checker($chk);
            $done_me = $done_me || $checker === $this;
            $checker->check($this, $spec, $prow, $doc);
        }
        if (!$done_me) {
            $this->check($this, $spec, $prow, $doc);
        }

        // save information about the run
        if (!empty($this->metadata_updates)) {
            if (isset($this->metadata_updates["banal"]) && $this->npages > 40) {
                $this->metadata_updates["banal"] = $this->truncate_banal_json($this->metadata_updates["banal"], $spec);
            }
            $doc->update_metadata($this->metadata_updates);
        }
        // record check status in `Paper` table
        if ($prow->is_primary_document($doc)
            && ($this->run_flags & CheckFormat::RUN_DESIRED) === 0
            && $this->check_ok()
            && $spec->timestamp) {
            $x = $this->has_error() ? -$spec->timestamp : $spec->timestamp;
            if ($x != $prow->pdfFormatStatus) {
                $prow->pdfFormatStatus = (string) $x;
                $prow->conf->qe("update Paper set pdfFormatStatus=? where paperId=?", $prow->pdfFormatStatus, $prow->paperId);
            }
        }
    }

    /** @return bool */
    function check_ok() {
        assert(($this->run_flags & CheckFormat::RUN_STARTED) !== 0);
        return !$this->has_error_at("error");
    }

    /** @return bool */
    function allow_recheck() {
        assert(($this->run_flags & CheckFormat::RUN_STARTED) !== 0);
        return ($this->run_flags & CheckFormat::RUN_ALLOWED) !== 0;
    }

    /** @return bool */
    function need_recheck() {
        return ($this->run_flags & CheckFormat::RUN_DESIRED) !== 0;
    }

    /** @return bool */
    function run_attempted() {
        assert(($this->run_flags & CheckFormat::RUN_STARTED) !== 0);
        return ($this->run_flags & CheckFormat::RUN_ATTEMPTED) !== 0;
    }

    function document_report(PaperInfo $prow, DocumentInfo $doc) {
        $spec = $prow->conf->format_spec($doc->documentType);
        foreach ($spec->checkers as $chk)
            if (($checker = $this->checker($chk))
                && $checker !== $this
                && ($report = $checker->report($this, $spec, $prow, $doc))) {
                return $report;
        }
        return $this->report($this, $spec, $prow, $doc);
    }

    function spec_error_kinds($dtype) {
        $spec = $this->conf->format_spec($dtype);
        $ekinds = $this->error_kinds($spec);
        foreach ($spec->checkers as $chk) {
            if (($checker = $this->checker($chk)) && $checker !== $this)
                $ekinds = $ekinds + $checker->error_kinds($spec);
        }
        return $ekinds;
    }
}
