<?php
// checkformat.php -- HotCRP/banal integration
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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
    const RUN_HAS_BANAL = 16;
    const RUN_ABANDONED = 32;

    /** @var bool */
    const DEBUG = false;

    /** @var Conf */
    private $conf;
    /** @var int */
    public $allow_run;
    /** @var array<string,FormatChecker> */
    private $fcheckers = [];
    /** @var ?DocumentInfo */
    private $last_doc;
    /** @var ?FormatSpec */
    private $last_spec;
    /** @var ?object */
    private $last_banal;
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
    /** @var int */
    private $run_flags = 0;

    static private $banal_args;
    /** @var int */
    static public $runcount = 0;

    /** @param ?int $allow_run */
    function __construct(Conf $conf, $allow_run = null) {
        $this->allow_run = $allow_run ?? self::RUN_ALWAYS;
        $this->conf = $conf;
        if (self::$banal_args === null) {
            $z = $this->conf->opt("banalZoom");
            self::$banal_args = $z ? "-zoom={$z}" : "";
        }
        $this->fcheckers["default"] = new Default_FormatChecker;
        $this->set_want_ftext(true, 5);
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
        $pdftohtml = $this->conf->opt("pdftohtmlCommand")
            ?? $this->conf->opt("pdftohtml") /* XXX */;
        if ($pdftohtml) {
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

    /** @param mixed $x
     * @return ?object */
    static private function validate_banal_json($x) {
        if (!is_object($x)
            || !is_int($x->at ?? null)
            || !is_array($x->pages ?? [])
            || !is_int($x->npages ?? 1)) {
            return null;
        }
        return $x;
    }

    /** @param ?object $bj
     * @param int $flags
     * @return ?object */
    private function complete_banal_json($bj, $flags) {
        $this->last_banal = $bj;
        $this->run_flags |= $flags | self::RUN_HAS_BANAL;
        if ($bj) {
            $this->npages = is_int($bj->npages ?? null) ? $bj->npages : count($bj->pages);
            $this->nwords = is_int($bj->w ?? null) ? $bj->w : null;
            $this->last_doc->set_prop("npages", $this->npages); // head off recursion
        }
        return $bj;
    }

    /** @return ?object */
    function banal_json() {
        assert($this->last_doc !== null);
        if (($this->run_flags & self::RUN_HAS_BANAL) !== 0) {
            return $this->last_banal;
        }

        $allow_run = $this->allow_run;
        if ($allow_run === CheckFormat::RUN_IF_NECESSARY_TIMEOUT
            && Conf::$blocked_time >= CheckFormat::TIMEOUT) {
            $allow_run = CheckFormat::RUN_NEVER;
        }

        // maybe extract cached banal JSON from document
        $doc = $this->last_doc;
        $bj = null;
        if (($metadata = $doc->metadata())
            && isset($metadata->banal)) {
            $bj = self::validate_banal_json($metadata->banal);
        }

        // check whether to skip run (cached JSON exists, matches spec)
        if ($bj
            && ($bj->args ?? "") === (self::$banal_args ?? "")
            && $bj->at >= @filemtime(SiteLoader::find("src/banal"))
            && ($allow_run !== CheckFormat::RUN_ALWAYS
                || $bj->at >= Conf::$now - 86400)
            && (!isset($bj->npages) /* i.e., banal JSON is not truncated */
                || ($this->last_spec->timestamp
                    && isset($bj->msx)
                    && is_array($bj->msx)
                    && ($bj->msx[0] ?? null) === $this->last_spec->timestamp))) {
            // existing banal JSON should suffice
            $flags = $bj->at >= Conf::$now - 86400 ? 0 : CheckFormat::RUN_ALLOWED;
            return $this->complete_banal_json($bj, $flags);
        }

        // we want to run, but may not be allowed to
        $flags = CheckFormat::RUN_DESIRED | CheckFormat::RUN_ALLOWED;
        if ($allow_run === CheckFormat::RUN_NEVER) {
            return $this->complete_banal_json($bj, $flags | CheckFormat::RUN_ABANDONED);
        }

        $path = $doc->content_file();
        if (!$path) {
            foreach ($doc->message_list() as $mi) {
                $this->append_item($mi->with_landmark($doc->export_filename()));
            }
            return $this->complete_banal_json($bj, $flags & ~CheckFormat::RUN_ALLOWED);
        }

        // constrain the number of concurrent banal executions to banalLimit
        // (counter resets every 2 seconds)
        $t = (int) (time() / 2);
        $n = ($doc->conf->setting_data("__banal_count") == $t ? $doc->conf->setting("__banal_count") + 1 : 1);
        $limit = $doc->conf->opt("banalLimit") ?? 8;
        if ($limit > 0) {
            if ($n > $limit) {
                $this->error_at("error", "<0>Server too busy to check paper formats");
                $this->inform_at("error", "<0>This is a transient error; feel free to try again.");
                return $this->complete_banal_json($bj, $flags | CheckFormat::RUN_ABANDONED);
            }
            $doc->conf->q("insert into Settings (name,value,data) values ('__banal_count',{$n},'{$t}') on duplicate key update value={$n}, data='{$t}'");
        }

        $flags |= CheckFormat::RUN_ATTEMPTED;
        if (($xbj = self::validate_banal_json($this->run_banal($path)))) {
            $flags &= ~(CheckFormat::RUN_ALLOWED | CheckFormat::RUN_DESIRED);
            $bj = $xbj;
        } else {
            $this->unprocessable_error($doc);
        }

        if ($limit > 0) {
            $doc->conf->q("update Settings set value=value-1 where name='__banal_count' and data='{$t}'");
        }
        return $this->complete_banal_json($bj, $flags);
    }

    /** @param DocumentInfo $doc */
    function unprocessable_error($doc) {
        if (!$this->has_error_at("error")) {
            $mi = $this->error_at("error", "<0>File may be corrupt or not in PDF format");
            $mi->landmark = $doc->export_filename();
        }
    }

    /** @return 'body'|'blank'|'cover'|'appendix'|'bib'|'figure' */
    static function banal_page_type($pg) {
        return $pg->type ?? $pg->pagetype ?? "body"; /* XXX pagetype obsolete */
    }

    /** @return bool */
    static function banal_page_is_body($pg) {
        return self::banal_page_type($pg) === "body";
    }

    /** @return string */
    static function page_message($px) {
        if (empty($px)) {
            return "";
        } else if (count($px) <= 20) {
            return " (" . plural_word($px, "page") . " " . numrangejoin($px) . ")";
        } else {
            return " (including pages " . numrangejoin(array_slice($px, 0, 20)) . ")";
        }
    }

    /** @return ?int */
    function npages() {
        if ($this->npages === null) {
            $this->banal_json();
        }
        return $this->npages;
    }


    // CHECKING ORCHESTRATION

    function clear() {
        $this->clear_messages();
        $this->last_doc = $this->last_banal = null;
        $this->npages = $this->nwords = null;
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

    /** @param null|string|FormatSpec $xspec
     * @return bool */
    function check_document(DocumentInfo $doc, $xspec = null) {
        $this->clear();
        $this->run_flags |= CheckFormat::RUN_STARTED;
        if ($doc->mimetype !== "application/pdf") {
            $this->error_at("error", "<0>The format checker only works on PDF files");
            return false;
        }

        if ($xspec === null) {
            $spec = $doc->conf->format_spec($doc->documentType);
        } else if (is_string($xspec)) {
            $spec = new FormatSpec($xspec);
        } else {
            $spec = $xspec;
        }

        $checkers = $this->spec_checkers($spec);
        if ($spec !== $this->last_spec) {
            $this->last_spec = $spec;
            $this->clear_status_for_problem_at();
            foreach ($checkers as $checker) {
                $checker->prepare($this, $spec);
            }
        }
        $this->last_doc = $doc;
        foreach ($checkers as $checker) {
            $checker->check($this, $spec, $doc);
        }

        // save information about the run
        if ($xspec === null) {
            $doc->save_prop();
        }
        // record check status in `Paper` table
        if ($doc->prow
            && $doc->prow->is_primary_document($doc)
            && ($this->run_flags & CheckFormat::RUN_DESIRED) === 0
            && $this->check_ok()
            && $spec->timestamp) {
            $x = $this->has_error() ? -$spec->timestamp : $spec->timestamp;
            if ($x != $doc->prow->pdfFormatStatus) {
                $doc->prow->pdfFormatStatus = (string) $x;
                $doc->conf->qe("update Paper set pdfFormatStatus=? where paperId=?", $doc->prow->pdfFormatStatus, $doc->paperId);
            }
        }

        $this->last_doc = $this->last_banal = null;
        return ($this->run_flags & CheckFormat::RUN_ABANDONED) === 0;
    }

    /** @return bool */
    function check_ok() {
        assert(($this->run_flags & CheckFormat::RUN_STARTED) !== 0);
        return ($this->run_flags & CheckFormat::RUN_ABANDONED) === 0;
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

    /** @return MessageSet */
    function document_messages(DocumentInfo $doc) {
        $spec = $doc->conf->format_spec($doc->documentType);
        $ms = new MessageSet;
        foreach ($this->spec_checkers($spec) as $checker) {
            if ($checker->append_report($this, $spec, $doc, $ms))
                break;
        }
        return $ms;
    }

    /** @return string */
    function document_report(DocumentInfo $doc) {
        $ms = $this->document_messages($doc);
        return $ms->has_message() ? Ht::feedback_msg($ms) : "";
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
    /** @var int */
    private $npages;
    /** @var int */
    private $body_pages;

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

    /** @return int */
    private function body_error_status($error_pages) {
        if ($this->body_pages >= 0.5 * $this->npages
            && $error_pages >= 0.16 * $this->body_pages) {
            return MessageSet::ERROR;
        } else {
            return MessageSet::WARNING;
        }
    }

    /** @return void */
    function check(CheckFormat $cf, FormatSpec $spec, DocumentInfo $doc) {
        $bj = $cf->banal_json();
        if (!$bj) {
            return;
        }

        // maybe use existing messages
        if ($spec->timestamp
            && isset($bj->msx)
            && is_array($bj->msx)
            && ($bj->msx[0] ?? null) === $spec->timestamp) {
            for ($i = 1; $i !== count($bj->msx); ++$i) {
                $mx = $bj->msx[$i];
                $mi = $cf->msg_at($mx[0], $mx[1], $mx[2]);
                if (isset($mx[3])) {
                    $mi->landmark = $mx[3];
                }
            }
            return;
        }

        // analyze JSON, store info
        $this->npages = $cf->npages;
        $this->body_pages = count(array_filter($bj->pages, function ($pg) {
            return CheckFormat::banal_page_is_body($pg);
        }));

        // check spec
        $nmsg0 = $cf->message_count();

        if (!isset($bj->papersize)
            || !is_array($bj->papersize)
            || count($bj->papersize) != 2) {
            $cf->unprocessable_error($doc);
        } else {
            if ($spec->papersize) {
                $this->check_papersize($cf, $bj, $spec);
            }
            if ($spec->pagelimit) {
                $this->check_pagelimit($cf, $bj, $spec);
            }
            if ($spec->columns) {
                $this->check_columns($cf, $bj, $spec);
            }
            if ($spec->textblock) {
                $this->check_textblock($cf, $bj, $spec);
            }
            if ($spec->bodyfontsize) {
                $this->check_bodyfontsize($cf, $bj, $spec);
            }
            if ($spec->bodylineheight) {
                $this->check_bodylineheight($cf, $bj, $spec);
            }
            if ($spec->wordlimit) {
                $this->check_wordlimit($cf, $bj, $spec);
            }
            if ($spec->columns || $spec->bodyfontsize || $spec->bodylineheight) {
                $this->check_body_pages_exist($cf, $bj);
            }
        }

        // store messages in metadata
        if ($cf->run_attempted()) {
            $doc->set_prop("banal", self::truncate_banal_json($bj, $cf, $nmsg0, $spec));
        }
    }

    /** @param object $bj */
    private function check_papersize(CheckFormat $cf, $bj, FormatSpec $spec) {
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
            $cf->problem_at("papersize", "<0>Paper size mismatch: expected " . commajoin(array_map(function ($d) { return FormatSpec::unparse_dimen($d, "paper"); }, $spec->papersize), "or") . ", got " . FormatSpec::unparse_dimen([$papersize[1], $papersize[0]], "paper"), 2);
        }
    }

    /** @param object $bj */
    private function check_pagelimit(CheckFormat $cf, $bj, FormatSpec $spec) {
        $pages = $this->npages;
        assert(is_int($pages));
        if ($pages < $spec->pagelimit[0]) {
            $cf->problem_at("pagelimit", "<0>Too few pages: expected " . plural($spec->pagelimit[0], "or more page") . ", found {$pages}", 1);
        }
        if ($pages > $spec->pagelimit[1]
            && $spec->unlimitedref
            && count($bj->pages) === $pages) {
            while ($pages > 0
                   && !CheckFormat::banal_page_is_body($bj->pages[$pages - 1])) {
                --$pages;
            }
        }
        if ($pages <= $spec->pagelimit[1]) {
            return;
        } else if (!$spec->unlimitedref) {
            $cf->problem_at("pagelimit", "<0>Too many pages: the limit is " . plural($spec->pagelimit[1], "page") . ", found {$pages}", 2);
            return;
        }
        $cf->problem_at("pagelimit", "<0>Too many pages: the limit is " . plural($spec->pagelimit[1], "non-reference page") . ", found {$pages}", 2);
        if (count($bj->pages) !== $this->npages) {
            return;
        }
        $p = 0;
        $last_fs = 0;
        while ($p < $pages
               && ($pt = CheckFormat::banal_page_type($bj->pages[$p])) !== "bib"
               && $pt !== "appendix") {
            $last_fs = $bj->pages[$p]->fs ?? $last_fs;
            ++$p;
        }
        if ($p <= $spec->pagelimit[1] && $last_fs > 0) {
            while ($p < $pages
                   && !CheckFormat::banal_page_is_body($bj->pages[$p])) {
                ++$p;
            }
            if ($p < $pages
                && ($bj->pages[$p]->fs ?? $last_fs) > $last_fs) {
                $cf->msg_at("pagelimit", "<5>It looks like this PDF might use normal section numbers for its appendixes. Appendix sections should use letters, like ‘A’ and ‘B’. If using LaTeX, start the appendixes with the <code>\appendix</code> command.", MessageSet::INFORM);
            }
        }
    }

    /** @param object $bj */
    private function check_columns(CheckFormat $cf, $bj, FormatSpec $spec) {
        $px = [];
        $ncol = $bj->columns ?? 0;
        foreach ($bj->pages as $i => $pg) {
            if (($pp = stoi($pg->columns ?? $ncol) ?? -1) > 0
                && $pp != $spec->columns
                && CheckFormat::banal_page_is_body($pg)
                && $spec->is_checkable($i + 1, "columns")) {
                $px[] = $i + 1;
            }
        }
        $maxpages = $spec->pagelimit ? $spec->pagelimit[1] : 0;
        if (count($px) > $maxpages * 0.75) {
            $cf->problem_at("columns", "<0>Wrong number of columns: expected " . plural($spec->columns, "column") . CheckFormat::page_message($px));
        }
    }

    /** @param object $bj */
    private function check_textblock(CheckFormat $cf, $bj, FormatSpec $spec) {
        $px = [];
        $py = [];
        $maxx = $maxy = $nbadx = $nbady = 0;
        $docpsiz = $bj->papersize ?? null;
        $docmarg = $bj->m ?? $bj->margin ?? null;
        foreach ($bj->pages as $i => $pg) {
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
        }
        if (!empty($px)) {
            $cf->problem_at("textblock", "<0>Margins too small: text width exceeds "
                . FormatSpec::unparse_dimen($spec->textblock[0]) . " by "
                . (count($px) > 1 ? "up to " : "")
                . ((int) (100 * $maxx / $spec->textblock[0] + .5) - 100)
                . "%" . CheckFormat::page_message($px),
                $this->body_error_status($nbadx));
        }
        if (!empty($py)) {
            $cf->problem_at("textblock", "<0>Margins too small: text height exceeds "
                . FormatSpec::unparse_dimen($spec->textblock[1]) . " by "
                . (count($py) > 1 ? "up to " : "")
                . ((int) (100 * $maxy / $spec->textblock[1] + .5) - 100)
                . "%" . CheckFormat::page_message($py),
                $this->body_error_status($nbady));
        }
    }

    /** @param object $bj */
    private function check_bodyfontsize(CheckFormat $cf, $bj, FormatSpec $spec) {
        $lopx = $hipx = [];
        $minval = 1000;
        $maxval = 0;
        $nbadsize = 0;
        $bfs = $bj->bodyfontsize ?? null;
        foreach ($bj->pages as $i => $pg) {
            if (CheckFormat::banal_page_is_body($pg)
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
            $cf->problem_at("bodyfontsize", "<0>Body font too small: minimum {$spec->bodyfontsize[0]}pt, saw values as small as {$minval}pt" . CheckFormat::page_message($lopx), $this->body_error_status($nbadsize));
        }
        if (!empty($hipx)) {
            $cf->problem_at("bodyfontsize", "<0>Body font too large: maximum {$spec->bodyfontsize[1]}pt, saw values as large as {$maxval}pt" . CheckFormat::page_message($hipx));
        }
    }

    /** @param object $bj */
    private function check_bodylineheight(CheckFormat $cf, $bj, FormatSpec $spec) {
        $lopx = $hipx = [];
        $minval = 1000;
        $maxval = 0;
        $nbadsize = 0;
        $l = $bj->leading ?? null;
        foreach ($bj->pages as $i => $pg) {
            if (CheckFormat::banal_page_is_body($pg)
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
            $cf->problem_at("bodylineheight", "<0>Line height too small: minimum {$spec->bodylineheight[0]}pt, saw values as small as {$minval}pt" . CheckFormat::page_message($lopx), $this->body_error_status($nbadsize));
        }
        if (!empty($hipx)) {
            $cf->problem_at("bodylineheight", "<0>Line height too large: minimum {$spec->bodylineheight[1]}pt, saw values as large as {$maxval}pt" . CheckFormat::page_message($hipx));
        }
    }

    /** @param object $bj */
    private function check_wordlimit(CheckFormat $cf, $bj, FormatSpec $spec) {
        if ($cf->nwords === null) {
            $cf->problem_at("wordlimit", "<0>Unable to count words in this PDF");
            return;
        }
        if ($cf->nwords < $spec->wordlimit[0]) {
            $cf->problem_at("wordlimit", "<0>Too few words: expected " . plural($spec->wordlimit[0], "or more word") . ", found {$cf->nwords}", 1);
        }
        if ($cf->nwords > $spec->wordlimit[1]) {
            $cf->problem_at("wordlimit", "<0>Too many words: the limit is " . plural($spec->wordlimit[1], "non-reference word") . ", found {$cf->nwords}", 2);
        }
    }

    /** @param object $bj */
    private function check_body_pages_exist(CheckFormat $cf, $bj) {
        if ($this->body_pages >= 0.5 * $this->npages) {
            return;
        }
        if ($this->body_pages == 0) {
            $cf->warning_at(null, "<0>Warning: No pages containing body text; results may be off");
        } else if ($this->body_pages < 10) {
            $cf->warning_at(null, "<0>Warning: Only {$this->body_pages} of " . plural($this->npages, "page") . " contain body text; results may be off");
        }
        $nd0_pages = count(array_filter($bj->pages, function ($pg) {
            return CheckFormat::banal_page_type($pg) === "blank";
        }));
        if ($nd0_pages == $this->npages) {
            $cf->problem_at("notext", "<0>This document appears to contain no text", 2);
            $cf->msg_at("notext", "<0>The PDF software has rendered pages as images. PDFs like this are less efficient to transfer and harder to search.", MessageSet::INFORM);
        }
    }

    /** @param object $bj
     * @param int $nmsg0
     * @return object */
    static private function truncate_banal_json($bj, CheckFormat $cf, $nmsg0, FormatSpec $spec) {
        $xj = clone $bj;
        if (isset($xj->npages) ? $xj->npages < count($bj->pages) : count($bj->pages) > 48) {
            $xj->npages = count($bj->pages);
        }
        $xj->pages = [];
        $bjpages = $bj->pages ?? [];
        $saw_refbreak = 0;
        $last_fs_page = 0;
        $last_fs = 0;
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
            $pt = CheckFormat::banal_page_type($pg);
            if ($pt !== "body") {
                $xg["type"] = $pt;
            }
            if ($saw_refbreak === 0) {
                if ($pt === "bib" || $pt === "appendix") {
                    $saw_refbreak = 1;
                } else if (isset($pg->fs)) {
                    $last_fs_page = $i;
                    $last_fs = $pg->fs;
                }
            } else if ($saw_refbreak === 1
                       && $pt === "body") {
                if ($last_fs && isset($pg->fs)) {
                    $xj->pages[$last_fs_page]->fs = $last_fs;
                    $xg["fs"] = $pg->fs;
                }
                $saw_refbreak = 2;
            }
            $xj->pages[] = (object) $xg;
        }
        if ($spec->timestamp) {
            $msx = [$spec->timestamp];
            $mlist = $cf->message_list();
            while ($nmsg0 !== count($mlist)) {
                $mi = $mlist[$nmsg0];
                $ms = [$mi->field, $mi->message, $mi->status];
                if ($mi->landmark) {
                    $ms[] = $mi->landmark;
                }
                $msx[] = $ms;
                ++$nmsg0;
            }
            $xj->msx = $msx;
        }
        $xj->{OBJECT_REPLACE_NO_RECURSE} = true;
        return $xj;
    }

    /** @return bool */
    function append_report(CheckFormat $cf, FormatSpec $spec, DocumentInfo $doc,
                           MessageSet $ms) {
        if (!$ms->has_message()) {
            $mi = $cf->front_report_item();
            if ($cf->has_error()) {
                $mi->message = "<5><strong>" . $mi->message_as(5) . ".</strong> The most serious errors are marked with <span class=\"error-mark\"></span>.";
            }
            $ms->append_item($mi);
        }
        if ($cf->has_problem()) {
            $ms->append_item(new MessageItem(null, "<5>Submissions that violate the requirements will not be considered. However, some violation reports may be false positives (for instance, the checker can miscalculate margins and text sizes for figures). If you are confident that the current document respects all format requirements, keep it as is.", MessageSet::INFORM));
        }
        $ms->append_list($cf->message_list());
        return true;
    }

    /** @return string */
    function report(CheckFormat $cf, FormatSpec $spec, DocumentInfo $doc) {
        $ms = new MessageSet;
        $this->append_report($cf, $spec, $doc, $ms);
        return Ht::feedback_msg($ms);
    }
}
