<?php
// checkformat.php -- HotCRP/banal integration
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class CheckFormat {
    const STATUS_NONE = 0;
    const STATUS_PROBLEM = 1;
    const STATUS_OK = 2;

    public $msgs = [];
    public $errf = [];
    public $pages = 0;
    public $metadata_updates = [];
    public $status = 0;
    public $banal_stdout;
    public $banal_sterr;
    public $banal_status;
    private $tempdir = null;
    public $no_run = false;
    public $need_run = false;
    public $possible_run = false;
    private $dt_specs = [];

    public function __construct($no_run = false) {
        $this->no_run = $no_run;
    }

    public function has_error($field = null) {
        return $field ? isset($this->errf[$field]) : !empty($this->errf);
    }

    public function msg($type, $what) {
        $this->msgs[] = array($type, $what);
        return self::STATUS_NONE;
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
                $pie[] = "Paper size mismatch: expected " . commajoin(array_map(function ($d) { FormatSpec::unparse_dimen($d, "paper"); }, $spec->papersize), "or") . ", got " . FormatSpec::unparse_dimen([$papersize[1], $papersize[0]], "paper");
                $this->errf["papersize"] = true;
            }
        }

        // number of pages
        $minpages = $maxpages = null;
        if ($spec->pagelimit) {
            if (count($bj->pages) < $spec->pagelimit[0]) {
                $pie[] = "Too few pages: expected " . plural($spec->pagelimit[0], "or more page") . ", found " . count($bj->pages);
                $this->errf["pagelimit"] = true;
            } else if (count($bj->pages) > $spec->pagelimit[1]) {
                $pie[] = "Too many pages: the limit is " . plural($spec->pagelimit[1], "page") . ", found " . count($bj->pages);
                $this->errf["pagelimit"] = true;
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
                $this->errf["columns"] = true;
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
                    . FormatSpec::unparse_dimen($spec->textblock[0]) . " by "
                    . (count($px) > 1 ? "up to " : "")
                    . ((int) (100 * $maxx / $spec->textblock[0] + .5) - 100)
                    . "% on " . pluralx($px, "page") . " "
                    . numrangejoin($px);
                $this->errf["textblock"] = true;
            }
            if (count($py) > 0) {
                $pie[] = "Margins too small: text height exceeds "
                    . FormatSpec::unparse_dimen($spec->textblock[1]) . " by "
                    . (count($py) > 1 ? "up to " : "")
                    . ((int) (100 * $maxy / $spec->textblock[1] + .5) - 100)
                    . "% on " . pluralx($py, "page") . " "
                    . numrangejoin($py);
                $this->errf["textblock"] = true;
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
                $this->errf["bodyfontsize"] = true;
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
                $this->errf["bodyleading"] = true;
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
        return $spec === "" ? null : new FormatSpec($spec);
    }

    private function clear() {
        $this->errf = $this->metadata_updates = [];
        $this->status = self::STATUS_NONE;
        $this->need_run = $this->possible_run = false;
    }

    public function check_file($filename, $spec) {
        if (is_string($spec))
            $spec = new FormatSpec($spec);
        $this->clear();
        $bj = $this->run_banal($filename, $spec->banal_args);
        return $this->check_banal_json($bj, $spec);
    }

    public function load_document($doc) {
        if (!$doc->docclass->load($doc))
            return $cf->msg("error", "Paper cannot be loaded.");
        if (!isset($doc->filestore)) {
            if (!$this->tempdir && ($this->tempdir = tempdir()) == false)
                return $this->msg("error", "Cannot create temporary directory.");
            if (file_put_contents("$this->tempdir/paper.pdf", $doc->content) != strlen($doc->content))
                return $this->msg("error", "Failed to save PDF to temporary file for analysis.");
            $doc->filestore = "$this->tempdir/paper.pdf";
        }
        return true;
    }

    public function check(CheckFormat $cf, FormatSpec $spec, PaperInfo $prow, $doc) {
        global $Conf, $Opt, $Now;
        $bj = null;
        if ($doc->infoJson && isset($doc->infoJson->banal))
            $bj = $doc->infoJson->banal;
        if (!($bj && $bj->at >= @filemtime("src/banal") && get($bj, "args") == $spec->banal_args
              && $bj->at >= $Now - 86400)) {
            $cf->possible_run = true;
            if (!$cf->no_run)
                $bj = null;
        }

        if ($bj)
            /* OK */;
        else if ($cf->no_run) {
            $cf->need_run = true;
            return self::STATUS_NONE;
        } else if (!$cf->load_document($doc))
            return self::STATUS_NONE;
        else {
            // constrain the number of concurrent banal executions to banalLimit
            // (counter resets every 2 seconds)
            $t = (int) (time() / 2);
            $n = ($Conf->setting_data("__banal_count") == $t ? $Conf->setting("__banal_count") + 1 : 1);
            $limit = get($Opt, "banalLimit", 8);
            if ($limit > 0 && $n > $limit)
                return $cf->msg("error", "Server too busy to check paper formats at the moment.  This is a transient error; feel free to try again.");
            if ($limit > 0)
                Dbl::q("insert into Settings (name,value,data) values ('__banal_count',$n,'$t') on duplicate key update value=$n, data='$t'");

            $bj = $cf->run_banal($doc->filestore, $spec->banal_args);
            if ($bj && is_object($bj) && isset($bj->pages)) {
                $cf->metadata_updates["npages"] = count($bj->pages);
                $cf->metadata_updates["banal"] = $bj;
            }

            if ($limit > 0)
                Dbl::q("update Settings set value=value-1 where name='__banal_count' and data='$t'");
        }

        return $cf->check_banal_json($bj, $spec);
    }

    public function has_spec($dtype) {
        if (!array_key_exists($dtype, $this->dt_specs))
            $this->dt_specs[$dtype] = self::document_spec($dtype);
        $spec = $this->dt_specs[$dtype];
        return $spec && !$spec->is_empty();
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
            return $this->msg("error", "No such document.");
        if ($doc->paperId != $prow->paperId || $doc->documentType != $dtype)
            return $this->msg("error", "The document has changed.");
        if ($doc->mimetype != "application/pdf")
            return $this->msg("error", "The format checker only works for PDF files.");
        if (!$this->has_spec($dtype))
            return $this->msg("error", "There are no formatting requirements defined for this document.");

        $this->check($this, $this->dt_specs[$dtype], $prow, $doc);

        if (!empty($this->metadata_updates))
            $Conf->update_document_metadata($doc, $this->metadata_updates);
        return $this->status;
    }

    public function messages_html() {
        $t = [];
        foreach ($this->msgs as $m)
            $t[] = Ht::xmsg($m[0], $m[1]);
        return $t;
    }
}
