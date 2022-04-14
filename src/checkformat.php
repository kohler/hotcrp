<?php
// checkformat.php -- HotCRP/banal integration
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class CheckFormat extends MessageSet {
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
    /** @var array<string,FormatChecker> */
    private $fcheckers = [];
    /** @var ?string */
    public $banal_stdout;
    /** @var ?string */
    public $banal_stderr;
    /** @var ?int */
    public $banal_status;
    /** @var ?int */
    public $npages;
    /** @var ?int */
    public $nwords;
    /** @var ?int */
    private $body_pages;
    /** @var int */
    public $run_flags = 0;
    /** @var array<string,mixed> */
    public $metadata_updates = [];
    /** @var ?FormatSpec */
    private $last_spec;

    static private $banal_args;
    /** @var int */
    static public $runcount = 0;

    /** @param ?int $allow_run */
    function __construct(Conf $conf, $allow_run = null) {
        $this->allow_run = $allow_run ?? self::RUN_ALWAYS;
        $this->conf = $conf;
        if (self::$banal_args === null) {
            $z = $this->conf->opt("banalZoom");
            self::$banal_args = $z ? "-zoom=$z" : "";
        }
        $this->fcheckers["default"] = new Default_FormatChecker;
        $this->set_want_ftext(true, 5);
    }

    /** @param string $what
     * @return MessageItem
     * @deprecated */
    function msg_fail($what) {
        return $this->error_at("error", $what);
    }

    /** @param string $cmd
     * @param string $dir
     * @param array<string,string> $env
     * @return array{int,string,string} */
    static function run_command_safely($cmd, $dir, $env) {
        $descriptors = [["file", "/dev/null", "r"], ["pipe", "wb"], ["pipe", "wb"]];
        $pipes = null;
        $proc = proc_open($cmd, $descriptors, $pipes, $dir, $env);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = "";
        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $x = fread($pipes[1], 32768);
            $y = fread($pipes[2], 32768);
            $stdout .= $x;
            $stderr .= $y;
            if ($x === false || $y === false) {
                break;
            }
            $r = [$pipes[1], $pipes[2]];
            $w = $e = [];
            stream_select($r, $w, $e, 5);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        return [$status, $stdout, $stderr];
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
        $tstart = microtime(true);
        list($this->banal_status, $this->banal_stdout, $this->banal_stderr) =
            self::run_command_safely($banal_run, SiteLoader::$root, $env);
        ++self::$runcount;
        $banal_time = microtime(true) - $tstart;
        Conf::$blocked_time += $banal_time;
        if (self::DEBUG && Conf::$blocked_time > 0.1) {
            error_log(sprintf("%.6f: +%.6f %s", Conf::$blocked_time, $banal_time, $banal_run));
        }
        return json_decode($this->banal_stdout);
    }

    function banal_json(DocumentInfo $doc, FormatSpec $spec) {
        if ($this->allow_run === CheckFormat::RUN_IF_NECESSARY_TIMEOUT
            && Conf::$blocked_time >= CheckFormat::TIMEOUT) {
            $allow_run = CheckFormat::RUN_NEVER;
        } else {
            $allow_run = $this->allow_run;
        }

        $bj = null;
        if (($m = $doc->metadata()) && isset($m->banal)) {
            $bj = $m->banal;
            $this->npages = is_int($bj->npages ?? null) ? $bj->npages : count($bj->pages);
            $this->nwords = is_int($bj->w ?? null) ? $bj->w : null;
        }
        $bj_ok = $bj
            && $bj->at >= @filemtime(SiteLoader::find("src/banal"))
            && ($bj->args ?? null) == self::$banal_args
            && (!isset($bj->npages)
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
                $this->error_at("error", "<0>Server too busy to check paper formats");
                $this->inform_at("error", "<0>This is a transient error; feel free to try again.");
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
                $this->nwords = is_int($bj->w ?? null) ? $bj->w : null;
                $this->metadata_updates["npages"] = $this->npages;
                $this->metadata_updates["banal"] = $bj;
                $flags &= ~(CheckFormat::RUN_ALLOWED | CheckFormat::RUN_DESIRED);
            } else {
                $mi = $this->error_at("error", "<0>File cannot be processed");
                $mi->landmark = $doc->export_filename();
                $this->inform_at("error", "<0>The file may be corrupted or not in PDF format.");
            }

            if ($limit > 0) {
                $doc->conf->q("update Settings set value=value-1 where name='__banal_count' and data='$t'");
            }
        } else {
            if (!$doc->has_error()) { error_log($doc->export_filename() . ": no content, no error"); }
            foreach ($doc->message_list() as $mi) {
                $this->append_item($mi->with_landmark($doc->export_filename()));
            }
            $flags &= ~CheckFormat::RUN_ALLOWED;
        }

        $this->run_flags |= $flags;
        return $bj;
    }

    /** @return int */
    protected function body_error_status($error_pages) {
        if ($this->body_pages >= 0.5 * $this->npages
            && $error_pages >= 0.16 * $this->body_pages) {
            return self::ERROR;
        } else {
            return self::WARNING;
        }
    }

    /** @return bool */
    static function banal_page_is_body($pg) {
        // Modern banal already includes the `pg->c` test
        return ($pg->type ?? $pg->pagetype ?? "body") === "body"; /* XXX pagetype obsolete */
    }

    /** @return string */
    static function page_message($px) {
        if (empty($px)) {
            return "";
        } else if (count($px) <= 20) {
            return " (" . pluralx($px, "page") . " " . numrangejoin($px) . ")";
        } else {
            return " (including pages " . numrangejoin(array_slice($px, 0, 20)) . ")";
        }
    }

    function check_banal_json($bj, FormatSpec $spec) {
        if ($bj
            && isset($bj->cfmsg)
            && is_array($bj->cfmsg)
            && $spec->timestamp === ($bj->spects ?? null)) {
            foreach ($bj->cfmsg as $m) {
                if ($m[1] === "" || str_starts_with($m[1], "<")) {
                    $this->msg_at($m[0], $m[1], $m[2]);
                } else {
                    $this->msg_at($m[0], "<0>$m[1]", $m[2]); // XXX backward compat
                }
            }
            return;
        }

        if (!$bj
            || !isset($bj->pages)
            || !isset($bj->papersize)
            || !is_array($bj->pages)
            || !is_array($bj->papersize)
            || count($bj->papersize) != 2) {
            $this->error_at("error", "<0>Analysis failure: no pages or paper size");
            return;
        }

        if (!isset($this->npages)) {
            $this->npages = $bj->npages ?? count($bj->pages);
        }
        if (!isset($this->nwords)) {
            $this->nwords = $bj->w ?? null;
        }

        // paper size
        if ($spec->papersize) {
            $papersize = $bj->papersize;
            $ok = false;
            foreach ($spec->papersize as $p) {
                if (abs($p[0] - $papersize[1]) < 9
                    && abs($p[1] - $papersize[0]) < 9) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $this->problem_at("papersize", "<0>Paper size mismatch: expected " . commajoin(array_map(function ($d) { return FormatSpec::unparse_dimen($d, "paper"); }, $spec->papersize), "or") . ", got " . FormatSpec::unparse_dimen([$papersize[1], $papersize[0]], "paper"), 2);
            }
        }

        // number of pages
        if ($spec->pagelimit) {
            $pages = $this->npages;
            assert(is_int($pages));
            if ($pages < $spec->pagelimit[0]) {
                $this->problem_at("pagelimit", "<0>Too few pages: expected " . plural($spec->pagelimit[0], "or more page") . ", found {$pages}", 1);
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
                $this->problem_at("pagelimit", "<0>Too many pages: the limit is " . plural($spec->pagelimit[1], $spec->unlimitedref ? "non-reference page" : "page") . ", found {$pages}", 2);
            }
        }
        $this->body_pages = count(array_filter($bj->pages, function ($pg) {
            return CheckFormat::banal_page_is_body($pg);
        }));

        // number of columns
        if ($spec->columns) {
            $px = [];
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
                $this->problem_at("columns", "<0>Wrong number of columns: expected " . plural($spec->columns, "column") . self::page_message($px));
            }
        }

        // text block
        if ($spec->textblock) {
            $px = [];
            $py = [];
            $maxx = $maxy = $nbadx = $nbady = 0;
            $docpsiz = $bj->papersize ?? null;
            $docmarg = $bj->m ?? $bj->margin ?? null;
            foreach ($bj->pages as $i => $pg)
                if (($psiz = $pg->papersize ?? $docpsiz)
                    && is_array($psiz)
                    && ($marg = $pg->m ?? $pg->margin ?? $docmarg)
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
                $this->problem_at("textblock", "<0>Margins too small: text width exceeds "
                    . FormatSpec::unparse_dimen($spec->textblock[0]) . " by "
                    . (count($px) > 1 ? "up to " : "")
                    . ((int) (100 * $maxx / $spec->textblock[0] + .5) - 100)
                    . "%" . self::page_message($px),
                    $this->body_error_status($nbadx));
            }
            if (!empty($py)) {
                $this->problem_at("textblock", "<0>Margins too small: text height exceeds "
                    . FormatSpec::unparse_dimen($spec->textblock[1]) . " by "
                    . (count($py) > 1 ? "up to " : "")
                    . ((int) (100 * $maxy / $spec->textblock[1] + .5) - 100)
                    . "%" . self::page_message($py),
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
                $this->problem_at("bodyfontsize", "<0>Body font too small: minimum {$spec->bodyfontsize[0]}pt, saw values as small as {$minval}pt" . self::page_message($lopx), $this->body_error_status($nbadsize));
            }
            if (!empty($hipx)) {
                $this->problem_at("bodyfontsize", "<0>Body font too large: maximum {$spec->bodyfontsize[1]}pt, saw values as large as {$maxval}pt" . self::page_message($hipx));
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
                $this->problem_at("bodylineheight", "<0>Line height too small: minimum {$spec->bodylineheight[0]}pt, saw values as small as {$minval}pt" . self::page_message($lopx), $this->body_error_status($nbadsize));
            }
            if (!empty($hipx)) {
                $this->problem_at("bodylineheight", "<0>Line height too large: minimum {$spec->bodylineheight[1]}pt, saw values as large as {$maxval}pt" . self::page_message($hipx));
            }
        }

        // number of words
        if ($spec->wordlimit && $this->nwords === null) {
            $this->problem_at("wordlimit", "<0>Unable to count words in this PDF");
        } else if ($spec->wordlimit) {
            $words = $this->nwords;
            if ($words < $spec->wordlimit[0]) {
                $this->problem_at("wordlimit", "<0>Too few words: expected " . plural($spec->wordlimit[0], "or more word") . ", found {$words}", 1);
            }
            if ($words > $spec->wordlimit[1]) {
                $this->problem_at("wordlimit", "<0>Too many words: the limit is " . plural($spec->wordlimit[1], "non-reference word") . ", found {$words}", 2);
            }
        }

        // body pages exist
        if (($spec->columns || $spec->bodyfontsize || $spec->bodylineheight)
            && $this->body_pages < 0.5 * $this->npages) {
            if ($this->body_pages == 0) {
                $this->warning_at(null, "<0>Warning: No pages containing body text; results may be off");
            } else if ($this->body_pages < 10) {
                $this->warning_at(null, "<0>Warning: Only {$this->body_pages} of " . plural($this->npages, "page") . " contain body text; results may be off");
            }
            $nd0_pages = count(array_filter($bj->pages, function ($pg) {
                return ($pg->type ?? $pg->pagetype ?? "body") === "blank";
            }));
            if ($nd0_pages == $this->npages) {
                $this->problem_at("notext", "<0>This document appears to contain no text", 2);
                $this->msg_at("notext", "<0>The PDF software used renders pages as images. PDFs like this are less efficient to transfer and harder to search.", MessageSet::INFORM);
            }
        }
    }


    // CHECKING ORCHESTRATION

    function clear() {
        $this->clear_messages();
        $this->npages = $this->nwords = null;
        $this->metadata_updates = [];
        $this->run_flags = 0;
    }

    /** @param FormatSpec $spec
     * @return list<FormatChecker> */
    private function spec_checkers($spec) {
        $chk = [];
        $has_default = false;
        foreach ($spec->checkers as $c) {
            if ($c === "") {
                $c = "default";
            }
            if (!isset($this->fcheckers[$c])) {
                /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName, PhanUndeclaredClass */
                $this->fcheckers[$c] = new $c;
            }
            $chk[] = $this->fcheckers[$c];
            $has_default = $has_default || $c === "default";
        }
        if (!$has_default) {
            $chk[] = $this->fcheckers["default"];
        }
        return $chk;
    }

    /** @param string $filename
     * @param string|FormatSpec $spec */
    function check_file($filename, $spec) {
        if (is_string($spec)) {
            $spec = new FormatSpec($spec);
        }
        $this->clear();
        $this->run_flags |= CheckFormat::RUN_STARTED;
        $bj = $this->run_banal($filename);
        $this->check_banal_json($bj, $spec);
    }

    /** @param object $bj
     * @return object */
    private function truncate_banal_json($bj, FormatSpec $spec) {
        $xj = clone $bj;
        if (isset($xj->npages) ? $xj->npages < count($bj->pages) : count($bj->pages) > 48) {
            $xj->npages = count($bj->pages);
        }
        $xj->pages = [];
        $bjpages = $bj->pages ?? [];
        '@phan-var-force list<object> $bjpages';
        for ($i = 0; $i !== 48 && $i !== count($bjpages); ++$i) {
            $pg = $bjpages[$i];
            $xg = [];
            if (isset($pg->papersize)) {
                $xg["papersize"] = $pg->papersize;
            }
            if (isset($pg->m) || isset($pg->margin)) {
                $xg["m"] = $pg->m ?? $pg->margin;
            }
            if (isset($pg->bodyfontsize)) {
                $xg["bodyfontsize"] = $pg->bodyfontsize;
            }
            if (isset($pg->leading)) {
                $xg["leading"] = $pg->leading;
            }
            if (isset($pg->columns)) {
                $xg["columns"] = $pg->columns;
            }
            if (isset($pg->type) || isset($pg->pagetype)) {
                $xg["type"] = $pg->type ?? $pg->pagetype;
            }
            $xj->pages[] = (object) $xg;
        }
        if ($this->has_message()) {
            $xj->cfmsg = [];
            foreach ($this->message_list() as $mx) {
                $xj->cfmsg[] = [$mx->field, $mx->message, $mx->status];
            }
        }
        if ($spec->timestamp) {
            $xj->spects = $spec->timestamp;
        }
        return $xj;
    }

    function check_document(DocumentInfo $doc) {
        $this->clear();
        $this->run_flags |= CheckFormat::RUN_STARTED;
        if ($doc->mimetype !== "application/pdf") {
            $this->error_at("error", "<0>The format checker only works on PDF files");
            return;
        }

        $spec = $doc->conf->format_spec($doc->documentType);
        $checkers = $this->spec_checkers($spec);
        if ($spec !== $this->last_spec) {
            $this->last_spec = $spec;
            $this->clear_status_for_problem_at();
            foreach ($checkers as $checker) {
                $checker->prepare($this, $spec);
            }
        }
        foreach ($checkers as $checker) {
            $checker->check($this, $spec, $doc);
        }

        // save information about the run
        if (!empty($this->metadata_updates)) {
            if (isset($this->metadata_updates["banal"])) {
                $this->metadata_updates["banal"] = $this->truncate_banal_json($this->metadata_updates["banal"], $spec);
            }
            $doc->update_metadata($this->metadata_updates);
        }
        // record check status in `Paper` table
        if ($doc->prow->is_primary_document($doc)
            && ($this->run_flags & CheckFormat::RUN_DESIRED) === 0
            && $this->check_ok()
            && $spec->timestamp) {
            $x = $this->has_error() ? -$spec->timestamp : $spec->timestamp;
            if ($x != $doc->prow->pdfFormatStatus) {
                $doc->prow->pdfFormatStatus = (string) $x;
                $doc->conf->qe("update Paper set pdfFormatStatus=? where paperId=?", $doc->prow->pdfFormatStatus, $doc->paperId);
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

    /** @return MessageItem */
    function front_report_item() {
        if ($this->has_error()) {
            return new MessageItem(null, "<5>This document violates the submission format requirements", MessageSet::ERROR);
        } else if ($this->has_problem()) {
            return new MessageItem(null, "<0>This document may violate the submission format requirements", MessageSet::WARNING);
        } else {
            return new MessageItem(null, "<0>Congratulations, this document seems to comply with the format guidelines. However, the automated checker may not verify all formatting requirements. It is your responsibility to ensure correct formatting.", MessageSet::SUCCESS);
        }
    }

    /** @return string */
    function document_report(DocumentInfo $doc) {
        $spec = $doc->conf->format_spec($doc->documentType);
        $report = null;
        foreach ($this->spec_checkers($spec) as $checker) {
            $report = $report ?? $checker->report($this, $spec, $doc);
        }
        return $report ?? "";
    }

    /** @param int $dtype
     * @return list<string> */
    function known_fields($dtype) {
        $spec = $this->conf->format_spec($dtype);
        $ekinds = [];
        foreach ($this->spec_checkers($spec) as $checker) {
            $ckinds = $checker->known_fields($spec);
            $ekinds = empty($ekinds) ? $ckinds : array_unique(array_merge($ekinds, $ckinds));
        }
        return $ekinds;
    }
}

class Default_FormatChecker implements FormatChecker {
    /** @return list<string> */
    function known_fields(FormatSpec $spec) {
        $ks = [];
        foreach (["papersize", "pagelimit", "wordlimit", "columns", "textblock", "bodyfontsize", "bodylineheight"] as $k) {
            if ($spec->unparse_key($k) !== "")
                $ks[] = $k;
        }
        return $ks;
    }

    /** @return void */
    function prepare(CheckFormat $cf, FormatSpec $spec) {
    }

    /** @return void */
    function check(CheckFormat $cf, FormatSpec $spec, DocumentInfo $doc) {
        if (($bj = $cf->banal_json($doc, $spec))) {
            $cf->check_banal_json($bj, $spec);
        } else {
            assert(($cf->run_flags & CheckFormat::RUN_DESIRED) !== 0);
            $cf->error_at("error", null);
        }
    }

    /** @return string */
    function report(CheckFormat $cf, FormatSpec $spec, DocumentInfo $doc) {
        $msgs = [$cf->front_report_item()];
        if ($cf->has_error()) {
            $msgs[0]->message = "<5><strong>" . $msgs[0]->message_as(5) . ".</strong> The most serious errors are marked with <span class=\"error-mark\"></span>.";
        }
        if ($cf->has_problem()) {
            $msgs[] = new MessageItem(null, "<5>Submissions that violate the requirements will not be considered. However, some violation reports may be false positives (for instance, the checker can miscalculate margins and text sizes for figures). If you are confident that the current document respects all format requirements, keep it as is.", MessageSet::INFORM);
        }
        return Ht::feedback_msg(array_merge($msgs, iterator_to_array($cf->problem_list())));
    }
}
