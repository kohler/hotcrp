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
    public $ignore_all;
    /** @var list<string> */
    public $include;
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
    /** @var ?string */
    public $verdict;
    /** @var string */
    public $spec_string;
    /** @var bool */
    public $summary;
    /** @var int */
    public $render_pages;
    /** @var ?string */
    public $corpus;
    /** @var list<string> */
    public $mubanal_opt;
    /** @var array<string,int> */
    public $tally = [];
    /** @var int */
    public $nflip = 0;
    /** @var ?string */
    public $config;
    /** @var ?string */
    public $name;
    /** @var ?Conf */
    private $_conf;
    /** @var array<string,?string> */
    private $_resolved = [];
    /** @var ?FormatSpec */
    private $_spec;
    /** @var ?bool */
    private $_color;
    /** @var ?int */
    private $_doc_dtype;
    /** @var list<array{string,list<string>,?object}> */
    public $report = [];
    /** @var list<DocumentFileTree> */
    public $ftrees = [];

    /** @var bool */
    public $papers;
    /** @var ?string */
    public $search_q;
    /** @var string */
    public $search_t;

    const MODE_COMPARE = 0;
    const MODE_LIST = 1;
    const MODE_RENDER = 2;
    const MODE_CORPUS = 3;

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
        if (isset($arg["list"])) {
            $this->mode = self::MODE_LIST;
        } else if (isset($arg["render"])) {
            $this->mode = self::MODE_RENDER;
        } else if (isset($arg["corpus"])) {
            $this->mode = self::MODE_CORPUS;
        } else {
            $this->mode = self::MODE_COMPARE;
        }
        $this->corpus = $arg["corpus"] ?? null;
        $this->config = $arg["config"] ?? null;
        $this->name = $arg["name"] ?? null;
        $this->mubanal_opt = $arg["mubanal-opt"] ?? [];
        // Two pages, because page 1 is a title page as often as it is a
        // representative one.
        $this->render_pages = is_int($arg["render"] ?? null)
            ? max(1, $arg["render"]) : 2;
        $this->count = $arg["count"] ?? null;
        $this->seed = $arg["seed"] ?? mt_rand();
        $this->mubanal = $arg["mubanal"];
        $this->margin_tolerance = $arg["margin-tolerance"] ?? 4;
        $this->html = $arg["html"] ?? null;
        $this->mutool = $arg["mutool"] ?? "mutool";
        $this->dpi = $arg["dpi"] ?? 72;
        if (isset($arg["verdict"])) {
            if ($arg["verdict"] === false || $arg["verdict"] === "any") {
                $this->verdict = "any";
            } else if ($arg["verdict"] === "worse") {
                $this->verdict = "worse";
            } else if ($arg["verdict"] === "better") {
                $this->verdict = "better";
            } else {
                throw new CommandLineException("Expected `--verdict=any|worse|better`");
            }
        }
        // Left null when unset, so a conference's own spec can stand in.
        $this->spec_string = $arg["spec"] ?? null;
        // A search names papers, so asking for one is asking for --papers.
        $this->search_q = $arg["q"] ?? null;
        $this->search_t = $arg["t"] ?? "s";
        $this->papers = isset($arg["papers"])
            || $this->search_q !== null
            || isset($arg["t"]);
        $this->summary = isset($arg["summary"]);
        $this->verbose = isset($arg["verbose"]);
        $this->quiet = isset($arg["quiet"]);
        $this->ignore = self::IGNORED;
        $this->ignore_all = false;
        foreach (self::field_list($arg["ignore"] ?? "") as $f) {
            if ($f === "all") {
                $this->ignore_all = true;
            } else if (!in_array($f, $this->ignore, true)) {
                $this->ignore[] = $f;
            }
        }
        $this->include = self::field_list($arg["include"] ?? "");
        if ($this->html !== null && $this->mutool === "NONE") {
            throw new CommandLineException("Cannot find `mutool` for generating thumbnails");
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
        $this->_conf = $this->_conf
            ?? (Conf::$main ?? initialize_conf($this->config, $this->name));
        return $this->_conf;
    }

    /** Resolve a `--files` or corpus entry to a readable path.
     *
     * A name with no slash is a docstore name: documents there are named by
     * their hash, so `sha2-<hex>.pdf` identifies one wherever it is stored.
     * Resolving it through `DocumentInfo` rather than the filesystem means the
     * search falls through the docstore to the database and to S3, so a corpus
     * stays usable on a machine that holds only some of it -- and a corpus
     * written in hashes is portable, where one written in absolute paths is
     * not.
     *
     * @param string $name
     * @return ?string */
    private function resolve_file($name) {
        if (array_key_exists($name, $this->_resolved)) {
            return $this->_resolved[$name];
        }
        $path = null;
        if (strpos($name, "/") !== false) {
            $path = is_readable($name) ? $name : null;
        } else {
            // Any extension is dropped: the docstore adds one, the hash is the
            // name. A name that is not a hash is still tried as a path, so a
            // file in the working directory keeps working.
            $hash = preg_replace('/\.[A-Za-z0-9]+\z/', "", $name);
            if (HashAnalysis::hash_as_binary($hash) === false) {
                $path = is_readable($name) ? $name : null;
            } else {
                $doc = DocumentInfo::make_hash($this->conf(), $hash, "application/pdf");
                $path = $doc->content_file();
                $path = $path !== null && is_readable($path) ? $path : null;
            }
        }
        $this->_resolved[$name] = $path;
        return $path;
    }

    /** The papers to test, in paper order.
     *
     * With no search this is every submission; with one it is whatever the
     * search names, evaluated as the site's root user so that nothing is
     * hidden by visibility rules -- this is a maintenance tool, not a view.
     *
     * @return iterable<PaperInfo> */
    private function paper_rows() {
        $user = $this->conf()->root_user();
        if (!in_array($this->search_t, PaperSearch::viewable_limits($user, $this->search_t), true)) {
            throw new CommandLineException("No search collection ‘{$this->search_t}’");
        }
        $srch = new PaperSearch($user, ["q" => $this->search_q ?? "", "t" => $this->search_t]);
        foreach ($srch->message_list() as $mi) {
            fwrite(STDERR, "search: " . $mi->message_as(0) . "\n");
        }
        return PaperInfoSet::make_search($srch);
    }

    /** Wrap `$t` in an ANSI colour, if the output is a terminal.
     *
     * Only on a tty: these reports are routinely piped into `grep` and into the
     * `--summary` tallies, where escape codes would be noise at best and would
     * break a comparison at worst.
     *
     * @param string $t
     * @param string $color one of "red", "green", "yellow", "dim"
     * @return string */
    private function colored($t, $color) {
        $this->_color = $this->_color ?? posix_isatty(STDOUT);
        if (!$this->_color || $t === "") {
            return $t;
        }
        $c = ["red" => "31", "green" => "32", "yellow" => "33", "dim" => "90"];
        return "\033[" . ($c[$color] ?? "0") . "m{$t}\033[0m";
    }

    /** How a verdict reads.
     *
     * "Good" is an empty verdict -- HotCRP found nothing to say about the
     * paper. A change into that state is an improvement, a change out of it a
     * regression, and a change between two different complaints is neither.
     *
     * @param ?list<string> $v
     * @return string */
    static private function verdict_text($v) {
        return $v === null ? "(no output)" : (empty($v) ? "ok" : join(" ", $v));
    }

    /** The spec `--verdict` checks against.
     *
     * `--papers` reads a conference's own submissions, so it checks them
     * against that conference's own rules -- the point of the mode is to ask
     * what this conference would tell these authors. Anywhere else there is no
     * conference to ask, so a representative spec stands in. An explicit
     * `--spec` wins over both.
     *
     * @param ?int $dtype
     * @return FormatSpec */
    private function spec($dtype = null) {
        if ($this->spec_string !== null) {
            $this->_spec = $this->_spec ?? new FormatSpec($this->spec_string);
            return $this->_spec;
        }
        if ($this->papers) {
            return $this->conf()->format_spec($dtype ?? DTYPE_SUBMISSION);
        }
        $this->_spec = $this->_spec ?? new FormatSpec(self::DEFAULT_SPEC);
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
     * @return array{int,?list<string>} sorted "field:status" pairs, or null */
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
        (new Default_FormatChecker)->check($cf, $this->spec($this->_doc_dtype), DocumentInfo::make_empty($this->conf()));
        $v = [];
        foreach ($cf->message_list() as $mi) {
            $v[] = ($mi->field ?? "?") . ":" . $mi->status;
        }
        sort($v);
        return [$cf->problem_status(), $v];
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
        $cmd = [$this->mubanal, "-json", "-no-time", "-colpos"];
        array_push($cmd, ...$this->mubanal_opt);
        $cmd[] = $fname;
        return $this->run_json($cmd);
    }

    /** Keys that hold other keys rather than a value of their own.
     *
     * `--ignore=all` must not swallow these, or there would be nothing left to
     * descend into and `--include=columns` would report only the document's
     * count while saying nothing about any page. Ignoring one by name still
     * works, and is how you ask for a document-level comparison only. */
    const CONTAINER = ["pages"];

    /** What is being ignored, for the report header.
     * @return string */
    private function ignore_description() {
        if (!$this->ignore_all) {
            return empty($this->ignore) ? "nothing" : join(", ", $this->ignore);
        }
        return empty($this->include)
            ? "every field" : "every field but " . join(", ", $this->include);
    }

    /** @param string $key
     * @return bool */
    private function ignored($key) {
        if (in_array($key, $this->include, true)) {
            return false;
        }
        if ($this->ignore_all) {
            return !in_array($key, self::CONTAINER, true);
        }
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

    /** Page types on which banal has no column count to disagree with.
     *
     * banal prints no per-page `columns` for these, so `resolve_defaults` has
     * just filled both sides in from their documents -- comparing them would
     * report a disagreement about a number banal never computed. mubanal does
     * print one for appendix pages, deliberately, and its reading of a figure
     * page is its own; neither is a defect measured against banal.
     *
     * @param object $bj
     * @param object $mj */
    static private function drop_untyped_columns($bj, $mj) {
        foreach ($bj->pages ?? [] as $i => $bp) {
            if (!is_object($bp)
                || (($bp->type ?? "body") !== "figure"
                    && ($bp->type ?? "body") !== "appendix")) {
                continue;
            }
            unset($bp->columns);
            $mp = $mj->pages[$i] ?? null;
            if (is_object($mp)) {
                unset($mp->columns);
            }
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
        self::drop_untyped_columns($bj, $mj);
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

    /** Render pages `$first` through `$last`, keyed by page number.
     *
     * A range past the end of the document is not an error: mutool renders the
     * pages that exist and says nothing about the rest, so the returned keys
     * are how a document shorter than the range is recognized. `%d` in the
     * output name is the absolute page number, not a counter.
     *
     * @param string $fname
     * @param int $first
     * @param int $last
     * @return array<int,string> */
    private function render_range($fname, $first, $last) {
        if (($dir = tempnam(sys_get_temp_dir(), "mubanal")) === false) {
            return [];
        }
        unlink($dir);
        if (!@mkdir($dir, 0700)) {
            return [];
        }
        $subp = (new Subprocess([$this->mutool, "draw", "-o", "{$dir}/p%d.png",
                                 "-r", (string) $this->dpi, $fname,
                                 "{$first}-{$last}"],
                                SiteLoader::$root))
            ->set_env(["PATH" => getenv("PATH")]);
        $subp->run();
        $pngs = [];
        for ($p = $first; $p <= $last; ++$p) {
            if (is_readable("{$dir}/p{$p}.png")
                && ($data = file_get_contents("{$dir}/p{$p}.png"))) {
                $pngs[$p] = $data;
            }
        }
        foreach (glob("{$dir}/*") ? : [] as $f) {
            unlink($f);
        }
        rmdir($dir);
        return $pngs;
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

    /** One rendered page, with the overlays for `$page` if there are any.
     *
     * `$page` is mubanal's JSON for this page, or null where there is none;
     * the image is then bare, since both overlays are mubanal's geometry, and
     * it is left unwrapped so it does not advertise a click that does nothing.
     *
     * @param string $png
     * @param ?object $page
     * @return string */
    static private function page_image($png, $page) {
        $img = "<img src=\"data:image/png;base64," . base64_encode($png) . "\">";
        list($mbox, $cols) = self::overlay_boxes($page);
        if ($mbox === "" && empty($cols)) {
            return $img;
        }
        $h = "<div class=\"page\" data-ov=\"0\">" . $img;
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
        return $h . "</div>";
    }

    /** A document's heading, with the checkbox the copy button reads.
     * @param string $fname
     * @param string $anno
     * @return string */
    static private function doc_heading($fname, $anno) {
        $e = htmlspecialchars($fname);
        return "<h2><label><input type=\"checkbox\" class=\"fsel\" value=\"{$e}\">"
            . "{$e}</label>"
            . ($anno === "" ? "" : " [" . htmlspecialchars($anno) . "]")
            . "</h2>\n";
    }

    /** The differing documents, each page under the complaints about it.
     * @param int &$nimg
     * @return string */
    private function compare_report(&$nimg) {
        $h = "";
        foreach ($this->report as [$fname, $diffs, $mj, $anno]) {
            $h .= self::doc_heading($fname, $anno);
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
                $pngs = $this->render_range($fname, $pageno, $pageno);
                $img = "<em>cannot render</em>";
                if (isset($pngs[$pageno])) {
                    ++$nimg;
                    $img = self::page_image($pngs[$pageno],
                                            $mj->pages[$pageno - 1] ?? null);
                }
                $h .= "<figure>{$img}<figcaption>page {$pageno}</figcaption>"
                    . self::diff_list($pdiffs) . "</figure>\n";
            }
        }
        return $h;
    }

    /** The first pages of every document traversed.
     *
     * Nothing has been run over these documents, so there is nothing to say
     * about them: this is for looking at a corpus, or at a candidate for one.
     *
     * @param int &$nimg
     * @return string */
    private function render_report(&$nimg) {
        $h = "";
        foreach ($this->report as [$fname, , , $anno]) {
            $h .= self::doc_heading($fname, $anno);
            if ($this->verbose) {
                $xanno = $anno === "" ? "" : " [{$anno}]";
                fwrite(STDERR, "rendering {$fname}{$xanno}\n");
            }
            // A document with fewer than `--render` pages simply yields fewer
            // images; one that will not render at all yields none, and says so,
            // since otherwise it would appear with no images and no explanation.
            $pngs = $this->render_range($fname, 1, $this->render_pages);
            if (empty($pngs)) {
                $h .= "<figure><em>cannot render</em></figure>\n";
                continue;
            }
            foreach ($pngs as $pageno => $png) {
                ++$nimg;
                $h .= "<figure>" . self::page_image($png, null)
                    . "<figcaption>page {$pageno}</figcaption></figure>\n";
            }
        }
        return $h;
    }

    private function write_html() {
        $render = $this->mode === self::MODE_RENDER;
        $title = $render ? "mubanal corpus" : "banal vs mubanal";
        $h = "<!DOCTYPE html>\n<meta charset=\"utf-8\">\n"
            . "<title>" . $title . "</title>\n"
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
            . "h2 label { cursor: pointer; }\n"
            . "h2 input { margin-right: .6em; }\n"
            . "#tools { position: fixed; right: 1.5em; bottom: 1.5em; z-index: 10;\n"
            . "         display: flex; align-items: center; gap: .6em; }\n"
            . "#tools button { width: 2.8em; height: 2.8em; border-radius: 50%;\n"
            . "         cursor: pointer; border: 1px solid #888; background: #fff;\n"
            . "         color: #333; box-shadow: 0 1px 6px rgba(0,0,0,.3); }\n"
            . "#copyb.ok { background: #dcf3dc; border-color: #3a3; color: #163; }\n"
            . "#copymsg { background: #fff; padding: .2em .6em; border-radius: .3em;\n"
            . "           font-size: .85em; box-shadow: 0 1px 6px rgba(0,0,0,.2);\n"
            . "           white-space: nowrap;\n"
            . "           opacity: 0; transition: opacity .15s; pointer-events: none; }\n"
            . "#copymsg.show { opacity: 1; }\n"
            . "</style>\n"
            . "<h1>" . $title . "</h1>\n"
            . "<p>seed " . htmlspecialchars((string) $this->seed) . ", "
            . plural(count($this->report), "document");
        if ($render) {
            $h .= ", up to " . plural($this->render_pages, "page") . " each.</p>\n";
        } else {
            $h .= " with differences. Margin differences of "
                . htmlspecialchars((string) $this->margin_tolerance)
                . "pt or less are ignored";
            $h .= "; " . htmlspecialchars($this->ignore_description()) . " ignored";
            $h .= ".</p>\n<p class=\"hint\">Click a page to cycle: bare &rarr; "
                . "<span style=\"color:#006eff\">margins</span> &rarr; "
                . "<span style=\"color:#dc3200\">columns</span> &rarr; bare. "
                . "Both are mubanal's.</p>\n";
        }

        $nimg = 0;
        $h .= $render ? $this->render_report($nimg) : $this->compare_report($nimg);

        // U+2FFB drawn rather than typed: the character is missing from most
        // systems' default fonts, and this page is opened off the filesystem
        // with whatever fonts happen to be there.
        $h .= "<div id=\"tools\"><span id=\"copymsg\"></span>"
            . "<button id=\"copyb\" title=\"Copy filenames; shift-click for full paths\">"
            . "<svg viewBox=\"0 0 24 24\" width=\"20\" height=\"20\" fill=\"none\""
            . " stroke=\"currentColor\" stroke-width=\"2\">"
            . "<rect x=\"3\" y=\"3\" width=\"13\" height=\"13\" rx=\"1.5\"></rect>"
            . "<rect x=\"8\" y=\"8\" width=\"13\" height=\"13\" rx=\"1.5\"></rect>"
            . "</svg></button></div>\n";

        $h .= "<script>\n"
            . "document.addEventListener('click', function (e) {\n"
            . "    var p = e.target.closest('.page');\n"
            . "    if (p) {\n"
            . "        p.dataset.ov = (+p.dataset.ov + 1) % 3;\n"
            . "    }\n"
            . "});\n"
            . "function copytext(t) {\n"
            . "    if (navigator.clipboard && navigator.clipboard.writeText) {\n"
            . "        return navigator.clipboard.writeText(t);\n"
            . "    }\n"
            // Reached when the page is opened somewhere navigator.clipboard is
            // not available; execCommand still works inside a click handler.
            . "    var ta = document.createElement('textarea');\n"
            . "    ta.value = t;\n"
            . "    ta.style.cssText = 'position:fixed;opacity:0';\n"
            . "    document.body.appendChild(ta);\n"
            . "    ta.select();\n"
            . "    var ok = document.execCommand('copy');\n"
            . "    document.body.removeChild(ta);\n"
            . "    return ok ? Promise.resolve() : Promise.reject();\n"
            . "}\n"
            . "document.getElementById('copyb').addEventListener('click', function (e) {\n"
            . "    var all = Array.prototype.slice.call(document.querySelectorAll('.fsel')),\n"
            . "        sel = all.filter(function (c) { return c.checked; }),\n"
            . "        full = e.shiftKey, b = this, msg = document.getElementById('copymsg');\n"
            . "    if (!sel.length) {\n"
            . "        sel = all;\n"
            . "    }\n"
            // Bare names by default: that is what a corpus is written in, since
            // a name with no `/` is looked up by hash and so is portable.
            . "    var t = sel.map(function (c) {\n"
            . "        return full ? c.value : c.value.replace(/^.*\\//, '');\n"
            . "    }).join('\\n');\n"
            . "    copytext(t + '\\n').then(function () {\n"
            . "        msg.textContent = sel.length + (sel.length === 1 ? ' file' : ' files')\n"
            . "            + (full ? ' copied with paths' : ' copied');\n"
            . "        b.className = 'ok';\n"
            // Clearing after a copy means the next selection starts empty,
            // rather than silently including what was already taken.
            . "        all.forEach(function (c) { c.checked = false; });\n"
            . "    }, function () {\n"
            . "        msg.textContent = 'copy failed';\n"
            . "    }).then(function () {\n"
            . "        msg.className = 'show';\n"
            . "        setTimeout(function () { msg.className = ''; b.className = ''; }, 1200);\n"
            . "    });\n"
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
     * @param string $anno
     * @return bool true if the file differs */
    private function handle_file($fname, $anno = "") {
        $xanno = $anno === "" ? "" : " [{$anno}]";
        if ($this->mode === self::MODE_LIST) {
            fwrite(STDOUT, "{$fname}{$xanno}\n");
            return false;
        }
        if ($this->mode === self::MODE_RENDER) {
            // Rendering is deferred to `write_html`, which is where every other
            // image is made; here the document is only collected.
            $this->report[] = [$fname, [], null, $anno];
            return false;
        }
        list($diffs, $mj, $bj) = $this->compare_file($fname);

        if ($this->verdict) {
            // In verdict mode the JSON differences are only context; what
            // counts is whether HotCRP would say anything different.
            [$bstatus, $bv] = $this->verdict_of($bj);
            [$mstatus, $mv] = $this->verdict_of($mj);
            if ($bv === $mv) {
                if ($this->verbose) {
                    $t = self::verdict_text($bv);
                    fwrite(STDOUT, $this->colored("{$fname}{$xanno}: same verdict ({$t})", "dim") . "\n");
                }
                return false;
            } else if (($this->verdict === "worse" && $bstatus >= $mstatus)
                       || ($this->verdict === "better" && $bstatus <= $mstatus)) {
                if ($this->verbose) {
                    $t = self::verdict_text($bv);
                    fwrite(STDOUT, $this->colored("{$fname}{$xanno}: verdict not {$this->verdict} ({$t})", "dim") . "\n");
                }
                return false;
            }
            ++$this->nflip;
            $this->tally["verdict"] = ($this->tally["verdict"] ?? 0) + 1;
            if (empty($bv)) {
                $this->tally["verdict_fail"] = ($this->tally["verdict_fail"] ?? 0) + 1;
            } else if (empty($mv)) {
                $this->tally["verdict_pass"] = ($this->tally["verdict_pass"] ?? 0) + 1;
            }
            if (!$this->summary) {
                // Green where mubanal newly finds nothing to complain about,
                // red where it complains about a paper banal passed, yellow
                // where both complain but differently.
                if (empty($mv) && !empty($bv)) {
                    $mcolor = "green";
                } else if (empty($bv) && !empty($mv)) {
                    $mcolor = "red";
                } else {
                    $mcolor = "yellow";
                }
                fwrite(STDOUT, "{$fname}{$xanno}\n");
                fwrite(STDOUT, "    banal:   " . $this->colored(self::verdict_text($bv), "dim") . "\n");
                fwrite(STDOUT, "    mubanal: " . $this->colored(self::verdict_text($mv), $mcolor) . "\n");
                foreach ($diffs as $d) {
                    fwrite(STDOUT, "      " . $this->colored($d, "dim") . "\n");
                }
            }
            if ($this->html !== null) {
                $this->report[] = [$fname, $diffs, $mj, $anno];
            }
            return true;
        }

        if (empty($diffs)) {
            if ($this->verbose) {
                fwrite(STDOUT, "{$fname}{$xanno}: same\n");
            }
            return false;
        }
        foreach ($diffs as $d) {
            $f = self::diff_field($d);
            $this->tally[$f] = ($this->tally[$f] ?? 0) + 1;
        }
        if (!$this->summary) {
            fwrite(STDOUT, "{$fname}{$xanno}\n");
            foreach ($diffs as $d) {
                fwrite(STDOUT, "    {$d}\n");
            }
        }
        if ($this->html !== null) {
            $this->report[] = [$fname, $diffs, $mj, $anno];
        }
        return true;
    }

    /** Column count asserted by a corpus heading, or null if it asserts none.
     *
     * The assertion is the `N-column documents|pages` prefix; whatever follows
     * is a human qualifier ("with a narrow gutter") that categorizes the entry
     * without changing what it claims. A heading with no such prefix -- "Slides
     * and other non-textual documents" -- collects documents that are in the
     * corpus without an expected answer.
     *
     * @param string $h
     * @return ?array{int,bool} count, and whether the section is about pages */
    static private function corpus_heading($h) {
        if (!preg_match('/\A(one|two|three|four|five)-column\s+(document|page)/i', $h, $m)) {
            return null;
        }
        $n = ["one" => 1, "two" => 2, "three" => 3, "four" => 4, "five" => 5];
        return [$n[strtolower($m[1])], strtolower($m[2]) === "page"];
    }

    /** Read corpus.md into per-file assertions.
     *
     * Fenced blocks are skipped: the preamble illustrates the page syntax with
     * a line that is otherwise indistinguishable from a real entry.
     *
     * @param string $fn
     * @return array<string,array{doc:?array{int,string},pages:array<int,array{int,string}>}> */
    static private function parse_corpus($fn) {
        if (($t = @file_get_contents($fn)) === false) {
            throw new CommandLineException("{$fn}: Cannot read");
        }
        $files = [];
        $heading = "";
        $assert = null;
        $fenced = false;
        foreach (explode("\n", $t) as $lineno => $line) {
            $line = trim($line);
            if (str_starts_with($line, "```")) {
                $fenced = !$fenced;
                continue;
            }
            if ($fenced || $line === "") {
                continue;
            }
            if (preg_match('/\A\#+\s*(.*)\z/', $line, $m)) {
                $heading = $m[1];
                $assert = self::corpus_heading($heading);
                continue;
            }
            // An entry is a lone token, optionally preceded by a page number:
            // a path, or a docstore name, which is a hash. Requiring a `/`, a
            // `.` or a hash keeps the prose in the preamble from reading as a
            // list of very short filenames.
            if (preg_match('/\A(?:(\d+)\s+)?(\S+)\z/', $line, $m)
                && (strpos($m[2], "/") !== false
                    || strpos($m[2], ".") !== false
                    || HashAnalysis::hash_as_binary($m[2]) !== false)) {
                $fname = $m[2];
                $files[$fname] = $files[$fname] ?? ["doc" => null, "pages" => []];
                if ($assert === null) {
                    continue;         // in the corpus, but nothing is claimed
                }
                list($ncols, $is_page) = $assert;
                if ($is_page !== ($m[1] !== "")) {
                    fwrite(STDERR, "{$fn}:" . ($lineno + 1) . ": "
                        . ($is_page ? "page entry without a page number" : "page number in a document section")
                        . "\n");
                    continue;
                }
                if ($is_page) {
                    $files[$fname]["pages"][intval($m[1])] = [$ncols, $heading];
                } else if ($files[$fname]["doc"] !== null
                           && $files[$fname]["doc"][0] !== $ncols) {
                    fwrite(STDERR, "{$fn}:" . ($lineno + 1) . ": listed as "
                        . $files[$fname]["doc"][0] . " and {$ncols} columns\n");
                } else {
                    $files[$fname]["doc"] = [$ncols, $heading];
                }
            }
        }
        return $files;
    }

    /** The column count decided for a page.
     *
     * `colpos` is one left/right pair per column, so it carries the count
     * directly. It is preferred because the `columns` field is suppressed on
     * `figure` and `appendix` pages -- banal prints a page field only when it
     * differs from the document, and never on those -- so reading the field
     * alone would inherit the document's count and score the report's
     * formatting convention rather than what the analysis found.
     *
     * @param ?object $pg
     * @param ?int $doc
     * @return ?int */
    static private function page_columns($pg, $doc) {
        if ($pg === null) {
            return null;
        }
        if (is_array($pg->colpos ?? null) && !empty($pg->colpos)) {
            return intdiv(count($pg->colpos), 2);
        }
        return $pg->columns ?? $doc;
    }

    /** Score mubanal's column counts against the corpus.
     * @return int */
    private function run_corpus() {
        $files = self::parse_corpus($this->corpus);
        $hit = $miss = [];
        $nfail = 0;
        foreach ($files as $fname => $a) {
            if ($a["doc"] === null && empty($a["pages"])) {
                continue;
            }
            if (($path = $this->resolve_file($fname)) === null) {
                fwrite(STDERR, "{$fname}: Not found\n");
                ++$nfail;
                continue;
            }
            $mj = $this->run_mubanal($path);
            if ($mj === null || !is_array($mj->pages ?? null)) {
                fwrite(STDERR, "{$fname}: mubanal failed\n");
                ++$nfail;
                continue;
            }
            $this->resolve_defaults($mj);
            $checks = [];
            if ($a["doc"] !== null) {
                $checks[] = [0, $a["doc"][0], $mj->columns ?? null, $a["doc"][1]];
            }
            foreach ($a["pages"] as $pageno => list($want, $head)) {
                $pg = $mj->pages[$pageno - 1] ?? null;
                $checks[] = [$pageno, $want,
                             self::page_columns($pg, $mj->columns ?? null), $head];
            }
            $diff = [];
            foreach ($checks as list($pageno, $want, $got, $head)) {
                $where = $fname . ($pageno === 0 ? "" : " page {$pageno}");
                if ($got === $want) {
                    $hit[$head] = ($hit[$head] ?? 0) + 1;
                } else {
                    $miss[$head][] = [$where, $want, $got];
                    $what = $pageno === 0 ? "columns" : "pages[" . ($pageno - 1) . "].columns";
                    $diff[] = "{$what}: gold={$want} mubanal={$got}";
                }
            }
            if (!empty($diff)) {
                $this->report[] = [$path, $diff, $mj, ""];
            }
        }

        $nhit = array_sum($hit);
        $nmiss = 0;
        foreach ($miss as $ms) {
            $nmiss += count($ms);
        }
        foreach (array_keys($hit + $miss) as $head) {
            $h = $hit[$head] ?? 0;
            $n = $h + count($miss[$head] ?? []);
            fwrite(STDOUT, sprintf("%4d/%-4d %s\n", $h, $n, $head));
        }
        foreach ($miss as $head => $ms) {
            foreach ($ms as list($where, $want, $got)) {
                fwrite(STDOUT, sprintf("  want %d, got %s  %s\n",
                                       $want, $got === null ? "?" : $got, $where));
            }
        }
        if (!$this->quiet) {
            fwrite(STDERR, sprintf("%d/%d correct%s\n", $nhit, $nhit + $nmiss,
                                   $nfail ? ", {$nfail} unusable" : ""));
        }
        if ($this->html !== null) {
            $this->write_html();
        }
        return $nmiss > 0 || $nfail > 0 ? 1 : 0;
    }

    /** @return int */
    function run() {
        if ($this->mode !== self::MODE_LIST
            && $this->mode !== self::MODE_RENDER
            && !self::command_exists($this->mubanal)
            && !is_executable(SiteLoader::$root . "/" . $this->mubanal)) {
            throw new CommandLineException("{$this->mubanal}: Not executable, use `--mubanal`");
        }
        if ($this->mode === self::MODE_RENDER && $this->html === null) {
            throw new CommandLineException("`--render` requires `--html`");
        }
        // The corpus names its own documents, so none of the traversal below
        // applies to it.
        if ($this->mode === self::MODE_CORPUS) {
            return $this->run_corpus();
        }

        // Named files come first, in order; the docstore then tops the run up
        // to `--count`. With no count, a named list is taken in full, a bare
        // docstore run samples 20 -- the docstore is a population to sample --
        // and `--papers` takes every match, since a search names the set it
        // means rather than a sample of one.
        $limit = $this->count
            ?? ($this->papers || !empty($this->files)
                ? ($this->papers ? PHP_INT_MAX : count($this->files))
                : 20);

        $nfile = $ndiff = 0;
        foreach ($this->files as $fname) {
            if ($nfile >= $limit) {
                break;
            }
            if (($path = $this->resolve_file($fname)) === null) {
                fwrite(STDERR, "{$fname}: Not found\n");
                continue;
            }
            ++$nfile;
            $ndiff += $this->handle_file($path) ? 1 : 0;
        }

        // The conference's own submissions, in paper order, replacing the
        // docstore rather than topping it up: this mode is asking about a
        // specific set of documents, not sampling a population.
        if ($this->papers) {
            foreach ($this->paper_rows() as $prow) {
                if ($nfile >= $limit) {
                    break;
                }
                $doc = $prow->primary_document();
                if (!$doc) {
                    continue;
                }
                if (($path = $doc->content_file()) === null || !is_readable($path)) {
                    fwrite(STDERR, "#{$prow->paperId}: document not available\n");
                    continue;
                }
                $this->_doc_dtype = $doc->documentType;
                ++$nfile;
                $ndiff += $this->handle_file($path, $doc->export_filename()) ? 1 : 0;
            }
        } else if ($nfile < $limit && !empty($this->docstores)) {
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
                // Compare where the name resolved to, not the name: a docstore
                // hash and the docstore path for it are the same document.
                $path = $this->resolve_file($fname);
                if ($path !== null && ($rp = realpath($path)) !== false) {
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
        if ($this->mode === self::MODE_COMPARE && $this->summary) {
            arsort($this->tally);
            foreach ($this->tally as $f => $n) {
                fwrite(STDOUT, sprintf("%6d  %s\n", $n, $f));
            }
        }
        if ($this->mode === self::MODE_COMPARE && !$this->quiet) {
            $what = $this->verdict ? "changed verdict" : "difference";
            $rest = "";
            if (isset($this->tally["verdict_fail"])) {
                $rest = ", " . plural($this->tally["verdict_fail"], "new fail");
            }
            fwrite(STDERR, plural($nfile, "file") . " compared, "
                . plural($ndiff, $what) . $rest . "\n");
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
        if (($mutool = getenv("MUTOOL"))) {
            // found in environment
        } else if (self::command_exists("mutool")) {
            $mutool = "mutool";
        } else {
            $mutool = "NONE";
        }
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "count:,c: {n} =COUNT Test COUNT documents [20, or all named files]",
            "seed:,s: {n} =SEED Seed the document choice [random]",
            "list,l Output document pathnames and exit",
            "files: =FILENAME Test the files listed in FILENAME, one per line",
            "corpus: =FILE Score mubanal's columns against the corpus in FILE",
            "verdict:: Compare HotCRP format-check verdicts, not JSON fields",
            "papers Test the current conference's submissions, not the docstore",
            "q:,search: =QUERY Test papers matching QUERY (implies --papers)",
            "t:,type: =SCOPE Scope for --search [submitted]",
            "spec: =SPEC Format spec for --verdict [conference's own, or "
                . self::DEFAULT_SPEC . "]",
            "ignore: =FIELD[,...] Also ignore differences in FIELD (`all` for every field)",
            "include: =FIELD[,...] Report differences in FIELD anyway [c,w ignored]",
            "margin-tolerance: {n} =PTS Ignore margin differences <=PTS [4]",
            "summary Print a tally of differences instead of listing them",
            "html: =FILE Write an HTML report with page images to FILE",
            "render::,R:: {n} =N Just render N pages of each document to --html [2]",
            "dpi: {n} =DPI Render pages at DPI for --html [72]",
            "mubanal: =CMD mubanal program [{$mubanal}]",
            "mubanal-opt[] =OPT Pass OPT to mubanal",
            "mutool: =CMD mutool program, for --html [{$mutool}]",
            "quiet,silent Be quiet",
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
            && !isset($arg["corpus"])
            && !isset($arg["papers"])
            && (empty($files) || ($arg["count"] ?? 0) > count($files))) {
            $confdps[] = self::default_docstore($arg);
        }
        $arg["mubanal"] = $arg["mubanal"] ?? $mubanal;
        $arg["mutool"] = $arg["mutool"] ?? $mutool;
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
