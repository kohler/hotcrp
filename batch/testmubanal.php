<?php
// testmubanal.php -- HotCRP maintenance script
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
}

class TestMubanal_Batch {
    /** @var list<string> */
    public $docstores;
    /** @var list<string> */
    public $files;
    /** @var int */
    public $mode = 0;
    /** @var ?int */
    public $count;
    /** @var int */
    public $seed;
    /** @var string */
    public $mubanal;
    /** @var int */
    public $margin_tolerance;
    /** @var list<string> */
    public $ignore;
    /** @var bool */
    public $verbose;
    /** @var bool */
    public $quiet;
    /** @var ?string */
    public $html;
    /** @var string */
    public $mutool;
    /** @var int */
    public $dpi;
    /** @var bool */
    public $verdict;
    /** @var string */
    public $spec_string;
    /** @var bool */
    public $summary;
    /** @var array<string,int> */
    public $tally = [];
    /** @var int */
    public $nflip = 0;
    /** @var ?Conf */
    private $_conf;
    /** @var ?FormatSpec */
    private $_spec;
    /** @var list<array{string,list<string>,?object}> */
    public $report = [];
    /** @var list<DocumentFileTree> */
    public $ftrees = [];

    const MODE_COMPARE = 0;
    const MODE_LIST = 1;

    /** Fields ignored unless `--include`d.
     *
     * The character and word counts differ often and by very little -- they
     * follow from text extraction rather than from the format analysis -- so
     * reporting them by default buries the differences that matter. */
    const IGNORED = ["c", "w"];

    /** Representative submission spec, in FormatSpec order:
     * papersize;pagelimit;columns;textblock;bodyfontsize;bodylineheight */
    const DEFAULT_SPEC = "letter;12;2;6.5inx9in;9;11";

    /** @param list<string> $docstores
     * @param list<string> $files */
    function __construct($docstores, $files, $arg) {
        $this->docstores = $docstores;
        $this->files = $files;
        $this->mode = isset($arg["list"]) ? self::MODE_LIST : self::MODE_COMPARE;
        $this->count = $arg["count"] ?? null;
        $this->seed = $arg["seed"] ?? mt_rand();
        $this->mubanal = $arg["mubanal"];
        $this->margin_tolerance = $arg["margin-tolerance"] ?? 4;
        $this->html = $arg["html"] ?? null;
        $this->mutool = $arg["mutool"] ?? "mutool";
        $this->dpi = $arg["dpi"] ?? 72;
        $this->verdict = isset($arg["verdict"]);
        $this->spec_string = $arg["spec"] ?? self::DEFAULT_SPEC;
        $this->summary = isset($arg["summary"]);
        $this->verbose = isset($arg["verbose"]);
        $this->quiet = isset($arg["quiet"]);
        $this->ignore = self::IGNORED;
        foreach (self::field_list($arg["ignore"] ?? "") as $f) {
            if (!in_array($f, $this->ignore, true)) {
                $this->ignore[] = $f;
            }
        }
        foreach (self::field_list($arg["include"] ?? "") as $f) {
            if (($i = array_search($f, $this->ignore, true)) !== false) {
                array_splice($this->ignore, $i, 1);
            }
        }
    }

    /** @param string $s
     * @return list<string> */
    static private function field_list($s) {
        $fs = [];
        foreach (explode(",", $s) as $f) {
            if (($f = trim($f)) !== "") {
                $fs[] = $f;
            }
        }
        return $fs;
    }

    /** Return a random unused PDF from the docstore, or null if none remain.
     * @return ?DocumentFileTreeMatch */
    function random_pdf() {
        // Each match is hidden whether or not it is used, so a file is never
        // returned twice and unusable files do not stall the search.
        for ($j = 0; $j !== 10000 && !empty($this->ftrees); ++$j) {
            $i = mt_rand(0, count($this->ftrees) - 1);
            $ftree = $this->ftrees[$i];
            if ($ftree->is_empty()) {
                array_splice($this->ftrees, $i, 1);
                continue;
            }
            $fm = $ftree->random_match();
            $ftree->hide($fm);
            if ($fm->is_complete() && is_readable($fm->fname)) {
                return $fm;
            }
        }
        return null;
    }

    /** @return Conf */
    private function conf() {
        $this->_conf = $this->_conf ?? (Conf::$main ?? initialize_conf(null, null));
        return $this->_conf;
    }

    /** @return FormatSpec */
    private function spec() {
        $this->_spec = $this->_spec ?? new FormatSpec($this->spec_string);
        return $this->_spec;
    }

    /** Run HotCRP's own format checker over a banal JSON blob.
     *
     * The point of this mode is that field-level drift only matters when it
     * changes what HotCRP tells an author. Rather than reimplementing the
     * checks -- which would rot the moment `src/checkformat.php` changed --
     * this drives `Default_FormatChecker` with the JSON injected, so the rules
     * and their tolerances are the real ones.
     *
     * @param ?object $bj
     * @return ?list<string> sorted "field:status" pairs, or null */
    private function verdict_of($bj) {
        if (!$bj || !is_array($bj->pages ?? null)) {
            return null;
        }
        $cf = new TestMubanal_CheckFormat($this->conf());
        $cf->injected = $bj;
        // mirror CheckFormat::complete_banal_json(), which is private
        $cf->npages = is_int($bj->npages ?? null) ? $bj->npages : count($bj->pages);
        $cf->nwords = is_int($bj->w ?? null) ? $bj->w : null;
        $cf->body_pages = 0;
        $cf->appendix_page = null;
        foreach ($bj->pages as $i => $pg) {
            if (CheckFormat::banal_page_is_body($pg)) {
                ++$cf->body_pages;
            } else if (($pg->type ?? null) === "appendix" && $cf->appendix_page === null) {
                $cf->appendix_page = $i + 1;
            }
        }
        (new Default_FormatChecker)->check($cf, $this->spec(), DocumentInfo::make_empty($this->conf()));
        $v = [];
        foreach ($cf->message_list() as $mi) {
            $v[] = ($mi->field ?? "?") . ":" . $mi->status;
        }
        sort($v);
        return $v;
    }

    /** @param list<string> $command
     * @return ?object */
    private function run_json($command) {
        $subp = (new Subprocess($command, SiteLoader::$root))
            ->set_env(["PATH" => getenv("PATH")]);
        $subp->run();
        if (!$subp->ok) {
            return null;
        }
        $j = json_decode($subp->stdout);
        return is_object($j) ? $j : null;
    }

    /** @param string $fname
     * @return ?object */
    function run_banal($fname) {
        return $this->run_json(["perl", "src/banal", "-json", $fname]);
    }

    /** @param string $fname
     * @return ?object */
    function run_mubanal($fname) {
        return $this->run_json([$this->mubanal, "-json", "-no-time", "-colpos", $fname]);
    }

    /** @param string $key
     * @return bool */
    private function ignored($key) {
        return in_array($key, $this->ignore, true);
    }

    /** Compare two decoded JSON values, collecting differences that matter.
     *
     * `$tol` is the slack allowed on numbers; it is nonzero under `margin` and
     * `nummargin`, whose values sit on a 4pt grid that the two implementations
     * land on either side of. That difference is an artifact, not a disagreement.
     *
     * @param mixed $a
     * @param mixed $b
     * @param string $path
     * @param int|float $tol
     * @param list<string> &$diffs */
    private function compare($a, $b, $path, $tol, &$diffs) {
        if (is_object($a) && is_object($b)) {
            $keys = array_keys(get_object_vars($a));
            foreach (array_keys(get_object_vars($b)) as $k) {
                if (!in_array($k, $keys, true)) {
                    $keys[] = $k;
                }
            }
            foreach ($keys as $k) {
                // banal stamps every run with `at`; mubanal is passed -no-time.
                // `colpos` is mubanal-only diagnostic output, so there is
                // nothing on the other side to compare it against.
                if ($k === "at" || $k === "colpos" || $this->ignored($k)) {
                    continue;
                }
                $ktol = $k === "margin" || $k === "nummargin"
                    ? $this->margin_tolerance : $tol;
                $kpath = $path === "" ? $k : "{$path}.{$k}";
                $this->compare($a->$k ?? null, $b->$k ?? null, $kpath, $ktol, $diffs);
            }
            return;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                $diffs[] = "{$path}: banal has " . count($a) . ", mubanal has " . count($b);
                return;
            }
            foreach ($a as $i => $av) {
                $this->compare($av, $b[$i], "{$path}[{$i}]", $tol, $diffs);
            }
            return;
        }
        if (is_numeric($a) && is_numeric($b)) {
            if (abs($a - $b) > $tol) {
                $diffs[] = "{$path}: banal={$a} mubanal={$b}";
            }
            return;
        }
        if ($a !== $b) {
            $diffs[] = "{$path}: banal=" . json_encode($a) . " mubanal=" . json_encode($b);
        }
    }

    /** Per-page fields that banal omits when they match the document. */
    const INHERITED = ["papersize", "margin", "bodyfontsize", "leading", "columns"];

    /** Fill in the per-page fields banal leaves out.
     *
     * banal prints a page field only when it differs from the document-level
     * value, so an absent field means "same as the document", not "missing".
     * Comparing the raw JSON would report a difference whenever the two
     * implementations disagree about whether a page matches the document --
     * which a sub-tolerance margin difference is enough to cause.
     *
     * @param object $j */
    private function resolve_defaults($j) {
        foreach ($j->pages ?? [] as $page) {
            if (!is_object($page)) {
                continue;
            }
            foreach (self::INHERITED as $f) {
                if (!isset($page->$f) && isset($j->$f)) {
                    $page->$f = $j->$f;
                }
            }
            $page->type = $page->type ?? "body";
        }
    }

    /** @param string $fname
     * @return array{list<string>,?object,?object} */
    function compare_file($fname) {
        $bj = $this->run_banal($fname);
        $mj = $this->run_mubanal($fname);
        if ($bj === null && $mj === null) {
            return [["both banal and mubanal failed"], null, null];
        } else if ($bj === null) {
            return [["banal failed"], null, null];
        } else if ($mj === null) {
            return [["mubanal failed"], $mj, $bj];
        }
        $this->resolve_defaults($bj);
        $this->resolve_defaults($mj);
        $diffs = [];
        $this->compare($bj, $mj, "", 0, $diffs);
        return [$diffs, $mj, $bj];
    }

    /** Group differences by the page they concern, as 1-based page numbers.
     *
     * Differences are reported against paths like `pages[3].columns`, so the
     * page is recoverable from the text, and the prefix can come off once the
     * page is named by the image it sits under. Document-level differences --
     * and `pages` itself, when the page counts disagree -- collect under 0.
     *
     * @param list<string> $diffs
     * @return array<int,list<string>> */
    static private function diff_by_page($diffs) {
        $by = [];
        foreach ($diffs as $d) {
            if (preg_match('/\Apages\[(\d+)\]\.?/', $d, $m)) {
                $by[intval($m[1]) + 1][] = substr($d, strlen($m[0]));
            } else {
                $by[0][] = $d;
            }
        }
        ksort($by);
        return $by;
    }

    /** Render one page to a PNG and return it, or null.
     * @param string $fname
     * @param int $pageno
     * @return ?string */
    private function render_page($fname, $pageno) {
        $png = tempnam(sys_get_temp_dir(), "mubanal") . ".png";
        $subp = (new Subprocess([$this->mutool, "draw", "-o", $png,
                                 "-r", (string) $this->dpi, $fname, (string) $pageno],
                                SiteLoader::$root))
            ->set_env(["PATH" => getenv("PATH")]);
        $subp->run();
        $data = $subp->ok && is_readable($png) ? file_get_contents($png) : null;
        if (file_exists($png)) {
            unlink($png);
        }
        return $data === false || $data === "" ? null : $data;
    }

    /** @param list<string> $diffs
     * @return string */
    static private function diff_list($diffs) {
        if (empty($diffs)) {
            return "";
        }
        $t = "<ul class=\"diffs\">";
        foreach ($diffs as $d) {
            $t .= "<li>" . htmlspecialchars($d) . "</li>";
        }
        return $t . "</ul>";
    }

    /** Percent-of-image geometry for one page's overlays.
     *
     * Boxes are positioned in percentages, not pixels, so they track the image
     * whatever `--dpi` rendered it at and whatever width it displays at.
     *
     * @param ?object $page
     * @return array{string,list<string>} margin box and column boxes as CSS */
    static private function overlay_boxes($page) {
        if (!$page || !is_array($page->papersize ?? null)
            || count($page->papersize) !== 2) {
            return ["", []];
        }
        list($ph, $pw) = $page->papersize;
        if ($pw <= 0 || $ph <= 0) {
            return ["", []];
        }
        $pct = function ($x, $whole) {
            return sprintf("%.3f%%", 100.0 * $x / $whole);
        };
        // `margin` is [top, right, bottom, left]; the text block is what is
        // left of the page once those are taken off.
        $mbox = "";
        $top = 0;
        $height = $ph;
        if (is_array($page->margin ?? null) && count($page->margin) === 4) {
            list($mt, $mr, $mb, $ml) = $page->margin;
            $top = $mt;
            $height = $ph - $mt - $mb;
            $mbox = "left:" . $pct($ml, $pw) . ";top:" . $pct($mt, $ph)
                . ";width:" . $pct($pw - $ml - $mr, $pw)
                . ";height:" . $pct($height, $ph) . ";";
        }
        // `colpos` is left/right pairs; each column spans the text block
        // vertically, so the pair only sets the horizontal extent.
        $cols = [];
        $cp = $page->colpos ?? null;
        if (is_array($cp)) {
            for ($i = 0; $i + 1 < count($cp); $i += 2) {
                $cols[] = "left:" . $pct($cp[$i], $pw) . ";top:" . $pct($top, $ph)
                    . ";width:" . $pct($cp[$i + 1] - $cp[$i], $pw)
                    . ";height:" . $pct($height, $ph) . ";";
            }
        }
        return [$mbox, $cols];
    }

    private function write_html() {
        $h = "<!DOCTYPE html>\n<meta charset=\"utf-8\">\n"
            . "<title>banal vs mubanal</title>\n"
            . "<style>\n"
            . "body { font: 14px/1.5 system-ui, sans-serif; margin: 2em auto; max-width: 60em; }\n"
            . "h2 { font-size: 1em; font-family: monospace; word-break: break-all;\n"
            . "     border-top: 1px solid #ccc; padding-top: 1em; }\n"
            . "ul.diffs { font-family: monospace; background: #f6f6f6;\n"
            . "           margin: .4em 0 0; padding: .5em 1.6em; font-size: .85em; }\n"
            . "figure { display: inline-block; width: 22em; margin: 0 1.5em 2em 0;\n"
            . "         vertical-align: top; }\n"
            . "figcaption { font-weight: bold; margin-top: .4em; }\n"
            . "img { border: 1px solid #bbb; width: 100%; height: auto; display: block; }\n"
            . ".page { position: relative; cursor: pointer; }\n"
            . ".ov { position: absolute; inset: 0; display: none; }\n"
            . ".ov b { position: absolute; display: block; }\n"
            . ".ov-margin b { outline: 2px solid rgba(0,110,255,.85);\n"
            . "               background: rgba(0,110,255,.10); }\n"
            . ".ov-cols b { outline: 2px solid rgba(220,50,0,.85);\n"
            . "             background: rgba(220,50,0,.12); }\n"
            . ".page[data-ov=\"1\"] .ov-margin, .page[data-ov=\"2\"] .ov-cols { display: block; }\n"
            . ".hint { color: #666; font-size: .85em; }\n"
            . "</style>\n"
            . "<h1>banal vs mubanal</h1>\n"
            . "<p>seed " . htmlspecialchars((string) $this->seed) . ", "
            . plural(count($this->report), "document") . " with differences. "
            . "Margin differences of " . htmlspecialchars((string) $this->margin_tolerance)
            . "pt or less are ignored";
        if (!empty($this->ignore)) {
            $h .= "; " . htmlspecialchars(join(", ", $this->ignore)) . " ignored";
        }
        $h .= ".</p>\n<p class=\"hint\">Click a page to cycle: bare &rarr; "
            . "<span style=\"color:#006eff\">margins</span> &rarr; "
            . "<span style=\"color:#dc3200\">columns</span> &rarr; bare. "
            . "Both are mubanal's.</p>\n";

        $nimg = 0;
        foreach ($this->report as [$fname, $diffs, $mj]) {
            $h .= "<h2>" . htmlspecialchars($fname) . "</h2>\n";
            $by_page = self::diff_by_page($diffs);
            // Document-level complaints belong to no page, so they stay above
            // the images rather than under one.
            if (isset($by_page[0])) {
                $h .= self::diff_list($by_page[0]);
                unset($by_page[0]);
            }
            // Every other complaint goes under the page it is about; a
            // document with only document-level complaints still shows page 1,
            // which is enough to see what kind of document it is.
            if (empty($by_page)) {
                $by_page[1] = [];
            }
            foreach ($by_page as $pageno => $pdiffs) {
                $png = $this->render_page($fname, $pageno);
                $h .= "<figure>";
                if ($png === null) {
                    $h .= "<em>cannot render</em>";
                } else {
                    ++$nimg;
                    list($mbox, $cols) = self::overlay_boxes($mj->pages[$pageno - 1] ?? null);
                    $h .= "<div class=\"page\" data-ov=\"0\">"
                        . "<img src=\"data:image/png;base64," . base64_encode($png) . "\">";
                    if ($mbox !== "") {
                        $h .= "<div class=\"ov ov-margin\"><b style=\"{$mbox}\"></b></div>";
                    }
                    if (!empty($cols)) {
                        $h .= "<div class=\"ov ov-cols\">";
                        foreach ($cols as $c) {
                            $h .= "<b style=\"{$c}\"></b>";
                        }
                        $h .= "</div>";
                    }
                    $h .= "</div>";
                }
                $h .= "<figcaption>page {$pageno}</figcaption>"
                    . self::diff_list($pdiffs) . "</figure>\n";
            }
        }

        $h .= "<script>\n"
            . "document.addEventListener('click', function (e) {\n"
            . "    var p = e.target.closest('.page');\n"
            . "    if (p) {\n"
            . "        p.dataset.ov = (+p.dataset.ov + 1) % 3;\n"
            . "    }\n"
            . "});\n"
            . "</script>\n";

        if (file_put_contents($this->html, $h) === false) {
            throw new CommandLineException("{$this->html}: Cannot write");
        }
        if (!$this->quiet) {
            fwrite(STDERR, "wrote {$this->html} (" . plural($nimg, "page image") . ")\n");
        }
    }

    /** Normalize a difference to the field it concerns, for tallying.
     * @param string $d
     * @return string */
    static private function diff_field($d) {
        $d = preg_replace('/\Apages\[\d+\]\./', "", $d);
        $d = preg_replace('/\[\d+\]/', "", $d);
        $c = strpos($d, ":");
        return $c === false ? $d : substr($d, 0, $c);
    }

    /** Compare one file and record the result.
     * @param string $fname
     * @return bool true if the file differs */
    private function handle_file($fname) {
        if ($this->mode === self::MODE_LIST) {
            fwrite(STDOUT, "{$fname}\n");
            return false;
        }
        list($diffs, $mj, $bj) = $this->compare_file($fname);

        if ($this->verdict) {
            // In verdict mode the JSON differences are only context; what
            // counts is whether HotCRP would say anything different.
            $bv = $this->verdict_of($bj);
            $mv = $this->verdict_of($mj);
            if ($bv === $mv) {
                if ($this->verbose) {
                    $t = $bv === null ? "(no output)" : (empty($bv) ? "ok" : join(" ", $bv));
                    fwrite(STDOUT, "{$fname}: same verdict ({$t})\n");
                }
                return false;
            }
            ++$this->nflip;
            $this->tally["verdict"] = ($this->tally["verdict"] ?? 0) + 1;
            if (!$this->summary) {
                fwrite(STDOUT, "{$fname}\n");
                fwrite(STDOUT, "    banal:   " . ($bv === null ? "(no output)" : (empty($bv) ? "ok" : join(" ", $bv))) . "\n");
                fwrite(STDOUT, "    mubanal: " . ($mv === null ? "(no output)" : (empty($mv) ? "ok" : join(" ", $mv))) . "\n");
                foreach ($diffs as $d) {
                    fwrite(STDOUT, "      {$d}\n");
                }
            }
            if ($this->html !== null) {
                $this->report[] = [$fname, $diffs, $mj];
            }
            return true;
        }

        if (empty($diffs)) {
            if ($this->verbose) {
                fwrite(STDOUT, "{$fname}: same\n");
            }
            return false;
        }
        foreach ($diffs as $d) {
            $f = self::diff_field($d);
            $this->tally[$f] = ($this->tally[$f] ?? 0) + 1;
        }
        if (!$this->summary) {
            fwrite(STDOUT, "{$fname}\n");
            foreach ($diffs as $d) {
                fwrite(STDOUT, "    {$d}\n");
            }
        }
        if ($this->html !== null) {
            $this->report[] = [$fname, $diffs, $mj];
        }
        return true;
    }

    /** @return int */
    function run() {
        if ($this->mode !== self::MODE_LIST
            && !is_executable($this->mubanal)
            && !is_executable(SiteLoader::$root . "/" . $this->mubanal)) {
            throw new CommandLineException("{$this->mubanal}: Not executable, use `--mubanal`");
        }

        // Named files come first, in order; the docstore then tops the run up
        // to `--count`. With no count, a named list is taken in full and a bare
        // docstore run samples 20.
        $limit = $this->count ?? (empty($this->files) ? 20 : count($this->files));

        $nfile = $ndiff = 0;
        foreach ($this->files as $fname) {
            if ($nfile >= $limit) {
                break;
            }
            if (!is_readable($fname)) {
                fwrite(STDERR, "{$fname}: Not readable\n");
                continue;
            }
            ++$nfile;
            $ndiff += $this->handle_file($fname) ? 1 : 0;
        }

        if ($nfile < $limit && !empty($this->docstores)) {
            foreach ($this->docstores as $dp) {
                if (!str_starts_with($dp, "/") || strpos($dp, "%") === false) {
                    throw new CommandLineException("{$dp}: Bad docstore pattern");
                }
                $this->ftrees[] = new DocumentFileTree($dp, new DocumentHashMatcher(".pdf"), 0);
            }
            // The traversal consumes mt_rand(), so the same seed over the same
            // docstore selects the same documents.
            mt_srand($this->seed);
            if (!$this->quiet) {
                fwrite(STDERR, "seed {$this->seed}\n");
            }
            // A named file can also turn up in the docstore; testing it twice
            // would just be confusing.
            $seen = [];
            foreach ($this->files as $fname) {
                if (($rp = realpath($fname)) !== false) {
                    $seen[$rp] = true;
                }
            }
            while ($nfile < $limit && ($fm = $this->random_pdf())) {
                if (isset($seen[realpath($fm->fname) ?: $fm->fname])) {
                    continue;
                }
                ++$nfile;
                $ndiff += $this->handle_file($fm->fname) ? 1 : 0;
            }
        }

        if ($this->html !== null) {
            $this->write_html();
        }

        if ($nfile === 0) {
            fwrite(STDERR, "No PDFs found\n");
            return 1;
        }
        if ($this->mode !== self::MODE_LIST && $this->summary) {
            arsort($this->tally);
            foreach ($this->tally as $f => $n) {
                fwrite(STDOUT, sprintf("%6d  %s\n", $n, $f));
            }
        }
        if ($this->mode !== self::MODE_LIST && !$this->quiet) {
            $what = $this->verdict ? "changed verdict" : "difference";
            fwrite(STDERR, plural($nfile, "file") . " compared, "
                . plural($ndiff, $what) . "\n");
        }
        return $ndiff > 0 ? 1 : 0;
    }

    /** Is $prog runnable -- either a path, or a name findable on $PATH?
     * @param string $prog
     * @return bool */
    static private function command_exists($prog) {
        if (strpos($prog, "/") !== false) {
            return is_executable($prog);
        }
        foreach (explode(PATH_SEPARATOR, getenv("PATH") ? : "") as $dir) {
            if ($dir !== "" && is_executable("{$dir}/{$prog}")) {
                return true;
            }
        }
        return false;
    }

    /** @return TestMubanal_Batch */
    static function make_args($argv) {
        if (($mubanal = getenv("MUBANAL"))) {
            // found in environment
        } else if (self::command_exists("mubanal")) {
            $mubanal = "mubanal";
        } else {
            $mubanal = getenv("HOME") . "/mubanal/build/mubanal";
        }
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "count:,c: {n} =COUNT Test COUNT documents [20, or all named files]",
            "seed:,s: {n} =SEED Seed the document choice [random]",
            "list,l Output document pathnames and exit",
            "files: =FILENAME Test the files named in FILENAME, one per line",
            "mubanal: =CMD mubanal program [{$mubanal}]",
            "margin-tolerance: {n} =PTS Ignore margin differences <=PTS [4]",
            "ignore: =FIELD[,...] Also ignore differences in FIELD",
            "include: =FIELD[,...] Report differences in FIELD [c,w ignored]",
            "verdict Compare HotCRP format-check verdicts, not JSON fields",
            "spec: =SPEC Format spec for --verdict [" . self::DEFAULT_SPEC . "]",
            "summary Print a tally of differences instead of listing them",
            "html: =FILE Write an HTML report with page images to FILE",
            "mutool: =CMD mutool program, for --html [mutool]",
            "dpi: {n} =DPI Render pages at DPI for --html [72]",
            "quiet,silent,q Be quiet",
            "verbose,V Report documents that agree",
            "help,h"
        )->helpopt("help")
         ->description("Compare src/banal and mubanal over PDFs
Usage: php batch/testmubanal.php [-c COUNT] [-s SEED] [-l] [DOCSTORE | FILE]...\n")
         ->interleave(true)
         ->parse($argv);

        // A `%` marks a docstore pattern; anything else on the command line
        // is a file to test directly. `-` and `default` keep meaning the
        // configured docstore.
        $confdps = $files = [];
        foreach ($arg["_"] as $x) {
            if ($x === "-" || $x === "default") {
                $confdps[] = self::default_docstore($arg);
            } else if (strpos($x, "%") !== false) {
                $confdps[] = $x;
            } else {
                $files[] = $x;
            }
        }
        if (isset($arg["files"])) {
            $fn = $arg["files"];
            $t = $fn === "-" ? stream_get_contents(STDIN) : @file_get_contents($fn);
            if ($t === false) {
                throw new CommandLineException("{$fn}: Cannot read");
            }
            foreach (explode("\n", $t) as $line) {
                if (($line = trim($line)) !== "" && $line[0] !== "#") {
                    $files[] = $line;
                }
            }
        }
        // The docstore is only consulted to top a run up to `--count`, so
        // resolve the default one only when that can actually happen -- a run
        // over named files alone should not require a configured docstore.
        if (empty($confdps)
            && (empty($files) || ($arg["count"] ?? 0) > count($files))) {
            $confdps[] = self::default_docstore($arg);
        }
        $arg["mubanal"] = $arg["mubanal"] ?? $mubanal;
        return new TestMubanal_Batch($confdps, $files, $arg);
    }

    /** @return string */
    static private function default_docstore($arg) {
        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        if (!($confds = $conf->docstore())) {
            throw new CommandLineException("No default docstore");
        }
        return $confds->full_pattern();
    }
}

/** Feeds a stored banal JSON blob to HotCRP's format checker.
 *
 * `Default_FormatChecker::check()` pulls its input from `CheckFormat::banal_json()`,
 * so overriding that is enough to check a blob we already have -- no document,
 * no subprocess, no cached metadata. `run_attempted()` is overridden because
 * the base class asserts a run actually started, and returning false also
 * keeps `check()` from writing the result back to a document.
 */
class TestMubanal_CheckFormat extends CheckFormat {
    /** @var ?object */
    public $injected;

    /** @return ?object */
    function banal_json() {
        return $this->injected;
    }

    /** @return bool */
    function run_attempted() {
        return false;
    }
}

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    exit(TestMubanal_Batch::make_args($argv)->run());
}
