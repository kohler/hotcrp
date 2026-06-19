<?php
// search.php -- HotCRP command-line search script
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(Search_Batch::make_args($argv)->run());
}

class Search_Batch {
    /** @var Contact */
    public $user;
    /** @var PaperSearch */
    public $search;
    /** @var list<string> */
    public $fields;
    /** @var ?bool */
    public $header;
    /** @var bool */
    public $sitename;
    /** @var bool */
    public $table;
    /** @var ?int */
    public $width;
    /** @var bool */
    public $debug;

    function __construct(Contact $user, $arg) {
        $tx = $arg["t"] ?? "";
        $t = PaperSearch::canonical_limit($tx, $user);
        if ($t === null) {
            throw new CommandLineException("Search collection ‘{$tx}’ not found");
        }

        $this->user = $user;
        $this->search = new PaperSearch($user, ["q" => join(" ", $arg["_"]), "t" => $t]);
        $this->fields = $arg["f"] ?? [];
        $this->sitename = isset($arg["N"]);
        $this->table = isset($arg["table"]);
        $this->width = isset($arg["width"]) ? (int) $arg["width"] : null;
        $this->debug = isset($arg["debug"]);
        if (isset($arg["no-header"])) {
            $this->header = false;
        } else if (isset($arg["header"]) || $this->sitename) {
            $this->header = true;
        }
    }

    /** @return int */
    function run() {
        $pl = new PaperList("empty", $this->search);
        $pl->set_view("pid", true, PaperList::VIEWORIGIN_MAX);
        foreach ($this->fields as $f) {
            $pl->set_view($f, true, PaperList::VIEWORIGIN_MAX);
        }
        list($header, $body) = $pl->text_csv();

        if ($this->search->has_problem()) {
            fwrite(STDERR, $this->search->full_feedback_text());
        }
        if ($this->debug) {
            fwrite(STDERR, json_encode($this->search->main_term()->debug_json(), JSON_PRETTY_PRINT) . "\n");
        }
        if (empty($body)) {
            return 0;
        }
        $csv = new CsvGenerator($this->table ? CsvGenerator::TYPE_TABLE : CsvGenerator::TYPE_COMMA);
        if ($this->table) {
            // wrap to `--width`, else to the terminal width when on a TTY
            $w = $this->width ?? (stream_isatty(STDOUT) ? self::terminal_width() : 0);
            if ($w > 0) {
                $csv->set_table_max_width($w);
            }
        }
        $siteid = $this->search->conf->opt("confid");
        $siteclass = $this->search->conf->opt("siteclass");
        // a table always selects columns (for alignment); a header shows by
        // default for a table, or for CSV with more than one column
        $show_header = $this->header ?? ($this->table || count($header) > 1);
        if ($show_header || $this->table) {
            if ($this->sitename) {
                $xheader = ["sitename", "siteclass"];
                foreach ($header as $i => $n) {
                    $xheader[$i + 2] = $n;
                }
                $header = $xheader;
            }
            $csv->set_keys(array_keys($header));
            if ($show_header) {
                $csv->set_header($header);
            }
        }
        foreach ($body as $row) {
            $this->sitename && array_unshift($row, $siteid, $siteclass);
            $csv->add_row($row);
        }
        fwrite(STDOUT, $csv->unparse());
        return 0;
    }

    /** @return int */
    static function terminal_width() {
        // `stty` is a plain POSIX ioctl (no terminfo, unlike `tput`); read the
        // controlling terminal so a redirected stdin/stdout doesn't matter
        if (($s = @exec("stty size 2>/dev/null </dev/tty")) !== false
            && preg_match('/\A\d+ (\d+)/', $s, $m)
            && ($w = (int) $m[1]) > 0) {
            return $w;
        }
        if (($w = (int) getenv("COLUMNS")) > 0) {
            return $w;
        }
        return 80;
    }

    /** @return Search_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "t:,type: =SCOPE Scope of search [default]",
            "f[],show[],field[] =FIELD Include FIELD in output",
            "N,sitename Include site name and class in output",
            "table,T Output an aligned text table instead of CSV",
            "width:,w: =WIDTH Wrap table output to WIDTH columns",
            "header Always include header",
            "no-header Omit header",
            "debug",
            "help,h !"
        )->description("Output the papers matching HotCRP search QUERY as CSV or a text table.
Usage: php batch/search.php [-n CONFID] [-t SCOPE] [-f FIELD]+ [-T] QUERY...")
         ->helpopt("help")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new Search_Batch($conf->root_user(), $arg);
    }
}
